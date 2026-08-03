<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';

type Rule = { id: number; account_prefix: string; increase_side: 'debit'|'credit'; label?: string|null };
type Category = { id: number; account_prefix: string; label: string; sort_order: number; account_count?: number };
type Account = {
  id: number; account_number: string; label: string; is_active: number;
  normal_side: 'debit'|'credit'; category?: { id: number; account_prefix: string; label: string }|null;
};
type FiscalPeriod = { id: number; code: string; label: string; starts_on: string; ends_on: string; status: string };
type Structure = {
  chart: { id: number; name: string; currency: string; default_increase_side: 'debit'|'credit' };
  rules: Rule[]; categories: Category[]; accounts: Account[]; fiscal_periods: FiscalPeriod[];
  opening_balances: Array<{ account_id: number; amount_minor: number }>;
  selected_fiscal_period_id?: number|null;
};

const route = useRoute();
const router = useRouter();
const context = useAdminContextStore();
const structure = ref<Structure|null>(null);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const success = ref('');
const activeTab = computed(() => route.path.endsWith('/opening') ? 'opening' : 'chart');
const canManageChart = computed(() => context.can('accounting.chart.manage'));
const canManageOpening = computed(() => context.can('accounting.opening.manage'));

const ruleForm = reactive({ id: 0, account_prefix: '', increase_side: 'credit' as 'debit'|'credit', label: '' });
const categoryForm = reactive({ id: 0, account_prefix: '', label: '', sort_order: 0 });
const accountForm = reactive({ id: 0, account_number: '', label: '', is_active: true });
const periodForm = reactive({ code: '', label: '', starts_on: '', ends_on: '' });
const selectedPeriodId = ref(0);
const openingAmounts = reactive<Record<number,string>>({});

function clearMessage(): void { error.value = ''; success.value = ''; }
function resetRule(): void { Object.assign(ruleForm, { id: 0, account_prefix: '', increase_side: 'credit', label: '' }); }
function resetCategory(): void { Object.assign(categoryForm, { id: 0, account_prefix: '', label: '', sort_order: 0 }); }
function resetAccount(): void { Object.assign(accountForm, { id: 0, account_number: '', label: '', is_active: true }); }
function moneyFromMinor(value: number): string { return (Number(value || 0) / 100).toFixed(2); }
function minorFromMoney(value: string): number {
  const normalized = value.trim().replace(',', '.');
  if (!/^-?\d+(?:\.\d{0,2})?$/.test(normalized || '0')) throw new Error(`Montant invalide: ${value}`);
  return Math.round(Number(normalized || 0) * 100);
}

async function load(periodId = selectedPeriodId.value): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<Structure>('/accounting/structure', { fiscal_period_id: periodId || undefined });
    structure.value = response.data;
    selectedPeriodId.value = Number(response.data.selected_fiscal_period_id || 0);
    Object.keys(openingAmounts).forEach(key => delete openingAmounts[Number(key)]);
    response.data.accounts.forEach(account => { openingAmounts[account.id] = '0.00'; });
    response.data.opening_balances.forEach(balance => { openingAmounts[balance.account_id] = moneyFromMinor(balance.amount_minor); });
  } catch (reason) {
    error.value = apiErrorMessage(reason, 'Plan comptable indisponible.');
  } finally { loading.value = false; }
}

