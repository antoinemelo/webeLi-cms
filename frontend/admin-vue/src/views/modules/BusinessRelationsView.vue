<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import BusinessPageHeader from './business/BusinessPageHeader.vue';
import BusinessQuickSearch from './business/BusinessQuickSearch.vue';
import EmptyState from './business/EmptyState.vue';
import RelationBadge from './business/RelationBadge.vue';
import RelationCard from './business/RelationCard.vue';

type RelationType = 'contact' | 'company';
type Relation = {
  type: RelationType;
  id: number;
  display_name: string;
  primary_email?: string | null;
  website_url?: string | null;
  iam_user_id?: number | null;
  company?: { id: number; name: string; is_system_individuals?: boolean } | null;
  phone?: string | null;
  mobile?: string | null;
  status?: string;
  tags?: string[];
  email_consent_status?: string | null;
  memo_count?: number;
  shared_memo_count?: number;
  linked_contacts_count?: number;
  last_activity_at?: string | null;
  last_memo_excerpt?: string | null;
  archived_at?: string | null;
};
type SearchItem = Record<string, unknown> & {
  group?: string;
  type?: RelationType | 'memo' | 'message';
  id: number;
  title?: string;
  subtitle?: string | null;
  excerpt?: string | null;
  status?: string | null;
  channel?: string | null;
  company_id?: number | null;
  contact_id?: number | null;
};
type SearchResults = {
  relations: SearchItem[];
  memos: SearchItem[];
  messages: SearchItem[];
  future_documents: SearchItem[];
};
type RelationColumnKey = 'company' | 'phone' | 'status' | 'indicators' | 'activity';
type RelationSortKey = RelationColumnKey | 'info';
type RelationPageSize = 10 | 25 | 50 | 100 | 'all';

const emit = defineEmits<{
  'new-relation': [];
  'open-import': [];
  'open-export': [];
  'open-memos-global': [];
  'open-comments': [];
  'open-messages-list': [];
  'edit-company': [relation: Relation, mode?: 'view' | 'edit'];
  'edit-contact': [relation: Relation, mode?: 'view' | 'edit'];
  'new-memo': [relation: Relation];
  'new-message': [relation: Relation, channel?: string];
  'open-memos': [relation: Relation];
  'open-consent': [relation: Relation];
  'archive-company': [relation: Relation];
  'archive-contact': [relation: Relation];
  'restore-company': [relation: Relation];
  'restore-contact': [relation: Relation];
  'delete-company': [relation: Relation];
  'delete-contact': [relation: Relation];
}>();
const context = useAdminContextStore();
const canConsentRead = computed(() => context.can('business.consent.read'));

const loading = ref(false);
const searchLoading = ref(false);
const error = ref('');
const searchError = ref('');
const relations = ref<Relation[]>([]);
const pagination = ref({ total: 0, limit: 25, offset: 0 });
const filters = reactive({ q: '', type: '', status: '', sort: 'activity_desc', has_memos: false, has_shared_memos: false, has_email: false, has_phone: false, missing_email_consent: false, linked_iam: false, archived: 'active' });
const appliedAdvancedFilters = reactive({ type: '', status: '', sort: 'activity_desc', archived: 'active' });
const relationSort = reactive<{ key: RelationSortKey; direction: 'asc' | 'desc' }>({ key: 'info', direction: 'asc' });
const relationPage = ref(1);
const relationPageSize = ref<RelationPageSize>(25);
const relationPageSizeOptions: RelationPageSize[] = [10, 25, 50, 100, 'all'];
const relationColumns: Array<{ key: RelationColumnKey; label: string }> = [
  { key: 'company', label: 'Entreprise' },
  { key: 'phone', label: 'Contact' },
  { key: 'status', label: 'Statut' },
  { key: 'indicators', label: 'Indicateurs' },
  { key: 'activity', label: 'Activité' },
];
const visibleColumns = reactive<Record<RelationColumnKey, boolean>>({ company: true, phone: true, status: true, indicators: true, activity: true });
const searchResults = reactive<SearchResults>({ relations: [], memos: [], messages: [], future_documents: [] });
const searchInput = ref<{ focus: () => void } | null>(null);
const filtersMenu = ref<HTMLDetailsElement | null>(null);
let searchTimer: ReturnType<typeof window.setTimeout> | null = null;

type QuickFilterKey = 'all' | 'contact' | 'company' | 'prospect' | 'client' | 'supplier' | 'former_client' | 'has_email' | 'has_phone' | 'missing_email_consent';
const quickFilters: Array<{ key: QuickFilterKey; label: string }> = [
  { key: 'all', label: 'Tous' },
  { key: 'contact', label: 'Personnes' },
  { key: 'company', label: 'Organisations' },
  { key: 'prospect', label: 'Prospects' },
  { key: 'client', label: 'Clients' },
  { key: 'supplier', label: 'Fournisseurs' },
  { key: 'former_client', label: 'Anciens' },
  { key: 'has_email', label: 'Avec email' },
  { key: 'has_phone', label: 'Avec téléphone' },
  { key: 'missing_email_consent', label: 'Sans consentement email' },
];

const searchGroups = computed(() => [
  { key: 'relations', label: 'Relations', items: searchResults.relations },
  { key: 'memos', label: 'Mémos', items: searchResults.memos },
  { key: 'messages', label: 'Messages', items: searchResults.messages },
  { key: 'future_documents', label: 'Documents', items: searchResults.future_documents },
]);
const sortedRelations = computed(() => {
  const rows = [...relations.value];
  rows.sort((a, b) => compareRelationValue(a, b, relationSort.key) * (relationSort.direction === 'asc' ? 1 : -1));
  return rows;
});
const relationTotal = computed(() => Number(pagination.value.total || relations.value.length));
const relationNumericPageLimit = computed(() => relationPageSize.value === 'all' ? Math.max(1, relationTotal.value || relations.value.length || 1) : relationPageSize.value);
const relationPageCount = computed(() => relationPageSize.value === 'all' ? 1 : Math.max(1, Math.ceil(relationTotal.value / relationNumericPageLimit.value)));
const relationPaginationLabel = computed(() => {
  if (relationTotal.value < 1) return '0 relation';
  const start = Number(pagination.value.offset || 0) + 1;
  const end = Math.min(start + relations.value.length - 1, relationTotal.value);
  return `${start}-${end} / ${relationTotal.value}`;
});

