<script setup lang="ts">
import { ref } from 'vue';
type SearchItem = Record<string, unknown> & {
  id: number;
  title?: string;
  subtitle?: string | null;
  excerpt?: string | null;
  status?: string | null;
  channel?: string | null;
};

const props = defineProps<{
  modelValue: string;
  loading?: boolean;
  error?: string;
  groups: Array<{ key: string; label: string; items: SearchItem[] }>;
  placeholder?: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string];
  open: [item: SearchItem];
  create: [];
}>();

const input = ref<HTMLInputElement | null>(null);

function itemTitle(item: SearchItem): string {
  return String(item.title || item.subtitle || `#${item.id}`);
}

function itemSubtitle(item: SearchItem): string {
  return [item.subtitle, item.status, item.channel].filter(Boolean).join(' · ');
}

function hasResults(): boolean {
  return props.groups.some((group) => group.items.length > 0);
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
  <div class="business-quick-search">
    <div class="business-quick-search-control">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
      <input
        ref="input"
        :value="modelValue"
        type="search"
        aria-label="Recherche globale CRM"
        :placeholder="placeholder || 'Recherche rapide'"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      >
      <button v-if="modelValue" type="button" aria-label="Effacer la recherche" @click="emit('update:modelValue', '')">×</button>
    </div>
    <div v-if="modelValue.trim().length >= 2" class="business-quick-search-results" role="listbox" aria-label="Résultats de recherche CRM">
      <p v-if="loading" class="business-empty">Recherche...</p>
      <p v-else-if="error" class="business-empty">{{ error }}</p>
      <template v-else-if="hasResults()">
        <section v-for="group in groups" :key="group.key" class="business-quick-search-group">
          <h3 v-if="group.items.length">{{ group.label }}</h3>
          <button v-for="item in group.items" :key="`${group.key}-${item.id}`" type="button" role="option" @click="emit('open', item)">
            <strong>{{ itemTitle(item) }}</strong>
            <span>{{ itemSubtitle(item) }}</span>
            <small v-if="item.excerpt">{{ item.excerpt }}</small>
          </button>
        </section>
      </template>
      <div v-else class="business-quick-search-empty">
        <p>Aucune relation trouvée.</p>
        <button class="btn small" type="button" @click="emit('create')">Créer une relation</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.business-quick-search {
  position: relative;
  flex: 1 1 420px;
  min-width: min(420px, 100%);
}

.business-quick-search-control {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: .55rem;
  width: 100%;
  min-width: min(420px, 100%);
  min-height: 2.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, .04), inset 0 1px 0 rgba(255, 255, 255, .85);
  padding: .25rem .45rem .25rem .85rem;
  transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
}

.business-quick-search-control:focus-within {
  border-color: #2563eb;
  background: #fff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
}

.business-quick-search-control svg {
  width: 1.05rem;
  height: 1.05rem;
  color: #64748b;
}

.business-quick-search-control input {
  appearance: none;
  -webkit-appearance: none;
  width: 100%;
  min-width: 0;
  min-height: 2.1rem;
  border: 0;
  outline: 0 !important;
  box-shadow: none !important;
  background: transparent;
  color: #0f172a;
  font: inherit;
}

.business-quick-search-control input:focus,
.business-quick-search-control input:focus-visible {
  outline: 0 !important;
  box-shadow: none !important;
}

.business-quick-search-control input::placeholder {
  color: #64748b;
}

.business-quick-search-control button {
  width: 1.85rem;
  height: 1.85rem;
  border: 0;
  border-radius: 999px;
  background: #e2e8f0;
  color: #334155;
  line-height: 1;
  cursor: pointer;
}

.business-quick-search-control button:hover,
.business-quick-search-control button:focus-visible {
  background: #cbd5e1;
}

.business-quick-search-results {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(100% + .35rem);
  z-index: 12;
  max-height: min(520px, 70vh);
  overflow: auto;
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
  padding: .6rem;
}

.business-quick-search-group {
  display: grid;
  gap: .25rem;
}

.business-quick-search-group + .business-quick-search-group {
  margin-top: .6rem;
  padding-top: .6rem;
  border-top: 1px solid #eef2f6;
}

.business-quick-search-group h3 {
  margin: 0 0 .15rem;
  color: #667085;
  font-size: .78rem;
  text-transform: uppercase;
}

.business-quick-search-group button {
  display: grid;
  gap: .1rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  padding: .5rem;
  text-align: left;
  cursor: pointer;
}

.business-quick-search-group button:hover,
.business-quick-search-group button:focus-visible {
  background: #f2f4f7;
  outline: 3px solid rgba(37, 99, 235, .35);
  outline-offset: 1px;
}

.business-quick-search-group span,
.business-quick-search-group small,
.business-quick-search-empty {
  color: #667085;
}

.business-quick-search-empty {
  display: flex;
  gap: .6rem;
  align-items: center;
}

.business-quick-search-empty p {
  margin: 0;
}

@media (max-width: 760px) {
  .business-quick-search {
    flex-basis: 100%;
    min-width: 100%;
  }

  .business-quick-search-results {
    position: fixed;
    inset: auto .75rem .75rem .75rem;
    max-height: min(70vh, 520px);
  }

  .business-quick-search-empty {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
