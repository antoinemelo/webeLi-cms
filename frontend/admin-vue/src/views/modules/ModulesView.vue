<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';

type ModuleFeatureMap = Record<string, number | boolean | string>;
type ModuleEndpoint = { key?: string; scope?: string; method: string; path: string };
type ModuleBlueprint = { blueprint_key?: string; key?: string; resource?: string; resource_type?: string; label?: string; description?: string; admin?: Record<string, unknown>; capabilities?: Record<string, boolean>; storage?: Record<string, unknown>; headless?: Record<string, unknown>; permissions?: Record<string, string> };
type ModuleHealth = { ok?: boolean; missing_dependencies?: string[]; missing_permissions?: string[]; databases?: Array<Record<string, unknown>>; route_count?: number; blueprint_count?: number; blueprint_errors?: string[] };
type ModuleItem = {
  key: string;
  name: string;
  version: string;
  description?: string;
  installed?: boolean;
  enabled?: boolean;
  features?: ModuleFeatureMap;
  dependencies?: string[];
  permissions?: string[];
  blueprints?: ModuleBlueprint[];
  navigation?: Array<Record<string, unknown>>;
  endpoints?: ModuleEndpoint[];
  alerts?: string[];
  health?: ModuleHealth;
};
type ModuleResourceSchema = { schema?: ModuleBlueprint & { fields?: Array<Record<string, unknown>>; sections?: Array<Record<string, unknown>> } };

const props = defineProps<{ moduleKey?: string }>();
const route = useRoute();
const router = useRouter();
const context = useAdminContextStore();
const modules = ref<ModuleItem[]>([]);
const selected = ref<ModuleItem | null>(null);
const selectedSchema = ref<Record<string, unknown> | null>(null);
const loading = ref(false);
const actionLoading = ref('');
const error = ref('');
const success = ref('');

const selectedKey = computed(() => String(props.moduleKey || route.params.moduleKey || '')); 
const installedCount = computed(() => modules.value.filter((module) => module.installed).length);
const enabledCount = computed(() => modules.value.filter((module) => module.enabled).length);
const canManage = computed(() => context.can('modules.manage'));
const canReadBlueprints = computed(() => context.can('blueprints.read'));
const moduleBlueprints = computed(() => selected.value?.blueprints ?? []);
const moduleResources = computed(() => moduleBlueprints.value.filter((bp) => ['module_resource', 'headless'].includes(String(bp.resource_type || ''))));
const primaryAdminRoute = computed(() => String(selected.value?.navigation?.find((entry) => typeof entry.route === 'string')?.route || ''));
const primaryBlueprint = computed<ModuleBlueprint | null>(() => {
  const adminRoute = primaryAdminRoute.value;
  const byAdminRoute = moduleResources.value.find((bp) => String(bp.admin?.route || '') === adminRoute);
  if (byAdminRoute) return byAdminRoute;
  const adminResource = moduleResources.value.find((bp) => bp.capabilities?.admin !== false);
  return adminResource ?? moduleResources.value[0] ?? null;
});
const selectedDetailTitle = computed(() => nonEmpty(primaryBlueprint.value?.label) || selected.value?.name || selected.value?.key || 'Module');
const selectedDetailInfo = computed(() => nonEmpty(primaryBlueprint.value?.description) || selected.value?.description || 'Module sans description.');

function statusFor(module: ModuleItem): string {
  if (!module.installed) return 'non installé';
  return module.enabled ? 'actif' : 'désactivé';
}

function healthStatus(module: ModuleItem): string {
  if (!module.installed) return 'neutre';
  if (module.alerts?.length) return 'alerte';
  if (module.health && module.health.ok === false) return 'alerte';
  return module.enabled ? 'ok' : 'désactivé';
}

function featureValue(module: ModuleItem, key: string): number {
  const value = module.features?.[key];
  return typeof value === 'number' ? value : Number(value || 0);
}

function blueprintKey(blueprint: ModuleBlueprint): string {
  return String(blueprint.blueprint_key || blueprint.key || '');
}

