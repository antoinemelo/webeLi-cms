import type {
  OpenApiPagination,
  OpenApiPublicApiError,
  OpenApiPublicContentIndexResponse,
  OpenApiPublicContentShowResponse,
  OpenApiPublicMediaResponse,
  OpenApiPublicMenuResponse,
  OpenApiPublicMeta,
  OpenApiPublicRouteResponse,
  OpenApiPublicSaleBootstrapResponse,
  OpenApiPublicSaleCartLineDeleteResponse,
  OpenApiPublicSaleCartLineMutationResponse,
  OpenApiPublicSaleCartResponse,
  OpenApiPublicSaleCheckoutResponse,
  OpenApiPublicSearchResponse,
} from './generated/openapi-types';

export type FetchLike = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;

export interface AmCmsClientOptions {
  baseUrl: string;
  token?: string;
  site?: string;
  site_id?: string | number;
  lang?: string;
  fetch?: FetchLike;
  headers?: Record<string, string>;
}

export interface CommonQueryOptions {
  site?: string;
  site_id?: string | number;
  lang?: string;
  signal?: AbortSignal;
  headers?: Record<string, string>;
}

export interface PaginationOptions extends CommonQueryOptions {
  limit?: number;
  offset?: number;
  page?: number;
}

export interface RouteOptions extends CommonQueryOptions {}

export interface RoutesOptions extends CommonQueryOptions {
  type?: string;
}

export interface ContentOptions extends PaginationOptions {
  taxonomy?: string;
  term?: string;
}

export interface MediaListOptions extends PaginationOptions {
  media_type?: string;
  type?: string;
}

export interface SearchOptions extends PaginationOptions {
  type?: string;
  taxonomy?: string;
  term?: string;
}

export interface MutationOptions extends CommonQueryOptions {
  idempotencyKey?: string;
}

export interface SaleChannelOptions extends CommonQueryOptions {}

export interface SaleCartCreateOptions extends CommonQueryOptions {}

export interface SaleCartLinePayload {
  business_variant_id?: number;
  variant_id?: number;
  quantity: number;
  idempotency_key?: string;
}

export interface SaleCartLineUpdatePayload {
  quantity: number;
}

export interface SaleCheckoutPayload {
  cart_token?: string;
  token?: string;
  idempotency_key?: string;
}

// Types de base générés depuis docs/public-api/openapi.v1.json.
export type PublicMeta = OpenApiPublicMeta;
export type Pagination = OpenApiPagination;
export type PublicApiErrorResponse = OpenApiPublicApiError;

// Payload toléré par le normaliseur d'erreurs du SDK : l'API publique retourne
// la forme OpenAPI `{ error: { code, message, details }, meta }`, mais ce type
// conserve aussi la compatibilité avec les payloads plats plus anciens.
export interface PublicApiErrorPayload {
  error?: string | { code?: string; message?: string; details?: unknown; [key: string]: unknown };
  code?: string;
  message?: string;
  details?: unknown;
  request_id?: string;
  requestId?: string;
  meta?: PublicMeta;
  [key: string]: unknown;
}

export interface PublicRoute {
  path: string;
  resource_type?: string;
  resource_id?: number;
  title?: string;
  updated_at?: string;
  meta_robots?: string;
  type?: string;
  slug?: string;
  canonical_url?: string;
  [key: string]: unknown;
}

export interface PublicContentItem {
  id?: number | string;
  key?: string;
  type?: string;
  slug?: string;
  title?: string;
  summary?: string;
  excerpt?: string | null;
  content?: unknown;
  url?: string;
  path?: string;
  published_at?: string | null;
  updated_at?: string | null;
  seo?: Record<string, unknown>;
  media?: unknown;
  taxonomies?: unknown;
  [key: string]: unknown;
}

export interface PublicMenuItem {
  id?: number | string;
  key?: string;
  label?: string;
  title?: string;
  url?: string;
  path?: string;
  target?: string | null;
  children?: PublicMenuItem[];
  [key: string]: unknown;
}

export interface PublicMenu {
  key: string;
  location?: string | null;
  name: string;
  language_mode?: string;
  item_count?: number;
  items?: PublicMenuItem[];
  [key: string]: unknown;
}

export interface PublicMediaItem {
  id: number | string;
  uuid?: string;
  filename: string;
  title?: string | null;
  alt?: string | null;
  alt_text?: string | null;
  caption?: string | null;
  url: string;
  mime_type: string;
  media_type?: 'image' | 'video' | 'audio' | 'document' | 'binary' | '' | string;
  width?: number | null;
  height?: number | null;
  size?: number | null;
  size_bytes?: number;
  updated_at?: string;
  [key: string]: unknown;
}

export interface PublicLanguage {
  code: string;
  name: string;
  native_name?: string;
  label?: string;
  url_prefix?: string;
  hreflang?: string;
  is_default?: boolean;
  is_active?: boolean;
  is_current?: boolean;
  [key: string]: unknown;
}

export interface PublicTaxonomy {
  key: string;
  name: string;
  archive_enabled?: boolean;
  is_active?: boolean;
  terms?: PublicTaxonomyTerm[];
  [key: string]: unknown;
}

export interface PublicTaxonomyTerm {
  id?: number;
  key: string;
  slug?: string;
  label?: string;
  name: string;
  description?: string;
  path?: string;
  count?: number;
  [key: string]: unknown;
}

export interface PublicSearchResult {
  id?: number | string;
  type?: string;
  slug?: string;
  title?: string;
  summary?: string;
  excerpt?: string | null;
  url?: string;
  path?: string;
  score?: number;
  updated_at?: string;
  seo?: Record<string, unknown>;
  [key: string]: unknown;
}

export interface PublicHealthResponse {
  data?: {
    status: 'ok' | string;
  };
  ok?: boolean;
  status?: string;
  meta?: PublicMeta;
  [key: string]: unknown;
}

export type PublicRouteResponse = OpenApiPublicRouteResponse;

export interface PublicRoutesResponse {
  data?: {
    items: PublicRoute[];
  };
  routes?: PublicRoute[];
  meta?: PublicMeta;
  [key: string]: unknown;
}

export type PublicContentIndexResponse = OpenApiPublicContentIndexResponse;
export type PublicContentShowResponse = OpenApiPublicContentShowResponse;

export interface PublicLanguagesResponse {
  data?: {
    items: PublicLanguage[];
  };
  languages?: PublicLanguage[];
  meta?: PublicMeta;
  [key: string]: unknown;
}

export type PublicMenusResponse = OpenApiPublicMenuResponse;
export type PublicMenuResponse = OpenApiPublicMenuResponse;

export interface PublicTaxonomiesResponse {
  data?: {
    items?: PublicTaxonomy[];
    taxonomy?: PublicTaxonomy;
    terms?: PublicTaxonomyTerm[];
  };
  taxonomies?: PublicTaxonomy[];
  terms?: PublicTaxonomyTerm[];
  meta?: PublicMeta;
  [key: string]: unknown;
}

export type PublicMediaResponse = OpenApiPublicMediaResponse;
export type PublicSearchResponse = OpenApiPublicSearchResponse;
export type PublicSaleBootstrapResponse = OpenApiPublicSaleBootstrapResponse;
export type PublicSaleCartResponse = OpenApiPublicSaleCartResponse;
export type PublicSaleCartLineMutationResponse = OpenApiPublicSaleCartLineMutationResponse;
export type PublicSaleCartLineDeleteResponse = OpenApiPublicSaleCartLineDeleteResponse;
export type PublicSaleCheckoutResponse = OpenApiPublicSaleCheckoutResponse;