const typeLabels: Record<string, string> = { contact: 'Personnes', company: 'Organisations' };
const statusLabels: Record<string, string> = { prospect: 'Prospects', client: 'Clients', supplier: 'Fournisseurs', former_client: 'Anciens', other: 'Autres' };
const sortLabels: Record<string, string> = { activity_desc: 'Activité récente', name_asc: 'Nom A-Z', created_desc: 'Date création', status_asc: 'Statut' };
const archivedLabels: Record<string, string> = { active: 'Actifs', archived: 'Archivés', all: 'Actifs et archivés' };
type AdvancedFilterKey = 'type' | 'status' | 'sort' | 'archived';
const activeFilterChips = computed<Array<{ key: AdvancedFilterKey; label: string }>>(() => {
  const chips: Array<{ key: AdvancedFilterKey; label: string }> = [];
  if (appliedAdvancedFilters.type) chips.push({ key: 'type', label: `Type · ${typeLabels[appliedAdvancedFilters.type] || appliedAdvancedFilters.type}` });
  if (appliedAdvancedFilters.status) chips.push({ key: 'status', label: `Statut · ${statusLabels[appliedAdvancedFilters.status] || statusLabel(appliedAdvancedFilters.status)}` });
  if (appliedAdvancedFilters.sort !== 'activity_desc') chips.push({ key: 'sort', label: `Tri · ${sortLabels[appliedAdvancedFilters.sort] || appliedAdvancedFilters.sort}` });
  if (appliedAdvancedFilters.archived !== 'active') chips.push({ key: 'archived', label: `Archivage · ${archivedLabels[appliedAdvancedFilters.archived] || appliedAdvancedFilters.archived}` });
  return chips;
});

function statusLabel(value: unknown): string {
  return String(value || 'other').replace(/_/g, ' ');
}

function relationKind(relation: Relation): string {
  return relation.type === 'contact' ? 'Personne' : 'Organisation';
}

function companyLabel(relation: Relation): string {
  if (relation.type === 'company') {
    return relation.company?.is_system_individuals ? 'Individus' : 'Organisation';
  }
  return relation.company?.name || 'Individus';
}

function phoneLabel(relation: Relation): string {
  return [relation.phone, relation.mobile].filter(Boolean).join(' / ') || '-';
}

function contactLines(relation: Relation): Array<{ key: string; icon: 'chat' | 'globe' | 'phone'; value: string }> {
  const lines: Array<{ key: string; icon: 'chat' | 'globe' | 'phone'; value: string }> = [];
  if (relation.primary_email) lines.push({ key: 'email', icon: 'chat', value: relation.primary_email });
  if (relation.website_url) lines.push({ key: 'website', icon: 'globe', value: relation.website_url });
  for (const [key, value] of [['phone', relation.phone], ['mobile', relation.mobile]] as const) {
    if (value) lines.push({ key, icon: 'phone', value });
  }
  return lines;
}

function relationTags(relation: Relation): string[] {
  return (relation.tags || []).slice(0, 3);
}

function isSystemCompanyRelation(relation: Relation): boolean {
  return relation.type === 'company' && relation.company?.is_system_individuals === true;
}

function columnVisible(key: RelationColumnKey): boolean {
  return visibleColumns[key] === true;
}

function shortDate(value: string | null | undefined): string {
  if (!value) return '';
  return value.slice(0, 10);
}

function emailConsentLabel(relation: Relation): string {
  if (relation.type !== 'contact' || !relation.primary_email) return '';
  if (relation.email_consent_status === 'opt_in') return 'Consentement email valide';
  if (relation.email_consent_status === 'opt_out') return 'Consentement email refusé';
  return 'Consentement email absent';
}

