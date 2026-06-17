import { AmCmsApiError } from './errors';
import type {
  AmCmsClientOptions,
  CommonQueryOptions,
  ContentOptions,
  FetchLike,
  MediaListOptions,
  PublicApiErrorPayload,
  PublicContentIndexResponse,
  PublicContentShowResponse,
  PublicHealthResponse,
  PublicLanguagesResponse,
  PublicMediaResponse,
  PublicMenuResponse,
  PublicMenusResponse,
  PublicRouteResponse,
  PublicRoutesResponse,
  PublicSearchResponse,
  PublicTaxonomiesResponse,
  RouteOptions,
  RoutesOptions,
  SearchOptions,
} from './types';

type QueryValue = string | number | boolean | null | undefined;
type QueryInput = Record<string, QueryValue | QueryValue[]>;

const COMMON_QUERY_KEYS = new Set(['site', 'site_id', 'lang']);

export class AmCmsClient {
  private readonly baseUrl: string;
  private readonly token?: string;
  private readonly site?: string;
  private readonly siteId?: string | number;
  private readonly lang?: string;
  private readonly fetchImpl: FetchLike;
  private readonly defaultHeaders: Record<string, string>;

  constructor(options: AmCmsClientOptions) {
    if (!options.baseUrl) {
      throw new Error('AmCmsClient requires a baseUrl.');
    }

    const fetchImpl = options.fetch ?? globalThis.fetch;
    if (!fetchImpl) {
      throw new Error('No fetch implementation available. Pass a custom fetch in AmCmsClient options.');
    }

    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.token = options.token;
    this.site = options.site;
    this.siteId = options.site_id;
    this.lang = options.lang;
    this.fetchImpl = fetchImpl.bind(globalThis) as FetchLike;
    this.defaultHeaders = options.headers ?? {};
  }

  getHealth(options: CommonQueryOptions = {}): Promise<PublicHealthResponse> {
    return this.request('/api/v1/health', { query: this.withCommonQuery({}, options), options });
  }

  getRoute(path: string, options: RouteOptions = {}): Promise<PublicRouteResponse> {
    return this.request('/api/v1/route', {
      query: this.withCommonQuery({ path }, options),
      options,
    });
  }

  getRoutes(options: RoutesOptions = {}): Promise<PublicRoutesResponse> {
    return this.request('/api/v1/routes', {
      query: this.withCommonQuery({ type: options.type }, options),
      options,
    });
  }

  getContent(type?: string, options: ContentOptions = {}): Promise<PublicContentIndexResponse> {
    const endpoint = type ? `/api/v1/content/${encodeSegment(type)}` : '/api/v1/content';
    return this.request(endpoint, {
      query: this.withCommonQuery(
        {
          limit: options.limit,
          offset: options.offset,
          page: options.page,
          taxonomy: options.taxonomy,
          term: options.term,
        },
        options,
      ),
      options,
    });
  }

  getContentBySlug(type: string, slug: string, options: CommonQueryOptions = {}): Promise<PublicContentShowResponse> {
    return this.request(`/api/v1/content/${encodeSegment(type)}/${encodeSegment(slug)}`, {
      query: this.withCommonQuery({}, options),
      options,
    });
  }

  getLanguages(options: CommonQueryOptions = {}): Promise<PublicLanguagesResponse> {
    return this.request('/api/v1/languages', { query: this.withCommonQuery({}, options), options });
  }

  getMenus(options: CommonQueryOptions = {}): Promise<PublicMenusResponse> {
    return this.request('/api/v1/menus', { query: this.withCommonQuery({}, options), options });
  }

  getMenu(key: string, options: CommonQueryOptions = {}): Promise<PublicMenuResponse> {
    return this.request(`/api/v1/menus/${encodeSegment(key)}`, {
      query: this.withCommonQuery({}, options),
      options,
    });
  }

  getTaxonomies(options: CommonQueryOptions = {}): Promise<PublicTaxonomiesResponse> {
    return this.request('/api/v1/taxonomies', { query: this.withCommonQuery({}, options), options });
  }

