<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import BlueprintStructureTree from '@/components/blueprints/BlueprintStructureTree.vue';
import BlueprintInspectorPanel from '@/components/blueprints/BlueprintInspectorPanel.vue';
import BlueprintNavigationPanel from '@/components/blueprints/BlueprintNavigationPanel.vue';
import BlueprintFieldEditorModal from '@/components/blueprints/BlueprintFieldEditorModal.vue';
import BlueprintSectionEditorModal from '@/components/blueprints/BlueprintSectionEditorModal.vue';
import { useBlueprintEditorState } from '@/composables/useBlueprintEditorState';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n } from '@/i18n';

type FieldConfig = Record<string, unknown>;
type BlueprintField = { id?: number; field_handle: string; field_type: string; label: string; help_text?: string; field_purpose?: string; width: number; is_required?: boolean; is_localized?: boolean; is_system?: boolean; is_deletable?: boolean; sort_order?: number; options?: FieldConfig; validation?: FieldConfig; conditions?: unknown[]; config: FieldConfig };
type BlueprintFieldsetMount = { fieldset_key: string; mount_handle: string; label?: string; sort_order?: number; conditions?: unknown[]; config?: FieldConfig };
type BlueprintSection = { id?: number; section_key: string; label: string; description?: string; layout: 'tab'|'section'|'sidebar'; sort_order: number; fields: BlueprintField[]; fieldsets: BlueprintFieldsetMount[] };
type BlueprintScope = 'global'|'site';
type BlueprintRow = { id: number; blueprint_key: string; resource_type: string; label: string; description?: string; site_id?: number|null; is_active?: boolean; active_version: number|null; active_version_label?: string|null; legacy_content_type_id?: number|null; content_type_key?: string|null; usage_count?: number; sections_count: number; fields_count: number; fieldsets_count: number };
type FieldsetUsage = { blueprint_key: string; label: string; resource_type: string; site_id?: number|null; scope?: 'global'|'site'|string };
type FieldsetRow = { id?: number; fieldset_key: string; label: string; description?: string; fieldset_purpose: string; fields_count: number; usage_count?: number; is_system: boolean; is_deletable: boolean; fields?: BlueprintField[]; used_by?: FieldsetUsage[]; config?: FieldConfig };
type FieldType = { handle: string; label: string; purpose: string; is_priority?: boolean; config_schema?: FieldConfig };
type AuditItem = { blueprint_key: string; resource_type?: string; site_id?: number|null; score: number; status: 'ok'|'warnings'|'blocking'; blocking_errors?: string[]; warnings?: string[]; optimizations?: string[] };
type AuditReport = { score?: number; status?: 'ok'|'warnings'|'blocking'|'unavailable'; blocking_errors?: string[]; warnings?: string[]; optimizations?: string[]; items?: AuditItem[] };
type Model = { summary?: { blueprints?: number; sections?: number; fields?: number; fieldsets?: number }; blueprints?: BlueprintRow[]; fieldsets?: FieldsetRow[]; system_fields?: BlueprintField[]; audit?: AuditReport; governance?: Array<{ key: string; level: string; message: string }> };
type BlueprintVersion = { id: number; blueprint_id: number; version: number; version_label?: string|null; status: string; is_active: boolean; checksum_sha256?: string|null };
type DragPayload = { kind: 'field'|'section'|'fieldsetField'; sectionIndex?: number; fieldIndex?: number };
type Tab = 'blueprints'|'fieldsets';
type BlueprintDesign = { blueprint_key: string; resource_type: string; label: string; description: string; site_id?: number|null; legacy_content_type_id: number|null; sections: BlueprintSection[] };
type BlueprintDesignResponse = { blueprint: Record<string, unknown>; sections: BlueprintSection[]; fieldsets: FieldsetRow[]; active_version?: BlueprintVersion|null; draft_version?: BlueprintVersion|null; versions?: BlueprintVersion[] };

const context = useAdminContextStore();
const route = useRoute();
const { t } = useI18n();
const model = ref<Model>({});
const fieldTypes = ref<FieldType[]>([]);
const loading = ref(false);
const saving = ref(false);
const activating = ref(false);
const error = ref('');
const success = ref('');
const activeTab = ref<Tab>('blueprints');
const activeBlueprintKey = ref('');
const activeBlueprintType = ref('');
const activeBlueprintScope = ref<BlueprintScope>('site');
const activeFieldsetKey = ref('');
const editingFieldset = ref<FieldsetRow|null>(null);
const selectedSectionIndex = ref(0);
const fieldModal = ref<{ mode: 'blueprint'|'fieldset'; sectionIndex?: number; fieldIndex: number; isNew?: boolean }|null>(null);
const fieldDraft = ref<BlueprintField|null>(null);
const sectionModal = ref<number|null>(null);
const sectionDraft = ref<BlueprintSection|null>(null);
const modalReturnFocus = ref<HTMLElement|null>(null);
const dragPayload = ref<DragPayload|null>(null);
let revertingSite = false;
const openGroups = ref<Record<string, boolean>>({ Studio: true, 'Modules métier': true });
const groupFilter = computed(() => String(route.query.group || '').toLowerCase());
const canRead = computed(() => context.can('blueprints.read'));
const canManage = computed(() => context.can('blueprints.manage'));
const searchQuery = ref('');
const familyFilter = ref('');
const typeFilter = ref('');
const scopeFilter = ref('');
const stateFilter = ref('');
const mobilePanel = ref<'list'|'structure'|'inspector'>('list');

const emptySection = (): BlueprintSection => ({ section_key: 'content', label: t('blueprints.purpose.content'), description: '', layout: 'tab', sort_order: 10, fields: [], fieldsets: [] });
const blueprint = ref<BlueprintDesign>({ blueprint_key: 'page', resource_type: 'content_type', label: 'Page', description: '', site_id: null, legacy_content_type_id: null, sections: [emptySection()] });

const fallbackFieldTypes = computed<FieldType[]>(() => [
  { handle: 'text', label: t('blueprints.fieldType.text'), purpose: 'content' },
  { handle: 'textarea', label: t('blueprints.fieldType.textarea'), purpose: 'content' },
  { handle: 'richtext', label: t('blueprints.fieldType.richtext'), purpose: 'content' },
  { handle: 'markdown', label: t('blueprints.fieldType.markdown'), purpose: 'content' },
  { handle: 'number', label: t('blueprints.fieldType.number'), purpose: 'content' },
  { handle: 'boolean', label: t('blueprints.fieldType.boolean'), purpose: 'content' },
  { handle: 'select', label: t('blueprints.fieldType.select'), purpose: 'content' },
  { handle: 'multiselect', label: t('blueprints.fieldType.multiselect'), purpose: 'content' },
  { handle: 'date', label: t('blueprints.fieldType.date'), purpose: 'content' },
  { handle: 'media', label: t('blueprints.fieldType.media'), purpose: 'content' },
  { handle: 'relation', label: t('blueprints.fieldType.relation'), purpose: 'content' },
  { handle: 'replicator', label: t('blueprints.fieldType.replicator'), purpose: 'page_builder' },
  { handle: 'json', label: t('blueprints.fieldType.json'), purpose: 'metadata' },
  { handle: 'slug', label: t('blueprints.fieldType.slug'), purpose: 'system' },
  { handle: 'sites', label: t('blueprints.fieldType.sites'), purpose: 'system' }
]);

