<?php

declare(strict_types=1);

namespace App\Application\Blueprint;

interface BlueprintRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $resourceType = null, ?int $siteId = null): array;

    /** @return array<string,mixed>|null */
    public function findByKey(string $key, ?string $resourceType = null, ?int $siteId = null): ?array;

    /** @return list<array<string,mixed>> */
    public function versions(string $key, ?string $resourceType = null, ?int $siteId = null): array;

    /** @return array<string,mixed>|null */
    public function activeVersion(string $key, ?string $resourceType = null, ?int $siteId = null): ?array;

    /** @return array<string,mixed>|null */
    public function findVersionById(int $versionId): ?array;

    /** @return array<string,mixed>|null */
    public function versionForRevision(int $revisionId): ?array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createBlueprint(array $payload): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createVersion(string $key, array $payload, ?int $siteId = null): array;

    /** @return array<string,mixed> */
    public function activate(string $key, int $version, ?int $siteId = null): array;

    /** @return array<string,mixed> */
    public function modelOverview(?int $siteId = null): array;

    /** @return array<string,mixed> */
    public function audit(?string $key = null, ?string $resourceType = null, ?int $siteId = null): array;

    /** @return list<array<string,mixed>> */
    public function presets(): array;

    /** @return list<array<string,mixed>> */
    public function fieldTypes(): array;

    /** @return array<string,mixed> */
    public function design(string $key, ?string $resourceType = null, ?int $siteId = null): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveDesign(string $key, array $payload, ?int $siteId = null): array;

    /** @return array<string,mixed> */
    public function deleteBlueprint(string $key, ?string $resourceType = null, ?int $siteId = null): array;

    /** @return list<array<string,mixed>> */
    public function fieldsets(): array;

    /** @return array<string,mixed> */
    public function fieldset(string $key): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveFieldset(?string $key, array $payload): array;

    /** @return array<string,mixed> */
    public function deleteFieldset(string $key): array;
}
