<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';

interface CookieSetting { id?: number; is_enabled: number; consent_version: string; cookie_name: string; consent_lifetime_days: number; log_retention_days: number; banner_position: string; theme: string; accent_color: string; show_floating_button: number; reject_equal_prominence: number; respect_dnt: number; }
interface BannerTexts { banner_title: string; banner_summary: string; preferences_title: string; preferences_summary: string; accept_all_label: string; reject_all_label: string; customize_label: string; save_choices_label: string; manage_link_label: string; legal_notice_html: string; }
interface CookieCategory { id?: number; category_key: string; name: string; description: string; is_required: number; is_enabled: number; is_preselected: number; sort_order: number; }
interface ServiceCookie { cookie_name: string; purpose: string; duration: string; domain: string; }
interface CookieService { id?: number; category_id: number; category_key?: string; service_key: string; name: string; provider_name: string; purpose: string; description: string; fallback_message: string; service_type: string; cookie_type: string; domain: string; duration: string; privacy_url: string; is_enabled: number; sort_order: number; cookies: ServiceCookie[]; }
interface CookieBinding { id?: number; service_id: number; service_key?: string; category_key?: string; binding_key: string; location: string; trigger_mode: string; script_kind: string; src_url: string; inline_code: string; attributes?: Record<string, string>; is_enabled: number; sort_order: number; }
interface ConsentLog { id: number; consent_uid: string; consent_version: string; language_code: string; action: string; choices_json: string; created_at: string; }
interface CookieState { setting: CookieSetting; texts: BannerTexts; categories: CookieCategory[]; services: CookieService[]; script_bindings: CookieBinding[]; logs: ConsentLog[]; }

type TabKey = 'settings' | 'banner' | 'categories' | 'services' | 'scripts' | 'logs';

const context = useAdminContextStore();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const activeTab = ref<TabKey>('settings');
const state = ref<CookieState | null>(null);
const categoryDraft = ref<CookieCategory>(blankCategory());
const serviceDraft = ref<CookieService>(blankService());
const bindingDraft = ref<CookieBinding>(blankBinding());

const language = computed(() => context.contentLanguageCode || 'fr');
const categories = computed(() => state.value?.categories || []);
const services = computed(() => state.value?.services || []);
const bindings = computed(() => state.value?.script_bindings || []);
const logs = computed(() => state.value?.logs || []);
const optionalServicesCount = computed(() => services.value.filter((service) => service.category_key !== 'necessary').length);
const activeCategoryName = (id: number) => categories.value.find((c) => Number(c.id) === Number(id))?.name || 'Catégorie';

function blankCategory(): CookieCategory { return { category_key: '', name: '', description: '', is_required: 0, is_enabled: 1, is_preselected: 0, sort_order: 100 }; }
function blankService(): CookieService { return { category_id: 0, service_key: '', name: '', provider_name: '', purpose: '', description: '', fallback_message: '', service_type: 'script', cookie_type: 'http', domain: '', duration: '', privacy_url: '', is_enabled: 1, sort_order: 100, cookies: [] }; }
function blankBinding(): CookieBinding { return { service_id: 0, binding_key: '', location: 'footer', trigger_mode: 'after_consent', script_kind: 'external', src_url: '', inline_code: '', attributes: {}, is_enabled: 1, sort_order: 100 }; }
function clone<T>(value: T): T { return JSON.parse(JSON.stringify(value)); }
function cookieLines(cookies: ServiceCookie[]): string { return (cookies || []).map((c) => [c.cookie_name, c.duration, c.domain, c.purpose].join('|')).join('\n'); }
function parseCookieLines(value: string): ServiceCookie[] { return value.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => { const [cookie_name, duration = '', domain = '', purpose = ''] = line.split('|'); return { cookie_name: cookie_name.trim(), duration: duration.trim(), domain: domain.trim(), purpose: purpose.trim() }; }); }
function localizedPath(path: string): string { return `${path}?lang=${encodeURIComponent(language.value)}`; }

async function load() {
  loading.value = true; error.value = ''; notice.value = '';
  try {
    const res = await adminApi.get<CookieState>('/cookies', { lang: language.value });
    state.value = res.data;
    if (!serviceDraft.value.category_id && res.data.categories[0]?.id) serviceDraft.value.category_id = Number(res.data.categories[0].id);
    if (!bindingDraft.value.service_id && res.data.services[0]?.id) bindingDraft.value.service_id = Number(res.data.services[0].id);
  } catch (e) { error.value = apiErrorMessage(e); }
  finally { loading.value = false; }
}

