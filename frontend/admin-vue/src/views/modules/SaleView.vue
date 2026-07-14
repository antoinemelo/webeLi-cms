<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';
import { hasTranslation, useI18n } from '@/i18n';
import { useAdminContextStore } from '@/stores/adminContext';
import ContextualHelpLink from '@/components/ui/ContextualHelpLink.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import BusinessPageHeader from './business/BusinessPageHeader.vue';
import SalePosView from './SalePosView.vue';
import SaleStockView from './SaleStockView.vue';
import SaleReservationsView from './SaleReservationsView.vue';
import SaleOperationsView from './SaleOperationsView.vue';
import SaleIdentityReviewView from './SaleIdentityReviewView.vue';

type Row = Record<string, unknown>;
type Dashboard = {
  orders?: number;
  active_carts?: number;
  paid_total_minor?: number;
  today_sales_minor?: number;
  channels?: number;
  recent_orders?: Row[];
  recent_payments?: Row[];
  open_cash_sessions?: Row[];
};
type Order = Row & {
  id: number;
  order_number?: string;
  source?: string;
  status?: string;
  payment_status?: string;
  currency?: string;
  grand_total_minor?: number;
  paid_total_minor?: number;
  placed_at?: string;
  lines?: Row[];
};
type Channel = Row & { id: number; code?: string; name?: string; channel_type?: string; status?: string; is_public?: number; currency?: string; price_tax_included?: number };
type PaymentTimelineEvent = { kind: string; status: string; at?: string; amount_minor?: number; detail?: string };
type ProviderStatus = Row & {
  provider?: string;
  label?: string;
  environment?: string;
  connected?: boolean;
  webhook_url?: string;
  twint_mode?: string;
  last_verified_at?: string | null;
  providers?: ProviderStatus[];
};
type PaymentSession = Row & {
  id: number;
  order_number?: string;
  amount_minor?: number;
  currency?: string;
  authorized_minor?: number;
  captured_minor?: number;
  refunded_minor?: number;
  state?: { label?: string; next_action?: string; next_action_label?: string };
  customer?: { name?: string; email?: string };
  timeline?: PaymentTimelineEvent[];
  technical?: Row;
  provider?: string;
  instructions?: Row | null;
  test_mode?: boolean;
  capturable_minor?: number;
  refundable_minor?: number;
  capabilities?: Record<string, boolean>;
  captures?: Row[];
};
type PaymentExceptionCenter = { health?: string; open_count?: number; pending_operations?: number; groups?: Record<string, number>; items?: Row[] };
type OrderColumnKey = 'number' | 'date' | 'source' | 'total' | 'payment' | 'status';
type SortDirection = 'asc' | 'desc';

const route = useRoute();
const router = useRouter();
const context = useAdminContextStore();
const { languageCode, t, money: formatMoney, dateTime } = useI18n();

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const dashboard = ref<Dashboard>({});
const orders = ref<Order[]>([]);
const selectedOrder = ref<Order | null>(null);
const orderPayments = ref<Row[]>([]);
const orderEvents = ref<Row[]>([]);
const paymentSessions = ref<PaymentSession[]>([]);
const paymentWebhookEvents = ref<Row[]>([]);
const paymentExceptionCenter = ref<PaymentExceptionCenter>({});
const selectedPayment = ref<PaymentSession | null>(null);
const paymentSearch = ref('');
const paymentFilter = ref({ status: '', provider: '' });
const paymentSort = ref('');
const confirmAmount = ref(0);
const paymentReference = ref('');
const paymentComment = ref('');
const proofAssetId = ref<number | null>(null);
const captureReasonCode = ref('order_ready');
const refundTransactionId = ref(0);
const refundAmount = ref(0);
const refundReasonCode = ref('customer_request');
const refundReasonNote = ref('');
const manualProvider = ref('manual_card');
const channels = ref<Channel[]>([]);
const paymentMethods = ref<Row[]>([]);
const providerStatus = ref<ProviderStatus | null>(null);
const providerStatuses = computed<ProviderStatus[]>(() => providerStatus.value?.providers ?? (providerStatus.value ? [providerStatus.value] : []));
const fulfillmentMethods = ref<Row[]>([]);
const sessions = ref<Row[]>([]);
const paymentAmount = ref(0);
const cancelReason = ref('');
const orderSearch = ref('');
const orderFilter = ref({ source: '', status: '', payment_status: '' });
const orderPage = ref(1);
const orderPageSize = ref<number | 'all'>(25);
const orderSort = ref<{ key: OrderColumnKey; direction: SortDirection }>({ key: 'date', direction: 'desc' });
const visibleOrderColumns = ref<Record<OrderColumnKey, boolean>>({
  number: true,
  date: true,
  source: true,
  total: true,
  payment: true,
  status: true
});

const tabs = [
  { key: 'dashboard', labelKey: 'sale.tabs.dashboard', path: '/sale' },
  { key: 'orders', labelKey: 'sale.tabs.orders', path: '/sale/orders' },
  { key: 'payments', labelKey: 'sale.tabs.payments', path: '/sale/payments' },
  { key: 'stock', labelKey: 'sale.tabs.stock', path: '/sale/stock' },
  { key: 'reservations', labelKey: 'sale.tabs.reservations', path: '/sale/reservations' },
  { key: 'operations', labelKey: 'sale.tabs.operations', path: '/sale/operations' },
  { key: 'identities', labelKey: 'sale.tabs.identities', path: '/sale/identities' },
  { key: 'pos', labelKey: 'sale.tabs.pos', path: '/sale/pos' },
  { key: 'settings', labelKey: 'sale.tabs.settings', path: '/sale/settings' }
];
const orderColumns: Array<{ key: OrderColumnKey; labelKey: string }> = [
  { key: 'number', labelKey: 'common.number' },
  { key: 'date', labelKey: 'common.date' },
  { key: 'source', labelKey: 'common.source' },
  { key: 'total', labelKey: 'common.total' },
  { key: 'payment', labelKey: 'common.payment' },
  { key: 'status', labelKey: 'common.status' }
];
const orderPageSizeOptions: Array<number | 'all'> = [10, 25, 50, 'all'];
const orderSourceOptions = ['ecommerce', 'pos', 'admin'];
const orderStatusOptions = ['placed', 'confirmed', 'completed', 'cancelled'];
const orderPaymentStatusOptions = ['unpaid', 'pending', 'partially_paid', 'paid', 'failed'];