async function saveRule(): Promise<void> {
  busy.value = 'rule'; clearMessage();
  try {
    const data = { account_prefix: ruleForm.account_prefix, increase_side: ruleForm.increase_side, label: ruleForm.label };
    ruleForm.id ? await adminApi.patch(`/accounting/rules/${ruleForm.id}`, data) : await adminApi.post('/accounting/rules', data);
    success.value = 'Règle enregistrée.'; resetRule(); await load();
  } catch (reason) { error.value = apiErrorMessage(reason); } finally { busy.value = ''; }
}
async function saveCategory(): Promise<void> {
  busy.value = 'category'; clearMessage();
  try {
    const data = { account_prefix: categoryForm.account_prefix, label: categoryForm.label, sort_order: categoryForm.sort_order };
    categoryForm.id ? await adminApi.patch(`/accounting/categories/${categoryForm.id}`, data) : await adminApi.post('/accounting/categories', data);
    success.value = 'Rubrique enregistrée.'; resetCategory(); await load();
  } catch (reason) { error.value = apiErrorMessage(reason); } finally { busy.value = ''; }
}
async function saveAccount(): Promise<void> {
  busy.value = 'account'; clearMessage();
  try {
    const data = { account_number: accountForm.account_number, label: accountForm.label, is_active: accountForm.is_active };
    accountForm.id ? await adminApi.patch(`/accounting/accounts/${accountForm.id}`, data) : await adminApi.post('/accounting/accounts', data);
    success.value = 'Compte enregistré.'; resetAccount(); await load();
  } catch (reason) { error.value = apiErrorMessage(reason); } finally { busy.value = ''; }
}
async function remove(kind: 'rules'|'categories'|'accounts', id: number): Promise<void> {
  if (!window.confirm('Confirmer le retrait de cet élément ?')) return;
  busy.value = `${kind}-${id}`; clearMessage();
  try {
    await adminApi.delete(`/accounting/${kind}/${id}`);
    success.value = kind === 'accounts' ? 'Compte retiré ou archivé s’il est déjà utilisé.' : 'Élément retiré.';
    await load();
  } catch (reason) { error.value = apiErrorMessage(reason); } finally { busy.value = ''; }
}
async function createPeriod(): Promise<void> {
  busy.value = 'period'; clearMessage();
  try {
    const response = await adminApi.post<{ fiscal_period: FiscalPeriod }>('/accounting/fiscal-periods', { ...periodForm });
    Object.assign(periodForm, { code: '', label: '', starts_on: '', ends_on: '' });
    selectedPeriodId.value = response.data.fiscal_period.id;
    success.value = 'Exercice créé.'; await load(selectedPeriodId.value);
  } catch (reason) { error.value = apiErrorMessage(reason); } finally { busy.value = ''; }
}
async function saveOpening(): Promise<void> {
  if (!selectedPeriodId.value || !structure.value) return;
  busy.value = 'opening'; clearMessage();
  try {
    const balances = structure.value.accounts.filter(a => a.is_active).map(account => ({
      account_id: account.id,
      amount_minor: minorFromMoney(openingAmounts[account.id] || '0')
    }));
    await adminApi.put(`/accounting/fiscal-periods/${selectedPeriodId.value}/opening-balances`, { balances });
    success.value = 'Soldes d’ouverture enregistrés.'; await load(selectedPeriodId.value);
  } catch (reason) { error.value = apiErrorMessage(reason); } finally { busy.value = ''; }
}

function editRule(item: Rule): void { Object.assign(ruleForm, { ...item, label: item.label || '' }); }
function editCategory(item: Category): void { Object.assign(categoryForm, item); }
function editAccount(item: Account): void { Object.assign(accountForm, { ...item, is_active: Boolean(item.is_active) }); }
function openTab(tab: 'chart'|'opening'): void { void router.push(tab === 'opening' ? '/accounting/opening' : '/accounting'); }

watch(selectedPeriodId, (value, oldValue) => { if (value && oldValue && value !== oldValue) void load(value); });
watch(() => context.siteId, () => void load());
onMounted(() => void load());
</script>

