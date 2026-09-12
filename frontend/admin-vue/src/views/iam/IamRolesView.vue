<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

type Permission = { id:number; permission_key:string; name:string; description?:string };
type Role = { id:number; role_key:string; name:string; description?:string; permission_ids:number[]; is_system:boolean };

const context = useAdminContextStore();
const roles = ref<Role[]>([]);
const permissions = ref<Permission[]>([]);
const selected = ref<Role|null>(null);
const error = ref('');
const message = ref('');
const saving = ref(false);
const q = ref('');
const permissionFilter = ref('');
const form = ref({ role_key:'', name:'', description:'', permission_ids: [] as number[] });
const permissionGroupLabels: Record<string, string> = {
  content: 'Contenus',
  media: 'Médias',
  taxonomy: 'Taxonomies',
  menu: 'Menus',
  fields: 'Champs',
  blueprints: 'Blueprints',
  modules: 'Modules',
  forms: 'Formulaires',
  cookies: 'Cookies',
  seo: 'SEO',
  settings: 'Paramètres',
  security: 'Sécurité',
  users: 'Utilisateurs',
  roles: 'Rôles',
  sessions: 'Sessions',
  audit: 'Audit',
  maintenance: 'Maintenance',
  deployments: 'Déploiements',
  profile: 'Profil',
  themes: 'Thèmes',
  system: 'Système',
  accounting: 'Comptabilité',
  ai: 'Assistant IA',
  business: 'Opérations',
  sale: 'Vente',
  imports_exports: 'Imports / Exports'
};
const permissionGroupOrder = ['system', 'modules', 'business', 'sale', 'accounting', 'ai', 'forms', 'content', 'blueprints', 'fields', 'media', 'menu', 'taxonomy', 'seo', 'cookies', 'imports_exports', 'settings', 'security', 'users', 'roles', 'sessions', 'audit', 'maintenance', 'deployments', 'profile', 'themes'];
const permissionSubgroupLabels: Record<string, string> = {
  general: 'Accès général',
  advanced_tools: 'Outils avancés',
  actions: 'Actions',
  catalog: 'Catalogue',
  cash: 'Caisse',
  channels: 'Canaux',
  chart: 'Plan comptable',
  consent: 'Consentements',
  content: 'Contenu',
  crm: 'CRM',
  customer_accounts: 'Comptes clients',
  documents: 'Documents',
  exports: 'Exports',
  form_links: 'Liens de formulaires',
  fulfillment: 'Préparation et livraison',
  gift_cards: 'Bons cadeaux',
  html_raw: 'HTML brut',
  inventory: 'Inventaire',
  logs: 'Journaux',
  mailing: 'Mailing',
  memo: 'Mémos',
  messaging: 'Messagerie',
  opening: 'Soldes d’ouverture',
  orders: 'Commandes',
  payments: 'Paiements',
  pos: 'Point de vente',
  provider: 'Fournisseurs',
  revisions: 'Révisions',
  segment: 'Segments',
  seo: 'SEO',
  suggestions: 'Suggestions',
  translation: 'Traductions'
};


