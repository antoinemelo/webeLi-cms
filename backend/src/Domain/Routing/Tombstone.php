<?php

declare(strict_types=1);

namespace App\Domain\Routing;

final class Tombstone
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $oldPath,
        public readonly ?string $languageCode = null,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly ?string $replacementPath = null,
        public readonly ?string $reason = null,
        public readonly ?string $goneAt = null,
        public readonly ?string $expiresAt = null,
        public readonly bool $isActive = true,
    ) {
        if ($this->siteId < 1) {
            throw new \InvalidArgumentException('Un tombstone doit appartenir à un site valide.');
        }
        if (!$this->isValidPath($this->oldPath)) {
            throw new \InvalidArgumentException('Le chemin supprime doit etre absolu, minuscule, sans espace, sans double slash et sans slash final, sauf /.');
        }
        if ($this->replacementPath !== null && !$this->isValidPath($this->replacementPath)) {
            throw new \InvalidArgumentException('Le chemin de remplacement doit etre absolu, minuscule, sans espace, sans double slash et sans slash final, sauf /.');
        }
        if ($this->replacementPath !== null && $this->replacementPath === $this->oldPath) {
            throw new \InvalidArgumentException('Un tombstone ne peut pas se remplacer lui-même.');
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['site_id'] ?? 0),
            (string) ($row['old_path'] ?? ''),
            isset($row['language_code']) ? (string) $row['language_code'] : null,
            isset($row['resource_type']) ? (string) $row['resource_type'] : null,
            isset($row['resource_id']) ? (int) $row['resource_id'] : null,
            isset($row['replacement_path']) ? (string) $row['replacement_path'] : null,
            isset($row['gone_reason']) ? (string) $row['gone_reason'] : null,
            isset($row['gone_at']) ? (string) $row['gone_at'] : null,
            isset($row['expires_at']) ? (string) $row['expires_at'] : null,
            (bool) (int) ($row['is_active'] ?? 1),
        );
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null || trim($this->expiresAt) === '') {
            return false;
        }
        $now ??= new \DateTimeImmutable('now');
        return new \DateTimeImmutable($this->expiresAt) <= $now;
    }

    public function shouldReturnGone(): bool
    {
        return $this->isActive && !$this->isExpired();
    }

    public function hasReplacement(): bool
    {
        return $this->replacementPath !== null && trim($this->replacementPath) !== '';
    }

    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'language_code' => $this->languageCode,
            'old_path' => $this->oldPath,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'replacement_path' => $this->replacementPath,
            'gone_reason' => $this->reason,
            'gone_at' => $this->goneAt,
            'expires_at' => $this->expiresAt,
            'is_active' => $this->isActive,
        ];
    }

    private function isValidPath(string $path): bool
    {
        return $path === '/' || (
            str_starts_with($path, '/')
            && !str_contains($path, '//')
            && !str_contains($path, ' ')
            && rtrim($path, '/') === $path
            && $path === strtolower($path)
        );
    }
}