<template>
  <main class="accounting-page">
    <header class="accounting-hero">
      <div>
        <p class="eyebrow">Comptabilité</p>
        <h1>{{ structure?.chart.name || 'Plan comptable' }}</h1>
        <p>La structure qui détermine le sens des comptes, leur regroupement et leurs montants d’ouverture.</p>
      </div>
      <span class="currency">{{ structure?.chart.currency || 'CHF' }}</span>
    </header>

    <nav class="accounting-tabs" aria-label="Sections comptables">
      <button :class="{ active: activeTab === 'chart' }" @click="openTab('chart')">Structure des comptes</button>
      <button :class="{ active: activeTab === 'opening' }" @click="openTab('opening')">Soldes d’ouverture</button>
    </nav>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>
    <div v-if="success" class="alert alert-success">{{ success }}</div>
    <div v-if="loading" class="accounting-loading">Chargement…</div>

    <template v-if="structure && activeTab === 'chart'">
      <section class="accounting-card">
        <div class="section-heading">
          <div><span>1</span><h2>Comptes fonctionnant en moins / plus</h2></div>
          <p>Définissez ici les préfixes qui augmentent au crédit. Sans règle correspondante, un compte augmente au débit.</p>
        </div>
        <form v-if="canManageChart" class="inline-editor" @submit.prevent="saveRule">
          <label>Préfixe<input v-model.trim="ruleForm.account_prefix" inputmode="numeric" required placeholder="2"></label>
          <label>Augmente au<select v-model="ruleForm.increase_side"><option value="credit">Crédit (droite)</option><option value="debit">Débit (gauche)</option></select></label>
          <label>Libellé<input v-model.trim="ruleForm.label" placeholder="Passifs"></label>
          <div class="editor-actions"><button class="btn btn-primary" :disabled="busy === 'rule'">{{ ruleForm.id ? 'Modifier' : 'Ajouter' }}</button><button v-if="ruleForm.id" type="button" class="btn btn-outline-secondary" @click="resetRule">Annuler</button></div>
        </form>
        <div class="table-responsive">
          <table><thead><tr><th>Préfixe</th><th>Débit</th><th>Crédit</th><th>Libellé</th><th></th></tr></thead>
            <tbody><tr v-for="rule in structure.rules" :key="rule.id"><td class="account-no">{{ rule.account_prefix }}…</td><td>{{ rule.increase_side === 'debit' ? 'Augmente +' : 'Diminue −' }}</td><td>{{ rule.increase_side === 'credit' ? 'Augmente +' : 'Diminue −' }}</td><td>{{ rule.label || '—' }}</td><td class="row-actions"><button v-if="canManageChart" @click="editRule(rule)">Modifier</button><button v-if="canManageChart" class="danger" @click="remove('rules', rule.id)">Retirer</button></td></tr></tbody>
          </table>
        </div>
      </section>

      <section class="accounting-card">
        <div class="section-heading">
          <div><span>2</span><h2>Rubriques par préfixe</h2></div>
          <p>Le préfixe le plus précis classe le compte : 1020 appartient par exemple à la rubrique 10 « Liquidités ».</p>
        </div>
        <form v-if="canManageChart" class="inline-editor category-editor" @submit.prevent="saveCategory">
          <label>Préfixe<input v-model.trim="categoryForm.account_prefix" inputmode="numeric" required placeholder="10"></label>
          <label>Rubrique<input v-model.trim="categoryForm.label" required placeholder="Liquidités"></label>
          <label>Ordre<input v-model.number="categoryForm.sort_order" type="number"></label>
          <div class="editor-actions"><button class="btn btn-primary" :disabled="busy === 'category'">{{ categoryForm.id ? 'Modifier' : 'Ajouter' }}</button><button v-if="categoryForm.id" type="button" class="btn btn-outline-secondary" @click="resetCategory">Annuler</button></div>
        </form>
        <div class="category-grid">
          <article v-for="category in structure.categories" :key="category.id">
            <strong class="account-no">{{ category.account_prefix }}…</strong><span>{{ category.label }}</span><small>{{ category.account_count || 0 }} compte(s)</small>
            <div v-if="canManageChart" class="row-actions"><button @click="editCategory(category)">Modifier</button><button class="danger" @click="remove('categories', category.id)">Retirer</button></div>
          </article>
        </div>
      </section>

      <section class="accounting-card">
        <div class="section-heading">
          <div><span>3</span><h2>Comptes</h2></div>
          <p>Le numéro est unique dans le plan. L’identifiant interne reste stable si le numéro ou le libellé change.</p>
        </div>
        <form v-if="canManageChart" class="inline-editor account-editor" @submit.prevent="saveAccount">
          <label>Numéro<input v-model.trim="accountForm.account_number" inputmode="numeric" required placeholder="1000"></label>
          <label>Libellé<input v-model.trim="accountForm.label" required placeholder="Caisse"></label>
          <label class="check"><input v-model="accountForm.is_active" type="checkbox"> Compte actif</label>
          <div class="editor-actions"><button class="btn btn-primary" :disabled="busy === 'account'">{{ accountForm.id ? 'Modifier' : 'Ajouter' }}</button><button v-if="accountForm.id" type="button" class="btn btn-outline-secondary" @click="resetAccount">Annuler</button></div>
        </form>
        <div class="table-responsive">
          <table><thead><tr><th>Numéro</th><th>Libellé</th><th>Rubrique</th><th>Augmentation</th><th>État</th><th></th></tr></thead>
            <tbody><tr v-for="account in structure.accounts" :key="account.id" :class="{ muted: !account.is_active }"><td class="account-no">{{ account.account_number }}</td><td>{{ account.label }}</td><td>{{ account.category?.label || 'Non classé' }}</td><td>{{ account.normal_side === 'credit' ? 'Crédit → +' : 'Débit → +' }}</td><td>{{ account.is_active ? 'Actif' : 'Archivé' }}</td><td class="row-actions"><button v-if="canManageChart" @click="editAccount(account)">Modifier</button><button v-if="canManageChart" class="danger" @click="remove('accounts', account.id)">Retirer</button></td></tr></tbody>
          </table>
        </div>
      </section>
    </template>

    <template v-if="structure && activeTab === 'opening'">
      <section class="accounting-card">
        <div class="section-heading">
          <div><span>4</span><h2>Exercice et montants d’ouverture</h2></div>
          <p>Un montant positif est placé du côté normal du compte. Exemple : 100.00 augmente la Caisse au débit, mais les Fournisseurs au crédit.</p>
        </div>
        <div class="period-toolbar">
          <label>Exercice<select v-model.number="selectedPeriodId"><option v-for="period in structure.fiscal_periods" :key="period.id" :value="period.id">{{ period.label }} · {{ period.starts_on }} — {{ period.ends_on }}</option></select></label>
        </div>
        <form v-if="canManageOpening" class="inline-editor period-editor" @submit.prevent="createPeriod">
          <label>Code<input v-model.trim="periodForm.code" required placeholder="2027"></label>
          <label>Libellé<input v-model.trim="periodForm.label" required placeholder="Exercice 2027"></label>
          <label>Du<input v-model="periodForm.starts_on" type="date" required></label>
          <label>Au<input v-model="periodForm.ends_on" type="date" required></label>
          <button class="btn btn-outline-primary" :disabled="busy === 'period'">Créer l’exercice</button>
        </form>
        <div class="opening-grid">
          <label v-for="account in structure.accounts.filter(item => item.is_active)" :key="account.id">
            <span><strong class="account-no">{{ account.account_number }}</strong> {{ account.label }}<small>{{ account.normal_side === 'credit' ? 'crédit +' : 'débit +' }}</small></span>
            <span class="money-field"><input v-model="openingAmounts[account.id]" inputmode="decimal" :disabled="!canManageOpening"><em>{{ structure.chart.currency }}</em></span>
          </label>
        </div>
        <button v-if="canManageOpening" class="btn btn-primary save-opening" :disabled="busy === 'opening'" @click="saveOpening">Enregistrer les soldes d’ouverture</button>
      </section>
    </template>
  </main>
