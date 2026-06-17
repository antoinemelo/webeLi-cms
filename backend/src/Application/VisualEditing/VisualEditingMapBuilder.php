<?php

declare(strict_types=1);

namespace App\Application\VisualEditing;

use App\Application\Schema\NativeFieldBlueprintRegistry;

/**
 * Builds a deterministic map between rendered editable DOM areas and the
 * canonical draft document. It deliberately stays storage-agnostic: callers
 * provide the already-loaded entry aggregate, and writes remain delegated to
 * SaveContentDraft through SaveVisualField.
 */
final class VisualEditingMapBuilder
{
    /** @param array<string,mixed> $aggregate @return array<string,mixed> */
    public function build(array $aggregate, int $siteId, string $languageCode): array
    {
        $entry = is_array($aggregate['entry'] ?? null) ? $aggregate['entry'] : [];
        $localization = is_array($aggregate['localization'] ?? null) ? $aggregate['localization'] : [];
        $seo = is_array($aggregate['seo'] ?? null) ? $aggregate['seo'] : [];
        $revision = is_array($aggregate['working_revision'] ?? null) ? $aggregate['working_revision'] : null;
        $publishedRevision = is_array($aggregate['published_revision'] ?? null) ? $aggregate['published_revision'] : null;
        $document = $this->documentFromRevision($revision);
        $publishedDocument = $this->documentFromRevision($publishedRevision);
        $blocks = $this->blocksFromAggregate($aggregate, $document);
        $publishedBlocksById = $this->blocksById($this->blocksFromAggregate([], $publishedDocument));

        $fields = [
            $this->field('entry:title', 'entry', null, 'content.title', 'Titre', 'inline_text', (string) ($localization['title'] ?? $document['content']['title'] ?? '')),
            $this->field('entry:slug', 'entry', null, 'content.slug', 'Slug', 'inline_text', (string) ($localization['draft_slug'] ?? $localization['slug'] ?? $document['content']['slug'] ?? '')),
            $this->field('seo:meta_title', 'seo', null, 'seo.meta_title', 'Titre SEO', 'inline_text', (string) ($seo['meta_title'] ?? $document['seo']['meta_title'] ?? '')),
            $this->field('seo:meta_description', 'seo', null, 'seo.meta_description', 'Description SEO', 'inline_textarea', (string) ($seo['meta_description'] ?? $document['seo']['meta_description'] ?? '')),
        ];

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockId = (string) ($block['id'] ?? ('block_' . $index));
            $type = (string) ($block['type'] ?? 'markdown');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $fields[] = $this->field('block:' . $blockId . ':enabled', 'block', $blockId, 'enabled', 'Bloc actif', 'boolean', (bool) ($block['enabled'] ?? true), $type, $index);
            $fields[] = $this->field('block:' . $blockId . ':editorial_status', 'block', $blockId, 'editorial_status', 'État éditorial', 'editorial_status', $this->normaliseEditorialStatus((string) ($block['editorial_status'] ?? 'published')), $type, $index);
            foreach ($this->editableFieldsForBlock($block, $index) as $field) {
                $fields[] = $field;
            }
        }

