<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRouter } from 'vue-router';
import { useAdminContextStore } from '@/stores/adminContext';
import { useContentEntriesStore } from '@/stores/contentEntries';
import { contentTypeKeyCandidates, coreFieldRequired, fieldKey, fieldLabel, fieldRequired, fieldType, isBlocksEnabled, isRoutingEnabled, isSeoEnabled, schemaLabel, schemaTabs, schemaTypeKey, useContentTypesStore } from '@/stores/contentTypes';
import { adminApi } from '@/api/client';
import type { EditorialBlock, EditorSchema, EntryListItem, EntryShow, EntryTaxonomyAssignment, SaveDraftResult, TaxonomyPolicyItem } from '@/api/contracts';
import PageHeader from '@/components/ui/PageHeader.vue';
import CoreContentFields, { type CoreContentModel, type EditorialDateInfo } from '@/components/editor/CoreContentFields.vue';
import SeoFields, { type SeoModel } from '@/components/editor/SeoFields.vue';
import PublicationPanel from '@/components/editor/PublicationPanel.vue';
import BlockEditor from '@/components/editor/BlockEditor.vue';
import TaxonomyTermSelector from '@/components/editor/TaxonomyTermSelector.vue';
import VisualEditorShell from '@/components/editor/VisualEditorShell.vue';
import RevisionHistoryPanel from '@/components/editor/RevisionHistoryPanel.vue';
import EditorialReadinessPanel, { type EditorialIssue } from '@/components/editor/EditorialReadinessPanel.vue';
import SchemaFormRenderer from '@/components/schema/SchemaFormRenderer.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';

const props = defineProps<{ typeKey: string; id?: string }>();
const router = useRouter();
const context = useAdminContextStore();
const contentTypes = useContentTypesStore();
const entries = useContentEntriesStore();
const schema = ref<EditorSchema | null>(null);
const customFields = ref<Record<string, unknown>>({});
const blocks = ref<EditorialBlock[]>([]);
const blockEditorRef = ref<InstanceType<typeof BlockEditor> | null>(null);
const changeNotes = ref('');
const taxonomyTerms = ref<Record<string, number[]>>({});
const lastSavedSnapshot = ref('');
const lastSavedRevisionableSnapshot = ref('');
const autosaveTimer = ref<number | null>(null);
const autosaveInFlight = ref(false);
const autosaveQueuedReason = ref('');
const autosaveReady = ref(false);
const unsavedMessage = 'Des modifications n’ont pas été sauvegardées. Voulez-vous vraiment quitter cette page ?';
const core = reactive<CoreContentModel>({ title: '', slug: '', entry_key: '' });
const slugManuallyEdited = ref(false);
const lastAutoSlug = ref('');
const seo = reactive<SeoModel>({ meta_title: '', meta_description: '', meta_robots: 'index,follow', og_image_media_id: 0, og_image_src: '', twitter_image_media_id: 0, twitter_image_src: '' });
const activeEditorTab = ref<'content' | 'visual' | 'taxonomies' | 'seo' | 'history'>('content');
const visualViewport = ref<'desktop' | 'tablet' | 'mobile'>('desktop');
const returnToVisualAfterStructure = ref(false);
const isNew = computed(() => !props.id);
const actualContentTypeKey = ref('');
const routeContentType = computed(() => normalizeContentTypeKey(props.typeKey));
const editorContentType = computed(() => actualContentTypeKey.value || routeContentType.value);
const isNativeArticleEditor = computed(() => editorContentType.value === 'article');
const isNativePageOrArticleEditor = computed(() => ['page', 'article'].includes(editorContentType.value));
const genericSchemaTabs = computed(() => isNativePageOrArticleEditor.value ? [] : schemaTabs(schema.value));
const hasGenericSchemaForm = computed(() => genericSchemaTabs.value.length > 0);
const editorialDates = computed<EditorialDateInfo[]>(() => buildEditorialDates());
const title = computed(() => isNew.value ? `Créer : ${schemaLabel(schema.value, editorContentType.value)}` : `Modifier : ${core.title || schemaLabel(schema.value, editorContentType.value)}`);
const schemaError = ref('');
const feedbackNonce = ref(0);
const allowInternalRouteReplace = ref(false);
const redirectSuggestions = ref<string[]>([]);
const publicUrl = computed(() => entries.current?.routes?.find((route) => route.is_primary || route.is_canonical)?.full_path || '');
const publicUrlWithBasePath = computed(() => withBasePath(publicUrl.value));
const taxonomyPolicyItems = computed<TaxonomyPolicyItem[]>(() => Array.isArray(schema.value?.taxonomy_policy?.taxonomies) ? schema.value.taxonomy_policy.taxonomies : []);
const hasTaxonomyEditor = computed(() => Boolean(schema.value?.content_type?.capabilities?.taxonomies) && taxonomyPolicyItems.value.length > 0);
const canPublish = computed(() => context.can('content.publish') || Boolean(entries.current?.permissions?.['content.publish']));
const hasSeoEditor = computed(() => isSeoEnabled(schema.value));
const hasBlockEditor = computed(() => isBlocksEnabled(schema.value));
const allowedBlockTypes = computed(() => {
  const ui = (schema.value?.ui_schema || {}) as Record<string, unknown>;
  const policy = (ui.block_policy || ui.blocks || {}) as Record<string, unknown>;
  const allowed = policy.allowed_types;
  return Array.isArray(allowed) ? allowed.map(String) : [];
});
const apiValidationIssues = computed(() => Object.keys(entries.fieldErrors || {}).length > 0);
const editorialIssues = computed<EditorialIssue[]>(() => buildEditorialIssues());
const blockingEditorialIssues = computed(() => editorialIssues.value.filter((issue) => issue.severity === 'error'));
const hasValidationErrors = computed(() => apiValidationIssues.value || blockingEditorialIssues.value.length > 0);
const currentSnapshot = computed(() => editorSnapshot());
const currentRevisionableSnapshot = computed(() => revisionableSnapshot());
const hasUnsavedChanges = computed(() => lastSavedSnapshot.value !== '' && currentSnapshot.value !== lastSavedSnapshot.value);
const hasRevisionableUnsavedChanges = computed(() => lastSavedRevisionableSnapshot.value !== '' && hasRevisionableDiff(lastSavedRevisionableSnapshot.value, currentRevisionableSnapshot.value));
const hasUnpublishedRevision = computed(() => {
  const working = Number(entries.current?.working_revision?.revision_number || entries.current?.working_revision?.id || 0);
  const published = Number(entries.current?.published_revision?.revision_number || entries.current?.published_revision?.id || 0);
  return Boolean(working > 0 && published > 0 && working !== published && !hasRevisionableUnsavedChanges.value);
});
const revisionConflictLocked = computed(() => Boolean(hasRevisionableUnsavedChanges.value && !entries.saving));
const revisionConflictMessage = 'Enregistrez la révision avant d’utiliser le visuel.';
const titleRequiredBlockLocked = computed(() => hasBlockEditor.value && String(core.title || '').trim() === '');
const titleRequiredBlockMessage = 'Saisissez d’abord le titre de la page ou de l’article pour activer les blocs.';
const blockEditorLocked = computed(() => titleRequiredBlockLocked.value);
const blockEditorLockMessage = computed(() => titleRequiredBlockMessage);
const visualEditorLocked = computed(() => revisionConflictLocked.value || titleRequiredBlockLocked.value);
const visualEditorLockMessage = computed(() => titleRequiredBlockLocked.value ? 'Saisissez d’abord le titre de la page ou de l’article avant d’utiliser l’éditeur visuel.' : revisionConflictMessage);

