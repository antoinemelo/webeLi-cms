<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useI18n } from '@/i18n';

type PosVariant = Record<string, unknown> & {
  sellable_id?: number;
  business_variant_id: number;
  sku?: string;
  barcode?: string | null;
  product_name?: string;
  variant_name?: string;
  sales_note?: string | null;
  variant_sales_note?: string | null;
  variant_options?: PosVariantOption[];
  option_values?: PosVariantOption[];
  product_type?: string;
  is_bundle?: boolean;
  regular_unit_price_minor?: number;
  unit_price_minor?: number;
  currency?: string;
  available_quantity?: number | string | null;
  stock_quantity?: number | string | null;
  stock_reserved?: number | string | null;
  track_stock?: boolean;
  metadata?: {
    available_quantity?: number | string | null;
    stock_quantity?: number | string | null;
    stock_reserved?: number | string | null;
    attributes?: {
      merged?: PosAttribute[];
      product?: PosAttribute[];
      variant?: PosAttribute[];
    };
  };
};
type PosAttribute = { code?: string; name?: string; value?: unknown; unit?: string | null };
type PosVariantOption = { option_code?: string; option_name?: string; value_code?: string; label?: string; value?: string };
type PosCartLine = Record<string, unknown> & { id: number; quantity: number; product_name?: string; sku?: string; line_total_minor?: number };
type PosAdjustment = Record<string, unknown> & { id?: number; adjustment_type?: string; amount_minor?: number };
type PosCart = Record<string, unknown> & { id: number; version?: number; subtotal_minor?: number; discount_total_minor?: number; grand_total_minor?: number; currency?: string; lines?: PosCartLine[]; adjustments?: PosAdjustment[] };
type PosSession = Record<string, unknown> & { id: number; register_id?: number; status?: string; expected_cash_minor?: number; currency?: string };
type PosPaymentMethod = Record<string, unknown> & { id: number; code: string; name: string; method_type: string };
type PosReceipt = Record<string, unknown> & { order_id?: number | string; order_number?: string | null; printable_text?: string };

defineProps<{ embedded?: boolean }>();

const { languageCode, t, money: formatMoney } = useI18n();

const loading = ref(false);
const saving = ref(false);
const notice = ref('');
const error = ref('');
const query = ref('');
const barcode = ref('');
const variants = ref<PosVariant[]>([]);
const bundles = ref<PosVariant[]>([]);
const cart = ref<PosCart | null>(null);
const session = ref<PosSession | null>(null);
const receipt = ref<PosReceipt | null>(null);
const bootstrap = reactive<{ registers: Array<Record<string, unknown>>; channels: Array<Record<string, unknown>> }>({ registers: [], channels: [] });
const openingCash = ref(0);
const selectedRegisterId = ref(0);
const countedCash = ref(0);
const closingJustification = ref('');
const cashMovementType = ref<'cash_in' | 'cash_out' | 'correction'>('cash_in');
const cashMovementAmount = ref(0);
const cashMovementReason = ref('');
const paidAmount = ref(0);
const adjustmentMode = ref<'amount' | 'percent'>('amount');
const adjustmentValue = ref(0);
const paymentMethod = ref('cash');
const paymentMethods = ref<PosPaymentMethod[]>([]);
const receiptEmail = ref('');
const receiptSending = ref(false);
const reprintReason = ref('');

const cartLines = computed(() => cart.value?.lines || []);
const catalogVariants = computed(() => variants.value.filter((variant) => !isBundle(variant)));
const cartAdjustments = computed(() => cart.value?.adjustments || []);
const cartSurchargeMinor = computed(() => cartAdjustments.value
  .filter((adjustment) => adjustment.adjustment_type === 'surcharge')
  .reduce((sum, adjustment) => sum + Number(adjustment.amount_minor || 0), 0));
const cartManualDiscountMinor = computed(() => cartAdjustments.value
  .filter((adjustment) => adjustment.adjustment_type !== 'surcharge')
  .reduce((sum, adjustment) => sum + Number(adjustment.amount_minor || 0), 0));
