<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { contextualHelpEntry, type ContextualHelpId } from '@/contextualHelp';
import { useI18n } from '@/i18n';

const props = defineProps<{ id: ContextualHelpId }>();
const { t } = useI18n();
const entry = computed(() => contextualHelpEntry(props.id));
const translationPrefix = computed(() => `contextHelp.${props.id}`);
</script>

<template>
  <aside class="context-help" :data-context-help-id="entry.id" aria-label="Aide contextuelle">
    <div>
      <strong>{{ t(`${translationPrefix}.label`) }}</strong>
      <p>{{ t(`${translationPrefix}.summary`) }}</p>
      <small>{{ t('contextHelp.commonErrors') }} {{ t(`${translationPrefix}.error1`) }} {{ t(`${translationPrefix}.error2`) }}</small>
    </div>
    <RouterLink class="btn btn-outline-secondary btn-sm" :to="{ name: 'docs', query: { doc: entry.docId } }">{{ t('contextHelp.open') }}</RouterLink>
  </aside>
</template>

<style scoped>
.context-help {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  width: 100%;
  padding: .75rem .9rem;
  border: 1px solid #dbe3ef;
  border-radius: .5rem;
  background: #f8fafc;
  color: #334155;
}
.context-help strong { display: block; color: #0f172a; }
.context-help p { margin: .1rem 0; font-size: .92rem; }
.context-help small { display: block; color: #64748b; line-height: 1.35; }
.context-help .btn { white-space: nowrap; }
@media (max-width: 720px) {
  .context-help { align-items: stretch; flex-direction: column; }
  .context-help .btn { width: 100%; }
}
</style>
