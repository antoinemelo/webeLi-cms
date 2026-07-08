<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';

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
type Channel = Row & { id: number; code?: string; name?: string; channel_type?: string; status?: string; is_public?: number; currency?: string };

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const dashboard = ref<Dashboard>({});
const orders = ref<Order[]>([]);
const selectedOrder = ref<Order | null>(null);
const orderPayments = ref<Row[]>([]);
const orderEvents = ref<Row[]>([]);
const channels = ref<Channel[]>([]);
const paymentMethods = ref<Row[]>([]);
const sessions = ref<Row[]>([]);
const paymentAmount = ref(0);
const cancelReason = ref('');

const tabs = [
  { key: 'dashboard', label: 'Tableau de bord', path: '/sale' },
  { key: 'orders', label: 'Commandes', path: '/sale/orders' },
  { key: 'pos', label: 'POS', path: '/sale/pos' },
  { key: 'settings', label: 'Réglages', path: '/sale/settings' }
];

const activeTab = computed(() => {
  if (route.path.includes('/sale/orders')) return 'orders';
  if (route.path.includes('/sale/settings')) return 'settings';
  return 'dashboard';
});

function money(minor?: unknown, currency?: unknown): string {
  return `${(Number(minor || 0) / 100).toFixed(2)} ${String(currency || 'CHF')}`;
}

function shortDate(value?: unknown): string {
  const text = String(value || '');
  return text ? text.replace('T', ' ').slice(0, 16) : '-';
}

function statusLabel(value?: unknown): string {
  const labels: Record<string, string> = {
    draft: 'Brouillon',
    active: 'Actif',
    placed: 'Placée',
    confirmed: 'Confirmée',
    completed: 'Terminée',
    cancelled: 'Annulée',
    unpaid: 'Non payé',
    pending: 'En attente',
    partially_paid: 'Partiel',
    paid: 'Payé',
    failed: 'Échec',
    ecommerce: 'E-commerce',
    pos: 'POS',
    admin: 'Admin'
  };
  const key = String(value || '');
  return labels[key] || key || '-';
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
  const response = await adminApi.get<{ orders: Order[] }>('/sale/orders', { limit: 50 });
  orders.value = response.data.orders || [];
  if (!selectedOrder.value && orders.value[0]) {
    await selectOrder(orders.value[0]);
  }
}

async function loadSettings(): Promise<void> {
  const [channelResponse, methodResponse, sessionResponse] = await Promise.all([
    adminApi.get<{ channels: Channel[] }>('/sale/channels', { limit: 100 }),
    adminApi.get<{ payment_methods: Row[] }>('/sale/payment-methods'),
    adminApi.get<{ sessions: Row[] }>('/sale/reports/pos-sessions')
  ]);
  channels.value = channelResponse.data.channels || [];
  paymentMethods.value = methodResponse.data.payment_methods || [];
  sessions.value = sessionResponse.data.sessions || [];
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    if (activeTab.value === 'dashboard') await loadDashboard();
    if (activeTab.value === 'orders') await loadOrders();
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
    const response = await adminApi.post<{ order: Order }>(`/sale/orders/${selectedOrder.value.id}/payments`, { amount_minor: Math.round(paymentAmount.value) });
    notice.value = 'Paiement enregistré.';
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
  if (!selectedOrder.value?.id || !confirm('Annuler cette commande ?')) return;
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const response = await adminApi.post<{ order: Order }>(`/sale/orders/${selectedOrder.value.id}/cancel`, { reason: cancelReason.value || 'Annulation back-office' });
    notice.value = 'Commande annulée.';
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
    const printable = String(response.data.receipt?.printable_text || `Commande ${selectedOrder.value.order_number || selectedOrder.value.id}\nTotal ${money(selectedOrder.value.grand_total_minor, selectedOrder.value.currency)}`);
    const win = window.open('', '_blank', 'width=420,height=640');
    if (win) {
      win.document.write(`<pre style="font:14px/1.5 monospace;white-space:pre-wrap">${printable.replace(/[&<>]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[char] || char))}</pre>`);
      win.document.close();
      win.print();
    }
  } catch (err) {
    error.value = apiErrorMessage(err);
  }
}

watch(() => route.path, () => { void load(); });
onMounted(load);
</script>

