<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { apiErrorMessage } from '@/api/client';
import { businessCatalogApi, type CatalogDiscount, type CatalogImportReport, type CatalogProduct, type CatalogRecord, type CatalogVariant, type ProductDetail } from '@/api/businessCatalog';
import { useAdminContextStore } from '@/stores/adminContext';

type CatalogTab = 'products' | 'offers';
type IdValue = number | string | null | undefined;

const props = withDefaults(defineProps<{
  embedded?: boolean;
  fixedTab?: CatalogTab;
}>(), {
  embedded: false,
  fixedTab: undefined,
});

const context = useAdminContextStore();
const activeTab = ref<CatalogTab>(props.fixedTab ?? 'products');
const loading = ref(false);
const busy = ref('');
const error = ref('');
const success = ref('');

const canRead = computed(() => context.can('business.catalog.read'));
const canWrite = computed(() => context.can('business.catalog.write'));
const canPriceRead = computed(() => context.can('business.catalog.prices.read'));
const canPriceWrite = computed(() => context.can('business.catalog.prices.write'));
const canPurchaseRead = computed(() => context.can('business.catalog.purchase_prices.read'));
const canDiscountWrite = computed(() => context.can('business.catalog.discounts.write'));
const canStockWrite = computed(() => context.can('business.catalog.stock.write'));

const brands = ref<CatalogRecord[]>([]);
const categories = ref<CatalogRecord[]>([]);
const options = ref<CatalogRecord[]>([]);
const products = ref<CatalogProduct[]>([]);
const discounts = ref<CatalogDiscount[]>([]);
const selectedProduct = ref<ProductDetail | null>(null);
const selectedVariant = ref<CatalogVariant | null>(null);
const selectedDiscount = ref<CatalogDiscount | null>(null);
const variantStock = ref<Record<string, unknown> | null>(null);
const stockMovements = ref<Array<Record<string, unknown>>>([]);
const importCsvText = ref('');
const importReport = ref<CatalogImportReport | null>(null);
const importOptions = reactive({
  create_brands: true,
  create_categories: true,
  create_options: true,
  overwrite_existing: false,
});

const productFilter = reactive({ q: '', type: '', brand_id: '', category_id: '', status: '', channel: '', low_stock: false });
const productForm = reactive({
  id: 0,
  name: '',
  slug: '',
  type: 'physical',
  status: 'draft',
  brand_id: '',
  category_id: '',
  short_description: '',
  description: '',
  is_public: false,
  is_ecommerce_enabled: true,
  is_pos_enabled: false,
  base_purchase_price: '',
  base_sale_price: '',
  currency: 'CHF',
  option_ids: [] as number[]
});
const variantForm = reactive({
  sku: '',
  barcode: '',
  name: '',
  status: 'active',
  stock_quantity: '0',
  track_stock: true,
  purchase_adjustment_type: 'none',
  purchase_adjustment_value: '',
  sale_adjustment_type: 'none',
  sale_adjustment_value: ''
});
const stockForm = reactive({ movement_type: 'purchase', quantity: '1', reason: '', reference_type: '', reference_id: '' });
const discountForm = reactive({
  id: 0,
  name: '',
  status: 'active',
  type: 'percent',
  value: '',
  currency: 'CHF',
  scope: 'product',
  scope_id: '',
  channel: 'all',
  starts_at: '',
  ends_at: '',
  priority: '100'
});

const productTypes = ['physical', 'service', 'gift_card'];
const statuses = ['draft', 'active', 'archived'];
const channels = ['public', 'ecommerce', 'pos'];
const discountTypes = ['percent', 'amount'];
const discountScopes = ['brand', 'category', 'product', 'variant'];
const discountChannels = ['all', 'ecommerce', 'pos', 'admin'];
const movementTypes = ['initial', 'purchase', 'sale', 'adjustment', 'return', 'reservation', 'release'];
const adjustmentTypes = ['none', 'amount_delta', 'percent_delta', 'fixed_override'];

const productData = computed(() => selectedProduct.value?.data || null);
const selectedVariants = computed(() => selectedProduct.value?.variants || []);
const selectedPrices = computed(() => selectedProduct.value?.prices || []);
const selectedProductStock = computed(() => selectedProduct.value?.stock || []);
const selectedOffers = computed(() => (selectedProduct.value?.offers || []).filter((offer) => !offer.archived_at));
const selectedProductId = computed(() => Number(productData.value?.id || productForm.id || 0));
const exportCsvUrl = computed(() => businessCatalogApi.exportCsvUrl());
const importHasErrors = computed(() => Number(importReport.value?.skipped || 0) > 0 || Number(importReport.value?.errors?.length || 0) > 0);

function setNotice(message: string): void {
  success.value = message;
  error.value = '';
}

function setError(err: unknown, fallback = 'Action catalogue impossible.'): void {
  error.value = apiErrorMessage(err, fallback);
  success.value = '';
}

function idOrNull(value: IdValue): number | null {
  const number = Number(value || 0);
  return Number.isFinite(number) && number > 0 ? number : null;
}

