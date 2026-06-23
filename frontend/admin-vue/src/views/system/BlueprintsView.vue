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

const emptySection = (): BlueprintSection => ({ section_key: 'content', label: 'Contenu', description: '', layout: 'tab', sort_order: 10, fields: [], fieldsets: [] });
const blueprint = ref<BlueprintDesign>({ blueprint_key: 'page', resource_type: 'content_type', label: 'Page', description: '', site_id: null, legacy_content_type_id: null, sections: [emptySection()] });

const fallbackFieldTypes: FieldType[] = [
  { handle: 'text', label: 'Texte court', purpose: 'content' }, { handle: 'textarea', label: 'Texte long', purpose: 'content' }, { handle: 'richtext', label: 'Rich text', purpose: 'content' }, { handle: 'markdown', label: 'Markdown', purpose: 'content' }, { handle: 'number', label: 'Nombre', purpose: 'content' }, { handle: 'boolean', label: 'Oui / non', purpose: 'content' }, { handle: 'select', label: 'Sélection', purpose: 'content' }, { handle: 'multiselect', label: 'Multi-sélection', purpose: 'content' }, { handle: 'date', label: 'Date', purpose: 'content' }, { handle: 'media', label: 'Image / média', purpose: 'content' }, { handle: 'relation', label: 'Relation', purpose: 'content' }, { handle: 'replicator', label: 'Blocs / répétiteur', purpose: 'page_builder' }, { handle: 'json', label: 'JSON', purpose: 'metadata' }, { handle: 'slug', label: 'Slug', purpose: 'system' }, { handle: 'sites', label: 'Site', purpose: 'system' }
];

const fieldTypeOptions = computed(() => fieldTypes.value.length ? fieldTypes.value : fallbackFieldTypes);
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
  if (key.includes('security_') || key.includes('iam_email_2fa')) return 'Système';
  return 'Studio';
}

function blueprintScope(item: Pick<BlueprintRow, 'site_id'>): BlueprintScope { return item.site_id === null || item.site_id === undefined ? 'global' : 'site'; }
function blueprintIdentity(item: Pick<BlueprintRow, 'blueprint_key'|'resource_type'|'site_id'>): string { return `${item.resource_type}:${item.blueprint_key}:${blueprintScope(item)}`; }
const activeBlueprintIdentity = computed(() => `${activeBlueprintType.value}:${activeBlueprintKey.value}:${activeBlueprintScope.value}`);
const resourceTypeLabels: Record<string, string> = { content_type: 'Type de contenu', block: 'Bloc éditorial', module_resource: 'Ressource métier', headless: 'Ressource headless', taxonomy: 'Taxonomie', media: 'Média', system: 'Système' };
function resourceTypeLabel(type: string): string { return resourceTypeLabels[type] || type; }
function scopeLabel(scope: BlueprintScope): string { return scope === 'global' ? 'Globale — tous les sites' : `Propre à ${context.context?.site.name || 'ce site'}`; }
function fieldsetUsageScopeLabel(usage: FieldsetUsage): string { return usage.scope === 'global' || usage.site_id == null ? 'Globale' : `Site ${usage.site_id}`; }
function fieldsetUsageLabel(usage: FieldsetUsage): string { return `${usage.label || usage.blueprint_key} (${usage.blueprint_key}) · ${resourceTypeLabel(usage.resource_type)} · ${fieldsetUsageScopeLabel(usage)}`; }
function fieldsetImpactedStructures(fieldset: FieldsetRow|null): string[] { return (fieldset?.used_by || []).map(fieldsetUsageLabel); }
function layoutLabel(layout: BlueprintSection['layout']): string { return ({ tab: 'Onglet', section: 'Section', sidebar: 'Panneau latéral' } as const)[layout]; }
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
const purposeLabels: Record<string, string> = { content: 'Contenu', seo: 'SEO', page_builder: 'Page builder', metadata: 'Métadonnées', system: 'Système' };

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
  if (item.is_active === false) return 'inactif';
  if (item.active_version) return `schéma v${item.active_version}`;
  return 'sans version active';
}
function blueprintStatusClass(item: BlueprintRow): string {
  if (item.is_active === false) return 'text-bg-secondary';
  if (item.active_version) return 'text-bg-success';
  return 'text-bg-warning';
}
function blueprintUsageLabel(item: BlueprintRow): string {
  if (item.content_type_key) return `type: ${item.content_type_key}`;
  if ((item.usage_count || 0) > 0) return `${item.usage_count} utilisation(s)`;
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
      await loadBlueprint(first.blueprint_key, first.resource_type, blueprintScope(first));
    }
  } catch (err) {
    error.value = apiErrorMessage(err, 'Modèle de blueprints indisponible.');
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
  } catch (err) { error.value = apiErrorMessage(err, 'Blueprint indisponible.'); }
}

