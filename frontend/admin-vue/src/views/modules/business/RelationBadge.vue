<script setup lang="ts">
withDefaults(defineProps<{
  label: string | number;
  title?: string;
  ariaLabel?: string;
  tone?: 'neutral' | 'warning' | 'accent';
  clickable?: boolean;
}>(), {
  tone: 'neutral',
  clickable: false,
});

defineEmits<{ click: [] }>();
</script>

<template>
  <button
    v-if="clickable"
    class="relation-badge"
    :class="`relation-badge--${tone}`"
    type="button"
    :title="title"
    :aria-label="ariaLabel || title || String(label)"
    @click="$emit('click')"
  >
    {{ label }}
  </button>
  <span v-else class="relation-badge" :class="`relation-badge--${tone}`" :title="title">{{ label }}</span>
</template>

<style scoped>
.relation-badge {
  display: inline-flex;
  align-items: center;
  max-width: 8rem;
  min-height: 1.45rem;
  border: 1px solid #cfd8dc;
  border-radius: 999px;
  background: #f8fafc;
  color: #344054;
  padding: .12rem .42rem;
  font-size: .75rem;
  line-height: 1.15rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

button.relation-badge {
  cursor: pointer;
}

button.relation-badge:focus-visible {
  outline: 3px solid rgba(37, 99, 235, .35);
  outline-offset: 2px;
}

.relation-badge--warning {
  border-color: #f59e0b;
  background: #fffbeb;
  color: #92400e;
}

.relation-badge--accent {
  border-color: #93c5fd;
  background: #eff6ff;
  color: #1d4ed8;
}
</style>
