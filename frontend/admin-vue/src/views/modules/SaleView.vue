<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';
import { hasTranslation, useI18n } from '@/i18n';
import { useAdminContextStore } from '@/stores/adminContext';
import PageHeader from '@/components/ui/PageHeader.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import ModuleSecondaryNavigation from '@/components/navigation/ModuleSecondaryNavigation.vue';
import BusinessPageHeader from './business/BusinessPageHeader.vue';
import SalePosView from './SalePosView.vue';
import SaleStockView from './SaleStockView.vue';
import SaleReservationsView from './SaleReservationsView.vue';
import SaleOperationsView from './SaleOperationsView.vue';
import SaleEcommerceSettings from './SaleEcommerceSettings.vue';

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
  actionable?: { tasks?: Row[]; groups?: Record<string, number>; total?: number };
};
type OrderDossier = {
  order: Order;
  state: Row & { key?: string; label?: string; next_action?: string; next_action_label?: string; blockers?: string[]; actions?: Row[]; internal?: Row };
  payments: { summary?: Row; plan?: Row | null; intents?: Row[]; transactions?: Row[]; refunds?: Row[] };
  fulfillment: { summary?: Row; operations?: Row[]; backorders?: Row[] };
  documents?: Row[];
  returns?: Row[];
  messages?: Row[];
  timeline?: Row[];
  relation?: Row;
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
type OrderColumnKey = 'number' | 'relation' | 'date' | 'source' | 'total' | 'payment' | 'fulfillment' | 'status';
type OrderFilterKey = 'source' | 'status' | 'payment_status';
type QuickOrderFilterKey = 'all' | 'placed' | 'confirmed' | 'completed' | 'ecommerce' | 'pos' | 'payment_pending' | 'paid';
type SettingsSection = 'policies' | 'channels' | 'payments' | 'fulfillment' | 'pos' | 'ecommerce';
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
const selectedDossier = ref<OrderDossier | null>(null);
const orderDetailOpen = ref(false);
const documentPreview = ref<Row | null>(null);
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
const salePolicies = ref<Row>({});
const sessions = ref<Row[]>([]);
const paymentAmount = ref(0);
const cancelReason = ref('');
const activeSettingsSection = ref<SettingsSection>('policies');
const orderSearch = ref('');
const orderFilter = ref({ source: '', status: '', payment_status: '' });
const appliedOrderFilter = ref({ source: '', status: '', payment_status: '' });
const orderPage = ref(1);
const orderPageSize = ref<number | 'all'>(25);
const orderSort = ref<{ key: OrderColumnKey; direction: SortDirection }>({ key: 'date', direction: 'desc' });
const visibleOrderColumns = ref<Record<OrderColumnKey, boolean>>({
  number: true,
  relation: true,
  date: true,
  source: true,
  total: true,
  payment: true,
  fulfillment: true,
  status: true
});

const navigationItems = computed(() => [
  { key: 'dashboard', label: t('sale.tabs.dashboard'), route: '/sale', visible: context.can('sale.read') },
  { key: 'orders', label: t('sale.tabs.orders'), route: '/sale/orders', visible: context.can('sale.orders.read') },
  { key: 'payments', label: t('sale.tabs.paymentsInvoices'), route: '/sale/payments', visible: context.can('sale.payments.read') },
  { key: 'pos', label: t('sale.tabs.pos'), route: '/sale/pos', visible: context.can('sale.pos.use') },
  { key: 'settings', label: t('sale.tabs.settings'), route: '/sale/settings', visible: context.can('sale.settings.manage') }
]);
const advancedItems = computed(() => [
  { key: 'stock', label: t('sale.advanced.stock'), route: '/sale/advanced/stock' },
  { key: 'reservations', label: t('sale.advanced.reservations'), route: '/sale/advanced/reservations' },
  { key: 'operations', label: t('sale.advanced.logistics'), route: '/sale/advanced/logistics' },
  { key: 'paymentDiagnostics', label: t('sale.advanced.paymentDiagnostics'), route: '/sale/advanced/payments' }
]);
const orderColumns: Array<{ key: OrderColumnKey; labelKey: string }> = [
  { key: 'number', labelKey: 'common.number' },
  { key: 'relation', labelKey: 'sale.orders.relation' },
  { key: 'date', labelKey: 'common.date' },
  { key: 'source', labelKey: 'common.source' },
  { key: 'total', labelKey: 'common.total' },
  { key: 'payment', labelKey: 'common.payment' },
  { key: 'fulfillment', labelKey: 'sale.orders.fulfillmentState' },
  { key: 'status', labelKey: 'common.status' }
];
const settingsSections = computed<Array<{ key: SettingsSection; label: string }>>(() => [
  { key: 'policies', label: t('sale.settings.orderPolicies') },
  { key: 'channels', label: t('sale.settings.channels') },
  { key: 'payments', label: t('sale.settings.paymentMethods') },
  { key: 'fulfillment', label: t('sale.settings.fulfillmentMethods') },
  { key: 'pos', label: t('sale.settings.posSessions') },
  { key: 'ecommerce', label: t('sale.settings.ecommerce') }
]);

function settingsSectionFromRoute(): SettingsSection {
  const requested = String(route.query.section || '');
  return settingsSections.value.some((section) => section.key === requested) ? requested as SettingsSection : 'policies';
}

function selectSettingsSection(section: SettingsSection): void {
  activeSettingsSection.value = section;
  void router.replace({ path: '/sale/settings', query: section === 'policies' ? {} : { section } });
}
const orderPageSizeOptions: Array<number | 'all'> = [10, 25, 50, 'all'];
const orderSourceOptions = ['ecommerce', 'pos', 'admin'];
const orderStatusOptions = ['placed', 'confirmed', 'completed', 'cancelled'];
const orderPaymentStatusOptions = ['unpaid', 'pending', 'partially_paid', 'paid', 'failed'];
const quickOrderFilters = computed<Array<{ key: QuickOrderFilterKey; label: string }>>(() => [
  { key: 'all', label: t('common.all') },
  { key: 'placed', label: statusLabel('placed') },
  { key: 'confirmed', label: statusLabel('confirmed') },
  { key: 'completed', label: statusLabel('completed') },
  { key: 'ecommerce', label: statusLabel('ecommerce') },
  { key: 'pos', label: statusLabel('pos') },
  { key: 'payment_pending', label: statusLabel('pending') },
  { key: 'paid', label: statusLabel('paid') }
]);
const activeOrderFilterChips = computed<Array<{ key: OrderFilterKey; label: string }>>(() => {
  const chips: Array<{ key: OrderFilterKey; label: string }> = [];
  if (appliedOrderFilter.value.source) chips.push({ key: 'source', label: `${t('common.source')} · ${statusLabel(appliedOrderFilter.value.source)}` });
  if (appliedOrderFilter.value.payment_status) chips.push({ key: 'payment_status', label: `${t('common.payment')} · ${statusLabel(appliedOrderFilter.value.payment_status)}` });
  if (appliedOrderFilter.value.status) chips.push({ key: 'status', label: `${t('common.status')} · ${statusLabel(appliedOrderFilter.value.status)}` });
  return chips;
});

const activeTab = computed(() => {
  if (route.path.includes('/sale/orders')) return 'orders';
  if (route.path.includes('/sale/payments')) return 'payments';
  if (route.path.includes('/advanced/stock')) return 'stock';
  if (route.path.includes('/advanced/reservations')) return 'reservations';
  if (route.path.includes('/advanced/logistics')) return 'operations';
  if (route.path.includes('/advanced/payments')) return 'paymentDiagnostics';
  if (route.path.includes('/sale/pos')) return 'pos';
  if (route.path.includes('/sale/settings')) return 'settings';
  return 'dashboard';
});
const isAdvancedRoute = computed(() => route.path.startsWith('/sale/advanced/'));
const canUseAdvancedTools = computed(() => context.can('sale.advanced_tools.manage'));
const relationReturnTo = computed(() => {
  const target = typeof route.query.return_to === 'string' ? route.query.return_to : '';
  return /^\/business\/relations(?:\?(?:relation_type=(?:contact|company)&relation_id=\d+))?$/.test(target) ? target : '';
});

function openOrder(order: Row): void {
  const orderId = Number(order.id || order.order_id || 0);
  void router.push(orderId > 0 ? { path: '/sale/orders', query: { order_id: String(orderId) } } : '/sale/orders');
}

const filteredOrders = computed(() => {
  const q = orderSearch.value.trim().toLowerCase();
  return orders.value.filter((order) => {
    if (appliedOrderFilter.value.source && String(order.source || '') !== appliedOrderFilter.value.source) return false;
    if (appliedOrderFilter.value.status && String(order.status || '') !== appliedOrderFilter.value.status) return false;
    if (appliedOrderFilter.value.payment_status && String(order.payment_status || '') !== appliedOrderFilter.value.payment_status) return false;
    if (!q) return true;
    return [
      order.order_number,
      orderRelationLabel(order),
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

async function loadDashboard(): Promise<void> {
  const response = await adminApi.get<Dashboard>('/sale/dashboard');
  dashboard.value = response.data;
}

async function loadOrders(): Promise<void> {
  const response = await adminApi.get<{ orders: Order[] }>('/sale/orders', {
    limit: 100,
    q: orderSearch.value.trim(),
    status: appliedOrderFilter.value.status,
    payment_status: appliedOrderFilter.value.payment_status
  });
  orders.value = response.data.orders || [];
  const requestedOrderId = Number(route.query.order_id || 0);
  if (requestedOrderId > 0 && (selectedOrder.value?.id !== requestedOrderId || !selectedDossier.value || !orderDetailOpen.value)) {
    const listed = orders.value.find((order) => order.id === requestedOrderId);
    if (listed) {
      await selectOrder(listed);
      return;
    }
    const detail = await adminApi.get<{ order: Order }>(`/sale/orders/${requestedOrderId}`);
    await selectOrder(detail.data.order);
    return;
  }
  if (selectedOrder.value && !orders.value.some((order) => order.id === selectedOrder.value?.id)) {
    selectedOrder.value = null;
    orderPayments.value = [];
    orderEvents.value = [];
  }
}

async function loadSettings(): Promise<void> {
  const [channelResponse, methodResponse, sessionResponse, fulfillmentResponse, settingsResponse] = await Promise.all([
    adminApi.get<{ channels: Channel[] }>('/sale/channels', { limit: 100 }),
    adminApi.get<{ payment_methods: Row[]; provider_status?: ProviderStatus }>('/sale/payment-methods'),
    adminApi.get<{ sessions: Row[] }>('/sale/reports/pos-sessions'),
    adminApi.get<{ methods: Row[] }>('/sale/fulfillment'),
    adminApi.get<{ order_policies: Row }>('/sale/settings')
  ]);
  channels.value = channelResponse.data.channels || [];
  paymentMethods.value = methodResponse.data.payment_methods || [];
  providerStatus.value = methodResponse.data.provider_status || null;
  sessions.value = sessionResponse.data.sessions || [];
  fulfillmentMethods.value = fulfillmentResponse.data.methods || [];
  salePolicies.value = settingsResponse.data.order_policies || {};
}

async function loadPayments(): Promise<void> {
  const response = await adminApi.get<{ payment_sessions: PaymentSession[] }>('/sale/payments', {
    q: paymentSearch.value,
    status: paymentFilter.value.status,
    provider: paymentFilter.value.provider,
    sort: paymentSort.value,
    limit: 100
  });
  paymentSessions.value = response.data.payment_sessions || [];
  if (canUseAdvancedTools.value) {
    const observability = await adminApi.get<{ webhook_events?: Row[]; exception_center?: PaymentExceptionCenter }>('/sale/payments/observability');
    paymentWebhookEvents.value = observability.data.webhook_events || [];
    paymentExceptionCenter.value = observability.data.exception_center || {};
  } else {
    paymentWebhookEvents.value = [];
    paymentExceptionCenter.value = {};
  }
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
    if (isAdvancedRoute.value && !canUseAdvancedTools.value) return;
    if (activeTab.value === 'dashboard') await loadDashboard();
    if (activeTab.value === 'orders') await loadOrders();
    if (activeTab.value === 'payments') await loadPayments();
    if (activeTab.value === 'paymentDiagnostics') await loadPayments();
    if (activeTab.value === 'settings') {
      activeSettingsSection.value = settingsSectionFromRoute();
      await loadSettings();
    }
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
    const response = await adminApi.get<{ dossier: OrderDossier }>(`/sale/orders/${order.id}/dossier`);
    selectedDossier.value = response.data.dossier;
    selectedOrder.value = response.data.dossier.order;
    orderPayments.value = response.data.dossier.payments.transactions || [];
    orderEvents.value = response.data.dossier.timeline || [];
    const due = Math.max(0, Number(selectedOrder.value.grand_total_minor || 0) - Number(selectedOrder.value.paid_total_minor || 0));
    paymentAmount.value = due;
    orderDetailOpen.value = true;
    if (String(route.query.order_id || '') !== String(selectedOrder.value.id)) {
      void router.replace({ path: '/sale/orders', query: { order_id: String(selectedOrder.value.id) } });
    }
  } catch (err) {
    error.value = apiErrorMessage(err);
  }
}

function closeOrderDetail(): void {
  orderDetailOpen.value = false;
  documentPreview.value = null;
  if (route.query.order_id) void router.replace({ path: '/sale/orders' });
}

function viewDocument(document: Row): void {
  documentPreview.value = document;
}

function printDocument(): void {
  window.print();
}

async function issueDocument(documentType: string): Promise<void> {
  if (!selectedOrder.value?.id) return;
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const response = await adminApi.post<{ document: Row }>(`/sale/orders/${selectedOrder.value.id}/documents`, { document_type: documentType });
    notice.value = t('sale.orders.documentIssued');
    documentPreview.value = response.data.document;
    await selectOrder(selectedOrder.value);
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}

async function markOrderAvailable(): Promise<void> {
  if (!selectedOrder.value?.id) return;
  saving.value = true; error.value = ''; notice.value = '';
  try {
    await adminApi.post(`/sale/orders/${selectedOrder.value.id}/availability`, { idempotency_key: crypto.randomUUID() });
    notice.value = t('sale.orders.paymentRequestCreated');
    await selectOrder(selectedOrder.value);
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}

async function createOrderFulfillment(): Promise<void> {
  if (!selectedOrder.value?.id || !selectedDossier.value) return;
  const lines = (selectedOrder.value.lines || []).map((line) => ({
    order_line_id: Number(line.id),
    quantity: Math.max(0, Number(line.quantity || 0) - Number(line.fulfilled_quantity || 0))
  })).filter((line) => line.quantity > 0);
  const locationId = Number(selectedDossier.value.fulfillment.summary?.suggested_stock_location_id || selectedOrder.value.stock_location_id || 0);
  if (locationId < 1) { error.value = t('sale.orders.fulfillmentLocationMissing'); return; }
  saving.value = true; error.value = '';
  try {
    const shippingMethod = (selectedDossier.value.order.shipping_method || {}) as Row;
    await adminApi.post(`/sale/orders/${selectedOrder.value.id}/fulfillments`, { stock_location_id: locationId, fulfillment_type: shippingMethod.type === 'pickup' ? 'pickup' : 'shipping', lines });
    notice.value = t('sale.orders.fulfillmentCreated');
    await refreshOrderAfterFulfillment();
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}

async function transitionOrderFulfillment(fulfillment: Row, status: string): Promise<void> {
  saving.value = true; error.value = '';
  try {
    const payload: Row = { status };
    if (status === 'shipped') payload.tracking_reference = window.prompt(t('sale.orders.trackingReference')) || '';
    if (status === 'handed_over') payload.proof = window.prompt(t('sale.orders.handoverProof')) || '';
    await adminApi.post(`/sale/fulfillments/${fulfillment.id}/transition`, payload);
    notice.value = t('sale.orders.fulfillmentUpdated');
    await refreshOrderAfterFulfillment();
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}

async function prepareFulfillmentLine(fulfillment: Row, line: Row): Promise<void> {
  saving.value = true; error.value = ''; notice.value = '';
  try {
    await adminApi.patch(`/sale/fulfillments/${fulfillment.id}/lines/${line.id}`, { prepared_quantity: Number(line.quantity || 0) });
    notice.value = t('sale.orders.linePrepared');
    await refreshOrderAfterFulfillment();
  } catch (err) { error.value = apiErrorMessage(err); } finally { saving.value = false; }
}

async function refreshOrderAfterFulfillment(): Promise<void> {
  if (!selectedOrder.value) return;
  const current = selectedOrder.value;
  await selectOrder(current);
  await Promise.all([loadOrders(), loadDashboard()]);
}

function fulfillmentTransition(operation: Row): string {
  const next = String(operation.next_action || 'none');
  return ({ allocate: 'allocated', mark_ready: 'ready_for_pickup', ship: 'shipped', hand_over: 'handed_over', confirm_delivery: 'delivered' } as Record<string, string>)[next] || '';
}

function fulfillmentLines(operation: Row): Row[] {
  return Array.isArray(operation.lines) ? operation.lines as Row[] : [];
}

function canIssueDocument(type: string): boolean {
  if (!selectedOrder.value || !selectedDossier.value) return false;
  if (type === 'invoice') return selectedOrder.value.payment_status === 'paid';
  if (type === 'credit_note') return Number(selectedOrder.value.refunded_total_minor || 0) > 0;
  if (type === 'delivery_note') return (selectedDossier.value.fulfillment.operations || []).some((item) => ['shipped','handed_over','delivered','returned'].includes(String(item.status)));
  if (type === 'pos_receipt') return selectedOrder.value.source === 'pos' && selectedOrder.value.payment_status === 'paid';
  return selectedOrder.value.status !== 'draft';
}

function dossierAction(key: string): Row | undefined {
  return (selectedDossier.value?.state.actions || []).find((action) => String(action.key) === key);
}

function dossierActionEnabled(key: string): boolean {
  const action = dossierAction(key);
  return Boolean(action?.allowed && action?.permitted);
}

function canCloseSelectedOrder(): boolean {
  return selectedOrder.value?.status !== 'completed'
    && selectedDossier.value?.state.next_action === 'close_order'
    && dossierActionEnabled('close_order');
}

function hasLinkedCustomer(): boolean {
  return Number(selectedOrder.value?.customer_contact_id || 0) > 0 || Number(selectedOrder.value?.customer_company_id || 0) > 0;
}

function customerSnapshot(): Row {
  return (selectedOrder.value?.customer || {}) as Row;
}

function customerPersonName(): string {
  const customer = customerSnapshot();
  const structuredName = `${String(customer.first_name || '').trim()} ${String(customer.last_name || '').trim()}`.trim();
  return structuredName || String(customer.display_name || customer.name || '').trim();
}

function customerCompanyName(): string {
  return String(customerSnapshot().company_name || '').trim();
}

function customerCopyValue(): string {
  return customerPersonName() || customerCompanyName();
}

function orderCustomerSnapshot(order: Order): Row {
  if (order.customer && typeof order.customer === 'object' && !Array.isArray(order.customer)) return order.customer as Row;
  try {
    const decoded = JSON.parse(String(order.customer_snapshot_json || '{}'));
    return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded as Row : {};
  } catch {
    return {};
  }
}

function orderCustomerPersonName(order: Order): string {
  const customer = orderCustomerSnapshot(order);
  const structuredName = `${String(customer.first_name || '').trim()} ${String(customer.last_name || '').trim()}`.trim();
  return structuredName || String(customer.display_name || customer.name || '').trim();
}

function orderCustomerCompanyName(order: Order): string {
  return String(orderCustomerSnapshot(order).company_name || '').trim();
}

function orderRelationLabel(order: Order): string {
  const person = orderCustomerPersonName(order);
  const company = orderCustomerCompanyName(order);
  return person && company && person !== company ? `${person} / ${company}` : person || company;
}

async function writeClipboard(value: string, successMessage: string): Promise<void> {
  if (!value) return;
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
    } else {
      const field = document.createElement('textarea');
      field.value = value;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      const copied = document.execCommand('copy');
      field.remove();
      if (!copied) throw new Error('clipboard unavailable');
    }
    notice.value = successMessage;
  } catch {
    error.value = t('sale.orders.customerCopyFailed');
  }
}

async function copyCustomerName(): Promise<void> {
  await writeClipboard(customerCopyValue(), t('sale.orders.customerCopied'));
}

async function copyOrderRelation(order: Order): Promise<void> {
  await writeClipboard(orderRelationLabel(order), t('sale.orders.relationCopied'));
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

async function completeOrder(): Promise<void> {
  if (!selectedOrder.value?.id || !confirm(t('sale.orders.closeConfirm'))) return;
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const response = await adminApi.patch<{ order: Order }>(`/sale/orders/${selectedOrder.value.id}`, { status: 'completed', reason: t('sale.orders.closeReason') });
    notice.value = t('sale.orders.closed');
    selectedOrder.value = response.data.order;
    await selectOrder(selectedOrder.value);
    await Promise.all([loadOrders(), loadDashboard()]);
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
  if (key === 'relation') return orderRelationLabel(order);
  if (key === 'date') return Date.parse(String(order.placed_at || '')) || 0;
  if (key === 'source') return statusLabel(order.source);
  if (key === 'total') return Number(order.grand_total_minor || 0);
  if (key === 'payment') return statusLabel(order.payment_status);
  if (key === 'fulfillment') return statusLabel(order.fulfillment_status);
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
  appliedOrderFilter.value = { ...orderFilter.value };
  closeSaleMenus();
  void loadOrders();
}

function resetOrderFilters(): void {
  orderSearch.value = '';
  orderFilter.value = { source: '', status: '', payment_status: '' };
  appliedOrderFilter.value = { source: '', status: '', payment_status: '' };
  orderPage.value = 1;
  closeSaleMenus();
  void loadOrders();
}

function quickOrderFilterActive(key: QuickOrderFilterKey): boolean {
  const filter = appliedOrderFilter.value;
  if (key === 'all') return !filter.source && !filter.status && !filter.payment_status;
  if (key === 'ecommerce' || key === 'pos') return filter.source === key && !filter.status && !filter.payment_status;
  if (key === 'payment_pending') return filter.payment_status === 'pending' && !filter.source && !filter.status;
  if (key === 'paid') return filter.payment_status === 'paid' && !filter.source && !filter.status;
  return filter.status === key && !filter.source && !filter.payment_status;
}

function applyQuickOrderFilter(key: QuickOrderFilterKey): void {
  const next = { source: '', status: '', payment_status: '' };
  if (key === 'ecommerce' || key === 'pos') next.source = key;
  else if (key === 'payment_pending') next.payment_status = 'pending';
  else if (key === 'paid') next.payment_status = 'paid';
  else if (key !== 'all') next.status = key;
  orderFilter.value = { ...next };
  appliedOrderFilter.value = { ...next };
  orderPage.value = 1;
  closeSaleMenus();
  void loadOrders();
}

function removeOrderFilterChip(key: OrderFilterKey): void {
  orderFilter.value = { ...orderFilter.value, [key]: '' };
  appliedOrderFilter.value = { ...appliedOrderFilter.value, [key]: '' };
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
    orderRelationLabel(order),
    shortDate(order.placed_at),
    statusLabel(order.source),
    money(order.grand_total_minor, order.currency),
    statusLabel(order.payment_status),
    statusLabel(order.fulfillment_status),
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

watch(() => route.fullPath, () => { void load(); });
watch(() => [filteredOrders.value.length, orderPageSize.value], () => {
  if (orderPage.value > orderPageCount.value) orderPage.value = orderPageCount.value;
});
onMounted(load);
</script>

<template>
  <section class="page-stack sale-admin">
    <PageHeader :title="t('sale.title')" :intro="t('sale.intro')" help-id="modules.sale" />

    <ModuleSecondaryNavigation :label="`${t('common.mainNavigation')} ${t('sale.title')}`" :items="navigationItems" />

    <ApiFeedback :success="notice" :error="error" />
    <div v-if="isAdvancedRoute && !canUseAdvancedTools" class="alert alert-danger" role="alert">
      <h2>{{ t('sale.advanced.deniedTitle') }}</h2>
      <p>{{ t('sale.advanced.deniedMessage') }}</p>
    </div>

    <section v-if="isAdvancedRoute && canUseAdvancedTools" class="sale-admin__advanced-intro">
      <div>
        <strong>{{ t('sale.advanced.title') }}</strong>
        <p>{{ t('sale.advanced.intro') }}</p>
      </div>
      <ModuleSecondaryNavigation :label="t('sale.advanced.title')" :items="advancedItems" variant="compact" />
    </section>

    <section v-if="!isAdvancedRoute && activeTab === 'dashboard'" class="sale-admin__panel">
      <section class="sale-admin__section">
        <div class="sale-admin__section-head">
          <div><h2>{{ t('sale.dashboard.toProcess') }}</h2><p>{{ t('sale.dashboard.toProcessIntro') }}</p></div>
          <strong>{{ Number(dashboard.actionable?.total || 0) }}</strong>
        </div>
        <div v-if="!Number(dashboard.actionable?.total || 0)" class="alert alert-success">{{ t('sale.dashboard.nothingToProcess') }}</div>
        <button v-for="task in dashboard.actionable?.tasks || []" :key="String(task.order_id)" class="sale-admin__task" type="button" @click="openOrder({ id: task.order_id })">
          <span><b>{{ task.order_number }}</b><small>{{ task.customer }} · {{ task.channel }}</small></span>
          <strong>{{ money(task.total_minor, task.currency) }}</strong>
          <span class="sale-admin__task-action"><b>{{ (task.state as Row)?.label }}</b><small>{{ (task.state as Row)?.next_action_label }}</small></span>
        </button>
      </section>
    </section>

    <section v-if="activeTab === 'orders'" class="sale-admin__panel sale-admin__orders">
      <section class="sale-admin__section sale-orders-list">
        <BusinessPageHeader :eyebrow="t('sale.title')" :title="t('sale.orders.title')" />

        <form class="sale-toolbar" @submit.prevent="applyOrderFilters">
          <div class="sale-search-control">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <input v-model="orderSearch" type="search" :aria-label="t('sale.orders.searchPlaceholder')" :placeholder="t('sale.orders.searchPlaceholder')" @keyup.enter="applyOrderFilters">
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

        <nav class="sale-quick-filters" :aria-label="t('sale.orders.quickFiltersLabel')">
          <button
            v-for="filter in quickOrderFilters"
            :key="filter.key"
            type="button"
            :class="{ active: quickOrderFilterActive(filter.key) }"
            @click="applyQuickOrderFilter(filter.key)"
          >{{ filter.label }}</button>
        </nav>

        <div v-if="activeOrderFilterChips.length" class="sale-active-filters" :aria-label="t('sale.orders.activeFiltersLabel')">
          <button
            v-for="chip in activeOrderFilterChips"
            :key="chip.key"
            type="button"
            class="sale-filter-chip"
            :aria-label="t('sale.orders.removeFilter', { filter: chip.label })"
            @click="removeOrderFilterChip(chip.key)"
          >
            <span aria-hidden="true">×</span>
            <strong>{{ chip.label }}</strong>
          </button>
        </div>

        <div class="sale-admin__table-wrap">
          <table class="table table-hover align-middle sale-orders-table">
            <thead>
              <tr>
                <th v-if="orderColumnVisible('number')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('number')" @click="setOrderSort('number')">{{ orderColumnLabel('number') }}<span :class="{ active: orderSort.key === 'number' }">{{ orderSort.key === 'number' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('relation')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('relation')" @click="setOrderSort('relation')">{{ orderColumnLabel('relation') }}<span :class="{ active: orderSort.key === 'relation' }">{{ orderSort.key === 'relation' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('date')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('date')" @click="setOrderSort('date')">{{ orderColumnLabel('date') }}<span :class="{ active: orderSort.key === 'date' }">{{ orderSort.key === 'date' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('source')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('source')" @click="setOrderSort('source')">{{ orderColumnLabel('source') }}<span :class="{ active: orderSort.key === 'source' }">{{ orderSort.key === 'source' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('total')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('total')" @click="setOrderSort('total')">{{ orderColumnLabel('total') }}<span :class="{ active: orderSort.key === 'total' }">{{ orderSort.key === 'total' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('payment')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('payment')" @click="setOrderSort('payment')">{{ orderColumnLabel('payment') }}<span :class="{ active: orderSort.key === 'payment' }">{{ orderSort.key === 'payment' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('fulfillment')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('fulfillment')" @click="setOrderSort('fulfillment')">{{ orderColumnLabel('fulfillment') }}<span :class="{ active: orderSort.key === 'fulfillment' }">{{ orderSort.key === 'fulfillment' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th v-if="orderColumnVisible('status')"><button class="sale-sort-button" type="button" :aria-label="orderSortLabel('status')" @click="setOrderSort('status')">{{ orderColumnLabel('status') }}<span :class="{ active: orderSort.key === 'status' }">{{ orderSort.key === 'status' && orderSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                <th class="sale-orders-table__action"><span class="visually-hidden">{{ t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in paginatedOrders" :key="order.id">
                <td v-if="orderColumnVisible('number')"><button class="sale-order-number-button" type="button" :aria-label="t('sale.orders.openOrder', { number: String(order.order_number || order.id) })" @click="selectOrder(order)">{{ order.order_number || order.id }}</button></td>
                <td v-if="orderColumnVisible('relation')">
                  <button v-if="orderRelationLabel(order)" class="sale-order-relation-button" type="button" :aria-label="t('sale.orders.copyRelation', { relation: orderRelationLabel(order) })" @click="copyOrderRelation(order)">{{ orderRelationLabel(order) }}</button>
                  <span v-else>—</span>
                </td>
                <td v-if="orderColumnVisible('date')">{{ shortDate(order.placed_at) }}</td>
                <td v-if="orderColumnVisible('source')">{{ statusLabel(order.source) }}</td>
                <td v-if="orderColumnVisible('total')">{{ money(order.grand_total_minor, order.currency) }}</td>
                <td v-if="orderColumnVisible('payment')">{{ statusLabel(order.payment_status) }}</td>
                <td v-if="orderColumnVisible('fulfillment')">{{ statusLabel(order.fulfillment_status) }}</td>
                <td v-if="orderColumnVisible('status')">{{ statusLabel(order.status) }}</td>
                <td class="sale-orders-table__action"><button class="btn ghost btn-sm" type="button" @click="selectOrder(order)">{{ t('common.view') }}</button></td>
              </tr>
              <tr v-if="!paginatedOrders.length && !loading">
                <td :colspan="orderColumns.length + 1" class="text-muted p-3">{{ t('sale.empty.filteredOrders') }}</td>
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

      <div v-if="orderDetailOpen && selectedOrder && selectedDossier" class="sale-order-modal-backdrop" role="presentation" @click.self="closeOrderDetail">
      <aside class="sale-admin__detail sale-order-dossier sale-order-modal" role="dialog" aria-modal="true" :aria-label="String(selectedOrder.order_number || t('sale.orders.title'))">
        <button class="sale-order-modal__close" type="button" :aria-label="t('common.close')" @click="closeOrderDetail">×</button>
        <router-link v-if="relationReturnTo" class="btn btn-outline-secondary btn-sm mb-2" :to="relationReturnTo">
          {{ t('sale.orders.backToRelation') }}
        </router-link>
        <div class="sale-admin__section-head">
          <div>
            <h2>{{ selectedOrder.order_number }}</h2>
            <p>{{ selectedOrder.channel_name || statusLabel(selectedOrder.source) }} · {{ shortDate(selectedOrder.placed_at) }}</p>
          </div>
          <strong>{{ money(selectedOrder.grand_total_minor, selectedOrder.currency) }}</strong>
        </div>

        <section class="sale-order-dossier__state" aria-live="polite">
          <span>{{ t('common.status') }}</span>
          <strong>{{ selectedDossier.state.label }}</strong>
          <span>{{ t('sale.payments.nextAction') }}</span>
          <strong>{{ selectedDossier.state.next_action_label }}</strong>
          <small v-for="blocker in selectedDossier.state.blockers || []" :key="blocker">{{ t(`sale.blocker.${blocker}`) }}</small>
        </section>

        <button v-if="canCloseSelectedOrder()" class="btn btn-primary" type="button" :disabled="saving" @click="completeOrder">
          {{ t('sale.orders.closeOrder') }}
        </button>

        <div v-if="hasLinkedCustomer()" class="sale-order-customer">
          <div>
            <small>{{ t('sale.orders.customer') }}</small>
            <strong>{{ customerPersonName() || customerCompanyName() || '—' }}</strong>
            <span v-if="customerPersonName() && customerCompanyName()">{{ customerCompanyName() }}</span>
          </div>
          <button v-if="customerCopyValue()" class="sale-order-customer__copy" type="button" :aria-label="t('sale.orders.copyCustomer')" :title="t('sale.orders.copyCustomer')" @click="copyCustomerName">
            <svg xmlns="http://www.w3.org/2000/svg" class="bi bi-copy" viewBox="0 0 16 16" aria-hidden="true">
              <path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2v2a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2zm2-1a1 1 0 0 0-1 1v2h5a2 2 0 0 1 2 2v5h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1z"/>
            </svg>
          </button>
        </div>

        <div class="sale-admin__totals">
          <span>{{ t('sale.orders.subtotal') }}</span>
          <strong>{{ money(selectedOrder.subtotal_minor, selectedOrder.currency) }}</strong>
          <span v-if="Number(selectedOrder.discount_total_minor || 0) > 0">{{ t('sale.pos.discount') }}</span>
          <strong v-if="Number(selectedOrder.discount_total_minor || 0) > 0">−{{ money(selectedOrder.discount_total_minor, selectedOrder.currency) }}</strong>
          <span>{{ t('sale.orders.shipping') }}</span>
          <strong>{{ money(selectedOrder.shipping_total_minor, selectedOrder.currency) }}</strong>
          <span class="sale-admin__total-label">{{ t('common.total') }}</span>
          <strong class="sale-admin__total-value">{{ money(selectedOrder.grand_total_minor, selectedOrder.currency) }}</strong>
          <span class="sale-admin__tax-included">{{ t('sale.orders.taxIncluded') }}</span>
          <strong class="sale-admin__tax-included">{{ money(selectedOrder.tax_total_minor, selectedOrder.currency) }}</strong>
          <span>{{ t('sale.status.paid') }}</span>
          <strong>{{ money(selectedOrder.paid_total_minor, selectedOrder.currency) }}</strong>
          <span>{{ t('sale.payments.remaining') }}</span>
          <strong>{{ money(selectedDossier.payments.summary?.due_minor, selectedOrder.currency) }}</strong>
        </div>

        <h3>{{ t('sale.orders.itemsAndTerms') }}</h3>
        <div v-for="line in selectedOrder.lines || []" :key="String(line.id)" class="sale-admin__line">
          <span>{{ line.product_name }} <small>{{ line.sku }}</small></span>
          <b>{{ line.quantity }} × {{ money(line.unit_price_minor, line.currency) }}</b>
        </div>

        <section v-if="selectedDossier.payments.plan" class="alert alert-info sale-order-dossier__plan">
          <b>{{ t('sale.orders.deferredPayment') }} · {{ statusLabel(selectedDossier.payments.plan.status) }}</b>
          <span>{{ t('sale.orders.expectedAvailability') }}: {{ shortDate(selectedDossier.payments.plan.expected_availability_at) }}</span>
          <span>{{ t('sale.orders.pricePolicy') }}: {{ t(`sale.pricePolicy.${selectedDossier.payments.plan.price_policy}`) }}</span>
          <span v-if="Number(selectedDossier.payments.plan.deposit_minor || 0) > 0">{{ t('sale.orders.deposit') }}: {{ money(selectedDossier.payments.plan.deposit_minor, selectedOrder.currency) }}</span>
          <button v-if="selectedDossier.payments.plan.status === 'waiting_availability'" class="btn btn-primary btn-sm" type="button" :disabled="saving" @click="markOrderAvailable">{{ t('sale.orders.markAvailable') }}</button>
        </section>

        <h3>{{ t('sale.orders.paymentAndInvoice') }}</h3>
        <div v-if="!orderPayments.length" class="text-muted">{{ t('sale.empty.payments') }}</div>
        <div v-for="payment in orderPayments" :key="String(payment.id)" class="sale-admin__line">
          <span>{{ statusLabel(payment.transaction_type) }} · {{ statusLabel(payment.status) }}</span>
          <b>{{ money(payment.amount_minor, payment.currency) }}</b>
        </div>
        <a v-if="selectedDossier.payments.summary?.active_payment_url" class="btn btn-outline-primary btn-sm" :href="String(selectedDossier.payments.summary.active_payment_url)" target="_blank" rel="noopener">{{ t('sale.orders.openPaymentLink') }}</a>

        <div v-if="dossierActionEnabled('record_payment')" class="sale-admin__actions-block">
          <label class="form-label">{{ t('sale.orders.manualPaymentAmount') }}</label>
          <select v-model="manualProvider" class="select" :aria-label="t('sale.payments.provider')"><option value="manual_card">{{ t('sale.payments.manual') }}</option><option value="bank_transfer">{{ t('sale.payments.bankTransfer') }}</option></select>
          <div class="input-group">
            <input v-model.number="paymentAmount" class="form-control" type="number" min="1" step="1" :aria-label="t('sale.orders.manualPaymentAmount')">
            <button class="btn btn-primary" type="button" :disabled="saving || paymentAmount < 1" @click="recordPayment">{{ t('sale.orders.recordPayment') }}</button>
          </div>
          <small>{{ t('sale.payments.impact', { amount: money(paymentAmount, selectedOrder.currency), balance: money(Math.max(0, Number(selectedOrder.grand_total_minor || 0) - Number(selectedOrder.paid_total_minor || 0) - paymentAmount), selectedOrder.currency) }) }}</small>
          <input v-model="paymentReference" class="form-control" type="text" :aria-label="t('sale.payments.referenceOptional')" :placeholder="t('sale.payments.referenceOptional')">
          <input v-model="paymentComment" class="form-control" type="text" :aria-label="t('sale.payments.commentOptional')" :placeholder="t('sale.payments.commentOptional')">
          <input v-model.number="proofAssetId" class="form-control" type="number" min="1" :aria-label="t('sale.payments.proofOptional')" :placeholder="t('sale.payments.proofOptional')">
        </div>

        <h3>{{ t('sale.orders.preparationAndDelivery') }}</h3>
        <div v-if="!(selectedDossier.fulfillment.operations || []).length" class="text-muted">{{ t('sale.orders.noFulfillment') }}</div>
        <button v-if="dossierActionEnabled('prepare_order') && !(selectedDossier.fulfillment.operations || []).length" class="btn btn-primary" type="button" :disabled="saving" @click="createOrderFulfillment">{{ t('sale.orders.startPreparation') }}</button>
        <section v-for="operation in selectedDossier.fulfillment.operations || []" :key="String(operation.id)" class="sale-order-dossier__operation">
          <div class="sale-admin__line"><span>{{ operation.fulfillment_number }} · {{ statusLabel(operation.status) }}</span><small>{{ operation.tracking_reference || operation.pickup_code || '' }}</small></div>
          <div v-for="line in fulfillmentLines(operation)" :key="String(line.id)" class="sale-admin__line sale-order-dossier__preparation-line">
            <span>{{ line.product_name }} <small>{{ Number(line.prepared_quantity || 0) }}/{{ Number(line.quantity || 0) }} {{ t('sale.orders.prepared') }}</small></span>
            <button v-if="Number(line.prepared_quantity || 0) < Number(line.quantity || 0) && context.can('sale.fulfillment.manage')" class="btn btn-outline-secondary btn-sm" type="button" :disabled="saving" @click="prepareFulfillmentLine(operation, line)">{{ t('sale.orders.markLinePrepared') }}</button>
          </div>
          <button v-if="fulfillmentTransition(operation) && context.can('sale.fulfillment.manage')" class="btn btn-outline-primary btn-sm" type="button" :disabled="saving" @click="transitionOrderFulfillment(operation, fulfillmentTransition(operation))">{{ t(`sale.fulfillment.action.${operation.next_action}`) }}</button>
        </section>
        <div v-for="backorder in selectedDossier.fulfillment.backorders || []" :key="String(backorder.id)" class="alert alert-warning py-2">{{ backorder.product_name }} · {{ t('sale.orders.waitingQuantity') }} {{ backorder.quantity }}</div>

        <h3>{{ t('sale.orders.documents') }}</h3>
        <div v-if="!(selectedDossier.documents || []).length" class="text-muted">{{ t('sale.orders.noDocuments') }}</div>
        <div v-for="document in selectedDossier.documents || []" :key="`${document.document_type}-${document.id}`" class="sale-admin__line sale-order-dossier__document-row">
          <span>{{ t(`sale.document.${document.document_type}`) }} <small>{{ document.document_number }}</small></span>
          <small>{{ shortDate(document.issued_at) }}</small>
          <button v-if="document.printable_text" class="btn ghost btn-sm" type="button" @click="viewDocument(document)">{{ t('common.view') }}</button>
        </div>
        <div class="sale-order-dossier__document-actions">
          <button v-for="type in ['order_confirmation','invoice','credit_note','delivery_note','pos_receipt']" :key="type" class="btn btn-outline-secondary btn-sm" type="button" :disabled="saving || !canIssueDocument(type)" :title="canIssueDocument(type) ? '' : t(`sale.document.blocked.${type}`)" @click="issueDocument(type)">{{ t(`sale.document.issue.${type}`) }}</button>
          <button class="btn btn-outline-secondary btn-sm" type="button" @click="printReceipt">{{ t('sale.orders.printReceipt') }}</button>
        </div>

        <div v-if="dossierAction('cancel_order')?.permitted" class="sale-admin__actions-block">
          <label class="form-label">{{ t('sale.orders.cancelReason') }}</label>
          <input v-model="cancelReason" class="form-control" type="text" :placeholder="t('common.optional')">
          <button class="btn btn-outline-danger mt-2" type="button" :disabled="saving || !dossierActionEnabled('cancel_order')" :title="dossierActionEnabled('cancel_order') ? '' : t('sale.orders.cancelBlocked')" @click="cancelOrder">{{ t('sale.orders.cancelOrder') }}</button>
        </div>

        <h3>{{ t('sale.orders.timeline') }}</h3>
        <div v-if="!orderEvents.length" class="text-muted">{{ t('common.none') }}</div>
        <div v-for="event in orderEvents" :key="String(event.id)" class="sale-admin__line">
          <span>{{ event.label || event.event_type || event.kind }}</span>
          <small>{{ shortDate(event.at || event.created_at) }}</small>
        </div>
      </aside>
      </div>

      <div v-if="documentPreview" class="sale-document-modal-backdrop" role="presentation" @click.self="documentPreview = null">
        <section class="sale-document-modal" role="dialog" aria-modal="true" :aria-label="String(documentPreview.document_number || t('sale.orders.documents'))">
          <header>
            <div><small>{{ t(`sale.document.${documentPreview.document_type}`) }}</small><h2>{{ documentPreview.document_number }}</h2></div>
            <button type="button" :aria-label="t('common.close')" @click="documentPreview = null">×</button>
          </header>
          <pre>{{ documentPreview.printable_text }}</pre>
          <footer><button class="btn btn-outline-secondary" type="button" @click="documentPreview = null">{{ t('common.close') }}</button><button class="btn btn-primary" type="button" @click="printDocument">{{ t('common.print') }}</button></footer>
        </section>
      </div>
    </section>

    <section v-if="activeTab === 'payments'" class="sale-admin__panel sale-admin__orders">
      <section class="sale-admin__section sale-orders-list">
        <BusinessPageHeader :eyebrow="t('sale.title')" :title="t('sale.payments.title')" />
        <div v-if="paymentSessions.some((payment) => payment.test_mode)" class="alert alert-warning"><b>MODE TEST</b> — {{ t('sale.payments.testWarning') }}</div>
        <details v-if="canUseAdvancedTools" class="sale-admin__technical" :open="Number(paymentExceptionCenter.open_count || 0) > 0">
          <summary>{{ t('sale.payments.exceptionCenter') }} · {{ Number(paymentExceptionCenter.open_count || 0) }} · {{ t(`sale.payments.health.${paymentExceptionCenter.health || 'healthy'}`) }}</summary>
          <p class="text-muted">{{ t('sale.payments.exceptionCenterHelp') }}</p>
          <p>{{ t('sale.payments.pendingOperations') }}: <b>{{ Number(paymentExceptionCenter.pending_operations || 0) }}</b></p>
          <div v-for="(count, cause) in paymentExceptionCenter.groups || {}" :key="cause" class="sale-admin__line"><span>{{ statusLabel(String(cause)) }}</span><b>{{ count }}</b></div>
          <div v-for="item in paymentExceptionCenter.items || []" :key="String(item.id)" class="sale-admin__list-row">
            <span>{{ item.order_number || '—' }} <small>{{ item.provider_key }} · {{ item.priority }}</small></span>
            <b>{{ money(item.amount_minor, item.currency) }}</b>
            <small>{{ findingLabels(item.findings) }} · {{ statusLabel(String(item.recommended_action || '')) }}</small>
            <button class="btn btn-sm btn-outline-primary" type="button" :disabled="saving" @click="resolvePaymentException(item)">{{ t('sale.payments.markResolved') }}</button>
          </div>
        </details>
        <div class="sale-payment-bank-queue">
          <button class="btn btn-outline-primary btn-sm" type="button" @click="showBankQueue">{{ t('sale.payments.bankQueue') }}</button>
          <small class="text-muted">{{ t('sale.payments.bankQueueHelp') }}</small>
        </div>
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
        <button v-for="payment in paymentSessions" :key="String(payment.id)" class="sale-admin__list-row sale-payment-row" :class="{ 'is-selected': selectedPayment?.id === payment.id }" type="button" :aria-pressed="selectedPayment?.id === payment.id" @click="selectPayment(payment)">
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
        <details v-if="canUseAdvancedTools" class="sale-admin__technical"><summary>{{ t('sale.payments.technical') }}</summary><pre>{{ jsonText(selectedPayment.technical) }}</pre></details>
      </aside>
    </section>

    <section v-if="activeTab === 'pos'" class="sale-admin__panel sale-admin__pos">
      <SalePosView embedded />
    </section>

    <section v-if="canUseAdvancedTools && activeTab === 'stock'" class="sale-admin__panel sale-admin__stock">
      <SaleStockView />
    </section>

    <section v-if="canUseAdvancedTools && activeTab === 'reservations'" class="sale-admin__panel sale-admin__stock">
      <SaleReservationsView />
    </section>

    <section v-if="canUseAdvancedTools && activeTab === 'operations'" class="sale-admin__panel sale-admin__stock">
      <SaleOperationsView />
    </section>

    <section v-if="canUseAdvancedTools && activeTab === 'paymentDiagnostics'" class="sale-admin__panel">
      <section class="sale-admin__section">
        <h2>{{ t('sale.advanced.paymentDiagnostics') }}</h2>
        <p>{{ t('sale.advanced.paymentDiagnosticsIntro') }}</p>
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

    <section v-if="activeTab === 'settings'" class="sale-admin__panel">
      <section v-if="canUseAdvancedTools" class="sale-admin__section sale-admin__advanced-entry">
        <h2>{{ t('sale.advanced.title') }}</h2>
        <p>{{ t('sale.advanced.settingsIntro') }}</p>
        <RouterLink class="btn btn-outline-secondary" to="/sale/advanced/stock">{{ t('sale.advanced.open') }}</RouterLink>
      </section>
      <nav class="sale-settings-tabs" :aria-label="t('sale.tabs.settings')">
        <button
          v-for="section in settingsSections"
          :key="section.key"
          type="button"
          :class="{ active: activeSettingsSection === section.key }"
          :aria-current="activeSettingsSection === section.key ? 'page' : undefined"
          @click="selectSettingsSection(section.key)"
        >{{ section.label }}</button>
      </nav>
      <div class="sale-admin__grid">
        <section v-if="activeSettingsSection === 'policies'" class="sale-admin__section sale-admin__policy">
          <h2>{{ t('sale.settings.orderPolicies') }}</h2>
          <div class="sale-admin__line"><span>{{ t('sale.settings.paymentBeforeDelivery') }}</span><b>{{ salePolicies.payment_before_delivery ? t('common.yes') : t('common.no') }}</b></div>
          <div class="sale-admin__line"><span>{{ t('sale.settings.deferredOnOrder') }}</span><b>{{ (salePolicies.deferred_on_order as Row)?.enabled ? t('common.enabled') : t('common.disabled') }}</b></div>
          <div class="sale-admin__line"><span>{{ t('sale.orders.pricePolicy') }}</span><b>{{ t(`sale.pricePolicy.${(salePolicies.deferred_on_order as Row)?.price_policy || 'frozen'}`) }}</b></div>
          <div class="sale-admin__line"><span>{{ t('sale.settings.paymentWindow') }}</span><b>{{ (salePolicies.deferred_on_order as Row)?.payment_window_days }} {{ t('common.days') }}</b></div>
          <div class="sale-admin__line"><span>{{ t('sale.settings.deposit') }}</span><b>{{ (salePolicies.deposit as Row)?.enabled ? t('common.enabled') : t('common.disabled') }}</b></div>
          <div class="sale-admin__line"><span>{{ t('sale.settings.posDeferred') }}</span><b>{{ (salePolicies.pos_deferred as Row)?.enabled ? t('common.enabled') : t('common.disabled') }}</b></div>
          <small>{{ t('sale.settings.policyExplanation') }}</small>
        </section>
        <section v-if="activeSettingsSection === 'channels'" class="sale-admin__section">
          <h2>{{ t('sale.settings.channels') }}</h2>
          <div v-for="channel in channels" :key="channel.id" class="sale-admin__list-row">
            <span>{{ channel.name }} <small>{{ channel.code }}</small></span>
            <b>{{ statusLabel(channel.channel_type) }}</b>
            <small>{{ statusLabel(channel.status) }} · {{ channel.is_public ? t('common.public') : t('common.private') }} · {{ channel.price_tax_included ? t('sale.tax.included') : t('sale.tax.excluded') }}</small>
            <a class="btn btn-sm btn-outline-secondary" :href="saleCatalogPdfUrl(channel)">{{ t('sale.settings.brochure') }}</a>
          </div>
        </section>
        <section v-if="activeSettingsSection === 'payments'" class="sale-admin__section">
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
        <section v-if="activeSettingsSection === 'fulfillment'" class="sale-admin__section">
          <h2>{{ t('sale.settings.fulfillmentMethods') }}</h2>
          <div v-for="method in fulfillmentMethods" :key="String(method.id)" class="sale-admin__list-row">
            <span>{{ languageCode === 'en' ? method.label_en : method.label_fr }} <small>{{ method.code }}</small></span>
            <b>{{ money(method.flat_rate_minor, 'CHF') }}</b>
            <small>{{ statusLabel(method.status) }} · {{ method.fulfillment_type }} · {{ method.requires_shipping_address ? t('sale.fulfillment.addressRequired') : t('sale.fulfillment.addressOptional') }}</small>
          </div>
          <div v-if="!fulfillmentMethods.length" class="text-muted">{{ t('sale.empty.fulfillmentMethods') }}</div>
        </section>
        <section v-if="activeSettingsSection === 'pos'" class="sale-admin__section">
          <h2>{{ t('sale.settings.posSessions') }}</h2>
          <div v-if="sessions.length" class="table-responsive">
            <table class="table align-middle sale-admin__sessions-table">
              <thead><tr><th>{{ t('sale.pos.register') }}</th><th>{{ t('sale.pos.sessionOpening') }}</th><th>{{ t('sale.pos.sessionMovements') }}</th><th>{{ t('sale.pos.sessionClosing') }}</th><th>{{ t('common.status') }}</th></tr></thead>
              <tbody><tr v-for="session in sessions" :key="String(session.id)">
                <td><strong>{{ session.register_name || session.register_code || session.register_id }}</strong><small>#{{ session.id }}</small></td>
                <td>{{ money(session.opening_cash_minor, session.currency) }}</td>
                <td><strong>{{ money(session.net_cash_movement_minor, session.currency) }}</strong><small>{{ Number(session.operational_movements_count || 0) }} {{ t('sale.pos.movementsCount') }}</small></td>
                <td>{{ session.status === 'closed' ? money(session.counted_cash_minor, session.currency) : '—' }}</td>
                <td>{{ statusLabel(session.status) }}</td>
              </tr></tbody>
            </table>
          </div>
          <div v-if="!sessions.length" class="text-muted">{{ t('sale.empty.sessions') }}</div>
        </section>
        <SaleEcommerceSettings v-if="activeSettingsSection === 'ecommerce'" class="sale-admin__section" />
      </div>
    </section>
  </section>
</template>

<style scoped>
.sale-admin {
  --sale-border: #d0d5dd;
}
.sale-admin__sessions-table {
  margin: 0;
}
.sale-admin__sessions-table td:first-child,
.sale-admin__sessions-table td:nth-child(3) {
  display: grid;
}
.sale-admin__sessions-table small {
  color: #64748b;
}
.sale-admin__advanced-intro {
  background: #f8fafc;
  border: 1px solid var(--sale-border);
  border-radius: 8px;
  display: grid;
  gap: .75rem;
  padding: .85rem 1rem 0;
}
.sale-admin__advanced-intro p { color: #667085; margin: .2rem 0 0; }
.sale-admin__advanced-entry { margin-bottom: 1rem; }
.sale-settings-tabs {
  border-bottom: 1px solid #d8dee8;
  display: flex;
  gap: .25rem;
  margin-bottom: 1rem;
  overflow-x: auto;
}
.sale-settings-tabs button {
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  color: #475569;
  font-size: .86rem;
  font-weight: 700;
  padding: .55rem .7rem .45rem;
  white-space: nowrap;
}
.sale-settings-tabs button.active { border-bottom-color: #2563eb; color: #1d4ed8; }
.sale-payment-bank-queue { align-items: center; display: flex; flex-wrap: wrap; gap: .65rem; }
.sale-payment-bank-queue small { max-width: 48rem; }
.sale-payment-row.is-selected { background: #e5e7eb; border-radius: 6px; padding-inline: .55rem; }
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
.sale-admin__task {
  align-items: center;
  background: #fff;
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  display: grid;
  gap: .75rem;
  grid-template-columns: minmax(0, 1fr) auto minmax(180px, .7fr);
  padding: .8rem;
  text-align: left;
}
.sale-admin__task span { display: grid; }
.sale-admin__task small { color: #667085; }
.sale-admin__task-action { border-left: 3px solid #f59e0b; padding-left: .75rem; }
.sale-admin__orders {
  align-items: start;
  grid-template-columns: minmax(0, 1fr);
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
.sale-quick-filters,
.sale-active-filters {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: .35rem;
}
.sale-quick-filters button {
  background: #fff;
  border: 1px solid var(--sale-border);
  border-radius: 999px;
  color: #344054;
  font-size: .84rem;
  min-height: 1.95rem;
  padding: .25rem .65rem;
}
.sale-quick-filters button.active {
  background: #eff6ff;
  border-color: #2563eb;
  color: #1d4ed8;
  font-weight: 700;
}
.sale-filter-chip {
  align-items: center;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  color: #1d4ed8;
  display: inline-flex;
  gap: .35rem;
  min-height: 1.85rem;
  padding: .22rem .6rem .22rem .38rem;
}
.sale-filter-chip span {
  align-items: center;
  background: #dbeafe;
  border-radius: 999px;
  color: #1e40af;
  display: inline-flex;
  font-size: .9rem;
  height: 1.05rem;
  justify-content: center;
  line-height: 1;
  width: 1.05rem;
}
.sale-filter-chip strong { font-size: .82rem; font-weight: 800; line-height: 1.1; }
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
.sale-orders-table__action { text-align: right; white-space: nowrap; width: 1%; }
.sale-order-number-button,
.sale-order-relation-button {
  background: transparent;
  border: 0;
  color: inherit;
  padding: 0;
  text-align: left;
}
.sale-order-number-button { font-weight: 700; }
.sale-order-number-button:hover,
.sale-order-number-button:focus-visible,
.sale-order-relation-button:hover,
.sale-order-relation-button:focus-visible { text-decoration: underline; }
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
.sale-admin__total-label,
.sale-admin__total-value {
  border-top: 1px solid #edf0f3;
  padding-top: .45rem;
}
.sale-admin__tax-included {
  color: #64748b;
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
.sale-order-dossier__state {
  background: #f8fafc;
  border-left: 4px solid #2563eb;
  display: grid;
  gap: .25rem;
  grid-template-columns: 1fr auto;
  padding: .75rem;
}
.sale-order-dossier__state small { color: #b42318; grid-column: 1 / -1; }
.sale-order-customer {
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  display: flex;
  gap: .75rem;
  justify-content: space-between;
  padding: .7rem .8rem;
}
.sale-order-customer > div { display: grid; gap: .1rem; }
.sale-order-customer small,
.sale-order-customer span { color: #64748b; }
.sale-order-customer__copy {
  align-items: center;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  color: #475569;
  display: inline-flex;
  flex: 0 0 auto;
  height: 2rem;
  justify-content: center;
  width: 2rem;
}
.sale-order-customer__copy:hover,
.sale-order-customer__copy:focus-visible { border-color: #2563eb; color: #2563eb; }
.sale-order-customer__copy svg { fill: currentColor; height: .9rem; width: .9rem; }
.sale-order-dossier__plan { display: grid; gap: .35rem; }
.sale-order-dossier__operation { border: 1px solid #e4e7ec; border-radius: 6px; display: grid; gap: .5rem; padding: .65rem; }
.sale-order-dossier__preparation-line { align-items: center; }
.sale-order-dossier__document-actions { display: flex; flex-wrap: wrap; gap: .4rem; }
.sale-order-dossier__document-row { align-items: center; display: grid; gap: .65rem; grid-template-columns: minmax(0, 1fr) auto auto; }
.sale-order-modal-backdrop,
.sale-document-modal-backdrop { background: rgba(15, 23, 42, .52); display: grid; inset: 0; overflow-y: auto; padding: 1rem; place-items: start center; position: fixed; z-index: 1080; }
.sale-order-modal { background: #fff; border-radius: 8px; box-shadow: 0 24px 70px rgba(15, 23, 42, .28); box-sizing: border-box; margin: auto 0; max-width: 100%; padding: 1.25rem; position: relative; top: auto; width: min(1060px, 100%); }
.sale-order-modal__close { align-items: center; background: #f1f5f9; border: 0; border-radius: 999px; color: #334155; display: inline-flex; font-size: 1.35rem; height: 2.25rem; justify-content: center; line-height: 1; position: absolute; right: 1rem; top: 1rem; width: 2.25rem; z-index: 1; }
.sale-order-modal .sale-admin__section-head { padding-right: 3rem; }
.sale-document-modal-backdrop { z-index: 1100; }
.sale-document-modal { background: #fff; border-radius: 8px; box-shadow: 0 24px 70px rgba(15, 23, 42, .32); margin: auto 0; padding: 1.25rem; width: min(720px, 100%); }
.sale-document-modal header, .sale-document-modal footer { align-items: center; display: flex; gap: 1rem; justify-content: space-between; }
.sale-document-modal header { border-bottom: 1px solid #e2e8f0; margin-bottom: 1rem; padding-bottom: .75rem; }
.sale-document-modal header h2 { margin: .15rem 0 0; }
.sale-document-modal header button { background: transparent; border: 0; font-size: 1.75rem; }
.sale-document-modal pre { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; min-height: 16rem; padding: 1rem; white-space: pre-wrap; }
.sale-document-modal footer { border-top: 1px solid #e2e8f0; justify-content: flex-end; padding-top: .75rem; }
@media print {
  body * { visibility: hidden; }
  .sale-document-modal, .sale-document-modal * { visibility: visible; }
  .sale-document-modal { box-shadow: none; left: 0; position: absolute; top: 0; width: 100%; }
  .sale-document-modal header button, .sale-document-modal footer { display: none; }
}
@media (max-width: 980px) {
  .sale-admin__orders {
    grid-template-columns: 1fr;
  }
  .sale-admin__detail {
    position: static;
  }
  .sale-admin__task { grid-template-columns: 1fr auto; }
  .sale-admin__task-action { border-left: 0; border-top: 1px solid #e4e7ec; grid-column: 1 / -1; padding: .55rem 0 0; }
}
@media (max-width: 760px) {
  .sale-admin {
    max-width: 100%;
    min-width: 0;
    overflow-x: clip;
  }
  .sale-toolbar {
    grid-template-columns: 1fr;
  }
  .sale-toolbar-buttons {
    justify-content: flex-start;
  }
  .sale-menu-panel {
    left: 0;
    right: auto;
  }
  .sale-admin__table-wrap {
    contain: inline-size layout paint;
    max-width: 100%;
    min-width: 0;
    width: 100%;
  }
}
</style>
