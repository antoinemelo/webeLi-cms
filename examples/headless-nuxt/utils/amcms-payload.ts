export function dataOf<T = any>(payload: any): T | null {
  if (!payload || typeof payload !== 'object') return null;
  return (payload.data ?? payload.item ?? payload.content ?? payload.menu ?? null) as T | null;
}

export function listOf<T = any>(payload: any): T[] {
  if (!payload || typeof payload !== 'object') return [];
  const data = payload.data;
  if (Array.isArray(data)) return data as T[];
  if (Array.isArray(data?.items)) return data.items as T[];
  if (Array.isArray(payload.items)) return payload.items as T[];
  if (Array.isArray(payload.results)) return payload.results as T[];
  if (Array.isArray(data?.results)) return data.results as T[];
  return [];
}

export function titleOf(value: any, fallback = 'DEC CMS'): string {
  const data = dataOf<any>(value) ?? value ?? {};
  return String(data?.seo?.title ?? data?.seo?.meta_title ?? data?.content?.title ?? data?.title ?? data?.route?.title ?? fallback);
}

export function descriptionOf(value: any, fallback = 'Contenu publié exposé par l’API headless v1.'): string {
  const data = dataOf<any>(value) ?? value ?? {};
  return String(data?.seo?.description ?? data?.seo?.meta_description ?? data?.content?.excerpt ?? data?.excerpt ?? fallback);
}
