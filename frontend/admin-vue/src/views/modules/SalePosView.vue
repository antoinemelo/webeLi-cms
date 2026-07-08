<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';

type PosVariant = Record<string, unknown> & {
  business_variant_id: number;
  sku?: string;
  barcode?: string | null;
  product_name?: string;
  variant_name?: string;
  unit_price_minor?: number;
  currency?: string;
};
type PosCartLine = Record<string, unknown> & { id: number; quantity: number; product_name?: string; sku?: string; line_total_minor?: number };
type PosCart = Record<string, unknown> & { id: number; grand_total_minor?: number; currency?: string; lines?: PosCartLine[] };
type PosSession = Record<string, unknown> & { id: number; status?: string; expected_cash_minor?: number; currency?: string };

const loading = ref(false);
const saving = ref(false);
const notice = ref('');
const error = ref('');
const query = ref('');
const barcode = ref('');
const variants = ref<PosVariant[]>([]);
const cart = ref<PosCart | null>(null);
const session = ref<PosSession | null>(null);
const receipt = ref<Record<string, unknown> | null>(null);
const bootstrap = reactive<{ registers: Array<Record<string, unknown>>; channels: Array<Record<string, unknown>> }>({ registers: [], channels: [] });
const openingCash = ref(0);
const countedCash = ref(0);
const paymentMethod = ref<'cash' | 'manual_card' | 'external_terminal'>('cash');

const cartLines = computed(() => cart.value?.lines || []);
const canCheckout = computed(() => !!cart.value?.id && cartLines.value.length > 0 && (paymentMethod.value !== 'cash' || !!session.value?.id));

function money(minor?: unknown, currency?: unknown): string {
  const value = Number(minor || 0) / 100;
  return `${value.toFixed(2)} ${String(currency || 'CHF')}`;
}

async function loadBootstrap(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ registers: Array<Record<string, unknown>>; channels: Array<Record<string, unknown>>; active_session?: PosSession | null }>('/sale/pos/bootstrap');
    bootstrap.registers = response.data.registers || [];
    bootstrap.channels = response.data.channels || [];
    session.value = response.data.active_session || null;
    countedCash.value = Number(session.value?.expected_cash_minor || 0);
    await search();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    loading.value = false;
  }
}

async function search(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const params = barcode.value.trim()
      ? { barcode: barcode.value.trim(), limit: 30 }
      : { q: query.value.trim(), limit: 30 };
    const response = await adminApi.get<{ variants: PosVariant[] }>('/sale/pos/variants', params);
    variants.value = response.data.variants || [];
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    loading.value = false;
  }
}

async function openSession(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ session: PosSession }>('/sale/pos/sessions/open', { opening_cash_minor: Number(openingCash.value || 0) });
    session.value = response.data.session;
    countedCash.value = Number(session.value.expected_cash_minor || 0);
    notice.value = 'Session caisse ouverte.';
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function closeSession(): Promise<void> {
  if (!session.value?.id) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ session: PosSession }>(`/sale/pos/sessions/${session.value.id}/close`, { counted_cash_minor: Number(countedCash.value || 0) });
    session.value = response.data.session;
    notice.value = 'Session caisse fermée.';
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function ensureCart(): Promise<PosCart> {
  if (cart.value?.id) return cart.value;
  const response = await adminApi.post<{ cart: PosCart }>('/sale/pos/carts', {});
  cart.value = response.data.cart;
  return cart.value;
}

