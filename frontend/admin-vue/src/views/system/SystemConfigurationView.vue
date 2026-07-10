<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n, translate } from '@/i18n';
import type { ConfigurationPayload, ConfigurationGroup, ConfigurationField, MultisitePayload } from '@/api/contracts';

const context = useAdminContextStore();
const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const loading = ref(true);
const saving = ref('');
const error = ref('');
const success = ref('');
const assetPreviewByField = reactive<Record<string, { id: number; public_url?: string; thumbnail_url?: string; original_filename?: string; mime_type?: string } | null>>({});
const uploadingAsset = ref('');
const localizationSeoOpen = ref(false);
const activeTabKey = ref(readInitialTabKey());
const configuration = ref<ConfigurationPayload | null>(null);
const multisite = ref<MultisitePayload | null>(null);
const multisiteLoading = ref(false);
const multisiteSaving = ref(false);
const multisiteDeleting = ref<number | null>(null);
const multisiteError = ref('');
const healthLoading = ref(false);
const healthCheckedAt = ref('');
const healthResults = reactive<Record<'live' | 'ready', { ok: boolean | null; status: number | null; duration_ms: number | null; payload: Record<string, unknown> | null; error: string }>>({
  live: { ok: null, status: null, duration_ms: null, payload: null, error: '' },
  ready: { ok: null, status: null, duration_ms: null, payload: null, error: '' }
});
const subsiteModalOpen = ref(false);
const newSite = reactive({
  site_key: '',
  name: '',
  primary_domain_host: '',
  base_path: '',
  default_language_code: 'fr',
  scheme: 'https',
  enforce_https: true,
  is_active: true
});
const drafts = reactive<Record<string, Record<string, unknown>>>({});


function tabKeyFromQuery(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const key = value.trim();
  if (!key) return null;
  return ['localization', 'seo'].includes(key) ? 'localization_seo' : key;
}

function readInitialTabKey(): string {
  return tabKeyFromQuery(route.query.tab) ?? 'multisite';
}

async function persistActiveTabInUrl(tabKey: string): Promise<void> {
  if (!tabKey || route.query.tab === tabKey) return;
  await router.replace({ query: { ...route.query, tab: tabKey } });
}

type ConfigurationTab = {
  key: string;
  label: string;
  groupKeys: string[];
};

const isMainSite = computed(() => (context.context?.site?.site_key ?? '') === 'main');
const groups = computed<ConfigurationGroup[]>(() => configuration.value?.groups ?? []);
const groupByKey = computed<Record<string, ConfigurationGroup>>(() => Object.fromEntries(groups.value.map((group) => [group.key, group])));
const tabs = computed<ConfigurationTab[]>(() => [
  { key: 'multisite', label: translate('configuration.tab.multisite', context.uiLanguageCode) || 'Multisite', groupKeys: ['site'] },
  { key: 'languages', label: translate('configuration.tab.languages', context.uiLanguageCode) || 'Langues', groupKeys: ['languages', 'backoffice'] },
  { key: 'localization_seo', label: translate('configuration.tab.localization_seo', context.uiLanguageCode) || 'SEO', groupKeys: ['localization', 'seo'] },
  { key: 'articles', label: translate('configuration.tab.articles', context.uiLanguageCode) || 'Articles', groupKeys: ['articles'] },
  { key: 'connection', label: translate('configuration.tab.connection', context.uiLanguageCode) || 'Apparence', groupKeys: ['public_ui'] },
  { key: 'media', label: translate('configuration.tab.media', context.uiLanguageCode) || 'Médias', groupKeys: ['media'] },
  { key: 'relations', label: translate('configuration.tab.relations', context.uiLanguageCode) || 'Relations', groupKeys: ['relations'] },
  { key: 'system', label: translate('configuration.tab.system', context.uiLanguageCode) || 'Système', groupKeys: [] }
].filter((tab) => tab.key === 'system' || tab.groupKeys.some((key) => Boolean(groupByKey.value[key]))));
const activeTab = computed<ConfigurationTab | null>(() => tabs.value.find((tab) => tab.key === activeTabKey.value) ?? tabs.value[0] ?? null);
const activeGroups = computed<ConfigurationGroup[]>(() => activeTab.value?.groupKeys.map((key) => groupByKey.value[key]).filter(Boolean) ?? []);
const activeTabUsesUnifiedSave = computed(() => activeTab.value?.key === 'localization_seo');
const activeTabSaving = computed(() => saving.value === 'localization_seo');
const queryContext = computed(() => ({
  site_id: context.siteId,
  content_language_code: context.contentLanguageCode,
  ui_language_code: context.uiLanguageCode
}));

function ensureValidActiveTab(preferred = activeTabKey.value): void {
  const available = tabs.value;
  if (available.some((tab) => tab.key === preferred)) {
    activeTabKey.value = preferred;
    return;
  }
  activeTabKey.value = available[0]?.key ?? 'multisite';
}


function clone(value: unknown): Record<string, unknown> {
  return JSON.parse(JSON.stringify(value ?? {}));
}

function resetDrafts(): void {
  Object.entries(configuration.value?.values ?? {}).forEach(([key, value]) => {
    drafts[key] = clone(value);
  });
  ensureValidActiveTab();
}


async function loadMultisite(): Promise<void> {
  if (!isMainSite.value) {
    multisite.value = null;
    multisiteError.value = '';
    return;
  }
  multisiteLoading.value = true;
  multisiteError.value = '';
  try {
    const response = await adminApi.get<MultisitePayload>('/multisite', queryContext.value);
    multisite.value = response.data;
    if (!newSite.default_language_code) {
      newSite.default_language_code = response.data.defaults.default_language_code || 'fr';
    }
  } catch (err) {
    multisite.value = null;
    multisiteError.value = apiErrorMessage(err, 'Configuration multisite indisponible depuis ce site.');
  } finally {
    multisiteLoading.value = false;
  }
}

