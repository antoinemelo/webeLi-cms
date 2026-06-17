<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import type { AdminLanguage, TaxonomySummary } from '@/api/contracts';
import PageHeader from '@/components/ui/PageHeader.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';

type TermLocalization = {
  name: string;
  slug: string;
  full_path: string;
  description: string;
  meta_title: string;
  meta_description: string;
};

type TermRecord = {
  id?: number;
  taxonomy_id?: number;
  taxonomy_key?: string;
  parent_id?: number | null;
  term_key?: string;
  name?: string;
  slug?: string;
  full_path?: string;
  description?: string;
  meta_title?: string;
  meta_description?: string;
  is_active?: boolean | number | string;
  sort_order?: number;
  has_current_localization?: boolean | number | string;
  localizations?: Record<string, Partial<TermLocalization>>;
};

type TermNode = TermRecord & { children: TermNode[]; depth: number };
type PanelMode = 'taxonomy' | 'term';

const context = useAdminContextStore();
const rows = ref<TaxonomySummary[]>([]);
const allTerms = ref<TermRecord[]>([]);
const terms = ref<TermRecord[]>([]);
const loading = ref(false);
const saving = ref(false);
const termsLoading = ref(false);
const error = ref('');
const success = ref('');
const selectedKey = ref('');
const selectedTermId = ref<number | null>(null);
const panelMode = ref<PanelMode>('taxonomy');
const taxonomyFilter = ref('');
const termSearch = ref('');
const editLanguage = ref('');
const showTaxonomyAdvanced = ref(false);
const showSeoFields = ref(false);
const isCreatingTaxonomy = ref(false);
const dirty = ref(false);
const draggingTaxonomyKey = ref('');
const draggingTermId = ref<number | null>(null);
const dragOverTaxonomyKey = ref('');
const dragOverTermId = ref<number | null>(null);

const taxonomyForm = ref({
  taxonomy_key: '',
  name: '',
  description: '',
  is_hierarchical: true,
  is_localized: true,
  seo_enabled: true,
  archive_enabled: true,
  sort_order: 0,
});

const termForm = ref({
  term_key: '',
  parent_id: '',
  is_active: true,
  sort_order: 0,
  localizations: {} as Record<string, TermLocalization>,
});

const fallbackLanguage: AdminLanguage = { code: 'fr', name: 'Français', is_default: true, is_enabled: true, is_current: true, url_prefix: '' };
const languages = computed(() => context.contentLanguages.length ? context.contentLanguages : [fallbackLanguage]);
const activeLanguage = computed(() => context.languageCode || languages.value[0]?.code || 'fr');
const visibleLanguage = computed(() => editLanguage.value || activeLanguage.value);
const activeLocalization = computed(() => ensureTermLocalization(visibleLanguage.value));

const currentTaxonomy = computed(() => rows.value.find((row) => taxonomyKey(row) === selectedKey.value));
const selectedTerm = computed(() => terms.value.find((term) => Number(term.id) === selectedTermId.value));
const selectedTaxonomyIsHierarchical = computed(() => toBool(currentTaxonomy.value?.is_hierarchical ?? taxonomyForm.value.is_hierarchical));

const filteredTaxonomies = computed(() => {
  const q = taxonomyFilter.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter((row) => `${row.name ?? ''} ${taxonomyKey(row)} ${row.description ?? ''}`.toLowerCase().includes(q));
});

const filteredTerms = computed(() => {
  const q = termSearch.value.trim().toLowerCase();
  if (!q) return terms.value;
  return terms.value.filter((term) => `${term.name ?? ''} ${term.term_key ?? ''} ${term.slug ?? ''} ${term.full_path ?? ''}`.toLowerCase().includes(q));
});

