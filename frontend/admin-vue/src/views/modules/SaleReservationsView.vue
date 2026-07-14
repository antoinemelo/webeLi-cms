<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useI18n } from '@/i18n';
import { useAdminContextStore } from '@/stores/adminContext';

type Reservation = {
  id: number; reservation_kind: 'physical'|'backorder'; status: string; quantity: number;
  product_name?: string; variant_name?: string; sku?: string; location_name?: string;
  cart_id?: number; order_id?: number; order_number?: string; expires_at?: string;
  ttl_seconds?: number; expiring_soon?: number; blocked?: number; releasable?: boolean;
  fulfillment_mode?: string; reservation_trigger?: string; delivery_lead_time_days?: number;
};
type Policy = {
  channel_id: number; channel_name: string; channel_code: string; channel_kind: string;
  reservation_policy: string; reservation_ttl_seconds: number; reservation_renewal_window_seconds: number;
  reservation_max_lifetime_seconds: number; backorder_policy: string; show_exact_quantity: number;
};
type Summary = { active?: number; expiring_soon?: number; blocked?: number; order_linked?: number; backorders?: number };

const { t, dateTime } = useI18n();
const context = useAdminContextStore();
const canManage = computed(() => context.can('sale.stock.manage'));
const canConfigure = computed(() => context.can('sale.settings.manage'));
const reservations = ref<Reservation[]>([]);
const policies = ref<Policy[]>([]);
const summary = ref<Summary>({});
const filters = ref({ q: '', status: '', alert: '', reservation_kind: '' });
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const releaseTarget = ref<Reservation|null>(null);
const releaseReason = ref('');

async function load(): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<{ reservations: Reservation[]; policies: Policy[]; summary: Summary }>('/sale/stock/reservations', { ...filters.value, limit: 200 });
    reservations.value = response.data.reservations || [];
    policies.value = response.data.policies || [];
    summary.value = response.data.summary || {};
  } catch (reason) { error.value = apiErrorMessage(reason); }
  finally { loading.value = false; }
}

function humanTtl(row: Reservation): string {
  const seconds = Number(row.ttl_seconds || 0);
  if (!row.expires_at) return t('sale.reservations.noExpiry');
  if (seconds <= 0) return t('sale.reservations.expired');
  if (seconds < 3600) return t('sale.reservations.minutes', { count: Math.ceil(seconds / 60) });
  return t('sale.reservations.hours', { count: Math.ceil(seconds / 3600) });
}

async function renew(row: Reservation): Promise<void> {
  saving.value = true; error.value = '';
  try {
    const response = await adminApi.post<{ reservation: Reservation }>(`/sale/stock/reservations/${row.id}/renew`, { reservation_kind: row.reservation_kind });
    notice.value = response.data.reservation && (response.data.reservation as Record<string, unknown>)._renewed === false ? t('sale.reservations.renewNotDue') : t('sale.reservations.renewed');
    await load();
  } catch (reason) { error.value = apiErrorMessage(reason); }
  finally { saving.value = false; }
}

async function release(): Promise<void> {
  if (!releaseTarget.value || releaseReason.value.trim().length < 3) return;
  saving.value = true; error.value = '';
  try {
    await adminApi.post(`/sale/stock/reservations/${releaseTarget.value.id}/release`, { reservation_kind: releaseTarget.value.reservation_kind, reason: releaseReason.value.trim() });
    notice.value = t('sale.reservations.released'); releaseTarget.value = null; releaseReason.value = ''; await load();
  } catch (reason) { error.value = apiErrorMessage(reason); }
  finally { saving.value = false; }
}

async function expireNow(): Promise<void> {
  saving.value = true; error.value = '';
  try { const response = await adminApi.post<{ expired: number }>('/sale/stock/reservations/expire', {}); notice.value = t('sale.reservations.expiredCount', { count: response.data.expired }); await load(); }
  catch (reason) { error.value = apiErrorMessage(reason); }
  finally { saving.value = false; }
}

async function savePolicy(policy: Policy): Promise<void> {
  saving.value = true; error.value = '';
  try { await adminApi.put(`/sale/stock/reservation-policies/${policy.channel_id}`, policy); notice.value = t('sale.reservations.policySaved'); await load(); }
  catch (reason) { error.value = apiErrorMessage(reason); }
  finally { saving.value = false; }
}

onMounted(load);
</script>

