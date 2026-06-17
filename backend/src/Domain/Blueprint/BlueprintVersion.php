<?php

declare(strict_types=1);

namespace App\Domain\Blueprint;

final class BlueprintVersion
{
    /** @param array<string,mixed> $policies */
    public function __construct(
        public readonly ?int $id,
        public readonly int $blueprintId,
        public readonly int $version,
        public readonly ?string $versionLabel,
        public readonly string $status,
        public readonly array $schema,
        public readonly array $uiSchema,
        public readonly array $validation,
        public readonly array $seoPolicy,
        public readonly array $routingPolicy,
        public readonly array $workflowPolicy,
        public readonly array $translationPolicy,
        public readonly array $permissionsPolicy,
        public readonly bool $isActive,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) $row['blueprint_id'],
            (int) $row['version'],
            $row['version_label'] === null ? null : (string) $row['version_label'],
            (string) $row['status'],
            self::decode((string) $row['schema_json']),
            self::decode((string) $row['ui_schema_json']),
            self::decode((string) $row['validation_json']),
            self::decode((string) $row['seo_policy_json']),
            self::decode((string) $row['routing_policy_json']),
            self::decode((string) $row['workflow_policy_json']),
            self::decode((string) $row['translation_policy_json']),
            self::decode((string) $row['permissions_policy_json']),
            (bool) ((int) ($row['is_active'] ?? 0)),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'blueprint_id' => $this->blueprintId,
            'version' => $this->version,
            'version_label' => $this->versionLabel,
            'status' => $this->status,
            'is_active' => $this->isActive,
            'schema' => $this->schema,
            'ui_schema' => $this->uiSchema,
            'validation' => $this->validation,
            'seo_policy' => $this->seoPolicy,
            'routing_policy' => $this->routingPolicy,
            'workflow_policy' => $this->workflowPolicy,
            'translation_policy' => $this->translationPolicy,
            'permissions_policy' => $this->permissionsPolicy,
        ];
    }

    /** @return array<string,mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