const canDragTaxonomies = computed(() => !taxonomyFilter.value.trim() && !loading.value && rows.value.length > 1);
const canDragTerms = computed(() => !!selectedKey.value && !termSearch.value.trim() && !termsLoading.value && terms.value.length > 1);
const termTree = computed<TermNode[]>(() => buildTree(filteredTerms.value, selectedTaxonomyIsHierarchical.value));
const flattenedTerms = computed<TermNode[]>(() => flattenTree(termTree.value));
const parentOptions = computed(() => terms.value.filter((term) => Number(term.id) !== selectedTermId.value));
const termCountByTaxonomy = computed(() => {
  const counts = new Map<string, number>();
  for (const term of allTerms.value) {
    const key = String(term.taxonomy_key ?? '');
    if (!key) continue;
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  return counts;
});

function taxonomyKey(row?: Partial<TaxonomySummary> | null): string {
  return String(row?.taxonomy_key ?? row?.key ?? '');
}
function toBool(value: unknown, fallback = false): boolean {
  if (value === undefined || value === null || value === '') return fallback;
  return value === true || value === 1 || value === '1' || value === 'true';
}
function inputValue(event: Event): string { return (event.target as HTMLInputElement).value; }
function markDirty() { dirty.value = true; }
function emptyLocalization(): TermLocalization { return { name: '', slug: '', full_path: '', description: '', meta_title: '', meta_description: '' }; }
function ensureTermLocalization(code: string): TermLocalization {
  if (!termForm.value.localizations[code]) termForm.value.localizations[code] = emptyLocalization();
  return termForm.value.localizations[code];
}
function normalizeSlug(value: string): string {
  return value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 90);
}
function localizationState(code: string): 'complete' | 'partial' | 'missing' {
  const loc = termForm.value.localizations[code];
  if (!loc || (!loc.name && !loc.slug && !loc.description && !loc.meta_title && !loc.meta_description)) return 'missing';
  return loc.name && loc.slug ? 'complete' : 'partial';
}
function localizationBadgeClass(code: string): string {
  const state = localizationState(code);
  if (state === 'complete') return 'text-bg-success';
  if (state === 'partial') return 'text-bg-warning';
  return 'text-bg-light';
}
function localizationLabel(code: string): string {
  const state = localizationState(code);
  return state === 'complete' ? 'complète' : state === 'partial' ? 'partielle' : 'manquante';
}
function onTaxonomyAdvancedToggle(event: Event) { showTaxonomyAdvanced.value = Boolean((event.target as HTMLDetailsElement).open); }
function termCount(key: string): number { return termCountByTaxonomy.value.get(key) ?? (key === selectedKey.value ? terms.value.length : 0); }

function buildTree(source: TermRecord[], hierarchical: boolean): TermNode[] {
  const sorted = [...source].sort((a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0) || String(a.name ?? a.term_key ?? '').localeCompare(String(b.name ?? b.term_key ?? '')));
  if (!hierarchical) return sorted.map((term) => ({ ...term, children: [], depth: 0 }));
  const byId = new Map<number, TermNode>();
  const roots: TermNode[] = [];
  for (const term of sorted) {
    const id = Number(term.id ?? 0);
    byId.set(id, { ...term, children: [], depth: 0 });
  }
  for (const node of byId.values()) {
    const parentId = Number(node.parent_id ?? 0);
    const parent = parentId ? byId.get(parentId) : null;
    if (parent && parent.id !== node.id) {
      node.depth = parent.depth + 1;
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }
  return roots;
}
function flattenTree(nodes: TermNode[]): TermNode[] {
  const result: TermNode[] = [];
  const walk = (items: TermNode[], depth = 0) => {
    for (const item of items) {
      item.depth = depth;
      result.push(item);
      walk(item.children, depth + 1);
    }
  };
  walk(nodes);
  return result;
}

async function load() {
  loading.value = true; error.value = ''; success.value = '';
  try {
    const r = await adminApi.get<TaxonomySummary[]>('/taxonomies');
    rows.value = Array.isArray(r.data) ? r.data : [];
    await loadAllTerms();
    if (selectedKey.value && !rows.value.some((row) => taxonomyKey(row) === selectedKey.value)) selectedKey.value = '';
    if (!selectedKey.value && rows.value.length) selectTaxonomy(rows.value[0]);
  } catch (e) {
    error.value = apiErrorMessage(e, 'Taxonomies indisponibles.');
  } finally {
    loading.value = false;
  }
}

async function loadAllTerms() {
  try {
    const r = await adminApi.get<TermRecord[]>('/taxonomy-terms', { lang: activeLanguage.value });
    allTerms.value = Array.isArray(r.data) ? r.data : [];
  } catch {
    allTerms.value = [];
  }
}

function hydrateTaxonomyForm(row: TaxonomySummary) {
  taxonomyForm.value = {
    taxonomy_key: taxonomyKey(row),
    name: String(row.name ?? row.label ?? ''),
    description: String(row.description ?? ''),
    is_hierarchical: toBool(row.is_hierarchical, true),
    is_localized: toBool(row.is_localized, true),
    seo_enabled: toBool(row.seo_enabled, true),
    archive_enabled: toBool(row.archive_enabled, true),
    sort_order: Number(row.sort_order ?? 0),
  };
}
function selectTaxonomy(row: TaxonomySummary) {
  isCreatingTaxonomy.value = false;
  selectedKey.value = taxonomyKey(row);
  hydrateTaxonomyForm(row);
  resetTerm(false);
  panelMode.value = 'taxonomy';
  dirty.value = false;
  loadTerms();
}
function newTaxonomy() {
  isCreatingTaxonomy.value = true;
  selectedKey.value = '';
  taxonomyForm.value = { taxonomy_key: '', name: '', description: '', is_hierarchical: true, is_localized: true, seo_enabled: true, archive_enabled: true, sort_order: 0 };
  terms.value = [];
  resetTerm(false);
  panelMode.value = 'taxonomy';
  showTaxonomyAdvanced.value = false;
  dirty.value = false;
}
function inferTaxonomyKey() {
  if (!selectedKey.value && taxonomyForm.value.name && !taxonomyForm.value.taxonomy_key) taxonomyForm.value.taxonomy_key = normalizeSlug(taxonomyForm.value.name);
}

async function saveTaxonomy() {
  inferTaxonomyKey();
  if (!taxonomyForm.value.taxonomy_key.trim() || !taxonomyForm.value.name.trim()) {
    error.value = 'La clé technique et le nom de la taxonomie sont obligatoires.';
    return;
  }
  saving.value = true; error.value = ''; success.value = '';
  try {
    const payload = { ...taxonomyForm.value, taxonomy_key: normalizeSlug(taxonomyForm.value.taxonomy_key) };
    const r = selectedKey.value ? await adminApi.patch<TaxonomySummary>(`/taxonomies/${selectedKey.value}`, payload) : await adminApi.post<TaxonomySummary>('/taxonomies', payload);
    selectedKey.value = taxonomyKey(r.data) || payload.taxonomy_key;
    isCreatingTaxonomy.value = false;
    success.value = 'Taxonomie enregistrée.';
    dirty.value = false;
    await load();
    await loadTerms();
  } catch (e) {
    error.value = apiErrorMessage(e, 'Enregistrement impossible.');
  } finally {
    saving.value = false;
  }
}

async function deleteTaxonomy() {
  if (!selectedKey.value || !confirm('Supprimer cette taxonomie et tous ses termes ?')) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    await adminApi.delete(`/taxonomies/${selectedKey.value}`);
    success.value = 'Taxonomie supprimée.';
    newTaxonomy();
    await load();
  } catch (e) {
    error.value = apiErrorMessage(e, 'Suppression impossible.');
  } finally {
    saving.value = false;
  }
}

