<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;
use App\Modules\Business\Contracts\ProductContentProjectionPort;
use App\Modules\Business\Contracts\ProductContentSourcePort;
use InvalidArgumentException;
use PDOException;

final class ProductContentLinkService implements ProductContentProjectionPort
{
    private const RELATIONS = ['product_page', 'storytelling', 'faq', 'guide', 'comparison', 'seo', 'related'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductContentSourcePort $products,
        private readonly CmsContentSourcePort $contents,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForProduct(int $siteId, int $productId): array
    {
        return $this->db->all(
            'SELECT l.*, ce.entry_key, ce.status AS content_status, ct.type_key AS content_type,
                    CASE WHEN pp.id IS NULL THEN 0 ELSE 1 END AS projection_ready
             FROM business_product_content_links l
             INNER JOIN content_entries ce ON ce.id = l.content_entry_id AND ce.site_id = l.site_id
             INNER JOIN content_types ct ON ct.id = ce.content_type_id
             LEFT JOIN business_product_public_projections pp ON pp.link_id = l.id
             WHERE l.site_id = ? AND l.product_id = ?
             ORDER BY l.is_canonical DESC, l.relation_type, l.locale, l.id',
            [$siteId, $productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function contentCandidates(int $siteId, string $query = ''): array
    {
        return $this->contents->searchContent($siteId, $query);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, int $productId, array $payload, int $actorId): array
    {
        $data = $this->normalize($siteId, $productId, $payload);
        try {
            $this->db->run(
                'INSERT INTO business_product_content_links(
                site_id, product_id, content_entry_id, relation_type, locale, is_canonical,
                status, seo_config_json, created_by_iam_user_id, updated_by_iam_user_id
             ) VALUES(:site_id, :product_id, :content_entry_id, :relation_type, :locale, :is_canonical,
                :status, :seo_config_json, :actor, :actor)',
                $data + ['actor' => $actorId > 0 ? $actorId : null]
            );
        } catch (PDOException $e) {
            throw new InvalidArgumentException('business.pim.content_link_conflict', 0, $e);
        }
        $id = $this->db->lastInsertId();
        $this->rebuildLink($id);
        return $this->find($siteId, $id) ?? [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(int $siteId, int $id, array $payload, int $actorId): array
    {
        $current = $this->find($siteId, $id);
        if ($current === null) {
            throw new InvalidArgumentException('business.pim.content_link_not_found');
        }
        $data = $this->normalize($siteId, (int) $current['product_id'], $payload + $current);
        try {
            $this->db->run(
                'UPDATE business_product_content_links SET content_entry_id=:content_entry_id, relation_type=:relation_type,
                locale=:locale, is_canonical=:is_canonical, status=:status, seo_config_json=:seo_config_json,
                updated_by_iam_user_id=:actor, updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND site_id=:site_id',
                $data + ['id' => $id, 'actor' => $actorId > 0 ? $actorId : null]
            );
        } catch (PDOException $e) {
            throw new InvalidArgumentException('business.pim.content_link_conflict', 0, $e);
        }
        $this->rebuildLink($id);
        return $this->find($siteId, $id) ?? [];
    }

    public function delete(int $siteId, int $id): bool
    {
        if ($this->find($siteId, $id) === null) {
            return false;
        }
        $this->db->run('DELETE FROM business_product_content_links WHERE id = ? AND site_id = ?', [$id, $siteId]);
        return true;
    }

    public function refreshProduct(int $siteId, int $productId): void
    {
        foreach ($this->db->all('SELECT id FROM business_product_content_links WHERE site_id=? AND product_id=? AND status=\'active\'', [$siteId, $productId]) as $row) {
            $this->rebuildLink((int) $row['id']);
        }
    }

    public function deactivateProduct(int $siteId, int $productId): void
    {
        $this->db->run('UPDATE business_product_public_projections SET is_active=0, projected_at=CURRENT_TIMESTAMP WHERE site_id=? AND product_id=?', [$siteId, $productId]);
    }

    public function assertProductCanBeDeleted(int $siteId, int $productId): void
    {
        if ($this->db->one('SELECT 1 FROM business_product_content_links WHERE site_id=? AND product_id=? LIMIT 1', [$siteId, $productId]) !== null) {
            throw new InvalidArgumentException('business.pim.product_content_links_must_be_removed_first');
        }
    }

    /** @return list<array<string,mixed>> */
    public function publicProductsForContent(int $siteId, int $contentEntryId, string $locale): array
    {
        $rows = $this->db->all(
            'SELECT pp.product_json, pp.structured_data_json, l.relation_type, l.is_canonical, l.locale
             FROM business_product_public_projections pp
             INNER JOIN business_product_content_links l ON l.id=pp.link_id AND l.status=\'active\'
             WHERE pp.site_id=? AND pp.content_entry_id=? AND pp.is_active=1
               AND (pp.locale IS NULL OR pp.locale=?)
             ORDER BY l.is_canonical DESC, l.id',
            [$siteId, $contentEntryId, $locale]
        );
        return array_map(static function (array $row): array {
            $product = json_decode((string) $row['product_json'], true) ?: [];
            $product['content_relation'] = ['type' => $row['relation_type'], 'canonical' => (bool) $row['is_canonical'], 'locale' => $row['locale']];
            $product['structured_data'] = json_decode((string) $row['structured_data_json'], true) ?: null;
            return $product;
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    private function find(int $siteId, int $id): ?array
    {
        return $this->db->one('SELECT * FROM business_product_content_links WHERE id=? AND site_id=? LIMIT 1', [$id, $siteId]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalize(int $siteId, int $productId, array $payload): array
    {
        $contentEntryId = max(0, (int) ($payload['content_entry_id'] ?? 0));
        if ($this->products->productSnapshot($siteId, $productId, 'und') === null) {
            throw new InvalidArgumentException('business.pim.product_not_found');
        }
        if ($this->contents->contentEntry($siteId, $contentEntryId) === null) {
            throw new InvalidArgumentException('business.pim.content_not_found_or_cross_site');
        }
        $relation = strtolower(trim((string) ($payload['relation_type'] ?? 'product_page')));
        if (!in_array($relation, self::RELATIONS, true)) {
            throw new InvalidArgumentException('business.pim.content_relation_type_invalid');
        }
        $locale = strtolower(trim((string) ($payload['locale'] ?? '')));
        if ($locale !== '' && (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $locale) || !$this->contents->supportsLocale($siteId, $locale))) {
            throw new InvalidArgumentException('business.pim.content_locale_invalid');
        }
        $seo = $payload['seo_config'] ?? $payload['seo_config_json'] ?? [];
        if (is_string($seo)) {
            $seo = json_decode($seo, true) ?: [];
        }
        return [
            'site_id' => $siteId,
            'product_id' => $productId,
            'content_entry_id' => $contentEntryId,
            'relation_type' => $relation,
            'locale' => $locale === '' ? null : $locale,
            'is_canonical' => !empty($payload['is_canonical']) ? 1 : 0,
            'status' => ($payload['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'seo_config_json' => json_encode(is_array($seo) ? $seo : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private function rebuildLink(int $linkId): void
    {
        $link = $this->db->one('SELECT * FROM business_product_content_links WHERE id=?', [$linkId]);
        if ($link === null) {
            return;
        }
        $locale = (string) ($link['locale'] ?: 'und');
        $snapshot = $this->products->productSnapshot((int) $link['site_id'], (int) $link['product_id'], $locale);
        if ($snapshot === null) {
            $this->db->run('DELETE FROM business_product_public_projections WHERE link_id=?', [$linkId]);
            return;
        }
        $product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
        $active = $link['status'] === 'active' && ($product['status'] ?? '') === 'active' && !empty($product['is_public']);
        $seoConfig = json_decode((string) $link['seo_config_json'], true) ?: [];
        $schemaType = (string) ($seoConfig['schema_type'] ?? (($product['type'] ?? '') === 'service' ? 'Service' : 'Product'));
        if (!in_array($schemaType, ['Product', 'Service'], true)) {
            $schemaType = 'Product';
        }
        $structured = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            'name' => (string) ($product['name'] ?? ''),
            'sku' => (string) ($product['sku_base'] ?? ''),
            'description' => (string) ($product['short_description'] ?? ''),
        ];
        if (!empty($product['brand_name'])) {
            $structured['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand_name']];
        }
        $this->db->run(
            'INSERT INTO business_product_public_projections(link_id,site_id,product_id,content_entry_id,relation_type,locale,is_canonical,is_active,product_json,structured_data_json,source_product_updated_at,projected_at)
             VALUES(:link_id,:site_id,:product_id,:content_entry_id,:relation_type,:locale,:is_canonical,:is_active,:product_json,:structured_data_json,:source_updated,CURRENT_TIMESTAMP)
             ON CONFLICT(link_id) DO UPDATE SET site_id=excluded.site_id,product_id=excluded.product_id,content_entry_id=excluded.content_entry_id,
                relation_type=excluded.relation_type,locale=excluded.locale,is_canonical=excluded.is_canonical,is_active=excluded.is_active,
                product_json=excluded.product_json,structured_data_json=excluded.structured_data_json,source_product_updated_at=excluded.source_product_updated_at,projected_at=CURRENT_TIMESTAMP',
            [
                'link_id' => $linkId, 'site_id' => $link['site_id'], 'product_id' => $link['product_id'], 'content_entry_id' => $link['content_entry_id'],
                'relation_type' => $link['relation_type'], 'locale' => $link['locale'], 'is_canonical' => $link['is_canonical'], 'is_active' => $active ? 1 : 0,
                'product_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'structured_data_json' => json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'source_updated' => $product['updated_at'] ?? null,
            ]
        );
    }
}
