<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n } from '@/i18n';

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
type DependencyRuntime = { key: string; name: string; installed?: string; latest?: string; latest_checked_at?: string; latest_source?: string; required?: string; path?: string; status?: string };
type DependencyPackage = { manager: 'composer' | 'npm'; name: string; label?: string; installed?: string; latest?: string; latest_checked_at?: string; latest_source?: string; path?: string; path_exists?: boolean; source_path?: string; direct?: boolean; status?: string };
type MaintenanceDependencies = { generated_at?: string; latest_enabled?: boolean; latest_cache_ttl_seconds?: number; runtimes: DependencyRuntime[]; packages: DependencyPackage[] };
type StableUpdateComponent = {
  type: 'core' | 'module'; key: string; name?: string; version: string;
  installed_version?: string | null; archive_url?: string; sha256?: string;
  requires_core?: string; update_available?: boolean; update_allowed?: boolean;
  current?: boolean; ahead_of_stable?: boolean; blocked_reason?: string | null;
};
type StableUpdates = {
  enabled: boolean; channel: 'stable'; checked_at: number; error?: string | null;
  repository: string; release_url?: string | null; catalog_fingerprint?: string | null;
  core?: StableUpdateComponent | null; modules: StableUpdateComponent[];
};
type VersionRow = { key: string; name: string; type: 'core' | 'module'; installed: string; dev: string; stable: string; status: string; statusClass: string; detail: string };
type DatabaseRow = { key: string; name: string; installed: string; dev: string; stable: string; status: string; statusClass: string; detail: string };
type RuntimeRow = { key: string; name: string; latest: string; installed: string; required: string; status: string; statusClass: string; source: string };
type DependencyPackageRow = { key: string; name: string; manager: string; installed: string; latest: string; path: string; status: string; statusClass: string; source: string };
type MaintenancePayload = { status: MaintenanceStatus; audit_logs: AuditLog[]; runtime_logs: RuntimeLog[]; versions?: MaintenanceVersions; dependencies?: MaintenanceDependencies; stable_updates?: StableUpdates };
const HIDDEN_SYSTEM_MODULE_KEYS = new Set(['articles', 'core', 'editor', 'media', 'pages', 'seo', 'taxonomy']);

const context = useAdminContextStore();
const { t, dateTime } = useI18n();
const status = ref<MaintenanceStatus | null>(null);
const auditLogs = ref<AuditLog[]>([]);
const runtimeLogs = ref<RuntimeLog[]>([]);
const versions = ref<MaintenanceVersions | null>(null);
const dependencies = ref<MaintenanceDependencies | null>(null);
const stableUpdates = ref<StableUpdates | null>(null);
const loading = ref(false);
const busyActions = ref<Record<string, boolean>>({});
const refreshingDependencyVersions = ref(false);
const refreshingStableUpdates = ref(false);
const applyingStableComponent = ref('');
const error = ref('');
const message = ref('');

const canMaintain = computed(() => context.can('maintenance.manage'));
const auditRows = computed(() => auditLogs.value.map((row) => ({
  id: row.id,
  created_at: dateTime(row.created_at),
  actor: row.actor_email || t('maintenance.audit.actor.system'),
  action_key: row.action_key,
  resource: `${row.resource_type || '—'} ${row.resource_id || ''}`.trim(),
  ip: row.ip_address || '—'
})));
const auditColumns = computed(() => [
  { key: 'created_at', label: t('maintenance.audit.column.date') },
  { key: 'actor', label: t('maintenance.audit.column.actor') },
  { key: 'action_key', label: t('maintenance.audit.column.action') },
  { key: 'resource', label: t('maintenance.audit.column.resource') },
  { key: 'ip', label: 'IP' }
]);
const devChannel = computed(() => versions.value?.channels?.dev ?? null);
const stableChannel = computed(() => versions.value?.channels?.stable ?? null);
const versionRows = computed<VersionRow[]>(() => buildVersionRows());
const databaseRows = computed<DatabaseRow[]>(() => buildDatabaseRows());
const runtimeRows = computed<RuntimeRow[]>(() => buildRuntimeRows());
const dependencyPackageRows = computed<DependencyPackageRow[]>(() => buildDependencyPackageRows());
const initialLoading = computed(() => loading.value && status.value === null && versions.value === null && dependencies.value === null);
const devUpdateAvailable = computed(() => versionRows.value.some((row) => {
  if (row.dev === '—') return false;
  return row.type === 'core'
    ? compareTechnicalVersion(row.dev, row.installed) > 0
    : compareModuleVersion(row.dev, row.installed) > 0;
}));
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
    dependencies.value = res.data.dependencies || null;
    stableUpdates.value = res.data.stable_updates || null;
  } catch (err) {
    error.value = apiErrorMessage(err, t('maintenance.loadError'));
  } finally {
    loading.value = false;
  }
}

