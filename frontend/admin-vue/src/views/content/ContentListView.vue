<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminContextStore } from '@/stores/adminContext';
import { useContentEntriesStore } from '@/stores/contentEntries';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';

const props = defineProps<{ typeKey: string }>();
const router = useRouter();
const context = useAdminContextStore();
const entries = useContentEntriesStore();
const q = ref('');
const status = ref('published');
const contentType = computed(() => {
  if (props.typeKey === 'articles') return 'article';
  if (props.typeKey === 'pages') return 'page';
  if (props.typeKey === 'article_archives') return 'article_archive';
  if (props.typeKey === 'page_archives') return 'page_archive';
  return props.typeKey;
});
const title = computed(() => {
  if (contentType.value === 'article') return 'Articles';
  if (contentType.value === 'page') return 'Pages';
  if (contentType.value === 'article_archive') return "Archives d\'articles";
  if (contentType.value === 'page_archive') return 'Archives de pages';
  return props.typeKey;
});
const intro = computed(() => {
  if (contentType.value === 'article') return 'Publications datées, brouillons, preview et publication atomique.';
  if (contentType.value === 'article_archive') return 'Pages spécialisées pour lister et organiser les articles.';
  if (contentType.value === 'page_archive') return 'Pages spécialisées pour lister, organiser ou rediriger des pages.';
  return 'Pages structurantes du site, routes canoniques et SEO éditorial.';
});

const rowCount = computed(() => entries.rows.length);

async function load() { await entries.list({ type: contentType.value, status: status.value, q: q.value }, context.siteId, context.languageCode); }
function edit(row: Record<string, unknown>) { router.push(`/contents/${props.typeKey}/${row.id}`); }
function create() { router.push(`/contents/${props.typeKey}/new`); }
function withPublicBasePath(path: string): string {
  if (!path) return '';
  if (/^(https?:)?\/\//i.test(path) || /^(mailto|tel):/i.test(path)) return path;

  const base = String(context.context?.site?.base_path || window.__AMCMS_ADMIN__?.basePath || '').trim();
  const normalizedBase = base === '/' ? '' : `/${base.replace(/^\/+|\/+$/g, '')}`;
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;

  if (!normalizedBase) return normalizedPath || '/';
  if (normalizedPath === normalizedBase || normalizedPath.startsWith(`${normalizedBase}/`)) return normalizedPath;

  return `${normalizedBase}${normalizedPath}`.replace(/\/{2,}/g, '/');
}

function openPath(path: unknown, event: MouseEvent) {
  event.stopPropagation();
  if (typeof path === 'string' && path) window.open(withPublicBasePath(path), '_blank', 'noopener');
}

function blockStatusSummary(row: Record<string, unknown>) {
  const summary = row.block_editorial_statuses as { total?: number; draft?: number; review?: number; ready?: number; published?: number; archived?: number } | undefined;
  return summary || { total: 0, draft: 0, review: 0, ready: 0, published: 0, archived: 0 };
}

function relativeDate(iso: unknown): string {
  if (!iso || typeof iso !== 'string') return '—';
  const date = new Date(iso);
  if (isNaN(date.getTime())) return '—';
  const now = Date.now();
  const diff = now - date.getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 2) return "À l\'instant";
  if (mins < 60) return `il y a ${mins} min`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `il y a ${hours} h`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `il y a ${days} j`;
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

function clearSearch() { q.value = ''; load(); }

onMounted(load);
watch(() => [context.siteId, context.languageCode, props.typeKey], load);
</script>
<template>
  <PageHeader :title="title" :intro="intro">
    <template #actions>
      <button class="btn primary" @click="create">
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" style="margin-right:.35rem"><path fill="currentColor" d="M8 1a.5.5 0 0 1 .5.5v6h6a.5.5 0 0 1 0 1h-6v6a.5.5 0 0 1-1 0v-6h-6a.5.5 0 0 1 0-1h6v-6A.5.5 0 0 1 8 1z"/></svg>
        Créer
      </button>
    </template>
  </PageHeader>

  <div class="toolbar card content-list-toolbar" style="margin-bottom:1rem">
    <div class="content-list-toolbar__search-wrap">
      <input
        class="input content-list-toolbar__search"
        v-model="q"
        placeholder="Titre, slug ou contenu…"
        @keyup.enter="load"
        aria-label="Rechercher des contenus"
      />
      <button v-if="q" type="button" class="content-list-clear-btn" @click="clearSearch" aria-label="Effacer la recherche">×</button>
    </div>
    <select class="select content-list-toolbar__status" v-model="status" @change="load" aria-label="Filtrer par statut">
      <option value="published">Publié</option>
      <option value="all">Tous les statuts</option>
      <option value="draft">Brouillon</option>
      <option value="review">En revue</option>
      <option value="archived">Archivé</option>
    </select>
    <button class="btn" :disabled="entries.loading" @click="load">Filtrer</button>
    <span v-if="!entries.loading" class="content-list-count muted">{{ rowCount }} résultat{{ rowCount !== 1 ? 's' : '' }}</span>
  </div>

  <ApiFeedback :error="entries.error" />

  <DataTable
    :loading="entries.loading"
    :skeleton-rows="6"
    :columns="[
      {key:'title',label:'Titre',sortable:true},
      {key:'status',label:'Statut',sortable:true},
      {key:'block_editorial_statuses',label:'Blocs'},
      {key:'full_path',label:'URL'},
      {key:'updated_at',label:'Modifié',sortable:true}
    ]"
    :rows="entries.rows as unknown as Record<string, unknown>[]"
    empty-message="Aucun contenu ne correspond aux filtres."
  >
    <template #cell-title="{ row }">
      <button class="content-list-title-btn" @click="edit(row)">
        <span>{{ row.title || `#${row.id}` }}</span>
        <svg class="content-list-title-arrow" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path fill="currentColor" d="m6 3 5 5-5 5"/></svg>
      </button>
    </template>
    <template #cell-status="{ row }">
      <StatusBadge :status="String(row.status || '')" />
    </template>
    <template #cell-block_editorial_statuses="{ row }">
      <div class="block-status-summary" v-if="blockStatusSummary(row).total" :title="`${blockStatusSummary(row).total} bloc(s)`">
        <span class="block-status block-status--count">{{ blockStatusSummary(row).total }}</span>
        <span v-if="(blockStatusSummary(row).draft ?? 0) > 0" class="block-status block-status--draft" :title="`${blockStatusSummary(row).draft} brouillon(s)`">{{ blockStatusSummary(row).draft }}</span>
      </div>
      <span v-else class="muted">—</span>
    </template>
    <template #cell-full_path="{ row }">
      <button v-if="row.full_path" class="link-button content-list-url-btn" @click="openPath(row.full_path, $event)" :title="withPublicBasePath(String(row.full_path))" target="_blank">
        <code>{{ row.full_path }}</code>
        <svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true" style="opacity:.5;flex-shrink:0"><path fill="currentColor" d="M4.5 11.5A.5.5 0 0 0 5 12h6a.5.5 0 0 0 .5-.5v-6a.5.5 0 0 0-1 0v4.793l-6.146-6.147a.5.5 0 1 0-.708.708L9.793 11H5a.5.5 0 0 0-.5.5z"/></svg>
      </button>
      <span v-else class="muted">—</span>
    </template>
    <template #cell-updated_at="{ row }">
      <span :title="String(row.updated_at || '')" class="muted">{{ relativeDate(row.updated_at) }}</span>
    </template>
  </DataTable>
</template>
