<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialEntry
{
    /** @param list<array<string,mixed>> $localizations @param array<string,mixed> $fields @param list<array<string,mixed>> $blocks @param array<string,mixed> $seo @param list<array<string,mixed>> $relations @param list<string> $mediaKeys @param array<string,string|null> $dates */
    public function __construct(
        public readonly string $entryKey,
        public readonly string $contentTypeKey,
        public readonly string $blueprintKey,
        public readonly string $sourceSiteKey,
        public readonly array $localizations,
        public readonly array $fields,
        public readonly array $blocks,
        public readonly array $seo,
        public readonly array $relations = [],
        public readonly array $mediaKeys = [],
        public readonly array $dates = [],
    ) {
        foreach ([$entryKey, $contentTypeKey, $blueprintKey, $sourceSiteKey] as $key) { new PortableKey($key); }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'entry_key' => $this->entryKey,
            'content_type_key' => $this->contentTypeKey,
            'blueprint_key' => $this->blueprintKey,
            'status' => 'published',
            'source_site_key' => $this->sourceSiteKey,
            'languages' => array_values(array_unique(array_map(static fn(array $l): string => (string) ($l['language'] ?? ''), $this->localizations))),
            'localizations' => array_values($this->localizations),
            'fields' => $this->fields,
            'blocks' => array_values($this->blocks),
            'seo' => $this->seo,
            'relations' => array_values($this->relations),
            'media_keys' => array_values(array_unique($this->mediaKeys)),
            'dates' => $this->dates,
        ];
    }
}