const fieldTypeOptions = computed(() => fieldTypes.value.length ? fieldTypes.value : fallbackFieldTypes.value);
const stats = computed(() => model.value.summary || { blueprints: 0, sections: 0, fields: 0, fieldsets: 0 });
const fieldsets = computed(() => model.value.fieldsets || []);
const audit = computed(() => model.value.audit || {});
const activeAudit = computed(() => (audit.value.items || []).find((item) => item.blueprint_key === blueprint.value.blueprint_key && (!item.resource_type || item.resource_type === blueprint.value.resource_type) && blueprintScope(item) === activeBlueprintScope.value));
const selectedSection = computed(() => blueprint.value.sections[selectedSectionIndex.value] || blueprint.value.sections[0] || emptySection());
const editingField = computed(() => fieldDraft.value);
const isNewBlueprint = computed(() => activeBlueprintKey.value === 'nouvelle_structure');
const isNewFieldset = computed(() => activeFieldsetKey.value === 'nouveau_fieldset');
const activeFieldsetProtected = computed(() => Boolean(editingFieldset.value?.is_system || editingFieldset.value?.is_deletable === false));
const activeVersion = ref<BlueprintVersion|null>(null);
const draftVersion = ref<BlueprintVersion|null>(null);
const blueprintVersions = ref<BlueprintVersion[]>([]);
const hasDraftToActivate = computed(() => Boolean(draftVersion.value && (!activeVersion.value || draftVersion.value.version !== activeVersion.value.version || draftVersion.value.checksum_sha256 !== activeVersion.value.checksum_sha256)));
const { blueprintBaseline, fieldsetBaseline, blueprintDirty, fieldsetDirty, hasUnsavedChanges, markBlueprintLoaded, markFieldsetLoaded } = useBlueprintEditorState(blueprint, editingFieldset, isNewBlueprint, isNewFieldset);
const blueprintGroupDefinitions = [
  'Studio',
  'Blocs éditoriaux',
  'Modules métier',
  'Système'
];

function groupForBlueprint(item: BlueprintRow): string {
  if (['module_resource', 'headless'].includes(item.resource_type)) return 'Modules métier';
  if (item.resource_type === 'block') return 'Blocs éditoriaux';
  if (item.resource_type === 'system') return 'Système';
  // Les lignes historiques ne portent pas encore toutes une famille dédiée.
  // Cette heuristique isolée ne sert que de repli jusqu'à l'ajout éventuel d'une métadonnée native.
  const key = `${item.blueprint_key} ${item.label}`.toLowerCase();
  if (key.startsWith('module_')) return 'Modules métier';
  if (key.includes('security_') || key.includes('iam_login_mode')) return 'Système';
  return 'Studio';
}

function blueprintScope(item: Pick<BlueprintRow, 'site_id'>): BlueprintScope { return item.site_id === null || item.site_id === undefined ? 'global' : 'site'; }
function blueprintIdentity(item: Pick<BlueprintRow, 'blueprint_key'|'resource_type'|'site_id'>): string { return `${item.resource_type}:${item.blueprint_key}:${blueprintScope(item)}`; }
const activeBlueprintIdentity = computed(() => `${activeBlueprintType.value}:${activeBlueprintKey.value}:${activeBlueprintScope.value}`);
function resourceTypeLabel(type: string): string { return t(`blueprints.resource.${type}`) || type; }
function scopeLabel(scope: BlueprintScope): string { return scope === 'global' ? t('blueprints.scope.global') : t('blueprints.scope.site', { site: context.context?.site.name || `#${context.siteId}` }); }
function fieldsetUsageScopeLabel(usage: FieldsetUsage): string { return usage.scope === 'global' || usage.site_id == null ? t('blueprints.scope.globalShort') : t('blueprints.scope.siteId', { id: usage.site_id }); }
function fieldsetUsageLabel(usage: FieldsetUsage): string { return `${usage.label || usage.blueprint_key} (${usage.blueprint_key}) · ${resourceTypeLabel(usage.resource_type)} · ${fieldsetUsageScopeLabel(usage)}`; }
function fieldsetImpactedStructures(fieldset: FieldsetRow|null): string[] { return (fieldset?.used_by || []).map(fieldsetUsageLabel); }
function layoutLabel(layout: BlueprintSection['layout']): string { return t(`blueprints.layout.${layout}`); }
function matchesState(item: BlueprintRow): boolean {
  if (!stateFilter.value) return true;
  if (stateFilter.value === 'active') return item.is_active !== false && Boolean(item.active_version);
  if (stateFilter.value === 'inactive') return item.is_active === false;
  return item.is_active !== false && !item.active_version;
}

const blueprintGroups = computed(() => {
  const groups: Record<string, BlueprintRow[]> = Object.fromEntries(blueprintGroupDefinitions.map((group) => [group, []])) as Record<string, BlueprintRow[]>;
  const query = searchQuery.value.trim().toLocaleLowerCase('fr');
  for (const item of model.value.blueprints || []) {
    const family = groupForBlueprint(item);
    if (familyFilter.value && family !== familyFilter.value) continue;
    if (typeFilter.value && item.resource_type !== typeFilter.value) continue;
    if (scopeFilter.value && blueprintScope(item) !== scopeFilter.value) continue;
    if (!matchesState(item)) continue;
    if (query && !`${item.label} ${item.blueprint_key} ${item.description || ''}`.toLocaleLowerCase('fr').includes(query)) continue;
    groups[family].push(item);
  }
  const entries = blueprintGroupDefinitions.map((group) => [group, groups[group]] as [string, BlueprintRow[]]);
  const routeEntries = groupFilter.value === 'modules' ? entries.filter(([group]) => group === 'Modules métier') : entries;
  return routeEntries.filter(([, items]) => items.length > 0);
});
const availableResourceTypes = computed(() => [...new Set((model.value.blueprints || []).map((item) => item.resource_type))].sort());
function purposeLabel(purpose: string): string { return t(`blueprints.purpose.${purpose}`) || purpose; }

function normalizeHandle(value: string): string {
  return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, '');
}
function clone<T>(value: T): T { return JSON.parse(JSON.stringify(value)) as T; }
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
  if (item.is_active === false) return t('blueprints.status.inactive');
  if (item.active_version) return t('blueprints.status.schemaVersion', { version: item.active_version });
  return t('blueprints.status.noActiveVersion');
}
function blueprintStatusClass(item: BlueprintRow): string {
  if (item.is_active === false) return 'text-bg-secondary';
  if (item.active_version) return 'text-bg-success';
  return 'text-bg-warning';
}
function blueprintUsageLabel(item: BlueprintRow): string {
  if (item.content_type_key) return t('blueprints.usage.contentType', { key: item.content_type_key });
  if ((item.usage_count || 0) > 0) return t('blueprints.tree.usagesCount', { count: item.usage_count || 0 });
  return t('blueprints.usage.unlinked');
}
function formatJson(value: unknown): string { try { return JSON.stringify(value || {}, null, 2); } catch { return '{}'; } }
function assignJson(target: BlueprintField, key: 'options'|'validation', raw: string): void { try { target[key] = raw.trim() ? JSON.parse(raw) : {}; error.value = ''; } catch { error.value = t('blueprints.error.invalidJson', { key }); } }
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
  return { field_handle: normalizeHandle(type === 'seo' ? `seo_field_${n}` : `field_${n}`), field_type: normalizeFieldTypeForSave(type), label: meta?.label || t('blueprints.field.new'), help_text: '', field_purpose: meta?.purpose || 'content', width: 100, is_required: false, is_localized: true, is_system: false, is_deletable: true, sort_order: n * 10, options: type.includes('select') ? { choices: [{ value: 'option', label: 'Option' }] } : {}, validation: {}, conditions: [], config: meta?.config_schema ? JSON.parse(JSON.stringify(meta.config_schema)) : {} };
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
      await loadBlueprint(first.blueprint_key, first.resource_type, blueprintScope(first));
    }
  } catch (err) {
    error.value = apiErrorMessage(err, t('blueprints.error.modelUnavailable'));
  } finally { loading.value = false; }
}

