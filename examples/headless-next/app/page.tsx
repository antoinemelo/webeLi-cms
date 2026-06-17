import { getArticles, getContentBySlug, getMenu, getRoute, normalizeApiError, searchContent } from '../lib/amcms';
import { dataOf, listOf, titleOf } from '../lib/payload';

export default async function HomePage() {
  try {
    const [route, menu, articles, results] = await Promise.all([
      getRoute('/'),
      getMenu('primary'),
      getArticles(5),
      searchContent('test', 5),
    ]);

    const articleItems = listOf<any>(articles);
    const firstArticle = articleItems.find((item) => item?.slug);
    const bySlug = firstArticle?.slug ? await getContentBySlug(firstArticle.type || 'article', firstArticle.slug) : null;
    const menuData = dataOf<any>(menu);

    return (
      <main>
        <h1>{titleOf(route, 'Accueil')}</h1>
        <p>Exemple minimal Next.js App Router : route publiée, menu, articles, contenu par type + slug et recherche.</p>

        <nav aria-label="Menu principal">
          {(menuData?.items ?? []).map((item: any) => (
            <a key={item.id ?? item.url ?? item.label} href={item.url ?? item.path ?? '#'}>{item.label ?? item.title ?? 'Lien'}</a>
          ))}
        </nav>

        <section>
          <h2>Articles publiés</h2>
          <ul>
            {articleItems.map((item: any) => (
              <li key={item.id ?? item.slug}>
                <a href={item.path ?? item.url ?? `/articles/${item.slug}`}>{item.title ?? item.slug}</a>
              </li>
            ))}
          </ul>
        </section>

        <section>
          <h2>Contenu par type + slug</h2>
          {bySlug ? <pre>{JSON.stringify(dataOf(bySlug), null, 2)}</pre> : <p>Aucun article avec slug disponible dans la liste retournée.</p>}
        </section>

        <section>
          <h2>Recherche simple</h2>
          <pre>{JSON.stringify(listOf(results), null, 2)}</pre>
        </section>
      </main>
    );
  } catch (error) {
    const apiError = normalizeApiError(error);
    return (
      <main>
        <h1>Erreur API</h1>
        <div className="error">
          <strong>{apiError.status ?? 'Erreur'}</strong> {apiError.code ? `· ${apiError.code}` : ''}
          <p>{apiError.message}</p>
          {apiError.requestId ? <small>Request ID : {apiError.requestId}</small> : null}
        </div>
      </main>
    );
  }
}
