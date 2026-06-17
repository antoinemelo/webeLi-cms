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
  system: 'Système'
};
const permissionGroupOrder = ['system', 'modules', 'forms', 'content', 'blueprints', 'fields', 'media', 'menu', 'taxonomy', 'seo', 'cookies', 'settings', 'security', 'users', 'roles', 'sessions', 'audit', 'maintenance', 'deployments', 'profile', 'themes'];


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
    .map(([key, items]) => ({ key, label: permissionGroupLabels[key] ?? key, items }))
    .sort((a, b) => {
      const ai = permissionGroupOrder.indexOf(a.key);
      const bi = permissionGroupOrder.indexOf(b.key);
      const ar = ai >= 0 ? ai : 999;
      const br = bi >= 0 ? bi : 999;
      return ar - br || a.label.localeCompare(b.label);
    });
});
const visiblePermissionGroups = computed(() => permissionGroups.value.map((group) => ({
  ...group,
  items: group.items.filter((permission) => !permissionFilter.value.trim() || `${permission.permission_key} ${permission.name} ${permission.description || ''}`.toLowerCase().includes(permissionFilter.value.trim().toLowerCase()))
})).filter((group) => group.items.length > 0));
const selectedPermissionCount = computed(() => form.value.permission_ids.length);
const systemRoleCount = computed(() => roles.value.filter((role) => role.is_system).length);

function listQuery(extra: Record<string, string|number|boolean|undefined|null> = {}) { return { ...extra }; }
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
          <div class="permission-matrix">
            <article v-for="group in visiblePermissionGroups" :key="group.key" class="permission-group">
              <header><button type="button" class="btn ghost" @click="toggleGroup(group.items)">{{ group.label }}</button><span class="badge">{{ group.items.length }}</span></header>
              <label v-for="permission in group.items" :key="permission.id" class="permission-check">
                <input v-model="form.permission_ids" type="checkbox" :value="permission.id">
                <span><strong>{{ permission.permission_key }}</strong><small>{{ permission.name }}</small></span>
              </label>
            </article>
          </div>
        </section>

        <div class="form-actions"><button class="btn primary" :disabled="saving">Enregistrer</button></div>
      </form>
    </aside>
  </section>
</template>
