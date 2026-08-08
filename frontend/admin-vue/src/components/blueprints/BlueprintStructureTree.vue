<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from '@/i18n';
import type { BlueprintSection, FieldsetRow, FieldType } from './types';

const props = defineProps<{ sections: BlueprintSection[]; selectedSectionIndex: number; canManage: boolean; fieldTypes: FieldType[]; availableFieldsets: FieldsetRow[] }>();
const emit = defineEmits<{
  selectSection: [index: number]; editSection: [index: number]; addSection: [];
  addField: [sectionIndex: number, type: string]; importFieldset: [sectionIndex: number, key: string];
  viewFieldsetUsages: [key: string];
  editField: [sectionIndex: number, fieldIndex: number, trigger: HTMLElement]; duplicateField: [sectionIndex: number, fieldIndex: number]; removeField: [sectionIndex: number, fieldIndex: number];
  moveSection: [from: number, to: number]; reorderField: [sectionIndex: number, from: number, to: number];
  moveField: [sectionIndex: number, fieldIndex: number, direction: -1|1]; changeFieldSection: [sectionIndex: number, fieldIndex: number, targetSection: number];
  removeFieldset: [sectionIndex: number, mountIndex: number];
}>();

const { t } = useI18n();
function selectValue(event: Event): string { const select = event.target as HTMLSelectElement; const value = select.value; select.value = ''; return value; }
const draggedSection = ref(-1);
const draggedField = ref<{ section: number; field: number }|null>(null);

function dropSection(target: number): void {
  if (draggedSection.value >= 0) emit('moveSection', draggedSection.value, target);
  draggedSection.value = -1;
}

function dropField(section: number, target: number): void {
  const source = draggedField.value;
  if (source?.section === section) emit('reorderField', section, source.field, target);
  draggedField.value = null;
}
</script>