function editorSnapshot(): string {
  return JSON.stringify({
    core: { ...core },
    seo: { ...seo },
    fields: customFields.value,
    blocks: blocks.value,
    taxonomy_terms: taxonomyTerms.value
  });
}
function revisionableSnapshot(): string {
  return JSON.stringify({
    core: { ...core },
    seo: { ...seo },
    fields: customFields.value,
    taxonomy_terms: taxonomyTerms.value,
    block_enabled: blockEnabledSnapshot(blocks.value)
  });
}
function blockEnabledSnapshot(items: EditorialBlock[]): Record<string, boolean> {
  const snapshot: Record<string, boolean> = {};
  for (const block of items) {
    const id = String(block.id || '').trim();
    if (!id) continue;
    snapshot[id] = block.enabled !== false;
  }
  return snapshot;
}
function markClean() {
  lastSavedSnapshot.value = editorSnapshot();
  lastSavedRevisionableSnapshot.value = revisionableSnapshot();
}
function confirmLeave(): boolean {
  blockEditorRef.value?.commitPendingDraft?.();
  if (allowInternalRouteReplace.value) {
    allowInternalRouteReplace.value = false;
    return true;
  }
  if (!hasUnsavedChanges.value || entries.saving || entries.publishing) return true;
  return window.confirm(unsavedMessage);
}
function handleBeforeUnload(event: BeforeUnloadEvent) {
  if (!hasUnsavedChanges.value || entries.saving || entries.publishing) return;
  event.preventDefault();
  event.returnValue = '';
}
function handleBeforeContentLanguageSwitch(event: Event) {
  if (confirmLeave()) return;
  event.preventDefault();
}
function clearAutosaveTimer() {
  if (autosaveTimer.value === null) return;
  window.clearTimeout(autosaveTimer.value);
  autosaveTimer.value = null;
}
function mergeAutosaveReason(reason: string): string {
  const clean = reason.trim();
  if (!clean) return autosaveQueuedReason.value;
  if (!autosaveQueuedReason.value) return clean;
  return autosaveQueuedReason.value.includes(clean) ? autosaveQueuedReason.value : `${autosaveQueuedReason.value} ${clean}`;
}
function mergeReasonText(primary: string, secondary: string): string {
  const first = primary.trim();
  const second = secondary.trim();
  if (!first) return second;
  if (!second || first.includes(second)) return first;
  return `${first} ${second}`;
}
function scheduleAutosave(reason: string, delay = 650) {
  if (!autosaveReady.value) return;
  autosaveQueuedReason.value = mergeAutosaveReason(reason);
  clearAutosaveTimer();
  autosaveTimer.value = window.setTimeout(() => { void flushAutosave(); }, delay);
}
async function flushAutosave() {
  clearAutosaveTimer();
  if (!autosaveReady.value || autosaveInFlight.value || entries.saving || entries.publishing) return;
  if (!hasUnsavedChanges.value && !autosaveQueuedReason.value) return;
  const queuedReason = autosaveQueuedReason.value || 'Sauvegarde automatique.';
  const revisionableReason = hasRevisionableUnsavedChanges.value ? buildRevisionableChangeNotes() : '';
  const reason = revisionableReason ? mergeReasonText(queuedReason, revisionableReason) : queuedReason;
  autosaveQueuedReason.value = '';
  autosaveInFlight.value = true;
  try {
    await save({ notes: reason, silent: true });
  } catch {
    autosaveQueuedReason.value = mergeAutosaveReason(reason);
    setEntryFeedback('error', entries.error || 'Sauvegarde automatique impossible.');
  } finally {
    autosaveInFlight.value = false;
    if (autosaveQueuedReason.value && hasUnsavedChanges.value) {
      clearAutosaveTimer();
      autosaveTimer.value = window.setTimeout(() => { void flushAutosave(); }, 250);
    }
  }
}