const cartDiscountMinor = computed(() => Number(cart.value?.discount_total_minor || 0));
const cartOfferDiscountMinor = computed(() => Math.max(0, cartDiscountMinor.value - cartManualDiscountMinor.value));
const openingCashMinor = computed(() => amountToMinor(openingCash.value));
const paidAmountMinor = computed(() => amountToMinor(paidAmount.value));
const paymentTotalMinor = computed(() => paidAmountMinor.value);
const remainingDueMinor = computed(() => Number(cart.value?.grand_total_minor || 0) - paymentTotalMinor.value);
const isOverpaid = computed(() => remainingDueMinor.value < 0);
const canCheckout = computed(() => !!cart.value?.id && cartLines.value.length > 0 && (paymentMethod.value !== 'cash' || !!session.value?.id));
const receiptText = computed(() => String(receipt.value?.printable_text || ''));
const receiptOrderId = computed(() => Number(receipt.value?.order_id || 0));

function money(minor?: unknown): string {
  return formatMoney(minor, cart.value?.currency || 'CHF');
}

function cardPrice(minor?: unknown): string {
  return formatMoney(minor, cart.value?.currency || 'CHF');
}

function amountToMinor(value: unknown): number {
  return Math.max(0, Math.round(Number(value || 0) * 100));
}

function numberValue(value: unknown): number {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}

async function loadBootstrap(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ registers: Array<Record<string, unknown>>; channels: Array<Record<string, unknown>>; active_session?: PosSession | null; payment_methods?: PosPaymentMethod[] }>('/sale/pos/bootstrap');
    bootstrap.registers = response.data.registers || [];
    bootstrap.channels = response.data.channels || [];
    session.value = response.data.active_session || null;
    paymentMethods.value = response.data.payment_methods || [];
    selectedRegisterId.value = Number(session.value?.register_id || selectedRegisterId.value || bootstrap.registers[0]?.id || 0);
    if (paymentMethods.value.length && !paymentMethods.value.some((method) => method.code === paymentMethod.value || method.method_type === paymentMethod.value)) {
      paymentMethod.value = paymentMethods.value[0].code;
    }
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
      ? { barcode: barcode.value.trim(), limit: 100, session_id: session.value?.id, register_id: selectedRegisterId.value || undefined }
      : { q: query.value.trim(), limit: 100, session_id: session.value?.id, register_id: selectedRegisterId.value || undefined };
    const response = await adminApi.get<{ variants: PosVariant[] }>('/sale/pos/variants', params);
    variants.value = response.data.variants || [];
    await loadBundles();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    loading.value = false;
  }
}

async function loadBundles(): Promise<void> {
  const params = barcode.value.trim()
    ? { barcode: barcode.value.trim(), product_type: 'bundle', limit: 24, session_id: session.value?.id, register_id: selectedRegisterId.value || undefined }
    : { q: query.value.trim(), product_type: 'bundle', limit: 24, session_id: session.value?.id, register_id: selectedRegisterId.value || undefined };
  const response = await adminApi.get<{ variants: PosVariant[] }>('/sale/pos/variants', params);
  bundles.value = response.data.variants || [];
}

async function openSession(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ session: PosSession }>('/sale/pos/sessions/open', { register_id: selectedRegisterId.value, opening_cash_minor: amountToMinor(openingCash.value) });
    session.value = response.data.session;
    notice.value = t('sale.pos.sessionOpened');
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
    const countedMinor = countedCash.value > 0 ? amountToMinor(countedCash.value) : Number(session.value.expected_cash_minor || 0);
    const response = await adminApi.post<{ session: PosSession }>(`/sale/pos/sessions/${session.value.id}/close`, {
      counted_cash_minor: countedMinor,
      difference_justification: closingJustification.value.trim() || undefined
    });
    session.value = response.data.session;
    notice.value = t('sale.pos.sessionClosedNotice');
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function recordCashMovement(): Promise<void> {
  if (!session.value?.id) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ session: PosSession }>(`/sale/pos/sessions/${session.value.id}/movements`, {
      movement_type: cashMovementType.value,
      amount_minor: amountToMinor(Math.abs(cashMovementAmount.value)),
      reason: cashMovementReason.value.trim()
    });
    session.value = response.data.session;
    cashMovementAmount.value = 0;
    cashMovementReason.value = '';
    notice.value = t('sale.pos.cashMovementRecorded');
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function ensureCart(): Promise<PosCart> {
  if (cart.value?.id) return cart.value;
  const response = await adminApi.post<{ cart: PosCart }>('/sale/pos/carts', { cash_session_id: session.value?.id });
  assignCart(response.data.cart);
  return response.data.cart;
}

