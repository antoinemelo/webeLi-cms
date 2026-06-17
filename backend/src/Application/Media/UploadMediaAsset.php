<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;
use App\Security\MediaUploadGuard;
use App\Application\Media\Storage\StorageDriverFactory;

final class UploadMediaAsset
{
    private readonly MediaFolderRepository $folders;
    private readonly MediaSettingsRepository $settings;
    private readonly StorageDriverFactory $storageFactory;

    public function __construct(
        private readonly Database $db,
        private readonly GenerateMediaVariants $generateMediaVariants,
    ) {
        $this->folders = new MediaFolderRepository($db);
        $this->settings = new MediaSettingsRepository($db);
        $this->storageFactory = new StorageDriverFactory($db);
    }

    /** @param array<string,mixed> $file @param array<string,mixed> $metadata @return array<string,mixed> */
    public function execute(int $siteId, array $file, array $metadata = [], ?int $uploadedByUserId = null): array
    {
        MediaStoragePreflight::ensureWritable();
        $siteSettings = $this->settings->forSite($siteId);
        $storage = $this->storageFactory->forSite($siteId);
        if ($storage->disk() !== 'local') { $storage->preflight(); }
        $validated = MediaUploadGuard::validate(
            $file,
            (int) ($metadata['max_bytes'] ?? $this->settings->maxUploadBytes($siteId)),
            $this->settings->allowedMimeTypes($siteId)
        );
        $sha256 = hash_file('sha256', (string) $validated['tmp']);
        $duplicate = $this->db->one(
            'SELECT id, path, lifecycle_status FROM media_assets WHERE site_id = :site_id AND sha256 = :sha256 AND lifecycle_status IN (\'ready\',\'delete_pending\') LIMIT 1',
            ['site_id' => $siteId, 'sha256' => $sha256]
        );
        if ($duplicate) {
            $this->recordEvent((int) $duplicate['id'], $siteId, 'deduplicated', $uploadedByUserId, [
                'sha256' => $sha256,
                'incoming_original_filename' => $file['name'] ?? null,
            ]);
            return [
                'status' => 'duplicate',
                'media_id' => (int) $duplicate['id'],
                'duplicate_of_media_id' => (int) $duplicate['id'],
                'sha256' => $sha256,
            ];
        }

        $uuid = self::uuid();
        $mediaType = self::mediaType((string) $validated['mime']);
        $folder = $this->resolveFolder($siteId, $metadata);
        $folderKey = (string) $folder['folder_key'];
        $safeBase = pathinfo((string) $validated['original'], PATHINFO_FILENAME);
        $safeBase = trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $safeBase) ?: 'media', '-_.');
        $filename = $safeBase . '-' . substr($uuid, 0, 8) . '.' . (string) $validated['extension'];

        $quarantineDir = MediaPath::root() . DIRECTORY_SEPARATOR . 'quarantine' . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . $folderKey;
        $publicDir = MediaPath::root() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . $folderKey;
        MediaPath::ensureDir($quarantineDir);
        MediaPath::ensureDir($publicDir);

        $quarantineAbs = $quarantineDir . DIRECTORY_SEPARATOR . $filename;
        $publicAbs = $publicDir . DIRECTORY_SEPARATOR . $filename;
        $quarantineRel = MediaPath::relative($quarantineAbs);
        $publicRel = MediaPath::relative($publicAbs);

        if (!@move_uploaded_file((string) $validated['tmp'], $quarantineAbs)) {
            if (!@rename((string) $validated['tmp'], $quarantineAbs)) {
                throw new \RuntimeException('MEDIA_UPLOAD_MOVE_FAILED');
            }
        }

        $imageSize = str_starts_with((string) $validated['mime'], 'image/') ? @getimagesize($quarantineAbs) : false;
        $width = is_array($imageSize) ? (int) ($imageSize[0] ?? 0) : null;
        $height = is_array($imageSize) ? (int) ($imageSize[1] ?? 0) : null;

        try {
            $mediaId = $this->db->transaction(function () use ($siteId, $uuid, $validated, $mediaType, $sha256, $folder, $quarantineRel, $publicRel, $filename, $metadata, $uploadedByUserId, $width, $height, $storage): int {
                $this->db->run(
                    'INSERT INTO media_assets (
                        uuid, site_id, storage_disk, path, quarantine_path, public_path, filename, original_filename,
                        extension, mime_type, media_type, size_bytes, width, height, sha256,
                        lifecycle_status, validation_status, variants_status, metadata_status,
                        copyright_text, license_type, source_url, folder_id, metadata_json,
                        uploaded_by_user_id, validated_at, created_at, updated_at
                     ) VALUES (
                        :uuid, :site_id, :storage_disk, :path, :quarantine_path, :public_path, :filename, :original_filename,
                        :extension, :mime_type, :media_type, :size_bytes, :width, :height, :sha256,
                        \'quarantined\', \'valid\', \'pending\', :metadata_status,
                        :copyright_text, :license_type, :source_url, :folder_id, :metadata_json,
                        :uploaded_by_user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                     )',
                    [
                        'uuid' => $uuid,
                        'site_id' => $siteId,
                        'storage_disk' => $storage->disk(),
                        'path' => $publicRel,
                        'quarantine_path' => $quarantineRel,
                        'public_path' => $publicRel,
                        'filename' => $filename,
                        'original_filename' => (string) $validated['original'],
                        'extension' => (string) $validated['extension'],
                        'mime_type' => (string) $validated['mime'],
                        'media_type' => $mediaType,
                        'size_bytes' => (int) $validated['size'],
                        'width' => $width,
                        'height' => $height,
                        'sha256' => $sha256,
                        'metadata_status' => self::metadataComplete($mediaType, $metadata) ? 'complete' : 'incomplete',
                        'copyright_text' => $metadata['copyright_text'] ?? null,
                        'license_type' => $metadata['license_type'] ?? null,
                        'source_url' => $metadata['source_url'] ?? null,
                        'folder_id' => (int) $folder['id'],
                        'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'uploaded_by_user_id' => $uploadedByUserId,
                    ]
                );
                $mediaId = $this->db->lastInsertId();

                foreach ((array) ($metadata['localizations'] ?? []) as $languageCode => $loc) {
                    if (is_array($loc)) {
                        $this->upsertLocalization($mediaId, (string) $languageCode, $loc);
                    }
                }

                $this->recordEvent($mediaId, $siteId, 'uploaded', $uploadedByUserId, [
                    'sha256' => $sha256,
                    'quarantine_path' => $quarantineRel,
                    'original_filename' => (string) $validated['original'],
                    'mime_type' => (string) $validated['mime'],
                    'size_bytes' => (int) $validated['size'],
                    'folder_id' => (int) $folder['id'],
                    'folder_key' => (string) $folder['folder_key'],
                ]);
                $this->recordEvent($mediaId, $siteId, 'validated', $uploadedByUserId, [
                    'validation_status' => 'valid',
                ]);

                return $mediaId;
            });

            try {
                MediaPath::ensureDir(dirname($publicAbs));
                if (!@rename($quarantineAbs, $publicAbs)) {
                    throw new \RuntimeException('local_promote_failed');
                }
                $storage->putFile($publicRel, $publicAbs, (string) $validated['mime']);
            } catch (\Throwable $storageError) {
                $this->db->run(
                    'UPDATE media_assets SET lifecycle_status = \'rejected\', validation_status = \'invalid\', validation_errors_json = :errors, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['id' => $mediaId, 'errors' => json_encode(['storage' => [$storageError->getMessage() ?: 'promote_failed']], JSON_UNESCAPED_UNICODE)]
                );
                throw new \RuntimeException('MEDIA_PROMOTE_FAILED');
            }

            $this->db->run(
                'UPDATE media_assets SET lifecycle_status = \'ready\', quarantine_path = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $mediaId]
            );
            $this->recordEvent($mediaId, $siteId, 'promoted', $uploadedByUserId, [
                'public_path' => $publicRel,
            ]);

            $variantWarning = null;
            $variants = ['variants' => []];
            try {
                if (!empty($siteSettings['auto_generate_variants'])) {
                    $variants = $this->generateMediaVariants->execute($siteId, $mediaId);
                } else {
                    $this->db->run('UPDATE media_assets SET variants_status = \'ready\', updated_at = CURRENT_TIMESTAMP WHERE id = :id', ['id' => $mediaId]);
                }
            } catch (\Throwable $variantError) {
                $variantWarning = $variantError->getMessage() ?: $variantError::class;
                $this->db->run(
                    'UPDATE media_assets SET variants_status = \'failed\', validation_errors_json = :errors, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['id' => $mediaId, 'errors' => json_encode(['variants' => [$variantWarning]], JSON_UNESCAPED_UNICODE)]
                );
                $this->recordEvent($mediaId, $siteId, 'rejected', $uploadedByUserId, [
                    'stage' => 'variants',
                    'reason' => $variantWarning,
                ]);
            }

            $response = ['status' => 'created', 'media_id' => $mediaId, 'sha256' => $sha256, 'variants' => $variants['variants'] ?? []];
            if ($variantWarning !== null) {
                $response['warning'] = 'MEDIA_VARIANTS_GENERATION_FAILED';
                $response['warning_reason'] = $variantWarning;
            }
            return $response;
        } catch (\Throwable $e) {
            @unlink($quarantineAbs);
            @unlink($publicAbs);
            throw $e;
        }
    }


    /** @param array<string,mixed> $metadata @return array{id:int,site_id:int,folder_key:string,name:string,parent_id:int|null,sort_order:int} */
    private function resolveFolder(int $siteId, array $metadata): array
    {
        if (isset($metadata['folder_id']) && (int) $metadata['folder_id'] > 0) {
            return $this->folders->requireFolder($siteId, (int) $metadata['folder_id']);
        }

        $folderKey = (string) ($metadata['folder_key'] ?? $this->settings->defaultFolderKey($siteId));
        $folderName = isset($metadata['folder_name']) ? (string) $metadata['folder_name'] : null;
        $parentId = isset($metadata['parent_folder_id']) ? (int) $metadata['parent_folder_id'] : null;
        return $this->folders->ensureFolder($siteId, $folderKey, $folderName, $parentId);
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(int $mediaId, int $siteId, string $eventType, ?int $actorId = null, array $payload = []): void
    {
        $this->db->run(
            'INSERT INTO media_asset_events (media_id, site_id, event_type, actor_iam_user_id, payload_json, created_at)
             VALUES (:media_id, :site_id, :event_type, :actor_iam_user_id, :payload_json, CURRENT_TIMESTAMP)',
            [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'event_type' => $eventType,
                'actor_iam_user_id' => $actorId,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    /** @param array<string,mixed> $loc */
    private function upsertLocalization(int $mediaId, string $languageCode, array $loc): void
    {
        $this->db->run(
            'INSERT INTO media_asset_localizations (media_id, language_code, alt_text, caption, title, is_alt_verified, updated_at)
             VALUES (:media_id, :language_code, :alt_text, :caption, :title, :is_alt_verified, CURRENT_TIMESTAMP)
             ON CONFLICT(media_id, language_code) DO UPDATE SET
                alt_text = excluded.alt_text,
                caption = excluded.caption,
                title = excluded.title,
                is_alt_verified = excluded.is_alt_verified,
                updated_at = CURRENT_TIMESTAMP',
            [
                'media_id' => $mediaId,
                'language_code' => $languageCode,
                'alt_text' => $loc['alt_text'] ?? null,
                'caption' => $loc['caption'] ?? null,
                'title' => $loc['title'] ?? null,
                'is_alt_verified' => !empty($loc['alt_text']) ? 1 : 0,
            ]
        );
    }

    /** @param array<string,mixed> $metadata */
    private static function metadataComplete(string $mediaType, array $metadata): bool
    {
        if ($mediaType !== 'image') {
            return true;
        }
        foreach ((array) ($metadata['localizations'] ?? []) as $loc) {
            if (is_array($loc) && trim((string) ($loc['alt_text'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function mediaType(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) { return 'image'; }
        if (str_starts_with($mime, 'video/')) { return 'video'; }
        if (str_starts_with($mime, 'audio/')) { return 'audio'; }
        if (str_contains($mime, 'pdf')) { return 'document'; }
        return 'binary';
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
