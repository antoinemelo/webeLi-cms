<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\EditorialBlockSecurityPolicy;

/**
 * Minimal PHP renderer used when Twig is not installed on a lightweight host.
 *
 * It intentionally supports only the public theme templates required by the
 * runtime. The editor/admin stays Vue-only and does not need Twig.
 */
final class NativeHtmlRenderer
{
    private static bool $visualEditing = false;
    public static function render(string $template, array $data = []): string
    {
        self::$visualEditing = !empty($data['visual_editing']);
        return match (trim($template, '/')) {
            'layout' => self::layout($data),
            'page' => self::page($data),
            'article' => self::article($data),
            'search' => self::search($data),
            'taxonomy-archive' => self::taxonomyArchive($data),
            'tombstone' => self::tombstone($data),
            'error' => self::error($data),
            default => self::page($data),
        };
    }

    private static function layout(array $data): string
    {
        $ui = self::ui($data);
        $lang = self::esc((string) ($data['languageCode'] ?? 'fr'));
        $title = self::esc((string) ($data['meta_title'] ?? $data['entry_title'] ?? $data['title'] ?? ''));
        $description = self::esc((string) ($data['meta_description'] ?? ''));
        $canonical = self::esc((string) ($data['canonical'] ?? ''));
        $robots = self::esc((string) ($data['meta_robots'] ?? 'index,follow'));
        $siteTitle = self::esc((string) ($data['site_localization']['site_title'] ?? $data['site']['name'] ?? $data['app_name'] ?? 'DEC CMS'));
        $content = (string) ($data['content'] ?? '');
        $breadcrumbs = self::breadcrumbs($data);
        $menu = self::navigation($data);
        $languages = self::languageSwitch($data);
        $homeUrl = self::esc((string) ($data['localized_home_url'] ?? url_path('/')));
        $searchUrl = self::esc((string) ($data['localized_search_url'] ?? url_path('/search')));
        $searchPlaceholder = self::esc($ui['search_placeholder'] ?? 'Rechercher');
        $searchLabel = self::esc($ui['search'] ?? 'Recherche');
        $footer = self::footer($data);
        $logoUrl = self::esc((string) ($data['site_logo_url'] ?? ''));
        $faviconUrl = self::esc((string) ($data['site_favicon_url'] ?? ''));
        $faviconLink = $faviconUrl !== '' ? '<link rel="icon" href="' . $faviconUrl . '">' : '';
        $visualCss = self::$visualEditing ? '<link rel="stylesheet" href="' . self::esc(asset_path('/frontend/theme-default/assets/css/visual-editing.css')) . '">' : '';
        $visualJs = self::$visualEditing ? '<script src="' . self::esc(asset_path('/frontend/theme-default/assets/js/visual-editing-bridge.js')) . '" defer></script>' : '';
        $appleIcon = self::esc(asset_path('/frontend/theme-default/assets/img/apple-touch-icon.png'));
        $brandInitials = self::esc((string) ($data['site_brand_initials'] ?? 'CMS'));
        $showSiteTitleInHeader = filter_var($data['show_site_title_in_header'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $brandTitleHtml = $showSiteTitleInHeader ? '<span class="brand__text">' . $siteTitle . '</span>' : '';
        $brandMark = $logoUrl !== '' ? '<img class="brand__logo" src="' . $logoUrl . '" alt="" loading="eager" decoding="async" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span class="brand__mark brand__mark--fallback" hidden>' . $brandInitials . '</span>' : '<span class="brand__mark">' . $brandInitials . '</span>';
        $bootstrapCss = self::esc(asset_path('/frontend/shared/vendor/bootstrap/5.3.3/css/bootstrap.min.css'));
        $css = self::esc(asset_path('/frontend/theme-default/assets/css/app.css'));
        $js = self::esc(asset_path('/frontend/theme-default/assets/js/app.js'));
        $cookieJs = self::esc(asset_path('/frontend/theme-default/assets/js/cookie-consent.js'));
        $ogTitle = self::esc((string) ($data['og_title'] ?? $data['meta_title'] ?? $title));
        $ogDescription = self::esc((string) ($data['og_description'] ?? $data['meta_description'] ?? ''));
        $ogType = self::esc((string) ($data['og_type'] ?? 'website'));
        $ogUrl = $canonical;
        $twitterCard = self::esc((string) ($data['twitter_card'] ?? 'summary_large_image'));
        $twitterTitle = self::esc((string) ($data['twitter_title'] ?? $data['og_title'] ?? $data['meta_title'] ?? $title));
        $twitterDescription = self::esc((string) ($data['twitter_description'] ?? $data['og_description'] ?? $data['meta_description'] ?? ''));
        $ogImageValue = self::esc((string) ($data['og_image'] ?? ''));
        $twitterImageValue = self::esc((string) ($data['twitter_image'] ?? $data['og_image'] ?? ''));
        $ogImageAlt = self::esc((string) ($data['og_image_alt'] ?? $data['meta_title'] ?? $title));
        $twitterImageAlt = self::esc((string) ($data['twitter_image_alt'] ?? $data['og_image_alt'] ?? $data['meta_title'] ?? $title));
        $ogImage = $ogImageValue !== '' ? '<meta property="og:image" content="' . $ogImageValue . '"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="' . $ogImageAlt . '">' : '';
        $twitterImage = $twitterImageValue !== '' ? '<meta name="twitter:image" content="' . $twitterImageValue . '"><meta name="twitter:image:alt" content="' . $twitterImageAlt . '">' : '';
        $jsonLd = self::jsonLdScript((string) ($data['json_ld'] ?? ''));
        $alternateLinks = self::alternateLinks($data);
        $aiSummary = trim((string) ($data['geo_summary'] ?? $data['ai_summary'] ?? ''));
        $aiSummaryMeta = $aiSummary !== '' ? '<meta name="ai-summary" content="' . self::esc($aiSummary) . '">' : '';
        $sitemapUrl = self::esc((string) ($data['sitemap_url'] ?? url_path('/sitemap.xml')));
        $llmsUrl = self::esc((string) ($data['llms_txt_url'] ?? url_path('/llms.txt')));
        $appearanceCss = trim((string) ($data['appearance_css'] ?? ''));
        $appearanceStyle = $appearanceCss !== '' ? '<style id="amcms-appearance">' . $appearanceCss . '</style>' : '';

        $htmlAttrs = self::$visualEditing ? ' data-amcms-visual="1"' : '';

        return <<<HTML
<!doctype html>
<html lang="{$lang}"{$htmlAttrs}>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="{$robots}">
  <title>{$title}</title>
  <meta name="description" content="{$description}">
  {$aiSummaryMeta}
  <meta name="generator" content="DEC CMS">
  <meta property="og:title" content="{$ogTitle}">
  <meta property="og:description" content="{$ogDescription}">
  <meta property="og:type" content="{$ogType}">
  <meta property="og:site_name" content="{$siteTitle}">
  <meta property="og:url" content="{$ogUrl}">
  {$ogImage}
  <meta name="twitter:card" content="{$twitterCard}">
  <meta name="twitter:title" content="{$twitterTitle}">
  <meta name="twitter:description" content="{$twitterDescription}">
  {$twitterImage}
  <link rel="canonical" href="{$canonical}">
  {$alternateLinks}
  <link rel="sitemap" type="application/xml" href="{$sitemapUrl}">
  <link rel="alternate" type="text/plain" title="LLMs.txt" href="{$llmsUrl}">
  {$faviconLink}
  {$visualCss}
  <link rel="stylesheet" href="{$bootstrapCss}">
  <link rel="apple-touch-icon" sizes="180x180" href="{$appleIcon}">
  <link rel="stylesheet" href="{$css}">
  {$appearanceStyle}
  {$jsonLd}
</head>
<body>
  <a class="skip-link" href="#main-content">{$ui['skip_to_content']}</a>
  <header class="site-header" data-site-header>
    <nav class="navbar navbar-expand-lg" aria-label="{$ui['main_navigation']}">
      <div class="container site-header__inner">
        <a class="navbar-brand brand" href="{$homeUrl}" aria-label="{$siteTitle} — {$ui['home']}">{$brandMark}{$brandTitleHtml}</a>
        <form class="header-search header-search--desktop d-none d-sm-block" action="{$searchUrl}" method="get" role="search"><label class="visually-hidden" for="header-search-q">{$searchLabel}</label><input id="header-search-q" class="form-control rounded-pill" name="q" value="" placeholder="{$searchPlaceholder}" autocomplete="search" inputmode="search"></form>
        <button class="navbar-toggler nav-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#site-navigation" aria-controls="site-navigation" aria-expanded="false" aria-label="{$ui['menu']}"><span class="navbar-toggler-icon"></span></button>
        <div id="site-navigation" class="collapse navbar-collapse site-navigation">{$menu}{$languages}</div>
      </div>
    </nav>
  </header>
  <main id="main-content" class="container main-content">{$breadcrumbs}{$content}</main>
  {$footer}
  <script src="{$js}" defer></script>
  <script src="{$cookieJs}" defer></script>
  {$visualJs}
</body>
</html>
HTML;
    }

    private static function page(array $data): string
    {
        $ui = self::ui($data);
        $classes = 'page-document' . (!empty($data['is_home']) ? ' page-document--home' : '');
        $entryKey = self::esc((string) ($data['entry_key'] ?? ''));
        $preview = !empty($data['is_preview']) ? '<span class="badge">' . self::esc($ui['preview'] ?? 'Prévisualisation') . '</span>' : '';
        $blocks = self::blocks($data);
        if ($blocks === '') {
            $label = self::esc((string) ($data['entry_type_label'] ?? 'Page'));
            $title = self::esc((string) ($data['entry_title'] ?? $data['title'] ?? ''));
            $blocks = '<header class="hero" aria-labelledby="page-title"><div class="meta">' . $label . '</div><h1 id="page-title">' . $title . '</h1></header>';
        }
        $share = self::share($data);
        return '<article class="' . $classes . '" data-entry-key="' . $entryKey . '">' . $preview . $blocks . $share . '</article>';
    }

    private static function article(array $data): string
    {
        $ui = self::ui($data);
        $preview = !empty($data['is_preview']) ? '<span class="badge">' . self::esc($ui['preview'] ?? 'Prévisualisation') . '</span>' : '';
        $label = self::esc((string) ($data['entry_type_label'] ?? 'Article'));
        $title = self::esc((string) ($data['entry_title'] ?? $data['title'] ?? ''));
        $date = (string) ($data['entry_published_at'] ?? '');
        $dateHtml = $date !== '' ? ' <span aria-hidden="true"> · </span><time datetime="' . self::esc($date) . '" itemprop="datePublished">' . self::esc(substr($date, 0, 10)) . '</time>' : '';
        return '<article class="article-page" data-entry-key="' . self::esc((string) ($data['entry_key'] ?? '')) . '" itemscope itemtype="https://schema.org/Article"><header class="hero" aria-labelledby="article-title">' . $preview . '<div class="meta"><span>' . $label . '</span>' . $dateHtml . '</div><h1 id="article-title" itemprop="headline">' . $title . '</h1></header><div class="prose article-page__body" itemprop="articleBody">' . self::blocks($data) . '</div></article>';
    }

    private static function search(array $data): string
    {
        $ui = self::ui($data);
        $q = self::esc((string) ($data['search_query'] ?? ''));
        $action = self::esc((string) ($data['localized_search_url'] ?? url_path('/search')));
        $html = '<section class="hero hero--compact" aria-labelledby="search-title"><p class="eyebrow">' . self::esc($ui['search'] ?? 'Recherche') . '</p><h1 id="search-title">' . self::esc($ui['search_title'] ?? 'Recherche') . '</h1><form class="search-page-form" action="' . $action . '" method="get" role="search"><label class="sr-only" for="search-q">' . self::esc($ui['search'] ?? 'Recherche') . '</label><input id="search-q" class="search-box" name="q" value="' . $q . '" placeholder="' . self::esc($ui['search_placeholder'] ?? 'Rechercher') . '" autocomplete="search">' . self::searchHiddenFilters($data) . '</form></section>';
        $items = is_array($data['search_results'] ?? null) ? $data['search_results'] : [];
        if ($items !== []) {
            $html .= '<section class="archive-list" aria-label="' . self::esc($ui['search_results'] ?? 'Résultats') . '">';
            foreach ($items as $item) {
                $html .= self::archiveItem(is_array($item) ? $item : []);
            }
            return $html . '</section>';
        }
        return $html . '<p class="empty-state">' . self::esc($q !== '' ? ($ui['no_results'] ?? 'Aucun résultat.') : ($ui['search_hint'] ?? 'Saisissez une recherche.')) . '</p>';
    }

    private static function searchHiddenFilters(array $data): string
    {
        $filters = is_array($data['search_filters'] ?? null) ? $data['search_filters'] : [];
        $html = '';
        foreach (['type', 'taxonomy', 'term'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $html .= '<input type="hidden" name="' . self::esc($key) . '" value="' . self::esc($value) . '">';
            }
        }
        return $html;
    }

    private static function taxonomyArchive(array $data): string
    {
        $ui = self::ui($data);
        $title = self::esc((string) ($data['archive_title'] ?? 'Archive'));
        $desc = self::esc((string) ($data['archive_description'] ?? ''));
        $html = '<section class="hero hero--compact" aria-labelledby="archive-title"><p class="eyebrow">' . self::esc($ui['taxonomy_archive'] ?? 'Archive') . '</p><h1 id="archive-title">' . $title . '</h1>' . ($desc !== '' ? '<p>' . $desc . '</p>' : '') . '</section>';
        $items = is_array($data['archive_items'] ?? null) ? $data['archive_items'] : [];
        if ($items !== []) {
            $html .= '<section class="archive-list" aria-label="' . $title . '">';
            foreach ($items as $item) {
                $html .= self::archiveItem(is_array($item) ? $item : []);
            }
            return $html . '</section>';
        }
        return $html . '<p class="empty-state">' . self::esc($ui['empty_archive'] ?? 'Aucun contenu.') . '</p>';
    }

    private static function tombstone(array $data): string
    {
        $ui = self::ui($data);
        $url = (string) ($data['replacement_url'] ?? $data['localized_home_url'] ?? url_path('/'));
        $label = ($data['replacement_url'] ?? '') !== '' ? ($ui['replacement'] ?? 'Voir le remplacement') : ($ui['back_home'] ?? 'Retour à l’accueil');
        return '<section class="hero hero--compact status-page"><div class="status-code">410</div><h1>' . self::esc($ui['gone_title'] ?? 'Page supprimée') . '</h1><p>' . self::esc($ui['gone_text'] ?? 'Cette page n’est plus disponible.') . '</p><a class="btn btn--primary" href="' . self::esc($url) . '">' . self::esc($label) . '</a></section>';
    }

    private static function error(array $data): string
    {
        $ui = self::ui($data);
        return '<section class="hero hero--compact status-page"><div class="status-code">' . self::esc((string) ($data['status'] ?? 404)) . '</div><h1>' . self::esc((string) ($data['error_title'] ?? $ui['not_found_title'] ?? 'Page introuvable')) . '</h1><p>' . self::esc((string) ($data['error_message'] ?? $ui['not_found_text'] ?? 'La page demandée est introuvable.')) . '</p><div class="actions"><a class="btn btn--primary" href="' . self::esc((string) ($data['localized_home_url'] ?? url_path('/'))) . '">' . self::esc($ui['back_home'] ?? 'Accueil') . '</a><a class="btn btn--ghost" href="' . self::esc((string) ($data['localized_search_url'] ?? url_path('/search'))) . '">' . self::esc($ui['search'] ?? 'Recherche') . '</a></div></section>';
    }

    private static function blocks(array $data): string
    {
        $blocks = is_array($data['entry_blocks'] ?? null) ? $data['entry_blocks'] : [];
        $preview = !empty($data['is_preview']);
        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['enabled'] ?? true) === false) {
                continue;
            }
            if (!$preview && (string) ($block['editorial_status'] ?? 'published') !== 'published') {
                continue;
            }
            $html .= self::block($block);
        }
        return $html;
    }

    private static function block(array $block): string
    {
        $type = (string) ($block['type'] ?? 'markdown');
        $data = is_array($block['data'] ?? null) ? $block['data'] : $block;
        $anchor = trim((string) ($block['anchor'] ?? ''));
        $id = $anchor !== '' ? ' id="' . self::esc($anchor) . '"' : '';
        $class = trim('content-block content-block--' . preg_replace('/[^a-z0-9_-]/i', '', $type) . ' ' . (string) ($block['css_class'] ?? ''));
        $classAttr = ' class="' . self::esc($class) . '"';
        $visualAttrs = self::visualBlockAttrs($block);
        return match ($type) {
            'richtext', 'html_safe' => '<section' . $id . $visualAttrs . $classAttr . '>' . self::policy()->sanitizeHtmlSafe((string) ($data['html'] ?? $block['html'] ?? '')) . '</section>',
            'html_raw' => '<section' . $id . $visualAttrs . $classAttr . '>' . (string) ($data['html'] ?? $block['html'] ?? '') . '</section>',
            'image' => self::imageBlock($id, $visualAttrs . $classAttr, $data),
            'video' => self::mediaBlock('video', $id, $visualAttrs . $classAttr, $data),
            'audio' => self::mediaBlock('audio', $id, $visualAttrs . $classAttr, $data),
            'iframe' => self::iframeBlock($id, $visualAttrs . $classAttr, $data),
            'embed' => self::embedBlock($id, $visualAttrs . $classAttr, $data),
            'hero' => self::heroBlock($id, $visualAttrs . $classAttr, $data),
            'gallery' => self::galleryBlock($id, $visualAttrs . $classAttr, $data),
            'buttons' => self::buttonsBlock($id, $visualAttrs . $classAttr, $data),
            'card' => self::cardBlock($id, $visualAttrs . $classAttr, $data, (string) ($block['id'] ?? '')),
            'columns' => self::columnsBlock($id, $visualAttrs . $classAttr, $data),
            'form' => self::formBlock($id, $visualAttrs . $classAttr, $data),
            default => self::markdownBlock($id, $class, $data, $block, $visualAttrs),
        };
    }



    private static function appendClass(string $classAttr, string $extraClasses): string
    {
        $extraClasses = trim($extraClasses);
        if ($extraClasses === '') {
            return $classAttr;
        }
        if (preg_match('/ class="([^"]*)"/', $classAttr, $match) !== 1) {
            return $classAttr . ' class="' . self::esc($extraClasses) . '"';
        }
        $classes = preg_split('/\s+/', trim($match[1] . ' ' . $extraClasses)) ?: [];
        $classes = array_values(array_unique(array_filter($classes, static fn (string $class): bool => $class !== '')));
        return preg_replace('/ class="[^"]*"/', ' class="' . self::esc(implode(' ', $classes)) . '"', $classAttr, 1) ?? $classAttr;
    }

    private static function visualBlockAttrs(array $block): string
    {
        if (!self::$visualEditing) {
            return '';
        }
        $id = self::esc((string) ($block['id'] ?? ''));
        if ($id === '') {
            return '';
        }
        $type = self::esc((string) ($block['type'] ?? 'markdown'));
        $label = self::esc((string) ($block['label'] ?? $type));
        $visualStatus = self::esc((string) ($block['_visual_editorial_status'] ?? self::visualStatusFromEditorial((string) ($block['editorial_status'] ?? 'published'))));
        $visualLabel = self::esc((string) ($block['_visual_status_label'] ?? self::visualStatusLabel($visualStatus)));
        $statusAttrs = ($visualStatus !== '' && $visualStatus !== 'none' && $visualStatus !== 'published') ? ' data-amcms-editorial-status="' . $visualStatus . '" data-amcms-editorial-label="' . $visualLabel . '"' : '';
        return ' data-amcms-block="1" data-amcms-block-id="' . $id . '" data-amcms-block-type="' . $type . '"' . $statusAttrs . ' data-amcms-label="' . $label . '"';
    }


    private static function visualStatusFromEditorial(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'draft', 'brouillon' => 'draft',
            'review', 'relecture' => 'review',
            'ready', 'ready_to_publish', 'ready-to-publish', 'validated', 'approved' => 'ready',
            'archived', 'archive' => 'archived',
            default => 'none',
        };
    }

    private static function visualStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'BLOC :: BROUILLON',
            'review' => 'BLOC :: EN RELECTURE',
            'ready' => 'BLOC :: PRÊT À PUBLIER',
            'archived' => 'BLOC :: ARCHIVÉ',
            default => '',
        };
    }

    private static function markdownBlock(string $id, string $class, array $data, array $block, string $visualAttrs = ''): string
    {
        $classAttr = ' class="' . self::esc(trim($class . ' prose-block')) . '"';
        $text = (string) ($data['text'] ?? $data['markdown'] ?? $block['text'] ?? '');
        return '<section' . $id . $visualAttrs . $classAttr . '><div>' . MarkdownRenderer::toHtml($text) . '</div></section>';
    }

    private static function imageBlock(string $id, string $classAttr, array $data): string
    {
        $src = (string) ($data['src'] ?? '');
        if ($src === '' || !self::policy()->isUrlAllowed($src, true)) {
            return '';
        }
        $caption = (string) ($data['caption'] ?? '');
        $attrs = ' src="' . self::esc($src) . '"';
        if (($data['srcset'] ?? '') !== '') { $attrs .= ' srcset="' . self::esc((string) $data['srcset']) . '"'; }
        if (($data['sizes'] ?? '') !== '') { $attrs .= ' sizes="' . self::esc((string) $data['sizes']) . '"'; }
        if ((int) ($data['width'] ?? 0) > 0) { $attrs .= ' width="' . self::esc((string) (int) $data['width']) . '"'; }
        if ((int) ($data['height'] ?? 0) > 0) { $attrs .= ' height="' . self::esc((string) (int) $data['height']) . '"'; }
        $attrs .= ' alt="' . self::esc((string) ($data['alt'] ?? '')) . '" loading="' . self::esc((string) ($data['loading'] ?? 'lazy')) . '"';
        return '<figure' . $id . $classAttr . '><img class="figure-img img-fluid rounded-4"' . $attrs . '>' . ($caption !== '' ? '<figcaption class="figure-caption">' . self::esc($caption) . '</figcaption>' : '') . '</figure>';
    }

    private static function mediaBlock(string $tag, string $id, string $classAttr, array $data): string
    {
        $src = (string) ($data['src'] ?? '');
        if ($src === '') {
            return '';
        }
        $title = (string) ($data['title'] ?? '');
        $caption = (string) ($data['caption'] ?? '');
        $posterUrl = (string) ($data['poster'] ?? $data['poster_src'] ?? '');
        $poster = $tag === 'video' && $posterUrl !== '' ? ' poster="' . self::esc($posterUrl) . '"' : '';
        $player = $tag === 'video' ? '<video class="w-100 rounded-4" controls preload="metadata"' . $poster . '><source src="' . self::esc($src) . '"></video>' : '<audio class="w-100" controls preload="metadata"><source src="' . self::esc($src) . '"></audio>';
        return '<figure' . $id . $classAttr . '>' . ($title !== '' ? '<h2 class="h4">' . self::esc($title) . '</h2>' : '') . $player . ($caption !== '' ? '<figcaption class="figure-caption">' . self::esc($caption) . '</figcaption>' : '') . '</figure>';
    }

    private static function iframeBlock(string $id, string $classAttr, array $data): string
    {
        $src = (string) ($data['src'] ?? $data['url'] ?? '');
        if ($src === '' || !self::policy()->isIframeSrcAllowed($src)) {
            return '';
        }
        $title = self::esc((string) ($data['title'] ?? 'Contenu intégré'));
        $height = max(160, min(1200, (int) ($data['height'] ?? 420)));
        $allow = self::esc((string) ($data['allow'] ?? 'fullscreen; picture-in-picture'));
        $sandbox = self::esc((string) ($data['sandbox'] ?? 'allow-scripts allow-same-origin allow-presentation'));
        return '<section' . $id . $classAttr . '><div class="ratio ratio-16x9" style="min-height:' . $height . 'px"><iframe src="' . self::esc($src) . '" title="' . $title . '" loading="lazy" allow="' . $allow . '" sandbox="' . $sandbox . '" allowfullscreen></iframe></div></section>';
    }


    private static function embedBlock(string $id, string $classAttr, array $data): string
    {
        $url = (string) ($data['url'] ?? $data['src'] ?? '');
        if ($url === '' || !self::policy()->isUrlAllowed($url)) { return ''; }
        $title = self::esc((string) ($data['title'] ?? 'Contenu externe'));
        return '<section' . $id . $classAttr . '><p><a href="' . self::esc($url) . '" rel="noopener noreferrer">' . $title . '</a></p></section>';
    }

    private static function policy(): EditorialBlockSecurityPolicy
    {
        return EditorialBlockSecurityPolicy::defaults();
    }

    private static function heroBlock(string $id, string $classAttr, array $data): string
    {
        $title = self::esc((string) ($data['title'] ?? $data['heading'] ?? ''));
        $text = self::esc((string) ($data['lead'] ?? $data['text'] ?? $data['subtitle'] ?? ''));
        $image = (string) ($data['image_src'] ?? $data['image'] ?? $data['src'] ?? '');
        $layout = (string) ($data['layout'] ?? 'simple');
        $style = $layout === 'cover' && $image !== '' ? ' style="background-image:linear-gradient(90deg,rgba(15,23,42,.72),rgba(15,23,42,.15)),url(' . self::esc($image) . ')"' : '';
        $media = '';
        if ($layout !== 'cover' && $image !== '') {
            $attrs = ' src="' . self::esc($image) . '"';
            if (($data['image_srcset'] ?? '') !== '') { $attrs .= ' srcset="' . self::esc((string) $data['image_srcset']) . '"'; }
            if (($data['image_sizes'] ?? '') !== '') { $attrs .= ' sizes="' . self::esc((string) $data['image_sizes']) . '"'; }
            if ((int) ($data['image_width'] ?? 0) > 0) { $attrs .= ' width="' . self::esc((string) (int) $data['image_width']) . '"'; }
            if ((int) ($data['image_height'] ?? 0) > 0) { $attrs .= ' height="' . self::esc((string) (int) $data['image_height']) . '"'; }
            $attrs .= ' alt="' . self::esc((string) ($data['image_alt'] ?? '')) . '" loading="' . self::esc((string) ($data['image_loading'] ?? 'eager')) . '"';
            $media = '<picture class="block-hero__media"><img' . $attrs . '></picture>';
        }
        $heading = ((string) ($data['heading_level'] ?? 'h2')) === 'h1' ? 'h1' : 'h2';
        $buttons = self::buttonLinks(is_array($data['buttons'] ?? null) ? $data['buttons'] : []);
        return '<section' . $id . $classAttr . $style . '><div class="hero">' . ((string) ($data['eyebrow'] ?? '') !== '' ? '<p class="eyebrow">' . self::esc((string) $data['eyebrow']) . '</p>' : '') . '<' . $heading . '>' . $title . '</' . $heading . '>' . ($text !== '' ? '<p>' . $text . '</p>' : '') . ($buttons !== '' ? '<div class="actions">' . $buttons . '</div>' : '') . '</div>' . $media . '</section>';
    }

    private static function galleryBlock(string $id, string $classAttr, array $data): string
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($items === []) { return ''; }
        $columns = max(1, min(4, (int) ($data['columns'] ?? 3)));
        $html = '<section' . $id . $classAttr . ' data-gallery-columns="' . $columns . '">';
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $src = (string) ($item['src'] ?? '');
            if ($src === '') { continue; }
            $attrs = ' src="' . self::esc($src) . '"';
            if (($item['srcset'] ?? '') !== '') { $attrs .= ' srcset="' . self::esc((string) $item['srcset']) . '"'; }
            if (($item['sizes'] ?? '') !== '') { $attrs .= ' sizes="' . self::esc((string) $item['sizes']) . '"'; }
            if ((int) ($item['width'] ?? 0) > 0) { $attrs .= ' width="' . self::esc((string) (int) $item['width']) . '"'; }
            if ((int) ($item['height'] ?? 0) > 0) { $attrs .= ' height="' . self::esc((string) (int) $item['height']) . '"'; }
            $attrs .= ' alt="' . self::esc((string) ($item['alt'] ?? '')) . '" loading="lazy"';
            $caption = (string) ($item['caption'] ?? '');
            $html .= '<figure><img' . $attrs . '>' . ($caption !== '' ? '<figcaption>' . self::esc($caption) . '</figcaption>' : '') . '</figure>';
        }
        return $html . '</section>';
    }

    private static function buttonsBlock(string $id, string $classAttr, array $data): string
    {
        $links = self::buttonLinks(is_array($data['items'] ?? null) ? $data['items'] : []);
        return $links === '' ? '' : '<nav' . $id . $classAttr . ' aria-label="Actions">' . $links . '</nav>';
    }

    private static function cardBlock(string $id, string $classAttr, array $data, string $blockId = ''): string
    {
        $title = self::esc((string) ($data['title'] ?? ''));
        $text = trim((string) ($data['text'] ?? ''));
        $icon = self::esc((string) ($data['icon'] ?? ''));
        $iconSrc = self::esc((string) ($data['icon_src'] ?? ''));
        $iconAlt = self::esc((string) ($data['icon_alt'] ?? ''));
        $url = self::esc((string) ($data['url'] ?? ''));
        $linkLabel = self::esc((string) ($data['link_label'] ?? ''));
        $style = preg_replace('/[^a-z0-9_-]/i', '', (string) ($data['style'] ?? 'default')) ?: 'default';
        $style = match ($style) {
            'feature', 'featured', 'force', 'strong', 'card' => 'feature',
            'compact' => 'compact',
            default => 'default',
        };
        if ($title === '' && $text === '') { return ''; }
        $editableTitle = self::$visualEditing ? ' data-amcms-editable="1" data-amcms-kind="block" data-amcms-block-id="' . self::esc($blockId) . '" data-amcms-field-path="data.title" data-amcms-editor="inline_text" data-amcms-label="Titre"' : '';
        $editableText = self::$visualEditing ? ' data-amcms-editable="1" data-amcms-kind="block" data-amcms-block-id="' . self::esc($blockId) . '" data-amcms-field-path="data.text" data-amcms-editor="inline_textarea" data-amcms-label="Texte"' : '';
        $classAttr = self::appendClass($classAttr, 'block-card block-card--' . $style);
        $html = '<article' . $id . $classAttr . ' data-card-style="' . self::esc($style) . '">';
        if ($iconSrc !== '') { $html .= '<img class="feature-card__icon feature-card__icon--media" src="' . $iconSrc . '" alt="' . $iconAlt . '" loading="lazy">'; }
        elseif ($icon !== '') { $html .= '<span class="feature-card__icon" aria-hidden="true">' . $icon . '</span>'; }
        if ($title !== '') { $html .= '<h2' . $editableTitle . '>' . $title . '</h2>'; }
        if ($text !== '') { $html .= '<div class="block-card__text"' . $editableText . '>' . MarkdownRenderer::toHtml($text) . '</div>'; }
        if ($url !== '' && $linkLabel !== '') { $html .= '<a class="block-card__link" href="' . $url . '">' . $linkLabel . '</a>'; }
        return $html . '</article>';
    }

    private static function columnsBlock(string $id, string $classAttr, array $data): string
    {
        $columns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
        if ($columns === []) { return ''; }
        $layout = self::esc((string) ($data['layout'] ?? '2'));
        $html = '<section' . $id . $classAttr . ' data-columns-layout="' . $layout . '">';
        foreach ($columns as $column) {
            if (!is_array($column)) { continue; }
            $width = self::esc((string) ($column['width'] ?? 'auto'));
            $html .= '<div class="block-column" data-column-width="' . $width . '">';
            foreach (is_array($column['blocks'] ?? null) ? $column['blocks'] : [] as $child) {
                if (is_array($child)) { $html .= self::block($child); }
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    private static function buttonLinks(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $label = (string) ($item['label'] ?? '');
            $url = (string) ($item['url'] ?? '');
            if ($label === '' || $url === '') { continue; }
            $style = preg_replace('/[^a-z0-9_-]/i', '', (string) ($item['style'] ?? 'primary')) ?: 'primary';
            $target = (string) ($item['target'] ?? '_self');
            $html .= '<a class="btn btn--' . self::esc($style) . '" href="' . self::esc($url) . '"' . ($target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . self::esc($label) . '</a>';
        }
        return $html;
    }

    private static function formBlock(string $id, string $classAttr, array $data): string
    {
        $key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($data['form_key'] ?? ''))) ?? '';
        if ($key === '') { return ''; }
        $title = self::esc((string) ($data['title'] ?? ''));
        $intro = self::esc((string) ($data['intro'] ?? ''));
        $html = '<section' . $id . $classAttr . ' data-form-block="' . self::esc($key) . '" data-form-started="' . time() . '">';
        if ($title !== '') { $html .= '<h2>' . $title . '</h2>'; }
        if ($intro !== '') { $html .= '<p>' . $intro . '</p>'; }
        return $html . '<div class="form-block__mount" role="status">Chargement du formulaire…</div></section>';
    }

    private static function headingBlock(string $id, string $classAttr, array $data): string
    {
        $level = (int) ($data['level'] ?? 2);
        $level = in_array($level, [2, 3, 4], true) ? $level : 2;
        return '<h' . $level . $id . $classAttr . '>' . self::esc((string) ($data['text'] ?? '')) . '</h' . $level . '>';
    }

    private static function archiveItem(array $item): string
    {
        $summary = (string) ($item['summary'] ?? '');
        $titleHtml = array_key_exists('title_html', $item) ? (string) $item['title_html'] : self::esc((string) ($item['title'] ?? ''));
        $summaryHtml = array_key_exists('summary_html', $item) ? (string) $item['summary_html'] : self::esc($summary);
        $taxonomies = trim((string) ($item['taxonomy_labels'] ?? ''));
        $taxonomyHtml = $taxonomies !== '' ? '<p class="archive-item__taxonomies">' . self::esc($taxonomies) . '</p>' : '';
        return '<article class="archive-item"><div class="meta">' . self::esc((string) ($item['type_key'] ?? '')) . '</div><h2><a href="' . self::esc((string) ($item['path'] ?? '#')) . '">' . $titleHtml . '</a></h2>' . ($summary !== '' ? '<p>' . $summaryHtml . '</p>' : '') . $taxonomyHtml . '</article>';
    }

    private static function breadcrumbs(array $data): string
    {
        $crumbs = is_array($data['breadcrumbs'] ?? null) ? $data['breadcrumbs'] : [];
        if (count($crumbs) <= 1) {
            return '';
        }
        $ui = self::ui($data);
        $html = '<nav class="breadcrumbs" aria-label="' . self::esc($ui['breadcrumbs'] ?? 'Fil d’Ariane') . '"><ol>';
        $last = count($crumbs) - 1;
        foreach ($crumbs as $idx => $crumb) {
            if (!is_array($crumb)) {
                continue;
            }
            $label = self::esc((string) ($crumb['label'] ?? ''));
            $url = (string) ($crumb['url'] ?? '');
            $html .= '<li>' . ($url !== '' && $idx !== $last ? '<a href="' . self::esc($url) . '">' . $label . '</a>' : '<span aria-current="page">' . $label . '</span>') . '</li>';
        }
        return $html . '</ol></nav>';
    }

    private static function navigation(array $data): string
    {
        $items = is_array($data['menu_items'] ?? null) ? $data['menu_items'] : [];
        $html = '<ul class="navbar-nav ms-lg-auto align-items-lg-center" role="list">';
        foreach ($items as $item) {
            if (is_array($item)) {
                $html .= self::navigationItem($item, 0);
            }
        }
        return $html . '</ul>';
    }

    private static function navigationItem(array $item, int $level): string
    {
        $children = is_array($item['children'] ?? null) ? array_values(array_filter($item['children'], 'is_array')) : [];
        $label = self::esc((string) ($item['label'] ?? ''));
        if ($label === '') {
            return '';
        }
        $url = self::esc((string) ($item['url'] ?? '#'));
        $target = (string) ($item['target'] ?? '_self');
        $targetAttr = $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $title = trim((string) ($item['title_attr'] ?? ''));
        $titleAttr = $title !== '' ? ' title="' . self::esc($title) . '"' : '';
        $css = trim((string) ($item['css_class'] ?? ''));
        $id = 'native-nav-item-' . self::esc((string) ($item['id'] ?? md5($label . $url . (string) $level)));
        $currentAttr = !empty($item['is_current']) ? ' aria-current="page"' : '';
        $activeClass = (!empty($item['is_current']) || !empty($item['is_ancestor'])) ? ' active' : '';
        if ($children === []) {
            $linkClass = $level === 0 ? 'nav-link' : 'dropdown-item';
            $extra = $css !== '' ? ' ' . self::esc($css) : '';
            return '<li class="' . ($level === 0 ? 'nav-item' : 'dropdown-item-wrap') . '"><a class="' . $linkClass . $extra . $activeClass . '" href="' . $url . '"' . $currentAttr . $targetAttr . $titleAttr . '>' . $label . '</a></li>';
        }
        $extra = $css !== '' ? ' ' . self::esc($css) : '';
        $openLabel = 'Ouvrir le sous-menu ';
        $html = '<li class="nav-item dropdown nav-item--has-children"><div class="nav-parent-control"><a class="nav-link nav-parent-link' . $extra . $activeClass . '" href="' . $url . '" id="' . $id . '" aria-haspopup="true" aria-expanded="false"' . $currentAttr . $targetAttr . $titleAttr . '>' . $label . '</a>';
        $html .= '<button class="nav-submenu-toggle dropdown-toggle" type="button" data-submenu-toggle aria-controls="' . $id . '-menu" aria-expanded="false" aria-label="' . self::esc($openLabel) . $label . '"></button></div>';
        $html .= '<ul class="dropdown-menu" id="' . $id . '-menu" aria-labelledby="' . $id . '">';
        foreach ($children as $child) {
            $grandchildren = is_array($child['children'] ?? null) ? array_values(array_filter($child['children'], 'is_array')) : [];
            if ($grandchildren !== []) {
                $childLabel = self::esc((string) ($child['label'] ?? ''));
                if ($childLabel !== '') {
                    $html .= '<li><span class="dropdown-header">' . $childLabel . '</span></li>';
                }
                foreach ($grandchildren as $grandchild) {
                    $html .= self::navigationDropdownLink($grandchild);
                }
            } else {
                $html .= self::navigationDropdownLink($child);
            }
        }
        return $html . '</ul></li>';
    }

    private static function navigationDropdownLink(array $item): string
    {
        $label = self::esc((string) ($item['label'] ?? ''));
        if ($label === '') { return ''; }
        $css = trim((string) ($item['css_class'] ?? ''));
        $isHeader = (string) ($item['url'] ?? '#') === '#' && str_contains($css, 'dropdown-header');
        if ($isHeader) {
            return '<li><span class="dropdown-header">' . $label . '</span></li>';
        }
        $url = self::esc((string) ($item['url'] ?? '#'));
        $target = (string) ($item['target'] ?? '_self');
        $targetAttr = $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $title = trim((string) ($item['title_attr'] ?? ''));
        $titleAttr = $title !== '' ? ' title="' . self::esc($title) . '"' : '';
        $extra = $css !== '' ? ' ' . self::esc(str_replace('dropdown-header', '', $css)) : '';
        $activeClass = !empty($item['is_current']) ? ' active' : '';
        $currentAttr = !empty($item['is_current']) ? ' aria-current="page"' : '';
        return '<li><a class="dropdown-item' . $extra . $activeClass . '" href="' . $url . '"' . $currentAttr . $targetAttr . $titleAttr . '>' . $label . '</a></li>';
    }

    private static function languageSwitch(array $data): string
    {
        $items = is_array($data['language_menu_items'] ?? null) ? $data['language_menu_items'] : (is_array($data['languages'] ?? null) ? $data['languages'] : []);
        $showLoginShortcut = !empty($data['show_login_shortcut']);
        if ($items === [] && !$showLoginShortcut) {
            return '';
        }

        $ui = self::ui($data);
        $loginIcon = '<svg class="login-shortcut__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/></svg>';
        $loginUrl = self::esc((string) ($data['admin_login_url'] ?? url_path('/admin/login')));
        $html = '<div class="header-actions header-actions--desktop d-none d-lg-flex align-items-center gap-2 ms-lg-3">';

        if ($items !== []) {
            $html .= '<div class="dropdown language-switch language-switch--desktop"><button class="btn btn-outline-primary dropdown-toggle language-switch__button" type="button" data-bs-toggle="dropdown" aria-expanded="false">' . self::esc($ui['language_menu'] ?? 'Langue') . '</button><ul class="dropdown-menu dropdown-menu-end language-switch__menu">';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $active = !empty($item['is_active']) ? ' active' : '';
                $html .= '<li><a class="dropdown-item' . $active . '" href="' . self::esc((string) ($item['url'] ?? '#')) . '" hreflang="' . self::esc((string) ($item['hreflang'] ?? $item['code'] ?? '')) . '"' . (!empty($item['is_active']) ? ' aria-current="true"' : '') . '>' . self::esc((string) ($item['label'] ?? $item['code'] ?? '')) . '</a></li>';
            }
            $html .= '</ul></div>';
        }

        if ($showLoginShortcut) {
            $html .= '<a class="login-shortcut login-shortcut--desktop" href="' . $loginUrl . '" aria-label="Connexion" title="Connexion">' . $loginIcon . '</a>';
        }
        $html .= '</div>';

        $html .= '<div class="header-actions header-actions--mobile d-lg-none">';
        if ($items !== []) {
            $html .= '<nav class="language-switch-mobile" aria-label="' . self::esc($ui['language_menu'] ?? 'Langue') . '">';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $active = !empty($item['is_active']) ? ' active' : '';
                $html .= '<a class="language-switch-mobile__link' . $active . '" href="' . self::esc((string) ($item['url'] ?? '#')) . '" hreflang="' . self::esc((string) ($item['hreflang'] ?? $item['code'] ?? '')) . '"' . (!empty($item['is_active']) ? ' aria-current="true"' : '') . '>' . self::esc((string) ($item['label'] ?? $item['code'] ?? '')) . '</a>';
            }
            $html .= '</nav>';
        }

        if ($showLoginShortcut) {
            $html .= '<a class="login-shortcut login-shortcut--mobile" href="' . $loginUrl . '" aria-label="Connexion" title="Connexion">' . $loginIcon . '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function footer(array $data): string
    {
        $ui = self::ui($data);
        $siteTitle = self::esc((string) ($data['site_localization']['site_title'] ?? $data['site']['name'] ?? $data['app_name'] ?? 'DEC CMS'));
        $footerText = self::esc((string) ($data['site_localization']['footer_text'] ?? 'CMS éditorial SEO-first, runtime léger.'));
        $top = is_array($data['footer_top_menu_items'] ?? null) ? $data['footer_top_menu_items'] : [];
        $bottom = is_array($data['footer_bottom_menu_items'] ?? null) ? $data['footer_bottom_menu_items'] : [];
        $all = is_array($data['footer_menu_items'] ?? null) ? $data['footer_menu_items'] : [];
        if ($top === [] && $bottom === [] && $all !== []) {
            $top = array_slice($all, 0, 3);
            $bottom = array_slice($all, 3);
        }
        $topHtml = self::footerLinks($top);
        $bottomHtml = self::footerLinks($bottom);
        $manageCookies = '<button type="button" class="footer-cookie-link" data-cookie-consent-manage>' . self::esc($ui['cookies'] ?? 'Cookies') . '</button>';
        return '<footer class="site-footer"><div class="container site-footer__inner"><div><strong>' . $siteTitle . '</strong><p>' . $footerText . '</p></div><nav class="footer-links" aria-label="' . self::esc($ui['footer_navigation'] ?? 'Navigation de pied de page') . '"><div class="footer-links__row footer-links__row--top">' . $topHtml . ($topHtml !== '' ? $manageCookies : '') . '</div><div class="footer-links__row footer-links__row--bottom">' . $bottomHtml . '</div></nav></div></footer>';
    }

    private static function footerLinks(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') { continue; }
            $target = (string) ($item['target'] ?? '_self');
            $targetAttr = $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
            $title = trim((string) ($item['title_attr'] ?? ''));
            $titleAttr = $title !== '' ? ' title="' . self::esc($title) . '"' : '';
            $css = trim((string) ($item['css_class'] ?? ''));
            $classAttr = $css !== '' ? ' class="' . self::esc($css) . '"' : '';
            $html .= '<a' . $classAttr . ' href="' . self::esc($url) . '"' . $targetAttr . $titleAttr . '>' . self::esc($label) . '</a>';
        }
        return $html;
    }

    private static function share(array $data): string
    {
        $canonical = (string) ($data['canonical'] ?? '');
        if ($canonical === '') {
            return '';
        }
        $title = (string) ($data['entry_title'] ?? $data['title'] ?? '');
        $encodedUrl = rawurlencode($canonical);
        $encodedTitle = rawurlencode($title);
        $attrUrl = self::esc($canonical);

        return '<nav class="social-share" aria-label="Partager cette page">'
            . '<span class="social-share__label" aria-label="Partager"><svg xmlns="http://www.w3.org/2000/svg" class="social-share__icon bi bi-share" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M13.5 1a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM11 2.5a2.5 2.5 0 1 1 .603 1.628l-6.718 3.12a2.499 2.499 0 0 1 0 1.504l6.718 3.12a2.5 2.5 0 1 1-.488.876l-6.718-3.12a2.5 2.5 0 1 1 0-3.256l6.718-3.12A2.5 2.5 0 0 1 11 2.5zm-8.5 4a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm11 5.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/></svg></span>'
            . '<a class="social-share__action social-share__action--linkedin" href="https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl . '" rel="noopener noreferrer" target="_blank" aria-label="Partager sur LinkedIn" title="LinkedIn"><svg xmlns="http://www.w3.org/2000/svg" class="social-share__icon bi bi-linkedin" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z"/></svg><span class="visually-hidden">LinkedIn</span></a>'
            . '<a class="social-share__action social-share__action--facebook" href="https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl . '" rel="noopener noreferrer" target="_blank" aria-label="Partager sur Facebook" title="Facebook"><svg xmlns="http://www.w3.org/2000/svg" class="social-share__icon bi bi-facebook" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M16 8.049C16 3.603 12.418 0 8 0S0 3.603 0 8.049c0 4.016 2.926 7.347 6.75 7.951v-5.625H4.718V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.875 0 1.79.157 1.79.157v1.98h-1.009c-.994 0-1.303.621-1.303 1.258V8.05h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.935 6.75-7.951z"/></svg><span class="visually-hidden">Facebook</span></a>'
            . '<a class="social-share__action social-share__action--x" href="https://twitter.com/intent/tweet?url=' . $encodedUrl . '&text=' . $encodedTitle . '" rel="noopener noreferrer" target="_blank" aria-label="Partager sur X" title="X"><svg xmlns="http://www.w3.org/2000/svg" class="social-share__icon bi bi-twitter-x" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z"/></svg><span class="visually-hidden">X</span></a>'
            . '<a class="social-share__action social-share__action--email" href="mailto:?subject=' . $encodedTitle . '&body=' . $encodedUrl . '" aria-label="Partager par email" title="Email"><svg xmlns="http://www.w3.org/2000/svg" class="social-share__icon bi bi-envelope" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/></svg><span class="visually-hidden">Email</span></a>'
            . '<button class="social-share__action social-share__copy" type="button" data-copy-url="' . $attrUrl . '" aria-label="Copier l’URL dans le presse-papier" title="Copier l’URL"><svg xmlns="http://www.w3.org/2000/svg" class="social-share__icon bi bi-copy" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/></svg><span class="visually-hidden">Copier l’URL</span></button>'
            . '<span class="social-share__status visually-hidden" aria-live="polite"></span>'
            . '</nav>';
    }

    private static function jsonLdScript(string $jsonLd): string
    {
        $jsonLd = trim($jsonLd);
        if ($jsonLd === '') {
            return '';
        }
        json_decode($jsonLd, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }
        return '<script type="application/ld+json">' . "\n" . $jsonLd . "\n" . '</script>';
    }

    private static function alternateLinks(array $data): string
    {
        $html = '';
        foreach (($data['languages'] ?? []) as $language) {
            if (!is_array($language)) {
                continue;
            }
            $href = (string) ($language['absolute_url'] ?? $language['url'] ?? '');
            if ($href === '') {
                continue;
            }
            $hreflang = (string) ($language['hreflang'] ?? $language['code'] ?? '');
            if ($hreflang === '') {
                continue;
            }
            $html .= '<link rel="alternate" hreflang="' . self::esc($hreflang) . '" href="' . self::esc($href) . '">' . "\n  ";
        }
        $xDefault = (string) ($data['x_default_url'] ?? '');
        if ($xDefault !== '') {
            $html .= '<link rel="alternate" hreflang="x-default" href="' . self::esc($xDefault) . '">' . "\n  ";
        }
        return trim($html);
    }

    /** @return array<string,string> */
    private static function ui(array $data): array
    {
        $ui = is_array($data['ui'] ?? null) ? $data['ui'] : [];
        $defaults = [
            'skip_to_content' => 'Aller au contenu',
            'main_navigation' => 'Navigation principale',
            'footer_navigation' => 'Navigation de pied de page',
            'home' => 'Accueil',
            'menu' => 'Menu',
            'search' => 'Recherche',
            'search_title' => 'Recherche',
            'search_placeholder' => 'Rechercher',
            'search_results' => 'Résultats',
            'search_hint' => 'Saisissez une recherche.',
            'no_results' => 'Aucun résultat.',
            'taxonomy_archive' => 'Archive',
            'empty_archive' => 'Aucun contenu.',
            'preview' => 'Prévisualisation',
            'language_menu' => 'Langue',
            'breadcrumbs' => 'Fil d’Ariane',
            'legal' => 'Mentions légales',
            'privacy' => 'Confidentialité',
            'cookies' => 'Cookies',
            'contact' => 'Contact',
            'sitemap' => 'Sitemap',
            'gone_title' => 'Page supprimée',
            'gone_text' => 'Cette page n’est plus disponible.',
            'replacement' => 'Voir le remplacement',
            'back_home' => 'Retour à l’accueil',
            'not_found_title' => 'Page introuvable',
            'not_found_text' => 'La page demandée est introuvable.',
        ];
        return array_map('strval', $ui + $defaults);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
