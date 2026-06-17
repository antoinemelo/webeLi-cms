<script setup lang="ts">
import { computed } from 'vue';
import type { SchemaField, MediaAsset, MediaVariant } from '@/api/contracts';
import { fieldChildren, fieldConfig, fieldHelp, fieldKey, fieldLabel, fieldOptions, fieldRequired, fieldType, fieldWidthClass } from '@/stores/contentTypes';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import InfoHint from '@/components/ui/InfoHint.vue';

const props = defineProps<{ field: SchemaField; modelValue: unknown; errors?: string[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>();

const key = computed(() => fieldKey(props.field));
const type = computed(() => fieldType(props.field));
const config = computed(() => fieldConfig(props.field));
const options = computed(() => fieldOptions(props.field));
const children = computed(() => fieldChildren(props.field));
const describedBy = computed(() => `${key.value}-help ${key.value}-error`);
const isMediaField = computed(() => {
  const raw = props.field as Record<string, unknown>;
  const interfaceKey = String(raw.interface_key ?? raw.ui_interface ?? config.value.interface_key ?? '').toLowerCase();
  return ['media', 'asset', 'assets', 'image'].includes(type.value) || interfaceKey === 'assets' || interfaceKey === 'media';
});

function asRecord(value: unknown): Record<string, unknown> { return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}; }
function asArray(value: unknown): unknown[] { return Array.isArray(value) ? value : []; }
function inputValue(value: unknown): string { return value === null || value === undefined ? '' : String(value); }
function datetimeValue(value: unknown): string { return inputValue(value).replace(' ', 'T').slice(0, 16); }
function updateScalar(event: Event) {
  const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
  if (['boolean', 'checkbox', 'toggle'].includes(type.value)) emit('update:modelValue', (target as HTMLInputElement).checked);
  else if (['number', 'integer', 'range'].includes(type.value)) emit('update:modelValue', target.value === '' ? null : Number(target.value));
  else if (type.value === 'json') emit('update:modelValue', safeJson(target.value));
  else emit('update:modelValue', target.value);
}
function safeJson(value: string): unknown {
  try { return value.trim() === '' ? null : JSON.parse(value); } catch { return value; }
}
function jsonValue(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return '';
  try { return JSON.stringify(value, null, 2); } catch { return String(value); }
}
function inputType(): string {
  if (type.value === 'date') return 'date';
  if (['datetime', 'datetime-local'].includes(type.value)) return 'datetime-local';
  if (type.value === 'time') return 'time';
  if (['number', 'integer', 'range'].includes(type.value)) return 'number';
  if (type.value === 'email') return 'email';
  if (type.value === 'url') return 'url';
  return 'text';
}
function setGroupChild(child: SchemaField, value: unknown) {
  emit('update:modelValue', { ...asRecord(props.modelValue), [fieldKey(child)]: value });
}
function addRepeaterItem() {
  const defaults = Object.fromEntries(children.value.map((child) => [fieldKey(child), child.default_value ?? null]));
  emit('update:modelValue', [...asArray(props.modelValue), defaults]);
}
function setRepeaterChild(index: number, child: SchemaField, value: unknown) {
  const next = [...asArray(props.modelValue)];
  const current = asRecord(next[index]);
  next[index] = { ...current, [fieldKey(child)]: value };
  emit('update:modelValue', next);
}
function removeRepeaterItem(index: number) {
  emit('update:modelValue', asArray(props.modelValue).filter((_, i) => i !== index));
}
function setMultiSelect(event: Event) {
  const selected = Array.from((event.target as HTMLSelectElement).selectedOptions).map((option) => option.value);
  emit('update:modelValue', selected);
}
function preferredMediaSet(): 'content'|'hero'|'open_graph' {
  const raw = props.field as Record<string, unknown>;
  const headless = raw.headless && typeof raw.headless === 'object' ? raw.headless as Record<string, unknown> : {};
  const explicit = String(config.value.preferred_media_set ?? config.value.preferred_set ?? headless.preferred_media_set ?? '').toLowerCase();
  if (explicit === 'hero' || explicit === 'open_graph' || explicit === 'content') return explicit;
  const name = `${key.value} ${fieldLabel(props.field)} ${JSON.stringify(config.value)} ${JSON.stringify(headless)}`.toLowerCase();
  if (name.includes('open_graph') || name.includes('og_') || name.includes('social')) return 'open_graph';
  if (name.includes('hero')) return 'hero';
  return 'content';
}
function mediaAcceptType(): 'image'|'video'|'audio'|'document'|'any' {
  const rawMime = props.field.validation?.allowed_mime ?? config.value.allowed_mime ?? config.value.allowed_mime_types;
  const serialized = JSON.stringify(rawMime ?? config.value).toLowerCase();
  if (serialized.includes('video/')) return 'video';
  if (serialized.includes('audio/')) return 'audio';
  if (serialized.includes('application/pdf') || serialized.includes('document')) return 'document';
  if (serialized.includes('*') || serialized.includes('any')) return 'any';
  return 'image';
}
function mediaUrl(asset: MediaAsset): string {
  const setName = preferredMediaSet();
  const preferred = setName === 'hero' ? ['hero_1280','hero_960','hero_640'] : setName === 'open_graph' ? ['og_1200x630'] : ['content_1280','content_1024','content_768','content_480'];
  const set = asset.responsive_sets?.[setName];
  for (const variantKey of preferred) {
    const variant = set?.find((item: MediaVariant) => item.variant_key === variantKey && item.public_url);
    if (variant?.public_url) return String(variant.public_url);
  }
  return String(asset.public_url || asset.path || asset.thumbnail_url || '');
}
function setMedia(asset: MediaAsset | null) {
  if (!asset) {
    emit('update:modelValue', { media_id: 0, src: '', alt: '', caption: '', title: '' });
    return;
  }
  const current = asRecord(props.modelValue);
  emit('update:modelValue', {
    ...current,
    media_id: Number(asset.id),
    src: mediaUrl(asset),
    alt: String(asset.alt_text || current.alt || ''),
    caption: String(asset.caption || current.caption || ''),
    title: String(asset.title || current.title || asset.original_filename || asset.filename || ''),
  });
}
function setMediaPeer(peer: 'media_id' | 'src' | 'alt' | 'caption' | 'title', value: unknown) {
  const current = asRecord(props.modelValue);
  const next = { ...current, [peer]: peer === 'media_id' ? Number(value || 0) : String(value || '') };
  if (peer === 'media_id' && Number(value || 0) === 0) {
    next.src = '';
    next.alt = '';
    next.caption = '';
    next.title = '';
  }
  emit('update:modelValue', next);
}
function mediaId(): number {
  const value = props.modelValue;
  if (typeof value === 'number') return value;
  return Number(asRecord(value).media_id || asRecord(value).id || 0);
}
function mediaSrc(): string {
  const value = props.modelValue;
  if (typeof value === 'string') return value;
  return String(asRecord(value).src || asRecord(value).public_url || asRecord(value).url || '');
}
function mediaAlt(): string { return String(asRecord(props.modelValue).alt || ''); }
function mediaCaption(): string { return String(asRecord(props.modelValue).caption || ''); }
function mediaTitle(): string { return String(asRecord(props.modelValue).title || ''); }
function attr(name: string): string | number | undefined {
  const value = config.value[name];
  return typeof value === 'string' || typeof value === 'number' ? value : undefined;
}
</script>
<template>
  <fieldset v-if="type === 'group'" class="field schema-field schema-field--group" :class="fieldWidthClass(field)">
    <legend>{{ fieldLabel(field) }} <span v-if="fieldRequired(field)" class="required">*</span> <InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></legend>
    <div class="schema-grid">
      <SchemaFieldRenderer v-for="child in children" :key="fieldKey(child)" :field="child" :model-value="asRecord(modelValue)[fieldKey(child)]" :errors="[]" @update:model-value="setGroupChild(child, $event)" />
    </div>
  </fieldset>

  <fieldset v-else-if="type === 'repeater'" class="field schema-field schema-field--repeater" :class="fieldWidthClass(field)">
    <legend>{{ fieldLabel(field) }} <span v-if="fieldRequired(field)" class="required">*</span> <InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></legend>
    <div v-for="(item, index) in asArray(modelValue)" :key="index" class="schema-repeater-item">
      <div class="schema-repeater-item__head"><strong>Élément {{ index + 1 }}</strong><button type="button" class="btn ghost" @click="removeRepeaterItem(index)">Retirer</button></div>
      <div class="schema-grid">
        <SchemaFieldRenderer v-for="child in children" :key="fieldKey(child)" :field="child" :model-value="asRecord(item)[fieldKey(child)]" :errors="[]" @update:model-value="setRepeaterChild(index, child, $event)" />
      </div>
    </div>
    <button type="button" class="btn ghost" @click="addRepeaterItem">Ajouter un élément</button>
  </fieldset>

  <div v-else class="field schema-field" :class="fieldWidthClass(field)">
    <label v-if="!isMediaField" :for="key" class="schema-label-with-hint"><span>{{ fieldLabel(field) }} <span v-if="fieldRequired(field)" class="required">*</span></span><InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" /></label>

    <textarea v-if="['textarea','richtext','markdown'].includes(type)" :id="key" :value="inputValue(modelValue)" :placeholder="field.placeholder" :rows="Number(config.rows || (type === 'textarea' ? 4 : 8))" :required="fieldRequired(field)" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @input="updateScalar" />

    <textarea v-else-if="type === 'json'" :id="key" :value="jsonValue(modelValue)" rows="8" spellcheck="false" class="schema-json" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @input="updateScalar" />

    <select v-else-if="type === 'select' || (options.length > 0 && type !== 'multiselect')" :id="key" class="select" :value="inputValue(modelValue)" :required="fieldRequired(field)" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @change="updateScalar">
      <option value="">—</option>
      <option v-for="option in options" :key="String(option.value)" :value="String(option.value)">{{ option.label }}</option>
    </select>

    <select v-else-if="type === 'multiselect'" :id="key" class="select" multiple :value="asArray(modelValue).map(String)" :required="fieldRequired(field)" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @change="setMultiSelect">
      <option v-for="option in options" :key="String(option.value)" :value="String(option.value)">{{ option.label }}</option>
    </select>

    <label v-else-if="['boolean','checkbox','toggle'].includes(type)" class="checkline">
      <input :id="key" type="checkbox" :checked="Boolean(modelValue)" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @change="updateScalar" /> Actif
    </label>

    <MediaPicker
      v-else-if="isMediaField"
      :accept-type="mediaAcceptType()"
      :label="fieldLabel(field)"
      :help="fieldHelp(field) || 'Choisissez une image source dans la médiathèque ou téléversez-la. L’URL publique est synchronisée automatiquement avec la variante responsive adaptée.'"
      :preferred-set="preferredMediaSet()"
      :required="fieldRequired(field)"
      :media-id="mediaId()"
      :src="mediaSrc()"
      :alt="mediaAlt()"
      :caption="mediaCaption()"
      :title="mediaTitle()"
      @select="setMedia"
      @update:media-id="setMediaPeer('media_id', $event)"
      @update:src="setMediaPeer('src', $event)"
      @update:alt="setMediaPeer('alt', $event)"
      @update:caption="setMediaPeer('caption', $event)"
      @update:title="setMediaPeer('title', $event)"
    />

    <input v-else-if="type === 'datetime' || type === 'datetime-local'" :id="key" class="input" type="datetime-local" :value="datetimeValue(modelValue)" :placeholder="field.placeholder" :required="fieldRequired(field)" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @input="updateScalar" />

    <input v-else :id="key" class="input" :type="inputType()" :value="inputValue(modelValue)" :placeholder="field.placeholder" :required="fieldRequired(field)" :min="attr('min')" :max="attr('max')" :step="attr('step')" :aria-describedby="describedBy" :aria-invalid="Boolean(errors?.length)" @input="updateScalar" />

    <small v-if="errors?.length" :id="`${key}-error`" class="field-error">{{ errors.join(' ') }}</small>
  </div>
</template>
