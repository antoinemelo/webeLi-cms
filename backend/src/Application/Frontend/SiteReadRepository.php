<?php

declare(strict_types=1);

namespace App\Application\Frontend;

interface SiteReadRepository
{
    /** @return array<string,mixed> */
    public function resolveCurrentSite(string $host = '', string $path = '', ?bool $isHttps = null): array;

    /** @return array<string,mixed>|null */
    public function getLocalization(int $siteId, string $languageCode): ?array;

    /** @return list<array<string,mixed>> */
    public function getLanguages(?int $siteId = null): array;

    public function defaultLanguageCode(): string;

    /** @return list<array<string,mixed>> */
    public function menuItems(int $siteId, string $menuKey, string $languageCode): array;
}