async function saveSettings() { if (!state.value) return; await mutate(() => adminApi.patch<CookieState>(localizedPath('/cookies/settings'), { ...state.value!.setting, texts: state.value!.texts }), 'Réglages cookies enregistrés.'); }
async function clearLogs() { if (!confirm('Effacer tout l’historique des consentements cookies ?')) return; await mutate(() => adminApi.delete<CookieState>(`/cookies/logs?lang=${encodeURIComponent(language.value)}`), 'Historique des consentements effacé.'); }
async function saveCategory() { await mutate(() => categoryDraft.value.id ? adminApi.patch<CookieState>(localizedPath(`/cookies/categories/${categoryDraft.value.id}`), categoryDraft.value) : adminApi.post<CookieState>(localizedPath('/cookies/categories'), categoryDraft.value), 'Catégorie enregistrée.'); categoryDraft.value = blankCategory(); }
async function saveService() { await mutate(() => serviceDraft.value.id ? adminApi.patch<CookieState>(localizedPath(`/cookies/services/${serviceDraft.value.id}`), serviceDraft.value) : adminApi.post<CookieState>(localizedPath('/cookies/services'), serviceDraft.value), 'Service enregistré.'); serviceDraft.value = blankService(); if (categories.value[0]?.id) serviceDraft.value.category_id = Number(categories.value[0].id); }
async function saveBinding() { await mutate(() => bindingDraft.value.id ? adminApi.patch<CookieState>(`/cookies/bindings/${bindingDraft.value.id}`, bindingDraft.value) : adminApi.post<CookieState>('/cookies/bindings', bindingDraft.value), 'Script enregistré.'); bindingDraft.value = blankBinding(); if (services.value[0]?.id) bindingDraft.value.service_id = Number(services.value[0].id); }
async function remove(path: string, label: string) { if (!confirm(`Supprimer ${label} ?`)) return; await mutate(() => adminApi.delete<CookieState>(path), `${label} supprimé.`); await load(); }
async function mutate(fn: () => Promise<{ data: CookieState }>, message: string) { saving.value = true; error.value = ''; notice.value = ''; try { const res = await fn(); state.value = res.data; notice.value = message; } catch (e) { error.value = apiErrorMessage(e); } finally { saving.value = false; } }

function editCategory(category: CookieCategory) { categoryDraft.value = clone(category); activeTab.value = 'categories'; }
function editService(service: CookieService) { serviceDraft.value = clone(service); activeTab.value = 'services'; }
function editBinding(binding: CookieBinding) { bindingDraft.value = clone(binding); activeTab.value = 'scripts'; }
function updateServiceCookies(raw: string) { serviceDraft.value.cookies = parseCookieLines(raw); }

watch(language, load);
onMounted(load);
</script>

