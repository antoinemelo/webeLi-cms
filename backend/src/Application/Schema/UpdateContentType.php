<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\TemplateBinding;

final class UpdateContentType
{
    public function __construct(
        private readonly ContentTypeRepository $contentTypes,
        private readonly ValidateContentSchema $validator = new ValidateContentSchema(),
    ) {}

    /** @param array<string,mixed> $input */
    public function execute(string $typeKey, array $input): ContentType
    {
        $current = $this->contentTypes->findByKey($typeKey);
        if (!$current) {
            throw new \RuntimeException(sprintf('Type de contenu introuvable : %s.', $typeKey));
        }

        $contentType = $this->merge($current, $input);
        $this->validator->assertValid($contentType);
        $this->contentTypes->save($contentType);

        return $this->contentTypes->findByKey($contentType->typeKey) ?? $contentType;
    }

    /** @param array<string,mixed> $input */
    private function merge(ContentType $current, array $input): ContentType
    {
        $fields = $current->fields;
        if (array_key_exists('fields', $input)) {
            $fields = array_map(
                static fn(array $field): FieldDefinition => FieldDefinition::fromArray($field),
                array_values(array_filter((array) $input['fields'], 'is_array'))
            );
        }

        $templateBinding = $current->effectiveTemplateBinding();
        if (isset($input['template_binding']) && is_array($input['template_binding'])) {
            $template = $input['template_binding'];
            $templateBinding = new TemplateBinding(
                array_key_exists('default_template', $template) ? ($template['default_template'] !== null ? (string) $template['default_template'] : null) : $templateBinding->defaultTemplate,
                array_key_exists('resolver_class', $template) ? ($template['resolver_class'] !== null ? (string) $template['resolver_class'] : null) : $templateBinding->resolverClass,
                array_values(array_map('strval', (array) ($template['allowed_templates'] ?? $templateBinding->allowedTemplates)))
            );
        }

        return new ContentType(
            $current->typeKey,
            (string) ($input['name'] ?? $current->name),
            $this->bool($input['has_localizations'] ?? $current->hasLocalizations),
            $this->bool($input['has_revisions'] ?? $current->hasRevisions),
            $this->bool($input['has_workflow'] ?? $current->hasWorkflow),
            $this->bool($input['has_permalink'] ?? $current->hasPermalink),
            $this->bool($input['has_layout'] ?? $current->hasLayout),
            $this->bool($input['has_taxonomies'] ?? $current->hasTaxonomies),
            $this->bool($input['has_seo'] ?? $current->hasSeo),
            $fields,
            $current->editorTabs,
            $templateBinding,
            $current->seoPolicy,
            $current->taxonomyPolicy,
            (string) ($input['default_status'] ?? $current->defaultStatus),
            array_key_exists('singular_label', $input) ? (string) $input['singular_label'] : $current->singularLabel,
            array_key_exists('plural_label', $input) ? (string) $input['plural_label'] : $current->pluralLabel,
        );
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
