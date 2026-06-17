<?php

declare(strict_types=1);

namespace App\Security;

final class InputValidator
{
    public static function email(string $value): string
    {
        $value = trim(mb_substr($value, 0, 254));
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('EMAIL_INVALID');
        }
        return mb_strtolower($value);
    }

    public static function string(string $value, int $max = 255, bool $allowEmpty = true): string
    {
        $value = trim(str_replace("\0", '', $value));
        if (!$allowEmpty && $value === '') {
            throw new \InvalidArgumentException('FIELD_REQUIRED');
        }
        return mb_substr($value, 0, $max);
    }

    public static function text(string $value, int $max = 20000): string
    {
        return mb_substr(str_replace("\0", '', $value), 0, $max);
    }

    public static function key(string $value, int $max = 80): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,' . ($max - 1) . '}$/i', $value)) {
            throw new \InvalidArgumentException('KEY_INVALID');
        }
        return $value;
    }

    public static function slug(string $value, int $max = 160): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9][a-z0-9\-\/]{0,' . ($max - 1) . '}$/i', $value)) {
            throw new \InvalidArgumentException('SLUG_INVALID');
        }
        return trim($value, '/');
    }

    public static function language(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $value)) {
            return 'fr';
        }
        return $value;
    }

    /** @return list<int> */
    public static function idList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function positiveInt(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \InvalidArgumentException('ID_INVALID');
        }
        return (int) $id;
    }
}
