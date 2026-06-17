<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class TemplateBinding
{
    /** @param list<string> $allowedTemplates */
    public function __construct(
        public readonly ?string $defaultTemplate = null,
        public readonly ?string $resolverClass = null,
        public readonly array $allowedTemplates = []
    ) {}

    public static function none(): self
    {
        return new self(null, null, []);
    }

    /** @param array<string,mixed> $row */
    public static function fromContentTypeRow(array $row): self
    {
        return new self(
            isset($row['frontend_template']) && trim((string) $row['frontend_template']) !== '' ? (string) $row['frontend_template'] : null,
            isset($row['frontend_resolver']) && trim((string) $row['frontend_resolver']) !== '' ? (string) $row['frontend_resolver'] : null,
        );
    }

    public function hasTemplate(): bool
    {
        return $this->defaultTemplate !== null && trim($this->defaultTemplate) !== '';
    }

    public function template(): ?string
    {
        return $this->defaultTemplate;
    }

    public function allowsTemplate(?string $template): bool
    {
        if ($template === null || trim($template) === '') {
            return !$this->hasTemplate();
        }
        return $this->allowedTemplates === [] || in_array($template, $this->allowedTemplates, true) || $template === $this->defaultTemplate;
    }

    public function toArray(): array
    {
        return [
            'default_template' => $this->defaultTemplate,
            'resolver_class' => $this->resolverClass,
            'allowed_templates' => $this->allowedTemplates,
        ];
    }
}