const currentSiteId = computed(() => Number(context.siteId || 1));
const filteredRoles = computed(() => roles.value.filter((role) => {
  const haystack = `${role.role_key} ${role.name} ${role.description || ''}`.toLowerCase();
  return !q.value.trim() || haystack.includes(q.value.trim().toLowerCase());
}));
const permissionGroups = computed(() => {
  const groups: Record<string, Permission[]> = {};
  permissions.value.forEach((permission) => {
    const group = permission.permission_key.includes('.') ? permission.permission_key.split('.')[0] : 'system';
    if (!groups[group]) groups[group] = [];
    groups[group].push(permission);
  });
  return Object.entries(groups)
    .map(([key, items]) => {
      const subgroups: Record<string, Permission[]> = {};
      items.forEach((permission) => {
        const segments = permission.permission_key.split('.');
        const subgroupKey = segments.length > 2 ? segments[1] : 'general';
        if (!subgroups[subgroupKey]) subgroups[subgroupKey] = [];
        subgroups[subgroupKey].push(permission);
      });
      return {
        key,
        label: permissionGroupLabels[key] ?? readableKey(key),
        items,
        subgroups:Object.entries(subgroups).map(([subgroupKey, subgroupItems]) => ({
          key:subgroupKey,
          label:permissionSubgroupLabels[subgroupKey] ?? readableKey(subgroupKey),
          items:subgroupItems.sort((a, b) => a.permission_key.localeCompare(b.permission_key))
        })).sort((a, b) => Number(a.key !== 'general') - Number(b.key !== 'general') || a.label.localeCompare(b.label, 'fr'))
      };
    })
    .sort((a, b) => {
      const ai = permissionGroupOrder.indexOf(a.key);
      const bi = permissionGroupOrder.indexOf(b.key);
      const ar = ai >= 0 ? ai : 999;
      const br = bi >= 0 ? bi : 999;
      return ar - br || a.label.localeCompare(b.label);
    });
});
const visiblePermissionGroups = computed(() => permissionGroups.value.map((group) => {
  const filter = permissionFilter.value.trim().toLowerCase();
  const subgroups = group.subgroups.map((subgroup) => ({
    ...subgroup,
    items:subgroup.items.filter((permission) => !filter || `${permission.permission_key} ${permission.name} ${permission.description || ''}`.toLowerCase().includes(filter))
  })).filter((subgroup) => subgroup.items.length > 0);
  return { ...group, subgroups, items:subgroups.flatMap((subgroup) => subgroup.items) };
}).filter((group) => group.items.length > 0));
const selectedPermissionCount = computed(() => form.value.permission_ids.length);
const systemRoleCount = computed(() => roles.value.filter((role) => role.is_system).length);

function listQuery(extra: Record<string, string|number|boolean|undefined|null> = {}) { return { ...extra }; }
function readableKey(value: string): string { const label = value.replace(/_/g, ' ').trim(); return label ? label.charAt(0).toUpperCase() + label.slice(1) : 'Général'; }
function selectedIn(items: Permission[]): number { return items.filter((permission) => form.value.permission_ids.includes(permission.id)).length; }
function create(): void { selected.value = null; form.value = { role_key:'', name:'', description:'', permission_ids:[] }; }
function edit(role: Role): void { selected.value = role; form.value = { role_key:role.role_key, name:role.name, description:role.description || '', permission_ids:[...role.permission_ids] }; }
function toggleGroup(groupPermissions: Permission[]): void {
  const ids = groupPermissions.map((p) => p.id);
  const allSelected = ids.every((id) => form.value.permission_ids.includes(id));
  form.value.permission_ids = allSelected
    ? form.value.permission_ids.filter((id) => !ids.includes(id))
    : Array.from(new Set([...form.value.permission_ids, ...ids]));
}
async function load(): Promise<void> {
  error.value = '';
  try {
    const res = await adminApi.get<{roles:Role[];permissions:Permission[]}>('/iam/roles', listQuery());
    roles.value = res.data.roles;
    permissions.value = res.data.permissions;
    if (!selected.value && roles.value.length) edit(roles.value[0]);
  } catch(e) { error.value = apiErrorMessage(e, 'Rôles indisponibles.'); }
}
async function save(): Promise<void> {
  saving.value = true; error.value = ''; message.value = '';
  try {
    const res = selected.value ? await adminApi.patch<{role:Role;message:string}>(`/iam/roles/${selected.value.id}`, form.value) : await adminApi.post<{role:Role;message:string}>('/iam/roles', form.value);
    message.value = res.data.message;
    await load();
    const refreshed = roles.value.find((role) => role.id === res.data.role.id);
    if (refreshed) edit(refreshed);
  } catch(e) { error.value = apiErrorMessage(e, 'Enregistrement impossible.'); }
  finally { saving.value = false; }
}
onMounted(() => { load(); });
</script>