function runtimeStatus(runtime: DependencyRuntime): Pick<RuntimeRow, 'status' | 'statusClass'> {
  return dependencyStatus(runtime.status);
}

function dependencyStatus(status?: string): Pick<DependencyPackageRow, 'status' | 'statusClass'> {
  switch (status) {
    case 'update_available':
      return { status: t('maintenance.status.updateAvailable'), statusClass: 'text-bg-warning' };
    case 'up_to_date':
      return { status: t('maintenance.status.upToDate'), statusClass: 'text-bg-success' };
    case 'missing':
      return { status: t('maintenance.status.missing'), statusClass: 'text-bg-danger' };
    case 'latest_unknown':
    default:
      return { status: t('maintenance.status.latestUnknown'), statusClass: 'text-bg-secondary' };
  }
}

function latestSourceLabel(source?: string): string {
  switch (source) {
    case 'composer_registry':
      return t('maintenance.latest.packagist');
    case 'composer_site':
      return t('maintenance.latest.composer');
    case 'php_site':
      return t('maintenance.latest.php');
    case 'node_site':
      return t('maintenance.latest.node');
    case 'npm_registry':
      return t('maintenance.latest.npm');
    case 'cache':
      return t('maintenance.latest.cache');
    case 'cache_empty':
      return t('maintenance.latest.cacheEmpty');
    case 'disabled':
      return t('maintenance.latest.disabled');
    case 'budget_exceeded':
      return t('maintenance.latest.budgetExceeded');
    default:
      return t('common.unavailable');
  }
}

function shortPath(path = ''): string {
  if (!path) return '—';
  const marker = '/web/';
  const index = path.indexOf(marker);
  if (index < 0) return path;
  const projectRelative = path.slice(index + marker.length);
  const separator = projectRelative.indexOf('/');
  return separator >= 0 ? projectRelative.slice(separator + 1) : projectRelative;
}

function isBusyAction(action: string): boolean {
  return Boolean(busyActions.value[action]);
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
  return database?.expected_latest || (database?.expected_count === 0 ? t('common.none') : '—');
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
  const noneLabel = t('common.none');
  if (!left || left === '—' || left === noneLabel) return -1;
  if (!right || right === '—' || right === noneLabel) return 1;
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
  if (!channel) return t('common.unavailable');
  if (channel.status !== 'available') return t('common.unavailable');
  if (channel.source === 'git') return t('maintenance.channel.git', { branch: channel.git_branch || '' }).trim();
  if (channel.source === 'reference_with_git_fallback') return t('maintenance.channel.referenceGitFallback');
  return t('maintenance.channel.reference');
}

function versionAvailabilityStatus(
  availableChannels: string[],
  newerChannels: string[],
  missing = ''
): Pick<VersionRow, 'status' | 'statusClass'> {
  if (missing) return { status: missing, statusClass: 'text-bg-secondary' };
  if (newerChannels.length > 0) {
    return {
      status: t('maintenance.status.updateAvailableChannels', { channels: newerChannels.join(', ') }),
      statusClass: 'text-bg-warning'
    };
  }
  if (availableChannels.length > 0) return { status: t('maintenance.status.upToDate'), statusClass: 'text-bg-success' };
  return { status: t('maintenance.status.remoteUnknown'), statusClass: 'text-bg-secondary' };
}

