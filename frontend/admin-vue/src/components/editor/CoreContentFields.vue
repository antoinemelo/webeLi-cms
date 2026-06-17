<script setup lang="ts">
import type { EditorSchema } from '@/api/contracts';
import InfoHint from '@/components/ui/InfoHint.vue';
import { blueprintField, blueprintFieldHelp, coreFieldRequired, fieldHelp, fieldLabel, fieldRequired, fieldWidthClass, isRoutingEnabled } from '@/stores/contentTypes';
export type CoreContentModel = { title: string; slug: string; entry_key: string };
export type EditorialDateInfo = { label: string; value?: string | null };
const props = defineProps<{
  modelValue: CoreContentModel;
  schema?: EditorSchema | null;
  errors?: Record<string, string[]>;
  publicUrl?: string;
  slugManualMode?: boolean;
  contentTypeKey?: string;
  customFields?: Record<string, unknown>;
  editorialDates?: EditorialDateInfo[];
}>();
const emit = defineEmits<{
  'update:modelValue': [value: CoreContentModel];
  'update:customFields': [value: Record<string, unknown>];
  'field-blur': [field: keyof CoreContentModel | 'author_name' | 'display_published_at'];
  'enable-manual-slug': [];
  'reset-auto-slug': [];
}>();
function setField(key: keyof CoreContentModel, value: string) { emit('update:modelValue', { ...props.modelValue, [key]: value }); }
function finishField(key: keyof CoreContentModel) { emit('field-blur', key); }
function err(key: keyof CoreContentModel): string[] { return props.errors?.[key] ?? props.errors?.[`localization.${key}`] ?? props.errors?.[`entry.${key}`] ?? []; }
function coreField(key: keyof CoreContentModel) {
  return blueprintField(props.schema || null, key);
}
function coreWidthClass(key: keyof CoreContentModel): string {
  const field = coreField(key);
  return field ? fieldWidthClass(field) : (key === 'entry_key' ? 'schema-field--full' : 'schema-field--half');
}
function coreHelp(key: keyof CoreContentModel): string {
  return blueprintFieldHelp(props.schema || null, key);
}
function articleField(key: 'author_name' | 'display_published_at') {
  return blueprintField(props.schema || null, key === 'display_published_at' ? ['display_published_at', 'publication_date'] : key);
}
function articleFieldLabel(key: 'author_name' | 'display_published_at', fallback: string): string {
  const field = articleField(key);
  return field ? fieldLabel(field) : fallback;
}
function articleFieldHelp(key: 'author_name' | 'display_published_at'): string {
  const field = articleField(key);
  return field ? fieldHelp(field) : '';
}
function articleFieldRequired(key: 'author_name' | 'display_published_at'): boolean {
  const field = articleField(key);
  return field ? fieldRequired(field) : false;
}
function routingEnabled(): boolean { return isRoutingEnabled(props.schema || null); }
function slugReadonly(): boolean { return routingEnabled() && !props.slugManualMode; }
function slugHelp(): string {
  const configuredHelp = coreHelp('slug');
  if (configuredHelp) return configuredHelp;
  return props.slugManualMode
    ? 'Slug modifiable manuellement. Utilisez des minuscules, chiffres et tirets pour conserver une URL stable.'
    : 'Slug généré automatiquement depuis le titre. Utilisez l’icône de réglage pour le modifier manuellement.';
}

