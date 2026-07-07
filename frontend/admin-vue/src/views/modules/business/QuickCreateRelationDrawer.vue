<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import QuickCreateDrawer from './QuickCreateDrawer.vue';

type RelationType = 'contact' | 'company';
type CompanyOption = { id: number; name: string };
type ContactForm = Record<string, string | number | null | undefined>;
type CompanyForm = Record<string, string | number | null | undefined>;

const props = defineProps<{
  draftType: RelationType;
  contactForm: ContactForm;
  companyForm: CompanyForm;
  companies: CompanyOption[];
  statuses: string[];
  canManage: boolean;
  busy?: string;
  inlineCompanyName: string;
  tags: string;
}>();

const emit = defineEmits<{
  'update:draftType': [value: RelationType];
  'update:inlineCompanyName': [value: string];
  'update:tags': [value: string];
  saveContact: [];
  saveCompany: [];
}>();

const firstField = ref<HTMLInputElement | null>(null);
const isContact = computed(() => props.draftType === 'contact');

function statusLabel(value: string): string {
  return value.replace(/_/g, ' ');
}

onMounted(() => firstField.value?.focus());
</script>

<template>
  <QuickCreateDrawer title="Création rapide relation" intro="Ajoutez une personne ou une organisation sans quitter la liste.">
    <nav class="business-relation-type-tabs" aria-label="Type de relation">
      <button type="button" :class="['editor-tab', { active: isContact }]" @click="emit('update:draftType', 'contact')">Individu</button>
      <button type="button" :class="['editor-tab', { active: !isContact }]" @click="emit('update:draftType', 'company')">Entreprise</button>
    </nav>

    <form v-if="isContact" class="business-form" @submit.prevent="emit('saveContact')">
      <label>Entreprise liée
        <select v-model="contactForm.company_id" :disabled="!canManage || Boolean(inlineCompanyName.trim())">
          <option value="">Individus</option>
          <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
        </select>
      </label>
      <label>Créer entreprise inline
        <input :value="inlineCompanyName" type="text" placeholder="Nom organisation" :disabled="!canManage" @input="emit('update:inlineCompanyName', ($event.target as HTMLInputElement).value)">
      </label>
      <label>Statut
        <select v-model="contactForm.status" :disabled="!canManage">
          <option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
        </select>
      </label>
      <label>Prénom<input ref="firstField" v-model="contactForm.first_name" type="text" :disabled="!canManage"></label>
      <label>Nom<input v-model="contactForm.last_name" type="text" :disabled="!canManage"></label>
      <label class="business-span">Nom affiché<input v-model="contactForm.display_name" type="text" :disabled="!canManage" required></label>
      <label>Email<input v-model="contactForm.email" type="email" :disabled="!canManage"></label>
      <label>Téléphone<input v-model="contactForm.phone" type="tel" :disabled="!canManage"></label>
      <label>WhatsApp / mobile<input v-model="contactForm.mobile" type="tel" :disabled="!canManage"></label>
      <label>Tags<input :value="tags" type="text" placeholder="client, VIP, relance" :disabled="!canManage" @input="emit('update:tags', ($event.target as HTMLInputElement).value)"></label>
      <label class="business-span">Notes<textarea v-model="contactForm.notes" rows="3" :disabled="!canManage"></textarea></label>
      <div class="business-actions business-span">
        <button class="btn primary small" type="submit" :disabled="!canManage || busy === 'contact.save'">Créer la personne</button>
      </div>
    </form>

    <form v-else class="business-form" @submit.prevent="emit('saveCompany')">
      <label class="business-span">Nom<input ref="firstField" v-model="companyForm.name" type="text" :disabled="!canManage" required></label>
      <label>Statut
        <select v-model="companyForm.status" :disabled="!canManage">
          <option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
        </select>
      </label>
      <label>Email<input v-model="companyForm.email" type="email" :disabled="!canManage"></label>
      <label>Téléphone<input v-model="companyForm.phone" type="tel" :disabled="!canManage"></label>
      <label>Site web<input v-model="companyForm.website_url" type="url" :disabled="!canManage"></label>
      <label>Tags<input :value="tags" type="text" placeholder="prospect, partenaire" :disabled="!canManage" @input="emit('update:tags', ($event.target as HTMLInputElement).value)"></label>
      <label class="business-span">Notes<textarea v-model="companyForm.notes" rows="4" :disabled="!canManage"></textarea></label>
      <div class="business-actions business-span">
        <button class="btn primary small" type="submit" :disabled="!canManage || busy === 'company.save'">Créer l'organisation</button>
      </div>
    </form>
  </QuickCreateDrawer>
</template>

<style scoped>
.quick-drawer {
  display: grid;
  gap: 1rem;
}

.business-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.business-relation-type-tabs {
  display: inline-flex;
  gap: 0.35rem;
  align-items: center;
  padding: 0.35rem;
  border: 1px solid #dbe3ef;
  border-radius: 1rem;
  background: rgba(248, 250, 252, .96);
  box-shadow: 0 10px 28px rgba(15, 23, 42, .04);
}

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

@media (max-width: 760px) {
  .business-form {
    grid-template-columns: 1fr;
  }
}
</style>
