<?php

declare(strict_types=1);

namespace App\Application\Blueprint;

/** Frontière applicative canonique des schémas éditoriaux versionnés. */
final class CanonicalBlueprintService
{
    public function __construct(
        private readonly BlueprintRepository $repository,
        private readonly BlueprintSchemaCanonicalizer $canonicalizer,
    ) {}

    public function blueprint(string $key, ?string $resourceType = null, ?int $siteId = null): array
    {
        return $this->repository->findByKey($key, $resourceType, $siteId)
            ?? throw new \RuntimeException(sprintf('Blueprint introuvable : %s.', $key));
    }

    public function activeVersion(string $key, ?string $resourceType = null, ?int $siteId = null): array
    {
        return $this->repository->activeVersion($key, $resourceType, $siteId)
            ?? throw new \RuntimeException(sprintf('Aucune version active pour le blueprint : %s.', $key));
    }

    public function version(int $versionId): array
    {
        return $this->repository->findVersionById($versionId)
            ?? throw new \RuntimeException(sprintf('Version de blueprint introuvable : %d.', $versionId));
    }

    public function versionForRevision(int $revisionId): array
    {
        return $this->repository->versionForRevision($revisionId)
            ?? throw new \RuntimeException(sprintf('La révision %d n’est reliée à aucune version de blueprint.', $revisionId));
    }

    /** @param array<string,mixed> $payload */
    public function validateVersionPayload(array $payload): void
    {
        $this->canonicalizer->normalizeVersionPayload($payload);
    }

    /** @param array<string,mixed> $payload */
    public function checksum(array $payload): string
    {
        return $this->canonicalizer->checksum($payload);
    }
}
