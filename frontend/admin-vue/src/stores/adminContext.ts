import { defineStore } from 'pinia';
import { adminApi, apiErrorMessage } from '@/api/client';
import type { AdminContext } from '@/api/contracts';
import { DEFAULT_UI_LANGUAGE, normalizeUiLanguage, type UiLanguageCode } from '@/i18n';

const UI_LANGUAGE_STORAGE_KEY = 'amcms.admin.uiLanguage';

function readStoredUiLanguage(): UiLanguageCode | null {
  try {
    const value = window.localStorage.getItem(UI_LANGUAGE_STORAGE_KEY);
    return value ? normalizeUiLanguage(value) : null;
  } catch {
    return null;
  }
}


function adminPathWithRoute(adminPath: string, routePath = '/'): string {
  const target = new URL(adminPath, window.location.origin);
  const route = new URL(routePath || '/', window.location.origin);
  const basePath = target.pathname.replace(/\/$/, '');
  const nextPath = route.pathname.startsWith('/') ? route.pathname : `/${route.pathname}`;
  target.pathname = `${basePath}${nextPath}`.replace(/\/+/g, '/');
  const merged = new URLSearchParams(target.search);
  route.searchParams.forEach((value, key) => merged.set(key, value));
  target.search = merged.toString();
  target.hash = route.hash;
  return `${target.pathname}${target.search}${target.hash}`;
}

function storeUiLanguage(code: UiLanguageCode): void {
  try {
    window.localStorage.setItem(UI_LANGUAGE_STORAGE_KEY, code);
  } catch {
    // localStorage is only a convenience layer; the server configuration remains the source of truth.
  }
}

export const useAdminContextStore = defineStore('adminContext', {
  state: () => ({
    context: null as AdminContext | null,
    loading: false,
    error: '',
    uiLanguageOverride: readStoredUiLanguage() as UiLanguageCode | null
  }),
  getters: {
    siteId: (state) => state.context?.site.id,
    availableSites: (state) => state.context?.available_sites ?? [],
    contentLanguageCode: (state) => state.context?.current_content_language_code ?? state.context?.current_language_code ?? 'fr',
    languageCode: (state) => state.context?.current_content_language_code ?? state.context?.current_language_code ?? 'fr',
    contentLanguages: (state) => state.context?.content_languages ?? state.context?.languages ?? [],
    languages: (state) => state.context?.content_languages ?? state.context?.languages ?? [],
    uiLanguageCode: (state): UiLanguageCode => normalizeUiLanguage(state.uiLanguageOverride ?? state.context?.ui?.language_code ?? DEFAULT_UI_LANGUAGE),
    uiLanguages: (state) => state.context?.ui?.available_languages ?? [],
    can: (state) => (permission: string) => Boolean(state.context?.capabilities?.[permission] || state.context?.permissions.includes(permission) || state.context?.permissions.includes('*')),
    hasEndpoint: (state) => (key: string) => Boolean(state.context?.endpoints.some((endpoint) => endpoint.key === key)),
    moduleNavigation: (state) => state.context?.modules?.navigation ?? [],
    activeModules: (state) => state.context?.modules?.active ?? [],
    moduleDependencyAlerts: (state) => state.context?.modules?.dependency_alerts ?? [],
    isSiteScopedAdmin: (state) => Boolean((state.context?.authorized_site_ids ?? []).length > 0)
  },
  actions: {
    async load(siteId?: number, contentLanguageCode?: string) {
      this.loading = true;
      this.error = '';
      try {
        const response = await adminApi.get<AdminContext>('/context', {
          site_id: siteId,
          content_language_code: contentLanguageCode,
          ui_language_code: this.uiLanguageOverride
        });
        this.context = response.data;
        if (!this.uiLanguageOverride) {
          this.uiLanguageOverride = normalizeUiLanguage(response.data.ui?.language_code ?? DEFAULT_UI_LANGUAGE);
        }
        adminApi.setCsrfToken(response.data.csrf_token);
        adminApi.setContractVersion(response.data.api.contract_version);
      } catch (error) {
        this.error = apiErrorMessage(error, 'Contexte admin indisponible.');
      } finally {
        this.loading = false;
      }
    },
    async switchContentLanguage(contentLanguageCode: string) {
      await this.load(this.siteId, contentLanguageCode);
    },
    switchSite(siteId: number, routePath = '/') {
      const target = (this.context?.available_sites ?? []).find((site) => site.id === siteId);
      if (target?.admin_path) {
        window.location.href = adminPathWithRoute(target.admin_path, routePath);
        return;
      }
      void this.load(siteId, undefined);
    },
    setUiLanguage(code: string) {
      const normalized = normalizeUiLanguage(code);
      this.uiLanguageOverride = normalized;
      storeUiLanguage(normalized);
    }
  }
});
