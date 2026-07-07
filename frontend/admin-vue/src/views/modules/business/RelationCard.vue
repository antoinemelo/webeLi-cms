<script setup lang="ts">
import StatusBadge from '@/components/ui/StatusBadge.vue';
import RelationAvatar from './RelationAvatar.vue';
import RelationBadge from './RelationBadge.vue';

type RelationType = 'contact' | 'company';
type Relation = {
  type: RelationType;
  id: number;
  display_name: string;
  primary_email?: string | null;
  company?: { id?: number; name?: string; is_system_individuals?: boolean } | null;
  phone?: string | null;
  mobile?: string | null;
  status?: string;
  tags?: string[];
  email_consent_status?: string | null;
  memo_count?: number;
  shared_memo_count?: number;
  linked_contacts_count?: number;
  last_activity_at?: string | null;
  archived_at?: string | null;
};

defineProps<{
  relation: Relation;
  companyLabel: string;
  phoneLabel: string;
  consentLabel?: string;
}>();

defineEmits<{
  view: [];
  memo: [];
  message: [];
  email: [];
  whatsapp: [];
  consent: [];
  archive: [];
  restore: [];
  delete: [];
  openMemos: [];
}>();
</script>

<template>
  <article class="relation-card">
    <div class="relation-card-main">
      <RelationAvatar :name="relation.display_name" :type="relation.type" />
      <div>
        <button class="business-link relation-card-name" type="button" :aria-label="`Ouvrir la fiche ${relation.display_name}`" @click="$emit('view')">{{ relation.display_name }}</button>
        <p class="relation-card-subline">
          <span>{{ companyLabel }}</span>
          <span v-if="relation.primary_email">· {{ relation.primary_email }}</span>
        </p>
      </div>
      <StatusBadge :status="relation.status || 'other'" />
    </div>

    <div class="relation-card-meta">
      <span>{{ phoneLabel }}</span>
      <span v-if="relation.last_activity_at">Act. {{ relation.last_activity_at.slice(0, 10) }}</span>
    </div>

    <div class="relation-card-badges">
      <RelationBadge v-if="Number(relation.memo_count || 0) > 0" :label="`M ${relation.memo_count}`" :title="`${relation.memo_count} mémo(s)`" :aria-label="`Voir les mémos de ${relation.display_name}`" clickable @click="$emit('openMemos')" />
      <RelationBadge v-if="Number(relation.shared_memo_count || 0) > 0" :label="`P ${relation.shared_memo_count}`" :title="`${relation.shared_memo_count} mémo(s) partagé(s)`" :aria-label="`Voir les mémos partagés de ${relation.display_name}`" clickable @click="$emit('openMemos')" />
      <RelationBadge v-if="relation.type === 'company' && Number(relation.linked_contacts_count || 0) > 0" :label="`C ${relation.linked_contacts_count}`" :title="`${relation.linked_contacts_count} contact(s) lié(s)`" />
      <RelationBadge v-if="consentLabel" label="@" :title="consentLabel" :tone="relation.email_consent_status === 'opt_in' ? 'neutral' : 'warning'" />
      <RelationBadge v-for="tag in (relation.tags || []).slice(0, 3)" :key="tag" :label="tag" :title="`Tag ${tag}`" tone="accent" />
    </div>

    <div class="relation-card-actions">
      <button class="btn ghost small" type="button" :aria-label="`Voir ${relation.display_name}`" @click="$emit('view')">Voir</button>
      <template v-if="relation.archived_at">
        <button class="btn ghost small" type="button" :aria-label="`Rétablir ${relation.display_name}`" @click="$emit('restore')">Rétablir</button>
        <button class="btn ghost small" type="button" :aria-label="`Effacer définitivement ${relation.display_name}`" @click="$emit('delete')">Effacer</button>
      </template>
      <template v-else>
        <button class="btn ghost small" type="button" :aria-label="`Ajouter un mémo pour ${relation.display_name}`" @click="$emit('memo')">Mémo</button>
        <button class="btn ghost small" type="button" :disabled="relation.type !== 'contact'" :aria-label="`Préparer un message pour ${relation.display_name}`" @click="$emit('message')">Message</button>
        <button class="btn ghost small" type="button" :disabled="relation.type !== 'contact' || !relation.primary_email" :aria-label="`Préparer un email pour ${relation.display_name}`" @click="$emit('email')">Email</button>
        <button class="btn ghost small" type="button" :disabled="relation.type !== 'contact' || !relation.mobile" :aria-label="`Préparer un message WhatsApp pour ${relation.display_name}`" @click="$emit('whatsapp')">WhatsApp</button>
        <button class="btn ghost small" type="button" :disabled="relation.type !== 'contact'" :aria-label="`Gérer les consentements de ${relation.display_name}`" @click="$emit('consent')">Consentement</button>
        <button class="btn ghost small" type="button" :aria-label="`Archiver ${relation.display_name}`" @click="$emit('archive')">Archiver</button>
      </template>
    </div>
  </article>
</template>

<style scoped>
.relation-card {
  display: grid;
  gap: .65rem;
  border: 1px solid #eef2f6;
  border-radius: 8px;
  padding: .75rem;
}

.relation-card:focus-within {
  border-color: #93c5fd;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
}

.relation-card-main,
.relation-card-meta,
.relation-card-badges,
.relation-card-actions {
  display: flex;
  gap: .5rem;
  align-items: center;
  flex-wrap: wrap;
}

.relation-card-main {
  align-items: flex-start;
}

.relation-card-main > div:nth-child(2) {
  flex: 1 1 auto;
  min-width: 0;
}

.relation-card-name {
  font-weight: 800;
}

.relation-card-subline {
  display: flex;
  gap: .35rem;
  align-items: center;
  flex-wrap: wrap;
  margin: .1rem 0;
  color: #667085;
  font-size: .9rem;
  line-height: 1.4;
}

.relation-card-meta {
  color: #667085;
}

.relation-card-actions {
  align-items: stretch;
}

.relation-card-actions .btn {
  min-height: 2.25rem;
}

@media (max-width: 560px) {
  .relation-card {
    padding: .85rem;
  }

  .relation-card-main {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
  }

  .relation-card-main :deep(.badge) {
    grid-column: 1 / -1;
    justify-self: start;
  }

  .relation-card-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
