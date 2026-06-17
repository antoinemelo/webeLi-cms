import type { Metadata } from 'next';
import { getRoute, normalizeApiError } from '../../lib/amcms';
import { dataOf, descriptionOf, titleOf } from '../../lib/payload';

type Props = { params: Promise<{ path?: string[] }> };

function pathFromParams(path?: string[]) {
  return `/${(path ?? []).join('/')}`.replace(/\/+/g, '/');
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const resolved = await params;
  const path = pathFromParams(resolved.path);
  try {
    const route = await getRoute(path);
    return {
      title: titleOf(route, 'DEC CMS'),
      description: descriptionOf(route),
      alternates: { canonical: (dataOf<any>(route)?.route?.canonical ?? dataOf<any>(route)?.canonical_url) as string | undefined },
    };
  } catch {
    return { title: 'Contenu indisponible' };
  }
}

export default async function DynamicPage({ params }: Props) {
  const resolved = await params;
  const path = pathFromParams(resolved.path);

  try {
    const route = await getRoute(path);
    const data = dataOf<any>(route);
    const content = data?.content ?? data;
    return (
      <main>
        <h1>{content?.title ?? data?.title ?? path}</h1>
        {content?.excerpt ? <p>{content.excerpt}</p> : null}
        <pre>{JSON.stringify(data, null, 2)}</pre>
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
