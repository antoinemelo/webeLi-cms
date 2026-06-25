<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';

type Role = { id:number; role_key:string; name:string; description?:string };
type SiteOption = { id:number; site_key:string; name:string; default_language_code?:string; is_active?:boolean; host?:string; base_path?:string };
type LoginMode = 'password' | 'email_code' | 'totp';
type User = { id:number; email:string; first_name:string; last_name:string; name:string; locale:string; is_active:boolean; disabled_at?:string|null; disabled_reason?:string|null; active_session_count:number; login_mode?:LoginMode; email_code_enabled?:boolean; totp_enabled?:boolean; roles?:number[]; role_ids?:number[]; site_roles?:Array<{site_id:number;role_id:number}>; sessions?:Array<Record<string, unknown>>; last_login_at?:string|null; created_at?:string; updated_at?:string };
type UsersPayload = { users: User[] };
type RolesPayload = { roles: Role[] };
type SitesPayload = { sites: SiteOption[] };
type UserPayload = { user: User; message?: string };
type TotpSetup = { user_id:number; email:string; secret:string; manual_entry_key:string; otpauth_uri:string; qr_payload:string; algorithm:string; digits:number; period:number };

const context = useAdminContextStore();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const message = ref('');
const confirmDelete = ref(false);
const users = ref<User[]>([]);
const roles = ref<Role[]>([]);
const sites = ref<SiteOption[]>([]);
const selected = ref<User | null>(null);
const q = ref('');
const status = ref('');
const showInactive = ref(true);
const form = ref({ email:'', first_name:'', last_name:'', locale:'fr-CH', password:'', is_active:true, disabled_reason:'', role_ids: [] as number[], site_roles: [] as Array<{site_id:number; role_id:number}> });
const siteRoleDraft = ref({ site_id: 1, role_id: 0 });
const showInitialPassword = ref(false);
const showAdvancedConfiguration = ref(false);
const initialPasswordInput = ref<HTMLInputElement | null>(null);
const loginMode = ref<LoginMode>('password');
const pendingLoginMode = ref<LoginMode>('password');
const totpSetup = ref<TotpSetup | null>(null);
const totpConfirmCode = ref('');
const recoveryCodes = ref<string[]>([]);

const activeCount = computed(() => users.value.filter((u) => u.is_active).length);
const inactiveCount = computed(() => users.value.filter((u) => !u.is_active).length);
const currentSiteId = computed(() => Number(context.siteId || 1));
const currentSiteName = computed(() => context.context?.site.name || `Site #${currentSiteId.value}`);
const isSiteScopedAdmin = computed(() => context.isSiteScopedAdmin);
const siteOptions = computed<SiteOption[]>(() => {
  const byId = new Map<number, SiteOption>();
  for (const site of context.availableSites || []) byId.set(site.id, site);
  if (context.context?.site) byId.set(context.context.site.id, context.context.site);
  for (const site of sites.value) byId.set(site.id, site);
  if (isSiteScopedAdmin.value) {
    const current = byId.get(currentSiteId.value);
    return current ? [current] : [];
  }
  return [...byId.values()].sort((a, b) => {
    const activeSort = Number(b.is_active !== false) - Number(a.is_active !== false);
    if (activeSort !== 0) return activeSort;
    return `${a.name} ${a.id}`.localeCompare(`${b.name} ${b.id}`, 'fr', { sensitivity: 'base' });
  });
});
const selectedRoleNames = computed(() => form.value.role_ids.map((id) => roleName(id)).filter(Boolean));
const hasAssignedRole = computed(() => form.value.role_ids.length > 0 || form.value.site_roles.some((row) => Number(row.site_id) > 0 && Number(row.role_id) > 0));
const roleRequiredMessage = computed(() => !selected.value && !hasAssignedRole.value ? 'Attribuez au moins un rôle global ou un accès par site avant de créer le compte.' : '');
const roleCounts = computed(() => roles.value.map((role) => ({ ...role, count: users.value.filter((u) => (u.roles || u.role_ids || []).includes(role.id) || (u.site_roles || []).some((sr) => sr.role_id === role.id)).length })).filter((r) => r.count > 0));
const visibleUsers = computed(() => users.value.filter((u) => showInactive.value || u.is_active));
const canManageLoginMode = computed(() => context.can('users.email_2fa.manage') || context.can('users.manage'));

