<script setup lang="ts">
import type { EditorialBlock, FormDefinition, MediaAsset, MediaVariant, NativeBlockBlueprint, NativeFieldDefinition } from '@/api/contracts';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import CommerceBlockDataEditor from '@/components/editor/CommerceBlockDataEditor.vue';
import InfoHint from '@/components/ui/InfoHint.vue';

defineOptions({ name: 'NativeBlockDataEditor' });

type BlockType = EditorialBlock['type'];
type EditorialStatus = 'draft' | 'review' | 'published' | 'archived';
type BlockTuple = [BlockType, string];
type StatusTuple = [EditorialStatus, string];

const props = defineProps<{
  modelValue: EditorialBlock;
  blueprints: NativeBlockBlueprint[];
  fieldsets?: Record<string, any>;
  blockTypes: BlockTuple[];
  statuses: StatusTuple[];
  allowedBlockTypes?: BlockType[];
  depth?: number;
  formOptions?: FormDefinition[];
  formsLoading?: boolean;
  formPlaceholder?: string;
  siteId?: number | null;
  languageCode?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: EditorialBlock] }>();

function uid() { return `block_${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36).slice(-4)}`; }
function clone<T>(value: T): T { return JSON.parse(JSON.stringify(value)) as T; }
function blockBlueprint(type: string): NativeBlockBlueprint | undefined { return props.blueprints.find((bp) => bp.key === type); }
function fieldsForFieldset(key: string): NativeFieldDefinition[] {
  const fieldset = props.fieldsets?.[key];
  const sections = Array.isArray(fieldset?.sections) ? fieldset.sections : [];
  return sections.flatMap((section: any) => Array.isArray(section?.fields) ? section.fields : []);
}
function fieldFields(field: NativeFieldDefinition): NativeFieldDefinition[] {
  const config = field.config || {};
  if (typeof config.fieldset === 'string') return fieldsForFieldset(config.fieldset);
  return Array.isArray(config.fields) ? config.fields as NativeFieldDefinition[] : [];
}
function options(field: NativeFieldDefinition): Array<{ value: string | number | boolean; label: string }> {
  const raw = field.config?.options;
  return Array.isArray(raw) ? raw : [];
}
function formOptionLabel(form: FormDefinition): string {
  const name = typeof form.name === 'string' && form.name.trim() !== '' ? form.name.trim() : form.form_key;
  return `${name} (${form.form_key})`;
}
function isPublishedFormField(field: NativeFieldDefinition): boolean {
  return props.modelValue.type === 'form' && field.handle === 'form_key';
}
function fieldHelp(field: NativeFieldDefinition): string {
  const raw = field as NativeFieldDefinition & { help?: string; help_text?: string };
  return String(raw.instructions || raw.help_text || raw.help || field.config?.instructions || field.config?.help_text || field.config?.help || '').trim();
}