<template>
  <section class="reservation-workspace">
    <header class="reservation-heading"><div><p>{{ t('sale.title') }}</p><h2>{{ t('sale.reservations.title') }}</h2><span>{{ t('sale.reservations.intro') }}</span></div><button v-if="canManage" class="btn btn-outline-secondary" type="button" :disabled="saving" @click="expireNow">{{ t('sale.reservations.runExpiry') }}</button></header>
    <div v-if="notice" class="alert alert-success">{{ notice }}</div><div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div class="reservation-metrics">
      <article><span>{{ t('sale.reservations.active') }}</span><strong>{{ summary.active || 0 }}</strong></article>
      <article :class="{ warning: Number(summary.expiring_soon || 0)>0 }"><span>{{ t('sale.reservations.expiringSoon') }}</span><strong>{{ summary.expiring_soon || 0 }}</strong></article>
      <article :class="{ danger: Number(summary.blocked || 0)>0 }"><span>{{ t('sale.reservations.blocked') }}</span><strong>{{ summary.blocked || 0 }}</strong></article>
      <article><span>{{ t('sale.reservations.backorders') }}</span><strong>{{ summary.backorders || 0 }}</strong></article>
    </div>
    <form class="reservation-filters" @submit.prevent="load"><label>{{ t('common.search') }}<input v-model="filters.q" class="form-control" :placeholder="t('sale.reservations.searchPlaceholder')"></label><label>{{ t('common.status') }}<select v-model="filters.status" class="form-select"><option value="">{{ t('common.all') }}</option><option v-for="status in ['active','confirmed','consumed','released','expired','cancelled']" :key="status" :value="status">{{ t(`sale.reservations.status.${status}` as never) }}</option></select></label><label>{{ t('sale.reservations.kind') }}<select v-model="filters.reservation_kind" class="form-select"><option value="">{{ t('common.all') }}</option><option value="physical">{{ t('sale.reservations.physical') }}</option><option value="backorder">{{ t('sale.reservations.backorder') }}</option></select></label><label>{{ t('sale.stock.alert') }}<select v-model="filters.alert" class="form-select"><option value="">{{ t('common.all') }}</option><option value="expiring_soon">{{ t('sale.reservations.expiringSoon') }}</option><option value="blocked">{{ t('sale.reservations.blocked') }}</option><option value="order">{{ t('sale.reservations.linkedOrder') }}</option></select></label><button class="btn btn-primary" type="submit">{{ t('common.apply') }}</button></form>
    <section class="reservation-list" :aria-busy="loading"><p v-if="!reservations.length&&!loading" class="empty">{{ t('sale.reservations.empty') }}</p><article v-for="row in reservations" :key="`${row.reservation_kind}-${row.id}`" :class="['reservation-row',{blocked:row.blocked,soon:row.expiring_soon}]"><div><strong>{{ row.product_name || row.variant_name || row.sku }}</strong><small>{{ row.sku }} · {{ row.location_name }}</small></div><span><small>{{ t('sale.reservations.kind') }}</small><b>{{ row.reservation_kind==='backorder'?t('sale.reservations.backorder'):t('sale.reservations.physical') }} · {{ row.quantity }}</b></span><span><small>{{ t('common.status') }}</small><b>{{ t(`sale.reservations.status.${row.status}` as never) }}</b></span><span><small>{{ t('sale.reservations.ttl') }}</small><b>{{ humanTtl(row) }}</b><small>{{ row.expires_at ? dateTime(row.expires_at) : '' }}</small></span><span><small>{{ t('sale.reservations.source') }}</small><b>{{ row.order_number || (row.cart_id ? `Panier #${row.cart_id}` : '—') }}</b><small>{{ t(`sale.reservations.trigger.${row.reservation_trigger}` as never) }} · {{ row.fulfillment_mode }}</small></span><div v-if="canManage&&row.releasable" class="row-actions"><button class="btn btn-sm btn-outline-secondary" type="button" :disabled="saving" @click="renew(row)">{{ t('sale.reservations.renew') }}</button><button class="btn btn-sm btn-outline-danger" type="button" :disabled="saving" @click="releaseTarget=row;releaseReason=''">{{ t('sale.reservations.release') }}</button></div></article></section>
    <section class="policy-panel"><h3>{{ t('sale.reservations.policyTitle') }}</h3><p>{{ t('sale.reservations.policyDefault') }}</p><article v-for="policy in policies" :key="policy.channel_id" class="policy-row"><div><strong>{{ policy.channel_name }}</strong><small>{{ policy.channel_code }} · {{ policy.channel_kind }}</small></div><label>{{ t('sale.reservations.triggerLabel') }}<select v-model="policy.reservation_policy" class="form-select" :disabled="!canConfigure"><option v-for="trigger in ['checkout_start','order_placement','payment_authorization','payment_capture']" :key="trigger" :value="trigger">{{ t(`sale.reservations.trigger.${trigger}` as never) }}</option></select></label><label>TTL (s)<input v-model.number="policy.reservation_ttl_seconds" class="form-control" type="number" min="60" max="86400" :disabled="!canConfigure"></label><label>{{ t('sale.reservations.backorderPolicy') }}<select v-model="policy.backorder_policy" class="form-select" :disabled="!canConfigure"><option value="disabled">{{ t('common.disabled') }}</option><option value="sellable">{{ t('sale.reservations.sellablePolicy') }}</option><option value="enabled">{{ t('common.enabled') }}</option></select></label><button v-if="canConfigure" class="btn btn-primary" type="button" :disabled="saving" @click="savePolicy(policy)">{{ t('common.save') }}</button></article></section>
    <div v-if="releaseTarget" class="release-modal" role="dialog" aria-modal="true" :aria-label="t('sale.reservations.release')"><section><h3>{{ t('sale.reservations.releaseTitle') }}</h3><p>{{ releaseTarget.product_name || releaseTarget.sku }} · {{ releaseTarget.quantity }}</p><label>{{ t('sale.stock.reason') }}<textarea v-model="releaseReason" class="form-control" rows="3"></textarea></label><footer><button class="btn btn-outline-secondary" type="button" @click="releaseTarget=null">{{ t('common.cancel') }}</button><button class="btn btn-danger" type="button" :disabled="releaseReason.trim().length<3||saving" @click="release">{{ t('sale.reservations.confirmRelease') }}</button></footer></section></div>
  </section>
