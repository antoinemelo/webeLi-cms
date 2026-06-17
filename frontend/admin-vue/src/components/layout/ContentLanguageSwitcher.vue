<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n } from '@/i18n';

const RECENT_STORAGE_KEY = 'amcms.admin.recentContentLanguages';
const context = useAdminContextStore();
const { t } = useI18n();
const open = ref(false);
const recentCodes = ref<string[]>(readRecentCodes());

const current = computed(() => context.contentLanguageCode);
const enabledLanguages = computed(() => context.contentLanguages.filter((lang) => lang.is_enabled));
const directLanguages = computed(() => {
  const candidates = [current.value, ...recentCodes.value, ...enabledLanguages.value.map((lang) => lang.code)];
  const unique = Array.from(new Set(candidates.filter(Boolean)));
  return unique
    .map((code) => enabledLanguages.value.find((lang) => lang.code === code))
    .filter(Boolean)
    .slice(0, 2) as typeof context.contentLanguages;
});
const menuLanguages = computed(() => enabledLanguages.value.filter((lang) => !directLanguages.value.some((direct) => direct.code === lang.code)));

watch(current, (code) => rememberLanguage(code), { immediate: true });

function readRecentCodes(): string[] {
  try {
    const raw = window.localStorage.getItem(RECENT_STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((item) => typeof item === 'string').slice(0, 2) : [];
  } catch {
    return [];
  }
}

function rememberLanguage(code: string) {
  if (!code) return;
  recentCodes.value = [code, ...recentCodes.value.filter((entry) => entry !== code)].slice(0, 2);
  try {
    window.localStorage.setItem(RECENT_STORAGE_KEY, JSON.stringify(recentCodes.value));
  } catch {
    // The UI still works if localStorage is unavailable.
  }
}

function canSwitchContentLanguage(code: string): boolean {
  const event = new CustomEvent('amcms:before-content-language-switch', {
    cancelable: true,
    detail: { from: current.value, to: code }
  });
  return window.dispatchEvent(event);
}

async function selectLanguage(code: string) {
  open.value = false;
  if (code === current.value) {
    return;
  }
  if (!canSwitchContentLanguage(code)) {
    return;
  }
  rememberLanguage(code);
  await context.switchContentLanguage(code);
}
</script>

<template>
  <div class="language-menu language-menu-inline">
    <div class="language-direct" :aria-label="t('language.contentRecent')">
      <button
        v-for="lang in directLanguages"
        :key="lang.code"
        class="language-pill"
        type="button"
        :class="{ active: lang.code === current }"
        :title="`${t('language.content')} : ${lang.name || lang.code.toUpperCase()}`"
        @click="selectLanguage(lang.code)"
      >
        {{ lang.code.slice(0, 2).toUpperCase() }}
      </button>
    </div>
    <button
      v-if="menuLanguages.length"
      class="language-more"
      type="button"
      :aria-expanded="open"
      :title="t('language.contentOther')"
      @click="open = !open"
    >
      ⋯
    </button>
    <div v-if="open" class="language-popover card">
      <button
        v-for="lang in menuLanguages"
        :key="lang.code"
        type="button"
        class="language-option"
        @click="selectLanguage(lang.code)"
      >
        <span>{{ lang.code.toUpperCase() }}</span>
        <small>{{ lang.name || lang.code.toUpperCase() }}</small>
      </button>
    </div>
  </div>
</template>