<template>
  <section class="sale-admin">
    <header class="sale-admin__head">
      <div>
        <p class="text-uppercase text-muted small mb-1">Module</p>
        <h1>Vente</h1>
      </div>
      <div class="sale-admin__actions">
        <button class="btn btn-outline-primary btn-sm" type="button" @click="go('/sale/pos')">Ouvrir POS</button>
        <button class="btn btn-primary btn-sm" type="button" @click="go('/sale/orders')">Voir commandes</button>
      </div>
    </header>

    <nav class="sale-admin__tabs" aria-label="Navigation Vente">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="sale-admin__tab"
        :class="{ 'sale-admin__tab--active': activeTab === tab.key }"
        @click="go(tab.path)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <div v-if="notice" class="alert alert-success py-2">{{ notice }}</div>
    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

    <section v-if="activeTab === 'dashboard'" class="sale-admin__panel">
      <div class="sale-admin__metrics">
        <div class="sale-admin__metric">
          <span>Ventes du jour</span>
          <strong>{{ money(dashboard.today_sales_minor) }}</strong>
        </div>
        <div class="sale-admin__metric">
          <span>Commandes</span>
          <strong>{{ dashboard.orders || 0 }}</strong>
        </div>
        <div class="sale-admin__metric">
          <span>Paniers actifs</span>
          <strong>{{ dashboard.active_carts || 0 }}</strong>
        </div>
        <div class="sale-admin__metric">
          <span>Payé total</span>
          <strong>{{ money(dashboard.paid_total_minor) }}</strong>
        </div>
      </div>

      <div class="sale-admin__grid">
        <section class="sale-admin__section">
          <div class="sale-admin__section-head">
            <h2>Commandes récentes</h2>
            <button class="btn btn-link btn-sm" type="button" @click="go('/sale/orders')">Tout voir</button>
          </div>
          <div v-if="!(dashboard.recent_orders || []).length" class="text-muted">Aucune commande.</div>
          <button v-for="order in dashboard.recent_orders || []" :key="String(order.id)" class="sale-admin__list-row" type="button" @click="go('/sale/orders')">
            <span>{{ order.order_number }}</span>
            <b>{{ money(order.grand_total_minor, order.currency) }}</b>
            <small>{{ statusLabel(order.payment_status) }}</small>
          </button>
        </section>

        <section class="sale-admin__section">
          <h2>Paiements récents</h2>
          <div v-if="!(dashboard.recent_payments || []).length" class="text-muted">Aucun paiement.</div>
          <div v-for="payment in dashboard.recent_payments || []" :key="String(payment.id)" class="sale-admin__list-row">
            <span>{{ payment.order_number }}</span>
            <b>{{ money(payment.amount_minor, payment.currency) }}</b>
            <small>{{ statusLabel(payment.status) }}</small>
          </div>
        </section>

        <section class="sale-admin__section">
          <h2>Sessions caisse ouvertes</h2>
          <div v-if="!(dashboard.open_cash_sessions || []).length" class="text-muted">Aucune session ouverte.</div>
          <div v-for="session in dashboard.open_cash_sessions || []" :key="String(session.id)" class="sale-admin__list-row">
            <span>{{ session.register_name || session.register_code }}</span>
            <b>{{ money(session.expected_cash_minor, session.currency) }}</b>
            <small>{{ shortDate(session.opened_at) }}</small>
          </div>
        </section>
      </div>
    </section>

    <section v-if="activeTab === 'orders'" class="sale-admin__panel sale-admin__orders">
      <div class="sale-admin__table-wrap">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Numéro</th>
              <th>Date</th>
              <th>Source</th>
              <th>Total</th>
              <th>Paiement</th>
              <th>Statut</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="order in orders" :key="order.id" :class="{ 'table-active': selectedOrder?.id === order.id }" @click="selectOrder(order)">
              <td><strong>{{ order.order_number }}</strong></td>
              <td>{{ shortDate(order.placed_at) }}</td>
              <td>{{ statusLabel(order.source) }}</td>
              <td>{{ money(order.grand_total_minor, order.currency) }}</td>
              <td>{{ statusLabel(order.payment_status) }}</td>
              <td>{{ statusLabel(order.status) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="!orders.length && !loading" class="text-muted p-3">Aucune commande.</div>
      </div>

      <aside class="sale-admin__detail" v-if="selectedOrder">
        <div class="sale-admin__section-head">
          <div>
            <h2>{{ selectedOrder.order_number }}</h2>
            <p>{{ statusLabel(selectedOrder.source) }} · {{ shortDate(selectedOrder.placed_at) }}</p>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" @click="printReceipt">Imprimer reçu</button>
        </div>

        <div class="sale-admin__totals">
          <span>Total</span>
          <strong>{{ money(selectedOrder.grand_total_minor, selectedOrder.currency) }}</strong>
          <span>Payé</span>
          <strong>{{ money(selectedOrder.paid_total_minor, selectedOrder.currency) }}</strong>
        </div>

        <h3>Lignes</h3>
        <div v-for="line in selectedOrder.lines || []" :key="String(line.id)" class="sale-admin__line">
          <span>{{ line.product_name }} <small>{{ line.sku }}</small></span>
          <b>{{ line.quantity }} × {{ money(line.unit_price_minor, line.currency) }}</b>
        </div>

        <h3>Paiements</h3>
        <div v-if="!orderPayments.length" class="text-muted">Aucun paiement.</div>
        <div v-for="payment in orderPayments" :key="String(payment.id)" class="sale-admin__line">
          <span>{{ statusLabel(payment.transaction_type) }} · {{ statusLabel(payment.status) }}</span>
          <b>{{ money(payment.amount_minor, payment.currency) }}</b>
        </div>

        <div class="sale-admin__actions-block">
          <label class="form-label">Montant paiement manuel</label>
          <div class="input-group">
            <input v-model.number="paymentAmount" class="form-control" type="number" min="1" step="1">
            <button class="btn btn-primary" type="button" :disabled="saving || paymentAmount < 1" @click="recordPayment">Enregistrer</button>
          </div>
        </div>

        <div class="sale-admin__actions-block">
          <label class="form-label">Raison d’annulation</label>
          <input v-model="cancelReason" class="form-control" type="text" placeholder="Optionnel">
          <button class="btn btn-outline-danger mt-2" type="button" :disabled="saving || selectedOrder.status === 'cancelled'" @click="cancelOrder">Annuler la commande</button>
        </div>

        <h3>Événements</h3>
        <div v-if="!orderEvents.length" class="text-muted">Aucun événement.</div>
        <div v-for="event in orderEvents" :key="String(event.id)" class="sale-admin__line">
          <span>{{ event.event_type }}</span>
          <small>{{ shortDate(event.created_at) }}</small>
        </div>
      </aside>
    </section>

    <section v-if="activeTab === 'settings'" class="sale-admin__panel">
      <div class="sale-admin__grid">
        <section class="sale-admin__section">
          <h2>Canaux</h2>
          <div v-for="channel in channels" :key="channel.id" class="sale-admin__list-row">
            <span>{{ channel.name }} <small>{{ channel.code }}</small></span>
            <b>{{ statusLabel(channel.channel_type) }}</b>
            <small>{{ statusLabel(channel.status) }} · {{ channel.is_public ? 'Public' : 'Privé' }}</small>
          </div>
        </section>
        <section class="sale-admin__section">
          <h2>Moyens de paiement</h2>
          <div v-for="method in paymentMethods" :key="String(method.id)" class="sale-admin__list-row">
            <span>{{ method.name }}</span>
            <b>{{ method.method_type }}</b>
            <small>{{ statusLabel(method.status) }}</small>
          </div>
          <div v-if="!paymentMethods.length" class="text-muted">Aucun moyen configuré.</div>
        </section>
        <section class="sale-admin__section">
          <h2>Sessions POS</h2>
          <div v-for="session in sessions" :key="String(session.id)" class="sale-admin__list-row">
            <span>{{ session.register_id }}</span>
            <b>{{ money(session.expected_cash_minor, session.currency) }}</b>
            <small>{{ statusLabel(session.status) }}</small>
          </div>
          <div v-if="!sessions.length" class="text-muted">Aucune session.</div>
        </section>
      </div>
    </section>
  </section>
</template>

<style scoped>
.sale-admin {
  padding: 1.25rem;
}
.sale-admin__head,
.sale-admin__actions,
.sale-admin__section-head,
.sale-admin__list-row,
.sale-admin__line {
  align-items: center;
  display: flex;
  gap: .75rem;
}
.sale-admin__head {
  justify-content: space-between;
  margin-bottom: 1rem;
}
.sale-admin__head h1,
.sale-admin__section h2,
.sale-admin__detail h2,
.sale-admin__detail h3 {
  margin: 0;
}
.sale-admin__tabs {
  border-bottom: 1px solid #d0d5dd;
  display: flex;
  gap: .25rem;
  margin-bottom: 1rem;
  overflow-x: auto;
}
.sale-admin__tab {
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  color: #475467;
  font-weight: 600;
  padding: .65rem .9rem;
  white-space: nowrap;
}
.sale-admin__tab--active {
  border-bottom-color: #0d6efd;
  color: #0d6efd;
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
  border: 1px solid #d0d5dd;
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
.sale-admin__table-wrap {
  background: #fff;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  overflow: auto;
}
.sale-admin__table-wrap table {
  margin-bottom: 0;
}
.sale-admin__table-wrap tbody tr {
  cursor: pointer;
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
@media (max-width: 640px) {
  .sale-admin__head,
  .sale-admin__actions {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
