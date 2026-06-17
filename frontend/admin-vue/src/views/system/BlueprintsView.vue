<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';

type FieldConfig = Record<string, unknown>;
type BlueprintField = { id?: number; field_handle: string; field_type: string; label: string; help_text?: string; field_purpose?: string; width: number; is_required?: boolean; is_localized?: boolean; is_system?: boolean; is_deletable?: boolean; sort_order?: number; options?: FieldConfig; validation?: FieldConfig; conditions?: unknown[]; config: FieldConfig };
type BlueprintFieldsetMount = { fieldset_key: string; mount_handle: string; label?: string; sort_order?: number; conditions?: unknown[]; config?: FieldConfig };
type BlueprintSection = { id?: number; section_key: string; label: string; description?: string; layout: 'tab'|'section'|'sidebar'; sort_order: number; fields: BlueprintField[]; fieldsets: BlueprintFieldsetMount[] };
type BlueprintRow = { blueprint_key: string; resource_type: string; label: string; description?: string; site_id?: number|null; is_active?: boolean; active_version: number|null; active_version_label?: string|null; legacy_content_type_id?: number|null; content_type_key?: string|null; usage_count?: number; sections_count: number; fields_count: number; fieldsets_count: number };
type FieldsetRow = { fieldset_key: string; label: string; description?: string; fieldset_purpose: string; fields_count: number; usage_count?: number; is_system: boolean; is_deletable: boolean; fields?: BlueprintField[]; used_by?: Array<{ blueprint_key: string; label: string; resource_type: string }> };
type FieldType = { handle: string; label: string; purpose: string; is_priority?: boolean; config_schema?: FieldConfig };
type AuditItem = { blueprint_key: string; score: number; status: 'ok'|'warnings'|'blocking'; blocking_errors?: string[]; warnings?: string[]; optimizations?: string[] };
type AuditReport = { score?: number; status?: 'ok'|'warnings'|'blocking'|'unavailable'; blocking_errors?: string[]; warnings?: string[]; optimizations?: string[]; items?: AuditItem[] };
type Preset = { preset_key: string; label: string; description: string; recommended_fields: string[] };
type Model = { summary?: { blueprints?: number; sections?: number; fields?: number; fieldsets?: number }; blueprints?: BlueprintRow[]; fieldsets?: FieldsetRow[]; system_fields?: BlueprintField[]; audit?: AuditReport; presets?: Preset[]; governance?: Array<{ key: string; level: string; message: string }> };
type DragPayload = { kind: 'field'|'section'|'fieldsetField'; sectionIndex?: number; fieldIndex?: number };
type Tab = 'blueprints'|'fieldsets';

