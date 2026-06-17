<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class FieldType
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const RICHTEXT = 'richtext';
    public const MARKDOWN = 'markdown';
    public const BARD = 'bard';
    public const NUMBER = 'number';
    public const INTEGER = 'integer';
    public const BOOLEAN = 'boolean';
    public const TOGGLE = 'toggle';
    public const DATE = 'date';
    public const TIME = 'time';
    public const DATETIME = 'datetime';
    public const JSON = 'json';
    public const YAML = 'yaml';
    public const CODE = 'code';
    public const MEDIA = 'media';
    public const ASSETS = 'assets';
    public const RELATION = 'relation';
    public const ENTRIES = 'entries';
    public const TAXONOMY = 'taxonomy';
    public const SELECT = 'select';
    public const RADIO = 'radio';
    public const MULTISELECT = 'multiselect';
    public const CHECKBOXES = 'checkboxes';
    public const SLUG = 'slug';
    public const LINK = 'link';
    public const LIST = 'list';
    public const REPLICATOR = 'replicator';
    public const TABLE = 'table';
    public const COLOR = 'color';
    public const VIDEO = 'video';
    public const BUTTON_GROUP = 'button_group';
    public const RANGE = 'range';
    public const REVEALER = 'revealer';
    public const SITES = 'sites';
    public const STRUCTURES = 'structures';
    public const TEMPLATE = 'template';
    public const USERS = 'users';

    /** @var list<string> */
    private const ALLOWED = [
        self::TEXT, self::TEXTAREA, self::RICHTEXT, self::MARKDOWN, self::BARD,
        self::NUMBER, self::INTEGER, self::BOOLEAN, self::TOGGLE,
        self::DATE, self::TIME, self::DATETIME,
        self::JSON, self::YAML, self::CODE,
        self::MEDIA, self::ASSETS, self::RELATION, self::ENTRIES, self::TAXONOMY,
        self::SELECT, self::RADIO, self::MULTISELECT, self::CHECKBOXES,
        self::SLUG, self::LINK, self::LIST, self::REPLICATOR, self::TABLE,
        self::COLOR, self::VIDEO, self::BUTTON_GROUP, self::RANGE, self::REVEALER,
        self::SITES, self::STRUCTURES, self::TEMPLATE, self::USERS,
    ];

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim($value));
        $aliases = [
            'asset' => self::ASSETS,
            'bool' => self::BOOLEAN,
            'checkbox' => self::TOGGLE,
            'int' => self::INTEGER,
            'textarea' => self::TEXTAREA,
            'text_long' => self::TEXTAREA,
            'rich_text' => self::RICHTEXT,
        ];
        $value = $aliases[$value] ?? $value;
        if (!self::isAllowed($value)) {
            throw new \InvalidArgumentException(sprintf('Type de champ inconnu : %s.', $value));
        }
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /** @return list<string> */
    public static function allowedValues(): array
    {
        return self::ALLOWED;
    }

    public static function isAllowed(string $value): bool
    {
        $value = strtolower(trim($value));
        $aliases = ['asset' => self::ASSETS, 'bool' => self::BOOLEAN, 'checkbox' => self::TOGGLE, 'int' => self::INTEGER, 'text_long' => self::TEXTAREA, 'rich_text' => self::RICHTEXT];
        return in_array($aliases[$value] ?? $value, self::ALLOWED, true);
    }

    public function isTextual(): bool
    {
        return in_array($this->value, [self::TEXT, self::TEXTAREA, self::RICHTEXT, self::MARKDOWN, self::BARD, self::SLUG, self::LINK, self::CODE, self::YAML], true);
    }

    public function isScalar(): bool
    {
        return in_array($this->value, [self::TEXT, self::TEXTAREA, self::RICHTEXT, self::MARKDOWN, self::BARD, self::NUMBER, self::INTEGER, self::BOOLEAN, self::TOGGLE, self::DATE, self::TIME, self::DATETIME, self::SLUG, self::LINK, self::COLOR, self::CODE, self::YAML], true);
    }

    public function supportsOptions(): bool
    {
        return in_array($this->value, [self::SELECT, self::MULTISELECT, self::RADIO, self::CHECKBOXES, self::RELATION, self::MEDIA, self::ASSETS, self::ENTRIES, self::TAXONOMY, self::BUTTON_GROUP, self::RANGE], true);
    }

    public function storesJson(): bool
    {
        return in_array($this->value, [self::JSON, self::MULTISELECT, self::CHECKBOXES, self::ASSETS, self::ENTRIES, self::TAXONOMY, self::LIST, self::REPLICATOR, self::TABLE, self::VIDEO, self::USERS, self::SITES, self::STRUCTURES], true);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
