<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useI18n } from '@/i18n';

type Row = Record<string, unknown>;
type StockItem = Row & {
  id: number; sellable_id: number; stock_location_id: number; sku?: string; barcode?: string;
  product_name?: string; variant_name?: string; location_name?: string; location_code?: string;
  on_hand_quantity: number; reserved_quantity: number; available_quantity: number;
  availability_status?: string; low_stock?: boolean; last_available?: boolean; inconsistent?: boolean;
};
type Location = { id: number; code: string; name: string; location_type: string };
type Summary = { item_count?: number; out_of_stock_count?: number; low_stock_count?: number; on_hand_quantity?: number; reserved_quantity?: number; available_quantity?: number };

const { t, dateTime } = useI18n();
const storageKey = 'amcms.sale.stock.filters.v1';
const saved = (() => { try { return JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch { return {}; } })();
const filters = ref({ q: String(saved.q || ''), location_id: Number(saved.location_id || 0), alert: String(saved.alert || '') });
const items = ref<StockItem[]>([]);
const locations = ref<Location[]>([]);
const movements = ref<Row[]>([]);
const summary = ref<Summary>({});
const selected = ref<StockItem | null>(null);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const wizardOpen = ref(false);
const wizardStep = ref(1);
const movement = ref({ item_id: 0, location_id: 0, movement_type: 'receipt', quantity: 1, reason: '' });

const selectedWizardItem = computed(() => items.value.find((item) => item.id === movement.value.item_id) || selected.value);
const signedQuantity = computed(() => movement.value.movement_type === 'issue' ? -Math.abs(Number(movement.value.quantity || 0)) : Number(movement.value.quantity || 0));
const beforeQuantity = computed(() => Number(selectedWizardItem.value?.on_hand_quantity || 0));
const afterQuantity = computed(() => beforeQuantity.value + signedQuantity.value);
const canContinue = computed(() => {
  if (wizardStep.value === 1) return Number(movement.value.item_id) > 0 && Number(movement.value.location_id) > 0;
  if (wizardStep.value === 2) return signedQuantity.value !== 0 && afterQuantity.value >= 0;
  if (wizardStep.value === 3) return movement.value.reason.trim().length >= 3;
  return true;
});

async function loadItems(): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<{ items: StockItem[]; locations: Location[]; summary: Summary }>('/sale/stock/items', { ...filters.value, limit: 100 });
    items.value = response.data.items || [];
    locations.value = response.data.locations || [];
    summary.value = response.data.summary || {};
    if (selected.value) selected.value = items.value.find((item) => item.id === selected.value?.id) || null;
  } catch (err) { error.value = apiErrorMessage(err); }
  finally { loading.value = false; }
}

async function selectItem(item: StockItem): Promise<void> {
  selected.value = item; error.value = '';
  try {
    const response = await adminApi.get<{ movements: Row[] }>('/sale/stock/movements', { inventory_item_id: item.id, limit: 50 });
    movements.value = response.data.movements || [];
  } catch (err) { error.value = apiErrorMessage(err); }
}

function openWizard(item?: StockItem): void {
  const target = item || selected.value || items.value[0];
  movement.value = { item_id: Number(target?.id || 0), location_id: Number(target?.stock_location_id || locations.value[0]?.id || 0), movement_type: 'receipt', quantity: 1, reason: '' };
  wizardStep.value = 1; wizardOpen.value = true; error.value = ''; notice.value = '';
}

function nextStep(): void { if (canContinue.value) wizardStep.value = Math.min(5, wizardStep.value + 1); }
function previousStep(): void { wizardStep.value = Math.max(1, wizardStep.value - 1); }

async function confirmMovement(): Promise<void> {
  const item = selectedWizardItem.value;
  if (!item || !canContinue.value || afterQuantity.value < 0) return;
  saving.value = true; error.value = '';
  try {
    await adminApi.post('/sale/stock/adjustments', {
      sellable_id: item.sellable_id,
      location_id: movement.value.location_id,
      movement_type: movement.value.movement_type,
      quantity_delta: signedQuantity.value,
      reason: movement.value.reason.trim(),
      idempotency_key: `manual-stock:${crypto.randomUUID()}`
    });
    notice.value = t('sale.stock.movementCreated');
    wizardOpen.value = false;
    await loadItems();
    const refreshed = items.value.find((candidate) => candidate.id === item.id);
    if (refreshed) await selectItem(refreshed);
  } catch (err) { error.value = apiErrorMessage(err); }
  finally { saving.value = false; }
}