function fieldInterface(field: NativeFieldDefinition): string {
  const raw = field as NativeFieldDefinition & { interface_key?: string; ui_interface?: string };
  return String(raw.interface_key || raw.ui_interface || field.config?.interface_key || '').toLowerCase();
}
function isAssetField(field: NativeFieldDefinition): boolean {
  const type = String(field.type || '').toLowerCase();
  const iface = fieldInterface(field);
  return ['assets', 'asset', 'media', 'image'].includes(type) || ['assets', 'asset', 'media'].includes(iface);
}
function isReplicatorField(field: NativeFieldDefinition): boolean {
  const type = String(field.type || '').toLowerCase();
  const iface = fieldInterface(field);
  return type === 'replicator' || iface === 'replicator' || (type === 'json' && (typeof field.config?.fieldset === 'string' || Array.isArray(field.config?.fields)));
}
function isBlocksReplicatorField(field: NativeFieldDefinition): boolean {
  return isReplicatorField(field) && field.config?.mode === 'blocks';
}
function defaultValue(field: NativeFieldDefinition): unknown {
  if (field.default !== undefined && field.default !== null) return clone(field.default);
  if (field.type === 'toggle') return false;
  if (field.type === 'integer' || field.type === 'range') return 0;
  if (isReplicatorField(field) || ['list', 'table', 'checkboxes', 'entries', 'taxonomy', 'video'].includes(field.type) || isAssetField(field)) return [];
  return '';
}
function defaultData(type: BlockType): Record<string, unknown> {
  const defaults = blockBlueprint(type)?.defaults;
  if (defaults && typeof defaults === 'object') return clone(defaults) as Record<string, unknown>;
  return {};
}
function makeBlock(type: BlockType): EditorialBlock {
  const label = props.blockTypes.find(([value]) => value === type)?.[1] || type;
  return { id: uid(), type, enabled: true, editorial_status: 'published', label, css_class: '', anchor: '', data: defaultData(type), sort_order: 0 } as EditorialBlock;
}
function allowedBlocks(field?: NativeFieldDefinition): BlockTuple[] {
  const fromField = field?.config?.allowed_block_types;
  const allowed = Array.isArray(fromField) ? fromField : props.allowedBlockTypes;
  const disallowed = Array.isArray(field?.config?.disallowed_block_types) ? field?.config?.disallowed_block_types : [];
  return props.blockTypes.filter(([type]) => (!allowed || allowed.includes(type)) && !disallowed.includes(type) && type !== 'columns');
}
function updateBlock(patch: Partial<EditorialBlock>) {
  emit('update:modelValue', { ...props.modelValue, ...patch } as EditorialBlock);
}
function updateData(handle: string, value: unknown) {
  updateBlock({ data: { ...(props.modelValue.data || {}), [handle]: value } });
}
function valueFor(field: NativeFieldDefinition): unknown {
  const data = props.modelValue.data || {};
  return Object.prototype.hasOwnProperty.call(data, field.handle) ? data[field.handle] : defaultValue(field);
}
function coerceInput(field: NativeFieldDefinition, event: Event): unknown {
  const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
  if (field.type === 'toggle') return (target as HTMLInputElement).checked;
  if (field.type === 'integer' || field.type === 'range') return target.value === '' ? null : Number(target.value);
  return target.value;
}
function setReplicator(field: NativeFieldDefinition, items: unknown[]) { updateData(field.handle, items); }
function itemLabel(fields: NativeFieldDefinition[], item: any, index: number): string {
  const labelField = fields.find((field) => field.handle === 'label' || field.handle === 'title');
  const label = labelField ? String(item?.[labelField.handle] || '').trim() : '';
  return label || `Élément ${index + 1}`;
}
function addReplicatorItem(field: NativeFieldDefinition) {
  const items = [...arrayValue(field)];
  const fields = fieldFields(field);
  const next: Record<string, unknown> = {};
  for (const childField of fields) next[childField.handle] = defaultValue(childField);
  setReplicator(field, [...items, next]);
}
function updateReplicatorItem(field: NativeFieldDefinition, index: number, patch: Record<string, unknown>) {
  const items = [...arrayValue(field)];
  items[index] = { ...(items[index] || {}), ...patch };
  setReplicator(field, items);
}
function removeReplicatorItem(field: NativeFieldDefinition, index: number) {
  const items = [...arrayValue(field)];
  setReplicator(field, items.filter((_, itemIndex) => itemIndex !== index));
}
function addNestedBlock(field: NativeFieldDefinition, itemIndex: number, blockType: BlockType) {
  const items = [...arrayValue(field)];
  const item = { ...(items[itemIndex] || {}) };
  item.blocks = [...(Array.isArray(item.blocks) ? item.blocks : []), makeBlock(blockType)];
  items[itemIndex] = item;
  setReplicator(field, items);
}
function updateNestedBlock(field: NativeFieldDefinition, itemIndex: number, blockIndex: number, block: EditorialBlock) {
  const items = [...arrayValue(field)];
  const item = { ...(items[itemIndex] || {}) };
  const blocks = Array.isArray(item.blocks) ? [...item.blocks] : [];
  blocks[blockIndex] = block;
  item.blocks = blocks;
  items[itemIndex] = item;
  setReplicator(field, items);
}
function removeNestedBlock(field: NativeFieldDefinition, itemIndex: number, blockIndex: number) {
  const items = [...arrayValue(field)];
  const item = { ...(items[itemIndex] || {}) };
  item.blocks = (Array.isArray(item.blocks) ? item.blocks : []).filter((_: unknown, index: number) => index !== blockIndex);
  items[itemIndex] = item;
  setReplicator(field, items);
}
function assetPeerKeys(field: NativeFieldDefinition): { media: string; src: string; alt: string; caption: string; title: string } {
  const handle = field.handle;
  if (handle.endsWith('_media_id')) {
    const prefix = handle.slice(0, -'_media_id'.length);
    return { media: handle, src: `${prefix}_src`, alt: `${prefix}_alt`, caption: `${prefix}_caption`, title: `${prefix}_title` };
  }
  if (handle === 'media_id') return { media: 'media_id', src: 'src', alt: 'alt', caption: 'caption', title: 'title' };
  return { media: handle, src: `${handle}_src`, alt: `${handle}_alt`, caption: `${handle}_caption`, title: `${handle}_title` };
}
function setAssetField(field: NativeFieldDefinition, key: 'media' | 'src' | 'alt' | 'caption' | 'title', value: unknown) {
  const peer = assetPeerKeys(field)[key];
  updateData(peer, value);
}

