<script setup lang="ts">
import InfoHint from '@/components/ui/InfoHint.vue';
import ContextualHelpLink from '@/components/ui/ContextualHelpLink.vue';
import type { ContextualHelpId } from '@/contextualHelp';

defineProps<{ title: string; intro?: string; eyebrow?: string; helpId?: ContextualHelpId }>();
</script>
<template>
  <div class="page-head">
    <div>
      <p v-if="eyebrow" class="eyebrow mb-1">{{ eyebrow }}</p>
      <h1 class="page-title d-inline-flex align-items-center gap-2 flex-wrap">
        <span>{{ title }}</span>
        <InfoHint v-if="intro" :text="intro" placement="end" />
        <ContextualHelpLink v-if="helpId" :id="helpId" />
      </h1>
    </div>
    <div class="toolbar"><slot name="actions" /></div>
  </div>
</template>