function databaseLocalStatus(database?: DatabaseVersion | null): Pick<DatabaseRow, 'status' | 'statusClass'> | null {
  if (!database) return { status: t('maintenance.status.databaseLocalMissing'), statusClass: 'text-bg-secondary' };
  switch (database.status) {
    case 'missing_database':
      return { status: t('maintenance.status.databaseMissing'), statusClass: 'text-bg-danger' };
    case 'optional_database_absent':
      return { status: t('maintenance.status.optionalDatabaseMissing'), statusClass: 'text-bg-secondary' };
    case 'missing_journal':
      return { status: t('maintenance.status.migrationJournalMissing'), statusClass: 'text-bg-warning' };
    case 'pending_migrations':
      return { status: t('maintenance.status.pendingMigration', { migrations: (database.missing_migrations || []).slice(0, 2).join(', ') }), statusClass: 'text-bg-warning' };
    case 'unknown_migrations':
      return { status: t('maintenance.status.unknownMigration', { migrations: (database.unknown_migrations || []).slice(0, 2).join(', ') }), statusClass: 'text-bg-warning' };
    case 'checksum_mismatch':
      return { status: t('maintenance.status.checksumMismatch'), statusClass: 'text-bg-danger' };
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
    const missing = !local ? t('maintenance.status.localMissing') : (!devModule && !stableModule ? t('maintenance.status.channelsMissing') : '');
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
      detail: local?.enabled === false ? t('maintenance.status.disabled') : local?.installed === false ? t('maintenance.status.notInstalled') : local?.type || ''
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
        ? { status: t('maintenance.status.migrationAvailableChannels', { channels: newer.join(', ') }), statusClass: 'text-bg-warning' }
        : local?.status === 'no_migrations' && available.length === 0
          ? { status: t('maintenance.status.noMigrationExpected'), statusClass: 'text-bg-secondary' }
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

function buildRuntimeRows(): RuntimeRow[] {
  return (dependencies.value?.runtimes ?? []).map((runtime) => {
    const status = runtimeStatus(runtime);
    return {
      key: runtime.key,
      name: runtime.name,
      latest: runtime.latest || '—',
      installed: runtime.installed || '—',
      required: runtime.required || '—',
      status: status.status,
      statusClass: status.statusClass,
      source: latestSourceLabel(runtime.latest_source)
    };
  }).sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));
}

function buildDependencyPackageRows(): DependencyPackageRow[] {
  return (dependencies.value?.packages ?? []).map((dependency) => {
    const status = dependencyStatus(dependency.status);
    return {
      key: `${dependency.manager}:${dependency.name}`,
      name: dependency.label || dependency.name,
      manager: dependency.manager === 'composer' ? 'Composer' : 'npm',
      installed: dependency.installed || '—',
      latest: dependency.latest || '—',
      path: shortPath(dependency.path),
      status: status.status,
      statusClass: status.statusClass,
      source: latestSourceLabel(dependency.latest_source)
    };
  }).sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));
}

async function refreshDependencyVersions(): Promise<void> {
  refreshingDependencyVersions.value = true;
  error.value = '';
  message.value = '';
  try {
    const query = `?site_id=${context.siteId}&language_code=${encodeURIComponent(context.languageCode)}`;
    const res = await adminApi.post<{ message: string; dependencies: MaintenanceDependencies }>('/maintenance/dependencies/refresh' + query, {});
    message.value = res.data.message;
    dependencies.value = res.data.dependencies;
  } catch (err) {
    error.value = apiErrorMessage(err, t('maintenance.refreshDependenciesError'));
  } finally {
    refreshingDependencyVersions.value = false;
  }
}

function stableComponent(row: VersionRow): StableUpdateComponent | null {
  if (row.type === 'core') return stableUpdates.value?.core ?? null;
  return stableUpdates.value?.modules?.find((component) => component.key === row.key) ?? null;
}

async function refreshStableCatalog(): Promise<void> {
  refreshingStableUpdates.value = true;
  error.value = '';
  message.value = '';
  try {
    const query = `?site_id=${context.siteId}&language_code=${encodeURIComponent(context.languageCode)}`;
    const res = await adminApi.post<{ message: string; stable_updates: StableUpdates }>('/maintenance/updates/stable/refresh' + query, {});
    message.value = res.data.message;
    stableUpdates.value = res.data.stable_updates;
  } catch (err) {
    error.value = apiErrorMessage(err, t('maintenance.stable.refreshError'));
  } finally {
    refreshingStableUpdates.value = false;
  }
}