async function loadBlueprint(key: string, resourceType?: string, scope: BlueprintScope = 'site'): Promise<void> {
  if (!key) return;
  error.value = ''; success.value = ''; selectedSectionIndex.value = 0; closeSectionModal(); closeFieldModal();
  try {
    const row = (model.value.blueprints || []).find((item) => item.blueprint_key === key && (!resourceType || item.resource_type === resourceType) && blueprintScope(item) === scope);
    const response = await adminApi.get<BlueprintDesignResponse>(`/blueprints/${encodeURIComponent(key)}/design`, { site_id: context.siteId, resource_type: row?.resource_type || resourceType || undefined, scope });
    const next: BlueprintDesign = {
      blueprint_key: String(response.data.blueprint?.blueprint_key || key),
      resource_type: String(response.data.blueprint?.resource_type || row?.resource_type || 'content_type'),
      label: String(response.data.blueprint?.label || key),
      description: String(response.data.blueprint?.description || ''),
      site_id: response.data.blueprint?.site_id as number|null ?? row?.site_id ?? null,
      legacy_content_type_id: response.data.blueprint?.legacy_content_type_id as number|null ?? null,
      sections: (response.data.sections || [emptySection()]).map((section) => ({ ...emptySection(), ...section, fields: (section.fields || []).map((field) => ({ ...field, field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {} })), fieldsets: section.fieldsets || [] }))
    };
    blueprint.value = next;
    activeBlueprintKey.value = key;
    activeBlueprintType.value = next.resource_type;
    activeBlueprintScope.value = scope;
    activeVersion.value = response.data.active_version || null;
    draftVersion.value = response.data.draft_version || null;
    blueprintVersions.value = response.data.versions || [];
    markBlueprintLoaded(next);
  } catch (err) { error.value = apiErrorMessage(err, t('blueprints.error.unavailable')); }
}

async function requestBlueprint(item: BlueprintRow): Promise<void> {
  if (blueprintIdentity(item) === activeBlueprintIdentity.value) return;
  if (blueprintDirty.value && !window.confirm(t('blueprints.confirm.changeBlueprint'))) return;
  await loadBlueprint(item.blueprint_key, item.resource_type, blueprintScope(item));
  mobilePanel.value = 'structure';
}

