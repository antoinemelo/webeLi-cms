<script setup lang="ts">
import { computed } from 'vue';

type TimelineItem = {
  id: string | number;
  kind: string;
  title: string;
  detail?: string;
  date?: string | null;
};

const props = defineProps<{
  title: string;
  emptyLabel: string;
  items: TimelineItem[];
  canAddMemo?: boolean;
  actionLabel?: string;
}>();

defineEmits<{ addMemo: [] }>();

const groups = computed(() => {
  const map = new Map<string, TimelineItem[]>();
  for (const item of props.items) {
    const key = String(item.date || '').slice(0, 10) || 'Sans date';
    map.set(key, [...(map.get(key) || []), item]);
  }
  return [...map.entries()].map(([date, items]) => ({ date, items }));
});
</script>

<template>
  <section class="relation-timeline">
    <header>
      <h3>{{ title }}</h3>
      <button v-if="canAddMemo" class="btn small" type="button" @click="$emit('addMemo')">{{ actionLabel || 'Ajouter mémo' }}</button>
    </header>
    <p v-if="!items.length" class="relation-empty">{{ emptyLabel }}</p>
    <div v-else class="relation-timeline-groups">
      <section v-for="group in groups" :key="group.date">
        <h4>{{ group.date }}</h4>
        <ol>
          <li v-for="item in group.items" :key="item.id">
            <span>{{ item.kind }}</span>
            <strong>{{ item.title }}</strong>
            <small>{{ item.date || '—' }}</small>
            <p v-if="item.detail">{{ item.detail }}</p>
          </li>
        </ol>
      </section>
    </div>
  </section>
</template>

<style scoped>
.relation-timeline {
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  padding: .85rem;
}

.relation-timeline header {
  display: flex;
  justify-content: space-between;
  gap: .75rem;
  align-items: center;
  margin-bottom: .65rem;
}

.relation-timeline h3,
.relation-timeline h4 {
  margin: 0 0 .65rem;
  font-size: .95rem;
}

.relation-timeline h4 {
  color: #667085;
  font-size: .82rem;
}

.relation-timeline-groups {
  display: grid;
  gap: .75rem;
}

.relation-timeline ol {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: .55rem;
}

.relation-timeline li {
  display: grid;
  gap: .2rem;
  border-bottom: 1px solid #eef2f6;
  padding-bottom: .55rem;
}

.relation-timeline span,
.relation-timeline small,
.relation-empty {
  color: #667085;
}

.relation-timeline p {
  margin: 0;
  color: #475467;
}
</style>