<template>
  <section class="card blueprint-tree" aria-labelledby="structure-tree-title">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
      <div><p class="eyebrow mb-1">{{ t('blueprints.mobile.structure') }}</p><h2 id="structure-tree-title" class="h5 mb-0">{{ t('blueprints.tree.title') }}</h2></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="!canManage" @click="emit('addSection')">{{ t('blueprints.tree.addSection') }}</button>
    </div>
    <ol class="blueprint-tree__sections list-unstyled mb-0">
      <li v-for="(section, sectionIndex) in sections" :key="section.section_key + sectionIndex" class="blueprint-tree__section" :class="{ 'is-selected': sectionIndex === selectedSectionIndex }" :draggable="canManage" @dragstart="draggedSection = sectionIndex" @dragover.prevent @drop.self="dropSection(sectionIndex)">
        <div class="blueprint-tree__section-header">
          <span class="dnd-handle" :class="{ 'is-disabled': !canManage }" :title="t('common.dragToReorder')" aria-hidden="true">⋮</span>
          <button type="button" class="btn btn-link text-start p-0 flex-grow-1" @click="emit('selectSection', sectionIndex)"><strong>{{ section.label }}</strong><small class="d-block text-muted">{{ t('blueprints.tree.sectionSummary', { layout: t(`blueprints.layout.${section.layout}`), count: section.fields.length }) }}</small></button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!canManage || sectionIndex === 0" :aria-label="t('blueprints.tree.moveSectionUp', { label: section.label })" @click="emit('moveSection', sectionIndex, sectionIndex - 1)">{{ t('common.moveUp') }}</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!canManage || sectionIndex === sections.length - 1" :aria-label="t('blueprints.tree.moveSectionDown', { label: section.label })" @click="emit('moveSection', sectionIndex, sectionIndex + 1)">{{ t('common.moveDown') }}</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!canManage" :aria-label="t('blueprints.tree.editSection', { label: section.label })" @click="emit('editSection', sectionIndex)">{{ t('common.edit') }}</button>
        </div>
        <div class="row g-2 my-2">
          <div class="col-md-6"><select class="form-select form-select-sm" :disabled="!canManage" :aria-label="t('blueprints.tree.addFieldTo', { label: section.label })" @change="emit('addField', sectionIndex, selectValue($event))"><option value="">{{ t('blueprints.tree.addField') }}</option><option v-for="type in fieldTypes" :key="type.handle" :value="type.handle">{{ type.label }}</option></select></div>
          <div class="col-md-6"><select class="form-select form-select-sm" :disabled="!canManage" :aria-label="t('blueprints.tree.addGroupTo', { label: section.label })" @change="emit('importFieldset', sectionIndex, selectValue($event))"><option value="">{{ t('blueprints.tree.addGroup') }}</option><option v-for="fieldset in availableFieldsets" :key="fieldset.fieldset_key" :value="fieldset.fieldset_key">{{ fieldset.label }} · {{ t('blueprints.tree.usagesCount', { count: fieldset.usage_count || 0 }) }}</option></select></div>
        </div>
        <details v-if="availableFieldsets.length" class="mb-2">
          <summary class="small text-muted">{{ t('blueprints.tree.availableUsages') }}</summary>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <button v-for="fieldset in availableFieldsets" :key="fieldset.fieldset_key" type="button" class="btn btn-sm btn-outline-secondary" @click="emit('viewFieldsetUsages', fieldset.fieldset_key)">
              {{ fieldset.label }} · {{ fieldset.usage_count || 0 }}
            </button>
          </div>
        </details>
        <ol class="list-group" :aria-label="t('blueprints.tree.fieldsOf', { label: section.label })">
          <li v-for="(field, fieldIndex) in section.fields" :key="field.field_handle + fieldIndex" class="list-group-item blueprint-tree__field" :draggable="canManage" @dragstart.stop="draggedField = { section: sectionIndex, field: fieldIndex }" @dragover.prevent @drop.stop="dropField(sectionIndex, fieldIndex)">
            <span class="dnd-handle" :class="{ 'is-disabled': !canManage }" :title="t('common.dragToReorder')" aria-hidden="true">⋮</span>
            <button type="button" class="blueprint-tree__field-main" @click="emit('editField', sectionIndex, fieldIndex, $event.currentTarget as HTMLElement)"><strong>{{ field.label }}</strong><small><code>{{ field.field_handle }}</code> · {{ field.field_type }} · {{ field.width }}%</small></button>
            <span v-if="field.is_system" class="badge text-bg-secondary">{{ t('blueprints.fieldsets.system') }}</span>
            <div class="blueprint-tree__actions" :aria-label="t('blueprints.tree.fieldActions')">
              <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage || fieldIndex === 0" :aria-label="t('blueprints.tree.moveUp', { label: field.label })" @click="emit('moveField', sectionIndex, fieldIndex, -1)">{{ t('common.moveUp') }}</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage || fieldIndex === section.fields.length - 1" :aria-label="t('blueprints.tree.moveDown', { label: field.label })" @click="emit('moveField', sectionIndex, fieldIndex, 1)">{{ t('common.moveDown') }}</button>
              <select v-if="sections.length > 1" class="form-select form-select-sm" :disabled="!canManage" :aria-label="t('blueprints.tree.changeSection', { label: field.label })" @change="emit('changeFieldSection', sectionIndex, fieldIndex, Number(($event.target as HTMLSelectElement).value))"><option :value="sectionIndex">{{ t('blueprints.tree.changeSectionPlaceholder') }}</option><option v-for="(target, targetIndex) in sections" :key="target.section_key" :value="targetIndex" :disabled="targetIndex === sectionIndex">{{ target.label }}</option></select>
              <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!canManage" :aria-label="t('blueprints.tree.duplicate', { label: field.label })" @click="emit('duplicateField', sectionIndex, fieldIndex)">{{ t('common.duplicate') }}</button>
              <button type="button" class="btn btn-sm btn-outline-danger" :disabled="!canManage || field.is_system || field.is_deletable === false" :aria-label="t('blueprints.tree.remove', { label: field.label })" @click="emit('removeField', sectionIndex, fieldIndex)">{{ t('common.remove') }}</button>
            </div>
          </li>
          <li v-for="(mount, mountIndex) in section.fieldsets" :key="mount.mount_handle + mountIndex" class="list-group-item blueprint-tree__fieldset"><div><strong>{{ t('blueprints.tree.groupLabel', { label: mount.label || mount.fieldset_key }) }}</strong><small class="d-block text-muted"><code>{{ mount.mount_handle }}</code></small></div><button type="button" class="btn btn-sm btn-outline-danger" :disabled="!canManage" :aria-label="t('blueprints.tree.removeGroup', { label: mount.label || mount.fieldset_key })" @click="emit('removeFieldset', sectionIndex, mountIndex)">{{ t('common.remove') }}</button></li>
          <li v-if="!section.fields.length && !section.fieldsets.length" class="list-group-item text-muted">{{ t('blueprints.tree.emptySection') }}</li>
        </ol>
      </li>
    </ol>
  </section>
</template>