async function loadTerms() {
  if (!selectedKey.value) { terms.value = []; return; }
  termsLoading.value = true; error.value = '';
  try {
    const r = await adminApi.get<TermRecord[]>('/taxonomy-terms', { taxonomy: selectedKey.value, lang: activeLanguage.value });
    terms.value = Array.isArray(r.data) ? r.data : [];
    allTerms.value = [...allTerms.value.filter((term) => String(term.taxonomy_key ?? '') !== selectedKey.value), ...terms.value];
    if (selectedTermId.value) {
      const current = terms.value.find((term) => Number(term.id) === selectedTermId.value);
      if (current) selectTerm(current);
      else resetTerm(false);
    }
  } catch (e) {
    error.value = apiErrorMessage(e, 'Termes indisponibles.');
  } finally {
    termsLoading.value = false;
  }
}

function resetTerm(openPanel = true, parentId = '') {
  selectedTermId.value = null;
  const localizations: Record<string, TermLocalization> = {};
  for (const lang of languages.value) localizations[lang.code] = emptyLocalization();
  termForm.value = { term_key: '', parent_id: parentId, is_active: true, sort_order: 0, localizations };
  showSeoFields.value = false;
  if (openPanel) panelMode.value = 'term';
}
function newRootTerm() { resetTerm(true); }
function newChildTerm(parent: TermRecord) { resetTerm(true, String(parent.id ?? '')); }
function selectTerm(term: TermRecord) {
  selectedTermId.value = Number(term.id);
  const localizations: Record<string, TermLocalization> = {};
  const source = term.localizations && typeof term.localizations === 'object' ? term.localizations : {};
  for (const lang of languages.value) {
    const loc = source[lang.code] || (lang.code === activeLanguage.value ? term : {});
    localizations[lang.code] = {
      name: String(loc.name ?? ''),
      slug: String(loc.slug ?? ''),
      full_path: String(loc.full_path ?? ''),
      description: String(loc.description ?? ''),
      meta_title: String(loc.meta_title ?? ''),
      meta_description: String(loc.meta_description ?? ''),
    };
  }
  termForm.value = {
    term_key: String(term.term_key ?? ''),
    parent_id: String(term.parent_id ?? ''),
    is_active: toBool(term.is_active, true),
    sort_order: Number(term.sort_order ?? 0),
    localizations,
  };
  panelMode.value = 'term';
  dirty.value = false;
}
function inferTermKeyAndSlug(code = visibleLanguage.value) {
  const loc = ensureTermLocalization(code);
  if (!loc.name.trim()) return;
  const slug = normalizeSlug(loc.name);
  if (!termForm.value.term_key) termForm.value.term_key = slug;
  if (!loc.slug) loc.slug = slug;
  if (!loc.full_path) loc.full_path = `/${taxonomyForm.value.taxonomy_key || selectedKey.value}/${slug}`.replace(/\/+/g, '/');
}