function numberValue(value: unknown): number {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function sortText(value: unknown): string {
  return String(value ?? '').toLocaleLowerCase('fr');
}

function relationSortValue(relation: Relation, key: RelationSortKey): string | number {
  if (key === 'company') return sortText(companyLabel(relation));
  if (key === 'phone') return sortText([relation.primary_email, relation.website_url, relation.phone, relation.mobile].filter(Boolean).join(' '));
  if (key === 'status') return sortText(relation.status || '');
  if (key === 'indicators') return numberValue(relation.memo_count) + numberValue(relation.shared_memo_count) + numberValue(relation.linked_contacts_count);
  if (key === 'activity') return sortText(relation.last_activity_at || '');
  return sortText(relation.display_name);
}

function compareRelationValue(a: Relation, b: Relation, key: RelationSortKey): number {
  const left = relationSortValue(a, key);
  const right = relationSortValue(b, key);
  if (typeof left === 'number' && typeof right === 'number') return left - right || Number(a.id || 0) - Number(b.id || 0);
  return String(left).localeCompare(String(right), 'fr', { numeric: true, sensitivity: 'base' }) || Number(a.id || 0) - Number(b.id || 0);
}

function setRelationSort(key: RelationSortKey): void {
  if (relationSort.key === key) relationSort.direction = relationSort.direction === 'asc' ? 'desc' : 'asc';
  else {
    relationSort.key = key;
    relationSort.direction = key === 'activity' || key === 'indicators' ? 'desc' : 'asc';
  }
}

function relationSortLabel(key: RelationSortKey): string {
  if (relationSort.key !== key) return 'Trier';
  return relationSort.direction === 'asc' ? 'Tri croissant' : 'Tri décroissant';
}

function relationPageSizeLabel(size: RelationPageSize): string {
  return size === 'all' ? 'Tous' : String(size);
}

function setRelationPageSize(size: RelationPageSize): void {
  relationPageSize.value = size;
  relationPage.value = 1;
  void loadRelations();
}

function onRelationPageSizeChange(event: Event): void {
  const value = String((event.target as HTMLSelectElement | null)?.value ?? '25');
  setRelationPageSize(value === 'all' ? 'all' : (Number(value) as RelationPageSize));
}

function setRelationPage(page: number): void {
  const next = Math.max(1, Math.min(relationPageCount.value, page));
  if (next === relationPage.value) return;
  relationPage.value = next;
  void loadRelations();
}

function resetQuickFilters(): void {
  filters.type = '';
  filters.status = '';
  filters.has_email = false;
  filters.has_phone = false;
  filters.missing_email_consent = false;
}

function quickFilterActive(key: QuickFilterKey): boolean {
  if (key === 'all') return filters.type === '' && filters.status === '' && !filters.has_email && !filters.has_phone && !filters.missing_email_consent;
  if (key === 'contact') return filters.type === 'contact' && filters.status === '' && !filters.has_email && !filters.has_phone && !filters.missing_email_consent;
  if (key === 'company') return filters.type === 'company' && filters.status === '' && !filters.has_email && !filters.has_phone && !filters.missing_email_consent;
  if (key === 'has_email') return filters.type === '' && filters.status === '' && filters.has_email && !filters.has_phone && !filters.missing_email_consent;
  if (key === 'has_phone') return filters.type === '' && filters.status === '' && !filters.has_email && filters.has_phone && !filters.missing_email_consent;
  if (key === 'missing_email_consent') return filters.type === '' && filters.status === '' && !filters.has_email && !filters.has_phone && filters.missing_email_consent;
  return filters.type === '' && filters.status === key && !filters.has_email && !filters.has_phone && !filters.missing_email_consent;
}

function applyQuickFilter(key: QuickFilterKey): void {
  relationPage.value = 1;
  resetQuickFilters();
  Object.assign(appliedAdvancedFilters, { type: '', status: '', sort: 'activity_desc', archived: 'active' });
  if (key === 'contact' || key === 'company') filters.type = key;
  else if (key === 'has_email') filters.has_email = true;
  else if (key === 'has_phone') filters.has_phone = true;
  else if (key === 'missing_email_consent') filters.missing_email_consent = true;
  else if (key !== 'all') filters.status = key;
  void loadRelations();
}

function applyFilters(): void {
  relationPage.value = 1;
  Object.assign(appliedAdvancedFilters, {
    type: filters.type,
    status: filters.status,
    sort: filters.sort,
    archived: filters.archived,
  });
  if (filtersMenu.value) filtersMenu.value.open = false;
  void loadRelations();
}

function removeFilterChip(key: AdvancedFilterKey): void {
  relationPage.value = 1;
  if (key === 'type') filters.type = appliedAdvancedFilters.type = '';
  else if (key === 'status') filters.status = appliedAdvancedFilters.status = '';
  else if (key === 'sort') filters.sort = appliedAdvancedFilters.sort = 'activity_desc';
  else if (key === 'archived') filters.archived = appliedAdvancedFilters.archived = 'active';
  void loadRelations();
}

function query(): Record<string, string | number | boolean> {
  const limit = relationPageSize.value === 'all' ? 'all' : relationPageSize.value;
  const offset = relationPageSize.value === 'all' ? 0 : (relationPage.value - 1) * relationNumericPageLimit.value;
  const params: Record<string, string | number | boolean> = { q: filters.q, sort: filters.sort, limit, offset };
  if (filters.type) params.type = filters.type;
  if (filters.status) params.status = filters.status;
  if (filters.has_memos) params.has_memos = true;
  if (filters.has_shared_memos) params.has_shared_memos = true;
  if (filters.has_email) params.has_email = true;
  if (filters.has_phone) params.has_phone = true;
  if (filters.missing_email_consent) params.missing_email_consent = true;
  if (filters.linked_iam) params.linked_iam = true;
  if (filters.archived !== 'active') params.archived = filters.archived;
  return params;
}

function clearSearchResults(): void {
  searchResults.relations = [];
  searchResults.memos = [];
  searchResults.messages = [];
  searchResults.future_documents = [];
}

async function runGlobalSearch(): Promise<void> {
  const q = filters.q.trim();
  if (q.length < 2) {
    clearSearchResults();
    return;
  }
  searchLoading.value = true;
  searchError.value = '';
  try {
    const response = await adminApi.get<SearchResults>('/business/search', { q, limit: 8 });
    searchResults.relations = response.data.relations || [];
    searchResults.memos = response.data.memos || [];
    searchResults.messages = response.data.messages || [];
    searchResults.future_documents = response.data.future_documents || [];
  } catch (err) {
    clearSearchResults();
    searchError.value = apiErrorMessage(err, 'Recherche globale indisponible.');
  } finally {
    searchLoading.value = false;
  }
}

async function loadRelations(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ relations: Relation[]; pagination: { total?: number; limit?: number; offset?: number } }>('/business/relations', query());
    const received = response.data.relations || [];
    relations.value = received.filter((relation) => !isSystemCompanyRelation(relation));
    const hiddenCount = received.length - relations.value.length;
    pagination.value = {
      total: Math.max(0, Number(response.data.pagination?.total || relations.value.length) - hiddenCount),
      limit: Number(response.data.pagination?.limit || relationNumericPageLimit.value),
      offset: Number(response.data.pagination?.offset || 0),
    };
    if (relationPage.value > relationPageCount.value) relationPage.value = relationPageCount.value;
  } catch (err) {
    error.value = apiErrorMessage(err, 'Chargement des relations impossible.');
  } finally {
    loading.value = false;
  }
}

