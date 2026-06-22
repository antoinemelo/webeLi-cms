<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
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
type DocsIndexPayload = { sections: DocsSection[]; documents: DocsDocument[]; access?: { authentication_required: boolean; permission_filtering: boolean; all_documentation_exposed: boolean } };
type DocsShowPayload = { document: DocsDocument & { html: string; markdown?: string; source?: string; front_matter?: Record<string, unknown> }; navigation: DocsDocument[] };

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

// Documentation access only requires the current authenticated back-office context.
const canOpenDocs = computed(() => Boolean(context.context));

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
    const response = await adminApi.get<DocsIndexPayload>('/docs');
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

type SelectDocumentOptions = { fromId?: string; linkPath?: string };

async function selectDocument(id: string, options: SelectDocumentOptions = {}): Promise<void> {
  if (!id || (id === selectedId.value && current.value && !options.fromId && !options.linkPath)) return;
  const previousId = selectedId.value;
  selectedId.value = id;
  documentLoading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<DocsShowPayload>('/docs/resolve', {
      id,
      from_id: options.fromId,
      link_path: options.linkPath
    });
    current.value = response.data.document;
    selectedId.value = response.data.document.id;
    if (Array.isArray(response.data.navigation) && response.data.navigation.length > 0) documents.value = response.data.navigation;
  } catch (err) {
    selectedId.value = current.value?.id || previousId;
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

function normalizedDocLookupPath(path?: string): string {
  return normalizeDocPath(path || '').replace(/^docs\//, '');
}

function decodeHrefPath(href: string): string {
  const clean = href.split('#')[0].split('?')[0].trim();
  try {
    return decodeURIComponent(clean);
  } catch {
    return clean;
  }
}

function documentIdFromRelativePath(path: string): string {
  return normalizedDocLookupPath(path)
    .replace(/\.md$/i, '')
    .replace(/\/README$/i, '/index')
    .replace(/\//g, '~')
    .replace(/[^A-Za-z0-9._~-]+/g, '-');
}

function explicitDocumentIdFromHref(href: string): string {
  const hashMatch = href.match(/^#docs\/(.+)$/);
  if (!hashMatch) return '';
  try {
    return decodeURIComponent(hashMatch[1]);
  } catch {
    return hashMatch[1];
  }
}

function markdownHrefCandidates(href: string): string[] {
  const cleanHref = decodeHrefPath(href);
  if (!cleanHref || !current.value || cleanHref.startsWith('#')) return [];
  if (/^[a-z][a-z0-9+.-]*:/i.test(cleanHref)) return [];

  const explicitDocsPath = cleanHref.match(/(?:^|\/)docs\/(.+)$/);
  if (cleanHref.startsWith('/') && !explicitDocsPath) return [];

  const currentPath = normalizedDocLookupPath(current.value.relative_path || current.value.source_path);
  const currentDirectory = currentPath.split('/').slice(0, -1).join('/');
  const resolved = explicitDocsPath
    ? explicitDocsPath[1]
    : cleanHref.startsWith('docs/')
      ? cleanHref.replace(/^docs\//, '')
      : normalizeDocPath(`${currentDirectory}/${cleanHref}`);

  const base = resolved.endsWith('/') ? `${resolved}README.md` : resolved;
  const candidates = new Set<string>([
    base,
    base.replace(/^docs\//, ''),
    base.replace(/\/index\.md$/i, '/README.md')
  ]);

  if (!/\.md$/i.test(base)) {
    candidates.add(`${base}.md`);
    candidates.add(`${base}/README.md`);
  }

  return [...candidates].map(normalizedDocLookupPath).filter(Boolean);
}

function documentMatchesCandidate(doc: DocsDocument, candidates: string[]): boolean {
  const relativePath = normalizedDocLookupPath(doc.relative_path);
  const sourcePath = normalizedDocLookupPath(doc.source_path);
  const id = doc.id;
  return candidates.some((candidate) => {
    const normalized = normalizedDocLookupPath(candidate);
    return normalized === relativePath
      || normalized === sourcePath
      || normalized === id
      || documentIdFromRelativePath(normalized) === id
      || relativePath.endsWith(`/${normalized}`)
      || sourcePath.endsWith(`/${normalized}`);
  });
}

function currentDocumentDirectory(): string {
  const currentPath = normalizedDocLookupPath(current.value?.relative_path || current.value?.source_path || '');
  return currentPath.split('/').slice(0, -1).join('/');
}

function documentIdForMarkdownHref(href: string): string {
  const explicitId = explicitDocumentIdFromHref(href);
  if (explicitId) {
    const knownExplicit = documents.value.find((doc) => doc.id === explicitId);
    return knownExplicit?.id || explicitId;
  }

  const candidates = markdownHrefCandidates(href);
  if (candidates.length === 0) return '';

  const known = documents.value.find((doc) => documentMatchesCandidate(doc, candidates));
  if (known?.id) return known.id;

  const markdownCandidate = candidates.find((candidate) => /\.md$/i.test(candidate));
  return markdownCandidate ? documentIdFromRelativePath(markdownCandidate) : documentIdFromRelativePath(candidates[0]);
}

function documentIdForMarkdownAnchor(anchor: HTMLAnchorElement, href: string): string {
  const rawTokens = [anchor.dataset.docPath || '', anchor.dataset.docId || '', href].filter(Boolean);
  const currentDir = currentDocumentDirectory();

  for (const token of rawTokens) {
    const explicitId = explicitDocumentIdFromHref(token);
    if (explicitId) {
      const known = documents.value.find((doc) => doc.id === explicitId);
      if (known?.id) return known.id;
      return explicitId;
    }

    const direct = documents.value.find((doc) => doc.id === token);
    if (direct?.id) return direct.id;

    const tokenAsPath = normalizedDocLookupPath(token.replace(/~/g, '/'));
    const candidates = new Set<string>([...markdownHrefCandidates(token), tokenAsPath]);
    if (tokenAsPath && !/\.md$/i.test(tokenAsPath)) {
      candidates.add(`${tokenAsPath}.md`);
      candidates.add(`${tokenAsPath}/README.md`);
    }
    if (currentDir && tokenAsPath && !tokenAsPath.startsWith(`${currentDir}/`)) {
      const relativeToCurrent = normalizeDocPath(`${currentDir}/${tokenAsPath}`);
      candidates.add(relativeToCurrent);
      if (!/\.md$/i.test(relativeToCurrent)) candidates.add(`${relativeToCurrent}.md`);
    }

    const known = documents.value.find((doc) => documentMatchesCandidate(doc, [...candidates]));
    if (known?.id) return known.id;
  }

  return documentIdForMarkdownHref(href);
}

function linkPathForMarkdownAnchor(anchor: HTMLAnchorElement, href: string): string {
  const explicitPath = anchor.dataset.docPath || '';
  if (explicitPath) return explicitPath;
  return href || '';
}

function onMarkdownClick(event: MouseEvent): void {
  const target = event.target as HTMLElement | null;
  const anchor = target?.closest('a[href]') as HTMLAnchorElement | null;
  if (!anchor) return;

  const href = anchor.getAttribute('href') || '';
  const docId = documentIdForMarkdownAnchor(anchor, href);
  if (docId) {
    event.preventDefault();
    void selectDocument(docId, {
      fromId: current.value?.id,
      linkPath: linkPathForMarkdownAnchor(anchor, href)
    });
    return;
  }

  if (/^https?:\/\//i.test(href)) {
    anchor.setAttribute('target', '_blank');
    anchor.setAttribute('rel', 'noopener noreferrer');
    return;
  }

  if (href && !href.startsWith('#') && !/^[a-z][a-z0-9+.-]*:/i.test(href)) {
    event.preventDefault();
    error.value = 'Document introuvable dans la documentation.';
  }
}

onMounted(loadIndex);
</script>

<template>
  <PageHeader title="Documentation" intro="Toute la documentation du CMS, accessible à chaque utilisateur du back-office depuis le menu principal Actifs." />

  <ApiFeedback :error="error" />

  <div v-if="!canOpenDocs" class="card empty-state">
    <h2>Session requise</h2>
    <p class="muted">Connectez-vous au back-office pour consulter la documentation.</p>
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
          <button
            v-for="doc in section.documents"
            :key="doc.id"
            class="docs-nav-item"
            :class="{ active: doc.id === selectedId }"
            type="button"
            @click="selectDocument(doc.id)"
          >
            <strong>{{ doc.title }}</strong>
          </button>
        </div>
      </aside>

      <article class="card docs-viewer">
        <div v-if="documentLoading" class="muted">Ouverture du document…</div>
        <div v-else-if="!current" class="empty-state">
          <strong>Sélectionnez un document</strong>
          <span>Sélectionnez une page Markdown ou une référence technique.</span>
        </div>
        <template v-else>
          <header class="docs-viewer__header">
            <p class="eyebrow">{{ currentSection?.label || current.section_label }}</p>
            <h1>{{ current.title }}</h1>
            <div class="docs-meta-row">
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
