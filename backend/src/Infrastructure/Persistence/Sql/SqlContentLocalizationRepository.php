<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\ContentLocalizationRepository;
use App\Core\Database;

final class SqlContentLocalizationRepository implements ContentLocalizationRepository
{
    public function __construct(private readonly Database $db) {}

    public function listActiveRowsForEntry(int $entryId): array
    {
        return $this->db->all('SELECT * FROM content_entry_localizations WHERE entry_id = :entry_id AND is_active = 1', ['entry_id' => $entryId]);
    }

    public function upsertDraft(int $entryId, string $languageCode, array $payload): int
    {
        $loc = $this->db->one('SELECT id FROM content_entry_localizations WHERE entry_id = :entry_id AND language_code = :language LIMIT 1', ['entry_id' => $entryId, 'language' => $languageCode]);
        $params = [
            'entry_id' => $entryId,
            'language_code' => $languageCode,
            'title' => (string) ($payload['title'] ?? ''),
            'draft_slug' => (string) ($payload['draft_slug'] ?? $payload['slug'] ?? ''),
            'updated_at' => now_utc(),
        ];
        if ($loc) {
            $this->db->run("UPDATE content_entry_localizations SET title = :title, draft_slug = :draft_slug, draft_status = 'draft', updated_at = :updated_at WHERE id = :id", [
                'title' => $params['title'],
                'draft_slug' => $params['draft_slug'],
                'updated_at' => $params['updated_at'],
                'id' => (int) $loc['id'],
            ]);
            return (int) $loc['id'];
        }
        $this->db->run("INSERT INTO content_entry_localizations(entry_id, language_code, title, draft_slug, draft_status, updated_at) VALUES(:entry_id, :language_code, :title, :draft_slug, 'draft', :updated_at)", $params);
        return $this->db->lastInsertId();
    }

}