function assetPeerHandles(fields: NativeFieldDefinition[]): Set<string> {
  const handles = new Set<string>();
  const fieldHandles = new Set(fields.map((field) => field.handle));
  for (const field of fields) {
    if (!isAssetField(field)) continue;
    const keys = assetPeerKeys(field);
    for (const peer of [keys.src, keys.alt, keys.caption, keys.title]) {
      if (fieldHandles.has(peer)) handles.add(peer);
    }
  }
  return handles;
}
function isHiddenAssetPeer(field: NativeFieldDefinition, fields: NativeFieldDefinition[]): boolean {
  return !isAssetField(field) && assetPeerHandles(fields).has(field.handle);
}
function fieldAcceptType(field: NativeFieldDefinition): 'image'|'video'|'audio'|'document'|'any' {
  const kind = String(field.config?.kind || field.config?.accept_type || field.config?.media_type || '').toLowerCase();
  return ['image','video','audio','document'].includes(kind) ? kind as 'image'|'video'|'audio'|'document' : 'image';
}
function fieldPreferredSet(field: NativeFieldDefinition): 'content'|'hero'|'open_graph' {
  const key = `${field.handle} ${field.display || ''} ${JSON.stringify(field.config || {})}`.toLowerCase();
  if (key.includes('open_graph') || key.includes('og_') || key.includes('social')) return 'open_graph';
  if (key.includes('hero') || key.includes('image_')) return 'hero';
  return 'content';
}
function preferredAssetUrl(asset: MediaAsset, setName: 'content'|'hero'|'open_graph' = 'content'): string {
  const preferred = setName === 'hero' ? ['hero_1280','hero_960','hero_640'] : setName === 'open_graph' ? ['og_1200x630'] : ['content_1280','content_1024','content_768','content_480'];
  const set = asset.responsive_sets?.[setName];
  for (const key of preferred) {
    const variant = set?.find((item: MediaVariant) => item.variant_key === key && item.public_url);
    if (variant?.public_url) return String(variant.public_url);
  }
  return String(asset.public_url || asset.thumbnail_url || '');
}
function setAssetFromMedia(field: NativeFieldDefinition, asset: MediaAsset | null) {
  const keys = assetPeerKeys(field);
  if (!asset) {
    updateBlock({ data: { ...(props.modelValue.data || {}), [keys.media]: 0, [keys.src]: '', [keys.alt]: '', [keys.caption]: '', [keys.title]: '' } });
    return;
  }
  const current = assetValue(field);
  updateBlock({
    data: {
      ...(props.modelValue.data || {}),
      [keys.media]: Number(asset.id),
      [keys.src]: preferredAssetUrl(asset, fieldPreferredSet(field)),
      [keys.alt]: String(asset.alt_text || current.alt || ''),
      [keys.caption]: String(asset.caption || current.caption || ''),
      [keys.title]: String(asset.title || current.title || ''),
    },
  });
}
function stringValue(value: unknown): string { return value === null || value === undefined ? '' : String(value); }
function arrayValue(field: NativeFieldDefinition): any[] {
  const value = valueFor(field);
  if (Array.isArray(value)) return value as any[];
  if (typeof value !== 'string') return [];
  const trimmed = value.trim();
  if (!trimmed || trimmed === '[object Object]' || trimmed === '[object Object],[object Object]') return [];
  try {
    const decoded = JSON.parse(trimmed);
    return Array.isArray(decoded) ? decoded as any[] : [];
  } catch {
    return [];
  }
}
function assetValue(field: NativeFieldDefinition): Record<string, unknown> {
  const keys = assetPeerKeys(field);
  const data = props.modelValue.data || {};
  const legacyValue = valueFor(field);
  if (legacyValue && typeof legacyValue === 'object' && !Array.isArray(legacyValue)) {
    const legacy = legacyValue as Record<string, unknown>;
    return {
      media_id: data[keys.media] || legacy.media_id || legacy.id || 0,
      src: data[keys.src] || legacy.src || '',
      alt: data[keys.alt] || legacy.alt || '',
      caption: data[keys.caption] || legacy.caption || '',
      title: data[keys.title] || legacy.title || '',
    };
  }
  return { media_id: data[keys.media] || legacyValue || 0, src: data[keys.src] || '', alt: data[keys.alt] || '', caption: data[keys.caption] || '', title: data[keys.title] || '' };
}

