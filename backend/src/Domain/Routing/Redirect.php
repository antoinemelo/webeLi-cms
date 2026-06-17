<?php

declare(strict_types=1);

namespace App\Domain\Routing;

final class Redirect
{
    public const MOVED_PERMANENTLY = 301;
    public const FOUND = 302;
    public const TEMPORARY_REDIRECT = 307;
    public const PERMANENT_REDIRECT = 308;

    /** @var list<int> */
    private const ALLOWED_CODES = [self::MOVED_PERMANENTLY, self::FOUND, self::TEMPORARY_REDIRECT, self::PERMANENT_REDIRECT];

    public function __construct(
        public readonly int $siteId,
        public readonly string $oldPath,
        public readonly string $newPath,
        public readonly ?string $languageCode = null,
        public readonly int $httpCode = self::MOVED_PERMANENTLY,
        public readonly ?string $reason = null,
        public readonly bool $isActive = true,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
    ) {
        if ($this->siteId < 1) {
            throw new \InvalidArgumentException('Une redirection doit appartenir à un site valide.');
        }
        if (!$this->isValidPath($this->oldPath) || !$this->isValidPath($this->newPath)) {
            throw new \InvalidArgumentException('Les chemins de redirection doivent etre absolus, minuscules, sans espace, sans double slash et sans slash final, sauf /.');
        }
        if ($this->oldPath === $this->newPath) {
            throw new \InvalidArgumentException('Une redirection ne peut pas pointer vers elle-même.');
        }
        if (!in_array($this->httpCode, self::ALLOWED_CODES, true)) {
            throw new \InvalidArgumentException(sprintf('Code HTTP de redirection non autorisé : %d.', $this->httpCode));
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['site_id'] ?? 0),
            (string) ($row['old_path'] ?? ''),
            (string) ($row['new_path'] ?? ''),
            isset($row['language_code']) ? (string) $row['language_code'] : null,
            (int) ($row['http_code'] ?? self::MOVED_PERMANENTLY),
            isset($row['redirect_reason']) ? (string) $row['redirect_reason'] : null,
            (bool) (int) ($row['is_active'] ?? 1),
            isset($row['resource_type']) ? (string) $row['resource_type'] : null,
            isset($row['resource_id']) ? (int) $row['resource_id'] : null,
        );
    }

    public function isPermanent(): bool
    {
        return in_array($this->httpCode, [self::MOVED_PERMANENTLY, self::PERMANENT_REDIRECT], true);
    }

    public function matches(string $path, ?string $languageCode = null): bool
    {
        return $this->isActive
            && $this->oldPath === $path
            && ($this->languageCode === null || $languageCode === null || $this->languageCode === $languageCode);
    }

    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'language_code' => $this->languageCode,
            'old_path' => $this->oldPath,
            'new_path' => $this->newPath,
            'http_code' => $this->httpCode,
            'redirect_reason' => $this->reason,
            'is_active' => $this->isActive,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
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