async function addVariant(variant: PosVariant): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    const current = await ensureCart();
    const response = await adminApi.post<{ cart: PosCart; line: PosCartLine }>(`/sale/pos/carts/${current.id}/lines`, {
      sellable_id: variant.sellable_id || variant.business_variant_id,
      quantity: 1,
      expected_version: current.version,
      idempotency_key: `pos-${Date.now()}-${variant.business_variant_id}`
    });
    assignCart(response.data.cart);
    receipt.value = null;
    await search();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function updateLine(line: PosCartLine, quantity: number): Promise<void> {
  if (!cart.value?.id) return;
  const nextQuantity = Math.max(1, quantity);
  const previousQuantity = line.quantity;
  error.value = '';
  line.quantity = nextQuantity;
  try {
    const response = await adminApi.patch<{ cart: PosCart; line?: PosCartLine }>(`/sale/pos/carts/${cart.value.id}/lines/${line.id}`, { quantity: nextQuantity, expected_version: cart.value.version });
    mergeCartUpdate(response.data.cart, response.data.line);
    await search();
  } catch (err) {
    line.quantity = previousQuantity;
    error.value = apiErrorMessage(err);
  }
}

async function deleteLine(line: PosCartLine): Promise<void> {
  if (!cart.value?.id) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.delete<{ cart: PosCart }>(`/sale/pos/carts/${cart.value.id}/lines/${line.id}`, { expected_version: cart.value.version });
    assignCart(response.data.cart);
    await search();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

function mergeCartUpdate(updatedCart: PosCart, updatedLine?: PosCartLine): void {
  if (!cart.value) {
    assignCart(updatedCart);
    return;
  }
  const shouldSyncPayment = paymentTracksCartTotal(cart.value);
  for (const [key, value] of Object.entries(updatedCart)) {
    if (key !== 'lines') cart.value[key] = value;
  }
  const lines = cart.value.lines || [];
  const updates = updatedLine ? [updatedLine] : updatedCart.lines || [];
  for (const update of updates) {
    const existing = lines.find((candidate) => candidate.id === update.id);
    if (existing) Object.assign(existing, update);
  }
  cart.value.lines = lines;
  if (shouldSyncPayment) {
    paidAmount.value = Number(cart.value.grand_total_minor || 0) / 100;
  }
}

function assignCart(nextCart: PosCart): void {
  const shouldSyncPayment = paymentTracksCartTotal(cart.value);
  cart.value = nextCart;
  if (shouldSyncPayment) {
    paidAmount.value = Number(nextCart.grand_total_minor || 0) / 100;
  }
}

function paymentTracksCartTotal(previousCart: PosCart | null): boolean {
  const previousTotal = Number(previousCart?.grand_total_minor || 0);
  return paidAmountMinor.value === 0 || paidAmountMinor.value === previousTotal;
}

async function applyCartAdjustment(): Promise<void> {
  if (!cart.value?.id) return;
  error.value = '';
  try {
    const response = await adminApi.post<{ cart: PosCart }>(`/sale/pos/carts/${cart.value.id}/adjustments`, {
      mode: adjustmentMode.value,
      value: Number(adjustmentValue.value || 0)
    });
    assignCart(response.data.cart);
  } catch (err) {
    error.value = apiErrorMessage(err);
  }
}

function updateLineFromInput(line: PosCartLine, event: Event): void {
  const target = event.target instanceof HTMLInputElement ? event.target : null;
  void updateLine(line, Number(target?.value || 1));
}

function printReceipt(): void {
  const text = receiptText.value.trim();
  if (!text) return;
  const printWindow = window.open('', 'sale-pos-receipt', 'popup,width=360,height=640');
  if (!printWindow) {
    error.value = t('sale.orders.printUnavailable');
    return;
  }

  const printDocument = printWindow.document;
  printDocument.open();
  printDocument.write(receiptPrintHtml(text));
  printDocument.close();
  printWindow.focus();
  printWindow.addEventListener('afterprint', () => {
    printWindow.close();
  }, { once: true });
  setTimeout(() => {
    printWindow.focus();
    printWindow.print();
  }, 100);
}

async function reprintReceipt(): Promise<void> {
  if (!receiptOrderId.value || !reprintReason.value.trim()) {
    error.value = t('sale.pos.reprintReasonRequired');
    return;
  }
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ receipt: PosReceipt }>(`/sale/pos/orders/${receiptOrderId.value}/receipt/reprint`, { reason: reprintReason.value.trim() });
    receipt.value = response.data.receipt;
    printReceipt();
    notice.value = t('sale.pos.receiptReprinted');
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function emailReceipt(): Promise<void> {
  if (!receiptOrderId.value) return;
  const email = receiptEmail.value.trim();
  if (!email) {
    error.value = t('sale.pos.emailRequired');
    return;
  }
  receiptSending.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ sent?: boolean; logged?: boolean; email?: string }>(`/sale/pos/orders/${receiptOrderId.value}/receipt/email`, { email });
    notice.value = response.data.sent === false && response.data.logged
      ? t('sale.pos.receiptQueued', { email })
      : t('sale.pos.receiptSent', { email });
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    receiptSending.value = false;
  }
}

