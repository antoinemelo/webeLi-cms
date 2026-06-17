<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useAdminContextStore } from '@/stores/adminContext';
import { adminApi, apiErrorMessage } from '@/api/client';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';

type ExportStatus = 'pending' | 'running' | 'succeeded' | 'partial' | 'failed' | 'skipped' | string;

type ExportRouteSuggestion = {
  id?: number;
  site_id?: number;
  site?: string;
  language_code?: string;
  path: string;
  label?: string;
  type?: string;
  route_type?: string;
};

type ExportRelease = {
  release_id?: string;
  status?: ExportStatus;
  site?: string;
  languages?: string[];
  mode?: string;
  exported_routes?: number;
  errors_count?: number;
  warnings_count?: number;
  generated_at?: string;
  output_path?: string;
  links?: { manifest?: string; report?: string; zip?: string; static_zip?: string; editorial_zip?: string };
  artifacts?: { static?: { filename?: string; sha256?: string; size?: number }; editorial?: { filename?: string; sha256?: string; size?: number; format?: string; format_version?: string } };
};


type EditorialImportHistory = {
  import_id?: string;
  status?: string;
  mode?: string;
  site_id?: number;
  finished_at?: string;
  contents_created?: unknown[] | number;
  contents_updated?: unknown[] | number;
  contents_ignored?: unknown[] | number;
  errors?: string[];
  warnings?: string[];
  errors_count?: number;
  warnings_count?: number;
};

type EditorialImportPlan = {
  inspection?: { format?: string; format_version?: string; source_site?: string; target_site?: string; languages?: string[]; scope?: unknown };
  modes?: Record<string, Record<string, unknown>>;
  contents?: { to_create?: unknown[]; existing?: unknown[]; conflicts?: unknown[] };
  blueprints?: { existing?: unknown[]; to_import?: unknown[]; incompatible?: unknown[] };
  media?: { new?: unknown[]; existing_by_sha256?: unknown[] };
  routes?: { conflicts?: unknown[] };
  relations?: { unresolved?: unknown[] };
  warnings?: string[];
  blocking_errors?: string[];
  can_execute_later?: boolean;
  writes_performed?: boolean;
};

type StaticExportsPayload = {
  last_export?: ExportRelease | null;
  history?: ExportRelease[];
  import_history?: EditorialImportHistory[];
  suggestions?: {
    routes?: ExportRouteSuggestion[];
    languages?: Array<{ code: string; label?: string; url_prefix?: string; default?: boolean }>;
    current_site?: string;
    current_site_id?: number;
    current_language?: string;
    fallback_all_languages?: boolean;
    empty_message?: string;
  };
};

const context = useAdminContextStore();
const loading = ref(false);
const runningAction = ref('');
const error = ref('');
const message = ref('');
const feedbackNonce = ref(0);
const routePath = ref('');
const payload = ref<StaticExportsPayload>({});
const routeInputFocused = ref(false);
const activeRouteSuggestionIndex = ref(-1);
const routeInputName = `static-export-route-${Math.random().toString(36).slice(2)}`;
const canReadImportsExports = computed(() => context.can('imports_exports.read') || context.can('imports_exports.write') || context.can('imports_exports.manage'));
const canWriteImportsExports = computed(() => context.can('imports_exports.write') || context.can('imports_exports.manage'));
const canManageImportsExports = computed(() => context.can('imports_exports.manage'));
const importArchive = ref<File | null>(null);
const importArchiveInput = ref<HTMLInputElement | null>(null);
const importPlan = ref<EditorialImportPlan | null>(null);
const inspectingImport = ref(false);
const executingImport = ref(false);
const importToken = ref('');
const importMode = ref<'create'|'upsert'|'replace'>('create');
const importReport = ref<Record<string, unknown> | null>(null);

const suggestions = computed(() => payload.value.suggestions?.routes ?? []);
const lastExport = computed(() => payload.value.last_export ?? null);
const history = computed(() => payload.value.history ?? []);
const importHistory = computed(() => payload.value.import_history ?? []);
const emptySuggestionMessage = computed(() => payload.value.suggestions?.empty_message || 'Aucune page publiée disponible pour ce site ou cette langue.');
const fallbackAllLanguages = computed(() => Boolean(payload.value.suggestions?.fallback_all_languages));