  getTaxonomy(key: string, options: CommonQueryOptions = {}): Promise<PublicTaxonomiesResponse> {
    return this.request(`/api/v1/taxonomies/${encodeSegment(key)}`, {
      query: this.withCommonQuery({}, options),
      options,
    });
  }

  getMediaList(options: MediaListOptions = {}): Promise<PublicMediaResponse> {
    return this.request('/api/v1/media', {
      query: this.withCommonQuery(
        {
          limit: options.limit,
          offset: options.offset,
          page: options.page,
          media_type: options.media_type,
          type: options.type,
        },
        options,
      ),
      options,
    });
  }

  getMedia(id: string | number, options: CommonQueryOptions = {}): Promise<PublicMediaResponse> {
    return this.request(`/api/v1/media/${encodeSegment(String(id))}`, {
      query: this.withCommonQuery({}, options),
      options,
    });
  }

  search(query: string, options: SearchOptions = {}): Promise<PublicSearchResponse> {
    return this.request('/api/v1/search', {
      query: this.withCommonQuery(
        {
          q: query,
          limit: options.limit,
          offset: options.offset,
          page: options.page,
          type: options.type,
          taxonomy: options.taxonomy,
          term: options.term,
        },
        options,
      ),
      options,
    });
  }

  private withCommonQuery(query: QueryInput, options: CommonQueryOptions): QueryInput {
    const merged: QueryInput = {
      site: options.site ?? this.site,
      site_id: options.site_id ?? this.siteId,
      lang: options.lang ?? this.lang,
      ...query,
    };

    for (const key of COMMON_QUERY_KEYS) {
      if (merged[key] === undefined || merged[key] === null || merged[key] === '') {
        delete merged[key];
      }
    }

    return merged;
  }

  private async request<T>(
    endpoint: string,
    params: { query?: QueryInput; options?: CommonQueryOptions } = {},
  ): Promise<T> {
    const url = new URL(`${this.baseUrl}${endpoint}`);
    appendQuery(url, params.query ?? {});

    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...this.defaultHeaders,
      ...(params.options?.headers ?? {}),
    };

    if (this.token) {
      headers.Authorization = `Bearer ${this.token}`;
    }

    const response = await this.fetchImpl(url, {
      method: 'GET',
      headers,
      signal: params.options?.signal,
    });

    const payload = await readJson(response);

    if (!response.ok) {
      throw toApiError(response.status, payload);
    }

    return payload as T;
  }
}

export function createAmCmsClient(options: AmCmsClientOptions): AmCmsClient {
  return new AmCmsClient(options);
}

function appendQuery(url: URL, query: QueryInput): void {
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') {
      continue;
    }
    if (Array.isArray(value)) {
      for (const item of value) {
        if (item !== undefined && item !== null && item !== '') {
          url.searchParams.append(key, String(item));
        }
      }
      continue;
    }
    url.searchParams.set(key, String(value));
  }
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text();
  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text) as unknown;
  } catch {
    return text;
  }
}

function toApiError(status: number, payload: unknown): AmCmsApiError {
  const body = isObject(payload) ? (payload as PublicApiErrorPayload) : undefined;
  const nestedError = isObject(body?.error) ? body.error : undefined;
  const message =
    typeof body?.message === 'string' && body.message
      ? body.message
      : typeof nestedError?.message === 'string' && nestedError.message
        ? nestedError.message
        : `DEC CMS API request failed with status ${status}`;
  const requestId =
    typeof body?.request_id === 'string'
      ? body.request_id
      : typeof body?.requestId === 'string'
        ? body.requestId
        : typeof body?.meta?.request_id === 'string'
          ? body.meta.request_id
          : undefined;

  return new AmCmsApiError({
    status,
    message,
    code:
      typeof body?.code === 'string'
        ? body.code
        : typeof body?.error === 'string'
          ? body.error
          : typeof nestedError?.code === 'string'
            ? nestedError.code
            : undefined,
    details: body?.details ?? nestedError?.details,
    requestId,
    payload,
  });
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function encodeSegment(value: string): string {
  return encodeURIComponent(value);
}
