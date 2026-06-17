<?php

declare(strict_types=1);

namespace App\Application\Publication;

use App\Application\Content\Projection\RouteProjector;
use App\Application\Support\ContentPathBuilder;
use App\Application\Content\BlockDocumentNormalizer;
use App\Core\Database;
use App\Domain\Content\ContentRevision;
use App\Domain\Seo\SeoMetadata;
use App\Application\Seo\SeoTemplateRenderer;
use App\Application\Media\Storage\MediaUrlGenerator;
use App\Module\HookDispatcher;

final class ProjectionBuilder
{
    private MediaUrlGenerator $mediaUrls;

    public function __construct(
        private readonly Database $db,
        private readonly ContentPathBuilder $paths,
        private readonly array $config,
        private readonly ?HookDispatcher $hooks = null,
    ) {
        $this->mediaUrls = new MediaUrlGenerator($db);
    }

    /** @param array<string,mixed> $entry */
    public function buildForPublication(array $entry, ContentRevision $revision, string $languageCode): PublishedProjection
    {
        return $this->build($entry, $revision, $languageCode);
    }

    /** @param array<string,mixed> $entry */
    public function buildForPublishedRevision(array $entry, ContentRevision $revision, string $languageCode): PublishedProjection
    {
        return $this->build($entry, $revision, $languageCode);
    }

