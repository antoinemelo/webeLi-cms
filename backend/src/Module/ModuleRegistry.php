<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class ModuleRegistry
{
    /** @var array<string,ModuleProvider> */
    private array $providers = [];
    private bool $booted = false;

    public function __construct(
        private readonly Database $coreDb,
        private readonly array $config,
        private readonly ?Logger $logger = null,
    ) {}

    /** @return list<ModuleProvider> Providers installés et activés, utilisés par le runtime. */
    public function providers(): array
    {
        return $this->activeProviders();
    }

    /** @return list<ModuleProvider> Tous les providers connus, installés ou non. */
    public function allProviders(): array
    {
        $this->boot();
        return array_values($this->providers);
    }

    /** @return list<ModuleProvider> */
    public function activeProviders(): array
    {
        $this->boot();
        return array_values(array_filter($this->providers, fn(ModuleProvider $provider): bool => $this->isEnabled($provider->key())));
    }

    public function reset(): void
    {
        $this->providers = [];
        $this->booted = false;
    }

    public function has(string $moduleKey): bool
    {
        $this->boot();
        return isset($this->providers[$moduleKey]);
    }

    public function get(string $moduleKey): ?ModuleProvider
    {
        $this->boot();
        return $this->providers[$moduleKey] ?? null;
    }

    /** @return array<string,mixed> */
    public function state(string $moduleKey): array
    {
        try {
            $row = $this->coreDb->one('SELECT * FROM modules WHERE module_key = :module_key LIMIT 1', ['module_key' => $moduleKey]);
            if (is_array($row)) {
                return $row;
            }
        } catch (\Throwable) {
            // La table modules peut ne pas encore exister pendant l'init from scratch.
        }

        $enabled = in_array($moduleKey, $this->configuredEnabledKeys(), true);
        return ['module_key' => $moduleKey, 'is_installed' => $enabled ? 1 : 0, 'is_enabled' => $enabled ? 1 : 0];
    }

    public function isInstalled(string $moduleKey): bool
    {
        return (bool) ((int) ($this->state($moduleKey)['is_installed'] ?? 0));
    }

    public function isEnabled(string $moduleKey): bool
    {
        $state = $this->state($moduleKey);
        return (bool) ((int) ($state['is_installed'] ?? 0)) && (bool) ((int) ($state['is_enabled'] ?? 0));
    }

    /** @return list<array<string,mixed>> */
    public function contentTypes(): array
    {
        $types = [];
        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->contentTypes() as $type) {
                $type['module_key'] = $provider->key();
                $types[] = $type;
            }
        }
        return $types;
    }

    /** @return array<string,list<callable|string>> */
    public function hooks(): array
    {
        $hooks = [];
        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->hooks() as $hookName => $handlers) {
                foreach ($handlers as $handler) {
                    $hooks[$hookName][] = $handler;
                }
            }
        }
        return $hooks;
    }

    /** @return array<string,string> */
    public function migrations(): array
    {
        $migrations = [];
        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->migrations() as $name => $migration) {
                if (is_array($migration)) {
                    $sql = (string) ($migration['sql'] ?? '');
                    $database = (string) ($migration['database'] ?? 'core');
                    if ($database !== 'core') {
                        // Les migrations multi-bases sont appliquées par ModuleLifecycleService::migrate().
                        continue;
                    }
                    $name = (string) ($migration['name'] ?? $name);
                } else {
                    $sql = (string) $migration;
                }
                $safeName = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', (string) $name) ?: 'migration.sql';
                $migrations['module:' . $provider->key() . ':' . $safeName] = $sql;
            }
        }
        return $migrations;
    }

    public function syncManifest(): void
    {
        $this->boot();
        foreach ($this->providers as $provider) {
            $configuredEnabled = in_array($provider->key(), $this->configuredEnabledKeys(), true);
            $state = $this->state($provider->key());
            $installed = isset($state['id']) ? (int) ($state['is_installed'] ?? 0) : (int) $configuredEnabled;
            $enabled = isset($state['id']) ? (int) ($state['is_enabled'] ?? 0) : (int) $configuredEnabled;
            $stmt = $this->coreDb->pdo()->prepare(
                'INSERT INTO modules(module_key, name, version, provider_class, is_installed, is_enabled, config_json, updated_at)
                 VALUES(:module_key, :name, :version, :provider_class, :is_installed, :is_enabled, :config_json, :updated_at)
                 ON CONFLICT(module_key) DO UPDATE SET
                    name = excluded.name,
                    version = excluded.version,
                    provider_class = excluded.provider_class,
                    config_json = excluded.config_json,
                    updated_at = excluded.updated_at'
            );
            $stmt->execute([
                'module_key' => $provider->key(),
                'name' => $provider->name(),
                'version' => $provider->version(),
                'provider_class' => $provider::class,
                'is_installed' => $installed,
                'is_enabled' => $enabled,
                'config_json' => json_encode(['description' => $provider->description()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now_utc(),
            ]);
        }
    }

    public function syncContentTypes(): void
    {
        $this->syncManifest();
        foreach ($this->activeProviders() as $provider) {
            $moduleId = $this->moduleId($provider->key());
            if ($moduleId < 1) {
                continue;
            }
            foreach ($provider->contentTypes() as $type) {
                $typeKey = trim((string) ($type['type_key'] ?? ''));
                if ($typeKey === '') {
                    $this->logger?->warning('module.content_type_without_key', ['module' => $provider->key()]);
                    continue;
                }

                $stmt = $this->coreDb->pdo()->prepare(
                    'INSERT INTO content_types(
                        module_id, type_key, name, singular_label, plural_label, description, icon,
                        is_system, is_hidden, storage_mode, has_localizations, has_revisions,
                        has_workflow, has_permalink, has_layout, has_taxonomies, has_seo,
                        has_publish_window, default_status, default_sort, frontend_template,
                        frontend_resolver, api_enabled, admin_enabled, updated_at
                    ) VALUES(
                        :module_id, :type_key, :name, :singular_label, :plural_label, :description, :icon,
                        :is_system, :is_hidden, :storage_mode, :has_localizations, :has_revisions,
                        :has_workflow, :has_permalink, :has_layout, :has_taxonomies, :has_seo,
                        :has_publish_window, :default_status, :default_sort, :frontend_template,
                        :frontend_resolver, :api_enabled, :admin_enabled, :updated_at
                    ) ON CONFLICT(type_key) DO UPDATE SET
                        module_id = excluded.module_id,
                        name = excluded.name,
                        singular_label = excluded.singular_label,
                        plural_label = excluded.plural_label,
                        description = excluded.description,
                        icon = excluded.icon,
                        is_hidden = excluded.is_hidden,
                        storage_mode = excluded.storage_mode,
                        has_localizations = excluded.has_localizations,
                        has_revisions = excluded.has_revisions,
                        has_workflow = excluded.has_workflow,
                        has_permalink = excluded.has_permalink,
                        has_layout = excluded.has_layout,
                        has_taxonomies = excluded.has_taxonomies,
                        has_seo = excluded.has_seo,
                        has_publish_window = excluded.has_publish_window,
                        default_status = excluded.default_status,
                        default_sort = excluded.default_sort,
                        frontend_template = excluded.frontend_template,
                        frontend_resolver = excluded.frontend_resolver,
                        api_enabled = excluded.api_enabled,
                        admin_enabled = excluded.admin_enabled,
                        updated_at = excluded.updated_at'
                );
                $stmt->execute([
                    'module_id' => $moduleId,
                    'type_key' => $typeKey,
                    'name' => (string) ($type['name'] ?? $typeKey),
                    'singular_label' => (string) ($type['singular_label'] ?? $type['name'] ?? $typeKey),
                    'plural_label' => (string) ($type['plural_label'] ?? $type['name'] ?? $typeKey),
                    'description' => $type['description'] ?? null,
                    'icon' => $type['icon'] ?? null,
                    'is_system' => (int) ($type['is_system'] ?? 0),
                    'is_hidden' => (int) ($type['is_hidden'] ?? 0),
                    'storage_mode' => (string) ($type['storage_mode'] ?? 'hybrid'),
                    'has_localizations' => (int) ($type['has_localizations'] ?? 1),
                    'has_revisions' => (int) ($type['has_revisions'] ?? 1),
                    'has_workflow' => (int) ($type['has_workflow'] ?? 1),
                    'has_permalink' => (int) ($type['has_permalink'] ?? 1),
                    'has_layout' => (int) ($type['has_layout'] ?? 0),
                    'has_taxonomies' => (int) ($type['has_taxonomies'] ?? 0),
                    'has_seo' => (int) ($type['has_seo'] ?? 1),
                    'has_publish_window' => (int) ($type['has_publish_window'] ?? 1),
                    'default_status' => (string) ($type['default_status'] ?? 'draft'),
                    'default_sort' => (string) ($type['default_sort'] ?? '-published_at'),
                    'frontend_template' => $type['frontend_template'] ?? null,
                    'frontend_resolver' => $type['frontend_resolver'] ?? null,
                    'api_enabled' => (int) ($type['api_enabled'] ?? 1),
                    'admin_enabled' => (int) ($type['admin_enabled'] ?? 1),
                    'updated_at' => now_utc(),
                ]);

                $contentTypeId = $this->contentTypeId($typeKey);
                $this->syncFields($contentTypeId, is_array($type['fields'] ?? null) ? $type['fields'] : []);
            }
        }
    }

    /** @param list<array<string,mixed>> $fields */
    private function syncFields(int $contentTypeId, array $fields): void
    {
        if ($contentTypeId < 1) {
            return;
        }
        foreach ($fields as $index => $field) {
            $fieldKey = trim((string) ($field['field_key'] ?? ''));
            if ($fieldKey === '') {
                continue;
            }
            $stmt = $this->coreDb->pdo()->prepare(
                'INSERT INTO fields(
                    content_type_id, field_key, label, field_type, storage_mode, interface_key,
                    help_text, placeholder, default_value_json, options_json, validation_json,
                    is_required, is_unique, is_localized, is_indexed, is_filterable,
                    is_sortable, is_searchable, is_hidden, sort_order
                ) VALUES(
                    :content_type_id, :field_key, :label, :field_type, :storage_mode, :interface_key,
                    :help_text, :placeholder, :default_value_json, :options_json, :validation_json,
                    :is_required, :is_unique, :is_localized, :is_indexed, :is_filterable,
                    :is_sortable, :is_searchable, :is_hidden, :sort_order
                ) ON CONFLICT(content_type_id, field_key) DO UPDATE SET
                    label = excluded.label,
                    field_type = excluded.field_type,
                    storage_mode = excluded.storage_mode,
                    interface_key = excluded.interface_key,
                    help_text = excluded.help_text,
                    placeholder = excluded.placeholder,
                    default_value_json = excluded.default_value_json,
                    options_json = excluded.options_json,
                    validation_json = excluded.validation_json,
                    is_required = excluded.is_required,
                    is_unique = excluded.is_unique,
                    is_localized = excluded.is_localized,
                    is_indexed = excluded.is_indexed,
                    is_filterable = excluded.is_filterable,
                    is_sortable = excluded.is_sortable,
                    is_searchable = excluded.is_searchable,
                    is_hidden = excluded.is_hidden,
                    sort_order = excluded.sort_order'
            );
            $stmt->execute([
                'content_type_id' => $contentTypeId,
                'field_key' => $fieldKey,
                'label' => (string) ($field['label'] ?? $fieldKey),
                'field_type' => (string) ($field['field_type'] ?? 'text'),
                'storage_mode' => (string) ($field['storage_mode'] ?? 'value_table'),
                'interface_key' => $field['interface_key'] ?? null,
                'help_text' => $field['help_text'] ?? null,
                'placeholder' => $field['placeholder'] ?? null,
                'default_value_json' => $this->jsonOrNull($field['default_value_json'] ?? $field['default_value'] ?? null),
                'options_json' => $this->jsonOrNull($field['options_json'] ?? $field['options'] ?? null),
                'validation_json' => $this->jsonOrNull($field['validation_json'] ?? $field['validation'] ?? null),
                'is_required' => (int) ($field['is_required'] ?? 0),
                'is_unique' => (int) ($field['is_unique'] ?? 0),
                'is_localized' => (int) ($field['is_localized'] ?? 1),
                'is_indexed' => (int) ($field['is_indexed'] ?? 0),
                'is_filterable' => (int) ($field['is_filterable'] ?? 0),
                'is_sortable' => (int) ($field['is_sortable'] ?? 0),
                'is_searchable' => (int) ($field['is_searchable'] ?? 0),
                'is_hidden' => (int) ($field['is_hidden'] ?? 0),
                'sort_order' => (int) ($field['sort_order'] ?? ($index + 1) * 10),
            ]);
        }
    }

    private function moduleId(string $moduleKey): int
    {
        $stmt = $this->coreDb->pdo()->prepare('SELECT id FROM modules WHERE module_key = ? LIMIT 1');
        $stmt->execute([$moduleKey]);
        return (int) $stmt->fetchColumn();
    }

    private function contentTypeId(string $typeKey): int
    {
        $stmt = $this->coreDb->pdo()->prepare('SELECT id FROM content_types WHERE type_key = ? LIMIT 1');
        $stmt->execute([$typeKey]);
        return (int) $stmt->fetchColumn();
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /** @return list<string> */
    public function applyMigrations(): array
    {
        $this->ensureModuleMigrationLog();
        $done = $this->appliedModuleMigrations();
        $applied = [];

        foreach ($this->migrations() as $name => $sql) {
            if (in_array($name, $done, true) || trim($sql) === '') {
                continue;
            }
            $this->coreDb->pdo()->exec($sql);
            $this->markModuleMigrationApplied($name);
            $applied[] = $name;
        }

        if ($applied !== []) {
            $this->logger?->info('module.migrations.applied', ['migrations' => $applied]);
        }
        return $applied;
    }

    private function ensureModuleMigrationLog(): void
    {
        $pdo = $this->coreDb->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, migrated_at TEXT NOT NULL)');
        $columns = $this->migrationLogColumns();

        if (!in_array('migration', $columns, true)) {
            $pdo->exec('ALTER TABLE schema_migrations ADD COLUMN migration TEXT');
            $columns[] = 'migration';
        }
        if (!in_array('migrated_at', $columns, true)) {
            $pdo->exec('ALTER TABLE schema_migrations ADD COLUMN migrated_at TEXT');
            $columns[] = 'migrated_at';
        }
        if (in_array('migration_name', $columns, true)) {
            $pdo->exec("UPDATE schema_migrations SET migration = migration_name WHERE migration IS NULL OR migration = ''");
        }
        if (in_array('applied_at', $columns, true)) {
            $pdo->exec("UPDATE schema_migrations SET migrated_at = applied_at WHERE migrated_at IS NULL OR migrated_at = ''");
        }
        $stmt = $pdo->prepare("UPDATE schema_migrations SET migrated_at = ? WHERE migrated_at IS NULL OR migrated_at = ''");
        $stmt->execute([now_utc()]);
    }

    /** @return list<string> */
    private function migrationLogColumns(): array
    {
        $stmt = $this->coreDb->pdo()->query('PRAGMA table_info(schema_migrations)');
        if (!$stmt) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => isset($row['name']) ? (string) $row['name'] : null,
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        )));
    }

    /** @return list<string> */
    private function appliedModuleMigrations(): array
    {
        $pdo = $this->coreDb->pdo();
        $columns = $this->migrationLogColumns();
        $done = [];

        if (in_array('migration', $columns, true)) {
            foreach ($pdo->query("SELECT migration FROM schema_migrations WHERE migration IS NOT NULL AND migration != ''")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $done[] = (string) $row['migration'];
            }
        }

        if (in_array('scope', $columns, true) && in_array('migration_name', $columns, true)) {
            $stmt = $pdo->prepare("SELECT migration_name FROM schema_migrations WHERE scope = 'module' AND migration_name IS NOT NULL AND migration_name != ''");
            $stmt->execute();
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $done[] = (string) $row['migration_name'];
            }
        }

        return array_values(array_unique($done));
    }

    private function markModuleMigrationApplied(string $name): void
    {
        $pdo = $this->coreDb->pdo();
        $columns = $this->migrationLogColumns();
        $now = now_utc();

        if (in_array('scope', $columns, true) && in_array('migration_name', $columns, true)) {
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO schema_migrations(scope, migration_name, migration, applied_at, migrated_at) VALUES(?, ?, ?, ?, ?)'
            );
            $stmt->execute(['module', $name, $name, $now, $now]);
            return;
        }

        $stmt = $pdo->prepare('INSERT OR IGNORE INTO schema_migrations(migration, migrated_at) VALUES(?, ?)');
        $stmt->execute([$name, $now]);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $classes = array_merge(
            $this->providerClassesFromConfig(),
            $this->providerClassesFromDatabase(),
        );

        $normalized = [];
        foreach ($classes as $class) {
            $class = $this->normalizeProviderClass($class);
            if ($class !== '') {
                $normalized[strtolower($class)] = $class;
            }
        }

        foreach (array_values($normalized) as $class) {
            $this->registerProviderClass($class);
        }
    }

    /**
     * Canonicalise a provider FQCN coming from PHP configuration or SQLite.
     *
     * SQLite string literals do not treat the backslash as an escape character.
     * A SQL value written as App\\Modules\\Foo therefore contains doubled
     * separators. Composer can resolve that malformed name to the same file as
     * App\Modules\Foo, while PHP still considers both class-name strings
     * distinct during class_exists(); loading the file twice then causes a fatal
     * "Cannot redeclare class" error.
     */
    private function normalizeProviderClass(string $class): string
    {
        $class = trim($class);
        $class = preg_replace('/\\\\+/', '\\', $class) ?? $class;
        return ltrim($class, '\\');
    }

    /** @return list<class-string> */
    private function providerClassesFromConfig(): array
    {
        $modules = $this->config['modules'] ?? [];
        $providers = [];
        if (is_array($modules)) {
            foreach (($modules['providers'] ?? []) as $class) {
                if (is_string($class) && $class !== '') {
                    $providers[] = $class;
                }
            }
            foreach ($modules as $item) {
                if (is_string($item) && str_contains($item, '\\')) {
                    $providers[] = $item;
                }
            }
        }
        return array_values(array_unique($providers));
    }

    /** @return list<string> */
    private function configuredEnabledKeys(): array
    {
        $modules = $this->config['modules'] ?? [];
        $enabled = is_array($modules) && is_array($modules['enabled'] ?? null) ? $modules['enabled'] : [];
        return array_values(array_filter(array_map(static fn(mixed $key): string => is_string($key) ? $key : '', $enabled)));
    }

    /** @return list<class-string> */
    private function providerClassesFromDatabase(): array
    {
        try {
            $rows = $this->coreDb->pdo()->query("SELECT provider_class FROM modules WHERE is_installed = 1 AND provider_class IS NOT NULL AND provider_class <> ''")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string) ($row['provider_class'] ?? ''), $rows))));
    }

    private function registerProviderClass(string $class): void
    {
        if (!class_exists($class)) {
            $this->logger?->warning('module.provider_missing', ['class' => $class]);
            return;
        }
        $provider = new $class();
        if (!$provider instanceof ModuleProvider) {
            $this->logger?->warning('module.provider_invalid', ['class' => $class, 'expected' => ModuleProvider::class]);
            return;
        }
        $key = trim($provider->key());
        if ($key === '') {
            $this->logger?->warning('module.provider_without_key', ['class' => $class]);
            return;
        }
        $this->providers[$key] = $provider;
    }
}
