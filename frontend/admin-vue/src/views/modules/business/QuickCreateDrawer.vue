<script setup lang="ts">
defineProps<{
  title: string;
  intro?: string;
}>();
</script>

<template>
  <section class="quick-create-drawer">
    <header>
      <h3>{{ title }}</h3>
      <p v-if="intro">{{ intro }}</p>
    </header>
    <slot />
  </section>
</template>

<style scoped>
.quick-create-drawer {
  display: grid;
  gap: .85rem;
}

.quick-create-drawer h3 {
  margin: 0;
  font-size: 1rem;
}

.quick-create-drawer p {
  margin: .2rem 0 0;
  color: #667085;
}
</style>
