<?php

declare(strict_types=1);

namespace App\Module;

/**
 * Déclaration minimale d'un module webeLi.
 *
 * Le manifeste complète la configuration PHP historique sans installer ni
 * activer automatiquement un module. Il sert uniquement à découvrir un provider
 * de manière explicite et lisible par les outils PHP/Python.
 */
final class ModuleManifest
{
    /** @param array<string,mixed> $data */
    private function __construct(
        private readonly array $data,
        private readonly string $path,
    ) {}

    public static function fromFile(string $path): ?self
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return new self($data, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function key(): string
    {
        return $this->stringValue('key');
    }

    public function type(): string
    {
        return $this->stringValue('type');
    }

    public function providerClass(): string
    {
        return $this->stringValue('provider_class');
    }

    public function providerFile(): string
    {
        return $this->stringValue('provider_file');
    }

    /** @return list<string> */
    public function missingRequiredFields(): array
    {
        $missing = [];
        foreach (['key', 'name', 'version', 'type', 'provider_class', 'provider_file'] as $field) {
            if ($this->stringValue($field) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    public function isUsable(): bool
    {
        return $this->missingRequiredFields() === [] && in_array($this->type(), ['system', 'client'], true);
    }

    private function stringValue(string $key): string
    {
        $value = $this->data[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
