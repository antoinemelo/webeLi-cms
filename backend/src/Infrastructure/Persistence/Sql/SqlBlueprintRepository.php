<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Blueprint\BlueprintRepository;
use App\Application\Blueprint\BlueprintSchemaCanonicalizer;
use App\Application\Content\BlockDocumentNormalizer;
use App\Core\Database;

final class SqlBlueprintRepository implements BlueprintRepository
{
    /** @var list<string> */
    private const PROTECTED_BLUEPRINTS = ['page', 'article', 'page_archive', 'article_archive'];

    /** @var list<string> */
    private const PROTECTED_FIELDS = ['id', 'title', 'slug', 'entry_key', 'status', 'workflow_state', 'language', 'site', 'site_id', 'revision_state', 'content_type', 'blueprint_id', 'blueprint_version_id', 'published_at', 'updated_at', 'created_at'];

    /** @var list<string> */
    private const SYSTEM_CONTEXT_FIELDS = ['status', 'workflow_state', 'language', 'site', 'site_id', 'revision_state', 'content_type', 'blueprint_id', 'blueprint_version_id'];

    /** @var list<string> */
    private const SYSTEM_EDITABLE_FIELDS = ['title', 'slug', 'entry_key', 'published_at', 'template'];

    /** @var list<string> */
    private const RESERVED_HANDLES = ['admin', 'api', 'assets', 'backend', 'config', 'database', 'docs', 'media', 'public', 'storage', 'system', 'user', 'users', 'roles', 'login', 'logout', 'preview', 'search', 'sitemap', 'robots', 'canonical'];

    public function __construct(
        private readonly Database $db,
        private readonly BlueprintSchemaCanonicalizer $canonicalizer = new BlueprintSchemaCanonicalizer(),
    ) {}

    public function list(?string $resourceType = null, ?int $siteId = null): array
    {
        $where = [];
        $params = [];
        if ($resourceType !== null && $resourceType !== '') {
            $where[] = 'b.resource_type = :resource_type';
            $params['resource_type'] = $resourceType;
        }
        if ($siteId !== null) {
            $where[] = '(b.site_id = :site_id OR b.site_id IS NULL)';
            $params['site_id'] = $siteId;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = $this->db->all(
            "SELECT b.*, bv.version AS active_version, bv.version_label AS active_version_label
             FROM blueprints b
             LEFT JOIN blueprint_versions bv ON bv.id = b.active_version_id
             {$sqlWhere}
             ORDER BY b.resource_type, b.site_id IS NOT NULL DESC, b.blueprint_key",
            $params,
        );
        return array_map(fn(array $row): array => $this->normalizeBlueprintRow($row), $rows);
    }

    public function findByKey(string $key, ?string $resourceType = null, ?int $siteId = null): ?array
    {
        [$where, $params] = $this->keyWhere($key, $resourceType, $siteId);
        $row = $this->db->one(
            "SELECT b.*, bv.version AS active_version, bv.version_label AS active_version_label
             FROM blueprints b
             LEFT JOIN blueprint_versions bv ON bv.id = b.active_version_id
             WHERE {$where}
             ORDER BY b.site_id IS NOT NULL DESC
             LIMIT 1",
            $params,
        );
        return $row ? $this->normalizeBlueprintRow($row) : null;
    }

    public function versions(string $key, ?string $resourceType = null, ?int $siteId = null): array
    {
        $blueprint = $this->findByKey($key, $resourceType, $siteId);
        if (!$blueprint) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT * FROM blueprint_versions WHERE blueprint_id = :blueprint_id ORDER BY version DESC',
            ['blueprint_id' => (int) $blueprint['id']],
        );
        return array_map(fn(array $row): array => $this->normalizeVersionRow($row), $rows);
    }

    public function activeVersion(string $key, ?string $resourceType = null, ?int $siteId = null): ?array
    {
        $blueprint = $this->findByKey($key, $resourceType, $siteId);
        if (!$blueprint || empty($blueprint['active_version_id'])) {
            return null;
        }
        $row = $this->db->one(
            'SELECT * FROM blueprint_versions WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $blueprint['active_version_id']],
        );
        return $row ? $this->normalizeVersionRow($row) : null;
    }


    public function findVersionById(int $versionId): ?array
    {
        $row = $this->db->one('SELECT * FROM blueprint_versions WHERE id = :id LIMIT 1', ['id' => $versionId]);
        return $row ? $this->normalizeVersionRow($row) : null;
    }

    public function versionForRevision(int $revisionId): ?array
    {
        $row = $this->db->one(
            'SELECT bv.* FROM revisions r JOIN blueprint_versions bv ON bv.id = r.blueprint_version_id WHERE r.id = :id LIMIT 1',
            ['id' => $revisionId],
        );
        return $row ? $this->normalizeVersionRow($row) : null;
    }

    public function createBlueprint(array $payload): array
    {
        $key = $this->normalizeKey((string) ($payload['blueprint_key'] ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException('blueprint_key est obligatoire.');
        }
        $resourceType = (string) ($payload['resource_type'] ?? 'content_type');
        $siteId = isset($payload['site_id']) && $payload['site_id'] !== '' ? (int) $payload['site_id'] : null;
        $legacyContentTypeId = isset($payload['legacy_content_type_id']) && $payload['legacy_content_type_id'] !== '' ? (int) $payload['legacy_content_type_id'] : null;
        $label = trim((string) ($payload['label'] ?? ucfirst($key)));
        if ($label === '') {
            $label = $key;
        }
        $description = isset($payload['description']) ? (string) $payload['description'] : null;
        $isActive = array_key_exists('is_active', $payload) ? ((bool) $payload['is_active'] ? 1 : 0) : 1;

        $this->db->run(
            'INSERT INTO blueprints(blueprint_key, resource_type, site_id, legacy_content_type_id, label, description, is_active)
             VALUES(:blueprint_key, :resource_type, :site_id, :legacy_content_type_id, :label, :description, :is_active)',
            [
                'blueprint_key' => $key,
                'resource_type' => $resourceType,
                'site_id' => $siteId,
                'legacy_content_type_id' => $legacyContentTypeId,
                'label' => $label,
                'description' => $description,
                'is_active' => $isActive,
            ],
        );
        return $this->findByKey($key, $resourceType, $siteId) ?? [];
    }

    public function createVersion(string $key, array $payload, ?int $siteId = null): array
    {
        $resourceType = (string) ($payload['resource_type'] ?? 'content_type');
        $blueprint = $this->findByKey($key, $resourceType, $siteId);
        if (!$blueprint) {
            throw new \RuntimeException(sprintf('Blueprint introuvable : %s.', $key));
        }
        $version = isset($payload['version']) ? (int) $payload['version'] : $this->nextVersion((int) $blueprint['id']);
        $status = (string) ($payload['status'] ?? 'draft');
        $isActive = (bool) ($payload['is_active'] ?? ($status === 'active'));
        if ($isActive) {
            $status = 'active';
        }
        $normalizedPolicies = $this->canonicalizer->normalizeVersionPayload($payload);
        $json = fn(string $key, array $default = []): string => $this->encodePolicy($normalizedPolicies[$key] ?? $default, $key);
        $checksum = $this->canonicalizer->checksum($normalizedPolicies);
        if ($isActive) {
            $this->assertVersionPayloadCanBeActivated($blueprint, $payload);
        }

        $this->db->transaction(function () use ($blueprint, $version, $payload, $status, $isActive, $json): void {
            if ($isActive) {
                $this->db->run('UPDATE blueprint_versions SET is_active = 0, status = CASE WHEN status = \'active\' THEN \'archived\' ELSE status END, archived_at = CURRENT_TIMESTAMP WHERE blueprint_id = :blueprint_id AND is_active = 1', ['blueprint_id' => (int) $blueprint['id']]);
            }
            $this->db->run(
                'INSERT INTO blueprint_versions(
                    blueprint_id, version, version_label, status, schema_json, ui_schema_json, validation_json,
                    seo_policy_json, routing_policy_json, workflow_policy_json, translation_policy_json,
                    permissions_policy_json, checksum_sha256, is_active, activated_at
                 ) VALUES(
                    :blueprint_id, :version, :version_label, :status, :schema_json, :ui_schema_json, :validation_json,
                    :seo_policy_json, :routing_policy_json, :workflow_policy_json, :translation_policy_json,
                    :permissions_policy_json, :checksum_sha256, :is_active, :activated_at
                 )',
                [
                    'blueprint_id' => (int) $blueprint['id'],
                    'version' => $version,
                    'version_label' => isset($payload['version_label']) ? (string) $payload['version_label'] : null,
                    'status' => $status,
                    'schema_json' => $json('schema_json', []),
                    'ui_schema_json' => $json('ui_schema_json', []),
                    'validation_json' => $json('validation_json', []),
                    'seo_policy_json' => $json('seo_policy_json', []),
                    'routing_policy_json' => $json('routing_policy_json', []),
                    'workflow_policy_json' => $json('workflow_policy_json', []),
                    'translation_policy_json' => $json('translation_policy_json', []),
                    'permissions_policy_json' => $json('permissions_policy_json', []),
                    'checksum_sha256' => $checksum,
                    'is_active' => $isActive ? 1 : 0,
                    'activated_at' => $isActive ? gmdate('Y-m-d H:i:s') : null,
                ],
            );
        });

        $row = $this->db->one('SELECT * FROM blueprint_versions WHERE blueprint_id = :blueprint_id AND version = :version', ['blueprint_id' => (int) $blueprint['id'], 'version' => $version]);
        return $row ? $this->normalizeVersionRow($row) : [];
    }

