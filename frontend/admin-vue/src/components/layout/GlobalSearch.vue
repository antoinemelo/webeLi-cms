<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { adminApi } from '@/api/client';
import { canShow, dashboardLinks, mainNavigation, moduleSearchActions, moduleWorkbenchLinks, studioLinks, type SectionLink } from '@/router/navigation';
import { useAdminContextStore } from '@/stores/adminContext';
import { useContentTypesStore } from '@/stores/contentTypes';
import { hasTranslation, useI18n } from '@/i18n';
import type { ContentTypeSummary, EntryListItem } from '@/api/contracts';

type SearchItem = {
  id: string;
  kind: 'action' | 'content';
  title: string;
  meta?: string;
  route?: string;
  score?: number;
};

type SearchAction = {
  key: string;
  label: string;
  route: string;
  section: string;
  permission?: string;
  keywords: string[];
};

const context = useAdminContextStore();
const contentTypes = useContentTypesStore();
const router = useRouter();
const { t } = useI18n();

const query = ref('');
const isOpen = ref(false);
const loading = ref(false);
const activeIndex = ref(0);
const contentResults = ref<SearchItem[]>([]);
let debounceTimer: number | undefined;
let requestSerial = 0;

const siteName = computed(() => context.context?.site.name || 'DEC CMS');
const placeholder = computed(() => t('search.placeholder', { site: siteName.value }));
const normalizedQuery = computed(() => normalize(query.value));
const hasEnoughCharacters = computed(() => normalizedQuery.value.length >= 3);

const actions = computed<SearchAction[]>(() => buildSearchActions(contentTypes.types));

const actionResults = computed<SearchItem[]>(() => {
  if (!hasEnoughCharacters.value) return [];
  const terms = normalizedQuery.value.split(' ').filter(Boolean);
  return actions.value
    .filter((item) => canShow(item.permission, context.can))
    .map((item) => ({
      id: `action:${item.key}`,
      kind: 'action' as const,
      title: item.label,
      meta: item.section,
      route: item.route,
      score: scoreAction(item, terms)
    }))
    .filter((item) => (item.score ?? 0) > 0)
    .sort((a, b) => (b.score ?? 0) - (a.score ?? 0) || a.title.localeCompare(b.title))
    .slice(0, 8);
});

const results = computed<SearchItem[]>(() => [...actionResults.value, ...contentResults.value].slice(0, 12));

onMounted(() => {
  contentTypes.loadTypes().catch(() => undefined);
});

watch(query, () => {
  activeIndex.value = 0;
  contentResults.value = [];
  if (debounceTimer) window.clearTimeout(debounceTimer);
  if (!hasEnoughCharacters.value) {
    isOpen.value = false;
    loading.value = false;
    return;
  }
  isOpen.value = true;
  debounceTimer = window.setTimeout(fetchContentResults, 220);
});

watch(() => [context.siteId, context.languageCode], () => {
  if (hasEnoughCharacters.value) fetchContentResults();
});

function buildSearchActions(types: ContentTypeSummary[]): SearchAction[] {
  const byRoute = new Map<string, SearchAction>();

  const add = (action: SearchAction) => {
    if (!action.route || byRoute.has(action.route)) return;
    byRoute.set(action.route, action);
  };

  const addLink = (link: SectionLink, section: string, prefix = '') => {
    const label = linkLabel(link);
    const hint = linkHint(link);
    const displayLabel = prefix ? `${prefix} ${label}` : label;
    add({
      key: `${section}:${link.route}`,
      label: displayLabel,
      route: link.route,
      section,
      permission: link.permission,
      keywords: [label, link.label, hint, link.hint ?? '', section, prefix]
    });
  };

  mainNavigation.forEach((link) => add({
    key: `nav:${link.key}`,
    label: linkLabel(link),
    route: link.route,
    section: t('search.section.navigation'),
    permission: link.permission,
    keywords: [linkLabel(link), link.label, link.key]
  }));

  studioLinks.forEach((link) => addLink(link, t('search.section.studio'), t('search.prefix.list')));
  dashboardLinks.forEach((link) => addLink(link, t('search.section.dashboard')));
  moduleWorkbenchLinks.forEach((link) => addLink(link, t('search.section.modules')));
  moduleSearchActions(context.moduleNavigation).forEach((action) => add({
    key: action.key,
    label: action.label,
    route: action.route,
    section: action.section === 'Modules' ? t('search.section.modules') : action.section,
    permission: action.permission,
    keywords: action.keywords
  }));

  types.filter((type) => type.is_active !== false).forEach((type) => {
    const routeSegment = contentTypeRouteSegment(type.type_key);
    const singular = labelFor(type, 'singular');
    const plural = labelFor(type, 'plural');
    const isArchiveType = ['page_archive', 'article_archive'].includes(type.type_key);
    const createPermission = isArchiveType ? 'content.archive' : (type.capabilities?.create === false ? undefined : 'content.create');
    const listPermission = isArchiveType ? 'content.archive' : 'content.read';
    add({
      key: `create:${type.type_key}`,
      label: t('search.createContent', { label: withArticle(singular) }),
      route: `/contents/${routeSegment}/new`,
      section: t('search.section.create'),
      permission: createPermission,
      keywords: [t('search.keyword.create'), singular, plural, type.type_key]
    });
    add({
      key: `list:${type.type_key}`,
      label: t('search.listContent', { label: withArticle(plural) }),
      route: `/contents/${routeSegment}`,
      section: t('search.section.contents'),
      permission: listPermission,
      keywords: [t('search.keyword.list'), singular, plural, type.type_key]
    });
  });

  return Array.from(byRoute.values());
}