const filteredRouteSuggestions = computed(() => {
  const query = normalizeRoutePath(routePath.value);
  const rows = suggestions.value;
  if (!query || query === '/') {
    return rows.slice(0, 30);
  }
  return rows
    .filter((route) => normalizeRoutePath(route.path).startsWith(query))
    .slice(0, 40);
});
const showRouteSuggestions = computed(() => routeInputFocused.value && filteredRouteSuggestions.value.length > 0);

const statusClass = computed(() => lastExport.value?.status || 'skipped');
const lastLanguageCount = computed(() => (lastExport.value?.languages ?? []).filter(Boolean).length);
const lastLanguages = computed(() => (lastExport.value?.languages ?? []).join(', ') || '—');
const lastSiteLanguageLabel = computed(() => lastLanguageCount.value === 1 ? 'Site / Langue' : 'Site / Langues');

function queryContext() {
  return {
    site_id: context.siteId,
    lang: context.languageCode
  };
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<StaticExportsPayload>('/imports-exports', queryContext());
    payload.value = response.data;
  } catch (err) {
    error.value = apiErrorMessage(err, 'Impossible de charger les exports statiques.');
  } finally {
    loading.value = false;
  }
}

async function run(action: 'page' | 'language' | 'site' | 'rerun') {
  if (runningAction.value) return;
  error.value = '';
  message.value = '';
  feedbackNonce.value += 1;
  if (action === 'page' && routePath.value.trim() === '') {
    error.value = 'Choisissez une page publiée ou indiquez un chemin public.';
    return;
  }
  runningAction.value = action;
  try {
    const endpoint = `/imports-exports/static/actions/${action}`;
    const response = await adminApi.post<{ message?: string }>(endpoint, {
      site_id: context.siteId,
      lang: context.languageCode,
      route: normalizeRoutePath(routePath.value)
    });
    message.value = response.data?.message || 'Export terminé.';
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, 'Export impossible.');
  } finally {
    runningAction.value = '';
  }
}

async function deleteRelease(releaseId: unknown) {
  const id = String(releaseId || '').trim();
  if (!id || runningAction.value) return;
  if (!window.confirm(`Supprimer définitivement la release statique ${id} ?`)) return;
  error.value = '';
  message.value = '';
  feedbackNonce.value += 1;
  runningAction.value = `delete:${id}`;
  try {
    const response = await adminApi.delete<{ message?: string }>(`/imports-exports/static/${encodeURIComponent(id)}`);
    message.value = response.data?.message || 'Release statique supprimée.';
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, 'Suppression impossible.');
  } finally {
    runningAction.value = '';
  }
}



async function deleteImportHistory(importId: unknown) {
  const id = String(importId || '').trim();
  if (!id || runningAction.value || !canManageImportsExports.value) return;
  if (!window.confirm(`Supprimer définitivement l’historique d’import ${id} ?`)) return;
  error.value = '';
  message.value = '';
  feedbackNonce.value += 1;
  runningAction.value = `delete-import:${id}`;
  try {
    const response = await adminApi.delete<{ message?: string }>(`/imports-exports/imports/${encodeURIComponent(id)}`);
    message.value = response.data?.message || 'Historique d’import supprimé.';
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, 'Suppression de l’historique d’import impossible.');
  } finally {
    runningAction.value = '';
  }
}


function resetImportPreparation() {
  importArchive.value = null;
  importPlan.value = null;
  importToken.value = '';
  importReport.value = null;
  importMode.value = 'create';
  if (importArchiveInput.value) importArchiveInput.value.value = '';
}

function onImportFileChange(event: Event) {
  const input = event.target as HTMLInputElement;
  importArchive.value = input.files?.[0] ?? null;
  importPlan.value = null;
  importToken.value = '';
  importReport.value = null;
}

async function inspectImport() {
  if (!importArchive.value || inspectingImport.value) return;
  error.value = '';
  message.value = '';
  feedbackNonce.value += 1;
  inspectingImport.value = true;
  try {
    const form = new FormData();
    form.append('archive', importArchive.value, importArchive.value.name);
    form.append('site_id', String(context.siteId || ''));
    form.append('lang', context.languageCode || '');
    const response = await adminApi.upload<{ message?: string; plan?: EditorialImportPlan; import_token?: string }>('/imports-exports/imports/inspect', form);
    importPlan.value = response.data?.plan ?? null;
    importToken.value = response.data?.import_token ?? '';
    importReport.value = null;
    message.value = response.data?.message || 'Archive analysée sans écriture.';
  } catch (err) {
    importPlan.value = null;
    error.value = apiErrorMessage(err, 'Inspection de l’archive impossible.');
  } finally {
    inspectingImport.value = false;
  }
}

