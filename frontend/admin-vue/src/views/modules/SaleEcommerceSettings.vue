<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useI18n } from '@/i18n';
import { useAdminContextStore } from '@/stores/adminContext';
import ContextualHelpLink from '@/components/ui/ContextualHelpLink.vue';

type ShopRow = {
  site_id: number; site_key: string; site_name: string; language_code: string; language_name: string;
  status: 'not_initialized'|'inactive'|'activating'|'active'|'error'; public_visible: boolean;
  is_initialized: boolean; is_published: boolean; channel_name?: string|null; channel_code?: string|null;
  currency: string; page_system_ready: boolean; menu_ready: boolean; cart_ready: boolean; projections_ready: boolean;
  studio_route: string; preview_path?: string|null; last_error_message?: string|null;
  permissions: { edit: boolean; publish: boolean; activate: boolean };
};

const context = useAdminContextStore();
const { t } = useI18n();
const loading = ref(false);
const actionKey = ref('');
const error = ref('');
const message = ref('');
const shops = ref<ShopRow[]>([]);
let loadSequence = 0;

async function load(): Promise<void> {
  const sequence = ++loadSequence;
  if (!context.context) await context.load();
  if (sequence !== loadSequence) return;
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<{ shops: ShopRow[] }>('/sale/ecommerce/shops');
    if (sequence !== loadSequence) return;
    shops.value = response.data.shops || [];
  } catch (err) {
    if (sequence !== loadSequence) return;
    shops.value = []; error.value = apiErrorMessage(err, t('commerce.loadError'));
  } finally { if (sequence === loadSequence) loading.value = false; }
}

function rowKey(shop: ShopRow): string { return `${shop.site_id}:${shop.language_code}`; }
function statusLabel(status: ShopRow['status']): string {
  return t(`commerce.status.${status}`);
}
function statusClass(status: ShopRow['status']): string {
  return status === 'active' ? 'text-bg-success' : status === 'error' ? 'text-bg-danger' : status === 'activating' ? 'text-bg-warning' : 'text-bg-secondary';
}

async function mutate(shop: ShopRow, action: 'activate'|'deactivate'|'repair'): Promise<void> {
  const consequence = action === 'deactivate' ? t('commerce.confirmDeactivate') : action === 'repair' ? t('commerce.confirmRepair') : t('commerce.confirmActivate');
  if (!window.confirm(consequence)) return;
  actionKey.value = `${rowKey(shop)}:${action}`; error.value = ''; message.value = '';
  try {
    await adminApi.post(`/sale/ecommerce/shops/${shop.site_id}/${shop.language_code}/${action}`, {});
    message.value = t(`commerce.${action}Success`);
    await load();
  } catch (err) { error.value = apiErrorMessage(err, t('commerce.actionError')); }
  finally { actionKey.value = ''; }
}

watch(() => [context.siteId, context.languageCode], () => { void load(); });
onMounted(load);
</script>

