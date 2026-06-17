<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import AdminSectionRenderer from '@/components/admin/AdminSectionRenderer.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { adminSections } from '@/admin/adminSections';
import { useAdminContextStore } from '@/stores/adminContext';
import type { EntryListItem } from '@/api/contracts';

const context = useAdminContextStore();
const recent = ref<EntryListItem[]>([]);
const loading = ref(false);
const error = ref('');


function relativeDate(iso?: string | null): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (isNaN(date.getTime())) return '—';
  const diff = Date.now() - date.getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 2) return "À l\'instant";
  if (mins < 60) return `il y a ${mins} min`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `il y a ${hours} h`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `il y a ${days} j`;
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

function listRouteForContentType(typeKey: unknown): string {
  const key = String(typeKey || 'page');
  if (key === 'article') return '/contents/articles';
  if (key === 'article_archive') return '/contents/article_archives';
  if (key === 'page_archive') return '/contents/page_archives';
  return '/contents/pages';
}

function labelForContentType(typeKey: unknown): string {
  const key = String(typeKey || 'page');
  if (key === 'article') return 'Article';
  if (key === 'article_archive') return 'Archive d’articles';
  if (key === 'page_archive') return 'Archive de pages';
  return 'Page';
}

async function loadRecentEntries(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const entries = await adminApi.get<EntryListItem[]>('/entries', {
      site_id: context.siteId,
      language_code: context.languageCode,
      limit: 6,
      offset: 0
    });
    recent.value = Array.isArray(entries.data) ? entries.data : [];
  } catch (err) {
    error.value = apiErrorMessage(err, 'Derniers contenus indisponibles.');
  } finally {
    loading.value = false;
  }
}

onMounted(loadRecentEntries);
watch(() => [context.siteId, context.languageCode], loadRecentEntries);
</script>

<template>
  <AdminSectionRenderer :section="adminSections.studio" />
  <ApiFeedback :error="error" />

  <section class="dashboard-recent-card card">
    <header class="split-head dashboard-recent-head">
      <div>
        <p class="eyebrow">Activité</p>
        <h2>Derniers contenus</h2>
      </div>
      <RouterLink class="btn ghost" to="/contents/pages">Voir tous les contenus</RouterLink>
    </header>

    <div v-if="loading" class="dashboard-recent-skeleton">
      <div v-for="i in 5" :key="i" class="skeleton skeleton-row-full"></div>
    </div>
    <ul v-else-if="recent.length" class="dashboard-recent-list">
      <li v-for="entry in recent" :key="entry.id" class="dashboard-recent-row">
        <RouterLink class="dashboard-recent-link" :to="`${listRouteForContentType(entry.content_type_key)}/${entry.id}`">
          <span class="dashboard-recent-title">{{ entry.title || `#${entry.id}` }}</span>
          <span class="dashboard-recent-type muted">{{ labelForContentType(entry.content_type_key) }}</span>
        </RouterLink>
        <div class="dashboard-recent-meta">
          <span class="badge" :class="entry.status">{{ entry.status }}</span>
          <span class="muted dashboard-recent-date">{{ relativeDate(entry.updated_at) }}</span>
        </div>
      </li>
    </ul>
    <p v-else class="muted" style="padding:.5rem 0">Aucun contenu récent à afficher.</p>
  </section>
</template>
