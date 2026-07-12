import { computed } from 'vue';
import { useAdminContextStore } from '@/stores/adminContext';
import { messages, normalizeUiLanguage, type MessageKey } from './messages';

export { DEFAULT_UI_LANGUAGE, UI_LANGUAGES, normalizeUiLanguage } from './messages';
export type { UiLanguageCode } from './messages';

type TranslationParams = Record<string, string | number>;
type I18nFallbackRegistry = Record<string, number>;

function fallbackRegistry(): I18nFallbackRegistry | null {
  if (typeof window === 'undefined') return null;
  const target = window as Window & { __AMCMS_I18N_FALLBACKS__?: I18nFallbackRegistry };
  target.__AMCMS_I18N_FALLBACKS__ ??= {};
  return target.__AMCMS_I18N_FALLBACKS__;
}

function recordFallback(languageCode: string, key: string): void {
  const registry = fallbackRegistry();
  if (!registry) return;
  const metricKey = `${languageCode}:${key}`;
  registry[metricKey] = (registry[metricKey] ?? 0) + 1;
}

export function translate(key: MessageKey | string, languageCode: unknown = 'fr', params: TranslationParams = {}): string {
  const lang = normalizeUiLanguage(languageCode);
  const catalog = messages[lang] as Record<string, string>;
  const fallbackCatalog = messages.fr as Record<string, string>;
  let text: string = catalog[key] ?? fallbackCatalog[key] ?? '';
  if (!catalog[key]) {
    recordFallback(lang, key);
  }
  if (!text) {
    text = fallbackCatalog['support.i18n.fallback'].replace('{key}', key);
  }
  Object.entries(params).forEach(([name, value]) => {
    text = text.replaceAll(`{${name}}`, String(value));
  });
  return text;
}

export function hasTranslation(key: MessageKey | string, languageCode: unknown = 'fr'): boolean {
  const lang = normalizeUiLanguage(languageCode);
  return Boolean((messages[lang] as Record<string, string>)[key] ?? (messages.fr as Record<string, string>)[key]);
}

export function formatNumber(value: unknown, languageCode: unknown = 'fr', options: Intl.NumberFormatOptions = {}): string {
  const number = Number(value ?? 0);
  return new Intl.NumberFormat(normalizeUiLanguage(languageCode), options).format(Number.isFinite(number) ? number : 0);
}

export function formatPercent(value: unknown, languageCode: unknown = 'fr', options: Intl.NumberFormatOptions = {}): string {
  return formatNumber(value, languageCode, { style: 'percent', maximumFractionDigits: 0, ...options });
}

export function formatMoney(minor: unknown, currency: unknown = 'CHF', languageCode: unknown = 'fr'): string {
  const amount = Number(minor ?? 0) / 100;
  return new Intl.NumberFormat(normalizeUiLanguage(languageCode), {
    style: 'currency',
    currency: String(currency || 'CHF'),
    currencyDisplay: 'narrowSymbol'
  }).format(Number.isFinite(amount) ? amount : 0);
}

export function formatDateTime(value: unknown, languageCode: unknown = 'fr', options: Intl.DateTimeFormatOptions = {}): string {
  const text = String(value || '');
  if (!text) return '-';
  const date = new Date(text.includes('T') ? text : text.replace(' ', 'T') + 'Z');
  if (Number.isNaN(date.getTime())) return text;
  return new Intl.DateTimeFormat(normalizeUiLanguage(languageCode), {
    dateStyle: 'short',
    timeStyle: 'short',
    ...options
  }).format(date);
}

export function useI18n() {
  const context = useAdminContextStore();
  const languageCode = computed(() => context.uiLanguageCode);
  const t = (key: MessageKey | string, params: TranslationParams = {}) => translate(key, languageCode.value, params);
  const n = (value: unknown, options: Intl.NumberFormatOptions = {}) => formatNumber(value, languageCode.value, options);
  const p = (value: unknown, options: Intl.NumberFormatOptions = {}) => formatPercent(value, languageCode.value, options);
  const money = (minor: unknown, currency: unknown = 'CHF') => formatMoney(minor, currency, languageCode.value);
  const dateTime = (value: unknown, options: Intl.DateTimeFormatOptions = {}) => formatDateTime(value, languageCode.value, options);
  return { languageCode, t, n, p, money, dateTime };
}
