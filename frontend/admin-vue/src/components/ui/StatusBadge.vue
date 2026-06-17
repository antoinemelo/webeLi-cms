<script setup lang="ts">
defineProps<{ status?: string }>();
</script>
<template><span class="badge" :class="status || 'draft'">{{ status || 'draft' }}</span></template>
