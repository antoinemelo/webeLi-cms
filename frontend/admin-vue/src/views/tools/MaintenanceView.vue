<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import { useAdminContextStore } from '@/stores/adminContext';

type DirectoryStats = { exists: boolean; files: number; size_bytes: number; writable: boolean };
type MaintenanceStatus = {
  cache: DirectoryStats;
  runtime_logs: DirectoryStats;
  audit_rows: number;
  published_entries: number;
  search_documents: number;
  search_documents_current_language: number;
};
type AuditLog = { id: number; actor_email?: string; action_key: string; resource_type?: string; resource_id?: number; context_json?: string; ip_address?: string; created_at: string };
type RuntimeLog = { name: string; size_bytes: number; updated_at: string; tail: string[] };
type MaintenancePayload = { status: MaintenanceStatus; audit_logs: AuditLog[]; runtime_logs: RuntimeLog[] };

const context = useAdminContextStore();
const status = ref<MaintenanceStatus | null>(null);
const auditLogs = ref<AuditLog[]>([]);
const runtimeLogs = ref<RuntimeLog[]>([]);
const loading = ref(false);
const busyAction = ref('');
const error = ref('');
const message = ref('');

const canMaintain = computed(() => context.can('maintenance.manage'));
const auditRows = computed(() => auditLogs.value.map((row) => ({
  id: row.id,
  created_at: row.created_at,
  actor: row.actor_email || 'Système',
  action_key: row.action_key,
  resource: `${row.resource_type || '—'} ${row.resource_id || ''}`.trim(),
  ip: row.ip_address || '—'
})));

function formatBytes(bytes = 0): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
  return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

async function load(): Promise<void> {
  if (!canMaintain.value) return;
  loading.value = true;
  error.value = '';
  try {
    const res = await adminApi.get<MaintenancePayload>('/maintenance', { site_id: context.siteId, language_code: context.languageCode, limit: 120 });
    status.value = res.data.status;
    auditLogs.value = res.data.audit_logs || [];
    runtimeLogs.value = res.data.runtime_logs || [];
  } catch (err) {
    error.value = apiErrorMessage(err, 'Module de maintenance indisponible.');
  } finally {
    loading.value = false;
  }
}

async function runAction(action: 'clear-cache' | 'reindex-search' | 'clear-audit' | 'clear-runtime'): Promise<void> {
  busyAction.value = action;
  error.value = '';
  message.value = '';
  try {
    const query = `?site_id=${context.siteId}&language_code=${encodeURIComponent(context.languageCode)}`;
    const res = action === 'clear-cache'
      ? await adminApi.post<{ message: string; status: MaintenanceStatus }>('/maintenance/cache/clear' + query, {})
      : action === 'reindex-search'
        ? await adminApi.post<{ message: string; status: MaintenanceStatus }>('/maintenance/search/reindex' + query, {})
        : action === 'clear-audit'
          ? await adminApi.delete<{ message: string; status: MaintenanceStatus; audit_logs: AuditLog[] }>('/maintenance/audit-logs' + query, {})
          : await adminApi.delete<{ message: string; status: MaintenanceStatus; runtime_logs: RuntimeLog[] }>('/maintenance/runtime-logs' + query, {});

    message.value = res.data.message;
    if ('status' in res.data) status.value = res.data.status;
    if ('audit_logs' in res.data && Array.isArray(res.data.audit_logs)) auditLogs.value = res.data.audit_logs;
    if ('runtime_logs' in res.data && Array.isArray(res.data.runtime_logs)) runtimeLogs.value = res.data.runtime_logs;
    if (action === 'clear-cache' || action === 'reindex-search') await load();
  } catch (err) {
    error.value = apiErrorMessage(err, 'Action de maintenance impossible.');
  } finally {
    busyAction.value = '';
  }
}

onMounted(load);
</script>

