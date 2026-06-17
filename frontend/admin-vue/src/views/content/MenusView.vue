<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import type { MenuSummary } from '@/api/contracts';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import MenuItemNode, { type AdminLanguageLite, type MenuItemDraft, type MenuTargetType } from '@/components/admin/MenuItemNode.vue';

type TargetOption = { id: number; label: string; type: 'content_entry'|'taxonomy_term'; subtitle?: string; status?: string; url?: string };

const context = useAdminContextStore();
const rows = ref<MenuSummary[]>([]);
const loading = ref(false);
const saving = ref(false);
const targetLoading = ref(false);
const error = ref('');
const success = ref('');
const selectedKey = ref('');
const menuFilter = ref('');
const selectedUid = ref('');
const creatingMenu = ref(false);
const menuSettingsOpen = ref(false);
const menuForm = ref({ menu_key: '', name: '', menu_location: 'header', language_mode: 'shared', is_active: true });
const items = ref<MenuItemDraft[]>([]);
const advancedJsonVisible = ref(false);
const itemsText = ref('[]');
const apiLanguages = ref<AdminLanguageLite[]>([]);
const entryOptions = ref<TargetOption[]>([]);
const termOptions = ref<TargetOption[]>([]);
const targetSearch = ref('');
const dirty = ref(false);
const hydrating = ref(false);
let uidSequence = 1;

const locationOptions = ['header', 'footer_top', 'footer_bottom', 'footer', 'sidebar', 'mobile', 'custom'];
const maxDepth = 3;

const selectedSummary = computed(() => rows.value.find((row) => String(row.menu_key ?? row.key ?? '') === selectedKey.value));
const filteredRows = computed(() => {
  const q = menuFilter.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter((row) => `${row.name ?? ''} ${row.menu_key ?? row.key ?? ''} ${row.menu_location ?? ''}`.toLowerCase().includes(q));
});
const languages = computed<AdminLanguageLite[]>(() => {
  const source = apiLanguages.value.length ? apiLanguages.value : context.contentLanguages.map((lang) => ({ code: lang.code, name: lang.name, is_default: lang.is_default }));
  return source.length ? source : [{ code: context.languageCode || 'fr', name: context.languageCode || 'fr', is_default: true }];
});
const activeLanguage = computed(() => context.languageCode || languages.value[0]?.code || 'fr');
const defaultLanguage = computed(() => languages.value.find((lang) => lang.is_default)?.code || languages.value[0]?.code || activeLanguage.value);
const selectedItem = computed(() => findItemByUid(items.value, selectedUid.value));
const modeHelp = computed(() => menuForm.value.language_mode === 'localized'
  ? 'Chaque langue possède sa propre structure. Si la structure manque, le CMS peut afficher la structure partagée existante.'
  : 'La structure est commune à toutes les langues ; seuls les libellés et titres au survol sont traduits.'
);
const validationIssues = computed(() => validateItems(items.value));
const canSaveItems = computed(() => Boolean(selectedKey.value) && validationIssues.value.length === 0);
const currentTargets = computed(() => selectedItem.value?.target_type === 'term' ? filteredTargetOptions(termOptions.value) : filteredTargetOptions(entryOptions.value));