<template>
  <section class="sale-ecommerce-settings">
    <div class="sale-ecommerce-settings__head">
      <h2><span>{{ t('sale.settings.ecommerce') }}</span><ContextualHelpLink id="modules.commerce" /></h2>
      <p>{{ t('commerce.intro') }}</p>
    </div>

    <div class="alert alert-info sale-ecommerce-settings__boundary">
      <strong>{{ t('commerce.boundaryTitle') }}</strong>
      <p>{{ t('commerce.boundaryMessage') }}</p>
    </div>
    <div v-if="loading" class="text-muted" role="status">{{ t('common.loadingAdminContext') }}</div>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="message" class="alert alert-success" role="status">{{ message }}</div>
    <div v-if="!loading && !error && !shops.length" class="alert alert-secondary">{{ t('commerce.empty') }}</div>

    <div v-if="shops.length" class="sale-ecommerce-settings__table-wrap">
      <table class="table align-middle">
        <thead><tr><th>{{ t('commerce.site') }}</th><th>{{ t('commerce.language') }}</th><th>{{ t('common.status') }}</th><th>{{ t('commerce.channel') }}</th><th>{{ t('commerce.effects') }}</th><th>{{ t('common.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="shop in shops" :key="rowKey(shop)">
            <td><strong>{{ shop.site_name }}</strong><small>{{ shop.site_key }}</small></td>
            <td>{{ shop.language_name }} <small>{{ shop.language_code.toUpperCase() }}</small></td>
            <td><span class="badge" :class="statusClass(shop.status)">{{ statusLabel(shop.status) }}</span><small v-if="shop.last_error_message" class="text-danger">{{ shop.last_error_message }}</small></td>
            <td>{{ shop.channel_name || '—' }}<small v-if="shop.channel_code">{{ shop.channel_code }} · {{ shop.currency }}</small></td>
            <td><ul class="shop-effects" :aria-label="t('commerce.effects')"><li :class="{ ready: shop.page_system_ready }">{{ t('commerce.page') }}</li><li :class="{ ready: shop.menu_ready }">{{ t('commerce.menu') }}</li><li :class="{ ready: shop.cart_ready }">{{ t('commerce.cart') }}</li><li :class="{ ready: shop.projections_ready }">{{ t('commerce.projections') }}</li></ul></td>
            <td class="sale-ecommerce-settings__actions">
              <RouterLink class="btn btn-sm btn-outline-primary" :to="shop.studio_route">{{ shop.is_initialized ? t('commerce.openStudio') : t('commerce.initialize') }}</RouterLink>
              <button v-if="shop.permissions.activate && shop.is_initialized && shop.is_published && shop.status !== 'active' && shop.status !== 'error'" class="btn btn-sm btn-primary" :disabled="Boolean(actionKey)" @click="mutate(shop,'activate')">{{ t('commerce.activate') }}</button>
              <button v-if="shop.permissions.activate && shop.status === 'active'" class="btn btn-sm btn-outline-danger" :disabled="Boolean(actionKey)" @click="mutate(shop,'deactivate')">{{ t('commerce.deactivate') }}</button>
              <button v-if="shop.permissions.activate && shop.status === 'error'" class="btn btn-sm btn-warning" :disabled="Boolean(actionKey)" @click="mutate(shop,'repair')">{{ t('commerce.repair') }}</button>
              <a v-if="shop.preview_path" class="btn btn-sm btn-outline-secondary" :href="shop.preview_path" target="_blank" rel="noopener">{{ t('commerce.preview') }}</a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.sale-ecommerce-settings { display:grid; gap:1rem; min-width:0; }
.sale-ecommerce-settings__head h2 { align-items:center; display:flex; gap:.45rem; margin-bottom:.25rem; }
.sale-ecommerce-settings__head p,.sale-ecommerce-settings__boundary p { margin:.25rem 0 0; }
.sale-ecommerce-settings__table-wrap { overflow-x:auto; }
.sale-ecommerce-settings td small { color:#64748b; display:block; }
.sale-ecommerce-settings__actions { display:flex; flex-wrap:wrap; gap:.4rem; min-width:15rem; }
.shop-effects { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.2rem .6rem; list-style:none; margin:0; padding:0; min-width:11rem; }
.shop-effects li { color:#94a3b8; font-size:.78rem; }
.shop-effects li::before { content:'○'; margin-right:.25rem; }
.shop-effects li.ready { color:#166534; }
.shop-effects li.ready::before { content:'●'; }
@media (max-width:760px) { .sale-ecommerce-settings__table-wrap { overflow:visible; } .sale-ecommerce-settings table,.sale-ecommerce-settings tbody,.sale-ecommerce-settings tr,.sale-ecommerce-settings td { display:block; width:100%; } .sale-ecommerce-settings thead { position:absolute; clip:rect(0 0 0 0); } .sale-ecommerce-settings tr { border:1px solid #dbe2ea; border-radius:.75rem; margin-bottom:.75rem; padding:.75rem; } }
</style>
