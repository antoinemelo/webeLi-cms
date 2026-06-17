<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Schema\NativeFieldBlueprintRegistry;
use App\Security\EditorialBlockSecurityPolicy;

/**
 * Contrat canonique des blocs natifs éditoriaux.
 *
 * Un bloc normalisé a toujours la forme :
 * { id, type, enabled, editorial_status, label, css_class, anchor, data, sort_order }.
 * Les seuls types natifs supportés sont exposés par SUPPORTED_TYPES.
 */
final class BlockDocumentNormalizer
{
    public function __construct(private readonly ?EditorialBlockSecurityPolicy $security = null) {}
    /** @var list<string> */
    public const SUPPORTED_TYPES = ['markdown', 'richtext', 'html_safe', 'html_raw', 'iframe', 'embed', 'image', 'video', 'audio', 'hero', 'gallery', 'buttons', 'card', 'columns', 'form', 'plan', 'articles'];

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return self::SUPPORTED_TYPES;
    }

    /** @return array<string,array<string,mixed>> */
    public static function schemas(): array
    {
        return NativeFieldBlueprintRegistry::blockSchemas();
    }

    /** @param mixed $rawBlocks @return list<array<string,mixed>> */
    public function normalize(mixed $rawBlocks, bool $preserveEmpty = true): array
    {
        if (!is_array($rawBlocks)) {
            return [];
        }

        $blocks = [];
        foreach (array_values($rawBlocks) as $index => $rawBlock) {
            if (!is_array($rawBlock)) {
                continue;
            }
            $type = $this->blockType($rawBlock['type'] ?? $rawBlock['block_type'] ?? 'markdown');
            $data = is_array($rawBlock['data'] ?? null) ? $rawBlock['data'] : $rawBlock;
            $block = [
                'id' => $this->blockId($rawBlock['id'] ?? null, $index),
                'type' => $type,
                'enabled' => !array_key_exists('enabled', $rawBlock) || (bool) $rawBlock['enabled'],
                'editorial_status' => $this->editorialStatus($rawBlock['editorial_status'] ?? $rawBlock['workflow_status'] ?? null),
                'label' => trim((string) ($rawBlock['label'] ?? '')),
                'css_class' => $this->safeCss((string) ($rawBlock['css_class'] ?? $data['css_class'] ?? '')),
                'anchor' => $this->safeAnchor((string) ($rawBlock['anchor'] ?? $data['anchor'] ?? '')),
                'data' => $this->normalizeData($type, $data),
                'sort_order' => (int) ($rawBlock['sort_order'] ?? $index),
            ];
            if (!$preserveEmpty && $this->isEmptyBlock($block)) {
                continue;
            }
            $blocks[] = $block;
        }

        usort($blocks, fn(array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']));
        foreach ($blocks as $i => $block) {
            $blocks[$i]['sort_order'] = $i;
        }
        return array_values($blocks);
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    public function publicBlocks(array $blocks): array
    {
        $public = [];
        foreach ($blocks as $block) {
            if (!$this->isPublicBlock($block)) {
                continue;
            }
            $copy = $block;
            $copy['meta'] = ['blueprint' => (string) ($copy['type'] ?? ''), 'schema_version' => 1];
            $data = is_array($copy['data'] ?? null) ? $copy['data'] : [];
            if (($copy['type'] ?? '') === 'columns') {
                $columns = [];
                foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
                    if (!is_array($column)) { continue; }
                    $column['blocks'] = $this->publicBlocks(is_array($column['blocks'] ?? null) ? $column['blocks'] : []);
                    $columns[] = $column;
                }
                $data['columns'] = $columns;
                $copy['data'] = $data;
            }
            $public[] = $copy;
        }
        return array_values($public);
    }

    /** @param array<string,mixed> $block */
    public function isPublicBlock(array $block): bool
    {
        return ($block['enabled'] ?? true) !== false
            && (string) ($block['editorial_status'] ?? 'published') === 'published'
            && !$this->isEmptyBlock($block);
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    public function validateForPublication(array $blocks, bool $canManageHtmlRaw = false): array
    {
        return $this->validate($this->publicBlocks($blocks), $canManageHtmlRaw);
    }

    /** @param list<array<string,mixed>> $blocks @return list<string> */
    public function validate(array $blocks, bool $canManageHtmlRaw = false): array
    {
        $errors = [];
        $h1Count = 0;
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                $errors[] = sprintf('Bloc %d : objet JSON attendu.', $index + 1);
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $errors[] = sprintf('Bloc %d : type non supporté "%s".', $index + 1, $type);
                continue;
            }
            $prefix = sprintf('Bloc %s %d', $type, $index + 1);
            if (!in_array((string) ($block['editorial_status'] ?? 'published'), ['draft', 'review', 'published', 'archived'], true)) {
                $errors[] = $prefix . ' : état éditorial invalide.';
            }
            if ($type === 'markdown' && trim((string) ($data['text'] ?? '')) === '') {
                $errors[] = $prefix . ' : texte obligatoire.';
            }
            if (in_array($type, ['richtext', 'html_safe', 'html_raw'], true) && trim(strip_tags((string) ($data['html'] ?? ''))) === '' && trim((string) ($data['html'] ?? '')) === '') {
                $errors[] = $prefix . ' : contenu obligatoire.';
            }
            if ($type === 'html_raw') {
                foreach ($this->policy()->validateHtmlRawPublication($block, $canManageHtmlRaw) as $securityError) { $errors[] = $securityError; }
            }
            if ($type === 'buttons' && count(is_array($data['items'] ?? null) ? $data['items'] : []) < 1) {
                $errors[] = $prefix . ' : au moins un bouton valide est obligatoire.';
            }
            if ($type === 'card' && trim((string) ($data['title'] ?? '')) === '' && trim((string) ($data['text'] ?? '')) === '') {
                $errors[] = $prefix . ' : titre ou texte obligatoire.';
            }
            if ($type === 'gallery' && count(is_array($data['items'] ?? null) ? $data['items'] : []) < 1) {
                $errors[] = $prefix . ' : au moins une image valide est obligatoire.';
            }
            if ($type === 'columns') {
                $columnCount = count(is_array($data['columns'] ?? null) ? $data['columns'] : []);
                if ($columnCount < 1 || $columnCount > 4) {
                    $errors[] = $prefix . ' : le bloc colonnes doit contenir entre 1 et 4 colonnes.';
                }
                $hasVisibleChild = false;
                foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
                    foreach (is_array($column['blocks'] ?? null) ? $column['blocks'] : [] as $child) {
                        if (is_array($child) && $this->isPublicBlock($child)) { $hasVisibleChild = true; break 2; }
                    }
                }
                if (!$hasVisibleChild) {
                    $errors[] = $prefix . ' : au moins un sous-bloc publié est obligatoire.';
                }
                foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $columnIndex => $column) {
                    foreach (is_array($column['blocks'] ?? null) ? $column['blocks'] : [] as $child) {
                        if (is_array($child) && (string) ($child['type'] ?? '') === 'columns') {
                            $errors[] = sprintf('%s, colonne %d : les blocs colonnes imbriqués sont désactivés par défaut.', $prefix, $columnIndex + 1);
                        }
                    }
                    foreach ($this->validate(is_array($column['blocks'] ?? null) ? $column['blocks'] : [], $canManageHtmlRaw) as $nestedError) {
                        $errors[] = sprintf('%s, colonne %d : %s', $prefix, $columnIndex + 1, $nestedError);
                    }
                }
            }
            if ($type === 'image' && $this->mediaId($data['media_id'] ?? null) < 1 && trim((string) ($data['src'] ?? '')) === '') {
                $errors[] = $prefix . ' : média ou URL obligatoire.';
            }
            if (in_array($type, ['video', 'audio'], true) && $this->mediaId($data['media_id'] ?? null) < 1 && trim((string) ($data['src'] ?? '')) === '') {
                $errors[] = $prefix . ' : média ou URL obligatoire.';
            }
            if ($type === 'iframe') {
                if (trim((string) ($data['src'] ?? '')) === '') {
                    $errors[] = $prefix . ' : URL obligatoire.';
                } elseif (!$this->policy()->isIframeSrcAllowed((string) ($data['src'] ?? ''))) {
                    $errors[] = $prefix . ' : domaine iframe non autorisé.';
                }
            }
            if ($type === 'embed' && trim((string) ($data['url'] ?? '')) === '') {
                $errors[] = $prefix . ' : URL obligatoire.';
            }
            if ($type === 'form' && trim((string) ($data['form_key'] ?? '')) === '') {
                $errors[] = $prefix . ' : clé formulaire obligatoire.';
            }
            if ($type === 'hero' && trim((string) ($data['title'] ?? '')) !== '' && (string) ($data['heading_level'] ?? 'h2') === 'h1') {
                $h1Count++;
            }
        }
        if ($h1Count > 1) {
            $errors[] = 'SEO : un seul bloc hero peut déclarer un H1. Le titre principal de page reste la source H1 recommandée.';
        }
        return $errors;
    }

    /** @param list<array<string,mixed>> $blocks @return list<int> */
    public function mediaIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) { continue; }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach (['media_id', 'poster_media_id', 'image_media_id'] as $key) {
                $id = $this->mediaId($data[$key] ?? null);
                if ($id > 0) { $ids[] = $id; }
            }
            foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
                if (is_array($item)) {
                    $id = $this->mediaId($item['media_id'] ?? null);
                    if ($id > 0) { $ids[] = $id; }
                }
            }
            foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
                if (is_array($column)) {
                    array_push($ids, ...$this->mediaIds(is_array($column['blocks'] ?? null) ? $column['blocks'] : []));
                }
            }
        }
        return array_values(array_unique($ids));
    }

    public function plainText(array $document): string
    {
        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $parts = [(string) ($content['title'] ?? '')];
        foreach ($this->normalize($document['blocks'] ?? $content['blocks'] ?? []) as $block) {
            $parts[] = $this->blockText($block);
        }
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(implode(' ', array_filter($parts)))));
    }

    /** @param array<string,mixed> $block */
    private function blockText(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        return match ($type) {
            'markdown' => (string) ($data['text'] ?? ''),
            'richtext', 'html_safe', 'html_raw' => (string) ($data['html'] ?? ''),
            'hero' => implode(' ', [(string) ($data['eyebrow'] ?? ''), (string) ($data['title'] ?? ''), (string) ($data['lead'] ?? ''), $this->buttonsText($data['buttons'] ?? [])]),
            'image', 'video', 'audio', 'iframe', 'embed' => implode(' ', [(string) ($data['title'] ?? ''), (string) ($data['caption'] ?? ''), (string) ($data['alt'] ?? ''), (string) ($data['image_alt'] ?? '')]),
            'form' => implode(' ', [(string) ($data['title'] ?? ''), (string) ($data['intro'] ?? ''), (string) ($data['form_key'] ?? '')]),
            'gallery' => implode(' ', array_map(fn($i) => is_array($i) ? implode(' ', [(string)($i['alt'] ?? ''), (string)($i['caption'] ?? '')]) : '', $data['items'] ?? [])),
            'buttons' => $this->buttonsText($data['items'] ?? []),
            'card' => implode(' ', [(string) ($data['icon'] ?? ''), (string) ($data['icon_alt'] ?? ''), (string) ($data['title'] ?? ''), (string) ($data['text'] ?? ''), (string) ($data['link_label'] ?? '')]),
            'columns' => implode(' ', array_map(fn($c) => is_array($c) ? implode(' ', array_map(fn($b) => is_array($b) ? $this->blockText($b) : '', $c['blocks'] ?? [])) : '', $data['columns'] ?? [])),
            'plan' => (string) ($data['title'] ?? ''),
            'articles' => (string) ($data['title'] ?? ''),
            default => '',
        };
    }

    private function buttonsText(mixed $items): string
    {
        return implode(' ', array_map(fn($i) => is_array($i) ? (string)($i['label'] ?? '') : '', is_array($items) ? $items : []));
    }

    private function blockType(mixed $type): string
    {
        $type = strtolower(trim((string) $type));
        $aliases = ['rich_text' => 'richtext', 'html' => 'html_safe', 'safe_html' => 'html_safe', 'raw_html' => 'html_raw', 'button' => 'buttons'];
        $type = $aliases[$type] ?? $type;
        return in_array($type, self::SUPPORTED_TYPES, true) ? $type : 'markdown';
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalizeData(string $type, array $data): array
    {
        return match ($type) {
            'markdown' => ['text' => trim((string) ($data['text'] ?? $data['markdown'] ?? $data['body'] ?? ''))],
            'richtext' => ['html' => $this->policy()->sanitizeHtmlSafe((string) ($data['html'] ?? $data['text'] ?? ''))],
            'html_safe' => ['html' => $this->policy()->sanitizeHtmlSafe((string) ($data['html'] ?? $data['text'] ?? ''))],
            'html_raw' => ['html' => trim((string) ($data['html'] ?? $data['text'] ?? ''))],
            'image' => [
                'media_id' => $this->mediaId($data['media_id'] ?? $data['id'] ?? null),
                'src' => $this->safeUrl((string) ($data['src'] ?? $data['url'] ?? '')),
                'srcset' => trim((string) ($data['srcset'] ?? '')),
                'sizes' => trim((string) ($data['sizes'] ?? '')),
                'width' => max(0, min(4000, (int) ($data['width'] ?? 0))),
                'height' => max(0, min(4000, (int) ($data['height'] ?? 0))),
                'mime_type' => trim((string) ($data['mime_type'] ?? '')),
                'alt' => trim((string) ($data['alt'] ?? '')),
                'caption' => trim((string) ($data['caption'] ?? '')),
                'ratio' => $this->choice($data['ratio'] ?? '', ['auto','1x1','4x3','16x9','21x9'], 'auto'),
                'loading' => $this->choice($data['loading'] ?? '', ['lazy','eager'], 'lazy'),
            ],
            'video' => [
                'media_id' => $this->mediaId($data['media_id'] ?? null),
                'src' => $this->safeUrl((string) ($data['src'] ?? '')),
                'title' => trim((string) ($data['title'] ?? '')),
                'poster_media_id' => $this->mediaId($data['poster_media_id'] ?? null),
                'poster' => $this->safeUrl((string) ($data['poster'] ?? $data['poster_src'] ?? '')),
                'poster_srcset' => trim((string) ($data['poster_srcset'] ?? '')),
                'poster_sizes' => trim((string) ($data['poster_sizes'] ?? '')),
                'poster_width' => max(0, min(4000, (int) ($data['poster_width'] ?? 0))),
                'poster_height' => max(0, min(4000, (int) ($data['poster_height'] ?? 0))),
                'mime_type' => trim((string) ($data['mime_type'] ?? '')),
                'caption' => trim((string) ($data['caption'] ?? '')),
                'controls' => true,
            ],
            'audio' => [
                'media_id' => $this->mediaId($data['media_id'] ?? null),
                'src' => $this->safeUrl((string) ($data['src'] ?? '')),
                'title' => trim((string) ($data['title'] ?? '')),
                'mime_type' => trim((string) ($data['mime_type'] ?? '')),
                'caption' => trim((string) ($data['caption'] ?? '')),
                'controls' => true,
            ],
            'iframe' => [
                'src' => $this->safeUrl((string) ($data['src'] ?? $data['url'] ?? '')),
                'title' => trim((string) ($data['title'] ?? '')),
                'height' => max(160, min(1200, (int) ($data['height'] ?? 420))),
                'allow' => $this->safeIframeAllow((string) ($data['allow'] ?? 'fullscreen; picture-in-picture')),
                'sandbox' => 'allow-scripts allow-same-origin allow-presentation',
            ],
            'embed' => [
                'provider' => $this->choice($data['provider'] ?? '', ['youtube','vimeo','openstreetmap','generic'], 'generic'),
                'url' => $this->safeLinkUrl((string) ($data['url'] ?? $data['src'] ?? '')),
                'title' => trim((string) ($data['title'] ?? '')),
            ],
            'hero' => [
                'eyebrow' => trim((string) ($data['eyebrow'] ?? '')),
                'title' => trim((string) ($data['title'] ?? '')),
                'lead' => trim((string) ($data['lead'] ?? $data['text'] ?? '')),
                'heading_level' => $this->choice($data['heading_level'] ?? '', ['h1','h2'], 'h2'),
                'image_media_id' => $this->mediaId($data['image_media_id'] ?? $data['media_id'] ?? null),
                'image_src' => $this->safeUrl((string) ($data['image_src'] ?? $data['src'] ?? '')),
                'image_srcset' => trim((string) ($data['image_srcset'] ?? '')),
                'image_sizes' => trim((string) ($data['image_sizes'] ?? '')),
                'image_alt' => trim((string) ($data['image_alt'] ?? $data['alt'] ?? '')),
                'image_width' => max(1, min(4000, (int) ($data['image_width'] ?? 1280))),
                'image_height' => max(1, min(4000, (int) ($data['image_height'] ?? 853))),
                'image_mime_type' => trim((string) ($data['image_mime_type'] ?? '')),
                'image_loading' => $this->choice($data['image_loading'] ?? '', ['lazy','eager'], 'eager'),
                'layout' => $this->choice($data['layout'] ?? '', ['simple','split','cover'], 'simple'),
                'buttons' => $this->buttons($data['buttons'] ?? $data['items'] ?? []),
            ],
            'gallery' => ['items' => $this->galleryItems($data['items'] ?? []), 'columns' => max(1, min(4, (int) ($data['columns'] ?? 3)))],
            'buttons' => ['items' => $this->buttons($data['items'] ?? [])],
            'card' => ['icon_media_id' => max(0, (int) ($data['icon_media_id'] ?? 0)), 'icon_src' => $this->safeUrl((string) ($data['icon_src'] ?? '')), 'icon_alt' => trim((string) ($data['icon_alt'] ?? '')), 'icon' => trim((string) ($data['icon'] ?? '')), 'title' => trim((string) ($data['title'] ?? '')), 'text' => trim((string) ($data['text'] ?? '')), 'url' => $this->safeLinkUrl((string) ($data['url'] ?? '')), 'link_label' => trim((string) ($data['link_label'] ?? '')), 'style' => $this->cardStyle($data['style'] ?? 'default')],
            'columns' => ['layout' => $this->columnsLayout($data['layout'] ?? ''), 'gap' => $this->choice($data['gap'] ?? '', ['sm','md','lg'], 'md'), 'stack_on_mobile' => !array_key_exists('stack_on_mobile', $data) || $this->bool($data['stack_on_mobile']), 'columns' => $this->columns($data['columns'] ?? [])],
            'form' => ['form_key' => $this->safeKey((string) ($data['form_key'] ?? '')), 'title' => trim((string) ($data['title'] ?? '')), 'intro' => trim((string) ($data['intro'] ?? '')), 'layout' => $this->choice($data['layout'] ?? '', ['default','compact','card'], 'default')],
            'plan' => ['title' => trim((string) ($data['title'] ?? 'Plan')), 'source' => $this->choice($data['source'] ?? '', ['pages','articles','taxonomies'], 'pages'), 'taxonomy_key' => $this->safeOptionalKey((string) ($data['taxonomy_key'] ?? '')), 'limit' => max(1, min(50, (int) ($data['limit'] ?? 8))), 'show_pagination' => !array_key_exists('show_pagination', $data) || $this->bool($data['show_pagination']), 'page_param' => $this->safeOptionalKey((string) ($data['page_param'] ?? ''))],
            'articles' => ['title' => trim((string) ($data['title'] ?? 'Articles')), 'limit' => max(1, min(24, (int) ($data['limit'] ?? 3))), 'category' => $this->safeSlug((string) ($data['category'] ?? '')), 'tag' => $this->safeSlug((string) ($data['tag'] ?? '')), 'show_more_button' => !array_key_exists('show_more_button', $data) || $this->bool($data['show_more_button']), 'more_label' => trim((string) ($data['more_label'] ?? ''))],
            default => [],
        };
    }

    /** @param array<string,mixed> $block */
    private function isEmptyBlock(array $block): bool
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        return match ($type) {
            'markdown' => trim((string) ($data['text'] ?? '')) === '',
            'richtext', 'html_safe', 'html_raw' => trim(strip_tags((string) ($data['html'] ?? ''))) === '' && trim((string) ($data['html'] ?? '')) === '',
            'image' => $this->mediaId($data['media_id'] ?? null) < 1 && trim((string) ($data['src'] ?? '')) === '',
            'video', 'audio' => $this->mediaId($data['media_id'] ?? null) < 1 && trim((string) ($data['src'] ?? '')) === '',
            'iframe' => trim((string) ($data['src'] ?? '')) === '',
            'embed' => trim((string) ($data['url'] ?? '')) === '',
            'hero' => trim(implode('', [(string)($data['eyebrow'] ?? ''), (string)($data['title'] ?? ''), (string)($data['lead'] ?? '')])) === '' && $this->mediaId($data['image_media_id'] ?? null) < 1 && trim((string) ($data['image_src'] ?? '')) === '' && count($data['buttons'] ?? []) < 1,
            'gallery', 'buttons' => count(is_array($data['items'] ?? null) ? $data['items'] : []) < 1,
            'card' => trim((string) ($data['title'] ?? '')) === '' && trim((string) ($data['text'] ?? '')) === '',
            'columns' => count(array_filter(is_array($data['columns'] ?? null) ? $data['columns'] : [], fn($column): bool => is_array($column) && count(is_array($column['blocks'] ?? null) ? $column['blocks'] : []) > 0)) < 1,
            'form' => trim((string) ($data['form_key'] ?? '')) === '',
            'plan', 'articles' => false,
            default => true,
        };
    }

    /** @return list<array<string,string>> */
    private function buttons(mixed $items): array
    {
        $out = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) { continue; }
            $label = trim((string) ($item['label'] ?? ''));
            $url = $this->safeLinkUrl((string) ($item['url'] ?? $item['href'] ?? ''));
            if ($label === '' || $url === '') { continue; }
            $out[] = [
                'label' => $label,
                'url' => $url,
                'style' => $this->choice($item['style'] ?? '', ['primary','ghost','secondary','light','link'], 'primary'),
                'target' => !empty($item['blank']) || ($item['target'] ?? '') === '_blank' ? '_blank' : '_self',
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function galleryItems(mixed $items): array
    {
        $out = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) { continue; }
            $id = $this->mediaId($item['media_id'] ?? $item['id'] ?? null);
            $src = $this->safeUrl((string) ($item['src'] ?? $item['url'] ?? ''));
            if ($id < 1 && $src === '') { continue; }
            $out[] = ['media_id' => $id, 'src' => $src, 'srcset' => trim((string) ($item['srcset'] ?? '')), 'sizes' => trim((string) ($item['sizes'] ?? '')), 'width' => max(0, min(4000, (int) ($item['width'] ?? 0))), 'height' => max(0, min(4000, (int) ($item['height'] ?? 0))), 'mime_type' => trim((string) ($item['mime_type'] ?? '')), 'alt' => trim((string) ($item['alt'] ?? '')), 'caption' => trim((string) ($item['caption'] ?? ''))];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function columnsLayout(mixed $layout): string
    {
        $layout = (string) $layout;
        $aliases = [
            '2' => '2_equal',
            '3' => '3_equal',
            '4' => '4_equal',
            'cards-3' => 'cards_3',
            'sidebar-left' => '2_right_wide',
            'sidebar-right' => '2_left_wide',
        ];
        $layout = $aliases[$layout] ?? $layout;
        return $this->choice($layout, ['2_equal','2_left_wide','2_right_wide','3_equal','4_equal','cards_3'], '2_equal');
    }

    /** @return list<array<string,mixed>> */
    private function columns(mixed $columns): array
    {
        $out = [];
        foreach (array_slice(is_array($columns) ? $columns : [], 0, 4) as $column) {
            if (!is_array($column)) { continue; }
            $children = array_values(array_filter(
                $this->normalize($column['blocks'] ?? []),
                static fn(array $child): bool => (string) ($child['type'] ?? '') !== 'columns'
            ));
            $out[] = [
                'label' => trim((string) ($column['label'] ?? '')),
                'width' => $this->choice($column['width'] ?? '', ['auto','25','33','50','66','75','100'], 'auto'),
                'blocks' => $children,
            ];
        }
        return $out !== [] ? $out : [['label' => '', 'width' => 'auto', 'blocks' => []], ['label' => '', 'width' => 'auto', 'blocks' => []]];
    }

    private function blockId(mixed $id, int $index): string
    {
        $id = trim((string) $id);
        return preg_match('/^[a-zA-Z0-9_-]{6,64}$/', $id) ? $id : 'block_' . substr(hash('sha1', (string) microtime(true) . '_' . $index), 0, 12);
    }

    private function mediaId(mixed $value): int { return is_numeric($value) && (int) $value > 0 ? (int) $value : 0; }
    private function editorialStatus(mixed $value): string { return $this->choice($value ?? 'published', ['draft','review','published','archived'], 'published'); }
    /** @param list<string> $allowed */

    private function cardStyle(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'feature', 'featured', 'force', 'strong', 'card' => 'feature',
            'compact' => 'compact',
            default => 'default',
        };
    }

    private function choice(mixed $value, array $allowed, string $default): string { $value = (string) $value; return in_array($value, $allowed, true) ? $value : $default; }
    private function safeCss(string $css): string { return trim((string) preg_replace('/[^a-zA-Z0-9_\-\s:]/', '', $css)); }
    private function safeAnchor(string $anchor): string { return preg_match('/^[a-z][a-z0-9_-]{1,60}$/', $anchor) ? $anchor : ''; }
    private function safeKey(string $key): string { $key = strtolower(trim($key)); return preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $key) ? $key : ''; }
    private function safeOptionalKey(string $key): string { $key = strtolower(trim($key)); return $key === '' ? '' : (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $key) ? $key : ''); }
    private function safeSlug(string $slug): string { $slug = strtolower(trim($slug)); return $slug === '' ? '' : (preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $slug) ? $slug : ''); }
    private function bool(mixed $value): bool { return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value; }

    private function safeUrl(string $url): string
    {
        return $this->policy()->sanitizeUrl($url);
    }

    private function safeLinkUrl(string $url): string
    {
        return $this->policy()->sanitizeUrl($url);
    }

    private function safeIframeAllow(string $allow): string
    {
        $tokens = preg_split('/[;\s]+/', strtolower($allow)) ?: [];
        $allowed = ['fullscreen', 'picture-in-picture', 'encrypted-media', 'accelerometer', 'gyroscope'];
        $out = array_values(array_intersect($allowed, array_filter(array_map('trim', $tokens))));
        return $out !== [] ? implode('; ', $out) : 'fullscreen; picture-in-picture';
    }

    private function policy(): EditorialBlockSecurityPolicy
    {
        return $this->security ?? EditorialBlockSecurityPolicy::defaults();
    }
}
