<?php

declare(strict_types=1);

namespace App\Application\Seo;

use App\Core\Database;

/**
 * Service central des templates SEO.
 *
 * Les projections publiques, le front HTML et l’API headless doivent lire les
 * mêmes valeurs finales depuis seo_metadata/public_content_snapshots. Ce service
 * ne rend donc les templates qu’au moment de construire la projection publiée.
 */
final class SeoTemplateRenderer
{
    public function __construct(private readonly Database $db, private readonly array $config) {}

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function renderForContentEntry(int $siteId, string $typeKey, string $languageCode, array $document, string $title, string $slug, string $path, string $descriptionFallback = ''): array
    {
        $explicit = is_array($document['seo'] ?? null) ? $document['seo'] : [];
        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        $site = $this->siteLocalization($siteId, $languageCode);
        $template = $this->template($siteId, 'content_entry', $typeKey, $languageCode);

        $summary = trim((string) ($explicit['meta_description'] ?? $descriptionFallback));
        $vars = [
            'title' => $title,
            'slug' => $slug,
            'path' => $path,
            'type' => $typeKey,
            'language' => $languageCode,
            'summary' => $summary,
            'site_title' => (string) ($site['site_title'] ?? ''),
            'site_name' => (string) ($site['site_title'] ?? ''),
            'site_baseline' => (string) ($site['baseline'] ?? ''),
            'default_meta_title_suffix' => (string) ($site['default_meta_title_suffix'] ?? ''),
        ];
        foreach ($content as $key => $value) {
            if (is_scalar($value) || $value === null) { $vars['content.' . $key] = (string) $value; }
        }
        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) { $vars['field.' . $key] = (string) $value; }
        }

        $metaTitle = $this->firstNonEmpty(
            $explicit['meta_title'] ?? null,
            $this->render($template['meta_title_template'] ?? null, $vars),
            $this->defaultTitle($title, (string) ($site['default_meta_title_suffix'] ?? ''))
        );
        $metaDescription = $this->firstNonEmpty(
            $explicit['meta_description'] ?? null,
            $this->render($template['meta_description_template'] ?? null, $vars),
            $descriptionFallback,
            $site['default_meta_description'] ?? null
        );
        $ogTitle = $this->firstNonEmpty($explicit['og_title'] ?? null, $this->render($template['og_title_template'] ?? null, $vars), $metaTitle);
        $ogDescription = $this->firstNonEmpty($explicit['og_description'] ?? null, $this->render($template['og_description_template'] ?? null, $vars), $metaDescription);
        $twitterTitle = $this->firstNonEmpty($explicit['twitter_title'] ?? null, $ogTitle, $metaTitle);
        $twitterDescription = $this->firstNonEmpty($explicit['twitter_description'] ?? null, $ogDescription, $metaDescription);
        $robots = $this->firstNonEmpty($explicit['meta_robots'] ?? null, $template['robots_default'] ?? null, $this->config['seo']['default_meta_robots'] ?? 'index,follow');
        $jsonLd = $this->firstNonEmpty($explicit['json_ld'] ?? null, $this->render($template['json_ld_template'] ?? null, $vars), null);

        return [
            'meta_title' => $this->limit($metaTitle, 120),
            'meta_description' => $this->limit($metaDescription, 320),
            'meta_robots' => $robots,
            'canonical_url' => $path,
            'og_title' => $this->limit($ogTitle, 120),
            'og_description' => $this->limit($ogDescription, 320),
            'og_image_media_id' => $this->mediaId($explicit['og_image_media_id'] ?? $site['og_default_image_media_id'] ?? null),
            'twitter_title' => $this->limit($twitterTitle, 120),
            'twitter_description' => $this->limit($twitterDescription, 320),
            'twitter_image_media_id' => $this->mediaId($explicit['twitter_image_media_id'] ?? $explicit['og_image_media_id'] ?? $site['og_default_image_media_id'] ?? null),
            'hreflang_code' => $this->hreflangCode($siteId, $languageCode),
            'json_ld' => $jsonLd !== '' ? $jsonLd : null,
            'source' => [
                'meta_title' => trim((string) ($explicit['meta_title'] ?? '')) !== '' ? 'editor' : (($template['meta_title_template'] ?? '') !== '' ? 'template' : 'fallback'),
                'meta_description' => trim((string) ($explicit['meta_description'] ?? '')) !== '' ? 'editor' : (($template['meta_description_template'] ?? '') !== '' ? 'template' : 'fallback'),
                'robots' => trim((string) ($explicit['meta_robots'] ?? '')) !== '' ? 'editor' : (($template['robots_default'] ?? '') !== '' ? 'template' : 'default'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function siteLocalization(int $siteId, string $languageCode): array
    {
        return $this->db->one('SELECT * FROM site_localizations WHERE site_id = :site_id AND language_code = :language LIMIT 1', ['site_id' => $siteId, 'language' => $languageCode])
            ?: $this->db->one('SELECT * FROM site_localizations WHERE site_id = :site_id ORDER BY id LIMIT 1', ['site_id' => $siteId])
            ?: [];
    }

    /** @return array<string,mixed> */
    private function template(int $siteId, string $resourceType, string $resourceSubtype, string $languageCode): array
    {
        return $this->db->one("SELECT * FROM seo_templates WHERE site_id = :site_id AND resource_type = :resource_type AND resource_subtype = :resource_subtype AND language_code = :language LIMIT 1", [
            'site_id' => $siteId, 'resource_type' => $resourceType, 'resource_subtype' => $resourceSubtype, 'language' => $languageCode,
        ]) ?: $this->db->one("SELECT * FROM seo_templates WHERE site_id = :site_id AND resource_type = :resource_type AND resource_subtype IS NULL AND language_code = :language LIMIT 1", [
            'site_id' => $siteId, 'resource_type' => $resourceType, 'language' => $languageCode,
        ]) ?: [];
    }

    private function hreflangCode(int $siteId, string $languageCode): string
    {
        $row = $this->db->one('SELECT hreflang_code FROM site_languages WHERE site_id = :site_id AND language_code = :language LIMIT 1', ['site_id' => $siteId, 'language' => $languageCode]);
        return trim((string) ($row['hreflang_code'] ?? $languageCode));
    }

    /** @param array<string,string> $vars */
    private function render(?string $template, array $vars): string
    {
        $template = trim((string) $template);
        if ($template === '') { return ''; }
        return trim((string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', static fn(array $m): string => $vars[$m[1]] ?? '', $template));
    }

    private function defaultTitle(string $title, string $suffix): string
    {
        $title = trim($title);
        $suffix = trim($suffix);
        if ($title === '') { return $suffix; }
        if ($suffix === '' || str_contains($title, $suffix)) { return $title; }
        return trim($title . ' ' . $suffix);
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') { return $text; }
        }
        return '';
    }

    private function mediaId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function limit(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }
}
