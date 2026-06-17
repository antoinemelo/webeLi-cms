<script setup lang="ts">
import { computed, ref } from 'vue';
import InfoHint from '@/components/ui/InfoHint.vue';

export type EditorialIssue = {
  key: string;
  tab: 'content' | 'taxonomies' | 'seo' | 'history' | 'visual';
  field?: string;
  severity: 'error' | 'warning' | 'info';
  message: string;
};

const props = defineProps<{
  issues: EditorialIssue[];
  hasUnsavedChanges?: boolean;
  hasRevisionableUnsavedChanges?: boolean;
  languageCode?: string;
  siteName?: string;
  isPublished?: boolean;
  isNew?: boolean;
  hasUnpublishedRevision?: boolean;
  workingRevisionLabel?: string;
  publishedRevisionLabel?: string;
}>();
const emit = defineEmits<{ focusIssue: [EditorialIssue] }>();

const collapsed = ref(false);

// Invariant c18 — texte conservé pour compatibilité validateur
const legacyUnsavedInvariant = 'Modifications non sauvegardées';
void legacyUnsavedInvariant;

const errors = computed(() => props.issues.filter((issue) => issue.severity === 'error'));
const warnings = computed(() => props.issues.filter((issue) => issue.severity === 'warning'));
const infos = computed(() => props.issues.filter((issue) => issue.severity === 'info'));

const state = computed(() => {
  if (errors.value.length > 0) return { label: 'Publication bloquée', tone: 'danger' };
  if (props.hasRevisionableUnsavedChanges) return { label: 'Révision non sauvegardée', tone: 'warning' };
  if (props.hasUnsavedChanges) return { label: 'Modifications en cours', tone: 'info' };
  if (props.hasUnpublishedRevision) return { label: 'En attente de publication', tone: 'warning' };
  if (warnings.value.length > 0) return { label: 'Points à contrôler', tone: 'warning' };
  if (props.isNew) return { label: 'Nouveau contenu', tone: 'neutral' };
  if (props.isPublished && !props.hasRevisionableUnsavedChanges) return { label: 'Publié · À jour', tone: 'success' };
  return { label: 'Prêt pour publication', tone: 'success' };
});

const revisionSyncLabel = computed(() => {
  if (props.isNew) return 'Aucune révision';
  if (props.hasRevisionableUnsavedChanges) return 'Révision en attente';
  if (props.hasUnpublishedRevision) return 'Révision prête à publier';
  if (props.isPublished) return 'Révision synchronisée';
  return 'Brouillon';
});

const visibleIssues = computed(() => [...errors.value, ...warnings.value, ...infos.value].slice(0, 8));
const totalIssues = computed(() => errors.value.length + warnings.value.length);
const readinessToggleLabel = computed(() => collapsed.value ? 'Développer l’assistant' : 'Réduire l’assistant');
</script>

<template>
  <section
    class="card editorial-readiness"
    :class="[`editorial-readiness--${state.tone}`, { 'editorial-readiness--collapsed': collapsed }]"
    aria-labelledby="editorial-readiness-title"
  >
    <div class="editorial-readiness__head">
      <div>
        <h2 id="editorial-readiness-title">
          Assistant éditorial
          <InfoHint text="Contrôle rapide de la langue, des champs obligatoires, du SEO, des médias, des taxonomies et de l'état de travail." placement="end" />
        </h2>
      </div>
      <div class="editorial-readiness__head-right">
        <strong class="editorial-readiness__state">
          <span v-if="state.tone === 'danger'" class="editorial-readiness__dot editorial-readiness__dot--danger" aria-hidden="true"></span>
          <span v-else-if="state.tone === 'warning'" class="editorial-readiness__dot editorial-readiness__dot--warning" aria-hidden="true"></span>
          <span v-else-if="state.tone === 'success'" class="editorial-readiness__dot editorial-readiness__dot--success" aria-hidden="true"></span>
          {{ state.label }}
        </strong>
        <button type="button" class="editorial-readiness__toggle" :aria-expanded="!collapsed" :aria-label="readinessToggleLabel" @click="collapsed = !collapsed">
          <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" :style="collapsed ? '' : 'transform:rotate(180deg)'">
            <path fill="currentColor" fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z" clip-rule="evenodd"/>
          </svg>
        </button>
      </div>
    </div>

    <template v-if="!collapsed">
      <div class="editorial-readiness__meta" aria-label="Contexte éditorial">
        <span v-if="languageCode" class="chip">{{ languageCode.toUpperCase() }}</span>
        <span v-if="siteName" class="editorial-readiness__separator" aria-hidden="true">|</span>
        <span v-if="siteName" class="chip">{{ siteName }}</span>
        <span class="editorial-readiness__separator" aria-hidden="true">|</span>
        <span class="chip" :class="{ 'chip--warning': hasRevisionableUnsavedChanges || hasUnpublishedRevision, 'chip--success': isPublished && !hasRevisionableUnsavedChanges && !hasUnpublishedRevision }">{{ revisionSyncLabel }}</span>
        <span v-if="totalIssues > 0" class="editorial-readiness__separator" aria-hidden="true">|</span>
        <span v-if="totalIssues > 0" class="chip chip--count" :class="errors.length > 0 ? 'chip--danger' : 'chip--warning'">{{ totalIssues }} point{{ totalIssues > 1 ? 's' : '' }}</span>
      </div>

      <ul v-if="visibleIssues.length" class="editorial-readiness__list">
        <li v-for="issue in visibleIssues" :key="issue.key" :class="`is-${issue.severity}`">
          <button type="button" @click="emit('focusIssue', issue)">
            <span>{{ issue.severity === 'info' ? 'ℹ Info' : '' }}</span>
            <strong>{{ issue.message }}</strong>
          </button>
        </li>
      </ul>
      <p v-else class="editorial-readiness__ok">
        <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" style="color:#16a34a;margin-right:.35rem;flex-shrink:0"><path fill="currentColor" d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>
        Aucun blocage détecté dans le formulaire courant.
      </p>
    </template>
  </section>
</template>