function isArticleEditor(): boolean {
  return String(props.contentTypeKey || '').toLowerCase() === 'article';
}
function customValue(key: string): string {
  return String(props.customFields?.[key] ?? '');
}
function setCustomField(key: string, value: string) {
  emit('update:customFields', { ...(props.customFields || {}), [key]: key === 'display_published_at' ? fromDateTimeLocal(value) : value });
}
function errCustom(key: string): string[] {
  return props.errors?.[key] ?? props.errors?.[`fields.${key}`] ?? [];
}
function toDateTimeLocal(value: unknown): string {
  const raw = String(value ?? '').trim();
  if (!raw) return '';
  const normalized = raw.replace(' ', 'T');
  const match = normalized.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
  return match ? `${match[1]}T${match[2]}` : '';
}
function fromDateTimeLocal(value: string): string {
  const text = String(value || '').trim();
  return text ? `${text.replace('T', ' ')}:00` : '';
}
function formatInfoDate(value?: string | null): string {
  const raw = String(value || '').trim();
  if (!raw) return '—';
  const match = raw.replace('T', ' ').match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
  if (!match) return raw;
  const [, year, month, day, hour, minute] = match;
  return `${day}.${month}.${year} ${hour}:${minute}`;
}
function visibleEditorialDates(): EditorialDateInfo[] {
  return (props.editorialDates || []).filter((item) => String(item?.label || '').trim() !== '');
}
</script>
<template>
  <section class="schema-section">
    <h2>Identité éditoriale <InfoHint text="La clé d’entrée relie les traductions d’une même page ou d’un même article. Le slug reste propre à chaque langue pour produire des URLs lisibles." placement="end" /></h2>
    <div class="grid schema-grid core-identity-grid">
      <div class="field schema-field core-identity-field" :class="coreWidthClass('title')">
        <label for="core-title" class="schema-label-with-hint"><span>Titre <span v-if="coreFieldRequired(schema || null, 'title')" class="required">*</span></span><InfoHint v-if="coreHelp('title')" :text="coreHelp('title')" placement="end" /></label>
        <input id="core-title" class="input" :value="modelValue.title" :required="coreFieldRequired(schema || null, 'title')" :aria-invalid="Boolean(err('title').length)" @input="setField('title', ($event.target as HTMLInputElement).value)" @blur="finishField('title')" />
        <small v-if="err('title').length" class="field-error">{{ err('title').join(' ') }}</small>
      </div>
      <div class="field schema-field core-identity-field" :class="coreWidthClass('slug')">
        <label for="core-slug" class="schema-label-with-hint"><span>Slug <span v-if="coreFieldRequired(schema || null, 'slug') && routingEnabled()" class="required">*</span></span><InfoHint :text="slugHelp()" placement="end" /></label>
        <div class="slug-control" :class="{ 'is-auto': slugReadonly(), 'is-manual': slugManualMode }">
          <input
            id="core-slug"
            class="input"
            :value="modelValue.slug"
            :required="coreFieldRequired(schema || null, 'slug') && routingEnabled()"
            :disabled="!routingEnabled()"
            :readonly="slugReadonly()"
            :aria-invalid="Boolean(err('slug').length)"
            @input="setField('slug', ($event.target as HTMLInputElement).value)"
            @blur="finishField('slug')"
          />
          <button
            v-if="routingEnabled() && !slugManualMode"
            type="button"
            class="slug-control__button"
            title="Modifier le slug manuellement"
            aria-label="Modifier le slug manuellement"
            @click="emit('enable-manual-slug')"
          ><svg class="slug-control__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.902 3.433 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.892 3.433-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.892-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115l.094-.319z"/></svg></button>
          <button
            v-if="routingEnabled() && slugManualMode"
            type="button"
            class="slug-control__button slug-control__button--reset"
            title="Revenir au slug automatique"
            aria-label="Revenir au slug automatique"
            @click="emit('reset-auto-slug')"
          >Auto</button>
        </div>
        <small v-if="routingEnabled()" class="field-help">{{ slugManualMode ? 'Mode manuel : le titre ne changera plus ce slug.' : 'Mode automatique : le slug suit le titre.' }}</small>
        <small v-if="err('slug').length" class="field-error">{{ err('slug').join(' ') }}</small>
      </div>
    </div>
    <div class="field schema-field" :class="coreWidthClass('entry_key')">
      <label for="core-entry-key" class="schema-label-with-hint"><span>Clé d’entrée <span v-if="coreFieldRequired(schema || null, 'entry_key')" class="required">*</span></span><InfoHint v-if="coreHelp('entry_key')" :text="coreHelp('entry_key')" placement="end" /></label>
      <input id="core-entry-key" class="input" :value="modelValue.entry_key" placeholder="ex. equipe, services, article-seo" :required="coreFieldRequired(schema || null, 'entry_key')" :aria-invalid="Boolean(err('entry_key').length)" @input="setField('entry_key', ($event.target as HTMLInputElement).value)" @blur="finishField('entry_key')" />
      <small v-if="err('entry_key').length" class="field-error">{{ err('entry_key').join(' ') }}</small>
    </div>
    <div v-if="isArticleEditor()" class="grid schema-grid article-editorial-grid">
      <div class="field schema-field schema-field--half">
        <label for="article-author-name" class="schema-label-with-hint"><span>{{ articleFieldLabel('author_name', 'Auteur') }} <span v-if="articleFieldRequired('author_name')" class="required">*</span></span><InfoHint v-if="articleFieldHelp('author_name')" :text="articleFieldHelp('author_name')" placement="end" /></label>
        <input id="article-author-name" class="input" :value="customValue('author_name')" placeholder="Nom public de l’auteur" :required="articleFieldRequired('author_name')" :aria-invalid="Boolean(errCustom('author_name').length)" @input="setCustomField('author_name', ($event.target as HTMLInputElement).value)" @blur="emit('field-blur', 'author_name')" />
        <small v-if="errCustom('author_name').length" class="field-error">{{ errCustom('author_name').join(' ') }}</small>
      </div>
      <div class="field schema-field schema-field--half">
        <label for="article-display-published-at" class="schema-label-with-hint"><span>{{ articleFieldLabel('display_published_at', 'Date affichée') }} <span v-if="articleFieldRequired('display_published_at')" class="required">*</span></span><InfoHint v-if="articleFieldHelp('display_published_at')" :text="articleFieldHelp('display_published_at')" placement="end" /></label>
        <input id="article-display-published-at" class="input" type="datetime-local" :value="toDateTimeLocal(customValue('display_published_at'))" :required="articleFieldRequired('display_published_at')" :aria-invalid="Boolean(errCustom('display_published_at').length)" @input="setCustomField('display_published_at', ($event.target as HTMLInputElement).value)" @blur="emit('field-blur', 'display_published_at')" />
        <small v-if="errCustom('display_published_at').length" class="field-error">{{ errCustom('display_published_at').join(' ') }}</small>
      </div>
    </div>
    <div v-if="isArticleEditor() && visibleEditorialDates().length" class="editorial-dates" aria-label="Dates système">
      <dl>
        <div v-for="item in visibleEditorialDates()" :key="item.label">
          <dt>{{ item.label }}</dt>
          <dd>{{ formatInfoDate(item.value) }}</dd>
        </div>
      </dl>
    </div>
  </section>