const activeTab = computed(() => {
  if (route.path.includes('/sale/orders')) return 'orders';
  if (route.path.includes('/sale/payments')) return 'payments';
  if (route.path.includes('/sale/stock')) return 'stock';
  if (route.path.includes('/sale/reservations')) return 'reservations';
  if (route.path.includes('/sale/operations')) return 'operations';
  if (route.path.includes('/sale/identities')) return 'identities';
  if (route.path.includes('/sale/pos')) return 'pos';
  if (route.path.includes('/sale/settings')) return 'settings';
  return 'dashboard';
});
const filteredOrders = computed(() => {
  const q = orderSearch.value.trim().toLowerCase();
  return orders.value.filter((order) => {
    if (orderFilter.value.source && String(order.source || '') !== orderFilter.value.source) return false;
    if (orderFilter.value.status && String(order.status || '') !== orderFilter.value.status) return false;
    if (orderFilter.value.payment_status && String(order.payment_status || '') !== orderFilter.value.payment_status) return false;
    if (!q) return true;
    return [
      order.order_number,
      shortDate(order.placed_at),
      order.source,
      statusLabel(order.source),
      statusLabel(order.payment_status),
      statusLabel(order.status),
      money(order.grand_total_minor, order.currency)
    ].join(' ').toLocaleLowerCase(languageCode.value).includes(q);
  });
});
const sortedOrders = computed(() => {
  const direction = orderSort.value.direction === 'asc' ? 1 : -1;
  return [...filteredOrders.value].sort((a, b) => {
    const left = orderSortValue(a, orderSort.value.key);
    const right = orderSortValue(b, orderSort.value.key);
    if (typeof left === 'number' && typeof right === 'number') return (left - right) * direction;
    return String(left).localeCompare(String(right), languageCode.value, { numeric: true, sensitivity: 'base' }) * direction;
  });
});
const orderPageCount = computed(() => {
  if (orderPageSize.value === 'all') return 1;
  return Math.max(1, Math.ceil(filteredOrders.value.length / orderPageSize.value));
});
const paginatedOrders = computed(() => {
  if (orderPageSize.value === 'all') return sortedOrders.value;
  const start = (orderPage.value - 1) * orderPageSize.value;
  return sortedOrders.value.slice(start, start + orderPageSize.value);
});
const orderPaginationLabel = computed(() => {
  const total = filteredOrders.value.length;
  if (total === 0) return t('sale.orders.count.zero');
  if (orderPageSize.value === 'all') return t('sale.orders.count.all', { count: total });
  const start = (orderPage.value - 1) * orderPageSize.value + 1;
  const end = Math.min(total, start + orderPageSize.value - 1);
  return t('sale.orders.count.range', { start, end, total });
});

function money(minor?: unknown, currency?: unknown): string {
  return formatMoney(minor, currency || 'CHF');
}

function shortDate(value?: unknown): string {
  return dateTime(value);
}

function statusLabel(value?: unknown): string {
  const key = String(value || '');
  return key ? (hasTranslation(`sale.status.${key}`, languageCode.value) ? t(`sale.status.${key}`) : key) : '-';
}

function orderColumnLabel(key: OrderColumnKey): string {
  const column = orderColumns.find((item) => item.key === key);
  return column ? t(column.labelKey) : key;
}

function saleCatalogPdfUrl(channel: Channel): string {
  return adminApi.href('/sale/catalog/export.pdf', { channel_id: channel.id, catalog_channel: 'catalogue' });
}

async function go(path: string): Promise<void> {
  if (path === '/sale/pos') {
    await router.push(path);
    return;
  }
  await router.push(path);
}

async function loadDashboard(): Promise<void> {
  const response = await adminApi.get<Dashboard>('/sale/dashboard');
  dashboard.value = response.data;
}

async function loadOrders(): Promise<void> {
  const response = await adminApi.get<{ orders: Order[] }>('/sale/orders', {
    limit: 100,
    q: orderSearch.value.trim(),
    status: orderFilter.value.status,
    payment_status: orderFilter.value.payment_status
  });
  orders.value = response.data.orders || [];
  if (selectedOrder.value && !orders.value.some((order) => order.id === selectedOrder.value?.id)) {
    selectedOrder.value = null;
    orderPayments.value = [];
    orderEvents.value = [];
  }
  if (!selectedOrder.value && orders.value[0]) {
    await selectOrder(orders.value[0]);
  }
}

async function loadSettings(): Promise<void> {
  const [channelResponse, methodResponse, sessionResponse, fulfillmentResponse] = await Promise.all([
    adminApi.get<{ channels: Channel[] }>('/sale/channels', { limit: 100 }),
    adminApi.get<{ payment_methods: Row[]; provider_status?: ProviderStatus }>('/sale/payment-methods'),
    adminApi.get<{ sessions: Row[] }>('/sale/reports/pos-sessions'),
    adminApi.get<{ methods: Row[] }>('/sale/fulfillment')
  ]);
  channels.value = channelResponse.data.channels || [];
  paymentMethods.value = methodResponse.data.payment_methods || [];
  providerStatus.value = methodResponse.data.provider_status || null;
  sessions.value = sessionResponse.data.sessions || [];
  fulfillmentMethods.value = fulfillmentResponse.data.methods || [];
}

async function loadPayments(): Promise<void> {
  const [response, observability] = await Promise.all([adminApi.get<{ payment_sessions: PaymentSession[] }>('/sale/payments', {
    q: paymentSearch.value,
    status: paymentFilter.value.status,
    provider: paymentFilter.value.provider,
    sort: paymentSort.value,
    limit: 100
  }), adminApi.get<{ webhook_events?: Row[]; exception_center?: PaymentExceptionCenter }>('/sale/payments/observability')]);
  paymentSessions.value = response.data.payment_sessions || [];
  paymentWebhookEvents.value = observability.data.webhook_events || [];
  paymentExceptionCenter.value = observability.data.exception_center || {};
  if (selectedPayment.value && !paymentSessions.value.some((payment) => payment.id === selectedPayment.value?.id)) selectedPayment.value = null;
  if (!selectedPayment.value && paymentSessions.value[0]) await selectPayment(paymentSessions.value[0]);
}

async function selectPayment(payment: PaymentSession): Promise<void> {
  try {
    const response = await adminApi.get<{ payment: PaymentSession }>(`/sale/payments/${payment.id}`);
    selectedPayment.value = response.data.payment;
    confirmAmount.value = Math.max(0, Number(response.data.payment.capturable_minor ?? response.data.payment.amount_minor ?? 0) - (response.data.payment.capturable_minor === undefined ? Number(response.data.payment.captured_minor || 0) : 0));
    const refundable = (response.data.payment.captures || []).find((capture) => Number(capture.refundable_minor || 0) > 0);
    refundTransactionId.value = Number(refundable?.id || 0);
    refundAmount.value = Number(refundable?.refundable_minor || 0);
  } catch (err) { error.value = apiErrorMessage(err); }
}

function applyPaymentFilters(): void { void loadPayments(); }
function clearPaymentFilters(): void { paymentSearch.value = ''; paymentFilter.value = { status: '', provider: '' }; paymentSort.value = ''; void loadPayments(); }
function showBankQueue(): void { paymentFilter.value = { status: 'requires_payment', provider: 'bank_transfer' }; paymentSort.value = 'oldest'; void loadPayments(); }
async function retryWebhook(event: Row): Promise<void> { saving.value=true;error.value='';try{await adminApi.post(`/sale/payment-webhooks/${event.id}/retry`,{});await loadPayments();}catch(err){error.value=apiErrorMessage(err);}finally{saving.value=false;} }
async function confirmSelectedPayment(): Promise<void> {
  if (!selectedPayment.value?.id || confirmAmount.value < 1) return;
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const delayedCapture = ['authorized','partially_captured'].includes(String(selectedPayment.value.status || '')) && selectedPayment.value.capabilities?.capture === true && Number(selectedPayment.value.capturable_minor || 0) > 0;
    await adminApi.post(`/sale/payment-intents/${selectedPayment.value.id}/${delayedCapture ? 'capture' : 'confirm'}`, { amount_minor: Math.round(confirmAmount.value), operator_reference: paymentReference.value || null, comment: paymentComment.value || null, proof_asset_id: proofAssetId.value || null, reason_code: captureReasonCode.value, reason_note: paymentComment.value || null, idempotency_key: crypto.randomUUID() });
    notice.value = t('sale.payments.confirmed'); paymentReference.value = ''; paymentComment.value = ''; proofAssetId.value = null; await loadPayments();
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}
async function refundSelectedPayment(): Promise<void> {
  if (refundTransactionId.value < 1 || refundAmount.value < 1) return;
  saving.value = true; error.value = ''; notice.value = '';
  try {
    await adminApi.post(`/sale/payments/${refundTransactionId.value}/refund`, { amount_minor: Math.round(refundAmount.value), reason_code: refundReasonCode.value, reason_note: refundReasonNote.value || null, idempotency_key: crypto.randomUUID() });
    notice.value = t('sale.payments.refundRequested'); refundReasonNote.value = ''; await loadPayments();
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}
async function resolvePaymentException(item: Row): Promise<void> {
  const note = window.prompt(t('sale.payments.resolutionNote'))?.trim();
  if (!note) return;
  saving.value = true; error.value = '';
  try { await adminApi.post(`/sale/payment-exceptions/${item.id}/resolve`, { note, resolution: 'resolved' }); await loadPayments(); }
  catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}
function jsonText(value: unknown): string { return JSON.stringify(value || {}, null, 2); }
function findingLabels(value: unknown): string { return Array.isArray(value) ? value.map((item) => statusLabel(String(item))).join(', ') : ''; }

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    if (activeTab.value === 'dashboard') await loadDashboard();
    if (activeTab.value === 'orders') await loadOrders();
    if (activeTab.value === 'payments') await loadPayments();
    if (activeTab.value === 'settings') await loadSettings();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    loading.value = false;
  }
}

