<script setup lang="ts">
import { ref, watch } from 'vue';
import type { BlueprintRow } from './types';
const props = defineProps<{ groups: Array<[string, BlueprintRow[]]>; families: string[]; resourceTypes: string[]; activeIdentity: string; canManage: boolean; siteName: string }>();
const emit = defineEmits<{ create: []; select: [item: BlueprintRow] }>();
const search = defineModel<string>('search', { required: true });
const family = defineModel<string>('family', { required: true });
const resourceType = defineModel<string>('resourceType', { required: true });
const scope = defineModel<string>('scope', { required: true });
const state = defineModel<string>('state', { required: true });
const open = ref<Record<string, boolean>>({ Studio: true, 'Modules métier': true });
const typeLabels: Record<string,string> = { content_type: 'Type de contenu', block: 'Bloc éditorial', module_resource: 'Ressource métier', headless: 'Ressource headless', taxonomy: 'Taxonomie', media: 'Média', system: 'Système' };
function identity(item: BlueprintRow): string { return `${item.resource_type}:${item.blueprint_key}:${item.site_id == null ? 'global' : 'site'}`; }
watch([() => props.activeIdentity, () => props.groups], () => {
  const activeGroup = props.groups.find(([, items]) => items.some((item) => identity(item) === props.activeIdentity));
  if (activeGroup) open.value[activeGroup[0]] = true;
}, { immediate: true });
</script>
<template>
  <aside class="blueprint-column blueprint-column--models"><div class="card sticky-xxl-top blueprint-sticky">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 mb-0">Structures</h2><button class="btn btn-dark btn-sm" type="button" :disabled="!canManage" @click="emit('create')">Créer</button></div>
    <div class="row g-2 mb-3" aria-label="Filtres des structures">
      <div class="col-12"><label class="visually-hidden" for="structure-search">Rechercher</label><input id="structure-search" v-model="search" class="form-control form-control-sm" type="search" placeholder="Rechercher une structure…"></div>
      <div class="col-6"><select v-model="family" class="form-select form-select-sm" aria-label="Famille"><option value="">Toutes les familles</option><option v-for="item in families" :key="item" :value="item">{{ item }}</option></select></div>
      <div class="col-6"><select v-model="resourceType" class="form-select form-select-sm" aria-label="Type"><option value="">Tous les types</option><option v-for="type in resourceTypes" :key="type" :value="type">{{ typeLabels[type] || type }}</option></select></div>
      <div class="col-6"><select v-model="scope" class="form-select form-select-sm" aria-label="Portée"><option value="">Toutes les portées</option><option value="global">Globales</option><option value="site">Propres au site</option></select></div>
      <div class="col-6"><select v-model="state" class="form-select form-select-sm" aria-label="État"><option value="">Tous les états</option><option value="active">Actives</option><option value="inactive">Inactives</option><option value="unversioned">Sans version active</option></select></div>
    </div>
    <p class="small text-muted">Le type natif détermine la famille lorsqu’il est disponible ; quelques anciennes structures utilisent encore une heuristique limitée.</p>
    <div class="accordion accordion-flush">
      <div v-for="([group, items]) in groups" :key="group" class="accordion-item">
        <h3 class="accordion-header"><button class="accordion-button py-2" :class="{ collapsed: !open[group] }" type="button" :aria-expanded="Boolean(open[group])" :aria-controls="`structure-family-${group.replace(/[^a-z0-9]/gi, '-')}`" @click="open[group] = !open[group]">{{ group }}</button></h3>
        <div :id="`structure-family-${group.replace(/[^a-z0-9]/gi, '-')}`" v-show="open[group]" class="accordion-collapse show"><div class="accordion-body p-0 py-2">
          <button v-for="item in items" :key="identity(item)" class="list-group-item list-group-item-action border-0 rounded-3 mb-1" :class="{ active: identity(item) === activeIdentity }" type="button" @click="emit('select', item)">
            <span class="d-flex align-items-center justify-content-between gap-2"><strong>{{ item.label }}</strong><small class="font-monospace">{{ item.blueprint_key }}</small></span>
            <small class="d-block opacity-75">{{ typeLabels[item.resource_type] || item.resource_type }} · {{ item.sections_count }} section(s) · {{ item.fields_count }} champ(s)</small>
            <span class="d-flex flex-wrap gap-1 mt-2"><span class="badge" :class="item.site_id == null ? 'text-bg-info' : 'text-bg-primary'">{{ item.site_id == null ? 'Globale — tous les sites' : `Propre à ${siteName}` }}</span><span class="badge" :class="item.is_active === false ? 'text-bg-secondary' : item.active_version ? 'text-bg-success' : 'text-bg-warning'">{{ item.is_active === false ? 'inactive' : item.active_version ? `schéma v${item.active_version}` : 'sans version active' }}</span></span>
          </button>
        </div></div>
      </div>
    </div>
    <p v-if="groups.length === 0" class="text-muted small mb-0">Aucune structure ne correspond aux filtres.</p>
  </div></aside>
</template>
