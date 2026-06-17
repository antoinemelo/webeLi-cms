<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Content\Projection\RouteProjector;
use App\Application\Publication\PublishedProjectionStore;
use App\Application\Support\TransactionManager;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\ErrorCode;
use App\Core\RuntimeCachePurger;
use App\Service\OutboxService;

final class ArchiveDeleteContentEntry
{
    private const RESOURCE_TYPE = RouteProjector::RESOURCE_TYPE;

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly Database $db,
        private readonly ContentEntryRepository $entries,
        private readonly PublishedProjectionStore $publishedProjections,
        private readonly OutboxService $outbox,
    ) {}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function archive(int $entryId, int $userId, array $options = []): array
    {
        if ($entryId < 1) { throw new \InvalidArgumentException('Entrée invalide.'); }
        if ($userId < 1) { throw new \InvalidArgumentException('Utilisateur invalide.'); }

        return $this->transactions->transaction(function () use ($entryId, $userId, $options): array {
            $entry = $this->entryOrFail($entryId);
            if ((string) ($entry['status'] ?? '') === 'archived') {
                throw new ApiException(ErrorCode::PUBLICATION_CONFLICT, 'Archivage refusé : le contenu est déjà archivé.', 409, ['entry_id' => $entryId]);
            }

            $siteId = (int) $entry['site_id'];
            $languageCode = $this->optionalLanguage($options['language_code'] ?? null);
            $redirectTo = $this->optionalPath($options['redirect_to'] ?? null, 'redirect_to', (string) ($options['base_path'] ?? ''));
            $httpCode = $this->httpCode($options['http_code'] ?? 301);
            $now = now_utc();
            $routes = $this->routesForEntry($entryId, $languageCode);
            $affectedLanguages = $this->languagesFromRoutesAndPublications($entryId, $languageCode, $routes);

            // Important: active route rows still reserve their public path through SQLite
            // exclusivity triggers. Remove the published route projections before inserting
            // redirects/tombstones for those same old paths; otherwise archiving a published
            // entry fails with an internal server error.
            foreach ($affectedLanguages as $language) {
                $this->markLanguageUnpublished($entryId, $language, $userId, $now);
                $this->publishedProjections->removeCriticalProjection(self::RESOURCE_TYPE, $entryId, $language);
            }

            if ($redirectTo !== null) {
                $this->createRedirects($siteId, $routes, $redirectTo, $httpCode, 'archived');
            } else {
                $this->createTombstones($siteId, $routes, 'archived', true);
            }

            $statusPair = EditorialStatusResolver::pair(EditorialStatusResolver::ARCHIVED);
            $this->db->run("UPDATE content_entries
                SET status = :status, workflow_state = :workflow_state, is_active = 0,
                    published_at = NULL,
                    updated_by_iam_user_id = :user_id, updated_at = :updated_at
                WHERE id = :entry_id", [
                'status' => $statusPair['status'],
                'workflow_state' => $statusPair['workflow_state'],
                'user_id' => $userId,
                'updated_at' => $now,
                'entry_id' => $entryId,
            ]);
            $this->db->run("UPDATE content_entry_localizations
                SET draft_status = 'archived', is_active = 0, updated_by_iam_user_id = :user_id, updated_at = :updated_at
                WHERE entry_id = :entry_id" . ($languageCode !== null ? ' AND language_code = :language_code' : ''),
                array_filter([
                    'user_id' => $userId,
                    'updated_at' => $now,
                    'entry_id' => $entryId,
                    'language_code' => $languageCode,
                ], static fn($value): bool => $value !== null)
            );

            $payload = [
                'entry_id' => $entryId,
                'site_id' => $siteId,
                'archived_by_user_id' => $userId,
                'archived_at' => $now,
                'scope' => $languageCode === null ? 'entry' : 'language',
                'language_code' => $languageCode,
                'affected_languages' => array_values($affectedLanguages),
                'removed_routes' => array_values(array_map(static fn(array $route): string => (string) ($route['full_path'] ?? ''), $routes)),
                'redirect_to' => $redirectTo,
                'http_code' => $redirectTo !== null ? $httpCode : null,
            ];
            $this->outbox->push('content.entry_archived', $payload);
            RuntimeCachePurger::purgeTwigCache();
            return ['status' => 'archived'] + $payload;
        });
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function delete(int $entryId, int $userId, array $options = []): array
    {
        if ($entryId < 1) { throw new \InvalidArgumentException('Entrée invalide.'); }
        if ($userId < 1) { throw new \InvalidArgumentException('Utilisateur invalide.'); }

        return $this->transactions->transaction(function () use ($entryId, $userId, $options): array {
            $entry = $this->entryOrFail($entryId);
            $siteId = (int) $entry['site_id'];
            $redirectTo = $this->optionalPath($options['redirect_to'] ?? null, 'redirect_to', (string) ($options['base_path'] ?? ''));
            $httpCode = $this->httpCode($options['http_code'] ?? 301);
            $now = now_utc();
            // Build the URL handover from every public projection still known for the entry.
            // Relying only on routes is fragile during hard-delete flows because some cleanup
            // paths can remove route rows before redirects are written. Snapshots are included as
            // a fallback so a previously published URL still receives its redirect.
            $routes = $this->publicRoutesForEntry($entryId, null);
            $revisionIds = $this->revisionIdsForEntry($entryId);

            // Remove entry-owned public projections first. The redirect/tombstone written below is
            // deliberately detached from the content entry so the final DELETE can succeed and the
            // old URL can keep resolving after the content row has disappeared.
            $this->removePolymorphicReferences($entryId);

            if ($redirectTo !== null) {
                $this->createDetachedRedirects($siteId, $routes, $redirectTo, $httpCode, 'deleted');
            } else {
                $this->createTombstones($siteId, $routes, 'deleted', false);
            }
            if ($revisionIds !== []) {
                $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
                $this->db->run("DELETE FROM revision_comments WHERE revision_id IN ($placeholders)", $revisionIds);
                $this->db->run("UPDATE revisions SET base_revision_id = NULL, source_published_revision_id = NULL WHERE base_revision_id IN ($placeholders) OR source_published_revision_id IN ($placeholders)", array_merge($revisionIds, $revisionIds));
            }
            $this->db->run('DELETE FROM content_entry_publications WHERE entry_id = :entry_id', ['entry_id' => $entryId]);
            $this->db->run('DELETE FROM content_entry_working_revisions WHERE entry_id = :entry_id', ['entry_id' => $entryId]);
            $this->db->run("DELETE FROM revisions WHERE resource_type = 'content_entry' AND resource_id = :entry_id", ['entry_id' => $entryId]);
            $this->db->run('DELETE FROM content_entries WHERE id = :entry_id', ['entry_id' => $entryId]);

            $payload = [
                'entry_id' => $entryId,
                'site_id' => $siteId,
                'deleted_by_user_id' => $userId,
                'deleted_at' => $now,
                'removed_routes' => array_values(array_map(static fn(array $route): string => (string) ($route['full_path'] ?? ''), $routes)),
                'redirect_to' => $redirectTo,
                'http_code' => $redirectTo !== null ? $httpCode : null,
            ];
            $this->outbox->push('content.entry_deleted', $payload);
            RuntimeCachePurger::purgeTwigCache();
            return ['status' => 'deleted'] + $payload;
        });
    }

    /** @return array<string,mixed> */
    private function entryOrFail(int $entryId): array
    {
        $entry = $this->entries->findProjectionRowById($entryId);
        if (!$entry) {
            throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_ENTRY_NOT_FOUND), 404, ['entry_id' => $entryId]);
        }
        return $entry;
    }

    private function optionalLanguage(mixed $value): ?string
    {
        $language = trim((string) ($value ?? ''));
        return $language === '' ? null : strtolower($language);
    }

    private function optionalPath(mixed $value, string $field, string $basePath = ''): ?string
    {
        $path = $this->normalizeRedirectPath($value, $basePath);
        if ($path === null) { return null; }
        if ($path === '') {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, 'Chemin de redirection invalide.', 422, [$field => (string) ($value ?? '')]);
        }
        return $path;
    }

    private function normalizeRedirectPath(mixed $value, string $basePath = ''): ?string
    {
        $raw = str_replace("\xc2\xa0", ' ', (string) ($value ?? ''));
        $raw = preg_replace('/[[:cntrl:]]+/u', '', $raw) ?? $raw;
        $path = trim($raw);
        if ($path === '') { return null; }

        if (preg_match('~^https?://~i', $path) === 1) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
        }

        $path = rawurldecode($path);
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, '/')) { $path = '/' . $path; }
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        $path = rtrim(strtolower($path), '/') ?: '/';

        $basePath = trim($basePath !== '' ? $basePath : (string) ($_SERVER['CMS_SITE_BASE_PATH'] ?? ''), '/');
        if ($basePath !== '') {
            $prefix = '/' . strtolower($basePath);
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        if (!preg_match('#^/(?:[a-z0-9._~!$&\'()*+,;=:@%\-]+/?)*$#', $path) || str_contains($path, ' ')) {
            return '';
        }
        return $path;
    }

    private function httpCode(mixed $value): int
    {
        $code = (int) $value;
        if (!in_array($code, [301, 302, 307, 308], true)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, 'Code HTTP de redirection invalide.', 422, ['http_code' => $value]);
        }
        return $code;
    }

    /** @return list<array<string,mixed>> */
    private function routesForEntry(int $entryId, ?string $languageCode): array
    {
        $params = ['entry_id' => $entryId];
        $languageSql = '';
        if ($languageCode !== null) {
            $languageSql = ' AND language_code = :language_code';
            $params['language_code'] = $languageCode;
        }
        return $this->db->all("SELECT * FROM routes WHERE resource_type = 'content_entry' AND resource_id = :entry_id{$languageSql} ORDER BY language_code, is_primary DESC, id", $params);
    }

    /** @return list<array<string,mixed>> */
    private function publicRoutesForEntry(int $entryId, ?string $languageCode): array
    {
        $routes = $this->routesForEntry($entryId, $languageCode);
        $seen = [];
        foreach ($routes as $route) {
            $language = (string) ($route['language_code'] ?? '');
            $path = (string) ($route['full_path'] ?? '');
            if ($language !== '' && $path !== '') {
                $seen[$language . '|' . $path] = true;
            }
        }

        $params = ['entry_id' => $entryId];
        $languageSql = '';
        if ($languageCode !== null) {
            $languageSql = ' AND language_code = :language_code';
            $params['language_code'] = $languageCode;
        }
        $snapshots = $this->db->all("SELECT site_id, language_code, resource_type, resource_id, route_path AS full_path
            FROM public_content_snapshots
            WHERE resource_type = 'content_entry' AND resource_id = :entry_id{$languageSql}
            ORDER BY language_code, id", $params);
        foreach ($snapshots as $snapshot) {
            $language = (string) ($snapshot['language_code'] ?? '');
            $path = (string) ($snapshot['full_path'] ?? '');
            $key = $language . '|' . $path;
            if ($language === '' || $path === '' || isset($seen[$key])) { continue; }
            $routes[] = $snapshot;
            $seen[$key] = true;
        }
        return $routes;
    }

    /** @param list<array<string,mixed>> $routes @return list<string> */
    private function languagesFromRoutesAndPublications(int $entryId, ?string $languageCode, array $routes): array
    {
        $languages = [];
        foreach ($routes as $route) {
            $language = (string) ($route['language_code'] ?? '');
            if ($language !== '') { $languages[$language] = true; }
        }
        $params = ['entry_id' => $entryId];
        $languageSql = '';
        if ($languageCode !== null) { $languageSql = ' AND language_code = :language_code'; $params['language_code'] = $languageCode; }
        foreach ($this->db->all("SELECT language_code FROM content_entry_publications WHERE entry_id = :entry_id{$languageSql}", $params) as $row) {
            $language = (string) ($row['language_code'] ?? '');
            if ($language !== '') { $languages[$language] = true; }
        }
        return array_keys($languages);
    }

    private function markLanguageUnpublished(int $entryId, string $languageCode, int $userId, string $now): void
    {
        $this->db->run("UPDATE content_entry_publications
            SET workflow_status = 'unpublished', unpublished_by_iam_user_id = :user_id, unpublished_at = :unpublished_at, updated_at = :updated_at
            WHERE entry_id = :entry_id AND language_code = :language_code AND workflow_status = 'published'", [
            'user_id' => $userId,
            'unpublished_at' => $now,
            'updated_at' => $now,
            'entry_id' => $entryId,
            'language_code' => $languageCode,
        ]);
        $this->db->run("UPDATE content_entry_working_revisions
            SET workflow_status = 'draft', updated_at = :updated_at
            WHERE entry_id = :entry_id AND language_code = :language_code", [
            'updated_at' => $now,
            'entry_id' => $entryId,
            'language_code' => $languageCode,
        ]);
    }

    /** @param list<array<string,mixed>> $routes */
    private function createRedirects(int $siteId, array $routes, string $newPath, int $httpCode, string $reason, bool $attachResource = true): void
    {
        $now = now_utc();
        foreach ($routes as $route) {
            $oldPath = (string) ($route['full_path'] ?? '');
            $language = (string) ($route['language_code'] ?? '');
            if ($oldPath === '' || $oldPath === $newPath || $language === '') { continue; }
            $this->db->run('UPDATE tombstones SET is_active = 0, updated_at = :updated_at WHERE site_id = :site_id AND language_code = :language_code AND old_path = :old_path AND is_active = 1', [
                'updated_at' => $now, 'site_id' => $siteId, 'language_code' => $language, 'old_path' => $oldPath,
            ]);
            $this->db->run('INSERT OR REPLACE INTO redirects(site_id, language_code, old_path, new_path, http_code, redirect_reason, resource_type, resource_id, is_active, created_at, updated_at)
                VALUES(:site_id, :language_code, :old_path, :new_path, :http_code, :reason, :resource_type, :resource_id, 1, :created_at, :updated_at)', [
                'site_id' => $siteId,
                'language_code' => $language,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'http_code' => $httpCode,
                'reason' => $reason,
                'resource_type' => $attachResource ? self::RESOURCE_TYPE : null,
                'resource_id' => $attachResource ? (int) ($route['resource_id'] ?? 0) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param list<array<string,mixed>> $routes */
    private function createDetachedRedirects(int $siteId, array $routes, string $newPath, int $httpCode, string $reason): void
    {
        $now = now_utc();
        foreach ($routes as $route) {
            $oldPath = (string) ($route['full_path'] ?? '');
            $language = (string) ($route['language_code'] ?? '');
            if ($oldPath === '' || $oldPath === $newPath || $language === '') { continue; }

            // A hard delete must leave an SEO redirect, not a 410 tombstone. The redirect is
            // detached from the deleted resource so it survives the content row deletion. It will
            // be deactivated automatically by publication when a future page reclaims the path.
            $this->db->run('UPDATE tombstones SET is_active = 0, updated_at = :updated_at WHERE site_id = :site_id AND language_code = :language_code AND old_path = :old_path AND is_active = 1', [
                'updated_at' => $now, 'site_id' => $siteId, 'language_code' => $language, 'old_path' => $oldPath,
            ]);
            $this->db->run('DELETE FROM redirects WHERE site_id = :site_id AND language_code = :language_code AND old_path = :old_path', [
                'site_id' => $siteId, 'language_code' => $language, 'old_path' => $oldPath,
            ]);
            $this->db->run('INSERT INTO redirects(site_id, language_code, old_path, new_path, http_code, redirect_reason, resource_type, resource_id, is_active, created_at, updated_at)
                VALUES(:site_id, :language_code, :old_path, :new_path, :http_code, :reason, NULL, NULL, 1, :created_at, :updated_at)', [
                'site_id' => $siteId,
                'language_code' => $language,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'http_code' => $httpCode,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param list<array<string,mixed>> $routes */
    private function createTombstones(int $siteId, array $routes, string $reason, bool $attachResource = true): void
    {
        $now = now_utc();
        foreach ($routes as $route) {
            $oldPath = (string) ($route['full_path'] ?? '');
            $language = (string) ($route['language_code'] ?? '');
            if ($oldPath === '' || $language === '') { continue; }
            $this->db->run('UPDATE redirects SET is_active = 0, updated_at = :updated_at WHERE site_id = :site_id AND language_code = :language_code AND old_path = :old_path AND is_active = 1', [
                'updated_at' => $now, 'site_id' => $siteId, 'language_code' => $language, 'old_path' => $oldPath,
            ]);
            $this->db->run('INSERT OR REPLACE INTO tombstones(site_id, language_code, old_path, resource_type, resource_id, replacement_path, gone_reason, gone_at, updated_at, is_active)
                VALUES(:site_id, :language_code, :old_path, :resource_type, :resource_id, NULL, :gone_reason, :gone_at, :updated_at, 1)', [
                'site_id' => $siteId,
                'language_code' => $language,
                'old_path' => $oldPath,
                'resource_type' => $attachResource ? self::RESOURCE_TYPE : null,
                'resource_id' => $attachResource ? (int) ($route['resource_id'] ?? 0) : null,
                'gone_reason' => $reason,
                'gone_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function removePolymorphicReferences(int $entryId): void
    {
        $params = ['entry_id' => $entryId, 'resource_type' => self::RESOURCE_TYPE];
        foreach (['routes', 'seo_metadata', 'search_documents', 'public_content_snapshots', 'seo_audit_issues', 'redirects', 'tombstones', 'media_usages'] as $table) {
            $this->db->run("DELETE FROM {$table} WHERE resource_type = :resource_type AND resource_id = :entry_id", $params);
        }
        $this->db->run("DELETE FROM menu_items WHERE resource_type = 'content_entry' AND resource_id = :entry_id", ['entry_id' => $entryId]);
    }

    /** @return list<int> */
    private function revisionIdsForEntry(int $entryId): array
    {
        return array_map(static fn(array $row): int => (int) $row['id'], $this->db->all("SELECT id FROM revisions WHERE resource_type = 'content_entry' AND resource_id = :entry_id", ['entry_id' => $entryId]));
    }
}
