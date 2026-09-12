<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
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
  audience?: string[];
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
const route = useRoute();
const sections = ref<DocsSection[]>([]);
const documents = ref<DocsDocument[]>([]);
const current = ref<DocsShowPayload['document'] | null>(null);
const loading = ref(false);
const documentLoading = ref(false);
const error = ref('');
const q = ref('');
const selectedAudience = ref('all');
const selectedCollection = ref('use');
const selectedId = ref('');
const openSections = ref<Set<string>>(new Set());

// Documentation access only requires the current authenticated back-office context.
const canOpenDocs = computed(() => Boolean(context.context));

const audienceLabels: Record<string, string> = {
  editor: 'Rédaction',
  publisher: 'Publication',
  seo: 'SEO',
  administrator: 'Administration',
  superadministrator: 'Super admin',
  installer: 'Installation',
  'api-integrator': 'API',
  developer: 'Développement',
  evaluator: 'Évaluation',
  'documentation-maintainer': 'Documentation'
};

const audienceOrder = [
  'editor',
  'publisher',
  'seo',
  'administrator',
  'superadministrator',
  'installer',
  'api-integrator',
  'developer',
  'evaluator',
  'documentation-maintainer'
];

const hiddenAudienceTabs = new Set([
  'ai-evaluator',
  'developers',
  'evaluators',
  'operator',
  'operators',
  'release-managers',
  'seo-manager'
]);

const collectionDefinitions = [
  { key: 'use', label: 'Démarrer et utiliser', shortLabel: 'Utiliser', description: 'Prendre ses repères, créer du contenu, publier, gérer les médias et améliorer le référencement.', sections: ['overview', 'getting-started', 'user-guide'] },
  { key: 'business', label: 'Gérer l’activité', shortLabel: 'Activité', description: 'Piloter le catalogue, les clients, les ventes, les paiements et les opérations métier.', sections: ['business'] },
  { key: 'configure', label: 'Configurer le CMS', shortLabel: 'Configurer', description: 'Organiser les sites, langues, utilisateurs, rôles, modules et intégrations.', sections: ['administration'] },
  { key: 'operate', label: 'Installer et exploiter', shortLabel: 'Exploiter', description: 'Installer, sauvegarder, mettre à jour, déployer et dépanner une instance.', sections: ['installation', 'operations'] },
  { key: 'build', label: 'Intégrer et développer', shortLabel: 'Développer', description: 'Utiliser les API, comprendre l’architecture et étendre le CMS sans casser ses contrats.', sections: ['api', 'public-api', 'development', 'reference'] },
  { key: 'verify', label: 'Vérifier et auditer', shortLabel: 'Vérifier', description: 'Consulter les capacités démontrées, les limites connues et les contrôles reproductibles.', sections: ['evaluation'] }
] as const;

const collections = computed(() => collectionDefinitions.map((collection) => ({
  ...collection,
  count: documents.value.filter((doc) => collection.sections.includes(doc.section_key as never)).length
})));

function collectionForSection(sectionKey?: string): string {
  return collectionDefinitions.find((collection) => collection.sections.includes(String(sectionKey || '') as never))?.key || 'use';
}

const profileTabs = computed(() => {
  const counts = new Map<string, number>();
  documents.value.forEach((doc) => {
    docAudiences(doc)
      .filter((audience) => !hiddenAudienceTabs.has(audience))
      .forEach((audience) => counts.set(audience, (counts.get(audience) || 0) + 1));
  });
  const orderedKeys = [
    ...audienceOrder.filter((key) => counts.has(key)),
    ...[...counts.keys()].filter((key) => !audienceOrder.includes(key)).sort()
  ];
  return [
    { key: 'all', label: 'Tous', count: documents.value.length },
    ...orderedKeys.map((key) => ({ key, label: audienceLabels[key] || titleizeAudience(key), count: counts.get(key) || 0 }))
  ];
});

const filteredSections = computed(() => {
  const term = q.value.trim().toLowerCase();
  const collection = collectionDefinitions.find((item) => item.key === selectedCollection.value) || collectionDefinitions[0];
  return sections.value
    .filter((section) => Boolean(term) || collection.sections.includes(section.key as never))
    .map((section) => ({
      ...section,
      documents: section.documents.filter((doc) => {
        const haystack = [doc.title, doc.summary, doc.source_path, doc.audience_label, doc.document_type].filter(Boolean).join(' ').toLowerCase();
        const matchesTerm = !term || haystack.includes(term);
        const matchesAudience = selectedAudience.value === 'all' || docAudiences(doc).includes(selectedAudience.value);
        return matchesTerm && matchesAudience;
      })
    }))
    .filter((section) => section.documents.length > 0);
});

