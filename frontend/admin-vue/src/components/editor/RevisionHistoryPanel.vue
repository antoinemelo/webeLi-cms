<script setup lang="ts">
import { computed } from 'vue';
import type { RevisionSummary } from '@/api/contracts';
import InfoHint from '@/components/ui/InfoHint.vue';

const props = defineProps<{
  revisions: RevisionSummary[];
  workingRevisionId?: number;
  publishedRevisionId?: number;
  loading?: boolean;
  pruning?: boolean;
}>();
const emit = defineEmits<{
  preview: [revisionId: number];
  restore: [revisionId: number];
  prune: [];
}>();

const sortedRevisions = computed(() => [...(props.revisions || [])].sort((a, b) => Number(b.revision_number || 0) - Number(a.revision_number || 0)));
const hasRestorableRevision = computed(() => sortedRevisions.value.some((revision) => canRestore(revision)));
const pruneDisabled = computed(() => Boolean(props.pruning || !props.publishedRevisionId || sortedRevisions.value.length < 2 || !hasRestorableRevision.value));

function statusLabel(status?: string): string {
  return ({ draft: 'Brouillon', published: 'Publiée', superseded: 'Remplacée' } as Record<string, string>)[String(status || '')] || 'Révision';
}
function formatDate(value?: string | null): string {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat('fr-CH', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function canRestore(revision: RevisionSummary): boolean {
  return Number(revision.id || 0) > 0 && Number(revision.id || 0) !== Number(props.workingRevisionId || 0);
}

function revisionNote(revision: RevisionSummary): string {
  const note = String(revision.change_notes || '').trim();
  if (note) return note;
  const summary = String((revision as any).summary || '').trim();
  if (summary && !/^Working draft$/i.test(summary) && !/^Initial draft$/i.test(summary)) return summary;
  const label = String((revision as any).revision_label || '').trim();
  if (label && !/^Working draft$/i.test(label) && !/^Initial draft$/i.test(label)) return label;
  return 'Aucune note détaillée enregistrée pour cette révision.';
}
</script>

<template>
  <section class="schema-section revision-history">
    <div class="revision-history__head">
      <div>
        <p class="eyebrow">Historique</p>
        <h2>Versions et restaurations <InfoHint text="Chaque sauvegarde crée une révision complète. Le nettoyage ne concerne que les révisions de cette langue, antérieures à la dernière version publiée, et conserve les versions encore actives." placement="end" /></h2>
      </div>
      <button type="button" class="btn btn-light" :disabled="pruneDisabled" @click="emit('prune')">
        {{ pruning ? 'Nettoyage…' : 'Effacer les anciennes révisions' }}
      </button>
    </div>

    <div v-if="loading" class="card muted">Chargement des révisions…</div>
    <div v-else-if="sortedRevisions.length === 0" class="empty-editor">Aucune révision enregistrée pour cette langue.</div>
    <ol v-else class="revision-list">
      <li v-for="revision in sortedRevisions" :key="revision.id" class="revision-row">
        <div class="revision-row__main">
          <div class="revision-row__title">
            <span class="revision-row__number">#{{ revision.revision_number || revision.id }}</span>
            <span>
              <strong>Révision {{ revision.revision_number || revision.id }}</strong>
              <small>{{ formatDate(revision.created_at || revision.updated_at) }}</small>
            </span>
          </div>
          <div class="revision-row__badges">
            <span :class="['badge', revision.workflow_status || 'draft']">{{ statusLabel(revision.workflow_status) }}</span>
            <span v-if="revision.id === workingRevisionId" class="badge draft">Travail</span>
            <span v-if="revision.id === publishedRevisionId" class="badge published">Dernière publiée</span>
          </div>
        </div>
        <p class="revision-row__notes">{{ revisionNote(revision) }}</p>
        <div class="revision-row__actions">
          <button type="button" class="btn btn-light btn-sm" :disabled="!revision.id" @click="emit('preview', revision.id)">Prévisualiser</button>
          <button type="button" class="btn btn-light btn-sm" :disabled="!canRestore(revision)" @click="emit('restore', revision.id)">Restaurer comme brouillon</button>
        </div>
      </li>
    </ol>
  </section>
</template>
