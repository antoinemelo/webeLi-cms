<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Schema\ContentTypeRepository;
use App\Core\Database;
use App\Domain\Schema\ContentType;
use App\Domain\Schema\EditorTab;
use App\Domain\Schema\FieldDefinition;

final class SqlContentTypeRepository implements ContentTypeRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly SqlFieldDefinitionRepository $fields,
    ) {}

    public function findByKey(string $typeKey): ?ContentType
    {
        foreach ($this->contentTypeKeyCandidates($typeKey) as $candidate) {
            $row = $this->db->one('SELECT * FROM content_types WHERE type_key = :type_key LIMIT 1', ['type_key' => $candidate]);
            if ($row) {
                return $this->hydrate($row);
            }
        }
        return null;
    }

    public function findIdByKey(string $typeKey): ?int
    {
        foreach ($this->contentTypeKeyCandidates($typeKey) as $candidate) {
            $row = $this->db->one('SELECT id FROM content_types WHERE type_key = :type_key LIMIT 1', ['type_key' => $candidate]);
            if ($row) {
                return (int) $row['id'];
            }
        }
        return null;
    }


    /**
     * Accept stable content type keys and admin route aliases. This keeps the
     * repository as the final source of truth, so edit forms, save actions and
     * schema endpoints cannot diverge between `pages` and native `page`.
     *
     * @return list<string>
     */
    private function contentTypeKeyCandidates(string $rawType): array
    {
        $rawType = strtolower(trim($rawType));
        $rawType = trim($rawType, " \t\n\r\0\x0B/");
        $rawType = preg_replace('/[^a-z0-9_\-\/]/', '', $rawType) ?: '';

        $segments = array_values(array_filter(explode('/', $rawType), static fn(string $segment): bool => $segment !== ''));
        $lastSemanticSegment = '';
        foreach (array_reverse($segments) as $segment) {
            if ($segment === 'contents' || $segment === 'content' || $segment === 'new' || $segment === 'edit' || ctype_digit($segment)) {
                continue;
            }
            $lastSemanticSegment = $segment;
            break;
        }

        $normalized = $lastSemanticSegment !== '' ? $lastSemanticSegment : $rawType;
        $aliases = [
            'pages' => 'page',
            'articles' => 'article',
        ];

        $candidates = [];
        foreach ([$normalized, $aliases[$normalized] ?? '', rtrim($normalized, 's')] as $candidate) {
            if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }
        return $candidates;
    }

    public function listEnabled(bool $adminOnly = false): array
    {
        $where = $adminOnly ? 'WHERE admin_enabled = 1 AND is_hidden = 0' : 'WHERE is_hidden = 0';
        $rows = $this->db->all("SELECT * FROM content_types {$where} ORDER BY is_system DESC, type_key");
        return array_map(fn(array $row): ContentType => $this->hydrate($row), $rows);
    }

    public function exists(string $typeKey): bool
    {
        return $this->findIdByKey($typeKey) !== null;
    }

    public function save(ContentType $contentType): int
    {
        $existingId = $this->findIdByKey($contentType->typeKey);
        $params = [
            'type_key' => $contentType->typeKey,
            'name' => $contentType->name,
            'singular_label' => $contentType->singularLabel ?: $contentType->name,
            'plural_label' => $contentType->pluralLabel ?: $contentType->name,
            'has_localizations' => $contentType->hasLocalizations ? 1 : 0,
            'has_revisions' => $contentType->hasRevisions ? 1 : 0,
            'has_workflow' => $contentType->hasWorkflow ? 1 : 0,
            'has_permalink' => $contentType->hasPermalink ? 1 : 0,
            'has_layout' => $contentType->hasLayout ? 1 : 0,
            'has_taxonomies' => $contentType->hasTaxonomies ? 1 : 0,
            'has_seo' => $contentType->hasSeo ? 1 : 0,
            'default_status' => $contentType->defaultStatus,
            'frontend_template' => $contentType->effectiveTemplateBinding()->template(),
            'frontend_resolver' => $contentType->effectiveTemplateBinding()->resolverClass,
            'updated_at' => now_utc(),
        ];

        if ($existingId !== null) {
            $this->db->run(
                'UPDATE content_types SET
                    name = :name,
                    singular_label = :singular_label,
                    plural_label = :plural_label,
                    has_localizations = :has_localizations,
                    has_revisions = :has_revisions,
                    has_workflow = :has_workflow,
                    has_permalink = :has_permalink,
                    has_layout = :has_layout,
                    has_taxonomies = :has_taxonomies,
                    has_seo = :has_seo,
                    default_status = :default_status,
                    frontend_template = :frontend_template,
                    frontend_resolver = :frontend_resolver,
                    updated_at = :updated_at
                 WHERE type_key = :type_key',
                $params
            );
            $this->fields->replaceForContentTypeId($existingId, $contentType->fields);
            return $existingId;
        }

        $this->db->run(
            'INSERT INTO content_types(
                type_key, name, singular_label, plural_label,
                has_localizations, has_revisions, has_workflow, has_permalink, has_layout,
                has_taxonomies, has_seo, default_status, frontend_template, frontend_resolver,
                storage_mode, api_enabled, admin_enabled, created_at, updated_at
            ) VALUES(
                :type_key, :name, :singular_label, :plural_label,
                :has_localizations, :has_revisions, :has_workflow, :has_permalink, :has_layout,
                :has_taxonomies, :has_seo, :default_status, :frontend_template, :frontend_resolver,
                :storage_mode, 1, 1, :created_at, :updated_at
            )',
            $params + ['storage_mode' => 'hybrid', 'created_at' => now_utc()]
        );
        $newId = $this->db->lastInsertId();
        $this->fields->replaceForContentTypeId($newId, $contentType->fields);
        return $newId;
    }

    private function hydrate(array $row): ContentType
    {
        $id = (int) $row['id'];
        $fields = $this->fields->listForContentTypeId($id);
        return ContentType::fromArray($row, $fields, $this->editorTabsFor($fields));
    }

    /** @param list<FieldDefinition> $fields @return list<EditorTab> */
    private function editorTabsFor(array $fields): array
    {
        $tabs = [];
        foreach ($fields as $field) {
            $tabKey = $field->tabKey ?: 'content';
            if (!isset($tabs[$tabKey])) {
                $tabs[$tabKey] = new EditorTab($tabKey, $this->labelForTab($tabKey), $this->sortForTab($tabKey));
            }
            $tabs[$tabKey] = $tabs[$tabKey]->withField($field->fieldKey);
        }
        if ($tabs === []) {
            $tabs['content'] = EditorTab::content();
        }
        uasort($tabs, static fn(EditorTab $a, EditorTab $b): int => $a->sortOrder <=> $b->sortOrder);
        return array_values($tabs);
    }

    private function labelForTab(string $tabKey): string
    {
        return match ($tabKey) {
            'content' => 'Structure',
            'seo' => 'SEO',
            'settings' => 'Réglages',
            'taxonomy', 'taxonomies' => 'Taxonomies',
            default => ucfirst(str_replace(['_', '-'], ' ', $tabKey)),
        };
    }

    private function sortForTab(string $tabKey): int
    {
        return match ($tabKey) {
            'content' => 10,
            'taxonomy', 'taxonomies' => 70,
            'seo' => 80,
            'settings' => 100,
            default => 50,
        };
    }
}
