<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n } from '@/i18n';

type StockItem = Record<string, unknown> & {
  id: number;
  business_variant_id: number;
  sku?: string;
  product_name?: string;
  variant_name?: string;
  location_name?: string;
  on_hand_quantity?: number;
  reserved_quantity?: number;
  available_quantity?: number;
  currency?: string;
  physical_purchase_value_minor?: number | null;
  reserved_sale_value_minor?: number;
  available_sale_value_minor?: number;
};

const context = useAdminContextStore();
const { money, n } = useI18n();
const items = ref<StockItem[]>([]);
const locations = ref<Array<Record<string, unknown>>>([]);
const summary = ref<Record<string, unknown>>({});
const query = ref('');
const locationId = ref(0);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const selected = ref<StockItem | null>(null);
const quantityDelta = ref(0);
const reason = ref('');
const preview = ref<Record<string, any> | null>(null);
const canWrite = computed(() => context.can('business.catalog.stock.write'));
const canReadPurchase = computed(() => context.can('business.catalog.purchase_prices.read'));

function quantity(value: unknown): string {
  return `${n(Number(value || 0), { maximumFractionDigits: 2 })} u.`;
}

function amountWithoutCurrency(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  return n(Number(value) / 100, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function stockValue(quantityValue: unknown, amountMinor: unknown, showAmount = true): string {
  const units = quantity(quantityValue);
  return showAmount ? `${units} / ${amountWithoutCurrency(amountMinor)}` : units;
}

function productLabel(item: StockItem): string {
  const product = String(item.product_name || '').trim();
  const variant = String(item.variant_name || '').trim();
  if (product && variant && product !== variant) return `${product} / ${variant}`;
  return product || variant || String(item.sku || '—');
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ items: StockItem[]; locations: Array<Record<string, unknown>>; summary: Record<string, unknown> }>('/business/catalog/inventory', {
      q: query.value.trim(), location_id: locationId.value || undefined, limit: 100,
    });
    items.value = response.data.items || [];
    locations.value = response.data.locations || [];
    summary.value = response.data.summary || {};
  } catch (err) { error.value = apiErrorMessage(err); }
  finally { loading.value = false; }
}

function openVariation(item: StockItem): void {
  selected.value = item;
  quantityDelta.value = 0;
  reason.value = '';
  preview.value = null;
  notice.value = '';
}

async function previewVariation(): Promise<void> {
  if (!selected.value || quantityDelta.value === 0 || !reason.value.trim()) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ preview?: Record<string, any> }>(`/business/catalog/variants/${selected.value.business_variant_id}/stock-movements`, {
      action: 'correction', quantity: Number(quantityDelta.value), reason: reason.value.trim(),
      stock_location_id: Number(selected.value.stock_location_id || 0), preview: true,
    });
    preview.value = response.data.preview || null;
  } catch (err) { error.value = apiErrorMessage(err); }
  finally { saving.value = false; }
}