function resetNewSite(): void {
  const main = multisite.value?.main_site;
  newSite.site_key = '';
  newSite.name = '';
  newSite.primary_domain_host = main?.host || window.location.host;
  newSite.base_path = '';
  newSite.default_language_code = multisite.value?.defaults.default_language_code || context.contentLanguageCode || 'fr';
  newSite.scheme = multisite.value?.defaults.scheme || 'https';
  newSite.enforce_https = Boolean(multisite.value?.defaults.enforce_https ?? true);
  newSite.is_active = true;
}

function openCreateSubsiteModal(): void {
  resetNewSite();
  multisiteError.value = '';
  subsiteModalOpen.value = true;
}

function closeCreateSubsiteModal(): void {
  if (multisiteSaving.value) return;
  subsiteModalOpen.value = false;
}

async function createSubsite(): Promise<void> {
  multisiteSaving.value = true;
  error.value = '';
  success.value = '';
  multisiteError.value = '';
  try {
    const response = await adminApi.post<{ site: unknown; multisite: MultisitePayload }>('/multisite/sites', { ...newSite });
    multisite.value = response.data.multisite;
    resetNewSite();
    subsiteModalOpen.value = false;
    await context.load(context.siteId, context.contentLanguageCode);
    success.value = 'Site ajouté. Il apparaît maintenant dans la liste et dans le sélecteur de site.';
  } catch (err) {
    multisiteError.value = apiErrorMessage(err, 'Impossible d’ajouter le site.');
  } finally {
    multisiteSaving.value = false;
  }
}

async function removeSubsite(siteId: number, siteName: string): Promise<void> {
  if (!window.confirm(`Retirer définitivement le sous-site « ${siteName} » ? Ses contenus, routes, médias et réglages liés seront supprimés par cascade.`)) {
    return;
  }
  multisiteDeleting.value = siteId;
  error.value = '';
  success.value = '';
  multisiteError.value = '';
  try {
    const response = await adminApi.delete<{ result: unknown; multisite: MultisitePayload }>(`/multisite/sites/${siteId}`);
    multisite.value = response.data.multisite;
    await context.load(context.siteId, context.contentLanguageCode);
    success.value = 'Site retiré proprement.';
  } catch (err) {
    multisiteError.value = apiErrorMessage(err, 'Impossible de retirer le sous-site.');
  } finally {
    multisiteDeleting.value = null;
  }
}

async function loadConfiguration(): Promise<void> {
  const currentTab = tabKeyFromQuery(route.query.tab) ?? activeTabKey.value;
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<ConfigurationPayload>('/configuration', queryContext.value);
    configuration.value = response.data;
    resetDrafts();
    await refreshAssetPreviews();
    if (isMainSite.value) {
      await loadMultisite();
      resetNewSite();
    } else {
      multisite.value = null;
      multisiteError.value = '';
    }
    ensureValidActiveTab(currentTab);
    await persistActiveTabInUrl(activeTabKey.value);
  } catch (err) {
    error.value = apiErrorMessage(err, t('configuration.loadError'));
  } finally {
    loading.value = false;
  }
}

async function persistGroup(group: ConfigurationGroup, values: Record<string, unknown>): Promise<ConfigurationPayload> {
  const response = await adminApi.patch<ConfigurationPayload>('/configuration', {
    group_key: group.key,
    values,
    site_id: context.siteId,
    content_language_code: context.contentLanguageCode,
    ui_language_code: context.uiLanguageCode
  });
  return response.data;
}

async function saveGroup(group: ConfigurationGroup): Promise<void> {
  const currentTab = activeTabKey.value;
  const savedUiLanguage = group.key === 'backoffice'
    ? String((drafts.backoffice?.admin_ui_language_code ?? context.uiLanguageCode) || 'fr')
    : context.uiLanguageCode;
  saving.value = group.key;
  error.value = '';
  success.value = '';
  try {
    configuration.value = await persistGroup(group, clone(drafts[group.key] ?? {}));
    resetDrafts();
    await refreshAssetPreviews();
    activeTabKey.value = currentTab;
    if (group.key === 'backoffice') {
      context.setUiLanguage(savedUiLanguage);
      activeTabKey.value = currentTab;
    }
    success.value = t('configuration.saved', { group: groupLabel(group) });
  } catch (err) {
    error.value = apiErrorMessage(err, t('configuration.saveError'));
  } finally {
    saving.value = '';
  }
}

async function saveActiveTabGroups(): Promise<void> {
  const currentTab = activeTabKey.value;
  const groupsToSave = activeGroups.value.filter((group) => ['localization', 'seo'].includes(group.key));
  if (!groupsToSave.length) return;
  const payloads = Object.fromEntries(groupsToSave.map((group) => [group.key, clone(drafts[group.key] ?? {})]));
  saving.value = 'localization_seo';
  error.value = '';
  success.value = '';
  try {
    for (const group of groupsToSave) {
      configuration.value = await persistGroup(group, payloads[group.key]);
    }
    resetDrafts();
    await refreshAssetPreviews();
    activeTabKey.value = currentTab;
    success.value = t('configuration.saved', { group: translate('configuration.tab.localization_seo', context.uiLanguageCode) || 'SEO' });
  } catch (err) {
    error.value = apiErrorMessage(err, t('configuration.saveError'));
  } finally {
    saving.value = '';
  }
}

function isGroupSaving(group: ConfigurationGroup): boolean {
  return saving.value === group.key || (activeTabSaving.value && ['localization', 'seo'].includes(group.key));
}

function groupLabel(group: ConfigurationGroup): string {
  const key = `configuration.group.${group.key}` as Parameters<typeof translate>[0];
  return translate(key, context.uiLanguageCode) || group.label;
}

function fieldId(groupKey: string, fieldKey: string): string {
  return `config-${groupKey}-${fieldKey}`;
}
function normalizePathSegment(value: unknown): string {
  const raw = String(value ?? '').trim();
  if (!raw || raw === '/') return '';
  return `/${raw.replace(/^\/+|\/+$/g, '')}`;
}