<template>
  <PageHeader title="Rôles et permissions" intro="Une matrice RBAC claire, proche d’un CMS éditorial : rôles lisibles, permissions regroupées et édition sécurisée des rôles système.">
    <template #actions>
      <RouterLink class="btn ghost" to="/iam/users">Utilisateurs</RouterLink>
    </template>
  </PageHeader>
  <ApiFeedback :error="error" :message="message" />

  <section class="iam-dashboard-strip">
    <article class="iam-stat card"><span>Rôles</span><strong>{{ roles.length }}</strong></article>
    <article class="iam-stat card"><span>Rôles système</span><strong>{{ systemRoleCount }}</strong></article>
    <article class="iam-stat card"><span>Permissions</span><strong>{{ permissions.length }}</strong></article>
    <article class="iam-stat card"><span>Sélection</span><strong>{{ selectedPermissionCount }}</strong></article>
  </section>

  <section class="iam-layout iam-layout--roles">
    <article class="card iam-list-panel">
      <div class="iam-toolbar">
        <input v-model="q" class="input" type="search" placeholder="Code, nom, description…">
      </div>
      <div class="iam-role-list">
        <button v-for="role in filteredRoles" :key="role.id" type="button" class="iam-role-row" :class="{ active: selected?.id === role.id }" @click="edit(role)">
          <span class="iam-role-icon">{{ role.role_key.slice(0,2).toUpperCase() }}</span>
          <span class="iam-user-main"><strong>{{ role.name }}</strong><small>{{ role.role_key }}</small><span v-if="role.description" class="muted">{{ role.description }}</span></span>
          <span class="iam-user-side"><span class="pill" :class="role.is_system ? 'pill--info' : 'pill--neutral'">{{ role.is_system ? 'Système' : 'Custom' }}</span><small>{{ role.permission_ids.length }} droits</small></span>
        </button>
      </div>
    </article>

    <aside class="card iam-editor-panel">
      <div class="panel__header">
        <div>
          <p class="eyebrow">{{ selected ? 'Rôle IAM' : 'Création' }}</p>
          <h2>{{ selected ? 'Modifier le rôle' : 'Créer un rôle' }}</h2>
          <p class="muted">Les permissions doivent rester cohérentes avec les contrôles serveur. La clé d’un rôle système est verrouillée.</p>
        </div>
      </div>

      <form class="form-stack" @submit.prevent="save">
        <div class="field-grid field-grid--2">
          <label class="field"><span>Clé</span><input v-model="form.role_key" class="input" :disabled="Boolean(selected?.is_system)" required pattern="^[a-z][a-z0-9_]{1,63}$"></label>
          <label class="field"><span>Nom</span><input v-model="form.name" class="input" required></label>
        </div>
        <label class="field"><span>Description</span><textarea v-model="form.description" class="input" rows="3"></textarea></label>

        <section class="iam-subpanel">
          <div class="split-head">
            <div><h3>Matrice de permissions</h3><p class="muted">Regroupée par domaine pour éviter les erreurs d’attribution.</p></div>
            <input v-model="permissionFilter" class="input iam-permission-search" type="search" placeholder="Filtrer les permissions…">
          </div>
          <div v-if="visiblePermissionGroups.length" class="permission-matrix">
            <article v-for="group in visiblePermissionGroups" :key="group.key" class="permission-group">
              <header class="permission-group__header">
                <div><span class="permission-group__eyebrow">Domaine</span><h4>{{ group.label }}</h4></div>
                <button type="button" class="permission-group__toggle" :class="{ active:selectedIn(group.items) === group.items.length }" @click="toggleGroup(group.items)"><strong>{{ selectedIn(group.items) }}</strong><span>/ {{ group.items.length }}</span><small>{{ selectedIn(group.items) === group.items.length ? 'Tout retirer' : 'Tout sélectionner' }}</small></button>
              </header>
              <div class="permission-subgroups">
                <section v-for="subgroup in group.subgroups" :key="`${group.key}-${subgroup.key}`" class="permission-subgroup">
                  <header><h5>{{ subgroup.label }}</h5><button type="button" :title="selectedIn(subgroup.items) === subgroup.items.length ? 'Retirer ce sous-groupe' : 'Sélectionner ce sous-groupe'" @click="toggleGroup(subgroup.items)">{{ selectedIn(subgroup.items) }}/{{ subgroup.items.length }}</button></header>
                  <div class="permission-subgroup__items">
                    <label v-for="permission in subgroup.items" :key="permission.id" class="permission-check" :class="{ selected:form.permission_ids.includes(permission.id) }">
                      <input v-model="form.permission_ids" type="checkbox" :value="permission.id">
                      <span><strong>{{ permission.name }}</strong><code>{{ permission.permission_key }}</code><small v-if="permission.description">{{ permission.description }}</small></span>
                    </label>
                  </div>
                </section>
              </div>
            </article>
          </div>
          <div v-else class="empty-state permission-matrix__empty"><strong>Aucune permission</strong><p>Aucun droit ne correspond au filtre saisi.</p></div>
        </section>

        <div class="form-actions"><button class="btn primary" :disabled="saving">Enregistrer</button></div>
      </form>
    </aside>
  </section>