async function applyStableUpdate(component: StableUpdateComponent | null): Promise<void> {
  const fingerprint = stableUpdates.value?.catalog_fingerprint;
  if (!component || !component.update_allowed || !fingerprint) return;
  if (!window.confirm(t('maintenance.stable.confirm', { name: component.name || component.key, version: component.version }))) return;

  applyingStableComponent.value = `${component.type}:${component.key}`;
  error.value = '';
  message.value = '';
  try {
    const query = `?site_id=${context.siteId}&language_code=${encodeURIComponent(context.languageCode)}`;
    const res = await adminApi.post<{ version: string; reload_required?: boolean }>('/maintenance/updates/stable/apply' + query, {
      component_type: component.type,
      component_key: component.key,
      expected_version: component.version,
      catalog_fingerprint: fingerprint
    });
    message.value = t('maintenance.stable.applied', { version: res.data.version });
    if (res.data.reload_required) window.location.reload();
    else await load();
  } catch (err) {
    error.value = apiErrorMessage(err, t('maintenance.stable.applyError'));
  } finally {
    applyingStableComponent.value = '';
  }
}

async function runAction(action: 'clear-cache' | 'reindex-search' | 'clear-audit' | 'clear-runtime'): Promise<void> {
  busyActions.value = { ...busyActions.value, [action]: true };
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
    error.value = apiErrorMessage(err, t('maintenance.actionError'));
  } finally {
    busyActions.value = { ...busyActions.value, [action]: false };
  }
}

onMounted(load);
</script>