function receiptPrintHtml(text: string): string {
  return `<!doctype html>
<html lang="${languageCode.value}">
<head>
  <meta charset="utf-8">
  <title>${escapeHtml(t('sale.orders.receiptTitle'))}</title>
  <style>
    @page { size: 80mm auto; margin: 4mm; }
    * { box-sizing: border-box; }
    body {
      color: #111827;
      font-family: "Courier New", monospace;
      font-size: 12px;
      line-height: 1.35;
      margin: 0;
      width: 72mm;
    }
    pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
    }
  </style>
</head>
<body><pre>${escapeHtml(text)}</pre></body>
</html>`;
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function isBundle(variant: PosVariant): boolean {
  return variant.product_type === 'bundle' || variant.is_bundle === true;
}

function hasDiscount(variant: PosVariant): boolean {
  return Number(variant.regular_unit_price_minor || 0) > Number(variant.unit_price_minor || 0);
}

function discountPercent(variant: PosVariant): number {
  const regular = Number(variant.regular_unit_price_minor || 0);
  const final = Number(variant.unit_price_minor || 0);
  if (regular <= 0 || final >= regular) return 0;
  return Math.round(((regular - final) / regular) * 100);
}

function variantNote(variant: PosVariant): string {
  return String(variant.variant_sales_note || variant.sales_note || '').trim();
}

function compactAttributeValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '';
  if (Array.isArray(value)) return value.map(compactAttributeValue).filter(Boolean).join('·');
  return String(value).trim().toUpperCase();
}

function attributeSummary(variant: PosVariant): string {
  const attributes = variant.metadata?.attributes?.merged || [];
  return attributes
    .map((attribute) => compactAttributeValue(attribute.value))
    .filter(Boolean)
    .join('·');
}

function optionSummary(variant: PosVariant): string {
  const options = variant.variant_options || variant.option_values || [];
  return options
    .map((option) => {
      const value = String(option.label || option.value || option.value_code || '').trim();
      return value ? value.toUpperCase() : String(option.option_name || option.option_code || '').trim().toUpperCase();
    })
    .filter(Boolean)
    .join('·');
}

function stockLabel(variant: PosVariant): string {
  const available = variant.metadata?.available_quantity ?? variant.available_quantity;
  if (available === null || available === undefined || available === '') return '';
  const quantity = numberValue(available);
  return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(2);
}

