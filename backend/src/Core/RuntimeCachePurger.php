<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Centralized runtime cache invalidation for public rendering artifacts.
 *
 * For now the public runtime cache is Twig's compiled template cache. Keeping
 * the purge behind a small service avoids duplicating filesystem traversal in
 * admin modules that change public output without touching content projections.
 */
final class RuntimeCachePurger
{
    public static function purgeTwigCache(?string $cachePath = null): void
    {
        $cachePath ??= function_exists('base_path') ? base_path('storage/cache/twig') : dirname(__DIR__, 3) . '/storage/cache/twig';
        if (!is_string($cachePath) || $cachePath === '' || !is_dir($cachePath)) {
            return;
        }

        $root = realpath($cachePath);
        if ($root === false || !is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
