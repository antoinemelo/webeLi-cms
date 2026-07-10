<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { BlueprintField, FieldConfig, FieldType } from './types';

const props = defineProps<{ field: BlueprintField; fieldTypes: FieldType[]; mode: 'blueprint'|'fieldset'; isNew?: boolean; canManage: boolean }>();
const emit = defineEmits<{ apply: []; cancel: []; duplicate: []; remove: []; jsonError: [message: string] }>();
const initialFocus = ref<HTMLInputElement|null>(null);
const closeButton = ref<HTMLButtonElement|null>(null);
const jsonBuffers = ref({ options: '', validation: '', conditions: '', config: '' });
const jsonErrors = ref<Record<string, string>>({});
const typeWarning = ref('');
const choiceRevision = ref(0);

const purposeLabels: Record<string, string> = { content: 'Contenu', seo: 'SEO', page_builder: 'Page builder', metadata: 'Métadonnées', system: 'Système' };
const choiceTypes = ['select', 'multiselect', 'radio', 'checkboxes', 'button_group'];
const textTypes = ['text', 'textarea', 'richtext', 'markdown', 'slug', 'link', 'code', 'yaml'];
const numericTypes = ['number', 'integer', 'range'];
const mediaTypes = ['media', 'assets'];
const relationTypes = ['relation', 'entries'];
const canApply = computed(() => Object.keys(jsonErrors.value).length === 0);
const hasTypedConfiguration = computed(() => isChoiceType.value || isTextType.value || isNumericType.value || isMediaType.value || isRelationType.value);
const isChoiceType = computed(() => choiceTypes.includes(props.field.field_type));
const isTextType = computed(() => textTypes.includes(props.field.field_type));
const isNumericType = computed(() => numericTypes.includes(props.field.field_type));
const isMediaType = computed(() => mediaTypes.includes(props.field.field_type));
const isRelationType = computed(() => relationTypes.includes(props.field.field_type));

function normalizeHandle(value: string): string { return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, ''); }
function fieldTypeLabel(type: string): string { return props.fieldTypes.find((item) => item.handle === type)?.label || type; }
function fieldPurpose(type: string): string { return props.fieldTypes.find((item) => item.handle === type)?.purpose || 'content'; }
function ensureRecord(value: unknown): FieldConfig { return value && typeof value === 'object' && !Array.isArray(value) ? value as FieldConfig : {}; }
function hasLegacyPayload(value: unknown): boolean {
  if (value === undefined || value === null) return false;
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'object') return Object.keys(value as Record<string, unknown>).length > 0;
  return true;
}
function editableRecord(key: 'options'|'validation'|'config'): FieldConfig {
  const current = props.field[key];
  if (current && typeof current === 'object' && !Array.isArray(current)) return current as FieldConfig;
  const next: FieldConfig = {};
  if (hasLegacyPayload(current)) next._legacy_value = current;
  props.field[key] = next;
  return next;
}
function readOptions(): FieldConfig { return ensureRecord(props.field.options); }
function readValidation(): FieldConfig { return ensureRecord(props.field.validation); }
function readConfig(): FieldConfig { return ensureRecord(props.field.config); }
function editOptions(): FieldConfig { return editableRecord('options'); }
function editValidation(): FieldConfig { return editableRecord('validation'); }
function editConfig(): FieldConfig { return editableRecord('config'); }
function formatJson(value: unknown): string { return JSON.stringify(value ?? (Array.isArray(value) ? [] : {}), null, 2); }
function syncJsonBuffers(): void {
  jsonBuffers.value = {
    options: formatJson(props.field.options || {}),
    validation: formatJson(props.field.validation || {}),
    conditions: formatJson(props.field.conditions || []),
    config: formatJson(props.field.config || {})
  };
  jsonErrors.value = {};
}
function assignJson(key: 'options'|'validation'|'conditions'|'config', value: string): void {
  jsonBuffers.value[key] = value;
  try {
    const parsed = JSON.parse(value || (key === 'conditions' ? '[]' : '{}'));
    if (key === 'conditions') props.field.conditions = Array.isArray(parsed) ? parsed : [];
    else props.field[key] = ensureRecord(parsed);
    const { [key]: _removed, ...rest } = jsonErrors.value;
    jsonErrors.value = rest;
  } catch {
    jsonErrors.value = { ...jsonErrors.value, [key]: `JSON invalide dans ${key}.` };
  }
}
function onKeydown(event: KeyboardEvent): void { if (event.key === 'Escape') { event.preventDefault(); emit('cancel'); } }
function requestApply(): void {
  if (!canApply.value) { emit('jsonError', Object.values(jsonErrors.value).join(' ')); return; }
  emit('apply');
}

