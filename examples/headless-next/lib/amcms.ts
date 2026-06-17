type CommonOptions = { site?: string; lang?: string; limit?: number; type?: string };

type SdkClient = {
  getRoute: (path: string, options?: CommonOptions) => Promise<unknown>;
  getMenu: (key: string, options?: CommonOptions) => Promise<unknown>;
  getContent: (type?: string, options?: CommonOptions) => Promise<unknown>;
  getContentBySlug: (type: string, slug: string, options?: CommonOptions) => Promise<unknown>;
  search: (query: string, options?: CommonOptions) => Promise<unknown>;
};

export type ApiErrorView = {
  status?: number;
  code?: string;
  message: string;
  requestId?: string;
};

export const amcmsConfig = {
  baseUrl: requiredEnv('AMCMS_BASE_URL').replace(/\/+$/, ''),
  token: process.env.AMCMS_TOKEN || undefined,
  site: process.env.AMCMS_SITE || undefined,
  lang: process.env.AMCMS_LANG || undefined,
};

let sdkClientPromise: Promise<SdkClient | null> | null = null;

async function getSdkClient(): Promise<SdkClient | null> {
  sdkClientPromise ??= import('@amcms/client')
    .then((mod: any) => {
      if (typeof mod.createAmCmsClient !== 'function') return null;
      return mod.createAmCmsClient({
        baseUrl: amcmsConfig.baseUrl,
        token: amcmsConfig.token,
        site: amcmsConfig.site,
        lang: amcmsConfig.lang,
      }) as SdkClient;
    })
    .catch(() => null);

  return sdkClientPromise;
}

export async function getRoute(path = '/') {
  return callWithSdkFallback((client) => client.getRoute(path), '/api/v1/route', { path });
}

export async function getMenu(key = 'primary') {
  return callWithSdkFallback((client) => client.getMenu(key), `/api/v1/menus/${encodeURIComponent(key)}`);
}

export async function getArticles(limit = 5) {
  return callWithSdkFallback((client) => client.getContent('article', { limit }), '/api/v1/content/article', { limit });
}

export async function getContentBySlug(type: string, slug: string) {
  return callWithSdkFallback(
    (client) => client.getContentBySlug(type, slug),
    `/api/v1/content/${encodeURIComponent(type)}/${encodeURIComponent(slug)}`,
  );
}

export async function searchContent(query = 'test', limit = 5) {
  return callWithSdkFallback((client) => client.search(query, { limit }), '/api/v1/search', { q: query, limit });
}

async function callWithSdkFallback<T>(sdkCall: (client: SdkClient) => Promise<T>, endpoint: string, query: Record<string, string | number | undefined> = {}): Promise<T> {
  const sdk = await getSdkClient();
  if (sdk) return sdkCall(sdk);
  return nativeFetch<T>(endpoint, query);
}

async function nativeFetch<T>(endpoint: string, query: Record<string, string | number | undefined> = {}): Promise<T> {
  const url = new URL(`${amcmsConfig.baseUrl}${endpoint}`);
  const merged = { site: amcmsConfig.site, lang: amcmsConfig.lang, ...query };
  Object.entries(merged).forEach(([key, value]) => {
    if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
  });

  const response = await fetch(url, {
    headers: {
      Accept: 'application/json',
      ...(amcmsConfig.token ? { Authorization: `Bearer ${amcmsConfig.token}` } : {}),
    },
    next: { revalidate: 60 },
  });

  const body = await response.json().catch(() => null);
  if (!response.ok) throw normalizeApiError(body, response.status);
  return body as T;
}

export function normalizeApiError(error: unknown, fallbackStatus?: number): ApiErrorView {
  const value = error as any;
  return {
    status: value?.status ?? fallbackStatus,
    code: value?.code ?? value?.error,
    message: value?.message ?? `Erreur API DEC CMS${fallbackStatus ? ` (${fallbackStatus})` : ''}`,
    requestId: value?.requestId ?? value?.request_id,
  };
}

function requiredEnv(key: string): string {
  const value = process.env[key];
  if (!value) throw new Error(`${key} is required. See examples/headless-next/.env.example.`);
  return value;
}