function edit(relation: Relation, mode: 'view' | 'edit' = 'view'): void {
  relation.type === 'company' ? emit('edit-company', relation, mode) : emit('edit-contact', relation, mode);
}

function openSearchItem(item: SearchItem): void {
  if (item.group === 'relation' || item.type === 'contact' || item.type === 'company') {
    edit({
      type: item.type === 'company' ? 'company' : 'contact',
      id: item.id,
      display_name: String(item.title || item.subtitle || `#${item.id}`),
      status: String(item.status || 'prospect'),
      primary_email: typeof item.email === 'string' ? item.email : null,
      phone: typeof item.phone === 'string' ? item.phone : null,
      mobile: typeof item.mobile === 'string' ? item.mobile : null,
      company: item.company_id ? { id: Number(item.company_id), name: String(item.company_name || item.subtitle || 'Organisation') } : null,
    });
    return;
  }
  if (item.group === 'memo' || item.type === 'memo') {
    if (item.contact_id) emit('open-memos', { type: 'contact', id: Number(item.contact_id), display_name: String(item.subtitle || 'Contact') });
    else if (item.company_id) emit('open-memos', { type: 'company', id: Number(item.company_id), display_name: String(item.subtitle || 'Organisation') });
    else emit('open-memos-global');
    return;
  }
  if (item.group === 'message' || item.type === 'message') {
    emit('open-messages-list');
  }
}

function archive(relation: Relation): void {
  relation.type === 'company' ? emit('archive-company', relation) : emit('archive-contact', relation);
}

function deleteRelation(relation: Relation): void {
  relation.type === 'company' ? emit('delete-company', relation) : emit('delete-contact', relation);
}

function restoreRelation(relation: Relation): void {
  relation.type === 'company' ? emit('restore-company', relation) : emit('restore-contact', relation);
}

defineExpose({ reload: loadRelations });
function onKeyboardShortcut(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null;
  const isEditing = target !== null && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
  if ((event.key === '/' && !isEditing) || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k')) {
    event.preventDefault();
    searchInput.value?.focus();
  }
}

function closeOpenMenus(): void {
  document.querySelectorAll<HTMLDetailsElement>('.relations-menu[open]').forEach((menu) => {
    menu.open = false;
  });
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target as HTMLElement | null;
  if (target?.closest('.relations-menu')) return;
  closeOpenMenus();
}

watch(() => filters.q, () => {
  if (searchTimer !== null) window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => { void runGlobalSearch(); }, 220);
});

onMounted(() => {
  window.addEventListener('keydown', onKeyboardShortcut);
  document.addEventListener('pointerdown', onDocumentPointerDown);
  void loadRelations();
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeyboardShortcut);
  document.removeEventListener('pointerdown', onDocumentPointerDown);
  if (searchTimer !== null) window.clearTimeout(searchTimer);
});
</script>