<template>
  <PageHeader title="Maintenance" intro="Cache, index de recherche, logs runtime et journal d’audit IAM depuis un écran unique orienté exploitation.">
    <template #actions>
      <button class="btn ghost" type="button" :disabled="loading || busyAction !== ''" @click="load">Rafraîchir</button>
    </template>
  </PageHeader>

  <ApiFeedback :error="error" :message="message" />

  <div v-if="!canMaintain" class="card empty-state">
    <h2>Accès restreint</h2>
    <p class="muted">La maintenance système demande la permission <code>maintenance.manage</code>.</p>
  </div>

  <template v-else>
    <section class="stats-grid dashboard-top-stats">
      <article class="card"><span class="muted">Cache</span><strong>{{ status?.cache.files ?? 0 }}</strong><small>{{ formatBytes(status?.cache.size_bytes ?? 0) }}</small></article>
      <article class="card"><span class="muted">Recherche</span><strong>{{ status?.search_documents ?? 0 }}</strong><small>{{ status?.published_entries ?? 0 }} entrée(s) publiée(s)</small></article>
      <article class="card"><span class="muted">Audits IAM</span><strong>{{ status?.audit_rows ?? 0 }}</strong><small>Événements journalisés</small></article>
      <article class="card"><span class="muted">Logs runtime</span><strong>{{ status?.runtime_logs.files ?? 0 }}</strong><small>{{ formatBytes(status?.runtime_logs.size_bytes ?? 0) }}</small></article>
    </section>

    <section class="grid grid-2 maintenance-actions">
      <article class="card maintenance-card">
        <h2 class="d-inline-flex align-items-center gap-1">
          Cache
          <InfoHint text="Supprime les fichiers générés dans storage/cache, y compris le cache Twig public." />
        </h2>
        <button class="btn primary" type="button" :disabled="busyAction !== ''" @click="runAction('clear-cache')">{{ busyAction === 'clear-cache' ? 'Nettoyage…' : 'Vider le cache' }}</button>
      </article>
      <article class="card maintenance-card">
        <h2 class="d-inline-flex align-items-center gap-1">
          Recherche
          <InfoHint text="Reconstruit les projections publiques des contenus publiés puis régénère l’index FTS natif." />
        </h2>
        <button class="btn primary" type="button" :disabled="busyAction !== ''" @click="runAction('reindex-search')">{{ busyAction === 'reindex-search' ? 'Réindexation…' : 'Réindexer la recherche' }}</button>
      </article>
      <article class="card maintenance-card danger-zone">
        <h2 class="d-inline-flex align-items-center gap-1">
          Journal d’audit
          <InfoHint text="Efface les audits IAM existants, puis écrit une nouvelle trace de purge." />
        </h2>
        <button class="btn danger" type="button" :disabled="busyAction !== ''" @click="runAction('clear-audit')">{{ busyAction === 'clear-audit' ? 'Suppression…' : 'Effacer les audits' }}</button>
      </article>
      <article class="card maintenance-card danger-zone">
        <h2 class="d-inline-flex align-items-center gap-1">
          Logs runtime
          <InfoHint text="Supprime les fichiers de log applicatifs dans storage/logs." />
        </h2>
        <button class="btn danger" type="button" :disabled="busyAction !== ''" @click="runAction('clear-runtime')">{{ busyAction === 'clear-runtime' ? 'Suppression…' : 'Effacer les logs' }}</button>
      </article>
    </section>

    <section class="maintenance-panels stack">
      <article class="card maintenance-panel-full">
        <h2>Logs runtime</h2>
        <div v-if="runtimeLogs.length === 0" class="muted">Aucun fichier de log runtime.</div>
        <details v-for="log in runtimeLogs" :key="log.name" class="runtime-log-details">
          <summary><strong>{{ log.name }}</strong> <span class="muted">{{ formatBytes(log.size_bytes) }} · {{ log.updated_at }}</span></summary>
          <pre>{{ log.tail.join('\n') }}</pre>
        </details>
      </article>

      <article class="maintenance-panel-full">
        <div class="panel-header"><h2>Audits récents</h2></div>
        <DataTable :columns="[{key:'created_at',label:'Date'},{key:'actor',label:'Acteur'},{key:'action_key',label:'Action'},{key:'resource',label:'Ressource'},{key:'ip',label:'IP'}]" :rows="auditRows" />
      </article>
    </section>
  </template>
</template>
