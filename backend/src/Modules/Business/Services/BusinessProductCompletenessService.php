<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

final class BusinessProductCompletenessService
{
    private const CHANNELS = ['admin', 'pos', 'ecommerce'];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function calculateProductScore(int $productId, string $channel): array
    {
        $channel = $this->channel($channel);
        $product = $this->product($productId);
        $variants = $this->variants($productId);
        $missing = [];
        $warnings = [];

        if ($variants === []) {
            $missing[] = $this->issue('variant_missing', 'variants', 'Aucune variante');
        }
        if (trim((string) ($product['name'] ?? '')) === '') {
            $missing[] = $this->issue('product_name_missing', 'name', 'Nom produit manquant');
        }
        if ($channel === 'ecommerce' && trim((string) ($product['slug'] ?? '')) === '') {
            $missing[] = $this->issue('product_slug_missing', 'slug', 'Slug e-commerce manquant');
        }
        if ($channel !== 'admin' && (string) ($product['status'] ?? '') !== 'active') {
            $missing[] = $this->issue('product_inactive', 'status', 'Produit inactif');
        }
        if ($channel === 'pos' && !((bool) ($product['is_pos_enabled'] ?? false))) {
            $missing[] = $this->issue('channel_pos_disabled', 'is_pos_enabled', 'Canal POS désactivé');
        }
        if ($channel === 'ecommerce') {
            if (!((bool) ($product['is_ecommerce_enabled'] ?? false))) {
                $missing[] = $this->issue('channel_ecommerce_disabled', 'is_ecommerce_enabled', 'Canal e-commerce désactivé');
            }
            if (!((bool) ($product['is_public'] ?? false)) || (string) ($product['visibility'] ?? '') !== 'public') {
                $missing[] = $this->issue('public_visibility_missing', 'visibility', 'Produit non public');
            }
        }

        if ($channel === 'ecommerce' && !$this->hasMainAsset($productId, null, 'ecommerce')) {
            $warnings[] = $this->issue('main_image_missing', 'assets', 'Image principale recommandée');
        }

        foreach ($this->requiredAttributesForProduct($productId) as $attribute) {
            if (!$this->hasAttributeValue('product', $productId, (int) $attribute['id'])) {
                $missing[] = $this->issue(
                    'required_product_attribute_missing_' . $this->issueCodeSuffix((string) ($attribute['code'] ?? $attribute['id'])),
                    'attributes',
                    'Attribut produit requis manquant : ' . (string) ($attribute['name'] ?? $attribute['code'])
                );
            }
        }

        $variantResults = [];
        foreach ($variants as $variant) {
            $result = $this->calculateVariantSellability((int) $variant['id'], $channel);
            $variantResults[] = $result;
            foreach ($result['missing'] as $issue) {
                $missing[] = $issue;
            }
            foreach ($result['warnings'] as $issue) {
                $warnings[] = $issue;
            }
        }

        $missing = $this->uniqueIssues($missing);
        $warnings = $this->uniqueIssues($warnings);
        $isSellable = $channel === 'admin'
            ? $variants !== [] && $missing === []
            : $this->hasSellableVariant($variantResults) && $this->productLevelBlockingIssues($missing) === [];
        $score = $this->score($missing, $warnings);
        if ($variantResults !== []) {
            $variantAverage = (int) floor(array_sum(array_map(static fn(array $result): int => (int) ($result['score'] ?? 0), $variantResults)) / count($variantResults));
            $score = min($score, $variantAverage);
        }

        return [
            'product_id' => $productId,
            'variant_id' => null,
            'channel' => $channel,
            'score' => $score,
            'is_sellable' => $isSellable,
            'status' => $this->status($channel, $isSellable, $missing),
            'label' => $this->label($channel, $isSellable, $missing),
            'missing' => $missing,
            'warnings' => $warnings,
            'variants' => $variantResults,
        ];
    }