function resourceKey(blueprint: ModuleBlueprint): string {
  return String(blueprint.resource || blueprintKey(blueprint).replace(`${selected.value?.key || ''}_`, ''));
}

function nonEmpty(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

async function loadModules(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ modules: ModuleItem[] }>('/modules');
    modules.value = response.data.modules ?? [];
    if (selectedKey.value) {
      await loadModule(selectedKey.value, false);
    } else {
      selected.value = modules.value[0] ?? null;
    }
  } catch (err) {
    error.value = apiErrorMessage(err, 'Modules indisponibles.');
  } finally {
    loading.value = false;
  }
}

async function loadModule(key: string, pushRoute = true): Promise<void> {
  if (!key) return;
  loading.value = true;
  error.value = '';
  selectedSchema.value = null;
  try {
    const response = await adminApi.get<{ module: ModuleItem }>(`/modules/${key}`);
    selected.value = response.data.module;
    const index = modules.value.findIndex((module) => module.key === key);
    if (index >= 0) modules.value[index] = response.data.module;
    if (pushRoute && route.params.moduleKey !== key) await router.push(`/modules/${key}`);
  } catch (err) {
    error.value = apiErrorMessage(err, `Module ${key} indisponible.`);
  } finally {
    loading.value = false;
  }
}

async function runAction(module: ModuleItem, action: 'install' | 'enable' | 'disable' | 'migrate'): Promise<void> {
  actionLoading.value = `${module.key}.${action}`;
  error.value = '';
  success.value = '';
  try {
    const response = await adminApi.post<{ module?: ModuleItem; applied?: string[] }>(`/modules/${module.key}/${action}`, {});
    if (response.data.module) {
      selected.value = response.data.module;
      const index = modules.value.findIndex((item) => item.key === module.key);
      if (index >= 0) modules.value[index] = response.data.module;
    }
    success.value = action === 'migrate' ? 'Migrations déclaratives exécutées.' : `Module ${action === 'install' ? 'installé' : action === 'enable' ? 'activé' : 'désactivé'}.`;
    await context.load(context.siteId, context.languageCode);
  } catch (err) {
    error.value = apiErrorMessage(err, `Action ${action} impossible.`);
  } finally {
    actionLoading.value = '';
  }
}

async function loadResourceSchema(blueprint: ModuleBlueprint): Promise<void> {
  if (!selected.value) return;
  const resource = resourceKey(blueprint);
  if (!resource) return;
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<ModuleResourceSchema>(`/modules/${selected.value.key}/resources/${resource}/schema`);
    selectedSchema.value = response.data.schema || null;
  } catch (err) {
    error.value = apiErrorMessage(err, 'Schéma de ressource indisponible.');
  } finally {
    loading.value = false;
  }
}

onMounted(loadModules);
watch(selectedKey, (key) => { if (key) void loadModule(key, false); });
</script>