function isBlank(value: unknown): boolean {
  return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
}
const blockContentConfigKeys = new Set([
  'layout', 'gap', 'style', 'target', 'heading_level', 'image_loading', 'sandbox', 'allow', 'limit', 'show_pagination',
  'show_more_button', 'source', 'width', 'stack_on_mobile', 'columns_count', 'image_width', 'image_height', 'poster_width',
  'poster_height', 'label', 'caption', 'icon', 'icon_alt', 'link_label'
]);
const blockContentPlaceholderTexts = new Set(['Votre texte enrichi', 'En savoir plus', 'Plan', 'Articles']);
function hasMeaningfulBlockContent(value: unknown, depth = 0, key = ''): boolean {
  if (depth > 8) return false;
  if (value === null || value === undefined) return false;
  if (typeof value === 'string') {
    const text = value.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
    if (text === '' || text === '/' || blockContentPlaceholderTexts.has(text)) return false;
    if (['provider', 'form_key'].includes(key) && ['generic', ''].includes(text)) return false;
    return true;
  }
  if (typeof value === 'number') return Number.isFinite(value) && value > 0;
  if (typeof value === 'boolean') return value === true;
  if (Array.isArray(value)) return value.some((item) => hasMeaningfulBlockContent(item, depth + 1, key));
  if (typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>)
      .filter(([entryKey]) => !blockContentConfigKeys.has(entryKey))
      .some(([entryKey, item]) => hasMeaningfulBlockContent(item, depth + 1, entryKey));
  }
  return false;
}
function emptyBlockIssueLabel(block: EditorialBlock, index: number): string {
  const label = String(block.label || '').trim();
  return label || `bloc ${index + 1} (${block.type})`;
}
function fieldValue(key: string): unknown { return customFields.value[key]; }
function tabIssueCount(tab: EditorialIssue['tab']): number {
  return editorialIssues.value.filter((issue) => issue.tab === tab && issue.severity === 'error').length;
}
function apiErrorCountForTab(tab: EditorialIssue['tab']): number {
  const keys = Object.keys(entries.fieldErrors || {});
  if (tab === 'seo') return keys.filter((key) => key.startsWith('meta_') || key.startsWith('seo.')).length;
  if (tab === 'taxonomies') return keys.filter((key) => key.startsWith('taxonomy')).length;
  if (tab === 'content') return keys.filter((key) => !key.startsWith('meta_') && !key.startsWith('seo.') && !key.startsWith('taxonomy')).length;
  return 0;
}
function tabBadge(tab: EditorialIssue['tab']): number {
  return tabIssueCount(tab) + apiErrorCountForTab(tab);
}
function addIssue(issues: EditorialIssue[], issue: Omit<EditorialIssue, 'key'>) {
  issues.push({ ...issue, key: `${issue.tab}:${issue.field || issue.message}:${issues.length}` });
}
function buildEditorialIssues(): EditorialIssue[] {
  const issues: EditorialIssue[] = [];
  if (coreFieldRequired(schema.value, 'title') && isBlank(core.title)) addIssue(issues, { tab: 'content', field: 'core-title', severity: 'error', message: 'Le titre éditorial est obligatoire.' });
  if (isRoutingEnabled(schema.value) && coreFieldRequired(schema.value, 'slug') && isBlank(core.slug)) addIssue(issues, { tab: 'content', field: 'core-slug', severity: 'error', message: 'Le slug est obligatoire pour produire une URL publique stable.' });
  if (core.entry_key && !/^[a-z0-9][a-z0-9_-]*$/.test(core.entry_key)) addIssue(issues, { tab: 'content', field: 'core-entry-key', severity: 'warning', message: 'La clé d’entrée devrait rester simple : lettres minuscules, chiffres, tirets ou underscores.' });

  for (const tab of genericSchemaTabs.value) {
    for (const field of tab.fields) {
      const key = fieldKey(field);
      if (!key || !fieldRequired(field)) continue;
      if (isBlank(fieldValue(key))) addIssue(issues, { tab: 'content', field: key, severity: 'error', message: `Le champ « ${fieldLabel(field)} » est obligatoire.` });
      if (fieldType(field) === 'media') {
        const value = fieldValue(key) as any;
        const mediaId = typeof value === 'number' ? value : Number(value?.media_id || value?.id || 0);
        if (fieldRequired(field) && mediaId < 1) addIssue(issues, { tab: 'content', field: key, severity: 'error', message: `Le média « ${fieldLabel(field)} » est obligatoire.` });
      }
    }
  }

  for (const taxonomy of taxonomyPolicyItems.value) {
    const selected = taxonomyTerms.value[taxonomy.taxonomy_key] || [];
    if (taxonomy.is_required && selected.length === 0) addIssue(issues, { tab: 'taxonomies', field: `taxonomy_terms.${taxonomy.taxonomy_key}`, severity: 'error', message: `La taxonomie « ${taxonomy.name || taxonomy.taxonomy_key} » est obligatoire.` });
    if (taxonomy.max_terms && selected.length > taxonomy.max_terms) addIssue(issues, { tab: 'taxonomies', field: `taxonomy_terms.${taxonomy.taxonomy_key}`, severity: 'error', message: `La taxonomie « ${taxonomy.name || taxonomy.taxonomy_key} » accepte au maximum ${taxonomy.max_terms} terme(s).` });
  }

  if (hasSeoEditor.value) {
    const metaTitleLength = String(seo.meta_title || '').length;
    const metaDescriptionLength = String(seo.meta_description || '').length;
    if (metaTitleLength === 0) addIssue(issues, { tab: 'seo', field: 'seo-title', severity: 'warning', message: 'Le meta title est vide : le titre éditorial sera probablement utilisé en fallback.' });
    if (metaTitleLength > 70) addIssue(issues, { tab: 'seo', field: 'seo-title', severity: 'error', message: 'Le meta title dépasse 70 caractères.' });
    if (metaDescriptionLength === 0) addIssue(issues, { tab: 'seo', field: 'seo-description', severity: 'warning', message: 'La meta description est vide : le résumé automatique risque d’être moins précis.' });
    if (metaDescriptionLength > 180) addIssue(issues, { tab: 'seo', field: 'seo-description', severity: 'error', message: 'La meta description dépasse 180 caractères.' });
    if (seo.meta_robots !== 'index,follow') addIssue(issues, { tab: 'seo', field: 'seo-robots', severity: 'info', message: `Robots configuré sur « ${seo.meta_robots} » : vérifiez que ce choix est volontaire.` });
  }

  if (hasBlockEditor.value && blocks.value.length === 0) addIssue(issues, { tab: 'content', field: 'blocks', severity: 'warning', message: 'Aucun bloc éditorial n’est encore présent dans la structure.' });
  if (hasBlockEditor.value && blocks.value.length > 0) {
    blocks.value.forEach((block, index) => {
      if (block.enabled === false) return;
      if (!hasMeaningfulBlockContent(block.data ?? {})) {
        addIssue(issues, { tab: 'content', field: block.id || 'blocks', severity: 'error', message: `Le ${emptyBlockIssueLabel(block, index)} est vide : renseignez les informations et le contenu manquant dans la composition par blocs.` });
      }
    });
  }
  return issues;
}
function focusIssue(issue: EditorialIssue) {
  activeEditorTab.value = issue.tab === 'taxonomies' || issue.tab === 'seo' || issue.tab === 'history' || issue.tab === 'visual' ? issue.tab : 'content';
  requestAnimationFrame(() => {
    const target = issue.field ? document.getElementById(issue.field) || document.querySelector(`[name="${CSS.escape(issue.field)}"]`) || document.querySelector(`[data-block-row-id="${CSS.escape(issue.field)}"]`) : null;
    if (target instanceof HTMLElement) {
      target.focus({ preventScroll: false });
      target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  });
}


function defaultAuthorName(): string {
  const contextUser = context.context?.user;
  const runtimeUser = window.__AMCMS_ADMIN__?.user;
  return String(contextUser?.name || runtimeUser?.name || contextUser?.email || runtimeUser?.email || '').trim();
}
function applyNewEntryDefaults(typeKey: string) {
  if (props.id || normalizeContentTypeKey(typeKey) !== 'article') return;
  const author = defaultAuthorName();
  if (author && !String(customFields.value.author_name || '').trim()) {
    customFields.value = { ...customFields.value, author_name: author };
  }
}

function buildEditorialDates(): EditorialDateInfo[] {
  if (!isNativeArticleEditor.value) return [];
  const entry = entries.current?.entry;
  const published = entries.current?.published_revision;
  return [
    { label: 'Création', value: entry?.created_at || null },
    { label: 'Dernière modification', value: entry?.updated_at || null },
    { label: 'Révision publiée', value: published?.published_at || published?.updated_at || null }
  ];
}

function hydrate(entry: EntryShow | null) {
  autosaveReady.value = false;
  core.title = entry?.localization.title ?? '';
  core.slug = entry?.localization.slug ?? '';
  core.entry_key = entry?.entry.entry_key ?? '';
  lastAutoSlug.value = slugifyTitle(core.title);
  slugManuallyEdited.value = Boolean(core.slug && core.slug !== lastAutoSlug.value);
  seo.meta_title = entry?.seo.meta_title ?? '';
  seo.meta_description = entry?.seo.meta_description ?? '';
  seo.meta_robots = entry?.seo.meta_robots ?? 'index,follow';
  seo.og_image_media_id = Number((entry?.seo as any)?.og_image_media_id || 0);
  seo.og_image_src = String((entry?.seo as any)?.og_image_src || '');
  seo.twitter_image_media_id = Number((entry?.seo as any)?.twitter_image_media_id || 0);
  seo.twitter_image_src = String((entry?.seo as any)?.twitter_image_src || '');
  customFields.value = { ...(entry?.fields ?? {}) };
  blocks.value = [...(entry?.localization.blocks ?? [])] as EditorialBlock[];
  taxonomyTerms.value = taxonomyTermsFromEntry(entry?.taxonomies ?? []);
  changeNotes.value = '';
  markClean();
  requestAnimationFrame(() => { autosaveReady.value = true; });
}
async function load() {
  blockEditorRef.value?.commitPendingDraft?.();
  entries.clearFeedback();
  contentTypes.error = '';
  schemaError.value = '';
  actualContentTypeKey.value = '';

  if (props.id) {
    const entry = await entries.load(props.id, context.siteId, context.languageCode);
    const entryTypeKey = normalizeContentTypeKey(entry?.entry?.content_type_key || routeContentType.value);
    actualContentTypeKey.value = entryTypeKey;
    hydrate(entry);
    schema.value = await loadEditorSchemaSafely(entryTypeKey, entry);
    markClean();
    return;
  }

  const requestedType = routeContentType.value;
  actualContentTypeKey.value = requestedType;
  schema.value = await loadEditorSchemaSafely(requestedType, null);
  entries.current = null;
  hydrate(null);
  applyNewEntryDefaults(requestedType);
}

function normalizeContentTypeKey(value: string): string {
  return contentTypeKeyCandidates(value)[0] || 'page';
}
function slugifyTitle(value: string): string {
  const normalized = String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/-{2,}/g, '-');
  return normalized;
}
function applyAutomaticSlug() {
  if (!isRoutingEnabled(schema.value) || slugManuallyEdited.value) return;
  const next = slugifyTitle(core.title);
  lastAutoSlug.value = next;
  core.slug = next;
}
function enableManualSlug() {
  slugManuallyEdited.value = true;
  if (!core.slug) core.slug = slugifyTitle(core.title);
  requestAnimationFrame(() => document.getElementById('core-slug')?.focus());
}
function resetAutomaticSlug() {
  slugManuallyEdited.value = false;
  applyAutomaticSlug();
}
function updateCoreModel(value: CoreContentModel) {
  if (value.slug !== core.slug && value.slug !== lastAutoSlug.value) {
    slugManuallyEdited.value = true;
  }
  Object.assign(core, value);
}
function setCustomField(key: string, value: unknown) {
  customFields.value = { ...customFields.value, [key]: value };
}
function selectEditorTab(tab: 'content' | 'visual' | 'taxonomies' | 'seo' | 'history') {
  if (tab === 'visual' && visualEditorLocked.value) {
    setEntryFeedback('error', visualEditorLockMessage.value);
    return;
  }
  activeEditorTab.value = tab;
}

