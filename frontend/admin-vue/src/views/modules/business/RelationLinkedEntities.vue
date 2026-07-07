<script setup lang="ts">
type LinkedEntity = {
  id: number;
  label: string;
  detail?: string;
  memo_count?: number;
  shared_memo_count?: number;
};

defineProps<{
  title: string;
  emptyLabel: string;
  entities: LinkedEntity[];
}>();

defineEmits<{
  open: [entity: LinkedEntity];
  openMemos: [entity: LinkedEntity];
}>();
</script>

<template>
  <section class="relation-linked">
    <h3>{{ title }}</h3>
    <p v-if="!entities.length" class="relation-empty">{{ emptyLabel }}</p>
    <div v-else class="relation-linked-list">
      <article v-for="entity in entities" :key="entity.id" class="relation-linked-row">
        <button type="button" @click="$emit('open', entity)">{{ entity.label }}</button>
        <span>{{ entity.detail || '—' }}</span>
        <div class="relation-linked-badges">
          <button v-if="Number(entity.memo_count || 0) > 0" type="button" @click="$emit('openMemos', entity)">Mémos {{ entity.memo_count }}</button>
          <button v-if="Number(entity.shared_memo_count || 0) > 0" type="button" @click="$emit('openMemos', entity)">Partagés {{ entity.shared_memo_count }}</button>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.relation-linked {
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  padding: .85rem;
}

.relation-linked h3 {
  margin: 0 0 .65rem;
  font-size: .95rem;
}

.relation-linked-list {
  display: grid;
  gap: .45rem;
}

.relation-linked-row {
  display: grid;
  grid-template-columns: minmax(140px, .8fr) minmax(120px, 1fr) auto;
  gap: .5rem;
  align-items: center;
  border-bottom: 1px solid #eef2f6;
  padding-bottom: .45rem;
}

.relation-linked-row button {
  width: fit-content;
}

.relation-linked-row > button {
  border: 0;
  background: transparent;
  color: #175cd3;
  padding: 0;
  text-align: left;
  cursor: pointer;
  font-weight: 700;
}

.relation-linked-row span,
.relation-empty {
  color: #667085;
}

.relation-linked-badges {
  display: flex;
  gap: .3rem;
  flex-wrap: wrap;
}

.relation-linked-badges button {
  border: 1px solid #cfd8dc;
  border-radius: 999px;
  background: #f8fafc;
  padding: .2rem .45rem;
}

@media (max-width: 760px) {
  .relation-linked-row {
    grid-template-columns: 1fr;
  }
}
</style>
