<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class MediaVariantPresetSeeder
{
    /** @var list<array{0:string,1:int,2:?int,3:string,4:int,5:string,6:int}> */
    private const DEFAULT_PRESETS = [
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

    public function __construct(private readonly Database $db) {}

    public function ensureForSite(int $siteId): void
    {
        $this->writeDefaults($siteId, true);
    }

    public function ensureMissingForSite(int $siteId): void
    {
        $this->writeDefaults($siteId, false);
    }

    private function writeDefaults(int $siteId, bool $refreshExisting): void
    {
        if ($siteId <= 0) {
            return;
        }

        $conflictClause = $refreshExisting
            ? 'DO UPDATE SET
                    width = excluded.width,
                    height = excluded.height,
                    format = excluded.format,
                    quality = excluded.quality,
                    mode = excluded.mode,
                    sort_order = excluded.sort_order,
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP'
            : 'DO NOTHING';

        foreach (self::DEFAULT_PRESETS as [$key, $width, $height, $format, $quality, $mode, $sortOrder]) {
            $this->db->run(
                'INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active, created_at, updated_at)
                 VALUES(:site_id, :preset_key, :width, :height, :format, :quality, :mode, :sort_order, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT(site_id, preset_key) ' . $conflictClause,
                [
                    'site_id' => $siteId,
                    'preset_key' => $key,
                    'width' => $width,
                    'height' => $height,
                    'format' => $format,
                    'quality' => $quality,
                    'mode' => $mode,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
