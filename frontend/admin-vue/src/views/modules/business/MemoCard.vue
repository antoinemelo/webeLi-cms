<script setup lang="ts">
defineProps<{
  title: string;
  context?: string;
  date?: string | null;
  body?: string | null;
}>();
</script>

<template>
  <article class="memo-card">
    <strong>{{ title }}</strong>
    <span>{{ context || body || '-' }}</span>
    <small v-if="date">{{ date }}</small>
  </article>
</template>

<style scoped>
.memo-card {
  display: grid;
  gap: .18rem;
  border-bottom: 1px solid #eef2f6;
  padding: .55rem 0;
}

.memo-card span,
.memo-card small {
  color: #667085;
}
</style>
