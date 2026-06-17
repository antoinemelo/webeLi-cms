<script setup lang="ts">
import InfoHint from '@/components/ui/InfoHint.vue';

defineProps<{ title: string; intro?: string }>();
</script>
<template>
  <div class="page-head">
    <div>
      <h1 class="page-title d-inline-flex align-items-center gap-2 flex-wrap">
        <span>{{ title }}</span>
        <InfoHint v-if="intro" :text="intro" placement="end" />
      </h1>
    </div>
    <div class="toolbar"><slot name="actions" /></div>
  </div>
</template>
