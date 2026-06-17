<?php

declare(strict_types=1);

namespace App\Application\Media;

final class MediaPath
{
    public static function root(): string
    {
        return rtrim(base_path('storage/media'), DIRECTORY_SEPARATOR);
    }

    public static function relative(string $absolute): string
    {
        $root = self::normalize(self::root());
        $path = self::normalize($absolute);
        if (!str_starts_with($path, $root . '/')) {
            throw new \InvalidArgumentException('MEDIA_PATH_OUTSIDE_ROOT');
        }
        return substr($path, strlen($root) + 1);
    }

    public static function absolute(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new \InvalidArgumentException('MEDIA_PATH_INVALID');
        }

        $absolute = self::normalize(self::root() . DIRECTORY_SEPARATOR . $relative);
        $root = self::normalize(self::root());
        if (!str_starts_with($absolute, $root . '/')) {
            throw new \InvalidArgumentException('MEDIA_PATH_OUTSIDE_ROOT');
        }
        return str_replace('/', DIRECTORY_SEPARATOR, $absolute);
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('MEDIA_STORAGE_UNWRITABLE');
        }
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return $prefix . implode('/', $parts);
    }
}
