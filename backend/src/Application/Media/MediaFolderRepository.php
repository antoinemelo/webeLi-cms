<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Core\Database;

final class MediaFolderRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return array{id:int,site_id:int,folder_key:string,name:string,parent_id:int|null,sort_order:int} */
    public function ensureFolder(int $siteId, string $folderKey, ?string $name = null, ?int $parentId = null): array
    {
        $folderKey = self::normalizeFolderKey($folderKey);
        $existing = $this->findByKey($siteId, $folderKey);
        if ($existing) {
            return $existing;
        }

        $parentId = $parentId !== null && $parentId > 0 ? $parentId : $this->inferParentId($siteId, $folderKey);
        if ($parentId !== null) {
            $this->assertFolderBelongsToSite($siteId, $parentId);
        }

        $displayName = self::normalizeName($name ?: basename($folderKey));
        $this->db->run(
            'INSERT INTO media_folders (site_id, folder_key, name, parent_id, sort_order, created_at, updated_at)
             VALUES (:site_id, :folder_key, :name, :parent_id, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(site_id, folder_key) DO NOTHING',
            [
                'site_id' => $siteId,
                'folder_key' => $folderKey,
                'name' => $displayName,
                'parent_id' => $parentId,
            ]
        );

        $folder = $this->findByKey($siteId, $folderKey);
        if (!$folder) {
            throw new \RuntimeException('MEDIA_FOLDER_CREATE_FAILED');
        }
        return $folder;
    }

    /** @return array{id:int,site_id:int,folder_key:string,name:string,parent_id:int|null,sort_order:int}|null */
    public function findByKey(int $siteId, string $folderKey): ?array
    {
        $row = $this->db->one(
            'SELECT id, site_id, folder_key, name, parent_id, sort_order FROM media_folders WHERE site_id = :site_id AND folder_key = :folder_key LIMIT 1',
            ['site_id' => $siteId, 'folder_key' => self::normalizeFolderKey($folderKey)]
        );
        return $row ?: null;
    }

    /** @return array{id:int,site_id:int,folder_key:string,name:string,parent_id:int|null,sort_order:int} */
    public function requireFolder(int $siteId, int $folderId): array
    {
        $row = $this->db->one(
            'SELECT id, site_id, folder_key, name, parent_id, sort_order FROM media_folders WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $folderId, 'site_id' => $siteId]
        );
        if (!$row) {
            throw new \InvalidArgumentException('MEDIA_FOLDER_NOT_FOUND');
        }
        return $row;
    }

    public function assertFolderBelongsToSite(int $siteId, int $folderId): void
    {
        $this->requireFolder($siteId, $folderId);
    }

    private function inferParentId(int $siteId, string $folderKey): ?int
    {
        $pos = strrpos($folderKey, '/');
        if ($pos === false) {
            return null;
        }
        $parentKey = substr($folderKey, 0, $pos);
        if ($parentKey === '') {
            return null;
        }
        return (int) $this->ensureFolder($siteId, $parentKey)['id'];
    }

    public static function normalizeFolderKey(string $folderKey): string
    {
        $folderKey = trim(str_replace('\\', '/', $folderKey), "/ \t\n\r\0\x0B");
        $folderKey = preg_replace('#/+#', '/', $folderKey) ?: '';
        if ($folderKey === '') {
            $folderKey = date('Y/m');
        }
        $parts = array_values(array_filter(explode('/', $folderKey), static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..'));
        $parts = array_map(static function (string $part): string {
            $part = trim($part);
            $part = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $part) ?: 'folder';
            return trim($part, '-_.') ?: 'folder';
        }, $parts);
        return implode('/', $parts) ?: date('Y/m');
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        return $name !== '' ? mb_substr($name, 0, 120) : 'Dossier';
    }
}