    /** @param array<string,mixed> $entry */
    private function build(array $entry, ContentRevision $revision, string $languageCode): PublishedProjection
    {
        $siteId = (int) ($entry['site_id'] ?? 0);
        $entryId = (int) ($entry['id'] ?? 0);
        $typeKey = (string) ($entry['type_key'] ?? '');

        if ($siteId < 1 || $entryId < 1 || $typeKey === '') {
            throw new \RuntimeException('Projection impossible : entrée, site ou type de contenu invalide.');
        }

        $this->assertLanguageCanPublish($siteId, $languageCode);
        $this->assertTemplateExists($entry);

        $document = $revision->document();
        $document['blocks'] = $this->enrichBlocksWithMediaUrls(is_array($document['blocks'] ?? null) ? $document['blocks'] : []);
        if (is_array($document['content'] ?? null)) { $document['content']['blocks'] = $document['blocks']; }
        $document = $this->sanitizePublishedMediaUrls($document);
        $documentLanguage = trim((string) ($document['language_code'] ?? ''));
        if ($documentLanguage !== $languageCode) {
            throw new \RuntimeException(sprintf('La révision est en langue "%s", mais la publication demandée vise "%s".', $documentLanguage, $languageCode));
        }

        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $title = trim((string) ($content['title'] ?? ''));
        $slug = trim((string) ($content['slug'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('Publication refusée : le titre est obligatoire.');
        }
        if ($slug === '') {
            throw new \RuntimeException('Publication refusée : le slug est obligatoire.');
        }
        if ($this->fieldsPreventRendering($content)) {
            throw new \RuntimeException('Publication refusée : au moins un champ invalide empêche le rendu public.');
        }
        $this->assertRequiredFields($entry, $document, $content);

        $path = $this->paths->build($typeKey, $slug);
        $blockNormalizer = new BlockDocumentNormalizer();
        $blocks = $blockNormalizer->normalize($document['blocks'] ?? []);
        $publicBlocks = $blockNormalizer->publicBlocks($blocks);
        if ($publicBlocks === []) {
            throw new \RuntimeException('Publication refusée : le contenu visible doit contenir au moins un bloc actif avec l’état éditorial publié.');
        }
        $blockErrors = $blockNormalizer->validate($publicBlocks);
        if ($blockErrors !== []) {
            throw new \RuntimeException('Publication refusée : ' . implode(' ', $blockErrors));
        }
        $document['blocks'] = $publicBlocks;
        if (is_array($document['content'] ?? null)) { $document['content']['blocks'] = $publicBlocks; }
        $document = $this->applyBlueprintPublicProjection($document, $typeKey, $siteId);

        $seo = $this->seoFromDocument($document, $title, $slug, $path, $siteId, $typeKey);
        if (trim((string) $seo->metaTitle) === '' || trim((string) $seo->metaDescription) === '') {
            throw new \RuntimeException('Publication refusée : les métadonnées SEO minimales ne peuvent pas être générées.');
        }

        $projection = new PublishedProjection($siteId, $languageCode, RouteProjector::RESOURCE_TYPE, $entryId, $path, $document, $seo, (int) $revision->id, $revision->checksum());

        return $this->applyProjectionHooks($entry, $revision, $projection);
    }

    /** @param array<string,mixed> $entry */
    private function applyProjectionHooks(array $entry, ContentRevision $revision, PublishedProjection $projection): PublishedProjection
    {
        if (!$this->hooks) {
            return $projection;
        }

        $document = $projection->document;
        $seo = $projection->seo;

        $payload = $this->hooks->filter('publication.projection_building', [
            'entry' => $entry,
            'revision' => $revision,
            'projection' => $projection,
            'document' => $document,
            'seo' => $seo->toArray(),
        ]);
        $document = is_array($payload['document'] ?? null) ? $payload['document'] : $document;
        $seo = is_array($payload['seo'] ?? null) ? SeoMetadata::fromArray($payload['seo']) : $seo;

        $seoPayload = $this->hooks->filter('seo.metadata_building', [
            'entry' => $entry,
            'revision' => $revision,
            'projection' => $projection,
            'seo' => $seo->toArray(),
        ]);
        $seo = is_array($seoPayload['seo'] ?? null) ? SeoMetadata::fromArray($seoPayload['seo']) : $seo;

        $searchPayload = $this->hooks->filter('search.document_building', [
            'entry' => $entry,
            'revision' => $revision,
            'projection' => $projection,
            'document' => $document,
        ]);
        $document = is_array($searchPayload['document'] ?? null) ? $searchPayload['document'] : $document;
        $document = $this->sanitizePublishedMediaUrls($document);

        return new PublishedProjection(
            $projection->siteId,
            $projection->languageCode,
            $projection->resourceType,
            $projection->resourceId,
            $projection->path,
            $document,
            $seo,
            $projection->sourcePublishedRevisionId,
            $projection->sourceRevisionChecksumSha256,
        );
    }



    /**
     * Filtre le document publié selon le blueprint actif du type de contenu.
     * Le front HTML et l’API headless lisent ensuite ce même snapshot cacheable :
     * aucun champ interne, brouillon ou désactivé ne sort publiquement.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function applyBlueprintPublicProjection(array $document, string $typeKey, int $siteId): array
    {
        $policy = $this->publicFieldPolicy($typeKey, $siteId);
        if ($policy === null) {
            return $document;
        }

        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        $publicFields = [];
        foreach ($policy['fields'] as $fieldKey => $fieldPolicy) {
            if (!array_key_exists($fieldKey, $fields)) {
                continue;
            }
            $value = $fields[$fieldKey];
            if ($value === null || $value === '') {
                continue;
            }
            if (($fieldPolicy['type'] ?? '') === 'assets' || ($fieldPolicy['type'] ?? '') === 'media') {
                $value = $this->resolveMediaFieldValue($value, (string) ($fieldPolicy['preferred_set'] ?? 'content'));
            }
            $publicFields[$fieldKey] = $value;
        }
        $document['fields'] = $publicFields;
        $document['blueprint'] = [
            'key' => $typeKey,
            'version' => $policy['version'],
            'projection' => 'public',
        ];
        $document['headless'] = [
            'system' => ['content_type' => $typeKey, 'language_code' => (string) ($document['language_code'] ?? '')],
            'editorial' => ['fields' => $publicFields],
        ];
        return $document;
    }

    /** @return array{version:int,fields:array<string,array<string,mixed>>}|null */
    private function publicFieldPolicy(string $typeKey, int $siteId): ?array
    {
        if (!$this->db->tableExists('blueprints') || !$this->db->tableExists('blueprint_versions')) {
            return null;
        }
        $row = $this->db->one(
            "SELECT b.blueprint_key, bv.version, bv.schema_json
             FROM blueprints b
             JOIN blueprint_versions bv ON bv.id = b.active_version_id AND bv.is_active = 1
             WHERE b.resource_type = 'content_type'
               AND b.blueprint_key = :type_key
               AND b.is_active = 1
               AND (b.site_id = :site_id OR b.site_id IS NULL)
             ORDER BY CASE WHEN b.site_id = :site_id THEN 0 ELSE 1 END
             LIMIT 1",
            ['type_key' => $typeKey, 'site_id' => $siteId]
        );
        if (!$row) { return null; }
        $schema = json_decode((string) ($row['schema_json'] ?? '{}'), true);
        if (!is_array($schema)) { return null; }

        $fields = [];
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            if (!is_array($field)) { continue; }
            $key = trim((string) ($field['field_key'] ?? $field['handle'] ?? ''));
            if ($key === '') { continue; }
            $purpose = (string) ($field['field_purpose'] ?? $field['purpose'] ?? 'content');
            $headless = is_array($field['headless'] ?? null) ? $field['headless'] : [];
            $public = $field['public'] ?? ($headless['public'] ?? true);
            $enabled = $field['enabled'] ?? true;
            if ($public === false || $enabled === false || in_array($purpose, ['system', 'seo', 'page_builder'], true)) {
                continue;
            }
            $fields[$key] = [
                'type' => (string) ($field['field_type'] ?? $field['type'] ?? 'text'),
                'preferred_set' => (string) ($headless['preferred_media_set'] ?? $field['preferred_media_set'] ?? 'content'),
            ];
        }
        return ['version' => (int) ($row['version'] ?? 1), 'fields' => $fields];
    }

    private function resolveMediaFieldValue(mixed $value, string $preferredSet): mixed
    {
        if (is_numeric($value)) {
            return $this->mediaProjection((int) $value, $preferredSet) ?: (int) $value;
        }
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $resolved = [];
                foreach ($value as $item) {
                    $resolved[] = $this->resolveMediaFieldValue($item, $preferredSet);
                }
                return $resolved;
            }
            if (isset($value['id']) && is_numeric($value['id'])) {
                return ($this->mediaProjection((int) $value['id'], $preferredSet) ?: []) + $value;
            }
        }
        return $value;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function enrichBlocksWithMediaUrls(array $blocks): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) { continue; }
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ($type === 'image') {
                $data = $this->applyMediaProjection($data, 'media_id', 'src', 'content');
            } elseif ($type === 'hero') {
                $data = $this->applyMediaProjection($data, 'image_media_id', 'image_src', 'hero', 'image_');
                unset($data['background_media_id'], $data['background_src']);
            } elseif (in_array($type, ['video', 'audio'], true)) {
                $data = $this->applyMediaProjection($data, 'media_id', 'src', 'original');
                $data = $this->applyMediaProjection($data, 'poster_media_id', 'poster', 'content', 'poster_');
            }

            if (is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $itemIndex => $item) {
                    if (!is_array($item)) { continue; }
                    $data['items'][$itemIndex] = $this->applyMediaProjection($item, 'media_id', 'src', 'content');
                }
            }
            if (is_array($data['columns'] ?? null)) {
                foreach ($data['columns'] as $columnIndex => $column) {
                    if (is_array($column)) {
                        $column['blocks'] = $this->enrichBlocksWithMediaUrls(is_array($column['blocks'] ?? null) ? $column['blocks'] : []);
                        $data['columns'][$columnIndex] = $column;
                    }
                }
            }
            $block['data'] = $data;
            $blocks[$index] = $block;
        }
        return array_values($blocks);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function applyMediaProjection(array $data, string $idKey, string $urlKey, string $preferredSet, string $prefix = ''): array
    {
        $id = is_numeric($data[$idKey] ?? null) ? (int) $data[$idKey] : 0;
        if ($id < 1) { return $data; }
        $media = $this->mediaProjection($id, $preferredSet);
        if (!$media) { return $data; }

        // Quand un media_id est présent, l'URL publiée doit être recalculée
        // depuis media_assets/media_asset_variants. On ignore volontairement
        // une ancienne valeur src stockée dans la révision pour éviter de figer
        // un chemin local absolu ou un ancien base path dans le snapshot public.
        $data[$urlKey] = $media['url'];
        if (!empty($media['srcset'])) { $data[$prefix . 'srcset'] = $media['srcset']; }
        if (!empty($media['sizes'])) { $data[$prefix . 'sizes'] = $media['sizes']; }
        if (!empty($media['width'])) { $data[$prefix . 'width'] = $media['width']; }
        if (!empty($media['height'])) { $data[$prefix . 'height'] = $media['height']; }
        if (!empty($media['mime_type'])) { $data[$prefix . 'mime_type'] = $media['mime_type']; }
        return $data;
    }

    /** @return array<string,mixed>|null */
    private function mediaProjection(int $mediaId, string $preferredSet): ?array
    {
        $asset = $this->db->one('SELECT id, media_type, mime_type, public_path, path, width, height FROM media_assets WHERE id = :id AND lifecycle_status = \'ready\' LIMIT 1', ['id' => $mediaId]);
        if (!$asset) { return null; }
        $variants = $this->db->all('SELECT variant_key, path, width, height, mime_type FROM media_asset_variants WHERE media_id = :id AND generation_status = \'ready\' ORDER BY width ASC, variant_key ASC', ['id' => $mediaId]);
        $keys = match ($preferredSet) {
            'hero' => ['hero_1280', 'hero_960', 'hero_640', 'content_1280', 'content_1024', 'original'],
            'content' => ['content_1280', 'content_1024', 'content_768', 'content_480', 'original'],
            'open_graph' => ['og_1200x630', 'hero_1280', 'content_1280', 'original'],
            default => ['original'],
        };
        $chosen = $this->variantByKeys($variants, $keys);
        $siteId = (int) ($asset['site_id'] ?? 0);
        $disk = (string) ($asset['storage_disk'] ?? 'local');
        $url = $chosen ? $this->publicMediaUrl((string) ($chosen['path'] ?? ''), $siteId, $disk) : $this->publicMediaUrl((string) ($asset['public_path'] ?? $asset['path'] ?? ''), $siteId, $disk);
        if ($url === '') { return null; }

        $srcset = '';
        if (in_array($preferredSet, ['content', 'hero'], true)) {
            $prefix = $preferredSet . '_';
            $srcsetItems = [];
            foreach ($variants as $variant) {
                $key = (string) ($variant['variant_key'] ?? '');
                $width = (int) ($variant['width'] ?? 0);
                if (!str_starts_with($key, $prefix) || $width < 1) { continue; }
                $variantUrl = $this->publicMediaUrl((string) ($variant['path'] ?? ''), $siteId, $disk);
                if ($variantUrl !== '') { $srcsetItems[] = $variantUrl . ' ' . $width . 'w'; }
            }
            $srcset = implode(', ', $srcsetItems);
        }

        return [
            'url' => $url,
            'srcset' => $srcset,
            'sizes' => $preferredSet === 'hero' ? '(min-width: 992px) 50vw, 100vw' : '(min-width: 960px) 960px, 100vw',
            'width' => (int) ($chosen['width'] ?? $asset['width'] ?? 0),
            'height' => (int) ($chosen['height'] ?? $asset['height'] ?? 0),
            'mime_type' => (string) ($chosen['mime_type'] ?? $asset['mime_type'] ?? ''),
        ];
    }

    /** @param list<array<string,mixed>> $variants @param list<string> $keys @return array<string,mixed>|null */
    private function variantByKeys(array $variants, array $keys): ?array
    {
        foreach ($keys as $key) {
            foreach ($variants as $variant) {
                if (($variant['variant_key'] ?? '') === $key) { return $variant; }
            }
        }
        return null;
    }

    private function publicMediaUrl(string $relativePath, int $siteId = 0, string $disk = 'local'): string
    {
        $path = trim(str_replace('\\', '/', $relativePath));
        if ($path === '') { return ''; }
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) { return $path; }
        if ($siteId > 0 && $disk !== 'local') { return $this->mediaUrls->publicUrl($path, $siteId, $disk); }

        $base = app_base_path();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            return $path;
        }

        // Tolère les anciens snapshots ou valeurs de champs qui contiennent un
        // chemin disque absolu ou un double préfixe "mod/storage/media". La
        // projection publiée doit toujours revenir à une URL publique stricte.
        foreach (['/storage/media/', '/storage/'] as $needle) {
            $pos = strrpos($path, $needle);
            if ($pos !== false) {
                return url_path(substr($path, $pos));
            }
        }
        foreach (['storage/media/', 'storage/'] as $needle) {
            $pos = strrpos($path, $needle);
            if ($pos !== false) {
                return url_path('/' . substr($path, $pos));
            }
        }

        return url_path('/storage/media/' . ltrim($path, '/'));
    }


    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function sanitizePublishedMediaUrls(array $document): array
    {
        $sanitized = $this->sanitizeMediaUrlValue($document);
        return is_array($sanitized) ? $sanitized : $document;
    }

    private function sanitizeMediaUrlValue(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->sanitizeMediaUrlValue($childValue, is_string($childKey) ? $childKey : '');
            }
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $urlKeys = ['src', 'image_src', 'poster', 'og_image_src', 'twitter_image_src', 'url'];
        $srcsetKeys = ['srcset', 'image_srcset', 'poster_srcset'];
        if (in_array($key, $srcsetKeys, true)) {
            return $this->sanitizeSrcset($value);
        }
        if (in_array($key, $urlKeys, true) && $this->isInternalStorageMediaPath($value)) {
            return $this->publicMediaUrl($value);
        }
        return $value;
    }

    private function sanitizeSrcset(string $srcset): string
    {
        $items = [];
        foreach (explode(',', $srcset) as $item) {
            $item = trim($item);
            if ($item === '') { continue; }
            $parts = preg_split('/\s+/', $item, 2);
            $url = $parts[0] ?? '';
            $descriptor = $parts[1] ?? '';
            if ($this->isInternalStorageMediaPath($url)) {
                $url = $this->publicMediaUrl($url);
            }
            $items[] = trim($url . ($descriptor !== '' ? ' ' . $descriptor : ''));
        }
        return implode(', ', $items);
    }

    private function isInternalStorageMediaPath(string $value): bool
    {
        $path = trim(str_replace('\\', '/', $value));
        if ($path === '' || preg_match('#^https?://#i', $path) || str_starts_with($path, '//') || str_starts_with($path, 'data:')) {
            return false;
        }
        return str_contains($path, '/storage/') || str_contains($path, 'storage/media/') || str_starts_with($path, 'storage/');
    }

    private function assertLanguageCanPublish(int $siteId, string $languageCode): void
    {
        $language = $this->db->one('SELECT code FROM languages WHERE code = :code AND is_active = 1 LIMIT 1', ['code' => $languageCode]);
        if (!$language) {
            throw new \RuntimeException(sprintf('Publication refusée : la langue "%s" n’existe pas ou n’est pas active.', $languageCode));
        }
        $siteLanguage = $this->db->one(
            'SELECT id
             FROM site_languages
             WHERE site_id = :site_id
               AND language_code = :code
               AND is_active = 1
             LIMIT 1',
            [
                'site_id' => $siteId,
                'code' => $languageCode,
            ]
        );
        if (!$siteLanguage) {
            throw new \RuntimeException(sprintf('Publication refusée : la langue "%s" n’est pas active pour ce site.', $languageCode));
        }
    }

    /** @param array<string,mixed> $entry */
    private function assertTemplateExists(array $entry): void
    {
        $template = trim((string) ($entry['frontend_template'] ?? ''));
        if ($template === '') {
            throw new \RuntimeException('Publication refusée : le template de rendu est introuvable.');
        }
        $base = (string) ($this->config['themes']['default']['templates_path'] ?? $this->config['themes']['default']['path'] ?? '');
        if ($base !== '' && !is_file(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $template)) {
            throw new \RuntimeException(sprintf('Publication refusée : le template "%s" est introuvable.', $template));
        }
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $document @param array<string,mixed> $content */
    private function assertRequiredFields(array $entry, array $document, array $content): void
    {
        $contentTypeId = (int) ($entry['content_type_id'] ?? 0);
        if ($contentTypeId < 1) { return; }

        $structuredFields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        $requiredFields = $this->db->all("SELECT field_key, label FROM fields WHERE content_type_id = :id AND is_required = 1", ['id' => $contentTypeId]);
        foreach ($requiredFields as $field) {
            $key = (string) ($field['field_key'] ?? '');
            $value = $content[$key] ?? ($structuredFields[$key] ?? null);
            if ($this->emptyValue($value)) {
                throw new \RuntimeException(sprintf('Publication refusée : le champ obligatoire "%s" est absent.', (string) ($field['label'] ?? $key)));
            }
        }
    }

    /** @param array<string,mixed> $document */
    private function descriptionFromBlocks(array $document, string $title): string
    {
        $text = (new BlockDocumentNormalizer())->plainText(['content' => ['title' => $title], 'blocks' => is_array($document['blocks'] ?? null) ? $document['blocks'] : []]);
        if ($text === '') {
            return '';
        }
        return mb_strlen($text) > 220 ? mb_substr($text, 0, 217) . '…' : $text;
    }

    private function fieldsPreventRendering(array $content): bool
    {
        foreach ($content as $value) {
            if (is_resource($value)) { return true; }
        }
        return false;
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @param array<string,mixed> $document */
    private function seoFromDocument(array $document, string $title, string $slug, string $path, int $siteId, string $typeKey): SeoMetadata
    {
        $descriptionFallback = $this->descriptionFromBlocks($document, $title);
        $languageCode = (string) ($document['language_code'] ?? '');
        $payload = (new SeoTemplateRenderer($this->db, $this->config))->renderForContentEntry($siteId, $typeKey, $languageCode, $document, $title, $slug, $path, $descriptionFallback);

        $metaTitle = trim((string) ($payload['meta_title'] ?? $title));
        $metaDescription = trim((string) ($payload['meta_description'] ?? $descriptionFallback));
        $robots = $this->normalizeRobots((string) ($payload['meta_robots'] ?? ($this->config['seo']['default_meta_robots'] ?? 'index,follow')));
        $jsonLd = isset($payload['json_ld']) ? trim((string) $payload['json_ld']) : '';
        if ($jsonLd !== '') {
            json_decode($jsonLd, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Publication refusée : le JSON-LD SEO est invalide. Corrigez le champ ou supprimez-le avant publication.');
            }
        }

        return new SeoMetadata(
            $metaTitle,
            $metaDescription,
            (string) ($payload['canonical_url'] ?? $path),
            $robots,
            $jsonLd !== '' ? $jsonLd : null,
            RouteProjector::RESOURCE_TYPE,
            0,
            $languageCode,
            $this->score($metaTitle, $metaDescription, $slug),
            (string) ($payload['og_title'] ?? $metaTitle),
            (string) ($payload['og_description'] ?? $metaDescription),
            is_numeric($payload['og_image_media_id'] ?? null) ? (int) $payload['og_image_media_id'] : null,
            (string) ($payload['twitter_title'] ?? $payload['og_title'] ?? $metaTitle),
            (string) ($payload['twitter_description'] ?? $payload['og_description'] ?? $metaDescription),
            is_numeric($payload['twitter_image_media_id'] ?? null) ? (int) $payload['twitter_image_media_id'] : null,
            (string) ($payload['hreflang_code'] ?? $languageCode),
            is_array($payload['source'] ?? null) ? $payload['source'] : null
        );
    }

    private function normalizeRobots(string $robots): string
    {
        $allowed = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1'];
        $parts = array_filter(array_map(fn(string $part): string => strtolower(trim($part)), explode(',', $robots)));
        $normalized = [];
        foreach ($parts as $part) {
            if (in_array($part, $allowed, true)) {
                $normalized[$part] = $part;
            }
        }
        if (isset($normalized['index']) && isset($normalized['noindex'])) {
            unset($normalized['index']);
        }
        if (isset($normalized['follow']) && isset($normalized['nofollow'])) {
            unset($normalized['follow']);
        }
        if ($normalized === []) {
            return 'index,follow';
        }
        return implode(',', array_values($normalized));
    }

    private function score(string $title, string $description, string $slug): int
    {
        $score = 100;
        if (mb_strlen($title) < 25) { $score -= 15; }
        if (mb_strlen($description) < 80) { $score -= 20; }
        if (mb_strlen($slug) < 3) { $score -= 10; }
        return max(0, $score);
    }
}