</template>

<style scoped>
.iam-layout--roles{grid-template-columns:minmax(270px,.58fr) minmax(0,1.42fr)}
.iam-layout--roles .iam-editor-panel{position:static}
.iam-permission-search{max-width:24rem}
.permission-matrix{column-gap:1rem;column-width:24rem;display:block;max-height:none;overflow:visible;padding:0}
.permission-group{background:#f8fafc;border:1px solid #cbd5e1;border-radius:1rem;break-inside:avoid;display:inline-grid;gap:.75rem;margin:0 0 1rem;padding:.8rem;width:100%}
.permission-group__header{align-items:center;display:flex;gap:.75rem;justify-content:space-between;margin:0}
.permission-group__eyebrow{color:#64748b;font-size:.66rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase}
.permission-group__header h4{color:#0f172a;font-size:1.05rem;margin:.05rem 0 0}
.permission-group__toggle{align-items:baseline;background:#fff;border:1px solid #cbd5e1;border-radius:.7rem;color:#475569;cursor:pointer;display:grid;grid-template-columns:auto auto;padding:.35rem .5rem;text-align:right}
.permission-group__toggle strong{color:#0f172a;font-size:1rem}.permission-group__toggle small{font-size:.64rem;grid-column:1/-1}.permission-group__toggle.active{background:#ecfdf5;border-color:#86efac;color:#166534}
.permission-subgroups{align-items:flex-start;display:flex;flex-wrap:wrap;gap:.65rem}
.permission-subgroup{background:#fff;border:1px solid #e2e8f0;border-radius:.8rem;display:grid;flex:1 1 16rem;gap:.4rem;min-width:0;padding:.55rem;width:fit-content}
.permission-subgroup>header{align-items:center;border-bottom:1px solid #f1f5f9;display:flex;gap:.5rem;justify-content:space-between;margin:0;padding:0 .15rem .4rem}
.permission-subgroup h5{color:#334155;font-size:.78rem;letter-spacing:.035em;margin:0;text-transform:uppercase}
.permission-subgroup>header button{background:#f1f5f9;border:0;border-radius:999px;color:#475569;cursor:pointer;font-size:.7rem;font-weight:900;padding:.18rem .42rem}
.permission-subgroup__items{display:grid;gap:.28rem}
.permission-check{align-items:start;border:1px solid transparent;border-radius:.65rem;cursor:pointer;display:grid;gap:.45rem;grid-template-columns:auto minmax(0,1fr);padding:.45rem}
.permission-check:hover{background:#f8fafc;border-color:#e2e8f0}.permission-check.selected{background:#eff6ff;border-color:#bfdbfe}
.permission-check input{margin-top:.2rem}.permission-check span{display:grid;gap:.08rem;min-width:0}.permission-check strong{color:#0f172a;font-size:.83rem;line-height:1.25}.permission-check code{color:#475569;font-size:.7rem;overflow-wrap:anywhere}.permission-check small{color:#64748b;font-size:.72rem;line-height:1.3}
.permission-matrix__empty{margin-top:.5rem}
@media(max-width:1080px){.iam-layout--roles{grid-template-columns:1fr}.permission-matrix{column-width:22rem}}
@media(max-width:720px){.permission-matrix{columns:1}.permission-subgroups{display:grid}.permission-subgroup{width:100%}.split-head{align-items:stretch;display:grid}.iam-permission-search{max-width:none;width:100%}}
</style>
