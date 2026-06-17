<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { EditorSchema, SchemaField } from '@/api/contracts';
import SchemaFieldRenderer from './SchemaFieldRenderer.vue';
import { fieldKey, schemaTabs } from '@/stores/contentTypes';
const props = defineProps<{ schema: EditorSchema | null; modelValue: Record<string, unknown>; errors?: Record<string, string[]> }>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, unknown>] }>();
const tabs = computed(() => schemaTabs(props.schema));
const activeTab = ref('');
watch(tabs, (next) => { if (!next.some((tab) => tab.key === activeTab.value)) activeTab.value = next[0]?.key || ''; }, { immediate: true });
function setField(field: SchemaField, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [fieldKey(field)]: value });
}
function fieldErrors(field: SchemaField): string[] {
  const key = fieldKey(field);
  return props.errors?.[key] ?? props.errors?.[`fields.${key}`] ?? [];
}
</script>
<template>
  <div v-if="tabs.length > 0" class="schema-form-renderer">
    <nav v-if="tabs.length > 1" class="editor-tabs schema-tabs" aria-label="Onglets du blueprint">
      <button v-for="tab in tabs" :key="tab.key" type="button" :class="['editor-tab', { active: activeTab === tab.key }]" @click="activeTab = tab.key">{{ tab.label }}</button>
    </nav>
    <section v-for="tab in tabs" v-show="activeTab === tab.key || tabs.length === 1" :key="tab.key" class="schema-section">
      <h2>{{ tab.label }}</h2>
      <div class="schema-grid">
        <SchemaFieldRenderer v-for="field in tab.fields" :key="fieldKey(field)" :field="field" :model-value="modelValue[fieldKey(field)]" :errors="fieldErrors(field)" @update:model-value="setField(field, $event)" />
      </div>
    </section>
  </div>
  <div v-else class="card muted">Aucun champ personnalisé pour ce type de contenu.</div>
</template>
