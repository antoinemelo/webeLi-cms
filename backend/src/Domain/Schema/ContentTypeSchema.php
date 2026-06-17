<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Domain\Seo\SeoPolicy;
use App\Domain\Taxonomy\TaxonomyPolicy;

final class ContentTypeSchema
{
    /** @var array<string,FieldDefinition> */
    private array $fieldsByKey = [];

    /** @var array<string,EditorTab> */
    private array $tabsByKey = [];

    /** @param list<FieldDefinition> $fields @param list<EditorTab> $editorTabs */
    public function __construct(
        public readonly ContentType $contentType,
        public readonly array $fields = [],
        public readonly array $editorTabs = [],
        public readonly ?TemplateBinding $templateBinding = null,
        public readonly ?SeoPolicy $seoPolicy = null,
        public readonly ?TaxonomyPolicy $taxonomyPolicy = null
    ) {
        foreach ($fields as $field) {
            if (!$field instanceof FieldDefinition) {
                throw new \InvalidArgumentException('Un schéma de type de contenu ne peut contenir que des FieldDefinition.');
            }
            if (isset($this->fieldsByKey[$field->fieldKey])) {
                throw new \InvalidArgumentException(sprintf('Champ dupliqué dans le schéma : %s.', $field->fieldKey));
            }
            $this->fieldsByKey[$field->fieldKey] = $field;
        }
        foreach ($editorTabs as $tab) {
            if (!$tab instanceof EditorTab) {
                throw new \InvalidArgumentException('Un schéma de type de contenu ne peut contenir que des EditorTab.');
            }
            $this->tabsByKey[$tab->tabKey] = $tab;
        }
        if ($this->tabsByKey === []) {
            $this->tabsByKey['content'] = EditorTab::content();
        }
    }

    public function typeKey(): string
    {
        return $this->contentType->typeKey;
    }

    public function acceptsLocalizations(): bool
    {
        return $this->contentType->acceptsLocalizations();
    }

    public function fieldExists(string $fieldKey): bool
    {
        return isset($this->fieldsByKey[$fieldKey]);
    }

    public function field(string $fieldKey): ?FieldDefinition
    {
        return $this->fieldsByKey[$fieldKey] ?? null;
    }

    /** @return list<FieldDefinition> */
    public function requiredFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldDefinition $field): bool => $field->isRequired()));
    }

    /** @return list<FieldDefinition> */
    public function translatableFields(): array
    {
        if (!$this->acceptsLocalizations()) {
            return [];
        }
        return array_values(array_filter($this->fields, static fn(FieldDefinition $field): bool => $field->isTranslatable()));
    }

    public function isFieldRequired(string $fieldKey): bool
    {
        return (bool) $this->field($fieldKey)?->isRequired();
    }

    public function isFieldTranslatable(string $fieldKey): bool
    {
        return $this->acceptsLocalizations() && (bool) $this->field($fieldKey)?->isTranslatable();
    }

    /** @param array<string,mixed> $values @return list<string> */
    public function validatePayload(array $values): array
    {
        $errors = [];
        foreach ($this->fields as $field) {
            array_push($errors, ...$field->validateValue($values[$field->fieldKey] ?? null));
        }
        foreach ($values as $key => $_) {
            if (!$this->fieldExists((string) $key) && !in_array($key, ['title', 'slug', 'summary', 'excerpt', 'body', 'blocks', 'content_blocks', 'meta_title', 'meta_description', 'meta_robots', 'og_image_media_id', 'og_image_src', 'twitter_image_media_id', 'twitter_image_src', 'change_notes', 'entry_key', 'fields', 'taxonomy_terms', 'taxonomies', 'status', 'workflow_state', 'language', 'site', 'site_id', 'revision_state'], true)) {
                $errors[] = sprintf('Le champ "%s" n’existe pas dans le schéma "%s".', (string) $key, $this->typeKey());
            }
        }
        return $errors;
    }

    /** @return list<EditorTab> */
    public function editorTabs(): array
    {
        $tabs = array_values($this->tabsByKey);
        usort($tabs, static fn(EditorTab $a, EditorTab $b): int => $a->sortOrder <=> $b->sortOrder);
        return $tabs;
    }

    public function templateBinding(): TemplateBinding
    {
        return $this->templateBinding ?? TemplateBinding::none();
    }

    public function seoPolicy(): SeoPolicy
    {
        return $this->seoPolicy ?? $this->contentType->effectiveSeoPolicy();
    }

    public function taxonomyPolicy(): TaxonomyPolicy
    {
        return $this->taxonomyPolicy ?? $this->contentType->effectiveTaxonomyPolicy();
    }

    public function toArray(): array
    {
        return [
            'content_type' => $this->contentType->toArray(),
            'fields' => array_map(static fn(FieldDefinition $field): array => $field->toArray(), $this->fields),
            'editor_tabs' => array_map(static fn(EditorTab $tab): array => $tab->toArray(), $this->editorTabs()),
            'template_binding' => $this->templateBinding()->toArray(),
            'seo_policy' => $this->seoPolicy()->toArray(),
            'taxonomy_policy' => $this->taxonomyPolicy()->toArray(),
        ];
    }
}