async function loadEditorSchemaSafely(typeKey: string, entry: EntryShow | null): Promise<EditorSchema> {
  try {
    return await contentTypes.loadSchema(typeKey, context.siteId);
  } catch (error) {
    const canonical = normalizeContentTypeKey(entry?.entry?.content_type_key || typeKey);
    schemaError.value = `Schéma éditeur indisponible pour « ${typeKey} ». Mode de secours activé avec le type « ${canonical} ».`;
    contentTypes.error = '';
    return fallbackEditorSchema(canonical, entry);
  }
}

function fallbackEditorSchema(typeKey: string, entry: EntryShow | null): EditorSchema {
  const normalized = normalizeContentTypeKey(typeKey);
  const isArticle = normalized === 'article';
  return {
    content_type: {
      type_key: normalized,
      name: isArticle ? 'Article' : 'Page',
      singular_label: isArticle ? 'Article' : 'Page',
      plural_label: isArticle ? 'Articles' : 'Pages',
      default_status: 'draft',
      capabilities: { localizations: true, revisions: true, workflow: true, permalink: true, layout: true, seo: true, taxonomies: false }
    },
    fields: [],
    editor_tabs: [],
    seo_policy: { enabled: true },
    taxonomy_policy: { taxonomies: [] },
    workflow_policy: { enabled: true, default_state: entry?.entry?.status || 'draft', states: ['draft', 'review', 'published', 'archived', 'scheduled'], publish_requires_revision_id: true },
    routing_policy: { enabled: true },
    template_binding: { template: isArticle ? 'article.twig' : 'page.twig' }
  };
}

function taxonomyTermsFromEntry(assignments: EntryTaxonomyAssignment[]): Record<string, number[]> {
  const grouped: Record<string, number[]> = {};
  for (const assignment of assignments) {
    const key = String(assignment.taxonomy_key || '');
    const termId = Number(assignment.term_id || 0);
    if (!key || !Number.isInteger(termId) || termId < 1) continue;
    if (!grouped[key]) grouped[key] = [];
    if (!grouped[key].includes(termId)) grouped[key].push(termId);
  }
  return grouped;
}
function draftPayload(notes?: string) {
  blockEditorRef.value?.commitPendingDraft?.();
  const resolvedNotes = typeof notes === 'string' && notes.trim() !== '' ? notes.trim() : changeNotes.value;
  return {
    site_id: context.siteId,
    language_code: context.languageCode,
    content_type_key: schemaTypeKey(schema.value, editorContentType.value),
    entry_key: core.entry_key,
    title: core.title,
    slug: core.slug,
    blocks: blocks.value,
    fields: customFields.value,
    taxonomy_terms: taxonomyTerms.value,
    meta_title: seo.meta_title,
    meta_description: seo.meta_description,
    meta_robots: seo.meta_robots,
    og_image_media_id: seo.og_image_media_id || 0,
    og_image_src: seo.og_image_src || '',
    twitter_image_media_id: seo.twitter_image_media_id || 0,
    twitter_image_src: seo.twitter_image_src || '',
    change_notes: resolvedNotes,
    expected_working_revision_id: entries.current?.working_revision?.id
  };
}

