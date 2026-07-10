<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use InvalidArgumentException;

final class CatalogPdfService
{
    public function __construct(
        private readonly CatalogProductRepository $products,
        private readonly CatalogVariantRepository $variants,
    ) {}

    /** @param array<string,mixed> $filters */
    public function exportProductsPdf(int $siteId, array $filters = []): string
    {
        $filters['channel'] = trim((string) ($filters['channel'] ?? '')) ?: 'catalogue';
        $result = $this->products->list($siteId, $filters, 10000, 0, false);
        $pdf = new SimpleCatalogPdf('Catalogue de nos produits');
        $pdf->line('Catalogue de nos produits', 18);
        $pdf->line('Generation: ' . date('Y-m-d H:i'));
        $pdf->line('Canal: ' . $this->channelLabel((string) $filters['channel']));
        $pdf->space();

        if ($result['items'] === []) {
            $pdf->line('Aucun produit ne correspond a ce canal.');
            return $pdf->output();
        }

        foreach ($result['items'] as $product) {
            $pdf->heading((string) ($product['name'] ?? 'Produit sans nom'));
            $pdf->line('SKU: ' . ($product['sku_base'] ?: '-') . ' | Type: ' . (string) ($product['type'] ?? '-') . ' | Statut: ' . (string) ($product['status'] ?? '-'));
            $pdf->line('Canaux: ' . implode(', ', $this->channels($product)));
            $pdf->line('Prix vente: ' . $this->money($product['sale_price_min'] ?? null) . ' | Stock: ' . $this->stock($product));
            $description = trim((string) ($product['short_description'] ?? ''));
            if ($description !== '') {
                $pdf->paragraph($description);
            }

            $variantRows = $this->variants->listForProduct($siteId, (int) $product['id'], false);
            foreach ($variantRows as $variant) {
                $pdf->line(' - ' . (string) ($variant['sku'] ?? '-') . ' ' . trim((string) ($variant['name'] ?? '')) . ' | ' . $this->variantAvailability($product, $variant), 10);
            }
            $pdf->space();
        }

        return $pdf->output();
    }

    /** @param array<string,mixed> $product */
    private function channels(array $product): array
    {
        $channels = [];
        if (!empty($product['is_public'])) {
            $channels[] = 'Public';
        }
        if (!empty($product['is_ecommerce_enabled'])) {
            $channels[] = 'E-commerce';
        }
        if (!empty($product['is_pos_enabled'])) {
            $channels[] = 'POS';
        }
        if (!empty($product['is_catalogue_enabled'])) {
            $channels[] = 'Catalogue';
        }
        return $channels === [] ? ['Interne'] : $channels;
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'public' => 'Public',
            'ecommerce' => 'E-commerce',
            'pos' => 'POS',
            'catalogue' => 'Catalogue',
            default => throw new InvalidArgumentException('business.catalog.export_channel_invalid'),
        };
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        return 'CHF ' . number_format((float) $value, 2, '.', "'");
    }

    /** @param array<string,mixed> $product */
    private function stock(array $product): string
    {
        if (empty($product['track_stock']) && (int) ($product['stock_tracked_variant_count'] ?? 0) < 1) {
            return 'non suivi';
        }
        return number_format((float) ($product['stock_quantity_total'] ?? 0), 2, '.', "'");
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $variant */
    private function variantAvailability(array $product, array $variant): string
    {
        $trackStock = $variant['track_stock'] === null ? (bool) ($product['track_stock'] ?? false) : (bool) $variant['track_stock'];
        $available = (float) ($variant['stock_quantity'] ?? 0) - (float) ($variant['stock_reserved'] ?? 0);
        if (!$trackStock || $available > 0.0) {
            return 'envoi immediat';
        }
        $allowBackorder = $variant['allow_backorder'] === null ? (bool) ($product['allow_backorder'] ?? true) : (bool) $variant['allow_backorder'];
        if ($allowBackorder) {
            $days = $variant['backorder_delivery_days'] ?? $product['backorder_delivery_days'] ?? 7;
            return 'livraison differee sous ' . max(1, (int) $days) . ' jours';
        }
        return 'nous contacter';
    }
}

final class SimpleCatalogPdf
{
    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;
    private const LEFT = 48;
    private const TOP = 790;
    private const BOTTOM = 48;

    /** @var list<list<array{0:string,1:int,2:int,3:int}>> */
    private array $pages = [[]];
    private int $y = self::TOP;

    public function __construct(private readonly string $title) {}

    public function heading(string $text): void
    {
        $this->space(4);
        $this->line($text, 14);
    }

    public function paragraph(string $text): void
    {
        foreach ($this->wrap($text, 92) as $line) {
            $this->line($line, 10);
        }
    }

    public function line(string $text, int $size = 11): void
    {
        foreach ($this->wrap($text, $size >= 14 ? 62 : 105) as $line) {
            $this->ensureSpace($size + 5);
            $this->pages[array_key_last($this->pages)][] = [$this->ascii($line), self::LEFT, $this->y, $size];
            $this->y -= $size + 5;
        }
    }

    public function space(int $height = 10): void
    {
        $this->ensureSpace($height);
        $this->y -= $height;
    }

    public function output(): string
    {
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageCount = count($this->pages);
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + ($i * 2)) . ' 0 R';
        }
        $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        foreach ($this->pages as $index => $lines) {
            $contentObjectId = 4 + ($index * 2);
            $stream = $this->pageStream($lines);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << /F1 ' . (3 + ($pageCount * 2)) . ' 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id + 1] = strlen($pdf);
            $pdf .= ($id + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info << /Title (" . $this->escape($this->ascii($this->title)) . ") >> >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /** @param list<array{0:string,1:int,2:int,3:int}> $lines */
    private function pageStream(array $lines): string
    {
        $stream = '';
        foreach ($lines as [$text, $x, $y, $size]) {
            $stream .= "BT /F1 {$size} Tf {$x} {$y} Td (" . $this->escape($text) . ") Tj ET\n";
        }
        return $stream;
    }

    private function ensureSpace(int $height): void
    {
        if ($this->y - $height >= self::BOTTOM) {
            return;
        }
        $this->pages[] = [];
        $this->y = self::TOP;
    }

    /** @return list<string> */
    private function wrap(string $text, int $max): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return [''];
        }
        $words = explode(' ', $text);
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($candidate) > $max && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    private function ascii(string $text): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $text) ?? '' : $converted;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
