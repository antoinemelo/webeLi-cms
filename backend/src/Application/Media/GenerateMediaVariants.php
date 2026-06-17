<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;
use App\Application\Media\Storage\StorageDriverFactory;

final class GenerateMediaVariants
{
    private readonly StorageDriverFactory $storageFactory;

    public function __construct(private readonly Database $db)
    {
        $this->storageFactory = new StorageDriverFactory($db);
    }

    /** @return array<string,mixed> */
    public function execute(int $siteId, int $mediaId): array
    {
        $asset = $this->db->one(
            'SELECT * FROM media_assets WHERE id = :id AND site_id = :site_id AND lifecycle_status != \'deleted\' LIMIT 1',
            ['id' => $mediaId, 'site_id' => $siteId]
        );
        if (!$asset) {
            throw new \InvalidArgumentException('MEDIA_NOT_FOUND');
        }

        $sourcePath = (string) ($asset['path'] ?? '');
        $source = MediaPath::absolute($sourcePath);
        $storage = $this->storageFactory->forDisk((string) ($asset['storage_disk'] ?? 'local'), $siteId);
        if ($sourcePath === '' || (!is_file($source) && !$storage->exists($sourcePath))) {
            $this->markFailed($mediaId, 'source_missing');
            throw new \InvalidArgumentException('MEDIA_SOURCE_MISSING');
        }

        $this->clearGeneratedVariants($mediaId, $sourcePath, $siteId, (string) ($asset['storage_disk'] ?? 'local'));
        if (!is_file($source) && $storage->exists($sourcePath)) {
            $this->markFailed($mediaId, 'source_cache_missing');
            throw new \InvalidArgumentException('MEDIA_SOURCE_CACHE_MISSING');
        }

        $variants = [];
        $variants[] = $this->registerVariant($mediaId, null, 'original', $sourcePath, $source, (string) $asset['mime_type'], (string) $asset['extension'], (int) ($asset['width'] ?? 0), (int) ($asset['height'] ?? 0), 'original');

        if (str_starts_with((string) $asset['mime_type'], 'image/')) {
            $generated = $this->generateImageVariants($siteId, $asset, $source, $sourcePath);
            $variants = array_merge($variants, $generated);
        }

        $this->db->run(
            'UPDATE media_assets SET variants_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => 'ready', 'id' => $mediaId]
        );
        $this->recordEvent($mediaId, $siteId, 'variants_generated', ['variant_keys' => array_column($variants, 'variant_key')]);

        return ['media_id' => $mediaId, 'variants' => $variants];
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(int $mediaId, int $siteId, string $eventType, array $payload = []): void
    {
        $this->db->run(
            'INSERT INTO media_asset_events (media_id, site_id, event_type, payload_json, created_at)
             VALUES (:media_id, :site_id, :event_type, :payload_json, CURRENT_TIMESTAMP)',
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'event_type' => $eventType,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    private function clearGeneratedVariants(int $mediaId, string $sourcePath, int $siteId, string $disk): void
    {
        $oldVariants = $this->db->all(
            'SELECT path FROM media_asset_variants WHERE media_id = :media_id AND variant_key != \'original\'',
            ['media_id' => $mediaId]
        );
        $storage = $this->storageFactory->forDisk($disk, $siteId);
        foreach ($oldVariants as $variant) {
            $path = (string) ($variant['path'] ?? '');
            if ($path === '' || $path === $sourcePath || !str_contains($path, '/variants/')) {
                continue;
            }
            try { $storage->delete($path); } catch (\Throwable) {}
            $absolute = MediaPath::absolute($path);
            if (is_file($absolute)) { @unlink($absolute); }
        }
        $this->db->run(
            'DELETE FROM media_asset_variants WHERE media_id = :media_id AND variant_key != \'original\'',
            ['media_id' => $mediaId]
        );
    }

    public function ensureNativePresets(int $siteId): void
    {
        $presets = [
            ['content_480', 480, null, 'webp', 82, 'fit', 10],
            ['content_768', 768, null, 'webp', 82, 'fit', 20],
            ['content_1024', 1024, null, 'webp', 82, 'fit', 30],
            ['content_1280', 1280, null, 'webp', 82, 'fit', 40],
            ['content_1600', 1600, null, 'webp', 82, 'fit', 50],
            ['hero_640', 640, null, 'webp', 82, 'fit', 110],
            ['hero_960', 960, null, 'webp', 82, 'fit', 120],
            ['hero_1280', 1280, null, 'webp', 82, 'fit', 130],
            ['hero_1920', 1920, null, 'webp', 82, 'fit', 140],
            ['og_1200x630', 1200, 630, 'webp', 82, 'crop', 210],
        ];

        foreach ($presets as [$key, $width, $height, $format, $quality, $mode, $sort]) {
            $this->db->run(
                'INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
                 VALUES(:site_id, :preset_key, :width, :height, :format, :quality, :mode, :sort_order, 1)
                 ON CONFLICT(site_id, preset_key) DO UPDATE SET
                    width = excluded.width,
                    height = excluded.height,
                    format = excluded.format,
                    quality = excluded.quality,
                    mode = excluded.mode,
                    sort_order = excluded.sort_order,
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP',
                [
                    'site_id' => $siteId,
                    'preset_key' => $key,
                    'width' => $width,
                    'height' => $height,
                    'format' => $format,
                    'quality' => $quality,
                    'mode' => $mode,
                    'sort_order' => $sort,
                ]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function activePresets(int $siteId): array
    {
        $this->ensureNativePresets($siteId);

        return $this->db->all(
            'SELECT id, preset_key, width, height, format, quality, mode
             FROM media_variant_presets
             WHERE site_id = :site_id AND is_active = 1
             ORDER BY sort_order, preset_key',
            ['site_id' => $siteId]
        );
    }

    /** @param array<string,mixed> $asset @return list<array<string,mixed>> */
    private function generateImageVariants(int $siteId, array $asset, string $source, string $sourcePath): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return [];
        }

        $mime = (string) $asset['mime_type'];
        $loader = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif' => 'imagecreatefromgif',
            default => null,
        };
        if ($loader === null || !function_exists($loader)) {
            return [];
        }

        $presets = $this->activePresets($siteId);
        if (!$presets) {
            return [];
        }

        $src = @$loader($source);
        if (!$src) {
            return [];
        }

        $width = imagesx($src);
        $height = imagesy($src);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($src);
            return [];
        }

        $result = [];
        foreach ($presets as $preset) {
            $targetWidth = (int) $preset['width'];
            $targetHeight = isset($preset['height']) ? (int) $preset['height'] : 0;
            $key = (string) $preset['preset_key'];
            $format = strtolower((string) ($preset['format'] ?? 'webp'));
            $quality = max(1, min(100, (int) ($preset['quality'] ?? 82)));
            $mode = (string) ($preset['mode'] ?? 'fit');

            if ($targetWidth <= 0 || ($targetHeight < 0)) {
                continue;
            }
            if ($targetHeight === 0) {
                if ($width <= $targetWidth) {
                    continue;
                }
                $targetHeight = (int) round($height * ($targetWidth / $width));
            }
            if ($targetWidth <= 0 || $targetHeight <= 0) {
                continue;
            }

            $dst = imagecreatetruecolor($targetWidth, $targetHeight);
            if (in_array($format, ['png', 'webp'], true)) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }

            [$srcX, $srcY, $srcW, $srcH, $dstX, $dstY, $dstW, $dstH] = $this->geometry($mode, $width, $height, $targetWidth, $targetHeight);
            imagecopyresampled($dst, $src, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

            $extension = $format === 'jpeg' ? 'jpg' : $format;
            $mimeType = match ($format) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'image/webp',
            };
            $dir = dirname(MediaPath::absolute($sourcePath)) . DIRECTORY_SEPARATOR . 'variants';
            MediaPath::ensureDir($dir);
            $variantRel = dirname($sourcePath) . '/variants/' . pathinfo($sourcePath, PATHINFO_FILENAME) . '-' . $key . '.' . $extension;
            $variantAbs = MediaPath::absolute($variantRel);

            $saved = match ($format) {
                'jpg', 'jpeg' => function_exists('imagejpeg') && @imagejpeg($dst, $variantAbs, $quality),
                'png' => function_exists('imagepng') && @imagepng($dst, $variantAbs, max(0, min(9, (int) round((100 - $quality) / 11.111)))),
                default => function_exists('imagewebp') && @imagewebp($dst, $variantAbs, $quality),
            };
            if ($saved) {
                $storage = $this->storageFactory->forDisk((string) ($asset['storage_disk'] ?? 'local'), $siteId);
                $storage->putFile($variantRel, $variantAbs, $mimeType);
                $result[] = $this->registerVariant((int) $asset['id'], (int) $preset['id'], $key, $variantRel, $variantAbs, $mimeType, $extension, $targetWidth, $targetHeight, 'gd:preset');
            }
            imagedestroy($dst);
        }

        imagedestroy($src);
        return $result;
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int} */
    private function geometry(string $mode, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): array
    {
        if ($mode === 'crop') {
            $sourceRatio = $sourceWidth / $sourceHeight;
            $targetRatio = $targetWidth / $targetHeight;
            if ($sourceRatio > $targetRatio) {
                $cropWidth = (int) round($sourceHeight * $targetRatio);
                $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
                return [$cropX, 0, $cropWidth, $sourceHeight, 0, 0, $targetWidth, $targetHeight];
            }
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropY = (int) round(($sourceHeight - $cropHeight) / 2);
            return [0, $cropY, $sourceWidth, $cropHeight, 0, 0, $targetWidth, $targetHeight];
        }

        if ($mode === 'contain') {
            $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
            $drawWidth = (int) round($sourceWidth * $scale);
            $drawHeight = (int) round($sourceHeight * $scale);
            $drawX = (int) round(($targetWidth - $drawWidth) / 2);
            $drawY = (int) round(($targetHeight - $drawHeight) / 2);
            return [0, 0, $sourceWidth, $sourceHeight, $drawX, $drawY, $drawWidth, $drawHeight];
        }

        return [0, 0, $sourceWidth, $sourceHeight, 0, 0, $targetWidth, $targetHeight];
    }

    /** @return array<string,mixed> */
    private function registerVariant(int $mediaId, ?int $presetId, string $key, string $relativePath, string $absolutePath, string $mime, string $format, int $width, int $height, string $generator): array
    {
        $size = is_file($absolutePath) ? filesize($absolutePath) : null;
        $sha = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
        $this->db->run(
            'INSERT INTO media_asset_variants (
                media_id, preset_id, variant_key, path, width, height, format, mime_type, size_bytes, sha256, generator, generation_status, generated_at
             ) VALUES (
                :media_id, :preset_id, :variant_key, :path, :width, :height, :format, :mime_type, :size_bytes, :sha256, :generator, \'ready\', CURRENT_TIMESTAMP
             )
             ON CONFLICT(media_id, variant_key) DO UPDATE SET
                preset_id = excluded.preset_id,
                path = excluded.path,
                width = excluded.width,
                height = excluded.height,
                format = excluded.format,
                mime_type = excluded.mime_type,
                size_bytes = excluded.size_bytes,
                sha256 = excluded.sha256,
                generator = excluded.generator,
                generation_status = \'ready\',
                generated_at = CURRENT_TIMESTAMP',
            [
                'media_id' => $mediaId,
                'preset_id' => $presetId,
                'variant_key' => $key,
                'path' => $relativePath,
                'width' => $width > 0 ? $width : null,
                'height' => $height > 0 ? $height : null,
                'format' => $format,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'sha256' => $sha,
                'generator' => $generator,
            ]
        );

        return [
            'variant_key' => $key,
            'preset_id' => $presetId,
            'path' => $relativePath,
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ];
    }

    private function markFailed(int $mediaId, string $reason): void
    {
        $siteId = (int) ($this->db->one('SELECT site_id FROM media_assets WHERE id = :id', ['id' => $mediaId])['site_id'] ?? 0);
        if ($siteId > 0) {
            $this->recordEvent($mediaId, $siteId, 'rejected', ['variants' => [$reason]]);
        }
        $this->db->run(
            'UPDATE media_assets SET variants_status = \'failed\', validation_errors_json = :errors, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $mediaId, 'errors' => json_encode(['variants' => [$reason]], JSON_UNESCAPED_UNICODE)]
        );
    }
}