function newBlueprint(): void {
  if (hasUnsavedChanges.value && !window.confirm(t('blueprints.confirm.createBlueprint'))) return;
  activeTab.value = 'blueprints'; activeBlueprintKey.value = 'nouvelle_structure'; activeBlueprintType.value = 'content_type'; activeBlueprintScope.value = 'site'; selectedSectionIndex.value = 0;
  blueprint.value = { blueprint_key: 'nouvelle_structure', resource_type: 'content_type', label: t('blueprints.default.newStructure'), description: '', site_id: context.siteId ?? null, legacy_content_type_id: null, sections: [emptySection()] };
  activeVersion.value = null; draftVersion.value = null; blueprintVersions.value = [];
  blueprintBaseline.value = '';
}
function addSection(): void {
  const index = blueprint.value.sections.length + 1;
  blueprint.value.sections.push({ section_key: `section_${index}`, label: t('blueprints.default.section', { index }), description: '', layout: 'tab', sort_order: index * 10, fields: [], fieldsets: [] });
  selectedSectionIndex.value = index - 1; renumber();
}
function selectSection(index: number): void { selectedSectionIndex.value = index; }
function openSectionAt(index: number): void { selectedSectionIndex.value = index; openSectionModal(); }
function addFieldAt(sectionIndex: number, type: string): void { addField(blueprint.value.sections[sectionIndex], type); }
function importFieldsetAt(sectionIndex: number, key: string): void { importFieldset(blueprint.value.sections[sectionIndex], key); }
function duplicateFieldAt(sectionIndex: number, fieldIndex: number): void { const section = blueprint.value.sections[sectionIndex]; const field = section?.fields[fieldIndex]; if (section && field) duplicateField(section, field); }
function removeFieldAt(sectionIndex: number, fieldIndex: number): void { const section = blueprint.value.sections[sectionIndex]; if (section) removeField(section, fieldIndex); }
function removeFieldsetAt(sectionIndex: number, mountIndex: number): void { const section = blueprint.value.sections[sectionIndex]; if (section) removeFieldset(section, mountIndex); }
function moveField(sectionIndex: number, fieldIndex: number, direction: -1|1): void {
  const fields = blueprint.value.sections[sectionIndex]?.fields; const target = fieldIndex + direction;
  if (!fields || target < 0 || target >= fields.length) return;
  const [field] = fields.splice(fieldIndex, 1); fields.splice(target, 0, field); renumber();
}
function moveSection(from: number, to: number): void { if (from === to) return; const [section] = blueprint.value.sections.splice(from, 1); if (section) blueprint.value.sections.splice(to, 0, section); selectedSectionIndex.value = to; renumber(); }
function reorderField(sectionIndex: number, from: number, to: number): void { if (from === to) return; const fields = blueprint.value.sections[sectionIndex]?.fields; if (!fields) return; const [field] = fields.splice(from, 1); if (field) fields.splice(to, 0, field); renumber(); }
function changeFieldSection(sectionIndex: number, fieldIndex: number, targetSection: number): void {
  if (sectionIndex === targetSection) return;
  const from = blueprint.value.sections[sectionIndex]; const to = blueprint.value.sections[targetSection];
  if (!from || !to) return;
  const [field] = from.fields.splice(fieldIndex, 1); if (field) to.fields.push(field); selectedSectionIndex.value = targetSection; renumber();
}
function openFieldFromTree(sectionIndex: number, fieldIndex: number, trigger: HTMLElement): void { modalReturnFocus.value = trigger; openFieldModal(sectionIndex, fieldIndex); }
function duplicateField(section: BlueprintSection, field: BlueprintField): void {
  const copy = JSON.parse(JSON.stringify(field)) as BlueprintField;
  copy.id = undefined; copy.field_handle = normalizeHandle(`${field.field_handle}_copy`); copy.label = t('blueprints.field.copySuffix', { label: field.label }); copy.is_system = false; copy.is_deletable = true;
  section.fields.push(copy); renumber();
}
function removeField(section: BlueprintSection, fieldIndex: number): void {
  const field = section.fields[fieldIndex];
  if (!field) return;
  if (field.is_system || field.is_deletable === false) {
    error.value = t('blueprints.field.protected', { field: field.field_handle });
    return;
  }
  if (!window.confirm(t('blueprints.field.deleteConfirm', { field: field.field_handle }))) return;
  section.fields.splice(fieldIndex, 1); closeFieldModal(); renumber();
}
function addField(section: BlueprintSection, type: string): void {
  if (!type) return;
  section.fields.push(makeField(type)); selectedSectionIndex.value = blueprint.value.sections.indexOf(section); renumber(); openFieldModal(selectedSectionIndex.value, section.fields.length - 1, true);
}
function importFieldset(section: BlueprintSection, key: string): void {
  if (!key) return;
  const fs = fieldsets.value.find((item) => item.fieldset_key === key); if (!fs) return;
  section.fieldsets.push({ fieldset_key: fs.fieldset_key, mount_handle: fs.fieldset_key, label: fs.label, sort_order: section.fieldsets.length * 10 + 10, conditions: [], config: {} }); renumber();
}
function removeFieldset(section: BlueprintSection, index: number): void { section.fieldsets.splice(index, 1); renumber(); }
function viewFieldsetUsages(key: string): void {
  const fs = fieldsets.value.find((item) => item.fieldset_key === key) || (editingFieldset.value?.fieldset_key === key ? editingFieldset.value : null);
  if (!fs) return;
  const usages = fieldsetImpactedStructures(fs);
  const message = usages.length
    ? t('blueprints.fieldset.usagesAlert', { label: fs.label, key: fs.fieldset_key, usages: usages.map((usage) => `- ${usage}`).join('\n') })
    : t('blueprints.fieldset.noUsage', { label: fs.label, key: fs.fieldset_key });
  window.alert(message);
}
function startDrag(payload: DragPayload): void { dragPayload.value = payload; }
function dropOnSection(targetIndex: number): void {
  const payload = dragPayload.value; if (!payload || payload.sectionIndex === undefined) return;
  if (payload.kind === 'section') {
    const [section] = blueprint.value.sections.splice(payload.sectionIndex, 1); blueprint.value.sections.splice(targetIndex, 0, section); selectedSectionIndex.value = targetIndex;
  } else if (payload.kind === 'field' && payload.fieldIndex !== undefined) {
    const from = blueprint.value.sections[payload.sectionIndex]; const to = blueprint.value.sections[targetIndex];
    const [field] = from.fields.splice(payload.fieldIndex, 1); to.fields.push(field); selectedSectionIndex.value = targetIndex;
  }
  dragPayload.value = null; renumber();
}
function dropOnField(targetSection: number, targetField: number): void {
  const payload = dragPayload.value; if (!payload || payload.kind !== 'field' || payload.sectionIndex === undefined || payload.fieldIndex === undefined) return;
  const from = blueprint.value.sections[payload.sectionIndex]; const [field] = from.fields.splice(payload.fieldIndex, 1); const to = blueprint.value.sections[targetSection];
  to.fields.splice(targetField, 0, field); dragPayload.value = null; selectedSectionIndex.value = targetSection; renumber();
}
function dropOnFieldsetField(targetField: number): void {
  const payload = dragPayload.value; const fields = editingFieldset.value?.fields;
  if (!fields || !payload || payload.kind !== 'fieldsetField' || payload.fieldIndex === undefined) return;
  const [field] = fields.splice(payload.fieldIndex, 1); fields.splice(targetField, 0, field); dragPayload.value = null; renumber();
}
function cleanPayload(): Record<string, unknown> {
  renumber();
  return { ...blueprint.value, blueprint_key: normalizeHandle(blueprint.value.blueprint_key), sections: blueprint.value.sections.map((section) => ({ ...section, section_key: normalizeHandle(section.section_key), fields: section.fields.map((field) => ({ ...field, field_handle: normalizeHandle(field.field_handle), field_type: normalizeFieldTypeForSave(field.field_type), field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {}, options: field.options || {}, validation: field.validation || {} })) })) };
}
function blueprintVersionLabel(version: BlueprintVersion|null): string {
  if (!version) return t('blueprints.editor.noVersion');
  return `v${version.version} · ${version.status}`;
}
function activationSummary(version: BlueprintVersion|null = draftVersion.value): string {
  const active = blueprintVersionLabel(activeVersion.value);
  const draft = blueprintVersionLabel(version);
  const sections = blueprint.value.sections.length;
  const fields = blueprint.value.sections.reduce((sum, section) => sum + section.fields.length, 0);
  const groups = blueprint.value.sections.reduce((sum, section) => sum + section.fieldsets.length, 0);
  const targetLabel = version?.status === 'draft' ? t('blueprints.editor.draftToActivate') : t('blueprints.editor.versionToRestore');
  return t('blueprints.editor.activationConfirm', { label: blueprint.value.label, key: blueprint.value.blueprint_key, active, targetLabel, draft, sections, fields, groups });
}
async function saveBlueprint(): Promise<void> {
  if (!canManage.value || saving.value || !blueprintDirty.value) return;
  const siteName = context.context?.site.name ?? String(context.siteId ?? '');
  const scope = blueprint.value.site_id === null ? t('blueprints.scope.globalShort') : t('blueprints.scope.site', { site: siteName });
  if (!window.confirm(t('blueprints.editor.saveConfirm', { label: blueprint.value.label, key: blueprint.value.blueprint_key, site: siteName, scope }))) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    const payload = cleanPayload();
    const query = new URLSearchParams({ site_id: String(context.siteId || ''), resource_type: String(payload.resource_type || ''), scope: activeBlueprintScope.value });
    const response = await adminApi.put(`/blueprints/${encodeURIComponent(activeBlueprintKey.value || String(payload.blueprint_key))}/design?${query.toString()}`, payload);
    activeBlueprintKey.value = String(payload.blueprint_key);
    await load();
    if (response.data) await loadBlueprint(String(payload.blueprint_key), String(payload.resource_type || 'content_type'), activeBlueprintScope.value);
    success.value = t('blueprints.editor.draftSaved');
  } catch (err) { error.value = apiErrorMessage(err, t('blueprints.editor.saveDraftError')); }
  finally { saving.value = false; }
}
async function activateBlueprint(): Promise<void> {
  await activateBlueprintVersion(draftVersion.value);
}
async function activateBlueprintVersion(version: BlueprintVersion|null): Promise<void> {
  if (!canManage.value || activating.value || !version) return;
  if (blueprintDirty.value) {
    error.value = t('blueprints.editor.saveBeforeActivation');
    return;
  }
  if (!window.confirm(activationSummary(version))) return;
  activating.value = true; error.value = ''; success.value = '';
  try {
    const activatedVersion = version.version;
    const query = new URLSearchParams({ site_id: String(context.siteId || ''), resource_type: blueprint.value.resource_type, scope: activeBlueprintScope.value });
    await adminApi.post(`/blueprints/${encodeURIComponent(activeBlueprintKey.value)}/activate?${query.toString()}`, { version: activatedVersion, resource_type: blueprint.value.resource_type, site_id: context.siteId, scope: activeBlueprintScope.value });
    await load();
    await loadBlueprint(activeBlueprintKey.value, blueprint.value.resource_type, activeBlueprintScope.value);
    success.value = t('blueprints.editor.versionActivated', { version: activatedVersion });
  } catch (err) {
    error.value = apiErrorMessage(err, t('blueprints.editor.activationError'));
  } finally { activating.value = false; }
}
async function deleteBlueprint(): Promise<void> {
  if (!activeBlueprintKey.value || !canManage.value) return;
  const message = t('blueprints.editor.deleteWarning');
  if (!window.confirm(message)) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    const query = new URLSearchParams({ site_id: String(context.siteId || ''), resource_type: blueprint.value.resource_type, scope: activeBlueprintScope.value });
    await adminApi.delete(`/blueprints/${encodeURIComponent(activeBlueprintKey.value)}?${query.toString()}`);
    success.value = t('blueprints.editor.deleted'); activeBlueprintKey.value = ''; activeBlueprintType.value = ''; await load();
  }
  catch (err) { error.value = apiErrorMessage(err, t('blueprints.editor.deleteError')); }
  finally { saving.value = false; }
}
function newFieldset(): void {
  if (hasUnsavedChanges.value && !window.confirm(t('blueprints.fieldsets.createConfirm'))) return;
  activeTab.value = 'fieldsets'; activeFieldsetKey.value = 'nouveau_fieldset'; editingFieldset.value = { fieldset_key: 'nouveau_fieldset', label: t('blueprints.fieldsets.newName'), description: '', fieldset_purpose: 'content', fields_count: 0, usage_count: 0, is_system: false, is_deletable: true, fields: [] };
  fieldsetBaseline.value = '';
}
async function editFieldset(key: string): Promise<void> {
  if (key === activeFieldsetKey.value) return;
  if (fieldsetDirty.value && !window.confirm(t('blueprints.fieldsets.changeConfirm'))) return;
  activeTab.value = 'fieldsets'; error.value = ''; closeFieldModal();
  try {
    const response = await adminApi.get<FieldsetRow>(`/fieldsets/${encodeURIComponent(key)}`);
    const next = { ...response.data, fields: (response.data.fields || []).map((field) => ({ ...field, field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {} })) };
    editingFieldset.value = next;
    activeFieldsetKey.value = key;
    markFieldsetLoaded(next);
  }
  catch (err) { error.value = apiErrorMessage(err, t('blueprints.error.unavailable')); }
}
function addFieldsetField(type = 'text'): void {
  if (!type) return;
  if (!editingFieldset.value) newFieldset();
  if (activeFieldsetProtected.value) {
    error.value = t('blueprints.fieldsets.protectedVariant');
    return;
  }
  editingFieldset.value?.fields?.push(makeField(type)); renumber(); openFieldsetFieldModal((editingFieldset.value?.fields?.length || 1) - 1, true);
}
function createFieldsetVariant(): void {
  if (!editingFieldset.value) return;
  const source = clone(editingFieldset.value);
  const baseKey = normalizeHandle(`${source.fieldset_key}_variante`) || 'fieldset_variante';
  const existing = new Set(fieldsets.value.map((item) => item.fieldset_key));
  let key = baseKey;
  let suffix = 2;
  while (existing.has(key)) {
    key = `${baseKey}_${suffix}`;
    suffix += 1;
  }
  editingFieldset.value = {
    ...source,
    id: undefined,
    fieldset_key: key,
    label: t('blueprints.fieldsets.variantSuffix', { label: source.label }),
    is_system: false,
    is_deletable: true,
    usage_count: 0,
    used_by: [],
    fields: (source.fields || []).map((field) => ({ ...field, id: undefined, is_system: false, is_deletable: true }))
  };
  activeFieldsetKey.value = 'nouveau_fieldset';
  fieldsetBaseline.value = '';
  success.value = t('blueprints.fieldsets.variantCreated');
}
async function saveFieldset(): Promise<void> {
  if (!editingFieldset.value || saving.value || !fieldsetDirty.value) return;
  if (activeFieldsetProtected.value) {
    error.value = t('blueprints.fieldsets.protectedDirectEdit');
    return;
  }
  const impacted = fieldsetImpactedStructures(editingFieldset.value);
  const impact = impacted.length > 0
    ? t('blueprints.fieldsets.affected', { items: impacted.map((item) => `- ${item}`).join('\n') })
    : t('blueprints.fieldsets.noAffected');
  if (!window.confirm(t('blueprints.fieldsets.saveConfirm', { label: editingFieldset.value.label, key: editingFieldset.value.fieldset_key, impact }))) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    const payload = { ...editingFieldset.value, fieldset_key: normalizeHandle(editingFieldset.value.fieldset_key), fields: (editingFieldset.value.fields || []).map((field, index) => ({ ...field, field_handle: normalizeHandle(field.field_handle), field_type: normalizeFieldTypeForSave(field.field_type), field_purpose: field.field_purpose || fieldPurpose(field.field_type), sort_order: (index + 1) * 10, config: field.config || {}, options: field.options || {}, validation: field.validation || {} })) };
    if (activeFieldsetKey.value === 'nouveau_fieldset') await adminApi.post('/fieldsets', payload); else await adminApi.put(`/fieldsets/${encodeURIComponent(activeFieldsetKey.value)}`, payload);
    success.value = t('blueprints.fieldsets.saved');
    activeFieldsetKey.value = ''; await load(); await editFieldset(payload.fieldset_key);
    success.value = t('blueprints.fieldsets.saved');
  } catch (err) { error.value = apiErrorMessage(err, t('blueprints.fieldsets.saveError')); }
  finally { saving.value = false; }
}
function confirmFieldModal(): void {
  const modal = fieldModal.value;
  if (!modal || !fieldDraft.value) return;
  if (modal.mode === 'fieldset' && activeFieldsetProtected.value) {
    error.value = t('blueprints.fieldsets.protectedVariant');
    return;
  }
  if (modal.mode === 'fieldset') editingFieldset.value!.fields![modal.fieldIndex] = clone(fieldDraft.value);
  else blueprint.value.sections[modal.sectionIndex ?? 0].fields[modal.fieldIndex] = clone(fieldDraft.value);
  closeFieldModal();
  renumber();
}
function removeFieldsetField(): void {
  const index = fieldModal.value?.fieldIndex;
  if (index === undefined) return;
  if (activeFieldsetProtected.value) {
    error.value = t('blueprints.fieldsets.protectedVariant');
    return;
  }
  editingFieldset.value?.fields?.splice(index, 1); closeFieldModal(); renumber();
}
function duplicateCurrentField(): void {
  const modal = fieldModal.value;
  if (!modal || modal.mode !== 'blueprint' || !fieldDraft.value) return;
  duplicateField(blueprint.value.sections[modal.sectionIndex ?? 0], fieldDraft.value);
}
function removeCurrentField(): void {
  const modal = fieldModal.value;
  if (!modal) return;
  if (modal.mode === 'fieldset') removeFieldsetField();
  else removeField(blueprint.value.sections[modal.sectionIndex ?? 0], modal.fieldIndex);
}
async function deleteFieldset(): Promise<void> {
  if (!editingFieldset.value) return;
  if (activeFieldsetProtected.value) {
    error.value = t('blueprints.fieldsets.deleteProtected');
    return;
  }
  if ((editingFieldset.value.usage_count || 0) > 0) {
    viewFieldsetUsages(editingFieldset.value.fieldset_key);
    return;
  }
  if (!window.confirm(t('blueprints.fieldsets.deleteConfirm', { label: editingFieldset.value.label, key: editingFieldset.value.fieldset_key }))) return;
  saving.value = true; error.value = ''; success.value = '';
  try { await adminApi.delete(`/fieldsets/${encodeURIComponent(editingFieldset.value.fieldset_key)}`); success.value = t('blueprints.fieldsets.deleted'); editingFieldset.value = null; activeFieldsetKey.value = ''; await load(); }
  catch (err) { error.value = apiErrorMessage(err, t('blueprints.fieldsets.deleteError')); }
  finally { saving.value = false; }
}
function openFieldModal(sectionIndex: number, fieldIndex: number, isNew = false): void { modalReturnFocus.value ||= document.activeElement as HTMLElement|null; selectedSectionIndex.value = sectionIndex; fieldModal.value = { mode: 'blueprint', sectionIndex, fieldIndex, isNew }; fieldDraft.value = clone(blueprint.value.sections[sectionIndex].fields[fieldIndex]); }
function openFieldsetFieldModal(fieldIndex: number, isNew = false): void { modalReturnFocus.value = document.activeElement as HTMLElement|null; fieldModal.value = { mode: 'fieldset', fieldIndex, isNew }; fieldDraft.value = clone(editingFieldset.value!.fields![fieldIndex]); }
function closeFieldModal(): void { const target = modalReturnFocus.value; fieldModal.value = null; fieldDraft.value = null; modalReturnFocus.value = null; nextTick(() => target?.focus()); }
function cancelFieldModal(): void {
  const modal = fieldModal.value;
  if (modal?.isNew) {
    if (modal.mode === 'fieldset') editingFieldset.value?.fields?.splice(modal.fieldIndex, 1);
    else blueprint.value.sections[modal.sectionIndex ?? 0]?.fields.splice(modal.fieldIndex, 1);
    renumber();
  }
  closeFieldModal();
}
function openSectionModal(): void { modalReturnFocus.value = document.activeElement as HTMLElement|null; sectionModal.value = selectedSectionIndex.value; sectionDraft.value = clone(selectedSection.value); }
function closeSectionModal(): void { const target = modalReturnFocus.value; sectionModal.value = null; sectionDraft.value = null; modalReturnFocus.value = null; nextTick(() => target?.focus()); }
function confirmSectionModal(): void {
  if (sectionModal.value === null || !sectionDraft.value) return;
  blueprint.value.sections[sectionModal.value] = clone(sectionDraft.value);
  closeSectionModal(); renumber();
}
function requestTab(tab: Tab): void {
  if (tab === activeTab.value) return;
  if (hasUnsavedChanges.value && !window.confirm(t('blueprints.confirm.changeTab'))) return;
  activeTab.value = tab;
}
function handleTabKeydown(event: KeyboardEvent, tab: Tab): void {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
  event.preventDefault();
  const target: Tab = event.key === 'Home' ? 'blueprints' : event.key === 'End' ? 'fieldsets' : tab === 'blueprints' ? 'fieldsets' : 'blueprints';
  requestTab(target);
  nextTick(() => document.getElementById(target === 'blueprints' ? 'structures-tab' : 'fieldsets-tab')?.focus());
}
function confirmLeave(): boolean { return !hasUnsavedChanges.value || window.confirm(t('blueprints.confirm.leave')); }
function handleBeforeUnload(event: BeforeUnloadEvent): void { if (!hasUnsavedChanges.value) return; event.preventDefault(); event.returnValue = ''; }
function handleBeforeSiteChange(event: Event): void { if (!confirmLeave()) event.preventDefault(); }