function applyLocalSavedDraftResult(result: SaveDraftResult) {
  const revisionId = Number(result.revision_id || 0);
  if (!entries.current || revisionId < 1) return;
  const now = new Date().toISOString();
  const previousRevision = entries.current.working_revision ?? ({} as NonNullable<EntryShow['working_revision']>);
  entries.current = {
    ...entries.current,
    entry: {
      ...entries.current.entry,
      status: result.status || entries.current.entry.status || 'draft',
      workflow_state: result.status || entries.current.entry.workflow_state || 'draft',
      updated_at: now
    },
    localization: {
      ...entries.current.localization,
      language_code: result.language_code || context.languageCode,
      title: core.title,
      slug: core.slug,
      blocks: [...blocks.value]
    },
    fields: { ...customFields.value },
    seo: {
      ...entries.current.seo,
      meta_title: seo.meta_title,
      meta_description: seo.meta_description,
      meta_robots: seo.meta_robots,
      og_image_media_id: seo.og_image_media_id || 0,
      og_image_src: seo.og_image_src || '',
      twitter_image_media_id: seo.twitter_image_media_id || 0,
      twitter_image_src: seo.twitter_image_src || ''
    },
    taxonomies: entries.current.taxonomies,
    working_revision: {
      ...previousRevision,
      id: revisionId,
      language_code: result.language_code || context.languageCode,
      workflow_status: result.status || previousRevision.workflow_status || 'draft',
      updated_at: now
    }
  };
}
async function refreshEntryInBackground(entryId: string | number) {
  try {
    await entries.load(entryId, context.siteId, context.languageCode, { clearFeedback: false, background: true });
  } catch {
    // A failed background refresh must not hide the editor after a successful local save.
  }
}

function bumpFeedback() {
  feedbackNonce.value += 1;
}
function setEntryFeedback(kind: 'success' | 'error', message: string) {
  bumpFeedback();
  if (kind === 'error') {
    entries.message = '';
    entries.error = message;
    return;
  }
  entries.error = '';
  entries.message = message;
}

function parseSnapshot(value: string): Record<string, any> {
  if (!value) return {};
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}
function stableJson(value: unknown): string {
  if (Array.isArray(value)) return JSON.stringify(value);
  if (!value || typeof value !== 'object') return JSON.stringify(value ?? null);
  const sorted: Record<string, unknown> = {};
  for (const key of Object.keys(value as Record<string, unknown>).sort()) sorted[key] = (value as Record<string, unknown>)[key];
  return JSON.stringify(sorted);
}
function hasRevisionableDiff(beforeValue: string, afterValue: string): boolean {
  const before = parseSnapshot(beforeValue);
  const after = parseSnapshot(afterValue);
  if (stableJson(before.core || {}) !== stableJson(after.core || {})) return true;
  if (stableJson(before.seo || {}) !== stableJson(after.seo || {})) return true;
  if (stableJson(before.fields || {}) !== stableJson(after.fields || {})) return true;
  if (stableJson(before.taxonomy_terms || {}) !== stableJson(after.taxonomy_terms || {})) return true;
  const beforeEnabled = before.block_enabled && typeof before.block_enabled === 'object' ? before.block_enabled as Record<string, unknown> : {};
  const afterEnabled = after.block_enabled && typeof after.block_enabled === 'object' ? after.block_enabled as Record<string, unknown> : {};
  return Object.keys(beforeEnabled).some((id) => Object.prototype.hasOwnProperty.call(afterEnabled, id) && Boolean(beforeEnabled[id]) !== Boolean(afterEnabled[id]));
}
function compactValue(value: unknown): string {
  const text = String(value ?? '').trim();
  if (!text) return 'vide';
  return text.length > 60 ? `${text.slice(0, 57)}…` : text;
}
function taxonomySummary(value: unknown): string {
  if (!value || typeof value !== 'object') return 'aucune taxonomie';
  const entries = Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b));
  if (entries.length === 0) return 'aucune taxonomie';
  return entries.map(([key, termIds]) => {
    const ids = Array.isArray(termIds) ? termIds.map(String).sort((a, b) => Number(a) - Number(b)) : [];
    return `${key}: ${ids.length ? ids.join(', ') : 'aucun terme'}`;
  }).join(' ; ');
}
function blockNoteLabel(block: any, fallbackId: string): string {
  const label = String(block?.label || '').trim();
  if (label) return label;
  const type = String(block?.type || '').trim();
  return type || fallbackId;
}
function blockByIdFromSnapshot(snapshot: Record<string, any>): Record<string, any> {
  const output: Record<string, any> = {};
  const source = Array.isArray(snapshot.blocks) ? snapshot.blocks : [];
  for (const block of source) {
    const id = String(block?.id || '').trim();
    if (id) output[id] = block;
  }
  return output;
}
function buildRevisionableChangeNotes(): string {
  const before = parseSnapshot(lastSavedRevisionableSnapshot.value);
  const after = parseSnapshot(currentRevisionableSnapshot.value);
  const notes: string[] = [];
  const identityLabels: Record<keyof CoreContentModel, string> = { title: 'titre', slug: 'slug', entry_key: 'clé d’entrée' };
  for (const key of Object.keys(identityLabels) as (keyof CoreContentModel)[]) {
    const previous = before.core?.[key] ?? '';
    const next = after.core?.[key] ?? '';
    if (previous !== next) notes.push(`Identité éditoriale : ${identityLabels[key]} « ${compactValue(previous)} » → « ${compactValue(next)} ».`);
  }
  const fieldLabels: Record<string, string> = {
    author_name: 'auteur',
    display_published_at: 'date affichée'
  };
  const beforeFields = before.fields && typeof before.fields === 'object' ? before.fields as Record<string, unknown> : {};
  const afterFields = after.fields && typeof after.fields === 'object' ? after.fields as Record<string, unknown> : {};
  for (const key of Array.from(new Set([...Object.keys(beforeFields), ...Object.keys(afterFields)])).sort()) {
    const previous = beforeFields[key] ?? '';
    const next = afterFields[key] ?? '';
    if (stableJson(previous) !== stableJson(next)) {
      notes.push(`Champ éditorial : ${fieldLabels[key] || key} « ${compactValue(previous)} » → « ${compactValue(next)} ».`);
    }
  }
  const seoLabels: Record<keyof SeoModel, string> = {
    meta_title: 'meta title',
    meta_description: 'meta description',
    meta_robots: 'robots',
    og_image_media_id: 'image Open Graph',
    og_image_src: 'source image Open Graph',
    twitter_image_media_id: 'image Twitter',
    twitter_image_src: 'source image Twitter'
  };
  for (const key of Object.keys(seoLabels) as (keyof SeoModel)[]) {
    const previous = before.seo?.[key] ?? '';
    const next = after.seo?.[key] ?? '';
    if (previous !== next) notes.push(`SEO : ${seoLabels[key]} modifié (« ${compactValue(previous)} » → « ${compactValue(next)} »).`);
  }
  if (JSON.stringify(before.taxonomy_terms || {}) !== JSON.stringify(after.taxonomy_terms || {})) {
    notes.push(`Taxonomies : ${taxonomySummary(before.taxonomy_terms)} → ${taxonomySummary(after.taxonomy_terms)}.`);
  }
  const beforeEditor = parseSnapshot(lastSavedSnapshot.value);
  const afterEditor = parseSnapshot(currentSnapshot.value);
  const beforeBlocks = blockByIdFromSnapshot(beforeEditor);
  const afterBlocks = blockByIdFromSnapshot(afterEditor);
  for (const id of Object.keys(beforeBlocks)) {
    if (!Object.prototype.hasOwnProperty.call(afterBlocks, id)) continue;
    const previous = beforeBlocks[id]?.enabled !== false;
    const next = afterBlocks[id]?.enabled !== false;
    if (previous !== next) {
      const label = blockNoteLabel(afterBlocks[id] || beforeBlocks[id], id);
      notes.push(`Bloc : statut « Actif » de « ${label} » passé de ${previous ? 'actif' : 'inactif'} à ${next ? 'actif' : 'inactif'}.`);
    }
  }
  return notes.length > 0 ? notes.join(' ') : 'Révision enregistrée après modifications éditoriales.';
}