    /** @return array<string,mixed> */
    public function calculateVariantSellability(int $variantId, string $channel): array
    {
        $channel = $this->channel($channel);
        $row = $this->variantRow($variantId);
        $productId = (int) $row['product_id'];
        $missing = [];
        $warnings = [];

        if ($channel !== 'admin' && (string) $row['product_status'] !== 'active') {
            $missing[] = $this->issue('product_inactive', 'status', 'Produit inactif');
        }
        if ($channel !== 'admin' && (string) $row['variant_status'] !== 'active') {
            $missing[] = $this->issue('variant_inactive', 'variant_status', 'Variante inactive');
        }
        if (trim((string) ($row['sku'] ?? '')) === '') {
            $missing[] = $this->issue('sku_missing', 'sku', 'SKU manquant');
        }
        if ($channel !== 'admin' && !$this->hasSalePrice($productId, $variantId)) {
            $missing[] = $this->issue('sale_price_missing', 'sale_price', 'Prix de vente manquant');
        }
        if ($channel === 'pos' && !((bool) $row['is_pos_enabled'])) {
            $missing[] = $this->issue('channel_pos_disabled', 'is_pos_enabled', 'Canal POS désactivé');
        }
        if ($channel === 'ecommerce') {
            if (!((bool) $row['is_ecommerce_enabled'])) {
                $missing[] = $this->issue('channel_ecommerce_disabled', 'is_ecommerce_enabled', 'Canal e-commerce désactivé');
            }
            if (!((bool) $row['is_public']) || (string) $row['visibility'] !== 'public') {
                $missing[] = $this->issue('public_visibility_missing', 'visibility', 'Produit non public');
            }
            if (trim((string) $row['slug']) === '') {
                $missing[] = $this->issue('product_slug_missing', 'slug', 'Slug e-commerce manquant');
            }
            if (!$this->hasMainAsset($productId, $variantId, 'ecommerce')) {
                $warnings[] = $this->issue('main_image_missing', 'assets', 'Image principale recommandée');
            }
        }

        if ($channel === 'pos') {
            $taxOk = $row['tax_class_id'] !== null || $this->hasDefaultTaxClass((int) $row['site_id']);
            if (!$taxOk) {
                $missing[] = $this->issue('tax_class_missing', 'tax_class_id', 'TVA manquante');
            }
        } elseif ($channel === 'ecommerce' && $row['tax_class_id'] === null) {
            $missing[] = $this->issue('tax_class_missing', 'tax_class_id', 'TVA manquante');
        }

        $trackStock = $row['variant_track_stock'] === null ? (bool) $row['product_track_stock'] : (bool) $row['variant_track_stock'];
        $allowBackorder = $row['variant_allow_backorder'] === null ? (bool) $row['product_allow_backorder'] : (bool) $row['variant_allow_backorder'];
        $available = (float) $row['stock_quantity'] - (float) $row['stock_reserved'];
        if ((string) $row['product_type'] === 'physical' && $trackStock && !$allowBackorder && $available <= 0.0) {
            $missing[] = $this->issue('stock_unavailable', 'stock', 'Stock indisponible');
        }

        foreach ($this->requiredAttributesForProduct($productId) as $attribute) {
            if (!$this->hasAttributeValue('variant', $variantId, (int) $attribute['id'])) {
                $missing[] = $this->issue(
                    'required_variant_attribute_missing_' . $this->issueCodeSuffix((string) ($attribute['code'] ?? $attribute['id'])),
                    'attributes',
                    'Attribut variante requis manquant : ' . (string) ($attribute['name'] ?? $attribute['code'])
                );
            }
        }

        $missing = $this->uniqueIssues($missing);
        $warnings = $this->uniqueIssues($warnings);
        $isSellable = $missing === [];

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'channel' => $channel,
            'score' => $this->score($missing, $warnings),
            'is_sellable' => $isSellable,
            'status' => $this->status($channel, $isSellable, $missing),
            'label' => $this->label($channel, $isSellable, $missing),
            'missing' => $missing,
            'warnings' => $warnings,
            'stock' => [
                'track_stock' => $trackStock,
                'allow_backorder' => $allowBackorder,
                'available_quantity' => $available,
            ],
        ];
    }

    public function recalculateProduct(int $productId): void
    {
        $this->product($productId);
        $this->db->transaction(function () use ($productId): void {
            $this->db->run('DELETE FROM business_product_completeness_scores WHERE product_id = ?', [$productId]);
            foreach (self::CHANNELS as $channel) {
                $productScore = $this->calculateProductScore($productId, $channel);
                if ($channel === 'admin') {
                    $summaryScore = $productScore;
                    $summaryScore['channel'] = 'all';
                    $this->persist($summaryScore);
                }
                $this->persist($productScore);
                foreach ($productScore['variants'] as $variantScore) {
                    $this->persist($variantScore);
                }
            }
        });
    }

    /** @return array<string,mixed> */
    public function storedProductCompleteness(int $productId): array
    {
        $this->product($productId);
        $scores = $this->db->all(
            'SELECT * FROM business_product_completeness_scores WHERE product_id = ? ORDER BY channel ASC, variant_id ASC',
            [$productId]
        );
        return [
            'product_id' => $productId,
            'scores' => array_map(fn(array $row): array => $this->scorePayload($row), $scores),
        ];
    }

    /** @param array<string,mixed> $score */
    private function persist(array $score): void
    {
        $issues = [
            'missing' => $score['missing'] ?? [],
            'warnings' => $score['warnings'] ?? [],
            'status' => $score['status'] ?? '',
            'label' => $score['label'] ?? '',
        ];
        $this->db->run(
            'INSERT INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json, calculated_at)
             VALUES(:product_id, :variant_id, :channel, :score, :is_sellable, :missing_json, CURRENT_TIMESTAMP)',
            [
                'product_id' => (int) $score['product_id'],
                'variant_id' => $score['variant_id'] === null ? null : (int) $score['variant_id'],
                'channel' => (string) $score['channel'],
                'score' => (int) $score['score'],
                'is_sellable' => (bool) $score['is_sellable'] ? 1 : 0,
                'missing_json' => json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function product(int $productId): array
    {
        $row = $this->db->one('SELECT * FROM business_products WHERE id = ? AND archived_at IS NULL LIMIT 1', [$this->id($productId, 'product_id')]);
        if ($row === null) {
            throw new InvalidArgumentException('business.product_not_found');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function variants(int $productId): array
    {
        return $this->db->all('SELECT * FROM business_product_variants WHERE product_id = ? AND archived_at IS NULL ORDER BY sort_order ASC, id ASC', [$productId]);
    }

    /** @return array<string,mixed> */
    private function variantRow(int $variantId): array
    {
        $row = $this->db->one(
            'SELECT
                p.id AS product_id,
                p.site_id,
                p.type AS product_type,
                p.status AS product_status,
                p.visibility,
                p.slug,
                p.tax_class_id,
                p.track_stock AS product_track_stock,
                p.allow_backorder AS product_allow_backorder,
                p.is_public,
                p.is_ecommerce_enabled,
                p.is_pos_enabled,
                v.id AS variant_id,
                v.status AS variant_status,
                v.sku,
                v.track_stock AS variant_track_stock,
                v.stock_quantity,
                v.stock_reserved,
                v.allow_backorder AS variant_allow_backorder
             FROM business_product_variants v
             INNER JOIN business_products p ON p.id = v.product_id
             WHERE v.id = ? AND v.archived_at IS NULL AND p.archived_at IS NULL
             LIMIT 1',
            [$this->id($variantId, 'variant_id')]
        );
        if ($row === null) {
            throw new InvalidArgumentException('business.variant_not_found');
        }
        return $row;
    }

    private function hasSalePrice(int $productId, int $variantId): bool
    {
        $base = $this->db->one(
            'SELECT amount FROM business_product_base_prices WHERE product_id = ? AND price_kind = "sale" AND amount > 0 AND (valid_from IS NULL OR valid_from <= CURRENT_TIMESTAMP) AND (valid_until IS NULL OR valid_until > CURRENT_TIMESTAMP) LIMIT 1',
            [$productId]
        );
        if ($base !== null) {
            return true;
        }
        return $this->db->one(
            'SELECT 1 FROM business_product_variant_price_adjustments WHERE variant_id = ? AND price_kind = "sale" AND adjustment_type = "fixed_override" AND adjustment_value > 0 AND (valid_from IS NULL OR valid_from <= CURRENT_TIMESTAMP) AND (valid_until IS NULL OR valid_until > CURRENT_TIMESTAMP) LIMIT 1',
            [$variantId]
        ) !== null;
    }

    private function hasDefaultTaxClass(int $siteId): bool
    {
        return $this->db->one('SELECT 1 FROM business_tax_classes WHERE site_id = ? AND is_default = 1 AND archived_at IS NULL LIMIT 1', [$siteId]) !== null;
    }

    private function hasMainAsset(int $productId, ?int $variantId, string $channel): bool
    {
        $params = ['product_id' => $productId, 'channel' => $channel];
        $variantClause = '';
        if ($variantId !== null) {
            $variantClause = ' AND (variant_id = :variant_id OR variant_id IS NULL)';
            $params['variant_id'] = $variantId;
        } else {
            $variantClause = ' AND variant_id IS NULL';
        }
        return $this->db->one(
            'SELECT 1 FROM business_product_assets
             WHERE product_id = :product_id
               AND role = "main"
               AND archived_at IS NULL
               AND is_public = 1
               AND channel_scope IN ("all", :channel)' . $variantClause . '
             LIMIT 1',
            $params
        ) !== null;
    }

    /** @return list<array<string,mixed>> */
    private function requiredAttributesForProduct(int $productId): array
    {
        $this->ensureProductAttributeGroupLinkTable();
        return $this->db->all(
            'SELECT DISTINCT a.id, a.code, a.name, a.data_type
             FROM business_product_attribute_group_links l
             INNER JOIN business_attribute_groups g ON g.id = l.group_id
             INNER JOIN business_attributes a ON a.group_id = g.id
             WHERE l.product_id = ?
               AND g.archived_at IS NULL
               AND a.archived_at IS NULL
               AND a.is_required = 1
             ORDER BY a.sort_order ASC, a.name ASC',
            [$this->id($productId, 'product_id')]
        );
    }

    private function hasAttributeValue(string $scope, int $ownerId, int $attributeId): bool
    {
        $table = $scope === 'variant' ? 'business_variant_attribute_values' : 'business_product_attribute_values';
        $owner = $scope === 'variant' ? 'variant_id' : 'product_id';
        return $this->db->one(
            'SELECT 1 FROM ' . $table . '
             WHERE ' . $owner . ' = ?
               AND attribute_id = ?
               AND (
                   value_text IS NOT NULL
                   OR value_number IS NOT NULL
                   OR value_json IS NOT NULL
               )
             LIMIT 1',
            [$this->id($ownerId, $owner), $this->id($attributeId, 'attribute_id')]
        ) !== null;
    }

    private function issueCodeSuffix(string $value): string
    {
        $suffix = strtolower(trim($value));
        $suffix = preg_replace('/[^a-z0-9_]+/', '_', $suffix) ?? '';
        return trim($suffix, '_') ?: 'attribute';
    }

    private function ensureProductAttributeGroupLinkTable(): void
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS business_product_attribute_group_links (
                product_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(product_id, group_id),
                FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY(group_id) REFERENCES business_attribute_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CHECK(sort_order >= 0)
            )'
        );
        $this->db->run('CREATE INDEX IF NOT EXISTS idx_business_product_attribute_group_links_group ON business_product_attribute_group_links(group_id, sort_order)');
    }

    /** @return array{code:string,field:string,label:string} */
    private function issue(string $code, string $field, string $label): array
    {
        return ['code' => $code, 'field' => $field, 'label' => $label];
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function uniqueIssues(array $issues): array
    {
        $seen = [];
        $unique = [];
        foreach ($issues as $issue) {
            $code = (string) ($issue['code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $unique[] = $issue;
        }
        return $unique;
    }

    /** @param list<array<string,mixed>> $missing @return list<array<string,mixed>> */
    private function productLevelBlockingIssues(array $missing): array
    {
        return array_values(array_filter($missing, static fn(array $issue): bool => !in_array((string) ($issue['code'] ?? ''), ['sku_missing', 'variant_inactive', 'stock_unavailable'], true)));
    }

    /** @param list<array<string,mixed>> $missing @param list<array<string,mixed>> $warnings */
    private function score(array $missing, array $warnings): int
    {
        return max(0, min(100, 100 - (count($missing) * 25) - (count($warnings) * 10)));
    }

    /** @param list<array<string,mixed>> $missing */
    private function status(string $channel, bool $isSellable, array $missing): string
    {
        if ($isSellable) {
            return 'ready_to_sell';
        }
        return match ($channel) {
            'pos' => 'blocking_pos',
            'ecommerce' => 'blocking_ecommerce',
            default => $missing === [] ? 'ready_to_sell' : 'needs_completion',
        };
    }

    /** @param list<array<string,mixed>> $missing */
    private function label(string $channel, bool $isSellable, array $missing): string
    {
        if ($isSellable) {
            return 'Prêt à vendre';
        }
        return match ($channel) {
            'pos' => 'Bloquant POS',
            'ecommerce' => 'Bloquant e-commerce',
            default => $missing === [] ? 'Prêt à vendre' : 'À compléter',
        };
    }

    /** @param list<array<string,mixed>> $variantResults */
    private function hasSellableVariant(array $variantResults): bool
    {
        foreach ($variantResults as $result) {
            if ((bool) ($result['is_sellable'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function scorePayload(array $row): array
    {
        $missing = json_decode((string) ($row['missing_json'] ?? '[]'), true);
        $missing = is_array($missing) ? $missing : [];
        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'variant_id' => $row['variant_id'] === null ? null : (int) $row['variant_id'],
            'channel' => (string) $row['channel'],
            'score' => (int) $row['score'],
            'is_sellable' => (bool) $row['is_sellable'],
            'missing' => $missing['missing'] ?? $missing,
            'warnings' => $missing['warnings'] ?? [],
            'status' => $missing['status'] ?? null,
            'label' => $missing['label'] ?? null,
            'calculated_at' => $row['calculated_at'] ?? null,
        ];
    }

    private function channel(string $channel): string
    {
        $channel = trim($channel) === '' ? 'admin' : trim($channel);
        if ($channel === 'public') {
            $channel = 'ecommerce';
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('business.completeness.channel_invalid');
        }
        return $channel;
    }

    private function id(int $id, string $field): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('business.' . $field . '_invalid');
        }
        return $id;
    }
}
