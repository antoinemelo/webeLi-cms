<?php

declare(strict_types=1);

namespace App\Application\Blueprint;

/**
 * Normalise et valide la représentation persistée d'une version de blueprint.
 * Les tableaux associatifs sont triés pour garantir un checksum déterministe ;
 * l'ordre des listes (champs, onglets, options) reste significatif.
 */
final class BlueprintSchemaCanonicalizer
{
    /** @var list<string> */
    private const POLICY_KEYS = [
        'schema_json', 'ui_schema_json', 'validation_json', 'seo_policy_json',
        'routing_policy_json', 'workflow_policy_json', 'translation_policy_json',
        'permissions_policy_json',
    ];

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function normalizeVersionPayload(array $payload): array
    {
        $normalized = [];
        foreach (self::POLICY_KEYS as $key) {
            $value = $payload[$key] ?? [];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException(sprintf('%s doit contenir un objet ou tableau JSON valide.', $key));
                }
                $value = $decoded;
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException(sprintf('%s doit être un tableau JSON.', $key));
            }
            $normalized[$key] = $this->normalizeValue($value);
        }
        $this->validateSchema($normalized['schema_json']);
        return $normalized;
    }

    /** @param array<string,mixed> $schema */
    public function validateSchema(array $schema): void
    {
        $fields = $schema['fields'] ?? [];
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('schema_json.fields doit être une liste.');
        }
        $seen = [];
        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException(sprintf('Le champ #%d doit être un objet.', $index + 1));
            }
            $key = trim((string) ($field['field_key'] ?? $field['key'] ?? $field['field_handle'] ?? $field['handle'] ?? ''));
            if ($key === '') {
                throw new \InvalidArgumentException(sprintf('Le champ #%d ne possède pas de clé.', $index + 1));
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(sprintf('La clé de champ "%s" est dupliquée.', $key));
            }
            $seen[$key] = true;
            $type = trim((string) ($field['field_type'] ?? $field['type'] ?? ''));
            if ($type === '') {
                throw new \InvalidArgumentException(sprintf('Le champ "%s" ne possède pas de type.', $key));
            }
            foreach (['required','localized','system','is_required','is_localized','is_system'] as $booleanKey) {
                if (array_key_exists($booleanKey, $field) && !is_bool($field[$booleanKey]) && !in_array($field[$booleanKey], [0,1], true)) {
                    throw new \InvalidArgumentException(sprintf('La propriété %s du champ "%s" doit être booléenne.', $booleanKey, $key));
                }
            }
            $validation = $field['validation'] ?? [];
            if (!is_array($validation)) {
                throw new \InvalidArgumentException(sprintf('La validation du champ "%s" doit être un objet.', $key));
            }
        }
    }

    /** @param array<string,mixed> $payload */
    public function checksum(array $payload): string
    {
        $normalized = $this->normalizeVersionPayload($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \RuntimeException('Impossible de sérialiser le blueprint canonique.');
        }
        return hash('sha256', $json);
    }

    /** @return mixed */
    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }
        return $value;
    }
}