async function save(options: { notes?: string; silent?: boolean } = {}) {
  const wasNewEntry = isNew.value;
  const result = await entries.save(props.id, draftPayload(options.notes), { silent: Boolean(options.silent) });
  if (options.silent) {
    entries.message = '';
    entries.error = '';
  }
  const savedId = result.entry_id || props.id;
  if (wasNewEntry && result.entry_id) {
    markClean();
    allowInternalRouteReplace.value = true;
    try {
      await router.replace(`/contents/${props.typeKey}/${result.entry_id}`);
    } finally {
      allowInternalRouteReplace.value = false;
    }
    await refreshEntryInBackground(result.entry_id);
  } else if (savedId) {
    applyLocalSavedDraftResult(result);
    void refreshEntryInBackground(savedId);
  }
  if (options.silent) {
    entries.message = '';
    entries.error = '';
  } else {
    setEntryFeedback('success', 'Brouillon enregistré.');
  }
  if ((options.notes || '').includes('Bloc')) {
    activeEditorTab.value = 'content';
    requestAnimationFrame(() => document.getElementById('block-structure-title')?.scrollIntoView({ block: 'start', behavior: 'smooth' }));
  }
  markClean();
}
async function saveRevisionFromRevisionableChanges() {
  if (isNew.value) {
    await save({ notes: changeNotes.value || 'Création du brouillon initial.' });
    setEntryFeedback('success', 'Brouillon enregistré.');
    return;
  }
  if (!hasRevisionableUnsavedChanges.value) return;
  await save({ notes: buildRevisionableChangeNotes(), silent: true });
  setEntryFeedback('success', 'Révision enregistrée. Elle reste en attente de publication par un rôle autorisé.');
}
async function preview() {
  if (!props.id) return;
  const revisionId = entries.current?.working_revision?.id;
  const url = await entries.preview(props.id, context.siteId, context.languageCode, revisionId);
  window.open(url, '_blank', 'noopener');
}
async function previewRevision(revisionId: number) {
  if (!props.id || !revisionId) return;
  const url = await entries.preview(props.id, context.siteId, context.languageCode, revisionId);
  window.open(url, '_blank', 'noopener');
}
async function restoreRevision(revisionId: number) {
  if (!props.id || !revisionId) return;
  if (!window.confirm('Restaurer cette ancienne version comme nouveau brouillon de travail ?')) return;
  await entries.restoreRevision(props.id, revisionId, context.siteId, context.languageCode);
  const entry = await entries.load(props.id, context.siteId, context.languageCode, { clearFeedback: false });
  hydrate(entry);
  setEntryFeedback('success', 'Ancienne version restaurée comme nouveau brouillon.');
  activeEditorTab.value = 'content';
  markClean();
}
async function pruneRevisionsBeforePublished() {
  if (!props.id) return;
  const ok = window.confirm('Effacer les anciennes révisions de cette langue qui sont antérieures à la dernière version publiée ? Les versions actives sont conservées. Cette action est définitive.');
  if (!ok) return;
  await entries.pruneRevisionsBeforePublished(props.id, context.siteId, context.languageCode);
  bumpFeedback();
  await entries.load(props.id, context.siteId, context.languageCode, { clearFeedback: false });
  markClean();
}
async function publish() {
  if (hasRevisionableUnsavedChanges.value) {
    return;
  }
  if (hasUnsavedChanges.value || autosaveQueuedReason.value) {
    await save({ notes: autosaveQueuedReason.value || 'Sauvegarde automatique avant publication.', silent: true });
    autosaveQueuedReason.value = '';
  }
  if (blockingEditorialIssues.value.length > 0) {
    const first = blockingEditorialIssues.value[0];
    setEntryFeedback('error', first?.message || 'Des erreurs éditoriales bloquent la publication.');
    if (first) focusIssue(first);
    return;
  }
  if (!props.id || !entries.current?.working_revision?.id) return;
  const revisionId = entries.current.working_revision.id;
  await entries.publish(props.id, { site_id: context.siteId, language_code: context.languageCode, revision_id: revisionId, expected_working_revision_id: revisionId });
  await entries.load(props.id, context.siteId, context.languageCode, { clearFeedback: false });
  setEntryFeedback('success', 'Révision publiée. Le rendu public a été régénéré.');
  markClean();
}
function normalizeLifecycleRedirect(path: string): string {
  let value = String(path || '').replace(/ /g, ' ').trim();
  if (!value) return '';
  try {
    if (/^https?:\/\//i.test(value)) value = new URL(value).pathname;
  } catch {
    return '';
  }
  const siteBasePath = String(context.context?.site?.base_path || window.__AMCMS_ADMIN__?.basePath || '').trim().replace(/^\/+|\/+$/g, '');
  if (siteBasePath) {
    const prefixed = `/${siteBasePath}`;
    if (value === prefixed) value = '/';
    else if (value.startsWith(`${prefixed}/`)) value = value.slice(prefixed.length) || '/';
  }
  if (!value.startsWith('/')) value = `/${value}`;
  value = value.replace(/\\/g, '/').replace(/\/+/g, '/');
  value = encodeURI(value).toLowerCase().replace(/\/+$/g, '') || '/';
  if (!value.startsWith('/') || /\s/.test(value) || value.includes('//')) return '';
  if (!/^\/(?:[a-z0-9._~!$&'()*+,;=:@%-]+\/?)*$/.test(value)) return '';
  return value;
}
function entryHasPublishedPublicFootprint(): boolean {
  if (!entries.current) return false;
  if (Number(entries.current.published_revision?.id || 0) > 0) return true;
  return (entries.current.routes || []).some((route) => String(route.full_path || '').trim() !== '');
}
async function loadRedirectSuggestions() {
  const suggestions = new Set<string>();
  suggestions.add('/');
  const currentPath = String(publicUrl.value || '').trim();
  if (currentPath && currentPath.includes('/')) {
    const parent = currentPath.replace(/\/[^/]*$/, '') || '/';
    suggestions.add(parent);
  }
  try {
    const response = await adminApi.get<EntryListItem[]>('/entries', { site_id: context.siteId, language_code: context.languageCode, status: 'published', limit: 50, sort: 'title' });
    for (const row of response.data || []) {
      const id = Number((row as any).id || 0);
      const path = String(row.full_path || '').trim();
      if (!path || id === Number(props.id || 0)) continue;
      suggestions.add(path.startsWith('/') ? path : `/${path}`);
    }
  } catch {
    // Les suggestions facilitent la saisie, mais ne doivent jamais bloquer l’édition.
  }
  redirectSuggestions.value = Array.from(suggestions).filter(Boolean).slice(0, 12);
}
async function archiveEntry(payload: { redirect_to: string }) {
  if (!props.id || !entries.current) return;
  const redirectTo = normalizeLifecycleRedirect(payload.redirect_to);
  if (!redirectTo) {
    setEntryFeedback('error', 'Indiquez un chemin interne valide avant d’archiver ce contenu.');
    return;
  }
  if (!window.confirm('Archiver ce contenu ? Il sera retiré du front public et ses anciennes URL seront redirigées.')) return;
  await entries.archive(props.id, { site_id: context.siteId, language_code: context.languageCode, redirect_to: redirectTo, http_code: 301 });
  await router.replace(`/contents/${props.typeKey}`);
}
async function deleteEntry(payload: { redirect_to: string }) {
  if (!props.id || !entries.current) return;
  const requiresRedirect = entryHasPublishedPublicFootprint();
  const redirectTo = normalizeLifecycleRedirect(payload.redirect_to);
  if (requiresRedirect && !redirectTo) {
    setEntryFeedback('error', 'Indiquez un chemin interne valide avant de supprimer définitivement un contenu déjà publié.');
    return;
  }
  const ok = window.prompt(requiresRedirect ? 'Suppression définitive avec redirection. Tapez DELETE pour confirmer.' : 'Suppression définitive du brouillon jamais publié. Tapez DELETE pour confirmer.');
  if (ok !== 'DELETE') return;
  await entries.destroy(props.id, {
    site_id: context.siteId,
    language_code: context.languageCode,
    confirm: 'DELETE',
    ...(redirectTo ? { redirect_to: redirectTo, http_code: 301 } : {})
  });
  await router.replace(`/contents/${props.typeKey}`);
}
function withBasePath(path: string): string {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  const base = context.context?.site?.base_path || window.__AMCMS_ADMIN__?.basePath || '';
  const normalizedBase = base === '/' ? '' : `/${String(base).replace(/^\/+|\/+$/g, '')}`;
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  if (normalizedBase && (normalizedPath === normalizedBase || normalizedPath.startsWith(`${normalizedBase}/`))) return normalizedPath;
  return `${normalizedBase}${normalizedPath}` || '/';
}
function openPublic() { if (publicUrlWithBasePath.value) window.open(publicUrlWithBasePath.value, '_blank', 'noopener'); }
async function handleVisualFieldSaved() {
  if (!props.id) return;
  await entries.load(props.id, context.siteId, context.languageCode, { clearFeedback: false, background: true });
  entries.message = '';
  hydrate(entries.current);
}
function handleVisualFeedback(payload: { kind: 'success' | 'error'; message: string }) {
  if (payload.kind === 'error') setEntryFeedback('error', payload.message);
}

async function handleVisualNavigation(payload: { entryId: number; contentTypeKey: string }) {
  if (visualEditorLocked.value) {
    setEntryFeedback('error', visualEditorLockMessage.value);
    return;
  }
  activeEditorTab.value = 'visual';
  await router.replace(`/contents/${payload.contentTypeKey}/${payload.entryId}`);
}
function handleOpenStructure(payload: { blockId?: string | null }) {
  if (visualEditorLocked.value) {
    setEntryFeedback('error', visualEditorLockMessage.value);
    activeEditorTab.value = 'content';
    return;
  }
  returnToVisualAfterStructure.value = activeEditorTab.value === 'visual';
  activeEditorTab.value = 'content';
  if (payload.blockId) {
    requestAnimationFrame(() => blockEditorRef.value?.openByBlockId?.(payload.blockId || ''));
  }
}
function handleStructureClosed() {
  if (!returnToVisualAfterStructure.value) return;
  returnToVisualAfterStructure.value = false;
  if (visualEditorLocked.value) {
    setEntryFeedback('error', visualEditorLockMessage.value);
    return;
  }
  requestAnimationFrame(() => { activeEditorTab.value = 'visual'; });
}
onMounted(() => {
  window.addEventListener('beforeunload', handleBeforeUnload);
  window.addEventListener('amcms:before-content-language-switch', handleBeforeContentLanguageSwitch);
  void load();
});
onBeforeUnmount(() => {
  clearAutosaveTimer();
  window.removeEventListener('beforeunload', handleBeforeUnload);
  window.removeEventListener('amcms:before-content-language-switch', handleBeforeContentLanguageSwitch);
});
onBeforeRouteLeave((_to, _from, next) => {
  if (confirmLeave()) next();
  else next(false);
});
watch(() => [context.siteId, context.languageCode, props.id, props.typeKey], () => { void load(); });
watch(() => core.title, () => { applyAutomaticSlug(); });
watch(() => [props.id, context.siteId, context.languageCode, publicUrl.value], () => { if (props.id) void loadRedirectSuggestions(); }, { immediate: true });
watch(revisionConflictLocked, (locked) => {
  if (locked && activeEditorTab.value === 'visual') {
    activeEditorTab.value = 'content';
    setEntryFeedback('error', revisionConflictMessage);
  }
});
watch(titleRequiredBlockLocked, (locked) => {
  if (locked && activeEditorTab.value === 'visual') {
    activeEditorTab.value = 'content';
    setEntryFeedback('error', visualEditorLockMessage.value);
  }
});
</script>
<template>
  <PageHeader :title="title" intro="Édition schema-driven : identité, blocs natifs, champs personnalisés, SEO, révisions ciblées et publication atomique." />
  <ApiFeedback :error="entries.error || contentTypes.error" :message="entries.message" :nonce="feedbackNonce" />
  <div v-if="schemaError" class="alert alert-warning" role="alert">{{ schemaError }}</div>
  <div v-if="entries.loading || contentTypes.loading" class="card muted">Chargement…</div>
  <div v-else class="editor-layout">
    <main>
      <nav class="editor-tabs editor-tabs--with-tools" aria-label="Sections d’édition">
        <div class="editor-tabs__main">
          <button type="button" :class="['editor-tab', { active: activeEditorTab === 'content' }]" @click="selectEditorTab('content')">Structure <span v-if="tabBadge('content')" class="tab-badge">{{ tabBadge('content') }}</span></button>
          <button v-if="!isNew" type="button" :class="['editor-tab', { active: activeEditorTab === 'visual', disabled: visualEditorLocked }]" :disabled="visualEditorLocked" :title="visualEditorLocked ? visualEditorLockMessage : 'Ouvrir l’éditeur visuel'" :aria-disabled="visualEditorLocked" @click="selectEditorTab('visual')">Visuel</button>
          <button v-if="hasTaxonomyEditor" type="button" :class="['editor-tab', { active: activeEditorTab === 'taxonomies' }]" @click="selectEditorTab('taxonomies')">Taxonomie <span v-if="tabBadge('taxonomies')" class="tab-badge">{{ tabBadge('taxonomies') }}</span></button>
          <button v-if="hasSeoEditor" type="button" :class="['editor-tab', { active: activeEditorTab === 'seo' }]" @click="selectEditorTab('seo')">SEO <span v-if="tabBadge('seo')" class="tab-badge">{{ tabBadge('seo') }}</span></button>
          <button v-if="!isNew" type="button" :class="['editor-tab', { active: activeEditorTab === 'history' }]" @click="selectEditorTab('history')">Historique</button>
        </div>
        <div v-if="activeEditorTab === 'visual' && !isNew" class="editor-tabs__visual-tools" aria-label="Taille de preview visuelle">
          <button type="button" :class="['chip', { active: visualViewport === 'desktop' }]" @click="visualViewport = 'desktop'">Desktop</button>
          <button type="button" :class="['chip', { active: visualViewport === 'tablet' }]" @click="visualViewport = 'tablet'">Tablette</button>
          <button type="button" :class="['chip', { active: visualViewport === 'mobile' }]" @click="visualViewport = 'mobile'">Mobile</button>
        </div>
      </nav>

      <EditorialReadinessPanel
        :issues="editorialIssues"
        :has-unsaved-changes="hasUnsavedChanges"
        :has-revisionable-unsaved-changes="hasRevisionableUnsavedChanges"
        :is-published="Boolean(entries.current?.published_revision?.id) && !hasRevisionableUnsavedChanges && !hasUnpublishedRevision"
        :is-new="isNew"
        :has-unpublished-revision="hasUnpublishedRevision"
        :language-code="context.languageCode"
        :site-name="String(context.context?.site?.name || '')"
        @focus-issue="focusIssue"
      />

      <div v-show="activeEditorTab === 'content'" class="editor-tab-panel">
        <CoreContentFields :model-value="core" :schema="schema" :errors="entries.fieldErrors" :public-url="publicUrlWithBasePath" :slug-manual-mode="slugManuallyEdited" :content-type-key="editorContentType" :custom-fields="customFields" :editorial-dates="editorialDates" @update:model-value="updateCoreModel" @update:custom-fields="customFields = $event" @enable-manual-slug="enableManualSlug" @reset-auto-slug="resetAutomaticSlug" />

        <SchemaFormRenderer v-if="hasGenericSchemaForm" :schema="schema" :model-value="customFields" :errors="entries.fieldErrors" @update:model-value="customFields = $event" />

        <section v-if="hasBlockEditor" :class="['revision-locked-zone', { 'is-locked': blockEditorLocked }]" :aria-disabled="blockEditorLocked">
          <BlockEditor ref="blockEditorRef" v-model="blocks" :entry-id="props.id || null" :site-id="context.siteId" :language-code="context.languageCode" :allowed-block-types="allowedBlockTypes" :disabled="blockEditorLocked" :disabled-message="blockEditorLockMessage" @modal-closed="handleStructureClosed" @lock-feedback="handleVisualFeedback" @autosave="scheduleAutosave" />
        </section>
        <section class="schema-section">
          <h2>Notes de changement</h2>
          <textarea rows="3" v-model="changeNotes" placeholder="Optionnel : utile pour l’historique des révisions" />
        </section>
      </div>

      <div v-if="!isNew" v-show="activeEditorTab === 'visual'" class="editor-tab-panel editor-tab-panel--visual">
        <div v-if="visualEditorLocked" class="alert alert-warning" role="alert">{{ visualEditorLockMessage }}</div>
        <VisualEditorShell v-else
          :entry-id="props.id || ''"
          :site-id="context.siteId"
          :language-code="context.languageCode"
          :working-revision-id="entries.current?.working_revision?.id || 0"
          :entry="entries.current"
          :blocks="blocks"
          v-model:viewport="visualViewport"
          @field-saved="handleVisualFieldSaved"
          @open-structure="handleOpenStructure"
          @navigate-entry="handleVisualNavigation"
          @feedback="handleVisualFeedback"
        />
      </div>

      <div v-show="activeEditorTab === 'taxonomies'" class="editor-tab-panel">
        <TaxonomyTermSelector v-model="taxonomyTerms" :taxonomies="taxonomyPolicyItems" :language-code="context.languageCode" />
      </div>

      <div v-show="activeEditorTab === 'seo'" class="editor-tab-panel">
        <SeoFields :model-value="seo" :schema="schema" :errors="entries.fieldErrors" @update:model-value="Object.assign(seo, $event)" />
      </div>

      <div v-if="!isNew" v-show="activeEditorTab === 'history'" class="editor-tab-panel">
        <RevisionHistoryPanel
          :revisions="entries.current?.revisions || []"
          :working-revision-id="entries.current?.working_revision?.id || 0"
          :published-revision-id="entries.current?.published_revision?.id || 0"
          :loading="entries.loading"
          :pruning="entries.pruningRevisions"
          @preview="previewRevision"
          @restore="restoreRevision"
          @prune="pruneRevisionsBeforePublished"
        />
      </div>
    </main>
    <PublicationPanel :entry="entries.current" :public-url="publicUrl" :can-publish="canPublish" :can-preview="!isNew" :saving="entries.saving" :publishing="entries.publishing" :previewing="entries.previewing" :archiving="entries.archiving" :deleting="entries.deleting" :has-unsaved-changes="hasUnsavedChanges" :has-revisionable-unsaved-changes="hasRevisionableUnsavedChanges" :schema="schema" :has-validation-errors="hasValidationErrors" :redirect-suggestions="redirectSuggestions" @save-revision="saveRevisionFromRevisionableChanges" @publish="publish" @archive="archiveEntry" @destroy="deleteEntry" />
  </div>
</template>