function linkLabel(link: SectionLink): string {
  return link.labelKey ? t(link.labelKey) : link.label;
}

function linkHint(link: SectionLink): string {
  return link.hintKey ? t(link.hintKey) : (link.hint ?? '');
}

function labelFor(type: ContentTypeSummary, mode: 'singular' | 'plural'): string {
  const fallback = type.name || type.type_key;
  return String(mode === 'singular' ? (type.singular_label || fallback) : (type.plural_label || type.name || fallback));
}

function withArticle(label: string): string {
  const normalized = label.trim();
  if (!normalized) return 'un contenu';
  if (/^(un|une|des|l'|la|le|les)\s/i.test(normalized)) return normalized;
  const first = normalized[0]?.toLowerCase() ?? '';
  return ['a', 'e', 'i', 'o', 'u', 'y', 'h'].includes(first) ? `un ${normalized}` : `une ${normalized}`;
}

function contentTypeRouteSegment(typeKey: string): string {
  if (typeKey === 'page') return 'pages';
  if (typeKey === 'article') return 'articles';
  return typeKey;
}

function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

function scoreAction(item: SearchAction, terms: string[]): number {
  const label = normalize(item.label);
  const section = normalize(item.section);
  const keywords = normalize(item.keywords.join(' '));
  const haystack = `${label} ${section} ${keywords}`.trim();
  let score = 0;
  for (const term of terms) {
    if (label === term) score += 120;
    else if (label.startsWith(term)) score += 70;
    else if (label.includes(` ${term}`)) score += 46;
    else if (keywords.includes(term)) score += 30;
    else if (haystack.includes(term)) score += 12;
  }
  return score;
}

async function fetchContentResults() {
  const q = query.value.trim();
  if (normalize(q).length < 3) return;
  const serial = ++requestSerial;
  loading.value = true;
  try {
    const response = await adminApi.get<EntryListItem[]>('/entries', {
      site_id: context.siteId,
      language_code: context.languageCode,
      q,
      limit: 6,
      offset: 0
    });
    if (serial !== requestSerial) return;
    contentResults.value = response.data.map((row) => ({
      id: `content:${row.id}`,
      kind: 'content',
      title: row.title || `#${row.id}`,
      meta: contentMeta(row),
      route: `/contents/${contentTypeRouteSegment(row.content_type_key)}/${row.id}`
    }));
  } catch (_) {
    if (serial === requestSerial) contentResults.value = [];
  } finally {
    if (serial === requestSerial) loading.value = false;
  }
}

function contentMeta(row: EntryListItem): string {
  const type = row.content_type_key || 'contenu';
  const status = row.status ? ` · ${statusLabel(row.status)}` : '';
  return `${type}${status}`;
}

function statusLabel(status: string): string {
  const key = `core.status.${status}`;
  return hasTranslation(key, context.uiLanguageCode) ? t(key) : status;
}

function submitSearch() {
  if (results.value.length > 0) {
    go(results.value[activeIndex.value] ?? results.value[0]);
    return;
  }
  const q = query.value.trim();
  if (!q) return;
  router.push({ path: '/contents/pages', query: { q } });
}

function go(item: SearchItem) {
  if (item.route) router.push(item.route);
  query.value = '';
  isOpen.value = false;
}

function onFocus() {
  if (hasEnoughCharacters.value) isOpen.value = true;
}

function closeSoon() {
  window.setTimeout(() => { isOpen.value = false; }, 140);
}

function onKeydown(event: KeyboardEvent) {
  if (!isOpen.value || results.value.length === 0) return;
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    activeIndex.value = (activeIndex.value + 1) % results.value.length;
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    activeIndex.value = (activeIndex.value - 1 + results.value.length) % results.value.length;
  } else if (event.key === 'Escape') {
    isOpen.value = false;
  }
}

onBeforeUnmount(() => {
  if (debounceTimer) window.clearTimeout(debounceTimer);
});
</script>

<template>
  <div class="global-search">
    <form class="topnav-search global-search-form" role="search" @submit.prevent="submitSearch">
      <input
        v-model="query"
        type="search"
        :placeholder="placeholder"
        autocomplete="off"
        @focus="onFocus"
        @blur="closeSoon"
        @keydown="onKeydown"
      />
    </form>

    <div v-if="isOpen" class="global-search-menu card" role="listbox" :aria-label="t('search.results')">
      <div v-if="loading" class="global-search-state">{{ t('search.loading') }}</div>
      <template v-if="results.length > 0">
        <button
          v-for="(item, index) in results"
          :key="item.id"
          class="global-search-item"
          :class="{ active: index === activeIndex }"
          type="button"
          role="option"
          :aria-selected="index === activeIndex"
          @mousedown.prevent="go(item)"
        >
          <span class="global-search-kind">{{ item.kind === 'action' ? t('search.action') : t('search.content') }}</span>
          <span class="global-search-item__body">
            <strong>{{ item.title }}</strong>
            <small v-if="item.meta">{{ item.meta }}</small>
          </span>
        </button>
      </template>
      <p v-else-if="!loading" class="global-search-state">
        {{ hasEnoughCharacters ? t('search.empty') : t('search.help') }}
      </p>
    </div>
  </div>
</template>