    public function activate(string $key, int $version, ?int $siteId = null): array
    {
        $resourceType = (string) (($this->db->one('SELECT resource_type FROM blueprints WHERE blueprint_key = :key AND (site_id = :site_id OR site_id IS NULL) ORDER BY site_id IS NOT NULL DESC LIMIT 1', ['key' => $this->normalizeKey($key), 'site_id' => $siteId])['resource_type'] ?? 'content_type'));
        $blueprint = $this->findByKey($key, $resourceType, $siteId);
        if (!$blueprint) {
            throw new \RuntimeException(sprintf('Blueprint introuvable : %s.', $key));
        }
        $versionRow = $this->db->one('SELECT * FROM blueprint_versions WHERE blueprint_id = :blueprint_id AND version = :version LIMIT 1', ['blueprint_id' => (int) $blueprint['id'], 'version' => $version]);
        if (!$versionRow) {
            throw new \RuntimeException(sprintf('Version de blueprint introuvable : %s@%d.', $key, $version));
        }
        $this->assertStoredVersionCanBeActivated($blueprint, $versionRow);
        $this->db->transaction(function () use ($blueprint, $versionRow): void {
            $this->db->run('UPDATE blueprint_versions SET is_active = 0, status = CASE WHEN status = \'active\' THEN \'archived\' ELSE status END, archived_at = CURRENT_TIMESTAMP WHERE blueprint_id = :blueprint_id AND is_active = 1', ['blueprint_id' => (int) $blueprint['id']]);
            $this->db->run('UPDATE blueprint_versions SET is_active = 1, status = \'active\', activated_at = CURRENT_TIMESTAMP, archived_at = NULL WHERE id = :id', ['id' => (int) $versionRow['id']]);
            $this->db->run('UPDATE blueprints SET active_version_id = :version_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id', ['version_id' => (int) $versionRow['id'], 'id' => (int) $blueprint['id']]);
        });
        return $this->findByKey($key, $resourceType, $siteId) ?? [];
    }


