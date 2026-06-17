<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Schema\ContentTypeRepository;
use App\Application\Support\ContentPathBuilder;
use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;

final class ValidateEntryPayload
{
    private const NATIVE_EDITORIAL_FIELD_KEYS = [
        'article_limit',
        'tag_limit',
        'article_show_published_date',
        'article_show_author',
        'article_detail_use_custom_display_settings',
        'article_detail_show_published_date',
        'article_detail_show_author',
        'article_detail_show_updated_date',
        'article_detail_show_type',
        'article_detail_include_author_in_schema',
        'article_detail_date_format',
        'show_published_date',
        'show_author',
        'display_published_at',
        'published_at',
        'publication_date',
        'author_name',
        'display_author_name',
        'byline',
    ];

    /**
     * Champs strictement système : ils sont fournis par le contexte, le workflow
     * ou les projections, jamais par le payload éditorial libre. Les accepter
     * silencieusement créerait une illusion de sauvegarde et donc une perte
     * silencieuse côté éditeur.
     *
     * Les champs système éditables title/slug/entry_key restent autorisés via
     * le contrat racine normalisé par l'API admin.
     *
     * @var list<string>
     */
    private const STRICT_SYSTEM_FIELD_KEYS = [
        'id',
        'uuid',
        'status',
        'workflow_state',
        'language',
        'locale',
        'site',
        'site_id',
        'revision_state',
        'revision_id',
        'blueprint_id',
        'blueprint_version_id',
        'created_at',
        'updated_at',
        'published_by_iam_user_id',
    ];

    public function __construct(
        private readonly ContentTypeRepository $contentTypes,
        private readonly EntryPayloadValidationRepository $repository,
        private readonly ContentPathBuilder $paths,
    ) {}

    /** @param array<string,mixed> $payload @return list<string> */
    public function validate(int $siteId, ContentType $contentType, string $languageCode, array $payload, ?int $entryId = null): array
    {
        $errors = [];
        $contentTypeId = $this->contentTypes->findIdByKey($contentType->typeKey);
        if (!$contentTypeId) {
            return [sprintf('Identifiant du type de contenu introuvable : %s.', $contentType->typeKey)];
        }

        array_push($errors, ...$this->validateNoStrictSystemFieldShadowing($payload));
        $schemaPayload = $this->flattenPayloadFields($payload);
        array_push($errors, ...$contentType->schema()->validatePayload($schemaPayload));

        $entryKey = $this->normalizeEntryKey($payload['entry_key'] ?? '', (string) ($payload['title'] ?? ''), $contentType->typeKey);
        if ($entryKey !== '' && $this->repository->entryKeyExists($siteId, $entryKey, $entryId)) {
            $errors[] = sprintf('La clé éditoriale "%s" existe déjà pour ce site.', $entryKey);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $slug = $this->normalizeSlug($payload['slug'] ?? '', $title !== '' ? $title : $entryKey);
        if ($contentType->supportsPermalink()) {
            if ($slug === '') {
                $errors[] = 'Le slug est obligatoire pour un type de contenu exposé publiquement.';
            } elseif ($this->repository->slugExists($siteId, $languageCode, $slug, $entryId)) {
                $errors[] = sprintf('Le slug "%s" existe déjà pour ce site et cette langue.', $slug);
            }
        }

        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition) { continue; }
            $value = $this->payloadHasFieldValue($payload, $field) ? $this->payloadFieldValue($payload, $field) : null;
            if ($this->isEmpty($value)) { continue; }
            array_push($errors, ...$this->validateFieldReferences($siteId, $languageCode, $field, $value));
            if ($field->isUnique) {
                $normalized = $this->normalizeUniqueValue($field, $value);
                $scopeLanguage = $field->isLocalized ? $languageCode : '';
                if ($normalized !== '' && $this->repository->uniqueFieldValueExists($siteId, $contentTypeId, $field->fieldKey, $scopeLanguage, $normalized, $entryId)) {
                    $errors[] = sprintf('La valeur du champ "%s" doit être unique.', $field->label);
                }
            }
        }

