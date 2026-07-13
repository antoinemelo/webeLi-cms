<script setup lang="ts">
import { useI18n } from '@/i18n';
import type { BlueprintSection } from './types';
defineProps<{ section: BlueprintSection; sectionIndex: number; canManage: boolean }>();
const emit = defineEmits<{ edit: [index: number] }>();
const { t } = useI18n();
function layoutLabel(layout: string): string { return t(`blueprints.layout.${layout}`) || layout; }
</script>
<template>
  <aside class="card blueprint-inspector" aria-labelledby="blueprint-inspector-title">
    <p class="eyebrow mb-1">{{ t('blueprints.inspector.eyebrow') }}</p>
    <h2 id="blueprint-inspector-title" class="h5">{{ t('blueprints.inspector.title') }}</h2>
    <p class="mb-3"><strong>{{ section.label }}</strong></p>
    <dl class="row small mb-3"><dt class="col-5">{{ t('blueprints.inspector.layout') }}</dt><dd class="col-7">{{ layoutLabel(section.layout) }}</dd><dt class="col-5">{{ t('blueprints.inspector.fields') }}</dt><dd class="col-7">{{ section.fields.length }}</dd><dt class="col-5">{{ t('blueprints.inspector.groups') }}</dt><dd class="col-7">{{ section.fieldsets.length }}</dd></dl>
    <p v-if="section.description" class="text-muted small">{{ section.description }}</p>
    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!canManage" @click="emit('edit', sectionIndex)">{{ t('blueprints.inspector.edit') }}</button>
  </aside>
</template>
