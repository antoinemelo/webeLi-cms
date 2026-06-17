<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Database;
use App\Core\Logger;

final class ModulePermissionRegistrar
{
    public function __construct(
        private readonly Database $coreDb,
        private readonly Database $iamDb,
        private readonly ?Logger $logger = null,
    ) {}

    public function register(ModuleProvider $provider): void
    {
        foreach ($provider->permissions() as $permission) {
            $key = trim((string) ($permission['key'] ?? $permission['permission_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $this->iamDb->run(
                'INSERT INTO iam_permissions(permission_key, name, description)
                 VALUES(:permission_key, :name, :description)
                 ON CONFLICT(permission_key) DO UPDATE SET
                    name = excluded.name,
                    description = excluded.description',
                [
                    'permission_key' => $key,
                    'name' => (string) ($permission['name'] ?? $key),
                    'description' => (string) ($permission['description'] ?? ('Permission module ' . $provider->key())),
                ]
            );
            if ($this->coreDb->tableExists('module_permissions')) {
                $this->coreDb->run(
                    'INSERT OR IGNORE INTO module_permissions(module_key, permission_key, created_at)
                     VALUES(:module_key, :permission_key, :created_at)',
                    ['module_key' => $provider->key(), 'permission_key' => $key, 'created_at' => $this->now()]
                );
            }
        }
    }

    /** @return list<string> */
    public function missing(ModuleProvider $provider): array
    {
        $missing = [];
        foreach ($provider->permissions() as $permission) {
            $key = trim((string) ($permission['key'] ?? $permission['permission_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $row = $this->iamDb->one('SELECT id FROM iam_permissions WHERE permission_key = :key LIMIT 1', ['key' => $key]);
            if (!$row) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function now(): string
    {
        return function_exists('now_utc') ? now_utc() : gmdate('Y-m-d H:i:s');
    }
}
