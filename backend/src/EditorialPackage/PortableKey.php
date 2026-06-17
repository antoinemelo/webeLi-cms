<?php

declare(strict_types=1);

namespace App\EditorialPackage;

/** Clé fonctionnelle portable, indépendante des identifiants SQLite locaux. */
final class PortableKey
{
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9._:-]{0,126}[a-z0-9])?$/';

    public function __construct(public readonly string $value)
    {
        if (!preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(sprintf('Clé portable invalide "%s".', $value));
        }
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function __toString(): string { return $this->value; }
}