    public function modelOverview(?int $siteId = null): array
    {
        if (!$this->db->tableExists('blueprint_fields') || !$this->db->tableExists('fieldsets')) {
            return [
                'summary' => ['blueprints' => 0, 'sections' => 0, 'fields' => 0, 'fieldsets' => 0],
                'blueprints' => [],
                'fieldsets' => [],
                'system_fields' => [],
            ];
        }

        $params = [];
        $siteWhere = '';
        if ($siteId !== null) {
            $siteWhere = 'WHERE b.site_id = :site_id OR b.site_id IS NULL';
            $params['site_id'] = $siteId;
        }

        $blueprints = $this->db->all(
            "SELECT b.id, b.blueprint_key, b.resource_type, b.site_id, b.legacy_content_type_id, b.label, b.description, b.is_active,
                    bv.version AS active_version, bv.version_label AS active_version_label, ct.type_key AS content_type_key, COUNT(DISTINCT bu.id) AS usage_count,
                    COUNT(DISTINCT bs.id) AS sections_count,
                    COUNT(DISTINCT bf.id) AS fields_count,
                    COUNT(DISTINCT bfs.id) AS fieldsets_count
             FROM blueprints b
             LEFT JOIN blueprint_versions bv ON bv.id = b.active_version_id
             LEFT JOIN content_types ct ON ct.id = b.legacy_content_type_id
             LEFT JOIN blueprint_usage bu ON bu.blueprint_id = b.id
             LEFT JOIN blueprint_sections bs ON bs.blueprint_id = b.id
             LEFT JOIN blueprint_fields bf ON bf.blueprint_id = b.id
             LEFT JOIN blueprint_fieldsets bfs ON bfs.blueprint_id = b.id
             {$siteWhere}
             GROUP BY b.id
             ORDER BY b.resource_type, b.blueprint_key",
            $params,
        );

        $fieldsetRows = $this->db->all(
            "SELECT fs.id, fs.fieldset_key, fs.label, fs.fieldset_purpose, fs.is_system, fs.is_deletable,
                    COUNT(ff.id) AS fields_count
             FROM fieldsets fs
             LEFT JOIN fieldset_fields ff ON ff.fieldset_id = fs.id
             GROUP BY fs.id
             ORDER BY fs.fieldset_purpose, fs.fieldset_key"
        );

        $systemFields = $this->db->all(
            "SELECT DISTINCT field_handle, field_type, label, field_purpose, is_required, is_localized
             FROM blueprint_fields
             WHERE is_system = 1
             ORDER BY field_purpose, sort_order, field_handle"
        );

        $summary = [
            'blueprints' => count($blueprints),
            'sections' => (int) ($this->db->one('SELECT COUNT(*) AS n FROM blueprint_sections')['n'] ?? 0),
            'fields' => (int) ($this->db->one('SELECT COUNT(*) AS n FROM blueprint_fields')['n'] ?? 0),
            'fieldsets' => count($fieldsetRows),
        ];

        return [
            'summary' => $summary,
            'blueprints' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'blueprint_key' => (string) $row['blueprint_key'],
                'resource_type' => (string) $row['resource_type'],
                'site_id' => $row['site_id'] === null ? null : (int) $row['site_id'],
                'legacy_content_type_id' => $row['legacy_content_type_id'] === null ? null : (int) $row['legacy_content_type_id'],
                'content_type_key' => $row['content_type_key'] === null ? null : (string) $row['content_type_key'],
                'label' => (string) $row['label'],
                'description' => (string) ($row['description'] ?? ''),
                'is_active' => (bool) ((int) $row['is_active']),
                'active_version' => $row['active_version'] === null ? null : (int) $row['active_version'],
                'active_version_label' => $row['active_version_label'] === null ? null : (string) $row['active_version_label'],
                'usage_count' => (int) ($row['usage_count'] ?? 0),
                'sections_count' => (int) $row['sections_count'],
                'fields_count' => (int) $row['fields_count'],
                'fieldsets_count' => (int) $row['fieldsets_count'],
            ], $blueprints),
            'fieldsets' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'fieldset_key' => (string) $row['fieldset_key'],
                'label' => (string) $row['label'],
                'fieldset_purpose' => (string) $row['fieldset_purpose'],
                'is_system' => (bool) ((int) $row['is_system']),
                'is_deletable' => (bool) ((int) $row['is_deletable']),
                'fields_count' => (int) $row['fields_count'],
            ], $fieldsetRows),
            'system_fields' => array_map(static fn(array $row): array => [
                'field_handle' => (string) $row['field_handle'],
                'field_type' => (string) $row['field_type'],
                'label' => (string) $row['label'],
                'field_purpose' => (string) $row['field_purpose'],
                'is_required' => (bool) ((int) $row['is_required']),
                'is_localized' => (bool) ((int) $row['is_localized']),
            ], $systemFields),
            'governance' => $this->governanceRules(),
            'audit' => $this->audit(null, null, $siteId),
            'presets' => $this->presets(),
        ];
    }


    /** @return array<string,mixed> */
    public function audit(?string $key = null, ?string $resourceType = null, ?int $siteId = null): array
    {
        if (!$this->db->tableExists('blueprint_fields')) {
            return ['score' => 0, 'status' => 'unavailable', 'blocking_errors' => [], 'warnings' => [], 'optimizations' => [], 'notes' => [], 'items' => []];
        }

        $blueprints = $key !== null && $key !== ''
            ? array_values(array_filter([$this->findByKey($key, $resourceType, $siteId)]))
            : $this->list($resourceType, $siteId);

        $items = [];
        $blocking = [];
        $warnings = [];
        $optimizations = [];
        $notes = [];
        foreach ($blueprints as $blueprint) {
            if (!is_array($blueprint)) { continue; }
            $item = $this->auditOneBlueprint($blueprint);
            $items[] = $item;
            foreach ($item['blocking_errors'] as $message) { $blocking[] = sprintf('%s : %s', $blueprint['blueprint_key'], $message); }
            foreach ($item['warnings'] as $message) { $warnings[] = sprintf('%s : %s', $blueprint['blueprint_key'], $message); }
            foreach ($item['optimizations'] as $message) { $optimizations[] = sprintf('%s : %s', $blueprint['blueprint_key'], $message); }
            foreach (($item['notes'] ?? []) as $message) { $notes[] = sprintf('%s : %s', $blueprint['blueprint_key'], $message); }
        }

        $score = $items === [] ? 0 : (int) round(array_sum(array_map(static fn(array $i): int => (int) $i['score'], $items)) / count($items));
        return [
            'score' => $score,
            'status' => $blocking !== [] ? 'blocking' : ($warnings !== [] ? 'warnings' : 'ok'),
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
            'optimizations' => $optimizations,
            'notes' => $notes,
            'items' => $items,
            'help' => [
                'blocking' => 'Bloque uniquement ce qui rendrait le modèle non publiable, ambigu ou destructeur.',
                'warning' => 'Signale un risque réel à corriger avant généralisation, sans empêcher le travail éditorial.',
                'optimization' => 'Réserve les améliorations actionnables qui ajoutent une capacité absente, sans doublonner les fallbacks natifs.',
                'note' => 'Documente les choix volontaires du CMS, notamment les fallbacks SEO et les simplifications éditoriales.',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function presets(): array
    {
        return [
            $this->preset('page_standard_seo', 'Page standard SEO', 'Page classique avec titre, slug, contenu et SEO explicite lorsque l’éditeur veut surcharger les fallbacks.', ['title','slug','seo_title','seo_description','canonical_url','robots','og_title','og_description','og_image']),
            $this->preset('editorial_article', 'Article éditorial', 'Article daté avec chapô, image sociale, auteur et SEO éditable sans obligation de dupliquer les valeurs Open Graph/Twitter.', ['title','slug','excerpt','hero_image','body','seo_title','seo_description','canonical_url','robots','og_image']),
            $this->preset('light_landing_page', 'Landing page légère', 'Page marketing simple, orientée performance et conversion. La composition et le SEO restent gérés par les éditeurs natifs.', ['title','slug','campaign_label','audience','cta_label','cta_url']),
            $this->preset('reusable_block', 'Bloc réutilisable', 'Fragment éditorial stable réutilisable dans un builder léger, sans routage ni SEO public propre.', ['title','handle','body','media']),
            $this->preset('structured_collection', 'Collection structurée', 'Fiche ou élément de catalogue exposable en HTML natif et API headless, avec SEO explicite ou calculé.', ['title','slug','summary','attributes','seo_title','seo_description','canonical_url','robots']),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function governanceRules(): array
    {
        return [
            ['key' => 'protected_system_fields', 'level' => 'blocking', 'message' => 'Les champs système protégés ne peuvent pas être supprimés brutalement.'],
            ['key' => 'reserved_handles', 'level' => 'blocking', 'message' => 'Les handles réservés évitent les collisions avec le front, l’API et l’administration ; robots reste autorisé comme champ SEO éditorial.'],
            ['key' => 'published_data_guard', 'level' => 'blocking', 'message' => 'Un champ contenant des données publiées doit être désactivé, masqué ou archivé avant suppression.'],
            ['key' => 'lifecycle_states', 'level' => 'info', 'message' => 'active = visible ; disabled = non éditable ; hidden = masqué éditeur ; archived = conservé pour lecture/migration ; deleted = suppression définitive.'],
            ['key' => 'seo_fallbacks', 'level' => 'info', 'message' => 'Les champs SEO avancés absents ne sont pas des erreurs : le moteur publie des valeurs finales via title, templates, Open Graph, Twitter, robots par défaut et chemin canonique.'],
        ];
    }

    /** @return array<string,mixed> */
    private function preset(string $key, string $label, string $description, array $fields): array
    {
        return ['preset_key' => $key, 'label' => $label, 'description' => $description, 'recommended_fields' => $fields];
    }

    /** @param array<string,mixed> $blueprint @return array<string,mixed> */
    private function auditOneBlueprint(array $blueprint): array
    {
        $blueprintId = (int) $blueprint['id'];
        $fields = $this->db->all('SELECT * FROM blueprint_fields WHERE blueprint_id=:id ORDER BY sort_order, id', ['id' => $blueprintId]);
        $rawHandles = array_map(static fn(array $f): string => (string) $f['field_handle'], $fields);
        $fieldHandles = array_values(array_unique($rawHandles));
        $fieldSet = array_fill_keys($fieldHandles, true);
        $blocking = [];
        $warnings = [];
        $optimizations = [];
        $notes = [];

        $resourceType = (string) $blueprint['resource_type'];
        $blueprintKey = (string) $blueprint['blueprint_key'];
        $isBlock = $resourceType === 'block';
        $isRoutable = $this->needsSlug($resourceType, $blueprintKey);

        if ($fieldHandles === []) {
            $blocking[] = 'Aucun champ défini : le modèle ne peut pas produire de formulaire éditorial stable.';
        }
        if (!$isBlock && !isset($fieldSet['title'])) {
            $blocking[] = 'Champ title absent : impossible d’identifier clairement les contenus et d’alimenter le fallback SEO principal.';
        }
        if ($isRoutable && !isset($fieldSet['slug'])) {
            $blocking[] = 'Champ slug absent : les URLs publiques ne peuvent pas être stabilisées pour ce contenu routable.';
        }
        if ($this->hasDuplicates($rawHandles)) {
            $blocking[] = 'Des handles de champs sont dupliqués : l’enregistrement, les projections ou l’API peuvent écraser des valeurs.';
        }

        foreach ($fieldHandles as $handle) {
            if ($this->isReservedFieldHandle($handle)) {
                $blocking[] = sprintf('Le handle "%s" est réservé au routage, à l’API ou à l’administration.', $handle);
            }
        }

        foreach ($fields as $field) {
            $handle = (string) $field['field_handle'];
            $config = $this->decodeJson((string) ($field['config_json'] ?? '{}'), []);
            $state = is_array($config) ? (string) ($config['lifecycle_state'] ?? 'active') : 'active';
            $scope = is_array($config) ? (string) ($config['field_scope'] ?? $config['scope'] ?? '') : '';
            $visibility = is_array($config) ? (string) ($config['ui_visibility'] ?? $config['visibility'] ?? '') : '';

            if (!in_array($state, ['active','disabled','hidden','archived'], true)) {
                $warnings[] = sprintf('Le champ "%s" utilise un état de cycle de vie inconnu.', $handle);
            }
            if ((int) $field['is_required'] === 1 && $state !== 'active') {
                $warnings[] = sprintf('Le champ obligatoire "%s" est %s : l’éditeur peut être bloqué ou publier une valeur figée.', $handle, $state);
            }
            if (in_array($handle, self::PROTECTED_FIELDS, true) && (int) $field['is_system'] === 1 && (int) $field['is_deletable'] === 1) {
                $blocking[] = sprintf('Le champ système protégé "%s" est supprimable.', $handle);
            } elseif ((int) $field['is_system'] === 1 && (int) $field['is_deletable'] === 1) {
                $warnings[] = sprintf('Le champ système "%s" devrait rester non supprimable ou être explicitement archivé.', $handle);
            }
            if (in_array($handle, self::SYSTEM_CONTEXT_FIELDS, true) && ($scope !== 'system_context' || !in_array($visibility, ['summary','native','hidden'], true))) {
                $warnings[] = sprintf('Le champ contextuel "%s" devrait être classé system_context et affiché en résumé, native ou hidden.', $handle);
            }
        }

        if (!$isBlock) {
            if (!isset($fieldSet['seo_title'])) {
                $notes[] = 'seo_title absent : choix acceptable, le titre SEO publié utilise le titre éditorial, un template SEO ou le suffixe du site.';
            }
            if (!isset($fieldSet['seo_description'])) {
                $notes[] = 'seo_description absent : choix acceptable, la description publiée peut venir du résumé, des blocs, d’un template ou du défaut du site.';
            }
            if (!isset($fieldSet['canonical_url'])) {
                $notes[] = 'canonical_url absent : choix acceptable, le canonique publié est dérivé du chemin public primaire.';
            }
            if (!isset($fieldSet['robots'])) {
                $notes[] = 'robots absent : choix acceptable, la publication applique le robots par défaut du template ou de la configuration SEO.';
            } elseif ($this->isSeoRobotsField($fields)) {
                $notes[] = 'robots est autorisé comme champ SEO éditorial ; il ne doit pas être confondu avec la route système /robots.txt.';
            }
            if (!isset($fieldSet['og_title']) || !isset($fieldSet['og_description'])) {
                $notes[] = 'Open Graph incomplet : choix acceptable, og_title et og_description reprennent les valeurs meta finales lorsqu’ils ne sont pas édités.';
            }
            if (!isset($fieldSet['og_image'])) {
                $notes[] = 'og_image absent : choix acceptable, l’image sociale peut venir du média dédié, de l’image par défaut du site ou rester vide.';
            }
            if (!isset($fieldSet['twitter_title']) || !isset($fieldSet['twitter_description']) || !isset($fieldSet['twitter_image'])) {
                $notes[] = 'Champs Twitter absents : choix de simplification, Twitter Card reprend Open Graph puis les metas finales.';
            }
            if (!isset($fieldSet['json_ld'])) {
                $notes[] = 'json_ld absent : choix de simplification, les données structurées ne sont exigées que si le projet décide de les éditer ou de les générer.';
            }
            if (!isset($fieldSet['hreflang'])) {
                $notes[] = 'hreflang absent : choix normal, le code hreflang est résolu depuis la configuration des langues du site.';
            }
        } else {
            $notes[] = 'Blueprint de bloc : pas d’exigence SEO ni de slug public propre ; le rendu dépend du contenu qui l’utilise.';
        }

        $localized = array_values(array_filter($fields, static fn(array $f): bool => (int) ($f['is_localized'] ?? 0) === 1));
        $resourceUsesExternalModuleStorage = in_array($resourceType, ['module_resource', 'headless'], true);
        if (!$isBlock && $resourceType !== 'system' && !$resourceUsesExternalModuleStorage && $this->hasMultipleLanguages((int) ($blueprint['site_id'] ?? 0)) && $localized === []) {
            $warnings[] = 'Aucun champ localisé alors que le site peut être multilingue.';
        }
        if ($resourceUsesExternalModuleStorage && $localized === []) {
            $notes[] = 'Ressource de module : les traductions peuvent être portées par la base métier dédiée ou par une table liée, sans imposer de champ éditorial localisé dans le blueprint.';
        }
        if ($blueprint['site_id'] === null && in_array($blueprintKey, ['page','article'], true)) {
            $notes[] = 'Blueprint global : les variantes multi-site restent possibles, mais ne sont pas nécessaires si le modèle doit rester commun.';
        }

        $score = 100 - (count(array_unique($blocking)) * 25) - (count(array_unique($warnings)) * 8) - min(10, count(array_unique($optimizations)) * 2);
        $score = max(0, min(100, $score));
        return [
            'blueprint_key' => $blueprintKey,
            'resource_type' => $resourceType,
            'score' => $score,
            'status' => $blocking !== [] ? 'blocking' : ($warnings !== [] ? 'warnings' : 'ok'),
            'blocking_errors' => array_values(array_unique($blocking)),
            'warnings' => array_values(array_unique($warnings)),
            'optimizations' => array_values(array_unique($optimizations)),
            'notes' => array_values(array_unique($notes)),
            'field_count' => count($fieldHandles),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function fieldTypes(): array
    {
        $priority = [
            'text' => ['label' => 'Texte court', 'purpose' => 'content', 'config' => ['maxlength' => 255]],
            'textarea' => ['label' => 'Texte long', 'purpose' => 'content', 'config' => ['rows' => 5]],
            'richtext' => ['label' => 'Rich text', 'purpose' => 'content', 'config' => ['toolbar' => 'editorial']],
            'markdown' => ['label' => 'Markdown', 'purpose' => 'content', 'config' => ['preview' => true]],
            'number' => ['label' => 'Nombre', 'purpose' => 'content', 'config' => ['step' => 1]],
            'boolean' => ['label' => 'Oui / non', 'purpose' => 'content', 'config' => ['style' => 'switch']],
            'select' => ['label' => 'Sélection', 'purpose' => 'content', 'config' => ['options' => []]],
            'multiselect' => ['label' => 'Multi-sélection', 'purpose' => 'content', 'config' => ['options' => []]],
            'date' => ['label' => 'Date', 'purpose' => 'content', 'config' => ['format' => 'Y-m-d']],
            'media' => ['label' => 'Image / média', 'purpose' => 'content', 'config' => ['accept' => ['image/*']]],
            'relation' => ['label' => 'Relation', 'purpose' => 'content', 'config' => ['resource_type' => 'entry']],
            'replicator' => ['label' => 'Blocs / répétiteur', 'purpose' => 'page_builder', 'config' => ['allowed_blocks' => []]],
            'json' => ['label' => 'JSON', 'purpose' => 'metadata', 'config' => ['mode' => 'object']],
            'slug' => ['label' => 'Slug', 'purpose' => 'system', 'config' => ['source' => 'title']],
            'sites' => ['label' => 'Site', 'purpose' => 'system', 'config' => ['source' => 'sites']],
            'users' => ['label' => 'Utilisateurs', 'purpose' => 'system', 'config' => ['source' => 'users']],
        ];
        $rows = [];
        if ($this->db->tableExists('schema_field_types')) {
            $rows = $this->db->all('SELECT type_key, label, storage_mode, component_key, is_implemented, definition_json FROM schema_field_types ORDER BY type_key');
        }
        $known = [];
        foreach ($priority as $handle => $meta) {
            $known[$handle] = [
                'handle' => $handle,
                'label' => $meta['label'],
                'purpose' => $meta['purpose'],
                'is_priority' => true,
                'is_implemented' => true,
                'config_schema' => $meta['config'],
            ];
        }
        foreach ($rows as $row) {
            $handle = $this->normalizeFieldType((string) ($row['type_key'] ?? 'text'));
            $definition = json_decode((string) ($row['definition_json'] ?? '{}'), true);
            $known[$handle] = [
                'handle' => $handle,
                'label' => (string) ($row['label'] ?? $known[$handle]['label'] ?? $handle),
                'purpose' => (string) ($known[$handle]['purpose'] ?? 'content'),
                'is_priority' => isset($priority[$handle]),
                'is_implemented' => (bool) ((int) ($row['is_implemented'] ?? 1)),
                'config_schema' => is_array($definition) ? ($definition['config'] ?? $definition) : ($known[$handle]['config_schema'] ?? []),
            ];
        }
        return array_values($known);
    }

    /** @return array<string,mixed> */
    public function design(string $key, ?string $resourceType = null, ?int $siteId = null): array
    {
        $blueprint = $this->findByKey($key, $resourceType, $siteId);
        if (!$blueprint) {
            throw new \RuntimeException(sprintf('Blueprint introuvable : %s.', $key));
        }
        $id = (int) $blueprint['id'];
        $sections = $this->db->all('SELECT * FROM blueprint_sections WHERE blueprint_id = :id ORDER BY sort_order, id', ['id' => $id]);
        $fields = $this->db->all('SELECT * FROM blueprint_fields WHERE blueprint_id = :id ORDER BY sort_order, id', ['id' => $id]);
        $mounts = $this->db->all('SELECT bf.*, fs.fieldset_key, fs.label AS fieldset_label FROM blueprint_fieldsets bf JOIN fieldsets fs ON fs.id = bf.fieldset_id WHERE bf.blueprint_id = :id ORDER BY bf.sort_order, bf.id', ['id' => $id]);
        $bySection = [];
        foreach ($sections as $section) {
            $bySection[(int) $section['id']] = $this->normalizeSectionRow($section) + ['fields' => [], 'fieldsets' => []];
        }
        foreach ($fields as $field) {
            $sectionId = $field['section_id'] === null ? 0 : (int) $field['section_id'];
            if (!isset($bySection[$sectionId])) {
                $bySection[$sectionId] = ['id' => $sectionId, 'section_key' => 'unclassified', 'label' => 'Non classé', 'description' => '', 'layout' => 'section', 'sort_order' => 9990, 'fields' => [], 'fieldsets' => []];
            }
            $bySection[$sectionId]['fields'][] = $this->normalizeFieldRow($field);
        }
        foreach ($mounts as $mount) {
            $sectionId = $mount['section_id'] === null ? 0 : (int) $mount['section_id'];
            if (!isset($bySection[$sectionId])) {
                $bySection[$sectionId] = ['id' => $sectionId, 'section_key' => 'unclassified', 'label' => 'Non classé', 'description' => '', 'layout' => 'section', 'sort_order' => 9990, 'fields' => [], 'fieldsets' => []];
            }
            $bySection[$sectionId]['fieldsets'][] = [
                'id' => (int) $mount['id'],
                'fieldset_key' => (string) $mount['fieldset_key'],
                'mount_handle' => (string) $mount['mount_handle'],
                'label' => (string) ($mount['label'] ?? $mount['fieldset_label']),
                'sort_order' => (int) $mount['sort_order'],
                'conditions' => $this->decodeJson((string) $mount['conditions_json'], []),
                'config' => $this->decodeJson((string) $mount['config_json'], []),
            ];
        }
        return ['blueprint' => $blueprint, 'sections' => array_values($bySection), 'fieldsets' => $this->fieldsets(), 'audit' => $this->audit($key, $resourceType, $siteId), 'governance' => $this->governanceRules(), 'presets' => $this->presets()];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveDesign(string $key, array $payload, ?int $siteId = null): array
    {
        $resourceType = $this->normalizeResourceType((string) ($payload['resource_type'] ?? 'content_type'));
        $blueprintKey = $this->normalizeKey((string) ($payload['blueprint_key'] ?? $key));
        if ($blueprintKey === '') {
            throw new \InvalidArgumentException('Le handle du blueprint est obligatoire.');
        }
        $label = trim((string) ($payload['label'] ?? $blueprintKey));
        if ($label === '') { $label = $blueprintKey; }
        $description = (string) ($payload['description'] ?? '');
        $legacyId = isset($payload['legacy_content_type_id']) && $payload['legacy_content_type_id'] !== '' ? (int) $payload['legacy_content_type_id'] : null;
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        if ($sections === []) {
            $sections = [['section_key' => 'content', 'label' => 'Contenu', 'layout' => 'tab', 'fields' => []]];
        }
        $sections = $this->withNativeSystemContextFields($sections);
        $this->assertDesignIsValid($blueprintKey, $resourceType, $sections);
        $this->assertDesignChangeIsSafe($blueprintKey, $resourceType, $siteId, $sections);

        $this->db->transaction(function () use ($blueprintKey, $resourceType, $siteId, $legacyId, $label, $description, $sections): void {
            $existing = $this->findByKey($blueprintKey, $resourceType, $siteId);
            if (!$existing) {
                $this->db->run(
                    'INSERT INTO blueprints(blueprint_key, resource_type, site_id, legacy_content_type_id, label, description, is_active) VALUES(:key,:type,:site,:legacy,:label,:description,1)',
                    ['key' => $blueprintKey, 'type' => $resourceType, 'site' => $siteId, 'legacy' => $legacyId, 'label' => $label, 'description' => $description]
                );
                $existing = $this->findByKey($blueprintKey, $resourceType, $siteId);
            } else {
                $this->db->run('UPDATE blueprints SET resource_type=:type, legacy_content_type_id=:legacy, label=:label, description=:description, updated_at=CURRENT_TIMESTAMP WHERE id=:id', [
                    'type' => $resourceType, 'legacy' => $legacyId, 'label' => $label, 'description' => $description, 'id' => (int) $existing['id']
                ]);
            }
            $blueprintId = (int) (($existing['id'] ?? 0) ?: $this->db->lastInsertId());
            $this->db->run('DELETE FROM blueprint_fieldsets WHERE blueprint_id=:id', ['id' => $blueprintId]);
            $this->db->run('DELETE FROM blueprint_fields WHERE blueprint_id=:id', ['id' => $blueprintId]);
            $this->db->run('DELETE FROM blueprint_sections WHERE blueprint_id=:id', ['id' => $blueprintId]);
            $schemaFields = [];
            $editorTabs = [];
            foreach (array_values($sections) as $index => $section) {
                if (!is_array($section)) { continue; }
                $sectionKey = $this->normalizeKey((string) ($section['section_key'] ?? $section['key'] ?? 'section_' . ($index + 1)));
                $layout = in_array((string) ($section['layout'] ?? 'tab'), ['tab','section','sidebar'], true) ? (string) ($section['layout'] ?? 'tab') : 'tab';
                $sortOrder = (int) ($section['sort_order'] ?? (($index + 1) * 10));
                $this->db->run('INSERT INTO blueprint_sections(blueprint_id, section_key, label, description, layout, sort_order, conditions_json, config_json) VALUES(:bid,:key,:label,:description,:layout,:sort,:conditions,:config)', [
                    'bid' => $blueprintId, 'key' => $sectionKey, 'label' => trim((string) ($section['label'] ?? $sectionKey)) ?: $sectionKey,
                    'description' => (string) ($section['description'] ?? ''), 'layout' => $layout, 'sort' => $sortOrder,
                    'conditions' => $this->jsonValue($section['conditions'] ?? []), 'config' => $this->jsonValue($section['config'] ?? []),
                ]);
                $sectionId = $this->db->lastInsertId();
                $tabFields = [];
                foreach (array_values(is_array($section['fields'] ?? null) ? $section['fields'] : []) as $fieldIndex => $field) {
                    if (!is_array($field)) { continue; }
                    $handle = $this->normalizeKey((string) ($field['field_handle'] ?? $field['handle'] ?? 'field_' . ($fieldIndex + 1)));
                    $type = $this->normalizeFieldType((string) ($field['field_type'] ?? $field['type'] ?? 'text'));
                    $fieldPurpose = $this->fieldPurpose($handle, (string) ($field['field_purpose'] ?? 'content'));
                    $classification = $this->fieldClassification($handle, $field, $fieldPurpose);
                    $fieldPurpose = $classification['field_purpose'];
                    $isSystem = (bool) ($field['is_system'] ?? $fieldPurpose === 'system' || $classification['field_scope'] !== 'editorial');
                    $isRequired = (bool) ($field['is_required'] ?? ($field['required'] ?? false));
                    if (in_array($handle, ['title', 'slug'], true)) { $isRequired = true; }
                    $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
                    if ($isRequired && $classification['field_scope'] !== 'system_context') { $validation['required'] = true; }
                    if ($fieldPurpose === 'seo') { $validation = $this->withSeoValidation($handle, $validation); }
                    $sort = (int) ($field['sort_order'] ?? (($fieldIndex + 1) * 10));
                    $config = $this->mergeFieldConfig($field['config'] ?? [], $classification);
                    $this->db->run('INSERT INTO blueprint_fields(blueprint_id, section_id, source_fieldset_id, field_handle, field_type, label, help_text, field_purpose, width, is_required, is_localized, is_system, is_deletable, sort_order, default_value_json, options_json, validation_json, conditions_json, config_json) VALUES(:bid,:sid,NULL,:handle,:type,:label,:help,:purpose,:width,:req,:loc,:sys,:del,:sort,:default,:options,:validation,:conditions,:config)', [
                        'bid' => $blueprintId, 'sid' => $sectionId, 'handle' => $handle, 'type' => $type,
                        'label' => trim((string) ($field['label'] ?? $handle)) ?: $handle, 'help' => (string) ($field['help_text'] ?? ''), 'purpose' => $fieldPurpose,
                        'width' => $this->normalizeWidth($field['width'] ?? 100), 'req' => $isRequired ? 1 : 0,
                        'loc' => (bool) ($field['is_localized'] ?? true) ? 1 : 0, 'sys' => $isSystem ? 1 : 0, 'del' => $classification['field_scope'] === 'editorial' ? ((bool) ($field['is_deletable'] ?? true) ? 1 : 0) : 0,
                        'sort' => $sort, 'default' => array_key_exists('default_value', $field) ? $this->jsonValue($field['default_value']) : null,
                        'options' => $this->jsonValue($field['options'] ?? []), 'validation' => $this->jsonValue($validation), 'conditions' => $this->jsonValue($field['conditions'] ?? []), 'config' => $this->jsonValue($config),
                    ]);
                    $schemaFields[] = ['field_key' => $handle, 'field_type' => $type, 'label' => trim((string) ($field['label'] ?? $handle)) ?: $handle, 'tab_key' => $sectionKey, 'width' => $this->normalizeWidth($field['width'] ?? 100), 'required' => $isRequired, 'localized' => (bool) ($field['is_localized'] ?? true), 'system' => $isSystem, 'purpose' => $fieldPurpose, 'field_purpose' => $fieldPurpose, 'field_scope' => $classification['field_scope'], 'scope' => $classification['field_scope'], 'value_source' => $classification['value_source'], 'source' => $classification['value_source'], 'ui_visibility' => $classification['ui_visibility'], 'visibility' => $classification['ui_visibility'], 'editable' => $classification['is_editable'], 'is_editable' => $classification['is_editable'], 'is_deletable' => $classification['field_scope'] === 'editorial' ? (bool) ($field['is_deletable'] ?? true) : false, 'options' => $field['options'] ?? [], 'validation' => $validation, 'conditions' => $field['conditions'] ?? [], 'config' => $config];
                    $tabFields[] = $handle;
                }
                foreach (array_values(is_array($section['fieldsets'] ?? null) ? $section['fieldsets'] : []) as $fieldsetIndex => $mount) {
                    if (!is_array($mount)) { continue; }
                    $fsKey = $this->normalizeKey((string) ($mount['fieldset_key'] ?? ''));
                    $fs = $fsKey === '' ? null : $this->db->one('SELECT id, label FROM fieldsets WHERE fieldset_key=:key', ['key' => $fsKey]);
                    if (!$fs) { continue; }
                    $mountHandle = $this->normalizeKey((string) ($mount['mount_handle'] ?? $fsKey));
                    $this->db->run('INSERT INTO blueprint_fieldsets(blueprint_id, section_id, fieldset_id, mount_handle, label, sort_order, conditions_json, config_json) VALUES(:bid,:sid,:fid,:handle,:label,:sort,:conditions,:config)', [
                        'bid' => $blueprintId, 'sid' => $sectionId, 'fid' => (int) $fs['id'], 'handle' => $mountHandle,
                        'label' => (string) ($mount['label'] ?? $fs['label']), 'sort' => (int) ($mount['sort_order'] ?? (($fieldsetIndex + 1) * 10)), 'conditions' => $this->jsonValue($mount['conditions'] ?? []), 'config' => $this->jsonValue($mount['config'] ?? []),
                    ]);
                    $tabFields[] = '@' . $mountHandle;
                }
                $editorTabs[] = ['key' => $sectionKey, 'label' => trim((string) ($section['label'] ?? $sectionKey)) ?: $sectionKey, 'sort_order' => $sortOrder, 'fields' => $tabFields];
            }
            $schema = ['schema_version' => 1, 'content_type' => ['type_key' => $blueprintKey, 'name' => $label, 'singular_label' => $label, 'plural_label' => $label], 'fields' => $schemaFields, 'headless' => ['enabled' => true]];
            if ($resourceType === 'block') { $schema['block'] = ['type' => $blueprintKey, 'title' => $label, 'sections' => $editorTabs]; }
            $this->createVersion($blueprintKey, ['resource_type' => $resourceType, 'version_label' => 'admin-design', 'schema_json' => $schema, 'ui_schema_json' => ['editor_tabs' => $editorTabs], 'validation_json' => ['server' => 'normalized_blueprint_model'], 'seo_policy_json' => ['native_service' => true, 'fields' => ['seo_title','seo_description','canonical_url','robots','og_title','og_description','og_image']], 'workflow_policy_json' => ['default_state' => 'draft'], 'is_active' => true], $siteId);
        });
        return $this->design($blueprintKey, $resourceType, $siteId);
    }

    /** @return array<string,mixed> */
    public function deleteBlueprint(string $key, ?string $resourceType = null, ?int $siteId = null): array
    {
        $blueprint = $this->findByKey($key, $resourceType, $siteId);
        if (!$blueprint) { throw new \RuntimeException(sprintf('Blueprint introuvable : %s.', $key)); }
        if (in_array((string) $blueprint['blueprint_key'], self::PROTECTED_BLUEPRINTS, true)) {
            throw new \InvalidArgumentException('Ce blueprint natif est protégé. Désactivez-le ou archivez ses versions plutôt que de le supprimer.');
        }
        $usageCount = $this->blueprintPublishedEntriesCount($blueprint);
        if ($usageCount > 0) {
            throw new \InvalidArgumentException(sprintf('Ce blueprint contient déjà %d contenu(s) publié(s). Désactivez-le ou archivez-le ; la suppression définitive doit rester exceptionnelle.', $usageCount));
        }
        $usage = $this->db->tableExists('blueprint_usage') ? $this->db->one('SELECT COUNT(*) AS n FROM blueprint_usage WHERE blueprint_id=:id', ['id' => (int) $blueprint['id']]) : ['n' => 0];
        if ((int) ($usage['n'] ?? 0) > 0) {
            throw new \InvalidArgumentException('Ce blueprint est utilisé et ne peut pas être supprimé.');
        }
        $this->db->run('DELETE FROM blueprints WHERE id=:id', ['id' => (int) $blueprint['id']]);
        return ['deleted' => true, 'blueprint_key' => (string) $blueprint['blueprint_key']];
    }

    /** @return list<array<string,mixed>> */
    public function fieldsets(): array
    {
        if (!$this->db->tableExists('fieldsets')) { return []; }
        $rows = $this->db->all('SELECT fs.*, COUNT(ff.id) AS fields_count, COUNT(DISTINCT bf.blueprint_id) AS usage_count FROM fieldsets fs LEFT JOIN fieldset_fields ff ON ff.fieldset_id=fs.id LEFT JOIN blueprint_fieldsets bf ON bf.fieldset_id=fs.id GROUP BY fs.id ORDER BY fs.fieldset_purpose, fs.fieldset_key');
        return array_map(fn(array $r): array => $this->normalizeFieldsetRow($r), $rows);
    }

    /** @return array<string,mixed> */
    public function fieldset(string $key): array
    {
        $row = $this->db->one('SELECT fs.*, COUNT(DISTINCT bf.blueprint_id) AS usage_count FROM fieldsets fs LEFT JOIN blueprint_fieldsets bf ON bf.fieldset_id=fs.id WHERE fs.fieldset_key=:key GROUP BY fs.id', ['key' => $this->normalizeKey($key)]);
        if (!$row) { throw new \RuntimeException(sprintf('Fieldset introuvable : %s.', $key)); }
        $fields = $this->db->all('SELECT * FROM fieldset_fields WHERE fieldset_id=:id ORDER BY sort_order, id', ['id' => (int) $row['id']]);
        $blueprints = $this->db->all('SELECT b.blueprint_key, b.resource_type, b.label FROM blueprint_fieldsets bf JOIN blueprints b ON b.id=bf.blueprint_id WHERE bf.fieldset_id=:id ORDER BY b.resource_type, b.blueprint_key', ['id' => (int) $row['id']]);
        return $this->normalizeFieldsetRow($row) + ['fields' => array_map(fn(array $f): array => $this->normalizeFieldRow($f), $fields), 'used_by' => array_map(static fn(array $b): array => ['blueprint_key' => (string) $b['blueprint_key'], 'resource_type' => (string) $b['resource_type'], 'label' => (string) $b['label']], $blueprints)];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveFieldset(?string $key, array $payload): array
    {
        $fieldsetKey = $this->normalizeKey((string) ($payload['fieldset_key'] ?? $key ?? ''));
        if ($fieldsetKey === '') { throw new \InvalidArgumentException('Le handle du fieldset est obligatoire.'); }
        $label = trim((string) ($payload['label'] ?? $fieldsetKey)) ?: $fieldsetKey;
        $purpose = in_array((string) ($payload['fieldset_purpose'] ?? 'content'), ['content','seo','system','page_builder','metadata'], true) ? (string) ($payload['fieldset_purpose'] ?? 'content') : 'content';
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $this->assertFieldHandlesAreValid($fields);
        $this->assertFieldsetChangeIsSafe($fieldsetKey, $fields);
        $this->db->transaction(function () use ($fieldsetKey, $label, $purpose, $payload, $fields): void {
            $this->db->run('INSERT INTO fieldsets(fieldset_key,label,description,fieldset_purpose,schema_version,is_system,is_deletable,config_json,updated_at) VALUES(:key,:label,:description,:purpose,1,0,1,:config,CURRENT_TIMESTAMP) ON CONFLICT(fieldset_key) DO UPDATE SET label=excluded.label, description=excluded.description, fieldset_purpose=excluded.fieldset_purpose, config_json=excluded.config_json, updated_at=CURRENT_TIMESTAMP', ['key'=>$fieldsetKey, 'label'=>$label, 'description'=>(string)($payload['description'] ?? ''), 'purpose'=>$purpose, 'config'=>$this->jsonValue($payload['config'] ?? [])]);
            $row = $this->db->one('SELECT id FROM fieldsets WHERE fieldset_key=:key', ['key'=>$fieldsetKey]);
            $id = (int) ($row['id'] ?? 0);
            $this->db->run('DELETE FROM fieldset_fields WHERE fieldset_id=:id', ['id'=>$id]);
            foreach (array_values($fields) as $i => $field) {
                if (!is_array($field)) { continue; }
                $handle = $this->normalizeKey((string) ($field['field_handle'] ?? $field['handle'] ?? 'field_' . ($i + 1)));
                $type = $this->normalizeFieldType((string) ($field['field_type'] ?? $field['type'] ?? 'text'));
                $required = (bool) ($field['is_required'] ?? false);
                $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
                if ($required) { $validation['required'] = true; }
                $this->db->run('INSERT INTO fieldset_fields(fieldset_id, field_handle, field_type, label, help_text, field_purpose, width, is_required, is_localized, is_system, is_deletable, sort_order, default_value_json, options_json, validation_json, conditions_json, config_json) VALUES(:fs,:handle,:type,:label,:help,:purpose,:width,:req,:loc,0,1,:sort,:default,:options,:validation,:conditions,:config)', ['fs'=>$id, 'handle'=>$handle, 'type'=>$type, 'label'=>trim((string)($field['label'] ?? $handle)) ?: $handle, 'help'=>(string)($field['help_text'] ?? ''), 'purpose'=>$this->fieldPurpose($handle, (string)($field['field_purpose'] ?? $purpose)), 'width'=>$this->normalizeWidth($field['width'] ?? 100), 'req'=>$required ? 1 : 0, 'loc'=>(bool)($field['is_localized'] ?? true) ? 1 : 0, 'sort'=>(int)($field['sort_order'] ?? (($i+1)*10)), 'default'=>array_key_exists('default_value', $field) ? $this->jsonValue($field['default_value']) : null, 'options'=>$this->jsonValue($field['options'] ?? []), 'validation'=>$this->jsonValue($validation), 'conditions'=>$this->jsonValue($field['conditions'] ?? []), 'config'=>$this->jsonValue($field['config'] ?? [])]);
            }
        });
        return $this->fieldset($fieldsetKey);
    }

    /** @return array<string,mixed> */
    public function deleteFieldset(string $key): array
    {
        $fieldset = $this->fieldset($key);
        if (!$fieldset['is_deletable']) { throw new \InvalidArgumentException('Ce fieldset système ne peut pas être supprimé.'); }
        if ((int) ($fieldset['usage_count'] ?? 0) > 0) { throw new \InvalidArgumentException('Ce fieldset est utilisé dans un ou plusieurs blueprints. Retirez ses usages avant suppression.'); }
        $this->db->run('DELETE FROM fieldsets WHERE fieldset_key=:key', ['key' => $this->normalizeKey($key)]);
        return ['deleted' => true, 'fieldset_key' => $this->normalizeKey($key)];
    }


    /** @param array<string,mixed> $blueprint @param array<string,mixed> $payload */
    private function assertVersionPayloadCanBeActivated(array $blueprint, array $payload): void
    {
        $schema = $this->policyArray($payload['schema_json'] ?? $payload['schema'] ?? []);
        $this->assertBlueprintSchemaCanBeActivated($blueprint, $schema);
    }

    /** @param array<string,mixed> $blueprint @param array<string,mixed> $versionRow */
    private function assertStoredVersionCanBeActivated(array $blueprint, array $versionRow): void
    {
        $schema = $this->decodeJson((string) ($versionRow['schema_json'] ?? '{}'), []);
        $this->assertBlueprintSchemaCanBeActivated($blueprint, is_array($schema) ? $schema : []);
    }

    /** @param array<string,mixed> $blueprint @param array<string,mixed> $schema */
    private function assertBlueprintSchemaCanBeActivated(array $blueprint, array $schema): void
    {
        if ($schema === []) {
            throw new \InvalidArgumentException('Un blueprint publié doit contenir un schema_json non vide.');
        }
        $resourceType = (string) ($blueprint['resource_type'] ?? 'content_type');
        $blueprintKey = (string) ($blueprint['blueprint_key'] ?? '');
        if ($resourceType === 'content_type') {
            $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
            $handles = [];
            foreach ($fields as $field) {
                if (!is_array($field)) { continue; }
                $handle = $this->normalizeKey((string) ($field['field_key'] ?? $field['handle'] ?? ''));
                if ($handle !== '') { $handles[$handle] = $field; }
            }
            foreach (['title', 'slug'] as $systemHandle) {
                if (!isset($handles[$systemHandle])) {
                    throw new \InvalidArgumentException(sprintf('Publication refusée : le champ système "%s" est obligatoire dans le blueprint %s.', $systemHandle, $blueprintKey));
                }
                $this->assertPublishedSystemFieldIsProtected($blueprintKey, $systemHandle, $handles[$systemHandle]);
            }
            foreach ($handles as $handle => $field) {
                if (in_array($handle, self::PROTECTED_FIELDS, true)) {
                    $this->assertPublishedSystemFieldIsProtected($blueprintKey, $handle, $field);
                }
            }
        }
        if ($resourceType === 'block') {
            if (!in_array($blueprintKey, BlockDocumentNormalizer::supportedTypes(), true)) {
                throw new \InvalidArgumentException(sprintf('Publication refusée : le bloc "%s" n’est pas un type natif supporté.', $blueprintKey));
            }
            if (!$this->blockRendererExists($blueprintKey)) {
                throw new \InvalidArgumentException(sprintf('Publication refusée : aucun renderer Twig fiable pour le bloc "%s".', $blueprintKey));
            }
        }
    }

    /** @param array<string,mixed> $field */
    private function assertPublishedSystemFieldIsProtected(string $blueprintKey, string $handle, array $field): void
    {
        $system = (bool) ($field['system'] ?? $field['is_system'] ?? false);
        $deletable = (bool) ($field['is_deletable'] ?? $field['deletable'] ?? false);
        $scope = (string) ($field['field_scope'] ?? $field['scope'] ?? '');
        if (is_array($field['config'] ?? null)) {
            $scope = $scope !== '' ? $scope : (string) (($field['config']['field_scope'] ?? $field['config']['scope'] ?? ''));
        }
        if (!$system || $deletable || $scope === 'editorial') {
            throw new \InvalidArgumentException(sprintf(
                'Publication refusée : le champ système "%s" du blueprint %s doit être marqué system, non supprimable et hors scope éditorial.',
                $handle,
                $blueprintKey
            ));
        }
        if (in_array($handle, ['title', 'slug'], true) && !(bool) ($field['required'] ?? $field['is_required'] ?? false)) {
            throw new \InvalidArgumentException(sprintf('Publication refusée : le champ système "%s" du blueprint %s doit rester requis.', $handle, $blueprintKey));
        }
    }

    private function blockRendererExists(string $blockType): bool
    {
        $renderer = dirname(__DIR__, 5) . '/frontend/theme-default/templates/partials/content-block.twig';
        if (!is_file($renderer)) { return false; }
        $source = (string) file_get_contents($renderer);
        if (in_array($blockType, ['richtext', 'html_safe', 'html_raw'], true)) {
            return str_contains($source, "type in ['richtext', 'html_safe', 'html_raw']");
        }
        return str_contains($source, "type == '" . $blockType . "'") || str_contains($source, '{% else %}');
    }

    private function policyArray(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function nextVersion(int $blueprintId): int
    {
        $row = $this->db->one('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM blueprint_versions WHERE blueprint_id = :id', ['id' => $blueprintId]);
        return (int) ($row['next_version'] ?? 1);
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_-]+/', '-', $key) ?: '';
        return trim($key, '-');
    }

    private function keyWhere(string $key, ?string $resourceType, ?int $siteId): array
    {
        $where = ['b.blueprint_key = :blueprint_key'];
        $params = ['blueprint_key' => $this->normalizeKey($key)];
        if ($resourceType !== null && $resourceType !== '') {
            $where[] = 'b.resource_type = :resource_type';
            $params['resource_type'] = $resourceType;
        }
        if ($siteId !== null) {
            $where[] = '(b.site_id = :site_id OR b.site_id IS NULL)';
            $params['site_id'] = $siteId;
        }
        return [implode(' AND ', $where), $params];
    }

    private function encodePolicy(mixed $value, string $field): string
    {
        if (is_string($value)) {
            json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException($field . ' doit être un JSON valide.');
            }
            return $value;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException($field . ' doit être un objet JSON.');
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \InvalidArgumentException($field . ' ne peut pas être encodé.');
        }
        return $json;
    }



    /** @param list<mixed> $sections @return list<mixed> */
    private function withNativeSystemContextFields(array $sections): array
    {
        $handles = $this->collectSectionFieldHandles($sections);
        $settingsIndex = null;
        foreach ($sections as $index => $section) {
            if (is_array($section) && $this->normalizeKey((string) ($section['section_key'] ?? $section['key'] ?? '')) === 'settings') {
                $settingsIndex = $index;
                break;
            }
        }
        if ($settingsIndex === null) {
            $sections[] = ['section_key' => 'settings', 'label' => 'Réglages', 'layout' => 'tab', 'sort_order' => 100, 'fields' => [], 'fieldsets' => []];
            $settingsIndex = array_key_last($sections);
        }
        if (!is_array($sections[$settingsIndex])) { return $sections; }
        if (!isset($sections[$settingsIndex]['fields']) || !is_array($sections[$settingsIndex]['fields'])) {
            $sections[$settingsIndex]['fields'] = [];
        }

        $native = [
            ['field_handle' => 'status', 'field_type' => 'select', 'label' => 'Statut', 'field_purpose' => 'system', 'field_scope' => 'system_context', 'value_source' => 'workflow', 'ui_visibility' => 'summary', 'is_required' => true, 'is_localized' => false, 'is_system' => true, 'is_deletable' => false, 'is_editable' => false, 'width' => 100, 'sort_order' => 900],
            ['field_handle' => 'language', 'field_type' => 'select', 'label' => 'Langue', 'field_purpose' => 'system', 'field_scope' => 'system_context', 'value_source' => 'context', 'ui_visibility' => 'summary', 'is_required' => true, 'is_localized' => false, 'is_system' => true, 'is_deletable' => false, 'is_editable' => false, 'width' => 100, 'sort_order' => 910],
            ['field_handle' => 'site', 'field_type' => 'sites', 'label' => 'Site', 'field_purpose' => 'system', 'field_scope' => 'system_context', 'value_source' => 'context', 'ui_visibility' => 'summary', 'is_required' => true, 'is_localized' => false, 'is_system' => true, 'is_deletable' => false, 'is_editable' => false, 'width' => 100, 'sort_order' => 920],
            ['field_handle' => 'revision_state', 'field_type' => 'select', 'label' => 'Révision', 'field_purpose' => 'system', 'field_scope' => 'system_context', 'value_source' => 'workflow', 'ui_visibility' => 'summary', 'is_required' => false, 'is_localized' => false, 'is_system' => true, 'is_deletable' => false, 'is_editable' => false, 'width' => 100, 'sort_order' => 930],
        ];
        foreach ($native as $field) {
            if (isset($handles[(string) $field['field_handle']])) { continue; }
            $field['config'] = $this->mergeFieldConfig([], $this->fieldClassification((string) $field['field_handle'], $field, 'system'));
            $sections[$settingsIndex]['fields'][] = $field;
        }
        return array_values($sections);
    }

    /** @param list<mixed> $sections */
    private function assertDesignIsValid(string $blueprintKey, string $resourceType, array $sections): void
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $blueprintKey)) { throw new \InvalidArgumentException('Le handle du blueprint doit contenir seulement a-z, 0-9, _ ou -.'); }
        $allowedResources = ['content_type','page','article','collection','block','module','module_resource','headless','taxonomy','form','media','system'];
        if (!in_array($resourceType, $allowedResources, true)) { throw new \InvalidArgumentException('Type de ressource non supporté.'); }
        $handles = [];
        foreach ($sections as $section) {
            if (!is_array($section)) { continue; }
            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (!is_array($field)) { continue; }
                $handle = $this->normalizeKey((string) ($field['field_handle'] ?? $field['handle'] ?? ''));
                if ($handle === '') { throw new \InvalidArgumentException('Chaque champ doit avoir un handle.'); }
                if (isset($handles[$handle])) { throw new \InvalidArgumentException(sprintf('Handle de champ dupliqué : %s.', $handle)); }
                if ($this->isReservedFieldHandle($handle)) { throw new \InvalidArgumentException(sprintf('Handle réservé : %s.', $handle)); }
                $lifecycle = (string) ((is_array($field['config'] ?? null) ? ($field['config']['lifecycle_state'] ?? 'active') : 'active'));
                if (!in_array($lifecycle, ['active','disabled','hidden','archived'], true)) { throw new \InvalidArgumentException(sprintf('État de cycle de vie invalide pour %s. Valeurs : active, disabled, hidden, archived.', $handle)); }
                $handles[$handle] = true;
                $this->normalizeFieldType((string) ($field['field_type'] ?? $field['type'] ?? 'text'));
                $this->normalizeWidth($field['width'] ?? 100);
            }
        }
    }

    /** @param list<mixed> $fields */
    private function assertFieldHandlesAreValid(array $fields): void
    {
        $seen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) { continue; }
            $handle = $this->normalizeKey((string) ($field['field_handle'] ?? $field['handle'] ?? ''));
            if ($handle === '') { throw new \InvalidArgumentException('Chaque champ doit avoir un handle.'); }
            if (isset($seen[$handle])) { throw new \InvalidArgumentException(sprintf('Handle de champ dupliqué dans le fieldset : %s.', $handle)); }
            if ($this->isReservedFieldHandle($handle)) { throw new \InvalidArgumentException(sprintf('Handle réservé dans le fieldset : %s.', $handle)); }
            $seen[$handle] = true;
        }
    }

    /** @param list<mixed> $sections */
    private function assertDesignChangeIsSafe(string $blueprintKey, string $resourceType, ?int $siteId, array $sections): void
    {
        if (in_array($blueprintKey, self::RESERVED_HANDLES, true)) {
            throw new \InvalidArgumentException(sprintf('Le handle "%s" est réservé au système, au front ou à l’API.', $blueprintKey));
        }
        $existing = $this->findByKey($blueprintKey, $resourceType, $siteId);
        if (!$existing) { return; }
        $incoming = $this->collectSectionFieldHandles($sections);
        $current = $this->db->all('SELECT field_handle, is_system, is_deletable, config_json FROM blueprint_fields WHERE blueprint_id=:id', ['id' => (int) $existing['id']]);
        foreach ($current as $field) {
            $handle = (string) $field['field_handle'];
            if (isset($incoming[$handle])) { continue; }
            $fieldConfig = $this->decodeJson((string) ($field['config_json'] ?? '{}'), []);
            $fieldScope = is_array($fieldConfig) ? (string) ($fieldConfig['field_scope'] ?? $fieldConfig['scope'] ?? '') : '';
            if ($fieldScope === 'system_context' || in_array($handle, self::SYSTEM_CONTEXT_FIELDS, true)) { continue; }
            $publishedCount = $this->publishedFieldValueCount($existing, $handle);
            if ((int) $field['is_system'] === 1 || in_array($handle, self::PROTECTED_FIELDS, true)) {
                throw new \InvalidArgumentException(sprintf('Le champ système protégé "%s" ne peut pas être supprimé. Utilisez lifecycle_state=hidden, disabled ou archived dans sa configuration.', $handle));
            }
            if ($publishedCount > 0) {
                throw new \InvalidArgumentException(sprintf('Le champ "%s" contient déjà des données publiées (%d occurrence(s)). Archivez, masquez ou désactivez le champ avant une suppression définitive documentée.', $handle, $publishedCount));
            }
        }
    }

    /** @param list<mixed> $fields */
    private function assertFieldsetChangeIsSafe(string $fieldsetKey, array $fields): void
    {
        if (!$this->db->tableExists('fieldsets')) { return; }
        $row = $this->db->one('SELECT id, is_system, is_deletable FROM fieldsets WHERE fieldset_key=:key', ['key' => $fieldsetKey]);
        if (!$row) { return; }
        if ((int) $row['is_system'] === 1 && (int) $row['is_deletable'] !== 1) {
            throw new \InvalidArgumentException('Ce fieldset système est protégé. Créez une variante éditoriale plutôt que de le modifier directement.');
        }
        $incoming = [];
        foreach ($fields as $field) {
            if (!is_array($field)) { continue; }
            $incoming[$this->normalizeKey((string) ($field['field_handle'] ?? $field['handle'] ?? ''))] = true;
        }
        $current = $this->db->all('SELECT field_handle, is_system FROM fieldset_fields WHERE fieldset_id=:id', ['id' => (int) $row['id']]);
        foreach ($current as $field) {
            $handle = (string) $field['field_handle'];
            if (!isset($incoming[$handle]) && ((int) $field['is_system'] === 1 || in_array($handle, self::PROTECTED_FIELDS, true))) {
                throw new \InvalidArgumentException(sprintf('Le champ système "%s" du fieldset est protégé. Désactivez-le, masquez-le ou archivez-le plutôt que de le supprimer.', $handle));
            }
        }
    }

    /** @param list<mixed> $sections @return array<string,bool> */
    private function collectSectionFieldHandles(array $sections): array
    {
        $handles = [];
        foreach ($sections as $section) {
            if (!is_array($section)) { continue; }
            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (!is_array($field)) { continue; }
                $handle = $this->normalizeKey((string) ($field['field_handle'] ?? $field['handle'] ?? ''));
                if ($handle !== '') { $handles[$handle] = true; }
            }
        }
        return $handles;
    }

    /** @param array<string,mixed> $blueprint */
    private function publishedFieldValueCount(array $blueprint, string $handle): int
    {
        if (!$this->db->tableExists('content_entry_field_values') || !$this->db->tableExists('content_entries')) { return 0; }
        $params = ['handle' => $handle];
        $typeClause = '';
        if (!empty($blueprint['legacy_content_type_id'])) {
            $typeClause = 'AND e.content_type_id = :content_type_id';
            $params['content_type_id'] = (int) $blueprint['legacy_content_type_id'];
        }
        $row = $this->db->one("SELECT COUNT(*) AS n FROM content_entry_field_values fv JOIN content_entries e ON e.id=fv.entry_id WHERE fv.field_key=:handle {$typeClause} AND (e.status='published' OR e.workflow_state='published' OR e.published_at IS NOT NULL)", $params);
        return (int) ($row['n'] ?? 0);
    }

    /** @param array<string,mixed> $blueprint */
    private function blueprintPublishedEntriesCount(array $blueprint): int
    {
        if (!$this->db->tableExists('content_entries') || empty($blueprint['legacy_content_type_id'])) { return 0; }
        $row = $this->db->one("SELECT COUNT(*) AS n FROM content_entries WHERE content_type_id=:id AND (status='published' OR workflow_state='published' OR published_at IS NOT NULL)", ['id' => (int) $blueprint['legacy_content_type_id']]);
        return (int) ($row['n'] ?? 0);
    }


    private function isReservedFieldHandle(string $handle): bool
    {
        return in_array($handle, self::RESERVED_HANDLES, true) && !in_array($handle, ['robots'], true);
    }

    /** @param list<array<string,mixed>> $fields */
    private function isSeoRobotsField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ((string) ($field['field_handle'] ?? '') !== 'robots') { continue; }
            $config = $this->decodeJson((string) ($field['config_json'] ?? '{}'), []);
            $purpose = (string) ($field['field_purpose'] ?? '');
            $scope = is_array($config) ? (string) ($config['field_scope'] ?? $config['scope'] ?? '') : '';
            return $purpose === 'seo' || $scope === 'editorial';
        }
        return false;
    }

    private function needsSlug(string $resourceType, string $blueprintKey): bool
    {
        if ($resourceType === 'block') { return false; }
        return in_array($resourceType, ['content_type','page','article','collection'], true) || in_array($blueprintKey, ['page','article'], true);
    }

    private function hasDuplicates(array $values): bool
    {
        return count($values) !== count(array_unique($values));
    }

    private function hasMultipleLanguages(int $siteId = 0): bool
    {
        if (!$this->db->tableExists('site_languages')) { return false; }
        $params = [];
        $where = '';
        if ($siteId > 0) { $where = 'WHERE site_id=:site_id'; $params['site_id'] = $siteId; }
        $row = $this->db->one("SELECT COUNT(*) AS n FROM site_languages {$where}", $params);
        return (int) ($row['n'] ?? 0) > 1;
    }

    private function normalizeResourceType(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'page', 'article', 'collection' => 'content_type',
            'bloc' => 'block',
            default => $value !== '' ? $value : 'content_type',
        };
    }


    /** @param array<string,mixed> $field @return array{field_scope:string,value_source:string,ui_visibility:string,is_editable:bool,field_purpose:string} */
    private function fieldClassification(string $handle, array $field, string $fieldPurpose): array
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $scope = (string) ($field['field_scope'] ?? $field['scope'] ?? $config['field_scope'] ?? $config['scope'] ?? '');
        if (in_array($handle, self::SYSTEM_CONTEXT_FIELDS, true)) {
            $scope = 'system_context';
        } elseif (in_array($handle, self::SYSTEM_EDITABLE_FIELDS, true)) {
            $scope = 'system_editable';
        } elseif (in_array($handle, self::PROTECTED_FIELDS, true) && $scope === 'editorial') {
            $scope = 'system_context';
        }
        if ($scope === '') {
            if (in_array($handle, self::SYSTEM_CONTEXT_FIELDS, true)) {
                $scope = 'system_context';
            } elseif (in_array($handle, self::SYSTEM_EDITABLE_FIELDS, true)) {
                $scope = 'system_editable';
            } else {
                $scope = 'editorial';
            }
        }
        if (!in_array($scope, ['editorial','system_editable','system_context'], true)) {
            $scope = 'editorial';
        }

        $source = (string) ($field['value_source'] ?? $field['source'] ?? $config['value_source'] ?? $config['source'] ?? '');
        if ($source === '') {
            $source = match ($handle) {
                'status', 'workflow_state', 'revision_state' => 'workflow',
                'language', 'site', 'site_id', 'content_type', 'blueprint_id', 'blueprint_version_id' => 'context',
                default => 'content',
            };
        }
        if (!in_array($source, ['content','context','workflow','relation','computed'], true)) {
            $source = 'content';
        }

        $visibility = (string) ($field['ui_visibility'] ?? $field['visibility'] ?? $config['ui_visibility'] ?? $config['visibility'] ?? '');
        if ($visibility === '') {
            $visibility = $scope === 'system_context' ? 'summary' : 'form';
        }
        if (!in_array($visibility, ['form','summary','hidden','readonly','native'], true)) {
            $visibility = $scope === 'system_context' ? 'summary' : 'form';
        }

        $editable = array_key_exists('is_editable', $field) ? (bool) $field['is_editable'] : (array_key_exists('editable', $field) ? (bool) $field['editable'] : $scope !== 'system_context');
        if ($scope === 'system_context') { $editable = false; }
        if ($scope !== 'editorial') { $fieldPurpose = 'system'; }

        return ['field_scope' => $scope, 'value_source' => $source, 'ui_visibility' => $visibility, 'is_editable' => $editable, 'field_purpose' => $fieldPurpose];
    }

    /** @param mixed $config @param array<string,mixed> $classification @return array<string,mixed> */
    private function mergeFieldConfig(mixed $config, array $classification): array
    {
        $config = is_array($config) ? $config : [];
        $config['field_scope'] = $classification['field_scope'];
        $config['scope'] = $classification['field_scope'];
        $config['value_source'] = $classification['value_source'];
        $config['source'] = $classification['value_source'];
        $config['ui_visibility'] = $classification['ui_visibility'];
        $config['visibility'] = $classification['ui_visibility'];
        $config['editable'] = $classification['is_editable'];
        return $config;
    }

    private function normalizeFieldType(string $value): string
    {
        $original = strtolower(trim($value));
        $map = [
            'string' => 'text',
            'bool' => 'boolean',
            'multi-select' => 'multiselect',
            'image' => 'media',
            'asset' => 'media',
            'file' => 'media',
            'files' => 'assets',
            'builder' => 'replicator',
            'blocks' => 'replicator',
            'block_builder' => 'replicator',
            'seo' => 'json',
            'language' => 'select',
            'languages' => 'select',
            'site' => 'sites',
            'structure' => 'structures',
            'user' => 'users',
        ];
        $value = $map[$original] ?? ($original ?: 'text');
        $allowed = ['text','textarea','richtext','markdown','bard','number','integer','boolean','toggle','date','time','datetime','json','yaml','code','media','assets','relation','entries','taxonomy','select','radio','multiselect','checkboxes','slug','link','list','replicator','table','color','video','button_group','range','revealer','sites','structures','template','users'];
        if (!in_array($value, $allowed, true)) { throw new \InvalidArgumentException(sprintf('Type de champ non supporté : %s.', $original ?: $value)); }

        return $value;
    }

    private function normalizeWidth(mixed $value): int
    {
        $width = (int) $value;
        if (!in_array($width, [25,33,50,66,75,100], true)) { throw new \InvalidArgumentException('La largeur doit être 25, 33, 50, 66, 75 ou 100.'); }
        return $width;
    }

    private function fieldPurpose(string $handle, string $fallback = 'content'): string
    {
        if (in_array($handle, ['title','slug','status','language','site'], true)) { return 'system'; }
        if (str_starts_with($handle, 'seo_') || in_array($handle, ['canonical_url','robots','og_title','og_description','og_image'], true)) { return 'seo'; }
        if (in_array($handle, ['blocks','builder','layout','template'], true)) { return 'page_builder'; }
        return in_array($fallback, ['content','seo','system','page_builder','metadata'], true) ? $fallback : 'content';
    }

    /** @param array<string,mixed> $validation @return array<string,mixed> */
    private function withSeoValidation(string $handle, array $validation): array
    {
        if ($handle === 'seo_title') { $validation += ['max' => 60]; }
        if ($handle === 'seo_description') { $validation += ['max' => 160]; }
        if ($handle === 'canonical_url') { $validation += ['url' => true]; }
        return $validation;
    }

    private function jsonValue(mixed $value): string
    {
        if (is_string($value)) {
            json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) { return $value; }
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new \InvalidArgumentException('JSON invalide.'); }
        return $json;
    }

    /** @return mixed */
    private function decodeJson(string $json, mixed $fallback): mixed
    {
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    /** @return array<string,mixed> */
    private function normalizeSectionRow(array $row): array
    {
        return ['id'=>(int)$row['id'], 'section_key'=>(string)$row['section_key'], 'label'=>(string)$row['label'], 'description'=>(string)($row['description'] ?? ''), 'layout'=>(string)$row['layout'], 'sort_order'=>(int)$row['sort_order'], 'conditions'=>$this->decodeJson((string)$row['conditions_json'], []), 'config'=>$this->decodeJson((string)$row['config_json'], [])];
    }

    /** @return array<string,mixed> */
    private function normalizeFieldRow(array $row): array
    {
        $config = $this->decodeJson((string)$row['config_json'], []);
        $handle = (string)$row['field_handle'];
        $classification = $this->fieldClassification($handle, ['config' => is_array($config) ? $config : []], (string)$row['field_purpose']);
        return ['id'=>(int)$row['id'], 'field_handle'=>$handle, 'field_type'=>(string)$row['field_type'], 'label'=>(string)$row['label'], 'help_text'=>(string)($row['help_text'] ?? ''), 'field_purpose'=>(string)$row['field_purpose'], 'field_scope'=>$classification['field_scope'], 'scope'=>$classification['field_scope'], 'value_source'=>$classification['value_source'], 'source'=>$classification['value_source'], 'ui_visibility'=>$classification['ui_visibility'], 'visibility'=>$classification['ui_visibility'], 'editable'=>$classification['is_editable'], 'is_editable'=>$classification['is_editable'], 'width'=>(int)$row['width'], 'is_required'=>(bool)((int)$row['is_required']), 'is_localized'=>(bool)((int)$row['is_localized']), 'is_system'=>(bool)((int)$row['is_system']), 'is_deletable'=>$classification['field_scope'] === 'editorial' ? (bool)((int)$row['is_deletable']) : false, 'sort_order'=>(int)$row['sort_order'], 'default_value'=> isset($row['default_value_json']) && $row['default_value_json'] !== null ? $this->decodeJson((string)$row['default_value_json'], null) : null, 'options'=>$this->decodeJson((string)$row['options_json'], []), 'validation'=>$this->decodeJson((string)$row['validation_json'], []), 'conditions'=>$this->decodeJson((string)$row['conditions_json'], []), 'config'=>$this->mergeFieldConfig(is_array($config) ? $config : [], $classification)];
    }

    /** @return array<string,mixed> */
    private function normalizeFieldsetRow(array $row): array
    {
        return ['id'=>(int)$row['id'], 'fieldset_key'=>(string)$row['fieldset_key'], 'label'=>(string)$row['label'], 'description'=>(string)($row['description'] ?? ''), 'fieldset_purpose'=>(string)$row['fieldset_purpose'], 'is_system'=>(bool)((int)$row['is_system']), 'is_deletable'=>(bool)((int)$row['is_deletable']), 'fields_count'=>(int)($row['fields_count'] ?? 0), 'usage_count'=>(int)($row['usage_count'] ?? 0), 'config'=>$this->decodeJson((string)($row['config_json'] ?? '{}'), [])];
    }

    private function normalizeBlueprintRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'blueprint_key' => (string) $row['blueprint_key'],
            'resource_type' => (string) $row['resource_type'],
            'site_id' => $row['site_id'] === null ? null : (int) $row['site_id'],
            'legacy_content_type_id' => $row['legacy_content_type_id'] === null ? null : (int) $row['legacy_content_type_id'],
            'label' => (string) $row['label'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'is_active' => (bool) ((int) $row['is_active']),
            'active_version_id' => $row['active_version_id'] === null ? null : (int) $row['active_version_id'],
            'active_version' => $row['active_version'] === null ? null : (int) $row['active_version'],
            'active_version_label' => $row['active_version_label'] ?? null,
        ];
    }

    private function normalizeVersionRow(array $row): array
    {
        $decode = static function (string $value): array {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        };
        return [
            'id' => (int) $row['id'],
            'blueprint_id' => (int) $row['blueprint_id'],
            'version' => (int) $row['version'],
            'version_label' => $row['version_label'] === null ? null : (string) $row['version_label'],
            'status' => (string) $row['status'],
            'is_active' => (bool) ((int) $row['is_active']),
            'checksum_sha256' => isset($row['checksum_sha256']) ? (string) $row['checksum_sha256'] : null,
            'schema' => $decode((string) $row['schema_json']),
            'ui_schema' => $decode((string) $row['ui_schema_json']),
            'validation' => $decode((string) $row['validation_json']),
            'seo_policy' => $decode((string) $row['seo_policy_json']),
            'routing_policy' => $decode((string) $row['routing_policy_json']),
            'workflow_policy' => $decode((string) $row['workflow_policy_json']),
            'translation_policy' => $decode((string) $row['translation_policy_json']),
            'permissions_policy' => $decode((string) $row['permissions_policy_json']),
        ];
    }
}