function text(value: unknown): string {
  return value === null || value === undefined ? '' : String(value);
}

function money(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  return String(value);
}

function optionLabel(option: Record<string, unknown>): string {
  const name = text(option.name || option.option_name || option.label);
  const value = text(option.label || option.value || option.value_code);
  return value && name !== value ? `${name}: ${value}` : name || value || 'Option';
}

function brandName(id: IdValue): string {
  return brands.value.find((item) => item.id === Number(id || 0))?.name || (id ? `Marque #${id}` : '—');
}

function categoryName(id: IdValue): string {
  return categories.value.find((item) => item.id === Number(id || 0))?.name || (id ? `Catégorie #${id}` : '—');
}

function productName(id: IdValue): string {
  const product = products.value.find((item) => item.id === Number(id || 0));
  return product?.name || (id ? `Produit #${id}` : '—');
}

function variantName(id: IdValue): string {
  for (const product of products.value) {
    if (product.id === Number(id || 0)) return text(product.name);
  }
  const variant = selectedVariants.value.find((item) => item.id === Number(id || 0));
  return variant?.sku || variant?.name || (id ? `Variante #${id}` : '—');
}

function channelsFor(product: CatalogProduct): string[] {
  return [
    product.is_public ? 'public' : '',
    product.is_ecommerce_enabled ? 'e-commerce' : '',
    product.is_pos_enabled ? 'POS' : '',
  ].filter(Boolean);
}

function resetProductForm(): void {
  Object.assign(productForm, {
    id: 0,
    name: '',
    slug: '',
    type: 'physical',
    status: 'draft',
    brand_id: '',
    category_id: '',
    short_description: '',
    description: '',
    is_public: false,
    is_ecommerce_enabled: true,
    is_pos_enabled: false,
    base_purchase_price: '',
    base_sale_price: '',
    currency: 'CHF',
    option_ids: [],
  });
  selectedProduct.value = null;
  selectedVariant.value = null;
  variantStock.value = null;
  stockMovements.value = [];
}

function fillProductForm(detail: ProductDetail): void {
  const data = (detail.data || {}) as Partial<CatalogProduct>;
  Object.assign(productForm, {
    id: Number(data.id || 0),
    name: text(data.name),
    slug: text(data.slug),
    type: text(data.type || 'physical'),
    status: text(data.status || 'draft'),
    brand_id: text(data.brand_id || ''),
    category_id: text(data.category_id || ''),
    short_description: text(data.short_description),
    description: text(data.description),
    is_public: Boolean(data.is_public),
    is_ecommerce_enabled: Boolean(data.is_ecommerce_enabled),
    is_pos_enabled: Boolean(data.is_pos_enabled),
    currency: 'CHF',
    option_ids: Array.isArray(detail.options) ? detail.options.map((option) => Number(option.id || 0)).filter(Boolean) : [],
  });
  const sale = selectedPrices.value.find((price) => price.price_kind === 'sale');
  const purchase = selectedPrices.value.find((price) => price.price_kind === 'purchase');
  productForm.base_sale_price = text(sale?.amount || '');
  productForm.base_purchase_price = text(purchase?.amount || '');
  productForm.currency = text(sale?.currency || purchase?.currency || 'CHF');
}

function productPayload(): Record<string, unknown> {
  const channelList = [
    productForm.is_public ? 'public' : '',
    productForm.is_ecommerce_enabled ? 'ecommerce' : '',
    productForm.is_pos_enabled ? 'pos' : '',
  ].filter(Boolean);
  return {
    name: productForm.name,
    slug: productForm.slug || productForm.name,
    type: productForm.type,
    status: productForm.status,
    brand_id: idOrNull(productForm.brand_id),
    category_id: idOrNull(productForm.category_id),
    short_description: productForm.short_description,
    description: productForm.description,
    channels: channelList.length > 0 ? channelList : ['internal'],
    is_public: productForm.is_public,
    is_ecommerce_enabled: productForm.is_ecommerce_enabled,
    is_pos_enabled: productForm.is_pos_enabled,
    option_ids: productForm.option_ids,
    base_purchase_price: productForm.base_purchase_price || undefined,
    base_sale_price: productForm.base_sale_price || undefined,
    currency: productForm.currency || 'CHF',
  };
}

function resetVariantForm(): void {
  Object.assign(variantForm, {
    sku: '',
    barcode: '',
    name: '',
    status: 'active',
    stock_quantity: '0',
    track_stock: true,
    purchase_adjustment_type: 'none',
    purchase_adjustment_value: '',
    sale_adjustment_type: 'none',
    sale_adjustment_value: '',
  });
}

function fillDiscountForm(discount: CatalogDiscount | null = null): void {
  Object.assign(discountForm, {
    id: Number(discount?.id || 0),
    name: text(discount?.name),
    status: text(discount?.status || 'active'),
    type: text(discount?.discount_type || 'percent'),
    value: text(discount?.discount_value || ''),
    currency: text(discount?.currency || 'CHF'),
    scope: text(discount?.scope_type || 'product'),
    scope_id: text(discount?.scope_id || ''),
    channel: text(discount?.channel || 'all'),
    starts_at: text(discount?.starts_at),
    ends_at: text(discount?.ends_at),
    priority: text(discount?.priority || 100),
  });
  selectedDiscount.value = discount;
}

