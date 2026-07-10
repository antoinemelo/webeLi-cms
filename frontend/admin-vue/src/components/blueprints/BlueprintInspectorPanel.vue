<script setup lang="ts">
import type { BlueprintSection } from './types';
defineProps<{ section: BlueprintSection; sectionIndex: number; canManage: boolean }>();
const emit = defineEmits<{ edit: [index: number] }>();
const layoutLabels = { tab: 'Onglet', section: 'Section', sidebar: 'Panneau latéral' };
</script>
<template>
  <aside class="card blueprint-inspector" aria-labelledby="blueprint-inspector-title">
    <p class="eyebrow mb-1">Inspecteur de propriétés</p>
    <h2 id="blueprint-inspector-title" class="h5">Propriétés de la section</h2>
    <p class="mb-3"><strong>{{ section.label }}</strong></p>
    <dl class="row small mb-3"><dt class="col-5">Disposition</dt><dd class="col-7">{{ layoutLabels[section.layout] }}</dd><dt class="col-5">Champs</dt><dd class="col-7">{{ section.fields.length }}</dd><dt class="col-5">Groupes</dt><dd class="col-7">{{ section.fieldsets.length }}</dd></dl>
    <p v-if="section.description" class="text-muted small">{{ section.description }}</p>
    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!canManage" @click="emit('edit', sectionIndex)">Modifier les propriétés</button>
  </aside>
</template>
