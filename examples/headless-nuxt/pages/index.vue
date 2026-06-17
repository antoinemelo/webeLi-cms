<script setup lang="ts">
const api = useAmCmsClient();

const { data, error } = await useAsyncData('amcms-home', async () => {
  const [route, menu, articles, results] = await Promise.all([
    api.getRoute('/'),
    api.getMenu('primary'),
    api.getArticles(5),
    api.searchContent('test', 5),
  ]);
  const articleItems = listOf<any>(articles);
  const firstArticle = articleItems.find((item) => item?.slug);
  const bySlug = firstArticle?.slug ? await api.getContentBySlug(firstArticle.type || 'article', firstArticle.slug) : null;
  return { route, menu, articles, articleItems, bySlug, results };
});

useHead(() => ({
  title: titleOf(data.value?.route, 'Accueil'),
  meta: [{ name: 'description', content: descriptionOf(data.value?.route) }],
}));

const apiError = computed(() => (error.value ? normalizeApiError(error.value) : null));
const menuItems = computed(() => dataOf<any>(data.value?.menu)?.items || []);
const bySlugJson = computed(() => JSON.stringify(dataOf(data.value?.bySlug), null, 2));
const resultsJson = computed(() => JSON.stringify(listOf(data.value?.results), null, 2));
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
      <h1>{{ titleOf(data?.route, 'Accueil') }}</h1>
      <p>Exemple minimal Nuxt 3 : route publiée, menu, articles, contenu par type + slug et recherche.</p>

      <nav aria-label="Menu principal">
        <a v-for="item in menuItems" :key="item.id || item.url || item.label" :href="item.url || item.path || '#'">
          {{ item.label || item.title || 'Lien' }}
        </a>
      </nav>

      <section>
        <h2>Articles publiés</h2>
        <ul>
          <li v-for="item in data?.articleItems || []" :key="item.id || item.slug">
            <a :href="item.path || item.url || `/articles/${item.slug}`">{{ item.title || item.slug }}</a>
          </li>
        </ul>
      </section>

      <section>
        <h2>Contenu par type + slug</h2>
        <pre v-if="data?.bySlug">{{ bySlugJson }}</pre>
        <p v-else>Aucun article avec slug disponible dans la liste retournée.</p>
      </section>

      <section>
        <h2>Recherche simple</h2>
        <pre>{{ resultsJson }}</pre>
      </section>
    </template>
  </main>
</template>