type ChoiceSource = { target: FieldConfig; key: string; kind: 'record'|'array' };
type ChoiceRow = { value: string; label: string };
function choiceSource(create = false): ChoiceSource|null {
  const options = create ? editOptions() : readOptions();
  const config = create ? editConfig() : readConfig();
  if (ensureRecord(options.choices) === options.choices) return { target: options, key: 'choices', kind: 'record' };
  if (Array.isArray(options.options)) return { target: options, key: 'options', kind: 'array' };
  if (ensureRecord(options.options) === options.options) return { target: options, key: 'options', kind: 'record' };
  if (Array.isArray(config.options)) return { target: config, key: 'options', kind: 'array' };
  if (ensureRecord(config.options) === config.options) return { target: config, key: 'options', kind: 'record' };
  if (!create) return null;
  options.choices = {};
  return { target: options, key: 'choices', kind: 'record' };
}
const choiceRows = computed<ChoiceRow[]>(() => {
  choiceRevision.value;
  const source = choiceSource(false);
  if (!source) return [];
  const raw = source.target[source.key];
  if (Array.isArray(raw)) return raw.map((item) => typeof item === 'object' && item !== null ? { value: String((item as FieldConfig).value ?? ''), label: String((item as FieldConfig).label ?? (item as FieldConfig).value ?? '') } : { value: String(item), label: String(item) });
  return Object.entries(ensureRecord(raw)).map(([value, label]) => ({ value, label: String(label) }));
});
function setChoice(index: number, field: 'value'|'label', value: string): void {
  const source = choiceSource(true)!;
  const raw = source.target[source.key];
  if (source.kind === 'array') {
    const next = Array.isArray(raw) ? [...raw] : [];
    const current = ensureRecord(next[index]);
    next[index] = { ...current, [field]: value };
    source.target[source.key] = next;
  } else {
    const entries = Object.entries(ensureRecord(raw));
    const current = entries[index] || ['', ''];
    entries[index] = field === 'value' ? [value, current[1]] : [current[0], value];
    source.target[source.key] = Object.fromEntries(entries.filter(([key]) => key !== ''));
  }
  choiceRevision.value += 1;
  syncJsonBuffers();
}
function addChoice(): void {
  const source = choiceSource(true)!;
  const raw = source.target[source.key];
  if (source.kind === 'array') source.target[source.key] = [...(Array.isArray(raw) ? raw : []), { value: '', label: '' }];
  else source.target[source.key] = { ...ensureRecord(raw), [`option_${choiceRows.value.length + 1}`]: '' };
  choiceRevision.value += 1;
  syncJsonBuffers();
}
function removeChoice(index: number): void {
  const source = choiceSource(false);
  if (!source) return;
  const raw = source.target[source.key];
  if (source.kind === 'array') source.target[source.key] = (Array.isArray(raw) ? raw : []).filter((_, i) => i !== index);
  else source.target[source.key] = Object.fromEntries(Object.entries(ensureRecord(raw)).filter((_, i) => i !== index));
  choiceRevision.value += 1;
  syncJsonBuffers();
}
function valueAt(target: FieldConfig, key: string): string { return target[key] === undefined || target[key] === null ? '' : String(target[key]); }
function setOptionalString(target: FieldConfig, key: string, value: string): void { if (value === '') delete target[key]; else target[key] = value; syncJsonBuffers(); }
function setOptionalNumber(target: FieldConfig, key: string, value: string): void { if (value === '') delete target[key]; else target[key] = Number(value); syncJsonBuffers(); }
function setOptionalBoolean(target: FieldConfig, key: string, checked: boolean): void { if (!checked) delete target[key]; else target[key] = true; syncJsonBuffers(); }
function allowedMimeText(): string { const validation = readValidation(); const value = validation.allowed_mimes ?? validation.allowed_mime ?? ''; return Array.isArray(value) ? value.join('\n') : String(value || ''); }
function setAllowedMime(value: string): void {
  const validation = editValidation();
  delete validation.allowed_mime;
  if (value.trim() === '') delete validation.allowed_mimes;
  else validation.allowed_mimes = value.split(/\n|,/).map((item) => item.trim()).filter(Boolean);
  syncJsonBuffers();
}