<template>
  <ApiFeedback :error="error" :success="success" />

  <section class="module-stats-grid" aria-label="Synthèse des modules">
    <article class="card stat-card">
      <span class="eyebrow">Modules actifs</span>
      <strong>{{ enabledCount }}</strong>
      <small class="muted">routes et navigation publiées</small>
    </article>
    <article class="card stat-card">
      <span class="eyebrow">Installés</span>
      <strong>{{ installedCount }}</strong>
      <small class="muted">conservées à la désactivation</small>
    </article>
    <article class="card stat-card">
      <span class="eyebrow">Catalogue</span>
      <strong>{{ modules.length }}</strong>
      <small class="muted">providers connus</small>
    </article>
  </section>

  <div class="module-workbench-grid">
    <section class="card module-list-card">
      <header class="split-head">
        <div>
          <p class="eyebrow">Socle modulaire</p>
          <h2>Modules disponibles</h2>
        </div>
      </header>
      <div v-if="loading && !modules.length" class="muted">Chargement…</div>
      <button
        v-for="module in modules"
        :key="module.key"
        type="button"
        class="module-list-row"
        :class="{ active: selected?.key === module.key }"
        @click="loadModule(module.key)"
      >
        <span>
          <strong>{{ module.name || module.key }}</strong>
          <small class="muted">{{ module.key }} · v{{ module.version }}</small>
        </span>
        <StatusBadge :status="statusFor(module)" />
      </button>
      <p v-if="!loading && !modules.length" class="muted">Aucun provider module déclaré pour l’instant.</p>
    </section>

    <section v-if="selected" class="card module-detail-card">
      <header class="split-head">
        <div>
          <p class="eyebrow">{{ selected.key }}</p>
          <h2 class="module-detail-title">
            <span>{{ selectedDetailTitle }}</span>
            <InfoHint v-if="selectedDetailInfo" :text="selectedDetailInfo" placement="end" />
          </h2>
        </div>
        <div class="toolbar">
          <StatusBadge :status="healthStatus(selected)" />
          <button v-if="canManage && !selected.installed" class="btn" type="button" :disabled="actionLoading !== ''" @click="runAction(selected, 'install')">Installer</button>
          <button v-if="canManage && selected.installed && !selected.enabled" class="btn" type="button" :disabled="actionLoading !== ''" @click="runAction(selected, 'enable')">Activer</button>
          <button v-if="canManage && selected.installed && selected.enabled" class="btn ghost" type="button" :disabled="actionLoading !== ''" @click="runAction(selected, 'disable')">Désactiver</button>
          <button v-if="canManage && selected.installed" class="btn ghost" type="button" :disabled="actionLoading !== ''" @click="runAction(selected, 'migrate')">Migrer</button>
        </div>
      </header>

      <div class="module-kpis">
        <span><strong>{{ featureValue(selected, 'databases') }}</strong><small>bases</small></span>
        <span><strong>{{ featureValue(selected, 'permissions') }}</strong><small>permissions</small></span>
        <span><strong>{{ featureValue(selected, 'blueprints') }}</strong><small>blueprints</small></span>
        <span><strong>{{ featureValue(selected, 'public_headless_routes') }}</strong><small>routes headless</small></span>
      </div>

      <div v-if="selected.alerts?.length" class="alert warning">
        <strong>Dépendances / cohérence</strong>
        <ul><li v-for="alert in selected.alerts" :key="alert">{{ alert }}</li></ul>
      </div>

      <div class="module-detail-columns">
        <article>
          <h3>Ressources blueprintées</h3>
          <p class="muted" v-if="!moduleResources.length">Aucune ressource métier déclarée.</p>
          <div v-for="blueprint in moduleResources" :key="blueprintKey(blueprint)" class="module-resource-row">
            <div>
              <strong>{{ blueprint.label || blueprintKey(blueprint) }}</strong>
              <small class="muted">{{ blueprint.resource_type }} · {{ resourceKey(blueprint) }}</small>
            </div>
            <button v-if="canReadBlueprints" class="btn ghost small" type="button" @click="loadResourceSchema(blueprint)">Schéma</button>
          </div>
        </article>

        <article>
          <h3>Endpoints déclarés</h3>
          <p class="muted" v-if="!selected.endpoints?.length">Aucun endpoint déclaré.</p>
          <ul v-else class="endpoint-list">
            <li v-for="endpoint in selected.endpoints" :key="`${endpoint.method}-${endpoint.path}`">
              <code>{{ endpoint.method }}</code> {{ endpoint.path }}
            </li>
          </ul>
        </article>
      </div>

      <section v-if="selectedSchema" class="module-schema-card">
        <header class="split-head">
          <div>
            <p class="eyebrow">Schema-driven</p>
            <h3>{{ String(selectedSchema.label || selectedSchema.blueprint_key || 'Schéma') }}</h3>
          </div>
        </header>
        <pre>{{ JSON.stringify(selectedSchema, null, 2) }}</pre>
      </section>
    </section>

    <section v-else class="card module-detail-card muted">
      Sélectionnez un module pour consulter son état, ses blueprints et ses routes.
    </section>
  </div>
</template>
