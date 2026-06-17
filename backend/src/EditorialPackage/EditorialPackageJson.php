<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialPackageJson
{
    /** @param array<string,mixed>|list<mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string,mixed> */
    public static function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new \UnexpectedValueException('Objet ou tableau JSON attendu.'); }
        return $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); }
        return $value;
    }
}