function listQuery(extra: Record<string, string|number|boolean|undefined|null> = {}) { return isSiteScopedAdmin.value ? { site_id: currentSiteId.value, ...extra } : { ...extra }; }
function scopedPath(path: string): string { return isSiteScopedAdmin.value ? `${path}?site_id=${currentSiteId.value}` : path; }
function roleName(id: number): string { return roles.value.find((role) => role.id === id)?.name || ''; }
function roleKey(id: number): string { return roles.value.find((role) => role.id === id)?.role_key || `role:${id}`; }
function siteOptionLabel(site: SiteOption): string {
  const bits = [site.name || `Site #${site.id}`, `#${site.id}`];
  if (site.site_key) bits.push(site.site_key);
  if (site.host) bits.push(site.base_path ? `${site.host}${site.base_path}` : site.host);
  if (site.is_active === false) bits.push('inactif');
  return bits.join(' · ');
}
function siteName(id: number): string {
  const site = siteOptions.value.find((item) => item.id === id);
  if (!site) return `Site #${id}`;
  const status = site.is_active === false ? ' · inactif' : '';
  return `${site.name || `Site #${id}`} (#${id})${status}`;
}
function initials(user: Pick<User, 'name'|'email'>): string { return (user.name || user.email || 'A').split(/[\s.@_-]+/).filter(Boolean).slice(0,2).map((p) => p[0]?.toUpperCase()).join('') || 'A'; }
function formatDate(value?: string|null): string { return value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString('fr-CH') : 'Jamais'; }
function statusLabel(user: User): string { return user.is_active ? 'Actif' : 'Inactif'; }

async function toggleInitialPasswordVisibility(): Promise<void> {
  showInitialPassword.value = !showInitialPassword.value;
  await nextTick();
  if (initialPasswordInput.value) {
    initialPasswordInput.value.type = showInitialPassword.value ? 'text' : 'password';
    initialPasswordInput.value.focus({ preventScroll: true });
  }
}

async function load(): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const [u, r, siteRes] = await Promise.all([
      adminApi.get<UsersPayload>('/iam/users', listQuery({ q: q.value, status: status.value, limit: 100 })),
      adminApi.get<RolesPayload>('/iam/roles', listQuery({ mode: 'assignment' })),
      adminApi.get<SitesPayload>('/iam/sites', listQuery())
    ]);
    users.value = u.data.users;
    roles.value = r.data.roles;
    sites.value = isSiteScopedAdmin.value ? siteRes.data.sites.filter((site) => site.id === currentSiteId.value) : siteRes.data.sites;
  } catch (err) { error.value = apiErrorMessage(err, 'Utilisateurs indisponibles.'); }
  finally { loading.value = false; }
}

function newUser(): void {
  confirmDelete.value = false;
  selected.value = null;
  form.value = { email:'', first_name:'', last_name:'', locale:'fr-CH', password:'', is_active:true, disabled_reason:'', role_ids: [], site_roles: [] };
  siteRoleDraft.value = { site_id: currentSiteId.value, role_id: roles.value[0]?.id || 0 };
  showInitialPassword.value = false;
  showAdvancedConfiguration.value = false;
  loginMode.value = 'password';
  pendingLoginMode.value = 'password';
  totpSetup.value = null;
  totpConfirmCode.value = '';
  recoveryCodes.value = [];
}

async function edit(id: number, notify = true): Promise<void> {
  confirmDelete.value = false;
  error.value = ''; if (notify) message.value = '';
  try {
    const res = await adminApi.get<UserPayload>(`/iam/users/${id}`, listQuery());
    selected.value = res.data.user;
    loginMode.value = selected.value.login_mode || (selected.value.totp_enabled ? 'totp' : 'password');
    pendingLoginMode.value = loginMode.value;
    totpSetup.value = null;
    totpConfirmCode.value = '';
    recoveryCodes.value = [];
    form.value = {
      email: selected.value.email,
      first_name: selected.value.first_name,
      last_name: selected.value.last_name,
      locale: selected.value.locale || 'fr-CH',
      password:'',
      is_active: selected.value.is_active,
      disabled_reason: selected.value.disabled_reason || '',
      role_ids: selected.value.roles || selected.value.role_ids || [],
      site_roles: selected.value.site_roles || []
    };
    siteRoleDraft.value = { site_id: currentSiteId.value, role_id: roles.value[0]?.id || 0 };
    showInitialPassword.value = false;
    showAdvancedConfiguration.value = false;
  } catch (err) { error.value = apiErrorMessage(err, 'Utilisateur introuvable.'); }
}