const context = useAdminContextStore();
const route = useRoute();
const model = ref<Model>({});
const fieldTypes = ref<FieldType[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const activeTab = ref<Tab>('blueprints');
const activeBlueprintKey = ref('');
const activeFieldsetKey = ref('');
const editingFieldset = ref<FieldsetRow|null>(null);
const selectedSectionIndex = ref(0);
const fieldModal = ref<{ mode: 'blueprint'|'fieldset'; sectionIndex?: number; fieldIndex: number }|null>(null);
const sectionModal = ref<number|null>(null);
const dragPayload = ref<DragPayload|null>(null);
let autosaveTimer: ReturnType<typeof window.setTimeout>|null = null;
let pendingAutosave = false;
const autosaveState = ref('');
const openGroups = ref<Record<string, boolean>>({ Studio: true, 'Modules métier': true });
const groupFilter = computed(() => String(route.query.group || '').toLowerCase());
const canRead = computed(() => context.can('blueprints.read'));
const canManage = computed(() => context.can('blueprints.manage'));

const emptySection = (): BlueprintSection => ({ section_key: 'content', label: 'Contenu', description: '', layout: 'tab', sort_order: 10, fields: [], fieldsets: [] });
const blueprint = ref({ blueprint_key: 'page', resource_type: 'content_type', label: 'Page', description: '', legacy_content_type_id: null as number|null, sections: [emptySection()] as BlueprintSection[] });

const fallbackFieldTypes: FieldType[] = [
  { handle: 'text', label: 'Texte court', purpose: 'content' }, { handle: 'textarea', label: 'Texte long', purpose: 'content' }, { handle: 'richtext', label: 'Rich text', purpose: 'content' }, { handle: 'markdown', label: 'Markdown', purpose: 'content' }, { handle: 'number', label: 'Nombre', purpose: 'content' }, { handle: 'boolean', label: 'Oui / non', purpose: 'content' }, { handle: 'select', label: 'Sélection', purpose: 'content' }, { handle: 'multiselect', label: 'Multi-sélection', purpose: 'content' }, { handle: 'date', label: 'Date', purpose: 'content' }, { handle: 'media', label: 'Image / média', purpose: 'content' }, { handle: 'relation', label: 'Relation', purpose: 'content' }, { handle: 'replicator', label: 'Blocs / répétiteur', purpose: 'page_builder' }, { handle: 'json', label: 'JSON', purpose: 'metadata' }, { handle: 'slug', label: 'Slug', purpose: 'system' }, { handle: 'sites', label: 'Site', purpose: 'system' }
];

const fieldTypeOptions = computed(() => fieldTypes.value.length ? fieldTypes.value : fallbackFieldTypes);
const stats = computed(() => model.value.summary || { blueprints: 0, sections: 0, fields: 0, fieldsets: 0 });
const fieldsets = computed(() => model.value.fieldsets || []);
const audit = computed(() => model.value.audit || {});
const activeAudit = computed(() => (audit.value.items || []).find((item) => item.blueprint_key === blueprint.value.blueprint_key));
const presets = computed(() => model.value.presets || []);
const selectedSection = computed(() => blueprint.value.sections[selectedSectionIndex.value] || blueprint.value.sections[0] || emptySection());
const editingField = computed(() => {
  const modal = fieldModal.value;
  if (!modal) return null;
  if (modal.mode === 'fieldset') return editingFieldset.value?.fields?.[modal.fieldIndex] || null;
  return blueprint.value.sections[modal.sectionIndex ?? 0]?.fields?.[modal.fieldIndex] || null;
});
const blueprintGroupDefinitions = [
  'Studio',
  'Blocs éditoriaux',
  'Modules métier',
  'Système'
];

function groupForBlueprint(item: BlueprintRow): string {
  const key = `${item.resource_type} ${item.blueprint_key} ${item.label}`.toLowerCase();
  if (['module_resource', 'headless'].includes(item.resource_type) || key.startsWith('module_') || key.includes('module_resource')) return 'Modules métier';
  if (item.resource_type === 'block' || key.includes('block')) return 'Blocs éditoriaux';
  if (item.resource_type === 'system' || key.includes('security_') || key.includes('iam_email_2fa') || key.includes('user') || key.includes('role') || key.includes('site') || key.includes('language')) return 'Système';
  return 'Studio';
}

const blueprintGroups = computed(() => {
  const groups: Record<string, BlueprintRow[]> = Object.fromEntries(blueprintGroupDefinitions.map((group) => [group, []])) as Record<string, BlueprintRow[]>;
  for (const item of model.value.blueprints || []) groups[groupForBlueprint(item)].push(item);
  const entries = blueprintGroupDefinitions.map((group) => [group, groups[group]] as [string, BlueprintRow[]]);
  if (groupFilter.value === 'modules') return entries.filter(([group]) => group === 'Modules métier');
  return entries;
});
const purposeLabels: Record<string, string> = { content: 'Contenu', seo: 'SEO', page_builder: 'Page builder', metadata: 'Métadonnées', system: 'Système' };

function normalizeHandle(value: string): string {
  return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, '');
}
function normalizeFieldTypeForSave(type: string): string {
  const raw = String(type || 'text').trim().toLowerCase();
  const aliases: Record<string, string> = {
    blocks: 'replicator',
    builder: 'replicator',
    block_builder: 'replicator',
    seo: 'json',
    language: 'select',
    languages: 'select',
    site: 'sites',
    structure: 'structures',
    user: 'users',
    image: 'media',
    asset: 'media',
    file: 'media',
    string: 'text',
    bool: 'boolean',
    'multi-select': 'multiselect'
  };
  return aliases[raw] || raw || 'text';
}
function fieldLabel(type: string): string { const normalized = normalizeFieldTypeForSave(type); return fieldTypeOptions.value.find((item) => item.handle === normalized)?.label || normalized; }
function fieldPurpose(type: string): string { const normalized = normalizeFieldTypeForSave(type); return fieldTypeOptions.value.find((item) => item.handle === normalized)?.purpose || 'content'; }
function toggleGroup(group: string): void { openGroups.value[group] = !(openGroups.value[group] ?? ['Studio', 'Modules métier'].includes(group)); }
function isGroupOpen(group: string): boolean { return openGroups.value[group] ?? ['Studio', 'Modules métier'].includes(group); }
function blueprintStatusLabel(item: BlueprintRow): string {
  if (item.is_active === false) return 'inactif';
  if (item.active_version) return `schéma v${item.active_version}`;
  return 'brouillon';
}
function blueprintStatusClass(item: BlueprintRow): string {
  if (item.is_active === false) return 'text-bg-secondary';
  if (item.active_version) return 'text-bg-success';
  return 'text-bg-warning';
}
function blueprintUsageLabel(item: BlueprintRow): string {
  if (item.content_type_key) return `type: ${item.content_type_key}`;
  if ((item.usage_count || 0) > 0) return `${item.usage_count} usage(s)`;
  return 'non relié';
}
function formatJson(value: unknown): string { try { return JSON.stringify(value || {}, null, 2); } catch { return '{}'; } }
function assignJson(target: BlueprintField, key: 'options'|'validation', raw: string): void { try { target[key] = raw.trim() ? JSON.parse(raw) : {}; error.value = ''; } catch { error.value = `JSON invalide pour ${key}.`; } }
function badgeClass(purpose?: string): string {
  if (purpose === 'seo') return 'text-bg-success';
  if (purpose === 'system') return 'text-bg-secondary';
  if (purpose === 'page_builder') return 'text-bg-primary';
  if (purpose === 'metadata') return 'text-bg-info';
  return 'text-bg-light text-dark';
}
function renumber(): void {
  blueprint.value.sections.forEach((section, s) => {
    section.sort_order = (s + 1) * 10;
    section.fields.forEach((field, f) => { field.sort_order = (f + 1) * 10; });
    section.fieldsets.forEach((mount, f) => { mount.sort_order = (f + 1) * 10; });
  });
  (editingFieldset.value?.fields || []).forEach((field, f) => { field.sort_order = (f + 1) * 10; });
}
function makeField(type = 'text'): BlueprintField {
  const n = blueprint.value.sections.reduce((sum, section) => sum + section.fields.length, 0) + (editingFieldset.value?.fields?.length || 0) + 1;
  const meta = fieldTypeOptions.value.find((item) => item.handle === type);
  return { field_handle: normalizeHandle(type === 'seo' ? `seo_field_${n}` : `field_${n}`), field_type: normalizeFieldTypeForSave(type), label: meta?.label || 'Nouveau champ', help_text: '', field_purpose: meta?.purpose || 'content', width: 100, is_required: false, is_localized: true, is_system: false, is_deletable: true, sort_order: n * 10, options: type.includes('select') ? { choices: [{ value: 'option', label: 'Option' }] } : {}, validation: {}, conditions: [], config: meta?.config_schema ? JSON.parse(JSON.stringify(meta.config_schema)) : {} };
}