</template>

<style scoped>
.accounting-page{max-width:1280px;margin:0 auto;padding:1.5rem}.accounting-hero{display:flex;justify-content:space-between;gap:2rem;align-items:flex-start;padding:1.5rem 0}.accounting-hero h1{font-size:2rem;margin:.15rem 0}.accounting-hero p{max-width:760px;color:var(--bs-secondary-color);margin:0}.eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.75rem;font-weight:700;color:#57705a!important}.currency{padding:.45rem .75rem;border:1px solid #ccd8cd;border-radius:999px;font-weight:700;color:#35523a;background:#f3f7f3}.accounting-tabs{display:flex;gap:.35rem;border-bottom:1px solid #dfe5df;margin-bottom:1.25rem}.accounting-tabs button{border:0;background:transparent;padding:.8rem 1rem;color:#5d675e;border-bottom:3px solid transparent}.accounting-tabs button.active{color:#234a2d;border-color:#477c52;font-weight:700}.accounting-card{background:var(--bs-body-bg);border:1px solid #dfe5df;border-radius:14px;padding:1.25rem;margin-bottom:1.25rem;box-shadow:0 4px 16px rgba(31,53,35,.04)}.section-heading{display:flex;justify-content:space-between;gap:2rem;align-items:flex-start;margin-bottom:1rem}.section-heading>div{display:flex;align-items:center;gap:.65rem}.section-heading span{width:2rem;height:2rem;border-radius:50%;display:grid;place-items:center;background:#e7f0e8;color:#315b38;font-weight:800}.section-heading h2{font-size:1.15rem;margin:0}.section-heading p{max-width:620px;margin:0;color:var(--bs-secondary-color);font-size:.92rem}.inline-editor{display:grid;grid-template-columns:minmax(110px,.6fr) minmax(180px,1fr) minmax(180px,1fr) auto;gap:.75rem;align-items:end;background:#f7f9f7;border-radius:10px;padding:1rem;margin-bottom:1rem}.inline-editor label,.period-toolbar label{display:grid;gap:.3rem;font-size:.78rem;font-weight:700;color:#59615a}.inline-editor input,.inline-editor select,.period-toolbar select,.money-field input{width:100%;border:1px solid #cdd6ce;border-radius:7px;background:var(--bs-body-bg);color:var(--bs-body-color);padding:.55rem .65rem;font-weight:400}.editor-actions{display:flex;gap:.4rem}.check{display:flex!important;align-items:center;gap:.5rem;padding-bottom:.6rem}.check input{width:auto}.table-responsive{overflow:auto}table{width:100%;border-collapse:collapse;font-size:.9rem}th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#707a71;background:#f7f9f7}th,td{padding:.72rem;border-bottom:1px solid #e5e9e5;text-align:left;white-space:nowrap}.account-no{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:800;color:#294d31}.row-actions{display:flex;gap:.65rem;justify-content:flex-end}.row-actions button{border:0;background:transparent;color:#3c6844;padding:0;font-size:.8rem}.row-actions button.danger{color:#a43c3c}.muted{opacity:.55}.category-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.65rem}.category-grid article{display:grid;grid-template-columns:auto 1fr;gap:.25rem .6rem;border:1px solid #e1e7e1;border-radius:9px;padding:.8rem}.category-grid small{grid-column:2;color:#778078}.category-grid .row-actions{grid-column:1/-1;margin-top:.35rem}.period-toolbar{max-width:480px;margin-bottom:1rem}.period-editor{grid-template-columns:.6fr 1.2fr 1fr 1fr auto}.opening-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:.55rem 1rem}.opening-grid>label{display:flex;justify-content:space-between;align-items:center;gap:1rem;border-bottom:1px solid #e8ece8;padding:.55rem}.opening-grid small{display:block;color:#7b847c;font-size:.72rem;margin-left:3.6rem}.money-field{display:flex;align-items:center}.money-field input{width:110px;text-align:right;border-radius:7px 0 0 7px}.money-field em{font-style:normal;background:#eef2ee;border:1px solid #cdd6ce;border-left:0;padding:.55rem;border-radius:0 7px 7px 0;font-size:.78rem}.save-opening{margin-top:1.25rem}.accounting-loading{padding:2rem;text-align:center;color:#6d776e}@media(max-width:850px){.accounting-page{padding:1rem}.section-heading{display:block}.section-heading p{margin-top:.7rem}.inline-editor,.period-editor{grid-template-columns:1fr}.accounting-hero{display:block}.currency{display:inline-block;margin-top:1rem}.opening-grid{grid-template-columns:1fr}}
</style>
