<?php

declare(strict_types=1);

namespace App\Application\VisualEditing;

use App\Application\Content\SaveContentDraft;
use App\Application\Content\BlockDocumentNormalizer;

final class SaveVisualField
{
    public function __construct(private readonly SaveContentDraft $saveContentDraft) {}

    /** @param array<string,mixed> $aggregate @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(int $siteId, int $entryId, string $languageCode, array $aggregate, array $input, int $userId): array
    {
        $entry = is_array($aggregate['entry'] ?? null) ? $aggregate['entry'] : [];
        $localization = is_array($aggregate['localization'] ?? null) ? $aggregate['localization'] : [];
        $seo = is_array($aggregate['seo'] ?? null) ? $aggregate['seo'] : [];
        $revision = is_array($aggregate['working_revision'] ?? null) ? $aggregate['working_revision'] : null;
        $document = $this->documentFromRevision($revision);

        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $payload = [
            'entry_key' => (string) ($entry['entry_key'] ?? ''),
            'title' => (string) ($content['title'] ?? $localization['title'] ?? ''),
            'slug' => (string) ($content['slug'] ?? $localization['draft_slug'] ?? $localization['slug'] ?? ''),
            'blocks' => is_array($document['blocks'] ?? null) ? $document['blocks'] : [],
            'fields' => is_array($document['fields'] ?? null) ? $document['fields'] : (is_array($aggregate['fields'] ?? null) ? $aggregate['fields'] : []),
            'taxonomy_terms' => is_array($document['taxonomy_terms'] ?? null) ? $document['taxonomy_terms'] : $this->taxonomyTermsFromAggregate($aggregate),
            'meta_title' => (string) ($document['seo']['meta_title'] ?? $seo['meta_title'] ?? ''),
            'meta_description' => (string) ($document['seo']['meta_description'] ?? $seo['meta_description'] ?? ''),
            'meta_robots' => (string) ($document['seo']['meta_robots'] ?? $seo['meta_robots'] ?? 'index,follow'),
            'change_notes' => (string) ($input['change_notes'] ?? 'Édition visuelle'),
        ];

        $target = is_array($input['target'] ?? null) ? $input['target'] : [];
        $kind = (string) ($target['kind'] ?? 'block');
        $path = (string) ($target['field_path'] ?? '');
        $value = $input['value'] ?? null;
        $updatedBlockId = null;

        if ($kind === 'entry') {
            if ($path === 'content.title' || $path === 'title') {
                $payload['title'] = is_scalar($value) ? trim((string) $value) : '';
            } elseif ($path === 'content.slug' || $path === 'slug') {
                $payload['slug'] = is_scalar($value) ? trim((string) $value) : '';
            } else {
                throw new \InvalidArgumentException('Champ d’entrée non pris en charge par l’éditeur visuel.');
            }
        } elseif ($kind === 'seo') {
            $seoKey = str_starts_with($path, 'seo.') ? substr($path, 4) : $path;
            if (!in_array($seoKey, ['meta_title', 'meta_description', 'meta_robots'], true)) {
                throw new \InvalidArgumentException('Champ SEO non pris en charge par l’éditeur visuel.');
            }
            $payload[$seoKey] = is_scalar($value) ? (string) $value : '';
        } elseif ($kind === 'field') {
            $fieldKey = trim(str_starts_with($path, 'fields.') ? substr($path, 7) : $path);
            if ($fieldKey === '') {
                throw new \InvalidArgumentException('Champ personnalisé manquant.');
            }
            $payload['fields'][$fieldKey] = $value;
        } else {
            $blockId = (string) ($target['block_id'] ?? '');
            $updatedBlockId = $blockId;
            $payload['blocks'] = (new BlockDocumentNormalizer())->normalize($this->replaceBlockValue($payload['blocks'], $blockId, $path, $value));
        }

        $typeKey = (string) ($entry['type_key'] ?? $entry['content_type_key'] ?? $document['content_type'] ?? 'page');
        $result = $this->saveContentDraft->execute($siteId, $typeKey, $languageCode, $payload, $userId, $entryId);

        return [
            'entry_id' => $result->entryId,
            'language_code' => $result->languageCode,
            'working_revision_id' => $result->revisionId,
            'updated_field' => [
                'kind' => $kind,
                'block_id' => $updatedBlockId,
                'field_path' => $path,
                'value' => $value,
            ],
            'dirty' => true,
        ];
    }

    /** @param array<string,mixed>|null $revision @return array<string,mixed> */
    private function documentFromRevision(?array $revision): array
    {
        if (!$revision) {
            return [];
        }
        $document = json_decode((string) ($revision['document_json'] ?? '{}'), true);
        return is_array($document) ? $document : [];
    }

