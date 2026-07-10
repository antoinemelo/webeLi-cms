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
type CoreVersion = { key: 'core'; name?: string; technical_version?: string; release_name?: string; release_type?: string; release_id?: string; deployment_id?: string };
type ModuleVersion = { key: string; name?: string; version?: string; type?: string; installed?: boolean | null; enabled?: boolean | null; manifest_path?: string; source?: string };
type DatabaseMigration = { name: string; checksum?: string; migrated_at?: string };
type DatabaseVersion = {
  key: string;
  name?: string;
  kind?: string;
  module_key?: string | null;
  path?: string;
  migrations_path?: string;
  required?: boolean;
  exists?: boolean | null;
  journal_exists?: boolean | null;
  status?: string;
  expected_count?: number;
  expected_latest?: string;
  applied_count?: number;
  applied_latest?: string;
  expected_migrations?: DatabaseMigration[];
  applied_migrations?: DatabaseMigration[];
  missing_migrations?: string[];
  unknown_migrations?: string[];
  checksum_mismatches?: string[];
};
type VersionManifest = { core?: CoreVersion | null; modules?: ModuleVersion[]; databases?: DatabaseVersion[]; source?: string; source_url?: string; generated_at?: string; git?: { branch?: string; commit?: string; repo?: string } };
type VersionChannel = { key: string; label: string; status: 'available' | 'unavailable'; source: string; source_url?: string; git_branch?: string; error?: string | null; core?: CoreVersion | null; modules: ModuleVersion[]; databases?: DatabaseVersion[]; generated_at?: string | null };
type MaintenanceVersions = { enabled: boolean; local: VersionManifest; channels: Record<string, VersionChannel> };
type VersionRow = { key: string; name: string; type: 'core' | 'module'; installed: string; dev: string; stable: string; status: string; statusClass: string; detail: string };
type DatabaseRow = { key: string; name: string; installed: string; dev: string; stable: string; status: string; statusClass: string; detail: string };
type MaintenancePayload = { status: MaintenanceStatus; audit_logs: AuditLog[]; runtime_logs: RuntimeLog[]; versions?: MaintenanceVersions };
const HIDDEN_SYSTEM_MODULE_KEYS = new Set(['articles', 'core', 'editor', 'media', 'pages', 'seo', 'taxonomy']);

const context = useAdminContextStore();
const status = ref<MaintenanceStatus | null>(null);
const auditLogs = ref<AuditLog[]>([]);
const runtimeLogs = ref<RuntimeLog[]>([]);
const versions = ref<MaintenanceVersions | null>(null);
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
const devChannel = computed(() => versions.value?.channels?.dev ?? null);
const stableChannel = computed(() => versions.value?.channels?.stable ?? null);
const versionRows = computed<VersionRow[]>(() => buildVersionRows());
const databaseRows = computed<DatabaseRow[]>(() => buildDatabaseRows());
const updateManifestUrl = computed(() => {
  const configured = window.__AMCMS_ADMIN__?.basePath;
  if (typeof configured === 'string') return `${configured.replace(/\/$/, '')}/updates/manifest.json`;
  const marker = '/admin/app';
  const index = window.location.pathname.indexOf(marker);
  const base = index >= 0 ? window.location.pathname.slice(0, index) : '';
  return `${base}/updates/manifest.json`;
});

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
    versions.value = res.data.versions || null;
  } catch (err) {
    error.value = apiErrorMessage(err, 'Module de maintenance indisponible.');
  } finally {
    loading.value = false;
  }
}

function coreLabel(core?: CoreVersion | null): string {
  return core?.technical_version || '—';
}

function moduleLabel(module?: ModuleVersion | null): string {
  return module?.version || '—';
}

function databaseAppliedLabel(database?: DatabaseVersion | null): string {
  return database?.applied_latest || (database?.expected_count === 0 ? '—' : '—');
}

function databaseExpectedLabel(database?: DatabaseVersion | null): string {
  return database?.expected_latest || (database?.expected_count === 0 ? 'aucune' : '—');
}

