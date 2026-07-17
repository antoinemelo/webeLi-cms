<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useI18n } from '@/i18n';
import { useAdminContextStore } from '@/stores/adminContext';
import ContextualHelpLink from '@/components/ui/ContextualHelpLink.vue';

type ShopRow = {
  site_id: number;
  site_key: string;
  site_name: string;
  language_code: string;
  language_name: string;
  is_default_language: boolean;
  status: 'existing_public_configuration' | 'not_active';
  public_channel_detected: boolean;
  channel_name?: string | null;
  route_prefix?: string | null;
  studio_route: string;
  preview_path?: string | null;
  shop_activation_available: boolean;
};

const context = useAdminContextStore();
const { t } = useI18n();
const loading = ref(false);
const error = ref('');
const shops = ref<ShopRow[]>([]);

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ shops: ShopRow[] }>('/sale/ecommerce/shops');
    shops.value = response.data.shops || [];
  } catch (err) {
    shops.value = [];
    error.value = apiErrorMessage(err, t('commerce.loadError'));
  } finally {
    loading.value = false;
  }
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
    <div v-if="!loading && !error && !shops.length" class="alert alert-secondary">{{ t('commerce.empty') }}</div>

    <div v-if="shops.length" class="sale-ecommerce-settings__table-wrap">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>{{ t('commerce.site') }}</th>
            <th>{{ t('commerce.language') }}</th>
            <th>{{ t('common.status') }}</th>
            <th>{{ t('commerce.channel') }}</th>
            <th>{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="shop in shops" :key="`${shop.site_id}:${shop.language_code}`">
            <td><strong>{{ shop.site_name }}</strong><small>{{ shop.site_key }}</small></td>
            <td>{{ shop.language_name }} <small>{{ shop.language_code.toUpperCase() }}</small></td>
            <td><span class="badge" :class="shop.public_channel_detected ? 'text-bg-warning' : 'text-bg-secondary'">{{ shop.public_channel_detected ? t('commerce.status.existing') : t('commerce.status.inactive') }}</span></td>
            <td>{{ shop.channel_name || '—' }}</td>
            <td class="sale-ecommerce-settings__actions">
              <RouterLink class="btn btn-sm btn-outline-primary" :to="shop.studio_route">{{ t('commerce.openStudio') }}</RouterLink>
              <a v-if="shop.preview_path" class="btn btn-sm btn-outline-secondary" :href="shop.preview_path" target="_blank" rel="noopener">{{ t('commerce.preview') }}</a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.sale-ecommerce-settings { display: grid; gap: 1rem; min-width: 0; }
.sale-ecommerce-settings__head h2 { align-items: center; display: flex; gap: .45rem; margin-bottom: .25rem; }
.sale-ecommerce-settings__head p,
.sale-ecommerce-settings__boundary p { margin: .25rem 0 0; }
.sale-ecommerce-settings__table-wrap { overflow-x: auto; }
.sale-ecommerce-settings td small { color: #64748b; display: block; }
.sale-ecommerce-settings__actions { display: flex; flex-wrap: wrap; gap: .4rem; }
</style>