function sitePublicPreviewBase(): string {
  const site = context.context?.site;
  const absoluteUrl = String(site?.public_url ?? '').trim();
  if (absoluteUrl) {
    try {
      const parsed = new URL(absoluteUrl, window.location.origin);
      const pathname = parsed.pathname.replace(/\/+$/, '') || '';
      return `${pathname}/`;
    } catch {
      // Fall back to path-based construction below.
    }
  }

  const explicitPath = String(site?.public_path ?? '').trim();
  if (explicitPath && explicitPath !== '/') {
    return explicitPath;
  }

  const siteBasePath = normalizePathSegment(site?.base_path);
  const appBasePath = normalizePathSegment(import.meta.env.BASE_URL || window.location.pathname.split('/admin/')[0] || '');
  const basePath = siteBasePath || appBasePath;
  return basePath ? `${basePath}/` : '/';
}

function publicThemePreviewUrl(groupKey: string, field: ConfigurationField): string {
  const param = field.validation?.preview_query_parameter;
  if (!param) return '';
  const value = String(drafts[groupKey]?.[field.key] ?? '');
  const url = new URL(sitePublicPreviewBase(), window.location.origin);
  url.searchParams.set(param, value);
  return url.pathname + url.search;
}


function asText(value: unknown): string {
  if (Array.isArray(value) || (value && typeof value === 'object')) return JSON.stringify(value, null, 2);
  return String(value ?? '');
}

function updateJson(groupKey: string, fieldKey: string, value: string): void {
  try {
    drafts[groupKey][fieldKey] = JSON.parse(value);
  } catch {
    drafts[groupKey][fieldKey] = value;
  }
}

function inputMode(field: ConfigurationField): 'text' | 'number' | 'select' | 'boolean' | 'textarea' | 'site_asset' | 'media' | 'media_storage' | 'color' {
  if (field.type === 'color') return 'color';
  if (field.type === 'number') return 'number';
  if (field.type === 'select') return 'select';
  if (field.type === 'boolean') return 'boolean';
  if (field.type === 'site_asset') return 'site_asset';
  if (field.type === 'media') return 'media';
  if (field.key === 'media_storage' || field.validation?.interface === 'media_storage') return 'media_storage';
  if (['array', 'textarea'].includes(field.type)) return 'textarea';
  return 'text';
}


const localizationSeoFieldKeys = ['default_meta_title_suffix', 'default_meta_description', 'og_default_image_media_id'];

function isLocalizationSeoField(groupKey: string, fieldKey: string): boolean {
  return groupKey === 'localization' && localizationSeoFieldKeys.includes(fieldKey);
}

function mainFields(group: ConfigurationGroup): ConfigurationField[] {
  return group.fields.filter((field) => !isLocalizationSeoField(group.key, field.key));
}

function localizationSeoFields(group: ConfigurationGroup): ConfigurationField[] {
  return group.key === 'localization' ? group.fields.filter((field) => localizationSeoFieldKeys.includes(field.key)) : [];
}


function fieldHelp(field: ConfigurationField): string {
  const description = field.validation?.description;
  return typeof description === 'string' ? description.trim() : '';
}

function fieldColumnClass(field: ConfigurationField, groupKey = ''): string {
  const mode = inputMode(field);
  if (groupKey === 'public_ui' && ['active_theme_key', 'body_font_family', 'heading_font_family', 'main_heading_font_family', 'main_heading_letter_spacing'].includes(field.key)) return 'appearance-col-4';
  if (mode === 'boolean') return 'col-lg-6';
  if (mode === 'textarea' || mode === 'media' || mode === 'media_storage') return 'col-12';
  if (groupKey === 'public_ui' && mode === 'color') return 'appearance-col-4';
  if (mode === 'color') return 'col-lg-4';
  return 'col-lg-6';
}

function setMediaField(groupKey: string, fieldKey: string, mediaId: number): void {
  drafts[groupKey][fieldKey] = mediaId > 0 ? mediaId : null;
}

function assetFieldPreviewKey(groupKey: string, fieldKey: string): string {
  return `${groupKey}.${fieldKey}`;
}

function assetId(groupKey: string, fieldKey: string): number | null {
  const raw = drafts[groupKey]?.[fieldKey];
  const id = Number(raw || 0);
  return Number.isFinite(id) && id > 0 ? id : null;
}

async function loadAssetPreview(groupKey: string, fieldKey: string): Promise<void> {
  const id = assetId(groupKey, fieldKey);
  const previewKey = assetFieldPreviewKey(groupKey, fieldKey);
  if (!id) {
    assetPreviewByField[previewKey] = null;
    return;
  }
  try {
    const response = await adminApi.get<{ asset: { id: number; public_url?: string; thumbnail_url?: string; original_filename?: string; mime_type?: string } }>(`/media/${id}`, queryContext.value);
    assetPreviewByField[previewKey] = response.data.asset;
  } catch {
    assetPreviewByField[previewKey] = { id };
  }
}

async function refreshAssetPreviews(): Promise<void> {
  const mediaGroup = groupByKey.value.media;
  if (!mediaGroup) return;
  await Promise.all(mediaGroup.fields
    .filter((field) => field.type === 'site_asset')
    .map((field) => loadAssetPreview('media', field.key)));
}

async function uploadSiteAsset(groupKey: string, field: ConfigurationField, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  const key = assetFieldPreviewKey(groupKey, field.key);
  uploadingAsset.value = key;
  error.value = '';
  success.value = '';
  try {
    const form = new FormData();
    form.append('file', file);
    form.append('folder_key', 'site-assets');
    form.append('folder_name', 'Identité du site');
    form.append('metadata_json', JSON.stringify({
      folder_key: 'site-assets',
      folder_name: 'Identité du site',
      site_asset_kind: field.validation?.asset_kind || field.key,
      localizations: {
        [context.contentLanguageCode || 'fr']: {
          alt_text: field.validation?.asset_kind === 'logo' ? 'Logo du site' : 'Favicon',
          title: field.label,
          is_alt_verified: true
        }
      }
    }));
    const response = await adminApi.upload<{ media_id: number; status: string }>('/media', form);
    drafts[groupKey][field.key] = response.data.media_id;
    await loadAssetPreview(groupKey, field.key);
    success.value = field.validation?.asset_kind === 'favicon'
      ? 'Favicon téléversé. Enregistrez les paramètres médias pour l’activer.'
      : 'Logo téléversé. Enregistrez les paramètres médias pour l’activer.';
  } catch (err) {
    error.value = apiErrorMessage(err, 'Impossible de téléverser le fichier.');
  } finally {
    uploadingAsset.value = '';
    input.value = '';
  }
}