async function load(): Promise<void> {
  if (!canRead.value) return;
  loading.value = true; error.value = ''; success.value = '';
  try {
    const [modelResponse, typesResponse] = await Promise.all([
      adminApi.get<Model>('/blueprints/model', { site_id: context.siteId }),
      adminApi.get<FieldType[]>('/field-types')
    ]);
    model.value = modelResponse.data || {};
    fieldTypes.value = typesResponse.data || [];
    if (!activeBlueprintKey.value && (model.value.blueprints || []).length) {
      const candidates = groupFilter.value === 'modules'
        ? (model.value.blueprints || []).filter((item) => groupForBlueprint(item) === 'Modules métier')
        : (model.value.blueprints || []);
      const first = candidates[0] || (model.value.blueprints || [])[0];
      activeBlueprintKey.value = first.blueprint_key;
      await loadBlueprint(first.blueprint_key, first.resource_type);
    }
  } catch (err) {
    error.value = apiErrorMessage(err, 'Modèle de blueprints indisponible.');
  } finally { loading.value = false; }
}

async function loadBlueprint(key: string, resourceType?: string): Promise<void> {
  if (!key) return;
  activeBlueprintKey.value = key; error.value = ''; success.value = ''; selectedSectionIndex.value = 0; sectionModal.value = null; fieldModal.value = null;
  try {
    const row = (model.value.blueprints || []).find((item) => item.blueprint_key === key && (!resourceType || item.resource_type === resourceType)) || (model.value.blueprints || []).find((item) => item.blueprint_key === key);
    const response = await adminApi.get<{ blueprint: Record<string, unknown>; sections: BlueprintSection[]; fieldsets: FieldsetRow[] }>(`/blueprints/${encodeURIComponent(key)}/design`, { site_id: context.siteId, resource_type: row?.resource_type || resourceType || undefined });
    blueprint.value = {
      blueprint_key: String(response.data.blueprint?.blueprint_key || key),
      resource_type: String(response.data.blueprint?.resource_type || row?.resource_type || 'content_type'),
      label: String(response.data.blueprint?.label || key),
      description: String(response.data.blueprint?.description || ''),
      legacy_content_type_id: response.data.blueprint?.legacy_content_type_id as number|null ?? null,
      sections: (response.data.sections || [emptySection()]).map((section) => ({ ...emptySection(), ...section, fields: (section.fields || []).map((field) => ({ ...field, field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {} })), fieldsets: section.fieldsets || [] }))
    };
  } catch (err) { error.value = apiErrorMessage(err, 'Blueprint indisponible.'); }
}

