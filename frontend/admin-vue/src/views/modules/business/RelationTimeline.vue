<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/i18n';

type TimelineItem = {
  id: string | number;
  kind: string;
  title: string;
  detail?: string;
  date?: string | null;
  action?: string;
  channel?: string;
  siteId?: number;
  groupKey?: string;
  link?: string;
  technical?: string;
};

const props = defineProps<{
  title: string;
  emptyLabel: string;
  items: TimelineItem[];
  canAddMemo?: boolean;
  actionLabel?: string;
  pendingCount?: number;
}>();

defineEmits<{ addMemo: [] }>();
const { t } = useI18n();

const search = ref('');
const kind = ref('');
const channel = ref('');
const page = ref(1);
const pageSize = 10;
const kinds = computed(() => [...new Set(props.items.map((item) => item.kind).filter(Boolean))]);
const channels = computed(() => [...new Set(props.items.map((item) => item.channel || '').filter(Boolean))]);
const filtered = computed(() => props.items.filter((item) => {
  const q = search.value.trim().toLocaleLowerCase();
  if (kind.value && item.kind !== kind.value) return false;
  if (channel.value && item.channel !== channel.value) return false;
  return !q || `${item.title} ${item.detail || ''} ${item.action || ''}`.toLocaleLowerCase().includes(q);
}));
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)));
const visible = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize));
watch([search, kind, channel], () => { page.value = 1; });
const groups = computed(() => {
  const map = new Map<string, TimelineItem[]>();
  for (const item of visible.value) {
    const date = String(item.date || '').slice(0, 10) || t('business.timeline.noDate');
    const key = item.groupKey ? `${item.groupKey} · ${date}` : date;
    map.set(key, [...(map.get(key) || []), item]);
  }
  return [...map.entries()].map(([date, items]) => ({ date, items }));
});
</script>

<template>
  <section class="relation-timeline">
    <header>
      <h3>{{ title }}</h3>
      <button v-if="canAddMemo" class="btn small" type="button" @click="$emit('addMemo')">{{ actionLabel || t('business.relation360.addMemo') }}</button>
    </header>
    <p v-if="pendingCount" class="pending-note"><b>{{ pendingCount }}</b> {{ t('business.timeline.pendingIdentity') }} <router-link to="/business/relations/advanced/profiles">{{ t('business.timeline.review') }}</router-link></p>
    <div v-if="items.length" class="timeline-filters">
      <label>{{ t('common.search') }}<input v-model="search" type="search" :placeholder="t('business.timeline.searchPlaceholder')"></label>
      <label>{{ t('business.timeline.type') }}<select v-model="kind"><option value="">{{ t('business.relations.views.all') }}</option><option v-for="value in kinds" :key="value" :value="value">{{ value }}</option></select></label>
      <label>{{ t('business.timeline.channel') }}<select v-model="channel"><option value="">{{ t('business.relations.views.all') }}</option><option v-for="value in channels" :key="value" :value="value">{{ value }}</option></select></label>
    </div>
    <p v-if="!items.length" class="relation-empty">{{ emptyLabel }}</p>
    <p v-else-if="!filtered.length" class="relation-empty">{{ t('business.timeline.noMatch') }}</p>
    <div v-else class="relation-timeline-groups">
      <section v-for="group in groups" :key="group.date">
        <h4>{{ group.date }}</h4>
        <ol>
          <li v-for="item in group.items" :key="item.id">
            <span>{{ item.kind }}</span>
            <strong>{{ item.title }}</strong>
            <small>{{ item.date || '—' }}</small>
            <p v-if="item.detail">{{ item.detail }}</p>
            <router-link v-if="item.link" :to="item.link">{{ t('business.timeline.openLinked') }}</router-link>
            <details v-if="item.technical"><summary>{{ t('business.timeline.technical') }}</summary><small>{{ item.technical }}</small></details>
          </li>
        </ol>
      </section>
    </div>
    <footer v-if="pages > 1" class="timeline-pagination"><button type="button" :disabled="page === 1" @click="page--">{{ t('common.previous') }}</button><span>{{ t('common.page') }} {{ page }} / {{ pages }}</span><button type="button" :disabled="page === pages" @click="page++">{{ t('common.next') }}</button></footer>
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

.pending-note { padding: .65rem; border-radius: 6px; background: #fff7e6; color: #8a4b08 !important; }
.timeline-filters { display: grid; grid-template-columns: minmax(12rem, 2fr) 1fr 1fr; gap: .55rem; margin-bottom: .8rem; }
.timeline-filters label { display: grid; gap: .2rem; color: #475467; font-size: .8rem; }
.timeline-filters input, .timeline-filters select { min-height: 2.2rem; border: 1px solid #d0d5dd; border-radius: 6px; padding: .35rem .5rem; }
.timeline-pagination { display: flex; justify-content: center; align-items: center; gap: .75rem; margin-top: .75rem; }
.timeline-pagination button { border: 1px solid #d0d5dd; border-radius: 6px; background: white; padding: .35rem .6rem; }
.relation-timeline details { color: #667085; }
@media (max-width: 720px) { .timeline-filters { grid-template-columns: 1fr; } }
</style>