function assetValueFromRecord(field: NativeFieldDefinition, record: Record<string, unknown>): Record<string, unknown> {
  const keys = assetPeerKeys(field);
  const legacyValue = record[field.handle];
  if (legacyValue && typeof legacyValue === 'object' && !Array.isArray(legacyValue)) {
    const legacy = legacyValue as Record<string, unknown>;
    return { media_id: record[keys.media] || legacy.media_id || legacy.id || 0, src: record[keys.src] || legacy.src || '', alt: record[keys.alt] || legacy.alt || '', caption: record[keys.caption] || legacy.caption || '', title: record[keys.title] || legacy.title || '' };
  }
  return { media_id: record[keys.media] || legacyValue || 0, src: record[keys.src] || '', alt: record[keys.alt] || '', caption: record[keys.caption] || '', title: record[keys.title] || '' };
}
function recordAssetPatch(field: NativeFieldDefinition, asset: MediaAsset | null, record: Record<string, unknown>): Record<string, unknown> {
  const keys = assetPeerKeys(field);
  if (!asset) return { [keys.media]: 0, [keys.src]: '', [keys.alt]: '', [keys.caption]: '', [keys.title]: '' };
  const current = assetValueFromRecord(field, record);
  return {
    [keys.media]: Number(asset.id),
    [keys.src]: preferredAssetUrl(asset, fieldPreferredSet(field)),
    [keys.alt]: String(asset.alt_text || current.alt || ''),
    [keys.caption]: String(asset.caption || current.caption || ''),
    [keys.title]: String(asset.title || current.title || ''),
  };
}
function updateReplicatorAssetFromMedia(parent: NativeFieldDefinition, itemIndex: number, field: NativeFieldDefinition, asset: MediaAsset | null) {
  const items = [...arrayValue(parent)];
  const current = { ...(items[itemIndex] || {}) };
  updateReplicatorItem(parent, itemIndex, recordAssetPatch(field, asset, current));
}
function setReplicatorAssetField(parent: NativeFieldDefinition, itemIndex: number, field: NativeFieldDefinition, key: 'media' | 'src' | 'alt' | 'caption' | 'title', value: unknown) {
  const peer = assetPeerKeys(field)[key];
  updateReplicatorItem(parent, itemIndex, { [peer]: value });
}

function itemBlocks(item: any): EditorialBlock[] { return Array.isArray(item?.blocks) ? item.blocks as EditorialBlock[] : []; }
function blockSections(): Array<{ key: string; display: string; fields: NativeFieldDefinition[] }> {
  return (blockBlueprint(props.modelValue.type)?.sections || []) as Array<{ key: string; display: string; fields: NativeFieldDefinition[] }>;
}
function isSpecializedCommerceBlock(): boolean { return ['commerce_product','commerce_product_variants','commerce_product_list','storytelling'].includes(props.modelValue.type); }
</script>