async function saveTerm() {
  if (!selectedKey.value) return;
  inferTermKeyAndSlug(visibleLanguage.value);
  const current = ensureTermLocalization(visibleLanguage.value);
  if (!termForm.value.term_key.trim() || !current.name.trim() || !current.slug.trim()) {
    error.value = 'La clé du terme, le nom et le slug de la langue active sont obligatoires.';
    return;
  }
  saving.value = true; error.value = ''; success.value = '';
  const payload = {
    ...termForm.value,
    term_key: normalizeSlug(termForm.value.term_key),
    ...current,
    parent_id: termForm.value.parent_id ? Number(termForm.value.parent_id) : null,
  };
  try {
    const r = selectedTermId.value
      ? await adminApi.patch<TermRecord>(`/taxonomies/${selectedKey.value}/terms/${selectedTermId.value}?lang=${encodeURIComponent(visibleLanguage.value)}`, payload)
      : await adminApi.post<TermRecord>(`/taxonomies/${selectedKey.value}/terms?lang=${encodeURIComponent(visibleLanguage.value)}`, payload);
    const savedId = Number(r.data?.id ?? selectedTermId.value ?? 0);
    success.value = 'Terme enregistré.';
    dirty.value = false;
    await loadTerms();
    await loadAllTerms();
    const saved = terms.value.find((term) => Number(term.id) === savedId);
    if (saved) selectTerm(saved);
    else resetTerm(false);
  } catch (e) {
    error.value = apiErrorMessage(e, 'Enregistrement impossible.');
  } finally {
    saving.value = false;
  }
}

async function deleteTerm() {
  if (!selectedKey.value || !selectedTermId.value || !confirm('Supprimer ce terme ?')) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    await adminApi.delete(`/taxonomies/${selectedKey.value}/terms/${selectedTermId.value}`);
    success.value = 'Terme supprimé.';
    resetTerm(false);
    panelMode.value = 'taxonomy';
    await loadTerms();
    await loadAllTerms();
  } catch (e) {
    error.value = apiErrorMessage(e, 'Suppression impossible.');
  } finally {
    saving.value = false;
  }
}

function reorderItems<T>(items: T[], fromIndex: number, toIndex: number): T[] {
  if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return items;
  const next = [...items];
  const [moved] = next.splice(fromIndex, 1);
  next.splice(toIndex, 0, moved);
  return next;
}
function normalizedSortOrder(index: number): number { return (index + 1) * 10; }
function termParentKey(term: TermRecord): string { return String(term.parent_id ?? ''); }

function onTaxonomyDragStart(row: TaxonomySummary, event: DragEvent) {
  if (!canDragTaxonomies.value) return;
  const key = taxonomyKey(row);
  draggingTaxonomyKey.value = key;
  event.dataTransfer?.setData('text/plain', key);
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}
function onTaxonomyDragEnter(row: TaxonomySummary) {
  if (!canDragTaxonomies.value) return;
  dragOverTaxonomyKey.value = taxonomyKey(row);
}
function onTaxonomyDragEnd() {
  draggingTaxonomyKey.value = '';
  dragOverTaxonomyKey.value = '';
}
async function onTaxonomyDrop(target: TaxonomySummary) {
  if (!canDragTaxonomies.value || !draggingTaxonomyKey.value) return onTaxonomyDragEnd();
  const sourceKey = draggingTaxonomyKey.value;
  const targetKey = taxonomyKey(target);
  onTaxonomyDragEnd();
  if (!sourceKey || sourceKey === targetKey) return;
  const fromIndex = rows.value.findIndex((row) => taxonomyKey(row) === sourceKey);
  const toIndex = rows.value.findIndex((row) => taxonomyKey(row) === targetKey);
  const ordered = reorderItems(rows.value, fromIndex, toIndex).map((row, index) => ({ ...row, sort_order: normalizedSortOrder(index) }));
  rows.value = ordered;
  if (selectedKey.value) {
    const selected = ordered.find((row) => taxonomyKey(row) === selectedKey.value);
    if (selected) taxonomyForm.value.sort_order = Number(selected.sort_order ?? 0);
  }
  saving.value = true; error.value = ''; success.value = '';
  try {
    await Promise.all(ordered.map((row) => adminApi.patch(`/taxonomies/${taxonomyKey(row)}`, { sort_order: Number(row.sort_order ?? 0) })));
    success.value = 'Ordre des taxonomies enregistré.';
    await load();
  } catch (e) {
    error.value = apiErrorMessage(e, 'Réorganisation impossible.');
    await load();
  } finally {
    saving.value = false;
  }
}