function newBlueprint(): void {
  activeTab.value = 'blueprints'; activeBlueprintKey.value = 'nouveau_blueprint'; selectedSectionIndex.value = 0;
  blueprint.value = { blueprint_key: 'nouveau_blueprint', resource_type: 'content_type', label: 'Nouveau blueprint', description: '', legacy_content_type_id: null, sections: [emptySection()] };
}
function addSection(): void {
  const index = blueprint.value.sections.length + 1;
  blueprint.value.sections.push({ section_key: `section_${index}`, label: `Section ${index}`, description: '', layout: 'tab', sort_order: index * 10, fields: [], fieldsets: [] });
  selectedSectionIndex.value = index - 1; renumber();
}
function selectSection(index: number): void { selectedSectionIndex.value = index; }
function duplicateField(section: BlueprintSection, field: BlueprintField): void {
  const copy = JSON.parse(JSON.stringify(field)) as BlueprintField;
  copy.id = undefined; copy.field_handle = normalizeHandle(`${field.field_handle}_copy`); copy.label = `${field.label} (copie)`; copy.is_system = false; copy.is_deletable = true;
  section.fields.push(copy); renumber();
}
function removeField(section: BlueprintSection, fieldIndex: number): void {
  const field = section.fields[fieldIndex];
  if (!field) return;
  if (field.is_system || field.is_deletable === false) {
    error.value = `Champ système protégé : ${field.field_handle}. Masquez, désactivez ou archivez-le plutôt que de le supprimer.`;
    return;
  }
  if (!window.confirm(`Supprimer le champ ${field.field_handle} ? Si ce champ contient déjà des données publiées, le serveur refusera la suppression.`)) return;
  section.fields.splice(fieldIndex, 1); fieldModal.value = null; renumber();
}
function addField(section: BlueprintSection, type: string): void {
  if (!type) return;
  section.fields.push(makeField(type)); selectedSectionIndex.value = blueprint.value.sections.indexOf(section); renumber(); fieldModal.value = { mode: 'blueprint', sectionIndex: selectedSectionIndex.value, fieldIndex: section.fields.length - 1 };
}
function importFieldset(section: BlueprintSection, key: string): void {
  if (!key) return;
  const fs = fieldsets.value.find((item) => item.fieldset_key === key); if (!fs) return;
  section.fieldsets.push({ fieldset_key: fs.fieldset_key, mount_handle: fs.fieldset_key, label: fs.label, sort_order: section.fieldsets.length * 10 + 10, conditions: [], config: {} }); renumber();
}
function removeFieldset(section: BlueprintSection, index: number): void { section.fieldsets.splice(index, 1); renumber(); }
function startDrag(payload: DragPayload): void { dragPayload.value = payload; }
function dropOnSection(targetIndex: number): void {
  const payload = dragPayload.value; if (!payload || payload.sectionIndex === undefined) return;
  if (payload.kind === 'section') {
    const [section] = blueprint.value.sections.splice(payload.sectionIndex, 1); blueprint.value.sections.splice(targetIndex, 0, section); selectedSectionIndex.value = targetIndex;
  } else if (payload.kind === 'field' && payload.fieldIndex !== undefined) {
    const from = blueprint.value.sections[payload.sectionIndex]; const to = blueprint.value.sections[targetIndex];
    const [field] = from.fields.splice(payload.fieldIndex, 1); to.fields.push(field); selectedSectionIndex.value = targetIndex;
  }
  dragPayload.value = null; renumber(); scheduleAutosave('ordre');
}
function dropOnField(targetSection: number, targetField: number): void {
  const payload = dragPayload.value; if (!payload || payload.kind !== 'field' || payload.sectionIndex === undefined || payload.fieldIndex === undefined) return;
  const from = blueprint.value.sections[payload.sectionIndex]; const [field] = from.fields.splice(payload.fieldIndex, 1); const to = blueprint.value.sections[targetSection];
  to.fields.splice(targetField, 0, field); dragPayload.value = null; selectedSectionIndex.value = targetSection; renumber(); scheduleAutosave('ordre');
}
function dropOnFieldsetField(targetField: number): void {
  const payload = dragPayload.value; const fields = editingFieldset.value?.fields;
  if (!fields || !payload || payload.kind !== 'fieldsetField' || payload.fieldIndex === undefined) return;
  const [field] = fields.splice(payload.fieldIndex, 1); fields.splice(targetField, 0, field); dragPayload.value = null; renumber(); scheduleFieldsetAutosave();
}
function cleanPayload(): Record<string, unknown> {
  renumber();
  return { ...blueprint.value, blueprint_key: normalizeHandle(blueprint.value.blueprint_key), sections: blueprint.value.sections.map((section) => ({ ...section, section_key: normalizeHandle(section.section_key), fields: section.fields.map((field) => ({ ...field, field_handle: normalizeHandle(field.field_handle), field_type: normalizeFieldTypeForSave(field.field_type), field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {}, options: field.options || {}, validation: field.validation || {} })) })) };
}
function scheduleAutosave(reason = 'modification'): void {
  if (!canManage.value) return;
  if (autosaveTimer !== null) window.clearTimeout(autosaveTimer);
  autosaveState.value = 'Enregistrement automatique…';
  autosaveTimer = window.setTimeout(() => { void saveBlueprint(true, reason); }, 450);
}
function scheduleFieldsetAutosave(): void {
  if (!canManage.value || !editingFieldset.value) return;
  if (autosaveTimer !== null) window.clearTimeout(autosaveTimer);
  autosaveState.value = 'Enregistrement automatique…';
  autosaveTimer = window.setTimeout(() => { void saveFieldset(true); }, 450);
}
async function saveBlueprint(silent = false, reason = 'manuel'): Promise<void> {
  if (!canManage.value) return;
  if (saving.value) { pendingAutosave = true; return; }
  saving.value = true; error.value = ''; if (!silent) success.value = '';
  try {
    const payload = cleanPayload();
    const response = await adminApi.put(`/blueprints/${encodeURIComponent(activeBlueprintKey.value || String(payload.blueprint_key))}/design`, payload);
    activeBlueprintKey.value = String(payload.blueprint_key);
    await load();
    if (response.data) await loadBlueprint(String(payload.blueprint_key), String(payload.resource_type || 'content_type'));
    autosaveState.value = silent ? 'Enregistré automatiquement.' : '';
    if (!silent) success.value = 'Blueprint enregistré : ordre des sections, champs et fieldsets persisté en base.';
  } catch (err) { autosaveState.value = ''; error.value = apiErrorMessage(err, 'Enregistrement impossible.'); }
  finally {
    saving.value = false;
    if (pendingAutosave) { pendingAutosave = false; scheduleAutosave(reason); }
  }
}
async function deleteBlueprint(): Promise<void> {
  if (!activeBlueprintKey.value || !canManage.value) return;
  const message = 'La suppression définitive est réservée aux modèles non utilisés. Pour un modèle existant, préférez désactiver, masquer ou archiver les champs concernés. Continuer ?';
  if (!window.confirm(message)) return;
  saving.value = true; error.value = ''; success.value = '';
  try { await adminApi.delete(`/blueprints/${encodeURIComponent(activeBlueprintKey.value)}`, { resource_type: blueprint.value.resource_type, site_id: context.siteId }); success.value = 'Blueprint supprimé.'; activeBlueprintKey.value = ''; await load(); }
  catch (err) { error.value = apiErrorMessage(err, 'Suppression impossible.'); }
  finally { saving.value = false; }
}
function newFieldset(): void { activeTab.value = 'fieldsets'; activeFieldsetKey.value = 'nouveau_fieldset'; editingFieldset.value = { fieldset_key: 'nouveau_fieldset', label: 'Nouveau fieldset', description: '', fieldset_purpose: 'content', fields_count: 0, usage_count: 0, is_system: false, is_deletable: true, fields: [] }; }
async function editFieldset(key: string): Promise<void> {
  activeTab.value = 'fieldsets'; activeFieldsetKey.value = key; error.value = ''; fieldModal.value = null;
  try { const response = await adminApi.get<FieldsetRow>(`/fieldsets/${encodeURIComponent(key)}`); editingFieldset.value = { ...response.data, fields: (response.data.fields || []).map((field) => ({ ...field, field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {} })) }; }
  catch (err) { error.value = apiErrorMessage(err, 'Fieldset indisponible.'); }
}
function addFieldsetField(type = 'text'): void { if (!type) return; if (!editingFieldset.value) newFieldset(); editingFieldset.value?.fields?.push(makeField(type)); renumber(); fieldModal.value = { mode: 'fieldset', fieldIndex: (editingFieldset.value?.fields?.length || 1) - 1 }; }
async function saveFieldset(silent = false): Promise<void> {
  if (!editingFieldset.value) return;
  if (saving.value) { pendingAutosave = true; return; }
  saving.value = true; error.value = ''; if (!silent) success.value = '';
  try {
    const payload = { ...editingFieldset.value, fieldset_key: normalizeHandle(editingFieldset.value.fieldset_key), fields: (editingFieldset.value.fields || []).map((field, index) => ({ ...field, field_handle: normalizeHandle(field.field_handle), field_type: normalizeFieldTypeForSave(field.field_type), field_purpose: field.field_purpose || fieldPurpose(field.field_type), sort_order: (index + 1) * 10, config: field.config || {}, options: field.options || {}, validation: field.validation || {} })) };
    if (activeFieldsetKey.value === 'nouveau_fieldset') await adminApi.post('/fieldsets', payload); else await adminApi.put(`/fieldsets/${encodeURIComponent(activeFieldsetKey.value)}`, payload);
    autosaveState.value = silent ? 'Enregistré automatiquement.' : '';
    if (!silent) success.value = 'Fieldset enregistré. Les blueprints qui l’importent signalent son impact.';
    activeFieldsetKey.value = payload.fieldset_key; await load(); await editFieldset(payload.fieldset_key);
  } catch (err) { autosaveState.value = ''; error.value = apiErrorMessage(err, 'Enregistrement du fieldset impossible.'); }
  finally {
    saving.value = false;
    if (pendingAutosave) { pendingAutosave = false; if (activeTab.value === 'fieldsets') scheduleFieldsetAutosave(); else scheduleAutosave('modification'); }
  }
}
async function confirmFieldModal(): Promise<void> {
  const mode = fieldModal.value?.mode;
  fieldModal.value = null;
  renumber();
  if (!canManage.value) return;
  if (mode === 'fieldset') await saveFieldset(true);
  else await saveBlueprint(true, 'champ');
}
async function deleteFieldset(): Promise<void> {
  if (!editingFieldset.value) return;
  saving.value = true; error.value = ''; success.value = '';
  try { await adminApi.delete(`/fieldsets/${encodeURIComponent(editingFieldset.value.fieldset_key)}`); success.value = 'Fieldset supprimé.'; editingFieldset.value = null; activeFieldsetKey.value = ''; await load(); }
  catch (err) { error.value = apiErrorMessage(err, 'Suppression du fieldset impossible.'); }
  finally { saving.value = false; }
}
function openFieldModal(sectionIndex: number, fieldIndex: number): void { selectedSectionIndex.value = sectionIndex; fieldModal.value = { mode: 'blueprint', sectionIndex, fieldIndex }; }
function openFieldsetFieldModal(fieldIndex: number): void { fieldModal.value = { mode: 'fieldset', fieldIndex }; }