        $normalisedBlocks = array_values(array_filter($blocks, 'is_array'));
        return [
            'entry_id' => (int) ($entry['id'] ?? 0),
            'site_id' => $siteId,
            'language_code' => $languageCode,
            'content_type_key' => (string) ($entry['type_key'] ?? $entry['content_type_key'] ?? $document['content_type'] ?? 'page'),
            'working_revision_id' => (int) ($revision['id'] ?? 0),
            'published_revision_id' => (int) (($aggregate['published_revision']['id'] ?? $aggregate['publication']['published_revision_id'] ?? 0)),
            'fields' => $fields,
            'blocks' => $this->visualBlocks($normalisedBlocks, $publishedBlocksById),
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

    /** @param array<string,mixed> $aggregate @param array<string,mixed> $document @return list<array<string,mixed>> */
    private function blocksFromAggregate(array $aggregate, array $document): array
    {
        if (is_array($document['blocks'] ?? null)) {
            return array_values(array_filter($document['blocks'], 'is_array'));
        }
        $loc = is_array($aggregate['localization'] ?? null) ? $aggregate['localization'] : [];
        if (is_array($loc['blocks'] ?? null)) {
            return array_values(array_filter($loc['blocks'], 'is_array'));
        }
        return [];
    }

    /** @param list<array<string,mixed>> $blocks @return array<string,array<string,mixed>> */
    private function blocksById(array $blocks): array
    {
        $indexed = [];
        foreach ($blocks as $block) {
            $id = (string) ($block['id'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $block;
            }
        }
        return $indexed;
    }

    /** @return array<string,mixed> */
    private function field(string $key, string $kind, ?string $blockId, string $path, string $label, string $editor, mixed $value, string $blockType = '', ?int $blockIndex = null): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'block_id' => $blockId,
            'block_type' => $blockType,
            'block_index' => $blockIndex,
            'field_path' => $path,
            'label' => $label,
            'editor' => $editor,
            'value' => $value,
            'editable' => true,
        ];
    }