<template>
  <PageHeader :title="t('maintenance.title')" :intro="t('maintenance.intro')" help-id="tools.maintenance" />

  <ApiFeedback :error="error" :message="message" />

  <div v-if="!canMaintain" class="card empty-state">
    <h2>{{ t('maintenance.restricted.title') }}</h2>
    <p class="muted" v-html="t('maintenance.restricted.message', { permission: '<code>maintenance.manage</code>' })"></p>
  </div>

  <div v-else-if="initialLoading" class="maintenance-loading" role="status" aria-live="polite">
    <span class="maintenance-loading-spinner" aria-hidden="true"></span>
    <span>{{ t('maintenance.loading') }}</span>
  </div>

  <template v-else>
    <section class="stats-grid dashboard-top-stats">
      <article class="card"><span class="muted">{{ t('maintenance.metric.cache') }}</span><strong>{{ status?.cache.files ?? 0 }}</strong><small>{{ formatBytes(status?.cache.size_bytes ?? 0) }}</small></article>
      <article class="card"><span class="muted">{{ t('maintenance.metric.search') }}</span><strong>{{ status?.search_documents ?? 0 }}</strong><small>{{ t('maintenance.metric.publishedEntries', { count: status?.published_entries ?? 0 }) }}</small></article>
      <article class="card"><span class="muted">{{ t('maintenance.metric.audit') }}</span><strong>{{ status?.audit_rows ?? 0 }}</strong><small>{{ t('maintenance.metric.loggedEvents') }}</small></article>
      <article class="card"><span class="muted">{{ t('maintenance.metric.runtimeLogs') }}</span><strong>{{ status?.runtime_logs.files ?? 0 }}</strong><small>{{ formatBytes(status?.runtime_logs.size_bytes ?? 0) }}</small></article>
    </section>

    <section class="card maintenance-panel-full maintenance-versions-panel">
      <div class="maintenance-dependencies-head">
        <p class="muted maintenance-channel-summary mb-0">
          {{ t('maintenance.channelSummary', { dev: channelSourceLabel(devChannel), stable: channelSourceLabel(stableChannel) }) }}
        </p>
        <button class="btn small" type="button" :disabled="refreshingStableUpdates" @click="refreshStableCatalog">
          {{ refreshingStableUpdates ? t('maintenance.stable.refreshing') : t('maintenance.stable.refresh') }}
        </button>
      </div>

      <p v-if="stableUpdates?.error" class="alert alert-warning mt-3 mb-3">{{ stableUpdates.error }}</p>
      <p v-if="devUpdateAvailable" class="alert alert-info mt-3 mb-3">
        {{ t('maintenance.dev.available') }}
        <a href="https://github.com/antoinemelo/webeLi-cms/tree/staging" target="_blank" rel="noopener">{{ t('maintenance.dev.openStaging') }}</a>
        ·
        <a href="https://github.com/antoinemelo/webeLi-cms/actions/workflows/release.yml" target="_blank" rel="noopener">{{ t('maintenance.dev.openDeployment') }}</a>
        <span class="muted">· {{ t('maintenance.dev.deliveryOnly') }}</span>
      </p>

      <div v-if="!versions" class="muted">{{ t('maintenance.empty.versions') }}</div>
      <div v-else class="table-responsive">
        <table class="table align-middle mb-0 maintenance-version-table">
          <thead>
            <tr>
              <th>
                <span class="maintenance-table-heading">
                  {{ t('maintenance.modules.title') }}
                  <InfoHint :text="t('maintenance.modules.info')" />
                </span>
              </th>
              <th>{{ t('maintenance.column.installed') }}</th>
              <th>{{ t('maintenance.column.dev') }}</th>
              <th>{{ t('maintenance.column.stable') }}</th>
              <th>{{ t('maintenance.column.status') }}</th>
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
              <td>
                <span class="badge" :class="row.statusClass">{{ row.status }}</span>
                <button
                  v-if="stableComponent(row)?.update_allowed"
                  class="btn small ms-2"
                  type="button"
                  :disabled="Boolean(applyingStableComponent)"
                  @click="applyStableUpdate(stableComponent(row))"
                >
                  {{ applyingStableComponent === `${stableComponent(row)?.type}:${stableComponent(row)?.key}` ? t('maintenance.stable.applying') : t('maintenance.stable.apply') }}
                </button>
              </td>
            </tr>
            <tr v-if="versionRows.length === 0">
              <td colspan="5" class="text-muted">{{ t('maintenance.empty.versionRows') }}</td>
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
                    {{ t('maintenance.databases.title') }}
                    <InfoHint :text="t('maintenance.databases.info')" />
                  </span>
                </th>
                <th>{{ t('maintenance.column.applied') }}</th>
                <th>{{ t('maintenance.column.dev') }}</th>
                <th>{{ t('maintenance.column.stable') }}</th>
                <th>{{ t('maintenance.column.status') }}</th>
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
                <td colspan="5" class="text-muted">{{ t('maintenance.empty.databaseRows') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="muted mt-3 mb-0">
        {{ t('maintenance.manifest.local') }}
        <a :href="updateManifestUrl" target="_blank" rel="noopener">{{ updateManifestUrl }}</a>
      </p>
    </section>

    <section class="card maintenance-panel-full maintenance-dependencies-panel">
      <div class="maintenance-dependencies-head">
        <span class="maintenance-table-heading">
          {{ t('maintenance.dependencies.title') }}
          <InfoHint :text="t('maintenance.dependencies.info')" />
        </span>
        <button
          class="btn small"
          type="button"
          :disabled="refreshingDependencyVersions || dependencies?.latest_enabled === false"
          @click="refreshDependencyVersions"
        >
          {{ refreshingDependencyVersions ? t('maintenance.action.refreshingDependencies') : t('maintenance.action.refreshDependencies') }}
        </button>
      </div>

      <div v-if="!dependencies" class="muted">{{ t('maintenance.empty.dependencies') }}</div>
      <template v-else>
        <div class="table-responsive">
          <table class="table align-middle mb-0 maintenance-dependencies-table">
            <thead>
              <tr>
                <th>{{ t('maintenance.column.environment') }}</th>
                <th>{{ t('maintenance.column.installed') }}</th>
                <th>{{ t('maintenance.column.latest') }}</th>
                <th>{{ t('maintenance.column.required') }}</th>
                <th>{{ t('maintenance.column.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in runtimeRows" :key="row.key">
                <td>{{ row.name }}</td>
                <td><code>{{ row.installed }}</code></td>
                <td><code>{{ row.latest }}</code> <span class="muted dependency-source">· {{ row.source }}</span></td>
                <td><code>{{ row.required }}</code></td>
                <td><span class="badge" :class="row.statusClass">{{ row.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="table-responsive maintenance-dependencies-subtable">
          <table class="table align-middle mb-0 maintenance-dependencies-table">
            <thead>
              <tr>
                <th>{{ t('maintenance.column.dependency') }}</th>
                <th>{{ t('maintenance.column.installed') }}</th>
                <th>{{ t('maintenance.column.latest') }}</th>
                <th>{{ t('maintenance.column.path') }}</th>
                <th>{{ t('maintenance.column.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in dependencyPackageRows" :key="row.key">
                <td>{{ row.name }}</td>
                <td><code>{{ row.installed }}</code></td>
                <td><code>{{ row.latest }}</code> <span class="muted dependency-source">· {{ row.source }}</span></td>
                <td><code>{{ row.path }}</code></td>
                <td><span class="badge" :class="row.statusClass">{{ row.status }}</span></td>
              </tr>
              <tr v-if="dependencyPackageRows.length === 0">
                <td colspan="5" class="text-muted">{{ t('maintenance.empty.packageRows') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <section class="grid grid-2 maintenance-actions">
      <article class="card maintenance-card">
        <h2 class="d-inline-flex align-items-center gap-1">
          {{ t('maintenance.action.cache.title') }}
          <InfoHint :text="t('maintenance.action.cache.info')" />
        </h2>
        <button class="btn primary" type="button" :disabled="isBusyAction('clear-cache')" @click="runAction('clear-cache')">{{ isBusyAction('clear-cache') ? t('maintenance.action.cache.running') : t('maintenance.action.cache.run') }}</button>
      </article>
      <article class="card maintenance-card">
        <h2 class="d-inline-flex align-items-center gap-1">
          {{ t('maintenance.action.search.title') }}
          <InfoHint :text="t('maintenance.action.search.info')" />
        </h2>
        <button class="btn primary" type="button" :disabled="isBusyAction('reindex-search')" @click="runAction('reindex-search')">{{ isBusyAction('reindex-search') ? t('maintenance.action.search.running') : t('maintenance.action.search.run') }}</button>
      </article>
      <article class="card maintenance-card danger-zone">
        <h2 class="d-inline-flex align-items-center gap-1">
          {{ t('maintenance.action.audit.title') }}
          <InfoHint :text="t('maintenance.action.audit.info')" />
        </h2>
        <button class="btn danger" type="button" :disabled="isBusyAction('clear-audit')" @click="runAction('clear-audit')">{{ isBusyAction('clear-audit') ? t('maintenance.action.audit.running') : t('maintenance.action.audit.run') }}</button>
      </article>
      <article class="card maintenance-card danger-zone">
        <h2 class="d-inline-flex align-items-center gap-1">
          {{ t('maintenance.action.runtime.title') }}
          <InfoHint :text="t('maintenance.action.runtime.info')" />
        </h2>
        <button class="btn danger" type="button" :disabled="isBusyAction('clear-runtime')" @click="runAction('clear-runtime')">{{ isBusyAction('clear-runtime') ? t('maintenance.action.runtime.running') : t('maintenance.action.runtime.run') }}</button>
      </article>
    </section>

    <section class="maintenance-panels stack">
      <article class="card maintenance-panel-full">
        <h2>{{ t('maintenance.action.runtime.title') }}</h2>
        <div v-if="runtimeLogs.length === 0" class="muted">{{ t('maintenance.empty.runtimeLogs') }}</div>
        <details v-for="log in runtimeLogs" :key="log.name" class="runtime-log-details">
          <summary><strong>{{ log.name }}</strong> <span class="muted">{{ formatBytes(log.size_bytes) }} · {{ dateTime(log.updated_at) }}</span></summary>
          <pre>{{ log.tail.join('\n') }}</pre>
        </details>
      </article>

      <article class="maintenance-panel-full">
        <div class="panel-header"><h2>{{ t('maintenance.audit.recent') }}</h2></div>
        <DataTable :columns="auditColumns" :rows="auditRows" />
      </article>
    </section>
  </template>
</template>

<style scoped>
.maintenance-version-table {
  table-layout: fixed;
}

.maintenance-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .65rem;
  min-height: 42vh;
  width: 100%;
  padding: 1rem;
  color: var(--muted);
}

.maintenance-loading-spinner {
  width: 1rem;
  height: 1rem;
  border: 2px solid color-mix(in srgb, currentColor 22%, transparent);
  border-top-color: currentColor;
  border-radius: 50%;
  animation: maintenance-spin .75s linear infinite;
  flex: 0 0 auto;
}

@keyframes maintenance-spin {
  to { transform: rotate(360deg); }
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

.maintenance-dependencies-panel {
  margin-top: 1.25rem;
  padding-bottom: 1.15rem;
}

.maintenance-dependencies-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: .55rem;
}

.maintenance-dependencies-table {
  table-layout: fixed;
}

.maintenance-dependencies-table th,
.maintenance-dependencies-table td {
  width: 20%;
  padding-top: .42rem;
  padding-bottom: .42rem;
  overflow-wrap: anywhere;
}

.maintenance-dependencies-table code {
  font-size: .8rem;
}

.maintenance-dependencies-table .badge {
  padding: .16rem .45rem;
  font-size: .72rem;
  line-height: 1.15;
}

.maintenance-dependencies-subtable {
  margin-top: .85rem;
}

.dependency-source {
  font-size: .78rem;
}

@media (max-width: 720px) {
  .maintenance-dependencies-head {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