function loginModeLabel(mode: LoginMode): string {
  if (mode === 'email_code') return 'Code par e-mail';
  if (mode === 'totp') return 'Mot de passe + application TOTP';
  return 'Mot de passe classique';
}

async function applyLoginMode(): Promise<void> {
  if (!selected.value || !canManageLoginMode.value) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    if (pendingLoginMode.value === 'totp') {
      const res = await adminApi.post<{totp: TotpSetup; message?: string}>(scopedPath(`/iam/users/${selected.value.id}/login-mode/totp/prepare`), { issuer: 'DEC CMS' });
      totpSetup.value = res.data.totp;
      totpConfirmCode.value = '';
      recoveryCodes.value = [];
      message.value = res.data.message || 'Secret TOTP préparé. Confirmez un code pour activer.';
      return;
    }
    const res = await adminApi.patch<{user: User; login_mode: LoginMode; message?: string}>(scopedPath(`/iam/users/${selected.value.id}/login-mode`), { login_mode: pendingLoginMode.value });
    selected.value = res.data.user;
    loginMode.value = res.data.login_mode;
    pendingLoginMode.value = res.data.login_mode;
    totpSetup.value = null;
    recoveryCodes.value = [];
    message.value = res.data.message || 'Mode de connexion mis à jour.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err, 'Changement du mode de connexion impossible.'); }
  finally { saving.value = false; }
}

async function confirmTotp(): Promise<void> {
  if (!selected.value || !totpSetup.value) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const res = await adminApi.post<{user: User; login_mode: LoginMode; recovery_codes: string[]; message?: string}>(scopedPath(`/iam/users/${selected.value.id}/login-mode/totp/confirm`), { secret: totpSetup.value.secret, code: totpConfirmCode.value });
    selected.value = res.data.user;
    loginMode.value = 'totp';
    pendingLoginMode.value = 'totp';
    totpSetup.value = null;
    totpConfirmCode.value = '';
    recoveryCodes.value = res.data.recovery_codes || [];
    message.value = res.data.message || 'TOTP activé.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err, 'Confirmation TOTP impossible.'); }
  finally { saving.value = false; }
}

async function disableTotp(): Promise<void> {
  if (!selected.value) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const res = await adminApi.post<{user: User; login_mode: LoginMode; message?: string}>(scopedPath(`/iam/users/${selected.value.id}/login-mode/totp/disable`), {});
    selected.value = res.data.user;
    loginMode.value = 'password';
    pendingLoginMode.value = 'password';
    totpSetup.value = null;
    recoveryCodes.value = [];
    message.value = res.data.message || 'TOTP désactivé.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err, 'Désactivation TOTP impossible.'); }
  finally { saving.value = false; }
}

async function regenerateRecoveryCodes(): Promise<void> {
  if (!selected.value || loginMode.value !== 'totp') return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const res = await adminApi.post<{user: User; recovery_codes: string[]; message?: string}>(scopedPath(`/iam/users/${selected.value.id}/totp/recovery-codes`), {});
    selected.value = res.data.user;
    recoveryCodes.value = res.data.recovery_codes || [];
    message.value = res.data.message || 'Codes de récupération régénérés.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err, 'Régénération des codes impossible.'); }
  finally { saving.value = false; }
}

async function save(): Promise<void> {
  saving.value = true; error.value = ''; message.value = '';
  if (!selected.value && !hasAssignedRole.value) {
    error.value = roleRequiredMessage.value;
    saving.value = false;
    return;
  }
  const payload: Record<string, unknown> = { ...form.value };
  if (!payload.password) delete payload.password;
  try {
    const res = selected.value ? await adminApi.patch<UserPayload>(scopedPath(`/iam/users/${selected.value.id}`), payload) : await adminApi.post<UserPayload>(scopedPath('/iam/users'), payload);
    selected.value = res.data.user;
    message.value = res.data.message || 'Utilisateur enregistré.';
    await load();
    await edit(res.data.user.id, false);
  } catch (err) { error.value = apiErrorMessage(err, 'Enregistrement impossible.'); }
  finally { saving.value = false; }
}

async function setActive(active: boolean): Promise<void> {
  if (!selected.value) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const path = scopedPath(active ? `/iam/users/${selected.value.id}/activate` : `/iam/users/${selected.value.id}/deactivate`);
    const res = await adminApi.post<UserPayload>(path, active ? {} : { reason: form.value.disabled_reason });
    message.value = res.data.message || (active ? 'Utilisateur activé.' : 'Utilisateur désactivé.');
    await load();
    await edit(selected.value.id, false);
  } catch (err) { error.value = apiErrorMessage(err, active ? 'Activation impossible.' : 'Désactivation impossible.'); }
  finally { saving.value = false; }
}