async function confirmVariation(): Promise<void> {
  if (!selected.value || !preview.value || preview.value.blocked) return;
  saving.value = true;
  error.value = '';
  try {
    await adminApi.post(`/business/catalog/variants/${selected.value.business_variant_id}/stock-movements`, {
      action: 'correction', quantity: Number(quantityDelta.value), reason: reason.value.trim(),
      stock_location_id: Number(selected.value.stock_location_id || 0), preview: false,
      idempotency_key: `operations-inventory-${selected.value.id}-${crypto.randomUUID()}`,
    });
    selected.value = null;
    preview.value = null;
    notice.value = 'Variation de stock enregistrée dans le registre unifié.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err); }
  finally { saving.value = false; }
}

watch(() => context.siteId, load);
onMounted(load);
</script>

<template>
  <section class="operations-inventory">
    <ApiFeedback :success="notice" :error="error" />

    <div class="operations-inventory__metrics">
      <article><span>Stock physique</span><strong>{{ quantity(summary.on_hand_quantity) }}</strong><small v-if="canReadPurchase">{{ money(summary.physical_purchase_value_minor, summary.currency) }} au prix d’achat</small></article>
      <article><span>Engagé</span><strong>{{ quantity(summary.reserved_quantity) }}</strong><small>{{ money(summary.reserved_sale_value_minor, summary.currency) }} au prix de vente</small></article>
      <article><span>Disponible</span><strong>{{ quantity(summary.available_quantity) }}</strong><small>{{ money(summary.available_sale_value_minor, summary.currency) }} au prix de vente</small></article>
      <article><span>Lignes de stock</span><strong>{{ Number(summary.item_count || 0) }}</strong></article>
    </div>

    <form class="operations-inventory__filters" @submit.prevent="load">
      <input v-model="query" class="form-control" type="search" placeholder="Produit, variante, SKU ou emplacement" aria-label="Rechercher dans l’inventaire">
      <select v-model.number="locationId" class="form-select" aria-label="Emplacement"><option :value="0">Tous les emplacements</option><option v-for="location in locations" :key="Number(location.id)" :value="Number(location.id)">{{ location.name || location.code }}</option></select>
      <button class="btn btn-primary" type="submit" :disabled="loading">Appliquer</button>
    </form>

    <div class="operations-inventory__table-wrap">
      <table class="table align-middle">
        <thead><tr><th>Produit</th><th>SKU</th><th>Emplacement</th><th>Physique</th><th>Engagé</th><th>Disponible</th><th></th></tr></thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="7"><div class="operations-inventory__loading" role="status" aria-label="Calcul de l’inventaire en cours"><span aria-hidden="true"></span></div></td>
          </tr>
          <tr v-for="item in loading ? [] : items" :key="item.id">
            <td><strong>{{ productLabel(item) }}</strong></td>
            <td>{{ item.sku || '—' }}</td><td>{{ item.location_name || '—' }}</td>
            <td class="operations-inventory__stock-value">{{ stockValue(item.on_hand_quantity, item.physical_purchase_value_minor, canReadPurchase) }}</td>
            <td class="operations-inventory__stock-value">{{ stockValue(item.reserved_quantity, item.reserved_sale_value_minor) }}</td>
            <td class="operations-inventory__stock-value">{{ stockValue(item.available_quantity, item.available_sale_value_minor) }}</td>
            <td><button class="btn ghost btn-sm" type="button" :disabled="!canWrite" @click="openVariation(item)">Saisir une variation</button></td>
          </tr>
          <tr v-if="!items.length && !loading"><td colspan="7" class="text-muted">Aucun stock existant ne correspond aux filtres.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="selected" class="operations-inventory__modal-backdrop" role="presentation" @click.self="selected = null">
      <section class="operations-inventory__modal" role="dialog" aria-modal="true" aria-label="Variation de stock">
        <header><div><small>Variation auditée</small><h2>{{ selected.product_name || selected.sku }}</h2><p>{{ selected.location_name }} · stock actuel {{ Number(selected.on_hand_quantity || 0) }}</p></div><button type="button" aria-label="Fermer" @click="selected = null">×</button></header>
        <label>Variation<input v-model.number="quantityDelta" class="form-control" type="number" step="1" placeholder="Ex. +5 ou -2" @input="preview = null"><small>Saisissez uniquement la différence à ajouter ou à retirer, jamais le nouveau total.</small></label>
        <label>Motif obligatoire<textarea v-model="reason" class="form-control" rows="3" @input="preview = null"></textarea></label>
        <button class="btn btn-outline-primary" type="button" :disabled="saving || quantityDelta === 0 || !reason.trim()" @click="previewVariation">Prévisualiser</button>
        <div v-if="preview" class="operations-inventory__preview" :class="{ blocked: preview.blocked }"><span>Avant <b>{{ preview.before?.on_hand }}</b></span><span>→</span><span>Après <b>{{ preview.after?.on_hand }}</b></span><p>{{ preview.explanation }}</p></div>
        <footer><button class="btn btn-outline-secondary" type="button" @click="selected = null">Annuler</button><button class="btn btn-primary" type="button" :disabled="saving || !preview || preview.blocked" @click="confirmVariation">Confirmer la variation</button></footer>
      </section>
    </div>
  </section>
</template>

<style scoped>
.operations-inventory{display:grid;gap:1rem}.operations-inventory__metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}.operations-inventory__metrics article{background:#fff;border:1px solid #e2e8f0;border-radius:8px;display:grid;padding:1rem}.operations-inventory__metrics span,.operations-inventory__metrics small{color:#64748b}.operations-inventory__metrics strong{font-size:1.55rem}.operations-inventory__filters{display:grid;grid-template-columns:minmax(16rem,2fr) minmax(12rem,1fr) auto;gap:.65rem}.operations-inventory__table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:auto}.operations-inventory__table-wrap table{margin:0;min-width:64rem}.operations-inventory__table-wrap td{vertical-align:middle}.operations-inventory__stock-value{font-variant-numeric:tabular-nums;white-space:nowrap}.operations-inventory__loading{align-items:center;display:flex;justify-content:center;min-height:4rem}.operations-inventory__loading span{animation:operations-inventory-spin .75s linear infinite;border:3px solid #dbeafe;border-radius:50%;border-top-color:#2563eb;height:1.35rem;width:1.35rem}@keyframes operations-inventory-spin{to{transform:rotate(360deg)}}.operations-inventory__modal-backdrop{background:#0f172a88;display:grid;inset:0;overflow-y:auto;padding:1rem;place-items:center;position:fixed;z-index:1100}.operations-inventory__modal{background:#fff;border-radius:8px;box-shadow:0 24px 70px #0f172a55;display:grid;gap:1rem;padding:1.25rem;width:min(36rem,100%)}.operations-inventory__modal header,.operations-inventory__modal footer{align-items:flex-start;display:flex;gap:1rem;justify-content:space-between}.operations-inventory__modal header h2,.operations-inventory__modal header p{margin:.15rem 0}.operations-inventory__modal header button{background:transparent;border:0;font-size:1.75rem}.operations-inventory__modal label{display:grid;gap:.35rem;font-weight:700}.operations-inventory__modal label small{color:#64748b;font-weight:400}.operations-inventory__modal footer{justify-content:flex-end}.operations-inventory__preview{align-items:center;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;display:grid;gap:.7rem;grid-template-columns:1fr auto 1fr;padding:.8rem;text-align:center}.operations-inventory__preview p{grid-column:1/-1;margin:0}.operations-inventory__preview.blocked{background:#fef2f2;border-color:#fecaca}@media(max-width:800px){.operations-inventory__metrics{grid-template-columns:repeat(2,1fr)}.operations-inventory__filters{grid-template-columns:1fr}}
</style>