function onTermDragStart(term: TermRecord, event: DragEvent) {
  if (!canDragTerms.value) return;
  const id = Number(term.id ?? 0);
  if (!id) return;
  draggingTermId.value = id;
  event.dataTransfer?.setData('text/plain', String(id));
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}
function onTermDragEnter(term: TermRecord) {
  if (!canDragTerms.value) return;
  dragOverTermId.value = Number(term.id ?? 0) || null;
}
function onTermDragEnd() {
  draggingTermId.value = null;
  dragOverTermId.value = null;
}
async function onTermDrop(target: TermRecord) {
  if (!canDragTerms.value || !selectedKey.value || !draggingTermId.value) return onTermDragEnd();
  const sourceId = draggingTermId.value;
  const targetId = Number(target.id ?? 0);
  onTermDragEnd();
  if (!sourceId || !targetId || sourceId === targetId) return;
  const source = terms.value.find((term) => Number(term.id) === sourceId);
  const destination = terms.value.find((term) => Number(term.id) === targetId);
  if (!source || !destination) return;
  if (selectedTaxonomyIsHierarchical.value && termParentKey(source) !== termParentKey(destination)) {
    error.value = 'Le glisser-déposer réordonne les termes d’un même niveau. Pour changer de parent, utilisez le champ Parent.';
    return;
  }
  const parentKey = termParentKey(destination);
  const siblings = terms.value
    .filter((term) => termParentKey(term) === parentKey)
    .sort((a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0) || String(a.name ?? a.term_key ?? '').localeCompare(String(b.name ?? b.term_key ?? '')));
  const fromIndex = siblings.findIndex((term) => Number(term.id) === sourceId);
  const toIndex = siblings.findIndex((term) => Number(term.id) === targetId);
  const orderedSiblings = reorderItems(siblings, fromIndex, toIndex).map((term, index) => ({ ...term, sort_order: normalizedSortOrder(index) }));
  const orderById = new Map(orderedSiblings.map((term) => [Number(term.id), Number(term.sort_order ?? 0)]));
  terms.value = terms.value.map((term) => orderById.has(Number(term.id)) ? { ...term, sort_order: orderById.get(Number(term.id)) } : term);
  allTerms.value = allTerms.value.map((term) => String(term.taxonomy_key ?? '') === selectedKey.value && orderById.has(Number(term.id)) ? { ...term, sort_order: orderById.get(Number(term.id)) } : term);
  if (selectedTermId.value && orderById.has(selectedTermId.value)) termForm.value.sort_order = Number(orderById.get(selectedTermId.value));
  saving.value = true; error.value = ''; success.value = '';
  try {
    await Promise.all(orderedSiblings.map((term) => adminApi.patch(`/taxonomies/${selectedKey.value}/terms/${Number(term.id)}?lang=${encodeURIComponent(visibleLanguage.value)}`, { sort_order: Number(term.sort_order ?? 0) })));
    success.value = 'Ordre des termes enregistré.';
    await loadTerms();
    await loadAllTerms();
  } catch (e) {
    error.value = apiErrorMessage(e, 'Réorganisation impossible.');
    await loadTerms();
    await loadAllTerms();
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  editLanguage.value = activeLanguage.value;
  await load();
});
watch(() => context.languageCode, async () => {
  editLanguage.value = activeLanguage.value;
  await loadAllTerms();
  await loadTerms();
});
watch(languages, () => {
  for (const lang of languages.value) ensureTermLocalization(lang.code);
});
</script>