function nextUid(): string { return `menu-item-${Date.now()}-${uidSequence++}`; }
function markDirty() { if (!hydrating.value) dirty.value = true; }
function slugify(value: string): string {
  return value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
}
function ensureLocalization(item: MenuItemDraft, code: string) {
  if (!item.localizations[code]) item.localizations[code] = { label: '', title_attr: '' };
  return item.localizations[code];
}
function emptyItem(label = 'Nouveau lien', targetType: MenuTargetType = 'url'): MenuItemDraft {
  const localizations: MenuItemDraft['localizations'] = {};
  for (const lang of languages.value) localizations[lang.code] = { label: lang.code === activeLanguage.value ? label : '', title_attr: '' };
  return { uid: nextUid(), label, title_attr: '', target_type: targetType, url: targetType === 'anchor' ? '#section' : targetType === 'separator' ? '#' : '/', term_id: '', resource_type: 'content_entry', resource_id: '', css_class: targetType === 'separator' ? 'dropdown-header' : '', open_in_new_tab: targetType === 'external', is_active: true, localizations, children: [] };
}
function normalizedLocalizations(raw: Record<string, unknown>, fallbackLabel: string, fallbackTitle: string): MenuItemDraft['localizations'] {
  const result: MenuItemDraft['localizations'] = {};
  const source = raw.localizations && typeof raw.localizations === 'object' ? raw.localizations as Record<string, Record<string, unknown>> : {};
  for (const lang of languages.value) {
    const loc = source[lang.code] || {};
    result[lang.code] = {
      label: String(loc.label ?? (lang.code === activeLanguage.value ? fallbackLabel : '')),
      title_attr: String(loc.title_attr ?? (lang.code === activeLanguage.value ? fallbackTitle : ''))
    };
  }
  return result;
}
function inferTargetType(raw: Record<string, unknown>): MenuTargetType {
  if (raw.term_id) return 'term';
  if (raw.resource_type && raw.resource_id) return String(raw.resource_type) === 'taxonomy_term' ? 'term' : 'resource';
  const manualUrl = String(raw.manual_url ?? raw.url ?? '');
  if (String(raw.css_class ?? '').includes('dropdown-header') || manualUrl === '#') return 'separator';
  if (manualUrl.startsWith('#')) return 'anchor';
  if (/^https?:\/\//i.test(manualUrl)) return 'external';
  return 'url';
}
function draftFromApi(raw: Record<string, unknown>): MenuItemDraft {
  const manualUrl = String(raw.manual_url ?? raw.url ?? '');
  const termId = raw.term_id ? String(raw.term_id) : (String(raw.resource_type ?? '') === 'taxonomy_term' && raw.resource_id ? String(raw.resource_id) : '');
  const resourceType = String(raw.resource_type ?? 'content_entry');
  const resourceId = raw.resource_id && resourceType !== 'taxonomy_term' ? String(raw.resource_id) : '';
  const fallbackLabel = String(raw.label ?? raw.title ?? '');
  const fallbackTitle = String(raw.title_attr ?? '');
  const children = Array.isArray(raw.children) ? raw.children.filter((child): child is Record<string, unknown> => Boolean(child) && typeof child === 'object').map(draftFromApi) : [];
  return {
    uid: nextUid(),
    label: fallbackLabel,
    title_attr: fallbackTitle,
    target_type: inferTargetType(raw),
    url: manualUrl || '/',
    term_id: termId,
    resource_type: resourceType === 'taxonomy_term' ? 'content_entry' : resourceType,
    resource_id: resourceId,
    css_class: String(raw.css_class ?? ''),
    open_in_new_tab: Number(raw.open_in_new_tab ?? 0) === 1 || raw.open_in_new_tab === true,
    is_active: Number(raw.is_active ?? 1) === 1 || raw.is_active === true,
    target_label: String(raw.target_label ?? ''),
    target_status: String(raw.target_status ?? ''),
    target_missing: Number(raw.target_missing ?? 0) === 1 || raw.target_missing === true,
    localizations: normalizedLocalizations(raw, fallbackLabel, fallbackTitle),
    children
  };
}
function apiFromDraft(draft: MenuItemDraft, index = 0): Record<string, unknown> {
  const current = draft.localizations[activeLanguage.value] || { label: draft.label, title_attr: draft.title_attr };
  const payload: Record<string, unknown> = {
    label: String(current.label || draft.label).trim(),
    title_attr: String(current.title_attr || draft.title_attr).trim() || null,
    localizations: draft.localizations,
    css_class: draft.css_class.trim() || null,
    open_in_new_tab: draft.open_in_new_tab,
    is_active: draft.is_active,
    sort_order: (index + 1) * 10,
    children: draft.children.map(apiFromDraft)
  };
  if (draft.target_type === 'term') payload.term_id = Number(draft.term_id || 0);
  else if (draft.target_type === 'resource') { payload.resource_type = draft.resource_type.trim() || 'content_entry'; payload.resource_id = Number(draft.resource_id || 0); }
  else if (draft.target_type === 'separator') payload.url = '#';
  else payload.url = draft.url.trim() || (draft.target_type === 'anchor' ? '#section' : '/');
  return payload;
}
function syncJsonFromItems() { itemsText.value = JSON.stringify(items.value.map(apiFromDraft), null, 2); }
function syncItemsFromJson(): boolean {
  try {
    const parsed = JSON.parse(itemsText.value);
    if (!Array.isArray(parsed)) throw new Error('root must be an array');
    items.value = parsed.filter((item): item is Record<string, unknown> => Boolean(item) && typeof item === 'object').map(draftFromApi);
    selectedUid.value = items.value[0]?.uid || '';
    markDirty();
    return true;
  } catch {
    error.value = 'Le JSON avancé des items est invalide.';
    return false;
  }
}
function validateItems(list: MenuItemDraft[], path = 'Menu'): string[] {
  const issues: string[] = [];
  list.forEach((item, index) => {
    const label = ensureLocalization(item, defaultLanguage.value).label.trim() || item.label.trim();
    const itemPath = `${path} > item ${index + 1}`;
    if (item.is_active && label === '') issues.push(`${itemPath} : libellé obligatoire en langue par défaut (${defaultLanguage.value.toUpperCase()}).`);
    if (item.is_active && item.target_type === 'resource' && Number(item.resource_id || 0) <= 0) issues.push(`${itemPath} : choisissez un contenu CMS.`);
    if (item.is_active && item.target_type === 'term' && Number(item.term_id || 0) <= 0) issues.push(`${itemPath} : choisissez un terme de taxonomie.`);
    if (item.is_active && ['url', 'external', 'anchor'].includes(item.target_type) && item.url.trim() === '') issues.push(`${itemPath} : renseignez une URL ou une ancre.`);
    if (item.children.length) issues.push(...validateItems(item.children, itemPath));
  });
  return issues;
}
function findItemByUid(list: MenuItemDraft[], uid: string): MenuItemDraft|null {
  for (const item of list) {
    if (item.uid === uid) return item;
    const child = findItemByUid(item.children, uid);
    if (child) return child;
  }
  return null;
}
function findParent(list: MenuItemDraft[], uid: string, parent: MenuItemDraft|null = null): { parent: MenuItemDraft|null; list: MenuItemDraft[]; index: number }|null {
  for (let index = 0; index < list.length; index++) {
    if (list[index].uid === uid) return { parent, list, index };
    const found = findParent(list[index].children, uid, list[index]);
    if (found) return found;
  }
  return null;
}
function filteredTargetOptions(source: TargetOption[]): TargetOption[] {
  const q = targetSearch.value.trim().toLowerCase();
  if (!q) return source.slice(0, 60);
  return source.filter((option) => `${option.label} ${option.subtitle ?? ''} ${option.id}`.toLowerCase().includes(q)).slice(0, 60);
}
function targetOptionValue(option: TargetOption): string { return `${option.type}:${option.id}`; }
function selectedTargetValue(item: MenuItemDraft): string {
  return item.target_type === 'term' ? `taxonomy_term:${item.term_id}` : `${item.resource_type || 'content_entry'}:${item.resource_id}`;
}
function applyTargetValue(value: string) {
  const item = selectedItem.value;
  if (!item) return;
  const [type, id] = value.split(':');
  if (type === 'taxonomy_term') {
    item.target_type = 'term'; item.term_id = id; item.resource_id = ''; item.resource_type = 'content_entry';
    const target = termOptions.value.find((option) => String(option.id) === id);
    item.target_label = target?.label || '';
  } else {
    item.target_type = 'resource'; item.resource_type = type || 'content_entry'; item.resource_id = id; item.term_id = '';
    const target = entryOptions.value.find((option) => String(option.id) === id);
    item.target_label = target?.label || '';
    item.target_status = target?.status || '';
  }
  markDirty(); syncJsonFromItems();
}
function selectItem(item: MenuItemDraft) { selectedUid.value = item.uid; targetSearch.value = ''; }
function addItem(parent?: MenuItemDraft, targetType: MenuTargetType = 'url') {
  const item = emptyItem(targetType === 'separator' ? 'Groupe' : 'Nouveau lien', targetType);
  (parent ? parent.children : items.value).push(item);
  selectItem(item); markDirty(); syncJsonFromItems();
}
function removeItem(list: MenuItemDraft[], index: number) {
  if (!confirm('Supprimer cet item et ses éventuels sous-items ?')) return;
  const [removed] = list.splice(index, 1);
  if (removed?.uid === selectedUid.value) selectedUid.value = items.value[0]?.uid || '';
  markDirty(); syncJsonFromItems();
}
function moveItem(list: MenuItemDraft[], index: number, direction: -1|1) {
  const next = index + direction;
  if (next < 0 || next >= list.length) return;
  const [item] = list.splice(index, 1);
  list.splice(next, 0, item);
  markDirty(); syncJsonFromItems();
}
function itemDepth(item: MenuItemDraft): number {
  const found = findParent(items.value, item.uid);
  let depth = 0; let parent = found?.parent || null;
  while (parent) { depth++; parent = findParent(items.value, parent.uid)?.parent || null; }
  return depth;
}
function indentItem(list: MenuItemDraft[], index: number) {
  if (index <= 0) return;
  const item = list[index];
  if (itemDepth(item) >= maxDepth - 1) return;
  const previous = list[index - 1];
  list.splice(index, 1);
  previous.children.push(item);
  markDirty(); syncJsonFromItems();
}
function outdentItem(list: MenuItemDraft[], index: number) {
  const item = list[index];
  const found = findParent(items.value, item.uid);
  if (!found?.parent) return;
  const parentFound = findParent(items.value, found.parent.uid);
  if (!parentFound) return;
  list.splice(index, 1);
  parentFound.list.splice(parentFound.index + 1, 0, item);
  markDirty(); syncJsonFromItems();
}
function selectTargetType(type: MenuTargetType) {
  const item = selectedItem.value;
  if (!item) return;
  item.target_type = type;
  if (type === 'resource') { item.resource_type = 'content_entry'; item.term_id = ''; }
  if (type === 'term') { item.resource_id = ''; }
  if (type === 'external' && !/^https?:\/\//i.test(item.url)) item.url = 'https://';
  if (type === 'anchor' && !item.url.startsWith('#')) item.url = '#section';
  if (type === 'separator') { item.url = '#'; item.open_in_new_tab = false; if (!item.css_class.includes('dropdown-header')) item.css_class = `${item.css_class} dropdown-header`.trim(); }
  markDirty(); syncJsonFromItems();
}

function localizationValue(item: MenuItemDraft, code: string, field: 'label'|'title_attr'): string {
  return ensureLocalization(item, code)[field];
}
function setLocalizationValue(item: MenuItemDraft, code: string, field: 'label'|'title_attr', value: string) {
  ensureLocalization(item, code)[field] = value;
  updateItemText();
}
function onTargetTypeChange(event: Event) { selectTargetType((event.target as HTMLSelectElement).value as MenuTargetType); }
function onTargetValueChange(event: Event) { applyTargetValue((event.target as HTMLSelectElement).value); }
function onJsonDetailsToggle(event: Event) { advancedJsonVisible.value = (event.target as HTMLDetailsElement).open; syncJsonFromItems(); }
function inputValue(event: Event): string { return (event.target as HTMLInputElement).value; }

function updateItemText() {
  const item = selectedItem.value;
  if (!item) return;
  const current = ensureLocalization(item, activeLanguage.value);
  item.label = current.label;
  item.title_attr = current.title_attr;
  markDirty(); syncJsonFromItems();
}
async function loadTargets() {
  targetLoading.value = true;
  try {
    const [entries, terms] = await Promise.all([
      adminApi.get<Record<string, unknown>[]>('/entries', { lang: activeLanguage.value, limit: 100, sort: 'title' }),
      adminApi.get<Record<string, unknown>[]>('/taxonomy-terms', { lang: activeLanguage.value })
    ]);
    entryOptions.value = (Array.isArray(entries.data) ? entries.data : []).map((row) => ({
      id: Number(row.id || 0), type: 'content_entry' as const, label: String(row.title || row.entry_key || `Contenu #${row.id}`), subtitle: `${row.content_type_key || 'contenu'} · ${row.full_path || row.entry_key || ''}`, status: String(row.status || ''), url: String(row.full_path || '')
    })).filter((row) => row.id > 0);
    termOptions.value = (Array.isArray(terms.data) ? terms.data : []).map((row) => ({
      id: Number(row.id || 0), type: 'taxonomy_term' as const, label: String(row.name || row.term_key || `Terme #${row.id}`), subtitle: `${row.taxonomy_key || 'taxonomie'} · ${row.full_path || row.term_key || ''}`, status: Number(row.is_active ?? 1) === 1 ? 'active' : 'inactive', url: String(row.full_path || '')
    })).filter((row) => row.id > 0);
  } catch (e) {
    error.value = apiErrorMessage(e, 'Cibles indisponibles.');
  } finally { targetLoading.value = false; }
}
async function load() {
  loading.value = true; error.value = ''; success.value = '';
  try { const r = await adminApi.get<MenuSummary[]>('/menus'); rows.value = Array.isArray(r.data) ? r.data : []; }
  catch(e) { error.value = apiErrorMessage(e, 'Menus indisponibles.'); }
  finally { loading.value = false; }
}
async function show(rowOrKey: Record<string, unknown>|string, force = false) {
  if (!force && dirty.value && !confirm('Des changements ne sont pas enregistrés. Changer de menu sans sauvegarder ?')) return;
  const key = typeof rowOrKey === 'string' ? rowOrKey : String(rowOrKey.menu_key ?? rowOrKey.key ?? '');
  if (!key) return;
  hydrating.value = true; loading.value = true; error.value = ''; success.value = '';
  try {
    const r = await adminApi.get<Record<string, unknown>>(`/menus/${key}`, { lang: activeLanguage.value });
    selectedKey.value = key;
    creatingMenu.value = false;
    menuSettingsOpen.value = false;
    if (Array.isArray(r.data.languages)) apiLanguages.value = r.data.languages as AdminLanguageLite[];
    const menu = (r.data.menu || {}) as Record<string, unknown>;
    menuForm.value = {
      menu_key: String(menu.menu_key ?? key),
      name: String(menu.name ?? ''),
      menu_location: String(menu.menu_location ?? 'header'),
      language_mode: String(menu.language_mode ?? 'shared'),
      is_active: Number(menu.is_active ?? 1) === 1
    };
    const rawItems = Array.isArray(r.data.items) ? r.data.items.filter((item): item is Record<string, unknown> => Boolean(item) && typeof item === 'object') : [];
    items.value = rawItems.map(draftFromApi);
    selectedUid.value = '';
    syncJsonFromItems();
    await nextTick();
    dirty.value = false;
  } catch(e) { error.value = apiErrorMessage(e, 'Menu indisponible.'); }
  finally { loading.value = false; hydrating.value = false; dirty.value = false; }
}
function resetForm() {
  if (dirty.value && !confirm('Des changements ne sont pas enregistrés. Créer un nouveau menu ?')) return;
  hydrating.value = true;
  selectedKey.value = '';
  creatingMenu.value = true;
  menuSettingsOpen.value = true;
  menuForm.value = { menu_key: '', name: '', menu_location: 'header', language_mode: 'shared', is_active: true };
  items.value = [];
  selectedUid.value = '';
  syncJsonFromItems(); dirty.value = false;
  nextTick(() => { hydrating.value = false; });
}
async function saveMenu() {
  saving.value = true; error.value = ''; success.value = '';
  try {
    if (!menuForm.value.menu_key.trim() && menuForm.value.name.trim()) menuForm.value.menu_key = slugify(menuForm.value.name);
    const payload = { ...menuForm.value, menu_key: slugify(menuForm.value.menu_key || menuForm.value.name) };
    const r = selectedKey.value ? await adminApi.patch<MenuSummary>(`/menus/${selectedKey.value}`, payload) : await adminApi.post<MenuSummary>('/menus', payload);
    selectedKey.value = String((r.data as Record<string, unknown>).menu_key ?? payload.menu_key);
    success.value = 'Menu enregistré.';
    dirty.value = false; await load(); await show(selectedKey.value, true); menuSettingsOpen.value = false; dirty.value = false;
  } catch(e) { error.value = apiErrorMessage(e, 'Enregistrement impossible.'); }
  finally { saving.value = false; }
}
async function saveItems() {
  if (!selectedKey.value) return;
  if (advancedJsonVisible.value && !syncItemsFromJson()) return;
  const issues = validateItems(items.value);
  if (issues.length) { error.value = issues[0]; return; }
  saving.value = true; error.value = ''; success.value = '';
  try {
    await adminApi.put(`/menus/${selectedKey.value}/items?lang=${encodeURIComponent(activeLanguage.value)}`, { items: items.value.map(apiFromDraft) });
    success.value = 'Structure, cibles et traductions enregistrées.';
    await show(selectedKey.value, true); dirty.value = false;
  } catch(e) { error.value = apiErrorMessage(e, 'Enregistrement impossible.'); }
  finally { saving.value = false; }
}
async function deleteMenu() {
  if (!selectedKey.value || !confirm('Supprimer ce menu et tous ses items ?')) return;
  saving.value = true; error.value = ''; success.value = '';
  try { await adminApi.delete(`/menus/${selectedKey.value}`); success.value = 'Menu supprimé.'; dirty.value = false; resetForm(); await load(); }
  catch(e) { error.value = apiErrorMessage(e, 'Suppression impossible.'); }
  finally { saving.value = false; }
}

onMounted(async () => { await Promise.all([load(), loadTargets()]); dirty.value = false; });
watch(() => context.languageCode, async () => { await loadTargets(); if (selectedKey.value) { dirty.value = false; await show(selectedKey.value, true); } });
watch(() => menuForm.value.name, (name) => {
  if (hydrating.value) return;
  if (!selectedKey.value && !menuForm.value.menu_key.trim()) menuForm.value.menu_key = slugify(name);
  markDirty();
});
watch(menuForm, () => { if (!hydrating.value) markDirty(); }, { deep: true, flush: 'post' });
</script>

<template>
  <PageHeader title="Menus" intro="Builder de navigation : choisissez les cibles et organisez l’arborescence sans manipuler les IDs à la main.">
    <template #actions>
      <span v-if="dirty" class="badge text-bg-warning">Modifications non enregistrées</span>
      <button class="btn btn-outline-secondary" :disabled="loading" @click="load">Rafraîchir</button>
      <button class="btn btn-primary" @click="resetForm">Nouveau menu</button>
    </template>
  </PageHeader>
  <ApiFeedback :error="error" :message="success" />

  <div class="menu-builder-admin menu-builder-layout">
    <section class="menu-builder-layout__left" aria-label="Colonne gauche : liste des menus">
      <div class="card h-100 overflow-hidden">
        <div class="card-header">
          <p class="eyebrow mb-1">Colonne gauche · Menus</p>
          <div class="d-flex justify-content-between align-items-center gap-2">
            <strong>{{ rows.length }} configuration(s)</strong>
            <span class="badge text-bg-light">{{ activeLanguage.toUpperCase() }}</span>
          </div>
          <input v-model="menuFilter" class="form-control form-control-sm mt-3" placeholder="Rechercher un menu…" />
        </div>
        <div class="list-group list-group-flush menu-builder-list">
          <button
            v-for="row in filteredRows"
            :key="String(row.menu_key ?? row.key)"
            type="button"
            class="list-group-item list-group-item-action menu-builder-menu-button"
            :class="{ active: String(row.menu_key ?? row.key) === selectedKey }"
            @click="show(row as unknown as Record<string, unknown>)"
          >
            <span class="d-flex justify-content-between align-items-start gap-2">
              <strong>{{ row.name || row.menu_key || row.key }}</strong>
              <span class="badge rounded-pill text-bg-light menu-builder-menu-count">{{ (row as Record<string, unknown>).item_count ?? 0 }}</span>
            </span>
            <small class="d-block opacity-75 menu-builder-menu-meta"><code>{{ row.menu_key || row.key }}</code> · {{ row.menu_location || '—' }}</small>
          </button>
          <p v-if="!loading && filteredRows.length === 0" class="p-3 text-muted mb-0">Aucun menu trouvé.</p>
          <p v-if="loading" class="p-3 text-muted mb-0">Chargement…</p>
        </div>
      </div>
    </section>

    <section class="menu-builder-layout__center" aria-label="Zone centrale : réglages et arborescence">
      <div v-if="creatingMenu" class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div><p class="eyebrow mb-1">Centre · Créer un menu</p><h2 class="h5 mb-0">{{ menuForm.name || 'Menu de navigation' }}</h2></div>
          <div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary" :disabled="saving" @click="saveMenu">Enregistrer le menu</button><button v-if="selectedKey" class="btn btn-outline-danger" :disabled="saving" @click="deleteMenu">Supprimer</button></div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <label class="col-12 col-md-6 form-field">Nom affiché <input v-model="menuForm.name" class="form-control" placeholder="Navigation principale" /></label>
            <label class="col-12 col-md-6 form-field">Clé technique <input v-model="menuForm.menu_key" class="form-control" placeholder="header" @input="menuForm.menu_key = slugify(menuForm.menu_key)" /><small class="form-text">Auto-générée depuis le nom, modifiable.</small></label>
            <label class="col-12 col-md-6 form-field">Emplacement <select v-model="menuForm.menu_location" class="form-select"><option v-for="option in locationOptions" :key="option" :value="option">{{ option }}</option></select></label>
            <label class="col-12 col-md-6 form-field">Mode langue <select v-model="menuForm.language_mode" class="form-select"><option value="shared">Structure partagée, libellés traduits</option><option value="localized">Structure propre par langue</option></select><small class="form-text">{{ modeHelp }}</small></label>
            <div class="col-12"><label class="form-check form-switch"><input v-model="menuForm.is_active" class="form-check-input" type="checkbox" /><span class="form-check-label">Menu actif</span></label></div>
          </div>
        </div>
      </div>

      <div v-if="selectedKey && !creatingMenu && menuSettingsOpen" class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div><p class="eyebrow mb-1">Paramètres du menu</p><h2 class="h5 mb-0">{{ menuForm.name || selectedKey }}</h2></div>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" :disabled="saving" @click="saveMenu">Enregistrer les paramètres</button>
            <button class="btn btn-outline-secondary" type="button" @click="menuSettingsOpen = false">Fermer</button>
            <button class="btn btn-outline-danger" :disabled="saving" @click="deleteMenu">Supprimer</button>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <label class="col-12 col-md-6 form-field">Nom affiché <input v-model="menuForm.name" class="form-control" placeholder="Navigation principale" /></label>
            <label class="col-12 col-md-6 form-field">Clé technique <input v-model="menuForm.menu_key" class="form-control" placeholder="header" @input="menuForm.menu_key = slugify(menuForm.menu_key)" /><small class="form-text">Modifiable avec prudence : cette clé peut être utilisée dans les templates.</small></label>
            <label class="col-12 col-md-6 form-field">Emplacement <select v-model="menuForm.menu_location" class="form-select"><option v-for="option in locationOptions" :key="option" :value="option">{{ option }}</option></select></label>
            <label class="col-12 col-md-6 form-field">Mode langue <select v-model="menuForm.language_mode" class="form-select"><option value="shared">Structure partagée, libellés traduits</option><option value="localized">Structure propre par langue</option></select><small class="form-text">{{ modeHelp }}</small></label>
            <div class="col-12"><label class="form-check form-switch"><input v-model="menuForm.is_active" class="form-check-input" type="checkbox" /><span class="form-check-label">Menu actif</span></label></div>
          </div>
        </div>
      </div>

      <div class="card" :class="{ 'opacity-75': !selectedKey }">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <p class="eyebrow mb-1">Centre · Structure du menu</p>
            <h2 class="h5 mb-0 d-inline-flex align-items-center gap-1">
              {{ selectedSummary?.name || selectedKey || 'Items du menu' }}
              <InfoHint :text="`Maximum ${maxDepth} niveaux. Utilisez → et ← pour créer ou remonter un sous-menu.`" />
            </h2>
          </div>
          <div class="menu-builder-structure-toolbar">
            <div class="menu-builder-structure-toolbar__primary d-flex gap-2 flex-wrap">
              <button v-if="selectedKey && !creatingMenu" class="btn btn-outline-primary" type="button" @click="menuSettingsOpen = !menuSettingsOpen">{{ menuSettingsOpen ? 'Masquer les paramètres' : 'Modifier le menu' }}</button>
              <button class="btn btn-primary" :disabled="saving || !canSaveItems" @click="saveItems">Enregistrer les items</button>
            </div>
            <div class="btn-group menu-builder-structure-toolbar__secondary">
              <button class="btn btn-outline-secondary" :disabled="!selectedKey" @click="addItem(undefined, 'resource')">Ajouter un lien · Page</button>
              <button class="btn btn-outline-secondary" :disabled="!selectedKey" @click="addItem(undefined, 'url')">Ajouter un lien · URL</button>
              <button class="btn btn-outline-secondary" :disabled="!selectedKey" @click="addItem(undefined, 'separator')">Ajouter un lien · Groupe</button>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div v-if="!selectedKey" class="alert alert-warning mb-0">Enregistrez ou sélectionnez d’abord un menu pour éditer sa structure.</div>
          <template v-else>
            <div v-if="validationIssues.length" class="alert alert-danger"><strong>À corriger avant sauvegarde</strong><br />{{ validationIssues[0] }}</div>
            <div v-if="items.length === 0" class="empty-state border rounded p-4 text-center text-muted">Aucun item. Ajoutez le premier lien de navigation.</div>
            <MenuItemNode v-for="(item, index) in items" :key="item.uid" :item="item" :list="items" :index="index" :active-language="activeLanguage" :selected-uid="selectedUid" :max-depth="maxDepth" @select="selectItem" @add-child="(parent) => addItem(parent, 'url')" @remove="removeItem" @move="moveItem" @indent="indentItem" @outdent="outdentItem" />
            <details class="mt-3" @toggle="onJsonDetailsToggle">
              <summary class="btn btn-link px-0">Développeur / JSON avancé</summary>
              <textarea v-model="itemsText" class="form-control code-area" rows="10" @input="dirty = true" />
              <button class="btn btn-sm btn-outline-secondary mt-2" @click="syncItemsFromJson">Appliquer ce JSON à l’éditeur</button>
            </details>
          </template>
        </div>
      </div>
    </section>

    <aside class="menu-builder-layout__right" aria-label="Colonne droite : édition et aperçu">
      <div v-if="selectedItem" class="card sticky-xl-top menu-builder-side">
        <div class="card-header"><p class="eyebrow mb-1">Colonne droite · Item sélectionné</p><h2 class="h5 mb-0">{{ selectedItem.localizations[activeLanguage]?.label || selectedItem.label || 'Sans libellé' }}</h2></div>
        <div class="card-body">
          <div class="d-grid gap-3">
            <label v-for="lang in languages" :key="lang.code" class="form-field">Libellé {{ lang.code.toUpperCase() }} <input :value="localizationValue(selectedItem, lang.code, 'label')" class="form-control" :placeholder="lang.is_default ? 'Obligatoire pour un item actif' : 'Traduction optionnelle'" @input="setLocalizationValue(selectedItem, lang.code, 'label', inputValue($event))" /></label>
            <label class="form-field">Type de lien
              <select :value="selectedItem.target_type" class="form-select" @change="onTargetTypeChange">
                <option value="resource">Page / contenu CMS</option>
                <option value="url">URL interne manuelle</option>
                <option value="external">URL externe</option>
                <option value="anchor">Ancre de page</option>
                <option value="term">Terme de taxonomie</option>
                <option value="separator">Titre de groupe / séparateur</option>
              </select>
            </label>
            <template v-if="selectedItem.target_type === 'resource' || selectedItem.target_type === 'term'">
              <input v-model="targetSearch" class="form-control" :placeholder="targetLoading ? 'Chargement…' : 'Rechercher une cible…'" />
              <select class="form-select" :value="selectedTargetValue(selectedItem)" @change="onTargetValueChange">
                <option value="">Choisir…</option>
                <option v-for="target in currentTargets" :key="targetOptionValue(target)" :value="targetOptionValue(target)">{{ target.label }} — {{ target.subtitle }}</option>
              </select>
              <div v-if="selectedItem.target_missing" class="alert alert-warning py-2 mb-0">La cible enregistrée semble introuvable ou indisponible. L’item reste éditable.</div>
            </template>
            <label v-if="['url','external','anchor'].includes(selectedItem.target_type)" class="form-field">URL / ancre <input v-model="selectedItem.url" class="form-control" placeholder="/contact, https://…, #section" @input="markDirty(); syncJsonFromItems()" /></label>
            <label class="form-field">Titre au survol {{ activeLanguage.toUpperCase() }} <input :value="localizationValue(selectedItem, activeLanguage, 'title_attr')" class="form-control" placeholder="Optionnel" @input="setLocalizationValue(selectedItem, activeLanguage, 'title_attr', inputValue($event))" /></label>
            <div class="d-flex gap-3 flex-wrap">
              <label class="form-check"><input v-model="selectedItem.is_active" class="form-check-input" type="checkbox" @change="markDirty(); syncJsonFromItems()" /><span class="form-check-label">Actif</span></label>
              <label class="form-check"><input v-model="selectedItem.open_in_new_tab" class="form-check-input" type="checkbox" @change="markDirty(); syncJsonFromItems()" /><span class="form-check-label">Nouvel onglet</span></label>
            </div>
            <details>
              <summary>Options avancées</summary>
              <label class="form-field mt-2">Classe CSS Bootstrap <input v-model="selectedItem.css_class" class="form-control" placeholder="nav-link btn btn-primary" @input="markDirty(); syncJsonFromItems()" /></label>
              <div class="row g-2 mt-1">
                <label class="col-6 form-field">resource_type <input v-model="selectedItem.resource_type" class="form-control" @input="markDirty(); syncJsonFromItems()" /></label>
                <label class="col-6 form-field">resource_id <input v-model="selectedItem.resource_id" class="form-control" inputmode="numeric" @input="markDirty(); syncJsonFromItems()" /></label>
                <label class="col-6 form-field">term_id <input v-model="selectedItem.term_id" class="form-control" inputmode="numeric" @input="markDirty(); syncJsonFromItems()" /></label>
              </div>
            </details>
          </div>
        </div>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.menu-builder-layout { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: start; }
.menu-builder-layout__left, .menu-builder-layout__center, .menu-builder-layout__right { min-width: 0; }
@media (min-width: 992px) {
  .menu-builder-layout { grid-template-columns: minmax(220px, 280px) minmax(420px, 1fr) minmax(280px, 360px); }
  .menu-builder-layout__left, .menu-builder-layout__right { position: sticky; top: 1rem; }
}
@media (min-width: 1400px) {
  .menu-builder-layout { grid-template-columns: minmax(240px, 310px) minmax(520px, 1fr) minmax(320px, 390px); }
}
.menu-builder-list { max-height: 68vh; overflow: auto; }
.menu-builder-menu-button strong { overflow-wrap: anywhere; }
.menu-builder-menu-count { min-width: 2rem; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; }
.menu-builder-menu-meta code { font-size: inherit; }
.list-group-item.active .menu-builder-menu-count { background: #fff !important; color: var(--bs-primary) !important; border: 1px solid rgba(var(--bs-primary-rgb), .18); }
.list-group-item.active .menu-builder-menu-meta,
.list-group-item.active .menu-builder-menu-meta code { color: rgba(255, 255, 255, .92); }
.menu-builder-structure-toolbar { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: .75rem; }
.menu-builder-structure-toolbar__primary { flex: 0 0 auto; }
.menu-builder-structure-toolbar__secondary { flex: 0 1 auto; }
.menu-builder-node { margin-bottom: .5rem; }
.menu-builder-children { margin-left: 1.25rem; padding-left: .75rem; border-left: 1px dashed var(--bs-border-color); }
.menu-builder-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .65rem .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); cursor: pointer; }
.menu-builder-row:hover, .menu-builder-node.is-selected > .menu-builder-row { border-color: var(--bs-primary); box-shadow: 0 0 0 .15rem rgba(var(--bs-primary-rgb), .12); }
.menu-builder-node.is-muted > .menu-builder-row { opacity: .62; }
.menu-builder-row__main { min-width: 0; display: flex; align-items: center; gap: .65rem; }
.menu-builder-row__main strong, .menu-builder-row__main small { overflow-wrap: anywhere; }
.menu-builder-row__indent { color: var(--bs-secondary-color); font-weight: 700; }
.menu-builder-row__tools { display: flex; align-items: center; justify-content: flex-end; gap: .35rem; flex-wrap: wrap; }
.menu-builder-side { top: 1rem; }
.code-area { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
@media (max-width: 991.98px) {
  .menu-builder-row { align-items: flex-start; flex-direction: column; }
  .menu-builder-row__tools { justify-content: flex-start; }
  .menu-builder-children { margin-left: .5rem; }
  .menu-builder-structure-toolbar { justify-content: flex-start; }
  .menu-builder-structure-toolbar__secondary { width: 100%; }
}
</style>
