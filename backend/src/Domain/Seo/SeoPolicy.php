<?php

declare(strict_types=1);

namespace App\Domain\Seo;

final class SeoPolicy
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $requireMetaTitle = false,
        public readonly bool $requireMetaDescription = false,
        public readonly string $robotsDefault = 'index,follow',
        public readonly int $metaTitleMaxLength = 60,
        public readonly int $metaDescriptionMaxLength = 160
    ) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public function allowsSeo(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> */
    public function validate(SeoMetadata $metadata): array
    {
        if (!$this->enabled) {
            return [];
        }
        $errors = [];
        if ($this->requireMetaTitle && !$metadata->hasMetaTitle()) {
            $errors[] = 'Le titre SEO est obligatoire.';
        }
        if ($this->requireMetaDescription && !$metadata->hasMetaDescription()) {
            $errors[] = 'La description SEO est obligatoire.';
        }
        if ($metadata->metaTitle !== null && mb_strlen($metadata->metaTitle) > $this->metaTitleMaxLength) {
            $errors[] = sprintf('Le titre SEO dépasse %d caractères.', $this->metaTitleMaxLength);
        }
        if ($metadata->metaDescription !== null && mb_strlen($metadata->metaDescription) > $this->metaDescriptionMaxLength) {
            $errors[] = sprintf('La description SEO dépasse %d caractères.', $this->metaDescriptionMaxLength);
        }
        return $errors;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'require_meta_title' => $this->requireMetaTitle,
            'require_meta_description' => $this->requireMetaDescription,
            'robots_default' => $this->robotsDefault,
            'meta_title_max_length' => $this->metaTitleMaxLength,
            'meta_description_max_length' => $this->metaDescriptionMaxLength,
        ];
    }
}
