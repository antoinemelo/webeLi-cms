<?php

declare(strict_types=1);

namespace App\Application\Media\Storage;

use App\Core\Database;

final class MediaUrlGenerator
{
    private readonly StorageDriverFactory $factory;

    public function __construct(Database $db)
    {
        $this->factory = new StorageDriverFactory($db);
    }

    public function publicUrl(string $relativePath, int $siteId, string $disk = 'local'): string
    {
        $path = trim(str_replace('\\', '/', $relativePath));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
            return $path;
        }
        return $this->factory->forDisk($disk !== '' ? $disk : 'local', $siteId)->publicUrl($path);
    }
}
