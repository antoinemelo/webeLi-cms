<script setup lang="ts">
import { computed, ref } from 'vue';
import type { EditorSchema, EntryShow } from '@/api/contracts';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import { fieldKey, fieldLabel, schemaTypeKey, schemaWorkflow } from '@/stores/contentTypes';

const props = defineProps<{
  entry: EntryShow | null;
  schema?: EditorSchema | null;
  publicUrl?: string;
  canPublish: boolean;
  canPreview?: boolean;
  saving?: boolean;
  publishing?: boolean;
  previewing?: boolean;
  archiving?: boolean;
  deleting?: boolean;
  hasUnsavedChanges?: boolean;
  hasRevisionableUnsavedChanges?: boolean;
  hasValidationErrors?: boolean;
  redirectSuggestions?: string[];
}>();
const emit = defineEmits<{ publish: []; saveRevision: []; archive: [{ redirect_to: string }]; destroy: [{ redirect_to: string }] }>();
const lifecycleOpen = ref(false);
const redirectTo = ref('');
const normalizedRedirectTo = computed(() => normalizeRedirectInput(redirectTo.value));
const currentPublicPath = computed(() => String(props.publicUrl || '').trim());
const hasPublishedRevision = computed(() => Number(props.entry?.published_revision?.id || 0) > 0);
const hasPublicRoute = computed(() => (props.entry?.routes || []).some((route) => String(route.full_path || '').trim() !== ''));
const canDestroyWithoutRedirect = computed(() => Boolean(props.entry) && !hasPublishedRevision.value && !hasPublicRoute.value);
const redirectPathError = computed(() => {
  const value = normalizedRedirectTo.value;
  if (!value) return 'Saisissez un chemin de redirection avant d’archiver une page publiée ou de supprimer une page déjà publiée.';
  if (!value.startsWith('/')) return 'Le chemin doit commencer par /. Exemple : /archives';
  if (/\s/.test(value)) return 'Le chemin ne doit pas contenir d’espace.';
  if (value.includes('//')) return 'Le chemin ne doit pas contenir de double barre oblique.';
  if (currentPublicPath.value && value === currentPublicPath.value) return 'Le chemin de redirection doit être différent de l’URL actuelle.';
  return '';
});
const canArchive = computed(() => Boolean(props.entry) && redirectPathError.value === '');
const canDestroy = computed(() => Boolean(props.entry) && (canDestroyWithoutRedirect.value || redirectPathError.value === ''));
const lifecycleHelp = computed(() => {
  if (canDestroyWithoutRedirect.value) return 'Cette page n’a jamais été publiée : elle peut être supprimée définitivement sans chemin de redirection.';
  return redirectPathError.value || 'Chemin valide : les actions d’archivage et de suppression sont disponibles.';
});
const normalizedSuggestions = computed(() => Array.from(new Set((props.redirectSuggestions || [])
  .map((path) => normalizeRedirectInput(String(path || '')))
  .filter((path) => path.startsWith('/') && path !== currentPublicPath.value)
)).slice(0, 8));
const archiveActionTitle = computed(() => redirectPathError.value || 'Action disponible.');
const destroyActionTitle = computed(() => canDestroyWithoutRedirect.value ? 'Suppression définitive disponible : cette page n’a jamais été publiée.' : (redirectPathError.value || 'Action disponible.'));
const workflow = computed(() => schemaWorkflow(props.schema || null));

const systemContextRows = computed(() => {
  const rows: Array<{ key: string; label: string; value: string }> = [];
  const schemaFields = props.schema?.system_context?.fields || [];
  const defaults = schemaFields.length ? schemaFields : [
    { field_key: 'language', label: 'Langue' },
    { field_key: 'status', label: 'Statut' },
    { field_key: 'revision_state', label: 'Révision' }
  ];
  for (const field of defaults) {
    const key = fieldKey(field as any);
    if (!key) continue;
    if (['site', 'site_id', 'language', 'status', 'workflow_state', 'revision_state'].includes(key)) continue;
    const value = contextValue(key);
    if (value === '') continue;
    rows.push({ key, label: fieldLabel(field as any), value });
  }
  const blueprintKey = schemaTypeKey(props.schema || null, '');
  if (blueprintKey) rows.push({ key: 'blueprint', label: 'Blueprint', value: blueprintKey });
  return rows;
});
function contextValue(key: string): string {
  if (key === 'site' || key === 'site_id') return String((props.entry as any)?.site?.name || (props.entry as any)?.entry?.site_name || (props.entry as any)?.entry?.site_id || '');
  if (key === 'language') return String(props.entry?.localization?.language_code || '');
  if (key === 'status' || key === 'workflow_state') return String(props.entry?.entry?.workflow_state || props.entry?.entry?.status || workflow.value?.default_state || 'draft');
  if (key === 'revision_state') {
    if (!props.entry) return '';
    if (!hasPublishedRevision.value) return 'Brouillon non publié';
    return modifiedSincePublication.value || props.hasRevisionableUnsavedChanges ? 'Révision modifiée' : 'Révision synchronisée';
  }
  if (key === 'content_type') return String(props.entry?.entry?.content_type_key || schemaTypeKey(props.schema || null, ''));
  return '';
}