async function resetPassword(): Promise<void> {
  if (!selected.value) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const res = await adminApi.post<{temporary_password:string}>(scopedPath(`/iam/users/${selected.value.id}/reset-password`), {});
    message.value = `Mot de passe temporaire : ${res.data.temporary_password}`;
  } catch (err) { error.value = apiErrorMessage(err, 'Réinitialisation impossible.'); }
  finally { saving.value = false; }
}

async function destroyUser(): Promise<void> {
  if (!selected.value || selected.value.is_active) return;
  if (!confirmDelete.value) {
    confirmDelete.value = true;
    message.value = 'Confirmez la suppression définitive du compte archivé.';
    return;
  }
  const deletedId = selected.value.id;
  saving.value = true; error.value = ''; message.value = '';
  try {
    await adminApi.delete<{ message?: string }>(scopedPath(`/iam/users/${deletedId}`));
    message.value = 'Utilisateur supprimé définitivement.';
    newUser();
    await load();
  } catch (err) { error.value = apiErrorMessage(err, 'Suppression définitive impossible.'); }
  finally { saving.value = false; }
}

function addSiteRole(): void {
  if (siteRoleDraft.value.site_id <= 0 || siteRoleDraft.value.role_id <= 0) return;
  const exists = form.value.site_roles.some((row) => row.site_id === siteRoleDraft.value.site_id && row.role_id === siteRoleDraft.value.role_id);
  if (!exists) form.value.site_roles.push({ ...siteRoleDraft.value });
}
function removeSiteRole(index: number): void { form.value.site_roles.splice(index, 1); }

onMounted(() => { newUser(); load(); });
</script>