function compareTechnicalVersion(left: string, right: string): number {
  if (left === right) return 0;
  if (!left) return -1;
  if (!right) return 1;
  const a = left.match(/^([A-Za-z][A-Za-z0-9_-]*)_v(\d+)-e(\d+)([a-z])$/);
  const b = right.match(/^([A-Za-z][A-Za-z0-9_-]*)_v(\d+)-e(\d+)([a-z])$/);
  if (a && b) {
    const productCompare = a[1].localeCompare(b[1], 'fr', { sensitivity: 'base' });
    if (productCompare !== 0) return productCompare;
    const partsA = [Number(a[2]), Number(a[3]), a[4].charCodeAt(0)];
    const partsB = [Number(b[2]), Number(b[3]), b[4].charCodeAt(0)];
    for (let i = 0; i < partsA.length; i++) {
      if (partsA[i] === partsB[i]) continue;
      return partsA[i] > partsB[i] ? 1 : -1;
    }
    return 0;
  }
  return left.localeCompare(right, 'fr', { numeric: true, sensitivity: 'base' });
}

function compareModuleVersion(left: string, right: string): number {
  if (left === right) return 0;
  if (!left) return -1;
  if (!right) return 1;
  return left.localeCompare(right, 'fr', { numeric: true, sensitivity: 'base' });
}

function compareMigrationName(left: string, right: string): number {
  if (left === right) return 0;
  if (!left || left === '—' || left === 'aucune') return -1;
  if (!right || right === '—' || right === 'aucune') return 1;
  return left.localeCompare(right, 'fr', { numeric: true, sensitivity: 'base' });
}

function moduleMap(channel: VersionChannel | null): Map<string, ModuleVersion> {
  return new Map((channel?.modules ?? []).filter((module) => module.key).map((module) => [module.key, module]));
}

function databaseMap(channel: VersionChannel | null): Map<string, DatabaseVersion> {
  return new Map((channel?.databases ?? []).filter((database) => database.key).map((database) => [database.key, database]));
}

function localModuleMap(): Map<string, ModuleVersion> {
  return new Map((versions.value?.local?.modules ?? []).filter((module) => module.key).map((module) => [module.key, module]));
}

function localDatabaseMap(): Map<string, DatabaseVersion> {
  return new Map((versions.value?.local?.databases ?? []).filter((database) => database.key).map((database) => [database.key, database]));
}

function channelSourceLabel(channel: VersionChannel | null): string {
  if (!channel) return 'indisponible';
  if (channel.status !== 'available') return 'indisponible';
  if (channel.source === 'git') return `Git ${channel.git_branch || ''}`.trim();
  if (channel.source === 'reference_with_git_fallback') return 'référence + Git';
  return 'référence';
}

function versionAvailabilityStatus(
  availableChannels: string[],
  newerChannels: string[],
  missing = ''
): Pick<VersionRow, 'status' | 'statusClass'> {
  if (missing) return { status: missing, statusClass: 'text-bg-secondary' };
  if (newerChannels.length > 0) {
    return {
      status: `Nouvelle version disponible: ${newerChannels.join(', ')}`,
      statusClass: 'text-bg-warning'
    };
  }
  if (availableChannels.length > 0) return { status: 'À jour', statusClass: 'text-bg-success' };
  return { status: 'Version distante inconnue', statusClass: 'text-bg-secondary' };
}

function databaseLocalStatus(database?: DatabaseVersion | null): Pick<DatabaseRow, 'status' | 'statusClass'> | null {
  if (!database) return { status: 'Base absente localement', statusClass: 'text-bg-secondary' };
  switch (database.status) {
    case 'missing_database':
      return { status: 'Base absente', statusClass: 'text-bg-danger' };
    case 'optional_database_absent':
      return { status: 'Base optionnelle absente', statusClass: 'text-bg-secondary' };
    case 'missing_journal':
      return { status: 'Journal de migrations absent', statusClass: 'text-bg-warning' };
    case 'pending_migrations':
      return { status: `Migration manquante: ${(database.missing_migrations || []).slice(0, 2).join(', ')}`, statusClass: 'text-bg-warning' };
    case 'unknown_migrations':
      return { status: `Migration inconnue: ${(database.unknown_migrations || []).slice(0, 2).join(', ')}`, statusClass: 'text-bg-warning' };
    case 'checksum_mismatch':
      return { status: 'Divergence checksum', statusClass: 'text-bg-danger' };
    case 'no_migrations':
      return null;
    case 'up_to_date':
    case 'expected_only':
    default:
      return null;
  }
}

