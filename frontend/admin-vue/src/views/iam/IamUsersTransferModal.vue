<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';

type Role = { id:number; role_key:string; name:string };
type Site = { id:number; site_key:string; name:string };
type User = {
  id:number; email:string; first_name:string; last_name:string; locale:string; is_active:boolean;
  disabled_reason?:string|null; login_mode?:string; roles?:number[]; role_ids?:number[];
  site_roles?:Array<{site_id:number;role_id:number}>;
};
type TransferUser = {
  email?:unknown; first_name?:unknown; last_name?:unknown; locale?:unknown; is_active?:unknown;
  disabled_reason?:unknown; login_mode?:unknown; global_roles?:unknown; site_roles?:unknown;
  new_password?:unknown;
};
type TransferDocument = { format?:unknown; version?:unknown; users?:unknown; exported_at?:unknown; scope?:unknown };
type PreparedRow = {
  email:string; action:'create'|'update'; existingId?:number; payload:Record<string,unknown>;
  globalRoleKeys:string[]; siteRoleKeys:Array<{site_key:string;role_key:string}>;
  errors:string[]; warnings:string[];
};
type ImportPlan = { rows:PreparedRow[]; deactivate:User[]; errors:string[]; warnings:string[] };

const props = defineProps<{ roles:Role[]; sites:Site[]; isSiteScopedAdmin:boolean; currentSiteId:number }>();
const emit = defineEmits<{ close:[]; imported:[message:string] }>();
const context = useAdminContextStore();
const busy = ref(false);
const error = ref('');
const notice = ref('');
const importMode = ref<'merge'|'replace'>('merge');
const importFileName = ref('');
const document = ref<TransferDocument|null>(null);
const plan = ref<ImportPlan|null>(null);
const replaceConfirmed = ref(false);

const planErrorCount = computed(() => (plan.value?.errors.length || 0) + (plan.value?.rows.reduce((sum, row) => sum + row.errors.length, 0) || 0));
const createCount = computed(() => plan.value?.rows.filter((row) => row.action === 'create').length || 0);
const updateCount = computed(() => plan.value?.rows.filter((row) => row.action === 'update').length || 0);
const canExecute = computed(() => Boolean(plan.value) && planErrorCount.value === 0 && (!plan.value?.deactivate.length || replaceConfirmed.value) && !busy.value);

function normalizeEmail(value: unknown): string { return String(value || '').trim().toLocaleLowerCase(); }
function scopedQuery(extra: Record<string,string|number|boolean> = {}): Record<string,string|number|boolean> {
  return props.isSiteScopedAdmin ? { site_id: props.currentSiteId, ...extra } : extra;
}
function scopedPath(path: string): string { return props.isSiteScopedAdmin ? `${path}?site_id=${props.currentSiteId}` : path; }

async function fetchAllUsers(): Promise<User[]> {
  const rows: User[] = [];
  let offset = 0;
  while (true) {
    const response = await adminApi.get<{users:User[]}>('/iam/users', scopedQuery({ limit:100, offset }));
    const page = response.data.users || [];
    rows.push(...page);
    if (!response.meta.pagination?.has_more || page.length === 0) break;
    offset += page.length;
  }
  return rows;
}

function roleKeys(user: User): string[] {
  const byId = new Map(props.roles.map((role) => [role.id, role.role_key]));
  return (user.roles || user.role_ids || []).map((id) => byId.get(id) || '').filter(Boolean);
}

function siteRoleKeys(user: User): Array<{site_key:string;role_key:string}> {
  const roleById = new Map(props.roles.map((role) => [role.id, role.role_key]));
  const siteById = new Map(props.sites.map((site) => [site.id, site.site_key]));
  return (user.site_roles || []).map((row) => ({ site_key:siteById.get(row.site_id) || '', role_key:roleById.get(row.role_id) || '' })).filter((row) => row.site_key !== '' && row.role_key !== '');
}

