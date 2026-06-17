<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Schema\ContentTypeRepository;
use App\Application\Media\SyncMediaUsagesForRevision;
use App\Application\Support\ContentPathBuilder;
use App\Application\Support\TransactionManager;
use App\Application\Taxonomy\AssignTaxonomyTerms;
use App\Application\Iam\UserReferenceValidator;
use App\Domain\Content\ContentEntry;
use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;

/**
 * Scénario central d'écriture éditoriale.
 *
 * Le cas d'usage orchestre le flux métier sans SQL direct :
 * 1. charger le content type ;
 * 2. valider le payload contre son schéma ;
 * 3. normaliser les valeurs ;
 * 4. créer ou retrouver l'entrée ;
 * 5. créer une révision de travail ;
 * 6. sauvegarder la localisation courante ;
 * 7. retourner un résultat applicatif explicite.
 */
final class SaveContentDraft
{
    /** @var list<string> */
    private const RESERVED_PAYLOAD_KEYS = [
        'entry_key',
        'title',
        'slug',
        'blocks',
        'content_blocks',
        'meta_title',
        'meta_description',
        'meta_robots',
        'og_image_media_id',
        'og_image_src',
        'twitter_image_media_id',
        'twitter_image_src',
        'change_notes',
        'taxonomy_terms',
        'taxonomies',
        'status',
        'workflow_state',
        'language',
        'site',
        'site_id',
        'revision_state',
    ];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ContentTypeRepository $contentTypes,
        private readonly ContentEntryRepository $entries,
        private readonly ContentRevisionRepository $revisions,
        private readonly ContentLocalizationRepository $localizations,
        private readonly ContentFieldValueProjectionRepository $fieldValueProjections,
        private readonly ContentPathBuilder $paths,
        private readonly ValidateEntryPayload $payloadValidator,
        private readonly UserReferenceValidator $userReferences,
        private readonly SyncMediaUsagesForRevision $mediaUsages,
        private readonly AssignTaxonomyTerms $assignTaxonomyTerms,
        private readonly ?BlockDocumentNormalizer $blocks = null,
    ) {}

    /** @param array<string,mixed> $payload */
    public function execute(int $siteId, string $typeKey, string $languageCode, array $payload, int $userId, ?int $entryId = null): SaveContentDraftResult
    {
        $this->userReferences->assertCanUpdateDraft($userId);
        $contentType = $this->loadContentType($typeKey);
        $normalized = $this->normalizePayload($siteId, $contentType, $payload, $languageCode);
        $effectiveEntryId = $this->resolveEntryIdByEditorialKey($siteId, $contentType, $normalized, $entryId);
        $this->assertPayloadValid($siteId, $contentType, $languageCode, $payload, $effectiveEntryId);

        return $this->transactions->transaction(function () use ($siteId, $contentType, $languageCode, $normalized, $payload, $userId, $effectiveEntryId): SaveContentDraftResult {
            [$targetEntryId, $created] = $this->createOrLoadEntry($siteId, $contentType, $normalized, $effectiveEntryId, $userId);
            $contentTypeId = $this->contentTypes->findIdByKey($contentType->typeKey);
            if (!$contentTypeId) {
                throw new \RuntimeException(sprintf('Identifiant du type de contenu introuvable : %s.', $contentType->typeKey));
            }
            $this->payloadValidator->reserveNativeConstraints(
                $siteId,
                $contentTypeId,
                $targetEntryId,
                $languageCode,
                (string) ($normalized['content']['slug'] ?? ''),
                $this->payloadValidator->uniqueFieldValuesByLanguage($contentType, $languageCode, $normalized),
            );
            $document = $this->buildRevisionDocument($targetEntryId, $contentType, $languageCode, $normalized);
            $revisionId = $this->revisions->createDraftForEntry(
                $targetEntryId,
                $languageCode,
                $this->revisions->nextNumberForEntry($targetEntryId),
                $document,
                $created ? 'Initial draft' : 'Working draft',
                $this->revisionSummary($normalized),
                trim((string) ($payload['change_notes'] ?? '')),
                $userId,
            );

            $localizationId = $this->localizations->upsertDraft($targetEntryId, $languageCode, $this->localizationPayload($normalized));
            $this->entries->setWorkingRevision($targetEntryId, $languageCode, $revisionId, $userId);
            $this->fieldValueProjections->replaceDraftIndexForRevision(
                $targetEntryId,
                $localizationId,
                $contentTypeId,
                $contentType,
                $languageCode,
                $revisionId,
                $document,
            );
            $this->mediaUsages->execute($siteId, $targetEntryId, $languageCode, $revisionId, $contentType, $document);
            $this->assignTaxonomyTerms->execute($targetEntryId, $normalized['taxonomy_terms'] ?? []);

            return new SaveContentDraftResult(
                $targetEntryId,
                $revisionId,
                $contentType->typeKey,
                $languageCode,
                $created,
                $document,
            );
        });
    }

    /** @param array<string,mixed> $payload */
    public function executeReturningId(int $siteId, string $typeKey, string $languageCode, array $payload, int $userId, ?int $entryId = null): int
    {
        return $this->execute($siteId, $typeKey, $languageCode, $payload, $userId, $entryId)->entryId;
    }

    private function loadContentType(string $typeKey): ContentType
    {
        $contentType = $this->contentTypes->findByKey($typeKey);
        if (!$contentType) {
            throw new \RuntimeException(sprintf('Type de contenu inconnu : %s.', $typeKey));
        }
        if (!$this->contentTypes->findIdByKey($contentType->typeKey)) {
            throw new \RuntimeException(sprintf('Identifiant du type de contenu introuvable : %s.', $contentType->typeKey));
        }
        return $contentType;
    }

    /** @param array<string,mixed> $payload */
    private function assertPayloadValid(int $siteId, ContentType $contentType, string $languageCode, array $payload, ?int $entryId): void
    {
        $errors = $this->payloadValidator->validate($siteId, $contentType, $languageCode, $payload, $entryId);
        // A draft must be permissive: editors often save incomplete image, video, iframe
        // or column blocks while composing. Block strictness is enforced at publication
        // time, so an in-progress draft can always be preserved without weakening
        // the public atomic projection contract.
        if ($errors !== []) {
            throw new ContentValidationException(
                "Payload éditorial invalide :\n- " . implode("\n- ", $errors),
                $this->validationFields($errors, $contentType),
            );
        }
    }


    /**
     * Convertit les messages métier existants en erreurs de champs stables pour
     * l'éditeur Vue. Les messages originaux restent inchangés pour conserver la
     * compatibilité avec les validations historiques et les scripts de test.
     *
     * @param list<string> $errors
     * @return array<string,list<string>>
     */
    private function validationFields(array $errors, ContentType $contentType): array
    {
        $fields = [];
        foreach ($errors as $message) {
            $key = $this->validationFieldKey($message, $contentType);
            $fields[$key] ??= [];
            $fields[$key][] = $message;
        }
        return $fields;
    }

    private function validationFieldKey(string $message, ContentType $contentType): string
    {
        $lower = mb_strtolower($message);
        if (str_contains($lower, 'titre éditorial') || str_starts_with($lower, 'le titre')) { return 'title'; }
        if (str_contains($lower, 'slug')) { return 'slug'; }
        if (str_contains($lower, 'clé éditoriale') || str_contains($lower, 'clé d')) { return 'entry_key'; }
        if (str_contains($lower, 'meta title')) { return 'meta_title'; }
        if (str_contains($lower, 'meta description')) { return 'meta_description'; }
        if (preg_match('/taxonomie "([^"]+)"/u', $message, $matches) === 1) { return 'taxonomy_terms.' . $matches[1]; }
        if (str_contains($lower, 'taxonomy_terms')) { return 'taxonomy_terms'; }
        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition) { continue; }
            $label = mb_strtolower($field->label);
            if ($label !== '' && str_contains($lower, $label)) { return 'fields.' . $field->fieldKey; }
            if (str_contains($lower, $field->fieldKey)) { return 'fields.' . $field->fieldKey; }
        }
        if (str_contains($lower, 'média') || str_contains($lower, 'image')) { return 'media'; }
        if (str_contains($lower, 'bloc')) { return 'blocks'; }
        return 'payload';
    }

    /** @param array<string,mixed> $payload @return array{entry_key:string,content:array<string,mixed>,seo:array<string,mixed>,fields:array<string,mixed>} */
    private function normalizePayload(int $siteId, ContentType $contentType, array $payload, string $languageCode): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $entryKey = $this->normalizeEntryKey($payload['entry_key'] ?? '', $title, $contentType->typeKey);
        $slug = $this->normalizeSlug($payload['slug'] ?? '', $title !== '' ? $title : $entryKey);
        $blockNormalizer = $this->blocks ?? new BlockDocumentNormalizer();
        $blocks = $blockNormalizer->normalize($payload['blocks'] ?? $payload['content_blocks'] ?? []);
        $blockExcerpt = $this->excerptFromBlocks($blockNormalizer, $title, $blocks);
        $metaTitle = trim((string) ($payload['meta_title'] ?? $title));
        $metaDescription = trim((string) ($payload['meta_description'] ?? $blockExcerpt));
        $metaRobots = trim((string) ($payload['meta_robots'] ?? 'index,follow')) ?: 'index,follow';
        $ogImageMediaId = is_numeric($payload['og_image_media_id'] ?? null) ? max(0, (int) $payload['og_image_media_id']) : 0;
        $twitterImageMediaId = is_numeric($payload['twitter_image_media_id'] ?? null) ? max(0, (int) $payload['twitter_image_media_id']) : 0;
        $ogImageSrc = trim((string) ($payload['og_image_src'] ?? ''));
        $twitterImageSrc = trim((string) ($payload['twitter_image_src'] ?? ''));

        $fields = [];
        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition) {
                continue;
            }
            if ($this->payloadHasFieldValue($payload, $field)) {
                $fields[$field->fieldKey] = $this->normalizeFieldValue($field, $this->payloadFieldValue($payload, $field));
            } else {
                $fields[$field->fieldKey] = null;
            }
        }
        $fields = $this->mergeNativeEditorialFields($fields, is_array($payload['fields'] ?? null) ? $payload['fields'] : []);

        return [
            'entry_key' => $entryKey,
            'content' => [
                'title' => $title,
                'slug' => $slug,
                'blocks' => $blocks,
            ],
            'seo' => [
                'meta_title' => $contentType->allowsSeo() ? $metaTitle : '',
                'meta_description' => $contentType->allowsSeo() ? $metaDescription : '',
                'meta_robots' => $contentType->allowsSeo() ? $metaRobots : 'noindex,nofollow',
                'og_image_media_id' => $contentType->allowsSeo() ? $ogImageMediaId : 0,
                'og_image_src' => $contentType->allowsSeo() ? $ogImageSrc : '',
                'twitter_image_media_id' => $contentType->allowsSeo() ? $twitterImageMediaId : 0,
                'twitter_image_src' => $contentType->allowsSeo() ? $twitterImageSrc : '',
            ],
            'fields' => $fields,
            'taxonomy_terms' => $this->payloadValidator->normalizeTaxonomyTerms($siteId, $contentType, $payload),
            'blocks' => $blocks,
            'meta' => [
                'language_code' => $languageCode,
                'accepts_localizations' => $contentType->acceptsLocalizations(),
            ],
        ];
    }

    /** @param array<string,mixed> $fields @param array<string,mixed> $payloadFields @return array<string,mixed> */
    private function mergeNativeEditorialFields(array $fields, array $payloadFields): array
    {
        $whitelist = [
            'article_limit' => 'int',
            'tag_limit' => 'int',
            'article_show_published_date' => 'bool',
            'article_show_author' => 'bool',
            'article_detail_use_custom_display_settings' => 'bool',
            'article_detail_show_published_date' => 'bool',
            'article_detail_show_author' => 'bool',
            'article_detail_show_updated_date' => 'bool',
            'article_detail_show_type' => 'bool',
            'article_detail_include_author_in_schema' => 'bool',
            'article_detail_date_format' => 'date_format',
            'show_published_date' => 'bool',
            'show_author' => 'bool',
            'display_published_at' => 'datetime',
            'published_at' => 'datetime',
            'publication_date' => 'datetime',
            'author_name' => 'string',
            'display_author_name' => 'string',
            'byline' => 'string',
        ];
        foreach ($whitelist as $key => $type) {
            if (!array_key_exists($key, $payloadFields)) {
                continue;
            }
            $fields[$key] = match ($type) {
                'int' => max(0, (int) $payloadFields[$key]),
                'bool' => $this->normalizeBoolean($payloadFields[$key]),
                'datetime' => $this->normalizeOptionalDateTime($payloadFields[$key]),
                'date_format' => $this->normalizeDateFormat($payloadFields[$key]),
                default => trim((string) $payloadFields[$key]),
            };
        }
        return $fields;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'oui', 'on'], true);
    }

    private function normalizeDateFormat(mixed $value): string
    {
        $format = strtolower(trim((string) $value));
        return in_array($format, ['short', 'medium', 'long', 'iso'], true) ? $format : 'medium';
    }

    private function normalizeOptionalDateTime(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $normalized = str_replace('T', ' ', $text);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})(?::(\d{2}))?(?:\.\d+)?(?:\s*)?([zZ]|[+-]\d{2}:?\d{2})?$/', $normalized, $matches) === 1) {
            $timezone = (string) ($matches[4] ?? '');
            if ($timezone !== '') {
                try {
                    $source = str_replace(' ', 'T', $text);
                    $date = new \DateTimeImmutable($source);
                    return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    // Fall through to literal wall-clock storage below.
                }
            }

            return $matches[1] . ' ' . $matches[2] . ':' . ($matches[3] ?? '00');
        }

        $timestamp = strtotime($text);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : $text;
    }

    /** @param array{entry_key:string,content:array<string,mixed>,seo:array<string,mixed>,fields:array<string,mixed>} $normalized @return array{0:int,1:bool} */

    /**
     * Résout une traduction par identité éditoriale stable avant validation.
     *
     * Le slug reste localisé et libre par langue. Il ne doit jamais servir à
     * rattacher une traduction à une page ou à un article existant : ce rôle
     * appartient à content_entries.entry_key, unique par site.
     *
     * @param array<string,mixed> $normalized
     */
    private function resolveEntryIdByEditorialKey(int $siteId, ContentType $contentType, array $normalized, ?int $entryId): ?int
    {
        if ($entryId !== null && $entryId > 0) {
            return $entryId;
        }

        $entryKey = trim((string) ($normalized['entry_key'] ?? ''));
        if ($entryKey === '') {
            return null;
        }

        $existingId = $this->entries->findIdBySiteAndEntryKey($siteId, $entryKey);
        if ($existingId === null) {
            return null;
        }

        $entry = $this->entries->findById($existingId);
        if (!$entry) {
            throw new \RuntimeException(sprintf('Entrée introuvable pour la clé éditoriale "%s".', $entryKey));
        }
        $this->assertEntryCanReceiveDraft($entry, $contentType, $siteId);

        return $existingId;
    }

    private function createOrLoadEntry(int $siteId, ContentType $contentType, array $normalized, ?int $entryId, int $userId): array
    {
        if ($entryId !== null && $entryId > 0) {
            $entry = $this->entries->findById($entryId);
            if (!$entry) {
                throw new \RuntimeException(sprintf('Entrée introuvable : %d.', $entryId));
            }
            $this->assertEntryCanReceiveDraft($entry, $contentType, $siteId);
            return [$entryId, false];
        }

        $contentTypeId = $this->contentTypes->findIdByKey($contentType->typeKey);
        if (!$contentTypeId) {
            throw new \RuntimeException(sprintf('Identifiant du type de contenu introuvable : %s.', $contentType->typeKey));
        }

        return [$this->entries->createDraft($siteId, $contentTypeId, $normalized['entry_key'], $userId), true];
    }

    private function assertEntryCanReceiveDraft(ContentEntry $entry, ContentType $contentType, int $siteId): void
    {
        if (!$entry->belongsToType($contentType)) {
            throw new \RuntimeException(sprintf('L’entrée %d ne correspond pas au type de contenu "%s".', $entry->id ?? 0, $contentType->typeKey));
        }
        if ($entry->siteId !== null && $entry->siteId !== $siteId) {
            throw new \RuntimeException(sprintf('L’entrée %d appartient à un autre site.', $entry->id ?? 0));
        }
        if ($entry->isArchived()) {
            throw new \RuntimeException(sprintf('L’entrée %d est archivée et ne peut plus recevoir de brouillon.', $entry->id ?? 0));
        }
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function buildRevisionDocument(int $entryId, ContentType $contentType, string $languageCode, array $normalized): array
    {
        return [
            'schema_version' => 2,
            'entry_id' => $entryId,
            'content_type' => $contentType->typeKey,
            'language_code' => $languageCode,
            'content' => $normalized['content'],
            'blocks' => $normalized['blocks'] ?? ($normalized['content']['blocks'] ?? []),
            'fields' => $normalized['fields'],
            'taxonomy_terms' => $normalized['taxonomy_terms'] ?? [],
            'seo' => $normalized['seo'],
            'capabilities' => [
                'localizations' => $contentType->acceptsLocalizations(),
                'revisions' => $contentType->supportsRevisions(),
                'workflow' => $contentType->supportsWorkflow(),
                'permalink' => $contentType->supportsPermalink(),
                'seo' => $contentType->allowsSeo(),
                'taxonomies' => $contentType->allowsTaxonomies(),
            ],
            'template' => $contentType->allowedTemplate(),
        ];
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function localizationPayload(array $normalized): array
    {
        return [
            'title' => (string) ($normalized['content']['title'] ?? ''),
            'slug' => (string) ($normalized['content']['slug'] ?? ''),
        ];
    }


    /** @param array<string,mixed> $normalized */
    private function revisionSummary(array $normalized): string
    {
        $seoDescription = trim((string) ($normalized['seo']['meta_description'] ?? ''));
        if ($seoDescription !== '') {
            return mb_strlen($seoDescription) > 220 ? mb_substr($seoDescription, 0, 217) . '…' : $seoDescription;
        }

        $content = is_array($normalized['content'] ?? null) ? $normalized['content'] : [];
        return trim((string) ($content['title'] ?? ''));
    }

    /** @param list<array<string,mixed>> $blocks */
    private function excerptFromBlocks(BlockDocumentNormalizer $normalizer, string $title, array $blocks): string
    {
        $text = $normalizer->plainText(['content' => ['title' => $title], 'blocks' => $blocks]);
        if ($text === '') {
            return '';
        }
        return mb_strlen($text) > 220 ? mb_substr($text, 0, 217) . '…' : $text;
    }

    private function normalizeEntryKey(mixed $rawEntryKey, string $title, string $typeKey): string
    {
        $entryKey = trim((string) $rawEntryKey);
        if ($entryKey === '') {
            $entryKey = $title !== '' ? $title : $typeKey . '-' . time();
        }
        return $this->paths->slugify($entryKey);
    }

    private function normalizeSlug(mixed $rawSlug, string $fallback): string
    {
        $slug = trim((string) $rawSlug);
        if ($slug === '') {
            $slug = $fallback;
        }
        return $this->paths->slugify($slug);
    }

    /** @param array<string,mixed> $payload */
    private function payloadHasFieldValue(array $payload, FieldDefinition $field): bool
    {
        if (array_key_exists($field->fieldKey, $payload)) {
            return true;
        }
        return isset($payload['fields']) && is_array($payload['fields']) && array_key_exists($field->fieldKey, $payload['fields']);
    }

    /** @param array<string,mixed> $payload */
    private function payloadFieldValue(array $payload, FieldDefinition $field): mixed
    {
        if (array_key_exists($field->fieldKey, $payload)) {
            return $payload[$field->fieldKey];
        }
        return is_array($payload['fields'] ?? null) ? ($payload['fields'][$field->fieldKey] ?? null) : null;
    }

    private function normalizeFieldValue(FieldDefinition $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->fieldType) {
            FieldType::NUMBER => is_numeric($value) ? (float) $value : $value,
            FieldType::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            FieldType::MULTISELECT => array_values(array_map('strval', (array) $value)),
            FieldType::MEDIA, FieldType::RELATION => $this->normalizeIdValue($value),
            FieldType::JSON => is_array($value) ? $value : $this->decodeJsonField((string) $value, $field),
            default => is_scalar($value) ? trim((string) $value) : $value,
        };
    }

    private function normalizeIdValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (isset($value['media_id']) || isset($value['id'])) {
                $candidate = $value['media_id'] ?? $value['id'];
                return is_numeric($candidate) && (int) $candidate > 0 ? (int) $candidate : null;
            }
            $ids = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $item = $item['media_id'] ?? $item['id'] ?? null;
                }
                if (is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }
            return array_values(array_unique($ids));
        }
        return is_numeric($value) ? (int) $value : $value;
    }

    /** @return array<string,mixed>|list<mixed> */
    private function decodeJsonField(string $value, FieldDefinition $field): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(sprintf('Le champ JSON "%s" est invalide.', $field->label));
        }
        return $decoded;
    }
}
