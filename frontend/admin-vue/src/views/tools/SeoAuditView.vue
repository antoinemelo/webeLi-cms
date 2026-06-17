<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '../../api/client';
import { useAdminContextStore } from '../../stores/adminContext';
import ApiFeedback from '../../components/feedback/ApiFeedback.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import InfoHint from '../../components/ui/InfoHint.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import DataTable from '../../components/ui/DataTable.vue';

type SeoIssue = {
  id: number;
  resource_type: string;
  resource_id: number;
  language_code: string;
  issue_code: string;
  severity: string;
  message: string;
  is_resolved: boolean;
  detected_at: string;
  resource?: { title?: string; entry_key?: string; public_path?: string; admin_edit_path?: string };
};

type TechnicalCheck = {
  code: string;
  severity: string;
  passed: boolean;
  count: number;
  message: string;
  recommendation?: string;
};

type PageRecommendation = { scope: string; severity: string; code: string; text: string };
type PageCheck = { code: string; severity: string; passed: boolean; label: string; recommendation: string; details?: Record<string, unknown> };
type SeoPage = {
  resource_id: number;
  title: string;
  entry_key: string;
  content_type_key: string;
  public_path: string;
  admin_edit_path: string;
  status: string;
  issue_count: number;
  scores: { overall: number; search_engines: number; technical: number; ai_readiness: number };
  checks: PageCheck[];
  recommendations: PageRecommendation[];
  serp_preview: { title: string; url: string; description: string };
  ai_preview: { answer_title: string; summary: string; signals: string[] };
};

type SeoAuditPayload = {
  summary: Record<string, number>;
  pages: SeoPage[];
  issues: SeoIssue[];
  technical_checks: TechnicalCheck[];
  guidance?: { score_model?: string; home_examples?: string[]; priority_order?: string[] };
};

const context = useAdminContextStore();
const loading = ref(false);
const error = ref('');
const payload = ref<SeoAuditPayload | null>(null);
const includeResolved = ref(false);
const selectedPageId = ref<number | null>(null);

const pages = computed(() => payload.value?.pages ?? []);
const selectedPage = computed(() => pages.value.find((page) => page.resource_id === selectedPageId.value) ?? pages.value[0] ?? null);

const rows = computed(() => pages.value
  .map((page) => ({
    id: page.resource_id,
    score: page.scores.overall,
    resource: page.title || page.entry_key || `Page #${page.resource_id}`,
    issue_count: page.issue_count,
    edit_path: page.admin_edit_path || ''
  }))
  .sort((a, b) => (b.score - a.score) || (b.issue_count - a.issue_count) || a.resource.localeCompare(b.resource))
);

const issueRows = computed(() => (payload.value?.issues ?? []).map((issue) => ({
  id: issue.id,
  severity: issue.severity,
  resource: issue.resource?.title || issue.resource?.entry_key || `${issue.resource_type} #${issue.resource_id}`,
  public_path: issue.resource?.public_path || '—',
  issue_code: issue.issue_code,
  message: issue.message,
  detected_at: issue.detected_at,
  edit_path: issue.resource?.admin_edit_path || ''
})));

function scoreClass(score: number): string {
  if (score >= 85) return 'score-ring score-ring--excellent';
  if (score >= 70) return 'score-ring score-ring--good';
  if (score >= 50) return 'score-ring score-ring--warning';
  return 'score-ring score-ring--critical';
}

function progressClass(score: number): string {
  if (score >= 85) return 'progress-bar bg-success';
  if (score >= 70) return 'progress-bar bg-primary';
  if (score >= 50) return 'progress-bar bg-warning';
  return 'progress-bar bg-danger';
}

async function loadAudit(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<SeoAuditPayload>('/seo/audit', {
      site_id: context.siteId,
      language_code: context.languageCode,
      include_resolved: includeResolved.value ? 1 : 0,
      include_technical: 1
    });
    payload.value = response.data;
    if (!selectedPageId.value || !response.data.pages.some((page) => page.resource_id === selectedPageId.value)) {
      selectedPageId.value = response.data.pages[0]?.resource_id ?? null;
    }
  } catch (err) {
    error.value = apiErrorMessage(err, 'Audit SEO indisponible.');
  } finally {
    loading.value = false;
  }
}

onMounted(loadAudit);
watch(() => [context.siteId, context.languageCode, includeResolved.value], loadAudit);
</script>