</template>

<style scoped>
.reservation-workspace{display:grid;gap:1rem}.reservation-heading{display:flex;justify-content:space-between;gap:1rem}.reservation-heading p{margin:0;color:#6366f1;font-weight:700;text-transform:uppercase;font-size:.75rem}.reservation-heading h2{margin:.2rem 0}.reservation-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem}.reservation-metrics article{display:grid;padding:1rem;border:1px solid #e2e8f0;border-radius:.8rem;background:#fff}.reservation-metrics strong{font-size:1.6rem}.warning{border-color:#fb923c!important;background:#fff7ed!important}.danger{border-color:#ef4444!important;background:#fef2f2!important}.reservation-filters{display:grid;grid-template-columns:2fr repeat(3,1fr) auto;gap:.7rem;align-items:end}.reservation-filters label,.policy-row label,.release-modal label{display:grid;gap:.3rem}.reservation-list,.policy-panel{border:1px solid #e2e8f0;border-radius:.8rem;background:#fff;overflow:hidden}.reservation-row{display:grid;grid-template-columns:2fr repeat(4,1fr) auto;gap:.75rem;align-items:center;padding:.8rem;border-bottom:1px solid #eef2f7}.reservation-row>div:first-child,.reservation-row span,.policy-row>div{display:grid}.reservation-row small,.policy-row small{color:#64748b}.reservation-row.blocked{background:#fef2f2}.reservation-row.soon:not(.blocked){background:#fff7ed}.row-actions{display:flex;gap:.4rem}.empty{padding:2rem;text-align:center}.policy-panel{padding:1rem}.policy-row{display:grid;grid-template-columns:1.4fr 1.4fr .7fr 1fr auto;gap:.7rem;align-items:end;padding:.8rem 0;border-top:1px solid #eef2f7}.release-modal{position:fixed;inset:0;z-index:1200;background:#0f172a88;display:grid;place-items:center;padding:1rem}.release-modal>section{width:min(32rem,100%);background:#fff;border-radius:1rem;padding:1.2rem}.release-modal footer{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1rem}@media(max-width:1000px){.reservation-metrics{grid-template-columns:1fr 1fr}.reservation-filters,.reservation-row,.policy-row{grid-template-columns:1fr 1fr}.reservation-row>div:first-child,.row-actions,.policy-row>div:first-child{grid-column:1/-1}}@media(max-width:600px){.reservation-metrics,.reservation-filters,.reservation-row,.policy-row{grid-template-columns:1fr}}
</style>
