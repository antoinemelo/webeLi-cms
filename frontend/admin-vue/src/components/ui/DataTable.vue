<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{
  columns: Array<{ key: string; label: string; sortable?: boolean }>;
  rows: Array<Record<string, unknown>>;
  hideHeader?: boolean;
  loading?: boolean;
  emptyMessage?: string;
  skeletonRows?: number;
}>(), {
  hideHeader: false,
  loading: false,
  emptyMessage: 'Aucune donnée disponible.',
  skeletonRows: 5,
});

const sortKey = ref('');
const sortDir = ref<'asc' | 'desc'>('asc');

function toggleSort(key: string) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortKey.value = key;
    sortDir.value = 'asc';
  }
}

const sortedRows = computed(() => {
  if (!sortKey.value) return props.rows;
  return [...props.rows].sort((a, b) => {
    const av = String(a[sortKey.value] ?? '').toLowerCase();
    const bv = String(b[sortKey.value] ?? '').toLowerCase();
    const cmp = av.localeCompare(bv, undefined, { numeric: true });
    return sortDir.value === 'asc' ? cmp : -cmp;
  });
});
</script>
<template>
  <div class="card datatable-card">
    <table class="table">
      <thead v-if="!hideHeader">
        <tr>
          <th
            v-for="column in columns"
            :key="column.key"
            :class="{ 'sortable-th': column.sortable }"
            @click="column.sortable ? toggleSort(column.key) : undefined"
          >
            <span class="th-content">
              {{ column.label }}
              <span v-if="column.sortable" class="sort-icon" aria-hidden="true">
                <svg viewBox="0 0 16 16" width="12" height="12">
                  <path :fill="sortKey === column.key && sortDir === 'asc' ? 'currentColor' : '#cbd5e1'" d="M8 3 L11 7 L5 7 Z"/>
                  <path :fill="sortKey === column.key && sortDir === 'desc' ? 'currentColor' : '#cbd5e1'" d="M8 13 L5 9 L11 9 Z"/>
                </svg>
              </span>
            </span>
          </th>
        </tr>
      </thead>
      <tbody>
        <!-- Loading skeleton -->
        <template v-if="loading">
          <tr v-for="i in skeletonRows" :key="`skel-${i}`" class="skeleton-row">
            <td v-for="column in columns" :key="column.key">
              <span class="skeleton skeleton-cell" :style="`width: ${50 + Math.random() * 40}%`"></span>
            </td>
          </tr>
        </template>
        <!-- Data rows -->
        <template v-else>
          <tr v-for="(row, index) in sortedRows" :key="String(row.id ?? index)" class="data-row">
            <td v-for="column in columns" :key="column.key">
              <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
            </td>
          </tr>
          <tr v-if="sortedRows.length === 0">
            <td :colspan="columns.length" class="empty-cell">
              <div class="table-empty-state">
                <svg viewBox="0 0 24 24" width="32" height="32" aria-hidden="true"><path fill="#cbd5e1" d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
                <span>{{ emptyMessage }}</span>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