async function executeImport() {
  if (!importToken.value || !importPlan.value || executingImport.value || importPlan.value.blocking_errors?.length) return;
  const labels = {
    create: 'Ajouter (aucune modification de page existante)',
    upsert: 'Ajouter les pages manquantes et modifier les pages existantes',
    replace: 'Modifier uniquement (sans ajouter les pages manquantes)'
  };
  if (!window.confirm(`Confirmer l’import : ${labels[importMode.value]} ?`)) return;
  executingImport.value = true; error.value = ''; message.value = ''; feedbackNonce.value += 1;
  try {
    const response = await adminApi.post<{ message?: string; report?: Record<string, unknown> }>('/imports-exports/imports/execute', {
      site_id: context.siteId, lang: context.languageCode, import_token: importToken.value, mode: importMode.value
    });
    importReport.value = response.data?.report ?? null;
    importToken.value = '';
    message.value = response.data?.message || 'Import éditorial terminé.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err, 'Import éditorial impossible.'); }
  finally { executingImport.value = false; }
}

function planCount(value: unknown): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  return Array.isArray(value) ? value.length : 0;
}

function formatImportWarning(value: unknown): string {
  const message = String(value || '').trim();
  if (!message) return '';
  return message
    .replace(/Le mode create/g, 'Le mode « Ajouter (aucune modification de page existante) »')
    .replace(/Le mode upsert/g, 'Le mode « Ajouter les pages manquantes et modifier les pages existantes »')
    .replace(/Le mode replace/g, 'Le mode « Modifier uniquement (sans ajouter les pages manquantes) »');
}

function normalizeRoutePath(value: string): string {
  const clean = String(value || '').trim();
  if (clean === '') return '';
  return '/' + clean.replace(/^\/+/, '').replace(/\/+$/g, '');
}

function exportFileHref(path?: string | null): string {
  if (!path) return '';
  const raw = String(path);
  const marker = '/admin/api';
  const index = raw.indexOf(marker);
  if (index >= 0) {
    return adminApi.href(raw.slice(index + marker.length));
  }
  if (raw.startsWith('/imports-exports/static') || raw.startsWith('/imports-exports/editorial')) {
    return adminApi.href(raw);
  }
  return raw;
}

function pickRoute(route: ExportRouteSuggestion) {
  routePath.value = route.path;
  routeInputFocused.value = false;
  activeRouteSuggestionIndex.value = -1;
}

function closeRouteSuggestionsSoon() {
  window.setTimeout(() => { routeInputFocused.value = false; }, 90);
}

function onRouteKeydown(event: KeyboardEvent) {
  const rows = filteredRouteSuggestions.value;
  if (event.key === 'Escape') {
    routeInputFocused.value = false;
    activeRouteSuggestionIndex.value = -1;
    return;
  }
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    routeInputFocused.value = true;
    if (rows.length === 0) return;
    activeRouteSuggestionIndex.value = (activeRouteSuggestionIndex.value + 1 + rows.length) % rows.length;
    return;
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault();
    routeInputFocused.value = true;
    if (rows.length === 0) return;
    activeRouteSuggestionIndex.value = (activeRouteSuggestionIndex.value - 1 + rows.length) % rows.length;
    return;
  }
  if (event.key === 'Enter') {
    const index = activeRouteSuggestionIndex.value >= 0 ? activeRouteSuggestionIndex.value : (rows.length === 1 ? 0 : -1);
    if (index >= 0 && rows[index]) {
      event.preventDefault();
      pickRoute(rows[index]);
    }
  }
}

function releaseLinks(row: ExportRelease) {
  return row.links ?? {};
}

function formatCount(value: unknown): number {
  return Number(value || 0);
}

