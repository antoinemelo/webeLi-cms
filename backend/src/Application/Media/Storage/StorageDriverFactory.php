<?php

declare(strict_types=1);

namespace App\Application\Media\Storage;

use App\Core\Database;

final class StorageDriverFactory
{
    public function __construct(private readonly Database $db) {}

    public function forSite(int $siteId): StorageDriverInterface
    {
        $config = MediaStorageConfig::forSite($this->db, $siteId);
        if (($config['driver'] ?? 'local') === 's3') {
            if (!MediaStorageConfig::isS3Complete($config)) {
                return new LocalStorageDriver();
            }
            return new S3StorageDriver(is_array($config['s3'] ?? null) ? $config['s3'] : []);
        }
        return new LocalStorageDriver();
    }

    public function forDisk(string $disk, int $siteId): StorageDriverInterface
    {
        if ($disk === 's3') {
            $config = MediaStorageConfig::forSite($this->db, $siteId);
            if (MediaStorageConfig::isS3Complete($config)) {
                return new S3StorageDriver(is_array($config['s3'] ?? null) ? $config['s3'] : []);
            }
        }
        return new LocalStorageDriver();
    }
}
