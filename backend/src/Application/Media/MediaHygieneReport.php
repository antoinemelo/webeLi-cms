<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class MediaHygieneReport
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function execute(int $siteId, string $languageCode, int $limit = 60): array
    {
        $limit = max(1, min(200, $limit));
        $summary = [
            'total_ready' => $this->count("ma.site_id = :site_id AND ma.lifecycle_status = 'ready'", ['site_id' => $siteId]),
            'unused' => $this->count("ma.site_id = :site_id AND ma.lifecycle_status = 'ready' AND NOT EXISTS (SELECT 1 FROM media_usages mu WHERE mu.media_id = ma.id)", ['site_id' => $siteId]),
            'images_without_alt' => $this->count("ma.site_id = :site_id AND ma.lifecycle_status = 'ready' AND ma.media_type = 'image' AND TRIM(COALESCE(mal.alt_text, '')) = ''", ['site_id' => $siteId, 'language_code' => $languageCode], true),
            'variant_failures' => $this->count("ma.site_id = :site_id AND ma.lifecycle_status = 'ready' AND ma.media_type = 'image' AND ma.variants_status = 'failed'", ['site_id' => $siteId]),
            'metadata_incomplete' => $this->count("ma.site_id = :site_id AND ma.lifecycle_status = 'ready' AND ma.metadata_status = 'incomplete'", ['site_id' => $siteId]),
            'delete_pending' => $this->count("ma.site_id = :site_id AND ma.lifecycle_status = 'delete_pending'", ['site_id' => $siteId]),
        ];

        return [
            'language_code' => $languageCode,
            'summary' => $summary,
            'checks' => [
                'images_without_alt' => $this->assetsWithoutAlt($siteId, $languageCode, $limit),
                'unused' => $this->assetsByCondition($siteId, "NOT EXISTS (SELECT 1 FROM media_usages mu WHERE mu.media_id = ma.id)", $limit),
                'variant_failures' => $this->assetsByCondition($siteId, "ma.media_type = 'image' AND ma.variants_status = 'failed'", $limit),
                'metadata_incomplete' => $this->assetsByCondition($siteId, "ma.metadata_status = 'incomplete'", $limit),
                'missing_original_files' => $this->missingFiles($siteId, $limit),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function count(string $condition, array $params, bool $joinLocalization = false): int
    {
        $join = $joinLocalization ? 'LEFT JOIN media_asset_localizations mal ON mal.media_id = ma.id AND mal.language_code = :language_code' : '';
        $row = $this->db->one("SELECT COUNT(*) AS c FROM media_assets ma $join WHERE $condition", $params);
        return (int) ($row['c'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function assetsWithoutAlt(int $siteId, string $languageCode, int $limit): array
    {
        return $this->db->all(
            "SELECT ma.id, ma.filename, ma.original_filename, ma.mime_type, ma.media_type, ma.width, ma.height, ma.public_path, ma.variants_status, ma.metadata_status,
                    COALESCE(mal.alt_text, '') AS alt_text,
                    (SELECT COUNT(*) FROM media_usages mu WHERE mu.media_id = ma.id) AS usage_count
             FROM media_assets ma
             LEFT JOIN media_asset_localizations mal ON mal.media_id = ma.id AND mal.language_code = :language_code
             WHERE ma.site_id = :site_id AND ma.lifecycle_status = 'ready' AND ma.media_type = 'image' AND TRIM(COALESCE(mal.alt_text, '')) = ''
             ORDER BY usage_count DESC, ma.created_at DESC
             LIMIT " . $limit,
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );
    }

    /** @return list<array<string,mixed>> */
    private function assetsByCondition(int $siteId, string $condition, int $limit): array
    {
        return $this->db->all(
            "SELECT ma.id, ma.filename, ma.original_filename, ma.mime_type, ma.media_type, ma.width, ma.height, ma.public_path, ma.variants_status, ma.metadata_status,
                    (SELECT COUNT(*) FROM media_usages mu WHERE mu.media_id = ma.id) AS usage_count
             FROM media_assets ma
             WHERE ma.site_id = :site_id AND ma.lifecycle_status = 'ready' AND $condition
             ORDER BY ma.created_at DESC, ma.id DESC
             LIMIT " . $limit,
            ['site_id' => $siteId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function missingFiles(int $siteId, int $limit): array
    {
        $rows = $this->assetsByCondition($siteId, '1 = 1', 500);
        $missing = [];
        foreach ($rows as $row) {
            $path = (string) ($row['public_path'] ?? '');
            if ($path === '') {
                $path = (string) ($row['path'] ?? '');
            }
            if ($path !== '' && !is_file(MediaPath::absolute($path))) {
                $row['missing_path'] = $path;
                $missing[] = $row;
            }
            if (count($missing) >= $limit) {
                break;
            }
        }
        return $missing;
    }
}
