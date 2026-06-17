<?php

declare(strict_types=1);

namespace App\Application\Api\Admin\Contract;

use App\Core\ApiException;
use App\Core\ErrorCode;
use App\Core\Request;
use App\Core\Response;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\InputValidator;

/**
 * Native admin API v1 contract helpers.
 *
 * This class intentionally contains no Vue/back-office assumption. It is the
 * small compatibility layer between PHP controllers and any future admin UI.
 */
final class AdminApiContract
{
    public const VERSION = 'admin-api-v1';
    public const HEADER_CONTRACT_VERSION = 'X-Contract-Version';
    public const HEADER_CSRF = 'X-CSRF-Token';

    /** @return array<string,mixed> */
    public static function siteContext(Request $request, SiteRepository $sites, ?int $siteId = null, ?AuthRepository $auth = null): array
    {
        if ($siteId !== null && $siteId > 0) {
            $site = $sites->findActiveSite($siteId);
            if (!$site) {
                throw new ApiException(ErrorCode::SITE_NOT_FOUND, ErrorCode::message(ErrorCode::SITE_NOT_FOUND), 404, ['site_id' => $siteId]);
            }
            if ($auth !== null && !$auth->canAccessSite((int) $site['id'])) {
                throw new ApiException(ErrorCode::AUTHZ_FORBIDDEN, ErrorCode::message(ErrorCode::AUTHZ_FORBIDDEN), ErrorCode::httpStatus(ErrorCode::AUTHZ_FORBIDDEN), ['site_id' => (int) $site['id']]);
            }
            return $site;
        }

        $site = $sites->resolveCurrentSite($request->server['HTTP_HOST'] ?? '');
        if ($auth !== null && !$auth->canAccessSite((int) $site['id'])) {
            $allowed = $auth->authorizedSiteIds();
            if ($allowed !== []) {
                $fallback = $sites->findActiveSite($allowed[0]);
                if ($fallback) {
                    return $fallback;
                }
            }
            throw new ApiException(ErrorCode::AUTHZ_FORBIDDEN, ErrorCode::message(ErrorCode::AUTHZ_FORBIDDEN), ErrorCode::httpStatus(ErrorCode::AUTHZ_FORBIDDEN), ['site_id' => (int) $site['id']]);
        }
        return $site;
    }

    public static function language(Request $request, SiteRepository $sites, array $site, array $payload = []): string
    {
        $languageCode = InputValidator::language((string) (
            $payload['content_language_code']
            ?? $payload['language_code']
            ?? $payload['lang']
            ?? $request->query['content_language_code']
            ?? $request->query['language_code']
            ?? $request->query['lang']
            ?? $site['default_language_code']
            ?? $sites->defaultLanguageCode()
        ));

        if (!$sites->isLanguageEnabled((int) $site['id'], $languageCode)) {
            throw new ApiException(ErrorCode::LANGUAGE_NOT_ENABLED, ErrorCode::message(ErrorCode::LANGUAGE_NOT_ENABLED), ErrorCode::httpStatus(ErrorCode::LANGUAGE_NOT_ENABLED), [
                'site_id' => (int) $site['id'],
                'language_code' => $languageCode,
            ]);
        }

        return $languageCode;
    }

    /** @return array<string,mixed> */
    public static function dataPayload(Request $request, bool $requireDataEnvelope = true): array
    {
        $json = $request->json();
        if ($json === []) {
            return $request->post;
        }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        if ($requireDataEnvelope) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, 'Le corps JSON doit contenir un objet data.', 422, [
                'fields' => ['data' => ['data doit être un objet JSON.']],
            ]);
        }

        return $json;
    }

    /** @return array<string,mixed> */
    public static function meta(array $site, string $languageCode, array $extra = []): array
    {
        return [
            'contract_version' => self::VERSION,
            'site_id' => (int) $site['id'],
            'language_code' => $languageCode,
        ] + $extra;
    }

    /** @param list<string> $permissions */
    public static function permissionsBlock(AuthRepository $auth, int $siteId, array $permissions): array
    {
        $granted = [];
        foreach ($permissions as $permission) {
            $granted[$permission] = $auth->hasPermission($permission, $siteId);
        }
        return $granted;
    }

    /** @return array<string,mixed> */
    public static function safeUser(?array $user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $entry */
    public static function assertEntryBelongsToSite(array $entry, array $site): void
    {
        if ((int) ($entry['site_id'] ?? 0) !== (int) ($site['id'] ?? 0)) {
            throw new ApiException(ErrorCode::CONTENT_ENTRY_NOT_FOUND, ErrorCode::message(ErrorCode::CONTENT_ENTRY_NOT_FOUND), 404, [
                'entry_id' => (int) ($entry['id'] ?? 0),
                'site_id' => (int) ($site['id'] ?? 0),
            ]);
        }
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    public static function revisionSummary(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $createdAt = (string) ($row['created_at'] ?? '');
        $updatedAt = (string) ($row['updated_at'] ?? '');
        $publishedAt = $row['published_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'revision_number' => (int) ($row['revision_number'] ?? 0),
            'language_code' => (string) ($row['language_code'] ?? ''),
            'workflow_status' => (string) ($row['workflow_status'] ?? ''),
            'created_by_user_id' => isset($row['created_by_iam_user_id']) ? (int) $row['created_by_iam_user_id'] : (isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null),
            'published_by_user_id' => isset($row['published_by_iam_user_id']) ? (int) $row['published_by_iam_user_id'] : (isset($row['published_by_user_id']) ? (int) $row['published_by_user_id'] : null),
            // System timestamps are stored in UTC in the database. The admin UI
            // receives local wall-clock values so editors do not see the 2-hour
            // summer-time offset between storage and editorial display.
            'created_at' => self::utcToApplicationDateTime($createdAt),
            'updated_at' => self::utcToApplicationDateTime($updatedAt),
            'published_at' => $publishedAt !== null ? self::utcToApplicationDateTime((string) $publishedAt) : null,
            'created_at_utc' => $createdAt,
            'updated_at_utc' => $updatedAt,
            'published_at_utc' => $publishedAt,
            'revision_label' => (string) ($row['revision_label'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'change_notes' => (string) ($row['change_notes'] ?? ''),
        ];
    }

    public static function utcToApplicationDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) !== 1) {
            return $value;
        }
        try {
            $source = new \DateTimeImmutable(str_replace(' ', 'T', $value), new \DateTimeZone('UTC'));
            return $source->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $value;
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function revisionList(array $rows): array
    {
        return array_values(array_filter(array_map(static fn(array $row): ?array => self::revisionSummary($row), $rows)));
    }

    /** @return array<string,mixed> */
    public static function csrfRequirement(): array
    {
        return [
            'header' => self::HEADER_CSRF,
            'required_for' => ['POST', 'PATCH', 'PUT', 'DELETE'],
        ];
    }

    public static function validationResponse(array $fields, string $message = 'Le contenu contient des erreurs.'): Response
    {
        return Response::validation($fields, $message, 422);
    }
}