const filteredDocuments = computed(() => filteredSections.value.flatMap((section) => section.documents));
const currentSection = computed(() => sections.value.find((section) => section.key === current.value?.section_key));
const currentDocumentIndex = computed(() => filteredDocuments.value.findIndex((doc) => doc.id === current.value?.id));
const previousDocument = computed(() => currentDocumentIndex.value > 0 ? filteredDocuments.value[currentDocumentIndex.value - 1] : null);
const nextDocument = computed(() => currentDocumentIndex.value >= 0 ? filteredDocuments.value[currentDocumentIndex.value + 1] || null : null);

const groupLabels: Record<string, string> = {
  index: 'Commencer ici',
  'getting-started': 'Prise en main', content: 'Contenus', publication: 'Publication', media: 'Médias',
  'navigation-taxonomies': 'Navigation et classement', seo: 'Référencement', 'forms-cookies': 'Formulaires et consentement',
  'ai-assistant': 'Assistant IA', business: 'Activité et relations clients',
  'sites-and-languages': 'Sites et langues', 'users-roles-permissions': 'Utilisateurs et accès', 'content-model': 'Structure des contenus',
  modules: 'Modules', security: 'Sécurité et intégrations', 'imports-exports': 'Imports et exports',
  architecture: 'Architecture', extending: 'Étendre le CMS', 'testing-validation': 'Tests et validation', database: 'Base de données',
  backoffice: 'Back-office', 'frontend-twig': 'Rendu public', 'python-tooling': 'Outils en ligne de commande', generated: 'Références générées',
  'admin-internal': 'API du back-office', 'machine-readable': 'Données d’audit', catalogue: 'Catalogue et produits', crm: 'Clients et relations',
  vente: 'Ventes', operations: 'Opérations métier', general: 'Guides principaux'
};

function documentGroupKey(doc: DocsDocument): string {
  if (doc.is_section_index) return 'index';
  const relative = doc.relative_path.replace(`${doc.section_key}/`, '');
  const first = relative.split('/')[0];
  if (relative.includes('/')) return first;
  const filename = first.toLowerCase();
  if (doc.section_key === 'business') {
    if (filename.startsWith('catalogue')) return 'catalogue';
    if (filename.startsWith('crm')) return 'crm';
    if (filename.startsWith('vente') || filename === 'commerce.md') return 'vente';
    if (filename.startsWith('operations')) return 'operations';
  }
  return 'general';
}

function groupsForSection(section: DocsSection): Array<{ key: string; label: string; documents: DocsDocument[] }> {
  const groups = new Map<string, DocsDocument[]>();
  section.documents.forEach((doc) => {
    const key = documentGroupKey(doc);
    groups.set(key, [...(groups.get(key) || []), doc]);
  });
  return [...groups.entries()].map(([key, docs]) => ({ key, label: groupLabels[key] || titleizeAudience(key), documents: docs }));
}

function setSectionOpen(sectionKey: string, open: boolean): void {
  const next = new Set(openSections.value);
  if (open) next.add(sectionKey); else next.delete(sectionKey);
  openSections.value = next;
}

function onSectionToggle(sectionKey: string, event: Event): void {
  setSectionOpen(sectionKey, Boolean((event.currentTarget as HTMLDetailsElement | null)?.open));
}

function selectCollection(key: string): void {
  selectedCollection.value = key;
  q.value = '';
  const collection = collectionDefinitions.find((item) => item.key === key);
  const firstSection = sections.value.find((section) => collection?.sections.includes(section.key as never));
  if (!firstSection) return;
  openSections.value = new Set([firstSection.key]);
  const first = firstSection.documents.find((doc) => doc.is_section_index) || firstSection.documents[0];
  if (first) void selectDocument(first.id);
}

function slugHeading(value: string): string {
  return value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section';
}

const renderedDocument = computed(() => {
  if (!current.value?.html || typeof DOMParser === 'undefined') return { html: current.value?.html || '', headings: [] as Array<{ id: string; label: string; level: number }> };
  const parsed = new DOMParser().parseFromString(`<div>${current.value.html}</div>`, 'text/html');
  parsed.querySelector('h1')?.remove();
  const used = new Set<string>();
  const headings = [...parsed.querySelectorAll('h2,h3')].map((heading) => {
    const label = heading.textContent?.trim() || '';
    let id = heading.id || slugHeading(label);let suffix = 2;while (used.has(id)) id = `${slugHeading(label)}-${suffix++}`;used.add(id);heading.id = id;
    return { id, label, level: heading.tagName === 'H3' ? 3 : 2 };
  }).filter((heading) => heading.label);
  return { html: parsed.body.firstElementChild?.innerHTML || current.value.html, headings };
});

