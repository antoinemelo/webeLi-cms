<?php

declare(strict_types=1);

namespace App\Application\Media;

final class MediaStoragePreflight
{
    /** @return array<string,mixed> */
    public static function ensureWritable(): array
    {
        $root = MediaPath::root();
        $directories = [
            $root,
            $root . DIRECTORY_SEPARATOR . 'public',
            $root . DIRECTORY_SEPARATOR . 'quarantine',
            base_path('storage/logs'),
        ];

        foreach ($directories as $directory) {
            MediaPath::ensureDir($directory);
            if (!is_dir($directory) || !is_writable($directory)) {
                throw new \RuntimeException('MEDIA_STORAGE_UNWRITABLE:' . self::safeRelative($directory));
            }
        }

        $probe = $root . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            throw new \RuntimeException('MEDIA_STORAGE_UNWRITABLE:' . self::safeRelative($root));
        }
        @unlink($probe);

        return [
            'root' => self::safeRelative($root),
            'public' => self::safeRelative($root . DIRECTORY_SEPARATOR . 'public'),
            'quarantine' => self::safeRelative($root . DIRECTORY_SEPARATOR . 'quarantine'),
        ];
    }

    private static function safeRelative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : basename($path);
    }
}
