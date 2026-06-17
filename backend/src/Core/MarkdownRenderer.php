<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small dependency-free Markdown renderer for editorial public content.
 *
 * Supported, intentionally conservative subset: headings, paragraphs, emphasis,
 * inline code, fenced code blocks (``` and '''), lists, blockquotes, horizontal
 * rules, Markdown tables and safe links. Raw HTML is escaped; use the dedicated HTML block when
 * trusted Bootstrap/HTML markup is required.
 */
final class MarkdownRenderer
{
    public static function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $lines = explode("\n", $markdown);
        $html = [];
        $paragraph = [];
        $listType = null;
        $listItems = [];
        $blockquote = [];
        $codeFence = null;
        $codeLang = '';
        $codeLines = [];

        $flushParagraph = static function () use (&$html, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }
            $text = implode("\n", $paragraph);
            $html[] = '<p>' . self::inline($text) . '</p>';
            $paragraph = [];
        };

        $flushList = static function () use (&$html, &$listType, &$listItems): void {
            if ($listType === null || $listItems === []) {
                $listType = null;
                $listItems = [];
                return;
            }
            $items = '';
            foreach ($listItems as $item) {
                $items .= '<li>' . self::inline($item) . '</li>';
            }
            $tag = $listType === 'ol' ? 'ol' : 'ul';
            $html[] = '<' . $tag . '>' . $items . '</' . $tag . '>';
            $listType = null;
            $listItems = [];
        };

        $flushBlockquote = static function () use (&$html, &$blockquote): void {
            if ($blockquote === []) {
                return;
            }
            $inner = self::toHtml(implode("\n", $blockquote));
            $html[] = '<blockquote>' . $inner . '</blockquote>';
            $blockquote = [];
        };

        $flushCode = static function () use (&$html, &$codeFence, &$codeLang, &$codeLines): void {
            if ($codeFence === null) {
                return;
            }
            $class = $codeLang !== '' ? ' class="language-' . self::escAttr($codeLang) . '"' : '';
            $html[] = '<pre><code' . $class . '>' . self::esc(implode("\n", $codeLines)) . '</code></pre>';
            $codeFence = null;
            $codeLang = '';
            $codeLines = [];
        };

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($codeFence !== null) {
                if (preg_match('/^\s*' . preg_quote($codeFence, '/') . '\s*$/', $line)) {
                    $flushCode();
                } else {
                    $codeLines[] = $line;
                }
                continue;
            }

            if (preg_match('/^\s*(```|\'\'\')\s*([A-Za-z0-9_+.-]+)?\s*$/', $line, $m)) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                $codeFence = $m[1];
                $codeLang = isset($m[2]) ? strtolower($m[2]) : '';
                $codeLines = [];
                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                continue;
            }

            if (preg_match('/^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/', $line)) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                $html[] = '<hr>';
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>' . self::inline(trim($m[2])) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m)) {
                $flushParagraph();
                $flushList();
                $blockquote[] = $m[1];
                continue;
            }

            if (self::looksLikeTableHeader($line, $lines[$i + 1] ?? null)) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                $header = self::splitTableRow($line);
                $alignment = self::parseTableAlignment((string) ($lines[$i + 1] ?? ''));
                $rows = [];
                $i += 2;
                while ($i < $count && trim((string) $lines[$i]) !== '' && self::isTableRow((string) $lines[$i])) {
                    $rows[] = self::splitTableRow((string) $lines[$i], count($header));
                    $i++;
                }
                $i--;
                $html[] = self::renderTable($header, $alignment, $rows);
                continue;
            }

            if (preg_match('/^\s{0,3}([-+*])\s+(.+)$/', $line, $m)) {
                $flushParagraph();
                $flushBlockquote();
                if ($listType !== 'ul') {
                    $flushList();
                    $listType = 'ul';
                }
                $listItems[] = $m[2];
                continue;
            }

            if (preg_match('/^\s{0,3}\d+[.)]\s+(.+)$/', $line, $m)) {
                $flushParagraph();
                $flushBlockquote();
                if ($listType !== 'ol') {
                    $flushList();
                    $listType = 'ol';
                }
                $listItems[] = $m[1];
                continue;
            }

            $flushList();
            $flushBlockquote();
            $paragraph[] = $line;
        }

        $flushCode();
        $flushParagraph();
        $flushList();
        $flushBlockquote();

        return implode("\n", $html);
    }

    private static function looksLikeTableHeader(string $line, ?string $nextLine): bool
    {
        if ($nextLine === null || !self::isTableRow($line)) {
            return false;
        }
        $header = self::splitTableRow($line);
        $separator = self::splitTableRow($nextLine);
        return count($header) >= 2
            && count($header) === count($separator)
            && self::isTableSeparator($nextLine);
    }

    private static function isTableRow(string $line): bool
    {
        $trimmed = trim($line);
        return $trimmed !== '' && str_contains($trimmed, '|');
    }

    private static function isTableSeparator(string $line): bool
    {
        $cells = self::splitTableRow($line);
        if (count($cells) < 2) {
            return false;
        }
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-+:?$/', str_replace(' ', '', $cell))) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private static function splitTableRow(string $line, ?int $expectedCells = null): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }

        $cells = [];
        $cell = '';
        $escaped = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($escaped) {
                $cell .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '|') {
                $cells[] = trim($cell);
                $cell = '';
                continue;
            }
            $cell .= $char;
        }
        if ($escaped) {
            $cell .= '\\';
        }
        $cells[] = trim($cell);

        if ($expectedCells !== null) {
            if (count($cells) > $expectedCells) {
                $cells = array_slice($cells, 0, $expectedCells);
            }
            while (count($cells) < $expectedCells) {
                $cells[] = '';
            }
        }

        return $cells;
    }

    /**
     * @return list<string>
     */
    private static function parseTableAlignment(string $separatorLine): array
    {
        $alignments = [];
        foreach (self::splitTableRow($separatorLine) as $cell) {
            $cell = str_replace(' ', '', $cell);
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            $alignments[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }
        return $alignments;
    }

    /**
     * @param list<string> $header
     * @param list<string> $alignment
     * @param list<list<string>> $rows
     */
    private static function renderTable(array $header, array $alignment, array $rows): string
    {
        $head = '';
        foreach ($header as $index => $cell) {
            $attr = self::tableAlignAttr($alignment[$index] ?? '');
            $head .= '<th scope="col"' . $attr . '>' . self::inline($cell) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $index => $cell) {
                $attr = self::tableAlignAttr($alignment[$index] ?? '');
                $body .= '<td' . $attr . '>' . self::inline($cell) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<div class="markdown-table-wrap"><table class="markdown-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private static function tableAlignAttr(string $alignment): string
    {
        return in_array($alignment, ['left', 'center', 'right'], true) ? ' style="text-align:' . $alignment . '"' : '';
    }

    private static function inline(string $text): string
    {
        $text = preg_replace("/\n( {2,}|\\\\)\n/", "<br>\n", $text) ?? $text;
        $text = str_replace("\n", ' ', $text);

        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $key = "\x1A" . count($codes) . "\x1A";
            $codes[$key] = '<code>' . self::esc($m[1]) . '</code>';
            return $key;
        }, $text) ?? $text;

        $text = self::esc($text);

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static function (array $m): string {
            $label = $m[1];
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (!self::safeUrl($url)) {
                return $label;
            }
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . self::escAttr(html_entity_decode($m[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '"' : '';
            $external = preg_match('/^https?:\/\//i', $url) ? ' rel="noopener noreferrer"' : '';
            return '<a href="' . self::escAttr($url) . '"' . $title . $external . '>' . $label . '</a>';
        }, $text) ?? $text;

        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text) ?? $text;

        foreach ($codes as $key => $html) {
            $text = str_replace(self::esc($key), $html, $text);
        }

        return $text;
    }

    private static function safeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        if (preg_match('/^(https?:|mailto:|tel:|\/|#)/i', $url)) {
            return true;
        }
        return !preg_match('/^[a-z][a-z0-9+.-]*:/i', $url);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
