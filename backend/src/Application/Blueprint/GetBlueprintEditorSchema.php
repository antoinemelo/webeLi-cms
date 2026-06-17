<?php

declare(strict_types=1);

namespace App\Application\Blueprint;

use App\Core\Database;

final class GetBlueprintEditorSchema
{
    public function __construct(
        private readonly BlueprintRepository $blueprints,
        private readonly ?Database $db = null,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $key, ?int $siteId = null): array
    {
        $blueprint = $this->blueprints->findByKey($key, 'content_type', $siteId);
        $version = $this->blueprints->activeVersion($key, 'content_type', $siteId);
        if (!$blueprint || !$version) {
            throw new \RuntimeException(sprintf('Blueprint actif introuvable : %s.', $key));
        }

        $schema = self::fromBlueprintVersion($blueprint, $version);
        $schema = $this->withBlueprintFieldHelp($schema, (int) ($blueprint['id'] ?? 0));
        return $this->withNativeTaxonomyPolicy($schema, (string) ($schema['content_type']['type_key'] ?? $key), $siteId);
    }

    /** @param array<string,mixed> $blueprint @param array<string,mixed> $version @return array<string,mixed> */
    public static function fromBlueprintVersion(array $blueprint, array $version): array
    {
        $schema = is_array($version['schema'] ?? null) ? $version['schema'] : [];
        $ui = is_array($version['ui_schema'] ?? null) ? $version['ui_schema'] : [];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $classified = self::classifyFields($fields);
        $fields = $classified['editable_fields'];
        $tabs = self::filterTabs(
            is_array($ui['editor_tabs'] ?? null) ? $ui['editor_tabs'] : [],
            $classified['editable_handles']
        );

        return [
            'blueprint' => [
                'blueprint_key' => (string) $blueprint['blueprint_key'],
                'resource_type' => (string) $blueprint['resource_type'],
                'site_id' => $blueprint['site_id'] ?? null,
                'version' => (int) ($version['version'] ?? 1),
                'version_label' => $version['version_label'] ?? null,
                'is_active' => (bool) ($blueprint['is_active'] ?? true),
            ],
            'content_type' => [
                'type_key' => (string) ($schema['content_type']['type_key'] ?? $blueprint['blueprint_key']),
                'name' => (string) ($schema['content_type']['name'] ?? $blueprint['label'] ?? $blueprint['blueprint_key']),
                'singular_label' => (string) ($schema['content_type']['singular_label'] ?? $blueprint['label'] ?? $blueprint['blueprint_key']),
                'plural_label' => (string) ($schema['content_type']['plural_label'] ?? $blueprint['label'] ?? $blueprint['blueprint_key']),
                'default_status' => (string) ($version['workflow_policy']['default_state'] ?? 'draft'),
                'capabilities' => $schema['capabilities'] ?? [],
            ],
            'fields' => $fields,
            'system_context' => ['fields' => $classified['context_fields']],
            'editor_tabs' => $tabs,
            'ui_schema' => $ui,
            'validation' => $version['validation'] ?? [],
            'seo_policy' => $version['seo_policy'] ?? [],
            'taxonomy_policy' => $version['taxonomy_policy'] ?? [],
            'routing_policy' => $version['routing_policy'] ?? [],
            'workflow_policy' => $version['workflow_policy'] ?? [],
            'translation_policy' => $version['translation_policy'] ?? [],
            'permissions_policy' => $version['permissions_policy'] ?? [],
            'template_binding' => $schema['template_binding'] ?? [],
            'headless' => $schema['headless'] ?? ['enabled' => true],
        ];
    }


    /**
     * @param list<mixed> $fields
     * @return array{editable_fields:list<array<string,mixed>>,context_fields:list<array<string,mixed>>,editable_handles:array<string,bool>}
     */
    private static function classifyFields(array $fields): array
    {
        $editable = [];
        $context = [];
        $editableHandles = [];
        $seenHandles = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $normalized = self::withFieldClassification($field);
            $handle = self::fieldHandle($normalized);
            if ($handle !== '') {
                $seenHandles[$handle] = true;
            }
            if (self::isContextField($normalized)) {
                $context[] = $normalized;
                continue;
            }
            $editable[] = $normalized;
            if ($handle !== '') {
                $editableHandles[$handle] = true;
            }
        }

        foreach (self::nativeContextFields() as $field) {
            $handle = self::fieldHandle($field);
            if ($handle !== '' && !isset($seenHandles[$handle])) {
                $context[] = self::withFieldClassification($field);
            }
        }