function discountScopeLabel(discount: CatalogDiscount): string {
  if (discount.scope_type === 'brand') return brandName(discount.scope_id);
  if (discount.scope_type === 'category') return categoryName(discount.scope_id);
  if (discount.scope_type === 'variant') return variantName(discount.scope_id);
  return productName(discount.scope_id);
}

async function loadCatalog(): Promise<void> {
  if (!canRead.value) return;
  loading.value = true;
  try {
    const [brandResponse, categoryResponse, optionResponse, productResponse, discountResponse] = await Promise.all([
      businessCatalogApi.brands(),
      businessCatalogApi.categories(),
      businessCatalogApi.options(),
      businessCatalogApi.products({
        q: productFilter.q,
        type: productFilter.type || undefined,
        brand_id: productFilter.brand_id || undefined,
        category_id: productFilter.category_id || undefined,
        status: productFilter.status || undefined,
        channel: productFilter.channel || undefined,
        low_stock: productFilter.low_stock ? 1 : undefined,
        limit: 100,
      }),
      businessCatalogApi.discounts(),
    ]);
    brands.value = brandResponse.data.brands || [];
    categories.value = categoryResponse.data.categories || [];
    options.value = optionResponse.data.options || [];
    products.value = productResponse.data.products || [];
    discounts.value = discountResponse.data.discounts || [];
  } catch (err) {
    setError(err, 'Catalogue indisponible.');
  } finally {
    loading.value = false;
  }
}

async function selectProduct(product: CatalogProduct): Promise<void> {
  loading.value = true;
  try {
    const response = await businessCatalogApi.product(Number(product.id));
    selectedProduct.value = response.data.product;
    fillProductForm(response.data.product);
    selectedVariant.value = selectedProduct.value.variants?.[0] || null;
    if (selectedVariant.value) await loadVariantStock(selectedVariant.value);
  } catch (err) {
    setError(err, 'Produit indisponible.');
  } finally {
    loading.value = false;
  }
}

