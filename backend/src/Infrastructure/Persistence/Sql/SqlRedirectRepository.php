<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Routing\RedirectRepository;
use App\Core\Database;
use App\Domain\Routing\Redirect;

final class SqlRedirectRepository implements RedirectRepository
{
    public function __construct(private readonly Database $db) {}

    public function deleteByResource(string $resourceType, int $resourceId): void
    {
        $this->db->run('DELETE FROM redirects WHERE resource_type = :resource_type AND resource_id = :resource_id', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function replace(Redirect $redirect): void
    {
        if ($redirect->oldPath === $redirect->newPath) {
            return;
        }
        $this->db->run('DELETE FROM redirects WHERE site_id = :site_id AND old_path = :old_path AND COALESCE(language_code, \'\') = COALESCE(:language_code, \'\')', [
            'site_id' => $redirect->siteId,
            'old_path' => $redirect->oldPath,
            'language_code' => $redirect->languageCode,
        ]);
        $now = now_utc();
        $this->db->run('INSERT INTO redirects(site_id, language_code, old_path, new_path, http_code, redirect_reason, is_active, resource_type, resource_id, created_at, updated_at) VALUES(:site_id, :language_code, :old_path, :new_path, :http_code, :redirect_reason, :is_active, :resource_type, :resource_id, :created_at, :updated_at)', [
            'site_id' => $redirect->siteId,
            'language_code' => $redirect->languageCode,
            'old_path' => $redirect->oldPath,
            'new_path' => $redirect->newPath,
            'http_code' => $redirect->httpCode,
            'redirect_reason' => $redirect->reason,
            'is_active' => $redirect->isActive ? 1 : 0,
            'resource_type' => $redirect->resourceType,
            'resource_id' => $redirect->resourceId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
