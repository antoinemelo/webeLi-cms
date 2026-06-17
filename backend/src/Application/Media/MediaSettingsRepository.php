<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class MediaSettingsRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $cache = [];

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function forSite(int $siteId): array
    {
        if (isset($this->cache[$siteId])) {
            return $this->cache[$siteId];
        }

        $defaults = [
            'max_upload_mb' => 12,
            'allowed_mime_types' => [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                'video/mp4', 'video/webm',
                'audio/mpeg', 'audio/mp4', 'audio/ogg',
                'application/pdf',
            ],
            'auto_generate_variants' => true,
            'require_alt_text' => true,
            'default_folder_key' => 'general',
            'default_social_image_media_id' => null,
            'social_image_policy' => [
                'required_width' => 1200,
                'required_height' => 630,
                'variant_key' => 'og_1200x630',
            ],
        ];

        $row = $this->db->one(
            "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'media' AND setting_key = 'defaults' LIMIT 1",
            ['site_id' => $siteId]
        );
        if ($row && is_string($row['value_json'] ?? null)) {
            $decoded = json_decode((string) $row['value_json'], true);
            if (is_array($decoded)) {
                $defaults = array_replace_recursive($defaults, $decoded);
            }
        }

        $defaults['max_upload_mb'] = max(1, min(128, (int) ($defaults['max_upload_mb'] ?? 12)));
        $defaults['allowed_mime_types'] = $this->normalizeAllowedMimeTypes($defaults['allowed_mime_types'] ?? []);
        $defaults['auto_generate_variants'] = filter_var($defaults['auto_generate_variants'] ?? true, FILTER_VALIDATE_BOOL);
        $defaults['require_alt_text'] = filter_var($defaults['require_alt_text'] ?? true, FILTER_VALIDATE_BOOL);
        $defaults['default_folder_key'] = $this->folderKey((string) ($defaults['default_folder_key'] ?? 'general'));
        $defaults['default_social_image_media_id'] = isset($defaults['default_social_image_media_id']) && is_numeric($defaults['default_social_image_media_id'])
            ? max(0, (int) $defaults['default_social_image_media_id'])
            : null;

        return $this->cache[$siteId] = $defaults;
    }

    public function maxUploadBytes(int $siteId): int
    {
        return (int) $this->forSite($siteId)['max_upload_mb'] * 1024 * 1024;
    }

    /** @return list<string> */
    public function allowedMimeTypes(int $siteId): array
    {
        return $this->forSite($siteId)['allowed_mime_types'];
    }

    public function defaultFolderKey(int $siteId): string
    {
        return (string) $this->forSite($siteId)['default_folder_key'];
    }

    /** @param mixed $value @return list<string> */
    private function normalizeAllowedMimeTypes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = [];
        foreach ($value as $mime) {
            $mime = strtolower(trim((string) $mime));
            if ($mime !== '' && preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $mime) === 1) {
                $allowed[] = $mime;
            }
        }
        return array_values(array_unique($allowed));
    }

    private function folderKey(string $folderKey): string
    {
        $folderKey = trim($folderKey, " \t\n\r\0\x0B/");
        $folderKey = preg_replace('/[^a-zA-Z0-9._\/-]+/', '-', $folderKey) ?: 'general';
        $folderKey = preg_replace('#/+#', '/', $folderKey) ?: 'general';
        if ($folderKey === '' || str_contains($folderKey, '..')) {
            return 'general';
        }
        return $folderKey;
    }
}
