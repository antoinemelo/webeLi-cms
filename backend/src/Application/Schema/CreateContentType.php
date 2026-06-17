<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\TemplateBinding;

final class CreateContentType
{
    public function __construct(
        private readonly ContentTypeRepository $contentTypes,
        private readonly ValidateContentSchema $validator = new ValidateContentSchema(),
    ) {}

    /** @param array<string,mixed> $input */
    public function execute(array $input): ContentType
    {
        $typeKey = $this->normalizeKey((string) ($input['type_key'] ?? $input['key'] ?? ''));
        if ($typeKey === '') {
            throw new \InvalidArgumentException('La clé du type de contenu est obligatoire.');
        }
        if ($this->contentTypes->exists($typeKey)) {
            throw new \InvalidArgumentException(sprintf('Le type de contenu "%s" existe déjà.', $typeKey));
        }

        $contentType = $this->buildContentType($input + ['type_key' => $typeKey]);
        $this->validator->assertValid($contentType);
        $this->contentTypes->save($contentType);

        return $this->contentTypes->findByKey($typeKey) ?? $contentType;
    }

    /** @param array<string,mixed> $input */
    private function buildContentType(array $input): ContentType
    {
        $fields = array_map(
            static fn(array $field): FieldDefinition => FieldDefinition::fromArray($field),
            array_values(array_filter((array) ($input['fields'] ?? []), 'is_array'))
        );

        $template = $input['template_binding'] ?? $input['template'] ?? [];
        $templateBinding = is_array($template)
            ? new TemplateBinding(
                isset($template['default_template']) ? (string) $template['default_template'] : (isset($input['frontend_template']) ? (string) $input['frontend_template'] : null),
                isset($template['resolver_class']) ? (string) $template['resolver_class'] : (isset($input['frontend_resolver']) ? (string) $input['frontend_resolver'] : null),
                array_values(array_map('strval', (array) ($template['allowed_templates'] ?? [])))
            )
            : new TemplateBinding((string) $template);

        return new ContentType(
            (string) $input['type_key'],
            (string) ($input['name'] ?? $input['plural_label'] ?? $input['type_key']),
            $this->bool($input['has_localizations'] ?? true),
            $this->bool($input['has_revisions'] ?? true),
            $this->bool($input['has_workflow'] ?? true),
            $this->bool($input['has_permalink'] ?? true),
            $this->bool($input['has_layout'] ?? false),
            $this->bool($input['has_taxonomies'] ?? false),
            $this->bool($input['has_seo'] ?? true),
            $fields,
            [],
            $templateBinding,
            null,
            null,
            (string) ($input['default_status'] ?? 'draft'),
            isset($input['singular_label']) ? (string) $input['singular_label'] : null,
            isset($input['plural_label']) ? (string) $input['plural_label'] : null,
        );
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $key) ?? '', '_-'));
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