async function requestBlueprint(item: BlueprintRow): Promise<void> {
  if (blueprintIdentity(item) === activeBlueprintIdentity.value) return;
  if (blueprintDirty.value && !window.confirm('Changer de structure ? Les modifications locales non enregistrées seront perdues.')) return;
  await loadBlueprint(item.blueprint_key, item.resource_type, blueprintScope(item));
  mobilePanel.value = 'structure';
}

function newBlueprint(): void {
  if (hasUnsavedChanges.value && !window.confirm('Créer une structure ? Les modifications locales non enregistrées seront perdues.')) return;
  activeTab.value = 'blueprints'; activeBlueprintKey.value = 'nouvelle_structure'; activeBlueprintType.value = 'content_type'; activeBlueprintScope.value = 'site'; selectedSectionIndex.value = 0;
  blueprint.value = { blueprint_key: 'nouvelle_structure', resource_type: 'content_type', label: 'Nouvelle structure', description: '', site_id: context.siteId ?? null, legacy_content_type_id: null, sections: [emptySection()] };
  activeVersion.value = null; draftVersion.value = null; blueprintVersions.value = [];
  blueprintBaseline.value = '';
}
function addSection(): void {
  const index = blueprint.value.sections.length + 1;
  blueprint.value.sections.push({ section_key: `section_${index}`, label: `Section ${index}`, description: '', layout: 'tab', sort_order: index * 10, fields: [], fieldsets: [] });
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
    ? `Utilisations de « ${fs.label} » (${fs.fieldset_key}) :\n\n${usages.map((usage) => `- ${usage}`).join('\n')}`
    : `Le groupe « ${fs.label} » (${fs.fieldset_key}) n’est utilisé par aucune structure.`;
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
  if (!version) return 'aucune';
  return `v${version.version} · ${version.status}`;
}
function activationSummary(version: BlueprintVersion|null = draftVersion.value): string {
  const active = blueprintVersionLabel(activeVersion.value);
  const draft = blueprintVersionLabel(version);
  const sections = blueprint.value.sections.length;
  const fields = blueprint.value.sections.reduce((sum, section) => sum + section.fields.length, 0);
  const groups = blueprint.value.sections.reduce((sum, section) => sum + section.fieldsets.length, 0);
  const targetLabel = version?.status === 'draft' ? 'Brouillon à activer' : 'Version à réactiver';
  return `Activer la version sélectionnée de « ${blueprint.value.label} » (${blueprint.value.blueprint_key}) ?\n\nVersion active actuelle : ${active}\n${targetLabel} : ${draft}\nStructure de travail : ${sections} section(s), ${fields} champ(s), ${groups} groupe(s) réutilisable(s)\n\nLes contenus existants utiliseront ce contrat après activation. L’ancienne version active restera disponible pour retour arrière.`;
}
async function saveBlueprint(): Promise<void> {
  if (!canManage.value || saving.value || !blueprintDirty.value) return;
  const scope = blueprint.value.site_id === null ? 'globale' : `limitée au site ${context.context?.site.name || context.siteId}`;
  if (!window.confirm(`Enregistrer le brouillon de la structure « ${blueprint.value.label} » (${blueprint.value.blueprint_key}) ?\n\nSite : ${context.context?.site.name || context.siteId}\nPortée : ${scope}\n\nLa version active ne sera pas modifiée.`)) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    const payload = cleanPayload();
    const query = new URLSearchParams({ site_id: String(context.siteId || ''), resource_type: String(payload.resource_type || ''), scope: activeBlueprintScope.value });
    const response = await adminApi.put(`/blueprints/${encodeURIComponent(activeBlueprintKey.value || String(payload.blueprint_key))}/design?${query.toString()}`, payload);
    activeBlueprintKey.value = String(payload.blueprint_key);
    await load();
    if (response.data) await loadBlueprint(String(payload.blueprint_key), String(payload.resource_type || 'content_type'), activeBlueprintScope.value);
    success.value = 'Brouillon enregistré. La version active n’a pas été modifiée.';
  } catch (err) { error.value = apiErrorMessage(err, 'Enregistrement du brouillon impossible. Les modifications locales sont conservées.'); }
  finally { saving.value = false; }
}
async function activateBlueprint(): Promise<void> {
  await activateBlueprintVersion(draftVersion.value);
}
async function activateBlueprintVersion(version: BlueprintVersion|null): Promise<void> {
  if (!canManage.value || activating.value || !version) return;
  if (blueprintDirty.value) {
    error.value = 'Enregistrez d’abord le brouillon local avant activation ou retour arrière.';
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
    success.value = `Version v${activatedVersion} activée.`;
  } catch (err) {
    error.value = apiErrorMessage(err, 'Activation refusée. Le brouillon est conservé.');
  } finally { activating.value = false; }
}
async function deleteBlueprint(): Promise<void> {
  if (!activeBlueprintKey.value || !canManage.value) return;
  const message = 'La suppression définitive est réservée aux modèles non utilisés. Pour un modèle existant, préférez désactiver, masquer ou archiver les champs concernés. Continuer ?';
  if (!window.confirm(message)) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    const query = new URLSearchParams({ site_id: String(context.siteId || ''), resource_type: blueprint.value.resource_type, scope: activeBlueprintScope.value });
    await adminApi.delete(`/blueprints/${encodeURIComponent(activeBlueprintKey.value)}?${query.toString()}`);
    success.value = 'Structure supprimée.'; activeBlueprintKey.value = ''; activeBlueprintType.value = ''; await load();
  }
  catch (err) { error.value = apiErrorMessage(err, 'Suppression impossible.'); }
  finally { saving.value = false; }
}
function newFieldset(): void {
  if (hasUnsavedChanges.value && !window.confirm('Créer un groupe réutilisable ? Les modifications locales non enregistrées seront perdues.')) return;
  activeTab.value = 'fieldsets'; activeFieldsetKey.value = 'nouveau_fieldset'; editingFieldset.value = { fieldset_key: 'nouveau_fieldset', label: 'Nouveau groupe réutilisable', description: '', fieldset_purpose: 'content', fields_count: 0, usage_count: 0, is_system: false, is_deletable: true, fields: [] };
  fieldsetBaseline.value = '';
}
async function editFieldset(key: string): Promise<void> {
  if (key === activeFieldsetKey.value) return;
  if (fieldsetDirty.value && !window.confirm('Changer de groupe réutilisable ? Les modifications locales non enregistrées seront perdues.')) return;
  activeTab.value = 'fieldsets'; error.value = ''; closeFieldModal();
  try {
    const response = await adminApi.get<FieldsetRow>(`/fieldsets/${encodeURIComponent(key)}`);
    const next = { ...response.data, fields: (response.data.fields || []).map((field) => ({ ...field, field_purpose: field.field_purpose || fieldPurpose(field.field_type), config: field.config || {} })) };
    editingFieldset.value = next;
    activeFieldsetKey.value = key;
    markFieldsetLoaded(next);
  }
  catch (err) { error.value = apiErrorMessage(err, 'Fieldset indisponible.'); }
}
function addFieldsetField(type = 'text'): void {
  if (!type) return;
  if (!editingFieldset.value) newFieldset();
  if (activeFieldsetProtected.value) {
    error.value = 'Ce groupe système est protégé. Créez une variante pour modifier ses champs.';
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
    label: `${source.label} — variante`,
    is_system: false,
    is_deletable: true,
    usage_count: 0,
    used_by: [],
    fields: (source.fields || []).map((field) => ({ ...field, id: undefined, is_system: false, is_deletable: true }))
  };
  activeFieldsetKey.value = 'nouveau_fieldset';
  fieldsetBaseline.value = '';
  success.value = 'Variante locale créée. Enregistrez-la pour obtenir un nouveau groupe réutilisable.';
}
async function saveFieldset(): Promise<void> {
  if (!editingFieldset.value || saving.value || !fieldsetDirty.value) return;
  if (activeFieldsetProtected.value) {
    error.value = 'Ce groupe système est protégé. Créez une variante éditoriale plutôt que de le modifier directement.';
    return;
  }
  const impacted = fieldsetImpactedStructures(editingFieldset.value);
  const impact = impacted.length > 0
    ? `\n\nStructures affectées :\n${impacted.map((item) => `- ${item}`).join('\n')}`
    : '\n\nAucune structure affectée actuellement.';
  if (!window.confirm(`Modifier le groupe partagé « ${editingFieldset.value.label} » (${editingFieldset.value.fieldset_key}) ?${impact}\n\nOK = Modifier le groupe partagé.\nAnnuler = conserver les modifications locales sans enregistrer.`)) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    const payload = { ...editingFieldset.value, fieldset_key: normalizeHandle(editingFieldset.value.fieldset_key), fields: (editingFieldset.value.fields || []).map((field, index) => ({ ...field, field_handle: normalizeHandle(field.field_handle), field_type: normalizeFieldTypeForSave(field.field_type), field_purpose: field.field_purpose || fieldPurpose(field.field_type), sort_order: (index + 1) * 10, config: field.config || {}, options: field.options || {}, validation: field.validation || {} })) };
    if (activeFieldsetKey.value === 'nouveau_fieldset') await adminApi.post('/fieldsets', payload); else await adminApi.put(`/fieldsets/${encodeURIComponent(activeFieldsetKey.value)}`, payload);
    success.value = 'Groupe de champs enregistré.';
    activeFieldsetKey.value = ''; await load(); await editFieldset(payload.fieldset_key);
    success.value = 'Groupe de champs enregistré.';
  } catch (err) { error.value = apiErrorMessage(err, 'Enregistrement du groupe impossible. Les modifications locales sont conservées.'); }
  finally { saving.value = false; }
}
function confirmFieldModal(): void {
  const modal = fieldModal.value;
  if (!modal || !fieldDraft.value) return;
  if (modal.mode === 'fieldset' && activeFieldsetProtected.value) {
    error.value = 'Ce groupe système est protégé. Créez une variante pour modifier ses champs.';
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
    error.value = 'Ce groupe système est protégé. Créez une variante pour modifier ses champs.';
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
    error.value = 'Ce groupe système est protégé et ne peut pas être supprimé.';
    return;
  }
  if ((editingFieldset.value.usage_count || 0) > 0) {
    viewFieldsetUsages(editingFieldset.value.fieldset_key);
    return;
  }
  if (!window.confirm(`Supprimer définitivement le groupe « ${editingFieldset.value.label} » (${editingFieldset.value.fieldset_key}) ?`)) return;
  saving.value = true; error.value = ''; success.value = '';
  try { await adminApi.delete(`/fieldsets/${encodeURIComponent(editingFieldset.value.fieldset_key)}`); success.value = 'Fieldset supprimé.'; editingFieldset.value = null; activeFieldsetKey.value = ''; await load(); }
  catch (err) { error.value = apiErrorMessage(err, 'Suppression du groupe impossible.'); }
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
  if (hasUnsavedChanges.value && !window.confirm('Changer d’onglet ? Vos modifications restent locales, mais ne sont pas encore enregistrées. Continuer ?')) return;
  activeTab.value = tab;
}
function handleTabKeydown(event: KeyboardEvent, tab: Tab): void {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
  event.preventDefault();
  const target: Tab = event.key === 'Home' ? 'blueprints' : event.key === 'End' ? 'fieldsets' : tab === 'blueprints' ? 'fieldsets' : 'blueprints';
  requestTab(target);
  nextTick(() => document.getElementById(target === 'blueprints' ? 'structures-tab' : 'fieldsets-tab')?.focus());
}
function confirmLeave(): boolean { return !hasUnsavedChanges.value || window.confirm('Quitter cette page ? Les modifications locales non enregistrées seront perdues.'); }
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
  <PageHeader eyebrow="Structure éditoriale" title="Structures de contenu" intro="Organisez les champs et groupes réutilisables de chaque site sans modifier le code." />
  <div v-if="!canRead" class="card empty-state"><h2>Accès non autorisé</h2><p class="muted">Le droit <code>blueprints.read</code> est nécessaire.</p></div>
  <template v-else>
    <section class="blueprint-stats-grid mb-3">
      <article class="card h-100"><span class="text-muted small">Structures de contenu</span><strong class="fs-3">{{ stats.blueprints ?? 0 }}</strong><small class="text-muted">Types de contenus, blocs et modules</small></article>
      <article class="card h-100"><span class="text-muted small">Sections / onglets</span><strong class="fs-3">{{ stats.sections ?? 0 }}</strong><small class="text-muted">Organisation persistée</small></article>
      <article class="card h-100"><span class="text-muted small">Champs</span><strong class="fs-3">{{ stats.fields ?? 0 }}</strong><small class="text-muted">Identifiants techniques stables</small></article>
      <article class="card h-100"><span class="text-muted small">Groupes de champs réutilisables</span><strong class="fs-3">{{ stats.fieldsets ?? 0 }}</strong><small class="text-muted">Configurations partagées</small></article>
    </section>

    <nav class="editor-tabs blueprint-tabs" role="tablist" aria-label="Navigation des structures de contenu">
      <button id="structures-tab" type="button" :class="['editor-tab', { active: activeTab === 'blueprints' }]" role="tab" aria-controls="structures-panel" :tabindex="activeTab === 'blueprints' ? 0 : -1" :aria-selected="activeTab === 'blueprints'" @click="requestTab('blueprints')" @keydown="handleTabKeydown($event, 'blueprints')">Structures de contenu</button>
      <button id="fieldsets-tab" type="button" :class="['editor-tab', { active: activeTab === 'fieldsets' }]" role="tab" aria-controls="fieldsets-panel" :tabindex="activeTab === 'fieldsets' ? 0 : -1" :aria-selected="activeTab === 'fieldsets'" @click="requestTab('fieldsets')" @keydown="handleTabKeydown($event, 'fieldsets')">Groupes de champs réutilisables</button>
    </nav>

    <section v-if="activeTab === 'blueprints'" class="card mb-3 border-0 shadow-sm">
      <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
          <p class="eyebrow mb-1">Gouvernance</p>
          <h2 class="h5 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
            <span>Audit des structures et du SEO</span>
            <InfoHint text="Score explicable : erreurs bloquantes seulement si la publication serait incohérente." placement="end" />
          </h2>
        </div>
        <span class="badge fs-6" :class="audit.status === 'blocking' ? 'text-bg-danger' : audit.status === 'warnings' ? 'text-bg-warning' : 'text-bg-success'">{{ audit.score ?? 0 }}/100 · {{ audit.status || 'ok' }}</span>
      </div>
      <div v-if="activeAudit" class="mt-3 row g-3">
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><strong>{{ activeAudit.score }}/100</strong><small class="d-block text-muted">{{ activeAudit.blueprint_key }} · {{ activeAudit.status }}</small></div></div>
        <div class="col-md-8"><ul class="mb-0 small text-muted"><li v-for="message in [...(activeAudit.blocking_errors || []), ...(activeAudit.warnings || []), ...(activeAudit.optimizations || [])].slice(0, 5)" :key="message">{{ message }}</li><li v-if="!((activeAudit.blocking_errors || []).length || (activeAudit.warnings || []).length || (activeAudit.optimizations || []).length)">Aucun problème détecté pour cette structure.</li></ul></div>
      </div>
    </section>

    <div v-show="activeTab === 'blueprints'" class="blueprint-mobile-navigation" role="navigation" aria-label="Étapes de l’éditeur"><button type="button" :class="['btn btn-sm', mobilePanel === 'list' ? 'btn-dark' : 'btn-outline-secondary']" @click="mobilePanel = 'list'">Liste</button><button type="button" :class="['btn btn-sm', mobilePanel === 'structure' ? 'btn-dark' : 'btn-outline-secondary']" @click="mobilePanel = 'structure'">Structure</button><button type="button" :class="['btn btn-sm', mobilePanel === 'inspector' ? 'btn-dark' : 'btn-outline-secondary']" @click="mobilePanel = 'inspector'">Inspecteur</button></div>
    <section id="structures-panel" v-show="activeTab === 'blueprints'" role="tabpanel" aria-labelledby="structures-tab" class="blueprint-workbench blueprint-admin-modern">
      <BlueprintNavigationPanel class="blueprint-mobile-panel" :class="{ 'is-mobile-hidden': mobilePanel !== 'list' }"
        v-model:search="searchQuery" v-model:family="familyFilter" v-model:resource-type="typeFilter" v-model:scope="scopeFilter" v-model:state="stateFilter"
        :groups="blueprintGroups" :families="blueprintGroupDefinitions" :resource-types="availableResourceTypes" :active-identity="activeBlueprintIdentity" :can-manage="canManage" :site-name="context.context?.site.name || 'ce site'"
        @create="newBlueprint" @select="requestBlueprint" />

      <main class="blueprint-column blueprint-column--editor blueprint-mobile-panel" :class="{ 'is-mobile-hidden': mobilePanel !== 'structure' }">
        <div class="card mb-3">
          <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
            <div>
              <p class="eyebrow mb-1">Éditeur</p>
              <h2 class="h4 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
                <span>{{ blueprint.label }}</span>
                <span class="badge" :class="activeBlueprintScope === 'global' ? 'text-bg-info' : 'text-bg-primary'">{{ scopeLabel(activeBlueprintScope) }}</span>
                <InfoHint text="Réglages généraux de la structure de contenu." placement="end" />
              </h2>
            </div>
            <div class="text-end">
              <div class="btn-group flex-wrap">
                <button class="btn btn-outline-dark" :disabled="saving || activating || !canManage || !blueprintDirty" @click="saveBlueprint">{{ saving ? 'Enregistrement…' : 'Enregistrer le brouillon' }}</button>
                <button class="btn btn-dark" :disabled="saving || activating || !canManage || blueprintDirty || !hasDraftToActivate" @click="activateBlueprint">{{ activating ? 'Activation…' : 'Activer le brouillon' }}</button>
                <button class="btn btn-outline-danger" :disabled="saving || activating || !canManage || ['page','article','page_archive','article_archive'].includes(blueprint.blueprint_key)" @click="deleteBlueprint">Supprimer</button>
              </div>
              <small class="d-block mt-1" :class="blueprintDirty ? 'text-warning' : 'text-muted'">{{ blueprintDirty ? 'Modifications locales non enregistrées' : 'Aucune modification locale' }}</small>
              <small class="d-block text-muted">Active : {{ blueprintVersionLabel(activeVersion) }} · Brouillon : {{ blueprintVersionLabel(draftVersion) }}</small>
            </div>
          </div>
          <div class="row g-3"><label class="col-md-6 form-label">Nom affiché<input v-model="blueprint.label" class="form-control mt-1" :disabled="!canManage"></label><label class="col-md-6 form-label">Description<textarea v-model="blueprint.description" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label></div>
          <details class="mt-3"><summary class="small fw-semibold">Options avancées</summary><div class="row g-3 mt-1"><label class="col-md-6 form-label">Identifiant technique<input v-model="blueprint.blueprint_key" class="form-control mt-1" :disabled="!canManage || !isNewBlueprint" @blur="blueprint.blueprint_key = normalizeHandle(blueprint.blueprint_key)"><small v-if="!isNewBlueprint" class="text-muted">Identifiant verrouillé après création.</small></label><label class="col-md-6 form-label">Type de ressource<select v-model="blueprint.resource_type" class="form-select mt-1" :disabled="!canManage || !isNewBlueprint"><option value="content_type">Type de contenu</option><option value="block">Bloc éditorial</option><option value="module_resource">Ressource métier</option><option value="headless">Ressource headless</option><option value="taxonomy">Taxonomie</option><option value="media">Média</option><option value="system">Système</option></select></label><div class="col-12"><strong>Portée :</strong> {{ scopeLabel(activeBlueprintScope) }}</div></div></details>
          <details class="mt-3"><summary class="small fw-semibold">Versions et retour arrière</summary><div class="list-group mt-2"><div v-for="version in blueprintVersions" :key="version.id" class="list-group-item d-flex justify-content-between align-items-center gap-2"><span><strong>v{{ version.version }}</strong> · {{ version.status }}<small v-if="version.version_label" class="d-block text-muted">{{ version.version_label }}</small></span><button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage || activating || blueprintDirty || version.is_active" @click="activateBlueprintVersion(version)">{{ version.is_active ? 'Active' : 'Activer cette version' }}</button></div><p v-if="blueprintVersions.length === 0" class="text-muted small mb-0">Aucune version enregistrée.</p></div></details>
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
            <div><h2 class="h5 mb-0">Groupes de champs</h2><small class="text-muted">Configurations réutilisables</small></div>
            <button class="btn btn-dark btn-sm" :disabled="!canManage" @click="newFieldset">Créer</button>
          </div>
          <div class="fieldset-list">
            <div v-for="fs in fieldsets" :key="fs.fieldset_key" class="fieldset-list-item" :class="{ 'is-selected': fs.fieldset_key === activeFieldsetKey }">
              <button class="fieldset-list-item__main" type="button" @click="editFieldset(fs.fieldset_key)">
                <span class="fieldset-list-item__title">
                  <strong>{{ fs.label }}</strong>
                  <span v-if="fs.is_system" class="badge text-bg-secondary">système</span>
                </span>
                <code>{{ fs.fieldset_key }}</code>
                <span class="fieldset-list-item__meta">
                  <span>{{ fs.fields_count }} champ(s)</span>
                  <span>{{ fs.usage_count || 0 }} utilisation(s)</span>
                  <span>{{ purposeLabels[fs.fieldset_purpose] || fs.fieldset_purpose }}</span>
                </span>
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary fieldset-list-item__usage" @click.stop="viewFieldsetUsages(fs.fieldset_key)">Voir les utilisations</button>
            </div>
          </div>
        </div>
      </aside>
      <main class="blueprint-column blueprint-column--section">
        <div class="card" v-if="editingFieldset">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><p class="eyebrow mb-1">Groupe réutilisable</p><h2 class="h4 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">{{ editingFieldset.label }}<span v-if="editingFieldset.is_system" class="badge text-bg-secondary">système protégé</span></h2><p class="text-muted mb-0">Une modification impacte toutes les structures qui utilisent ce groupe de champs.</p></div>
            <div class="text-end"><div class="btn-group"><button class="btn btn-dark" :disabled="saving || !canManage || !fieldsetDirty || activeFieldsetProtected" @click="saveFieldset">{{ saving ? 'Enregistrement…' : 'Modifier le groupe partagé' }}</button><button class="btn btn-outline-secondary" :disabled="saving || !canManage" @click="createFieldsetVariant">Créer une variante</button><button class="btn btn-outline-danger" :disabled="saving || !canManage || activeFieldsetProtected || (editingFieldset.usage_count || 0) > 0" @click="deleteFieldset">Supprimer</button></div><small class="d-block mt-1" :class="fieldsetDirty ? 'text-warning' : 'text-muted'">{{ fieldsetDirty ? 'Modifications non enregistrées' : 'Aucune modification locale' }}</small></div>
          </div>
          <div v-if="activeFieldsetProtected" class="alert alert-secondary">Ce groupe système est protégé par le backend. Pour l’adapter, créez une variante éditoriale.</div>
          <div v-if="(editingFieldset.usage_count || 0) > 0" class="alert alert-warning">
            <div><strong>Ce groupe est partagé par {{ editingFieldset.usage_count }} structure(s).</strong> Toute sauvegarde modifie le groupe commun.</div>
            <ul class="mb-2 mt-2"><li v-for="usage in editingFieldset.used_by || []" :key="`${usage.resource_type}:${usage.blueprint_key}:${usage.scope || usage.site_id}`">{{ fieldsetUsageLabel(usage) }}</li></ul>
            <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-dark" @click="viewFieldsetUsages(editingFieldset.fieldset_key)">Voir les utilisations</button><button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage" @click="createFieldsetVariant">Créer une variante</button></div>
          </div>
          <div class="row g-3 mb-3"><label class="col-md-6 form-label">Nom affiché<input v-model="editingFieldset.label" class="form-control mt-1" :disabled="!canManage || activeFieldsetProtected"></label><label class="col-md-6 form-label">Description<textarea v-model="editingFieldset.description" class="form-control mt-1" rows="2" :disabled="!canManage || activeFieldsetProtected"></textarea></label></div>
          <details class="mb-3"><summary class="small fw-semibold">Options avancées</summary><div class="row g-3 mt-1"><label class="col-md-6 form-label">Identifiant technique<input v-model="editingFieldset.fieldset_key" class="form-control mt-1" :disabled="!canManage || !isNewFieldset || activeFieldsetProtected" @blur="editingFieldset.fieldset_key = normalizeHandle(editingFieldset.fieldset_key)"><small v-if="!isNewFieldset" class="text-muted">Identifiant verrouillé après création.</small></label><label class="col-md-6 form-label">Usage principal<select v-model="editingFieldset.fieldset_purpose" class="form-select mt-1" :disabled="!canManage || activeFieldsetProtected"><option value="content">Contenu</option><option value="seo">SEO</option><option value="page_builder">Construction de page</option><option value="metadata">Métadonnées</option><option value="system">Système</option></select></label></div></details>
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h3 class="h5 mb-0">Champs</h3><select class="form-select w-auto" :disabled="!canManage || activeFieldsetProtected" @change="addFieldsetField(String(($event.target as HTMLSelectElement).value)); ($event.target as HTMLSelectElement).value='' "><option value="">Ajouter un champ…</option><option v-for="type in fieldTypeOptions" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></div>
          <div class="list-group">
            <button v-for="(field, i) in editingFieldset.fields || []" :key="field.field_handle + i" class="list-group-item list-group-item-action" type="button" draggable="true" @click="openFieldsetFieldModal(i)" @dragstart.stop="startDrag({ kind: 'fieldsetField', fieldIndex: i })" @dragover.prevent @drop.stop="dropOnFieldsetField(i)">
              <span class="d-flex align-items-center gap-2"><span class="blueprint-drag-handle" title="Déplacer" aria-hidden="true">⋮</span><span class="flex-grow-1"><strong>{{ field.label }}</strong><small class="d-block text-muted"><code>{{ field.field_handle }}</code> · {{ fieldLabel(field.field_type) }} · {{ field.width }}%</small></span><span v-if="field.is_required" class="badge text-bg-warning">requis</span></span>
            </button>
          </div>
        </div>
        <div v-else class="card empty-state"><strong>Sélectionnez ou créez un groupe de champs réutilisable.</strong></div>
      </main>
    </section>
  </template>

  <div v-if="sectionModal !== null && sectionDraft" class="modal d-block blueprint-modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Modifier la section</h5><button type="button" class="btn-close" aria-label="Annuler" @click="closeSectionModal"></button></div><div class="modal-body"><div class="row g-3"><label class="col-md-6 form-label">Nom affiché<input v-model="sectionDraft.label" class="form-control mt-1" :disabled="!canManage"></label><label class="col-md-6 form-label">Identifiant technique<input v-model="sectionDraft.section_key" class="form-control mt-1" :disabled="!canManage || Boolean(sectionDraft.id)" @blur="sectionDraft.section_key = normalizeHandle(sectionDraft.section_key)"></label><label class="col-md-4 form-label">Disposition<select v-model="sectionDraft.layout" class="form-select mt-1" :disabled="!canManage"><option value="tab">Onglet</option><option value="section">Section</option><option value="sidebar">Panneau latéral</option></select></label><label class="col-12 form-label">Description<textarea v-model="sectionDraft.description" class="form-control mt-1" rows="3" :disabled="!canManage"></textarea></label></div></div><div class="modal-footer"><button class="btn btn-secondary" @click="closeSectionModal">Annuler</button><button class="btn btn-dark" :disabled="!canManage" @click="confirmSectionModal">Appliquer localement</button></div></div></div></div>

  <div v-if="fieldModal && editingField" class="modal d-block blueprint-modal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title">Modifier le champ</h5><small class="text-muted"><code>{{ editingField.field_handle }}</code> · {{ fieldLabel(editingField.field_type) }}</small></div><button type="button" class="btn-close" aria-label="Annuler" @click="cancelFieldModal"></button></div><div class="modal-body"><div class="row g-3"><label class="col-md-4 form-label">Handle<input v-model="editingField.field_handle" class="form-control mt-1" :disabled="editingField.is_system || Boolean(editingField.id) || !canManage" @blur="editingField.field_handle = normalizeHandle(editingField.field_handle)"><small v-if="editingField.id" class="text-muted">Identifiant technique verrouillé.</small></label><label class="col-md-4 form-label">Label<input v-model="editingField.label" class="form-control mt-1" :disabled="!canManage"></label><label class="col-md-4 form-label">Type<select v-model="editingField.field_type" class="form-select mt-1" :disabled="editingField.is_system || !canManage"><option v-for="type in fieldTypeOptions" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></label><label class="col-md-3 form-label">Largeur<select v-model.number="editingField.width" class="form-select mt-1" :disabled="!canManage"><option :value="25">25%</option><option :value="33">33%</option><option :value="50">50%</option><option :value="66">66%</option><option :value="75">75%</option><option :value="100">100%</option></select></label><div class="col-md-9 d-flex flex-wrap align-items-end gap-3"><label class="form-check"><input v-model="editingField.is_required" class="form-check-input" type="checkbox" :disabled="!canManage"><span class="form-check-label">requis</span></label><label class="form-check"><input v-model="editingField.is_localized" class="form-check-input" type="checkbox" :disabled="!canManage"><span class="form-check-label">multilingue</span></label><label class="form-check"><input v-model="editingField.config.disabled" class="form-check-input" type="checkbox" :disabled="editingField.is_system || !canManage"><span class="form-check-label">désactivé</span></label><span v-if="editingField.is_system" class="badge text-bg-secondary">Champ système non supprimable</span><span class="badge" :class="badgeClass(editingField.field_purpose || fieldPurpose(editingField.field_type))">{{ purposeLabels[editingField.field_purpose || fieldPurpose(editingField.field_type)] || editingField.field_purpose }}</span></div><label class="col-12 form-label">Aide<textarea v-model="editingField.help_text" class="form-control mt-1" rows="2" :disabled="!canManage"></textarea></label><label class="col-md-6 form-label">Options JSON<textarea :value="formatJson(editingField.options)" class="form-control font-monospace mt-1" rows="5" :disabled="!canManage" @change="assignJson(editingField, 'options', ($event.target as HTMLTextAreaElement).value)"></textarea></label><label class="col-md-6 form-label">Validation JSON<textarea :value="formatJson(editingField.validation)" class="form-control font-monospace mt-1" rows="5" :disabled="!canManage" @change="assignJson(editingField, 'validation', ($event.target as HTMLTextAreaElement).value)"></textarea></label></div></div><div class="modal-footer justify-content-between"><div><button v-if="fieldModal.mode === 'blueprint' && !fieldModal.isNew" class="btn btn-outline-secondary" :disabled="!canManage" @click="duplicateField(blueprint.sections[fieldModal.sectionIndex ?? 0], editingField)">Dupliquer</button></div><div class="d-flex gap-2"><button v-if="fieldModal.mode === 'blueprint' && !fieldModal.isNew" class="btn btn-outline-danger" :disabled="editingField.is_system || !canManage" @click="removeField(blueprint.sections[fieldModal.sectionIndex ?? 0], fieldModal.fieldIndex)">Retirer</button><button v-if="fieldModal.mode === 'fieldset' && !fieldModal.isNew" class="btn btn-outline-danger" :disabled="!canManage" @click="removeFieldsetField">Retirer</button><button class="btn btn-secondary" @click="cancelFieldModal">Annuler</button><button class="btn btn-dark" :disabled="!canManage" @click="confirmFieldModal">Appliquer localement</button></div></div></div></div></div>
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

  <p v-if="loading" class="muted mt-3">Chargement…</p><ApiFeedback :error="error" :success="success" />
</template>
