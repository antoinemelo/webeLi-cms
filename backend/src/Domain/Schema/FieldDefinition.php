<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class FieldDefinition
{
    public readonly string $fieldKey;
    public readonly string $label;
    public readonly string $fieldType;
    public readonly bool $isRequired;
    public readonly bool $isLocalized;
    public readonly bool $isSearchable;
    public readonly array $options;
    public readonly array $validation;
    public readonly ?string $groupKey;
    public readonly ?string $tabKey;
    public readonly int $sortOrder;
    public readonly bool $isUnique;
    public readonly bool $isHidden;

    public function __construct(
        string $fieldKey,
        string $label,
        string|FieldType $fieldType,
        bool $isRequired = false,
        bool $isLocalized = true,
        bool $isSearchable = false,
        array $options = [],
        array $validation = [],
        ?string $groupKey = null,
        ?string $tabKey = 'content',
        int $sortOrder = 0,
        bool $isUnique = false,
        bool $isHidden = false
    ) {
        $fieldKey = self::normalizeKey($fieldKey);
        if ($fieldKey === '') {
            throw new \InvalidArgumentException('La clé du champ est obligatoire.');
        }
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Le libellé du champ est obligatoire.');
        }
        $type = $fieldType instanceof FieldType ? $fieldType : FieldType::fromString($fieldType);

        $this->fieldKey = $fieldKey;
        $this->label = $label;
        $this->fieldType = (string) $type;
        $this->isRequired = $isRequired;
        $this->isLocalized = $isLocalized;
        $this->isSearchable = $isSearchable;
        $this->options = $options;
        $this->validation = $validation;
        $this->groupKey = $groupKey ? self::normalizeKey($groupKey) : null;
        $this->tabKey = $tabKey ? self::normalizeKey($tabKey) : 'content';
        $this->sortOrder = $sortOrder;
        $this->isUnique = $isUnique;
        $this->isHidden = $isHidden;
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['field_key'] ?? $row['key'] ?? ''),
            (string) ($row['label'] ?? $row['name'] ?? ''),
            (string) ($row['field_type'] ?? $row['type'] ?? FieldType::TEXT),
            (bool) (int) ($row['is_required'] ?? $row['required'] ?? 0),
            (bool) (int) ($row['is_localized'] ?? $row['localized'] ?? 1),
            (bool) (int) ($row['is_searchable'] ?? $row['searchable'] ?? 0),
            self::decodeJsonArray($row['options_json'] ?? $row['options'] ?? []),
            self::decodeJsonArray($row['validation_json'] ?? $row['validation'] ?? []),
            isset($row['group_key']) ? (string) $row['group_key'] : null,
            isset($row['tab_key']) ? (string) $row['tab_key'] : 'content',
            (int) ($row['sort_order'] ?? 0),
            (bool) (int) ($row['is_unique'] ?? $row['unique'] ?? 0),
            (bool) (int) ($row['is_hidden'] ?? $row['hidden'] ?? 0),
        );
    }

    public function key(): string
    {
        return $this->fieldKey;
    }

    public function type(): FieldType
    {
        return FieldType::fromString($this->fieldType);
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isTranslatable(): bool
    {
        return $this->isLocalized;
    }

    public function acceptsEmptyValue(): bool
    {
        return !$this->isRequired;
    }

    public function hasValidationRule(string $rule): bool
    {
        return array_key_exists($rule, $this->validation);
    }

    public function validationValue(string $rule, mixed $default = null): mixed
    {
        return $this->validation[$rule] ?? $default;
    }

    /** @return list<string> */
    public function validateValue(mixed $value): array
    {
        $errors = [];
        if ($this->isRequired && self::isEmpty($value)) {
            $errors[] = sprintf('Le champ "%s" est obligatoire.', $this->label);
            return $errors;
        }
        if (self::isEmpty($value)) {
            return $errors;
        }

        if ($this->type()->isTextual()) {
            $text = (string) $value;
            $min = $this->validationValue('min', $this->validationValue('min_length'));
            $max = $this->validationValue('max', $this->validationValue('max_length'));
            if ($min !== null && mb_strlen($text) < (int) $min) {
                $errors[] = sprintf('Le champ "%s" doit contenir au moins %d caractères.', $this->label, (int) $min);
            }
            if ($max !== null && mb_strlen($text) > (int) $max) {
                $errors[] = sprintf('Le champ "%s" doit contenir au maximum %d caractères.', $this->label, (int) $max);
            }
        }

        $format = strtolower((string) $this->validationValue('format', ''));
        if (($format === 'email' || $this->validationValue('email') === true) && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = sprintf('Le champ "%s" doit être une adresse e-mail valide.', $this->label);
        }
        if (($format === 'url' || $this->validationValue('url') === true) && !filter_var((string) $value, FILTER_VALIDATE_URL)) {
            $errors[] = sprintf('Le champ "%s" doit être une URL valide.', $this->label);
        }
        if ($this->fieldType === FieldType::SLUG || $format === 'slug') {
            if (!preg_match('/^[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9])?$/', (string) $value)) {
                $errors[] = sprintf('Le champ "%s" doit être un slug valide.', $this->label);
            }
        }
        $regex = $this->validationValue('regex', $this->validationValue('pattern'));
        if (is_string($regex) && $regex !== '') {
            if (@preg_match($regex, '') === false) {
                $errors[] = sprintf('La règle regex du champ "%s" est invalide.', $this->label);
            } elseif (!preg_match($regex, (string) $value)) {
                $errors[] = sprintf('Le champ "%s" ne respecte pas le format attendu.', $this->label);
            }
        }

        if (in_array($this->fieldType, [FieldType::NUMBER, FieldType::INTEGER, FieldType::RANGE], true) && !is_numeric($value)) {
            $errors[] = sprintf('Le champ "%s" doit être numérique.', $this->label);
        }
        if ((in_array($this->fieldType, [FieldType::INTEGER], true) || $format === 'integer' || $this->validationValue('integer') === true) && filter_var($value, FILTER_VALIDATE_INT) === false) {
            $errors[] = sprintf('Le champ "%s" doit être un entier.', $this->label);
        }
        if ($this->fieldType === FieldType::DATE && !self::matchesDate((string) $value, 'Y-m-d')) {
            $errors[] = sprintf('Le champ "%s" doit être une date valide au format YYYY-MM-DD.', $this->label);
        }
        if ($this->fieldType === FieldType::TIME && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $value)) {
            $errors[] = sprintf('Le champ "%s" doit être une heure valide au format HH:MM.', $this->label);
        }
        if ($this->fieldType === FieldType::DATETIME && !self::matchesDateTime((string) $value)) {
            $errors[] = sprintf('Le champ "%s" doit être une date-heure valide.', $this->label);
        }
        if (in_array($this->fieldType, [FieldType::MEDIA, FieldType::ASSETS, FieldType::RELATION, FieldType::ENTRIES], true)) {
            foreach (self::relationCandidates($value) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $errors[] = sprintf('Le champ "%s" doit contenir des identifiants valides.', $this->label);
                    break;
                }
            }
        }
        if (in_array($this->fieldType, [FieldType::JSON, FieldType::TABLE, FieldType::LIST, FieldType::REPLICATOR, FieldType::VIDEO], true)) {
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);
            if (!is_array($decoded)) {
                $errors[] = sprintf('Le champ JSON "%s" est invalide.', $this->label);
            } else {
                array_push($errors, ...self::validateJsonSchema($decoded, (array) $this->validationValue('json_schema', []), $this->label));
            }
        }

        if (in_array($this->fieldType, [FieldType::SELECT, FieldType::MULTISELECT, FieldType::RADIO, FieldType::CHECKBOXES, FieldType::BUTTON_GROUP], true) && isset($this->options['choices'])) {
            $allowed = array_map('strval', array_keys((array) $this->options['choices']));
            $values = in_array($this->fieldType, [FieldType::MULTISELECT, FieldType::CHECKBOXES], true) ? (array) $value : [(string) $value];
            foreach ($values as $candidate) {
                if (!in_array((string) $candidate, $allowed, true)) {
                    $errors[] = sprintf('La valeur "%s" n’est pas autorisée pour "%s".', (string) $candidate, $this->label);
                }
            }
        }

        if (is_array($value)) {
            $minItems = $this->validationValue('min_items');
            $maxItems = $this->validationValue('max_items');
            if ($minItems !== null && count($value) < (int) $minItems) {
                $errors[] = sprintf('Le champ "%s" doit contenir au moins %d élément(s).', $this->label, (int) $minItems);
            }
            if ($maxItems !== null && count($value) > (int) $maxItems) {
                $errors[] = sprintf('Le champ "%s" doit contenir au maximum %d élément(s).', $this->label, (int) $maxItems);
            }
        }

        return $errors;
    }
    public function toArray(): array
    {
        return [
            'field_key' => $this->fieldKey,
            'label' => $this->label,
            'field_type' => $this->fieldType,
            'is_required' => $this->isRequired,
            'is_localized' => $this->isLocalized,
            'is_searchable' => $this->isSearchable,
            'options' => $this->options,
            'validation' => $this->validation,
            'group_key' => $this->groupKey,
            'tab_key' => $this->tabKey,
            'sort_order' => $this->sortOrder,
            'is_unique' => $this->isUnique,
            'is_hidden' => $this->isHidden,
        ];
    }


    /** @return list<mixed> */
    private static function relationCandidates(mixed $value): array
    {
        if (is_array($value)) {
            if (isset($value['media_id']) || isset($value['id'])) {
                return [$value['media_id'] ?? $value['id']];
            }
            $candidates = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $candidates[] = $item['media_id'] ?? $item['id'] ?? null;
                    continue;
                }
                $candidates[] = $item;
            }
            return $candidates;
        }
        return [$value];
    }

    private static function matchesDate(string $value, string $format): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $dt instanceof \DateTimeImmutable && $dt->format($format) === $value;
    }

    private static function matchesDateTime(string $value): bool
    {
        return strtotime($value) !== false;
    }

    /** @return list<string> */
    private static function validateJsonSchema(array $value, array $schema, string $label): array
    {
        if ($schema === []) {
            return [];
        }
        $errors = [];
        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (is_string($key) && !array_key_exists($key, $value)) {
                $errors[] = sprintf('Le champ JSON "%s" doit contenir la clé "%s".', $label, $key);
            }
        }
        foreach ((array) ($schema['properties'] ?? []) as $key => $rules) {
            if (!is_array($rules) || !array_key_exists((string) $key, $value)) {
                continue;
            }
            $type = (string) ($rules['type'] ?? '');
            $candidate = $value[(string) $key];
            $valid = match ($type) {
                'string' => is_string($candidate),
                'integer' => is_int($candidate),
                'number' => is_int($candidate) || is_float($candidate),
                'boolean' => is_bool($candidate),
                'array' => is_array($candidate) && array_is_list($candidate),
                'object' => is_array($candidate) && !array_is_list($candidate),
                default => true,
            };
            if (!$valid) {
                $errors[] = sprintf('La clé JSON "%s.%s" doit être de type %s.', $label, (string) $key, $type);
            }
        }
        return $errors;
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $key) ?? '', '_-'));
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
