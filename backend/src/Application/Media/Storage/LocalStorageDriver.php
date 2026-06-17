<?php

declare(strict_types=1);

namespace App\Application\Media\Storage;

use App\Application\Media\MediaPath;

final class LocalStorageDriver implements StorageDriverInterface
{
    public function disk(): string
    {
        return 'local';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function preflight(): array
    {
        $root = MediaPath::root();
        foreach ([$root, $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'quarantine'] as $directory) {
            MediaPath::ensureDir($directory);
            if (!is_dir($directory) || !is_writable($directory)) {
                throw new \RuntimeException('MEDIA_STORAGE_UNWRITABLE:' . basename($directory));
            }
        }
        return ['driver' => 'local', 'root' => $root];
    }

    public function putFile(string $relativePath, string $absoluteSourcePath, string $mimeType = 'application/octet-stream'): void
    {
        $target = MediaPath::absolute($relativePath);
        MediaPath::ensureDir(dirname($target));
        if (!is_file($absoluteSourcePath)) {
            throw new \RuntimeException('MEDIA_STORAGE_SOURCE_MISSING');
        }
        if (realpath($absoluteSourcePath) !== realpath($target) && !@copy($absoluteSourcePath, $target)) {
            throw new \RuntimeException('MEDIA_STORAGE_WRITE_FAILED');
        }
    }

    public function readStream(string $relativePath)
    {
        $absolute = MediaPath::absolute($relativePath);
        $stream = @fopen($absolute, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('MEDIA_STORAGE_READ_FAILED');
        }
        return $stream;
    }

    public function exists(string $relativePath): bool
    {
        return is_file(MediaPath::absolute($relativePath));
    }

    public function delete(string $relativePath): void
    {
        if ($relativePath === '') {
            return;
        }
        $absolute = MediaPath::absolute($relativePath);
        if (is_file($absolute) && !@unlink($absolute)) {
            throw new \RuntimeException('MEDIA_STORAGE_DELETE_FAILED');
        }
    }

    public function publicUrl(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $relativePath) || str_starts_with($relativePath, '//')) {
            return $relativePath;
        }
        if (str_starts_with($relativePath, 'storage/')) {
            return url_path('/' . $relativePath);
        }
        return url_path('/storage/media/' . ltrim($relativePath, '/'));
    }
}
