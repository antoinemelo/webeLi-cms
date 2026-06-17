<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class EditorTab
{
    /** @param list<string> $fieldKeys */
    public function __construct(
        public readonly string $tabKey,
        public readonly string $label,
        public readonly int $sortOrder = 0,
        public readonly array $fieldKeys = []
    ) {
        if (trim($this->tabKey) === '') {
            throw new \InvalidArgumentException('La clé de l’onglet est obligatoire.');
        }
        if (trim($this->label) === '') {
            throw new \InvalidArgumentException('Le libellé de l’onglet est obligatoire.');
        }
    }

    public static function content(): self
    {
        return new self('content', 'Structure', 10);
    }

    public static function seo(): self
    {
        return new self('seo', 'SEO', 80);
    }

    public static function settings(): self
    {
        return new self('settings', 'Réglages', 100);
    }

    public function containsField(string $fieldKey): bool
    {
        return in_array($fieldKey, $this->fieldKeys, true);
    }

    public function withField(string $fieldKey): self
    {
        if ($this->containsField($fieldKey)) {
            return $this;
        }
        return new self($this->tabKey, $this->label, $this->sortOrder, [...$this->fieldKeys, $fieldKey]);
    }

    public function toArray(): array
    {
        return [
            'tab_key' => $this->tabKey,
            'label' => $this->label,
            'sort_order' => $this->sortOrder,
            'field_keys' => $this->fieldKeys,
        ];
    }
}