    /** @param array<string,mixed> $aggregate @return array<string,list<int>> */
    private function taxonomyTermsFromAggregate(array $aggregate): array
    {
        $grouped = [];
        foreach (is_array($aggregate['taxonomies'] ?? null) ? $aggregate['taxonomies'] : [] as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }
            $key = (string) ($assignment['taxonomy_key'] ?? '');
            $termId = (int) ($assignment['term_id'] ?? 0);
            if ($key === '' || $termId < 1) {
                continue;
            }
            $grouped[$key] ??= [];
            if (!in_array($termId, $grouped[$key], true)) {
                $grouped[$key][] = $termId;
            }
        }
        return $grouped;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function replaceBlockValue(array $blocks, string $blockId, string $path, mixed $value): array
    {
        if ($blockId === '') {
            throw new \InvalidArgumentException('Identifiant de bloc manquant.');
        }
        $found = $this->replaceBlockValueRecursive($blocks, $blockId, $path, $value);
        if (!$found) {
            throw new \InvalidArgumentException('Bloc introuvable dans le brouillon courant.');
        }
        return $blocks;
    }

    /** @param list<array<string,mixed>> $blocks */
    private function replaceBlockValueRecursive(array &$blocks, string $blockId, string $path, mixed $value): bool
    {
        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }
            if ((string) ($block['id'] ?? '') === $blockId) {
                $this->setBlockPathValue($block, $path, $value);
                return true;
            }
            $data =& $block['data'];
            if (is_array($data) && (string) ($block['type'] ?? '') === 'columns' && is_array($data['columns'] ?? null)) {
                foreach ($data['columns'] as &$column) {
                    if (is_array($column) && is_array($column['blocks'] ?? null)) {
                        if ($this->replaceBlockValueRecursive($column['blocks'], $blockId, $path, $value)) {
                            return true;
                        }
                    }
                }
                unset($column);
            }
        }
        unset($block);
        return false;
    }


    /** @param array<string,mixed> $target */
    private function setBlockPathValue(array &$target, string $path, mixed $value): void
    {
        if (is_array($value) && !empty($value['__media_selection'])) {
            $src = is_scalar($value['src'] ?? null) ? (string) $value['src'] : '';
            $this->setPath($target, $path, $src);
            foreach ($this->mediaPeerPaths($path) as $key => $peerPath) {
                if (array_key_exists($key, $value)) {
                    $this->setPath($target, $peerPath, $key === 'media_id' ? max(0, (int) $value[$key]) : (is_scalar($value[$key]) ? (string) $value[$key] : ''));
                }
            }
            return;
        }
        $this->setPath($target, $path, $value);
    }

    /** @return array<string,string> */
    private function mediaPeerPaths(string $srcPath): array
    {
        if (str_ends_with($srcPath, '_src')) {
            $prefix = substr($srcPath, 0, -4);
            return ['media_id' => $prefix . '_media_id', 'alt' => $prefix . '_alt', 'caption' => $prefix . '_caption', 'title' => $prefix . '_title'];
        }
        if (str_ends_with($srcPath, '.src')) {
            $prefix = substr($srcPath, 0, -4);
            return ['media_id' => $prefix . '.media_id', 'alt' => $prefix . '.alt', 'caption' => $prefix . '.caption', 'title' => $prefix . '.title'];
        }
        if (str_ends_with($srcPath, '_url')) {
            $prefix = substr($srcPath, 0, -4);
            return ['media_id' => $prefix . '_media_id', 'alt' => $prefix . '_alt', 'caption' => $prefix . '_caption', 'title' => $prefix . '_title'];
        }
        return [];
    }

    /** @param array<string,mixed> $target */
    private function setPath(array &$target, string $path, mixed $value): void
    {
        $segments = array_values(array_filter(explode('.', $path), static fn(string $part): bool => $part !== ''));
        if ($segments === []) {
            throw new \InvalidArgumentException('Chemin de champ manquant.');
        }
        $cursor =& $target;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                return;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
    }
}
