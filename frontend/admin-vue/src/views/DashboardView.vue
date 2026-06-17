<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import AdminSectionRenderer from '@/components/admin/AdminSectionRenderer.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { adminSections } from '@/admin/adminSections';
import { useAdminContextStore } from '@/stores/adminContext';
import type { EntryListItem } from '@/api/contracts';

type SeoAuditPayload = { summary?: Record<string, number> };

const context = useAdminContextStore();
const recent = ref<EntryListItem[]>([]);
const seoSummary = ref<Record<string, number>>({});
const loading = ref(false);
const error = ref('');

const unresolvedSeo = computed(() => seoSummary.value.unresolved ?? seoSummary.value.issues_total ?? 0);
const failedChecks = computed(() => seoSummary.value.technical_checks_failed ?? 0);
const canReadContent = computed(() => context.can('content.read'));

const seoStatusTone = computed(() => {
  if (unresolvedSeo.value > 10) return 'danger';
  if (unresolvedSeo.value > 0) return 'warning';
  return 'success';
});

const technicalTone = computed(() => {
  if (failedChecks.value > 5) return 'danger';
  if (failedChecks.value > 0) return 'warning';
  return 'success';
});

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
  if (key === 'article_archive') return "Archive d\'articles";
  if (key === 'page_archive') return 'Archive de pages';
  return 'Page';
}

async function loadDashboard(): Promise<void> {
  if (!canReadContent.value) {
    recent.value = [];
    seoSummary.value = {};
    loading.value = false;
    error.value = '';
    return;
  }
  loading.value = true;
  error.value = '';
  try {
    const [entries, audit] = await Promise.all([
      adminApi.get<EntryListItem[]>('/entries', { site_id: context.siteId, language_code: context.languageCode, limit: 6, offset: 0 }),
      adminApi.get<SeoAuditPayload>('/seo/audit', { site_id: context.siteId, language_code: context.languageCode, include_resolved: 0, include_technical: 1 })
    ]);
    recent.value = Array.isArray(entries.data) ? entries.data : [];
    seoSummary.value = audit.data.summary ?? {};
  } catch (err) {
    error.value = apiErrorMessage(err, 'Cockpit indisponible.');
  } finally {
    loading.value = false;
  }
}

onMounted(loadDashboard);
watch(() => [context.siteId, context.languageCode], loadDashboard);
</script>

<template>
  <!-- KPI strip -->
  <section class="stats-grid editorial-stats dashboard-top-stats" aria-label="Indicateurs">
    <article class="card stat-card">
      <p class="eyebrow">Site actif</p>
      <strong>{{ context.context?.site.name || '—' }}</strong>
      <small class="muted">{{ context.languageCode.toUpperCase() }} · {{ context.context?.api.contract_version || 'v1' }}</small>
    </article>
    <article class="card stat-card" :class="{ 'stat-card--loading': loading }">
      <p class="eyebrow">Contenus récents</p>
      <strong>{{ loading ? '…' : recent.length }}</strong>
      <small class="muted">Dernières entrées chargées</small>
    </article>
    <article class="card stat-card" :class="`stat-card--${seoStatusTone}`">
      <p class="eyebrow">Issues SEO</p>
      <strong>{{ loading ? '…' : unresolvedSeo }}</strong>
      <small class="muted">{{ unresolvedSeo === 0 ? 'Aucun problème ouvert' : 'Problèmes ouverts' }}</small>
    </article>
    <article class="card stat-card" :class="`stat-card--${technicalTone}`">
      <p class="eyebrow">Checks techniques</p>
      <strong>{{ loading ? '…' : failedChecks }}</strong>
      <small class="muted">{{ failedChecks === 0 ? 'Tous les checks passent' : 'Échecs à corriger' }}</small>
    </article>
  </section>

  <div v-if="!canReadContent" class="card empty-state">
    <h2>Session ouverte</h2>
    <p class="muted">Votre compte est actif, mais aucun droit de back-office ne lui est encore attribué pour ce site. Vous pouvez accéder à votre profil et vous déconnecter normalement.</p>
  </div>
  <template v-else>
    <!-- Quick actions -->
    <section class="dashboard-quick-actions card" aria-labelledby="quick-actions-title">
      <p class="eyebrow" id="quick-actions-title">Actions rapides</p>
      <div class="quick-action-grid">
        <RouterLink class="quick-action-btn" to="/contents/pages/new">
          <svg viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zM9.5 1v3a1 1 0 0 0 1 1h3L9.5 1zM4 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V6h-2.5A1.5 1.5 0 0 1 9 4.5V1H4z"/><path fill="currentColor" d="M8 6.5a.5.5 0 0 1 .5.5v2H10a.5.5 0 0 1 0 1H8.5v2a.5.5 0 0 1-1 0v-2H6a.5.5 0 0 1 0-1h1.5V7a.5.5 0 0 1 .5-.5z"/></svg>
          Nouvelle page
        </RouterLink>
        <RouterLink class="quick-action-btn" to="/contents/articles/new">
          <svg viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4 0h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2zm0 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H4zm2 4h4v1H6V5zm0 2.5h4v1H6v-1zm0 2.5h3v1H6v-1z"/></svg>
          Nouvel article
        </RouterLink>
        <RouterLink class="quick-action-btn" to="/media">
          <svg viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zM2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/></svg>
          Bibliothèque médias
        </RouterLink>
        <RouterLink class="quick-action-btn" to="/seo/audit">
          <svg viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
          Audit SEO
        </RouterLink>
      </div>
    </section>

    <AdminSectionRenderer :section="adminSections.dashboard" />

    <!-- Recent content list -->
    <section class="dashboard-recent-card card" aria-labelledby="recent-content-title">
      <header class="split-head dashboard-recent-head">
        <div>
          <p class="eyebrow">Activité</p>
          <h2 id="recent-content-title">Derniers contenus</h2>
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

  <ApiFeedback :error="error" />
</template>