        return ['editable_fields' => $editable, 'context_fields' => $context, 'editable_handles' => $editableHandles];
    }

    /** @return list<array<string,mixed>> */
    private static function nativeContextFields(): array
    {
        return [
            ['field_key' => 'status', 'label' => 'Statut', 'type' => 'select', 'field_scope' => 'system_context', 'value_source' => 'workflow', 'ui_visibility' => 'summary', 'editable' => false, 'is_required' => true, 'is_system' => true, 'is_deletable' => false],
            ['field_key' => 'language', 'label' => 'Langue', 'type' => 'select', 'field_scope' => 'system_context', 'value_source' => 'context', 'ui_visibility' => 'summary', 'editable' => false, 'is_required' => true, 'is_system' => true, 'is_deletable' => false],
            ['field_key' => 'site', 'label' => 'Site', 'type' => 'sites', 'field_scope' => 'system_context', 'value_source' => 'context', 'ui_visibility' => 'summary', 'editable' => false, 'is_required' => true, 'is_system' => true, 'is_deletable' => false],
            ['field_key' => 'revision_state', 'label' => 'Révision', 'type' => 'select', 'field_scope' => 'system_context', 'value_source' => 'workflow', 'ui_visibility' => 'summary', 'editable' => false, 'is_required' => false, 'is_system' => true, 'is_deletable' => false],
        ];
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private static function withFieldClassification(array $field): array
    {
        $handle = self::fieldHandle($field);
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $scope = (string) ($field['field_scope'] ?? $field['scope'] ?? $config['field_scope'] ?? $config['scope'] ?? '');
        $source = (string) ($field['value_source'] ?? $field['source'] ?? $config['value_source'] ?? $config['source'] ?? '');
        $visibility = (string) ($field['ui_visibility'] ?? $field['visibility'] ?? $config['ui_visibility'] ?? $config['visibility'] ?? '');

        if ($scope === '') {
            $scope = match ($handle) {
                'status', 'workflow_state', 'language', 'site', 'site_id', 'revision_state', 'content_type', 'blueprint_id', 'blueprint_version_id' => 'system_context',
                'title', 'slug', 'entry_key', 'published_at', 'template' => 'system_editable',
                default => 'editorial',
            };
        }
        if ($source === '') {
            $source = match ($handle) {
                'status', 'workflow_state', 'revision_state' => 'workflow',
                'language', 'site', 'site_id', 'content_type', 'blueprint_id', 'blueprint_version_id' => 'context',
                default => 'content',
            };
        }
        if ($visibility === '') {
            $visibility = $scope === 'system_context' ? 'summary' : 'form';
        }

        $field['field_scope'] = $scope;
        $field['scope'] = $scope;
        $field['value_source'] = $source;
        $field['source'] = $source;
        $field['ui_visibility'] = $visibility;
        $field['editable'] = array_key_exists('editable', $field) ? (bool) $field['editable'] : $scope !== 'system_context';
        $field['is_editable'] = array_key_exists('is_editable', $field) ? (bool) $field['is_editable'] : (bool) $field['editable'];
        $field['protected'] = array_key_exists('protected', $field) ? (bool) $field['protected'] : in_array($scope, ['system_context','system_editable'], true);
        $field['is_deletable'] = $scope === 'editorial' ? (bool) ($field['is_deletable'] ?? true) : false;
        $config['field_scope'] = $scope;
        $config['value_source'] = $source;
        $config['ui_visibility'] = $visibility;
        $config['editable'] = (bool) $field['editable'];
        $field['config'] = $config;
        return $field;
    }

    /** @param array<string,mixed> $field */
    private static function fieldHandle(array $field): string
    {
        return (string) ($field['field_key'] ?? $field['key'] ?? $field['field_handle'] ?? $field['handle'] ?? '');
    }

    /** @param array<string,mixed> $field */
    private static function isContextField(array $field): bool
    {
        $scope = (string) ($field['field_scope'] ?? $field['scope'] ?? '');
        $visibility = (string) ($field['ui_visibility'] ?? $field['visibility'] ?? '');
        return $scope === 'system_context' || in_array($visibility, ['summary', 'native', 'readonly_summary'], true);
    }

    /** @param list<mixed> $tabs @param array<string,bool> $editableHandles @return list<array<string,mixed>> */
    private static function filterTabs(array $tabs, array $editableHandles): array
    {
        $filtered = [];
        foreach ($tabs as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $fields = [];
            foreach (is_array($tab['fields'] ?? null) ? $tab['fields'] : [] as $field) {
                if (is_string($field)) {
                    if (isset($editableHandles[$field])) {
                        $fields[] = $field;
                    }
                    continue;
                }
                if (is_array($field)) {
                    $handle = self::fieldHandle($field);
                    $normalized = self::withFieldClassification($field);
                    if ($handle !== '' && isset($editableHandles[$handle]) && !self::isContextField($normalized)) {
                        $fields[] = $normalized;
                    }
                }
            }
            if ($fields === []) {
                continue;
            }
            $tab['fields'] = $fields;
            $filtered[] = $tab;
        }
        return $filtered;
    }

    /**
     * Keep editor help bubbles strictly sourced from blueprint field definitions.
     * The active schema remains the canonical structure, while blueprint_fields
     * can hold the current help text edited in the blueprint designer. Empty
     * help values stay empty so the Vue editor does not display an information
     * icon when no explanation exists in the blueprint.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function withBlueprintFieldHelp(array $schema, int $blueprintId): array
    {
        if ($this->db === null || $blueprintId <= 0 || !$this->db->tableExists('blueprint_fields')) {
            return $schema;
        }

        $rows = $this->db->all(
            'SELECT field_handle, help_text FROM blueprint_fields WHERE blueprint_id = ? ORDER BY sort_order ASC, id ASC',
            [$blueprintId]
        );
        $helpByHandle = [];
        foreach ($rows as $row) {
            $handle = trim((string) ($row['field_handle'] ?? ''));
            if ($handle === '') {
                continue;
            }
            $helpByHandle[$handle] = trim((string) ($row['help_text'] ?? ''));
        }
        if ($helpByHandle === []) {
            return $schema;
        }

        $applyHelp = static function (array $field) use ($helpByHandle): array {
            $handle = self::fieldHandle($field);
            if ($handle === '' || !array_key_exists($handle, $helpByHandle)) {
                return $field;
            }

            $helpText = $helpByHandle[$handle];
            if ($helpText === '') {
                unset($field['help_text'], $field['help'], $field['instructions']);
                if (isset($field['config']) && is_array($field['config'])) {
                    unset($field['config']['help_text'], $field['config']['help'], $field['config']['instructions']);
                }
                return $field;
            }

            $field['help_text'] = $helpText;
            $config = is_array($field['config'] ?? null) ? $field['config'] : [];
            $config['help_text'] = $helpText;
            $field['config'] = $config;
            return $field;
        };

        if (isset($schema['fields']) && is_array($schema['fields'])) {
            $schema['fields'] = array_map(
                static fn(mixed $field): mixed => is_array($field) ? $applyHelp($field) : $field,
                $schema['fields']
            );
        }
        if (isset($schema['system_context']['fields']) && is_array($schema['system_context']['fields'])) {
            $schema['system_context']['fields'] = array_map(
                static fn(mixed $field): mixed => is_array($field) ? $applyHelp($field) : $field,
                $schema['system_context']['fields']
            );
        }

        $schema['blueprint_field_help'] = $helpByHandle;
        return $schema;
    }

    /**
     * A content type blueprint defines the editorial fields, but categories and
     * tags are live site data. The Vue editor loads the blueprint endpoint first,
     * so the response must expose the same taxonomy policy as the legacy content
     * type endpoint.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function withNativeTaxonomyPolicy(array $schema, string $typeKey, ?int $siteId): array
    {
        if ($this->db === null || $typeKey === '') {
            return $schema;
        }

        $taxonomies = $this->taxonomiesForContentType($typeKey, $siteId);
        $existingPolicy = is_array($schema['taxonomy_policy'] ?? null) ? $schema['taxonomy_policy'] : [];
        $schema['taxonomy_policy'] = $existingPolicy + ['enabled' => true];
        $schema['taxonomy_policy']['taxonomies'] = $taxonomies;

        $contentType = is_array($schema['content_type'] ?? null) ? $schema['content_type'] : [];
        $capabilities = is_array($contentType['capabilities'] ?? null) ? $contentType['capabilities'] : [];
        $capabilities['taxonomies'] = $taxonomies !== [] || (bool) ($capabilities['taxonomies'] ?? false);
        $contentType['capabilities'] = $capabilities;
        $schema['content_type'] = $contentType;

        $schema['editor_tabs'] = $this->withTaxonomyTab(is_array($schema['editor_tabs'] ?? null) ? $schema['editor_tabs'] : [], $taxonomies !== []);
        $uiSchema = is_array($schema['ui_schema'] ?? null) ? $schema['ui_schema'] : [];
        if (is_array($uiSchema['editor_tabs'] ?? null)) {
            $uiSchema['editor_tabs'] = $this->withTaxonomyTab($uiSchema['editor_tabs'], $taxonomies !== []);
        }
        $schema['ui_schema'] = $uiSchema;

        return $schema;
    }

    /** @param list<array<string,mixed>> $tabs @return list<array<string,mixed>> */
    private function withTaxonomyTab(array $tabs, bool $enabled): array
    {
        if (!$enabled) {
            return $tabs;
        }
        foreach ($tabs as $tab) {
            if (is_array($tab) && (string) ($tab['key'] ?? '') === 'taxonomies') {
                return $tabs;
            }
        }

        $next = [];
        $inserted = false;
        foreach ($tabs as $tab) {
            if (!$inserted && is_array($tab) && (string) ($tab['key'] ?? '') === 'seo') {
                $next[] = ['key' => 'taxonomies', 'label' => 'Taxonomie', 'fields' => [], 'sort_order' => 70];
                $inserted = true;
            }
            $next[] = $tab;
        }
        if (!$inserted) {
            $next[] = ['key' => 'taxonomies', 'label' => 'Taxonomie', 'fields' => [], 'sort_order' => 70];
        }
        return $next;
    }

    /** @return list<array<string,mixed>> */
    private function taxonomiesForContentType(string $typeKey, ?int $siteId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT t.id, t.site_id, t.taxonomy_key, t.name, t.description, t.is_hierarchical, t.is_localized,
                    t.seo_enabled, t.archive_enabled, t.sort_order, ctt.is_required, ctt.max_terms
             FROM content_type_taxonomies ctt
             JOIN content_types ct ON ct.id = ctt.content_type_id
             JOIN taxonomies t ON t.id = ctt.taxonomy_id
             WHERE ct.type_key = :type_key
               AND (:site_id IS NULL OR t.site_id = :site_id)
             ORDER BY ctt.is_required DESC, t.sort_order, t.taxonomy_key',
            ['type_key' => $typeKey, 'site_id' => $siteId],
        );

        $policies = [];
        foreach ($rows as $row) {
            $policies[] = [
                'id' => (int) $row['id'],
                'site_id' => (int) $row['site_id'],
                'taxonomy_key' => (string) $row['taxonomy_key'],
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'is_hierarchical' => (bool) ($row['is_hierarchical'] ?? false),
                'is_localized' => (bool) ($row['is_localized'] ?? true),
                'seo_enabled' => (bool) ($row['seo_enabled'] ?? true),
                'archive_enabled' => (bool) ($row['archive_enabled'] ?? true),
                'is_required' => (bool) ($row['is_required'] ?? false),
                'max_terms' => $row['max_terms'] === null ? null : (int) $row['max_terms'],
                'terms' => $this->termsForTaxonomy((int) $row['id']),
            ];
        }
        return $policies;
    }

    /** @return list<array<string,mixed>> */
    private function termsForTaxonomy(int $taxonomyId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT tt.id, tt.taxonomy_id, tt.parent_id, tt.term_key, tt.is_active, tt.sort_order,
                    ttl.language_code, ttl.name, ttl.slug, ttl.full_path, ttl.description
             FROM taxonomy_terms tt
             LEFT JOIN taxonomy_term_localizations ttl ON ttl.term_id = tt.id
             WHERE tt.taxonomy_id = :taxonomy_id
               AND tt.is_active = 1
             ORDER BY tt.sort_order, ttl.name, tt.term_key',
            ['taxonomy_id' => $taxonomyId],
        );

        $byId = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id,
                    'taxonomy_id' => (int) $row['taxonomy_id'],
                    'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                    'term_key' => (string) $row['term_key'],
                    'name' => (string) ($row['name'] ?? $row['term_key']),
                    'slug' => (string) ($row['slug'] ?? $row['term_key']),
                    'full_path' => (string) ($row['full_path'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'localizations' => [],
                ];
            }
            if (!empty($row['language_code'])) {
                $byId[$id]['localizations'][(string) $row['language_code']] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'full_path' => (string) ($row['full_path'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                ];
            }
        }
        return array_values($byId);
    }
}