async function selectOrder(order: Order): Promise<void> {
  error.value = '';
  selectedOrder.value = order;
  try {
    const [orderResponse, paymentsResponse, eventsResponse] = await Promise.all([
      adminApi.get<{ order: Order }>(`/sale/orders/${order.id}`),
      adminApi.get<{ payments: Row[] }>(`/sale/orders/${order.id}/payments`),
      adminApi.get<{ events: Row[] }>(`/sale/orders/${order.id}/events`)
    ]);
    selectedOrder.value = orderResponse.data.order;
    orderPayments.value = paymentsResponse.data.payments || [];
    orderEvents.value = eventsResponse.data.events || [];
    const due = Math.max(0, Number(selectedOrder.value.grand_total_minor || 0) - Number(selectedOrder.value.paid_total_minor || 0));
    paymentAmount.value = due;
  } catch (err) {
    error.value = apiErrorMessage(err);
  }
}

async function recordPayment(): Promise<void> {
  if (!selectedOrder.value?.id || paymentAmount.value < 1) return;
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const response = await adminApi.post<{ order: Order }>(`/sale/orders/${selectedOrder.value.id}/payments`, { amount_minor: Math.round(paymentAmount.value), provider_key: manualProvider.value, operator_reference: paymentReference.value || null, comment: paymentComment.value || null, proof_asset_id: proofAssetId.value || null, idempotency_key: crypto.randomUUID() });
    notice.value = t('sale.orders.paymentRecorded');
    selectedOrder.value = response.data.order;
    await selectOrder(selectedOrder.value);
    await loadOrders();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function cancelOrder(): Promise<void> {
  if (!selectedOrder.value?.id || !confirm(t('sale.orders.cancelConfirm'))) return;
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const response = await adminApi.post<{ order: Order }>(`/sale/orders/${selectedOrder.value.id}/cancel`, { reason: cancelReason.value || t('sale.orders.cancelFallbackReason') });
    notice.value = t('sale.orders.cancelled');
    selectedOrder.value = response.data.order;
    await loadOrders();
  } catch (err) {
    error.value = apiErrorMessage(err);
  } finally {
    saving.value = false;
  }
}