function buildVersionRows(): VersionRow[] {
  if (!versions.value) return [];
  const localCore = versions.value.local?.core ?? null;
  const devCore = devChannel.value?.core ?? null;
  const stableCore = stableChannel.value?.core ?? null;
  const rows: VersionRow[] = [];
  const installedCore = coreLabel(localCore);
  const dev = coreLabel(devCore);
  const stable = coreLabel(stableCore);
  const newerChannels = [
    dev !== '—' && compareTechnicalVersion(dev, installedCore) > 0 ? 'dev' : '',
    stable !== '—' && compareTechnicalVersion(stable, installedCore) > 0 ? 'stable' : ''
  ].filter(Boolean);
  const coreAvailableChannels = [
    dev !== '—' ? 'dev' : '',
    stable !== '—' ? 'stable' : ''
  ].filter(Boolean);
  const coreStatus = versionAvailabilityStatus(coreAvailableChannels, newerChannels);
  rows.push({
    key: 'core',
    name: localCore?.name || 'Core',
    type: 'core',
    installed: installedCore,
    dev,
    stable,
    status: coreStatus.status,
    statusClass: coreStatus.statusClass,
    detail: localCore?.release_name || ''
  });

  const localModules = localModuleMap();
  const devModules = moduleMap(devChannel.value);
  const stableModules = moduleMap(stableChannel.value);
  const moduleKeys = new Set<string>([
    ...localModules.keys(),
    ...devModules.keys(),
    ...stableModules.keys()
  ]);

  [...moduleKeys].map((key) => {
    const local = localModules.get(key) ?? null;
    const devModule = devModules.get(key) ?? null;
    const stableModule = stableModules.get(key) ?? null;
    if (
      HIDDEN_SYSTEM_MODULE_KEYS.has(key)
      && [local, devModule, stableModule].some((module) => module?.type === 'system')
    ) {
      return null;
    }
    const installed = moduleLabel(local);
    const devVersion = moduleLabel(devModule);
    const stableVersion = moduleLabel(stableModule);
    const newer = [
      devVersion !== '—' && compareModuleVersion(devVersion, installed) > 0 ? 'dev' : '',
      stableVersion !== '—' && compareModuleVersion(stableVersion, installed) > 0 ? 'stable' : ''
    ].filter(Boolean);
    const available = [
      devVersion !== '—' ? 'dev' : '',
      stableVersion !== '—' ? 'stable' : ''
    ].filter(Boolean);
    const missing = !local ? 'Absent localement' : (!devModule && !stableModule ? 'Absent des canaux' : '');
    const status = versionAvailabilityStatus(available, newer, missing);
    return {
      key,
      name: local?.name || devModule?.name || stableModule?.name || key,
      type: 'module',
      installed,
      dev: devVersion,
      stable: stableVersion,
      status: status.status,
      statusClass: status.statusClass,
      detail: local?.enabled === false ? 'désactivé' : local?.installed === false ? 'non installé' : local?.type || ''
    };
  }).filter((row): row is VersionRow => row !== null)
    .sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }))
    .forEach((row) => rows.push(row));

  return rows;
}