<template>
  <PageHeader title="Taxonomies" intro="Gérez les vocabulaires éditoriaux, leurs termes, leurs traductions et leurs réglages SEO.">
    <template #actions>
      <span v-if="dirty" class="badge text-bg-warning align-self-center">modifications non enregistrées</span>
      <button class="btn btn-outline-secondary" :disabled="loading" @click="load">Rafraîchir</button>
      <button class="btn btn-primary" @click="newTaxonomy">Nouvelle taxonomie</button>
    </template>
  </PageHeader>
  <ApiFeedback :error="error" :message="success" />

  <div class="taxonomy-builder-layout" data-taxonomy-layout="three-columns">
    <section class="taxonomy-builder-layout__left" aria-label="Liste des taxonomies">
      <div class="card h-100 p-0 overflow-hidden">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div><p class="eyebrow mb-1">Vocabulaires</p><h2 class="h5 mb-0">{{ rows.length }} taxonomie(s)</h2></div>
            <span class="badge text-bg-light">{{ activeLanguage.toUpperCase() }}</span>
          </div>
          <input v-model="taxonomyFilter" class="form-control" placeholder="Rechercher une taxonomie…" />
          <p v-if="taxonomyFilter.trim()" class="form-text mb-0 mt-2">Le glisser-déposer est disponible lorsque la recherche est vide.</p>
        </div>
        <div class="list-group list-group-flush taxonomy-list">
          <button
            v-for="row in filteredTaxonomies"
            :key="taxonomyKey(row)"
            type="button"
            class="list-group-item list-group-item-action taxonomy-list-item"
            :class="{ active: taxonomyKey(row) === selectedKey, 'is-dragging': draggingTaxonomyKey === taxonomyKey(row), 'is-drop-target': dragOverTaxonomyKey === taxonomyKey(row) }"
            :draggable="canDragTaxonomies"
            @click="selectTaxonomy(row)"
            @dragstart="onTaxonomyDragStart(row, $event)"
            @dragenter.prevent="onTaxonomyDragEnter(row)"
            @dragover.prevent
            @dragleave="dragOverTaxonomyKey = ''"
            @drop.prevent="onTaxonomyDrop(row)"
            @dragend="onTaxonomyDragEnd"
          >
            <div class="d-flex justify-content-between gap-2">
              <span class="d-inline-flex align-items-center gap-2 min-w-0">
                <span v-if="canDragTaxonomies" class="drag-handle d-inline-flex align-items-center text-secondary me-1 fs-5 lh-1" aria-hidden="true">⠿</span>
                <strong class="fs-5 lh-sm">{{ row.name || row.label || taxonomyKey(row) }}</strong>
              </span>
              <span class="badge rounded-pill" :class="toBool(row.archive_enabled, true) ? 'text-bg-success' : 'text-bg-light'">{{ toBool(row.archive_enabled, true) ? 'publique' : 'interne' }}</span>
            </div>
            <div class="d-flex gap-2 flex-wrap mt-2">
              <span class="badge text-bg-light">{{ toBool(row.is_hierarchical, true) ? 'hiérarchique' : 'plate' }}</span>
              <span class="badge text-bg-light">{{ termCount(taxonomyKey(row)) }} terme(s)</span>
            </div>
          </button>
          <p v-if="loading" class="p-3 text-muted mb-0">Chargement…</p>
          <p v-else-if="filteredTaxonomies.length === 0" class="p-3 text-muted mb-0">Aucune taxonomie trouvée.</p>
        </div>
      </div>
    </section>

    <section class="taxonomy-builder-layout__center" aria-label="Structure des termes">
      <div class="card h-100 p-0 overflow-hidden" :class="{ 'opacity-75': !selectedKey }">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <p class="eyebrow mb-1">Structure</p>
              <h2 class="h5 mb-0">{{ currentTaxonomy?.name || taxonomyForm.name || 'Termes de taxonomie' }}</h2>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-outline-primary btn-sm" :disabled="!selectedKey" @click="panelMode = 'taxonomy'; selectedTermId = null">Paramètres</button>
              <button class="btn btn-primary btn-sm" :disabled="!selectedKey" @click="newRootTerm">Ajouter un terme</button>
            </div>
          </div>
          <input v-model="termSearch" class="form-control mt-3" placeholder="Rechercher dans les termes…" />
          <p v-if="termSearch.trim()" class="form-text mb-0 mt-2">Le glisser-déposer est disponible lorsque la recherche est vide.</p>
        </div>
        <div class="card-body taxonomy-tree-panel">
          <div v-if="!selectedKey" class="empty-state border rounded p-4 text-center text-muted">
            Sélectionnez une taxonomie à gauche ou créez-en une nouvelle pour commencer.
          </div>
          <p v-else-if="termsLoading" class="text-muted mb-0">Chargement des termes…</p>
          <div v-else-if="flattenedTerms.length === 0" class="empty-state border rounded p-4 text-center text-muted">
            Aucun terme. Utilisez “Ajouter un terme” pour créer le premier élément.
          </div>
          <div v-else class="taxonomy-tree-list">
            <div
              v-for="term in flattenedTerms"
              :key="String(term.id ?? term.term_key)"
              class="taxonomy-term-row"
              :class="{ 'is-selected': Number(term.id) === selectedTermId, 'is-muted': !toBool(term.is_active, true), 'is-dragging': draggingTermId === Number(term.id), 'is-drop-target': dragOverTermId === Number(term.id) }"
              :style="{ '--term-depth': String(term.depth) }"
              :draggable="canDragTerms"
              @dragstart="onTermDragStart(term, $event)"
              @dragenter.prevent="onTermDragEnter(term)"
              @dragover.prevent
              @dragleave="dragOverTermId = null"
              @drop.prevent="onTermDrop(term)"
              @dragend="onTermDragEnd"
            >
              <button type="button" class="taxonomy-term-main" @click="selectTerm(term)">
                <span v-if="canDragTerms" class="drag-handle d-inline-flex align-items-center text-secondary me-1 fs-5 lh-1" aria-hidden="true">⠿</span>
                <span class="taxonomy-term-text">
                  <strong class="fs-5 lh-sm">{{ term.name || term.term_key || term.slug || 'Sans nom' }}</strong>
                </span>
              </button>
              <div class="taxonomy-term-tools">
                <span class="badge" :class="toBool(term.is_active, true) ? 'text-bg-success' : 'text-bg-secondary'">{{ toBool(term.is_active, true) ? 'actif' : 'inactif' }}</span>
                <button v-if="selectedTaxonomyIsHierarchical" class="btn btn-sm btn-outline-secondary" type="button" @click="newChildTerm(term)">Sous-terme</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <aside class="taxonomy-builder-layout__right" aria-label="Édition contextuelle">
      <div v-if="!selectedKey && !isCreatingTaxonomy" class="card taxonomy-side sticky-xl-top">
        <div class="card-header"><p class="eyebrow mb-1">Aide</p><h2 class="h5 mb-0">Créer ou sélectionner</h2></div>
        <div class="card-body">
          <p class="text-muted mb-3">Ce panneau affiche soit les paramètres de la taxonomie, soit le formulaire du terme sélectionné.</p>
          <button class="btn btn-primary w-100" @click="newTaxonomy">Nouvelle taxonomie</button>
        </div>
      </div>

      <div v-else-if="panelMode === 'taxonomy'" class="card taxonomy-side sticky-xl-top">
        <div class="card-header d-flex justify-content-between align-items-start gap-2">
          <div><p class="eyebrow mb-1">Taxonomie</p><h2 class="h5 mb-0">{{ taxonomyForm.name || 'Nouvelle taxonomie' }}</h2></div>
          <span v-if="dirty" class="badge text-bg-warning">non enregistré</span>
        </div>
        <div class="card-body">
          <div class="d-grid gap-3">
            <label class="form-field">Nom affiché
              <input v-model="taxonomyForm.name" class="form-control" placeholder="Catégories" @input="markDirty" @blur="inferTaxonomyKey" />
            </label>
            <label class="form-field">Clé technique
              <input v-model="taxonomyForm.taxonomy_key" class="form-control" placeholder="categories" @input="taxonomyForm.taxonomy_key = normalizeSlug(inputValue($event)); markDirty()" />
              <small class="form-text">Auto-générée depuis le nom. À modifier avec prudence.</small>
            </label>
            <label class="form-field">Description
              <textarea v-model="taxonomyForm.description" class="form-control" rows="3" placeholder="Courte description éditoriale." @input="markDirty" />
            </label>
            <div class="border rounded p-3">
              <p class="eyebrow mb-2">Type</p>
              <div class="d-grid gap-2">
                <label class="form-check"><input v-model="taxonomyForm.is_hierarchical" class="form-check-input" type="radio" :value="true" @change="markDirty" /><span class="form-check-label">Hiérarchique : catégories, rubriques, familles</span></label>
                <label class="form-check"><input v-model="taxonomyForm.is_hierarchical" class="form-check-input" type="radio" :value="false" @change="markDirty" /><span class="form-check-label">Plate : tags, mots-clés, labels</span></label>
              </div>
            </div>
            <label class="form-check form-switch"><input v-model="taxonomyForm.archive_enabled" class="form-check-input" type="checkbox" @change="markDirty" /><span class="form-check-label">Archive publique activée</span></label>
            <details :open="showTaxonomyAdvanced" @toggle="onTaxonomyAdvancedToggle">
              <summary>Options avancées</summary>
              <div class="d-grid gap-3 mt-3">
                <label class="form-check form-switch"><input v-model="taxonomyForm.is_localized" class="form-check-input" type="checkbox" @change="markDirty" /><span class="form-check-label">Taxonomie localisée</span></label>
                <label class="form-check form-switch"><input v-model="taxonomyForm.seo_enabled" class="form-check-input" type="checkbox" @change="markDirty" /><span class="form-check-label">Champs SEO activés</span></label>
              </div>
            </details>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-primary" :disabled="saving" @click="saveTaxonomy">Enregistrer</button>
              <button v-if="selectedKey" class="btn btn-outline-danger" :disabled="saving" @click="deleteTaxonomy">Supprimer</button>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="card taxonomy-side sticky-xl-top">
        <div class="card-header d-flex justify-content-between align-items-start gap-2">
          <div><p class="eyebrow mb-1">Terme</p><h2 class="h5 mb-0">{{ activeLocalization.name || selectedTerm?.name || 'Nouveau terme' }}</h2></div>
          <span v-if="dirty" class="badge text-bg-warning">non enregistré</span>
        </div>
        <div class="card-body p-4">
          <div class="d-grid gap-4">
            <div class="language-tabs d-flex flex-wrap gap-3" role="tablist" aria-label="Langues du terme">
              <button v-for="lang in languages" :key="lang.code" type="button" class="btn btn-sm" :class="visibleLanguage === lang.code ? 'btn-primary' : 'btn-outline-secondary'" @click="editLanguage = lang.code">
                {{ lang.code.toUpperCase() }} <span class="badge ms-1" :class="localizationBadgeClass(lang.code)">{{ localizationLabel(lang.code) }}</span>
              </button>
            </div>
            <label class="form-field">Nom
              <input v-model="activeLocalization.name" class="form-control" placeholder="Actualités" @input="markDirty" @blur="inferTermKeyAndSlug(visibleLanguage)" />
            </label>
            <label class="form-field">Slug
              <input v-model="activeLocalization.slug" class="form-control" placeholder="actualites" @input="activeLocalization.slug = normalizeSlug(inputValue($event)); markDirty()" />
            </label>
            <label class="form-field">Description courte
              <textarea v-model="activeLocalization.description" class="form-control" rows="3" placeholder="Texte optionnel utilisé dans l’archive ou l’éditeur." @input="markDirty" />
            </label>
            <label class="form-field">Clé stable du terme
              <input v-model="termForm.term_key" class="form-control" placeholder="actualites" @input="termForm.term_key = normalizeSlug(inputValue($event)); markDirty()" />
            </label>
            <label v-if="selectedTaxonomyIsHierarchical" class="form-field">Parent
              <select v-model="termForm.parent_id" class="form-select" @change="markDirty">
                <option value="">Aucun parent</option>
                <option v-for="term in parentOptions" :key="String(term.id)" :value="String(term.id)">{{ term.name || term.term_key || term.slug }}</option>
              </select>
            </label>
            <label class="form-check form-switch mb-0"><input v-model="termForm.is_active" class="form-check-input" type="checkbox" @change="markDirty" /><span class="form-check-label">Terme actif</span></label>
            <details>
              <summary>SEO avancé</summary>
              <div class="d-grid gap-3 mt-3">
                <label class="form-field">Chemin archive
                  <input v-model="activeLocalization.full_path" class="form-control" placeholder="/categories/actualites" @input="markDirty" />
                </label>
                <label v-if="taxonomyForm.seo_enabled" class="form-field">Meta title
                  <input v-model="activeLocalization.meta_title" class="form-control" maxlength="70" placeholder="70 caractères max." @input="markDirty" />
                </label>
                <label v-if="taxonomyForm.seo_enabled" class="form-field">Meta description
                  <textarea v-model="activeLocalization.meta_description" class="form-control" rows="2" maxlength="170" placeholder="170 caractères max." @input="markDirty" />
                </label>
              </div>
            </details>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-primary" :disabled="saving || !selectedKey" @click="saveTerm">Enregistrer</button>
              <button class="btn btn-outline-secondary" :disabled="!selectedKey" @click="newRootTerm">Nouveau</button>
              <button v-if="selectedTermId" class="btn btn-outline-danger" :disabled="saving" @click="deleteTerm">Supprimer</button>
            </div>
          </div>
        </div>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.taxonomy-builder-layout { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: start; }
