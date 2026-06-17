<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Schema\FieldDefinitionRepository;
use App\Core\Database;
use App\Domain\Schema\FieldDefinition;

final class SqlFieldDefinitionRepository implements FieldDefinitionRepository
{
    public function __construct(private readonly Database $db) {}

    public function listForContentTypeId(int $contentTypeId): array
    {
        if ($contentTypeId < 1) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT f.*, fg.group_key, COALESCE(fg.tab_key, :default_tab) AS tab_key
             FROM fields f
             LEFT JOIN field_groups fg ON fg.id = f.group_id
             WHERE f.content_type_id = :content_type_id
             ORDER BY COALESCE(fg.sort_order, 0), f.sort_order, f.field_key',
            ['content_type_id' => $contentTypeId, 'default_tab' => 'content']
        );

        return array_map(static fn(array $row): FieldDefinition => FieldDefinition::fromArray($row), $rows);
    }

    public function replaceForContentTypeId(int $contentTypeId, array $fields): void
    {
        if ($contentTypeId < 1) {
            throw new \InvalidArgumentException('contentTypeId invalide.');
        }

        $this->db->run('DELETE FROM fields WHERE content_type_id = :content_type_id', ['content_type_id' => $contentTypeId]);

        $groupIds = $this->ensureGroups($contentTypeId, $fields);
        foreach ($fields as $field) {
            if (!$field instanceof FieldDefinition) {
                throw new \InvalidArgumentException('La liste des champs doit contenir uniquement des FieldDefinition.');
            }
            $groupKey = $field->groupKey ?: 'main';
            $this->db->run(
                'INSERT INTO fields(
                    content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key,
                    help_text, placeholder, default_value_json, options_json, validation_json,
                    is_required, is_unique, is_localized, is_indexed, is_filterable, is_sortable,
                    is_searchable, is_hidden, sort_order
                ) VALUES(
                    :content_type_id, :group_id, :field_key, :label, :field_type, :storage_mode, :interface_key,
                    :help_text, :placeholder, :default_value_json, :options_json, :validation_json,
                    :is_required, :is_unique, :is_localized, :is_indexed, :is_filterable, :is_sortable,
                    :is_searchable, :is_hidden, :sort_order
                )',
                [
                    'content_type_id' => $contentTypeId,
                    'group_id' => $groupIds[$groupKey] ?? null,
                    'field_key' => $field->fieldKey,
                    'label' => $field->label,
                    'field_type' => $field->fieldType,
                    'storage_mode' => $field->type()->storesJson() ? 'json' : 'value_table',
                    'interface_key' => $field->fieldType,
                    'help_text' => null,
                    'placeholder' => null,
                    'default_value_json' => null,
                    'options_json' => $this->encodeJson($field->options),
                    'validation_json' => $this->encodeJson($field->validation),
                    'is_required' => $field->isRequired ? 1 : 0,
                    'is_unique' => $field->isUnique ? 1 : 0,
                    'is_localized' => $field->isLocalized ? 1 : 0,
                    'is_indexed' => $field->isSearchable ? 1 : 0,
                    'is_filterable' => 0,
                    'is_sortable' => 0,
                    'is_searchable' => $field->isSearchable ? 1 : 0,
                    'is_hidden' => $field->isHidden ? 1 : 0,
                    'sort_order' => $field->sortOrder,
                ]
            );
        }
    }

    /** @param list<FieldDefinition> $fields @return array<string,int> */
    private function ensureGroups(int $contentTypeId, array $fields): array
    {
        $groups = [];
        foreach ($fields as $field) {
            if (!$field instanceof FieldDefinition) {
                continue;
            }
            $groupKey = $field->groupKey ?: 'main';
            $groups[$groupKey] = [
                'label' => $groupKey === 'main' ? 'Contenu' : ucfirst(str_replace(['_', '-'], ' ', $groupKey)),
                'tab_key' => $field->tabKey ?: 'content',
                'sort_order' => min($groups[$groupKey]['sort_order'] ?? $field->sortOrder, $field->sortOrder),
            ];
        }
        if ($groups === []) {
            $groups['main'] = ['label' => 'Contenu', 'tab_key' => 'content', 'sort_order' => 0];
        }

        $ids = [];
        foreach ($groups as $groupKey => $group) {
            $this->db->run(
                'INSERT OR IGNORE INTO field_groups(content_type_id, group_key, label, tab_key, sort_order)
                 VALUES(:content_type_id, :group_key, :label, :tab_key, :sort_order)',
                [
                    'content_type_id' => $contentTypeId,
                    'group_key' => $groupKey,
                    'label' => $group['label'],
                    'tab_key' => $group['tab_key'],
                    'sort_order' => $group['sort_order'],
                ]
            );
            $row = $this->db->one('SELECT id FROM field_groups WHERE content_type_id = :content_type_id AND group_key = :group_key', [
                'content_type_id' => $contentTypeId,
                'group_key' => $groupKey,
            ]);
            if ($row) {
                $ids[$groupKey] = (int) $row['id'];
            }
        }
        return $ids;
    }

    private function encodeJson(array $value): ?string
    {
        if ($value === []) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
