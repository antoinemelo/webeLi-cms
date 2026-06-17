import { computed } from 'vue';
import { useAdminContextStore } from '@/stores/adminContext';
import { messages, normalizeUiLanguage, type MessageKey } from './messages';

export { DEFAULT_UI_LANGUAGE, UI_LANGUAGES, normalizeUiLanguage } from './messages';
export type { UiLanguageCode } from './messages';

export function translate(key: MessageKey, languageCode: unknown = 'fr', params: Record<string, string|number> = {}): string {
  const lang = normalizeUiLanguage(languageCode);
  let text: string = messages[lang][key] ?? messages.fr[key] ?? key;
  Object.entries(params).forEach(([name, value]) => {
    text = text.replaceAll(`{${name}}`, String(value));
  });
  return text;
}

export function useI18n() {
  const context = useAdminContextStore();
  const languageCode = computed(() => context.uiLanguageCode);
  const t = (key: MessageKey, params: Record<string, string|number> = {}) => translate(key, languageCode.value, params);
  return { languageCode, t };
}