function scrollToHeading(id: string): void {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function requestedDocumentId(): string {
  const doc = route.query.doc;
  return Array.isArray(doc) ? String(doc[0] || '') : String(doc || '');
}

function labelForStatus(status?: string): string {
  if (!status) return '';
  const labels: Record<string, string> = { stable: 'stable', draft: 'brouillon', generated: 'généré' };
  return labels[status] || status;
}

function docAudiences(doc: DocsDocument): string[] {
  return Array.isArray(doc.audience) ? doc.audience.filter(Boolean) : [];
}

function titleizeAudience(value: string): string {
  return value
    .split(/[-_]/)
    .filter(Boolean)
    .map((part) => part.slice(0, 1).toUpperCase() + part.slice(1))
    .join(' ');
}

async function loadIndex(): Promise<void> {
  if (!canOpenDocs.value) return;
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<DocsIndexPayload>('/docs');
    sections.value = response.data.sections || [];
    documents.value = response.data.documents || [];
    const requested = requestedDocumentId();
    const first = requested
      ? documents.value.find((doc) => doc.id === requested) || { id: requested }
      : documents.value.find((doc) => doc.is_section_index) || documents.value[0];
    if (first && !selectedId.value) {
      const indexed = documents.value.find((doc) => doc.id === first.id);
      if (indexed) {
        selectedCollection.value = collectionForSection(indexed.section_key);
        openSections.value = new Set([indexed.section_key]);
      }
      await selectDocument(first.id);
    }
  } catch (err) {
    error.value = apiErrorMessage(err, 'Documentation indisponible.');
  } finally {
    loading.value = false;
  }
}

function selectAudience(audience: string): void {
  selectedAudience.value = audience;
  const currentVisible = current.value && filteredDocuments.value.some((doc) => doc.id === current.value?.id);
  if (!currentVisible) {
    const first = filteredDocuments.value.find((doc) => doc.is_section_index) || filteredDocuments.value[0];
    if (first) void selectDocument(first.id);
  }
}

