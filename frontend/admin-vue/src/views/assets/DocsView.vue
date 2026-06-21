<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useAdminContextStore } from '@/stores/adminContext';

type DocsDocument = {
  id: string;
  section_key: string;
  section_label: string;
  title: string;
  summary?: string;
  relative_path: string;
  source_path: string;
  format: 'markdown' | string;
  audience_label?: string;
  status?: string;
  version?: string;
  last_verified?: string;
  document_type?: string;
  generated?: boolean;
  is_section_index?: boolean;
  updated_at?: string;
};

type DocsSection = { key: string; label: string; description: string; audience_label: string; documents: DocsDocument[] };
type DocsIndexPayload = { sections: DocsSection[]; documents: DocsDocument[]; policy?: Array<{ key: string; label: string; audience_label: string }> };
type DocsShowPayload = { document: DocsDocument & { html: string; markdown?: string; front_matter?: Record<string, unknown> }; navigation: DocsDocument[] };

const context = useAdminContextStore();
const sections = ref<DocsSection[]>([]);
const documents = ref<DocsDocument[]>([]);
const current = ref<DocsShowPayload['document'] | null>(null);
const loading = ref(false);
const documentLoading = ref(false);
const error = ref('');
const q = ref('');
const selectedSection = ref('all');
const selectedId = ref('');

const canOpenDocs = computed(() =>
  context.can('*') ||
  context.can('content.read') ||
  context.can('content.publish') ||
  context.can('content.approve') ||
  context.can('seo.read') ||
  context.can('seo.manage') ||
  context.can('media.read') ||
  context.can('settings.read') ||
  context.can('modules.read') ||
  context.can('users.read') ||
  context.can('roles.read') ||
  context.can('maintenance.manage') ||
  context.can('imports_exports.manage')
);

const filteredSections = computed(() => {
  const term = q.value.trim().toLowerCase();
  return sections.value
    .filter((section) => selectedSection.value === 'all' || section.key === selectedSection.value)
    .map((section) => ({
      ...section,
      documents: section.documents.filter((doc) => {
        const haystack = [doc.title, doc.summary, doc.source_path, doc.audience_label, doc.document_type].filter(Boolean).join(' ').toLowerCase();
        return !term || haystack.includes(term);
      })
    }))
    .filter((section) => section.documents.length > 0);
});

const currentSection = computed(() => sections.value.find((section) => section.key === current.value?.section_key));

function labelForStatus(status?: string): string {
  if (!status) return '';
  const labels: Record<string, string> = { stable: 'stable', draft: 'brouillon', generated: 'généré' };
  return labels[status] || status;
}

async function loadIndex(): Promise<void> {
  if (!canOpenDocs.value) return;
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<DocsIndexPayload>('/docs', { site_id: context.siteId });
    sections.value = response.data.sections || [];
    documents.value = response.data.documents || [];
    const first = documents.value.find((doc) => doc.is_section_index) || documents.value[0];
    if (first && !selectedId.value) await selectDocument(first.id);
  } catch (err) {
    error.value = apiErrorMessage(err, 'Documentation indisponible.');
  } finally {
    loading.value = false;
  }
}

async function selectDocument(id: string): Promise<void> {
  if (!id || id === selectedId.value && current.value) return;
  selectedId.value = id;
  documentLoading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<DocsShowPayload>(`/docs/${encodeURIComponent(id)}`, { site_id: context.siteId });
    current.value = response.data.document;
    if (Array.isArray(response.data.navigation) && response.data.navigation.length > 0) documents.value = response.data.navigation;
  } catch (err) {
    error.value = apiErrorMessage(err, 'Document indisponible.');
  } finally {
    documentLoading.value = false;
  }
}

function normalizeDocPath(path: string): string {
  const output: string[] = [];
  path.split('/').forEach((part) => {
    if (!part || part === '.') return;
    if (part === '..') output.pop();
    else output.push(part);
  });
  return output.join('/');
}

