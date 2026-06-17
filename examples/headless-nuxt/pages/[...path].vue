<script setup lang="ts">
const route = useRoute();
const api = useAmCmsClient();
const path = computed(() => `/${[route.params.path].flat().filter(Boolean).join('/')}`.replace(/\/+/g, '/'));

const { data, error } = await useAsyncData(`amcms-route-${path.value}`, () => api.getRoute(path.value), { watch: [path] });

useHead(() => ({
  title: titleOf(data.value, 'DEC CMS'),
  meta: [{ name: 'description', content: descriptionOf(data.value) }],
  link: dataOf<any>(data.value)?.route?.canonical ? [{ rel: 'canonical', href: dataOf<any>(data.value)?.route?.canonical }] : [],
}));

const apiError = computed(() => (error.value ? normalizeApiError(error.value) : null));
const content = computed(() => dataOf<any>(data.value)?.content ?? dataOf<any>(data.value));
const routeJson = computed(() => JSON.stringify(dataOf(data.value), null, 2));
</script>

<template>
  <main>
    <template v-if="apiError">
      <h1>Erreur API</h1>
      <div class="error">
        <strong>{{ apiError.status || 'Erreur' }}</strong>
        <span v-if="apiError.code"> · {{ apiError.code }}</span>
        <p>{{ apiError.message }}</p>
        <small v-if="apiError.requestId">Request ID : {{ apiError.requestId }}</small>
      </div>
    </template>

    <template v-else>
      <h1>{{ content?.title || path }}</h1>
      <p v-if="content?.excerpt">{{ content.excerpt }}</p>
      <pre>{{ routeJson }}</pre>
    </template>
  </main>
</template>