function clearSiteAsset(groupKey: string, fieldKey: string): void {
  drafts[groupKey][fieldKey] = null;
  assetPreviewByField[assetFieldPreviewKey(groupKey, fieldKey)] = null;
}

type MediaStorageDraft = {
  driver: string;
  s3: {
    endpoint: string;
    bucket: string;
    region: string;
    access_key: string;
    secret_key: string;
    public_base_url: string;
    path_prefix: string;
  };
};

function ensureMediaStorageDraft(groupKey: string): MediaStorageDraft {
  const groupDraft = drafts[groupKey] ?? (drafts[groupKey] = {});
  if (!groupDraft.media_storage || typeof groupDraft.media_storage !== 'object' || Array.isArray(groupDraft.media_storage)) {
    groupDraft.media_storage = {
      driver: 'local',
      s3: {
        endpoint: '',
        bucket: '',
        region: 'us-east-1',
        access_key: '',
        secret_key: '',
        public_base_url: '',
        path_prefix: '',
      },
    };
  }

  const current = groupDraft.media_storage as Partial<MediaStorageDraft>;
  const driver = String(current.driver ?? 'local');
  if (!['local', 's3'].includes(driver)) {
    current.driver = 'local';
  } else if (current.driver !== driver) {
    current.driver = driver;
  }

  if (!current.s3 || typeof current.s3 !== 'object' || Array.isArray(current.s3)) {
    current.s3 = {} as MediaStorageDraft['s3'];
  }

  const s3 = current.s3 as Partial<MediaStorageDraft['s3']>;
  const defaults: MediaStorageDraft['s3'] = {
    endpoint: '',
    bucket: '',
    region: 'us-east-1',
    access_key: '',
    secret_key: '',
    public_base_url: '',
    path_prefix: '',
  };

  for (const [key, fallback] of Object.entries(defaults) as Array<[keyof MediaStorageDraft['s3'], string]>) {
    if (typeof s3[key] !== 'string') {
      s3[key] = String(s3[key] ?? fallback);
    }
    if (key === 'region' && !s3[key]) {
      s3[key] = fallback;
    }
  }

  return current as MediaStorageDraft;
}


const colorFieldKeys = ['color_background', 'color_surface', 'color_text', 'color_muted', 'color_primary', 'color_accent'];
const themeAppearanceFieldKeys = ['body_font_family', 'heading_font_family', 'main_heading_font_family', 'main_heading_letter_spacing', 'show_site_title_in_header', ...colorFieldKeys];

function isHexColor(value: unknown): boolean {
  return /^#[0-9a-fA-F]{6}$/.test(String(value ?? '').trim());
}

function normalizeHexInput(groupKey: string, fieldKey: string): void {
  const value = String(drafts[groupKey]?.[fieldKey] ?? '').trim();
  drafts[groupKey][fieldKey] = value.startsWith('#') ? value.toLowerCase() : `#${value}`.toLowerCase();
}

function colorValue(fieldKey: string, fallback: string): string {
  const value = drafts.public_ui?.[fieldKey];
  return isHexColor(value) ? String(value).toLowerCase() : fallback;
}

const appearancePreviewStyle = computed(() => ({
  '--preview-bg': colorValue('color_background', '#fbfcfe'),
  '--preview-surface': colorValue('color_surface', '#ffffff'),
  '--preview-text': colorValue('color_text', '#102033'),
  '--preview-muted': colorValue('color_muted', '#627086'),
  '--preview-primary': colorValue('color_primary', '#1b5fc1'),
  '--preview-accent': colorValue('color_accent', '#ffb21e'),
  '--preview-font-body': fontStack(String(drafts.public_ui?.body_font_family ?? 'system')),
  '--preview-font-heading': fontStack(String(drafts.public_ui?.heading_font_family ?? 'system')),
  '--preview-font-main-heading': mainHeadingFontStack(String(drafts.public_ui?.main_heading_font_family ?? 'heading')),
  '--preview-main-heading-letter-spacing': mainHeadingLetterSpacingCss(String(drafts.public_ui?.main_heading_letter_spacing ?? 'normal'))
}));

function mainHeadingFontStack(fontKey: string): string {
  return fontKey === 'heading' ? 'var(--preview-font-heading)' : fontStack(fontKey);
}

function mainHeadingLetterSpacingCss(key: string): string {
  if (key === 'relaxed') return '.012em';
  if (key === 'airy') return '.025em';
  return '0em';
}

function fontStack(fontKey: string): string {
  if (fontKey === 'serif') return 'Georgia, Cambria, "Times New Roman", Times, serif';
  if (fontKey === 'slab') return 'Roboto Slab, Rockwell, "Courier Bold", serif';
  if (fontKey === 'mono') return 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace';
  if (fontKey === 'arial') return 'Arial, Helvetica, sans-serif';
  return 'Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
}

function isAppearanceColorField(groupKey: string, fieldKey: string): boolean {
  return groupKey === 'public_ui' && colorFieldKeys.includes(fieldKey);
}

function isStringRecord(value: unknown): value is Record<string, string> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function applyThemeAppearanceDefaults(field: ConfigurationField): void {
  const themeKey = String(drafts.public_ui?.active_theme_key ?? '').trim();
  if (!themeKey) return;
  const defaults = field.validation?.appearance_defaults?.[themeKey];
  if (!isStringRecord(defaults)) return;
  themeAppearanceFieldKeys.forEach((key) => {
    if (Object.prototype.hasOwnProperty.call(defaults, key)) {
      drafts.public_ui[key] = defaults[key];
    }
  });
}

