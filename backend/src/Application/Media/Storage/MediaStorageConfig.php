<?php

declare(strict_types=1);

namespace App\Application\Media\Storage;

use App\Core\Database;

final class MediaStorageConfig
{
    /** @return array<string,mixed> */
    public static function forSite(Database $db, int $siteId): array
    {
        $defaults = self::defaults();
        $row = $db->one(
            "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'relations' AND setting_key = 'defaults' LIMIT 1",
            ['site_id' => $siteId]
        );
        if ($row && is_string($row['value_json'] ?? null)) {
            $decoded = json_decode((string) $row['value_json'], true);
            if (is_array($decoded)) {
                $defaults = array_replace_recursive($defaults, $decoded['media_storage'] ?? []);
            }
        }
        $driver = strtolower(trim((string) ($defaults['driver'] ?? 'local')));
        if (!in_array($driver, ['local', 's3'], true)) {
            $driver = 'local';
        }
        $defaults['driver'] = $driver;
        $defaults['s3'] = is_array($defaults['s3'] ?? null) ? $defaults['s3'] : [];
        foreach (['endpoint', 'bucket', 'region', 'access_key', 'secret_key', 'public_base_url', 'path_prefix'] as $key) {
            $defaults['s3'][$key] = trim((string) ($defaults['s3'][$key] ?? ''));
        }
        return $defaults;
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'driver' => 'local',
            's3' => [
                'endpoint' => '',
                'bucket' => '',
                'region' => 'us-east-1',
                'access_key' => '',
                'secret_key' => '',
                'public_base_url' => '',
                'path_prefix' => '',
            ],
        ];
    }

    /** @param array<string,mixed> $config */
    public static function isS3Complete(array $config): bool
    {
        $s3 = is_array($config['s3'] ?? null) ? $config['s3'] : [];
        foreach (['endpoint', 'bucket', 'region', 'access_key', 'secret_key'] as $key) {
            if (trim((string) ($s3[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }
}