async function checkout(): Promise<void> {
  if (!cart.value?.id) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await adminApi.post<{ order: Record<string, unknown>; receipt: PosReceipt }>('/sale/pos/checkout', {
      cart_id: cart.value.id,
      cash_session_id: session.value?.id,
      payment_method: paymentMethod.value,
      amount_minor: paymentTotalMinor.value,
      idempotency_key: `pos-checkout-${cart.value.id}`
    });
    receipt.value = response.data.receipt;
    cart.value = null;
    paidAmount.value = 0;
    adjustmentValue.value = 0;
    notice.value = t('sale.pos.saleCompleted', { order: String(response.data.order.order_number || '') });
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
  <section class="sale-pos" :class="{ 'sale-pos--embedded': embedded }">
    <header class="sale-pos__head" :class="{ 'sale-pos__head--embedded': embedded }">
      <div v-if="!embedded">
        <p class="text-uppercase text-muted small mb-1">{{ t('sale.title') }}</p>
        <h1>{{ t('sale.pos.title') }}</h1>
      </div>
      <div class="sale-pos__session">
        <span class="badge" :class="session?.status === 'open' ? 'text-bg-success' : 'text-bg-secondary'">
          {{ session?.status === 'open' ? t('sale.pos.sessionOpen') : t('sale.pos.sessionClosed') }}
        </span>
        <select v-if="!session || session.status !== 'open'" v-model.number="selectedRegisterId" class="form-select form-select-sm" :aria-label="t('sale.pos.register')">
          <option v-for="register in bootstrap.registers" :key="Number(register.id)" :value="Number(register.id)">{{ register.name }}</option>
        </select>
        <input v-if="!session || session.status !== 'open'" v-model.number="openingCash" class="form-control form-control-sm" type="number" min="0" step="0.01" :placeholder="t('sale.pos.openingCash')">
        <button v-if="!session || session.status !== 'open'" class="btn btn-primary btn-sm" :disabled="saving" @click="openSession">{{ t('sale.pos.open') }}</button>
        <template v-else>
          <input v-model.number="countedCash" class="form-control form-control-sm" type="number" min="0" step="0.01" :placeholder="t('sale.pos.countedCash')">
          <input v-model="closingJustification" class="form-control form-control-sm" type="text" :placeholder="t('sale.pos.differenceJustification')">
          <button class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="closeSession">{{ t('sale.pos.close') }}</button>
        </template>
      </div>
    </header>

    <div v-if="session?.status === 'open'" class="sale-pos__cash-movement mb-3">
      <select v-model="cashMovementType" class="form-select form-select-sm">
        <option value="cash_in">{{ t('sale.pos.cashIn') }}</option>
        <option value="cash_out">{{ t('sale.pos.cashOut') }}</option>
        <option value="correction">{{ t('sale.pos.cashCorrection') }}</option>
      </select>
      <input v-model.number="cashMovementAmount" class="form-control form-control-sm" type="number" min="0" step="0.01" :placeholder="t('common.amount')">
      <input v-model="cashMovementReason" class="form-control form-control-sm" type="text" :placeholder="t('sale.pos.cashReason')">
      <button class="btn btn-outline-primary btn-sm" :disabled="saving || cashMovementAmount <= 0 || !cashMovementReason.trim()" @click="recordCashMovement">{{ t('common.add') }}</button>
    </div>

    <div v-if="notice" class="alert alert-success py-2">{{ notice }}</div>
    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <div class="sale-pos__layout">
      <main class="sale-pos__catalog">
        <div class="sale-pos__search">
          <input v-model="query" class="form-control" type="search" :placeholder="t('sale.pos.productPlaceholder')" @keyup.enter="search">
          <input v-model="barcode" class="form-control" type="search" :placeholder="t('sale.pos.barcodePlaceholder')" @keyup.enter="search">
          <button class="btn btn-outline-primary" :disabled="loading" @click="search">{{ t('common.search') }}</button>
        </div>

        <section v-if="bundles.length" class="sale-pos__bundle-section">
          <div class="sale-pos__section-head">
            <h2>{{ t('sale.pos.bundles') }}</h2>
          </div>
          <div class="sale-pos__grid sale-pos__grid--bundles">
            <button v-for="bundle in bundles" :key="`bundle-${bundle.business_variant_id}`" class="sale-pos__product sale-pos__product--bundle" type="button" :disabled="saving" @click="addVariant(bundle)">
              <span v-if="hasDiscount(bundle)" class="sale-pos__discount-badge">-{{ discountPercent(bundle) }}%</span>
              <span class="sale-pos__product-top">
                <span v-if="stockLabel(bundle)" class="sale-pos__stock-tag">{{ stockLabel(bundle) }}</span>
              </span>
              <strong>{{ bundle.product_name }}</strong>
              <span>{{ bundle.variant_name || bundle.sku }}</span>
              <small v-if="attributeSummary(bundle)" class="sale-pos__attrs">{{ attributeSummary(bundle) }}</small>
              <small v-else-if="optionSummary(bundle)" class="sale-pos__attrs">{{ optionSummary(bundle) }}</small>
              <small v-if="variantNote(bundle)" class="sale-pos__note">{{ variantNote(bundle) }}</small>
              <span class="sale-pos__product-footer">
                <small class="sale-pos__sku">{{ bundle.sku }}</small>
                <b>
                  <span v-if="hasDiscount(bundle)" class="sale-pos__price-before">{{ cardPrice(bundle.regular_unit_price_minor) }}</span>
                  <span class="sale-pos__price-final">{{ cardPrice(bundle.unit_price_minor) }}</span>
                </b>
              </span>
            </button>
          </div>
        </section>

        <div class="sale-pos__grid">
          <button v-for="variant in catalogVariants" :key="variant.business_variant_id" class="sale-pos__product" type="button" :disabled="saving" @click="addVariant(variant)">
            <span v-if="hasDiscount(variant)" class="sale-pos__discount-badge">-{{ discountPercent(variant) }}%</span>
            <span class="sale-pos__product-top">
              <span v-if="stockLabel(variant)" class="sale-pos__stock-tag">{{ stockLabel(variant) }}</span>
            </span>
            <strong>{{ variant.product_name }}</strong>
            <span>{{ variant.variant_name || variant.sku }}</span>
            <small v-if="attributeSummary(variant)" class="sale-pos__attrs">{{ attributeSummary(variant) }}</small>
            <small v-else-if="optionSummary(variant)" class="sale-pos__attrs">{{ optionSummary(variant) }}</small>
            <small v-if="variantNote(variant)" class="sale-pos__note">{{ variantNote(variant) }}</small>
            <span class="sale-pos__product-footer">
              <small class="sale-pos__sku">{{ variant.sku }}</small>
              <b>
                <span v-if="hasDiscount(variant)" class="sale-pos__price-before">{{ cardPrice(variant.regular_unit_price_minor) }}</span>
                <span class="sale-pos__price-final">{{ cardPrice(variant.unit_price_minor) }}</span>
              </b>
            </span>
          </button>
        </div>
      </main>

      <aside class="sale-pos__cart">
        <h2>{{ t('sale.pos.cart') }} <span>[{{ cart?.currency || 'CHF' }}]</span></h2>
        <p v-if="!cartLines.length" class="text-muted">{{ t('sale.pos.emptyCart') }}</p>
        <div v-for="line in cartLines" :key="line.id" class="sale-pos__line">
          <div>
            <strong>{{ line.product_name }}</strong>
          </div>
          <button class="sale-pos__line-delete" type="button" :disabled="saving" :aria-label="t('sale.pos.removeLine')" @click="deleteLine(line)">
            <svg xmlns="http://www.w3.org/2000/svg" class="bi bi-trash" viewBox="0 0 16 16" aria-hidden="true">
              <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>
              <path d="M14.5 3a1 1 0 0 1-1 1H13l-.8 9.6A2 2 0 0 1 10.2 15H5.8a2 2 0 0 1-2-1.4L3 4h-.5a1 1 0 0 1 0-2H5V1a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1h2.5a1 1 0 0 1 1 1M6 2h4V1H6zM4 4l.8 9.5a1 1 0 0 0 1 .5h4.4a1 1 0 0 0 1-.5L12 4z"/>
            </svg>
          </button>
          <input class="form-control form-control-sm sale-pos__qty" type="number" min="1" :value="line.quantity" @change="updateLineFromInput(line, $event)">
          <b>{{ money(line.line_total_minor) }}</b>
        </div>

        <div v-if="cartManualDiscountMinor > 0" class="sale-pos__summary-row">
          <span>{{ t('sale.pos.discount') }}</span>
          <strong>-{{ money(cartManualDiscountMinor) }}</strong>
        </div>
        <div v-if="cartSurchargeMinor > 0" class="sale-pos__summary-row">
          <span>{{ t('sale.pos.surcharge') }}</span>
          <strong>+{{ money(cartSurchargeMinor) }}</strong>
        </div>
        <div class="sale-pos__total">
          <span>{{ t('common.total') }}</span>
          <strong>{{ money(cart?.grand_total_minor) }}</strong>
        </div>
        <div class="sale-pos__due" :class="{ 'sale-pos__due--overpaid': isOverpaid }">
          <span>{{ t('sale.pos.due') }}</span>
          <strong>{{ money(remainingDueMinor) }}</strong>
        </div>
        <div v-if="cartOfferDiscountMinor > 0" class="sale-pos__summary-row sale-pos__summary-row--offer">
          <span>{{ t('sale.pos.offers') }}</span>
          <strong>-{{ money(cartOfferDiscountMinor) }}</strong>
        </div>

        <div class="sale-pos__adjustment">
          <select v-model="adjustmentMode" class="form-select form-select-sm" :disabled="!cartLines.length || saving" @change="applyCartAdjustment">
            <option value="amount">{{ t('common.amount') }}</option>
            <option value="percent">%</option>
          </select>
          <input v-model.number="adjustmentValue" class="form-control form-control-sm" type="number" step="0.01" :disabled="!cartLines.length || saving" @change="applyCartAdjustment">
        </div>

        <label class="form-label mt-3">{{ t('sale.pos.payment') }}</label>
        <select v-model="paymentMethod" class="form-select">
          <option v-for="method in paymentMethods" :key="method.id" :value="method.code">{{ method.name }}</option>
        </select>

        <div class="sale-pos__cash">
          <label>{{ t('sale.pos.paidAmount') }}<input v-model.number="paidAmount" class="form-control form-control-sm" type="number" min="0" step="0.01"></label>
        </div>

        <button class="btn btn-success w-100 mt-3" :disabled="saving || !canCheckout" @click="checkout">{{ t('sale.pos.checkout') }}</button>

        <section v-if="receipt" class="sale-pos__receipt">
          <h3>{{ t('sale.pos.receipt') }}</h3>
          <pre>{{ receiptText }}</pre>
          <div class="sale-pos__receipt-actions">
            <button class="btn btn-outline-secondary btn-sm" type="button" @click="printReceipt">{{ t('common.print') }}</button>
            <input v-model="reprintReason" class="form-control form-control-sm" type="text" :placeholder="t('sale.pos.reprintReason')">
            <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="saving || !receiptOrderId" @click="reprintReceipt">{{ t('sale.pos.reprint') }}</button>
            <input v-model="receiptEmail" class="form-control form-control-sm" type="email" :placeholder="t('sale.pos.emailPlaceholder')">
            <button class="btn btn-outline-primary btn-sm" type="button" :disabled="receiptSending || !receiptOrderId" @click="emailReceipt">{{ receiptSending ? t('common.sending') : t('common.send') }}</button>
          </div>
        </section>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.sale-pos {
  padding: 1.25rem;
}
.sale-pos--embedded {
  padding: 0;
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
.sale-pos__cash-movement {
  align-items: center;
  display: grid;
  gap: .5rem;
  grid-template-columns: minmax(120px, .7fr) minmax(100px, .5fr) minmax(180px, 1fr) auto;
}
.sale-pos__head {
  justify-content: space-between;
  margin-bottom: 1rem;
}
.sale-pos__head--embedded {
  justify-content: flex-end;
  margin-bottom: .75rem;
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
.sale-pos__cart h2 {
  align-items: baseline;
  display: flex;
  justify-content: space-between;
}
.sale-pos__cart h2 span {
  color: #667085;
  font-size: .78rem;
  font-weight: 700;
}
.sale-pos__search {
  margin-bottom: 1rem;
}
.sale-pos__bundle-section {
  display: grid;
  gap: .65rem;
  margin-bottom: 1rem;
}
.sale-pos__section-head {
  align-items: center;
  display: flex;
  justify-content: space-between;
}
.sale-pos__section-head h2 {
  font-size: 1rem;
  margin: 0;
}
.sale-pos__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  column-gap: 1rem;
  row-gap: 1rem;
  overflow: visible;
}
.sale-pos__grid--bundles {
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}
.sale-pos__product {
  position: relative;
  display: grid;
  grid-template-rows: auto auto auto auto 1fr;
  gap: .25rem;
  text-align: left;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  overflow: visible;
  padding: .65rem .7rem 2.05rem;
  min-height: 8.15rem;
}
.sale-pos__product:hover {
  border-color: #0d6efd;
}
.sale-pos__product--bundle {
  background: #f8fafc;
  border-color: #b9c7d8;
}
.sale-pos__product-top {
  align-items: center;
  display: flex;
  gap: .35rem;
}
.sale-pos__discount-badge {
  background: #dc2626;
  border-radius: 4px;
  color: #fff !important;
  font-size: .72rem;
  font-weight: 900;
  line-height: 1;
  padding: .25rem .35rem;
  position: absolute;
  right: .5rem;
  top: .5rem;
}
.sale-pos__stock-tag {
  background: #fff;
  border: 1px solid #d0d5dd;
  border-radius: 4px;
  color: #111827 !important;
  display: inline-flex;
  font-family: "Courier New", monospace;
  font-size: .72rem;
  font-weight: 800;
  line-height: 1;
  padding: .24rem .35rem;
  position: absolute;
  left: .55rem;
  top: -.58rem;
  z-index: 2;
}
.sale-pos__product span,
.sale-pos__product small {
  color: #667085;
}
.sale-pos__note {
  color: #344054 !important;
  font-weight: 700;
}
.sale-pos__attrs {
  line-height: 1.35;
}
.sale-pos__product-footer {
  display: contents;
}
.sale-pos__sku {
  background: #fff;
  bottom: -.36rem;
  color: #98a2b3 !important;
  font-size: .62rem;
  font-weight: 700;
  left: .55rem;
  letter-spacing: 0;
  line-height: 1.15;
  padding: 0 .18rem;
  position: absolute;
  text-transform: uppercase;
  white-space: nowrap;
  z-index: 1;
}
.sale-pos__product--bundle .sale-pos__sku {
  background: #f8fafc;
}
.sale-pos__product b {
  align-items: baseline;
  display: flex;
  flex-wrap: wrap;
  gap: .35rem;
  justify-content: flex-end;
  margin-left: auto;
  position: absolute;
  right: .7rem;
  text-align: right;
  bottom: .48rem;
}
.sale-pos__price-final,
.sale-pos__price-before {
  font-weight: 700;
  font-size: 1rem;
}
.sale-pos__price-before {
  color: #98a2b3 !important;
  text-decoration: line-through;
}
.sale-pos__line {
  align-items: center;
  display: grid;
  gap: .35rem;
  grid-template-columns: minmax(0, 1fr) 1.55rem 3.4rem 5.4rem;
  padding: .55rem 0;
  border-bottom: 1px solid #edf0f3;
}
.sale-pos__line div {
  display: grid;
}
.sale-pos__line strong {
  font-size: .92rem;
}
.sale-pos__qty {
  font-family: "Courier New", monospace;
  font-size: .95rem;
  font-variant-numeric: tabular-nums;
  justify-self: end;
  max-width: 3.4rem;
  padding-left: 0;
  padding-right: 0;
  text-align: center;
}
.sale-pos__line-delete {
  align-items: center;
  background: transparent;
  border: 0;
  color: #475467;
  display: inline-flex;
  height: 1.6rem;
  justify-content: center;
  justify-self: end;
  padding: 0;
  width: 1.55rem;
}
.sale-pos__line-delete svg {
  display: block;
  fill: currentColor;
  height: 1rem;
  width: 1rem;
}
.sale-pos__line-delete:hover {
  color: #dc2626;
}
.sale-pos__summary-row,
.sale-pos__total,
.sale-pos__due {
  align-items: center;
  display: flex;
  justify-content: space-between;
}
.sale-pos__summary-row {
  color: #667085;
  font-size: .88rem;
  padding-top: .55rem;
}
.sale-pos__total,
.sale-pos__due {
  color: #667085;
  font-size: .88rem;
}
.sale-pos__summary-row--offer {
  padding-top: .45rem;
}
.sale-pos__total {
  padding-top: 1rem;
}
.sale-pos__due {
  border-top: 1px solid #edf0f3;
  margin-top: .65rem;
  padding-top: .65rem;
}
.sale-pos__due strong {
  color: #0f172a;
}
.sale-pos__due--overpaid {
  color: #dc2626;
}
.sale-pos__due--overpaid strong {
  color: #dc2626;
}
.sale-pos__line b,
.sale-pos__summary-row strong,
.sale-pos__total strong,
.sale-pos__due strong {
  font-family: "Courier New", monospace;
  font-size: 1rem;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  min-width: 5.4rem;
  text-align: right;
}
.sale-pos__line b {
  display: block;
  justify-self: end;
}
.sale-pos__adjustment {
  display: grid;
  grid-template-columns: 10.8rem 4.5rem;
  gap: .4rem;
  justify-content: end;
  margin-top: .85rem;
}
.sale-pos__adjustment input {
  font-family: "Courier New", monospace;
  font-size: 1rem;
  font-variant-numeric: tabular-nums;
  text-align: right;
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
.sale-pos__cash input {
  font-family: "Courier New", monospace;
  font-size: 1rem;
  font-variant-numeric: tabular-nums;
  text-align: right;
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
.sale-pos__receipt-actions {
  align-items: center;
  display: grid;
  gap: .45rem;
  grid-template-columns: auto minmax(0, 1fr) auto;
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
