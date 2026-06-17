import type { ApiEnvelope, ApiErrorPayload } from './contracts';

declare global {
  interface Window {
    __AMCMS_ADMIN__?: {
      basePath?: string;
      adminAppPath?: string;
      apiBasePath?: string;
      siteBasePath?: string;
      logoutPath?: string;
      loginPath?: string;
      user?: { id?: number; email?: string; name?: string };
    };
  }
}

function normalizeAbsolutePath(path: string): string {
  const clean = `/${String(path || '').trim().replace(/^\/+|\/+$/g, '')}`;
  return clean === '/' ? '' : clean;
}

function inferBasePathFromAdminApp(): string {
  const marker = '/admin/app';
  const pathname = window.location.pathname;
  const index = pathname.indexOf(marker);
  return index >= 0 ? pathname.slice(0, index) : '';
}


function resolveAdminEntryPath(): string {
  const configured = window.__AMCMS_ADMIN__?.adminAppPath || window.__AMCMS_ADMIN__?.loginPath;
  if (configured && configured.trim() !== '') {
    const normalized = normalizeAbsolutePath(configured);
    return normalized.replace(/\/admin\/(?:app|login)(?:$|[?#].*)/, '/admin');
  }

  const basePath = window.__AMCMS_ADMIN__?.basePath ?? inferBasePathFromAdminApp();
  return `${normalizeAbsolutePath(basePath)}/admin`;
}

let authRedirectStarted = false;

function redirectToAdminOnAuthRequired(): void {
  if (authRedirectStarted) return;
  authRedirectStarted = true;
  const target = resolveAdminEntryPath();
  if (window.location.pathname + window.location.search !== target) {
    window.location.assign(target);
  }
}

function resolveAdminApiBasePath(): string {
  const configured = window.__AMCMS_ADMIN__?.apiBasePath;
  if (configured && configured.trim() !== '') {
    return normalizeAbsolutePath(configured);
  }

  const basePath = window.__AMCMS_ADMIN__?.basePath ?? inferBasePathFromAdminApp();
  return `${normalizeAbsolutePath(basePath)}/admin/api`;
}

export class AdminApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly details: Record<string, unknown>;
  readonly fields: Record<string, string[]>;
  readonly requestId: string;

  constructor(message: string, options: { code?: string; status?: number; details?: Record<string, unknown>; fields?: Record<string, string[]>; requestId?: string } = {}) {
    super(message);
    this.name = 'AdminApiError';
    this.code = options.code ?? 'API_ERROR';
    this.status = options.status ?? 0;
    this.details = options.details ?? {};
    this.fields = options.fields ?? {};
    this.requestId = options.requestId ?? '';
  }
}

export class AdminApiClient {
  private csrfToken = '';
  private contractVersion = 'admin-api-v1';
  constructor(private readonly baseUrl = resolveAdminApiBasePath()) {}

  setCsrfToken(token: string): void { this.csrfToken = token; }
  setContractVersion(version: string): void { this.contractVersion = version || 'admin-api-v1'; }

  async get<T>(path: string, query: Record<string, string|number|boolean|undefined|null> = {}): Promise<ApiEnvelope<T>> {
    return this.request<T>(this.url(path, query), { method: 'GET' });
  }

  async post<T>(path: string, data: unknown): Promise<ApiEnvelope<T>> { return this.mutate<T>('POST', path, data); }
  async upload<T>(path: string, form: FormData): Promise<ApiEnvelope<T>> {
    return this.request<T>(this.url(path), {
      method: 'POST',
      headers: { 'X-CSRF-Token': this.csrfToken, 'X-Contract-Version': this.contractVersion },
      body: form
    });
  }
  async patch<T>(path: string, data: unknown): Promise<ApiEnvelope<T>> { return this.mutate<T>('PATCH', path, data); }
  async put<T>(path: string, data: unknown): Promise<ApiEnvelope<T>> { return this.mutate<T>('PUT', path, data); }
  async delete<T>(path: string, data: unknown = {}): Promise<ApiEnvelope<T>> { return this.mutate<T>('DELETE', path, data); }
  href(path: string, query: Record<string, string|number|boolean|undefined|null> = {}): string { return this.url(path, query); }

  private async mutate<T>(method: string, path: string, data: unknown): Promise<ApiEnvelope<T>> {
    return this.request<T>(this.url(path), {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': this.csrfToken,
        'X-Contract-Version': this.contractVersion
      },
      body: JSON.stringify({ data })
    });
  }

  private async request<T>(input: string, init: RequestInit): Promise<ApiEnvelope<T>> {
    const response = await fetch(input, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', ...(init.headers || {}) },
      ...init
    });
    const payload = await response.json().catch(() => null) as ApiEnvelope<T> | ApiErrorPayload | null;
    if (!response.ok || !payload || 'error' in payload) {
      if (payload && 'error' in payload) {
        if (response.status === 401 || payload.error.code === 'AUTH_REQUIRED') {
          redirectToAdminOnAuthRequired();
        }
        throw new AdminApiError(payload.error.message, {
          code: payload.error.code,
          status: response.status,
          details: payload.error.details,
          fields: payload.error.fields,
          requestId: payload.error.request_id
        });
      }
      if (response.status === 401) {
        redirectToAdminOnAuthRequired();
      }
      throw new AdminApiError(`Erreur HTTP ${response.status}`, { status: response.status });
    }
    return payload;
  }

  private url(path: string, query: Record<string, string|number|boolean|undefined|null> = {}): string {
    const normalized = path.startsWith('/') ? path : `/${path}`;
    const url = new URL(`${this.baseUrl}${normalized}`, window.location.origin);
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
    });
    return url.pathname + url.search;
  }
}

export const adminApi = new AdminApiClient();
export function apiErrorMessage(error: unknown, fallback = 'Erreur API.'): string {
  if (error instanceof AdminApiError) {
    const fieldMessages = Object.entries(error.fields || {})
      .flatMap(([field, messages]) => (Array.isArray(messages) ? messages : []).map((message) => `${field}: ${message}`));
    if (fieldMessages.length > 0) return `${error.message} ${fieldMessages.join(' ')}`;
    const reason = typeof error.details?.reason === 'string' && error.details.reason.trim() !== '' ? ` (${error.details.reason})` : '';
    const requestId = typeof error.details?.request_id === 'string' && error.details.request_id.trim() !== '' ? ` [${error.details.request_id}]` : '';
    return `${error.message}${reason}${requestId}`;
  }
  return error instanceof Error ? error.message : fallback;
}
