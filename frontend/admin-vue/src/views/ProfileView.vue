<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { adminApi, apiErrorMessage, AdminApiError } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import type { AdminProfilePayload, AdminProfile } from '@/api/contracts';

const context = useAdminContextStore();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const passwordSuccess = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
const passwordErrors = ref<Record<string, string[]>>({});
const profile = ref<AdminProfile | null>(null);
const form = reactive({
  email: '',
  first_name: '',
  last_name: '',
  locale: 'fr-CH'
});
const passwordForm = reactive({
  current_password: '',
  new_password: '',
  confirm_password: ''
});
const savingPassword = ref(false);

const initials = computed(() => {
  const source = `${form.first_name} ${form.last_name}`.trim() || form.email || context.context?.user.name || 'Admin';
  return source
    .split(/[\s.@_-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || 'A';
});

onMounted(loadProfile);

async function loadProfile() {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<AdminProfilePayload>('/profile');
    applyProfile(response.data.profile);
  } catch (err) {
    error.value = apiErrorMessage(err, 'Profil indisponible.');
  } finally {
    loading.value = false;
  }
}

function applyProfile(nextProfile: AdminProfile) {
  profile.value = nextProfile;
  form.email = nextProfile.email;
  form.first_name = nextProfile.first_name;
  form.last_name = nextProfile.last_name;
  form.locale = nextProfile.locale || 'fr-CH';
}

async function saveProfile() {
  saving.value = true;
  error.value = '';
  success.value = '';
  fieldErrors.value = {};
  try {
    const response = await adminApi.patch<{ profile: AdminProfile; message?: string }>('/profile', { ...form });
    applyProfile(response.data.profile);
    success.value = response.data.message || 'Profil mis à jour.';
    await context.load(context.siteId, context.languageCode);
  } catch (err) {
    if (err instanceof AdminApiError) {
      fieldErrors.value = err.fields;
    }
    error.value = apiErrorMessage(err, 'Le profil n’a pas pu être enregistré.');
  } finally {
    saving.value = false;
  }
}


function clearPasswordForm() {
  passwordForm.current_password = '';
  passwordForm.new_password = '';
  passwordForm.confirm_password = '';
}

async function changePassword() {
  savingPassword.value = true;
  error.value = '';
  passwordSuccess.value = '';
  passwordErrors.value = {};
  try {
    const response = await adminApi.patch<{ message?: string }>('/profile/password', { ...passwordForm });
    clearPasswordForm();
    passwordSuccess.value = response.data.message || 'Mot de passe modifié.';
  } catch (err) {
    if (err instanceof AdminApiError) {
      passwordErrors.value = err.fields;
    }
    error.value = apiErrorMessage(err, 'Le mot de passe n’a pas pu être modifié.');
  } finally {
    savingPassword.value = false;
  }
}

</script>

<template>
  <PageHeader title="Profil utilisateur" intro="Informations du compte connecté, modifiables sans quitter le backoffice." />

  <ApiFeedback :error="error" :success="success" />
  <div v-if="loading" class="card muted">Chargement du profil…</div>

  <div v-else class="profile-edit-layout">
    <aside class="card profile-summary">
      <div class="profile-avatar">{{ initials }}</div>
      <div>
        <p class="eyebrow">Compte actif</p>
        <h2>{{ form.first_name }} {{ form.last_name }}</h2>
        <p class="muted">{{ form.email }}</p>
      </div>
      <dl class="meta-list" v-if="profile">
        <dt>Identifiant</dt>
        <dd>#{{ profile.id }}</dd>
        <dt>Locale</dt>
        <dd>{{ profile.locale }}</dd>
        <dt>Dernière connexion</dt>
        <dd>{{ profile.last_login_at || '—' }}</dd>
      </dl>
    </aside>

    <div class="profile-form-stack">
    <form class="card profile-form" @submit.prevent="saveProfile">
      <div class="grid grid-2">
        <p class="field">
          <label for="first_name">Prénom <span class="required">*</span></label>
          <input id="first_name" v-model="form.first_name" class="input" type="text" maxlength="120" required />
          <small v-if="fieldErrors.first_name" class="alert error">{{ fieldErrors.first_name.join(' ') }}</small>
        </p>
        <p class="field">
          <label for="last_name">Nom</label>
          <input id="last_name" v-model="form.last_name" class="input" type="text" maxlength="120" />
          <small v-if="fieldErrors.last_name" class="alert error">{{ fieldErrors.last_name.join(' ') }}</small>
        </p>
      </div>

      <p class="field">
        <label for="email">Email <span class="required">*</span></label>
        <input id="email" v-model="form.email" class="input" type="email" maxlength="254" autocomplete="email" required />
        <small v-if="fieldErrors.email" class="alert error">{{ fieldErrors.email.join(' ') }}</small>
      </p>

      <p class="field">
        <label for="locale">Locale</label>
        <input id="locale" v-model="form.locale" class="input" type="text" maxlength="16" placeholder="fr-CH" pattern="^[a-z]{2}(-[A-Z]{2})?$" />
        <small class="muted">Format recommandé : fr-CH, fr-FR, en-GB, en-US.</small>
        <small v-if="fieldErrors.locale" class="alert error">{{ fieldErrors.locale.join(' ') }}</small>
      </p>

      <div class="form-actions">
        <button class="btn primary" type="submit" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer le profil' }}</button>
        <button class="btn ghost" type="button" :disabled="saving" @click="loadProfile">Réinitialiser</button>
      </div>
    </form>

    <form id="password" class="card profile-form" @submit.prevent="changePassword">
      <div>
        <p class="eyebrow">Sécurité</p>
        <h2>
          Modifier mon mot de passe
          <InfoHint text="Disponible pour chaque utilisateur connecté. Après modification, les autres sessions du compte sont révoquées." placement="end" />
        </h2>
      </div>

      <ApiFeedback :success="passwordSuccess" />

      <p class="field">
        <label for="current_password">Mot de passe actuel <span class="required">*</span></label>
        <input id="current_password" v-model="passwordForm.current_password" class="input" type="password" autocomplete="current-password" required />
        <small v-if="passwordErrors.current_password" class="alert error">{{ passwordErrors.current_password.join(' ') }}</small>
      </p>

      <div class="grid grid-2">
        <p class="field">
          <label for="new_password">Nouveau mot de passe <span class="required">*</span></label>
          <input id="new_password" v-model="passwordForm.new_password" class="input" type="password" minlength="12" maxlength="255" autocomplete="new-password" required />
          <small class="muted">Minimum 12 caractères, avec majuscule, minuscule et chiffre.</small>
          <small v-if="passwordErrors.new_password" class="alert error">{{ passwordErrors.new_password.join(' ') }}</small>
        </p>
        <p class="field">
          <label for="confirm_password">Confirmation <span class="required">*</span></label>
          <input id="confirm_password" v-model="passwordForm.confirm_password" class="input" type="password" minlength="12" maxlength="255" autocomplete="new-password" required />
          <small v-if="passwordErrors.confirm_password" class="alert error">{{ passwordErrors.confirm_password.join(' ') }}</small>
        </p>
      </div>

      <div class="form-actions">
        <button class="btn primary" type="submit" :disabled="savingPassword">{{ savingPassword ? 'Modification…' : 'Modifier le mot de passe' }}</button>
        <button class="btn ghost" type="button" :disabled="savingPassword" @click="clearPasswordForm">Effacer</button>
      </div>
    </form>
    </div>
  </div>
</template>