watch(() => props.field, syncJsonBuffers, { immediate: true });
watch(() => props.field.field_type, (next, previous) => {
  if (!previous || next === previous) return;
  const hasChoiceConfig = choiceRows.value.length > 0;
  const validation = readValidation();
  const config = readConfig();
  const hasMediaConfig = Boolean(validation.alt_policy || validation.allowed_mime || validation.allowed_mimes || config.kind || config.max_files);
  if ((choiceTypes.includes(previous) && !choiceTypes.includes(next) && hasChoiceConfig) || (mediaTypes.includes(previous) && !mediaTypes.includes(next) && hasMediaConfig)) {
    typeWarning.value = 'Le nouveau type ne consomme peut-être pas certaines options existantes. Elles sont conservées dans la zone experte.';
  } else typeWarning.value = '';
});
onMounted(() => nextTick(() => (initialFocus.value?.disabled ? closeButton.value : initialFocus.value)?.focus()));
</script>

<template>
  <div class="modal d-block blueprint-modal" role="dialog" aria-modal="true" aria-labelledby="field-editor-title" tabindex="-1" @keydown="onKeydown">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><div><h2 id="field-editor-title" class="modal-title h5">Modifier le champ</h2><small class="text-muted"><code>{{ field.field_handle }}</code> · {{ fieldTypeLabel(field.field_type) }}</small></div><button ref="closeButton" type="button" class="btn-close" aria-label="Annuler la modification du champ" @click="emit('cancel')"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <label class="col-md-4 form-label">Nom affiché<input ref="initialFocus" v-model="field.label" class="form-control mt-1" :disabled="!canManage"></label>
          <label class="col-md-4 form-label">Type<select v-model="field.field_type" class="form-select mt-1" :disabled="field.is_system || !canManage"><option v-for="type in fieldTypes" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></label>
          <label class="col-md-4 form-label">Largeur<select v-model.number="field.width" class="form-select mt-1" :disabled="!canManage"><option v-for="width in [25,33,50,66,75,100]" :key="width" :value="width">{{ width }}%</option></select></label>
          <div class="col-md-4 d-flex align-items-end gap-3"><label class="form-check"><input v-model="field.is_required" class="form-check-input" type="checkbox" :disabled="!canManage"><span class="form-check-label">obligatoire</span></label><label class="form-check"><input v-model="field.is_localized" class="form-check-input" type="checkbox" :disabled="!canManage"><span class="form-check-label">multilingue</span></label></div>
          <label class="col-md-8 form-label">Aide<textarea v-model="field.help_text" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label>
        </div>
        <div v-if="typeWarning" class="alert alert-warning mt-3 mb-0">{{ typeWarning }}</div>

        <section v-if="hasTypedConfiguration" class="border rounded-3 p-3 mt-3" aria-labelledby="typed-field-config-title">
          <h3 id="typed-field-config-title" class="h6">Configuration guidée</h3>
          <div v-if="isChoiceType" class="vstack gap-2">
            <p class="small text-muted mb-1">Options réellement utilisées par les champs de choix. Les autres clés restent dans la zone experte.</p>
            <div v-for="(choice, index) in choiceRows" :key="index" class="row g-2 align-items-end">
              <label class="col-md-4 form-label mb-0">Valeur<input class="form-control mt-1" :value="choice.value" :disabled="!canManage" @input="setChoice(index, 'value', ($event.target as HTMLInputElement).value)"></label>
              <label class="col-md-6 form-label mb-0">Libellé<input class="form-control mt-1" :value="choice.label" :disabled="!canManage" @input="setChoice(index, 'label', ($event.target as HTMLInputElement).value)"></label>
              <div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100" :disabled="!canManage" @click="removeChoice(index)">Retirer</button></div>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm align-self-start" :disabled="!canManage" @click="addChoice">Ajouter une option</button>
          </div>
          <div v-if="isTextType" class="row g-3">
            <label class="col-md-3 form-label">Min. caractères<input class="form-control mt-1" type="number" :value="valueAt(readValidation(), 'min')" :disabled="!canManage" @input="setOptionalNumber(editValidation(), 'min', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-3 form-label">Max. caractères<input class="form-control mt-1" type="number" :value="valueAt(readValidation(), 'max')" :disabled="!canManage" @input="setOptionalNumber(editValidation(), 'max', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-3 form-label">Format<select class="form-select mt-1" :value="valueAt(readValidation(), 'format')" :disabled="!canManage" @change="setOptionalString(editValidation(), 'format', ($event.target as HTMLSelectElement).value)"><option value="">—</option><option value="email">E-mail</option><option value="url">URL</option><option value="slug">Slug</option></select></label>
            <label class="col-md-3 form-label">Regex<input class="form-control mt-1" :value="valueAt(readValidation(), 'regex')" :disabled="!canManage" @input="setOptionalString(editValidation(), 'regex', ($event.target as HTMLInputElement).value)"></label>
          </div>
          <div v-if="isNumericType" class="row g-3">
            <label class="col-md-4 form-label">Minimum UI<input class="form-control mt-1" type="number" :value="valueAt(readConfig(), 'min')" :disabled="!canManage" @input="setOptionalNumber(editConfig(), 'min', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-4 form-label">Maximum UI<input class="form-control mt-1" type="number" :value="valueAt(readConfig(), 'max')" :disabled="!canManage" @input="setOptionalNumber(editConfig(), 'max', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-4 form-label">Pas UI<input class="form-control mt-1" type="number" :value="valueAt(readConfig(), 'step')" :disabled="!canManage" @input="setOptionalNumber(editConfig(), 'step', ($event.target as HTMLInputElement).value)"></label>
          </div>
          <div v-if="isMediaType" class="row g-3">
            <label class="col-md-3 form-label">Type média<select class="form-select mt-1" :value="valueAt(readConfig(), 'kind')" :disabled="!canManage" @change="setOptionalString(editConfig(), 'kind', ($event.target as HTMLSelectElement).value)"><option value="">—</option><option value="image">Image</option><option value="video">Vidéo</option><option value="audio">Audio</option><option value="document">Document</option><option value="any">Tout</option></select></label>
            <label class="col-md-3 form-label">Nombre max.<input class="form-control mt-1" type="number" min="1" :value="valueAt(readConfig(), 'max_files')" :disabled="!canManage" @input="setOptionalNumber(editConfig(), 'max_files', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-3 form-label">Politique alt<select class="form-select mt-1" :value="valueAt(readValidation(), 'alt_policy')" :disabled="!canManage" @change="setOptionalString(editValidation(), 'alt_policy', ($event.target as HTMLSelectElement).value)"><option value="">Défaut serveur</option><option value="required">Requis</option><option value="decorative_allowed">Décoratif autorisé</option><option value="forbidden">Interdit</option></select></label>
            <label class="col-md-3 form-label">Set préféré<input class="form-control mt-1" :value="valueAt(readConfig(), 'preferred_set')" :disabled="!canManage" @input="setOptionalString(editConfig(), 'preferred_set', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-12 form-label">MIME autorisés<textarea class="form-control mt-1" rows="2" :value="allowedMimeText()" :disabled="!canManage" @input="setAllowedMime(($event.target as HTMLTextAreaElement).value)"></textarea></label>
          </div>
          <div v-if="isRelationType" class="row g-3">
            <label class="col-md-4 form-label">Type de ressource<input class="form-control mt-1" :value="valueAt(readConfig(), 'resource_type')" :disabled="!canManage" @input="setOptionalString(editConfig(), 'resource_type', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-4 form-label">Minimum éléments<input class="form-control mt-1" type="number" :value="valueAt(readValidation(), 'min_items')" :disabled="!canManage" @input="setOptionalNumber(editValidation(), 'min_items', ($event.target as HTMLInputElement).value)"></label>
            <label class="col-md-4 form-label">Maximum éléments<input class="form-control mt-1" type="number" :value="valueAt(readValidation(), 'max_items')" :disabled="!canManage" @input="setOptionalNumber(editValidation(), 'max_items', ($event.target as HTMLInputElement).value)"></label>
          </div>
        </section>

        <details class="mt-3"><summary class="small fw-semibold">Options expertes : identifiant, cycle de vie, conditions et JSON</summary>
          <div class="row g-3 mt-1">
            <label class="col-md-4 form-label">Identifiant technique<input v-model="field.field_handle" class="form-control mt-1" :disabled="field.is_system || Boolean(field.id) || !canManage" @blur="field.field_handle = normalizeHandle(field.field_handle)"><small v-if="field.id" class="text-muted">Identifiant technique verrouillé.</small></label>
            <label class="col-md-4 form-label">Cycle de vie<select class="form-select mt-1" :value="valueAt(readConfig(), 'lifecycle_state') || 'active'" :disabled="!canManage" @change="setOptionalString(editConfig(), 'lifecycle_state', ($event.target as HTMLSelectElement).value === 'active' ? '' : ($event.target as HTMLSelectElement).value)"><option value="active">Actif</option><option value="disabled">Désactivé</option><option value="hidden">Masqué</option><option value="archived">Archivé</option></select></label>
            <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" :checked="Boolean(readConfig().disabled)" :disabled="field.is_system || !canManage" @change="setOptionalBoolean(editConfig(), 'disabled', ($event.target as HTMLInputElement).checked)"><span class="form-check-label">désactivation UI historique</span></label></div>
            <label class="col-md-6 form-label">Options JSON<textarea :value="jsonBuffers.options" class="form-control font-monospace mt-1" rows="6" :disabled="!canManage" @input="assignJson('options', ($event.target as HTMLTextAreaElement).value)"></textarea><small v-if="jsonErrors.options" class="text-danger">{{ jsonErrors.options }}</small></label>
            <label class="col-md-6 form-label">Validation JSON<textarea :value="jsonBuffers.validation" class="form-control font-monospace mt-1" rows="6" :disabled="!canManage" @input="assignJson('validation', ($event.target as HTMLTextAreaElement).value)"></textarea><small v-if="jsonErrors.validation" class="text-danger">{{ jsonErrors.validation }}</small></label>
            <label class="col-md-6 form-label">Conditions JSON<textarea :value="jsonBuffers.conditions" class="form-control font-monospace mt-1" rows="5" :disabled="!canManage" @input="assignJson('conditions', ($event.target as HTMLTextAreaElement).value)"></textarea><small v-if="jsonErrors.conditions" class="text-danger">{{ jsonErrors.conditions }}</small></label>
            <label class="col-md-6 form-label">Configuration JSON<textarea :value="jsonBuffers.config" class="form-control font-monospace mt-1" rows="5" :disabled="!canManage" @input="assignJson('config', ($event.target as HTMLTextAreaElement).value)"></textarea><small v-if="jsonErrors.config" class="text-danger">{{ jsonErrors.config }}</small></label>
          </div>
        </details>
        <div v-if="Object.keys(jsonErrors).length" class="alert alert-danger mt-3 mb-0">Corrigez le JSON expert avant d’appliquer localement. Le texte saisi est conservé.</div>
      </div>
      <div class="modal-footer justify-content-between">
        <button v-if="mode === 'blueprint' && !isNew" class="btn btn-outline-secondary" :disabled="!canManage" @click="emit('duplicate')">Dupliquer</button>
        <div class="d-flex gap-2 ms-auto"><button v-if="!isNew" class="btn btn-outline-danger" :disabled="field.is_system || !canManage" @click="emit('remove')">Retirer</button><button class="btn btn-secondary" @click="emit('cancel')">Annuler</button><button class="btn btn-dark" :disabled="!canManage || !canApply" @click="requestApply">Appliquer localement</button></div>
      </div>
    </div></div>
  </div>
</template>