.taxonomy-builder-layout__left, .taxonomy-builder-layout__center, .taxonomy-builder-layout__right { min-width: 0; }
@media (min-width: 992px) {
  .taxonomy-builder-layout { grid-template-columns: minmax(220px, 280px) minmax(300px, .78fr) minmax(430px, 1.22fr); }
  .taxonomy-builder-layout__left, .taxonomy-builder-layout__right { position: sticky; top: 1rem; }
}
@media (min-width: 1400px) {
  .taxonomy-builder-layout { grid-template-columns: minmax(240px, 310px) minmax(340px, .72fr) minmax(520px, 1.28fr); }
}
.taxonomy-list { max-height: 70vh; overflow: auto; }
.taxonomy-list-item strong { overflow-wrap: anywhere; }
.drag-handle { cursor: grab; user-select: none; }
.taxonomy-list-item[draggable="true"], .taxonomy-term-row[draggable="true"] { cursor: grab; }
.taxonomy-list-item.is-dragging, .taxonomy-term-row.is-dragging { opacity: .55; }
.taxonomy-list-item.is-drop-target, .taxonomy-term-row.is-drop-target { outline: 2px dashed rgba(var(--bs-primary-rgb), .55); outline-offset: -4px; }
.taxonomy-list-item.active .badge.text-bg-light { background: #e2e8f0 !important; color: #0f172a !important; }
.taxonomy-list-item.active .badge.text-bg-success { color: #fff !important; }
.taxonomy-tree-panel { min-height: 420px; }
.taxonomy-tree-list { display: grid; gap: .5rem; }
.taxonomy-term-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .65rem .75rem .65rem calc(.75rem + (var(--term-depth) * 1.35rem)); border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
.taxonomy-term-row:hover, .taxonomy-term-row.is-selected { border-color: var(--bs-primary); box-shadow: 0 0 0 .15rem rgba(var(--bs-primary-rgb), .12); }
.taxonomy-term-row.is-muted { opacity: .62; }
.taxonomy-term-main { display: flex; align-items: center; gap: .65rem; min-width: 0; border: 0; background: transparent; color: inherit; padding: 0; text-align: left; }
.taxonomy-term-text { display: grid; min-width: 0; }
.taxonomy-term-text strong { overflow-wrap: anywhere; }
.taxonomy-term-tools { display: flex; align-items: center; justify-content: flex-end; gap: .35rem; flex-wrap: wrap; }
.taxonomy-side { top: 1rem; }
.language-tabs { display: flex; flex-wrap: wrap; gap: .75rem; }
.empty-state { background: var(--bs-tertiary-bg, rgba(0, 0, 0, .02)); }
@media (max-width: 991.98px) {
  .taxonomy-term-row { align-items: flex-start; flex-direction: column; padding-left: .75rem; }
  .taxonomy-term-tools { justify-content: flex-start; }
}
</style>