async function exportUsers(): Promise<void> {
  busy.value = true; error.value = ''; notice.value = '';
  try {
    const allUsers = await fetchAllUsers();
    const payload = {
      format: 'dec-cms-iam-users',
      version: 1,
      exported_at: new Date().toISOString(),
      scope: props.isSiteScopedAdmin ? { type:'site', site_key:props.sites.find((site) => site.id === props.currentSiteId)?.site_key || String(props.currentSiteId) } : { type:'global' },
      security: { passwords:false, password_hashes:false, sessions:false, totp_secrets:false },
      users: allUsers.map((user) => ({
        email: user.email,
        first_name: user.first_name || '',
        last_name: user.last_name || '',
        locale: user.locale || 'fr-CH',
        is_active: Boolean(user.is_active),
        disabled_reason: user.disabled_reason || '',
        login_mode: user.login_mode || 'password',
        global_roles: roleKeys(user),
        site_roles: siteRoleKeys(user)
      }))
    };
    const blob = new Blob([JSON.stringify(payload, null, 2) + '\n'], { type:'application/json;charset=utf-8' });
    const link = window.document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.download = `utilisateurs-${new Date().toISOString().slice(0,10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
    notice.value = `${allUsers.length} utilisateur${allUsers.length === 1 ? '' : 's'} exporté${allUsers.length === 1 ? '' : 's'}, sans mot de passe ni session.`;
  } catch (err) { error.value = apiErrorMessage(err, 'Export impossible.'); }
  finally { busy.value = false; }
}

function stringList(value: unknown): string[] {
  return Array.isArray(value) ? value.map((item) => String(item || '').trim()).filter(Boolean) : [];
}

async function preparePlan(): Promise<void> {
  if (!document.value || !Array.isArray(document.value.users)) { plan.value = null; return; }
  busy.value = true; error.value = ''; notice.value = ''; replaceConfirmed.value = false;
  try {
    const allUsers = await fetchAllUsers();
    const existingByEmail = new Map(allUsers.map((user) => [normalizeEmail(user.email), user]));
    const roleByKey = new Map(props.roles.map((role) => [role.role_key, role]));
    const siteByKey = new Map(props.sites.map((site) => [site.site_key, site]));
    const importedEmails = new Set<string>();
    const rows: PreparedRow[] = [];
    const globalErrors: string[] = [];
    const globalWarnings: string[] = [];

    if (document.value.format !== 'dec-cms-iam-users' || Number(document.value.version) !== 1) globalErrors.push('Format de fichier non reconnu ou version incompatible.');
    if (document.value.users.length > 5000) globalErrors.push('Le fichier dépasse la limite de 5 000 utilisateurs.');

    for (const raw of document.value.users as TransferUser[]) {
      const email = normalizeEmail(raw.email);
      const existing = existingByEmail.get(email);
      const row: PreparedRow = { email, action:existing ? 'update' : 'create', existingId:existing?.id, payload:{}, globalRoleKeys:[], siteRoleKeys:[], errors:[], warnings:[] };
      if (!email || !/^\S+@\S+\.\S+$/.test(email)) row.errors.push('Adresse e-mail invalide.');
      if (importedEmails.has(email)) row.errors.push('Adresse présente plusieurs fois dans le fichier.');
      importedEmails.add(email);

      row.globalRoleKeys = stringList(raw.global_roles);
      const globalRoleIds = row.globalRoleKeys.map((key) => {
        const role = roleByKey.get(key);
        if (!role) row.errors.push(`Rôle global inconnu : ${key}.`);
        return role?.id || 0;
      }).filter((id) => id > 0);

      const rawSiteRoles = Array.isArray(raw.site_roles) ? raw.site_roles : [];
      const siteRoles = rawSiteRoles.map((item) => {
        const value = item && typeof item === 'object' ? item as Record<string,unknown> : {};
        const siteKey = String(value.site_key || '').trim();
        const roleKey = String(value.role_key || '').trim();
        const site = siteByKey.get(siteKey);
        const role = roleByKey.get(roleKey);
        if (!site) row.errors.push(`Site inconnu : ${siteKey || '—'}.`);
        if (!role) row.errors.push(`Rôle de site inconnu : ${roleKey || '—'}.`);
        row.siteRoleKeys.push({ site_key:siteKey, role_key:roleKey });
        return { site_id:site?.id || 0, role_id:role?.id || 0 };
      }).filter((item) => item.site_id > 0 && item.role_id > 0);
      if (globalRoleIds.length === 0 && siteRoles.length === 0) row.errors.push('Au moins un rôle global ou un accès par site est obligatoire.');

      const newPassword = String(raw.new_password || '');
      if (!existing && newPassword.length < 12) row.errors.push('Un nouvel utilisateur exige new_password avec au moins 12 caractères.');
      if (existing && newPassword !== '' && newPassword.length < 12) row.errors.push('Le nouveau mot de passe doit contenir au moins 12 caractères.');
      const requestedMode = String(raw.login_mode || 'password');
      let createMode = requestedMode === 'email_code' ? 'email_code' : 'password';
      if (!existing && requestedMode === 'totp') row.warnings.push('Le secret TOTP n’étant jamais exporté, le nouveau compte sera créé en mode mot de passe.');

      row.payload = {
        email,
        first_name:String(raw.first_name || '').trim(),
        last_name:String(raw.last_name || '').trim(),
        locale:String(raw.locale || 'fr-CH').trim() || 'fr-CH',
        is_active:raw.is_active !== false,
        disabled_reason:String(raw.disabled_reason || '').trim(),
        role_ids:globalRoleIds,
        site_roles:siteRoles,
        ...(!existing ? { login_mode:createMode } : {}),
        ...(newPassword !== '' ? { password:newPassword } : {})
      };
      rows.push(row);
    }

    let deactivate: User[] = [];
    if (importMode.value === 'replace') {
      if (props.isSiteScopedAdmin) globalErrors.push('Le remplacement global est réservé à un administrateur transversal.');
      const currentEmail = normalizeEmail(context.context?.user.email);
      if (currentEmail && !importedEmails.has(currentEmail)) globalErrors.push('Le fichier de remplacement doit contenir votre propre compte administrateur.');
      const activeSuperAdmin = rows.some((row) => row.payload.is_active !== false && (row.globalRoleKeys.includes('super_admin') || row.siteRoleKeys.some((role) => role.role_key === 'super_admin')));
      if (!activeSuperAdmin) globalErrors.push('Le remplacement doit conserver au moins un super-administrateur actif.');
      deactivate = allUsers.filter((user) => user.is_active && !importedEmails.has(normalizeEmail(user.email)));
      if (deactivate.length) globalWarnings.push(`${deactivate.length} utilisateur${deactivate.length === 1 ? '' : 's'} absent${deactivate.length === 1 ? '' : 's'} du fichier sera${deactivate.length === 1 ? '' : 'ont'} désactivé${deactivate.length === 1 ? '' : 's'}, jamais supprimé${deactivate.length === 1 ? '' : 's'}.`);
    }
    plan.value = { rows, deactivate, errors:globalErrors, warnings:globalWarnings };
  } catch (err) { error.value = apiErrorMessage(err, 'Préparation de l’import impossible.'); plan.value = null; }
  finally { busy.value = false; }
}

async function selectFile(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  document.value = null; plan.value = null; error.value = ''; notice.value = ''; importFileName.value = file?.name || '';
  if (!file) return;
  if (file.size > 2_000_000) { error.value = 'Le fichier dépasse la limite de 2 Mo.'; return; }
  try {
    const parsed = JSON.parse(await file.text()) as TransferDocument;
    if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.users)) throw new Error('La liste users est absente.');
    document.value = parsed;
    await preparePlan();
  } catch (err) { error.value = `Fichier JSON invalide : ${err instanceof Error ? err.message : 'lecture impossible'}`; }
}

async function executeImport(): Promise<void> {
  if (!plan.value || !canExecute.value) return;
  busy.value = true; error.value = ''; notice.value = '';
  const failures: string[] = [];
  let completed = 0;
  const currentEmail = normalizeEmail(context.context?.user.email);
  const orderedRows = [...plan.value.rows].sort((a, b) => Number(a.email === currentEmail) - Number(b.email === currentEmail));
  for (const row of orderedRows) {
    try {
      if (row.action === 'create') await adminApi.post(scopedPath('/iam/users'), row.payload);
      else await adminApi.patch(scopedPath(`/iam/users/${row.existingId}`), row.payload);
      completed += 1;
    } catch (err) { failures.push(`${row.email}: ${apiErrorMessage(err, 'échec')}`); }
  }
  if (failures.length === 0) {
    for (const user of plan.value.deactivate) {
      try { await adminApi.post(`/iam/users/${user.id}/deactivate`, { reason:'Absent du fichier de remplacement IAM.' }); }
      catch (err) { failures.push(`${user.email}: ${apiErrorMessage(err, 'désactivation impossible')}`); }
    }
  }
  busy.value = false;
  if (failures.length) {
    error.value = `Import partiel : ${completed} ligne${completed === 1 ? '' : 's'} appliquée${completed === 1 ? '' : 's'}. ${failures.join(' ')}`;
    return;
  }
  const summary = `${completed} utilisateur${completed === 1 ? '' : 's'} importé${completed === 1 ? '' : 's'}${plan.value.deactivate.length ? `, ${plan.value.deactivate.length} compte${plan.value.deactivate.length === 1 ? '' : 's'} désactivé${plan.value.deactivate.length === 1 ? '' : 's'}` : ''}.`;
  emit('imported', summary);
}

watch(importMode, () => { if (document.value) void preparePlan(); });
</script>

<template>
  <Teleport to="body">
    <div class="iam-transfer-backdrop" role="presentation" @click.self="emit('close')">
      <section class="iam-transfer-modal" role="dialog" aria-modal="true" aria-labelledby="iam-transfer-title">
        <header>
          <div><p class="eyebrow">Utilisateurs IAM</p><h2 id="iam-transfer-title">Importer / exporter</h2><p>Transférez les profils et leurs rôles sans exposer les secrets d’authentification.</p></div>
          <button type="button" aria-label="Fermer" @click="emit('close')">×</button>
        </header>

        <p v-if="error" class="alert danger">{{ error }}</p>
        <p v-if="notice" class="alert success">{{ notice }}</p>

        <article class="iam-transfer-section">
          <div><h3>Sauvegarder la liste</h3><p>L’export JSON ne contient aucun mot de passe, hash, session, secret TOTP ou code de récupération.</p></div>
          <button class="btn ghost" type="button" :disabled="busy" @click="exportUsers">Exporter les utilisateurs</button>
        </article>

        <article class="iam-transfer-section iam-transfer-import">
          <div><h3>Importer une liste</h3><p>Un utilisateur est reconnu par son adresse e-mail. Ajoutez facultativement <code>"new_password"</code> à une ligne pour définir ou remplacer son mot de passe.</p></div>
          <label class="iam-transfer-file"><span>Fichier JSON</span><input type="file" accept="application/json,.json" :disabled="busy" @change="selectFile"><small>{{ importFileName || 'Format DEC CMS IAM, version 1 · maximum 2 Mo' }}</small></label>
          <fieldset>
            <legend>Mode d’import</legend>
            <label><input v-model="importMode" type="radio" value="merge"> <span><strong>Compléter / mettre à jour</strong><small>Crée les absents et actualise les utilisateurs présents dans le fichier. Les autres comptes restent inchangés.</small></span></label>
            <label :class="{ disabled:isSiteScopedAdmin }"><input v-model="importMode" type="radio" value="replace" :disabled="isSiteScopedAdmin"> <span><strong>Remplacer la liste</strong><small>Met à jour le fichier puis désactive les comptes absents, sans jamais les supprimer.</small></span></label>
          </fieldset>

          <div v-if="plan" class="iam-transfer-plan">
            <div class="iam-transfer-metrics"><span><strong>{{ createCount }}</strong> à créer</span><span><strong>{{ updateCount }}</strong> à mettre à jour</span><span><strong>{{ plan.deactivate.length }}</strong> à désactiver</span><span :class="{ invalid:planErrorCount }"><strong>{{ planErrorCount }}</strong> erreur(s)</span></div>
            <div v-if="plan.errors.length || plan.warnings.length" class="iam-transfer-messages">
              <p v-for="item in plan.errors" :key="`e-${item}`" class="text-danger">{{ item }}</p>
              <p v-for="item in plan.warnings" :key="`w-${item}`" class="text-warning">{{ item }}</p>
            </div>
            <div class="iam-transfer-preview">
              <div v-for="row in plan.rows" :key="row.email" :class="{ invalid:row.errors.length }"><span class="pill">{{ row.action === 'create' ? 'Créer' : 'Modifier' }}</span><strong>{{ row.email || 'E-mail absent' }}</strong><small v-if="row.payload.password">Nouveau mot de passe fourni · sessions révoquées</small><small v-for="item in [...row.errors,...row.warnings]" :key="item">{{ item }}</small></div>
            </div>
            <label v-if="plan.deactivate.length" class="iam-transfer-confirm"><input v-model="replaceConfirmed" type="checkbox"> Je confirme la désactivation des {{ plan.deactivate.length }} comptes absents du fichier.</label>
          </div>
        </article>

        <footer><button class="btn ghost" type="button" :disabled="busy" @click="emit('close')">Fermer</button><button class="btn primary" type="button" :disabled="!canExecute" @click="executeImport">{{ busy ? 'Traitement…' : 'Exécuter l’import' }}</button></footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.iam-transfer-backdrop{background:#0f172a8c;display:grid;inset:0;overflow-y:auto;padding:1rem;place-items:start center;position:fixed;z-index:1200}.iam-transfer-modal{background:#fff;border-radius:1rem;box-shadow:0 24px 80px #0f172a66;display:grid;gap:1rem;margin:auto;padding:1.25rem;width:min(58rem,100%)}.iam-transfer-modal header,.iam-transfer-modal footer,.iam-transfer-section{align-items:flex-start;display:flex;gap:1rem;justify-content:space-between}.iam-transfer-modal header h2,.iam-transfer-modal header p,.iam-transfer-section h3,.iam-transfer-section p{margin:.15rem 0}.iam-transfer-modal header>button{background:transparent;border:0;color:#475569;font-size:2rem;line-height:1}.iam-transfer-section{border:1px solid #e2e8f0;border-radius:.8rem;padding:1rem}.iam-transfer-import{display:grid}.iam-transfer-file{display:grid;gap:.35rem}.iam-transfer-file span,.iam-transfer-import legend{font-weight:800}.iam-transfer-file small,.iam-transfer-import fieldset small{color:#64748b}.iam-transfer-import fieldset{border:0;display:grid;gap:.5rem;margin:0;padding:0}.iam-transfer-import fieldset label{align-items:flex-start;border:1px solid #e2e8f0;border-radius:.65rem;display:flex;gap:.65rem;padding:.7rem}.iam-transfer-import fieldset label span{display:grid}.iam-transfer-import fieldset label.disabled{opacity:.55}.iam-transfer-plan{display:grid;gap:.75rem}.iam-transfer-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem}.iam-transfer-metrics span{background:#f8fafc;border:1px solid #e2e8f0;border-radius:.6rem;display:grid;padding:.65rem}.iam-transfer-metrics strong{font-size:1.35rem}.iam-transfer-metrics .invalid{background:#fef2f2;border-color:#fecaca}.iam-transfer-messages p{margin:.2rem 0}.iam-transfer-preview{border:1px solid #e2e8f0;border-radius:.65rem;max-height:16rem;overflow:auto}.iam-transfer-preview>div{align-items:center;border-bottom:1px solid #e2e8f0;display:grid;gap:.25rem;grid-template-columns:5rem 1fr;padding:.6rem}.iam-transfer-preview>div:last-child{border-bottom:0}.iam-transfer-preview small{color:#64748b;grid-column:2}.iam-transfer-preview .invalid{background:#fef2f2}.iam-transfer-confirm{align-items:center;background:#fff7ed;border:1px solid #fed7aa;border-radius:.65rem;display:flex;gap:.5rem;padding:.7rem}.iam-transfer-modal footer{justify-content:flex-end}@media(max-width:700px){.iam-transfer-section{display:grid}.iam-transfer-metrics{grid-template-columns:repeat(2,1fr)}}
</style>
