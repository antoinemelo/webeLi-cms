<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
  name: string;
  type?: 'contact' | 'company';
}>(), {
  type: 'contact',
});

const initials = computed(() => {
  const parts = props.name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return props.type === 'company' ? 'O' : 'P';
  return parts.slice(0, 2).map((part) => part[0]?.toUpperCase()).join('');
});
</script>

<template>
  <span class="relation-avatar" :class="`relation-avatar--${type}`" aria-hidden="true">{{ initials }}</span>
</template>

<style scoped>
.relation-avatar {
  display: inline-grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 999px;
  background: #eef2f6;
  color: #344054;
  font-size: .78rem;
  font-weight: 800;
}

.relation-avatar--company {
  background: #ecfeff;
  color: #155e75;
}
</style>
