<script setup lang="ts">
import { onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminShell from '@/components/layout/AdminShell.vue';
import { useAdminContextStore } from '@/stores/adminContext';

const context = useAdminContextStore();
const route = useRoute();
const router = useRouter();

function shouldOpenStudioByDefault(): boolean {
  const hasAdministrativeAccess = context.can('*') || context.can('users.manage') || context.can('roles.manage') || context.can('settings.read') || context.can('maintenance.manage');
  return !hasAdministrativeAccess && context.can('content.read');
}

onMounted(async () => {
  await context.load();
  if (route.path === '/' && shouldOpenStudioByDefault()) {
    await router.replace('/studio');
  }
});
</script>

<template>
  <AdminShell>
    <RouterView />
  </AdminShell>
</template>
