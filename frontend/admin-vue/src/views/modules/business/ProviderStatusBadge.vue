<script setup lang="ts">
defineProps<{
  enabled?: boolean;
  errors?: string[];
}>();
</script>

<template>
  <span
    class="provider-status-badge"
    :class="enabled === false || (errors || []).length ? 'provider-status-badge--disabled' : 'provider-status-badge--enabled'"
    :title="(errors || []).join('; ')"
  >
    {{ enabled === false || (errors || []).length ? 'Désactivé' : 'Actif' }}
  </span>
</template>

<style scoped>
.provider-status-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: .18rem .5rem;
  font-size: .78rem;
  font-weight: 700;
}

.provider-status-badge--enabled {
  background: #ecfdf3;
  color: #027a48;
}

.provider-status-badge--disabled {
  background: #fff1f3;
  color: #b42318;
}
</style>
