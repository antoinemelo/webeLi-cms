<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Politique unique de sécurité appliquée aux blocs éditoriaux avant publication,
 * preview et rendu public. Elle privilégie une allowlist stricte : ce qui n'est
 * pas explicitement autorisé est supprimé ou refusé.
 */
final class EditorialBlockSecurityPolicy
{
    /** @var list<string> */
    private array $iframeAllowedHosts;
    /** @var list<string> */
    private array $allowedUrlSchemes;
    /** @var list<string> */
    private array $allowedDataMimeTypes;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config = [])
    {
        $hosts = (array) ($config['iframe_allowed_hosts'] ?? [
            'www.youtube.com', 'youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com', 'www.openstreetmap.org', 'openstreetmap.org',
        ]);
        $schemes = (array) ($config['allowed_url_schemes'] ?? ['http', 'https', 'mailto', 'tel']);
        $dataMimes = (array) ($config['allowed_data_mime_types'] ?? []);
        $this->iframeAllowedHosts = array_values(array_filter(array_map(static fn($v): string => strtolower(trim((string) $v)), $hosts)));
        $this->allowedUrlSchemes = array_values(array_filter(array_map(static fn($v): string => strtolower(trim((string) $v)), $schemes)));
        $this->allowedDataMimeTypes = array_values(array_filter(array_map(static fn($v): string => strtolower(trim((string) $v)), $dataMimes)));
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function sanitizeHtmlSafe(string $html): string
    {
        $html = trim($html);
        if ($html === '') { return ''; }
        if (stripos($html, '<svg') !== false) {
            $html = preg_replace('#<\s*svg\b[^>]*>.*?<\s*/\s*svg\s*>#isu', '', $html) ?? '';
            $html = preg_replace('#<\s*svg\b[^>]*/\s*>#isu', '', $html) ?? $html;
        }
        $html = preg_replace('#<\s*/?\s*(script|style|object|embed|form|input|button|textarea|select|option|meta|link|base|iframe|frame|frameset|applet|canvas|math)[^>]*>#iu', '', $html) ?? '';
        $html = preg_replace_callback('#<([a-z][a-z0-9:-]*)(\s[^>]*)?>#iu', function (array $m): string {
            $tag = strtolower($m[1]);
            if (!in_array($tag, $this->allowedHtmlTags(), true)) { return ''; }
            $attrs = $this->sanitizeAttributes((string) ($m[2] ?? ''), $tag);
            return '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . '>';
        }, $html) ?? '';
        $html = preg_replace_callback('#</\s*([a-z][a-z0-9:-]*)\s*>#iu', function (array $m): string {
            $tag = strtolower($m[1]);
            return in_array($tag, $this->allowedHtmlTags(), true) ? '</' . $tag . '>' : '';
        }, $html) ?? '';
        return trim($html);
    }

    public function isUrlAllowed(string $url, bool $allowData = false, bool $allowProtocolRelative = false): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }
        if (str_starts_with($url, '//')) {
            return $allowProtocolRelative;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return false;
        }
        $scheme = strtolower($m[1]);
        if ($scheme === 'javascript' || $scheme === 'vbscript') { return false; }
        if ($scheme === 'data') { return $allowData && $this->isAllowedDataUrl($url); }
        return in_array($scheme, $this->allowedUrlSchemes, true);
    }

    public function sanitizeUrl(string $url, bool $allowData = false): string
    {
        $url = trim($url);
        return $this->isUrlAllowed($url, $allowData) ? $url : '';
    }

    public function isIframeSrcAllowed(string $url): bool
    {
        if (!$this->isUrlAllowed($url)) { return false; }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') { return false; }
        foreach ($this->iframeAllowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) { return true; }
        }
        return false;
    }

    /** @return list<string> */
    public function validateHtmlRawPublication(array $block, bool $canManageHtmlRaw): array
    {
        if ((string) ($block['type'] ?? '') !== 'html_raw') { return []; }
        return $canManageHtmlRaw ? [] : ['Bloc html_raw : permission content.html_raw.manage obligatoire pour publier du HTML brut.'];
    }

    public function contentSecurityPolicyHeader(): string
    {
        $frameSrc = array_map(fn(string $host): string => 'https://' . $host, $this->iframeAllowedHosts);
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "img-src 'self' https: data:",
            "font-src 'self' https: data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'",
            "connect-src 'self'",
            'frame-src ' . implode(' ', array_unique(array_merge(["'self'"], $frameSrc))),
            "upgrade-insecure-requests",
        ];
        $custom = trim((string) ($this->config['content_security_policy'] ?? ''));
        return $custom !== '' ? $custom : implode('; ', $directives);
    }

    /** @return list<string> */
    private function allowedHtmlTags(): array
    {
        return ['p','br','strong','em','b','i','u','s','a','ul','ol','li','blockquote','code','pre','hr','span','div','h2','h3','h4','h5','h6','table','thead','tbody','tr','th','td','figure','figcaption','img'];
    }

    private function sanitizeAttributes(string $raw, string $tag): string
    {
        $out = [];
        if (!preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/u', $raw, $matches, PREG_SET_ORDER)) {
            return '';
        }
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $value = trim((string) $match[2], " \t\n\r\0\x0B\"'");
            if ($name === '' || str_starts_with($name, 'on') || str_starts_with($name, 'style') || str_starts_with($name, 'xmlns')) { continue; }
            if (str_starts_with($name, 'data-') || in_array($name, ['class','id','title','aria-label','aria-hidden','role'], true)) {
                $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                continue;
            }
            if (in_array($name, ['href','src'], true)) {
                $allowData = $tag === 'img' && $name === 'src' && $this->allowedDataMimeTypes !== [];
                if (!$this->isUrlAllowed($value, $allowData)) { continue; }
                $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                continue;
            }
            if ($tag === 'a' && in_array($name, ['target','rel'], true)) {
                if ($name === 'target' && !in_array($value, ['_blank','_self'], true)) { continue; }
                $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                if ($name === 'target' && $value === '_blank') { $out[] = 'rel="noopener noreferrer"'; }
                continue;
            }
            if ($tag === 'img' && in_array($name, ['alt','width','height','loading'], true)) {
                $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        return implode(' ', array_values(array_unique($out)));
    }

    private function isAllowedDataUrl(string $url): bool
    {
        if (!preg_match('#^data:([^;,\s]+)#i', $url, $m)) { return false; }
        return in_array(strtolower($m[1]), $this->allowedDataMimeTypes, true);
    }
}
