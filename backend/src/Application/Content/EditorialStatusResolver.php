<?php

declare(strict_types=1);

namespace App\Application\Content;

/**
 * Centralise le contrat éditorial agrégé de content_entries.
 *
 * `status` est la colonne canonique pour les requêtes publiques/admin. Pour
 * compatibilité avec les anciens écrans et contrats API, `workflow_state` reste
 * présent mais doit toujours refléter exactement la même valeur au niveau de
 * l'entrée agrégée. Les états par langue ou révision restent portés par
 * content_entry_working_revisions.workflow_status et
 * content_entry_publications.workflow_status.
 */
final class EditorialStatusResolver
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const SCHEDULED = 'scheduled';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const ALLOWED = [
        self::DRAFT,
        self::REVIEW,
        self::SCHEDULED,
        self::PUBLISHED,
        self::ARCHIVED,
    ];

    public static function normalize(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::ALLOWED, true)) {
            throw new \InvalidArgumentException(sprintf('Statut éditorial inconnu : %s.', $status));
        }
        return $status;
    }

    /** @return array{status:string,workflow_state:string} */
    public static function pair(string $status): array
    {
        $status = self::normalize($status);
        return ['status' => $status, 'workflow_state' => $status];
    }

    /** @param array<string,mixed> $row */
    public static function fromEntryRow(array $row): string
    {
        $status = self::normalize((string) ($row['status'] ?? self::DRAFT));
        $workflowState = self::normalize((string) ($row['workflow_state'] ?? $status));
        if ($workflowState !== $status) {
            throw new \RuntimeException(sprintf(
                'Divergence éditoriale détectée pour content_entries.id=%s : status=%s, workflow_state=%s.',
                (string) ($row['id'] ?? '?'),
                $status,
                $workflowState,
            ));
        }
        return $status;
    }

    /** @return array{status:string,workflow_state:string} */
    public static function aggregateFromPublication(bool $hasActivePublication): array
    {
        return self::pair($hasActivePublication ? self::PUBLISHED : self::DRAFT);
    }

    /** @return array{status:string,workflow_state:string} */
    public static function preservePublicAggregateOnDraftSave(?array $entryRow): array
    {
        if ($entryRow === null) {
            return self::pair(self::DRAFT);
        }
        $current = self::fromEntryRow($entryRow);
        if ($current === self::PUBLISHED || $current === self::ARCHIVED) {
            return self::pair($current);
        }
        return self::pair(self::DRAFT);
    }
}
