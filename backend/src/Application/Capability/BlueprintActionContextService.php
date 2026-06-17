<?php

declare(strict_types=1);

namespace App\Application\Capability;

use App\Core\Database;

final class BlueprintActionContextService
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function describe(?int $siteId = null): array
    {
        return [
            'site_id' => $siteId,
            'content_types' => $this->contentTypes(),
            'blueprints' => $this->blueprints($siteId),
            'block_types' => $this->blockTypes(),
            'seo_fields' => $this->seoFields(),
            'permissions' => $this->permissionHints(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function contentTypes(): array
    {
        $rows = $this->db->all(
            'SELECT ct.id, ct.type_key, ct.name, ct.singular_label, ct.plural_label, ct.has_localizations, ct.has_revisions, ct.has_workflow, ct.has_seo, ct.api_enabled, ct.admin_enabled
             FROM content_types ct
             WHERE ct.admin_enabled = 1
             ORDER BY ct.type_key'
        );
        foreach ($rows as &$row) {
            $row['fields'] = $this->fieldsForType((int) $row['id']);
            unset($row['id']);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function fieldsForType(int $typeId): array
    {
        return $this->db->all(
            'SELECT f.field_key, f.label, f.field_type, f.field_purpose, f.is_required, f.is_localized, f.is_unique, f.is_searchable, f.is_filterable, f.validation_json, f.sort_order
             FROM fields f
             WHERE f.content_type_id = :type_id
             ORDER BY f.sort_order, f.field_key',
            ['type_id' => $typeId],
        );
    }

    /** @return list<array<string,mixed>> */
    private function blueprints(?int $siteId): array
    {
        if (!$this->db->tableExists('blueprints')) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT b.blueprint_key, b.resource_type, b.label, b.description, b.site_id, bv.version
             FROM blueprints b
             LEFT JOIN blueprint_versions bv ON bv.id = b.active_version_id
             WHERE b.is_active = 1 AND (:site_id IS NULL OR b.site_id IS NULL OR b.site_id = :site_id)
             ORDER BY b.resource_type, b.blueprint_key',
            ['site_id' => $siteId],
        );
        return array_map(static fn(array $row): array => [
            'blueprint_key' => (string) ($row['blueprint_key'] ?? ''),
            'resource_type' => (string) ($row['resource_type'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'version' => (int) ($row['version'] ?? 1),
            'site_id' => isset($row['site_id']) ? (int) $row['site_id'] : null,
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function blockTypes(): array
    {
        if (!$this->db->tableExists('editor_block_types')) {
            return [];
        }
        return $this->db->all(
            'SELECT block_type AS type, label, category, schema_json, is_enabled
             FROM editor_block_types
             WHERE is_enabled = 1
             ORDER BY sort_order, block_type'
        );
    }

    /** @return list<string> */
    private function seoFields(): array
    {
        return ['meta_title', 'meta_description', 'meta_robots', 'canonical_url', 'og_title', 'og_description', 'twitter_title', 'twitter_description'];
    }

    /** @return array<string,string> */
    private function permissionHints(): array
    {
        return [
            'read_content' => 'content.read',
            'create_content' => 'content.create',
            'save_revision' => 'content.revisions.save',
            'publish_content' => 'content.publish',
            'manage_schema' => 'fields.manage',
            'read_schema' => 'fields.read',
        ];
    }
}
