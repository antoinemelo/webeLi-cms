<script setup lang="ts">
import { onMounted, ref } from 'vue';
import QuickCreateDrawer from './QuickCreateDrawer.vue';

type Option = { id: number; name?: string; display_name?: string };
type MemoForm = Record<string, string | number | null | undefined>;

defineProps<{
  memoForm: MemoForm;
  companies: Option[];
  contacts: Option[];
  canManage: boolean;
  canShare: boolean;
  busy?: string;
  publicShare: boolean;
}>();

const emit = defineEmits<{
  'update:publicShare': [value: boolean];
  save: [];
}>();

const bodyField = ref<HTMLTextAreaElement | null>(null);

onMounted(() => bodyField.value?.focus());
</script>

<template>
  <QuickCreateDrawer title="Création rapide mémo" intro="Rédigez le mémo et choisissez sa visibilité avant enregistrement.">
  <form class="business-form" @submit.prevent="emit('save')">
    <label class="business-span">Titre optionnel<input v-model="memoForm.title" type="text" :disabled="!canManage" placeholder="Généré depuis le texte si vide"></label>
    <label>Entreprise
      <select v-model="memoForm.company_id" :disabled="!canManage">
        <option value="">—</option>
        <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
      </select>
    </label>
    <label>Contact
      <select v-model="memoForm.contact_id" :disabled="!canManage">
        <option value="">—</option>
        <option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.display_name }}</option>
      </select>
    </label>
    <label>Visibilité
      <select v-model="memoForm.visibility" :disabled="!canManage">
        <option value="internal">Interne</option>
        <option value="private">Privé</option>
        <option value="public_link">Lien public</option>
      </select>
    </label>
    <label class="business-span">Texte<textarea ref="bodyField" v-model="memoForm.body" rows="7" :disabled="!canManage" required></textarea></label>
    <label v-if="canShare" class="business-check business-span">
      <input :checked="publicShare" type="checkbox" :disabled="!canManage" @change="emit('update:publicShare', ($event.target as HTMLInputElement).checked)">
      Créer un lien public après enregistrement
    </label>
    <label v-if="canShare && publicShare" class="business-span">Expiration optionnelle
      <input v-model="memoForm.public_expires_at" type="text" placeholder="YYYY-MM-DD HH:MM:SS" :disabled="!canManage">
    </label>
    <div class="business-actions business-span">
      <button class="btn primary small" type="submit" :disabled="!canManage || busy === 'memo.save'">Créer le mémo</button>
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

.business-check {
  display: flex !important;
  align-items: center;
  gap: 0.5rem;
}

.business-check input {
  width: auto;
}

.business-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

@media (max-width: 760px) {
  .business-form {
    grid-template-columns: 1fr;
  }
}
</style>