function handleAudienceTabKeydown(event: KeyboardEvent, audience: string): void {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
  event.preventDefault();
  const tabs = profileTabs.value;
  const currentIndex = Math.max(0, tabs.findIndex((tab) => tab.key === audience));
  const targetIndex = event.key === 'Home'
    ? 0
    : event.key === 'End'
      ? tabs.length - 1
      : event.key === 'ArrowLeft'
        ? (currentIndex - 1 + tabs.length) % tabs.length
        : (currentIndex + 1) % tabs.length;
  const target = tabs[targetIndex];
  if (!target) return;
  selectAudience(target.key);
  requestAnimationFrame(() => document.getElementById(`docs-profile-tab-${target.key}`)?.focus());
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
    selectedCollection.value = collectionForSection(response.data.document.section_key);
    setSectionOpen(response.data.document.section_key, true);
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
watch(() => route.query.doc, (doc) => {
  const id = Array.isArray(doc) ? String(doc[0] || '') : String(doc || '');
  if (id && id !== selectedId.value) void selectDocument(id);
});
</script>

<template>
  <PageHeader
    title="Documentation"
    intro="Des parcours guidés pour utiliser, configurer et exploiter le CMS, avec les références techniques lorsque vous en avez besoin."
    help-id="docs.index"
  />

  <ApiFeedback :error="error" />

  <div v-if="!canOpenDocs" class="card empty-state">
    <h2>Session requise</h2>
    <p class="muted">Connectez-vous au back-office pour consulter la documentation.</p>
  </div>

  <template v-else>
    <section class="docs-collections" aria-labelledby="docs-needs-title">
      <div class="docs-collections__intro">
        <p class="eyebrow">Choisir un parcours</p>
        <h2 id="docs-needs-title">Que souhaitez-vous faire ?</h2>
      </div>
      <button
        v-for="collection in collections"
        :key="collection.key"
        type="button"
        :class="['docs-collection-card', { active: selectedCollection === collection.key }]"
        :aria-pressed="selectedCollection === collection.key"
        @click="selectCollection(collection.key)"
      >
        <span class="docs-collection-card__title">{{ collection.label }}</span>
        <span class="docs-collection-card__description">{{ collection.description }}</span>
        <span class="docs-collection-card__count">{{ collection.count }} page{{ collection.count > 1 ? 's' : '' }}</span>
      </button>
    </section>

    <section class="card docs-toolbar">
      <label class="docs-toolbar__field docs-toolbar__search">
        <span>Rechercher dans toute la documentation</span>
        <input v-model="q" class="input" type="search" placeholder="Ex. publier une page, sauvegarder, droits d’accès…" />
      </label>
      <label class="docs-toolbar__field docs-toolbar__profile">
        <span>Adapter les résultats à mon profil</span>
        <select v-model="selectedAudience" class="select" @change="selectAudience(selectedAudience)">
          <option v-for="tab in profileTabs" :key="tab.key" :value="tab.key">
            {{ tab.label }} ({{ tab.count }})
          </option>
        </select>
      </label>
      <p v-if="q" class="docs-toolbar__hint">
        {{ filteredDocuments.length }} résultat{{ filteredDocuments.length > 1 ? 's' : '' }} dans l’ensemble des espaces.
      </p>
    </section>

    <section class="docs-layout">
      <aside class="card docs-sidebar" aria-label="Documents disponibles">
        <header class="docs-sidebar__header">
          <span class="eyebrow">Parcours actif</span>
          <strong>{{ collections.find((collection) => collection.key === selectedCollection)?.label }}</strong>
          <span class="muted">{{ filteredDocuments.length }} page{{ filteredDocuments.length > 1 ? 's' : '' }}</span>
        </header>
        <div v-if="loading" class="muted">Chargement de la documentation…</div>
        <div v-else-if="filteredSections.length === 0" class="muted">Aucun document ne correspond au filtre.</div>
        <details
          v-for="section in filteredSections"
          :key="section.key"
          class="docs-section-list"
          :open="Boolean(q) || openSections.has(section.key)"
          @toggle="onSectionToggle(section.key, $event)"
        >
          <summary>
            <span>
              <strong>{{ section.label }}</strong>
              <small>{{ section.description }}</small>
            </span>
            <span class="docs-section-list__count">{{ section.documents.length }}</span>
          </summary>
          <div class="docs-section-list__body">
            <div v-for="group in groupsForSection(section)" :key="group.key" class="docs-nav-group">
              <h3 v-if="groupsForSection(section).length > 1">{{ group.label }}</h3>
              <button
                v-for="doc in group.documents"
                :key="doc.id"
                class="docs-nav-item"
                :class="{ active: doc.id === selectedId }"
                type="button"
                @click="selectDocument(doc.id)"
              >
                <strong>{{ doc.title }}</strong>
                <span v-if="doc.summary">{{ doc.summary }}</span>
              </button>
            </div>
          </div>
        </details>
      </aside>

      <div class="docs-reader-layout">
      <article class="card docs-viewer">
        <div v-if="documentLoading" class="muted">Ouverture du document…</div>
        <div v-else-if="!current" class="empty-state">
          <strong>Choisissez une page</strong>
          <span>La navigation de gauche vous guide par sujet et par tâche.</span>
        </div>
        <template v-else>
          <header class="docs-viewer__header">
            <p class="eyebrow">{{ currentSection?.label || current.section_label }}</p>
            <h1>{{ current.title }}</h1>
            <p v-if="current.summary" class="docs-viewer__summary">{{ current.summary }}</p>
            <details class="docs-source-details">
              <summary>Informations sur cette page</summary>
              <div>
                <span v-if="labelForStatus(current.status)">Statut : {{ labelForStatus(current.status) }}</span>
                <span v-if="current.last_verified">Vérifiée le {{ current.last_verified }}</span>
                <span>Source : {{ current.source_path }}</span>
              </div>
            </details>
          </header>
          <div class="docs-markdown" v-html="renderedDocument.html" @click="onMarkdownClick"></div>
          <nav v-if="previousDocument || nextDocument" class="docs-page-navigation" aria-label="Pages précédente et suivante">
            <button v-if="previousDocument" type="button" class="docs-page-navigation__link previous" @click="selectDocument(previousDocument.id)">
              <span>Page précédente</span>
              <strong>{{ previousDocument.title }}</strong>
            </button>
            <span v-else></span>
            <button v-if="nextDocument" type="button" class="docs-page-navigation__link next" @click="selectDocument(nextDocument.id)">
              <span>Page suivante</span>
              <strong>{{ nextDocument.title }}</strong>
            </button>
          </nav>
        </template>
      </article>
      <aside v-if="current && renderedDocument.headings.length" class="docs-on-this-page" aria-label="Sommaire de la page">
        <strong>Sur cette page</strong>
        <button
          v-for="heading in renderedDocument.headings"
          :key="heading.id"
          type="button"
          :class="{ nested: heading.level === 3 }"
          @click="scrollToHeading(heading.id)"
        >
          {{ heading.label }}
        </button>
      </aside>
      </div>
    </section>
  </template>
</template>