function handleSelectChange(groupKey: string, field: ConfigurationField): void {
  if (groupKey === 'public_ui' && field.key === 'active_theme_key') {
    applyThemeAppearanceDefaults(field);
  }
}


function healthBasePath(): string {
  const configured = window.__AMCMS_ADMIN__?.basePath;
  if (typeof configured === 'string') return configured.replace(/\/$/, '');
  const marker = '/admin/app';
  const index = window.location.pathname.indexOf(marker);
  return index >= 0 ? window.location.pathname.slice(0, index) : '';
}

function healthUrl(kind: 'live' | 'ready'): string {
  return `${healthBasePath()}/health/${kind}` || `/health/${kind}`;
}

async function checkHealth(kind: 'live' | 'ready'): Promise<void> {
  const target = healthResults[kind];
  const started = performance.now();
  target.error = '';
  try {
    const response = await fetch(healthUrl(kind), { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
    const payload = await response.json().catch(() => null) as Record<string, unknown> | null;
    target.status = response.status;
    target.ok = response.ok;
    target.payload = payload;
  } catch (err) {
    target.status = null;
    target.ok = false;
    target.payload = null;
    target.error = err instanceof Error ? err.message : 'Contrôle indisponible.';
  } finally {
    target.duration_ms = Math.round(performance.now() - started);
  }
}

async function checkAllHealth(): Promise<void> {
  healthLoading.value = true;
  await Promise.all([checkHealth('live'), checkHealth('ready')]);
  healthCheckedAt.value = new Date().toLocaleString(context.uiLanguageCode || 'fr');
  healthLoading.value = false;
}

function healthBadgeClass(kind: 'live' | 'ready'): string {
  const state = healthResults[kind].ok;
  return state === true ? 'text-bg-success' : state === false ? 'text-bg-danger' : 'text-bg-secondary';
}

function healthLabel(kind: 'live' | 'ready'): string {
  const state = healthResults[kind].ok;
  return state === true ? 'Opérationnel' : state === false ? 'Indisponible' : 'Non testé';
}

onMounted(loadConfiguration);
watch(() => [context.siteId, context.contentLanguageCode, context.uiLanguageCode], () => { void loadConfiguration(); });
watch(activeTabKey, (tabKey) => { void persistActiveTabInUrl(tabKey); });
watch(() => route.query.tab, (tab) => {
  const requested = tabKeyFromQuery(tab);
  if (requested && requested !== activeTabKey.value) {
    ensureValidActiveTab(requested);
  }
});
</script>

<template>
  <PageHeader :title="t('configuration.title')" :intro="t('configuration.intro')" />
  <ApiFeedback :error="error" :message="success" />

  <section v-if="loading" class="card p-4 text-center text-muted">{{ t('configuration.loading') }}</section>

  <section v-else class="config-page">
    <nav class="editor-tabs" aria-label="Configuration">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        :class="['editor-tab', { active: activeTabKey === tab.key }]"
        @click="activeTabKey = tab.key"
      >
        {{ tab.label }}
      </button>
    </nav>

    <div class="editor-tab-panel">

      <section v-if="activeTabKey === 'system'" class="schema-section config-card">
        <header class="config-card__header">
          <div>
            <h2>Système</h2>
            <p class="muted mb-0">Vue en lecture seule des contrôles de santé natifs. Les paramètres restent gérés par les variables d’environnement et <code>backend/config/health.php</code>.</p>
          </div>
          <button class="btn primary" type="button" :disabled="healthLoading" @click="checkAllHealth">
            {{ healthLoading ? 'Contrôle…' : 'Tester maintenant' }}
          </button>
        </header>

        <div class="row g-3">
          <article v-for="kind in (['live', 'ready'] as const)" :key="kind" class="col-12 col-lg-6">
            <div class="card h-100 p-3">
              <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                  <h3 class="h5 mb-1">/health/{{ kind }}</h3>
                  <small class="text-muted">{{ kind === 'live' ? 'Disponibilité du frontal et de PHP, sans base de données.' : 'Capacité réelle à servir le trafic public.' }}</small>
                </div>
                <span class="badge" :class="healthBadgeClass(kind)">{{ healthLabel(kind) }}</span>
              </div>
              <dl class="row mb-0">
                <dt class="col-5">URL</dt><dd class="col-7"><a :href="healthUrl(kind)" target="_blank" rel="noopener">{{ healthUrl(kind) }}</a></dd>
                <dt class="col-5">HTTP</dt><dd class="col-7">{{ healthResults[kind].status ?? '—' }}</dd>
                <dt class="col-5">Durée navigateur</dt><dd class="col-7">{{ healthResults[kind].duration_ms !== null ? `${healthResults[kind].duration_ms} ms` : '—' }}</dd>
              </dl>
              <p v-if="healthResults[kind].error" class="text-danger mt-3 mb-0">{{ healthResults[kind].error }}</p>
              <pre v-if="healthResults[kind].payload" class="mt-3 mb-0 small overflow-auto">{{ JSON.stringify(healthResults[kind].payload, null, 2) }}</pre>
            </div>
          </article>
        </div>
        <p class="muted mt-3 mb-0">Dernier contrôle : {{ healthCheckedAt || 'aucun' }}. Cette page ne modifie aucune donnée et n’écrit pas dans SQLite.</p>
      </section>

      <section v-if="activeTabUsesUnifiedSave" class="schema-section config-card config-card--tab-actions">
        <header class="config-card__header mb-0">
          <div>
            <h2>{{ translate('configuration.tab.localization_seo', context.uiLanguageCode) || 'SEO' }}</h2>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn ghost" type="button" :disabled="activeTabSaving" @click="resetDrafts">
              Réinitialiser
            </button>
            <button class="btn primary" type="button" :disabled="activeTabSaving" @click="saveActiveTabGroups">
              {{ activeTabSaving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </header>
      </section>

      <section
        v-for="group in activeGroups"
        :key="group.key"
        class="schema-section config-card"
      >
        <header class="config-card__header">
          <h2>{{ groupLabel(group) }}</h2>
          <div class="d-flex gap-2 flex-wrap">
            <button v-if="!activeTabUsesUnifiedSave" class="btn ghost" type="button" :disabled="isGroupSaving(group)" @click="resetDrafts">
              Réinitialiser
            </button>
            <button v-if="!activeTabUsesUnifiedSave" class="btn primary" type="button" :disabled="isGroupSaving(group)" @click="saveGroup(group)">
              {{ isGroupSaving(group) ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </header>

        <div class="row g-3" :class="{ 'appearance-fields-grid': group.key === 'public_ui' }">
          <div
            v-for="field in mainFields(group)"
            :key="field.key"
            class="col-12"
            :class="fieldColumnClass(field, group.key)"
          >
            <label class="form-label fw-bold schema-label-with-hint" :for="fieldId(group.key, field.key)">
              <span>{{ field.label }} <span v-if="field.required" class="text-danger">*</span></span>
              <InfoHint v-if="fieldHelp(field)" :text="fieldHelp(field)" placement="end" />
            </label>

            <div v-if="inputMode(field) === 'site_asset'" class="site-asset-field">
              <div v-if="assetPreviewByField[assetFieldPreviewKey(group.key, field.key)]" class="site-asset-preview">
                <img
                  v-if="assetPreviewByField[assetFieldPreviewKey(group.key, field.key)]?.thumbnail_url || assetPreviewByField[assetFieldPreviewKey(group.key, field.key)]?.public_url"
                  :src="assetPreviewByField[assetFieldPreviewKey(group.key, field.key)]?.thumbnail_url || assetPreviewByField[assetFieldPreviewKey(group.key, field.key)]?.public_url"
                  :alt="field.label"
                />
                <div>
                  <strong>#{{ assetId(group.key, field.key) }}</strong>
                  <small>{{ assetPreviewByField[assetFieldPreviewKey(group.key, field.key)]?.original_filename || 'Média sélectionné' }}</small>
                </div>
              </div>
              <p v-else class="muted mb-2">Aucun fichier actif.</p>

              <div class="d-flex gap-2 flex-wrap">
                <label class="btn ghost mb-0" :for="fieldId(group.key, field.key)">
                  {{ uploadingAsset === assetFieldPreviewKey(group.key, field.key) ? 'Téléversement…' : 'Remplacer' }}
                </label>
                <input
                  :id="fieldId(group.key, field.key)"
                  class="visually-hidden"
                  type="file"
                  :accept="field.validation?.accept || 'image/png,image/jpeg,image/webp,image/gif'"
                  :disabled="uploadingAsset === assetFieldPreviewKey(group.key, field.key)"
                  @change="uploadSiteAsset(group.key, field, $event)"
                />
                <button
                  v-if="assetId(group.key, field.key)"
                  class="btn ghost"
                  type="button"
                  :disabled="uploadingAsset === assetFieldPreviewKey(group.key, field.key)"
                  @click="clearSiteAsset(group.key, field.key)"
                >
                  Retirer
                </button>
              </div>
              <p class="muted mt-2 mb-0">La modification remplace l’élément précédent après enregistrement du groupe Médias.</p>
            </div>


            <div v-else-if="inputMode(field) === 'media_storage'" class="media-storage-config">
              <div class="row g-3">
                <div class="col-12 col-lg-4">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-driver`">Driver</label>
                  <select
                    :id="`${fieldId(group.key, field.key)}-driver`"
                    v-model="ensureMediaStorageDraft(group.key).driver"
                    class="form-select"
                  >
                    <option value="local">Local</option>
                    <option value="s3">S3 compatible</option>
                  </select>
                </div>
              </div>

              <div v-if="ensureMediaStorageDraft(group.key).driver === 's3'" class="row g-3 mt-1">
                <div class="col-12 col-lg-6">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-endpoint`">Endpoint</label>
                  <input :id="`${fieldId(group.key, field.key)}-endpoint`" v-model="ensureMediaStorageDraft(group.key).s3.endpoint" class="form-control" type="url" placeholder="https://s3.example.com" />
                </div>
                <div class="col-12 col-lg-6">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-bucket`">Bucket</label>
                  <input :id="`${fieldId(group.key, field.key)}-bucket`" v-model="ensureMediaStorageDraft(group.key).s3.bucket" class="form-control" type="text" />
                </div>
                <div class="col-12 col-lg-4">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-region`">Region</label>
                  <input :id="`${fieldId(group.key, field.key)}-region`" v-model="ensureMediaStorageDraft(group.key).s3.region" class="form-control" type="text" placeholder="us-east-1" />
                </div>
                <div class="col-12 col-lg-4">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-access-key`">Access key</label>
                  <input :id="`${fieldId(group.key, field.key)}-access-key`" v-model="ensureMediaStorageDraft(group.key).s3.access_key" class="form-control" type="text" autocomplete="off" />
                </div>
                <div class="col-12 col-lg-4">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-secret-key`">Secret key</label>
                  <input :id="`${fieldId(group.key, field.key)}-secret-key`" v-model="ensureMediaStorageDraft(group.key).s3.secret_key" class="form-control" type="password" autocomplete="new-password" />
                </div>
                <div class="col-12 col-lg-6">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-public-base-url`">Public base URL</label>
                  <input :id="`${fieldId(group.key, field.key)}-public-base-url`" v-model="ensureMediaStorageDraft(group.key).s3.public_base_url" class="form-control" type="url" placeholder="https://cdn.example.com" />
                </div>
                <div class="col-12 col-lg-6">
                  <label class="form-label" :for="`${fieldId(group.key, field.key)}-path-prefix`">Path prefix</label>
                  <input :id="`${fieldId(group.key, field.key)}-path-prefix`" v-model="ensureMediaStorageDraft(group.key).s3.path_prefix" class="form-control" type="text" placeholder="prod/site-a" />
                </div>
              </div>
            </div>

            <MediaPicker
              v-else-if="inputMode(field) === 'media'"
              accept-type="image"
              :label="field.label"
              preferred-set="open_graph"
              :media-id="Number(drafts[group.key][field.key] || 0)"
              compact
              @update:media-id="setMediaField(group.key, field.key, $event)"
              @update:src="() => undefined"
              @select="() => undefined"
            />

            <div v-else-if="inputMode(field) === 'color'" class="appearance-color-field">
              <input
                :id="fieldId(group.key, field.key)"
                v-model="drafts[group.key][field.key]"
                class="form-control font-monospace"
                type="text"
                inputmode="text"
                placeholder="#1b5fc1"
                maxlength="7"
                :aria-invalid="isAppearanceColorField(group.key, field.key) && !isHexColor(drafts[group.key][field.key])"
                @blur="normalizeHexInput(group.key, field.key)"
              />
              <input
                v-model="drafts[group.key][field.key]"
                class="appearance-color-field__picker"
                type="color"
                :aria-label="`${field.label} — sélecteur de couleur`"
              />
            </div>

            <input
              v-else-if="inputMode(field) === 'text'"
              :id="fieldId(group.key, field.key)"
              v-model="drafts[group.key][field.key]"
              class="form-control"
              type="text"
            />

            <input
              v-else-if="inputMode(field) === 'number'"
              :id="fieldId(group.key, field.key)"
              v-model.number="drafts[group.key][field.key]"
              class="form-control"
              type="number"
              :min="field.validation?.min"
              :max="field.validation?.max"
            />

            <div v-else-if="inputMode(field) === 'select'" class="config-select-stack">
              <select
                :id="fieldId(group.key, field.key)"
                v-model="drafts[group.key][field.key]"
                class="form-select"
                @change="handleSelectChange(group.key, field)"
              >
                <option v-for="option in field.validation?.options || []" :key="String(option.value)" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <a
                v-if="field.validation?.preview_query_parameter"
                class="config-preview-link"
                :href="publicThemePreviewUrl(group.key, field)"
                target="_blank"
                rel="noopener noreferrer"
              >
                Aperçu du template sélectionné
              </a>
            </div>

            <div v-else-if="inputMode(field) === 'boolean'" class="form-check form-switch config-switch">
              <input
                :id="fieldId(group.key, field.key)"
                v-model="drafts[group.key][field.key]"
                class="form-check-input"
                type="checkbox"
                role="switch"
              />
              <label class="form-check-label" :for="fieldId(group.key, field.key)">{{ t('common.enabled') }}</label>
            </div>

            <textarea
              v-else
              :id="fieldId(group.key, field.key)"
              class="form-control font-monospace"
              :value="asText(drafts[group.key][field.key])"
              rows="8"
              @input="updateJson(group.key, field.key, ($event.target as HTMLTextAreaElement).value)"
            />
          </div>
        </div>

        <section v-if="group.key === 'public_ui'" class="appearance-preview" :style="appearancePreviewStyle" aria-label="Aperçu de l’apparence">
          <div class="appearance-preview__frame">
            <div class="appearance-preview__nav">
              <strong>webeLi</strong>
              <span>Menu</span>
            </div>
            <div class="appearance-preview__content">
              <p class="appearance-preview__eyebrow">Aperçu instantané</p>
              <h3>Un titre lisible sur desktop et mobile</h3>
              <p>Ce texte montre la police principale, la couleur du texte et la couleur secondaire. Les changements sont visibles ici immédiatement, mais ne sont appliqués au site qu’après enregistrement.</p>
              <button type="button">Action principale</button>
            </div>
          </div>
        </section>

        <section v-if="group.key === 'localization' && localizationSeoFields(group).length" class="localization-seo-panel mt-4" aria-labelledby="localization-seo-title">
          <button
            id="localization-seo-title"
            class="btn btn-light localization-seo-panel__toggle"
            type="button"
            :aria-expanded="localizationSeoOpen"
            aria-controls="localization-seo-fields"
            @click="localizationSeoOpen = !localizationSeoOpen"
          >
            <svg class="localization-seo-panel__chevron" :class="{ 'localization-seo-panel__chevron--open': localizationSeoOpen }" aria-hidden="true" viewBox="0 0 16 16" focusable="false">
              <path fill="currentColor" fill-rule="evenodd" d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
            <span>
              <strong>Réglages SEO par défaut</strong>
              <small>Suffixe de titre, description et image OpenGraph utilisées en fallback.</small>
            </span>
          </button>

          <div v-if="localizationSeoOpen" id="localization-seo-fields" class="localization-seo-panel__content row g-3">
            <div
              v-for="field in localizationSeoFields(group)"
              :key="field.key"
              class="col-12"
              :class="fieldColumnClass(field, group.key)"
            >
              <label class="form-label fw-bold" :for="fieldId(group.key, field.key)">
                {{ field.label }}
                <span v-if="field.required" class="text-danger">*</span>
              </label>

              <MediaPicker
                v-if="inputMode(field) === 'media'"
                accept-type="image"
                :label="field.label"
                help="Sélection visuelle depuis la médiathèque. La variante OpenGraph 1200×630 est privilégiée si elle existe."
                preferred-set="open_graph"
                :media-id="Number(drafts[group.key][field.key] || 0)"
                compact
                @update:media-id="setMediaField(group.key, field.key, $event)"
                @update:src="() => undefined"
                @select="() => undefined"
              />

              <input
                v-else-if="inputMode(field) === 'text'"
                :id="fieldId(group.key, field.key)"
                v-model="drafts[group.key][field.key]"
                class="form-control"
                type="text"
              />

              <textarea
                v-else
                :id="fieldId(group.key, field.key)"
                class="form-control"
                :value="asText(drafts[group.key][field.key])"
                rows="5"
                @input="updateJson(group.key, field.key, ($event.target as HTMLTextAreaElement).value)"
              />
            </div>
          </div>
        </section>
      </section>

      <section v-if="activeTab?.key === 'multisite' && isMainSite" class="schema-section config-card multisite-manager">
        <header class="config-card__header">
          <div>
            <h2 class="d-inline-flex align-items-center gap-1">
              Sites rattachés
              <InfoHint text="Gestion centrale depuis le site principal. Le retrait supprime les données liées au site par les contraintes natives de la base." />
            </h2>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn ghost" type="button" :disabled="multisiteLoading" @click="loadMultisite">
              Actualiser
            </button>
            <button class="btn primary" type="button" :disabled="multisiteLoading" @click="openCreateSubsiteModal">
              Ajouter un site
            </button>
          </div>
        </header>

        <p v-if="multisiteError && !subsiteModalOpen" class="alert error">{{ multisiteError }}</p>
        <div v-if="multisiteLoading" class="muted">Chargement des sites…</div>

        <div v-if="multisite" class="subsite-list">
          <div v-if="multisite.subsites.length === 0" class="muted">Aucun site rattaché configuré.</div>
          <article v-for="site in multisite.subsites" :key="site.id" class="subsite-item">
            <div class="subsite-item__main">
              <strong>{{ site.name }}</strong>
              <small>{{ site.site_key }} · {{ site.host }}{{ site.base_path || '/' }} · {{ site.language_count }} langue(s) · {{ site.content_count }} contenu(s)</small>
            </div>
            <div class="subsite-item__actions">
              <a class="btn ghost" :href="site.admin_path">Administrer</a>
              <button class="btn danger" type="button" :disabled="multisiteDeleting === site.id" @click="removeSubsite(site.id, site.name)">
                {{ multisiteDeleting === site.id ? 'Retrait…' : 'Retirer' }}
              </button>
            </div>
          </article>
        </div>
      </section>
    </div>

    <div v-if="subsiteModalOpen && isMainSite" class="modal-backdrop-layer" role="presentation" @click.self="closeCreateSubsiteModal">
      <form class="modal-card subsite-modal" role="dialog" aria-modal="true" aria-labelledby="new-site-title" @submit.prevent="createSubsite">
        <header class="modal-card__header">
          <div>
            <h2 id="new-site-title">Ajouter un site</h2>
            <p class="muted mb-0">Crée un site rattaché au site principal, avec sa langue, son domaine, ses réglages, ses dossiers médias et ses menus de base.</p>
          </div>
          <button class="btn ghost" type="button" :disabled="multisiteSaving" aria-label="Fermer" @click="closeCreateSubsiteModal">×</button>
        </header>

        <div class="modal-card__body">
          <p v-if="multisiteError" class="alert error">{{ multisiteError }}</p>

          <div class="row g-3 subsite-form-grid">
            <div class="col-12 col-lg-6 subsite-field">
              <label class="form-label fw-bold" for="new-site-key">Clé technique</label>
              <input id="new-site-key" v-model="newSite.site_key" class="form-control" type="text" placeholder="site_c" required />
              <p class="form-text">Identifiant stable en base et dans les scripts. Lettres minuscules, chiffres et underscores, par exemple <code>site_c</code>.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field">
              <label class="form-label fw-bold" for="new-site-name">Nom affiché</label>
              <input id="new-site-name" v-model="newSite.name" class="form-control" type="text" placeholder="Site C" required />
              <p class="form-text">Libellé lisible dans le backoffice, les listes de sites et certains fallbacks éditoriaux.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field">
              <label class="form-label fw-bold" for="new-site-host">Domaine</label>
              <input id="new-site-host" v-model="newSite.primary_domain_host" class="form-control" type="text" placeholder="webe.li" required />
              <p class="form-text">Nom d’hôte sans protocole ni chemin. Exemple : <code>webe.li</code> ou <code>demo.example.ch</code>.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field">
              <label class="form-label fw-bold" for="new-site-path">Chemin public</label>
              <input id="new-site-path" v-model="newSite.base_path" class="form-control" type="text" placeholder="/site-c" />
              <p class="form-text">Sous-dossier public du site. Laisser vide pour un site à la racine du domaine.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field">
              <label class="form-label fw-bold" for="new-site-language">Langue par défaut</label>
              <input id="new-site-language" v-model="newSite.default_language_code" class="form-control" type="text" placeholder="fr" required />
              <p class="form-text">Code langue initial du site, par exemple <code>fr</code>, <code>en</code> ou <code>de</code>.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field">
              <label class="form-label fw-bold" for="new-site-scheme">Schéma d’URL</label>
              <select id="new-site-scheme" v-model="newSite.scheme" class="form-select">
                <option value="https">https</option>
                <option value="http">http</option>
              </select>
              <p class="form-text">Protocole utilisé pour générer les URLs publiques, canonical, sitemap et liens d’administration.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field subsite-field--switch">
              <div class="form-check form-switch config-switch mb-2">
                <input id="new-site-active" v-model="newSite.is_active" class="form-check-input" type="checkbox" role="switch" />
                <label class="form-check-label" for="new-site-active">Site actif</label>
              </div>
              <p class="form-text">Désactiver le site empêche sa résolution publique sans supprimer ses contenus.</p>
            </div>
            <div class="col-12 col-lg-6 subsite-field subsite-field--switch">
              <div class="form-check form-switch config-switch mb-2">
                <input id="new-site-enforce-https" v-model="newSite.enforce_https" class="form-check-input" type="checkbox" role="switch" />
                <label class="form-check-label" for="new-site-enforce-https">Forcer HTTPS</label>
              </div>
              <p class="form-text">À garder actif en production pour cohérence SEO et sécurité. À désactiver seulement pour un environnement local HTTP.</p>
            </div>
          </div>
        </div>

        <footer class="modal-card__footer">
          <button class="btn ghost" type="button" :disabled="multisiteSaving" @click="closeCreateSubsiteModal">Annuler</button>
          <button class="btn primary" type="submit" :disabled="multisiteSaving">
            {{ multisiteSaving ? 'Ajout…' : 'Ajouter un site' }}
          </button>
        </footer>
      </form>
    </div>
  </section>
</template>
