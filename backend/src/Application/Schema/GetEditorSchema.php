<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Core\Database;
use App\Application\Blueprint\BlueprintRepository;
use App\Application\Blueprint\GetBlueprintEditorSchema;

final class GetEditorSchema
{
    public function __construct(
        private readonly ContentTypeRepository $contentTypes,
        private readonly Database $db,
        private readonly ?BlueprintRepository $blueprints = null,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $typeKey, ?int $siteId = null): array
    {
        if ($this->blueprints !== null) {
            $blueprint = $this->blueprints->findByKey($typeKey, 'content_type');
            $version = $this->blueprints->activeVersion($typeKey, 'content_type');
            if ($blueprint && $version) {
                $blueprintSchema = GetBlueprintEditorSchema::fromBlueprintVersion($blueprint, $version);
                $blueprintSchema = $this->withBlueprintFieldHelp($blueprintSchema, (int) ($blueprint['id'] ?? 0));
                $taxonomyPolicy = is_array($blueprintSchema['taxonomy_policy'] ?? null) ? $blueprintSchema['taxonomy_policy'] : [];
                $taxonomies = $this->taxonomiesForContentType($typeKey, $siteId);
                $taxonomyPolicy['enabled'] = true;
                $taxonomyPolicy['taxonomies'] = $taxonomies;
                $blueprintSchema['taxonomy_policy'] = $taxonomyPolicy;
                $blueprintSchema['editor_tabs'] = $this->withTaxonomyTab(is_array($blueprintSchema['editor_tabs'] ?? null) ? $blueprintSchema['editor_tabs'] : [], $taxonomies !== []);
                if (is_array($blueprintSchema['ui_schema']['editor_tabs'] ?? null)) {
                    $blueprintSchema['ui_schema']['editor_tabs'] = $this->withTaxonomyTab($blueprintSchema['ui_schema']['editor_tabs'], $taxonomies !== []);
                }
                if (isset($blueprintSchema['content_type']) && is_array($blueprintSchema['content_type'])) {
                    $capabilities = is_array($blueprintSchema['content_type']['capabilities'] ?? null) ? $blueprintSchema['content_type']['capabilities'] : [];
                    $capabilities['taxonomies'] = $taxonomies !== [] || (bool) ($capabilities['taxonomies'] ?? false);
                    $blueprintSchema['content_type']['capabilities'] = $capabilities;
                }
                return $blueprintSchema + [
                    'compatibility' => [
                        'legacy_content_types_enabled' => true,
                        'fallback' => false,
                    ],
                ];
            }
        }

        $contentType = $this->contentTypes->findByKey($typeKey);
        if (!$contentType) {
            throw new \RuntimeException(sprintf('Type de contenu introuvable : %s.', $typeKey));
        }

        $schema = $contentType->schema();
        $taxonomyPolicy = $schema->taxonomyPolicy()->toArray();
        $taxonomyPolicy['taxonomies'] = $this->taxonomiesForContentType($contentType->typeKey, $siteId);

        return [
            'content_type' => [
                'type_key' => $contentType->typeKey,
                'name' => $contentType->name,
                'singular_label' => $contentType->singularLabel,
                'plural_label' => $contentType->pluralLabel,
                'default_status' => $contentType->defaultStatus,
                'capabilities' => [
                    'localizations' => $contentType->acceptsLocalizations(),
                    'revisions' => $contentType->supportsRevisions(),
                    'workflow' => $contentType->supportsWorkflow(),
                    'permalink' => $contentType->supportsPermalink(),
                    'layout' => $contentType->hasLayout,
                    'seo' => $contentType->allowsSeo(),
                    'taxonomies' => $contentType->allowsTaxonomies(),
                ],
            ],
            'fields' => array_map(static fn($field): array => $field->toArray(), $schema->fields),
            'editor_tabs' => array_map(static fn($tab): array => $tab->toArray(), $schema->editorTabs()),
            'seo_policy' => $schema->seoPolicy()->toArray(),
            'taxonomy_policy' => $taxonomyPolicy,
            'workflow_policy' => [
                'enabled' => $contentType->supportsWorkflow(),
                'default_state' => $contentType->defaultStatus,
                'states' => ['draft', 'review', 'published', 'archived', 'scheduled'],
                'publish_requires_revision_id' => true,
                'publish_is_language_scoped' => true,
            ],
            'routing_policy' => [
                'enabled' => $contentType->supportsPermalink(),
                'resource_type' => 'content_entry',
                'language_scoped' => true,
                'unique_scope' => ['site_id', 'language_code', 'full_path'],
                'primary_route_per_language' => true,
            ],
            'template_binding' => $schema->templateBinding()->toArray(),
            'compatibility' => [
                'legacy_content_types_enabled' => true,
                'fallback' => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function withBlueprintFieldHelp(array $schema, int $blueprintId): array
    {
        if ($blueprintId <= 0 || !$this->db->tableExists('blueprint_fields')) {
            return $schema;
        }
        $rows = $this->db->all(
            'SELECT field_handle, help_text FROM blueprint_fields WHERE blueprint_id = ? ORDER BY sort_order ASC, id ASC',
            [$blueprintId]
        );
        $helpByHandle = [];
        foreach ($rows as $row) {
            $handle = trim((string) ($row['field_handle'] ?? ''));
            if ($handle !== '') {
                $helpByHandle[$handle] = trim((string) ($row['help_text'] ?? ''));
            }
        }
        if ($helpByHandle === []) {
            return $schema;
        }

        $applyHelp = static function (array $field) use ($helpByHandle): array {
            $handle = (string) ($field['field_key'] ?? $field['key'] ?? $field['field_handle'] ?? $field['handle'] ?? '');
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
            $schema['fields'] = array_map(static fn(mixed $field): mixed => is_array($field) ? $applyHelp($field) : $field, $schema['fields']);
        }
        if (isset($schema['system_context']['fields']) && is_array($schema['system_context']['fields'])) {
            $schema['system_context']['fields'] = array_map(static fn(mixed $field): mixed => is_array($field) ? $applyHelp($field) : $field, $schema['system_context']['fields']);
        }
        $schema['blueprint_field_help'] = $helpByHandle;
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
