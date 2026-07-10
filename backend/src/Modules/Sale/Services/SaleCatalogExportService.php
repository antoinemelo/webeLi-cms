<?php

declare(strict_types=1);

namespace App\Modules\Sale\Services;

use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleChannelRepository;

final class SaleCatalogExportService
{
    public function __construct(
        private readonly SaleChannelRepository $channels,
        private readonly SaleCatalogSnapshotService $catalog,
        private readonly SalePricingService $pricing,
    ) {}

    /** @param array<string,mixed> $filters */
    public function exportPdf(int $siteId, array $filters = []): string
    {
        $channel = $this->saleChannel($siteId, (int) ($filters['channel_id'] ?? 0));
        $catalogChannel = trim((string) ($filters['catalog_channel'] ?? $filters['channel'] ?? 'catalogue')) ?: 'catalogue';
        $rows = $this->catalogRows($siteId, $catalogChannel);
        $pdf = new SaleCatalogPdf('Catalogue de vente');

        $pdf->line('Catalogue de vente', 18);
        $pdf->line('Canal: ' . (string) $channel['name'] . ' (' . (string) $channel['code'] . ')');
        $pdf->line('Devise: ' . (string) $channel['currency'] . ' | TVA: ' . ((bool) ($channel['price_tax_included'] ?? true) ? 'prix TTC' : 'prix HT'));
        $pdf->line('Diffusion: ' . $this->channelLabel($catalogChannel) . ' | Generation: ' . date('Y-m-d H:i'));
        $pdf->space();

        if ($rows === []) {
            $pdf->line('Aucun produit ou bundle disponible pour cet export.');
            return $pdf->output();
        }

        foreach ($rows as $row) {
            $amounts = $this->pricing->lineAmounts($row);
            $amounts['tax_included'] = (bool) ($channel['price_tax_included'] ?? true);
            $totals = $this->pricing->lineTotals($amounts, 1);
            $pdf->heading((string) ($row['product_name'] ?? 'Produit sans nom'));
            $pdf->line('SKU: ' . (string) ($row['sku'] ?? '-') . ' | Type: ' . (string) ($row['product_type'] ?? '-') . ' | Variante: ' . (string) ($row['variant_name'] ?? '-'));
            $pdf->line('Prix unite: ' . $this->money((int) $totals['line_total_minor'], (string) $channel['currency']) . ' | TVA: ' . $this->money((int) $totals['line_tax_minor'], (string) $channel['currency']) . ' (' . ((int) ($amounts['tax_rate_basis_points'] ?? 0) / 100) . '%)');
            $pdf->line('Disponibilite: ' . (string) (($row['availability']['label'] ?? null) ?: '-'));
            if (!empty($row['brand_name']) || !empty($row['category_name'])) {
                $pdf->line('Marque: ' . (string) ($row['brand_name'] ?? '-') . ' | Categorie: ' . (string) ($row['category_name'] ?? '-'));
            }
            $pdf->space();
        }

        return $pdf->output();
    }

    /** @return array<string,mixed> */
    private function saleChannel(int $siteId, int $channelId): array
    {
        if ($channelId > 0) {
            return $this->channels->requireChannel($siteId, $channelId);
        }
        foreach (['ecommerce', 'pos', 'admin'] as $type) {
            $result = $this->channels->list($siteId, ['status' => 'active', 'channel_type' => $type], 1, 0);
            if (($result['items'] ?? []) !== []) {
                return $result['items'][0];
            }
        }
        $result = $this->channels->list($siteId, [], 1, 0);
        return $result['items'][0] ?? ['id' => 0, 'code' => 'catalogue', 'name' => 'Catalogue', 'currency' => 'CHF', 'price_tax_included' => 1];
    }

    /** @return list<array<string,mixed>> */
    private function catalogRows(int $siteId, string $catalogChannel): array
    {
        $rows = [];
        $offset = 0;
        do {
            $page = $this->catalog->searchSellableVariants($siteId, [
                'channel' => $catalogChannel,
                'limit' => 100,
                'offset' => $offset,
            ]);
            foreach ($page['items'] as $item) {
                $rows[] = $item;
            }
            $offset += 100;
        } while (!empty($page['has_more']));
        return $rows;
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'catalogue' => 'Catalogue',
            'ecommerce' => 'E-commerce',
            'pos' => 'POS',
            'public' => 'Public',
            'admin' => 'Admin',
            default => $channel,
        };
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2, '.', "'") . ' ' . strtoupper($currency ?: 'CHF');
    }
}

final class SaleCatalogPdf
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
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        foreach ($this->pages as $index => $_) {
            $kids[] = (3 + ($index * 2)) . ' 0 R';
        }
        $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($this->pages) . ' >>';
        $fontId = 3 + (count($this->pages) * 2);
        foreach ($this->pages as $index => $lines) {
            $contentId = 4 + ($index * 2);
            $stream = $this->stream($lines);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
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
    private function stream(array $lines): string
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
        $words = explode(' ', trim(preg_replace('/\s+/', ' ', $text) ?? ''));
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
        return $lines === [] ? [''] : $lines;
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
