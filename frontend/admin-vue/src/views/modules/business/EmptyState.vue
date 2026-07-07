<script setup lang="ts">
defineProps<{
  title: string;
  actionLabel?: string;
}>();

defineEmits<{ action: [] }>();
</script>

<template>
  <section class="business-empty-state" role="status">
    <p>{{ title }}</p>
    <button v-if="actionLabel" class="btn small" type="button" @click="$emit('action')">{{ actionLabel }}</button>
  </section>
</template>

<style scoped>
.business-empty-state {
  display: flex;
  gap: .65rem;
  align-items: center;
  justify-content: space-between;
  border: 1px dashed #d0d5dd;
  border-radius: 8px;
  padding: .85rem;
  color: #667085;
}

.business-empty-state p {
  margin: 0;
}

@media (max-width: 760px) {
  .business-empty-state {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
