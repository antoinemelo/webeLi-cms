<script setup lang="ts">
import StatusBadge from '@/components/ui/StatusBadge.vue';

defineProps<{
  title: string;
  context?: string;
  status?: string | null;
}>();
</script>

<template>
  <article class="message-card">
    <strong>{{ title }}</strong>
    <span>{{ context || '-' }}</span>
    <StatusBadge :status="status || 'pending'" />
  </article>
</template>

<style scoped>
.message-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: .55rem;
  align-items: center;
  border-bottom: 1px solid #eef2f6;
  padding: .55rem 0;
}

.message-card span {
  color: #667085;
}

@media (max-width: 760px) {
  .message-card {
    grid-template-columns: 1fr;
  }
}
</style>