onMounted(load);
watch(() => context.siteId, load);
</script>

<template>
  <PageHeader eyebrow="Structure éditoriale" title="Blueprints & fieldsets" intro="Construisez une structure éditoriale claire, robuste et SEO-first sans modifier le code." />
  <div v-if="!canRead" class="card empty-state"><h2>Accès non autorisé</h2><p class="muted">Le droit <code>blueprints.read</code> est nécessaire.</p></div>
  <template v-else>
    <section class="blueprint-stats-grid mb-3">
      <article class="card h-100"><span class="text-muted small">Blueprints</span><strong class="fs-3">{{ stats.blueprints ?? 0 }}</strong><small class="text-muted">Types de contenus, blocs et modules</small></article>
      <article class="card h-100"><span class="text-muted small">Sections / tabs</span><strong class="fs-3">{{ stats.sections ?? 0 }}</strong><small class="text-muted">Organisation persistée</small></article>
      <article class="card h-100"><span class="text-muted small">Champs</span><strong class="fs-3">{{ stats.fields ?? 0 }}</strong><small class="text-muted">Handles stables</small></article>
      <article class="card h-100"><span class="text-muted small">Fieldsets</span><strong class="fs-3">{{ stats.fieldsets ?? 0 }}</strong><small class="text-muted">Groupes réutilisables</small></article>
    </section>

    <nav class="editor-tabs" aria-label="Navigation blueprints et fieldsets">
      <button type="button" :class="['editor-tab', { active: activeTab === 'blueprints' }]" role="tab" :aria-selected="activeTab === 'blueprints'" @click="activeTab = 'blueprints'">Blueprints</button>
      <button type="button" :class="['editor-tab', { active: activeTab === 'fieldsets' }]" role="tab" :aria-selected="activeTab === 'fieldsets'" @click="activeTab = 'fieldsets'">Fieldsets</button>
    </nav>

    <section v-if="activeTab === 'blueprints'" class="card mb-3 border-0 shadow-sm">
      <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
          <p class="eyebrow mb-1">Gouvernance</p>
          <h2 class="h5 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
            <span>Audit blueprints & SEO</span>
            <InfoHint text="Score explicable : erreurs bloquantes seulement si la publication serait incohérente." placement="end" />
          </h2>
        </div>
        <span class="badge fs-6" :class="audit.status === 'blocking' ? 'text-bg-danger' : audit.status === 'warnings' ? 'text-bg-warning' : 'text-bg-success'">{{ audit.score ?? 0 }}/100 · {{ audit.status || 'ok' }}</span>
      </div>
      <div v-if="activeAudit" class="mt-3 row g-3">
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><strong>{{ activeAudit.score }}/100</strong><small class="d-block text-muted">{{ activeAudit.blueprint_key }} · {{ activeAudit.status }}</small></div></div>
        <div class="col-md-8"><ul class="mb-0 small text-muted"><li v-for="message in [...(activeAudit.blocking_errors || []), ...(activeAudit.warnings || []), ...(activeAudit.optimizations || [])].slice(0, 5)" :key="message">{{ message }}</li><li v-if="!((activeAudit.blocking_errors || []).length || (activeAudit.warnings || []).length || (activeAudit.optimizations || []).length)">Aucun problème détecté pour ce blueprint.</li></ul></div>
      </div>
      <details class="mt-3">
        <summary class="small text-muted">Presets recommandés</summary>
        <div class="row g-2 mt-2">
          <div v-for="preset in presets" :key="preset.preset_key" class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100"><strong>{{ preset.label }}</strong><p class="small text-muted mb-2">{{ preset.description }}</p><code class="small">{{ preset.recommended_fields.join(', ') }}</code></div></div>
        </div>
      </details>
    </section>

    <section v-show="activeTab === 'blueprints'" class="blueprint-workbench blueprint-admin-modern">
      <aside class="blueprint-column blueprint-column--models">
        <div class="card sticky-xxl-top blueprint-sticky">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
              <h2 class="h5 mb-0 d-inline-flex align-items-center gap-2 flex-wrap">
                <span>Modèles</span>
                <InfoHint text="Blueprints par famille éditoriale" placement="end" />
              </h2>
            </div>
            <button class="btn btn-dark btn-sm" :disabled="!canManage" @click="newBlueprint">Créer</button>
          </div>
          <div class="accordion accordion-flush">
            <div v-for="([group, items]) in blueprintGroups" :key="group" class="accordion-item">
              <h3 class="accordion-header"><button class="accordion-button py-2" :class="{ collapsed: !isGroupOpen(group) }" type="button" @click="toggleGroup(group)">{{ group }}</button></h3>
              <div v-show="isGroupOpen(group)" class="accordion-collapse show">
                <div class="accordion-body p-0 py-2">
                  <button v-for="item in items" :key="`${item.resource_type}:${item.blueprint_key}`" class="list-group-item list-group-item-action border-0 rounded-3 mb-1" :class="{ active: item.blueprint_key === activeBlueprintKey }" type="button" @click="loadBlueprint(item.blueprint_key, item.resource_type)">
                    <span class="d-flex align-items-center justify-content-between gap-2"><strong>{{ item.label }}</strong><small class="font-monospace">{{ item.blueprint_key }}</small></span>
                    <small class="d-block opacity-75">{{ item.sections_count }} section(s) · {{ item.fields_count }} champ(s) · {{ blueprintUsageLabel(item) }}</small>
                    <span class="d-flex flex-wrap gap-1 mt-2"><span class="badge" :class="blueprintStatusClass(item)">{{ blueprintStatusLabel(item) }}</span><span v-if="item.active_version_label" class="badge text-bg-light text-dark">{{ item.active_version_label }}</span></span>
                  </button>
                  <p v-if="!items.length" class="blueprint-empty-group">
                    Aucun modèle dans cette famille pour le moment.
                    <span v-if="group === 'Modules métier'">Les providers actifs pourront déclarer ici leurs ressources métier et contrats headless.</span>
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <main class="blueprint-column blueprint-column--editor">
        <div class="card mb-3">
          <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
            <div>
              <p class="eyebrow mb-1">Éditeur</p>
              <h2 class="h4 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
                <span>{{ blueprint.label }}</span>
                <InfoHint text="Réglages généraux du modèle éditorial." placement="end" />
              </h2>
            </div>
            <div class="text-end"><div class="btn-group"><button class="btn btn-dark" :disabled="saving || !canManage" @click="saveBlueprint(false)">Enregistrer</button><button class="btn btn-outline-danger" :disabled="saving || !canManage || ['page','article','page_archive','article_archive'].includes(blueprint.blueprint_key)" @click="deleteBlueprint">Supprimer</button></div><small v-if="autosaveState" class="d-block text-muted mt-1">{{ autosaveState }}</small></div>
          </div>
          <div class="row g-3">
            <label class="col-md-4 form-label">Handle<input v-model="blueprint.blueprint_key" class="form-control mt-1" :disabled="!canManage" @blur="blueprint.blueprint_key = normalizeHandle(blueprint.blueprint_key)"></label>
            <label class="col-md-4 form-label">Type<select v-model="blueprint.resource_type" class="form-select mt-1" :disabled="!canManage"><option value="content_type">Type de contenu</option><option value="block">Bloc éditorial</option><option value="module_resource">Ressource module</option><option value="headless">Headless</option><option value="taxonomy">Taxonomie</option><option value="media">Média</option><option value="system">Système</option></select></label>
            <label class="col-md-4 form-label">Label<input v-model="blueprint.label" class="form-control mt-1" :disabled="!canManage"></label>
            <label class="col-12 form-label">Description<textarea v-model="blueprint.description" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label>
          </div>
        </div>

        <div class="card">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
              <p class="eyebrow mb-1">Structure</p>
              <h2 class="h5 mb-0 d-inline-flex align-items-center gap-2 flex-wrap">
                <span>Sections du blueprint</span>
                <InfoHint text="Réordonner les sections sans afficher leurs champs." placement="end" />
              </h2>
            </div>
            <button class="btn btn-outline-secondary btn-sm" :disabled="!canManage" @click="addSection">Ajouter</button>
          </div>
          <div class="list-group blueprint-section-list">
            <button v-for="(section, sectionIndex) in blueprint.sections" :key="section.section_key + sectionIndex" class="list-group-item list-group-item-action" :class="{ active: sectionIndex === selectedSectionIndex }" type="button" draggable="true" @click="selectSection(sectionIndex)" @dragstart="startDrag({ kind: 'section', sectionIndex })" @dragover.prevent @drop="dropOnSection(sectionIndex)">
              <span class="d-flex align-items-center gap-2">
                <span class="blueprint-drag-handle" title="Déplacer" aria-hidden="true">⋮</span>
                <span class="flex-grow-1"><strong>{{ section.label }}</strong><small class="d-block opacity-75 font-monospace">{{ section.section_key }}</small></span>
                <span class="badge rounded-pill" :class="section.layout === 'sidebar' ? 'text-bg-secondary' : 'text-bg-light text-dark'">{{ section.layout }}</span>
              </span>
              <small class="d-block mt-2 opacity-75">{{ section.fields.length }} champ(s) · {{ section.fieldsets.length }} fieldset(s)</small>
            </button>
          </div>
        </div>
      </main>

      <aside class="blueprint-column blueprint-column--section">
        <div class="card sticky-xxl-top blueprint-sticky">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
              <p class="eyebrow mb-1">Section</p>
              <h2 class="h5 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
                <span>{{ selectedSection.label }}</span>
                <InfoHint :text="selectedSection.description || 'Cliquez sur un champ pour modifier ses caractéristiques dans une fenêtre dédiée.'" placement="end" />
              </h2>
            </div>
            <button class="btn btn-outline-secondary btn-sm" :disabled="!canManage" @click="sectionModal = selectedSectionIndex">Modifier</button>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6 col-xxl-12"><select class="form-select" :disabled="!canManage" @change="addField(selectedSection, String(($event.target as HTMLSelectElement).value)); ($event.target as HTMLSelectElement).value='' "><option value="">Ajouter un champ…</option><option v-for="type in fieldTypeOptions" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></div>
            <div class="col-md-6 col-xxl-12"><select class="form-select" :disabled="!canManage" @change="importFieldset(selectedSection, String(($event.target as HTMLSelectElement).value)); ($event.target as HTMLSelectElement).value='' "><option value="">Importer un fieldset…</option><option v-for="fs in fieldsets" :key="fs.fieldset_key" :value="fs.fieldset_key">{{ fs.label }} · {{ fs.usage_count || 0 }} usage(s)</option></select></div>
          </div>
          <div class="list-group" @dragover.prevent @drop="dropOnSection(selectedSectionIndex)">
            <button v-for="(field, fieldIndex) in selectedSection.fields" :key="field.field_handle + fieldIndex" class="list-group-item list-group-item-action" :class="{ 'opacity-75': field.config?.disabled === true }" type="button" draggable="true" @click="openFieldModal(selectedSectionIndex, fieldIndex)" @dragstart.stop="startDrag({ kind: 'field', sectionIndex: selectedSectionIndex, fieldIndex })" @dragover.prevent @drop.stop="dropOnField(selectedSectionIndex, fieldIndex)">
              <span class="d-flex align-items-center gap-2">
                <span class="blueprint-drag-handle" title="Déplacer" aria-hidden="true">⋮</span>
                <span class="flex-grow-1"><strong>{{ field.label }}</strong><small class="d-block text-muted"><code>{{ field.field_handle }}</code> · {{ fieldLabel(field.field_type) }} · {{ field.width }}%</small></span>
                <span v-if="field.is_required" class="badge text-bg-warning">requis</span>
                <span v-if="field.is_system" class="badge text-bg-secondary">système</span>
                <span v-if="field.config?.lifecycle_state" class="badge text-bg-light text-dark">{{ field.config?.lifecycle_state }}</span>
              </span>
            </button>
            <div v-for="(mount, mountIndex) in selectedSection.fieldsets" :key="mount.mount_handle + mountIndex" class="list-group-item bg-light">
              <div class="d-flex justify-content-between align-items-center gap-2"><div><strong>Fieldset : {{ mount.label || mount.fieldset_key }}</strong><small class="d-block text-muted"><code>{{ mount.mount_handle }}</code> · modification partagée avec les blueprints utilisateurs</small></div><button class="btn btn-outline-danger btn-sm" :disabled="!canManage" @click="removeFieldset(selectedSection, mountIndex)">Retirer</button></div>
            </div>
            <div v-if="!selectedSection.fields.length && !selectedSection.fieldsets.length" class="border rounded-3 p-4 text-center text-muted">Aucun champ dans cette section.</div>
          </div>
        </div>
      </aside>
    </section>

    <section v-show="activeTab === 'fieldsets'" class="fieldset-workbench blueprint-admin-modern">
      <aside class="blueprint-column blueprint-column--models">
        <div class="card sticky-xl-top blueprint-sticky">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div><h2 class="h5 mb-0">Modèles</h2><small class="text-muted">Fieldsets réutilisables</small></div>
            <button class="btn btn-dark btn-sm" :disabled="!canManage" @click="newFieldset">Créer</button>
          </div>
          <div class="list-group">
            <button v-for="fs in fieldsets" :key="fs.fieldset_key" class="list-group-item list-group-item-action" :class="{ active: fs.fieldset_key === activeFieldsetKey }" type="button" @click="editFieldset(fs.fieldset_key)">
              <strong>{{ fs.label }}</strong><small class="d-block opacity-75"><code>{{ fs.fieldset_key }}</code> · {{ fs.fields_count }} champ(s) · {{ fs.usage_count || 0 }} usage(s)</small>
            </button>
          </div>
        </div>
      </aside>
      <main class="blueprint-column blueprint-column--section">
        <div class="card" v-if="editingFieldset">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><p class="eyebrow mb-1">Section</p><h2 class="h4 mb-1">{{ editingFieldset.label }}</h2><p class="text-muted mb-0">Fieldset réutilisable : une modification impacte tous les blueprints qui importent ce groupe de champs.</p></div>
            <div class="text-end"><div class="btn-group"><button class="btn btn-dark" :disabled="saving || !canManage" @click="saveFieldset(false)">Enregistrer</button><button class="btn btn-outline-danger" :disabled="saving || !canManage || (editingFieldset.usage_count || 0) > 0" @click="deleteFieldset">Supprimer</button></div><small v-if="autosaveState" class="d-block text-muted mt-1">{{ autosaveState }}</small></div>
          </div>
          <div v-if="(editingFieldset.usage_count || 0) > 0" class="alert alert-warning">Ce fieldset est utilisé par {{ editingFieldset.usage_count }} blueprint(s). Vérifiez l’impact avant d’enregistrer.</div>
          <div class="row g-3 mb-3">
            <label class="col-md-4 form-label">Handle<input v-model="editingFieldset.fieldset_key" class="form-control mt-1" :disabled="!canManage" @blur="editingFieldset.fieldset_key = normalizeHandle(editingFieldset.fieldset_key)"></label>
            <label class="col-md-4 form-label">Label<input v-model="editingFieldset.label" class="form-control mt-1" :disabled="!canManage"></label>
            <label class="col-md-4 form-label">Usage<select v-model="editingFieldset.fieldset_purpose" class="form-select mt-1" :disabled="!canManage"><option value="content">Contenu</option><option value="seo">SEO</option><option value="page_builder">Page builder</option><option value="metadata">Métadonnées</option><option value="system">Système</option></select></label>
            <label class="col-12 form-label">Description<textarea v-model="editingFieldset.description" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label>
          </div>
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h3 class="h5 mb-0">Champs</h3><select class="form-select w-auto" :disabled="!canManage" @change="addFieldsetField(String(($event.target as HTMLSelectElement).value)); ($event.target as HTMLSelectElement).value='' "><option value="">Ajouter un champ…</option><option v-for="type in fieldTypeOptions" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></div>
          <div class="list-group">
            <button v-for="(field, i) in editingFieldset.fields || []" :key="field.field_handle + i" class="list-group-item list-group-item-action" type="button" draggable="true" @click="openFieldsetFieldModal(i)" @dragstart.stop="startDrag({ kind: 'fieldsetField', fieldIndex: i })" @dragover.prevent @drop.stop="dropOnFieldsetField(i)">
              <span class="d-flex align-items-center gap-2"><span class="blueprint-drag-handle" title="Déplacer" aria-hidden="true">⋮</span><span class="flex-grow-1"><strong>{{ field.label }}</strong><small class="d-block text-muted"><code>{{ field.field_handle }}</code> · {{ fieldLabel(field.field_type) }} · {{ field.width }}%</small></span><span v-if="field.is_required" class="badge text-bg-warning">requis</span></span>
            </button>
          </div>
        </div>
        <div v-else class="card empty-state"><strong>Sélectionnez ou créez un fieldset.</strong></div>
      </main>
    </section>
  </template>

  <div v-if="sectionModal !== null" class="modal d-block blueprint-modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Modifier la section</h5><button type="button" class="btn-close" @click="sectionModal = null"></button></div><div class="modal-body"><div class="row g-3"><label class="col-md-6 form-label">Label<input v-model="blueprint.sections[sectionModal].label" class="form-control mt-1" :disabled="!canManage"></label><label class="col-md-6 form-label">Handle<input v-model="blueprint.sections[sectionModal].section_key" class="form-control mt-1" :disabled="!canManage" @blur="blueprint.sections[sectionModal].section_key = normalizeHandle(blueprint.sections[sectionModal].section_key)"></label><label class="col-md-4 form-label">Disposition<select v-model="blueprint.sections[sectionModal].layout" class="form-select mt-1" :disabled="!canManage"><option value="tab">Tab</option><option value="section">Section</option><option value="sidebar">Sidebar</option></select></label><label class="col-12 form-label">Description<textarea v-model="blueprint.sections[sectionModal].description" class="form-control mt-1" rows="3" :disabled="!canManage"></textarea></label></div></div><div class="modal-footer"><button class="btn btn-secondary" @click="sectionModal = null">Fermer</button></div></div></div></div>

  <div v-if="fieldModal && editingField" class="modal d-block blueprint-modal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title">Modifier le champ</h5><small class="text-muted"><code>{{ editingField.field_handle }}</code> · {{ fieldLabel(editingField.field_type) }}</small></div><button type="button" class="btn-close" @click="fieldModal = null"></button></div><div class="modal-body"><div class="row g-3"><label class="col-md-4 form-label">Handle<input v-model="editingField.field_handle" class="form-control mt-1" :disabled="editingField.is_system || !canManage" @blur="editingField.field_handle = normalizeHandle(editingField.field_handle)"></label><label class="col-md-4 form-label">Label<input v-model="editingField.label" class="form-control mt-1" :disabled="!canManage"></label><label class="col-md-4 form-label">Type<select v-model="editingField.field_type" class="form-select mt-1" :disabled="editingField.is_system || !canManage"><option v-for="type in fieldTypeOptions" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></label><label class="col-md-3 form-label">Largeur<select v-model.number="editingField.width" class="form-select mt-1" :disabled="!canManage"><option :value="25">25%</option><option :value="33">33%</option><option :value="50">50%</option><option :value="66">66%</option><option :value="75">75%</option><option :value="100">100%</option></select></label><div class="col-md-9 d-flex flex-wrap align-items-end gap-3"><label class="form-check"><input v-model="editingField.is_required" class="form-check-input" type="checkbox" :disabled="!canManage"><span class="form-check-label">requis</span></label><label class="form-check"><input v-model="editingField.is_localized" class="form-check-input" type="checkbox" :disabled="!canManage"><span class="form-check-label">multilingue</span></label><label class="form-check"><input v-model="editingField.config.disabled" class="form-check-input" type="checkbox" :disabled="editingField.is_system || !canManage"><span class="form-check-label">désactivé</span></label><span v-if="editingField.is_system" class="badge text-bg-secondary">Champ système non supprimable</span><span class="badge" :class="badgeClass(editingField.field_purpose || fieldPurpose(editingField.field_type))">{{ purposeLabels[editingField.field_purpose || fieldPurpose(editingField.field_type)] || editingField.field_purpose }}</span></div><label class="col-12 form-label">Aide<textarea v-model="editingField.help_text" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label><label class="col-md-6 form-label">Options JSON<textarea :value="formatJson(editingField.options)" class="form-control font-monospace mt-1" rows="5" :disabled="!canManage" @change="assignJson(editingField, 'options', ($event.target as HTMLTextAreaElement).value)"></textarea></label><label class="col-md-6 form-label">Validation JSON<textarea :value="formatJson(editingField.validation)" class="form-control font-monospace mt-1" rows="5" :disabled="!canManage" @change="assignJson(editingField, 'validation', ($event.target as HTMLTextAreaElement).value)"></textarea></label></div></div><div class="modal-footer justify-content-between"><div><button v-if="fieldModal.mode === 'blueprint'" class="btn btn-outline-secondary" :disabled="!canManage" @click="duplicateField(blueprint.sections[fieldModal.sectionIndex ?? 0], editingField)">Dupliquer</button></div><div class="d-flex gap-2"><button v-if="fieldModal.mode === 'blueprint'" class="btn btn-outline-danger" :disabled="editingField.is_system || !canManage" @click="removeField(blueprint.sections[fieldModal.sectionIndex ?? 0], fieldModal.fieldIndex)">Retirer</button><button v-if="fieldModal.mode === 'fieldset'" class="btn btn-outline-danger" :disabled="!canManage" @click="editingFieldset!.fields!.splice(fieldModal!.fieldIndex, 1); fieldModal = null; renumber()">Retirer</button><button class="btn btn-dark" :disabled="saving" @click="confirmFieldModal">Enregistrer</button></div></div></div></div></div>
  <div v-if="sectionModal !== null || fieldModal" class="modal-backdrop fade show"></div>

  <p v-if="loading" class="muted mt-3">Chargement…</p><ApiFeedback :error="error" :success="success" />
</template>