async function saveProduct(): Promise<void> {
  if (!canWrite.value) return;
  busy.value = 'product';
  try {
    const id = selectedProductId.value;
    const response = id > 0
      ? await businessCatalogApi.updateProduct(id, productPayload())
      : await businessCatalogApi.createProduct(productPayload());
    selectedProduct.value = response.data.product;
    fillProductForm(response.data.product);
    if (id > 0 && canPriceWrite.value) {
      await businessCatalogApi.updateBasePrices(id, {
        base_purchase_price: canPurchaseRead.value ? productForm.base_purchase_price || undefined : undefined,
        base_sale_price: productForm.base_sale_price || undefined,
        currency: productForm.currency || 'CHF',
      });
      await selectProduct({ id, name: productForm.name });
    }
    await loadCatalog();
    setNotice('Produit enregistré.');
  } catch (err) {
    setError(err, 'Produit non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function createVariant(): Promise<void> {
  const productId = selectedProductId.value;
  if (!canWrite.value || productId < 1) return;
  busy.value = 'variant';
  try {
    await businessCatalogApi.createVariant(productId, {
      sku: variantForm.sku,
      barcode: variantForm.barcode || undefined,
      name: variantForm.name || variantForm.sku,
      status: variantForm.status,
      stock_quantity: Number(variantForm.stock_quantity || 0),
      track_stock: variantForm.track_stock,
      purchase_adjustment_type: canPurchaseRead.value ? variantForm.purchase_adjustment_type : 'none',
      purchase_adjustment_value: canPurchaseRead.value ? variantForm.purchase_adjustment_value || undefined : undefined,
      sale_adjustment_type: variantForm.sale_adjustment_type,
      sale_adjustment_value: variantForm.sale_adjustment_value || undefined,
    });
    resetVariantForm();
    await selectProduct({ id: productId, name: productForm.name });
    setNotice('Variante créée.');
  } catch (err) {
    setError(err, 'Variante non créée.');
  } finally {
    busy.value = '';
  }
}

async function selectVariant(variant: CatalogVariant): Promise<void> {
  selectedVariant.value = variant;
  await loadVariantStock(variant);
}

async function loadVariantStock(variant: CatalogVariant): Promise<void> {
  if (!variant.id) return;
  try {
    const response = await businessCatalogApi.stock(Number(variant.id));
    variantStock.value = response.data.stock;
    stockMovements.value = response.data.movements || [];
  } catch (_) {
    variantStock.value = null;
    stockMovements.value = [];
  }
}

async function saveVariantAdjustments(variant: CatalogVariant): Promise<void> {
  if (!canPriceWrite.value || !variant.id) return;
  busy.value = `variant-${variant.id}`;
  try {
    await businessCatalogApi.updateVariantPriceAdjustments(Number(variant.id), {
      sale_adjustment_type: variant.sale_adjustment_type || 'none',
      sale_adjustment_value: variant.sale_adjustment_value || undefined,
      purchase_adjustment_type: canPurchaseRead.value ? variant.purchase_adjustment_type || 'none' : undefined,
      purchase_adjustment_value: canPurchaseRead.value ? variant.purchase_adjustment_value || undefined : undefined,
    });
    await selectProduct({ id: selectedProductId.value, name: productForm.name });
    setNotice('Ajustements enregistrés.');
  } catch (err) {
    setError(err, 'Ajustements non enregistrés.');
  } finally {
    busy.value = '';
  }
}

async function createStockMovement(): Promise<void> {
  if (!canStockWrite.value || !selectedVariant.value?.id) return;
  busy.value = 'stock';
  try {
    await businessCatalogApi.createStockMovement(Number(selectedVariant.value.id), {
      movement_type: stockForm.movement_type,
      quantity: Number(stockForm.quantity || 0),
      reason: stockForm.reason || undefined,
      reference_type: stockForm.reference_type || undefined,
      reference_id: idOrNull(stockForm.reference_id),
    });
    await loadVariantStock(selectedVariant.value);
    await selectProduct({ id: selectedProductId.value, name: productForm.name });
    setNotice('Mouvement enregistré.');
  } catch (err) {
    setError(err, 'Mouvement non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function saveDiscount(): Promise<void> {
  if (!canDiscountWrite.value) return;
  busy.value = 'discount';
  try {
    const payload = {
      name: discountForm.name,
      status: discountForm.status,
      type: discountForm.type,
      value: Number(discountForm.value || 0),
      currency: discountForm.type === 'amount' ? discountForm.currency || 'CHF' : undefined,
      scope: discountForm.scope,
      scope_id: idOrNull(discountForm.scope_id),
      channel: discountForm.channel,
      starts_at: discountForm.starts_at || undefined,
      ends_at: discountForm.ends_at || undefined,
      priority: Number(discountForm.priority || 100),
    };
    if (discountForm.id > 0) {
      await businessCatalogApi.updateDiscount(discountForm.id, payload);
    } else {
      await businessCatalogApi.createDiscount(payload);
    }
    fillDiscountForm();
    await loadCatalog();
    if (selectedProductId.value > 0) await selectProduct({ id: selectedProductId.value, name: productForm.name });
    setNotice('Offre enregistrée.');
  } catch (err) {
    setError(err, 'Offre non enregistrée.');
  } finally {
    busy.value = '';
  }
}

async function archiveDiscount(discount: CatalogDiscount): Promise<void> {
  if (!canDiscountWrite.value) return;
  busy.value = `discount-${discount.id}`;
  try {
    await businessCatalogApi.archiveDiscount(discount.id);
    await loadCatalog();
    fillDiscountForm();
    setNotice('Offre archivée.');
  } catch (err) {
    setError(err, 'Offre non archivée.');
  } finally {
    busy.value = '';
  }
}

function importPayload(): Record<string, unknown> {
  return {
    csv: importCsvText.value,
    create_brands: importOptions.create_brands,
    create_categories: importOptions.create_categories,
    create_options: importOptions.create_options,
    overwrite_existing: importOptions.overwrite_existing,
  };
}

async function previewCatalogImport(): Promise<void> {
  if (!canWrite.value || !importCsvText.value.trim()) return;
  busy.value = 'csv-preview';
  try {
    const response = await businessCatalogApi.previewImport(importPayload());
    importReport.value = response.data.import;
    setNotice(importHasErrors.value ? 'Prévisualisation terminée avec erreurs.' : 'Prévisualisation CSV valide.');
  } catch (err) {
    setError(err, 'Prévisualisation CSV impossible.');
  } finally {
    busy.value = '';
  }
}

async function applyCatalogImport(): Promise<void> {
  if (!canWrite.value || !importCsvText.value.trim() || importHasErrors.value) return;
  busy.value = 'csv-apply';
  try {
    const response = await businessCatalogApi.applyImport(importPayload());
    importReport.value = response.data.import;
    await loadCatalog();
    setNotice('Import CSV appliqué.');
  } catch (err) {
    setError(err, 'Import CSV impossible.');
  } finally {
    busy.value = '';
  }
}

watch(() => props.fixedTab, (tab) => {
  if (tab && activeTab.value !== tab) {
    activeTab.value = tab;
  }
});

watch(() => context.siteId, () => { void loadCatalog(); });

onMounted(async () => {
  await loadCatalog();
});
</script>

<template>
  <section class="business-catalog" :class="{ 'business-catalog--embedded': embedded }">
    <PageHeader v-if="!embedded" title="Catalogue Business" subtitle="Produits, variantes, prix, stock et offres." />
    <ApiFeedback :error="error" :success="success" />

    <div v-if="!canRead" class="card empty-state">
      <strong>Accès catalogue indisponible</strong>
    </div>

    <template v-else>
      <div v-if="!embedded" class="catalog-tabs card">
        <button class="btn" :class="{ primary: activeTab === 'products' }" type="button" @click="activeTab = 'products'">Produits</button>
        <button class="btn" :class="{ primary: activeTab === 'offers' }" type="button" @click="activeTab = 'offers'">Offres</button>
        <button class="btn ghost" type="button" :disabled="loading" @click="loadCatalog">Actualiser</button>
      </div>

      <div v-if="activeTab === 'products'" class="catalog-layout">
        <aside class="card catalog-list-card">
          <div class="panel__header">
            <div>
              <p class="eyebrow">Produits</p>
              <h2>Catalogue</h2>
            </div>
            <button class="btn ghost" type="button" :disabled="!canWrite" @click="resetProductForm">Nouveau</button>
          </div>

          <div class="catalog-filters">
            <input v-model="productFilter.q" class="input" type="search" placeholder="Recherche" @keyup.enter="loadCatalog">
            <select v-model="productFilter.type" class="select" @change="loadCatalog">
              <option value="">Types</option>
              <option v-for="type in productTypes" :key="type" :value="type">{{ type }}</option>
            </select>
            <select v-model="productFilter.status" class="select" @change="loadCatalog">
              <option value="">Statuts</option>
              <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
            </select>
            <select v-model="productFilter.brand_id" class="select" @change="loadCatalog">
              <option value="">Marques</option>
              <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
            </select>
            <select v-model="productFilter.category_id" class="select" @change="loadCatalog">
              <option value="">Catégories</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
            <select v-model="productFilter.channel" class="select" @change="loadCatalog">
              <option value="">Canaux</option>
              <option value="public">public</option>
              <option value="ecommerce">e-commerce</option>
              <option value="pos">POS</option>
            </select>
            <label class="checkbox-inline">
              <input v-model="productFilter.low_stock" type="checkbox" @change="loadCatalog">
              Stock faible
            </label>
          </div>

          <div class="catalog-csv-panel">
            <div class="toolbar">
              <a class="btn ghost" :href="exportCsvUrl">Exporter CSV</a>
              <button class="btn ghost" type="button" :disabled="!canWrite || !importCsvText.trim() || busy === 'csv-preview'" @click="previewCatalogImport">Prévisualiser</button>
              <button class="btn primary" type="button" :disabled="!canWrite || !importCsvText.trim() || importHasErrors || busy === 'csv-apply'" @click="applyCatalogImport">Importer</button>
            </div>
            <div class="catalog-import-options">
              <label class="checkbox-inline"><input v-model="importOptions.create_brands" type="checkbox" :disabled="!canWrite"> Créer marques</label>
              <label class="checkbox-inline"><input v-model="importOptions.create_categories" type="checkbox" :disabled="!canWrite"> Créer catégories</label>
              <label class="checkbox-inline"><input v-model="importOptions.create_options" type="checkbox" :disabled="!canWrite"> Créer options</label>
              <label class="checkbox-inline"><input v-model="importOptions.overwrite_existing" type="checkbox" :disabled="!canWrite"> Écraser confirmés</label>
            </div>
            <textarea v-model="importCsvText" class="input catalog-csv-input" rows="5" :disabled="!canWrite" placeholder="Coller un CSV catalogue"></textarea>
            <div v-if="importReport" class="catalog-import-report" :class="{ 'has-errors': importHasErrors }">
              <strong>{{ importReport.valid_rows || 0 }} ligne(s) valides / {{ importReport.rows_total || 0 }}</strong>
              <span>{{ importReport.skipped || 0 }} erreur(s)</span>
              <small v-for="row in importReport.rows || []" :key="Number(row.line || 0)">
                Ligne {{ row.line }} · {{ row.status }} · {{ row.action || '' }}
              </small>
            </div>
          </div>

          <div class="catalog-product-list">
            <button
              v-for="product in products"
              :key="product.id"
              class="catalog-product-row"
              :class="{ active: product.id === selectedProductId }"
              type="button"
              @click="selectProduct(product)"
            >
              <span>
                <strong>{{ product.name }}</strong>
                <small>{{ product.type }} · {{ brandName(product.brand_id) }} · {{ categoryName(product.category_id) }}</small>
              </span>
              <StatusBadge :status="product.status || 'draft'" />
              <small>{{ channelsFor(product).join(', ') || 'interne' }}</small>
            </button>
          </div>
        </aside>

        <main class="catalog-detail">
          <div class="card">
            <div class="panel__header">
              <div>
                <p class="eyebrow">Produit</p>
                <h2>{{ productForm.id ? productForm.name : 'Nouveau produit' }}</h2>
              </div>
              <button class="btn primary" type="button" :disabled="!canWrite || busy === 'product'" @click="saveProduct">Enregistrer</button>
            </div>

            <div class="catalog-form-grid">
              <label class="field">Nom<input v-model="productForm.name" class="input" :disabled="!canWrite"></label>
              <label class="field">Slug<input v-model="productForm.slug" class="input" :disabled="!canWrite"></label>
              <label class="field">Type<select v-model="productForm.type" class="select" :disabled="!canWrite"><option v-for="type in productTypes" :key="type" :value="type">{{ type }}</option></select></label>
              <label class="field">Statut<select v-model="productForm.status" class="select" :disabled="!canWrite"><option v-for="status in statuses" :key="status" :value="status">{{ status }}</option></select></label>
              <label class="field">Marque<select v-model="productForm.brand_id" class="select" :disabled="!canWrite"><option value="">Aucune</option><option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option></select></label>
              <label class="field">Catégorie<select v-model="productForm.category_id" class="select" :disabled="!canWrite"><option value="">Aucune</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
              <label class="field wide">Résumé<textarea v-model="productForm.short_description" class="input" rows="2" :disabled="!canWrite"></textarea></label>
              <label class="field wide">Description<textarea v-model="productForm.description" class="input" rows="4" :disabled="!canWrite"></textarea></label>
            </div>

            <div class="catalog-section">
              <h3>Canaux</h3>
              <div class="toolbar">
                <label class="checkbox-inline"><input v-model="productForm.is_public" type="checkbox" :disabled="!canWrite"> Public</label>
                <label class="checkbox-inline"><input v-model="productForm.is_ecommerce_enabled" type="checkbox" :disabled="!canWrite"> E-commerce</label>
                <label class="checkbox-inline"><input v-model="productForm.is_pos_enabled" type="checkbox" :disabled="!canWrite"> POS</label>
              </div>
            </div>

            <div class="catalog-section">
              <h3>Prix de base</h3>
              <div class="catalog-form-grid">
                <label v-if="canPurchaseRead" class="field">Prix d'achat de base<input v-model="productForm.base_purchase_price" class="input" :disabled="!canPriceWrite"></label>
                <label class="field">Prix de vente de base<input v-model="productForm.base_sale_price" class="input" :disabled="!canPriceWrite"></label>
                <label class="field">Devise<input v-model="productForm.currency" class="input" :disabled="!canPriceWrite"></label>
              </div>
            </div>

            <div class="catalog-section">
              <h3>Options utilisées</h3>
              <div class="option-grid">
                <label v-for="option in options" :key="option.id" class="checkbox-inline">
                  <input v-model="productForm.option_ids" type="checkbox" :value="option.id" :disabled="!canWrite || productForm.id > 0">
                  {{ option.name }}
                </label>
              </div>
            </div>
          </div>

          <div class="card catalog-section">
            <div class="panel__header">
              <div>
                <p class="eyebrow">Variantes</p>
                <h2>Prix calculés et stock</h2>
              </div>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Combinaison</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    <th>Statut</th>
                    <th v-if="canPurchaseRead">Achat</th>
                    <th>Vente</th>
                    <th>Stock</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="variant in selectedVariants" :key="variant.id">
                    <td>
                      <div class="token-row token-row--wrap">
                        <span v-for="option in variant.option_values || []" :key="`${variant.id}-${option.option_code}-${option.value_code}`" class="token">{{ optionLabel(option) }}</span>
                      </div>
                    </td>
                    <td>{{ variant.sku }}</td>
                    <td>{{ variant.barcode || '—' }}</td>
                    <td><StatusBadge :status="variant.status || 'draft'" /></td>
                    <td v-if="canPurchaseRead">
                      <div class="price-stack">
                        <small>{{ money(variant.computed_prices?.regular_purchase_price) }}</small>
                      </div>
                    </td>
                    <td>
                      <div class="price-stack">
                        <small>{{ money(variant.computed_prices?.regular_sale_price) }}</small>
                        <strong>{{ money(variant.computed_prices?.final_sale_price) }}</strong>
                        <small v-if="variant.computed_prices?.active_discount">- {{ (variant.computed_prices.active_discount as Record<string, unknown>).name }}</small>
                      </div>
                    </td>
                    <td>{{ variant.stock_quantity ?? 0 }} / {{ variant.stock_reserved ?? 0 }}</td>
                    <td class="action-cell">
                      <button class="btn ghost btn-sm" type="button" @click="selectVariant(variant)">Stock</button>
                      <button class="btn ghost btn-sm" type="button" :disabled="!canPriceWrite" @click="saveVariantAdjustments(variant)">Prix</button>
                    </td>
                  </tr>
                  <tr v-if="selectedVariants.length === 0">
                    <td colspan="8" class="muted">Aucune variante.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="catalog-form-grid variant-create">
              <label class="field">SKU<input v-model="variantForm.sku" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Barcode<input v-model="variantForm.barcode" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Nom<input v-model="variantForm.name" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Statut<select v-model="variantForm.status" class="select" :disabled="!canWrite || !selectedProductId"><option v-for="status in statuses" :key="status" :value="status">{{ status }}</option></select></label>
              <label class="field">Stock initial<input v-model="variantForm.stock_quantity" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="checkbox-inline"><input v-model="variantForm.track_stock" type="checkbox" :disabled="!canWrite || !selectedProductId"> Suivi stock</label>
              <label v-if="canPurchaseRead" class="field">Ajustement achat<select v-model="variantForm.purchase_adjustment_type" class="select" :disabled="!canWrite || !selectedProductId"><option v-for="type in adjustmentTypes" :key="type" :value="type">{{ type }}</option></select></label>
              <label v-if="canPurchaseRead" class="field">Valeur achat<input v-model="variantForm.purchase_adjustment_value" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Ajustement vente<select v-model="variantForm.sale_adjustment_type" class="select" :disabled="!canWrite || !selectedProductId"><option v-for="type in adjustmentTypes" :key="type" :value="type">{{ type }}</option></select></label>
              <label class="field">Valeur vente<input v-model="variantForm.sale_adjustment_value" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <button class="btn primary" type="button" :disabled="!canWrite || !selectedProductId || busy === 'variant'" @click="createVariant">Ajouter variante</button>
            </div>
          </div>

          <div class="grid grid-2">
            <div class="card catalog-section">
              <h3>Stock</h3>
              <div v-if="selectedVariant" class="stack">
                <div class="stock-summary">
                  <strong>{{ selectedVariant.sku }}</strong>
                  <span>{{ variantStock?.stock_quantity ?? selectedVariant.stock_quantity ?? 0 }} en stock</span>
                  <span>{{ variantStock?.stock_reserved ?? selectedVariant.stock_reserved ?? 0 }} réservé</span>
                </div>
                <div class="catalog-form-grid">
                  <label class="field">Mouvement<select v-model="stockForm.movement_type" class="select" :disabled="!canStockWrite"><option v-for="type in movementTypes" :key="type" :value="type">{{ type }}</option></select></label>
                  <label class="field">Quantité<input v-model="stockForm.quantity" class="input" :disabled="!canStockWrite"></label>
                  <label class="field wide">Raison<input v-model="stockForm.reason" class="input" :disabled="!canStockWrite"></label>
                  <label class="field">Référence<input v-model="stockForm.reference_type" class="input" :disabled="!canStockWrite"></label>
                  <label class="field">ID référence<input v-model="stockForm.reference_id" class="input" :disabled="!canStockWrite"></label>
                  <button class="btn primary" type="button" :disabled="!canStockWrite || busy === 'stock'" @click="createStockMovement">Enregistrer mouvement</button>
                </div>
                <div class="history-list">
                  <div v-for="movement in stockMovements" :key="movement.id as number" class="history-row">
                    <strong>{{ movement.movement_type }}</strong>
                    <span>{{ movement.quantity }}</span>
                    <small>{{ movement.reason || movement.created_at }}</small>
                  </div>
                </div>
              </div>
              <p v-else class="muted">Sélectionnez une variante.</p>
            </div>

            <div class="card catalog-section">
              <h3>Offres actives</h3>
              <div class="stack">
                <div v-for="offer in selectedOffers" :key="offer.id" class="list-row">
                  <span>
                    <strong>{{ offer.name }}</strong>
                    <small>{{ offer.discount_type }} {{ offer.discount_value }} · {{ offer.scope_type }} #{{ offer.scope_id }}</small>
                  </span>
                  <StatusBadge :status="offer.status || 'active'" />
                </div>
                <p v-if="selectedOffers.length === 0" class="muted">Aucune offre.</p>
              </div>
            </div>
          </div>
        </main>
      </div>

      <div v-else class="catalog-layout catalog-layout--offers">
        <section class="card">
          <div class="panel__header">
            <div>
              <p class="eyebrow">Offres</p>
              <h2>Réductions simples</h2>
            </div>
            <button class="btn ghost" type="button" :disabled="!canDiscountWrite" @click="fillDiscountForm()">Nouvelle</button>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Nom</th>
                  <th>Statut</th>
                  <th>Type</th>
                  <th>Valeur</th>
                  <th>Portée</th>
                  <th>Canal</th>
                  <th>Dates</th>
                  <th>Priorité</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="discount in discounts" :key="discount.id">
                  <td>{{ discount.name }}</td>
                  <td><StatusBadge :status="discount.status || 'active'" /></td>
                  <td>{{ discount.discount_type }}</td>
                  <td>{{ discount.discount_value }} {{ discount.discount_type === 'amount' ? discount.currency : '%' }}</td>
                  <td>{{ discount.scope_type }} · {{ discountScopeLabel(discount) }}</td>
                  <td>{{ discount.channel }}</td>
                  <td>{{ discount.starts_at || '—' }} → {{ discount.ends_at || '—' }}</td>
                  <td>{{ discount.priority }}</td>
                  <td class="action-cell">
                    <button class="btn ghost btn-sm" type="button" @click="fillDiscountForm(discount)">Modifier</button>
                    <button class="btn ghost btn-sm" type="button" :disabled="!canDiscountWrite || busy === `discount-${discount.id}`" @click="archiveDiscount(discount)">Archiver</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <aside class="card catalog-editor-card">
          <div class="panel__header">
            <div>
              <p class="eyebrow">Édition</p>
              <h2>{{ discountForm.id ? discountForm.name : 'Nouvelle offre' }}</h2>
            </div>
            <button class="btn primary" type="button" :disabled="!canDiscountWrite || busy === 'discount'" @click="saveDiscount">Enregistrer</button>
          </div>
          <div class="catalog-form-grid">
            <label class="field wide">Nom<input v-model="discountForm.name" class="input" :disabled="!canDiscountWrite"></label>
            <label class="field">Statut<select v-model="discountForm.status" class="select" :disabled="!canDiscountWrite"><option v-for="status in statuses" :key="status" :value="status">{{ status }}</option></select></label>
            <label class="field">Type<select v-model="discountForm.type" class="select" :disabled="!canDiscountWrite"><option v-for="type in discountTypes" :key="type" :value="type">{{ type }}</option></select></label>
            <label class="field">Valeur<input v-model="discountForm.value" class="input" :disabled="!canDiscountWrite"></label>
            <label v-if="discountForm.type === 'amount'" class="field">Devise<input v-model="discountForm.currency" class="input" :disabled="!canDiscountWrite"></label>
            <label class="field">Portée<select v-model="discountForm.scope" class="select" :disabled="!canDiscountWrite"><option v-for="scope in discountScopes" :key="scope" :value="scope">{{ scope }}</option></select></label>
            <label class="field">ID portée<input v-model="discountForm.scope_id" class="input" :disabled="!canDiscountWrite"></label>
            <label class="field">Canal<select v-model="discountForm.channel" class="select" :disabled="!canDiscountWrite"><option v-for="channel in discountChannels" :key="channel" :value="channel">{{ channel }}</option></select></label>
            <label class="field">Début<input v-model="discountForm.starts_at" class="input" type="datetime-local" :disabled="!canDiscountWrite"></label>
            <label class="field">Fin<input v-model="discountForm.ends_at" class="input" type="datetime-local" :disabled="!canDiscountWrite"></label>
            <label class="field">Priorité<input v-model="discountForm.priority" class="input" :disabled="!canDiscountWrite"></label>
          </div>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.business-catalog {
  display: grid;
  gap: 1rem;
}

.catalog-tabs {
  display: flex;
  gap: .55rem;
  align-items: center;
  flex-wrap: wrap;
}

.catalog-layout {
  display: grid;
  grid-template-columns: minmax(320px, .72fr) minmax(0, 1.28fr);
  gap: 1rem;
  align-items: start;
}

.catalog-layout--offers {
  grid-template-columns: minmax(0, 1.25fr) minmax(360px, .75fr);
}

.catalog-list-card,
.catalog-editor-card {
  position: sticky;
  top: 6rem;
}

.catalog-filters,
.catalog-product-list,
.catalog-detail,
.history-list {
  display: grid;
  gap: .65rem;
}

.catalog-csv-panel {
  border: 1px solid #e5e7eb;
  border-radius: .75rem;
  display: grid;
  gap: .65rem;
  padding: .75rem;
}

.catalog-import-options {
  display: grid;
  gap: .35rem;
}

.catalog-csv-input {
  min-height: 8rem;
  resize: vertical;
}

.catalog-import-report {
  border-left: 3px solid #16a34a;
  color: #334155;
  display: grid;
  font-size: .86rem;
  gap: .25rem;
  padding-left: .6rem;
}

.catalog-import-report.has-errors {
  border-left-color: #dc2626;
}

.catalog-product-row {
  border: 1px solid #e5e7eb;
  border-radius: .9rem;
  background: #fff;
  padding: .75rem;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: .5rem;
  text-align: left;
  cursor: pointer;
}

.catalog-product-row:hover,
.catalog-product-row.active {
  border-color: #0f172a;
  box-shadow: 0 0 0 2px rgba(15, 23, 42, .08);
}

.catalog-product-row small {
  color: #64748b;
}

.catalog-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
}

.catalog-form-grid .wide {
  grid-column: 1 / -1;
}

.catalog-section {
  margin-top: 1rem;
}

.catalog-section h3 {
  margin: .1rem 0 .75rem;
  font-size: 1rem;
  color: #0f172a;
}

.option-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .5rem;
}

.table-wrap {
  overflow-x: auto;
}

.price-stack {
  display: grid;
  gap: .1rem;
}

.price-stack small {
  color: #64748b;
}

.action-cell {
  display: flex;
  gap: .35rem;
  flex-wrap: wrap;
}

.variant-create {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid #e5e7eb;
}

.stock-summary {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
  align-items: center;
}

.stock-summary span,
.history-row {
  border: 1px solid #e5e7eb;
  border-radius: .75rem;
  background: #f8fafc;
  padding: .45rem .6rem;
}

.history-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: .25rem .5rem;
}

.history-row small {
  grid-column: 1 / -1;
  color: #64748b;
}

@media (max-width: 1120px) {
  .catalog-layout,
  .catalog-layout--offers {
    grid-template-columns: 1fr;
  }

  .catalog-list-card,
  .catalog-editor-card {
    position: static;
  }
}

@media (max-width: 720px) {
  .catalog-form-grid,
  .option-grid {
    grid-template-columns: 1fr;
  }
}
</style>
