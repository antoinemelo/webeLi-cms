<script setup lang="ts">
import { onMounted, ref } from 'vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import QuickCreateDrawer from './QuickCreateDrawer.vue';

type MessageForm = {
  channel: string;
  provider_key: string;
  recipient_value?: string;
  subject: string;
  body_text: string;
  confirm_external_test?: boolean;
};
type ChannelRow = { channel: string; has_channel?: boolean; consent_status?: string };
type Provider = Record<string, unknown> & { key?: string; provider_key?: string; channel?: string; enabled?: boolean };
type MessagePreview = Record<string, unknown> & { can_send?: boolean; reason?: string | null; recipient_value?: string | null };
type MessageRow = Record<string, unknown> & { id: number; channel?: string; subject?: string | null; body_text?: string | null; recipient_value?: string | null; status?: string | null };

defineProps<{
  form: MessageForm;
  relationName: string;
  channels: ChannelRow[];
  providers: Provider[];
  preview: MessagePreview | null;
  messages: MessageRow[];
  canSend: boolean;
  busy?: string;
  previewNotice: string;
}>();

const emit = defineEmits<{
  save: [];
  openConsent: [];
}>();

const bodyField = ref<HTMLTextAreaElement | null>(null);

function providerKey(provider: Provider): string {
  return String(provider.provider_key || provider.key || '');
}

function providerLabel(provider: Provider): string {
  const key = providerKey(provider) || 'log_only';
  const state = provider.enabled === false ? 'désactivé' : 'actif';
  return `${key} · ${state}`;
}

function channelLabel(row: ChannelRow): string {
  return `${row.channel} · ${row.has_channel ? (row.consent_status || 'unknown') : 'coordonnée absente'}`;
}

onMounted(() => bodyField.value?.focus());
</script>

<template>
  <QuickCreateDrawer title="Nouveau message" intro="Choisissez le canal, vérifiez le consentement et envoyez via le provider configuré.">
  <form class="business-form" @submit.prevent="emit('save')">
    <label>Canal
      <select v-model="form.channel">
        <option v-for="row in channels" :key="row.channel" :value="row.channel" :disabled="!row.has_channel">{{ channelLabel(row) }}</option>
      </select>
    </label>
    <label>Provider
      <select v-model="form.provider_key">
        <option v-for="provider in providers" :key="`${providerKey(provider)}-${provider.channel}`" :value="providerKey(provider)" :disabled="provider.enabled === false">{{ providerLabel(provider) }}</option>
      </select>
    </label>
    <label class="business-span">Relation<input :value="relationName" type="text" disabled></label>
    <p class="business-empty business-span">{{ previewNotice }}</p>
    <div v-if="preview && !preview.can_send" class="business-actions business-span">
      <button class="btn ghost small" type="button" @click="emit('openConsent')">Ouvrir consentements</button>
    </div>
    <label class="business-span">Sujet<input v-model="form.subject" type="text"></label>
    <label class="business-span">Message<textarea ref="bodyField" v-model="form.body_text" rows="5" required></textarea></label>
    <div class="business-actions business-span">
      <button class="btn primary small" type="submit" :disabled="busy === 'relation.message' || busy === 'relation.message.preview' || !canSend">Envoyer</button>
    </div>
    <div v-if="messages.length" class="business-list business-span">
      <article v-for="message in messages" :key="message.id" class="business-list-row">
        <strong>{{ message.channel }}</strong>
        <span>{{ message.subject || message.body_text || message.recipient_value }}</span>
        <StatusBadge :status="message.status || 'pending'" />
      </article>
    </div>
  </form>
  </QuickCreateDrawer>
</template>

<style scoped>
.business-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.business-form label {
  display: grid;
  gap: 0.25rem;
  font-weight: 600;
}

.business-form input,
.business-form select,
.business-form textarea {
  width: 100%;
  border: 1px solid var(--bs-border-color, #d6dce5);
  border-radius: 0.5rem;
  padding: 0.55rem 0.65rem;
}

.business-span {
  grid-column: 1 / -1;
}

.business-empty {
  margin: 0;
  color: #607084;
}

.business-actions,
.business-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.business-list {
  flex-direction: column;
}

.business-list-row {
  display: grid;
  grid-template-columns: minmax(5rem, 0.3fr) 1fr auto;
  gap: 0.75rem;
  align-items: center;
  border: 1px solid var(--bs-border-color, #d6dce5);
  border-radius: 0.5rem;
  padding: 0.65rem;
}

@media (max-width: 760px) {
  .business-form,
  .business-list-row {
    grid-template-columns: 1fr;
  }
}
</style>
