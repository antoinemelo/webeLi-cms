type CommonOptions = { site?: string; lang?: string; limit?: number; type?: string };

type SdkClient = {
  getRoute: (path: string, options?: CommonOptions) => Promise<unknown>;
  getMenu: (key: string, options?: CommonOptions) => Promise<unknown>;
  getContent: (type?: string, options?: CommonOptions) => Promise<unknown>;
  getContentBySlug: (type: string, slug: string, options?: CommonOptions) => Promise<unknown>;
  search: (query: string, options?: CommonOptions) => Promise<unknown>;
};

let sdkClientPromise: Promise<SdkClient | null> | null = null;

export function useAmCmsClient() {
  const config = useRuntimeConfig();
  const baseUrl = String(config.public.amcmsBaseUrl || '').replace(/\/+$/, '');
  const token = String(config.amcmsToken || '');
  const site = String(config.public.amcmsSite || '');
  const lang = String(config.public.amcmsLang || '');

  if (!baseUrl) {
    throw new Error('AMCMS_BASE_URL is required. See examples/headless-nuxt/.env.example.');
  }

  async function getSdkClient(): Promise<SdkClient | null> {
    sdkClientPromise ??= import('@amcms/client')
      .then((mod: any) => {
        if (typeof mod.createAmCmsClient !== 'function') return null;
        return mod.createAmCmsClient({ baseUrl, token: token || undefined, site: site || undefined, lang: lang || undefined }) as SdkClient;
      })
      .catch(() => null);
    return sdkClientPromise;
  }

  async function callWithSdkFallback<T>(sdkCall: (client: SdkClient) => Promise<T>, endpoint: string, query: Record<string, string | number | undefined> = {}): Promise<T> {
    const sdk = await getSdkClient();
    if (sdk) return sdkCall(sdk);
    return nativeFetch<T>(endpoint, query);
  }

  async function nativeFetch<T>(endpoint: string, query: Record<string, string | number | undefined> = {}): Promise<T> {
    const url = new URL(`${baseUrl}${endpoint}`);
    const merged = { site: site || undefined, lang: lang || undefined, ...query };
    Object.entries(merged).forEach(([key, value]) => {
      if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
    });

    const response = await fetch(url, {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });
    const body = await response.json().catch(() => null);
    if (!response.ok) throw normalizeApiError(body, response.status);
    return body as T;
  }

  return {
    getRoute: (path = '/') => callWithSdkFallback((client) => client.getRoute(path), '/api/v1/route', { path }),
    getMenu: (key = 'primary') => callWithSdkFallback((client) => client.getMenu(key), `/api/v1/menus/${encodeURIComponent(key)}`),
    getArticles: (limit = 5) => callWithSdkFallback((client) => client.getContent('article', { limit }), '/api/v1/content/article', { limit }),
    getContentBySlug: (type: string, slug: string) => callWithSdkFallback((client) => client.getContentBySlug(type, slug), `/api/v1/content/${encodeURIComponent(type)}/${encodeURIComponent(slug)}`),
    searchContent: (query = 'test', limit = 5) => callWithSdkFallback((client) => client.search(query, { limit }), '/api/v1/search', { q: query, limit }),
  };
}

export function normalizeApiError(error: unknown, fallbackStatus?: number) {
  const value = error as any;
  return {
    status: value?.status ?? fallbackStatus,
    code: value?.code ?? value?.error,
    message: value?.message ?? `Erreur API DEC CMS${fallbackStatus ? ` (${fallbackStatus})` : ''}`,
    requestId: value?.requestId ?? value?.request_id,
  };
}