const workflowEnabled = computed(() => workflow.value?.enabled !== false);
const workingRevisionLabel = computed(() => revisionDisplay(props.entry?.working_revision));
const publishedRevisionLabel = computed(() => revisionDisplay(props.entry?.published_revision));
const entryStatus = computed(() => String(props.entry?.entry.workflow_state || props.entry?.entry.status || workflow.value?.default_state || 'draft'));
const hasWorkingRevision = computed(() => Number(props.entry?.working_revision?.id || 0) > 0);
const publishedComparableRevision = computed(() => Number(props.entry?.published_revision?.revision_number || props.entry?.published_revision?.id || 0));
const workingComparableRevision = computed(() => Number(props.entry?.working_revision?.revision_number || props.entry?.working_revision?.id || 0));
const modifiedSincePublication = computed(() => {
  const working = workingComparableRevision.value;
  const published = publishedComparableRevision.value;
  return working > 0 && published > 0 && working !== published;
});
const hasPendingPublicationRevision = computed(() => Boolean(
  props.entry
  && hasWorkingRevision.value
  && hasPublishedRevision.value
  && modifiedSincePublication.value
  && !props.hasRevisionableUnsavedChanges
));
const showPublishRevisionButton = computed(() => {
  if (!props.entry) return false;
  if (!hasWorkingRevision.value) return false;
  if (!hasPublishedRevision.value) return true;
  return modifiedSincePublication.value;
});
const stateLabel = computed(() => {
  if (!props.entry) return 'Nouveau brouillon';
  if (!workflowEnabled.value) return 'Workflow désactivé';
  if (props.hasValidationErrors) return 'Non publiable';
  if (!hasPublishedRevision.value) return 'Brouillon';
  if (hasPendingPublicationRevision.value) return 'En attente de publication';
  if (modifiedSincePublication.value || props.hasRevisionableUnsavedChanges) return 'Modifié depuis publication';
  return entryStatus.value === 'published' ? 'Publié' : entryStatus.value;
});
const mustSaveInitialDraft = computed(() => !props.entry);
const mustSaveRevisionBeforePublish = computed(() => Boolean(props.entry && props.hasRevisionableUnsavedChanges));
const showSaveRevisionButton = computed(() => mustSaveInitialDraft.value || mustSaveRevisionBeforePublish.value);
const publishDisabledReason = computed(() => {
  if (!props.entry) return 'Enregistrez d’abord un brouillon.';
  if (!workflowEnabled.value) return 'Le workflow du blueprint désactive la publication.';
  if (props.hasRevisionableUnsavedChanges) return 'Enregistrez les modifications avant publication.';
  if (!props.canPublish && hasPendingPublicationRevision.value) return 'Un rôle autorisé doit publier cette révision.';
  if (!props.canPublish) return 'Permission de publication manquante.';
  if (props.hasValidationErrors) return 'Des erreurs de validation bloquent la publication.';
  if (!hasWorkingRevision.value && workflow.value?.publish_requires_revision_id !== false) return 'Aucune révision de travail publiable.';
  return '';
});
const canPublishNow = computed(() => publishDisabledReason.value === '' && !props.publishing);
const saveRevisionButtonLabel = computed(() => {
  if (props.saving) return 'Enregistrement…';
  return mustSaveInitialDraft.value ? 'Enregistrer le brouillon' : 'Enregistrer la révision';
});
function revisionDisplay(revision?: { id?: number; revision_number?: number } | null): string {
  const number = Number(revision?.revision_number || 0);
  if (number > 0) return `#${number}`;
  const id = Number(revision?.id || 0);
  return id > 0 ? `ID ${id}` : '—';
}
function normalizeRedirectInput(path: string): string {
  let value = String(path || '').replace(/ /g, ' ').trim();
  if (!value) return '';
  try {
    if (/^https?:\/\//i.test(value)) value = new URL(value).pathname || '/';
  } catch {
    return value;
  }
  if (!value.startsWith('/')) value = `/${value}`;
  value = value.replace(/\\/g, '/').replace(/\/+/g, '/');
  return value.replace(/\/+$/g, '') || '/';
}
function setRedirectSuggestion(path: string) { redirectTo.value = normalizeRedirectInput(path); }
function archive() { if (canArchive.value) emit('archive', { redirect_to: normalizedRedirectTo.value }); }
function destroy() { if (canDestroy.value) emit('destroy', { redirect_to: canDestroyWithoutRedirect.value ? '' : normalizedRedirectTo.value }); }
</script>
<template>
  <aside class="card publication-panel">
    <h2>Workflow</h2>
    <p><StatusBadge :status="entryStatus" /></p>
    <div class="publication-state" :class="{ 'publication-state--blocked': Boolean(publishDisabledReason) && entry !== null }">
      <strong>{{ stateLabel }}</strong>
      <span v-if="publishDisabledReason">{{ publishDisabledReason }}</span>
      <button v-if="showSaveRevisionButton" type="button" class="btn secondary publication-state__revision-button" :disabled="saving" @click="emit('saveRevision')">{{ saveRevisionButtonLabel }}</button>
    </div>
    <dl class="meta-list publication-panel__meta-list">
      <template v-for="row in systemContextRows" :key="row.key">
        <dt>{{ row.label }}</dt><dd>{{ row.value }}</dd>
      </template>
      <dt>Révision / publication</dt><dd>Révision {{ workingRevisionLabel }} / Publication {{ publishedRevisionLabel }}</dd>
      <dt>URL publique</dt><dd>{{ publicUrl || '—' }}</dd>
    </dl>
    <button v-if="showPublishRevisionButton" class="btn primary" style="width:100%" :disabled="!canPublishNow" :title="publishDisabledReason" @click="$emit('publish')">{{ publishing ? 'Publication…' : 'Publier la révision' }}</button>

    <hr class="my-3" />
    <section class="danger-zone" aria-labelledby="entry-lifecycle-title">
      <button type="button" class="btn btn-outline-danger danger-zone__toggle" :aria-expanded="lifecycleOpen" aria-controls="entry-lifecycle-content" @click="lifecycleOpen = !lifecycleOpen">
        <svg class="danger-zone__chevron" :class="{ 'danger-zone__chevron--open': lifecycleOpen }" aria-hidden="true" viewBox="0 0 16 16" focusable="false">
          <path fill="currentColor" fill-rule="evenodd" d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
        </svg>
        <strong id="entry-lifecycle-title">Archivage et suppression</strong>
        <InfoHint text="Ces actions restent exécutées et contrôlées côté backend. Indiquez un chemin interne de remplacement pour créer une redirection 301 lorsqu’une page publiée est retirée. Une page jamais publiée peut être supprimée sans redirection." placement="end" />
      </button>
      <div v-if="lifecycleOpen" id="entry-lifecycle-content" class="danger-zone__content">
        <label class="field" for="entry-lifecycle-redirect">
          <span>Chemin de redirection</span>
          <input id="entry-lifecycle-redirect" class="input" v-model="redirectTo" list="entry-lifecycle-redirect-suggestions" placeholder="/nouvelle-page" autocomplete="off" />
        </label>
        <datalist id="entry-lifecycle-redirect-suggestions">
          <option v-for="path in normalizedSuggestions" :key="path" :value="path" />
        </datalist>
        <p class="form-help" :class="{ 'form-help--error': Boolean(redirectPathError) && !canDestroyWithoutRedirect }">{{ lifecycleHelp }}</p>
        <div v-if="normalizedSuggestions.length" class="redirect-suggestions" aria-label="Suggestions de chemins">
          <button v-for="path in normalizedSuggestions" :key="path" type="button" class="chip" @click="setRedirectSuggestion(path)">{{ path }}</button>
        </div>
        <button class="btn" style="width:100%;margin-top:.5rem" :disabled="archiving || !canArchive" :title="archiveActionTitle" @click="archive">{{ archiving ? 'Archivage…' : 'Archiver' }}</button>
        <button class="btn danger" style="width:100%;margin-top:.5rem" :disabled="deleting || !canDestroy" :title="destroyActionTitle" @click="destroy">{{ deleting ? 'Suppression…' : 'Supprimer définitivement' }}</button>
      </div>
    </section>
  </aside>
</template>
