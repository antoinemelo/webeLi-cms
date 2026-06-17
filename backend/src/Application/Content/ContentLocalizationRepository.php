<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Content\ContentLocalization;

interface ContentLocalizationRepository
{
    /** @return list<array<string,mixed>> */
    public function listActiveRowsForEntry(int $entryId): array;

    /** @param array<string,mixed> $payload */
    public function upsertDraft(int $entryId, string $languageCode, array $payload): int;

}