<template>
  <section class="relations-panel">
    <BusinessPageHeader eyebrow="CRM" title="Relations">
      <template #actions>
        <button class="btn primary small" type="button" aria-label="Créer une nouvelle relation CRM" @click="emit('new-relation')">Nouvelle relation</button>
      </template>
    </BusinessPageHeader>

    <form class="relations-toolbar" @submit.prevent="applyFilters">
      <BusinessQuickSearch
        ref="searchInput"
        v-model="filters.q"
        :groups="searchGroups"
        :loading="searchLoading"
        :error="searchError"
        placeholder="Recherche globale : personne, organisation, email, téléphone, ville, tag, mémo"
        @open="openSearchItem"
        @create="emit('new-relation')"
      />
      <div class="relations-toolbar-buttons">
        <details ref="filtersMenu" class="relations-menu relations-menu--filters">
          <summary class="relations-icon-summary" aria-label="Filtres relations" title="Filtres">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16l-6 7v5l-4 2v-7L4 6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          </summary>
          <div class="relations-menu-panel relations-filter-panel">
            <strong>Filtres</strong>
            <label>
              <select v-model="filters.type" aria-label="Type relation">
                <option value="">Tous types</option>
                <option value="contact">Personnes</option>
                <option value="company">Organisations</option>
              </select>
            </label>
            <label>
              <select v-model="filters.status" aria-label="Statut relation">
                <option value="">Tous statuts</option>
                <option value="prospect">Prospects</option>
                <option value="client">Clients</option>
                <option value="supplier">Fournisseurs</option>
                <option value="former_client">Anciens</option>
                <option value="other">Autres</option>
              </select>
            </label>
            <label>
              <select v-model="filters.sort" aria-label="Tri relation">
                <option value="activity_desc">Activité récente</option>
                <option value="name_asc">Nom A-Z</option>
                <option value="created_desc">Date création</option>
                <option value="status_asc">Statut</option>
              </select>
            </label>
            <label><input v-model="filters.has_memos" type="checkbox"> A des mémos</label>
            <label><input v-model="filters.has_shared_memos" type="checkbox"> A des mémos partagés</label>
            <label><input v-model="filters.has_email" type="checkbox"> Avec email</label>
            <label><input v-model="filters.has_phone" type="checkbox"> Avec téléphone</label>
            <label><input v-model="filters.missing_email_consent" type="checkbox"> Sans consentement email</label>
            <label><input v-model="filters.linked_iam" type="checkbox"> Lié à IAM</label>
            <label>
              <select v-model="filters.archived" aria-label="Archivage relation">
                <option value="active">Actifs</option>
                <option value="archived">Archivés</option>
                <option value="all">Actifs et archivés</option>
              </select>
            </label>
            <button class="btn small" type="submit" :disabled="loading">Appliquer</button>
          </div>
        </details>
        <details class="relations-menu relations-menu--columns">
          <summary class="relations-icon-summary" aria-label="Colonnes relations" title="Colonnes">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4V5Zm5 0v14m6-14v14" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
          </summary>
          <div class="relations-menu-panel relations-column-panel">
            <strong>Colonnes</strong>
            <label v-for="column in relationColumns" :key="column.key"><input v-model="visibleColumns[column.key]" type="checkbox"> {{ column.label }}</label>
          </div>
        </details>
        <details class="relations-menu relations-menu--actions">
          <summary aria-label="Actions relations" title="Actions">...</summary>
          <div class="relations-menu-panel">
            <button type="button" @click="emit('open-import')">Importer</button>
            <button type="button" @click="emit('open-export')">Exporter</button>
            <button type="button" @click="emit('open-memos-global')">Mémos</button>
            <button type="button" @click="emit('open-comments')">Commentaires</button>
            <button type="button" @click="emit('open-messages-list')">Messages</button>
          </div>
        </details>
      </div>
    </form>

    <nav class="relations-quick-filters" aria-label="Filtres rapides relations">
      <button
        v-for="filter in quickFilters"
        :key="filter.key"
        type="button"
        :class="{ active: quickFilterActive(filter.key) }"
        @click="applyQuickFilter(filter.key)"
      >
        {{ filter.label }}
      </button>
    </nav>

    <div v-if="activeFilterChips.length" class="relations-active-filters" aria-label="Filtres actifs">
      <button
        v-for="chip in activeFilterChips"
        :key="chip.key"
        type="button"
        class="relations-filter-chip"
        :aria-label="`Retirer le filtre ${chip.label}`"
        @click="removeFilterChip(chip.key)"
      >
        <span aria-hidden="true">×</span>
        <strong>{{ chip.label }}</strong>
      </button>
    </div>

    <EmptyState v-if="error" :title="error" />
    <EmptyState v-else-if="loading" title="Chargement des relations..." />
    <EmptyState v-else-if="!relations.length" title="Aucune relation trouvée." action-label="Créer une relation" @action="emit('new-relation')" />

    <table v-else class="relations-table">
      <thead>
        <tr>
          <th class="col-info"><button class="relations-sort-button" type="button" :aria-label="relationSortLabel('info')" @click="setRelationSort('info')">Infos<span :class="{ active: relationSort.key === 'info' }">{{ relationSort.key === 'info' && relationSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
          <th v-if="columnVisible('company')" class="col-company"><button class="relations-sort-button" type="button" :aria-label="relationSortLabel('company')" @click="setRelationSort('company')">Entreprise<span :class="{ active: relationSort.key === 'company' }">{{ relationSort.key === 'company' && relationSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
          <th v-if="columnVisible('phone')" class="col-phone"><button class="relations-sort-button" type="button" :aria-label="relationSortLabel('phone')" @click="setRelationSort('phone')">Contact<span :class="{ active: relationSort.key === 'phone' }">{{ relationSort.key === 'phone' && relationSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
          <th v-if="columnVisible('status')" class="col-status"><button class="relations-sort-button" type="button" :aria-label="relationSortLabel('status')" @click="setRelationSort('status')">Statut<span :class="{ active: relationSort.key === 'status' }">{{ relationSort.key === 'status' && relationSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
          <th v-if="columnVisible('indicators')" class="col-indicators"><button class="relations-sort-button" type="button" :aria-label="relationSortLabel('indicators')" @click="setRelationSort('indicators')">Indicateurs<span :class="{ active: relationSort.key === 'indicators' }">{{ relationSort.key === 'indicators' && relationSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
          <th v-if="columnVisible('activity')" class="col-activity"><button class="relations-sort-button" type="button" :aria-label="relationSortLabel('activity')" @click="setRelationSort('activity')">Activité<span :class="{ active: relationSort.key === 'activity' }">{{ relationSort.key === 'activity' && relationSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="relation in sortedRelations" :key="`${relation.type}-${relation.id}`">
          <td class="relation-basic">
            <div class="relation-title-line">
              <span class="relation-type-icon" :title="relationKind(relation)" aria-hidden="true">
                <svg v-if="relation.type === 'contact'" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4Zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10Z"/></svg>
                <svg v-else viewBox="0 0 16 16"><path d="M8.707 1.5a1 1 0 0 0-1.414 0L.646 8.146a.5.5 0 0 0 .708.708L2 8.207V13.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V8.207l.646.647a.5.5 0 0 0 .708-.708L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.707 1.5ZM13 7.207V13.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V7.207l5-5 5 5Z"/></svg>
              </span>
              <button class="business-link relation-name" type="button" @click="edit(relation)">{{ relation.display_name }}</button>
            </div>
          </td>
          <td v-if="columnVisible('company')">{{ companyLabel(relation) }}</td>
          <td v-if="columnVisible('phone')">
            <div class="relations-contact-lines">
              <span v-for="line in contactLines(relation)" :key="`${relation.type}-${relation.id}-${line.key}`">
                <svg v-if="line.icon === 'chat'" viewBox="0 0 16 16" aria-hidden="true"><path d="M2.678 11.894a1 1 0 0 1 .287.801 10.97 10.97 0 0 1-.398 2c1.395-.323 2.247-.697 2.634-.893a1 1 0 0 1 .71-.074A8.06 8.06 0 0 0 8 14c3.866 0 7-2.91 7-6.5S11.866 1 8 1 1 3.91 1 7.5c0 1.329.43 2.568 1.178 3.598.146.2.322.413.5.796Zm-.493 3.905a21.68 21.68 0 0 1-.713.129c-.2.032-.352-.176-.273-.362a9.68 9.68 0 0 0 .244-.637l.003-.01c.248-.72.45-1.548.524-2.319C.743 11.37 0 9.76 0 7.5 0 3.358 3.582 0 8 0s8 3.358 8 7.5S12.418 15 8 15a9.06 9.06 0 0 1-2.347-.306c-.52.263-1.639.742-3.468 1.105Z"/></svg>
                <svg v-else-if="line.icon === 'globe'" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077ZM4.09 4a9.27 9.27 0 0 1 .64-1.539 7.03 7.03 0 0 1 .597-.933A7.03 7.03 0 0 0 2.255 4H4.09Zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674A6.96 6.96 0 0 0 1 7.5h2.508Zm.312 3.5A13.6 13.6 0 0 1 3.508 8.5H1c.07.877.312 1.704.674 2.5H3.82Zm.91 2.539A9.27 9.27 0 0 1 4.09 12H2.255a7.03 7.03 0 0 0 3.072 2.472 7.03 7.03 0 0 1-.597-.933ZM7.5 14.923V12H5.145c.14.448.297.805.468 1.068.552 1.035 1.217 1.651 1.887 1.855ZM5 11h2.5V8.5H4.509c.035.898.158 1.74.35 2.5H5Zm-.141-3.5H7.5V5H4.859a12.8 12.8 0 0 0-.35 2.5ZM8.5 1.077V4h2.355a7.97 7.97 0 0 0-.468-1.068C9.835 1.897 9.17 1.281 8.5 1.077ZM11.91 4h1.835a7.03 7.03 0 0 0-3.072-2.472c.22.28.42.592.597.933.25.484.464 1.003.64 1.539ZM8.5 5v2.5h2.991a12.8 12.8 0 0 0-.35-2.5H8.5Zm3.491 3.5H8.5V11h2.641c.192-.76.315-1.602.35-2.5ZM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.171-.263.328-.62.468-1.068H8.5Zm2.173 2.472A7.03 7.03 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 7.03 7.03 0 0 1-.597.933ZM12.18 11h2.146A6.96 6.96 0 0 0 15 8.5h-2.508A13.6 13.6 0 0 1 12.18 11Zm.312-3.5H15a6.96 6.96 0 0 0-.674-2.5H12.18c.174.782.282 1.623.312 2.5Z"/></svg>
                <svg v-else viewBox="0 0 16 16" aria-hidden="true"><path d="M3.654 1.328a.678.678 0 0 1 1.015-.063l2.29 2.29c.329.329.445.81.294 1.25l-.547 1.64a.678.678 0 0 0 .162.692l2.457 2.457a.678.678 0 0 0 .692.162l1.64-.547a1.745 1.745 0 0 1 1.25.294l2.29 2.29a.678.678 0 0 1-.063 1.015l-1.028.775c-.606.457-1.438.649-2.252.445-1.93-.482-3.85-1.558-5.607-3.315C4.486 8.946 3.41 7.026 2.928 5.096c-.204-.814-.012-1.646.445-2.252l.28-.516Z"/></svg>
                <span>{{ line.value }}</span>
              </span>
              <span v-if="!contactLines(relation).length">—</span>
            </div>
          </td>
          <td v-if="columnVisible('status')"><StatusBadge :status="relation.status" /></td>
          <td v-if="columnVisible('indicators')">
            <div class="relations-indicators">
              <RelationBadge v-if="Number(relation.memo_count || 0) > 0" :label="`M ${relation.memo_count}`" :title="`${relation.memo_count} mémo(s)`" :aria-label="`Voir les mémos de ${relation.display_name}`" clickable @click="emit('open-memos', relation)" />
              <RelationBadge v-if="Number(relation.shared_memo_count || 0) > 0" :label="`P ${relation.shared_memo_count}`" :title="`${relation.shared_memo_count} mémo(s) partagé(s)`" :aria-label="`Voir les mémos partagés de ${relation.display_name}`" clickable @click="emit('open-memos', relation)" />
              <RelationBadge v-if="relation.type === 'company' && !relation.company?.is_system_individuals && Number(relation.linked_contacts_count || 0) > 0" :label="`C ${relation.linked_contacts_count}`" :title="`${relation.linked_contacts_count} contact(s) lié(s)`" />
              <RelationBadge v-if="emailConsentLabel(relation)" label="@" :tone="relation.email_consent_status === 'opt_in' ? 'neutral' : 'warning'" :title="emailConsentLabel(relation)" />
              <RelationBadge v-for="tag in relationTags(relation)" :key="`${relation.type}-${relation.id}-${tag}`" :label="tag" :title="`Tag ${tag}`" tone="accent" />
            </div>
          </td>
          <td v-if="columnVisible('activity')">
            <div class="relations-activity">
              <small v-if="relation.last_activity_at" :title="relation.last_activity_at">Act. {{ shortDate(relation.last_activity_at) }}</small>
              <small v-if="relation.last_memo_excerpt" class="relation-excerpt">{{ relation.last_memo_excerpt }}</small>
              <small v-if="!relation.last_activity_at && !relation.last_memo_excerpt">—</small>
            </div>
          </td>
          <td>
            <div class="relations-actions">
              <button class="btn ghost small" type="button" :aria-label="`Voir ${relation.display_name}`" @click="edit(relation)">Voir</button>
              <button v-if="!relation.archived_at" class="btn ghost small" type="button" :aria-label="`Ajouter un mémo pour ${relation.display_name}`" @click="emit('new-memo', relation)">Mémo</button>
              <details class="relations-menu">
                <summary class="relations-row-menu-summary" :aria-label="`Actions pour ${relation.display_name}`">
                  <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                </summary>
                <div class="relations-menu-panel">
                  <template v-if="relation.archived_at">
                    <button type="button" :aria-label="`Rétablir ${relation.display_name}`" @click="restoreRelation(relation)">Rétablir</button>
                    <button type="button" :aria-label="`Effacer définitivement ${relation.display_name}`" title="Effacer définitivement la relation archivée." @click="deleteRelation(relation)">Effacer</button>
                  </template>
                  <template v-else>
                    <button type="button" :aria-label="`Éditer ${relation.display_name}`" @click="edit(relation, 'edit')">Éditer</button>
                    <button type="button" :aria-label="`Voir tous les mémos de ${relation.display_name}`" @click="emit('open-memos', relation)">Mémos</button>
                    <button type="button" :aria-label="`Préparer un message pour ${relation.display_name}`" @click="emit('new-message', relation)">Nouveau message</button>
                    <button type="button" :disabled="relation.type !== 'contact' || !relation.primary_email" :aria-label="`Préparer un email pour ${relation.display_name}`" @click="emit('new-message', relation, 'email')">Email</button>
                    <button type="button" :disabled="relation.type !== 'contact' || !relation.mobile" :aria-label="`Préparer un message WhatsApp pour ${relation.display_name}`" @click="emit('new-message', relation, 'whatsapp')">WhatsApp</button>
                    <button v-if="canConsentRead" type="button" :disabled="relation.type !== 'contact'" :aria-label="`Gérer les consentements de ${relation.display_name}`" @click="emit('open-consent', relation)">Consentement</button>
                    <button type="button" :aria-label="`Archiver ${relation.display_name}`" @click="archive(relation)">Archiver</button>
                  </template>
                </div>
              </details>
            </div>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="!error && !loading && relations.length" class="relations-pagination" aria-label="Pagination relations">
      <label>
        Lignes
        <select class="select" :value="relationPageSize" @change="onRelationPageSizeChange">
          <option v-for="size in relationPageSizeOptions" :key="String(size)" :value="size">{{ relationPageSizeLabel(size) }}</option>
        </select>
      </label>
      <span>{{ relationPaginationLabel }}</span>
      <div class="relations-pagination-actions">
        <button class="btn ghost btn-sm" type="button" :disabled="relationPage <= 1 || loading || relationPageSize === 'all'" @click="setRelationPage(relationPage - 1)">Précédent</button>
        <strong>Page {{ relationPage }} / {{ relationPageCount }}</strong>
        <button class="btn ghost btn-sm" type="button" :disabled="relationPage >= relationPageCount || loading || relationPageSize === 'all'" @click="setRelationPage(relationPage + 1)">Suivant</button>
      </div>
    </div>

    <div v-if="!error && !loading && relations.length" class="relations-cards" aria-label="Relations en cartes compactes">
      <RelationCard
        v-for="relation in sortedRelations"
        :key="`card-${relation.type}-${relation.id}`"
        :relation="relation"
        :company-label="companyLabel(relation)"
        :phone-label="phoneLabel(relation)"
        :consent-label="emailConsentLabel(relation)"
        :can-consent="canConsentRead"
        @view="edit(relation)"
        @memo="emit('new-memo', relation)"
        @message="emit('new-message', relation)"
        @email="emit('new-message', relation, 'email')"
        @whatsapp="emit('new-message', relation, 'whatsapp')"
        @consent="emit('open-consent', relation)"
        @archive="archive(relation)"
        @restore="restoreRelation(relation)"
        @delete="deleteRelation(relation)"
        @open-memos="emit('open-memos', relation)"
      />
    </div>

    <footer v-if="pagination.total" class="relations-footer">{{ pagination.total }} relation(s)</footer>
  </section>
</template>

<style scoped>
.relations-panel {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 8px;
  background: #fff;
  padding: 1rem;
  display: grid;
  gap: .9rem;
}

.relations-toolbar,
.relations-toolbar-buttons,
.relations-quick-filters,
.relations-actions,
.relations-indicators,
.relations-activity {
  display: flex;
  gap: .55rem;
  align-items: center;
  flex-wrap: wrap;
}

.relations-toolbar {
  display: grid;
  grid-template-columns: minmax(280px, 1fr) auto;
}

.relations-toolbar-buttons {
  justify-content: flex-end;
}

.relations-quick-filters {
  gap: .35rem;
}

.relations-quick-filters button {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 999px;
  background: #fff;
  color: #344054;
  min-height: 1.95rem;
  padding: .25rem .65rem;
  font-size: .84rem;
}

.relations-quick-filters button.active {
  border-color: #2563eb;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 700;
}

.relations-active-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: .35rem;
  margin-top: -.25rem;
}

.relations-filter-chip {
  display: inline-flex;
  flex-direction: row;
  align-items: center;
  gap: .35rem;
  min-height: 1.85rem;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  padding: .22rem .6rem .22rem .38rem;
  cursor: pointer;
}

.relations-filter-chip span {
  display: inline-grid;
  place-items: center;
  width: 1.05rem;
  height: 1.05rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1e40af;
  font-size: .9rem;
  line-height: 1;
}

.relations-filter-chip strong {
  font-size: .82rem;
  font-weight: 800;
  line-height: 1.1;
}

.relations-filter-chip:hover {
  border-color: #93c5fd;
  background: #dbeafe;
}

.relations-panel :where(button, summary, input, select):focus-visible {
  outline: 3px solid rgba(37, 99, 235, .35);
  outline-offset: 2px;
}

.relations-toolbar input,
.relations-toolbar select,
.relations-column-panel label {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 6px;
  min-height: 2.25rem;
  padding: .4rem .55rem;
}

.relations-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
  border: 1px solid #eef2f6;
  border-radius: 8px;
  overflow: visible;
  font-size: .92rem;
}

.relations-cards {
  display: none;
  gap: .65rem;
}

.relations-table th,
.relations-table td {
  border-bottom: 1px solid #eef2f6;
  padding: .5rem .45rem;
  text-align: left;
  vertical-align: top;
}

.relations-table th {
  color: #667085;
  font-size: .78rem;
  text-transform: uppercase;
  background: #f8fafc;
}

.relations-sort-button {
  align-items: center;
  background: transparent;
  border: 0;
  color: inherit;
  display: inline-flex;
  font: inherit;
  gap: .32rem;
  min-width: 0;
  padding: 0;
  text-align: left;
  text-transform: inherit;
}

.relations-sort-button span {
  align-items: center;
  border: 1px solid #d0d5dd;
  border-radius: 999px;
  color: #98a2b3;
  display: inline-flex;
  font-size: .68rem;
  height: 1.05rem;
  justify-content: center;
  line-height: 1;
  width: 1.05rem;
}

.relations-sort-button span.active {
  border-color: #0f172a;
  color: #0f172a;
}

.relations-pagination {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: .75rem;
  justify-content: space-between;
  padding: .75rem .1rem 0;
}

.relations-pagination label,
.relations-pagination-actions {
  align-items: center;
  display: inline-flex;
  gap: .5rem;
}

.relations-pagination label {
  color: #667085;
  font-size: .84rem;
  font-weight: 700;
}

.relations-pagination .select {
  min-width: 5.5rem;
}

.relations-pagination > span,
.relations-pagination-actions strong {
  color: #475467;
  font-size: .84rem;
  white-space: nowrap;
}

.relations-table .col-info { width: 28%; }
.relations-table .col-company { width: 16%; }
.relations-table .col-phone { width: 18%; }
.relations-table .col-status { width: 10%; }
.relations-table .col-indicators { width: 14%; }
.relations-table .col-activity { width: 14%; }
.relations-table .col-actions { width: 12%; }

.relations-table tbody tr:hover {
  background: #fcfcfd;
}

.relations-table td:first-child {
  display: grid;
  gap: .1rem;
}

.relations-table td.relation-basic {
  border-bottom: 0;
}

.relation-title-line,
.relations-contact-lines span {
  display: inline-flex;
  align-items: center;
  gap: .42rem;
  min-width: 0;
}

.relation-title-line {
  align-items: center;
}

.relation-type-icon {
  width: 1rem;
  height: 1rem;
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
  color: #0f766e;
}

.relations-contact-lines svg,
.relations-row-menu-summary svg {
  width: 1rem;
  height: 1rem;
  fill: currentColor;
}

.relation-type-icon svg {
  width: .82rem;
  height: .82rem;
  fill: currentColor;
}

.relation-name {
  border: 0;
  background: transparent;
  color: #0f172a;
  font-weight: 700;
  line-height: 1.2;
  min-width: 0;
  overflow-wrap: anywhere;
  padding: 0;
  text-align: left;
}

.relation-name:hover,
.relation-name:focus-visible {
  color: #0f172a;
  text-decoration: underline;
  text-underline-offset: .16em;
}

.relation-meta-line {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  flex-wrap: wrap;
  font-size: .78rem;
}

.relations-table small,
.relations-table span {
  color: #667085;
  overflow-wrap: anywhere;
}

.relations-contact-lines {
  display: grid;
  gap: .22rem;
  color: #475467;
  font-size: .84rem;
  line-height: 1.3;
}

.relations-contact-lines span {
  max-width: 100%;
}

.relations-contact-lines svg {
  flex: 0 0 auto;
  color: #64748b;
}

.relations-contact-lines span span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.relation-excerpt {
  flex: 1 1 100%;
  max-width: 18rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.relations-activity {
  align-items: flex-start;
  flex-direction: column;
  gap: .15rem;
}

.relations-menu {
  position: relative;
  z-index: 3;
}

.relations-menu summary {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 6px;
  cursor: pointer;
  list-style: none;
  min-width: 2.1rem;
  min-height: 2.1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  user-select: none;
}

.relations-menu[open] {
  z-index: 20;
}

.relations-row-menu-summary {
  color: #344054;
}

.relations-icon-summary svg {
  width: 1.1rem;
  height: 1.1rem;
}

.relations-menu summary::-webkit-details-marker {
  display: none;
}

.relations-menu-panel {
  position: absolute;
  right: 0;
  z-index: 8;
  min-width: 170px;
  margin-top: .35rem;
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 14px 28px rgba(15, 23, 42, .16);
  padding: .35rem;
  display: grid;
  gap: .2rem;
}

.relations-menu-panel button {
  border: 0;
  background: transparent;
  border-radius: 6px;
  padding: .45rem .55rem;
  text-align: left;
}

.relations-menu-panel button:not(:disabled):hover {
  background: #f2f4f7;
}

.relations-menu-panel button:not(:disabled):focus-visible {
  background: #eff6ff;
  outline: 2px solid rgba(37, 99, 235, .35);
  outline-offset: 1px;
}

.relations-menu-panel button:disabled {
  color: #475467;
}

.relations-filter-panel {
  min-width: 280px;
}

.relations-filter-panel label {
  display: grid;
  gap: .25rem;
  color: #344054;
  padding: .35rem .45rem;
}

.relations-filter-panel label:has(input[type="checkbox"]) {
  grid-template-columns: auto 1fr;
  align-items: center;
}

.relations-filter-panel select {
  width: 100%;
  min-height: 2rem;
}

.relations-column-panel {
  min-width: 210px;
}

.relations-column-panel strong,
.relations-filter-panel strong {
  color: #344054;
  font-size: .82rem;
  padding: .3rem .45rem;
}

.relations-column-panel label {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: .45rem;
  align-items: center;
  border: 0;
  border-radius: 6px;
  color: #344054;
}

.relations-footer {
  color: #667085;
  font-size: .86rem;
}


@media (max-width: 860px) {
  .relations-panel {
    padding: .75rem;
  }

  .relations-toolbar,
  .relations-toolbar-buttons,
  .relations-quick-filters {
    width: 100%;
  }

  .relations-toolbar {
    grid-template-columns: 1fr;
  }

  .relations-toolbar-buttons {
    justify-content: stretch;
  }

  .relations-toolbar-buttons .relations-menu {
    flex: 1 1 0;
  }

  .relations-toolbar-buttons summary {
    width: 100%;
  }

  .relations-quick-filters {
    overflow-x: visible;
    flex-wrap: wrap;
    padding-bottom: 0;
    scrollbar-width: none;
  }

  .relations-quick-filters button {
    flex: 0 1 auto;
  }

  .relations-table {
    display: none;
  }

  .relations-cards {
    display: grid;
  }

  .relations-menu .relations-menu-panel {
    left: 0;
    right: auto;
    width: min(100%, calc(100vw - 1.5rem));
  }
}
</style>