<template>
  <div class="native-block-editor" :class="{ 'native-block-editor--nested': (depth || 0) > 0 }">
    <CommerceBlockDataEditor v-if="isSpecializedCommerceBlock()" :model-value="modelValue" :site-id="siteId" :language-code="languageCode" @update:model-value="$emit('update:modelValue', $event)" />
    <template v-else><section v-for="section in blockSections()" :key="section.key" class="native-blueprint-section">
      <h3 class="native-blueprint-section__title">{{ section.display }}</h3>
      <div class="block-grid">
        <template v-for="field in section.fields.filter((item) => !isHiddenAssetPeer(item, section.fields))" :key="field.handle">
          <label v-if="isPublishedFormField(field)" class="native-field" :style="{ gridColumn: `span ${field.width && field.width <= 50 ? 1 : 2}` }">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
            <select :value="stringValue(valueFor(field))" :disabled="formsLoading" @change="updateData(field.handle, ($event.target as HTMLSelectElement).value)">
              <option value="">{{ formsLoading ? 'Chargement des formulaires…' : (formPlaceholder || 'Sélectionner un formulaire publié') }}</option>
              <option v-for="form in formOptions || []" :key="form.form_key" :value="form.form_key">{{ formOptionLabel(form) }}</option>
            </select>
            <small v-if="!formsLoading && !(formOptions || []).length" class="field-help">Aucun formulaire publié disponible.</small>
          </label>
          <label v-else-if="field.type === 'toggle'" class="checkline checkline--modal native-field" :style="{ gridColumn: `span ${field.width && field.width <= 50 ? 1 : 2}` }">
            <input type="checkbox" :checked="Boolean(valueFor(field))" @change="updateData(field.handle, coerceInput(field, $event))">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
          </label>
          <label v-else-if="field.type === 'select' || options(field).length" class="native-field" :style="{ gridColumn: `span ${field.width && field.width <= 50 ? 1 : 2}` }">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
            <select :value="stringValue(valueFor(field))" @change="updateData(field.handle, coerceInput(field, $event))">
              <option v-for="option in options(field)" :key="String(option.value)" :value="String(option.value)">{{ option.label }}</option>
            </select>
          </label>
          <label v-else-if="field.type === 'integer' || field.type === 'range'" class="native-field" :style="{ gridColumn: `span ${field.width && field.width <= 50 ? 1 : 2}` }">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
            <input type="number" :value="stringValue(valueFor(field))" @input="updateData(field.handle, coerceInput(field, $event))">
          </label>
          <label v-else-if="['textarea','markdown','richtext','bard'].includes(field.type) || ['markdown','richtext','textarea'].includes(fieldInterface(field))" class="native-field native-field--wide">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
            <textarea :rows="Number(field.config?.rows || (field.type === 'richtext' ? 10 : 7))" :value="stringValue(valueFor(field))" @input="updateData(field.handle, coerceInput(field, $event))" />
          </label>
          <label v-else-if="['code','yaml'].includes(field.type)" class="native-field native-field--wide">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
            <textarea class="font-monospace" rows="8" :value="stringValue(valueFor(field))" @input="updateData(field.handle, coerceInput(field, $event))" />
          </label>
          <div v-else-if="isAssetField(field)" class="native-field native-field--wide native-asset-field">
            <MediaPicker
              :accept-type="fieldAcceptType(field)"
              :label="field.display"
              :help="fieldHelp(field) || 'Choisissez une image dans la médiathèque ou téléversez-la. L’URL technique est remplie automatiquement avec la variante responsive adaptée.'"
              :preferred-set="fieldPreferredSet(field)"
              :media-id="Number(assetValue(field).media_id || 0)"
              :src="stringValue(assetValue(field).src || '')"
              :alt="stringValue(assetValue(field).alt || '')"
              :caption="stringValue(assetValue(field).caption || '')"
              :title="stringValue(assetValue(field).title || '')"
              @select="setAssetFromMedia(field, $event)"
              @update:media-id="setAssetField(field, 'media', $event)"
              @update:src="setAssetField(field, 'src', $event)"
              @update:alt="setAssetField(field, 'alt', $event)"
              @update:caption="setAssetField(field, 'caption', $event)"
              @update:title="setAssetField(field, 'title', $event)"
            />
            <details class="native-asset-field__technical">
              <summary>Valeurs techniques</summary>
              <label>ID média<input type="number" :value="Number(assetValue(field).media_id || 0)" @input="setAssetField(field, 'media', Number(($event.target as HTMLInputElement).value || 0))"></label>
              <label>URL image<input :value="stringValue(assetValue(field).src || '')" placeholder="/storage/media/..." @input="setAssetField(field, 'src', ($event.target as HTMLInputElement).value)"></label>
              <label>Texte alternatif<input :value="stringValue(assetValue(field).alt || '')" @input="setAssetField(field, 'alt', ($event.target as HTMLInputElement).value)"></label>
              <label>Légende<input :value="stringValue(assetValue(field).caption || '')" @input="setAssetField(field, 'caption', ($event.target as HTMLInputElement).value)"></label>
              <label>Titre technique<input :value="stringValue(assetValue(field).title || '')" @input="setAssetField(field, 'title', ($event.target as HTMLInputElement).value)"></label>
            </details>
          </div>
          <div v-else-if="isReplicatorField(field)" class="native-field native-field--wide native-replicator">
            <div class="nested-panel__header">
              <h3>{{ field.display }} <InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></h3>
              <button type="button" class="chip" @click="addReplicatorItem(field)">+ Élément</button>
            </div>
            <div v-for="(item, itemIndex) in arrayValue(field)" :key="itemIndex" class="nested-panel native-replicator__item">
              <div class="nested-panel__header">
                <h3>{{ itemLabel(fieldFields(field), item, itemIndex) }}</h3>
                <button type="button" @click="removeReplicatorItem(field, itemIndex)">Supprimer</button>
              </div>
              <div class="block-grid">
                <template v-for="childField in fieldFields(field).filter((f) => !isBlocksReplicatorField(f) && !isHiddenAssetPeer(f, fieldFields(field)))" :key="childField.handle">
                  <label v-if="childField.type === 'select' || options(childField).length" class="native-field" :style="{ gridColumn: `span ${childField.width && childField.width <= 50 ? 1 : 2}` }">
                    <span class="native-label-with-hint"><span>{{ childField.display }}</span><InfoHint v-if="fieldHelp(childField)" :text="fieldHelp(childField)" placement="end" /></span>
                    <select :value="stringValue(item?.[childField.handle] ?? defaultValue(childField))" @change="updateReplicatorItem(field, itemIndex, { [childField.handle]: ($event.target as HTMLSelectElement).value })">
                      <option v-for="option in options(childField)" :key="String(option.value)" :value="String(option.value)">{{ option.label }}</option>
                    </select>
                  </label>
                  <div v-else-if="isAssetField(childField)" class="native-field native-field--wide native-asset-field">
                    <MediaPicker
                      :accept-type="fieldAcceptType(childField)"
                      :label="childField.display"
                      :help="fieldHelp(childField) || 'Choisissez une image. Les champs techniques liés sont synchronisés automatiquement.'"
                      :compact="true"
                      :preferred-set="fieldPreferredSet(childField)"
                      :media-id="Number(assetValueFromRecord(childField, item || {}).media_id || 0)"
                      :src="stringValue(assetValueFromRecord(childField, item || {}).src || '')"
                      :alt="stringValue(assetValueFromRecord(childField, item || {}).alt || '')"
                      :caption="stringValue(assetValueFromRecord(childField, item || {}).caption || '')"
                      :title="stringValue(assetValueFromRecord(childField, item || {}).title || '')"
                      @select="updateReplicatorAssetFromMedia(field, itemIndex, childField, $event)"
                      @update:media-id="setReplicatorAssetField(field, itemIndex, childField, 'media', $event)"
                      @update:src="setReplicatorAssetField(field, itemIndex, childField, 'src', $event)"
                      @update:alt="setReplicatorAssetField(field, itemIndex, childField, 'alt', $event)"
                      @update:caption="setReplicatorAssetField(field, itemIndex, childField, 'caption', $event)"
                      @update:title="setReplicatorAssetField(field, itemIndex, childField, 'title', $event)"
                    />
                  </div>
                  <label v-else-if="childField.type === 'toggle'" class="checkline checkline--modal native-field">
                    <input type="checkbox" :checked="Boolean(item?.[childField.handle] ?? defaultValue(childField))" @change="updateReplicatorItem(field, itemIndex, { [childField.handle]: ($event.target as HTMLInputElement).checked })"> <span class="native-label-with-hint"><span>{{ childField.display }}</span><InfoHint v-if="fieldHelp(childField)" :text="fieldHelp(childField)" placement="end" /></span>
                  </label>
                  <label v-else-if="['textarea','markdown','richtext','bard'].includes(childField.type) || ['markdown','richtext','textarea'].includes(fieldInterface(childField))" class="native-field native-field--wide">
                    <span class="native-label-with-hint"><span>{{ childField.display }}</span><InfoHint v-if="fieldHelp(childField)" :text="fieldHelp(childField)" placement="end" /></span>
                    <textarea :rows="Number(childField.config?.rows || (childField.type === 'richtext' ? 10 : 7))" :value="stringValue(item?.[childField.handle] ?? defaultValue(childField))" @input="updateReplicatorItem(field, itemIndex, { [childField.handle]: ($event.target as HTMLTextAreaElement).value })" />
                  </label>
                  <label v-else class="native-field" :style="{ gridColumn: `span ${childField.width && childField.width <= 50 ? 1 : 2}` }">
                    <span class="native-label-with-hint"><span>{{ childField.display }}</span><InfoHint v-if="fieldHelp(childField)" :text="fieldHelp(childField)" placement="end" /></span>
                    <input :value="stringValue(item?.[childField.handle] ?? defaultValue(childField))" @input="updateReplicatorItem(field, itemIndex, { [childField.handle]: ($event.target as HTMLInputElement).value })">
                  </label>
                </template>
              </div>
              <template v-for="blocksField in fieldFields(field).filter((f) => isBlocksReplicatorField(f))" :key="blocksField.handle">
                <div class="native-blocks-replicator">
                  <div class="native-blocks-replicator__toolbar">
                    <strong>{{ blocksField.display }} <InfoHint v-if="fieldHelp(blocksField)" :text="fieldHelp(blocksField)" placement="end" /></strong>
                    <select @change="addNestedBlock(field, itemIndex, ($event.target as HTMLSelectElement).value as BlockType); ($event.target as HTMLSelectElement).value = ''">
                      <option value="">+ Ajouter un sous-bloc</option>
                      <option v-for="[type, label] in allowedBlocks(blocksField)" :key="type" :value="type">{{ label }}</option>
                    </select>
                  </div>
                  <div v-for="(child, childIndex) in itemBlocks(item)" :key="child.id || childIndex" class="nested-child native-child-block">
                    <div class="nested-child__header">
                      <select :value="child.type" @change="updateNestedBlock(field, itemIndex, childIndex, { ...child, type: ($event.target as HTMLSelectElement).value as BlockType, data: defaultData(($event.target as HTMLSelectElement).value as BlockType) })">
                        <option v-for="[type, label] in allowedBlocks(blocksField)" :key="type" :value="type">{{ label }}</option>
                      </select>
                      <input :value="child.label || ''" placeholder="Libellé interne" @input="updateNestedBlock(field, itemIndex, childIndex, { ...child, label: ($event.target as HTMLInputElement).value })">
                      <select :value="child.editorial_status || 'published'" @change="updateNestedBlock(field, itemIndex, childIndex, { ...child, editorial_status: ($event.target as HTMLSelectElement).value as EditorialStatus })">
                        <option v-for="[value, label] in statuses" :key="value" :value="value">{{ label }}</option>
                      </select>
                      <button type="button" class="danger" @click="removeNestedBlock(field, itemIndex, childIndex)">Supprimer</button>
                    </div>
                    <NativeBlockDataEditor
                      :model-value="child"
                      :blueprints="blueprints"
                      :fieldsets="fieldsets"
                      :block-types="blockTypes"
                      :statuses="statuses"
                      :allowed-block-types="allowedBlocks(blocksField).map(([type]) => type)"
                      :depth="(depth || 0) + 1"
                      :site-id="siteId"
                      :language-code="languageCode"
                      @update:model-value="updateNestedBlock(field, itemIndex, childIndex, $event)"
                    />
                  </div>
                </div>
              </template>
            </div>
            <p v-if="arrayValue(field).length === 0" class="muted">Aucun élément pour le moment.</p>
          </div>
          <label v-else class="native-field" :style="{ gridColumn: `span ${field.width && field.width <= 50 ? 1 : 2}` }">
            <span class="native-label-with-hint"><span>{{ field.display }}</span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></span>
            <input :type="field.type === 'color' ? 'color' : field.type === 'date' ? 'date' : field.type === 'time' ? 'time' : 'text'" :value="stringValue(valueFor(field))" @input="updateData(field.handle, coerceInput(field, $event))">
          </label>
        </template>
      </div>
    </section></template>
  </div>
</template>