    /** @param array<string,mixed> $block @return list<array<string,mixed>> */
    private function editableFieldsForBlock(array $block, int $blockIndex): array
    {
        $blockId = (string) ($block['id'] ?? ('block_' . $blockIndex));
        $type = (string) ($block['type'] ?? 'markdown');
        $fields = [];
        $blueprint = NativeFieldBlueprintRegistry::blockBlueprint($type);
        if ($blueprint) {
            foreach ((array) ($blueprint['sections'] ?? []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    if (!is_array($field)) { continue; }
                    $fields = array_merge($fields, $this->editableFieldsForDefinition($block, $blockId, $type, $blockIndex, $field, 'data.' . (string) ($field['handle'] ?? ''), (string) ($field['display'] ?? $field['handle'] ?? 'Champ')));
                }
            }
        }
        if ($fields !== []) { return $fields; }
        foreach ($this->legacyEditablePathsForBlock($type) as $spec) {
            [$path, $label, $editor] = $spec;
            $fields[] = $this->field('block:' . $blockId . ':' . $path, 'block', $blockId, $path, $label, $editor, $this->valueAtPath($block, $path), $type, $blockIndex);
        }
        return $fields;
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $definition @return list<array<string,mixed>> */
    private function editableFieldsForDefinition(array $block, string $blockId, string $blockType, int $blockIndex, array $definition, string $path, string $label): array
    {
        $handle = (string) ($definition['handle'] ?? '');
        if ($handle === '') { return []; }
        $type = (string) ($definition['type'] ?? 'text');
        $config = is_array($definition['config'] ?? null) ? $definition['config'] : [];
        if ($type === 'assets') {
            return [];
        }
        if ($type === 'replicator' && ($config['mode'] ?? '') === 'blocks') {
            return [];
        }
        if ($type === 'replicator' && $handle === 'columns') {
            return $this->editableColumnsFields($block, $blockId, $blockType, $blockIndex, $definition, $path, $label);
        }
        if ($type === 'replicator' && in_array($handle, ['buttons', 'items'], true)) {
            $editor = $handle === 'buttons' || $blockType === 'buttons' ? 'button_list' : 'gallery_items';
            return [$this->field('block:' . $blockId . ':' . $path, 'block', $blockId, $path, $label, $editor, $this->valueAtPath($block, $path), $blockType, $blockIndex)];
        }
        return [$this->field('block:' . $blockId . ':' . $path, 'block', $blockId, $path, $label, $this->editorForField($definition), $this->valueAtPath($block, $path), $blockType, $blockIndex)];
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $definition @return list<array<string,mixed>> */
    private function editableColumnsFields(array $block, string $blockId, string $blockType, int $blockIndex, array $definition, string $path, string $label): array
    {
        $out = [];
        $columns = $this->valueAtPath($block, $path);
        if (!is_array($columns)) { return $out; }
        $columnFields = [];
        $blocksField = null;
        $config = is_array($definition['config'] ?? null) ? $definition['config'] : [];
        foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $field) {
            if (!is_array($field)) { continue; }
            if (($field['handle'] ?? '') === 'blocks') { $blocksField = $field; continue; }
            $columnFields[] = $field;
        }
        foreach (array_values($columns) as $columnIndex => $column) {
            if (!is_array($column)) { continue; }
            foreach ($columnFields as $field) {
                $handle = (string) ($field['handle'] ?? '');
                if ($handle === '') { continue; }
                $fieldPath = $path . '.' . $columnIndex . '.' . $handle;
                $fieldLabel = sprintf('Colonne %d — %s', $columnIndex + 1, (string) ($field['display'] ?? $handle));
                $out[] = $this->field('block:' . $blockId . ':' . $fieldPath, 'block', $blockId, $fieldPath, $fieldLabel, $this->editorForField($field), $this->valueAtPath($block, $fieldPath), $blockType, $blockIndex);
            }
            foreach (is_array($column['blocks'] ?? null) ? array_values($column['blocks']) : [] as $childIndex => $child) {
                if (!is_array($child)) { continue; }
                if ((string) ($child['type'] ?? '') === 'columns') { continue; }
                $childId = (string) ($child['id'] ?? '');
                $childType = (string) ($child['type'] ?? 'markdown');
                $nestedIndex = ($blockIndex * 1000) + ($columnIndex * 100) + $childIndex + 1;
                $out[] = $this->field('block:' . $childId . ':enabled', 'block', $childId, 'enabled', 'Sous-bloc actif', 'boolean', (bool) ($child['enabled'] ?? true), $childType, $nestedIndex);
                $out[] = $this->field('block:' . $childId . ':editorial_status', 'block', $childId, 'editorial_status', 'État éditorial', 'editorial_status', $this->normaliseEditorialStatus((string) ($child['editorial_status'] ?? 'published')), $childType, $nestedIndex);
                foreach ($this->editableFieldsForBlock($child, $nestedIndex) as $childField) {
                    $out[] = $childField;
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $field */
    private function editorForField(array $field): string
    {
        $type = (string) ($field['type'] ?? 'text');
        $handle = strtolower((string) ($field['handle'] ?? ''));
        if (in_array($type, ['link', 'text'], true) && (str_ends_with($handle, '_src') || str_ends_with($handle, '_url') || in_array($handle, ['src', 'url', 'image_src', 'image_url'], true))) {
            if (str_contains($handle, 'image') || $handle === 'src' || str_contains($handle, 'hero') || str_contains($handle, 'photo') || str_contains($handle, 'visual')) {
                return 'media_url';
            }
        }
        return match ($type) {
            'markdown', 'bard' => 'inline_textarea',
            'code', 'yaml' => 'html_code',
            'integer', 'range' => 'number',
            'toggle' => 'boolean',
            'entries', 'taxonomy', 'table', 'checkboxes', 'list', 'replicator' => 'json',
            default => 'inline_text',
        };
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function legacyEditablePathsForBlock(string $type): array
    {
        return match ($type) {
            'hero' => [['data.eyebrow', 'Surtitre', 'inline_text'], ['data.title', 'Titre', 'inline_text'], ['data.lead', 'Texte introductif', 'inline_textarea'], ['data.image_src', 'Image', 'media_url'], ['data.image_alt', 'Texte alternatif', 'inline_text'], ['data.buttons', 'Boutons', 'button_list']],
            'image' => [['data.src', 'Image', 'media_url'], ['data.alt', 'Texte alternatif', 'inline_text'], ['data.caption', 'Légende', 'inline_textarea']],
            'gallery' => [['data.items', 'Images de galerie', 'gallery_items']],
            'buttons' => [['data.items', 'Boutons', 'button_list']],
            'html' => [['data.html', 'HTML', 'html_code']],
            'iframe' => [['data.title', 'Titre', 'inline_text'], ['data.src', 'URL intégrée', 'inline_text'], ['data.height', 'Hauteur', 'number']],
            'form' => [['data.title', 'Titre', 'inline_text'], ['data.intro', 'Introduction', 'inline_textarea'], ['data.form_key', 'Formulaire', 'inline_text']],
            'plan', 'articles' => [['data', 'Réglages du bloc', 'json']],
            default => [['data.text', 'Texte', 'richtext_light'], ['data.markdown', 'Markdown', 'richtext_light']],
        };
    }


    /** @param array<string,mixed> $source */
    private function valueAtPath(array $source, string $path): mixed
    {
        $cursor = $source;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    private function normaliseEditorialStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'draft', 'brouillon' => 'draft',
            'review', 'relecture' => 'review',
            'ready', 'ready_to_publish', 'ready-to-publish', 'validated', 'approved' => 'ready',
            'archived', 'archive' => 'archived',
            default => 'published',
        };
    }

    /** @param list<array<string,mixed>> $blocks @param array<string,array<string,mixed>> $publishedBlocksById @return list<array<string,mixed>> */
    private function visualBlocks(array $blocks, array $publishedBlocksById): array
    {
        $out = [];
        foreach ($blocks as $blockIndex => $block) {
            if (!is_array($block)) { continue; }
            $out[] = $this->visualBlockItem($block, (int) $blockIndex, $publishedBlocksById);
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            if ((string) ($block['type'] ?? '') !== 'columns') { continue; }
            foreach (is_array($data['columns'] ?? null) ? array_values($data['columns']) : [] as $columnIndex => $column) {
                if (!is_array($column)) { continue; }
                foreach (is_array($column['blocks'] ?? null) ? array_values($column['blocks']) : [] as $childIndex => $child) {
                    if (!is_array($child)) { continue; }
                    $out[] = $this->visualBlockItem($child, ((int) $blockIndex * 1000) + ((int) $columnIndex * 100) + (int) $childIndex + 1, $publishedBlocksById);
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $block @param array<string,array<string,mixed>> $publishedBlocksById @return array<string,mixed> */
    private function visualBlockItem(array $block, int $blockIndex, array $publishedBlocksById): array
    {
        $id = (string) ($block['id'] ?? '');
        $status = $this->normaliseEditorialStatus((string) ($block['editorial_status'] ?? 'published'));
        $published = $id !== '' && isset($publishedBlocksById[$id]) ? $publishedBlocksById[$id] : null;
        return [
            'id' => $id,
            'type' => (string) ($block['type'] ?? 'markdown'),
            'label' => (string) ($block['label'] ?? ucfirst((string) ($block['type'] ?? 'bloc'))),
            'enabled' => (bool) ($block['enabled'] ?? true),
            'editorial_status' => $status,
            'visual_status' => $this->visualStatus($status, $block, $published),
            'is_modified_since_publish' => $published === null || $this->canonicalBlock($block) !== $this->canonicalBlock($published),
            'block_index' => $blockIndex,
        ];
    }

    /** @param array<string,mixed> $block @param array<string,mixed>|null $published */
    private function visualStatus(string $status, array $block, ?array $published): string
    {
        if ($status === 'draft' || $status === 'review' || $status === 'ready') {
            return $status;
        }
        if ($status === 'published' && $published !== null && $this->canonicalBlock($block) === $this->canonicalBlock($published)) {
            return 'none';
        }
        if ($status === 'published' && $published !== null) {
            return 'ready';
        }
        return $status;
    }

    /** @param array<string,mixed> $block */
    private function canonicalBlock(array $block): string
    {
        unset($block['editorial_status'], $block['_visual_editorial_status'], $block['_visual_status_label'], $block['_visual_modified']);
        ksort($block);
        return json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
