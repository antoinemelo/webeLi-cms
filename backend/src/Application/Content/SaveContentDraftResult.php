<?php

declare(strict_types=1);

namespace App\Application\Content;

/**
 * Résultat applicatif du scénario SaveContentDraft.
 *
 * Il évite de faire fuiter un simple identifiant comme contrat implicite :
 * le contrôleur, l'API ou un futur back-office Vue savent si une entrée a été
 * créée, quelle révision de travail a été produite et quelle localisation a été
 * sauvegardée.
 */
final class SaveContentDraftResult
{
    /** @param array<string,mixed> $document */
    public function __construct(
        public readonly int $entryId,
        public readonly int $revisionId,
        public readonly string $contentTypeKey,
        public readonly string $languageCode,
        public readonly bool $created,
        public readonly array $document,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'revision_id' => $this->revisionId,
            'content_type_key' => $this->contentTypeKey,
            'language_code' => $this->languageCode,
            'created' => $this->created,
            'document' => $this->document,
        ];
    }
}
