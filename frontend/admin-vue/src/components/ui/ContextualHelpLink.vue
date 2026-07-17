<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { contextualHelpEntry, type ContextualHelpId } from '@/contextualHelp';
import { useI18n } from '@/i18n';

const props = defineProps<{ id: ContextualHelpId }>();
const { t } = useI18n();
const entry = computed(() => contextualHelpEntry(props.id));
</script>

<template>
  <RouterLink
    class="context-help-link"
    :data-context-help-id="entry.id"
    :to="{ name: 'docs', query: { doc: entry.docId } }"
    :aria-label="t('contextHelp.open')"
    :title="t('contextHelp.open')"
  >
    <svg xmlns="http://www.w3.org/2000/svg" class="bi bi-bookmarks" viewBox="0 0 16 16" aria-hidden="true">
      <path d="M2 4v10.5a.5.5 0 0 0 .777.416L8 11.101l5.223 3.815A.5.5 0 0 0 14 14.5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2m2-1h8a1 1 0 0 1 1 1v9.517l-4.705-3.438a.5.5 0 0 0-.59 0L3 13.517V4a1 1 0 0 1 1-1"/>
      <path d="M4.268 1H12a2 2 0 0 1 2 2v9.732l.223.163A.5.5 0 0 0 15 12.5V3a3 3 0 0 0-3-3H4a3 3 0 0 0-3 3v9.5a.5.5 0 0 0 .777.416L2 12.753V3a2 2 0 0 1 2-2z"/>
    </svg>
  </RouterLink>
</template>

<style scoped>
.context-help-link {
  align-items: center;
  background: transparent;
  border: 1px solid #94a3b8;
  border-radius: 999px;
  color: #64748b;
  display: inline-flex;
  height: 1.45rem;
  justify-content: center;
  line-height: 1;
  text-decoration: none;
  width: 1.45rem;
}
.context-help-link:hover,
.context-help-link:focus-visible {
  background: rgba(var(--bs-primary-rgb, 13, 110, 253), .1);
  border-color: var(--bs-primary, #0d6efd);
  color: var(--bs-primary, #0d6efd);
}
.context-help-link:focus-visible {
  outline: 2px solid #2563eb;
  outline-offset: 2px;
}
.context-help-link svg { display: block; fill: currentColor; height: .82rem; width: .82rem; }
</style>