        array_push($errors, ...$this->validateTaxonomies($siteId, $contentType, $payload));
        array_push($errors, ...$this->validateSeo($contentType, $payload));
        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $normalized @return array<string,array<string,string>> */
    public function uniqueFieldValuesByLanguage(ContentType $contentType, string $languageCode, array $normalized): array
    {
        $byLanguage = [];
        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition || !$field->isUnique) { continue; }
            $value = $normalized['fields'][$field->fieldKey] ?? null;
            $normalizedValue = $this->normalizeUniqueValue($field, $value);
            if ($normalizedValue === '') { continue; }
            $scopeLanguage = $field->isLocalized ? $languageCode : '';
            $byLanguage[$scopeLanguage][$field->fieldKey] = $normalizedValue;
        }
        return $byLanguage;
    }

    /** @param array<string,array<string,string>> $uniqueValuesByLanguage */
    public function reserveNativeConstraints(int $siteId, int $contentTypeId, int $entryId, string $languageCode, string $slug, array $uniqueValuesByLanguage): void
    {
        $this->repository->reserveSlug($siteId, $entryId, $languageCode, $slug);
        foreach ($uniqueValuesByLanguage as $scopeLanguage => $values) {
            $this->repository->replaceUniqueFieldValues($siteId, $contentTypeId, $entryId, (string) $scopeLanguage, $values);
        }
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function validateNoStrictSystemFieldShadowing(array $payload): array
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($fields === []) { return []; }

        $errors = [];
        foreach (self::STRICT_SYSTEM_FIELD_KEYS as $key) {
            if (array_key_exists($key, $fields)) {
                $errors[] = sprintf('Le champ système "%s" ne peut pas être fourni dans fields. Il est piloté par le contexte, le workflow ou la publication.', $key);
            }
        }
        return $errors;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function flattenPayloadFields(array $payload): array
    {
        $schemaPayload = $payload;
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            foreach ($payload['fields'] as $key => $value) {
                $fieldKey = (string) $key;
                if (in_array($fieldKey, self::NATIVE_EDITORIAL_FIELD_KEYS, true)) {
                    continue;
                }
                if (!array_key_exists($fieldKey, $schemaPayload)) { $schemaPayload[$fieldKey] = $value; }
            }
        }
        return $schemaPayload;
    }

    /** @return list<string> */
    private function validateFieldReferences(int $siteId, string $languageCode, FieldDefinition $field, mixed $value): array
    {
        $errors = [];
        if ($field->fieldType === FieldType::MEDIA) {
            foreach ($this->idsFromValue($value) as $mediaId) {
                if (!$this->repository->mediaExists($siteId, $mediaId)) {
                    $errors[] = sprintf('Le média #%d référencé par "%s" est introuvable.', $mediaId, $field->label);
                    continue;
                }
                $mediaType = (string) $this->repository->mediaType($siteId, $mediaId);
                $altPolicy = (string) $field->validationValue('alt_policy', 'required');
                if (!in_array($altPolicy, ['required', 'decorative_allowed', 'forbidden'], true)) {
                    $errors[] = sprintf('La politique alt du champ média "%s" est invalide.', $field->label);
                } elseif ($mediaType === 'image' && $altPolicy === 'required' && !$this->repository->mediaHasAlt($siteId, $mediaId, $languageCode)) {
                    $errors[] = sprintf('Le média image #%d référencé par "%s" doit avoir un texte alternatif en %s.', $mediaId, $field->label, $languageCode);
                }
                $allowedMime = $field->validationValue('allowed_mime', $field->validationValue('allowed_mimes', []));
                if ($allowedMime !== [] && $allowedMime !== null) {
                    $allowed = array_map('strtolower', array_map('strval', (array) $allowedMime));
                    $mime = strtolower((string) $this->repository->mediaMimeType($siteId, $mediaId));
                    if ($mime !== '' && !$this->mimeAllowed($mime, $allowed)) {
                        $errors[] = sprintf('Le type MIME "%s" du média #%d n’est pas autorisé pour "%s".', $mime, $mediaId, $field->label);
                    }
                }
            }
        }
        if ($field->fieldType === FieldType::RELATION) {
            foreach ($this->idsFromValue($value) as $targetId) {
                if (!$this->repository->contentEntryExists($siteId, $targetId)) {
                    $errors[] = sprintf('L’entrée #%d référencée par "%s" est introuvable.', $targetId, $field->label);
                }
            }
        }
        return $errors;
    }

    /**
     * Normalise les taxonomies du payload admin en un dictionnaire stable :
     * taxonomy_key => liste ordonnée d'identifiants de termes.
     *
     * @param array<string,mixed> $payload
     * @return array<string,list<int>>
     */
    public function normalizeTaxonomyTerms(int $siteId, ContentType $contentType, array $payload): array
    {
        if (!$contentType->allowsTaxonomies()) { return []; }

        $raw = $payload['taxonomy_terms'] ?? ($payload['taxonomies'] ?? []);
        if (!is_array($raw) || $raw === []) { return []; }

        $contentTypeId = $this->contentTypes->findIdByKey($contentType->typeKey);
        if (!$contentTypeId) { return []; }

        $policies = $this->taxonomyPoliciesByKey($siteId, $contentTypeId);
        if ($policies === []) { return []; }

        $grouped = [];
        $flatIds = [];
        foreach ($raw as $taxonomyKey => $termIds) {
            if (is_string($taxonomyKey) && !is_numeric($taxonomyKey)) {
                $key = trim($taxonomyKey);
                if ($key === '' || !isset($policies[$key])) { continue; }
                foreach ($this->idsFromValue($termIds) as $termId) {
                    $grouped[$key][] = $termId;
                    $flatIds[] = $termId;
                }
                continue;
            }
            foreach ($this->idsFromValue($termIds) as $termId) { $flatIds[] = $termId; }
        }

        $termMap = $this->repository->taxonomyTermsById($siteId, $flatIds);
        foreach (array_values(array_unique($flatIds)) as $termId) {
            $term = $termMap[$termId] ?? null;
            if (!$term) { continue; }
            $key = (string) $term['taxonomy_key'];
            if (!isset($policies[$key])) { continue; }
            $grouped[$key][] = $termId;
        }

        $normalized = [];
        foreach ($policies as $key => $policy) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $grouped[$key] ?? []), static fn(int $id): bool => $id > 0)));
            if ($ids !== []) { $normalized[$key] = $ids; }
        }
        return $normalized;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function validateTaxonomies(int $siteId, ContentType $contentType, array $payload): array
    {
        if (!$contentType->allowsTaxonomies()) { return []; }

        $contentTypeId = $this->contentTypes->findIdByKey($contentType->typeKey);
        if (!$contentTypeId) { return [sprintf('Identifiant du type de contenu introuvable : %s.', $contentType->typeKey)]; }

        $raw = $payload['taxonomy_terms'] ?? ($payload['taxonomies'] ?? []);
        if (!is_array($raw)) { return ['Le champ taxonomy_terms doit être un objet ou une liste.']; }

        $policies = $this->taxonomyPoliciesByKey($siteId, $contentTypeId);
        if ($policies === []) {
            return $this->hasSelectedTaxonomyTerms($raw)
                ? ['Ce type de contenu n’a aucune taxonomie autorisée.']
                : [];
        }

        $errors = [];
        $normalized = $this->normalizeTaxonomyTerms($siteId, $contentType, $payload);
        $allIds = [];
        foreach ($normalized as $ids) { foreach ($ids as $id) { $allIds[] = $id; } }
        $termMap = $this->repository->taxonomyTermsById($siteId, $allIds);

        foreach ($raw as $taxonomyKey => $termIds) {
            if (is_string($taxonomyKey) && !is_numeric($taxonomyKey) && !isset($policies[$taxonomyKey])) {
                $errors[] = sprintf('La taxonomie "%s" n’est pas autorisée pour ce type de contenu.', $taxonomyKey);
            }
            foreach ($this->idsFromValue($termIds) as $termId) {
                if (!isset($termMap[$termId])) {
                    $errors[] = sprintf('Le terme de taxonomie #%d est introuvable ou inactif pour ce site.', $termId);
                    continue;
                }
                $termTaxonomyKey = (string) $termMap[$termId]['taxonomy_key'];
                if (!isset($policies[$termTaxonomyKey])) {
                    $errors[] = sprintf('Le terme #%d appartient à une taxonomie non autorisée pour ce type de contenu.', $termId);
                }
            }
        }

        foreach ($policies as $taxonomyKey => $policy) {
            $count = count($normalized[$taxonomyKey] ?? []);
            if ((int) ($policy['is_required'] ?? 0) === 1 && $count === 0) {
                $errors[] = sprintf('La taxonomie "%s" est obligatoire.', $taxonomyKey);
            }
            $maxTerms = $policy['max_terms'] ?? null;
            if ($maxTerms !== null && (int) $maxTerms > 0 && $count > (int) $maxTerms) {
                $errors[] = sprintf('La taxonomie "%s" accepte au maximum %d terme(s).', $taxonomyKey, (int) $maxTerms);
            }
        }

        return $errors;
    }


    /** @param mixed $raw */
    private function hasSelectedTaxonomyTerms(mixed $raw): bool
    {
        if (!is_array($raw) || $raw === []) { return false; }
        foreach ($raw as $value) {
            if ($this->idsFromValue($value) !== []) { return true; }
        }
        return false;
    }

    /** @return array<string,array{id:int,taxonomy_key:string,name:string,is_required:int,max_terms:int|null}> */
    private function taxonomyPoliciesByKey(int $siteId, int $contentTypeId): array
    {
        $policies = [];
        foreach ($this->repository->contentTypeTaxonomyPolicies($siteId, $contentTypeId) as $policy) {
            $policies[(string) $policy['taxonomy_key']] = $policy;
        }
        return $policies;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function validateSeo(ContentType $contentType, array $payload): array
    {
        if (!$contentType->allowsSeo()) { return []; }
        $errors = [];
        $title = trim((string) ($payload['title'] ?? ''));
        $metaTitle = trim((string) ($payload['meta_title'] ?? $title));
        $metaDescription = trim((string) ($payload['meta_description'] ?? ''));
        if ($title === '') { $errors[] = 'Le titre éditorial est obligatoire.'; }
        if ($metaTitle !== '' && mb_strlen($metaTitle) > 70) { $errors[] = 'Le meta title devrait rester sous 70 caractères.'; }
        if ($metaDescription !== '' && mb_strlen($metaDescription) > 180) { $errors[] = 'La meta description devrait rester sous 180 caractères.'; }
        return $errors;
    }

    private function normalizeEntryKey(mixed $rawEntryKey, string $title, string $typeKey): string
    { $entryKey = trim((string) $rawEntryKey); return $this->paths->slugify($entryKey === '' ? ($title !== '' ? $title : $typeKey . '-' . time()) : $entryKey); }

    private function normalizeSlug(mixed $rawSlug, string $fallback): string
    { $slug = trim((string) $rawSlug); return $this->paths->slugify($slug === '' ? $fallback : $slug); }

    /** @param array<string,mixed> $payload */
    private function payloadHasFieldValue(array $payload, FieldDefinition $field): bool
    { return array_key_exists($field->fieldKey, $payload) || (isset($payload['fields']) && is_array($payload['fields']) && array_key_exists($field->fieldKey, $payload['fields'])); }

    /** @param array<string,mixed> $payload */
    private function payloadFieldValue(array $payload, FieldDefinition $field): mixed
    { return array_key_exists($field->fieldKey, $payload) ? $payload[$field->fieldKey] : (is_array($payload['fields'] ?? null) ? ($payload['fields'][$field->fieldKey] ?? null) : null); }

    /** @return list<int> */
    private function idsFromValue(mixed $value): array
    {
        if (is_array($value)) {
            if (isset($value['media_id']) || isset($value['id'])) {
                $candidate = $value['media_id'] ?? $value['id'];
                return is_numeric($candidate) && (int) $candidate > 0 ? [(int) $candidate] : [];
            }
            $ids = [];
            foreach ($value as $item) {
                if (is_array($item)) { $item = $item['media_id'] ?? $item['id'] ?? null; }
                if (is_numeric($item)) { $ids[] = (int) $item; }
            }
            return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        }
        return is_numeric($value) && (int) $value > 0 ? [(int) $value] : [];
    }

    /** @param list<string> $allowed */
    private function mimeAllowed(string $mime, array $allowed): bool
    {
        foreach ($allowed as $candidate) { if ($candidate === $mime) { return true; } if (str_ends_with($candidate, '/*') && str_starts_with($mime, substr($candidate, 0, -1))) { return true; } }
        return false;
    }

    private function normalizeUniqueValue(FieldDefinition $field, mixed $value): string
    {
        if ($this->isEmpty($value)) { return ''; }
        if (is_array($value)) { $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        $value = trim((string) $value);
        return $field->fieldType === FieldType::SLUG ? $this->paths->slugify($value) : mb_strtolower($value);
    }

    private function isEmpty(mixed $value): bool
    { return $value === null || $value === '' || $value === []; }
}