<template>
  <PageHeader title="Audit SEO & IA" intro="Scoring par page, problèmes détectés, aperçu moteur de recherche et recommandations orientées moteurs + assistants IA.">
    <template #actions>
      <label class="checkbox-inline">
        <input v-model="includeResolved" type="checkbox">
        Inclure les résolus
      </label>
      <button class="btn btn-primary" :disabled="loading" @click="loadAudit">Rafraîchir</button>
    </template>
  </PageHeader>

  <ApiFeedback :error="error" />

  <div v-if="loading" class="card muted">Chargement de l’audit…</div>

  <template v-else-if="payload">
    <section class="seo-summary-grid mb-3">
      <article>
        <div class="card h-100"><span class="muted">Score moyen</span><strong class="display-score">{{ payload.summary.average_score ?? '—' }}</strong></div>
      </article>
      <article>
        <div class="card h-100"><span class="muted">Pages auditées</span><strong class="display-score">{{ payload.summary.pages_total ?? 0 }}</strong></div>
      </article>
      <article>
        <div class="card h-100"><span class="muted">Problèmes</span><strong class="display-score">{{ payload.summary.issues_total ?? 0 }}</strong></div>
      </article>
      <article>
        <div class="card h-100">
          <span class="muted">Checks échoués</span>
          <strong class="display-score">{{ payload.summary.technical_checks_failed ?? 0 }}</strong>
          <small class="text-muted">Contrôles automatiques non validés.</small>
        </div>
      </article>
    </section>

    <section class="card mb-3">
      <h2 class="h5">Toutes les pages</h2>
      <DataTable
        :columns="[
          { key: 'score', label: 'Score' },
          { key: 'resource', label: 'Page' },
          { key: 'issue_count', label: 'Problèmes' }
        ]"
        :rows="rows"
      >
        <template #cell-score="{ row }"><button class="btn btn-light btn-sm" @click="selectedPageId = Number(row.id)">{{ row.score }}</button></template>
        <template #cell-resource="{ row }">
          <RouterLink v-if="row.edit_path" :to="String(row.edit_path).replace('/admin/app', '')">{{ row.resource }}</RouterLink>
          <span v-else>{{ row.resource }}</span>
        </template>
      </DataTable>
    </section>

    <section class="card mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <h2 class="h5 mb-1 d-inline-flex align-items-center gap-2 flex-wrap">
            <span>Sélection de la page</span>
            <InfoHint text="Chaque page publiée peut être analysée individuellement." placement="end" />
          </h2>
        </div>
        <select v-model.number="selectedPageId" class="form-select seo-page-select">
          <option v-for="page in pages" :key="page.resource_id" :value="page.resource_id">
            {{ page.public_path }} — {{ page.title || page.entry_key }}
          </option>
        </select>
      </div>
    </section>

    <section v-if="selectedPage" class="seo-detail-grid mb-3">
      <article class="card seo-score-card">
        <div :class="scoreClass(selectedPage.scores.overall)">{{ selectedPage.scores.overall }}</div>
        <div>
          <p class="eyebrow">Score global</p>
          <h2>{{ selectedPage.title || selectedPage.entry_key }}</h2>
          <p class="text-muted">{{ payload.guidance?.score_model }}</p>
        </div>
      </article>

      <article class="card seo-breakdown">
        <h2 class="h5">Détail du scoring</h2>
        <div class="score-line">
          <span>Moteurs</span>
          <div class="progress"><div :class="progressClass(selectedPage.scores.search_engines)" :style="{ width: `${selectedPage.scores.search_engines}%` }"></div></div>
          <strong>{{ selectedPage.scores.search_engines }}</strong>
        </div>
        <div class="score-line">
          <span>Technique</span>
          <div class="progress"><div :class="progressClass(selectedPage.scores.technical)" :style="{ width: `${selectedPage.scores.technical}%` }"></div></div>
          <strong>{{ selectedPage.scores.technical }}</strong>
        </div>
        <div class="score-line">
          <span>IA</span>
          <div class="progress"><div :class="progressClass(selectedPage.scores.ai_readiness)" :style="{ width: `${selectedPage.scores.ai_readiness}%` }"></div></div>
          <strong>{{ selectedPage.scores.ai_readiness }}</strong>
        </div>
      </article>

      <article class="card serp-preview">
        <p class="eyebrow">Aperçu moteur de recherche</p>
        <div class="serp-title">{{ selectedPage.serp_preview.title }}</div>
        <div class="serp-url">{{ selectedPage.serp_preview.url }}</div>
        <p>{{ selectedPage.serp_preview.description }}</p>
      </article>

      <article class="card ai-preview">
        <p class="eyebrow">Aperçu réponse IA</p>
        <h2>{{ selectedPage.ai_preview.answer_title }}</h2>
        <p>{{ selectedPage.ai_preview.summary }}</p>
        <div class="d-flex flex-wrap gap-2">
          <span v-for="signal in selectedPage.ai_preview.signals" :key="signal" class="badge rounded-pill text-bg-light">{{ signal }}</span>
        </div>
      </article>
    </section>

    <section v-if="selectedPage" class="card stack mb-3">
      <h2 class="h5">Recommandations prioritaires</h2>
      <div v-if="!selectedPage.recommendations.length" class="text-muted">Aucune recommandation prioritaire pour cette page.</div>
      <div v-for="recommendation in selectedPage.recommendations" :key="recommendation.code + recommendation.text" class="list-row">
        <p class="mb-0">{{ recommendation.text }}</p>
        <StatusBadge :status="recommendation.severity" />
      </div>
    </section>

    <section v-if="issueRows.length" class="card mb-3">
      <h2 class="h5">Liste détaillée des problèmes</h2>
      <DataTable
        :columns="[
          { key: 'severity', label: 'Sévérité' },
          { key: 'resource', label: 'Ressource' },
          { key: 'public_path', label: 'URL' },
          { key: 'issue_code', label: 'Code' },
          { key: 'message', label: 'Message' },
          { key: 'detected_at', label: 'Détection' }
        ]"
        :rows="issueRows"
      >
        <template #cell-severity="{ row }"><StatusBadge :status="String(row.severity)" /></template>
        <template #cell-resource="{ row }">
          <RouterLink v-if="row.edit_path" :to="String(row.edit_path).replace('/admin/app', '')">{{ row.resource }}</RouterLink>
          <span v-else>{{ row.resource }}</span>
        </template>
        <template #cell-public_path="{ row }"><code>{{ row.public_path }}</code></template>
      </DataTable>
    </section>
  </template>
</template>