async function printReceipt(): Promise<void> {
  if (!selectedOrder.value?.id) return;
  try {
    const response = await adminApi.get<{ receipt: Row | null }>(`/sale/orders/${selectedOrder.value.id}/receipt`);
    const printable = String(response.data.receipt?.printable_text || `${t('sale.tabs.orders')} ${selectedOrder.value.order_number || selectedOrder.value.id}\n${t('common.total')} ${money(selectedOrder.value.grand_total_minor, selectedOrder.value.currency)}`);
    const win = window.open('', 'sale-order-receipt', 'popup,width=360,height=640');
    if (!win) {
      error.value = t('sale.orders.printUnavailable');
      return;
    }
    win.document.open();
    win.document.write(receiptPrintHtml(printable));
    win.document.close();
    win.focus();
    win.addEventListener('afterprint', () => {
      win.close();
    }, { once: true });
    setTimeout(() => {
      win.focus();
      win.print();
    }, 100);
  } catch (err) {
    error.value = apiErrorMessage(err);
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

function orderColumnVisible(key: OrderColumnKey): boolean {
  return visibleOrderColumns.value[key];
}

function orderSortValue(order: Order, key: OrderColumnKey): string | number {
  if (key === 'number') return String(order.order_number || order.id || '');
  if (key === 'date') return Date.parse(String(order.placed_at || '')) || 0;
  if (key === 'source') return statusLabel(order.source);
  if (key === 'total') return Number(order.grand_total_minor || 0);
  if (key === 'payment') return statusLabel(order.payment_status);
  return statusLabel(order.status);
}

function orderSortLabel(key: OrderColumnKey): string {
  const label = orderColumnLabel(key);
  if (orderSort.value.key !== key) return t('common.sortBy', { label });
  return t('common.sortState', { label, direction: t(orderSort.value.direction === 'asc' ? 'common.ascending' : 'common.descending') });
}

function setOrderSort(key: OrderColumnKey): void {
  if (orderSort.value.key === key) {
    orderSort.value = { key, direction: orderSort.value.direction === 'asc' ? 'desc' : 'asc' };
  } else {
    orderSort.value = { key, direction: key === 'date' || key === 'total' ? 'desc' : 'asc' };
  }
  orderPage.value = 1;
}

function orderPageSizeLabel(size: number | 'all'): string {
  return size === 'all' ? t('common.all') : String(size);
}

function onOrderPageSizeChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  orderPageSize.value = value === 'all' ? 'all' : Number(value);
  orderPage.value = 1;
}

function setOrderPage(page: number): void {
  orderPage.value = Math.min(orderPageCount.value, Math.max(1, page));
}

function applyOrderFilters(): void {
  orderPage.value = 1;
  void loadOrders();
}

function resetOrderFilters(): void {
  orderSearch.value = '';
  orderFilter.value = { source: '', status: '', payment_status: '' };
  orderPage.value = 1;
  void loadOrders();
}

function closeSaleMenus(): void {
  document.querySelectorAll<HTMLDetailsElement>('.sale-menu[open]').forEach((menu) => {
    menu.removeAttribute('open');
  });
}

function csvCell(value: unknown): string {
  return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function exportOrdersCsv(): void {
  const header = orderColumns.map((column) => t(column.labelKey));
  const rows = sortedOrders.value.map((order) => [
    order.order_number || order.id,
    shortDate(order.placed_at),
    statusLabel(order.source),
    money(order.grand_total_minor, order.currency),
    statusLabel(order.payment_status),
    statusLabel(order.status)
  ]);
  const csv = [header, ...rows].map((row) => row.map(csvCell).join(',')).join('\n');
  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${t('sale.orders.csvFilename')}-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(url);
  closeSaleMenus();
}

watch(() => route.path, () => { void load(); });
watch(() => [filteredOrders.value.length, orderPageSize.value], () => {
  if (orderPage.value > orderPageCount.value) orderPage.value = orderPageCount.value;
});
onMounted(load);
</script>

<template>
  <section class="page-stack sale-admin">
    <PageHeader :title="t('sale.title')" :intro="t('sale.intro')" />
    <ContextualHelpLink id="modules.sale" class="mb-3" />

    <nav class="editor-tabs sale-tabs" :aria-label="`${t('common.mainNavigation')} ${t('sale.title')}`">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        :class="['editor-tab', { active: activeTab === tab.key }]"
        @click="go(tab.path)"
      >
        {{ t(tab.labelKey) }}
      </button>
    </nav>

    <div v-if="notice" class="alert alert-success py-2">{{ notice }}</div>
    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <section v-if="activeTab === 'dashboard'" class="sale-admin__panel">
      <div class="sale-admin__metrics">
        <div class="sale-admin__metric">
          <span>{{ t('sale.metrics.todaySales') }}</span>
          <strong>{{ money(dashboard.today_sales_minor) }}</strong>
        </div>
        <div class="sale-admin__metric">
          <span>{{ t('sale.metrics.orders') }}</span>
          <strong>{{ dashboard.orders || 0 }}</strong>
        </div>
        <div class="sale-admin__metric">
          <span>{{ t('sale.metrics.activeCarts') }}</span>
          <strong>{{ dashboard.active_carts || 0 }}</strong>
        </div>
        <div class="sale-admin__metric">
          <span>{{ t('sale.metrics.paidTotal') }}</span>
          <strong>{{ money(dashboard.paid_total_minor) }}</strong>
        </div>
      </div>

      <div class="sale-admin__grid">
        <section class="sale-admin__section">
          <div class="sale-admin__section-head">
            <h2>{{ t('sale.dashboard.recentOrders') }}</h2>
          </div>
          <div v-if="!(dashboard.recent_orders || []).length" class="text-muted">{{ t('sale.empty.orders') }}</div>
          <button v-for="order in dashboard.recent_orders || []" :key="String(order.id)" class="sale-admin__list-row" type="button" @click="go('/sale/orders')">
            <span>{{ order.order_number }}</span>
            <b>{{ money(order.grand_total_minor, order.currency) }}</b>
            <small>{{ statusLabel(order.payment_status) }}</small>
          </button>
        </section>

        <section class="sale-admin__section">
          <h2>{{ t('sale.dashboard.recentPayments') }}</h2>
          <div v-if="!(dashboard.recent_payments || []).length" class="text-muted">{{ t('sale.empty.payments') }}</div>
          <div v-for="payment in dashboard.recent_payments || []" :key="String(payment.id)" class="sale-admin__list-row">
            <span>{{ payment.order_number }}</span>
            <b>{{ money(payment.amount_minor, payment.currency) }}</b>
            <small>{{ statusLabel(payment.status) }}</small>
          </div>
        </section>

        <section class="sale-admin__section">
          <h2>{{ t('sale.dashboard.openCashSessions') }}</h2>
          <div v-if="!(dashboard.open_cash_sessions || []).length" class="text-muted">{{ t('sale.empty.openSessions') }}</div>
          <div v-for="session in dashboard.open_cash_sessions || []" :key="String(session.id)" class="sale-admin__list-row">
            <span>{{ session.register_name || session.register_code }}</span>
            <b>{{ money(session.expected_cash_minor, session.currency) }}</b>
            <small>{{ shortDate(session.opened_at) }}</small>
          </div>
        </section>
      </div>
    </section>

    <section v-if="activeTab === 'orders'" class="sale-admin__panel sale-admin__orders">
      <section class="sale-admin__section sale-orders-list">
        <BusinessPageHeader :eyebrow="t('sale.title')" :title="t('sale.orders.title')" />

        <form class="sale-toolbar" @submit.prevent="applyOrderFilters">
          <div class="sale-search-control">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <input v-model="orderSearch" type="search" :placeholder="t('sale.orders.searchPlaceholder')" @keyup.enter="applyOrderFilters">
            <button v-if="orderSearch" type="button" :aria-label="t('sale.orders.clearSearch')" @click="orderSearch = ''; applyOrderFilters()">×</button>
          </div>
          <div class="sale-toolbar-buttons">
            <details class="sale-menu sale-menu--filters">
              <summary class="sale-icon-summary" :aria-label="t('sale.orders.filtersLabel')" :title="t('common.filters')">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16l-6 7v5l-4 2v-7L4 6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
              </summary>
              <div class="sale-menu-panel sale-filter-panel">
                <strong>{{ t('common.filters') }}</strong>
                <label>
                  <select v-model="orderFilter.source" :aria-label="t('common.source')">
                    <option value="">{{ t('sale.orders.allSources') }}</option>
                    <option v-for="source in orderSourceOptions" :key="source" :value="source">{{ statusLabel(source) }}</option>
                  </select>
                </label>
                <label>
                  <select v-model="orderFilter.payment_status" :aria-label="t('common.payment')">
                    <option value="">{{ t('sale.orders.allPayments') }}</option>
                    <option v-for="status in orderPaymentStatusOptions" :key="status" :value="status">{{ statusLabel(status) }}</option>
                  </select>
                </label>
                <label>
                  <select v-model="orderFilter.status" :aria-label="t('common.status')">
                    <option value="">{{ t('sale.orders.allStatuses') }}</option>
                    <option v-for="status in orderStatusOptions" :key="status" :value="status">{{ statusLabel(status) }}</option>
                  </select>
                </label>
                <div class="sale-filter-actions">
                  <button class="btn small" type="submit" :disabled="loading">{{ t('common.apply') }}</button>
                  <button class="btn ghost small" type="button" :disabled="loading" @click="resetOrderFilters">{{ t('common.reset') }}</button>
                </div>
              </div>
            </details>
            <details class="sale-menu sale-menu--columns">
              <summary class="sale-icon-summary" :aria-label="t('sale.orders.columnsLabel')" :title="t('common.columns')">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4V5Zm5 0v14m6-14v14" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
              </summary>
              <div class="sale-menu-panel sale-column-panel">
                <strong>{{ t('common.columns') }}</strong>
                <label v-for="column in orderColumns" :key="column.key"><input v-model="visibleOrderColumns[column.key]" type="checkbox"> {{ t(column.labelKey) }}</label>
              </div>
            </details>
            <details class="sale-menu sale-menu--exports">
              <summary class="sale-icon-summary" :aria-label="t('sale.orders.importExportLabel')" :title="t('common.importExport')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left-right" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M1 11.5a.5.5 0 0 0 .5.5h11.793l-3.147 3.146a.5.5 0 0 0 .708.708l4-4a.5.5 0 0 0 0-.708l-4-4a.5.5 0 0 0-.708.708L13.293 11H1.5a.5.5 0 0 0-.5.5m14-7a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 1 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 4H14.5a.5.5 0 0 1 .5.5"/></svg>
              </summary>
              <div class="sale-menu-panel">
                <button type="button" :disabled="!filteredOrders.length" @click="exportOrdersCsv">{{ t('sale.orders.exportCsv') }}</button>
                <button type="button" disabled :title="t('sale.orders.importCsvPlanned')">{{ t('sale.orders.importCsv') }}</button>
              </div>
            </details>
            <details class="sale-menu sale-menu--actions">
              <summary :aria-label="t('sale.orders.actionsLabel')" :title="t('common.actions')">...</summary>
              <div class="sale-menu-panel">
                <button type="button" :disabled="loading" @click="loadOrders(); closeSaleMenus()">{{ t('common.refresh') }}</button>
              </div>
            </details>
          </div>
        </form>

        <div class="sale-admin__table-wrap">
          <table class="table table-hover align-middle sale-orders-table">
            <thead>
              <tr>
                <th v-if="orderColumnVisible('number')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('number')" @click="setOrderSort('number')">{{ orderColumnLabel('number') }}<span :class="{ active: orderSort.key === 'number' }">{{ orderSort.key === 'number' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('date')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('date')" @click="setOrderSort('date')">{{ orderColumnLabel('date') }}<span :class="{ active: orderSort.key === 'date' }">{{ orderSort.key === 'date' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('source')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('source')" @click="setOrderSort('source')">{{ orderColumnLabel('source') }}<span :class="{ active: orderSort.key === 'source' }">{{ orderSort.key === 'source' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('total')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('total')" @click="setOrderSort('total')">{{ orderColumnLabel('total') }}<span :class="{ active: orderSort.key === 'total' }">{{ orderSort.key === 'total' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('payment')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('payment')" @click="setOrderSort('payment')">{{ orderColumnLabel('payment') }}<span :class="{ active: orderSort.key === 'payment' }">{{ orderSort.key === 'payment' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('status')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('status')" @click="setOrderSort('status')">{{ orderColumnLabel('status') }}<span :class="{ active: orderSort.key === 'status' }">{{ orderSort.key === 'status' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in paginatedOrders" :key="order.id" :class="{ 'table-active': selectedOrder?.id === order.id }" @click="selectOrder(order)">
                <td v-if="orderColumnVisible('number')"><strong>{{ order.order_number }}</strong></td>
                <td v-if="orderColumnVisible('date')">{{ shortDate(order.placed_at) }}</td>
                <td v-if="orderColumnVisible('source')">{{ statusLabel(order.source) }}</td>
                <td v-if="orderColumnVisible('total')">{{ money(order.grand_total_minor, order.currency) }}</td>
                <td v-if="orderColumnVisible('payment')">{{ statusLabel(order.payment_status) }}</td>
                <td v-if="orderColumnVisible('status')">{{ statusLabel(order.status) }}</td>
              </tr>
              <tr v-if="!paginatedOrders.length && !loading">
                <td :colspan="orderColumns.length" class="text-muted p-3">{{ t('sale.empty.filteredOrders') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="sale-pagination" :aria-label="`${t('common.page')} ${t('sale.orders.title')}`">
          <label>
            {{ t('sale.orders.rows') }}
            <select class="select" :value="orderPageSize" @change="onOrderPageSizeChange">
              <option v-for="size in orderPageSizeOptions" :key="String(size)" :value="size">{{ orderPageSizeLabel(size) }}</option>
            </select>
          </label>
          <span>{{ orderPaginationLabel }}</span>
          <div class="sale-pagination-actions">
            <button class="btn ghost btn-sm" type="button" :disabled="orderPage <= 1 || loading || orderPageSize === 'all'" @click="setOrderPage(orderPage - 1)">{{ t('common.previous') }}</button>
            <strong>{{ t('common.page') }} {{ orderPage }} / {{ orderPageCount }}</strong>
            <button class="btn ghost btn-sm" type="button" :disabled="orderPage >= orderPageCount || loading || orderPageSize === 'all'" @click="setOrderPage(orderPage + 1)">{{ t('common.next') }}</button>
          </div>
        </div>
      </section>

      <aside class="sale-admin__detail" v-if="selectedOrder">
        <div class="sale-admin__section-head">
          <div>
            <h2>{{ selectedOrder.order_number }}</h2>
            <p>{{ statusLabel(selectedOrder.source) }} · {{ shortDate(selectedOrder.placed_at) }}</p>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" @click="printReceipt">{{ t('sale.orders.printReceipt') }}</button>
        </div>

        <div class="sale-admin__totals">
          <span>{{ t('common.total') }}</span>
          <strong>{{ money(selectedOrder.grand_total_minor, selectedOrder.currency) }}</strong>
          <span>{{ t('sale.status.paid') }}</span>
          <strong>{{ money(selectedOrder.paid_total_minor, selectedOrder.currency) }}</strong>
        </div>

        <router-link v-if="context.can('business.crm.read') && (Number(selectedOrder.customer_contact_id || 0) > 0 || Number(selectedOrder.customer_company_id || 0) > 0)" class="btn btn-outline-secondary btn-sm" to="/business">
          Ouvrir la relation CRM · contact #{{ selectedOrder.customer_contact_id || '—' }} · organisation #{{ selectedOrder.customer_company_id || '—' }}
        </router-link>

        <h3>{{ t('sale.orders.lines') }}</h3>
        <div v-for="line in selectedOrder.lines || []" :key="String(line.id)" class="sale-admin__line">
          <span>{{ line.product_name }} <small>{{ line.sku }}</small></span>
          <b>{{ line.quantity }} × {{ money(line.unit_price_minor, line.currency) }}</b>
        </div>

        <h3>{{ t('sale.orders.payments') }}</h3>
        <div v-if="!orderPayments.length" class="text-muted">{{ t('sale.empty.payments') }}</div>
        <div v-for="payment in orderPayments" :key="String(payment.id)" class="sale-admin__line">
          <span>{{ statusLabel(payment.transaction_type) }} · {{ statusLabel(payment.status) }}</span>
          <b>{{ money(payment.amount_minor, payment.currency) }}</b>
        </div>

        <div class="sale-admin__actions-block">
          <label class="form-label">{{ t('sale.orders.manualPaymentAmount') }}</label>
          <select v-model="manualProvider" class="select"><option value="manual_card">{{ t('sale.payments.manual') }}</option><option value="bank_transfer">{{ t('sale.payments.bankTransfer') }}</option></select>
          <div class="input-group">
            <input v-model.number="paymentAmount" class="form-control" type="number" min="1" step="1">
            <button class="btn btn-primary" type="button" :disabled="saving || paymentAmount < 1" @click="recordPayment">{{ t('sale.orders.recordPayment') }}</button>
          </div>
          <small>{{ t('sale.payments.impact', { amount: money(paymentAmount, selectedOrder.currency), balance: money(Math.max(0, Number(selectedOrder.grand_total_minor || 0) - Number(selectedOrder.paid_total_minor || 0) - paymentAmount), selectedOrder.currency) }) }}</small>
          <input v-model="paymentReference" class="form-control" type="text" :placeholder="t('sale.payments.referenceOptional')">
          <input v-model="paymentComment" class="form-control" type="text" :placeholder="t('sale.payments.commentOptional')">
          <input v-model.number="proofAssetId" class="form-control" type="number" min="1" :placeholder="t('sale.payments.proofOptional')">
        </div>

        <div class="sale-admin__actions-block">
          <label class="form-label">{{ t('sale.orders.cancelReason') }}</label>
          <input v-model="cancelReason" class="form-control" type="text" :placeholder="t('common.optional')">
          <button class="btn btn-outline-danger mt-2" type="button" :disabled="saving || selectedOrder.status === 'cancelled'" @click="cancelOrder">{{ t('sale.orders.cancelOrder') }}</button>
        </div>

        <h3>{{ t('sale.orders.events') }}</h3>
        <div v-if="!orderEvents.length" class="text-muted">{{ t('common.none') }}</div>
        <div v-for="event in orderEvents" :key="String(event.id)" class="sale-admin__line">
          <span>{{ event.event_type }}</span>
          <small>{{ shortDate(event.created_at) }}</small>
        </div>
      </aside>
      <section class="sale-admin__section">
        <h2>{{ t('sale.payments.webhookQueue') }}</h2>
        <div v-if="!paymentWebhookEvents.length" class="text-muted">{{ t('common.none') }}</div>
        <div v-for="event in paymentWebhookEvents" :key="String(event.id)" class="sale-admin__list-row">
          <span>{{ event.provider_key }} · {{ event.event_type }} <small>{{ event.order_number || '—' }}</small></span>
          <b>{{ statusLabel(String(event.processing_status)) }}</b>
          <small>{{ Math.max(0, Number(event.age_seconds || 0)) }} s · {{ event.error_code || '—' }}</small>
          <button v-if="['failed','received'].includes(String(event.processing_status))" class="btn btn-sm btn-outline-primary" type="button" :disabled="saving" @click="retryWebhook(event)">{{ t('sale.payments.retryWebhook') }}</button>
          <details><summary>{{ t('sale.payments.technical') }}</summary><pre>{{ jsonText(event.payload_json ? JSON.parse(String(event.payload_json)) : {}) }}</pre></details>
        </div>
      </section>
    </section>

    <section v-if="activeTab === 'payments'" class="sale-admin__panel sale-admin__orders">
      <section class="sale-admin__section sale-orders-list">
        <BusinessPageHeader :eyebrow="t('sale.title')" :title="t('sale.payments.title')" />
        <div v-if="paymentSessions.some((payment) => payment.test_mode)" class="alert alert-warning"><b>MODE TEST</b> — {{ t('sale.payments.testWarning') }}</div>
        <details class="sale-admin__technical" :open="Number(paymentExceptionCenter.open_count || 0) > 0">
          <summary>{{ t('sale.payments.exceptionCenter') }} · {{ Number(paymentExceptionCenter.open_count || 0) }} · {{ t(`sale.payments.health.${paymentExceptionCenter.health || 'healthy'}`) }}</summary>
          <p>{{ t('sale.payments.pendingOperations') }}: <b>{{ Number(paymentExceptionCenter.pending_operations || 0) }}</b></p>
          <div v-for="(count, cause) in paymentExceptionCenter.groups || {}" :key="cause" class="sale-admin__line"><span>{{ statusLabel(String(cause)) }}</span><b>{{ count }}</b></div>
          <div v-for="item in paymentExceptionCenter.items || []" :key="String(item.id)" class="sale-admin__list-row">
            <span>{{ item.order_number || '—' }} <small>{{ item.provider_key }} · {{ item.priority }}</small></span>
            <b>{{ money(item.amount_minor, item.currency) }}</b>
            <small>{{ findingLabels(item.findings) }} · {{ statusLabel(String(item.recommended_action || '')) }}</small>
            <button class="btn btn-sm btn-outline-primary" type="button" :disabled="saving" @click="resolvePaymentException(item)">{{ t('sale.payments.markResolved') }}</button>
          </div>
        </details>
        <button class="btn btn-outline-primary btn-sm" type="button" @click="showBankQueue">{{ t('sale.payments.bankQueue') }}</button>
        <form class="sale-toolbar" @submit.prevent="applyPaymentFilters">
          <input v-model="paymentSearch" class="form-control" type="search" :aria-label="t('sale.payments.searchPlaceholder')" :placeholder="t('sale.payments.searchPlaceholder')">
          <select v-model="paymentFilter.status" class="select" :aria-label="t('common.status')">
            <option value="">{{ t('sale.payments.allStatuses') }}</option>
            <option v-for="status in ['requires_payment','requires_action','authorized','partially_captured','captured','cancelled','failed','expired']" :key="status" :value="status">{{ statusLabel(status) }}</option>
          </select>
          <input v-model="paymentFilter.provider" class="form-control" type="text" :aria-label="t('sale.payments.provider')" :placeholder="t('sale.payments.provider')">
          <button class="btn btn-primary btn-sm" type="submit">{{ t('common.filters') }}</button>
          <button class="btn btn-outline-secondary btn-sm" type="button" @click="clearPaymentFilters">{{ t('common.reset') }}</button>
        </form>
        <div v-if="!paymentSessions.length" class="text-muted">{{ t('sale.empty.payments') }}</div>
        <button v-for="payment in paymentSessions" :key="String(payment.id)" class="sale-admin__list-row" type="button" @click="selectPayment(payment)">
          <span>{{ payment.order_number }} <small>{{ payment.customer?.name || payment.customer?.email || '—' }}</small></span>
          <b>{{ money(payment.amount_minor, payment.currency) }}</b>
          <small>{{ payment.state?.label }} · {{ payment.state?.next_action_label || payment.state?.next_action }}</small>
        </button>
      </section>

      <aside v-if="selectedPayment" class="sale-admin__detail">
        <div class="sale-admin__section-head">
          <div><h2>{{ selectedPayment.order_number }}</h2><p>{{ selectedPayment.state?.label }}</p></div>
          <strong>{{ money(selectedPayment.amount_minor, selectedPayment.currency) }}</strong>
        </div>
        <p><b>{{ t('sale.payments.nextAction') }}:</b> {{ selectedPayment.state?.next_action_label || selectedPayment.state?.next_action }}</p>
        <p><b>{{ t('sale.payments.customer') }}:</b> {{ selectedPayment.customer?.name || '—' }} · {{ selectedPayment.customer?.email || '—' }}</p>
        <div v-if="selectedPayment.instructions" class="alert alert-info"><b>{{ t('sale.payments.bankTransfer') }}</b><pre>{{ jsonText(selectedPayment.instructions) }}</pre></div>
        <div class="sale-admin__totals">
          <span>{{ t('sale.payments.authorized') }}</span><strong>{{ money(selectedPayment.authorized_minor, selectedPayment.currency) }}</strong>
          <span>{{ t('sale.payments.captured') }}</span><strong>{{ money(selectedPayment.captured_minor, selectedPayment.currency) }}</strong>
          <span>{{ t('sale.payments.refunded') }}</span><strong>{{ money(selectedPayment.refunded_minor, selectedPayment.currency) }}</strong>
        </div>
        <div v-if="['requires_payment','requires_action','authorized','partially_captured'].includes(String(selectedPayment.status || ''))" class="sale-admin__actions-block">
          <h3>{{ t('sale.payments.guidedConfirmation') }}</h3>
          <label>{{ t('sale.orders.manualPaymentAmount') }}<input v-model.number="confirmAmount" class="form-control" type="number" min="1"></label>
          <small>{{ t('sale.payments.remaining') }}: {{ money(Math.max(0, Number(selectedPayment.amount_minor || 0) - Number(selectedPayment.captured_minor || 0)), selectedPayment.currency) }}</small>
          <input v-model="paymentReference" class="form-control" type="text" :placeholder="t('sale.payments.referenceOptional')">
          <input v-model="paymentComment" class="form-control" type="text" :placeholder="t('sale.payments.commentOptional')">
          <select v-if="selectedPayment.capabilities?.capture" v-model="captureReasonCode" class="select">
            <option value="order_ready">{{ t('sale.payments.captureReason.orderReady') }}</option>
            <option value="partial_fulfillment">{{ t('sale.payments.captureReason.partialFulfillment') }}</option>
            <option value="service_delivered">{{ t('sale.payments.captureReason.serviceDelivered') }}</option>
            <option value="manual_review">{{ t('sale.payments.captureReason.manualReview') }}</option>
          </select>
          <input v-model.number="proofAssetId" class="form-control" type="number" min="1" :placeholder="t('sale.payments.proofOptional')">
          <button class="btn btn-primary" type="button" :disabled="saving || confirmAmount < 1" @click="confirmSelectedPayment">{{ t('sale.payments.confirmReceipt') }}</button>
        </div>
        <div v-if="Number(selectedPayment.refundable_minor || 0) > 0" class="sale-admin__actions-block">
          <h3>{{ t('sale.payments.guidedRefund') }}</h3>
          <select v-model.number="refundTransactionId" class="select" @change="refundAmount = Number((selectedPayment?.captures || []).find((capture) => Number(capture.id) === refundTransactionId)?.refundable_minor || 0)">
            <option v-for="capture in (selectedPayment.captures || []).filter((item) => Number(item.refundable_minor || 0) > 0)" :key="String(capture.id)" :value="Number(capture.id)">#{{ capture.id }} · {{ money(capture.refundable_minor, selectedPayment.currency) }}</option>
          </select>
          <label>{{ t('sale.payments.refundAmount') }}<input v-model.number="refundAmount" class="form-control" type="number" min="1"></label>
          <select v-model="refundReasonCode" class="select">
            <option value="customer_request">{{ t('sale.payments.refundReason.customerRequest') }}</option>
            <option value="return">{{ t('sale.payments.refundReason.return') }}</option>
            <option value="duplicate">{{ t('sale.payments.refundReason.duplicate') }}</option>
            <option value="fraud">{{ t('sale.payments.refundReason.fraud') }}</option>
            <option value="service_failure">{{ t('sale.payments.refundReason.serviceFailure') }}</option>
            <option value="commercial_gesture">{{ t('sale.payments.refundReason.commercialGesture') }}</option>
            <option value="other">{{ t('sale.payments.refundReason.other') }}</option>
          </select>
          <input v-model="refundReasonNote" class="form-control" type="text" :placeholder="t('sale.payments.reasonNote')">
          <button class="btn btn-outline-primary" type="button" :disabled="saving || refundAmount < 1 || refundTransactionId < 1" @click="refundSelectedPayment">{{ t('sale.payments.requestRefund') }}</button>
        </div>
        <h3>{{ t('sale.payments.timeline') }}</h3>
        <div v-for="(event, index) in selectedPayment.timeline || []" :key="`${event.kind}-${index}`" class="sale-admin__line">
          <span>{{ statusLabel(event.kind) }} · {{ statusLabel(event.status) }}</span><small>{{ shortDate(event.at) }}</small>
        </div>
        <details class="sale-admin__technical"><summary>{{ t('sale.payments.technical') }}</summary><pre>{{ jsonText(selectedPayment.technical) }}</pre></details>
      </aside>
    </section>

    <section v-if="activeTab === 'pos'" class="sale-admin__panel sale-admin__pos">
      <SalePosView embedded />
    </section>

    <section v-if="activeTab === 'stock'" class="sale-admin__panel sale-admin__stock">
      <SaleStockView />
    </section>

    <section v-if="activeTab === 'reservations'" class="sale-admin__panel sale-admin__stock">
      <SaleReservationsView />
    </section>

    <section v-if="activeTab === 'operations'" class="sale-admin__panel sale-admin__stock">
      <SaleOperationsView />
    </section>

    <section v-if="activeTab === 'identities'" class="sale-admin__panel sale-admin__stock">
      <SaleIdentityReviewView />
    </section>

    <section v-if="activeTab === 'settings'" class="sale-admin__panel">
      <div class="sale-admin__grid">
        <section class="sale-admin__section">
          <h2>{{ t('sale.settings.channels') }}</h2>
          <div v-for="channel in channels" :key="channel.id" class="sale-admin__list-row">
            <span>{{ channel.name }} <small>{{ channel.code }}</small></span>
            <b>{{ statusLabel(channel.channel_type) }}</b>
            <small>{{ statusLabel(channel.status) }} · {{ channel.is_public ? t('common.public') : t('common.private') }} · {{ channel.price_tax_included ? t('sale.tax.included') : t('sale.tax.excluded') }}</small>
            <a class="btn btn-sm btn-outline-secondary" :href="saleCatalogPdfUrl(channel)">{{ t('sale.settings.brochure') }}</a>
          </div>
        </section>
        <section class="sale-admin__section">
          <h2>{{ t('sale.settings.paymentMethods') }}</h2>
          <div v-for="status in providerStatuses" :key="String(status.provider)" class="alert" :class="status.connected ? 'alert-success' : 'alert-warning'">
            <b>{{ status.label || status.provider }} · {{ String(status.environment).toUpperCase() }}</b>
            <p>{{ status.connected ? t('sale.payments.providerConnected') : t('sale.payments.providerNotConnected') }}</p>
            <small>{{ t('sale.payments.webhookUrl') }}: <code>{{ status.webhook_url }}</code></small>
            <p v-if="status.provider === 'stripe_checkout'"><small>TWINT: {{ status.twint_mode || 'dynamic' }}</small></p>
            <p><small>{{ t('sale.payments.secretMasked') }} · {{ t('sale.payments.lastVerification') }}: {{ status.last_verified_at || '—' }}</small></p>
          </div>
          <div v-for="method in paymentMethods" :key="String(method.id)" class="sale-admin__list-row">
            <span>{{ method.name }}</span>
            <b>{{ method.method_type }}</b>
            <small>{{ statusLabel(method.status) }}</small>
          </div>
          <div v-if="!paymentMethods.length" class="text-muted">{{ t('sale.empty.paymentMethods') }}</div>
        </section>
        <section class="sale-admin__section">
          <h2>{{ t('sale.settings.fulfillmentMethods') }}</h2>
          <div v-for="method in fulfillmentMethods" :key="String(method.id)" class="sale-admin__list-row">
            <span>{{ languageCode === 'en' ? method.label_en : method.label_fr }} <small>{{ method.code }}</small></span>
            <b>{{ money(method.flat_rate_minor, 'CHF') }}</b>
            <small>{{ statusLabel(method.status) }} · {{ method.fulfillment_type }} · {{ method.requires_shipping_address ? t('sale.fulfillment.addressRequired') : t('sale.fulfillment.addressOptional') }}</small>
          </div>
          <div v-if="!fulfillmentMethods.length" class="text-muted">{{ t('sale.empty.fulfillmentMethods') }}</div>
        </section>
        <section class="sale-admin__section">
          <h2>{{ t('sale.settings.posSessions') }}</h2>
          <div v-for="session in sessions" :key="String(session.id)" class="sale-admin__list-row">
            <span>{{ session.register_id }}</span>
            <b>{{ money(session.expected_cash_minor, session.currency) }}</b>
            <small>{{ statusLabel(session.status) }}</small>
          </div>
          <div v-if="!sessions.length" class="text-muted">{{ t('sale.empty.sessions') }}</div>
        </section>
      </div>
    </section>
  </section>
</template>

<style scoped>
.sale-admin {
  --sale-border: #d0d5dd;
}
.sale-admin__section-head,
.sale-admin__list-row,
.sale-admin__line {
  align-items: center;
  display: flex;
  gap: .75rem;
}
.sale-admin__section h2,
.sale-admin__detail h2,
.sale-admin__detail h3 {
  margin: 0;
}
.sale-admin__technical pre { max-height: 24rem; overflow: auto; white-space: pre-wrap; }
.sale-tabs {
  flex-wrap: wrap;
  overflow-x: visible;
  scrollbar-width: none;
}
.sale-tabs .editor-tab {
  flex: 0 1 auto;
}
.sale-admin__panel {
  display: grid;
  gap: 1rem;
}
.sale-admin__metrics {
  display: grid;
  gap: .75rem;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}
.sale-admin__metric,
.sale-admin__section,
.sale-admin__detail {
  background: #fff;
  border: 1px solid var(--sale-border);
  border-radius: 8px;
  padding: 1rem;
}
.sale-admin__metric {
  display: grid;
  gap: .25rem;
}
.sale-admin__metric span,
.sale-admin__list-row small,
.sale-admin__line small,
.sale-admin__section-head p {
  color: #667085;
}
.sale-admin__metric strong {
  font-size: 1.35rem;
}
.sale-admin__grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}
.sale-admin__section {
  display: grid;
  gap: .75rem;
}
.sale-admin__section-head {
  justify-content: space-between;
}
.sale-admin__list-row {
  background: #fff;
  border: 0;
  border-bottom: 1px solid #edf0f3;
  justify-content: space-between;
  min-height: 42px;
  padding: .35rem 0;
  text-align: left;
  width: 100%;
}
.sale-admin__list-row span {
  display: grid;
}
.sale-admin__orders {
  align-items: start;
  grid-template-columns: minmax(0, 1fr) minmax(320px, 440px);
}
.sale-orders-list {
  min-width: 0;
}
.sale-toolbar,
.sale-toolbar-buttons {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: .55rem;
}
.sale-toolbar {
  display: grid;
  grid-template-columns: minmax(280px, 1fr) auto;
}
.sale-toolbar-buttons {
  justify-content: flex-end;
}
.sale-search-control {
  align-items: center;
  background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, .04), inset 0 1px 0 rgba(255, 255, 255, .85);
  display: grid;
  gap: .55rem;
  grid-template-columns: auto minmax(0, 1fr) auto;
  min-height: 2.75rem;
  padding: .25rem .45rem .25rem .85rem;
}
.sale-search-control:focus-within {
  background: #fff;
  border-color: #2563eb;
  box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
}
.sale-search-control svg {
  color: #64748b;
  height: 1.05rem;
  width: 1.05rem;
}
.sale-search-control input {
  appearance: none;
  -webkit-appearance: none;
  background: transparent;
  border: 0;
  box-shadow: none !important;
  color: #0f172a;
  font: inherit;
  min-height: 2.1rem;
  min-width: 0;
  outline: 0 !important;
  width: 100%;
}
.sale-search-control button {
  background: #e2e8f0;
  border: 0;
  border-radius: 999px;
  color: #334155;
  height: 1.85rem;
  line-height: 1;
  width: 1.85rem;
}
.sale-menu {
  position: relative;
  z-index: 3;
}
.sale-menu[open] {
  z-index: 20;
}
.sale-menu summary {
  align-items: center;
  border: 1px solid var(--sale-border);
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  font-weight: 700;
  justify-content: center;
  list-style: none;
  min-height: 2.1rem;
  min-width: 2.1rem;
  user-select: none;
}
.sale-menu summary::-webkit-details-marker {
  display: none;
}
.sale-icon-summary svg {
  fill: currentColor;
  height: 1.1rem;
  width: 1.1rem;
}
.sale-menu-panel {
  background: #fff;
  border: 1px solid var(--sale-border);
  border-radius: 8px;
  box-shadow: 0 14px 28px rgba(15, 23, 42, .16);
  display: grid;
  gap: .2rem;
  margin-top: .35rem;
  min-width: 170px;
  padding: .35rem;
  position: absolute;
  right: 0;
  z-index: 8;
}
.sale-menu-panel button,
.sale-menu-panel a {
  background: transparent;
  border: 0;
  border-radius: 6px;
  color: #344054;
  padding: .45rem .55rem;
  text-align: left;
  text-decoration: none;
}
.sale-menu-panel button:not(:disabled):hover,
.sale-menu-panel a:hover {
  background: #f2f4f7;
}
.sale-menu-panel button:disabled {
  color: #98a2b3;
}
.sale-filter-panel {
  min-width: 260px;
}
.sale-filter-panel label {
  color: #344054;
  display: grid;
  gap: .25rem;
  padding: .35rem .45rem;
}
.sale-filter-panel select,
.sale-column-panel label {
  border: 1px solid var(--sale-border);
  border-radius: 6px;
  min-height: 2.25rem;
  padding: .4rem .55rem;
}
.sale-filter-actions {
  display: flex;
  gap: .45rem;
  padding: .35rem .45rem;
}
.sale-column-panel {
  min-width: 210px;
}
.sale-column-panel strong,
.sale-filter-panel strong {
  color: #344054;
  font-size: .82rem;
  padding: .3rem .45rem;
}
.sale-column-panel label {
  align-items: center;
  border: 0;
  color: #344054;
  display: grid;
  gap: .45rem;
  grid-template-columns: auto 1fr;
}
.sale-admin__table-wrap {
  background: #fff;
  border: 1px solid var(--sale-border);
  border-radius: 8px;
  overflow: auto;
}
.sale-admin__table-wrap table {
  margin-bottom: 0;
}
.sale-admin__table-wrap tbody tr {
  cursor: pointer;
}
.sale-orders-table th {
  color: #475467;
  font-size: .78rem;
  letter-spacing: .02em;
  text-transform: uppercase;
}
.sale-orders-table td {
  vertical-align: middle;
}
.sale-sort-button {
  align-items: center;
  background: transparent;
  border: 0;
  color: inherit;
  display: inline-flex;
  font: inherit;
  font-weight: 800;
  gap: .35rem;
  padding: 0;
  text-align: left;
  text-transform: inherit;
}
.sale-sort-button span {
  align-items: center;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  color: #98a2b3;
  display: inline-flex;
  font-size: .68rem;
  height: 1.05rem;
  justify-content: center;
  line-height: 1;
  width: 1.05rem;
}
.sale-sort-button span.active {
  border-color: #0f172a;
  color: #0f172a;
}
.sale-pagination {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: .75rem;
  justify-content: space-between;
  padding: .75rem .1rem 0;
}
.sale-pagination label,
.sale-pagination-actions {
  align-items: center;
  display: inline-flex;
  gap: .5rem;
}
.sale-pagination label {
  color: #667085;
  font-size: .84rem;
  font-weight: 700;
}
.sale-pagination .select {
  min-width: 5.5rem;
}
.sale-pagination > span,
.sale-pagination-actions strong {
  color: #475467;
  font-size: .84rem;
  white-space: nowrap;
}
.sale-admin__detail {
  display: grid;
  gap: 1rem;
  position: sticky;
  top: 1rem;
}
.sale-admin__totals {
  border-bottom: 1px solid #edf0f3;
  border-top: 1px solid #edf0f3;
  display: grid;
  gap: .4rem;
  grid-template-columns: 1fr auto;
  padding: .75rem 0;
}
.sale-admin__line {
  border-bottom: 1px solid #edf0f3;
  justify-content: space-between;
  padding-bottom: .5rem;
}
.sale-admin__line span {
  display: grid;
}
.sale-admin__actions-block {
  display: grid;
  gap: .4rem;
}
@media (max-width: 980px) {
  .sale-admin__orders {
    grid-template-columns: 1fr;
  }
  .sale-admin__detail {
    position: static;
  }
}
@media (max-width: 760px) {
  .sale-toolbar {
    grid-template-columns: 1fr;
  }
  .sale-toolbar-buttons {
    justify-content: flex-start;
  }
}
</style>
