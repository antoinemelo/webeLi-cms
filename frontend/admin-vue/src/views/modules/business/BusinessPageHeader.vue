<script setup lang="ts">
defineProps<{
  eyebrow?: string;
  title: string;
  intro?: string;
}>();
</script>

<template>
  <header class="business-page-header">
    <div>
      <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
      <h2>{{ title }}</h2>
      <p v-if="intro" class="business-page-header-intro">{{ intro }}</p>
    </div>
    <div v-if="$slots.actions" class="business-page-header-actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.business-page-header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
}

.business-page-header h2 {
  margin: 0;
  font-size: 1.1rem;
}

.business-page-header-intro {
  margin: .2rem 0 0;
  color: #667085;
}

.business-page-header-actions {
  display: flex;
  gap: .5rem;
  align-items: center;
  flex-wrap: wrap;
}

@media (max-width: 760px) {
  .business-page-header {
    flex-direction: column;
  }
}
</style>
