<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from '@/i18n';
import type { BlueprintRow } from './types';
const props = defineProps<{ groups: Array<[string, BlueprintRow[]]>; families: string[]; resourceTypes: string[]; activeIdentity: string; canManage: boolean; siteName: string }>();
const emit = defineEmits<{ create: []; select: [item: BlueprintRow] }>();
const search = defineModel<string>('search', { required: true });
const family = defineModel<string>('family', { required: true });
const resourceType = defineModel<string>('resourceType', { required: true });
const scope = defineModel<string>('scope', { required: true });
const state = defineModel<string>('state', { required: true });
const { t } = useI18n();
const open = ref<Record<string, boolean>>({ Studio: true, 'Modules métier': true });
function identity(item: BlueprintRow): string { return `${item.resource_type}:${item.blueprint_key}:${item.site_id == null ? 'global' : 'site'}`; }
function resourceTypeLabel(type: string): string { return t(`blueprints.resource.${type}`) || type; }
function familyLabel(value: string): string {
  const labels: Record<string, string> = {
    Studio: t('blueprints.group.studio'),
    'Blocs éditoriaux': t('blueprints.group.editorialBlocks'),
    'Modules métier': t('blueprints.group.businessModules'),
    'Système': t('blueprints.group.system')
  };
  return labels[value] || value;
}
function scopeLabel(item: BlueprintRow): string {
  return item.site_id == null ? t('blueprints.scope.global') : t('blueprints.scope.site', { site: props.siteName });
}
function statusLabel(item: BlueprintRow): string {
  if (item.is_active === false) return t('blueprints.status.inactive');
  if (item.active_version) return t('blueprints.status.schemaVersion', { version: item.active_version });
  return t('blueprints.status.noActiveVersion');
}
watch([() => props.activeIdentity, () => props.groups], () => {
  const activeGroup = props.groups.find(([, items]) => items.some((item) => identity(item) === props.activeIdentity));
  if (activeGroup) open.value[activeGroup[0]] = true;
}, { immediate: true });
</script>
<template>
  <aside class="blueprint-column blueprint-column--models"><div class="card sticky-xxl-top blueprint-sticky">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 mb-0">{{ t('blueprints.navigation.title') }}</h2><button class="btn btn-dark btn-sm" type="button" :disabled="!canManage" @click="emit('create')">{{ t('common.create') }}</button></div>
    <div class="row g-2 mb-3" :aria-label="t('blueprints.navigation.filters')">
      <div class="col-12"><label class="visually-hidden" for="structure-search">{{ t('blueprints.navigation.search') }}</label><input id="structure-search" v-model="search" class="form-control form-control-sm" type="search" :placeholder="t('blueprints.navigation.searchPlaceholder')"></div>
      <div class="col-6"><select v-model="family" class="form-select form-select-sm" :aria-label="t('blueprints.navigation.family')"><option value="">{{ t('blueprints.navigation.allFamilies') }}</option><option v-for="item in families" :key="item" :value="item">{{ familyLabel(item) }}</option></select></div>
      <div class="col-6"><select v-model="resourceType" class="form-select form-select-sm" :aria-label="t('blueprints.navigation.type')"><option value="">{{ t('blueprints.navigation.allTypes') }}</option><option v-for="type in resourceTypes" :key="type" :value="type">{{ resourceTypeLabel(type) }}</option></select></div>
      <div class="col-6"><select v-model="scope" class="form-select form-select-sm" :aria-label="t('blueprints.navigation.scope')"><option value="">{{ t('blueprints.navigation.allScopes') }}</option><option value="global">{{ t('blueprints.navigation.globalScopes') }}</option><option value="site">{{ t('blueprints.navigation.siteScopes') }}</option></select></div>
      <div class="col-6"><select v-model="state" class="form-select form-select-sm" :aria-label="t('blueprints.navigation.state')"><option value="">{{ t('blueprints.navigation.allStates') }}</option><option value="active">{{ t('blueprints.navigation.active') }}</option><option value="inactive">{{ t('blueprints.navigation.inactive') }}</option><option value="unversioned">{{ t('blueprints.navigation.unversioned') }}</option></select></div>
    </div>
    <p class="small text-muted">{{ t('blueprints.navigation.help') }}</p>
    <div class="accordion accordion-flush">
      <div v-for="([group, items]) in groups" :key="group" class="accordion-item">
        <h3 class="accordion-header"><button class="accordion-button py-2" :class="{ collapsed: !open[group] }" type="button" :aria-expanded="Boolean(open[group])" :aria-controls="`structure-family-${group.replace(/[^a-z0-9]/gi, '-')}`" @click="open[group] = !open[group]">{{ familyLabel(group) }}</button></h3>
        <div :id="`structure-family-${group.replace(/[^a-z0-9]/gi, '-')}`" v-show="open[group]" class="accordion-collapse show"><div class="accordion-body p-0 py-2">
          <button v-for="item in items" :key="identity(item)" class="list-group-item list-group-item-action border-0 rounded-3 mb-1" :class="{ active: identity(item) === activeIdentity }" type="button" @click="emit('select', item)">
            <span class="d-flex align-items-center justify-content-between gap-2"><strong>{{ item.label }}</strong><small class="font-monospace">{{ item.blueprint_key }}</small></span>
            <small class="d-block opacity-75">{{ resourceTypeLabel(item.resource_type) }} · {{ t('blueprints.navigation.counts', { sections: item.sections_count, fields: item.fields_count }) }}</small>
            <span class="d-flex flex-wrap gap-1 mt-2"><span class="badge" :class="item.site_id == null ? 'text-bg-info' : 'text-bg-primary'">{{ scopeLabel(item) }}</span><span class="badge" :class="item.is_active === false ? 'text-bg-secondary' : item.active_version ? 'text-bg-success' : 'text-bg-warning'">{{ statusLabel(item) }}</span></span>
          </button>
        </div></div>
      </div>
    </div>
    <p v-if="groups.length === 0" class="text-muted small mb-0">{{ t('blueprints.navigation.empty') }}</p>
  </div></aside>
</template>