</template>

<style scoped>
.core-identity-grid {
  align-items: start;
}
.core-identity-field {
  align-self: start;
  margin-bottom: 0;
}
.core-identity-field .input {
  min-height: 2.7rem;
  height: 2.7rem;
}
.slug-control {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.45rem;
  align-items: stretch;
}
.slug-control .input[readonly] {
  background: #f8fafc;
  color: #475569;
  cursor: default;
}
.slug-control__button {
  min-width: 2.7rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #cbd5e1;
  border-radius: 0.75rem;
  background: #fff;
  color: #0f172a;
  font-weight: 900;
  cursor: pointer;
}
.slug-control__button:hover {
  background: #f8fafc;
  border-color: #94a3b8;
}
.slug-control__icon {
  width: 1rem;
  height: 1rem;
  fill: currentColor;
}
.slug-control__button--reset {
  min-width: 3.8rem;
  padding: 0 0.7rem;
  font-size: 0.82rem;
}
.field-help {
  display: block;
  margin-top: 0.35rem;
  color: var(--muted);
  font-size: 0.82rem;
}
.article-editorial-grid {
  margin-top: 1rem;
}
.editorial-dates {
  margin-top: 0.75rem;
  color: #64748b;
}
.editorial-dates dl {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin: 0;
  font-size: 0.72rem;
  line-height: 1.2;
}
.editorial-dates dl > div {
  min-width: 0;
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
  padding: 0.4rem 0.55rem;
  border-radius: 0.65rem;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  white-space: nowrap;
}
.editorial-dates dt {
  flex: 0 0 auto;
  font-weight: 800;
  color: #475569;
}
.editorial-dates dd {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  color: #64748b;
  overflow: hidden;
  text-overflow: ellipsis;
}
@media (max-width: 720px) {
  .editorial-dates dl {
    grid-template-columns: 1fr;
  }
}
</style>