async function addVariant(variant: PosVariant): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    const current = await ensureCart();
    const response = await adminApi.post<{ cart: PosCart; line: PosCartLine }>(`/sale/pos/carts/${current.id}/lines`, {
      business_variant_id: variant.business_variant_id,
      quantity: 1,
      idempotency_key: `pos-${Date.now()}-${variant.business_variant_id}`
    });
    cart.value = response.data.cart;
    receipt.value = null;
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function updateLine(line: PosCartLine, quantity: number): Promise<void> {
  if (!cart.value?.id) return;
  saving.value = true;
  try {
    const response = await adminApi.patch<{ cart: PosCart }>(`/sale/pos/carts/${cart.value.id}/lines/${line.id}`, { quantity: Math.max(1, quantity) });
    cart.value = response.data.cart;
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

function updateLineFromInput(line: PosCartLine, event: Event): void {
  const target = event.target instanceof HTMLInputElement ? event.target : null;
  void updateLine(line, Number(target?.value || 1));
}

function printReceipt(): void {
  window.print();
}

async function checkout(): Promise<void> {
  if (!cart.value?.id) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ order: Record<string, unknown>; receipt: Record<string, unknown> }>('/sale/pos/checkout', {
      cart_id: cart.value.id,
      cash_session_id: session.value?.id,
      payment_method: paymentMethod.value,
      idempotency_key: `pos-checkout-${cart.value.id}`
    });
    receipt.value = response.data.receipt;
    cart.value = null;
    notice.value = `Vente ${String(response.data.order.order_number || '')} terminée.`;
    await loadBootstrap();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

onMounted(loadBootstrap);
</script>

<template>
  <section class="sale-pos">
    <header class="sale-pos__head">
      <div>
        <p class="text-uppercase text-muted small mb-1">Vente</p>
        <h1>POS</h1>
      </div>
      <div class="sale-pos__session">
        <span class="badge" :class="session?.status === 'open' ? 'text-bg-success' : 'text-bg-secondary'">
          {{ session?.status === 'open' ? 'Session ouverte' : 'Session fermée' }}
        </span>
        <button v-if="!session || session.status !== 'open'" class="btn btn-primary btn-sm" :disabled="saving" @click="openSession">Ouvrir</button>
        <button v-else class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="closeSession">Fermer</button>
      </div>
    </header>

    <div v-if="notice" class="alert alert-success py-2">{{ notice }}</div>
    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="sale-pos__layout">
      <main class="sale-pos__catalog">
        <div class="sale-pos__search">
          <input v-model="query" class="form-control" type="search" placeholder="Produit, SKU..." @keyup.enter="search">
          <input v-model="barcode" class="form-control" type="search" placeholder="Code-barres" @keyup.enter="search">
          <button class="btn btn-outline-primary" :disabled="loading" @click="search">Rechercher</button>
        </div>

        <div class="sale-pos__grid">
          <button v-for="variant in variants" :key="variant.business_variant_id" class="sale-pos__product" type="button" :disabled="saving" @click="addVariant(variant)">
            <strong>{{ variant.product_name }}</strong>
            <span>{{ variant.variant_name || variant.sku }}</span>
            <small>{{ variant.sku }}</small>
            <b>{{ money(variant.unit_price_minor, variant.currency) }}</b>
          </button>
        </div>
      </main>

      <aside class="sale-pos__cart">
        <h2>Panier</h2>
        <p v-if="!cartLines.length" class="text-muted">Aucun article.</p>
        <div v-for="line in cartLines" :key="line.id" class="sale-pos__line">
          <div>
            <strong>{{ line.product_name }}</strong>
            <small>{{ line.sku }}</small>
          </div>
          <input class="form-control form-control-sm" type="number" min="1" :value="line.quantity" @change="updateLineFromInput(line, $event)">
          <b>{{ money(line.line_total_minor, cart?.currency) }}</b>
        </div>

        <div class="sale-pos__total">
          <span>Total</span>
          <strong>{{ money(cart?.grand_total_minor, cart?.currency) }}</strong>
        </div>

        <label class="form-label mt-3">Paiement</label>
        <select v-model="paymentMethod" class="form-select">
          <option value="cash">Cash</option>
          <option value="manual_card">Carte manuelle</option>
          <option value="external_terminal">Terminal externe</option>
        </select>

        <div class="sale-pos__cash">
          <label>Fond initial<input v-model.number="openingCash" class="form-control form-control-sm" type="number" min="0"></label>
          <label>Compté<input v-model.number="countedCash" class="form-control form-control-sm" type="number" min="0"></label>
        </div>

        <button class="btn btn-success w-100 mt-3" :disabled="saving || !canCheckout" @click="checkout">Encaisser</button>

        <section v-if="receipt" class="sale-pos__receipt">
          <h3>Reçu</h3>
          <pre>{{ receipt.printable_text }}</pre>
          <button class="btn btn-outline-secondary btn-sm" @click="printReceipt">Imprimer</button>
        </section>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.sale-pos {
  padding: 1.25rem;
}
.sale-pos__head,
.sale-pos__session,
.sale-pos__search,
.sale-pos__line,
.sale-pos__total,
.sale-pos__cash {
  display: flex;
  gap: .75rem;
  align-items: center;
}
.sale-pos__head {
  justify-content: space-between;
  margin-bottom: 1rem;
}
.sale-pos__head h1 {
  margin: 0;
  font-size: 1.75rem;
}
.sale-pos__layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 1rem;
  align-items: start;
}
.sale-pos__catalog,
.sale-pos__cart {
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  padding: 1rem;
}
.sale-pos__search {
  margin-bottom: 1rem;
}
.sale-pos__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: .75rem;
}
.sale-pos__product {
  display: grid;
  gap: .25rem;
  text-align: left;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  padding: .75rem;
}
.sale-pos__product:hover {
  border-color: #0d6efd;
}
.sale-pos__product span,
.sale-pos__product small {
  color: #667085;
}
.sale-pos__line {
  grid-template-columns: minmax(0, 1fr) 72px auto;
  padding: .75rem 0;
  border-bottom: 1px solid #edf0f3;
}
.sale-pos__line div {
  display: grid;
}
.sale-pos__line small {
  color: #667085;
}
.sale-pos__total {
  justify-content: space-between;
  padding-top: 1rem;
  font-size: 1.15rem;
}
.sale-pos__cash {
  margin-top: 1rem;
}
.sale-pos__cash label {
  display: grid;
  gap: .25rem;
  font-size: .875rem;
  color: #667085;
}
.sale-pos__receipt {
  margin-top: 1rem;
  border-top: 1px solid #d0d5dd;
  padding-top: 1rem;
}
.sale-pos__receipt pre {
  white-space: pre-wrap;
  background: #f8f9fb;
  padding: .75rem;
  border-radius: 6px;
}
@media (max-width: 900px) {
  .sale-pos__layout {
    grid-template-columns: 1fr;
  }
  .sale-pos__head,
  .sale-pos__search {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
