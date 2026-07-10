<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import type { BlueprintSection } from './types';

const props = defineProps<{ section: BlueprintSection; canManage: boolean }>();
const emit = defineEmits<{ apply: []; cancel: [] }>();
const initialFocus = ref<HTMLInputElement|null>(null);
const closeButton = ref<HTMLButtonElement|null>(null);
function normalizeHandle(value: string): string { return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, ''); }
function onKeydown(event: KeyboardEvent): void { if (event.key === 'Escape') { event.preventDefault(); emit('cancel'); } }
onMounted(() => nextTick(() => (initialFocus.value?.disabled ? closeButton.value : initialFocus.value)?.focus()));
</script>

<template>
  <div class="modal d-block blueprint-modal" role="dialog" aria-modal="true" aria-labelledby="section-editor-title" tabindex="-1" @keydown="onKeydown">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h2 id="section-editor-title" class="modal-title h5">Modifier la section</h2><button ref="closeButton" type="button" class="btn-close" aria-label="Annuler la modification de la section" @click="emit('cancel')"></button></div>
      <div class="modal-body"><div class="row g-3">
        <label class="col-md-6 form-label">Nom affiché<input ref="initialFocus" v-model="section.label" class="form-control mt-1" :disabled="!canManage"></label>
        <label class="col-md-6 form-label">Identifiant technique<input v-model="section.section_key" class="form-control mt-1" :disabled="!canManage || Boolean(section.id)" @blur="section.section_key = normalizeHandle(section.section_key)"></label>
        <label class="col-md-4 form-label">Disposition<select v-model="section.layout" class="form-select mt-1" :disabled="!canManage"><option value="tab">Onglet</option><option value="section">Section</option><option value="sidebar">Panneau latéral</option></select></label>
        <label class="col-12 form-label">Description<textarea v-model="section.description" class="form-control mt-1" rows="3" :disabled="!canManage"></textarea></label>
      </div></div>
      <div class="modal-footer"><button class="btn btn-secondary" @click="emit('cancel')">Annuler</button><button class="btn btn-dark" :disabled="!canManage" @click="emit('apply')">Appliquer localement</button></div>
    </div></div>
  </div>
</template>