<template>
  <section class="page-stack">
    <PageHeader title="Cookies et consentements" intro="Configuration native de la bannière, de la page publique /cookies, des services facultatifs et des scripts soumis à consentement." />
    <ApiFeedback :error="error" :notice="notice" />

    <div v-if="loading" class="alert alert-light border">Chargement…</div>

    <div v-if="state" class="vstack gap-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-start">
          <div>
            <h2 class="h5 mb-1 d-inline-flex align-items-center gap-1">
              Configuration active
              <InfoHint text="Changez de langue avec les boutons du menu admin." />
            </h2>
            <p class="text-muted mb-0">Langue éditée : <span class="badge text-bg-secondary text-uppercase">{{ language }}</span>.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="badge text-bg-light border">{{ categories.length }} catégories</span>
            <span class="badge text-bg-light border">{{ services.length }} services</span>
            <span class="badge text-bg-light border">{{ optionalServicesCount }} facultatifs</span>
          </div>
        </div>
      </div>

      <nav class="editor-tabs" aria-label="Gestion cookies">
        <button class="editor-tab" :class="{ active: activeTab === 'settings' }" type="button" @click="activeTab = 'settings'">Paramètres</button>
        <button class="editor-tab" :class="{ active: activeTab === 'banner' }" type="button" @click="activeTab = 'banner'">Bannière</button>
        <button class="editor-tab" :class="{ active: activeTab === 'categories' }" type="button" @click="activeTab = 'categories'">Catégories</button>
        <button class="editor-tab" :class="{ active: activeTab === 'services' }" type="button" @click="activeTab = 'services'">Services</button>
        <button class="editor-tab" :class="{ active: activeTab === 'scripts' }" type="button" @click="activeTab = 'scripts'">Scripts</button>
        <button class="editor-tab" :class="{ active: activeTab === 'logs' }" type="button" @click="activeTab = 'logs'">Journal</button>
      </nav>

      <form v-if="activeTab === 'settings'" class="card border-0 shadow-sm" @submit.prevent="saveSettings">
        <div class="card-header bg-body d-flex justify-content-between align-items-center gap-3">
          <div>
            <h2 class="h5 mb-0 d-inline-flex align-items-center gap-1">
              Paramètres techniques
              <InfoHint text="Réglages globaux du module, communs à toutes les langues." />
            </h2>
          </div>
          <button class="btn btn-primary btn-sm" :disabled="saving">Enregistrer</button>
        </div>
        <div class="card-body row g-3">
          <label class="col-md-3 form-label">Module actif<select class="form-select" v-model.number="state.setting.is_enabled"><option :value="1">Oui</option><option :value="0">Non</option></select></label>
          <label class="col-md-3 form-label">Version<input class="form-control" v-model="state.setting.consent_version"></label>
          <label class="col-md-3 form-label">Nom du cookie<input class="form-control font-monospace" v-model="state.setting.cookie_name"></label>
          <label class="col-md-3 form-label">Position<select class="form-select" v-model="state.setting.banner_position"><option>bottom</option><option>top</option><option>modal</option></select></label>
          <label class="col-md-3 form-label">Durée consentement<input class="form-control" v-model.number="state.setting.consent_lifetime_days" type="number" min="1" max="730"></label>
          <label class="col-md-3 form-label">Rétention journal<input class="form-control" v-model.number="state.setting.log_retention_days" type="number" min="1" max="1095"></label>
          <label class="col-md-3 form-label">Bouton flottant<select class="form-select" v-model.number="state.setting.show_floating_button"><option :value="1">Oui</option><option :value="0">Non</option></select></label>
          <label class="col-md-3 form-label">Refus aussi visible<select class="form-select" v-model.number="state.setting.reject_equal_prominence"><option :value="1">Oui</option><option :value="0">Non</option></select></label>
        </div>
      </form>

      <form v-if="activeTab === 'banner'" class="card border-0 shadow-sm" @submit.prevent="saveSettings">
        <div class="card-header bg-body d-flex justify-content-between align-items-center gap-3">
          <div>
            <h2 class="h5 mb-0 d-inline-flex align-items-center gap-1">
              Bannière et textes publics
              <InfoHint text="Ces textes sont propres à la langue active et alimentent aussi automatiquement la page publique /cookies." />
            </h2>
          </div>
          <button class="btn btn-primary btn-sm" :disabled="saving">Enregistrer</button>
        </div>
        <div class="card-body row g-3">
          <label class="col-md-6 form-label">Titre bannière<input class="form-control" v-model="state.texts.banner_title"></label>
          <label class="col-md-6 form-label">Titre préférences<input class="form-control" v-model="state.texts.preferences_title"></label>
          <label class="col-12 form-label">Résumé bannière<textarea class="form-control" v-model="state.texts.banner_summary" rows="2"></textarea></label>
          <label class="col-12 form-label">Résumé panneau / page cookies<textarea class="form-control" v-model="state.texts.preferences_summary" rows="2"></textarea></label>
          <label class="col-md-4 form-label">Tout accepter<input class="form-control" v-model="state.texts.accept_all_label"></label>
          <label class="col-md-4 form-label">Tout refuser<input class="form-control" v-model="state.texts.reject_all_label"></label>
          <label class="col-md-4 form-label">Enregistrer les choix<input class="form-control" v-model="state.texts.save_choices_label"></label>
          <label class="col-md-4 form-label">Lien gérer cookies<input class="form-control" v-model="state.texts.manage_link_label"></label>
          <label class="col-12 form-label">Note juridique courte autorisée en HTML<textarea class="form-control font-monospace" v-model="state.texts.legal_notice_html" rows="3"></textarea></label>
        </div>
      </form>

      <section v-if="activeTab === 'categories'" class="card border-0 shadow-sm">
        <div class="card-header bg-body"><h2 class="h5 mb-0">Catégories</h2></div>
        <div class="card-body">
          <div class="table-responsive mb-4"><table class="table align-middle"><thead><tr><th>Clé</th><th>Nom</th><th>Obligatoire</th><th>Active</th><th class="text-end">Actions</th></tr></thead><tbody><tr v-for="category in categories" :key="category.id"><td><code>{{ category.category_key }}</code></td><td>{{ category.name }}</td><td>{{ category.is_required ? 'Oui' : 'Non' }}</td><td>{{ category.is_enabled ? 'Oui' : 'Non' }}</td><td class="text-end"><button class="btn btn-outline-secondary btn-sm" @click="editCategory(category)">Modifier</button><button v-if="!category.is_required" class="btn btn-outline-danger btn-sm ms-2" @click="remove(`/cookies/categories/${category.id}`, 'cette catégorie')">Supprimer</button></td></tr></tbody></table></div>
          <form class="row g-3" @submit.prevent="saveCategory">
            <label class="col-md-3 form-label">Clé<input class="form-control font-monospace" v-model="categoryDraft.category_key" :disabled="Boolean(categoryDraft.id)" placeholder="statistics"></label>
            <label class="col-md-3 form-label">Nom<input class="form-control" v-model="categoryDraft.name"></label>
            <label class="col-md-6 form-label">Description<input class="form-control" v-model="categoryDraft.description"></label>
            <label class="col-md-2 form-label">Obligatoire<select class="form-select" v-model.number="categoryDraft.is_required"><option :value="0">Non</option><option :value="1">Oui</option></select></label>
            <label class="col-md-2 form-label">Active<select class="form-select" v-model.number="categoryDraft.is_enabled"><option :value="1">Oui</option><option :value="0">Non</option></select></label>
            <label class="col-md-2 form-label">Précochée<select class="form-select" v-model.number="categoryDraft.is_preselected"><option :value="0">Non</option><option :value="1">Oui</option></select></label>
            <label class="col-md-2 form-label">Ordre<input class="form-control" type="number" v-model.number="categoryDraft.sort_order"></label>
            <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-primary" :disabled="saving">{{ categoryDraft.id ? 'Mettre à jour' : 'Ajouter' }}</button><button class="btn btn-outline-secondary" type="button" @click="categoryDraft = blankCategory()">Réinitialiser</button></div>
          </form>
        </div>
      </section>

      <section v-if="activeTab === 'services'" class="card border-0 shadow-sm">
        <div class="card-header bg-body"><h2 class="h5 mb-0">Services et cookies déclarés</h2></div>
        <div class="card-body">
          <div class="list-group mb-4"><div v-for="service in services" :key="service.id" class="list-group-item"><div class="d-flex flex-wrap justify-content-between gap-2"><div><strong>{{ service.name || service.service_key }}</strong> <span class="text-muted">· {{ service.provider_name }} · {{ activeCategoryName(service.category_id) }}</span><p class="mb-0 small text-muted">{{ service.purpose || service.description }}</p></div><div><button class="btn btn-outline-secondary btn-sm" @click="editService(service)">Modifier</button><button class="btn btn-outline-danger btn-sm ms-2" @click="remove(`/cookies/services/${service.id}`, 'ce service')">Supprimer</button></div></div></div></div>
          <form class="row g-3" @submit.prevent="saveService">
            <label class="col-md-4 form-label">Catégorie<select class="form-select" v-model.number="serviceDraft.category_id"><option v-for="category in categories" :value="Number(category.id)" :key="category.id">{{ category.name }}</option></select></label>
            <label class="col-md-4 form-label">Clé service<input class="form-control font-monospace" v-model="serviceDraft.service_key" :disabled="Boolean(serviceDraft.id)" placeholder="matomo"></label>
            <label class="col-md-4 form-label">Nom public<input class="form-control" v-model="serviceDraft.name"></label>
            <label class="col-md-4 form-label">Fournisseur<input class="form-control" v-model="serviceDraft.provider_name"></label>
            <label class="col-md-4 form-label">Durée<input class="form-control" v-model="serviceDraft.duration" placeholder="13 mois"></label>
            <label class="col-md-4 form-label">Domaine<input class="form-control" v-model="serviceDraft.domain" placeholder="example.com"></label>
            <label class="col-12 form-label">Finalité<input class="form-control" v-model="serviceDraft.purpose"></label>
            <label class="col-12 form-label">Politique de confidentialité<input class="form-control" v-model="serviceDraft.privacy_url" placeholder="https://..."></label>
            <label class="col-12 form-label">Cookies déclarés <small class="text-muted">un par ligne : nom|durée|domaine|finalité</small><textarea class="form-control font-monospace" :value="cookieLines(serviceDraft.cookies)" @input="updateServiceCookies(($event.target as HTMLTextAreaElement).value)" rows="4"></textarea></label>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" :disabled="saving">{{ serviceDraft.id ? 'Mettre à jour' : 'Ajouter le service' }}</button><button class="btn btn-outline-secondary" type="button" @click="serviceDraft = blankService()">Réinitialiser</button></div>
          </form>
        </div>
      </section>

      <section v-if="activeTab === 'scripts'" class="card border-0 shadow-sm">
        <div class="card-header bg-body"><h2 class="h5 mb-0">Scripts soumis au consentement</h2></div>
        <div class="card-body">
          <div class="list-group mb-4"><div v-for="binding in bindings" :key="binding.id" class="list-group-item"><strong>{{ binding.binding_key }}</strong><br><small>{{ binding.script_kind }} · {{ binding.category_key }} · {{ binding.src_url }}</small><div class="mt-2"><button class="btn btn-outline-secondary btn-sm" @click="editBinding(binding)">Modifier</button><button class="btn btn-outline-danger btn-sm ms-2" @click="remove(`/cookies/bindings/${binding.id}`, 'ce script')">Supprimer</button></div></div></div>
          <form class="row g-3" @submit.prevent="saveBinding">
            <label class="col-md-4 form-label">Service<select class="form-select" v-model.number="bindingDraft.service_id"><option v-for="service in services" :value="Number(service.id)" :key="service.id">{{ service.name || service.service_key }}</option></select></label>
            <label class="col-md-4 form-label">Clé script<input class="form-control font-monospace" v-model="bindingDraft.binding_key" :disabled="Boolean(bindingDraft.id)" placeholder="matomo_base"></label>
            <label class="col-md-2 form-label">Type<select class="form-select" v-model="bindingDraft.script_kind"><option>external</option><option>inline</option><option>html</option></select></label>
            <label class="col-md-2 form-label">Emplacement<select class="form-select" v-model="bindingDraft.location"><option>footer</option><option>head</option><option>manual</option></select></label>
            <label class="col-12 form-label">URL externe<input class="form-control" v-model="bindingDraft.src_url" placeholder="https://..."></label>
            <label class="col-12 form-label">Code inline / HTML<textarea class="form-control font-monospace" v-model="bindingDraft.inline_code" rows="5"></textarea></label>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" :disabled="saving">{{ bindingDraft.id ? 'Mettre à jour' : 'Ajouter le script' }}</button><button class="btn btn-outline-secondary" type="button" @click="bindingDraft = blankBinding()">Réinitialiser</button></div>
          </form>
        </div>
      </section>

      <section v-if="activeTab === 'logs'" class="card border-0 shadow-sm">
        <div class="card-header bg-body d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <h2 class="h5 mb-0 d-inline-flex align-items-center gap-1">
              Derniers consentements
              <InfoHint text="Historique technique des choix enregistrés pour ce site." />
            </h2>
          </div>
          <button class="btn btn-outline-danger btn-sm" type="button" :disabled="saving || logs.length === 0" @click="clearLogs">Effacer tout l’historique</button>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Action</th><th>Langue</th><th>Version</th><th>UID</th></tr></thead><tbody><tr v-for="log in logs" :key="log.id"><td>{{ log.created_at }}</td><td><span class="badge text-bg-light border">{{ log.action }}</span></td><td>{{ log.language_code }}</td><td>{{ log.consent_version }}</td><td><code>{{ log.consent_uid }}</code></td></tr><tr v-if="logs.length === 0"><td colspan="5" class="text-muted">Aucun consentement journalisé.</td></tr></tbody></table></div>
      </section>
    </div>
  </section>
</template>
