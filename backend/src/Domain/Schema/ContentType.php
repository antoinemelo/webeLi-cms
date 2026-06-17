<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Domain\Seo\SeoPolicy;
use App\Domain\Taxonomy\TaxonomyPolicy;

final class ContentType
{
    /** @param list<FieldDefinition> $fields @param list<EditorTab> $editorTabs */
    public function __construct(
        public readonly string $typeKey,
        public readonly string $name,
        public readonly bool $hasLocalizations = true,
        public readonly bool $hasRevisions = true,
        public readonly bool $hasWorkflow = true,
        public readonly bool $hasPermalink = true,
        public readonly bool $hasLayout = false,
        public readonly bool $hasTaxonomies = false,
        public readonly bool $hasSeo = true,
        public readonly array $fields = [],
        public readonly array $editorTabs = [],
        public readonly ?TemplateBinding $templateBinding = null,
        public readonly ?SeoPolicy $seoPolicy = null,
        public readonly ?TaxonomyPolicy $taxonomyPolicy = null,
        public readonly string $defaultStatus = 'draft',
        public readonly ?string $singularLabel = null,
        public readonly ?string $pluralLabel = null
    ) {
        if (trim($this->typeKey) === '') {
            throw new \InvalidArgumentException('La clé du type de contenu est obligatoire.');
        }
        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Le nom du type de contenu est obligatoire.');
        }
        foreach ($this->fields as $field) {
            if (!$field instanceof FieldDefinition) {
                throw new \InvalidArgumentException('La liste des champs doit contenir uniquement des FieldDefinition.');
            }
        }
        foreach ($this->editorTabs as $tab) {
            if (!$tab instanceof EditorTab) {
                throw new \InvalidArgumentException('La liste des onglets doit contenir uniquement des EditorTab.');
            }
        }
    }

    public static function fromArray(array $row, array $fields = [], array $editorTabs = []): self
    {
        return new self(
            (string) ($row['type_key'] ?? ''),
            (string) ($row['name'] ?? $row['plural_label'] ?? ''),
            (bool) (int) ($row['has_localizations'] ?? 1),
            (bool) (int) ($row['has_revisions'] ?? 1),
            (bool) (int) ($row['has_workflow'] ?? 1),
            (bool) (int) ($row['has_permalink'] ?? 1),
            (bool) (int) ($row['has_layout'] ?? 0),
            (bool) (int) ($row['has_taxonomies'] ?? 0),
            (bool) (int) ($row['has_seo'] ?? 1),
            $fields,
            $editorTabs,
            TemplateBinding::fromContentTypeRow($row),
            ((bool) (int) ($row['has_seo'] ?? 1)) ? new SeoPolicy(true) : SeoPolicy::disabled(),
            ((bool) (int) ($row['has_taxonomies'] ?? 0)) ? new TaxonomyPolicy(true) : TaxonomyPolicy::disabled(),
            (string) ($row['default_status'] ?? 'draft'),
            isset($row['singular_label']) ? (string) $row['singular_label'] : null,
            isset($row['plural_label']) ? (string) $row['plural_label'] : null,
        );
    }

    public function acceptsLocalizations(): bool
    {
        return $this->hasLocalizations;
    }

    public function supportsRevisions(): bool
    {
        return $this->hasRevisions;
    }

    public function supportsWorkflow(): bool
    {
        return $this->hasWorkflow;
    }

    public function supportsPermalink(): bool
    {
        return $this->hasPermalink;
    }

    public function allowsSeo(): bool
    {
        return $this->hasSeo && $this->effectiveSeoPolicy()->allowsSeo();
    }

    public function allowsTaxonomies(): bool
    {
        return $this->hasTaxonomies && $this->effectiveTaxonomyPolicy()->allowsTaxonomies();
    }

    public function fieldExists(string $fieldKey): bool
    {
        return $this->field($fieldKey) !== null;
    }

    public function field(string $fieldKey): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->fieldKey === $fieldKey) {
                return $field;
            }
        }
        return null;
    }

    public function isFieldRequired(string $fieldKey): bool
    {
        return (bool) $this->field($fieldKey)?->isRequired();
    }

    public function isFieldTranslatable(string $fieldKey): bool
    {
        return $this->acceptsLocalizations() && (bool) $this->field($fieldKey)?->isTranslatable();
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

    public function allowedTemplate(): ?string
    {
        return $this->effectiveTemplateBinding()->template();
    }

    public function allowsTemplate(?string $template): bool
    {
        return $this->effectiveTemplateBinding()->allowsTemplate($template);
    }

    public function effectiveTemplateBinding(): TemplateBinding
    {
        return $this->templateBinding ?? TemplateBinding::none();
    }

    public function effectiveSeoPolicy(): SeoPolicy
    {
        return $this->seoPolicy ?? ($this->hasSeo ? new SeoPolicy(true) : SeoPolicy::disabled());
    }

    public function effectiveTaxonomyPolicy(): TaxonomyPolicy
    {
        return $this->taxonomyPolicy ?? ($this->hasTaxonomies ? new TaxonomyPolicy(true) : TaxonomyPolicy::disabled());
    }

    public function schema(): ContentTypeSchema
    {
        return new ContentTypeSchema($this, $this->fields, $this->editorTabs, $this->effectiveTemplateBinding(), $this->effectiveSeoPolicy(), $this->effectiveTaxonomyPolicy());
    }

    public function withFields(array $fields): self
    {
        return new self(
            $this->typeKey,
            $this->name,
            $this->hasLocalizations,
            $this->hasRevisions,
            $this->hasWorkflow,
            $this->hasPermalink,
            $this->hasLayout,
            $this->hasTaxonomies,
            $this->hasSeo,
            $fields,
            $this->editorTabs,
            $this->templateBinding,
            $this->seoPolicy,
            $this->taxonomyPolicy,
            $this->defaultStatus,
            $this->singularLabel,
            $this->pluralLabel,
        );
    }

    public function toArray(): array
    {
        return [
            'type_key' => $this->typeKey,
            'name' => $this->name,
            'singular_label' => $this->singularLabel,
            'plural_label' => $this->pluralLabel,
            'has_localizations' => $this->hasLocalizations,
            'has_revisions' => $this->hasRevisions,
            'has_workflow' => $this->hasWorkflow,
            'has_permalink' => $this->hasPermalink,
            'has_layout' => $this->hasLayout,
            'has_taxonomies' => $this->hasTaxonomies,
            'has_seo' => $this->hasSeo,
            'default_status' => $this->defaultStatus,
            'template' => $this->effectiveTemplateBinding()->toArray(),
            'seo_policy' => $this->effectiveSeoPolicy()->toArray(),
            'taxonomy_policy' => $this->effectiveTaxonomyPolicy()->toArray(),
            'fields' => array_map(static fn(FieldDefinition $field): array => $field->toArray(), $this->fields),
            'editor_tabs' => array_map(static fn(EditorTab $tab): array => $tab->toArray(), $this->editorTabs),
        ];
    }
}