onMounted(() => { window.addEventListener('beforeunload', handleBeforeUnload); window.addEventListener('amcms:before-site-change', handleBeforeSiteChange); void load(); });
onBeforeUnmount(() => { window.removeEventListener('beforeunload', handleBeforeUnload); window.removeEventListener('amcms:before-site-change', handleBeforeSiteChange); });
onBeforeRouteLeave((_to, _from, next) => { if (confirmLeave()) next(); else next(false); });
watch(() => context.siteId, async (_next, previous) => {
  if (previous === undefined || revertingSite) return;
  if (!confirmLeave()) {
    revertingSite = true;
    try { await context.load(previous); } finally { revertingSite = false; }
    return;
  }
  activeBlueprintKey.value = ''; activeBlueprintType.value = ''; activeFieldsetKey.value = ''; editingFieldset.value = null; activeVersion.value = null; draftVersion.value = null; blueprintVersions.value = []; blueprintBaseline.value = ''; fieldsetBaseline.value = ''; void load();
});
</script>

<template>
  <PageHeader :eyebrow="t('blueprints.eyebrow')" :title="t('blueprints.title')" :intro="t('blueprints.intro')" help-id="system.blueprints" />
  <div v-if="!canRead" class="card empty-state"><h2>{{ t('blueprints.accessDenied') }}</h2><p class="muted" v-html="t('blueprints.accessDeniedHelp', { permission: '<code>blueprints.read</code>' })"></p></div>
  <template v-else>
    <section class="blueprint-stats-grid mb-3">
      <article class="card h-100"><span class="text-muted small">{{ t('blueprints.stats.structures') }}</span><strong class="fs-3">{{ stats.blueprints ?? 0 }}</strong><small class="text-muted">{{ t('blueprints.stats.structuresHelp') }}</small></article>
      <article class="card h-100"><span class="text-muted small">{{ t('blueprints.stats.sections') }}</span><strong class="fs-3">{{ stats.sections ?? 0 }}</strong><small class="text-muted">{{ t('blueprints.stats.sectionsHelp') }}</small></article>
      <article class="card h-100"><span class="text-muted small">{{ t('blueprints.stats.fields') }}</span><strong class="fs-3">{{ stats.fields ?? 0 }}</strong><small class="text-muted">{{ t('blueprints.stats.fieldsHelp') }}</small></article>
      <article class="card h-100"><span class="text-muted small">{{ t('blueprints.stats.fieldsets') }}</span><strong class="fs-3">{{ stats.fieldsets ?? 0 }}</strong><small class="text-muted">{{ t('blueprints.stats.fieldsetsHelp') }}</small></article>
    </section>

    <nav class="editor-tabs blueprint-tabs" role="tablist" :aria-label="t('blueprints.tabs.navigation')">
      <button id="structures-tab" type="button" :class="['editor-tab', { active: activeTab === 'blueprints' }]" role="tab" aria-controls="structures-panel" :tabindex="activeTab === 'blueprints' ? 0 : -1" :aria-selected="activeTab === 'blueprints'" @click="requestTab('blueprints')" @keydown="handleTabKeydown($event, 'blueprints')">{{ t('blueprints.tabs.structures') }}</button>
      <button id="fieldsets-tab" type="button" :class="['editor-tab', { active: activeTab === 'fieldsets' }]" role="tab" aria-controls="fieldsets-panel" :tabindex="activeTab === 'fieldsets' ? 0 : -1" :aria-selected="activeTab === 'fieldsets'" @click="requestTab('fieldsets')" @keydown="handleTabKeydown($event, 'fieldsets')">{{ t('blueprints.tabs.fieldsets') }}</button>
    </nav>

    <section v-if="activeTab === 'blueprints'" class="card mb-3 border-0 shadow-sm">
      <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
          <p class="eyebrow mb-1">{{ t('blueprints.governance.eyebrow') }}</p>
          <h2 class="h5 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
            <span>{{ t('blueprints.governance.title') }}</span>
            <InfoHint :text="t('blueprints.governance.info')" placement="end" />
          </h2>
        </div>
        <span class="badge fs-6" :class="audit.status === 'blocking' ? 'text-bg-danger' : audit.status === 'warnings' ? 'text-bg-warning' : 'text-bg-success'">{{ audit.score ?? 0 }}/100 · {{ audit.status || 'ok' }}</span>
      </div>
      <div v-if="activeAudit" class="mt-3 row g-3">
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><strong>{{ activeAudit.score }}/100</strong><small class="d-block text-muted">{{ activeAudit.blueprint_key }} · {{ activeAudit.status }}</small></div></div>
        <div class="col-md-8"><ul class="mb-0 small text-muted"><li v-for="message in [...(activeAudit.blocking_errors || []), ...(activeAudit.warnings || []), ...(activeAudit.optimizations || [])].slice(0, 5)" :key="message">{{ message }}</li><li v-if="!((activeAudit.blocking_errors || []).length || (activeAudit.warnings || []).length || (activeAudit.optimizations || []).length)">{{ t('blueprints.governance.noIssue') }}</li></ul></div>
      </div>
    </section>

    <div v-show="activeTab === 'blueprints'" class="blueprint-mobile-navigation" role="navigation" :aria-label="t('blueprints.mobile.steps')"><button type="button" :class="['btn btn-sm', mobilePanel === 'list' ? 'btn-dark' : 'btn-outline-secondary']" @click="mobilePanel = 'list'">{{ t('blueprints.mobile.list') }}</button><button type="button" :class="['btn btn-sm', mobilePanel === 'structure' ? 'btn-dark' : 'btn-outline-secondary']" @click="mobilePanel = 'structure'">{{ t('blueprints.mobile.structure') }}</button><button type="button" :class="['btn btn-sm', mobilePanel === 'inspector' ? 'btn-dark' : 'btn-outline-secondary']" @click="mobilePanel = 'inspector'">{{ t('blueprints.mobile.inspector') }}</button></div>
    <section id="structures-panel" v-show="activeTab === 'blueprints'" role="tabpanel" aria-labelledby="structures-tab" class="blueprint-workbench blueprint-admin-modern">
      <BlueprintNavigationPanel class="blueprint-mobile-panel" :class="{ 'is-mobile-hidden': mobilePanel !== 'list' }"
        v-model:search="searchQuery" v-model:family="familyFilter" v-model:resource-type="typeFilter" v-model:scope="scopeFilter" v-model:state="stateFilter"
        :groups="blueprintGroups" :families="blueprintGroupDefinitions" :resource-types="availableResourceTypes" :active-identity="activeBlueprintIdentity" :can-manage="canManage" :site-name="context.context?.site.name || 'ce site'"
        @create="newBlueprint" @select="requestBlueprint" />

      <main class="blueprint-column blueprint-column--editor blueprint-mobile-panel" :class="{ 'is-mobile-hidden': mobilePanel !== 'structure' }">
        <div class="card mb-3">
          <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
            <div>
              <p class="eyebrow mb-1">{{ t('blueprints.editor.eyebrow') }}</p>
              <h2 class="h4 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
                <span>{{ blueprint.label }}</span>
                <span class="badge" :class="activeBlueprintScope === 'global' ? 'text-bg-info' : 'text-bg-primary'">{{ scopeLabel(activeBlueprintScope) }}</span>
                <InfoHint :text="t('blueprints.editor.info')" placement="end" />
              </h2>
            </div>
            <div class="text-end">
              <div class="btn-group flex-wrap">
                <button class="btn btn-outline-dark" :disabled="saving || activating || !canManage || !blueprintDirty" @click="saveBlueprint">{{ saving ? t('common.saving') : t('blueprints.editor.saveDraft') }}</button>
                <button class="btn btn-dark" :disabled="saving || activating || !canManage || blueprintDirty || !hasDraftToActivate" @click="activateBlueprint">{{ activating ? t('blueprints.editor.activating') : t('blueprints.editor.activateDraft') }}</button>
                <button class="btn btn-outline-danger" :disabled="saving || activating || !canManage || ['page','article','page_archive','article_archive'].includes(blueprint.blueprint_key)" @click="deleteBlueprint">{{ t('common.delete') }}</button>
              </div>
              <small class="d-block mt-1" :class="blueprintDirty ? 'text-warning' : 'text-muted'">{{ blueprintDirty ? t('blueprints.editor.localChanges') : t('blueprints.editor.noLocalChanges') }}</small>
              <small class="d-block text-muted">{{ t('blueprints.editor.activeDraft', { active: blueprintVersionLabel(activeVersion), draft: blueprintVersionLabel(draftVersion) }) }}</small>
            </div>
          </div>
          <div class="row g-3"><label class="col-md-6 form-label">{{ t('blueprints.editor.displayName') }}<input v-model="blueprint.label" class="form-control mt-1" :disabled="!canManage"></label><label class="col-md-6 form-label">{{ t('blueprints.editor.description') }}<textarea v-model="blueprint.description" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label></div>
          <details class="mt-3"><summary class="small fw-semibold">{{ t('blueprints.editor.advancedOptions') }}</summary><div class="row g-3 mt-1"><label class="col-md-6 form-label">{{ t('blueprints.editor.technicalId') }}<input v-model="blueprint.blueprint_key" class="form-control mt-1" :disabled="!canManage || !isNewBlueprint" @blur="blueprint.blueprint_key = normalizeHandle(blueprint.blueprint_key)"><small v-if="!isNewBlueprint" class="text-muted">{{ t('blueprints.editor.lockedAfterCreation') }}</small></label><label class="col-md-6 form-label">{{ t('blueprints.editor.resourceType') }}<select v-model="blueprint.resource_type" class="form-select mt-1" :disabled="!canManage || !isNewBlueprint"><option value="content_type">{{ t('blueprints.resource.content_type') }}</option><option value="block">{{ t('blueprints.resource.block') }}</option><option value="module_resource">{{ t('blueprints.resource.module_resource') }}</option><option value="headless">{{ t('blueprints.resource.headless') }}</option><option value="taxonomy">{{ t('blueprints.resource.taxonomy') }}</option><option value="media">{{ t('blueprints.resource.media') }}</option><option value="system">{{ t('blueprints.resource.system') }}</option></select></label><div class="col-12"><strong>{{ t('blueprints.editor.scope') }}</strong> {{ scopeLabel(activeBlueprintScope) }}</div></div></details>
          <details class="mt-3"><summary class="small fw-semibold">{{ t('blueprints.editor.versions') }}</summary><div class="list-group mt-2"><div v-for="version in blueprintVersions" :key="version.id" class="list-group-item d-flex justify-content-between align-items-center gap-2"><span><strong>v{{ version.version }}</strong> · {{ version.status }}<small v-if="version.version_label" class="d-block text-muted">{{ version.version_label }}</small></span><button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage || activating || blueprintDirty || version.is_active" @click="activateBlueprintVersion(version)">{{ version.is_active ? t('common.enabled') : t('blueprints.editor.activateVersion') }}</button></div><p v-if="blueprintVersions.length === 0" class="text-muted small mb-0">{{ t('blueprints.editor.noVersions') }}</p></div></details>
        </div>

        <BlueprintStructureTree
          :sections="blueprint.sections" :selected-section-index="selectedSectionIndex" :can-manage="canManage"
          :field-types="fieldTypeOptions" :available-fieldsets="fieldsets"
          @select-section="selectSection" @edit-section="openSectionAt" @add-section="addSection"
          @add-field="addFieldAt" @import-fieldset="importFieldsetAt" @edit-field="openFieldFromTree"
          @duplicate-field="duplicateFieldAt" @remove-field="removeFieldAt" @move-field="moveField"
          @move-section="moveSection" @reorder-field="reorderField" @change-field-section="changeFieldSection" @remove-fieldset="removeFieldsetAt"
          @view-fieldset-usages="viewFieldsetUsages"
        />
      </main>

      <BlueprintInspectorPanel class="blueprint-column blueprint-column--section sticky-xxl-top blueprint-sticky blueprint-mobile-panel" :class="{ 'is-mobile-hidden': mobilePanel !== 'inspector' }" :section="selectedSection" :section-index="selectedSectionIndex" :can-manage="canManage" @edit="openSectionAt" />
    </section>

    <section id="fieldsets-panel" v-show="activeTab === 'fieldsets'" role="tabpanel" aria-labelledby="fieldsets-tab" class="fieldset-workbench blueprint-admin-modern">
      <aside class="blueprint-column blueprint-column--models">
        <div class="card sticky-xl-top blueprint-sticky">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div><h2 class="h5 mb-0">{{ t('blueprints.fieldsets.title') }}</h2><small class="text-muted">{{ t('blueprints.fieldsets.reusableConfigurations') }}</small></div>
            <button class="btn btn-dark btn-sm" :disabled="!canManage" @click="newFieldset">{{ t('common.create') }}</button>
          </div>
          <div class="fieldset-list">
            <div v-for="fs in fieldsets" :key="fs.fieldset_key" class="fieldset-list-item" :class="{ 'is-selected': fs.fieldset_key === activeFieldsetKey }">
              <button class="fieldset-list-item__main" type="button" @click="editFieldset(fs.fieldset_key)">
                <span class="fieldset-list-item__title">
                  <strong>{{ fs.label }}</strong>
                  <span v-if="fs.is_system" class="badge text-bg-secondary">{{ t('blueprints.fieldsets.system') }}</span>
                </span>
                <code>{{ fs.fieldset_key }}</code>
                <span class="fieldset-list-item__meta">
                  <span>{{ t('blueprints.navigation.counts', { sections: 0, fields: fs.fields_count }).replace(/^0 section\\(s\\) · /, '') }}</span>
                  <span>{{ t('blueprints.tree.usagesCount', { count: fs.usage_count || 0 }) }}</span>
                  <span>{{ purposeLabel(fs.fieldset_purpose) }}</span>
                </span>
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary fieldset-list-item__usage" @click.stop="viewFieldsetUsages(fs.fieldset_key)">{{ t('blueprints.fieldsets.usages') }}</button>
            </div>
          </div>
        </div>
      </aside>
      <main class="blueprint-column blueprint-column--section">
        <div class="card" v-if="editingFieldset">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><p class="eyebrow mb-1">{{ t('blueprints.fieldsets.reusableGroup') }}</p><h2 class="h4 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">{{ editingFieldset.label }}<span v-if="editingFieldset.is_system" class="badge text-bg-secondary">{{ t('blueprints.fieldsets.systemProtected') }}</span></h2><p class="text-muted mb-0">{{ t('blueprints.fieldsets.sharedImpact') }}</p></div>
            <div class="text-end"><div class="btn-group"><button class="btn btn-dark" :disabled="saving || !canManage || !fieldsetDirty || activeFieldsetProtected" @click="saveFieldset">{{ saving ? t('common.saving') : t('blueprints.fieldsets.editShared') }}</button><button class="btn btn-outline-secondary" :disabled="saving || !canManage" @click="createFieldsetVariant">{{ t('blueprints.fieldsets.createVariant') }}</button><button class="btn btn-outline-danger" :disabled="saving || !canManage || activeFieldsetProtected || (editingFieldset.usage_count || 0) > 0" @click="deleteFieldset">{{ t('common.delete') }}</button></div><small class="d-block mt-1" :class="fieldsetDirty ? 'text-warning' : 'text-muted'">{{ fieldsetDirty ? t('blueprints.fieldsets.unsavedChanges') : t('blueprints.editor.noLocalChanges') }}</small></div>
          </div>
          <div v-if="activeFieldsetProtected" class="alert alert-secondary">{{ t('blueprints.fieldsets.protected') }}</div>
          <div v-if="(editingFieldset.usage_count || 0) > 0" class="alert alert-warning">
            <div><strong>{{ t('blueprints.fieldsets.sharedBy', { count: editingFieldset.usage_count || 0 }) }}</strong> {{ t('blueprints.fieldsets.sharedSaveWarning') }}</div>
            <ul class="mb-2 mt-2"><li v-for="usage in editingFieldset.used_by || []" :key="`${usage.resource_type}:${usage.blueprint_key}:${usage.scope || usage.site_id}`">{{ fieldsetUsageLabel(usage) }}</li></ul>
            <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-dark" @click="viewFieldsetUsages(editingFieldset.fieldset_key)">{{ t('blueprints.fieldsets.usages') }}</button><button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage" @click="createFieldsetVariant">{{ t('blueprints.fieldsets.createVariant') }}</button></div>
          </div>
          <div class="row g-3 mb-3"><label class="col-md-6 form-label">{{ t('blueprints.editor.displayName') }}<input v-model="editingFieldset.label" class="form-control mt-1" :disabled="!canManage || activeFieldsetProtected"></label><label class="col-md-6 form-label">{{ t('blueprints.editor.description') }}<textarea v-model="editingFieldset.description" class="form-control mt-1" rows="2" :disabled="!canManage || activeFieldsetProtected"></textarea></label></div>
          <details class="mb-3"><summary class="small fw-semibold">{{ t('blueprints.editor.advancedOptions') }}</summary><div class="row g-3 mt-1"><label class="col-md-6 form-label">{{ t('blueprints.editor.technicalId') }}<input v-model="editingFieldset.fieldset_key" class="form-control mt-1" :disabled="!canManage || !isNewFieldset || activeFieldsetProtected" @blur="editingFieldset.fieldset_key = normalizeHandle(editingFieldset.fieldset_key)"><small v-if="!isNewFieldset" class="text-muted">{{ t('blueprints.editor.lockedAfterCreation') }}</small></label><label class="col-md-6 form-label">{{ t('blueprints.fieldsets.mainUsage') }}<select v-model="editingFieldset.fieldset_purpose" class="form-select mt-1" :disabled="!canManage || activeFieldsetProtected"><option value="content">{{ t('blueprints.purpose.content') }}</option><option value="seo">{{ t('blueprints.purpose.seo') }}</option><option value="page_builder">{{ t('blueprints.purpose.page_builder') }}</option><option value="metadata">{{ t('blueprints.purpose.metadata') }}</option><option value="system">{{ t('blueprints.purpose.system') }}</option></select></label></div></details>
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h3 class="h5 mb-0">{{ t('blueprints.stats.fields') }}</h3><select class="form-select w-auto" :disabled="!canManage || activeFieldsetProtected" @change="addFieldsetField(String(($event.target as HTMLSelectElement).value)); ($event.target as HTMLSelectElement).value='' "><option value="">{{ t('blueprints.tree.addField') }}</option><option v-for="type in fieldTypeOptions" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></div>
          <div class="list-group">
            <button v-for="(field, i) in editingFieldset.fields || []" :key="field.field_handle + i" class="list-group-item list-group-item-action" type="button" draggable="true" @click="openFieldsetFieldModal(i)" @dragstart.stop="startDrag({ kind: 'fieldsetField', fieldIndex: i })" @dragover.prevent @drop.stop="dropOnFieldsetField(i)">
              <span class="d-flex align-items-center gap-2"><span class="blueprint-drag-handle" title="Move" aria-hidden="true">⋮</span><span class="flex-grow-1"><strong>{{ field.label }}</strong><small class="d-block text-muted"><code>{{ field.field_handle }}</code> · {{ fieldLabel(field.field_type) }} · {{ field.width }}%</small></span><span v-if="field.is_required" class="badge text-bg-warning">{{ t('common.required') }}</span></span>
            </button>
          </div>
        </div>
        <div v-else class="card empty-state"><strong>{{ t('blueprints.fieldsets.selectOrCreate') }}</strong></div>
      </main>
    </section>
  </template>

  <BlueprintFieldEditorModal
    v-if="fieldModal && fieldDraft"
    class="blueprint-field-editor"
    :field="fieldDraft"
    :field-types="fieldTypeOptions"
    :mode="fieldModal.mode"
    :is-new="fieldModal.isNew"
    :can-manage="canManage"
    @apply="confirmFieldModal"
    @cancel="cancelFieldModal"
    @duplicate="duplicateCurrentField"
    @remove="removeCurrentField"
    @json-error="error = $event"
  />
  <BlueprintSectionEditorModal v-if="sectionModal !== null && sectionDraft" class="blueprint-section-editor" :section="sectionDraft" :can-manage="canManage" @apply="confirmSectionModal" @cancel="closeSectionModal" />
  <div v-if="sectionModal !== null || fieldModal" class="modal-backdrop fade show"></div>

  <p v-if="loading" class="muted mt-3">{{ t('blueprints.editor.loading') }}</p><ApiFeedback :error="error" :success="success" />
</template>