<template>
  <PageHeader title="Utilisateurs et profils" intro="Une gestion IAM lisible : recherche, statuts, rôles globaux, accès par site, sessions et réinitialisation contrôlée.">
    <template #actions>
      <RouterLink v-if="!isSiteScopedAdmin" class="btn ghost" to="/iam/roles">Rôles / permissions</RouterLink>
    </template>
  </PageHeader>
  <ApiFeedback :error="error" :message="message" />

  <section class="iam-dashboard-strip">
    <article class="iam-stat card"><span>Actifs</span><strong>{{ activeCount }}</strong></article>
    <article class="iam-stat card"><span>Inactifs</span><strong>{{ inactiveCount }}</strong></article>
    <article class="iam-stat card"><span>Site courant</span><strong>{{ currentSiteName }}</strong></article>
    <article class="iam-stat card"><span>Résultats</span><strong>{{ visibleUsers.length }}</strong></article>
  </section>

  <section class="iam-layout">
    <article class="card iam-list-panel">
      <div class="iam-toolbar">
        <input v-model="q" class="input" type="search" placeholder="email, nom, prénom…" @keyup.enter="load">
        <select v-model="status" class="select">
          <option value="">Tous les statuts</option>
          <option value="active">Actifs</option>
          <option value="inactive">Inactifs</option>
        </select>
        <button class="btn" :disabled="loading" @click="load">Filtrer</button>
      </div>
      <div class="token-row token-row--wrap iam-list-actions">
        <button class="btn primary" type="button" @click="newUser">Nouvel utilisateur</button>
        <span v-for="role in roleCounts" :key="role.id" class="token">{{ role.name }} {{ role.count }}</span>
      </div>
      <label class="checkbox-inline iam-small-toggle"><input v-model="showInactive" type="checkbox"> Afficher les comptes inactifs</label>

      <div v-if="visibleUsers.length === 0" class="empty-state"><strong>Aucun utilisateur</strong><p>Essayez un autre filtre ou créez un compte.</p></div>
      <div v-else class="iam-user-list">
        <button v-for="user in visibleUsers" :key="user.id" type="button" class="iam-user-row" :class="{ active: selected?.id === user.id }" @click="edit(user.id)">
          <span class="avatar-pill">{{ initials(user) }}</span>
          <span class="iam-user-main">
            <strong>{{ user.name || user.email }}</strong>
            <small>{{ user.email }}</small>
            <span class="iam-user-tags"><span v-for="rid in (user.roles || user.role_ids || [])" :key="rid" class="token">{{ roleKey(rid) }}</span></span>
          </span>
          <span class="iam-user-side">
            <span class="pill" :class="user.is_active ? 'pill--success' : 'pill--danger'">{{ statusLabel(user) }}</span>
            <small>{{ user.active_session_count }} session(s)</small>
          </span>
        </button>
      </div>
    </article>

    <aside class="card iam-editor-panel">
      <div class="panel__header">
        <div>
          <p class="eyebrow">{{ selected ? 'Profil IAM' : 'Création' }}</p>
          <h2>{{ selected ? 'Modifier l’utilisateur' : 'Créer un utilisateur' }}</h2>
          <p class="muted">{{ selected ? `Dernière connexion : ${formatDate(selected.last_login_at)}` : 'Le compte est utilisable dès sa création.' }}</p>
        </div>
      </div>

      <form class="form-stack" @submit.prevent="save">
        <div class="field-grid field-grid--2">
          <label class="field"><span>Prénom</span><input v-model="form.first_name" class="input"></label>
          <label class="field"><span>Nom</span><input v-model="form.last_name" class="input"></label>
        </div>
        <label class="field"><span>Email</span><input v-model="form.email" type="email" class="input" required></label>
        <label v-if="!selected" class="field">
            <span>Mot de passe initial</span>
            <span class="password-field">
              <input v-if="showInitialPassword" ref="initialPasswordInput" key="initial-password-visible" v-model="form.password" type="text" class="input" required minlength="12" placeholder="Minimum 12 caractères" autocomplete="new-password">
              <input v-else ref="initialPasswordInput" key="initial-password-hidden" v-model="form.password" type="password" class="input" required minlength="12" placeholder="Minimum 12 caractères" autocomplete="new-password">
              <button class="password-field__toggle" type="button" :aria-pressed="showInitialPassword ? 'true' : 'false'" :aria-label="showInitialPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @pointerdown.prevent @click.prevent.stop="toggleInitialPasswordVisibility">
                <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                  <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z" />
                  <path d="M8 5.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5m0 1a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3" />
                </svg>
              </button>
            </span>
          </label>
        <details class="iam-advanced-config" :open="showAdvancedConfiguration" @toggle="showAdvancedConfiguration = ($event.target as HTMLDetailsElement).open">
          <summary>
            <svg class="iam-advanced-config__chevron" aria-hidden="true" viewBox="0 0 16 16" focusable="false">
              <path fill="currentColor" fill-rule="evenodd" d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
            <span>Configuration avancée</span>
          </summary>
          <label class="field"><span>Locale</span><input v-model="form.locale" class="input" placeholder="fr-CH"></label>

          <section v-if="selected" class="iam-subpanel">
            <div class="split-head">
              <div>
                <h3>Mode de connexion</h3>
                <p class="muted">Mode actuel : {{ loginModeLabel(loginMode) }}</p>
              </div>
              <span class="badge">{{ loginMode }}</span>
            </div>
            <div class="role-grid role-grid--compact">
              <label><input v-model="pendingLoginMode" type="radio" value="password" :disabled="!canManageLoginMode"> <span>Mot de passe classique</span><small>Connexion avec le mot de passe du compte.</small></label>
              <label><input v-model="pendingLoginMode" type="radio" value="email_code" :disabled="!canManageLoginMode"> <span>Code par e-mail</span><small>Code temporaire envoyé par e-mail. Ce n’est pas un TOTP.</small></label>
              <label><input v-model="pendingLoginMode" type="radio" value="totp" :disabled="!canManageLoginMode"> <span>Mot de passe + application TOTP</span><small>Application d’authentification standard, URI otpauth.</small></label>
            </div>
            <div class="form-actions">
              <button type="button" class="btn ghost" :disabled="saving || !canManageLoginMode || pendingLoginMode === loginMode" @click="applyLoginMode">{{ pendingLoginMode === 'totp' ? 'Préparer TOTP' : 'Appliquer le mode' }}</button>
              <button v-if="loginMode === 'totp'" type="button" class="btn danger" :disabled="saving || !canManageLoginMode" @click="disableTotp">Désactiver TOTP</button>
              <button v-if="loginMode === 'totp'" type="button" class="btn ghost" :disabled="saving || !canManageLoginMode" @click="regenerateRecoveryCodes">Régénérer les codes de récupération</button>
            </div>
            <div v-if="totpSetup" class="iam-subpanel">
              <div class="split-head"><div><h3>Activation TOTP</h3><p class="muted">Scannez le payload otpauth avec une application compatible ou saisissez le secret manuel.</p></div></div>
              <label class="field"><span>Secret manuel</span><input class="input" :value="totpSetup.manual_entry_key" readonly></label>
              <label class="field"><span>URI otpauth / payload QR</span><textarea class="input" rows="3" :value="totpSetup.otpauth_uri" readonly></textarea></label>
              <p class="muted">Paramètres : {{ totpSetup.digits }} chiffres, {{ totpSetup.period }} secondes, {{ totpSetup.algorithm }}.</p>
              <label class="field"><span>Code de confirmation</span><input v-model="totpConfirmCode" class="input" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="123456"></label>
              <div class="form-actions">
                <button type="button" class="btn primary" :disabled="saving || totpConfirmCode.length !== 6" @click="confirmTotp">Confirmer et activer TOTP</button>
              </div>
            </div>
            <div v-if="recoveryCodes.length" class="iam-subpanel">
              <div class="split-head"><div><h3>Codes de récupération</h3><p class="muted">Copiez ces codes maintenant. Ils ne seront plus affichés et chacun n’est utilisable qu’une seule fois.</p></div></div>
              <textarea class="input" rows="6" :value="recoveryCodes.join('\n')" readonly></textarea>
            </div>
          </section>

          <section v-if="!isSiteScopedAdmin" class="iam-subpanel">
            <div class="split-head"><div><h3>Rôles globaux <InfoHint text="À réserver aux comptes d’administration transversale." placement="end" /></h3></div><span class="badge">{{ selectedRoleNames.length }}</span></div>
            <div class="role-grid role-grid--compact">
              <label v-for="role in roles" :key="role.id"><input v-model="form.role_ids" type="checkbox" :value="role.id"> <span>{{ role.name }}</span><small>{{ role.role_key }}</small></label>
            </div>
          </section>

          <section class="iam-subpanel">
            <div class="split-head"><div><h3>Accès par site <InfoHint text="Attribuez un rôle à un site précis sans donner un accès global." placement="end" /></h3></div></div>
            <div class="iam-site-role-builder">
              <input v-if="isSiteScopedAdmin" class="input" :value="siteName(currentSiteId)" readonly aria-label="Site courant">
              <select v-else v-model.number="siteRoleDraft.site_id" class="select" aria-label="Site">
                <option :value="0">Site…</option>
                <option v-for="site in siteOptions" :key="site.id" :value="site.id">{{ siteOptionLabel(site) }}</option>
              </select>
              <select v-model.number="siteRoleDraft.role_id" class="select"><option :value="0">Rôle…</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select>
              <button type="button" class="btn ghost" @click="addSiteRole">Ajouter</button>
            </div>
            <div v-if="form.site_roles.length" class="token-row token-row--wrap">
              <span v-for="(sr,index) in form.site_roles" :key="`${sr.site_id}-${sr.role_id}-${index}`" class="token token--closable">{{ siteName(sr.site_id) }} · {{ roleName(sr.role_id) || `rôle #${sr.role_id}` }} <button type="button" @click="removeSiteRole(index)">×</button></span>
            </div>
            <p v-else class="muted">Aucun rôle limité par site.</p>
            <p v-if="roleRequiredMessage" class="alert alert-warning iam-role-required" role="alert">{{ roleRequiredMessage }}</p>
          </section>
        </details>
        <p v-if="roleRequiredMessage && !showAdvancedConfiguration" class="alert alert-warning iam-role-required" role="alert">Ouvrez la configuration avancée pour attribuer au moins un rôle global ou un accès par site avant de créer le compte.</p>

        <label class="checkline iam-active-toggle"><input v-model="form.is_active" type="checkbox"> Compte actif</label>
        <label v-if="!form.is_active" class="field"><span>Motif de désactivation</span><input v-model="form.disabled_reason" class="input" placeholder="Optionnel, journalisé côté audit"></label>

        <div class="form-actions">
          <button class="btn primary" :disabled="saving || Boolean(roleRequiredMessage)">Enregistrer</button>
          <button v-if="selected && selected.is_active" type="button" class="btn ghost" :disabled="saving" @click="setActive(false)">Désactiver</button>
          <button v-if="selected && !selected.is_active" type="button" class="btn ghost" :disabled="saving" @click="setActive(true)">Réactiver</button>
          <button v-if="selected" type="button" class="btn" :disabled="saving" @click="resetPassword">Réinitialiser le mot de passe</button>
          <button v-if="selected && !selected.is_active" type="button" class="btn danger" :disabled="saving" @click="destroyUser">{{ confirmDelete ? 'Confirmer la suppression' : 'Supprimer définitivement' }}</button>
        </div>
      </form>
    </aside>
  </section>
</template>