function resetFilters(): void { filters.value = { q: '', location_id: 0, alert: '' }; void loadItems(); }
function exportMovements(): void { window.location.assign(adminApi.href('/sale/export/stock-movements.csv', { ...filters.value })); }
function referenceLabel(row: Row): string { return row.reference_type ? `${row.reference_type}${row.reference_id ? ` #${row.reference_id}` : ''}` : t('common.none'); }
function statusLabel(status?: string): string { return t(`sale.stock.status.${status || 'unavailable'}` as never); }

watch(filters, (value) => localStorage.setItem(storageKey, JSON.stringify(value)), { deep: true });
watch(() => movement.value.item_id, () => {
  const item = items.value.find((candidate) => candidate.id === movement.value.item_id);
  if (item) movement.value.location_id = item.stock_location_id;
});
onMounted(loadItems);
</script>

<template>
  <section class="stock-workspace">
    <header class="stock-heading">
      <div><p>{{ t('sale.title') }}</p><h2>{{ t('sale.stock.title') }}</h2><span>{{ t('sale.stock.intro') }}</span></div>
      <div class="stock-actions"><button class="btn btn-outline-secondary" type="button" @click="exportMovements">{{ t('sale.stock.export') }}</button><button class="btn btn-primary" type="button" :disabled="!items.length" @click="openWizard()">{{ t('sale.stock.newMovement') }}</button></div>
    </header>

    <div v-if="notice" class="alert alert-success">{{ notice }}</div>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>

    <div class="stock-metrics" aria-label="Résumé du stock">
      <article><span>{{ t('sale.stock.physical') }}</span><strong>{{ Number(summary.on_hand_quantity || 0) }}</strong></article>
      <article><span>{{ t('sale.stock.reserved') }}</span><strong>{{ Number(summary.reserved_quantity || 0) }}</strong></article>
      <article><span>{{ t('sale.stock.available') }}</span><strong>{{ Number(summary.available_quantity || 0) }}</strong></article>
      <article :class="{ warning: Number(summary.out_of_stock_count || 0) > 0 }"><span>{{ t('sale.stock.alerts') }}</span><strong>{{ Number(summary.out_of_stock_count || 0) + Number(summary.low_stock_count || 0) }}</strong></article>
    </div>

    <form class="stock-filters" @submit.prevent="loadItems">
      <label><span>{{ t('sale.stock.search') }}</span><input v-model="filters.q" class="form-control" type="search" :placeholder="t('sale.stock.searchPlaceholder')"></label>
      <label><span>{{ t('sale.stock.location') }}</span><select v-model.number="filters.location_id" class="form-select"><option :value="0">{{ t('common.all') }}</option><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select></label>
      <label><span>{{ t('sale.stock.alert') }}</span><select v-model="filters.alert" class="form-select"><option value="">{{ t('common.all') }}</option><option value="out_of_stock">{{ t('sale.stock.outOfStock') }}</option><option value="low_stock">{{ t('sale.stock.lowStock') }}</option><option value="inconsistent">{{ t('sale.stock.inconsistent') }}</option></select></label>
      <button class="btn btn-primary" type="submit" :disabled="loading">{{ t('common.apply') }}</button><button class="btn btn-outline-secondary" type="button" @click="resetFilters">{{ t('common.reset') }}</button>
    </form>

    <div class="stock-layout">
      <section class="stock-list" :aria-busy="loading">
        <p v-if="!items.length && !loading" class="stock-empty">{{ t('sale.stock.empty') }}</p>
        <button v-for="item in items" :key="item.id" type="button" :class="['stock-row', { active: selected?.id === item.id }]" @click="selectItem(item)">
          <span class="stock-identity"><b>{{ item.product_name || item.variant_name || item.sku }}</b><small>{{ item.sku }}<template v-if="item.barcode"> · {{ item.barcode }}</template></small></span>
          <span><small>{{ t('sale.stock.location') }}</small><b>{{ item.location_name || item.location_code }}</b></span>
          <span><small>{{ t('sale.stock.physical') }}</small><b>{{ item.on_hand_quantity }}</b></span>
          <span><small>{{ t('sale.stock.reserved') }}</small><b>{{ item.reserved_quantity }}</b></span>
          <span><small>{{ t('sale.stock.available') }}</small><b>{{ item.available_quantity }}</b></span>
          <span :class="['stock-status', item.availability_status]">{{ statusLabel(item.availability_status) }}<em v-if="item.last_available">{{ t('sale.stock.lastAvailable') }}</em><em v-if="item.inconsistent">{{ t('sale.stock.inconsistent') }}</em></span>
        </button>
      </section>

      <aside v-if="selected" class="stock-detail">
        <div class="stock-detail-head"><div><p>{{ selected.sku }}</p><h3>{{ selected.product_name || selected.variant_name }}</h3><span>{{ selected.location_name }}</span></div><button class="btn btn-sm btn-primary" type="button" @click="openWizard(selected)">{{ t('sale.stock.newMovement') }}</button></div>
        <div class="stock-detail-summary"><span>{{ t('sale.stock.physical') }} <b>{{ selected.on_hand_quantity }}</b></span><span>{{ t('sale.stock.reserved') }} <b>{{ selected.reserved_quantity }}</b></span><span>{{ t('sale.stock.available') }} <b>{{ selected.available_quantity }}</b></span></div>
        <h4>{{ t('sale.stock.recentMovements') }}</h4>
        <p v-if="!movements.length" class="text-muted">{{ t('sale.stock.noMovements') }}</p>
        <article v-for="row in movements" :key="String(row.id)" class="stock-movement">
          <span :class="Number(row.quantity) > 0 ? 'positive' : 'negative'">{{ Number(row.quantity) > 0 ? '+' : '' }}{{ row.quantity }}</span>
          <div><b>{{ t(`sale.stock.type.${String(row.movement_type)}` as never) }}</b><small>{{ row.reason || referenceLabel(row) }} · {{ dateTime(row.created_at) }}</small><a v-if="row.reference_type && row.reference_id" :href="`#${row.reference_type}-${row.reference_id}`">{{ referenceLabel(row) }}</a></div>
        </article>
        <details><summary>{{ t('sale.stock.technical') }}</summary><pre>{{ JSON.stringify(selected, null, 2) }}</pre></details>
      </aside>
    </div>

    <div v-if="wizardOpen" class="stock-modal" role="dialog" aria-modal="true" :aria-label="t('sale.stock.newMovement')">
      <section>
        <header><div><small>{{ t('sale.stock.step', { step: wizardStep }) }}</small><h3>{{ t(`sale.stock.wizard.step${wizardStep}` as never) }}</h3></div><button type="button" :aria-label="t('common.close')" @click="wizardOpen=false">×</button></header>
        <div v-if="wizardStep===1" class="stock-wizard-fields"><label>{{ t('sale.stock.item') }}<select v-model.number="movement.item_id" class="form-select"><option v-for="item in items" :key="item.id" :value="item.id">{{ item.product_name || item.sku }} · {{ item.location_name }}</option></select></label><label>{{ t('sale.stock.location') }}<select v-model.number="movement.location_id" class="form-select"><option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option></select></label></div>
        <div v-if="wizardStep===2" class="stock-wizard-fields"><label>{{ t('sale.stock.movementType') }}<select v-model="movement.movement_type" class="form-select"><option value="receipt">{{ t('sale.stock.type.receipt') }}</option><option value="issue">{{ t('sale.stock.type.issue') }}</option><option value="adjustment">{{ t('sale.stock.type.adjustment') }}</option><option value="correction">{{ t('sale.stock.type.correction') }}</option></select></label><label>{{ t('sale.stock.quantity') }}<input v-model.number="movement.quantity" class="form-control" type="number" step="1" required></label><p v-if="afterQuantity<0" class="text-danger">{{ t('sale.stock.quantityError') }}</p></div>
        <label v-if="wizardStep===3">{{ t('sale.stock.reason') }}<textarea v-model="movement.reason" class="form-control" rows="4" required></textarea></label>
        <div v-if="wizardStep>=4" class="stock-preview"><span>{{ t('sale.stock.before') }} <b>{{ beforeQuantity }}</b></span><span aria-hidden="true">→</span><span>{{ t('sale.stock.after') }} <b>{{ afterQuantity }}</b></span><p>{{ t(`sale.stock.type.${movement.movement_type}` as never) }} · {{ movement.reason }}</p></div>
        <p v-if="wizardStep===5">{{ t('sale.stock.confirmHelp') }}</p>
        <footer><button class="btn btn-outline-secondary" type="button" :disabled="wizardStep===1" @click="previousStep">{{ t('common.previous') }}</button><button v-if="wizardStep<5" class="btn btn-primary" type="button" :disabled="!canContinue" @click="nextStep">{{ t('common.next') }}</button><button v-else class="btn btn-primary" type="button" :disabled="saving || !canContinue" @click="confirmMovement">{{ t('sale.stock.confirm') }}</button></footer>
      </section>
    </div>
  </section>
</template>

<style scoped>
.stock-workspace{display:grid;gap:1rem}.stock-heading,.stock-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}.stock-heading p,.stock-detail-head p{margin:0;color:#6366f1;font-weight:700;text-transform:uppercase;font-size:.75rem}.stock-heading h2,.stock-detail-head h3{margin:.2rem 0}.stock-actions{display:flex;gap:.5rem;flex-wrap:wrap}.stock-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}.stock-metrics article{padding:1rem;border:1px solid #e2e8f0;border-radius:.85rem;background:#fff;display:grid}.stock-metrics span,.stock-row small{color:#64748b}.stock-metrics strong{font-size:1.65rem}.stock-metrics .warning{border-color:#fb923c;background:#fff7ed}.stock-filters{display:grid;grid-template-columns:minmax(14rem,2fr) 1fr 1fr auto auto;align-items:end;gap:.65rem}.stock-filters label,.stock-wizard-fields label{display:grid;gap:.3rem}.stock-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(18rem,.7fr);gap:1rem}.stock-list,.stock-detail{border:1px solid #e2e8f0;border-radius:.85rem;background:#fff;overflow:hidden}.stock-row{width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;padding:.8rem;text-align:left;display:grid;grid-template-columns:minmax(10rem,1.8fr) repeat(4,minmax(4rem,.7fr)) minmax(7rem,1fr);gap:.7rem;align-items:center}.stock-row:hover,.stock-row.active{background:#f8fafc}.stock-row span:not(.stock-status){display:grid}.stock-status{display:grid;justify-items:start;gap:.2rem;font-weight:700}.stock-status em{font-size:.68rem;background:#ffedd5;color:#9a3412;padding:.1rem .35rem;border-radius:1rem}.stock-status.in_stock{color:#15803d}.stock-status.unavailable{color:#b91c1c}.stock-status.backorder{color:#c2410c}.stock-detail{padding:1rem}.stock-detail-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin:1rem 0}.stock-detail-summary span{display:grid;background:#f8fafc;padding:.65rem;border-radius:.6rem}.stock-movement{display:grid;grid-template-columns:3rem 1fr;gap:.7rem;padding:.65rem 0;border-bottom:1px solid #eef2f7}.stock-movement>span{font-size:1.1rem;font-weight:800}.stock-movement .positive{color:#15803d}.stock-movement .negative{color:#b91c1c}.stock-movement div{display:grid}.stock-movement small{color:#64748b}.stock-movement a{font-size:.8rem}.stock-empty{padding:2rem;text-align:center;color:#64748b}.stock-modal{position:fixed;inset:0;z-index:1200;background:#0f172a88;display:grid;place-items:center;padding:1rem}.stock-modal>section{background:#fff;width:min(36rem,100%);border-radius:1rem;padding:1.2rem;box-shadow:0 24px 70px #0f172a55}.stock-modal header,.stock-modal footer{display:flex;justify-content:space-between;align-items:center;gap:.7rem}.stock-modal header button{border:0;background:none;font-size:1.8rem}.stock-wizard-fields{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1rem 0}.stock-preview{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:1rem;margin:1.5rem 0;text-align:center}.stock-preview span{padding:1rem;background:#f8fafc;border-radius:.7rem}.stock-preview p{grid-column:1/-1}.stock-modal footer{margin-top:1.25rem;justify-content:flex-end}details{margin-top:1rem}pre{max-height:14rem;overflow:auto;font-size:.72rem}
@media(max-width:900px){.stock-metrics{grid-template-columns:repeat(2,1fr)}.stock-filters{grid-template-columns:1fr 1fr}.stock-layout{grid-template-columns:1fr}.stock-row{grid-template-columns:1fr 1fr}.stock-identity,.stock-status{grid-column:1/-1}.stock-detail{order:-1}.stock-heading{display:grid}.stock-wizard-fields{grid-template-columns:1fr}}
</style>
