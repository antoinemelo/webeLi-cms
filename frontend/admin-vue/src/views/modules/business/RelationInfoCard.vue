<script setup lang="ts">
defineProps<{
  title: string;
  rows: Array<{ label: string; value?: string | number | null }>;
}>();
</script>

<template>
  <section class="relation-info-card">
    <h3>{{ title }}</h3>
    <dl>
      <template v-for="row in rows" :key="row.label">
        <dt>{{ row.label }}</dt>
        <dd>{{ row.value || '—' }}</dd>
      </template>
    </dl>
  </section>
</template>

<style scoped>
.relation-info-card {
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  padding: .85rem;
  min-width: 0;
}

.relation-info-card h3 {
  margin: 0 0 .65rem;
  font-size: .95rem;
}

.relation-info-card dl {
  display: grid;
  grid-template-columns: minmax(110px, .35fr) minmax(0, 1fr);
  gap: .45rem .75rem;
  margin: 0;
}

.relation-info-card dt {
  color: #667085;
}

.relation-info-card dd {
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
}
</style>