function documentIdForMarkdownHref(href: string): string {
  const cleanHref = href.split('#')[0].split('?')[0];
  if (!cleanHref || !current.value) return '';
  if (/^[a-z][a-z0-9+.-]*:/i.test(cleanHref) || cleanHref.startsWith('#')) return '';
  const relative = cleanHref.startsWith('/docs/')
    ? cleanHref.replace(/^\/docs\//, '')
    : normalizeDocPath(`${current.value.relative_path.split('/').slice(0, -1).join('/')}/${cleanHref}`);
  const normalized = relative.endsWith('/') ? `${relative}README.md` : relative;
  const candidates = [normalized, normalized.replace(/\/index\.md$/i, '/README.md')];
  return documents.value.find((doc) => candidates.includes(doc.relative_path))?.id || '';
}

function onMarkdownClick(event: MouseEvent): void {
  const target = event.target as HTMLElement | null;
  const anchor = target?.closest('a[href]') as HTMLAnchorElement | null;
  if (!anchor) return;
  const href = anchor.getAttribute('href') || '';
  const docId = documentIdForMarkdownHref(href);
  if (docId) {
    event.preventDefault();
    void selectDocument(docId);
    return;
  }
  if (/^https?:\/\//i.test(href)) {
    anchor.setAttribute('target', '_blank');
    anchor.setAttribute('rel', 'noopener noreferrer');
    return;
  }
  if (href && !href.startsWith('#') && !/^[a-z][a-z0-9+.-]*:/i.test(href)) {
    event.preventDefault();
    error.value = 'Ce lien pointe vers un fichier non Markdown ou vers un document non autorisé pour ce profil.';
  }
}

watch(() => context.siteId, () => { void loadIndex(); });
onMounted(loadIndex);
</script>

<template>
  <PageHeader title="Documentation" intro="Documents pertinents selon le rôle connecté, accessibles uniquement depuis le menu principal Actifs.">
    <template #actions>
      <button class="btn ghost" type="button" :disabled="loading || documentLoading" @click="loadIndex">Rafraîchir</button>
    </template>
  </PageHeader>

  <ApiFeedback :error="error" />

  <div v-if="!canOpenDocs" class="card empty-state">
    <h2>Accès restreint</h2>
    <p class="muted">Aucun espace documentaire n’est disponible pour ce profil.</p>
  </div>

  <template v-else>
    <section class="card docs-toolbar">
      <input v-model="q" class="input docs-toolbar__search" type="search" placeholder="Rechercher dans les titres, chemins et résumés…" />
      <select v-model="selectedSection" class="select docs-toolbar__section" aria-label="Filtrer par espace documentaire">
        <option value="all">Tous les espaces</option>
        <option v-for="section in sections" :key="section.key" :value="section.key">{{ section.label }}</option>
      </select>
    </section>

    <section class="docs-layout">
      <aside class="card docs-sidebar" aria-label="Documents disponibles">
        <div v-if="loading" class="muted">Chargement de la documentation…</div>
        <div v-else-if="filteredSections.length === 0" class="muted">Aucun document ne correspond au filtre.</div>
        <div v-for="section in filteredSections" :key="section.key" class="docs-section-list">
          <h2>{{ section.label }}</h2>
          <p class="muted">{{ section.audience_label }}</p>
          <button
            v-for="doc in section.documents"
            :key="doc.id"
            class="docs-nav-item"
            :class="{ active: doc.id === selectedId }"
            type="button"
            @click="selectDocument(doc.id)"
          >
            <strong>{{ doc.title }}</strong>
            <small>{{ doc.source_path }}</small>
          </button>
        </div>
      </aside>

      <article class="card docs-viewer">
        <div v-if="documentLoading" class="muted">Ouverture du document…</div>
        <div v-else-if="!current" class="empty-state">
          <strong>Sélectionnez un document</strong>
          <span>Les documents Markdown autorisés s’affichent ici.</span>
        </div>
        <template v-else>
          <header class="docs-viewer__header">
            <p class="eyebrow">{{ currentSection?.label || current.section_label }}</p>
            <h1>{{ current.title }}</h1>
            <div class="docs-meta-row">
              <span v-if="current.audience_label" class="badge">{{ current.audience_label }}</span>
              <span v-if="labelForStatus(current.status)" class="badge">{{ labelForStatus(current.status) }}</span>
              <span v-if="current.last_verified" class="muted">Vérifié : {{ current.last_verified }}</span>
              <span class="muted">{{ current.source_path }}</span>
            </div>
          </header>
          <div class="docs-markdown" v-html="current.html" @click="onMarkdownClick"></div>
        </template>
      </article>
    </section>
  </template>
</template>