function formatDate(value: unknown): string {
  if (!value || typeof value !== 'string') return '—';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

onMounted(load);
watch(() => [context.siteId, context.languageCode], () => {
  routePath.value = '';
  activeRouteSuggestionIndex.value = -1;
  void load();
});

watch(routePath, () => {
  activeRouteSuggestionIndex.value = filteredRouteSuggestions.value.length > 0 ? 0 : -1;
});
</script>

<template>
  <PageHeader
    title="Imports / Exports"
    intro="Exports de publication et futurs imports éditoriaux, réunis dans une interface commune."
  />

  <ApiFeedback :error="error" :success="message" :nonce="feedbackNonce" />

  <section v-if="canReadImportsExports" class="static-export-page">
    <div v-if="canWriteImportsExports" class="static-export-layout static-export-layout--manage">
      <article class="card static-export-card static-export-card--primary">
        <div class="static-export-card__head">
          <div>
            <p class="eyebrow">Nouvel export</p>
            <p class="muted">Chaque action produit un ZIP statique de publication et un ZIP éditorial portable issus de la même photographie publiée.</p>
          </div>
        </div>

        <div class="field static-export-route-field">
          <label for="static-export-route">Chemin public de la page</label>
          <input
            id="static-export-route"
            class="input"
            v-model="routePath"
            placeholder="/contact"
            type="search"
            :name="routeInputName"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            data-lpignore="true"
            data-form-type="other"
            spellcheck="false"
            role="combobox"
            aria-autocomplete="list"
            aria-controls="static-export-route-options"
            :aria-expanded="showRouteSuggestions ? 'true' : 'false'"
            @focus="routeInputFocused = true"
            @input="routeInputFocused = true"
            @keydown="onRouteKeydown"
            @blur="closeRouteSuggestionsSoon"
          />
          <div v-if="showRouteSuggestions" id="static-export-route-options" class="static-export-route-picker" role="listbox">
            <button
              v-for="(route, index) in filteredRouteSuggestions"
              :key="`${route.language_code}-${route.path}-${route.id}`"
              type="button"
              role="option"
              class="static-export-route-option"
              :class="{ active: index === activeRouteSuggestionIndex || normalizeRoutePath(routePath) === normalizeRoutePath(route.path) }"
              :aria-selected="index === activeRouteSuggestionIndex ? 'true' : 'false'"
              @mouseenter="activeRouteSuggestionIndex = index"
              @mousedown.prevent="pickRoute(route)"
            >
              <span>{{ route.path }}</span>
              <small>{{ route.label || route.path }} · {{ route.language_code || '—' }}</small>
            </button>
            <p v-if="fallbackAllLanguages" class="muted static-export-route-picker__note">Suggestions élargies aux autres langues du site.</p>
          </div>
          <p v-else-if="routeInputFocused && routePath.trim() !== '' && !loading" class="muted static-export-route-hint">{{ emptySuggestionMessage }}</p>
        </div>

        <div class="static-export-actions">
          <button class="btn primary" :disabled="Boolean(runningAction) || loading" @click="run('page')">
            {{ runningAction === 'page' ? 'Export…' : 'Exporter cette page' }}
          </button>
          <button class="btn ghost" :disabled="Boolean(runningAction) || loading" @click="run('language')">
            {{ runningAction === 'language' ? 'Export…' : 'Exporter la langue' }}
          </button>
          <button class="btn ghost" :disabled="Boolean(runningAction) || loading" @click="run('site')">
            {{ runningAction === 'site' ? 'Export…' : 'Exporter le site' }}
          </button>
        </div>
      </article>

      <div class="static-export-side-stack">
        <article class="card static-export-card static-export-release-card">
          <div class="static-export-card__head">
            <div>
              <p class="eyebrow">Dernière sortie générée</p>
              <h2>{{ lastExport?.release_id || 'Aucun export' }}</h2>
            </div>
            <button class="btn" :disabled="Boolean(runningAction) || !lastExport" @click="run('rerun')">
              {{ runningAction === 'rerun' ? 'Relance…' : 'Relancer l’exportation' }}
            </button>
          </div>

          <dl class="static-export-kpis static-export-kpis--compact">
            <div><dt>{{ lastSiteLanguageLabel }}</dt><dd>{{ lastExport?.site || payload.suggestions?.current_site || '—' }} · {{ lastLanguages }}</dd></div>
            <div><dt>Pages</dt><dd>{{ formatCount(lastExport?.exported_routes) }}</dd></div>
          </dl>

          <div class="static-export-path">
            <code>{{ lastExport?.output_path || 'storage/exports/static/&lt;release_id&gt;/public/' }}</code>
          </div>
        </article>

      </div>
    </div>

    <section class="static-export-history" :class="{ 'static-export-history--readonly': !canWriteImportsExports }">
      <DataTable
        :loading="loading"
        :skeleton-rows="4"
        :columns="[
          {key:'release_id',label:'Release',sortable:true},
          {key:'status',label:'Statut',sortable:true},
          {key:'site',label:'Site'},
          {key:'mode',label:'Mode'},
          {key:'exported_routes',label:'Routes',sortable:true},
          ...(canWriteImportsExports ? [{key:'files',label:'Actions'}] : [])
        ]"
        :rows="history as unknown as Record<string, unknown>[]"
        empty-message="Aucune release enregistrée."
      >
        <template #cell-release_id="{ row }">
          <strong>{{ row.release_id }}</strong><br />
          <small class="muted">{{ formatDate(row.generated_at) }}</small>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge :status="String(row.status || 'skipped')" />
        </template>
        <template #cell-site="{ row }">
          <span>{{ row.site || '—' }}</span><br />
          <small class="muted">{{ Array.isArray(row.languages) ? row.languages.join(', ') : '—' }}</small>
        </template>
        <template #cell-exported_routes="{ row }">
          <span>{{ formatCount(row.exported_routes) }}</span><br />
          <small class="muted">{{ formatCount(row.errors_count) }} err. · {{ formatCount(row.warnings_count) }} warn.</small>
        </template>
        <template v-if="canWriteImportsExports" #cell-files="{ row }">
          <details class="static-export-row-menu">
            <summary class="static-export-row-menu__toggle" aria-label="Actions de la release" title="Actions">
              <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                <path d="M9.5 2a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm0 6a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm0 6a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z" />
              </svg>
            </summary>
            <div class="static-export-row-menu__popover card">
              <a v-if="releaseLinks(row).manifest" :href="exportFileHref(releaseLinks(row).manifest)" target="_blank" rel="noopener">Manifest</a>
              <a v-if="releaseLinks(row).report" :href="exportFileHref(releaseLinks(row).report)" target="_blank" rel="noopener">Rapport</a>
              <a v-if="releaseLinks(row).static_zip || releaseLinks(row).zip" :href="exportFileHref(releaseLinks(row).static_zip || releaseLinks(row).zip)" target="_blank" rel="noopener">ZIP statique</a>
              <a v-if="releaseLinks(row).editorial_zip" :href="exportFileHref(releaseLinks(row).editorial_zip)" target="_blank" rel="noopener">ZIP éditorial</a>
              <button v-if="canManageImportsExports" type="button" class="static-export-row-menu__danger" :disabled="Boolean(runningAction)" @click="deleteRelease(row.release_id)">
                {{ runningAction === `delete:${row.release_id}` ? 'Suppression…' : 'Supprimer' }}
              </button>
            </div>
          </details>
        </template>
      </DataTable>
    </section>

    <article class="card static-export-card static-export-import-card static-export-import-card--full">
      <div class="static-export-card__head">
        <div>
          <p class="eyebrow">Importer</p>
          <p class="muted">Le ZIP est inspecté dans un répertoire temporaire non public, puis supprimé. Cette étape ne modifie aucune donnée.</p>
        </div>
      </div>
      <div class="field">
        <input ref="importArchiveInput" id="editorial-import-archive" class="input" type="file" accept=".zip,application/zip" aria-label="Archive ZIP éditoriale" @change="onImportFileChange" />
      </div>
      <button v-if="!importPlan" class="btn primary" type="button" :disabled="!importArchive || inspectingImport" @click="inspectImport">
        {{ inspectingImport ? 'Vérification…' : 'Vérifier' }}
      </button>

      <div v-if="importPlan" class="static-export-import-plan">
        <details class="static-export-simulation-details">
          <summary>
            <svg class="static-export-summary-caret" aria-hidden="true" viewBox="0 0 16 16" focusable="false">
              <path fill="currentColor" fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z" clip-rule="evenodd" />
            </svg>
            <span>Rapport de simulation</span>
          </summary>
          <div class="static-export-simulation-content">
            <dl class="static-export-kpis">
              <div><dt>Format</dt><dd>{{ importPlan.inspection?.format }} {{ importPlan.inspection?.format_version }}</dd></div>
              <div><dt>Source → cible</dt><dd>{{ importPlan.inspection?.source_site }} → {{ importPlan.inspection?.target_site }}</dd></div>
              <div><dt>Langues</dt><dd>{{ importPlan.inspection?.languages?.join(', ') || '—' }}</dd></div>
              <div><dt>À créer</dt><dd>{{ planCount(importPlan.contents?.to_create) }}</dd></div>
              <div><dt>Existants</dt><dd>{{ planCount(importPlan.contents?.existing) }}</dd></div>
              <div><dt>Conflits contenus</dt><dd>{{ planCount(importPlan.contents?.conflicts) }}</dd></div>
              <div><dt>Blueprints existants</dt><dd>{{ planCount(importPlan.blueprints?.existing) }}</dd></div>
              <div><dt>Blueprints à importer</dt><dd>{{ planCount(importPlan.blueprints?.to_import) }}</dd></div>
              <div><dt>Blueprints incompatibles</dt><dd>{{ planCount(importPlan.blueprints?.incompatible) }}</dd></div>
              <div><dt>Médias nouveaux</dt><dd>{{ planCount(importPlan.media?.new) }}</dd></div>
              <div><dt>Médias réutilisés</dt><dd>{{ planCount(importPlan.media?.existing_by_sha256) }}</dd></div>
              <div><dt>Routes en conflit</dt><dd>{{ planCount(importPlan.routes?.conflicts) }}</dd></div>
              <div><dt>Relations non résolues</dt><dd>{{ planCount(importPlan.relations?.unresolved) }}</dd></div>
            </dl>
            <div v-if="importPlan.blocking_errors?.length" class="alert danger static-export-plan-alert">
              <strong>Erreurs bloquantes</strong>
              <ul><li v-for="item in importPlan.blocking_errors" :key="item">{{ item }}</li></ul>
            </div>
            <div v-if="importPlan.warnings?.length" class="alert warning static-export-plan-alert">
              <strong>Points d’attention</strong>
              <ul><li v-for="item in importPlan.warnings" :key="item">{{ formatImportWarning(item) }}</li></ul>
            </div>
          </div>
        </details>
        <div class="field static-export-import-mode">
          <label for="editorial-import-mode">Mode d’import</label>
          <select id="editorial-import-mode" v-model="importMode" class="input">
            <option value="create">Ajouter (aucune modification de page existante)</option>
            <option value="upsert">Ajouter les pages manquantes et modifier les pages existantes</option>
            <option value="replace">Modifier uniquement (sans ajouter les pages manquantes)</option>
          </select>
          <div class="static-export-actions">
            <button class="btn primary" type="button" :disabled="executingImport || !importPlan.can_execute_later || !importToken || Boolean(importPlan.blocking_errors?.length)" @click="executeImport">{{ executingImport ? 'Import…' : 'Exécuter' }}</button>
            <button class="btn ghost" type="button" :disabled="executingImport" @click="resetImportPreparation">Annuler</button>
          </div>
        </div>
        <div v-if="importReport" class="alert success">
          <strong>Import terminé</strong>
          <p>ID : {{ importReport.import_id }} · Statut : {{ importReport.status }} · Durée : {{ importReport.duration_seconds }} s</p>
          <p>Créés : {{ planCount(importReport.contents_created) }} · Mis à jour : {{ planCount(importReport.contents_updated) }} · Ignorés : {{ planCount(importReport.contents_ignored) }}</p>
        </div>
      </div>
    </article>

    <section class="static-export-history" :class="{ 'static-export-history--readonly': !canWriteImportsExports }">
      <DataTable
        :loading="loading"
        :skeleton-rows="3"
        :columns="[
          {key:'import_id',label:'Import',sortable:true},
          {key:'status',label:'Statut',sortable:true},
          {key:'mode',label:'Mode'},
          {key:'changes',label:'Résultat'},
          ...(canManageImportsExports ? [{key:'actions',label:'Actions'}] : [])
        ]"
        :rows="importHistory as unknown as Record<string, unknown>[]"
        empty-message="Aucun import enregistré."
      >
        <template #cell-import_id="{ row }">
          <strong>{{ row.import_id }}</strong><br />
          <small class="muted">{{ formatDate(row.finished_at) }}</small>
        </template>
        <template #cell-status="{ row }"><StatusBadge :status="String(row.status || 'failed')" /></template>
        <template #cell-changes="{ row }">
          <span>Créés : {{ planCount(row.contents_created) }} · Modifiés : {{ planCount(row.contents_updated) }} · Ignorés : {{ planCount(row.contents_ignored) }}</span><br />
          <small class="muted">{{ planCount(row.errors) || Number(row.errors_count || 0) }} err. · {{ planCount(row.warnings) || Number(row.warnings_count || 0) }} warn.</small>
        </template>
        <template v-if="canManageImportsExports" #cell-actions="{ row }">
          <button class="btn danger static-export-delete" type="button" :disabled="Boolean(runningAction)" @click="deleteImportHistory(row.import_id)">
            {{ runningAction === `delete-import:${row.import_id}` ? 'Suppression…' : 'Supprimer' }}
          </button>
        </template>
      </DataTable>
    </section>
  </section>
  <section v-else class="card static-export-card">
    <h2>Accès réservé</h2>
    <p class="muted">Cette fonctionnalité nécessite une permission Imports / Exports.</p>
  </section>
</template>