function buildDatabaseRows(): DatabaseRow[] {
  if (!versions.value) return [];
  const localDatabases = localDatabaseMap();
  const devDatabases = databaseMap(devChannel.value);
  const stableDatabases = databaseMap(stableChannel.value);
  const keys = new Set<string>([
    ...localDatabases.keys(),
    ...devDatabases.keys(),
    ...stableDatabases.keys()
  ]);

  return [...keys].map((key) => {
    const local = localDatabases.get(key) ?? null;
    const dev = devDatabases.get(key) ?? null;
    const stable = stableDatabases.get(key) ?? null;
    const installed = databaseAppliedLabel(local);
    const devExpected = databaseExpectedLabel(dev);
    const stableExpected = databaseExpectedLabel(stable);
    const localStatus = databaseLocalStatus(local);
    const newer = [
      compareMigrationName(devExpected, installed) > 0 ? 'dev' : '',
      compareMigrationName(stableExpected, installed) > 0 ? 'stable' : ''
    ].filter(Boolean);
    const available = [
      devExpected !== '—' ? 'dev' : '',
      stableExpected !== '—' ? 'stable' : ''
    ].filter(Boolean);
    const status = localStatus
      ?? (newer.length > 0
        ? { status: `Nouvelle migration disponible: ${newer.join(', ')}`, statusClass: 'text-bg-warning' }
        : local?.status === 'no_migrations' && available.length === 0
          ? { status: 'Aucune migration attendue', statusClass: 'text-bg-secondary' }
          : versionAvailabilityStatus(available, []));

    return {
      key,
      name: local?.name || dev?.name || stable?.name || key,
      installed,
      dev: devExpected,
      stable: stableExpected,
      status: status.status,
      statusClass: status.statusClass,
      detail: [local?.kind, local?.path].filter(Boolean).join(' · ')
    };
  }).sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));
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

    <section class="card maintenance-panel-full maintenance-versions-panel">
      <p class="muted maintenance-channel-summary">
        Dev : {{ channelSourceLabel(devChannel) }} · Stable : {{ channelSourceLabel(stableChannel) }}
      </p>

      <div v-if="!versions" class="muted">Informations de version indisponibles.</div>
      <div v-else class="table-responsive">
        <table class="table align-middle mb-0 maintenance-version-table">
          <thead>
            <tr>
              <th>
                <span class="maintenance-table-heading">
                  Versions installées
                  <InfoHint text="Comparaison informative du core et des modules installés avec les canaux dev et stable. Aucune mise à jour n’est lancée depuis cette page." />
                </span>
              </th>
              <th>Installé</th>
              <th>Dev</th>
              <th>Stable</th>
              <th>État</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in versionRows" :key="row.key">
              <td>
                <strong>{{ row.name }}</strong>
              </td>
              <td><code>{{ row.installed }}</code></td>
              <td><code>{{ row.dev }}</code></td>
              <td><code>{{ row.stable }}</code></td>
              <td><span class="badge" :class="row.statusClass">{{ row.status }}</span></td>
            </tr>
            <tr v-if="versionRows.length === 0">
              <td colspan="5" class="text-muted">Aucune information de version à afficher.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="versions" class="maintenance-database-section">
        <div class="table-responsive">
          <table class="table align-middle mb-0 maintenance-version-table">
            <thead>
              <tr>
                <th>
                  <span class="maintenance-table-heading">
                    Bases de données
                    <InfoHint text="Comparaison informative des migrations SQLite attendues et appliquées. Cette page ne crée pas de base et n’applique aucune migration." />
                  </span>
                </th>
                <th>Appliquée</th>
                <th>Dev</th>
                <th>Stable</th>
                <th>État</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in databaseRows" :key="row.key">
                <td>
                  <strong>{{ row.name }}</strong>
                </td>
                <td><code>{{ row.installed }}</code></td>
                <td><code>{{ row.dev }}</code></td>
                <td><code>{{ row.stable }}</code></td>
                <td><span class="badge" :class="row.statusClass">{{ row.status }}</span></td>
              </tr>
              <tr v-if="databaseRows.length === 0">
                <td colspan="5" class="text-muted">Aucune information de base de données à afficher.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="muted mt-3 mb-0">
        Manifest local public :
        <a :href="updateManifestUrl" target="_blank" rel="noopener">{{ updateManifestUrl }}</a>
      </p>
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

<style scoped>
.maintenance-version-table {
  table-layout: fixed;
}

.maintenance-versions-panel {
  margin-top: 1.25rem;
  padding-bottom: 1.15rem;
}

.maintenance-version-table th,
.maintenance-version-table td {
  width: 20%;
  padding-top: .45rem;
  padding-bottom: .45rem;
  overflow-wrap: anywhere;
}

.maintenance-channel-summary {
  margin: 0 0 .55rem;
  line-height: 1.25;
  text-align: right;
}

.maintenance-table-heading {
  display: inline-flex;
  align-items: center;
  gap: .25rem;
  font-weight: 400;
}

.maintenance-versions-panel code {
  font-size: .84rem;
}

.maintenance-versions-panel .badge {
  padding: .16rem .45rem;
  font-size: .72rem;
  line-height: 1.15;
}

.maintenance-database-section {
  margin-top: 1rem;
}

.maintenance-actions {
  margin-top: 1.25rem;
}
</style>
