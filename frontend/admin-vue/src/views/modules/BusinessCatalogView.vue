<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { apiErrorMessage } from '@/api/client';
import { businessCatalogApi, type CatalogAttribute, type CatalogAttributeGroup, type CatalogAttributeValue, type CatalogBulkReport, type CatalogBundleComponent, type CatalogDiscount, type CatalogImportReport, type CatalogProduct, type CatalogProductAsset, type CatalogProductBundle, type CatalogRecord, type CatalogTaxClass, type CatalogVariant, type ProductContentCandidate, type ProductContentLink, type ProductDetail, type ProductRelation, type ProductRelationRule } from '@/api/businessCatalog';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n } from '@/i18n';
import BusinessPageHeader from './business/BusinessPageHeader.vue';
import type { MediaAsset } from '@/api/contracts';

type CatalogTab = 'products' | 'offers';
type IdValue = number | string | null | undefined;
type ProductSection = 'summary' | 'variants' | 'stock' | 'prices' | 'media' | 'attributes' | 'quality' | 'channels';
type ProductSignal = { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' };
type ProductColumnKey = 'sku' | 'brand' | 'variants' | 'stock' | 'price' | 'image' | 'channels' | 'status' | 'quality';
type ProductFilterKey = 'type' | 'status' | 'brand_id' | 'category_id' | 'channel' | 'archived' | 'low_stock' | 'view' | 'image' | 'price' | 'purchase_price';
type ProductEditField = 'type' | 'status' | 'brand' | 'category';
type ProductEditFocus = ProductEditField | 'sku' | 'name' | 'slug' | 'name_type';
type ProductModalScope = 'all' | 'identity' | 'classification' | 'stock' | 'prices' | 'tax' | 'media' | 'attributes' | 'channels';
type ProductReferenceKind = ProductEditField | 'tax';
type AttributeSettingsModal = 'groups' | 'attributes' | 'options';
type ProductSortKey = ProductColumnKey | 'product';
type ProductPageSize = 10 | 25 | 50 | 100 | 'all';
type BulkTriState = '' | '1' | '0';
type OfferKind = 'bundle' | 'discount';
type OfferFilterKey = 'type' | 'status' | 'channel';
type OfferSortKey = 'type' | 'name' | 'status' | 'channels';
type OfferPageSize = 10 | 25 | 50 | 100 | 'all';
type CatalogOfferRow = {
  key: string;
  kind: OfferKind;
  id: number;
  name: string;
  status: string;
  channels: string[];
  detail: string;
  bundle?: CatalogProduct;
  discount?: CatalogDiscount;
};

const props = withDefaults(defineProps<{
  embedded?: boolean;
  fixedTab?: CatalogTab;
  initialProductId?: number;
}>(), {
  embedded: false,
  fixedTab: undefined,
  initialProductId: 0,
});

const context = useAdminContextStore();
const route = useRoute();
const { t } = useI18n();
const activeTab = ref<CatalogTab>(props.fixedTab ?? 'products');
const activeProductSection = ref<ProductSection>('summary');
const productModalOpen = ref(false);
const inventoryAdjustmentOpen = ref(false);
const productModalMode = ref<'create' | 'edit'>('create');
const productModalScope = ref<ProductModalScope>('all');
const productModalFocus = ref<ProductEditFocus | ''>('');
const productViewModalOpen = ref(false);
const variantEditModalOpen = ref(false);
const variantAttributesOnlyModal = ref(false);
const variantPriceModalOpen = ref(false);
const variantMenuProductId = ref(0);
const stockMenuProductId = ref(0);
const referenceModalOpen = ref(false);
const referenceKind = ref<ProductReferenceKind>('type');
const attributeSettingsModal = ref<AttributeSettingsModal | ''>('');
const showImportPanel = ref(false);
const showOfferImportPanel = ref(false);
const offerViewModalOpen = ref(false);
const offerEditModalOpen = ref(false);
const offerEditKind = ref<OfferKind>('bundle');
const selectedOfferRow = ref<CatalogOfferRow | null>(null);
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
const brandCompanies = ref<CatalogRecord[]>([]);
const categories = ref<CatalogRecord[]>([]);
const options = ref<CatalogRecord[]>([]);
const products = ref<CatalogProduct[]>([]);
const bundleProductRows = ref<CatalogProduct[]>([]);
const bundleComponentProductRows = ref<CatalogProduct[]>([]);
const discounts = ref<CatalogDiscount[]>([]);
const productAssets = ref<CatalogProductAsset[]>([]);
const productContentLinks = ref<ProductContentLink[]>([]);
const productRelations = ref<ProductRelation[]>([]);
const productRelationRules = ref<ProductRelationRule[]>([]);
const contentCandidates = ref<ProductContentCandidate[]>([]);
const taxClasses = ref<CatalogTaxClass[]>([]);
const attributeGroups = ref<CatalogAttributeGroup[]>([]);
const attributes = ref<CatalogAttribute[]>([]);
const productAttributeValues = ref<CatalogAttributeValue[]>([]);
const variantAttributeValues = ref<CatalogAttributeValue[]>([]);
const variantAttributeValuesById = ref<Record<number, CatalogAttributeValue[]>>({});
const mediaRows = ref<CatalogRecord[]>([]);
const selectedProduct = ref<ProductDetail | null>(null);
const selectedVariant = ref<CatalogVariant | null>(null);
const selectedDiscount = ref<CatalogDiscount | null>(null);
const offerWizardStep = ref(1);
const offerPreview = ref<Record<string, any> | null>(null);
const offerConflictAcknowledged = ref(false);
const selectedBundleProductId = ref(0);
const selectedBundle = ref<CatalogProductBundle | null>(null);
const bundleLoading = ref(false);
const variantStock = ref<Record<string, unknown> | null>(null);
const stockLocations = ref<Array<Record<string, unknown>>>([]);
const stockByLocation = ref<Array<Record<string, unknown>>>([]);
const stockPreview = ref<Record<string, any> | null>(null);
const stockMovements = ref<Array<Record<string, unknown>>>([]);
const importCsvText = ref('');
const importReport = ref<CatalogImportReport | null>(null);
const offerImportCsvText = ref('');
const offerImportReport = ref<CatalogImportReport | null>(null);
const bulkReport = ref<CatalogBulkReport | null>(null);
const selectedProductIds = ref<number[]>([]);
const offerBulkReport = ref<CatalogBulkReport | null>(null);
const selectedOfferKeys = ref<string[]>([]);
const importOptions = reactive({
  create_brands: true,
  create_categories: true,
  create_options: true,
  overwrite_existing: false,
});
const bulkProductForm = reactive({
  status: '',
  brand_id: '',
  category_id: '',
  tax_class_id: '',
  is_public: '' as BulkTriState,
  is_ecommerce_enabled: '' as BulkTriState,
  is_pos_enabled: '' as BulkTriState,
  is_catalogue_enabled: '' as BulkTriState,
  archive: false,
});
const bulkOfferForm = reactive({
  status: '',
  channel: '',
  archive: false,
});
const bundleForm = reactive({
  bundle_variant_id: '',
  pricing_mode: 'fixed',
  stock_mode: 'components',
  stock_strategy: 'COMPONENT_DERIVED' as 'OWN_STOCK' | 'COMPONENT_DERIVED' | 'NON_STOCKED',
  partial_availability_policy: 'REQUIRE_ALL' as 'REQUIRE_ALL' | 'ALLOW_PARTIAL',
  component_return_policy: 'BUNDLE_ONLY' as 'BUNDLE_ONLY' | 'COMPONENTS_ALLOWED',
  components_public: true,
  is_active: true,
});
const bundleIdentityForm = reactive({
  sku_base: '',
  name: '',
  slug: '',
  short_description: '',
  description: '',
  status: 'draft',
  is_public: false,
  is_ecommerce_enabled: true,
  is_pos_enabled: true,
  is_catalogue_enabled: true,
});
const bundlePriceForm = reactive({
  currency: 'CHF',
  base_purchase_price: '',
  base_sale_price: '',
});
const bundleComponentForm = reactive({
  component_product_id: '',
  component_variant_id: '',
  quantity: '1',
  is_required: true,
});
const bundleComponentSearch = ref('');

const productFilter = reactive({ q: '', type: '', brand_id: '', category_id: '', status: '', channel: '', archived: '1', low_stock: false, view: '', image: '', price: '', purchase_price: false });
const offerFilter = reactive({ q: '', type: '', status: '', channel: '' });
const appliedProductFilters = reactive({ type: '', brand_id: '', category_id: '', status: '', channel: '', archived: '', low_stock: false, view: '', image: '', price: '', purchase_price: false });
const appliedOfferFilters = reactive({ type: '', status: '', channel: '' });
const productSort = reactive<{ key: ProductSortKey; direction: 'asc' | 'desc' }>({ key: 'product', direction: 'asc' });
const productPage = ref(1);
const productPageSize = ref<ProductPageSize>(25);
const productPagination = ref({ limit: 25, offset: 0, total: 0 });
const productPageSizeOptions: ProductPageSize[] = [10, 25, 50, 100, 'all'];
const offerSort = reactive<{ key: OfferSortKey; direction: 'asc' | 'desc' }>({ key: 'name', direction: 'asc' });
const offerPage = ref(1);
const offerPageSize = ref<OfferPageSize>(25);
const offerPageSizeOptions: OfferPageSize[] = [10, 25, 50, 100, 'all'];
const productColumns: Array<{ key: ProductColumnKey; label: string }> = [
  { key: 'sku', label: 'SKU' },
  { key: 'brand', label: 'Marque / Catégorie' },
  { key: 'variants', label: 'Variantes' },
  { key: 'stock', label: 'Stock' },
  { key: 'price', label: 'Vente / Achat' },
  { key: 'image', label: 'Image' },
  { key: 'channels', label: 'Canaux' },
  { key: 'status', label: 'Statut' },
  { key: 'quality', label: 'Complétude' },
];
const visibleProductColumns = reactive<Record<ProductColumnKey, boolean>>({ sku: true, brand: true, variants: true, stock: true, price: true, image: true, channels: true, status: true, quality: true });
const productForm = reactive({
  id: 0,
  sku_base: '',
  name: '',
  slug: '',
  type: 'physical',
  status: 'draft',
  brand_id: '',
  category_id: '',
  unit: 'unit',
  tax_class_id: '',
  track_stock: false,
  allow_backorder: true,
  backorder_delivery_days: '7',
  short_description: '',
  description: '',
  is_public: false,
  is_ecommerce_enabled: true,
  is_pos_enabled: false,
  is_catalogue_enabled: true,
  base_purchase_price: '',
  base_sale_price: '',
  currency: 'CHF',
  attribute_group_ids: [] as number[],
  option_ids: [] as number[]
});
const variantForm = reactive({
  sku: '',
  barcode: '',
  name: '',
  sales_note: '',
  status: 'active',
  stock_quantity: '0',
  track_stock: true,
  allow_backorder: true,
  backorder_delivery_days: '',
  purchase_adjustment_type: 'none',
  purchase_adjustment_value: '',
  sale_adjustment_type: 'none',
  sale_adjustment_value: ''
});
const stockForm = reactive({ action: 'receipt', quantity: '1', reason: '', stock_location_id: '' });
const discountForm = reactive({
  id: 0,
  objective: 'increase_sales',
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
  priority: '100',
  customer_segment: ''
});
const brandForm = reactive({ id: 0, name: '', slug: '', company_id: '', description: '', website_url: '', status: 'active', sort_order: '0' });
const categoryForm = reactive({ id: 0, name: '', slug: '', parent_id: '', description: '', sort_order: '0' });
const taxClassForm = reactive({ id: 0, code: '', name: '', rate: '0', country: 'CH', is_default: false, usage_count: 0 });
const assetForm = reactive({
  id: 0,
  media_id: '',
  variant_id: '',
  role: 'gallery',
  channel_scope: 'all',
  title: '',
  alt_text: '',
  caption: '',
  sort_order: '0',
  is_public: true,
});
const contentLinkForm = reactive({
  content_entry_id: '',
  relation_type: 'product_page',
  locale: '',
  is_canonical: false,
  schema_type: 'Product',
});
const productRelationForm = reactive({ search: '', target_product_id: '', relation_type: 'related', sort_order: '0' });
const productRelationRuleForm = reactive({ relation_type: 'related', match_type: 'category', match_id: '', result_limit: '6', sort_order: '0' });
const attributeGroupForm = reactive({ id: 0, code: '', name: '', description: '', sort_order: '0' });
const attributeForm = reactive({
  id: 0,
  group_id: '',
  code: '',
  name: '',
  data_type: 'text',
  unit: '',
  is_required: false,
  is_filterable: false,
  is_searchable: false,
  is_public: false,
  sort_order: '0',
});
const attributeOptionForm = reactive({ id: 0, attribute_id: '', code: '', label: '', value: '', color_hex: '', sort_order: '0' });
const productAttributeForm = reactive<Record<number, string>>({});
const variantAttributeForm = reactive<Record<number, string>>({});

const productTypes = ['physical', 'service', 'gift_card', 'bundle'];
const statuses = ['draft', 'active', 'archived'];
const channels = ['public', 'ecommerce', 'pos', 'catalogue'];
const discountTypes = ['percent', 'amount'];
const discountScopes = ['brand', 'category', 'product', 'variant'];
const discountChannels = ['all', 'ecommerce', 'pos', 'catalogue', 'admin'];
const movementTypes = ['initial', 'purchase', 'sale', 'adjustment', 'return', 'reservation', 'release'];
const adjustmentTypes = ['none', 'amount_delta', 'percent_delta', 'fixed_override'];
const assetChannels = ['all', 'public', 'ecommerce', 'pos', 'catalogue', 'admin', 'pdf'];
const contentRelationTypes = ['product_page', 'storytelling', 'faq', 'guide', 'comparison', 'seo', 'related'];
const productRelationTypes = ['related', 'alternative', 'accessory', 'upsell', 'cross_sell'];
const productRelationLabels: Record<string, string> = { related: 'Produits liés', alternative: 'Alternatives', accessory: 'Accessoires', upsell: 'Montée en gamme', cross_sell: 'Achat complémentaire' };
const attributeTypes = ['text', 'textarea', 'rich_text', 'number', 'decimal', 'boolean', 'select', 'multi_select', 'date', 'url', 'file', 'dimension', 'weight', 'color'];
const colorSwatches = ['#000000', '#334155', '#FFFFFF', '#E11D48', '#EA580C', '#F59E0B', '#16A34A', '#0EA5E9', '#2563EB', '#7C3AED', '#C026D3'];
const attributeTypeLabels: Record<string, string> = {
  text: 'Texte',
  textarea: 'Texte long',
  rich_text: 'Texte riche',
  number: 'Nombre',
  decimal: 'Décimal',
  boolean: 'Oui/non',
  select: 'Choix',
  multi_select: 'Choix multiple',
  date: 'Date',
  url: 'URL',
  file: 'Fichier',
  dimension: 'Dimension',
  weight: 'Poids',
  color: 'Couleur',
};
const assetRoleLabels: Record<string, string> = {
  main: 'Image principale',
  gallery: 'Galerie',
  variant: 'Image variante',
  thumbnail: 'Miniature',
  document: 'Document',
  technical_sheet: 'Fiche technique',
  internal: 'Fichier interne',
};
const assetChannelLabels: Record<string, string> = {
  all: 'Tous',
  public: 'Public',
  ecommerce: 'E-commerce',
  pos: 'POS',
  catalogue: 'Brochure',
  admin: 'Admin',
  pdf: 'PDF',
};
const defaultProductTypeLabels: Record<string, string> = { physical: 'Produits physiques', service: 'Services', gift_card: 'Bon cadeau', bundle: 'Offres composees' };
const defaultProductStatusLabels: Record<string, string> = { draft: 'Brouillons', active: 'Actifs', archived: 'Archivés' };
const productTypeLabels = reactive<Record<string, string>>({ ...defaultProductTypeLabels });
const productStatusLabels = reactive<Record<string, string>>({ ...defaultProductStatusLabels });
const productChannelLabels: Record<string, string> = { public: 'Public', ecommerce: 'E-commerce', pos: 'POS', catalogue: 'Brochure' };
const productViewLabels: Record<string, string> = {
  all: 'Tous les produits',
  to_complete: 'À compléter',
  ready_pos: 'POS prêts',
  ready_ecommerce: 'E-commerce prêts',
  ready_catalogue: 'Brochure prête',
  without_image: 'Sans image',
  without_price: 'Sans prix',
  low_stock: 'Stock faible',
};
const productImageFilterLabels: Record<string, string> = { with: 'Avec image', without: 'Sans image' };
const productPriceFilterLabels: Record<string, string> = { with: 'Avec prix de vente', without: 'Sans prix de vente' };
const quickProductFilters: Array<{ key: string; label: string }> = [
  { key: 'all', label: 'Tous les produits' },
  { key: 'to_complete', label: 'À compléter' },
  { key: 'ready_pos', label: 'POS prêts' },
  { key: 'ready_ecommerce', label: 'E-commerce prêts' },
  { key: 'ready_catalogue', label: 'Brochure prête' },
  { key: 'without_image', label: 'Sans image' },
  { key: 'without_price', label: 'Sans prix' },
  { key: 'low_stock', label: 'Stock faible' },
  { key: 'active', label: 'Actifs' },
  { key: 'draft', label: 'Brouillons' },
  { key: 'archived', label: 'Archivés' },
  { key: 'ecommerce', label: 'E-commerce' },
  { key: 'pos', label: 'POS' },
  { key: 'catalogue', label: 'Brochure' },
];

const productData = computed(() => selectedProduct.value?.data || null);
const selectedVariants = computed(() => selectedProduct.value?.variants || []);
const selectedPrices = computed(() => selectedProduct.value?.prices || []);
const selectedProductStock = computed(() => selectedProduct.value?.stock || []);
const selectedOffers = computed(() => (selectedProduct.value?.offers || []).filter((offer) => !offer.archived_at));
const selectedProductId = computed(() => Number(productData.value?.id || productForm.id || 0));
const productRelationCandidates = computed(() => {
  const query = productRelationForm.search.trim().toLocaleLowerCase();
  return bundleComponentProductRows.value.filter((product) => Number(product.id) !== selectedProductId.value && (!query || `${product.sku_base || ''} ${product.name || ''} ${product.slug || ''}`.toLocaleLowerCase().includes(query))).slice(0, 30);
});
const productRelationRuleTargets = computed(() => productRelationRuleForm.match_type === 'group' ? attributeGroups.value : categories.value);
const exportIncompleteCsvUrl = computed(() => businessCatalogApi.exportCsvUrl({ quality: 'incomplete' }));
const exportPosCsvUrl = computed(() => businessCatalogApi.exportCsvUrl({ channel: 'pos' }));
const exportEcommerceCsvUrl = computed(() => businessCatalogApi.exportCsvUrl({ channel: 'ecommerce' }));
const exportCatalogueCsvUrl = computed(() => businessCatalogApi.exportCsvUrl({ channel: 'catalogue' }));
const exportCataloguePdfUrl = computed(() => businessCatalogApi.exportPdfUrl({ channel: 'catalogue' }));
const importHasErrors = computed(() => Number(importReport.value?.skipped || 0) > 0 || Number(importReport.value?.errors?.length || 0) > 0);
const exportOffersCsvUrl = computed(() => businessCatalogApi.exportOffersCsvUrl());
const exportBundlesCsvUrl = computed(() => businessCatalogApi.exportOffersCsvUrl({ type: 'bundle' }));
const exportDiscountsCsvUrl = computed(() => businessCatalogApi.exportOffersCsvUrl({ type: 'discount' }));
const offerImportHasErrors = computed(() => Number(offerImportReport.value?.skipped || 0) > 0 || Number(offerImportReport.value?.errors?.length || 0) > 0);
const selectedProductSummary = computed(() => productData.value ? productSummary(productData.value as CatalogProduct) : null);
const selectedProductSignals = computed(() => productData.value ? productSignals(productData.value as CatalogProduct, selectedProduct.value) : []);
const bundleProducts = computed(() => bundleProductRows.value);
const selectedBundleProduct = computed(() => bundleProductRows.value.find((product) => Number(product.id) === selectedBundleProductId.value) || null);
const selectedBundleProductVariants = computed(() => Number(productData.value?.id || 0) === selectedBundleProductId.value ? selectedVariants.value : []);
const bundleComponentProducts = computed(() => {
  const query = bundleComponentSearch.value.trim().toLocaleLowerCase();
  return bundleComponentProductRows.value.filter((product) => Number(product.id) !== selectedBundleProductId.value && (!query || `${product.sku_base || ''} ${product.name || ''}`.toLocaleLowerCase().includes(query)));
});
const bundleStockEstimate = computed(() => selectedBundle.value?.stock_estimate || null);
const bundleLimitingFactor = computed(() => bundleStockEstimate.value?.limiting_factor as Record<string, unknown> | null | undefined);
const selectedBundleDiscounts = computed(() => {
  const productId = selectedBundleProductId.value;
  const variantId = Number(bundleForm.bundle_variant_id || 0);
  return discounts.value.filter((discount) => {
    if ((discount.status || 'active') !== 'active') return false;
    if (discount.scope_type === 'product') return Number(discount.scope_id || 0) === productId;
    if (discount.scope_type === 'variant') return variantId > 0 && Number(discount.scope_id || 0) === variantId;
    return false;
  });
});
const offerRows = computed<CatalogOfferRow[]>(() => {
  const bundleRows = bundleProductRows.value.map((product) => ({
    key: `bundle-${product.id}`,
    kind: 'bundle' as const,
    id: Number(product.id || 0),
    name: text(product.name),
    status: text(product.status || 'draft'),
    channels: productChannelKeys(product),
    detail: `${product.sku_base || '—'} · ${pricePairLabel(product)}`,
    bundle: product,
  }));
  const discountRows = discounts.value.map((discount) => ({
    key: `discount-${discount.id}`,
    kind: 'discount' as const,
    id: Number(discount.id || 0),
    name: text(discount.name),
    status: text(discount.status || 'active'),
    channels: [text(discount.channel || 'all')],
    detail: `${discount.discount_type === 'amount' ? `${discount.discount_value} ${discount.currency || 'CHF'}` : `${discount.discount_value}%`} · ${discount.scope_type} ${discountScopeLabel(discount)}`,
    discount,
  }));
  return [...bundleRows, ...discountRows].sort((a, b) => a.name.localeCompare(b.name));
});
const filteredOfferRows = computed(() => {
  const q = offerFilter.q.trim().toLowerCase();
  return offerRows.value.filter((row) => {
    if (appliedOfferFilters.type && row.kind !== appliedOfferFilters.type) return false;
    if (appliedOfferFilters.status && row.status !== appliedOfferFilters.status) return false;
    if (appliedOfferFilters.channel && !row.channels.includes(appliedOfferFilters.channel)) return false;
    if (!q) return true;
    return `${row.name} ${row.detail} ${row.status} ${row.channels.join(' ')}`.toLowerCase().includes(q);
  });
});
const sortedOfferRows = computed(() => {
  const rows = [...filteredOfferRows.value];
  rows.sort((a, b) => compareOfferValue(a, b, offerSort.key) * (offerSort.direction === 'asc' ? 1 : -1));
  return rows;
});
const offerTotal = computed(() => sortedOfferRows.value.length);
const offerNumericPageLimit = computed(() => offerPageSize.value === 'all' ? Math.max(1, offerTotal.value || 1) : offerPageSize.value);
const offerPageCount = computed(() => offerPageSize.value === 'all' ? 1 : Math.max(1, Math.ceil(offerTotal.value / offerNumericPageLimit.value)));
const paginatedOfferRows = computed(() => {
  if (offerPageSize.value === 'all') return sortedOfferRows.value;
  const start = (offerPage.value - 1) * offerNumericPageLimit.value;
  return sortedOfferRows.value.slice(start, start + offerNumericPageLimit.value);
});
const visibleOfferKeys = computed(() => paginatedOfferRows.value.map((row) => row.key));
const selectedOfferRows = computed(() => offerRows.value.filter((row) => selectedOfferKeys.value.includes(row.key)));
const selectedOffersCount = computed(() => selectedOfferKeys.value.length);
const allVisibleOffersSelected = computed(() => visibleOfferKeys.value.length > 0 && visibleOfferKeys.value.every((key) => selectedOfferKeys.value.includes(key)));
const someVisibleOffersSelected = computed(() => visibleOfferKeys.value.some((key) => selectedOfferKeys.value.includes(key)));
const canBulkOffersWrite = computed(() => selectedOfferRows.value.length > 0 && selectedOfferRows.value.every((row) => row.kind === 'bundle' ? canWrite.value : canDiscountWrite.value));
const bulkOfferChanges = computed<Record<string, unknown>>(() => {
  const changes: Record<string, unknown> = {};
  if (bulkOfferForm.status) changes.status = bulkOfferForm.status;
  if (bulkOfferForm.channel) {
    changes.channel = bulkOfferForm.channel;
    if (bulkOfferForm.channel === 'all') {
      changes.is_public = true;
      changes.is_ecommerce_enabled = true;
      changes.is_pos_enabled = true;
      changes.is_catalogue_enabled = true;
    } else if (bulkOfferForm.channel === 'public') {
      changes.is_public = true;
    } else if (bulkOfferForm.channel === 'ecommerce') {
      changes.is_ecommerce_enabled = true;
    } else if (bulkOfferForm.channel === 'pos') {
      changes.is_pos_enabled = true;
    } else if (bulkOfferForm.channel === 'catalogue') {
      changes.is_catalogue_enabled = true;
    }
  }
  if (bulkOfferForm.archive) changes.archive = true;
  return changes;
});
const bulkHasOfferChanges = computed(() => Object.keys(bulkOfferChanges.value).length > 0);
const offerPaginationLabel = computed(() => {
  if (offerTotal.value < 1) return '0 offre';
  const start = offerPageSize.value === 'all' ? 1 : ((offerPage.value - 1) * offerNumericPageLimit.value) + 1;
  const end = Math.min(start + paginatedOfferRows.value.length - 1, offerTotal.value);
  return `${start}-${end} / ${offerTotal.value}`;
});
const sortedProducts = computed(() => {
  const rows = [...products.value];
  rows.sort((a, b) => compareProductValue(a, b, productSort.key) * (productSort.direction === 'asc' ? 1 : -1));
  return rows;
});
const visibleProductIds = computed(() => sortedProducts.value.map((product) => Number(product.id || 0)).filter(Boolean));
const selectedProductsCount = computed(() => selectedProductIds.value.length);
const allVisibleProductsSelected = computed(() => visibleProductIds.value.length > 0 && visibleProductIds.value.every((id) => selectedProductIds.value.includes(id)));
const someVisibleProductsSelected = computed(() => visibleProductIds.value.some((id) => selectedProductIds.value.includes(id)));
const bulkProductChanges = computed<Record<string, unknown>>(() => {
  const changes: Record<string, unknown> = {};
  if (bulkProductForm.status) changes.status = bulkProductForm.status;
  if (bulkProductForm.brand_id !== '') changes.brand_id = idOrNull(bulkProductForm.brand_id);
  if (bulkProductForm.category_id !== '') changes.category_id = idOrNull(bulkProductForm.category_id);
  if (bulkProductForm.tax_class_id !== '') changes.tax_class_id = idOrNull(bulkProductForm.tax_class_id);
  if (bulkProductForm.is_public !== '') changes.is_public = bulkProductForm.is_public === '1';
  if (bulkProductForm.is_ecommerce_enabled !== '') changes.is_ecommerce_enabled = bulkProductForm.is_ecommerce_enabled === '1';
  if (bulkProductForm.is_pos_enabled !== '') changes.is_pos_enabled = bulkProductForm.is_pos_enabled === '1';
  if (bulkProductForm.is_catalogue_enabled !== '') changes.is_catalogue_enabled = bulkProductForm.is_catalogue_enabled === '1';
  if (bulkProductForm.archive) changes.archive = true;
  return changes;
});
const bulkHasProductChanges = computed(() => Object.keys(bulkProductChanges.value).length > 0);
const productPageLimit = computed(() => productPageSize.value === 'all' ? 'all' : productPageSize.value);
const productNumericPageLimit = computed(() => productPageSize.value === 'all' ? Math.max(1, productTotal.value || products.value.length || 1) : productPageSize.value);
const productTotal = computed(() => Number(productPagination.value.total || products.value.length));
const productPageCount = computed(() => productPageSize.value === 'all' ? 1 : Math.max(1, Math.ceil(productTotal.value / productNumericPageLimit.value)));
const productPaginationLabel = computed(() => {
  if (productTotal.value < 1) return '0 produit';
  const start = Number(productPagination.value.offset || 0) + 1;
  const end = Math.min(start + products.value.length - 1, productTotal.value);
  return `${start}-${end} / ${productTotal.value}`;
});
const galleryProductAssets = computed(() => productAssets.value.filter((asset) => ['gallery', 'thumbnail'].includes(String(asset.role || '')) && !asset.variant_id));
const variantProductAssets = computed(() => productAssets.value.filter((asset) => Boolean(asset.variant_id) || asset.role === 'variant'));
const documentProductAssets = computed(() => productAssets.value.filter((asset) => ['document', 'technical_sheet'].includes(String(asset.role || ''))));
const internalProductAssets = computed(() => productAssets.value.filter((asset) => asset.role === 'internal'));
const storefrontProductAssets = computed(() => productAssets.value
  .filter((asset) => !asset.variant_id && asset.is_public !== false && ['main','gallery','thumbnail'].includes(String(asset.role || '')) && ['all','public','ecommerce'].includes(String(asset.channel_scope || 'all')) && assetIsVisual(asset))
  .sort((left,right) => (left.role === 'main' ? 0 : 1)-(right.role === 'main' ? 0 : 1) || Number(left.sort_order || 0)-Number(right.sort_order || 0) || Number(left.id || 0)-Number(right.id || 0)));
const storefrontMainProductAsset = computed(() => storefrontProductAssets.value.find((asset)=>asset.role==='main')||null);
const mainProductAsset = computed(() => storefrontMainProductAsset.value || productAssets.value.find((asset) => asset.role === 'main' && !asset.variant_id) || null);
const assetRoleChoices = computed(() => {
  const choices=assetForm.variant_id?['variant','thumbnail']:['main','gallery','thumbnail','document','technical_sheet','internal'];
  return choices.includes(assetForm.role)?choices:[assetForm.role,...choices];
});
const assetUsageExplanation = computed(() => {
  if(assetForm.role==='internal')return 'Conservé dans l’administration et jamais envoyé à la boutique.';
  if(['document','technical_sheet'].includes(assetForm.role))return 'Affiché dans « Documents à télécharger » sur la fiche produit, séparément de la galerie.';
  if(assetForm.variant_id)return 'Affiché lorsque le visiteur choisit cette variante. À défaut, la variante reprend les images générales du produit.';
  if(assetForm.role==='main')return 'Première image des cartes catalogue et de la fiche produit.';
  return 'Ajouté à la galerie générale de la fiche produit.';
});
const selectedAssetMedia = computed(() => mediaRows.value.find((media)=>Number(media.id||0)===Number(assetForm.media_id||0))||null);
const assetNeedsVisualMedia = computed(() => ['main','gallery','variant','thumbnail'].includes(assetForm.role));
const assetSelectionValid = computed(() => Boolean(assetForm.media_id)&&(!assetNeedsVisualMedia.value||Boolean(selectedAssetMedia.value&&(text(selectedAssetMedia.value.media_type)==='image'||text(selectedAssetMedia.value.media_type)==='video'||text(selectedAssetMedia.value.mime_type).startsWith('image/')||text(selectedAssetMedia.value.mime_type).startsWith('video/')))));
const assetWarnings = computed(() => {
  const warnings: string[] = [];
  if (productData.value && !storefrontMainProductAsset.value) warnings.push('Aucune image principale visible dans la boutique.');
  for (const asset of productAssets.value) {
    if (asset.is_public && ['main', 'gallery', 'variant', 'thumbnail'].includes(String(asset.role || '')) && !text(asset.alt_text)) {
      warnings.push(`${assetRoleLabel(asset.role)} public sans texte alternatif.`);
    }
    if (asset.is_public && asset.role === 'internal') {
      warnings.push('Un fichier interne ne doit pas être publié.');
    }
    if (text(asset.expires_at) && new Date(text(asset.expires_at)).getTime() < Date.now()) {
      warnings.push(`${assetRoleLabel(asset.role)} expiré.`);
    }
    if (text(asset.usage_rights) && !text(asset.license)) {
      warnings.push(`${assetRoleLabel(asset.role)} sans licence.`);
    }
  }
  return Array.from(new Set(warnings));
});
const groupedAttributes = computed(() => {
  const selectedGroupIds = new Set(productForm.attribute_group_ids.map((id) => Number(id || 0)).filter(Boolean));
  const activeAttributes = selectedGroupIds.size > 0
    ? attributes.value.filter((attribute) => selectedGroupIds.has(Number(attribute.group_id || 0)))
    : [];
  const groups = attributeGroups.value.map((group) => ({
    group,
    attributes: activeAttributes.filter((attribute) => Number(attribute.group_id || 0) === Number(group.id || 0)),
  }));
  return groups.filter((group) => group.attributes.length > 0);
});
const selectableAttributes = computed(() => groupedAttributes.value.flatMap((group) => group.attributes));
const selectedOptionAttribute = computed(() => attributes.value.find((attribute) => Number(attribute.id || 0) === Number(attributeOptionForm.attribute_id || 0)) || null);
const selectedOptionAttributeOptions = computed(() => selectedOptionAttribute.value ? attributeOptions(selectedOptionAttribute.value) : []);
const attributeOptionUsesColor = computed(() => {
  const attribute = selectedOptionAttribute.value;
  if (!attribute) return false;
  const haystack = `${attribute.data_type || ''} ${attribute.name || ''} ${attribute.code || ''}`.toLowerCase();
  return haystack.includes('color') || haystack.includes('couleur');
});
const attributeDefinitionWarnings = computed(() => attributes.value
  .filter((attribute) => ['select', 'multi_select'].includes(String(attribute.data_type || '')) && attributeOptions(attribute).length === 0)
  .map((attribute) => `${attribute.name || attribute.code} attend au moins une option.`));
const attributeFormImpactWarnings = computed(() => {
  const warnings: string[] = [];
  if (!attributeForm.is_public && attributeForm.is_filterable) warnings.push('Un attribut filtrable doit être public.');
  if (!attributeForm.is_public && attributeForm.is_searchable) warnings.push('Un attribut recherchable doit être public.');
  return warnings;
});
const attributeSettingsTitle = computed(() => {
  if (attributeSettingsModal.value === 'groups') return 'Groupes d’attributs';
  if (attributeSettingsModal.value === 'options') return 'Options d’attributs';
  return 'Attributs produit';
});
const productAttributeWarnings = computed(() => attributeValueWarnings(productAttributeForm, selectableAttributes.value));
const variantAttributeWarnings = computed(() => selectedVariant.value ? attributeValueWarnings(variantAttributeForm, selectableAttributes.value) : []);
const referenceTitle = computed(() => {
  if (referenceKind.value === 'type') return 'Types de produits';
  if (referenceKind.value === 'status') return 'Statuts produits';
  if (referenceKind.value === 'brand') return 'Marques';
  if (referenceKind.value === 'tax') return 'TVA';
  return 'Catégories';
});
const activeProductFilterChips = computed<Array<{ key: ProductFilterKey; label: string }>>(() => {
  const chips: Array<{ key: ProductFilterKey; label: string }> = [];
  if (appliedProductFilters.view) chips.push({ key: 'view', label: `Vue · ${productViewLabels[appliedProductFilters.view] || appliedProductFilters.view}` });
  if (appliedProductFilters.type) chips.push({ key: 'type', label: `Type · ${productTypeLabels[appliedProductFilters.type] || appliedProductFilters.type}` });
  if (appliedProductFilters.status) chips.push({ key: 'status', label: `Statut · ${productStatusLabels[appliedProductFilters.status] || appliedProductFilters.status}` });
  if (appliedProductFilters.brand_id) chips.push({ key: 'brand_id', label: `Marque · ${brandName(appliedProductFilters.brand_id)}` });
  if (appliedProductFilters.category_id) chips.push({ key: 'category_id', label: `Catégorie · ${categoryName(appliedProductFilters.category_id)}` });
  if (appliedProductFilters.channel) chips.push({ key: 'channel', label: `Canal · ${productChannelLabels[appliedProductFilters.channel] || appliedProductFilters.channel}` });
  if (appliedProductFilters.image) chips.push({ key: 'image', label: `Image · ${productImageFilterLabels[appliedProductFilters.image] || appliedProductFilters.image}` });
  if (appliedProductFilters.price) chips.push({ key: 'price', label: `Prix · ${productPriceFilterLabels[appliedProductFilters.price] || appliedProductFilters.price}` });
  if (appliedProductFilters.archived) chips.push({ key: 'archived', label: 'Archivés inclus' });
  if (appliedProductFilters.low_stock) chips.push({ key: 'low_stock', label: 'Stock faible' });
  if (appliedProductFilters.purchase_price) chips.push({ key: 'purchase_price', label: 'Prix d’achat manquant' });
  return chips;
});
const activeOfferFilterChips = computed<Array<{ key: OfferFilterKey; label: string }>>(() => {
  const chips: Array<{ key: OfferFilterKey; label: string }> = [];
  if (appliedOfferFilters.type) chips.push({ key: 'type', label: `Type · ${offerKindLabel(appliedOfferFilters.type)}` });
  if (appliedOfferFilters.status) chips.push({ key: 'status', label: `Statut · ${productStatusLabel(appliedOfferFilters.status)}` });
  if (appliedOfferFilters.channel) chips.push({ key: 'channel', label: `Canal · ${offerChannelLabel(appliedOfferFilters.channel)}` });
  return chips;
});

function setNotice(message: string): void {
  success.value = message;
  error.value = '';
}

function setError(err: unknown, fallback = 'Action catalogue impossible.'): void {
  error.value = apiErrorMessage(err, fallback);
  success.value = '';
}

function labelStorageKey(kind: 'type' | 'status'): string {
  return kind === 'type' ? 'dec.business.catalog.productTypeLabels' : 'dec.business.catalog.productStatusLabels';
}

function loadReferenceLabels(): void {
  if (typeof window === 'undefined') return;
  for (const [kind, target] of [['type', productTypeLabels], ['status', productStatusLabels]] as const) {
    try {
      const stored = JSON.parse(window.localStorage.getItem(labelStorageKey(kind)) || '{}') as Record<string, unknown>;
      for (const key of Object.keys(target)) {
        if (typeof stored[key] === 'string' && stored[key].trim()) target[key] = stored[key].trim();
      }
    } catch {
      // Les libellés locaux sont facultatifs; un stockage invalide ne doit pas bloquer le catalogue.
    }
  }
}

function saveReferenceLabels(kind: 'type' | 'status'): void {
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(labelStorageKey(kind), JSON.stringify(kind === 'type' ? productTypeLabels : productStatusLabels));
  }
  setNotice('Libellés enregistrés.');
}

function idOrNull(value: IdValue): number | null {
  const number = Number(value || 0);
  return Number.isFinite(number) && number > 0 ? number : null;
}

function text(value: unknown): string {
  return value === null || value === undefined ? '' : String(value);
}

function eventValue(event: Event): string {
  return String((event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null)?.value ?? '');
}

function money(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  return String(value);
}

function optionalMoneyPayload(value: unknown): string | undefined {
  const normalized = text(value).trim().replace(',', '.');
  return normalized === '' ? undefined : normalized;
}

function numberValue(value: unknown): number {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function normalizeSkuInput(value: unknown): string {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '');
}

function priceLabel(value: unknown, currency = 'CHF'): string {
  if (value === null || value === undefined || value === '') return '—';
  const parsed = Number(value);
  return Number.isFinite(parsed) ? `${currency} ${parsed.toFixed(2)}` : String(value);
}

function tablePriceLabel(value: unknown, currency = 'CHF'): string {
  if (value === null || value === undefined || value === '') return '--';
  const parsed = Number(value);
  return Number.isFinite(parsed) ? `${currency} ${parsed.toFixed(2)}` : String(value);
}

function tableAmountLabel(value: unknown): string {
  if (value === null || value === undefined || value === '') return '--';
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed.toFixed(2) : String(value);
}

function pricePairLabel(product: CatalogProduct): string {
  const sale = tablePriceLabel(product.sale_price_min);
  const purchase = tableAmountLabel(product.purchase_price_min);
  return `${sale} / ${purchase}`;
}

function sortText(value: unknown): string {
  return text(value).toLocaleLowerCase('fr');
}

function productSortValue(product: CatalogProduct, key: ProductSortKey): string | number {
  if (key === 'sku') return sortText(product.sku_base);
  if (key === 'brand') return sortText(`${brandName(product.brand_id)} ${categoryName(product.category_id)}`);
  if (key === 'variants') return numberValue(product.active_variant_count);
  if (key === 'stock') return Math.max(0, numberValue(product.stock_quantity_total) - numberValue(product.stock_reserved_total));
  if (key === 'price') return numberValue(product.sale_price_min);
  if (key === 'image') return numberValue(product.image_count);
  if (key === 'channels') return channelReadinessSignals(product).filter((signal) => signal.tone === 'success').length;
  if (key === 'status') return statuses.indexOf(text(product.status || 'draft'));
  if (key === 'quality') return numberValue(product.completeness_score);
  return sortText(product.name);
}

function compareProductValue(a: CatalogProduct, b: CatalogProduct, key: ProductSortKey): number {
  const left = productSortValue(a, key);
  const right = productSortValue(b, key);
  if (typeof left === 'number' && typeof right === 'number') return left - right || Number(a.id || 0) - Number(b.id || 0);
  return String(left).localeCompare(String(right), 'fr', { numeric: true, sensitivity: 'base' }) || Number(a.id || 0) - Number(b.id || 0);
}

function setProductSort(key: ProductSortKey): void {
  if (productSort.key === key) productSort.direction = productSort.direction === 'asc' ? 'desc' : 'asc';
  else {
    productSort.key = key;
    productSort.direction = 'asc';
  }
}

function productSortLabel(key: ProductSortKey): string {
  if (productSort.key !== key) return 'Trier';
  return productSort.direction === 'asc' ? 'Tri croissant' : 'Tri décroissant';
}

function offerSortValue(row: CatalogOfferRow, key: OfferSortKey): string | number {
  if (key === 'type') return row.kind === 'bundle' ? 0 : 1;
  if (key === 'status') return statuses.indexOf(text(row.status || 'draft'));
  if (key === 'channels') return sortText(row.channels.map((channel) => offerChannelLabel(channel)).join(' '));
  return sortText(row.name);
}

function compareOfferValue(a: CatalogOfferRow, b: CatalogOfferRow, key: OfferSortKey): number {
  const left = offerSortValue(a, key);
  const right = offerSortValue(b, key);
  if (typeof left === 'number' && typeof right === 'number') return left - right || a.id - b.id;
  return String(left).localeCompare(String(right), 'fr', { numeric: true, sensitivity: 'base' }) || a.id - b.id;
}

function setOfferSort(key: OfferSortKey): void {
  if (offerSort.key === key) offerSort.direction = offerSort.direction === 'asc' ? 'desc' : 'asc';
  else {
    offerSort.key = key;
    offerSort.direction = 'asc';
  }
}

function offerSortLabel(key: OfferSortKey): string {
  if (offerSort.key !== key) return 'Trier';
  return offerSort.direction === 'asc' ? 'Tri croissant' : 'Tri décroissant';
}

function productPageSizeLabel(size: ProductPageSize): string {
  return size === 'all' ? 'Tous' : String(size);
}

function offerPageSizeLabel(size: OfferPageSize): string {
  return size === 'all' ? 'Tous' : String(size);
}

function setProductPageSize(size: ProductPageSize): void {
  productPageSize.value = size;
  productPage.value = 1;
  void loadCatalog();
}

function setOfferPageSize(size: OfferPageSize): void {
  offerPageSize.value = size;
  offerPage.value = 1;
}

function onProductPageSizeChange(event: Event): void {
  const value = eventValue(event);
  setProductPageSize(value === 'all' ? 'all' : (Number(value) as ProductPageSize));
}

function onOfferPageSizeChange(event: Event): void {
  const value = eventValue(event);
  setOfferPageSize(value === 'all' ? 'all' : (Number(value) as OfferPageSize));
}

function setProductPage(page: number): void {
  const next = Math.max(1, Math.min(productPageCount.value, page));
  if (next === productPage.value) return;
  productPage.value = next;
  void loadCatalog();
}

function setOfferPage(page: number): void {
  offerPage.value = Math.max(1, Math.min(offerPageCount.value, page));
}

function productHasAnyPrice(product: CatalogProduct): boolean {
  return hasProductSalePrice(product) || (product.purchase_price_min !== null && product.purchase_price_min !== undefined && product.purchase_price_min !== '');
}

function productIsArchived(product: CatalogProduct): boolean {
  return product.status === 'archived' || Boolean(product.archived_at);
}

function productStatusLabel(status: unknown): string {
  const key = text(status || 'draft');
  return productStatusLabels[key] || key;
}

function productDetailId(detail: ProductDetail | CatalogProduct | null | undefined): number {
  const value = detail as Record<string, unknown> | null | undefined;
  const nested = value?.data as Record<string, unknown> | undefined;
  return Number(nested?.id || value?.id || 0);
}

function optionValues(option: Record<string, unknown>): Array<Record<string, unknown>> {
  return Array.isArray(option.values) ? option.values as Array<Record<string, unknown>> : [];
}

function brandName(id: IdValue): string {
  return brands.value.find((item) => item.id === Number(id || 0))?.name || (id ? `Marque #${id}` : '—');
}

function categoryName(id: IdValue): string {
  return categories.value.find((item) => item.id === Number(id || 0))?.name || (id ? `Catégorie #${id}` : '—');
}

function taxClassLabel(id: IdValue): string {
  const taxClass = taxClasses.value.find((item) => item.id === Number(id || 0));
  if (!taxClass) return id ? `TVA #${id}` : 'Aucune TVA';
  const rate = Number(taxClass.rate ?? 0);
  const rateLabel = Number.isFinite(rate) ? `${rate.toFixed(rate % 1 === 0 ? 0 : 1)}%` : '';
  return [taxClass.name || taxClass.code || 'TVA', rateLabel].filter(Boolean).join(' · ');
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

function assetRoleLabel(role: unknown): string {
  const key = text(role);
  return assetRoleLabels[key] || key || 'Média';
}

function assetChannelLabel(channel: unknown): string {
  const key = text(channel || 'all');
  return assetChannelLabels[key] || key || 'Tous';
}

function assetMediaLabel(mediaId: IdValue): string {
  const id = Number(mediaId || 0);
  const media = mediaRows.value.find((item) => Number(item.id || 0) === id);
  return text(media?.title || media?.original_filename || media?.filename || media?.name) || (id ? `Média ${id}` : 'Média');
}

function assetPreviewUrl(asset: CatalogProductAsset): string {
  const media = mediaRows.value.find((item) => Number(item.id || 0) === Number(asset.media_id || 0));
  return text(media?.thumbnail_url || media?.public_url || '');
}

function assetIsVisual(asset: CatalogProductAsset): boolean {
  const media = mediaRows.value.find((item) => Number(item.id || 0) === Number(asset.media_id || 0));
  const type=text(media?.media_type);const mime=text(media?.mime_type);
  return type==='image'||type==='video'||mime.startsWith('image/')||mime.startsWith('video/');
}

function assetIsImage(asset: CatalogProductAsset): boolean {
  const media = mediaRows.value.find((item) => Number(item.id || 0) === Number(asset.media_id || 0));
  return text(media?.mime_type).startsWith('image/');
}

function assetVariantLabel(asset: CatalogProductAsset): string {
  return asset.variant_id ? variantName(asset.variant_id) : 'Produit';
}

function normalizeMediaResponse(payload: unknown): CatalogRecord[] {
  if (Array.isArray(payload)) return payload as CatalogRecord[];
  const data = payload as Record<string, unknown>;
  for (const key of ['assets', 'media', 'items', 'rows']) {
    if (Array.isArray(data?.[key])) return data[key] as CatalogRecord[];
  }
  return [];
}

function attributeTypeLabel(type: unknown): string {
  const key = text(type || 'text');
  return attributeTypeLabels[key] || key;
}

function isChoiceAttribute(attribute: CatalogAttribute): boolean {
  return ['select', 'multi_select', 'color'].includes(String(attribute.data_type || ''));
}

function attributeInputType(attribute: CatalogAttribute): string {
  const type = text(attribute.data_type || 'text');
  if (['number', 'decimal', 'dimension', 'weight'].includes(type)) return 'number';
  if (type === 'date') return 'date';
  if (type === 'url') return 'url';
  if (type === 'color') return 'color';
  return 'text';
}

function attributeValue(attributeId: IdValue, scope: 'product' | 'variant'): string {
  const source = scope === 'variant' ? variantAttributeForm : productAttributeForm;
  return source[Number(attributeId || 0)] ?? '';
}

function setAttributeValue(attributeId: IdValue, scope: 'product' | 'variant', value: string): void {
  const source = scope === 'variant' ? variantAttributeForm : productAttributeForm;
  source[Number(attributeId || 0)] = value;
}

function attributeValueParts(attributeId: IdValue, scope: 'product' | 'variant'): string[] {
  return attributeValue(attributeId, scope).split(',').map((item) => item.trim()).filter(Boolean);
}

function attributeOptionSelected(attribute: CatalogAttribute, scope: 'product' | 'variant', option: CatalogRecord): boolean {
  return attributeValueParts(attribute.id, scope).includes(attributeOptionValue(option));
}

function toggleAttributeOption(attribute: CatalogAttribute, scope: 'product' | 'variant', option: CatalogRecord): void {
  const value = attributeOptionValue(option);
  if (!value) return;
  if (attribute.data_type === 'multi_select') {
    const values = new Set(attributeValueParts(attribute.id, scope));
    if (values.has(value)) values.delete(value);
    else values.add(value);
    setAttributeValue(attribute.id, scope, Array.from(values).join(', '));
    return;
  }
  setAttributeValue(attribute.id, scope, attributeOptionSelected(attribute, scope, option) ? '' : value);
}

function attributeOptions(attribute: CatalogAttribute): CatalogRecord[] {
  return Array.isArray(attribute.options) ? attribute.options : [];
}

function attributeOptionKey(option: CatalogRecord): string | number {
  return Number(option.id || 0) > 0 ? Number(option.id) : text(option.code || option.value || option.label || option.name);
}

function attributeOptionValue(option: CatalogRecord): string {
  return text(option.value || option.code || option.label || option.name);
}

function attributeOptionLabel(option: CatalogRecord): string {
  return text(option.label || option.name || option.value || option.code) || 'Option';
}

function attributeValueWarnings(values: Record<number, string>, sourceAttributes: CatalogAttribute[]): string[] {
  const warnings: string[] = [];
  for (const attribute of sourceAttributes) {
    const value = text(values[Number(attribute.id || 0)]).trim();
    if (attribute.is_required && value === '') warnings.push(`${attribute.name || attribute.code} requis manquant.`);
    if (attribute.is_public && value === '') warnings.push(`${attribute.name || attribute.code} public sans valeur.`);
  }
  return warnings;
}

function valuePayloadForAttribute(attribute: CatalogAttribute, value: string): Record<string, unknown> {
  const dataType = text(attribute.data_type || 'text');
  const payload: Record<string, unknown> = { attribute_id: Number(attribute.id), language: 'und' };
  if (value.trim() === '') return payload;
  if (['number', 'decimal', 'dimension', 'weight'].includes(dataType)) payload.value_number = Number(value);
  else if (dataType === 'boolean') payload.value_text = ['1', 'true', 'yes', 'oui', 'on'].includes(value.toLowerCase()) ? '1' : '0';
  else if (dataType === 'multi_select') payload.value_json = value.split(',').map((item) => item.trim()).filter(Boolean);
  else if (dataType === 'json') {
    try {
      payload.value_json = JSON.parse(value);
    } catch {
      payload.value_text = value;
    }
  } else {
    payload.value_text = value;
  }
  return payload;
}

function valuesPayloadFromForm(values: Record<number, string>): Array<Record<string, unknown>> {
  return selectableAttributes.value
    .map((attribute) => valuePayloadForAttribute(attribute, values[Number(attribute.id || 0)] ?? ''))
    .filter((value) => Object.keys(value).some((key) => key.startsWith('value_')));
}

function applyAttributeValues(scope: 'product' | 'variant', rows: CatalogAttributeValue[]): void {
  const target = scope === 'variant' ? variantAttributeForm : productAttributeForm;
  for (const key of Object.keys(target)) delete target[Number(key)];
  for (const row of rows) {
    const attributeId = Number(row.attribute_id || 0);
    if (attributeId > 0) target[attributeId] = Array.isArray(row.value) ? row.value.join(', ') : text(row.value);
  }
}

function compactAttributeValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '';
  if (Array.isArray(value)) return value.map(compactAttributeValue).filter(Boolean).join('·');
  return text(value).trim().toUpperCase();
}

function compactAttributeSummary(rows: CatalogAttributeValue[]): string {
  return rows
    .map((row) => compactAttributeValue(row.value))
    .filter(Boolean)
    .join('·');
}

function mergedVariantAttributeRows(variant: Partial<CatalogVariant> | null | undefined): CatalogAttributeValue[] {
  const variantId = Number(variant?.id || 0);
  const merged = new Map<string, CatalogAttributeValue>();
  for (const row of productAttributeValues.value) {
    const key = text(row.attribute_code || row.attribute_id);
    if (key) merged.set(key, row);
  }
  for (const row of variantAttributeValuesById.value[variantId] || []) {
    const key = text(row.attribute_code || row.attribute_id);
    if (key) merged.set(key, row);
  }
  return Array.from(merged.values());
}

function variantCompactAttributeSummary(variant: Partial<CatalogVariant> | null | undefined): string {
  return compactAttributeSummary(mergedVariantAttributeRows(variant));
}

function pruneAttributeForms(): void {
  const allowed = new Set(selectableAttributes.value.map((attribute) => Number(attribute.id || 0)).filter(Boolean));
  for (const key of Object.keys(productAttributeForm)) {
    if (!allowed.has(Number(key))) delete productAttributeForm[Number(key)];
  }
  for (const key of Object.keys(variantAttributeForm)) {
    if (!allowed.has(Number(key))) delete variantAttributeForm[Number(key)];
  }
}

function channelsFor(product: CatalogProduct): string[] {
  return [
    product.is_public ? 'public' : '',
    product.is_ecommerce_enabled ? 'e-commerce' : '',
    product.is_pos_enabled ? 'POS' : '',
    product.is_catalogue_enabled ? 'catalogue' : '',
  ].filter(Boolean);
}

function productChannelKeys(product: CatalogProduct): string[] {
  return [
    product.is_public ? 'public' : '',
    product.is_ecommerce_enabled ? 'ecommerce' : '',
    product.is_pos_enabled ? 'pos' : '',
    product.is_catalogue_enabled ? 'catalogue' : '',
  ].filter(Boolean);
}

function offerChannelLabel(channel: string): string {
  if (channel === 'all') return 'Tous';
  return productChannelLabels[channel] || channel;
}

function offerKindLabel(kind: string): string {
  if (kind === 'bundle') return 'Bundle';
  if (kind === 'discount') return 'Réduction';
  return kind;
}

function productSummary(product: CatalogProduct): Record<string, unknown> {
  const variantCount = numberValue(product.variant_count);
  const activeVariantCount = numberValue(product.active_variant_count);
  const imageCount = numberValue(product.image_count);
  const salePrice = product.sale_price_min ?? selectedPrices.value.find((price) => price.price_kind === 'sale')?.amount ?? null;
  const detailMedia = Array.isArray(selectedProduct.value?.media) ? selectedProduct.value.media.length : 0;
  const detailVariants = selectedVariants.value.length;
  return {
    variant_count: variantCount || detailVariants,
    active_variant_count: activeVariantCount || selectedVariants.value.filter((variant) => variant.status === 'active').length,
    sale_price_min: salePrice,
    image_count: imageCount || detailMedia,
    completeness_score: product.completeness_score ?? null,
    is_sellable_summary: product.is_sellable_summary ?? null,
  };
}

function productSignals(product: CatalogProduct, detail: ProductDetail | null = null): ProductSignal[] {
  const summary = detail ? productSummary(product) : {
    variant_count: numberValue(product.variant_count),
    active_variant_count: numberValue(product.active_variant_count),
    sale_price_min: product.sale_price_min ?? null,
    image_count: numberValue(product.image_count),
    completeness_score: product.completeness_score ?? null,
    is_sellable_summary: product.is_sellable_summary ?? null,
  };
  const activeVariants = numberValue(summary.active_variant_count);
  const totalVariants = numberValue(summary.variant_count);
  const hasSalePrice = summary.sale_price_min !== null && summary.sale_price_min !== undefined && summary.sale_price_min !== '';
  const hasImage = numberValue(summary.image_count) > 0;
  const signals: ProductSignal[] = [];

  signals.push({ label: 'POS', tone: product.is_pos_enabled && product.status === 'active' && hasSalePrice && activeVariants > 0 ? 'success' : 'danger' });
  signals.push({ label: 'E-commerce', tone: product.is_ecommerce_enabled && product.is_public && product.status === 'active' && hasSalePrice && hasImage && activeVariants > 0 ? 'success' : 'danger' });
  signals.push({ label: 'Brochure', tone: product.is_catalogue_enabled && product.status === 'active' && hasSalePrice && activeVariants > 0 ? 'success' : 'muted' });
  if (!hasImage) signals.push({ label: 'Image manquante', tone: 'warning' });
  if (!hasSalePrice) signals.push({ label: 'Prix manquant', tone: 'danger' });
  if (!product.tax_class_id) signals.push({ label: 'TVA manquante', tone: 'warning' });
  if (summary.is_sellable_summary === false) signals.push({ label: 'À compléter', tone: 'warning' });

  return signals.slice(0, 6);
}

function hasProductImage(product: CatalogProduct): boolean {
  return numberValue(product.image_count) > 0;
}

function hasProductSalePrice(product: CatalogProduct): boolean {
  return product.sale_price_min !== null && product.sale_price_min !== undefined && product.sale_price_min !== '';
}

function mediaCountLabel(product: CatalogProduct): string {
  const count = numberValue(product.image_count);
  return count > 1 ? `${count} médias` : `${count} média`;
}

function channelReady(product: CatalogProduct, channel: 'pos' | 'ecommerce' | 'catalogue'): boolean {
  const activeVariants = numberValue(product.active_variant_count);
  const hasSalePrice = hasProductSalePrice(product);
  if (channel === 'pos') {
    return Boolean(product.is_pos_enabled) && product.status === 'active' && hasSalePrice && activeVariants > 0;
  }
  if (channel === 'catalogue') {
    return Boolean(product.is_catalogue_enabled) && product.status === 'active' && hasSalePrice && activeVariants > 0;
  }
  return Boolean(product.is_ecommerce_enabled)
    && Boolean(product.is_public)
    && product.status === 'active'
    && hasSalePrice
    && hasProductImage(product)
    && activeVariants > 0;
}

function channelReadinessSignals(product: CatalogProduct): ProductSignal[] {
  return [
    { label: 'Public', tone: product.is_public ? 'success' : 'muted' },
    { label: 'POS', tone: product.is_pos_enabled ? (channelReady(product, 'pos') ? 'success' : 'danger') : 'muted' },
    { label: 'E-commerce', tone: product.is_ecommerce_enabled ? (channelReady(product, 'ecommerce') ? 'success' : 'danger') : 'muted' },
    { label: 'Brochure', tone: product.is_catalogue_enabled ? (channelReady(product, 'catalogue') ? 'success' : 'warning') : 'muted' },
  ];
}

function productStockTracked(product: CatalogProduct): boolean {
  const trackedVariants = numberValue(product.stock_tracked_variant_count);
  const detailTracked = Number(product.id || 0) === selectedProductId.value
    ? selectedVariants.value.filter((variant) => variant.track_stock === true || (variant.track_stock === null && product.track_stock)).length
    : 0;
  return Boolean(product.track_stock) || trackedVariants > 0 || detailTracked > 0;
}

function productStockAvailable(product: CatalogProduct): number {
  let quantity = numberValue(product.stock_quantity_total);
  let reserved = numberValue(product.stock_reserved_total);
  if (Number(product.id || 0) === selectedProductId.value && selectedVariants.value.length > 0 && quantity === 0 && reserved === 0) {
    quantity = selectedVariants.value.reduce((total, variant) => total + numberValue(variant.stock_quantity), 0);
    reserved = selectedVariants.value.reduce((total, variant) => total + numberValue(variant.stock_reserved), 0);
  }
  return Math.max(0, quantity - reserved);
}

function stockIndicatorTone(product: CatalogProduct): 'success' | 'warning' | 'danger' | 'muted' {
  if (!productStockTracked(product)) return 'muted';
  if (productStockAvailable(product) <= 0) return 'danger';
  if (numberValue(product.low_stock_variant_count) > 0) return 'warning';
  return 'success';
}

function stockIndicatorLabel(product: CatalogProduct): string {
  const tone = stockIndicatorTone(product);
  if (tone === 'muted') return 'Stock non suivi';
  if (tone === 'danger') return 'Stock nul';
  if (tone === 'warning') return 'Niveau faible';
  return 'Stock suivi';
}

function stockSummaryLabel(product: CatalogProduct): string {
  if (!productStockTracked(product)) return '--';
  const available = productStockAvailable(product);
  return `${available} disponible${available > 1 ? 's' : ''}`;
}

function variantStockTracked(product: CatalogProduct, variant: CatalogVariant): boolean {
  return variant.track_stock === true || ((variant.track_stock === null || variant.track_stock === undefined) && Boolean(product.track_stock));
}

function variantStockAvailable(variant: CatalogVariant): number {
  return Math.max(0, numberValue(variant.stock_quantity) - numberValue(variant.stock_reserved));
}

function variantStockTone(product: CatalogProduct, variant: CatalogVariant): 'success' | 'warning' | 'danger' | 'muted' {
  if (!variantStockTracked(product, variant)) return 'muted';
  const available = variantStockAvailable(variant);
  if (available <= 0) return 'danger';
  if (available <= 5) return 'warning';
  return 'success';
}

function variantStockLabel(product: CatalogProduct, variant: CatalogVariant): string {
  if (!variantStockTracked(product, variant)) return '--';
  const available = variantStockAvailable(variant);
  return `${available} disponible${available > 1 ? 's' : ''}`;
}

function qualitySignals(product: CatalogProduct): ProductSignal[] {
  return productSignals(product).filter((signal) => ['TVA manquante', 'À compléter'].includes(signal.label));
}

function completenessLabel(product: CatalogProduct): string {
  return product.completeness_score === null || product.completeness_score === undefined
    ? 'Non calculée'
    : `${Number(product.completeness_score)}%`;
}

function variantActivityLabel(product: CatalogProduct): string {
  const active = numberValue(product.active_variant_count);
  const total = numberValue(product.variant_count);
  const adjective = active > 1 ? 'actives' : 'active';
  return `${active} / ${total} ${adjective}`;
}

function productVariantMenuRows(product: CatalogProduct): CatalogVariant[] {
  return Number(product.id || 0) === selectedProductId.value ? selectedVariants.value : [];
}

function variantDisplayName(variant: Partial<CatalogVariant> | null | undefined): string {
  return text(variant?.name || variant?.sku || 'Variante');
}

function variantIsArchived(variant: Partial<CatalogVariant> | null | undefined): boolean {
  return text(variant?.status) === 'archived' || Boolean(variant?.archived_at);
}

function variantIsInactive(variant: Partial<CatalogVariant> | null | undefined): boolean {
  return !variantIsArchived(variant) && text(variant?.status || 'active') !== 'active';
}

function setProductSection(section: ProductSection): void {
  activeProductSection.value = section;
}

function productModalTitle(): string {
  if (productModalMode.value === 'create') return 'Nouveau produit';
  if (productModalScope.value === 'attributes' && variantAttributesOnlyModal.value) return 'Modifier les attributs de la variante';
  if (productModalScope.value === 'identity' && productModalFocus.value === 'sku') return 'Modifier le SKU';
  if (productModalScope.value === 'identity' && productModalFocus.value === 'name_type') return 'Modifier le nom et le type';
  if (productModalScope.value === 'identity') return 'Modifier l’identité';
  if (productModalScope.value === 'classification') return 'Modifier le classement';
  if (productModalScope.value === 'stock') return 'Modifier le stock';
  if (productModalScope.value === 'prices') return 'Modifier les prix';
  if (productModalScope.value === 'tax') return 'Modifier le taux TVA';
  if (productModalScope.value === 'media') return 'Modifier les médias';
  if (productModalScope.value === 'attributes') return 'Modifier les attributs';
  if (productModalScope.value === 'channels') return 'Modifier les canaux';
  return 'Modifier le produit';
}

function productModalShows(scope: ProductModalScope): boolean {
  if (scope === 'media' || scope === 'attributes') {
    return productModalScope.value === scope;
  }
  return productModalMode.value === 'create' || productModalScope.value === 'all' || productModalScope.value === scope;
}

async function selectProductSection(product: CatalogProduct, section: ProductSection): Promise<void> {
  closeCatalogMenus();
  await selectProduct(product);
  setProductSection(section);
}

async function openViewProduct(product: CatalogProduct): Promise<void> {
  closeCatalogMenus();
  await selectProduct(product);
  productViewModalOpen.value = true;
}

function productColumnVisible(key: ProductColumnKey): boolean {
  return visibleProductColumns[key] === true;
}

function resetProductForm(preserveSelection = false): void {
  Object.assign(productForm, {
    id: 0,
    sku_base: '',
    name: '',
    slug: '',
    type: 'physical',
    status: 'draft',
    brand_id: '',
    category_id: '',
    unit: 'unit',
    tax_class_id: '',
    track_stock: false,
    allow_backorder: true,
    backorder_delivery_days: '7',
    short_description: '',
    description: '',
    is_public: false,
    is_ecommerce_enabled: true,
    is_pos_enabled: false,
    is_catalogue_enabled: true,
    base_purchase_price: '',
    base_sale_price: '',
    currency: 'CHF',
    attribute_group_ids: [],
    option_ids: [],
  });
  if (!preserveSelection) {
    selectedProduct.value = null;
    selectedVariant.value = null;
    productAssets.value = [];
    productContentLinks.value = [];
    productRelations.value = [];
    productRelationRules.value = [];
    contentCandidates.value = [];
    productAttributeValues.value = [];
    variantAttributeValues.value = [];
    variantStock.value = null;
    stockMovements.value = [];
    activeProductSection.value = 'summary';
  }
}

function fillProductForm(detail: ProductDetail): void {
  const data = (detail.data || {}) as Partial<CatalogProduct>;
  Object.assign(productForm, {
    id: Number(data.id || 0),
    sku_base: text(data.sku_base),
    name: text(data.name),
    slug: text(data.slug),
    type: text(data.type || 'physical'),
    status: text(data.status || 'draft'),
    brand_id: text(data.brand_id || ''),
    category_id: text(data.category_id || ''),
    unit: text(data.unit || 'unit'),
    tax_class_id: text(data.tax_class_id || ''),
    track_stock: Boolean(data.track_stock),
    allow_backorder: data.allow_backorder !== false,
    backorder_delivery_days: text(data.backorder_delivery_days ?? 7),
    short_description: text(data.short_description),
    description: text(data.description),
    is_public: Boolean(data.is_public),
    is_ecommerce_enabled: Boolean(data.is_ecommerce_enabled),
    is_pos_enabled: Boolean(data.is_pos_enabled),
    is_catalogue_enabled: data.is_catalogue_enabled !== false,
    currency: 'CHF',
    attribute_group_ids: Array.isArray(detail.attribute_groups) ? detail.attribute_groups.map((group) => Number(group.id || 0)).filter(Boolean) : [],
    option_ids: Array.isArray(detail.options) ? detail.options.map((option) => Number(option.id || 0)).filter(Boolean) : [],
  });
  const sale = selectedPrices.value.find((price) => price.price_kind === 'sale');
  const purchase = selectedPrices.value.find((price) => price.price_kind === 'purchase');
  productForm.base_sale_price = text(sale?.amount || '');
  productForm.base_purchase_price = text(purchase?.amount || '');
  productForm.currency = text(sale?.currency || purchase?.currency || 'CHF');
}

function productPayload(): Record<string, unknown> {
  productForm.sku_base = normalizeSkuInput(productForm.sku_base);
  const channelList = [
    productForm.is_public ? 'public' : '',
    productForm.is_ecommerce_enabled ? 'ecommerce' : '',
    productForm.is_pos_enabled ? 'pos' : '',
    productForm.is_catalogue_enabled ? 'catalogue' : '',
  ].filter(Boolean);
  return {
    name: productForm.name,
    sku_base: productForm.sku_base || undefined,
    slug: productForm.slug || productForm.name,
    type: productForm.type,
    status: productForm.status,
    brand_id: idOrNull(productForm.brand_id),
    category_id: idOrNull(productForm.category_id),
    unit: productForm.unit || 'unit',
    tax_class_id: idOrNull(productForm.tax_class_id),
    track_stock: productForm.track_stock,
    allow_backorder: productForm.allow_backorder,
    backorder_delivery_days: Number(productForm.backorder_delivery_days || 0),
    short_description: productForm.short_description,
    description: productForm.description,
    channels: channelList.length > 0 ? channelList : ['internal'],
    is_public: productForm.is_public,
    is_ecommerce_enabled: productForm.is_ecommerce_enabled,
    is_pos_enabled: productForm.is_pos_enabled,
    is_catalogue_enabled: productForm.is_catalogue_enabled,
    attribute_group_ids: productForm.attribute_group_ids,
    option_ids: productForm.option_ids,
    base_purchase_price: optionalMoneyPayload(productForm.base_purchase_price),
    base_sale_price: optionalMoneyPayload(productForm.base_sale_price),
    currency: productForm.currency || 'CHF',
  };
}

function productSelected(product: CatalogProduct): boolean {
  return selectedProductIds.value.includes(Number(product.id || 0));
}

function toggleProductSelection(product: CatalogProduct, checked: boolean): void {
  const id = Number(product.id || 0);
  if (id < 1) return;
  const set = new Set(selectedProductIds.value);
  if (checked) set.add(id);
  else set.delete(id);
  selectedProductIds.value = Array.from(set);
}

function toggleVisibleProductSelection(checked: boolean): void {
  const set = new Set(selectedProductIds.value);
  for (const id of visibleProductIds.value) {
    if (checked) set.add(id);
    else set.delete(id);
  }
  selectedProductIds.value = Array.from(set);
}

function checkedFromEvent(event: Event): boolean {
  return Boolean((event.target as HTMLInputElement | null)?.checked);
}

function clearBulkSelection(): void {
  selectedProductIds.value = [];
  bulkReport.value = null;
}

function offerSelected(row: CatalogOfferRow): boolean {
  return selectedOfferKeys.value.includes(row.key);
}

function toggleOfferSelection(row: CatalogOfferRow, checked: boolean): void {
  const set = new Set(selectedOfferKeys.value);
  if (checked) set.add(row.key);
  else set.delete(row.key);
  selectedOfferKeys.value = Array.from(set);
}

function toggleVisibleOfferSelection(checked: boolean): void {
  const set = new Set(selectedOfferKeys.value);
  for (const key of visibleOfferKeys.value) {
    if (checked) set.add(key);
    else set.delete(key);
  }
  selectedOfferKeys.value = Array.from(set);
}

function clearOfferBulkSelection(): void {
  selectedOfferKeys.value = [];
  offerBulkReport.value = null;
}

function resetBulkProductForm(): void {
  Object.assign(bulkProductForm, {
    status: '',
    brand_id: '',
    category_id: '',
    tax_class_id: '',
    is_public: '',
    is_ecommerce_enabled: '',
    is_pos_enabled: '',
    is_catalogue_enabled: '',
    archive: false,
  });
  bulkReport.value = null;
}

function resetBulkOfferForm(): void {
  Object.assign(bulkOfferForm, {
    status: '',
    channel: '',
    archive: false,
  });
  offerBulkReport.value = null;
}

function applyProductFilters(): void {
  productPage.value = 1;
  Object.assign(appliedProductFilters, {
    view: productFilter.view,
    type: productFilter.type,
    brand_id: productFilter.brand_id,
    category_id: productFilter.category_id,
    status: productFilter.status,
    channel: productFilter.channel,
    archived: productFilter.archived,
    low_stock: productFilter.low_stock,
    image: productFilter.image,
    price: productFilter.price,
    purchase_price: productFilter.purchase_price,
  });
  closeCatalogMenus();
  void loadCatalog();
}

function resetQuickProductFilters(): void {
  productFilter.view = '';
  productFilter.type = '';
  productFilter.brand_id = '';
  productFilter.category_id = '';
  productFilter.status = '';
  productFilter.channel = '';
  productFilter.archived = '1';
  productFilter.low_stock = false;
  productFilter.image = '';
  productFilter.price = '';
  productFilter.purchase_price = false;
}

function clearAppliedProductFilters(): void {
  Object.assign(appliedProductFilters, {
    view: '',
    type: '',
    brand_id: '',
    category_id: '',
    status: '',
    channel: '',
    archived: '',
    low_stock: false,
    image: '',
    price: '',
    purchase_price: false,
  });
}

function resetProductFilters(): void {
  productPage.value = 1;
  productFilter.q = '';
  resetQuickProductFilters();
  clearAppliedProductFilters();
  closeCatalogMenus();
  void loadCatalog();
}

function quickProductFilterActive(key: string): boolean {
  if (key === 'all') return !productFilter.view && !productFilter.status && !productFilter.channel && productFilter.archived === '1' && !productFilter.low_stock;
  if (['to_complete', 'ready_pos', 'ready_ecommerce', 'ready_catalogue', 'without_image', 'without_price', 'low_stock'].includes(key)) return productFilter.view === key;
  if (key === 'active' || key === 'draft') return productFilter.status === key && !productFilter.view && !productFilter.channel && !productFilter.archived && !productFilter.low_stock;
  if (key === 'archived') return productFilter.archived === '1' && productFilter.status === 'archived';
  if (key === 'ecommerce' || key === 'pos' || key === 'catalogue') return !productFilter.view && !productFilter.status && productFilter.channel === key && !productFilter.low_stock;
  return false;
}

function applyQuickProductFilter(key: string): void {
  productPage.value = 1;
  resetQuickProductFilters();
  if (['to_complete', 'ready_pos', 'ready_ecommerce', 'ready_catalogue', 'without_image', 'without_price', 'low_stock'].includes(key)) {
    productFilter.view = key;
  }
  else if (key === 'active' || key === 'draft') {
    productFilter.status = key;
    productFilter.archived = '';
  }
  else if (key === 'archived') {
    productFilter.status = 'archived';
    productFilter.archived = '1';
  }
  else if (key === 'ecommerce' || key === 'pos' || key === 'catalogue') productFilter.channel = key;
  clearAppliedProductFilters();
  void loadCatalog();
}

function removeProductFilterChip(key: ProductFilterKey): void {
  productPage.value = 1;
  if (key === 'view') productFilter.view = appliedProductFilters.view = '';
  else if (key === 'type') productFilter.type = appliedProductFilters.type = '';
  else if (key === 'status') productFilter.status = appliedProductFilters.status = '';
  else if (key === 'brand_id') productFilter.brand_id = appliedProductFilters.brand_id = '';
  else if (key === 'category_id') productFilter.category_id = appliedProductFilters.category_id = '';
  else if (key === 'channel') productFilter.channel = appliedProductFilters.channel = '';
  else if (key === 'archived') productFilter.archived = appliedProductFilters.archived = '';
  else if (key === 'low_stock') productFilter.low_stock = appliedProductFilters.low_stock = false;
  else if (key === 'image') productFilter.image = appliedProductFilters.image = '';
  else if (key === 'price') productFilter.price = appliedProductFilters.price = '';
  else if (key === 'purchase_price') productFilter.purchase_price = appliedProductFilters.purchase_price = false;
  void loadCatalog();
}

function applyOfferFilters(): void {
  offerPage.value = 1;
  Object.assign(appliedOfferFilters, {
    type: offerFilter.type,
    status: offerFilter.status,
    channel: offerFilter.channel,
  });
  closeCatalogMenus();
}

function removeOfferFilterChip(key: OfferFilterKey): void {
  offerPage.value = 1;
  if (key === 'type') offerFilter.type = appliedOfferFilters.type = '';
  else if (key === 'status') offerFilter.status = appliedOfferFilters.status = '';
  else if (key === 'channel') offerFilter.channel = appliedOfferFilters.channel = '';
}

function openCreateProduct(): void {
  closeCatalogMenus();
  resetProductForm(true);
  productModalMode.value = 'create';
  productModalScope.value = 'all';
  productModalFocus.value = '';
  productModalOpen.value = true;
}

async function openEditProduct(product?: CatalogProduct, scope: ProductModalScope = 'all', focus: ProductEditFocus | '' = ''): Promise<void> {
  closeCatalogMenus();
  variantAttributesOnlyModal.value = false;
  if (product && Number(product.id) !== selectedProductId.value) {
    await selectProduct(product, scope);
  } else if (selectedProduct.value) {
    fillProductForm(selectedProduct.value);
  }
  productModalMode.value = 'edit';
  productModalScope.value = scope;
  productModalFocus.value = focus;
  productModalOpen.value = true;
  if (focus) {
    await nextTick();
    const control = document.querySelector<HTMLElement>(`[data-product-field="${focus}"]`);
    control?.focus();
  }
}

async function openProductMedia(product?: CatalogProduct): Promise<void> {
  await openEditProduct(product, 'media');
}

async function openProductAttributes(product?: CatalogProduct): Promise<void> {
  await openEditProduct(product, 'attributes');
}

async function openProductTax(product?: CatalogProduct): Promise<void> {
  await openEditProduct(product, 'tax');
}

async function openVariantEditor(variant: CatalogVariant): Promise<void> {
  productViewModalOpen.value = false;
  await openProductAttributes();
  await selectVariant(variant);
}

async function toggleProductVariantMenu(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (productId < 1 || productIsArchived(product)) return;
  stockMenuProductId.value = 0;
  if (variantMenuProductId.value === productId) {
    variantMenuProductId.value = 0;
    return;
  }
  if (productId !== selectedProductId.value) {
    await selectProduct(product);
  }
  await loadVariantMenuAttributes();
  variantMenuProductId.value = productId;
}

async function toggleProductStockMenu(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (productId < 1 || productIsArchived(product) || numberValue(product.variant_count) <= 1) return;
  variantMenuProductId.value = 0;
  if (stockMenuProductId.value === productId) {
    stockMenuProductId.value = 0;
    return;
  }
  if (productId !== selectedProductId.value) {
    await selectProduct(product);
  }
  stockMenuProductId.value = productId;
}

async function loadVariantMenuAttributes(): Promise<void> {
  const missingVariantIds = selectedVariants.value
    .map((variant) => Number(variant.id || 0))
    .filter((id) => id > 0 && !variantAttributeValuesById.value[id]);
  if (missingVariantIds.length === 0) return;
  const entries = await Promise.all(missingVariantIds.map(async (id) => {
    try {
      const response = await businessCatalogApi.variantAttributes(id);
      return [id, response.data.attributes || []] as const;
    } catch {
      return [id, []] as const;
    }
  }));
  variantAttributeValuesById.value = {
    ...variantAttributeValuesById.value,
    ...Object.fromEntries(entries),
  };
}

async function openVariantEditModal(product?: CatalogProduct, variant?: CatalogVariant): Promise<void> {
  if (product && Number(product.id || 0) !== selectedProductId.value) {
    await selectProduct(product);
  }
  const targetVariant = variant || selectedVariant.value || selectedVariants.value[0] || null;
  if (targetVariant) {
    await selectVariant(targetVariant);
  }
  variantMenuProductId.value = 0;
  productViewModalOpen.value = false;
  productModalOpen.value = false;
  variantEditModalOpen.value = true;
}

function closeVariantEditModal(): void {
  variantEditModalOpen.value = false;
}

async function openVariantAttributesModal(product?: CatalogProduct, variant?: CatalogVariant): Promise<void> {
  if (product && Number(product.id || 0) !== selectedProductId.value) {
    await selectProduct(product);
  }
  const targetVariant = variant || selectedVariant.value || selectedVariants.value[0] || null;
  if (targetVariant) {
    await selectVariant(targetVariant);
  }
  variantMenuProductId.value = 0;
  productViewModalOpen.value = false;
  variantEditModalOpen.value = false;
  await openEditProduct(product, 'attributes');
  variantAttributesOnlyModal.value = true;
}

async function openNewVariantForProduct(product?: CatalogProduct): Promise<void> {
  if (product && Number(product.id || 0) !== selectedProductId.value) {
    await selectProduct(product);
  }
  variantMenuProductId.value = 0;
  stockMenuProductId.value = 0;
  productViewModalOpen.value = false;
  productModalOpen.value = false;
  startNewVariantForm();
  variantEditModalOpen.value = true;
  await nextTick();
  document.querySelector<HTMLInputElement>('[data-variant-field="sku"]')?.focus();
}

async function deleteVariantFromProduct(product: CatalogProduct | undefined, variant: CatalogVariant): Promise<void> {
  const variantId = Number(variant.id || 0);
  const productId = Number(product?.id || selectedProductId.value || variant.product_id || 0);
  if (!canWrite.value || variantId < 1 || productId < 1) return;
  if (!window.confirm(`Effacer la variante "${variantDisplayName(variant)}" ?`)) return;
  busy.value = `delete-variant-${variantId}`;
  try {
    await businessCatalogApi.deleteVariant(variantId);
    variantMenuProductId.value = 0;
    if (selectedVariant.value && Number(selectedVariant.value.id || 0) === variantId) {
      selectedVariant.value = null;
    }
    await Promise.all([
      selectProduct({ id: productId, name: product?.name || productForm.name }),
      loadCatalog(),
    ]);
    setNotice('Variante effacée.');
  } catch (err) {
    setError(err, 'Variante non effacée.');
  } finally {
    busy.value = '';
  }
}

async function openVariantPriceAdjustments(product: CatalogProduct): Promise<void> {
  closeCatalogMenus();
  if (Number(product.id) !== selectedProductId.value) {
    await selectProduct(product);
  } else if (selectedProduct.value) {
    fillProductForm(selectedProduct.value);
  }
  if (!selectedVariant.value && selectedVariants.value.length > 0) {
    await selectVariant(selectedVariants.value[0]);
  } else if (selectedVariant.value) {
    fillVariantFormFromVariant(selectedVariant.value);
  }
  variantPriceModalOpen.value = true;
}

function closeVariantPriceModal(): void {
  variantPriceModalOpen.value = false;
}

function closeProductModal(): void {
  inventoryAdjustmentOpen.value = false;
  productModalOpen.value = false;
  variantAttributesOnlyModal.value = false;
  if (selectedProduct.value) fillProductForm(selectedProduct.value);
}

async function openInventoryAdjustment(variant: CatalogVariant): Promise<void> {
  stockForm.action = 'correction';
  stockForm.quantity = '0';
  stockForm.reason = '';
  stockPreview.value = null;
  await selectVariant(variant);
  inventoryAdjustmentOpen.value = true;
}

function closeInventoryAdjustment(): void {
  inventoryAdjustmentOpen.value = false;
  stockPreview.value = null;
  stockForm.reason = '';
}

function closeProductViewModal(): void {
  productViewModalOpen.value = false;
}

function closeCatalogMenus(): void {
  document.querySelectorAll<HTMLDetailsElement>('.catalog-menu[open]').forEach((menu) => {
    menu.open = false;
  });
  variantMenuProductId.value = 0;
  stockMenuProductId.value = 0;
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target as HTMLElement | null;
  if (target?.closest('.catalog-menu, .catalog-variant-cell, .catalog-stock-cell')) return;
  closeCatalogMenus();
}

function resetVariantForm(): void {
  Object.assign(variantForm, {
    sku: '',
    barcode: '',
    name: '',
    sales_note: '',
    status: 'active',
    stock_quantity: '0',
    track_stock: true,
    allow_backorder: true,
    backorder_delivery_days: '',
    purchase_adjustment_type: 'none',
    purchase_adjustment_value: '',
    sale_adjustment_type: 'none',
    sale_adjustment_value: '',
  });
}

function startNewVariantForm(): void {
  selectedVariant.value = null;
  variantStock.value = null;
  stockMovements.value = [];
  variantAttributeValues.value = [];
  applyAttributeValues('variant', []);
  resetVariantForm();
}

function variantAdjustmentValue(variant: CatalogVariant, priceKind: 'purchase' | 'sale', field: 'type' | 'value'): string {
  const computed = variant.computed_prices || {};
  const computedKey = `${priceKind}_adjustment_${field}` as keyof typeof computed;
  const computedValue = computed[computedKey];
  if (computedValue !== null && computedValue !== undefined && computedValue !== '') return text(computedValue);
  const adjustment = Array.isArray(variant.adjustments)
    ? variant.adjustments.find((row) => row.price_kind === priceKind)
    : null;
  if (!adjustment) return field === 'type' ? 'none' : '';
  const key = field === 'type' ? 'adjustment_type' : 'adjustment_value';
  return text(adjustment[key] ?? (field === 'type' ? 'none' : ''));
}

function fillVariantFormFromVariant(variant: CatalogVariant): void {
  Object.assign(variantForm, {
    sku: text(variant.sku),
    barcode: text(variant.barcode),
    name: text(variant.name),
    sales_note: text(variant.sales_note),
    status: text(variant.status || 'active'),
    stock_quantity: text(variant.stock_quantity ?? 0),
    track_stock: variant.track_stock !== null && variant.track_stock !== undefined ? Boolean(variant.track_stock) : true,
    allow_backorder: variant.allow_backorder !== null && variant.allow_backorder !== undefined ? Boolean(variant.allow_backorder) : true,
    backorder_delivery_days: variant.backorder_delivery_days !== null && variant.backorder_delivery_days !== undefined ? text(variant.backorder_delivery_days) : '',
    purchase_adjustment_type: variantAdjustmentValue(variant, 'purchase', 'type') || 'none',
    purchase_adjustment_value: variantAdjustmentValue(variant, 'purchase', 'value'),
    sale_adjustment_type: variantAdjustmentValue(variant, 'sale', 'type') || 'none',
    sale_adjustment_value: variantAdjustmentValue(variant, 'sale', 'value'),
  });
}

function fillDiscountForm(discount: CatalogDiscount | null = null): void {
  Object.assign(discountForm, {
    id: Number(discount?.id || 0),
    objective: 'increase_sales',
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
    customer_segment: text(discount?.customer_segment),
  });
  selectedDiscount.value = discount;
  offerWizardStep.value = 1;
  offerPreview.value = null;
  offerConflictAcknowledged.value = false;
}

function fillBundleForm(bundle: CatalogProductBundle | null): void {
  const legacyStrategy = bundle?.stock_mode === 'virtual' ? 'OWN_STOCK' : bundle?.stock_mode === 'none' ? 'NON_STOCKED' : 'COMPONENT_DERIVED';
  Object.assign(bundleForm, {
    bundle_variant_id: text(bundle?.bundle_variant_id),
    pricing_mode: text(bundle?.pricing_mode || 'fixed'),
    stock_mode: text(bundle?.stock_mode || 'components'),
    stock_strategy: bundle?.stock_strategy || legacyStrategy,
    partial_availability_policy: bundle?.partial_availability_policy || 'REQUIRE_ALL',
    component_return_policy: bundle?.component_return_policy || 'BUNDLE_ONLY',
    components_public: bundle?.components_public !== false,
    is_active: bundle?.is_active !== false,
  });
}

function resetBundleIdentityForm(): void {
  Object.assign(bundleIdentityForm, {
    sku_base: '',
    name: '',
    slug: '',
    short_description: '',
    description: '',
    status: 'draft',
    is_public: false,
    is_ecommerce_enabled: true,
    is_pos_enabled: true,
    is_catalogue_enabled: true,
  });
  Object.assign(bundlePriceForm, { currency: 'CHF', base_purchase_price: '', base_sale_price: '' });
}

function fillBundleIdentityForm(detail: ProductDetail | null): void {
  const data = (detail?.data || {}) as Partial<CatalogProduct>;
  if (!data.id) {
    resetBundleIdentityForm();
    return;
  }
  Object.assign(bundleIdentityForm, {
    sku_base: text(data.sku_base),
    name: text(data.name),
    slug: text(data.slug),
    short_description: text(data.short_description),
    description: text(data.description),
    status: text(data.status || 'draft'),
    is_public: Boolean(data.is_public),
    is_ecommerce_enabled: Boolean(data.is_ecommerce_enabled),
    is_pos_enabled: Boolean(data.is_pos_enabled),
    is_catalogue_enabled: data.is_catalogue_enabled !== false,
  });
}

function fillBundlePriceForm(detail: ProductDetail | null): void {
  const prices = Array.isArray(detail?.prices) ? detail.prices : [];
  const sale = prices.find((price) => price.price_kind === 'sale');
  const purchase = prices.find((price) => price.price_kind === 'purchase');
  Object.assign(bundlePriceForm, {
    currency: text(sale?.currency || purchase?.currency || 'CHF'),
    base_purchase_price: text(purchase?.amount || ''),
    base_sale_price: text(sale?.amount || ''),
  });
}

function bundleChannels(): string[] {
  return [
    bundleIdentityForm.is_public ? 'public' : '',
    bundleIdentityForm.is_ecommerce_enabled ? 'ecommerce' : '',
    bundleIdentityForm.is_pos_enabled ? 'pos' : '',
    bundleIdentityForm.is_catalogue_enabled ? 'catalogue' : '',
  ].filter(Boolean);
}

function bundleProductPayload(): Record<string, unknown> {
  bundleIdentityForm.sku_base = normalizeSkuInput(bundleIdentityForm.sku_base || bundleIdentityForm.name || `BUNDLE${String(Date.now()).slice(-6)}`).slice(0, 80);
  return {
    sku_base: bundleIdentityForm.sku_base || undefined,
    name: bundleIdentityForm.name,
    slug: bundleIdentityForm.slug || bundleIdentityForm.name,
    type: 'bundle',
    status: bundleIdentityForm.status || 'draft',
    short_description: bundleIdentityForm.short_description,
    description: bundleIdentityForm.description,
    unit: 'unit',
    track_stock: bundleForm.stock_strategy === 'OWN_STOCK',
    allow_backorder: false,
    backorder_delivery_days: 7,
    channels: bundleChannels().length > 0 ? bundleChannels() : ['internal'],
    is_public: bundleIdentityForm.is_public,
    is_ecommerce_enabled: bundleIdentityForm.is_ecommerce_enabled,
    is_pos_enabled: bundleIdentityForm.is_pos_enabled,
    is_catalogue_enabled: bundleIdentityForm.is_catalogue_enabled,
    base_purchase_price: optionalMoneyPayload(bundlePriceForm.base_purchase_price),
    base_sale_price: optionalMoneyPayload(bundlePriceForm.base_sale_price),
    currency: bundlePriceForm.currency || 'CHF',
  };
}

async function changeBundleProduct(event: Event): Promise<void> {
  selectedBundleProductId.value = Number((event.target as HTMLSelectElement | null)?.value || 0);
  selectedBundle.value = null;
  bundleComponentForm.component_product_id = '';
  bundleComponentForm.component_variant_id = '';
  const product = selectedBundleProduct.value;
  if (product) {
    await selectProduct(product);
    fillBundleIdentityForm(selectedProduct.value);
    fillBundlePriceForm(selectedProduct.value);
  } else {
    selectedProduct.value = null;
    fillBundleForm(null);
    resetBundleIdentityForm();
  }
  await loadSelectedBundle();
}

async function loadSelectedBundle(): Promise<void> {
  if (selectedBundleProductId.value < 1) {
    selectedBundle.value = null;
    selectedProduct.value = null;
    fillBundleForm(null);
    resetBundleIdentityForm();
    return;
  }
  bundleLoading.value = true;
  try {
    const [bundleResponse, productResponse] = await Promise.all([
      businessCatalogApi.productBundle(selectedBundleProductId.value),
      businessCatalogApi.product(selectedBundleProductId.value),
    ]);
    selectedProduct.value = productResponse.data.product || null;
    selectedBundle.value = bundleResponse.data.bundle || null;
    fillBundleForm(selectedBundle.value);
    fillBundleIdentityForm(productResponse.data.product || null);
    fillBundlePriceForm(productResponse.data.product || null);
  } catch (err) {
    setError(err, 'Offre composée indisponible.');
  } finally {
    bundleLoading.value = false;
  }
}

function newBundleProduct(): void {
  selectedBundleProductId.value = 0;
  selectedBundle.value = null;
  selectedProduct.value = null;
  bundleComponentForm.component_product_id = '';
  bundleComponentForm.component_variant_id = '';
  fillBundleForm(null);
  resetBundleIdentityForm();
}

function openCreateBundleOffer(): void {
  newBundleProduct();
  selectedOfferRow.value = null;
  offerEditKind.value = 'bundle';
  offerEditModalOpen.value = true;
}

function openCreateDiscountOffer(): void {
  fillDiscountForm();
  selectedOfferRow.value = null;
  offerEditKind.value = 'discount';
  offerEditModalOpen.value = true;
}

async function openViewOffer(row: CatalogOfferRow): Promise<void> {
  selectedOfferRow.value = row;
  if (row.kind === 'bundle') {
    selectedBundleProductId.value = row.id;
    await loadSelectedBundle();
  } else if (row.discount) {
    fillDiscountForm(row.discount);
  }
  offerViewModalOpen.value = true;
}

async function openEditOffer(row: CatalogOfferRow): Promise<void> {
  selectedOfferRow.value = row;
  offerEditKind.value = row.kind;
  if (row.kind === 'bundle') {
    selectedBundleProductId.value = row.id;
    await loadSelectedBundle();
  } else if (row.discount) {
    fillDiscountForm(row.discount);
  }
  offerEditModalOpen.value = true;
}

async function duplicateDiscount(discount: CatalogDiscount): Promise<void> {
  if (!canDiscountWrite.value) return;
  busy.value = `duplicate-discount-${discount.id}`;
  try {
    await businessCatalogApi.createDiscount({
      name: `${discount.name || 'Réduction'} copie`,
      status: 'draft',
      type: discount.discount_type || 'percent',
      value: Number(discount.discount_value || 0),
      currency: discount.discount_type === 'amount' ? discount.currency || 'CHF' : undefined,
      scope: discount.scope_type || 'product',
      scope_id: idOrNull(discount.scope_id),
      channel: discount.channel || 'all',
      starts_at: discount.starts_at || undefined,
      ends_at: discount.ends_at || undefined,
      priority: Number(discount.priority || 100),
    });
    await loadCatalog();
    setNotice('Réduction dupliquée.');
  } catch (err) {
    setError(err, 'Réduction non dupliquée.');
  } finally {
    busy.value = '';
  }
}

async function duplicateBundleOffer(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (!canWrite.value || productId < 1) return;
  busy.value = `duplicate-bundle-${productId}`;
  try {
    const [detailResponse, bundleResponse] = await Promise.all([
      businessCatalogApi.product(productId),
      businessCatalogApi.productBundle(productId),
    ]);
    const detail = detailResponse.data.product;
    const data = detail.data || product;
    const prices = Array.isArray(detail.prices) ? detail.prices : [];
    const sale = prices.find((price) => price.price_kind === 'sale');
    const purchase = prices.find((price) => price.price_kind === 'purchase');
    const currency = text(sale?.currency || purchase?.currency || 'CHF');
    const copySku = duplicateSkuBase(data.sku_base || product.sku_base || data.name || product.name);
    const created = await businessCatalogApi.createProduct({
      name: `${text(data.name || product.name)} copie`,
      sku_base: copySku,
      slug: `${text(data.slug || product.slug || data.name || product.name)}-copie-${String(Date.now()).slice(-6)}`,
      type: 'bundle',
      status: 'draft',
      short_description: text(data.short_description),
      description: text(data.description),
      is_public: false,
      is_ecommerce_enabled: Boolean(data.is_ecommerce_enabled ?? product.is_ecommerce_enabled),
      is_pos_enabled: Boolean(data.is_pos_enabled ?? product.is_pos_enabled),
      is_catalogue_enabled: data.is_catalogue_enabled !== false,
      track_stock: false,
      allow_backorder: true,
      backorder_delivery_days: Number(data.backorder_delivery_days ?? product.backorder_delivery_days ?? 7),
      base_sale_price: sale?.amount ?? undefined,
      base_purchase_price: canPurchaseRead.value ? purchase?.amount ?? undefined : undefined,
      currency: currency || 'CHF',
    });
    const copyId = productDetailId(created.data.product);
    if (copyId > 0) {
      const sourceBundle = bundleResponse.data.bundle;
      const sourceStrategy = sourceBundle?.stock_strategy || (sourceBundle?.stock_mode === 'virtual' ? 'OWN_STOCK' : sourceBundle?.stock_mode === 'none' ? 'NON_STOCKED' : 'COMPONENT_DERIVED');
      await businessCatalogApi.createVariant(copyId, { sku: copySku, name: 'Standard', status: 'active', stock_quantity: 0, track_stock: sourceStrategy === 'OWN_STOCK' });
      const copiedBundle = await businessCatalogApi.updateProductBundle(copyId, {
        pricing_mode: sourceBundle?.pricing_mode || 'fixed',
        stock_mode: sourceBundle?.stock_mode || 'components',
        stock_strategy: sourceBundle?.stock_strategy || 'COMPONENT_DERIVED',
        partial_availability_policy: sourceBundle?.partial_availability_policy || 'REQUIRE_ALL',
        component_return_policy: sourceBundle?.component_return_policy || 'BUNDLE_ONLY',
        components_public: sourceBundle?.components_public !== false,
        is_active: sourceBundle?.is_active !== false,
      });
      for (const component of sourceBundle?.components || []) {
        await businessCatalogApi.addBundleComponent(Number(copiedBundle.data.bundle.id), {
          component_product_id: component.component_product_id,
          component_variant_id: component.component_variant_id || undefined,
          quantity: component.quantity || 1,
          is_required: component.is_required !== false,
          sort_order: component.sort_order || 0,
        });
      }
    }
    await loadCatalog();
    setNotice('Bundle dupliqué en brouillon.');
  } catch (err) {
    setError(err, 'Bundle non dupliqué.');
  } finally {
    busy.value = '';
  }
}

async function duplicateOffer(row: CatalogOfferRow): Promise<void> {
  closeCatalogMenus();
  if (row.kind === 'bundle' && row.bundle) await duplicateBundleOffer(row.bundle);
  if (row.kind === 'discount' && row.discount) await duplicateDiscount(row.discount);
}

async function archiveOffer(row: CatalogOfferRow): Promise<void> {
  closeCatalogMenus();
  if (row.kind === 'bundle' && row.bundle) await archiveProduct(row.bundle);
  if (row.kind === 'discount' && row.discount) await archiveDiscount(row.discount);
}

async function saveBundle(): Promise<void> {
  if (!canWrite.value || !bundleIdentityForm.name.trim()) return;
  busy.value = 'bundle';
  try {
    let productId = selectedBundleProductId.value;
    const productPayload = bundleProductPayload();
    const targetStatus = String(productPayload.status || 'draft');
    const identityPayload = { ...productPayload };
    if (targetStatus === 'active') {
      delete identityPayload.status;
    }
    if (productId > 0) {
      await businessCatalogApi.updateProduct(productId, identityPayload);
      const variants = Array.isArray(selectedProduct.value?.variants) ? selectedProduct.value.variants : [];
      await Promise.all(variants.map((variant) => businessCatalogApi.updateVariant(Number(variant.id), { track_stock: bundleForm.stock_strategy === 'OWN_STOCK' })));
    } else {
      const created = await businessCatalogApi.createProduct({ ...productPayload, status: 'draft' });
      productId = productDetailId(created.data.product);
      selectedBundleProductId.value = productId;
      if (productId < 1) {
        throw new Error('business.bundle_product_id_missing');
      }
      if (productId > 0 && bundleIdentityForm.sku_base) {
        await businessCatalogApi.createVariant(productId, {
          sku: bundleIdentityForm.sku_base,
          name: 'Standard',
          status: 'active',
          stock_quantity: 0,
          track_stock: bundleForm.stock_strategy === 'OWN_STOCK',
        });
      }
    }
    const response = await businessCatalogApi.updateProductBundle(productId, {
      bundle_variant_id: idOrNull(bundleForm.bundle_variant_id),
      pricing_mode: bundleForm.pricing_mode,
      stock_mode: bundleForm.stock_mode,
      stock_strategy: bundleForm.stock_strategy,
      partial_availability_policy: bundleForm.partial_availability_policy,
      partial_fulfillment_supported: false,
      component_return_policy: bundleForm.component_return_policy,
      components_public: bundleForm.components_public,
      is_active: bundleForm.is_active,
    });
    if (canPriceWrite.value) {
      const pricePayload: Record<string, unknown> = { currency: bundlePriceForm.currency || 'CHF' };
      const purchasePrice = canPurchaseRead.value ? optionalMoneyPayload(bundlePriceForm.base_purchase_price) : undefined;
      const salePrice = optionalMoneyPayload(bundlePriceForm.base_sale_price);
      if (purchasePrice !== undefined) pricePayload.base_purchase_price = purchasePrice;
      if (salePrice !== undefined) pricePayload.base_sale_price = salePrice;
      if (purchasePrice !== undefined || salePrice !== undefined) {
        await businessCatalogApi.updateBasePrices(productId, pricePayload);
      }
    }
    if (targetStatus !== 'draft') {
      await businessCatalogApi.updateProduct(productId, { ...productPayload, status: targetStatus });
    }
    selectedBundle.value = response.data.bundle;
    fillBundleForm(selectedBundle.value);
    await loadCatalog();
    await loadSelectedBundle();
    setNotice('Offre composée enregistrée.');
  } catch (err) {
    setError(err, 'Offre composée non enregistrée.');
  } finally {
    busy.value = '';
  }
}

function prepareBundleDiscount(): void {
  if (selectedBundleProductId.value < 1) return;
  const variantId = Number(bundleForm.bundle_variant_id || 0);
  fillDiscountForm();
  Object.assign(discountForm, {
    name: `Réduction ${selectedBundleProduct.value?.name || 'bundle'}`,
    status: 'active',
    type: 'percent',
    value: '',
    currency: bundlePriceForm.currency || 'CHF',
    scope: variantId > 0 ? 'variant' : 'product',
    scope_id: text(variantId > 0 ? variantId : selectedBundleProductId.value),
    channel: 'all',
    priority: '50',
  });
  setNotice('Réduction préparée pour cette offre composée.');
}

async function disableBundle(): Promise<void> {
  if (!canWrite.value || selectedBundleProductId.value < 1) return;
  busy.value = 'bundle';
  try {
    await businessCatalogApi.deleteProductBundle(selectedBundleProductId.value);
    selectedBundle.value = null;
    fillBundleForm(null);
    setNotice('Offre composée désactivée.');
  } catch (err) {
    setError(err, 'Offre composée non désactivée.');
  } finally {
    busy.value = '';
  }
}

async function addBundleComponent(): Promise<void> {
  if (!canWrite.value || !selectedBundle.value?.id) return;
  busy.value = 'bundle-component';
  try {
    await businessCatalogApi.addBundleComponent(Number(selectedBundle.value.id), {
      component_product_id: idOrNull(bundleComponentForm.component_product_id),
      component_variant_id: idOrNull(bundleComponentForm.component_variant_id),
      quantity: Number(bundleComponentForm.quantity || 1),
      is_required: bundleComponentForm.is_required,
    });
    Object.assign(bundleComponentForm, { component_product_id: '', component_variant_id: '', quantity: '1', is_required: true });
    await loadSelectedBundle();
    setNotice('Composant ajouté.');
  } catch (err) {
    setError(err, 'Composant non ajouté.');
  } finally {
    busy.value = '';
  }
}

async function removeBundleComponent(component: CatalogBundleComponent): Promise<void> {
  if (!canWrite.value) return;
  busy.value = `bundle-component-${component.id}`;
  try {
    await businessCatalogApi.deleteBundleComponent(Number(component.id));
    await loadSelectedBundle();
    setNotice('Composant retiré.');
  } catch (err) {
    setError(err, 'Composant non retiré.');
  } finally {
    busy.value = '';
  }
}

function bundlePricingLabel(mode: string | undefined): string {
  return ({ fixed: 'Prix fixe', sum_components: 'Somme des composants', discount_components: 'Somme remisée' } as Record<string, string>)[mode || 'fixed'] || mode || '—';
}

function bundleStockLabel(mode: string | undefined): string {
  return ({ COMPONENT_DERIVED: t('business.bundle.strategy.componentDerived'), OWN_STOCK: t('business.bundle.strategy.ownStock'), NON_STOCKED: t('business.bundle.strategy.nonStocked'), components: t('business.bundle.strategy.componentDerived'), virtual: t('business.bundle.strategy.ownStock'), none: t('business.bundle.strategy.nonStocked') } as Record<string, string>)[mode || 'COMPONENT_DERIVED'] || mode || '—';
}

function bundleAvailabilityLabel(status: unknown): string {
  return t(`business.bundle.availability.${String(status || 'unavailable')}` as never);
}

function bundleConfigurationErrorLabel(error: string): string {
  return t(`business.bundle.error.${error}` as never);
}

async function moveBundleComponent(component: CatalogBundleComponent, direction: -1 | 1): Promise<void> {
  const rows = [...(selectedBundle.value?.components || [])];
  const index = rows.findIndex((row) => Number(row.id) === Number(component.id));
  const target = index + direction;
  if (index < 0 || target < 0 || target >= rows.length) return;
  busy.value = `bundle-component-${component.id}`;
  try {
    await Promise.all([
      businessCatalogApi.updateBundleComponent(Number(component.id), { sort_order: (target + 1) * 10 }),
      businessCatalogApi.updateBundleComponent(Number(rows[target].id), { sort_order: (index + 1) * 10 }),
    ]);
    await loadSelectedBundle();
  } catch (err) { setError(err, t('business.bundle.reorderError')); }
  finally { busy.value = ''; }
}

function fillBrandForm(brand: CatalogRecord | null = null): void {
  Object.assign(brandForm, {
    id: Number(brand?.id || 0),
    name: text(brand?.name),
    slug: text(brand?.slug),
    company_id: text(brand?.company_id),
    description: text(brand?.description),
    website_url: text(brand?.website_url),
    status: text(brand?.status || 'active'),
    sort_order: text(brand?.sort_order || 0),
  });
}

function fillCategoryForm(category: CatalogRecord | null = null): void {
  Object.assign(categoryForm, {
    id: Number(category?.id || 0),
    name: text(category?.name),
    slug: text(category?.slug),
    parent_id: text(category?.parent_id),
    description: text(category?.description),
    sort_order: text(category?.sort_order || 0),
  });
}

function fillTaxClassForm(taxClass: CatalogTaxClass | null = null): void {
  Object.assign(taxClassForm, {
    id: Number(taxClass?.id || 0),
    code: text(taxClass?.code),
    name: text(taxClass?.name),
    rate: text(taxClass?.rate ?? 0),
    country: text(taxClass?.country || 'CH'),
    is_default: Boolean(taxClass?.is_default),
    usage_count: Number(taxClass?.usage_count || 0),
  });
}

function resetAssetForm(asset: CatalogProductAsset | null = null): void {
  Object.assign(assetForm, {
    id: Number(asset?.asset_id || asset?.id || 0),
    media_id: text(asset?.media_id),
    variant_id: text(asset?.variant_id),
    role: text(asset?.role || 'gallery'),
    channel_scope: text(asset?.channel_scope || 'ecommerce'),
    title: text(asset?.title),
    alt_text: text(asset?.alt_text),
    caption: text(asset?.caption),
    sort_order: text(asset?.sort_order || 0),
    is_public: asset ? Boolean(asset.is_public) : true,
  });
}

function startAssetPreset(preset: 'main'|'gallery'|'variant'|'document'): void {
  resetAssetForm(null);
  assetForm.channel_scope=preset==='document'?'all':'ecommerce';
  assetForm.is_public=true;
  assetForm.role=preset==='document'?'document':preset;
  assetForm.variant_id=preset==='variant'&&selectedVariants.value.length?String(selectedVariants.value[0].id):'';
  const relevant=productAssets.value.filter((asset)=>preset==='variant'?Boolean(asset.variant_id):!asset.variant_id);
  assetForm.sort_order=String((relevant.reduce((highest,asset)=>Math.max(highest,Number(asset.sort_order||0)),0)||0)+10);
}

function onAssetVariantChange(): void {
  if(assetForm.variant_id&&['main','gallery','thumbnail'].includes(assetForm.role))assetForm.role='variant';
  if(!assetForm.variant_id&&assetForm.role==='variant')assetForm.role=mainProductAsset.value?'gallery':'main';
}

function onAssetRoleChange(): void {
  if(assetForm.role==='internal'){assetForm.is_public=false;assetForm.channel_scope='admin';}
  else if(['document','technical_sheet'].includes(assetForm.role)){assetForm.variant_id='';if(assetForm.channel_scope==='admin')assetForm.channel_scope='all';}
}

function selectProductMedia(asset: MediaAsset|null): void {
  if(!asset){assetForm.media_id='';return;}
  if(!mediaRows.value.some((media)=>Number(media.id)===Number(asset.id)))mediaRows.value=[asset as unknown as CatalogRecord,...mediaRows.value];
  assetForm.media_id=String(asset.id);
  if(!assetForm.title)assetForm.title=text(asset.title||asset.original_filename||asset.filename);
  if(!assetForm.alt_text)assetForm.alt_text=text(asset.alt_text);
  if(!assetForm.caption)assetForm.caption=text(asset.caption);
  void loadMediaRows();
}

function fillAttributeGroupForm(group: CatalogAttributeGroup | null = null): void {
  Object.assign(attributeGroupForm, {
    id: Number(group?.id || 0),
    code: text(group?.code),
    name: text(group?.name),
    description: text(group?.description),
    sort_order: text(group?.sort_order || 0),
  });
}

function fillAttributeForm(attribute: CatalogAttribute | null = null): void {
  Object.assign(attributeForm, {
    id: Number(attribute?.id || 0),
    group_id: text(attribute?.group_id),
    code: text(attribute?.code),
    name: text(attribute?.name),
    data_type: text(attribute?.data_type || 'text'),
    unit: text(attribute?.unit),
    is_required: Boolean(attribute?.is_required),
    is_filterable: Boolean(attribute?.is_filterable),
    is_searchable: Boolean(attribute?.is_searchable),
    is_public: Boolean(attribute?.is_public),
    sort_order: text(attribute?.sort_order || 0),
  });
  attributeOptionForm.attribute_id = text(attribute?.id);
}

function fillAttributeOptionForm(attribute: CatalogAttribute, option: CatalogRecord | null = null): void {
  Object.assign(attributeOptionForm, {
    id: Number(option?.id || 0),
    attribute_id: text(attribute.id),
    code: text(option?.code),
    label: text(option?.label || option?.name),
    value: text(option?.value || option?.label || option?.name),
    color_hex: text(option?.color_hex),
    sort_order: text(option?.sort_order || 0),
  });
}

function openAttributeSettings(modal: AttributeSettingsModal = 'attributes'): void {
  closeCatalogMenus();
  attributeSettingsModal.value = modal;
  void loadAttributeDefinitions();
}

function openProductReference(kind: ProductReferenceKind): void {
  closeCatalogMenus();
  referenceKind.value = kind;
  if (kind === 'brand') fillBrandForm(null);
  if (kind === 'category') fillCategoryForm(null);
  if (kind === 'tax') fillTaxClassForm(null);
  referenceModalOpen.value = true;
}

function closeReferenceModal(): void {
  referenceModalOpen.value = false;
}

function closeAttributeSettings(): void {
  attributeSettingsModal.value = '';
}

function resetAttributeOptionForm(attribute: CatalogAttribute | null = selectedOptionAttribute.value): void {
  Object.assign(attributeOptionForm, {
    id: 0,
    attribute_id: text(attribute?.id || attributeOptionForm.attribute_id),
    code: '',
    label: '',
    value: '',
    color_hex: '',
    sort_order: '0',
  });
}

function setAttributeOptionColor(color: string): void {
  attributeOptionForm.color_hex = color.toUpperCase();
}

async function saveBrand(): Promise<void> {
  if (!canWrite.value) return;
  busy.value = 'brand';
  try {
    const payload = {
      name: brandForm.name,
      slug: brandForm.slug || brandForm.name,
      company_id: idOrNull(brandForm.company_id),
      description: brandForm.description,
      website_url: brandForm.website_url,
      status: brandForm.status,
      sort_order: Number(brandForm.sort_order || 0),
    };
    if (brandForm.id > 0) await businessCatalogApi.updateBrand(brandForm.id, payload);
    else await businessCatalogApi.createBrand(payload);
    await loadCatalog();
    fillBrandForm(null);
    setNotice('Marque enregistrée.');
  } catch (err) {
    setError(err, 'Marque non enregistrée.');
  } finally {
    busy.value = '';
  }
}

async function deleteCurrentBrand(): Promise<void> {
  if (!canWrite.value || brandForm.id < 1) return;
  if (typeof window !== 'undefined' && !window.confirm('Effacer cette marque ? Cette action est possible uniquement si aucun produit ne l’utilise.')) return;
  busy.value = 'brand-delete';
  try {
    await businessCatalogApi.deleteBrand(brandForm.id);
    await loadCatalog();
    if (String(productForm.brand_id) === String(brandForm.id)) productForm.brand_id = '';
    fillBrandForm(null);
    setNotice('Marque effacée.');
  } catch (err) {
    setError(err, 'Marque non effacée.');
  } finally {
    busy.value = '';
  }
}

async function saveCategory(): Promise<void> {
  if (!canWrite.value) return;
  busy.value = 'category';
  try {
    const payload = {
      name: categoryForm.name,
      slug: categoryForm.slug || categoryForm.name,
      parent_id: idOrNull(categoryForm.parent_id),
      description: categoryForm.description,
      sort_order: Number(categoryForm.sort_order || 0),
    };
    if (categoryForm.id > 0) await businessCatalogApi.updateCategory(categoryForm.id, payload);
    else await businessCatalogApi.createCategory(payload);
    await loadCatalog();
    fillCategoryForm(null);
    setNotice('Catégorie enregistrée.');
  } catch (err) {
    setError(err, 'Catégorie non enregistrée.');
  } finally {
    busy.value = '';
  }
}

async function deleteCurrentCategory(): Promise<void> {
  if (!canWrite.value || categoryForm.id < 1) return;
  if (typeof window !== 'undefined' && !window.confirm('Effacer cette catégorie ?')) return;
  busy.value = 'category-delete';
  try {
    await businessCatalogApi.deleteCategory(categoryForm.id);
    await loadCatalog();
    if (String(productForm.category_id) === String(categoryForm.id)) productForm.category_id = '';
    fillCategoryForm(null);
    setNotice('Catégorie effacée.');
  } catch (err) {
    setError(err, 'Catégorie non effacée.');
  } finally {
    busy.value = '';
  }
}

async function saveTaxClass(): Promise<void> {
  if (!canWrite.value) return;
  busy.value = 'tax-class';
  try {
    const payload = {
      code: taxClassForm.code || taxClassForm.name,
      name: taxClassForm.name,
      rate: Number(taxClassForm.rate || 0),
      country: taxClassForm.country || 'CH',
      is_default: taxClassForm.is_default,
    };
    if (taxClassForm.id > 0) await businessCatalogApi.updateTaxClass(taxClassForm.id, payload);
    else await businessCatalogApi.createTaxClass(payload);
    await loadCatalog();
    fillTaxClassForm(null);
    setNotice('Taux TVA enregistré.');
  } catch (err) {
    setError(err, 'Taux TVA non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function deleteCurrentTaxClass(): Promise<void> {
  if (!canWrite.value || taxClassForm.id < 1 || taxClassForm.usage_count > 0) return;
  if (typeof window !== 'undefined' && !window.confirm('Effacer ce taux TVA ? Cette action est possible uniquement si aucun produit ne l’utilise.')) return;
  busy.value = 'tax-class-delete';
  try {
    await businessCatalogApi.deleteTaxClass(taxClassForm.id);
    await loadCatalog();
    if (String(productForm.tax_class_id) === String(taxClassForm.id)) productForm.tax_class_id = '';
    if (String(bulkProductForm.tax_class_id) === String(taxClassForm.id)) bulkProductForm.tax_class_id = '';
    fillTaxClassForm(null);
    setNotice('Taux TVA effacé.');
  } catch (err) {
    setError(err, 'Taux TVA non effacé.');
  } finally {
    busy.value = '';
  }
}

async function loadAttributeDefinitions(): Promise<void> {
  try {
    const [groupResponse, attributeResponse] = await Promise.all([
      businessCatalogApi.attributeGroups(),
      businessCatalogApi.attributes(),
    ]);
    attributeGroups.value = groupResponse.data.attribute_groups || [];
    attributes.value = attributeResponse.data.attributes || [];
  } catch (err) {
    setError(err, 'Attributs indisponibles.');
  }
}

async function loadProductAttributeValues(productId = selectedProductId.value): Promise<void> {
  if (!productId) {
    productAttributeValues.value = [];
    applyAttributeValues('product', []);
    return;
  }
  try {
    const response = await businessCatalogApi.productAttributes(productId);
    productAttributeValues.value = response.data.attributes || [];
    applyAttributeValues('product', productAttributeValues.value);
  } catch (err) {
    productAttributeValues.value = [];
    applyAttributeValues('product', []);
    setError(err, 'Attributs produit indisponibles.');
  }
}

async function loadVariantAttributeValues(variantId = Number(selectedVariant.value?.id || 0)): Promise<void> {
  if (!variantId) {
    variantAttributeValues.value = [];
    applyAttributeValues('variant', []);
    return;
  }
  try {
    const response = await businessCatalogApi.variantAttributes(variantId);
    variantAttributeValues.value = response.data.attributes || [];
    variantAttributeValuesById.value = { ...variantAttributeValuesById.value, [variantId]: variantAttributeValues.value };
    applyAttributeValues('variant', variantAttributeValues.value);
  } catch (err) {
    variantAttributeValues.value = [];
    applyAttributeValues('variant', []);
    setError(err, 'Attributs variante indisponibles.');
  }
}

async function saveAttributeGroup(): Promise<void> {
  if (!canWrite.value) return;
  busy.value = 'attribute-group';
  try {
    const payload = {
      code: attributeGroupForm.code || attributeGroupForm.name,
      name: attributeGroupForm.name,
      description: attributeGroupForm.description,
      sort_order: Number(attributeGroupForm.sort_order || 0),
    };
    if (attributeGroupForm.id > 0) await businessCatalogApi.updateAttributeGroup(attributeGroupForm.id, payload);
    else await businessCatalogApi.createAttributeGroup(payload);
    await loadAttributeDefinitions();
    fillAttributeGroupForm(null);
    setNotice('Groupe attribut enregistré.');
  } catch (err) {
    setError(err, 'Groupe attribut non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function saveAttributeDefinition(): Promise<void> {
  if (!canWrite.value) return;
  if (attributeFormImpactWarnings.value.length) {
    setError(new Error(attributeFormImpactWarnings.value.join(' ')), 'Attribut non enregistré.');
    return;
  }
  if (!idOrNull(attributeForm.group_id)) {
    setError(new Error('Sélectionner un groupe d’attributs.'), 'Attribut non enregistré.');
    return;
  }
  busy.value = 'attribute';
  try {
    const payload = {
      group_id: idOrNull(attributeForm.group_id),
      code: attributeForm.code || attributeForm.name,
      name: attributeForm.name,
      data_type: attributeForm.data_type,
      unit: attributeForm.unit,
      is_required: attributeForm.is_required,
      is_filterable: attributeForm.is_filterable,
      is_searchable: attributeForm.is_searchable,
      is_public: attributeForm.is_public,
      sort_order: Number(attributeForm.sort_order || 0),
    };
    if (attributeForm.id > 0) await businessCatalogApi.updateAttribute(attributeForm.id, payload);
    else await businessCatalogApi.createAttribute(payload);
    await loadAttributeDefinitions();
    fillAttributeForm(null);
    setNotice('Attribut enregistré.');
  } catch (err) {
    setError(err, 'Attribut non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function saveAttributeOption(): Promise<void> {
  const attributeId = idOrNull(attributeOptionForm.attribute_id);
  if (!canWrite.value || !attributeId) return;
  busy.value = 'attribute-option';
  try {
    const payload = {
      code: attributeOptionForm.code || attributeOptionForm.label,
      label: attributeOptionForm.label,
      value: attributeOptionForm.value || attributeOptionForm.label,
      color_hex: attributeOptionForm.color_hex,
      sort_order: Number(attributeOptionForm.sort_order || 0),
    };
    if (attributeOptionForm.id > 0) await businessCatalogApi.updateAttributeOption(attributeOptionForm.id, payload);
    else await businessCatalogApi.createAttributeOption(attributeId, payload);
    await loadAttributeDefinitions();
    resetAttributeOptionForm(selectedOptionAttribute.value);
    setNotice('Option attribut enregistrée.');
  } catch (err) {
    setError(err, 'Option attribut non enregistrée.');
  } finally {
    busy.value = '';
  }
}

async function deleteCurrentAttributeOption(): Promise<void> {
  if (!canWrite.value || attributeOptionForm.id < 1) return;
  if (typeof window !== 'undefined' && !window.confirm('Effacer cette option ?')) return;
  busy.value = 'attribute-option-delete';
  try {
    await businessCatalogApi.deleteAttributeOption(attributeOptionForm.id);
    await loadAttributeDefinitions();
    resetAttributeOptionForm(selectedOptionAttribute.value);
    setNotice('Option attribut effacée.');
  } catch (err) {
    setError(err, 'Option attribut non effacée.');
  } finally {
    busy.value = '';
  }
}

async function saveProductAttributeValues(notify = true): Promise<boolean> {
  const productId = selectedProductId.value;
  if (!canWrite.value || productId < 1) return false;
  busy.value = 'product-attributes';
  try {
    const response = await businessCatalogApi.updateProductAttributes(productId, { values: valuesPayloadFromForm(productAttributeForm) });
    productAttributeValues.value = response.data.attributes || [];
    applyAttributeValues('product', productAttributeValues.value);
    if (notify) setNotice('Attributs produit enregistrés.');
    return true;
  } catch (err) {
    setError(err, 'Attributs produit non enregistrés.');
    return false;
  } finally {
    busy.value = '';
  }
}

async function saveVariantAttributeValues(notify = true): Promise<boolean> {
  const variantId = Number(selectedVariant.value?.id || 0);
  if (!canWrite.value || variantId < 1) return false;
  busy.value = 'variant-attributes';
  try {
    const response = await businessCatalogApi.updateVariantAttributes(variantId, { values: valuesPayloadFromForm(variantAttributeForm) });
    variantAttributeValues.value = response.data.attributes || [];
    variantAttributeValuesById.value = { ...variantAttributeValuesById.value, [variantId]: variantAttributeValues.value };
    applyAttributeValues('variant', variantAttributeValues.value);
    if (notify) setNotice('Attributs variante enregistrés.');
    return true;
  } catch (err) {
    setError(err, 'Attributs variante non enregistrés.');
    return false;
  } finally {
    busy.value = '';
  }
}

async function loadProductAssets(productId = selectedProductId.value): Promise<void> {
  if (!productId) {
    productAssets.value = [];
    return;
  }
  try {
    const response = await businessCatalogApi.productAssets(productId, { include_product_assets: 1 });
    productAssets.value = response.data.assets || [];
  } catch (err) {
    productAssets.value = [];
    setError(err, 'Médias produit indisponibles.');
  }
}

async function loadMediaRows(): Promise<void> {
  try {
    const response = await businessCatalogApi.media({ limit: 120, type: 'all' });
    mediaRows.value = normalizeMediaResponse(response.data);
  } catch (_) {
    mediaRows.value = [];
  }
}

function assetPayload(): Record<string, unknown> {
  return {
    media_id: idOrNull(assetForm.media_id),
    variant_id: idOrNull(assetForm.variant_id),
    role: assetForm.role,
    channel_scope: assetForm.channel_scope,
    title: assetForm.title,
    alt_text: assetForm.alt_text,
    caption: assetForm.caption,
    sort_order: Number(assetForm.sort_order || 0),
    is_public: assetForm.is_public,
  };
}

async function saveProductAsset(): Promise<void> {
  const productId = selectedProductId.value;
  if (!canWrite.value || productId < 1) return;
  busy.value = 'asset';
  try {
    const response=assetForm.id > 0?await businessCatalogApi.updateProductAsset(assetForm.id,assetPayload()):await businessCatalogApi.assignProductAsset(productId,assetPayload());
    await Promise.all([loadProductAssets(productId),loadMediaRows()]);
    await loadCatalog();
    resetAssetForm(null);
    setNotice(text((response.data as Record<string,unknown>).message)||'Média enregistré et boutique actualisée.');
  } catch (err) {
    setError(err, 'Média produit non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function archiveProductAsset(asset: CatalogProductAsset): Promise<void> {
  const assetId = Number(asset.asset_id || asset.id || 0);
  if (!canWrite.value || assetId < 1) return;
  busy.value = `asset-${assetId}`;
  try {
    const response=await businessCatalogApi.archiveProductAsset(assetId);
    await loadProductAssets();
    await loadCatalog();
    setNotice(text((response.data as Record<string,unknown>).message)||'Média retiré et boutique actualisée.');
  } catch (err) {
    setError(err, 'Lien média non archivé.');
  } finally {
    busy.value = '';
  }
}

async function archiveCurrentAsset(): Promise<void> {
  if (assetForm.id < 1) return;
  await archiveProductAsset({ id: assetForm.id } as CatalogProductAsset);
  resetAssetForm(null);
}

async function setMainProductAsset(asset: CatalogProductAsset): Promise<void> {
  const assetId = Number(asset.asset_id || asset.id || 0);
  if (!canWrite.value || assetId < 1) return;
  busy.value = `asset-${assetId}`;
  try {
    const response=await businessCatalogApi.setMainProductAsset(assetId);
    await loadProductAssets();
    await loadCatalog();
    setNotice(text((response.data as Record<string,unknown>).message)||'Image principale définie et boutique actualisée.');
  } catch (err) {
    setError(err, 'Image principale non définie.');
  } finally {
    busy.value = '';
  }
}

async function setCurrentAssetAsMain(): Promise<void> {
  if (assetForm.id < 1) return;
  await setMainProductAsset({ id: assetForm.id } as CatalogProductAsset);
  assetForm.role = 'main';
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
    const pageLimit = productPageLimit.value;
    const pageOffset = productPageSize.value === 'all' ? 0 : (productPage.value - 1) * productNumericPageLimit.value;
    const [brandResponse, categoryResponse, optionResponse, taxClassResponse, productResponse, bundleProductResponse, componentProductResponse, discountResponse] = await Promise.all([
      businessCatalogApi.brands(),
      businessCatalogApi.categories(),
      businessCatalogApi.options(),
      businessCatalogApi.taxClasses(),
      businessCatalogApi.products({
        q: productFilter.q,
        view: productFilter.view || undefined,
        type: productFilter.type || undefined,
        brand_id: productFilter.brand_id || undefined,
        category_id: productFilter.category_id || undefined,
        status: productFilter.status || undefined,
        channel: productFilter.channel || undefined,
        archived: (productFilter.archived || productFilter.status === 'archived') ? '1' : undefined,
        low_stock: productFilter.low_stock ? 1 : undefined,
        image: productFilter.image || undefined,
        price: productFilter.price || undefined,
        purchase_price: productFilter.purchase_price ? 'missing' : undefined,
        limit: pageLimit,
        offset: pageOffset,
      }),
      businessCatalogApi.products({ type: 'bundle', archived: '1', limit: 200, offset: 0 }),
      businessCatalogApi.products({ archived: '1', limit: 500, offset: 0 }),
      businessCatalogApi.discounts(),
    ]);
    brands.value = brandResponse.data.brands || [];
    categories.value = categoryResponse.data.categories || [];
    options.value = optionResponse.data.options || [];
    taxClasses.value = taxClassResponse.data.tax_classes || [];
    products.value = productResponse.data.products || [];
    bundleProductRows.value = bundleProductResponse.data.products || [];
    bundleComponentProductRows.value = componentProductResponse.data.products || [];
    if (selectedBundleProductId.value < 1) {
      const firstBundle = bundleProductRows.value[0];
      selectedBundleProductId.value = Number(firstBundle?.id || 0);
    }
    productPagination.value = {
      limit: Number(productResponse.data.pagination?.limit || productNumericPageLimit.value),
      offset: Number(productResponse.data.pagination?.offset || pageOffset),
      total: Number(productResponse.data.pagination?.total || products.value.length),
    };
    if (productPage.value > productPageCount.value) productPage.value = productPageCount.value;
    discounts.value = discountResponse.data.discounts || [];
    if (selectedBundleProductId.value > 0) {
      void loadSelectedBundle();
    }
    try {
      const companyResponse = await businessCatalogApi.brandCompanies();
      brandCompanies.value = companyResponse.data.companies || [];
    } catch {
      brandCompanies.value = [];
    }
  } catch (err) {
    setError(err, 'Catalogue indisponible.');
  } finally {
    loading.value = false;
  }
}

async function selectProduct(product: CatalogProduct, scope: ProductModalScope = 'all'): Promise<void> {
  loading.value = true;
  try {
    variantAttributeValuesById.value = {};
    const response = await businessCatalogApi.product(Number(product.id));
    selectedProduct.value = response.data.product;
    fillProductForm(response.data.product);
    selectedVariant.value = selectedProduct.value.variants?.[0] || null;
    if (scope === 'media') {
      await Promise.all([loadProductAssets(Number(product.id)), loadMediaRows()]);
      return;
    }
    await Promise.all([loadAttributeDefinitions(), loadProductAssets(Number(product.id)), loadProductAttributeValues(Number(product.id)), loadMediaRows(), loadProductContentLinks(Number(product.id)), loadProductRelations(Number(product.id))]);
    if (selectedVariant.value) await Promise.all([loadVariantStock(selectedVariant.value), loadVariantAttributeValues(Number(selectedVariant.value.id || 0))]);
  } catch (err) {
    setError(err, 'Produit indisponible.');
  } finally {
    loading.value = false;
  }
}

async function loadProductContentLinks(productId = selectedProductId.value): Promise<void> {
  if (productId < 1) {
    productContentLinks.value = [];
    contentCandidates.value = [];
    return;
  }
  const [linksResponse, contentsResponse] = await Promise.all([
    businessCatalogApi.productContentLinks(productId),
    businessCatalogApi.contentCandidates(),
  ]);
  productContentLinks.value = linksResponse.data.links || [];
  contentCandidates.value = contentsResponse.data.contents || [];
}

async function loadProductRelations(productId = selectedProductId.value): Promise<void> {
  if (productId < 1) {
    productRelations.value = [];
    productRelationRules.value = [];
    return;
  }
  const response = await businessCatalogApi.productRelations(productId);
  productRelations.value = response.data.relations || [];
  productRelationRules.value = response.data.rules || [];
}

async function createProductRelation(): Promise<void> {
  const targetId = Number(productRelationForm.target_product_id || 0);
  if (!canWrite.value || selectedProductId.value < 1 || targetId < 1) return;
  busy.value = 'product-relation';
  try {
    await businessCatalogApi.createProductRelation(selectedProductId.value, { target_product_id: targetId, relation_type: productRelationForm.relation_type, sort_order: Number(productRelationForm.sort_order || 0) });
    Object.assign(productRelationForm, { search: '', target_product_id: '', relation_type: 'related', sort_order: '0' });
    await loadProductRelations();
    setNotice('Relation produit enregistrée. La projection boutique sera reconstruite.');
  } catch (err) { setError(err, 'Relation produit non enregistrée.'); } finally { busy.value = ''; }
}

async function deleteProductRelation(relation: ProductRelation): Promise<void> {
  if (!canWrite.value || relation.source !== 'manual' || typeof relation.id !== 'number') return;
  busy.value = `product-relation-${relation.id}`;
  try { await businessCatalogApi.deleteProductRelation(relation.id); await loadProductRelations(); setNotice('Relation produit supprimée.'); }
  catch (err) { setError(err, 'Relation produit non supprimée.'); } finally { busy.value = ''; }
}

async function createProductRelationRule(): Promise<void> {
  const matchId = Number(productRelationRuleForm.match_id || 0);
  if (!canWrite.value || selectedProductId.value < 1 || matchId < 1) return;
  busy.value = 'product-relation-rule';
  try {
    await businessCatalogApi.createProductRelationRule(selectedProductId.value, { relation_type: productRelationRuleForm.relation_type, match_type: productRelationRuleForm.match_type, match_id: matchId, result_limit: Number(productRelationRuleForm.result_limit || 6), sort_order: Number(productRelationRuleForm.sort_order || 0) });
    productRelationRuleForm.match_id = '';
    await loadProductRelations();
    setNotice('Règle automatique enregistrée. Seuls les produits publics du même site seront projetés.');
  } catch (err) { setError(err, 'Règle automatique non enregistrée.'); } finally { busy.value = ''; }
}

async function deleteProductRelationRule(rule: ProductRelationRule): Promise<void> {
  if (!canWrite.value || !rule.id) return;
  busy.value = `product-relation-rule-${rule.id}`;
  try { await businessCatalogApi.deleteProductRelationRule(rule.id); await loadProductRelations(); setNotice('Règle automatique supprimée.'); }
  catch (err) { setError(err, 'Règle automatique non supprimée.'); } finally { busy.value = ''; }
}

function productRelationRuleTargetName(rule: ProductRelationRule): string {
  const rows = rule.match_type === 'group' ? attributeGroups.value : categories.value;
  return String(rows.find((row) => Number(row.id) === Number(rule.match_id))?.name || `#${rule.match_id}`);
}

async function createProductContentLink(): Promise<void> {
  if (!canWrite.value || selectedProductId.value < 1 || Number(contentLinkForm.content_entry_id) < 1) return;
  busy.value = 'content-link';
  try {
    await businessCatalogApi.createProductContentLink(selectedProductId.value, {
      content_entry_id: Number(contentLinkForm.content_entry_id),
      relation_type: contentLinkForm.relation_type,
      locale: contentLinkForm.locale || null,
      is_canonical: contentLinkForm.is_canonical,
      seo_config: { schema_type: contentLinkForm.schema_type },
    });
    Object.assign(contentLinkForm, { content_entry_id: '', relation_type: 'product_page', locale: '', is_canonical: false, schema_type: 'Product' });
    await loadProductContentLinks();
    setNotice('Contenu éditorial lié au produit.');
  } catch (err) {
    setError(err, 'Liaison éditoriale non enregistrée.');
  } finally {
    busy.value = '';
  }
}

async function deleteProductContentLink(link: ProductContentLink): Promise<void> {
  if (!canWrite.value || !link.id) return;
  busy.value = `content-link-${link.id}`;
  try {
    await businessCatalogApi.deleteProductContentLink(link.id);
    await loadProductContentLinks();
    setNotice('Liaison éditoriale supprimée.');
  } catch (err) {
    setError(err, 'Liaison éditoriale non supprimée.');
  } finally {
    busy.value = '';
  }
}

async function saveProduct(notify = true): Promise<boolean> {
  if (!canWrite.value) return false;
  busy.value = 'product';
  try {
    const id = productForm.id;
    const basePurchasePrice = productForm.base_purchase_price;
    const baseSalePrice = productForm.base_sale_price;
    const currency = productForm.currency || 'CHF';
    const response = id > 0
      ? await businessCatalogApi.updateProduct(id, productPayload())
      : await businessCatalogApi.createProduct(productPayload());
    selectedProduct.value = response.data.product;
    fillProductForm(response.data.product);
    if (id > 0 && canPriceWrite.value) {
      await businessCatalogApi.updateBasePrices(id, {
        base_purchase_price: canPurchaseRead.value ? optionalMoneyPayload(basePurchasePrice) : undefined,
        base_sale_price: optionalMoneyPayload(baseSalePrice),
        currency,
      });
      await selectProduct({ id, name: response.data.product?.data?.name || productForm.name });
    }
    await loadCatalog();
    if (notify) setNotice('Produit enregistré.');
    return true;
  } catch (err) {
    setError(err, 'Produit non enregistré.');
    return false;
  } finally {
    busy.value = '';
  }
}

async function saveProductModal(): Promise<void> {
  if (productModalScope.value === 'attributes' && productModalMode.value === 'edit' && variantAttributesOnlyModal.value) {
    await saveVariantAttributeValues(true);
    return;
  }
  if (productModalScope.value !== 'attributes' || productModalMode.value !== 'edit') {
    await saveProduct();
    return;
  }
  const productId = selectedProductId.value;
  const productValues = valuesPayloadFromForm(productAttributeForm);
  const productSaved = await saveProduct(false);
  if (!productSaved) return;
  try {
    busy.value = 'product-attributes';
    const productResponse = await businessCatalogApi.updateProductAttributes(productId, { values: productValues });
    productAttributeValues.value = productResponse.data.attributes || [];
    applyAttributeValues('product', productAttributeValues.value);
    await selectProduct({ id: productId, name: productForm.name });
    setNotice('Attributs produit enregistrés.');
  } catch (err) {
    setError(err, 'Attributs non enregistrés.');
  } finally {
    busy.value = '';
  }
}

function duplicateSkuBase(value: unknown): string {
  const base = normalizeSkuInput(value).slice(0, 64) || 'PRODUIT';
  return `${base}COPIE${String(Date.now()).slice(-6)}`.slice(0, 80);
}

async function duplicateProduct(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (!canWrite.value || productId < 1) return;
  closeCatalogMenus();
  busy.value = `duplicate-product-${productId}`;
  try {
    const response = await businessCatalogApi.product(productId);
    const detail = response.data.product;
    const data = detail.data || product;
    const prices = Array.isArray(detail.prices) ? detail.prices : [];
    const sale = prices.find((price) => price.price_kind === 'sale');
    const purchase = prices.find((price) => price.price_kind === 'purchase');
    const currency = text(sale?.currency || purchase?.currency || 'CHF');
    const payload: Record<string, unknown> = {
      name: `${text(data.name || product.name)} copie`,
      sku_base: duplicateSkuBase(data.sku_base || product.sku_base),
      slug: `${text(data.slug || product.slug || data.name || product.name)}-copie-${String(Date.now()).slice(-6)}`,
      type: text(data.type || product.type || 'physical'),
      status: 'draft',
      brand_id: idOrNull(data.brand_id || product.brand_id),
      category_id: idOrNull(data.category_id || product.category_id),
      short_description: text(data.short_description),
      description: text(data.description),
      is_public: false,
      is_ecommerce_enabled: Boolean(data.is_ecommerce_enabled ?? product.is_ecommerce_enabled),
      is_pos_enabled: Boolean(data.is_pos_enabled ?? product.is_pos_enabled),
      is_catalogue_enabled: data.is_catalogue_enabled !== false,
      option_ids: Array.isArray(detail.options) ? detail.options.map((option) => Number(option.id || 0)).filter(Boolean) : [],
      base_sale_price: sale?.amount ?? undefined,
      currency: currency || 'CHF',
    };
    if (canPurchaseRead.value && purchase?.amount !== undefined && purchase?.amount !== null) {
      payload.base_purchase_price = purchase.amount;
    }
    const created = await businessCatalogApi.createProduct(payload);
    await loadCatalog();
    if (created.data.product?.data) {
      await selectProduct(created.data.product.data as CatalogProduct);
    }
    setNotice('Produit dupliqué en brouillon.');
  } catch (err) {
    setError(err, 'Produit non dupliqué.');
  } finally {
    busy.value = '';
  }
}

async function archiveProduct(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (!canWrite.value || productId < 1 || productIsArchived(product)) return;
  closeCatalogMenus();
  if (typeof window !== 'undefined' && !window.confirm(`Archiver le produit « ${product.name || productId} » ?`)) return;
  busy.value = `archive-product-${productId}`;
  try {
    await businessCatalogApi.deleteProduct(productId);
    if (productId === selectedProductId.value) {
      resetProductForm(false);
    }
    await loadCatalog();
    setNotice('Produit archivé.');
  } catch (err) {
    setError(err, 'Produit non archivé.');
  } finally {
    busy.value = '';
  }
}

async function restoreProduct(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (!canWrite.value || productId < 1 || !productIsArchived(product)) return;
  closeCatalogMenus();
  busy.value = `restore-product-${productId}`;
  try {
    await businessCatalogApi.restoreProduct(productId);
    await loadCatalog();
    setNotice('Produit désarchivé en brouillon.');
  } catch (err) {
    setError(err, 'Produit non désarchivé.');
  } finally {
    busy.value = '';
  }
}

async function purgeProduct(product: CatalogProduct): Promise<void> {
  const productId = Number(product.id || 0);
  if (!canWrite.value || productId < 1 || !productIsArchived(product)) return;
  closeCatalogMenus();
  if (typeof window !== 'undefined' && !window.confirm(`Effacer définitivement le produit « ${product.name || productId} » ? Cette action est irréversible.`)) return;
  busy.value = `purge-product-${productId}`;
  try {
    await businessCatalogApi.purgeProduct(productId);
    if (productId === selectedProductId.value) {
      resetProductForm(false);
    }
    await loadCatalog();
    setNotice('Produit effacé définitivement.');
  } catch (err) {
    setError(err, 'Produit non effacé.');
  } finally {
    busy.value = '';
  }
}

async function createVariant(): Promise<void> {
  const productId = selectedProductId.value;
  if (!canWrite.value || productId < 1) return;
  busy.value = 'variant';
  try {
    const response = await businessCatalogApi.createVariant(productId, {
      sku: variantForm.sku,
      barcode: variantForm.barcode || undefined,
      name: variantForm.name || variantForm.sku,
      sales_note: variantForm.sales_note || undefined,
      status: variantForm.status,
      stock_quantity: Number(variantForm.stock_quantity || 0),
      track_stock: variantForm.track_stock,
      allow_backorder: variantForm.allow_backorder,
      backorder_delivery_days: variantForm.backorder_delivery_days === '' ? undefined : Number(variantForm.backorder_delivery_days || 0),
      purchase_adjustment_type: canPurchaseRead.value ? variantForm.purchase_adjustment_type : 'none',
      purchase_adjustment_value: canPurchaseRead.value ? positiveAdjustmentValue(variantForm.purchase_adjustment_value, variantForm.purchase_adjustment_type) : undefined,
      sale_adjustment_type: variantForm.sale_adjustment_type,
      sale_adjustment_value: positiveAdjustmentValue(variantForm.sale_adjustment_value, variantForm.sale_adjustment_type),
    });
    const createdVariantId = Number(response.data.variant?.id || 0);
    resetVariantForm();
    await loadCatalog();
    await selectProduct({ id: productId, name: productForm.name });
    const createdVariant = selectedVariants.value.find((variant) => Number(variant.id || 0) === createdVariantId) || response.data.variant;
    if (createdVariant) {
      await selectVariant(createdVariant);
    }
    if (variantEditModalOpen.value) {
      variantEditModalOpen.value = false;
    }
    setNotice('Variante créée.');
  } catch (err) {
    setError(err, 'Variante non créée.');
  } finally {
    busy.value = '';
  }
}

function applyUpdatedVariant(updated: CatalogVariant): void {
  selectedVariant.value = { ...selectedVariant.value, ...updated };
  const index = selectedVariants.value.findIndex((variant) => Number(variant.id || 0) === Number(updated.id || 0));
  if (index >= 0 && selectedProduct.value?.variants) {
    selectedProduct.value.variants[index] = { ...selectedProduct.value.variants[index], ...updated };
  }
  if (selectedVariant.value) {
    fillVariantFormFromVariant(selectedVariant.value);
  }
}

async function saveSelectedVariant(): Promise<void> {
  const variantId = Number(selectedVariant.value?.id || 0);
  if (!canWrite.value || variantId < 1) return;
  busy.value = `variant-${variantId}`;
  try {
    const payload: Record<string, unknown> = {
      sku: variantForm.sku,
      barcode: variantForm.barcode || null,
      name: variantForm.name || variantForm.sku,
      sales_note: variantForm.sales_note || null,
      status: variantForm.status,
      stock_quantity: Number(variantForm.stock_quantity || 0),
      track_stock: variantForm.track_stock,
      allow_backorder: variantForm.allow_backorder,
    };
    if (variantForm.backorder_delivery_days !== '') {
      payload.backorder_delivery_days = Number(variantForm.backorder_delivery_days || 0);
    }
    const response = await businessCatalogApi.updateVariant(variantId, payload);
    applyUpdatedVariant(response.data.variant);
    await Promise.all([loadVariantStock(response.data.variant), loadVariantAttributeValues(Number(response.data.variant.id || 0))]);
    setNotice('Variante enregistrée.');
  } catch (err) {
    setError(err, 'Variante non enregistrée.');
  } finally {
    busy.value = '';
  }
}

async function saveSelectedVariantSalesNote(): Promise<void> {
  const variantId = Number(selectedVariant.value?.id || 0);
  if (!canWrite.value || variantId < 1) return;
  busy.value = `variant-note-${variantId}`;
  try {
    const response = await businessCatalogApi.updateVariant(variantId, { sales_note: variantForm.sales_note || null });
    applyUpdatedVariant(response.data.variant);
    setNotice('Commentaire variante enregistré.');
  } catch (err) {
    setError(err, 'Commentaire variante non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function selectVariant(variant: CatalogVariant): Promise<void> {
  selectedVariant.value = variant;
  fillVariantFormFromVariant(variant);
  await Promise.all([loadVariantStock(variant), loadVariantAttributeValues(Number(variant.id || 0))]);
}

async function selectVariantById(value: IdValue): Promise<void> {
  const variant = selectedVariants.value.find((item) => Number(item.id || 0) === Number(value || 0));
  if (!variant) {
    selectedVariant.value = null;
    variantAttributeValues.value = [];
    applyAttributeValues('variant', []);
    return;
  }
  await selectVariant(variant);
}

async function loadVariantStock(variant: CatalogVariant): Promise<void> {
  if (!variant.id) return;
  try {
    const response = await businessCatalogApi.stock(Number(variant.id));
    variantStock.value = response.data.stock;
    stockLocations.value = response.data.locations || [];
    stockByLocation.value = response.data.location_stock || [];
    stockMovements.value = response.data.movements || [];
    if (!stockForm.stock_location_id && stockLocations.value.length) stockForm.stock_location_id = String(stockLocations.value[0].id || '');
  } catch (_) {
    variantStock.value = null;
    stockLocations.value = [];
    stockByLocation.value = [];
    stockMovements.value = [];
  }
}

function positiveAdjustmentValue(value: unknown, type: unknown): number | undefined {
  if (String(type || 'none') === 'none') return undefined;
  if (value === null || value === undefined || String(value).trim() === '') return undefined;
  const amount = Number(value);
  if (!Number.isFinite(amount)) return undefined;
  return Math.abs(amount);
}

async function saveVariantAdjustments(variant: CatalogVariant): Promise<void> {
  if (!canPriceWrite.value || !variant.id) return;
  busy.value = `variant-${variant.id}`;
  try {
    await businessCatalogApi.updateVariantPriceAdjustments(Number(variant.id), {
      sale_adjustment_type: variant.sale_adjustment_type || 'none',
      sale_adjustment_value: positiveAdjustmentValue(variant.sale_adjustment_value, variant.sale_adjustment_type),
      purchase_adjustment_type: canPurchaseRead.value ? variant.purchase_adjustment_type || 'none' : undefined,
      purchase_adjustment_value: canPurchaseRead.value ? positiveAdjustmentValue(variant.purchase_adjustment_value, variant.purchase_adjustment_type) : undefined,
    });
    await selectProduct({ id: selectedProductId.value, name: productForm.name });
    setNotice('Ajustements enregistrés.');
  } catch (err) {
    setError(err, 'Ajustements non enregistrés.');
  } finally {
    busy.value = '';
  }
}

async function saveSelectedVariantAdjustments(): Promise<void> {
  if (!selectedVariant.value) return;
  await saveVariantAdjustments({
    ...selectedVariant.value,
    purchase_adjustment_type: variantForm.purchase_adjustment_type,
    purchase_adjustment_value: variantForm.purchase_adjustment_value,
    sale_adjustment_type: variantForm.sale_adjustment_type,
    sale_adjustment_value: variantForm.sale_adjustment_value,
  });
}

function stockMovementPayload(preview: boolean): Record<string, unknown> {
  return {
    action: stockForm.action,
    quantity: Number(stockForm.quantity || 0),
    reason: stockForm.reason,
    stock_location_id: Number(stockForm.stock_location_id || 0),
    preview,
    idempotency_key: preview ? undefined : `operations-stock-${selectedVariant.value?.id}-${Date.now()}`,
  };
}

async function previewStockMovement(): Promise<void> {
  if (!canStockWrite.value || !selectedVariant.value?.id) return;
  busy.value = 'stock-preview';
  try {
    const response = await businessCatalogApi.createStockMovement(Number(selectedVariant.value.id), stockMovementPayload(true));
    stockPreview.value = response.data.preview || null;
  } catch (err) {
    stockPreview.value = null;
    setError(err, 'Impact du mouvement non calculé.');
  } finally {
    busy.value = '';
  }
}

async function createStockMovement(): Promise<void> {
  if (!canStockWrite.value || !selectedVariant.value?.id || !stockPreview.value) return;
  busy.value = 'stock';
  try {
    await businessCatalogApi.createStockMovement(Number(selectedVariant.value.id), stockMovementPayload(false));
    await loadVariantStock(selectedVariant.value);
    await selectProduct({ id: selectedProductId.value, name: productForm.name });
    stockPreview.value = null;
    stockForm.reason = '';
    inventoryAdjustmentOpen.value = false;
    setNotice('Mouvement audité enregistré dans le ledger Vente.');
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
    const payload = discountPayload();
    if (!offerPreview.value) throw new Error('offer.preview_required');
    if (discountForm.status === 'active' && offerPreview.value.activation_allowed === false) throw new Error('offer.conflict_acknowledgement_required');
    if (discountForm.id > 0) {
      await businessCatalogApi.updateDiscount(discountForm.id, payload);
    } else {
      await businessCatalogApi.createDiscount(payload);
    }
    fillDiscountForm();
    await loadCatalog();
    if (selectedProductId.value > 0) await selectProduct({ id: selectedProductId.value, name: productForm.name });
    setNotice('Offre enregistrée après prévisualisation.');
  } catch (err) {
    setError(err, 'Offre non enregistrée. Vérifiez l’aperçu et les conflits.');
  } finally {
    busy.value = '';
  }
}

function discountPayload(): Record<string, unknown> {
  return {
      id: discountForm.id || undefined,
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
      customer_segment: discountForm.customer_segment || undefined,
      conflict_acknowledged: offerConflictAcknowledged.value,
    };
}

async function previewDiscount(): Promise<void> {
  if (!canDiscountWrite.value) return;
  busy.value = 'discount-preview';
  try {
    const response = await businessCatalogApi.previewDiscount(discountPayload());
    offerPreview.value = response.data.preview;
    offerWizardStep.value = 7;
  } catch (err) {
    offerPreview.value = null;
    setError(err, 'Aperçu de l’offre impossible.');
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

async function previewBulkProductUpdate(): Promise<void> {
  if (!canWrite.value || selectedProductsCount.value < 1 || !bulkHasProductChanges.value) return;
  busy.value = 'bulk-preview';
  try {
    const response = await businessCatalogApi.bulkUpdateProducts({
      product_ids: selectedProductIds.value,
      changes: bulkProductChanges.value,
      dry_run: true,
    });
    bulkReport.value = response.data.bulk;
    setNotice(`${selectedProductsCount.value} produit(s) prêt(s) pour modification en masse.`);
  } catch (err) {
    setError(err, 'Prévisualisation en masse impossible.');
  } finally {
    busy.value = '';
  }
}

async function applyBulkProductUpdate(): Promise<void> {
  if (!canWrite.value || selectedProductsCount.value < 1 || !bulkHasProductChanges.value) return;
  busy.value = 'bulk-apply';
  try {
    const response = await businessCatalogApi.bulkUpdateProducts({
      product_ids: selectedProductIds.value,
      changes: bulkProductChanges.value,
      dry_run: false,
    });
    bulkReport.value = response.data.bulk;
    await loadCatalog();
    setNotice(`${Number(response.data.bulk.updated || 0)} produit(s) modifié(s).`);
  } catch (err) {
    setError(err, 'Modification en masse impossible.');
  } finally {
    busy.value = '';
  }
}

async function recalculateSelectedProductCompleteness(): Promise<void> {
  if (!canWrite.value || selectedProductsCount.value < 1) return;
  busy.value = 'bulk-recalculate';
  try {
    const response = await businessCatalogApi.bulkRecalculateProducts({ product_ids: selectedProductIds.value });
    bulkReport.value = response.data.bulk;
    await loadCatalog();
    setNotice(`${Number(response.data.bulk.recalculated || 0)} complétude(s) recalculée(s).`);
  } catch (err) {
    setError(err, 'Recalcul en masse impossible.');
  } finally {
    busy.value = '';
  }
}

function selectedOfferPayload(): Array<{ kind: OfferKind; id: number }> {
  return selectedOfferRows.value.map((row) => ({ kind: row.kind, id: row.id }));
}

async function previewBulkOfferUpdate(): Promise<void> {
  if (!canBulkOffersWrite.value || selectedOffersCount.value < 1 || !bulkHasOfferChanges.value) return;
  busy.value = 'bulk-offer-preview';
  try {
    const response = await businessCatalogApi.bulkUpdateOffers({
      offers: selectedOfferPayload(),
      changes: bulkOfferChanges.value,
      dry_run: true,
    });
    offerBulkReport.value = response.data.bulk;
    setNotice(`${selectedOffersCount.value} offre(s) prête(s) pour modification en masse.`);
  } catch (err) {
    setError(err, 'Prévisualisation offres impossible.');
  } finally {
    busy.value = '';
  }
}

async function applyBulkOfferUpdate(): Promise<void> {
  if (!canBulkOffersWrite.value || selectedOffersCount.value < 1 || !bulkHasOfferChanges.value) return;
  busy.value = 'bulk-offer-apply';
  try {
    const response = await businessCatalogApi.bulkUpdateOffers({
      offers: selectedOfferPayload(),
      changes: bulkOfferChanges.value,
      dry_run: false,
    });
    offerBulkReport.value = response.data.bulk;
    await loadCatalog();
    setNotice(`${Number(response.data.bulk.updated || 0)} offre(s) modifiée(s).`);
  } catch (err) {
    setError(err, 'Modification en masse des offres impossible.');
  } finally {
    busy.value = '';
  }
}

function offerImportPayload(): Record<string, unknown> {
  return { csv: offerImportCsvText.value };
}

async function previewOffersImport(): Promise<void> {
  if (!canWrite.value || !offerImportCsvText.value.trim()) return;
  busy.value = 'offers-csv-preview';
  try {
    const response = await businessCatalogApi.previewOffersImport(offerImportPayload());
    offerImportReport.value = response.data.import;
    setNotice(offerImportHasErrors.value ? 'Prévisualisation offres terminée avec erreurs.' : 'Prévisualisation offres valide.');
  } catch (err) {
    setError(err, 'Prévisualisation CSV offres impossible.');
  } finally {
    busy.value = '';
  }
}

async function applyOffersImport(): Promise<void> {
  if (!canWrite.value || !offerImportCsvText.value.trim() || offerImportHasErrors.value) return;
  busy.value = 'offers-csv-apply';
  try {
    const response = await businessCatalogApi.applyOffersImport(offerImportPayload());
    offerImportReport.value = response.data.import;
    await loadCatalog();
    setNotice('Import CSV offres appliqué.');
  } catch (err) {
    setError(err, 'Import CSV offres impossible.');
  } finally {
    busy.value = '';
  }
}

watch(() => props.fixedTab, (tab) => {
  if (tab && activeTab.value !== tab) {
    activeTab.value = tab;
  }
});
watch(() => props.initialProductId, (id) => {
  if (Number(id || 0) > 0 && Number(selectedProduct.value?.id || 0) !== Number(id)) {
    void openViewProduct({ id: Number(id), name: '' });
  }
});

watch(() => context.siteId, () => { void loadCatalog(); });
watch(() => productForm.attribute_group_ids.slice(), () => pruneAttributeForms());
watch(() => [offerFilter.q, appliedOfferFilters.type, appliedOfferFilters.status, appliedOfferFilters.channel], () => { offerPage.value = 1; });
watch(offerPageCount, (count) => {
  if (offerPage.value > count) offerPage.value = count;
});

onMounted(async () => {
  loadReferenceLabels();
  if (typeof route.query.view === 'string') productFilter.view = appliedProductFilters.view = route.query.view;
  if (route.query.status && activeTab.value === 'offers') offerFilter.status = appliedOfferFilters.status = String(route.query.status);
  document.addEventListener('pointerdown', onDocumentPointerDown);
  await Promise.all([loadCatalog(), loadMediaRows(), loadAttributeDefinitions()]);
  if (Number(props.initialProductId || 0) > 0) {
    await openViewProduct({ id: Number(props.initialProductId), name: '' });
  }
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown);
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
      </div>

      <div v-if="activeTab === 'products'" class="catalog-products-shell">
        <section class="catalog-products-panel">
          <BusinessPageHeader eyebrow="Catalogue" title="Produits">
            <template #actions>
              <button class="btn primary small" type="button" :disabled="!canWrite" @click="openCreateProduct">Nouveau produit</button>
            </template>
          </BusinessPageHeader>

          <form class="catalog-toolbar" @submit.prevent="applyProductFilters">
            <div class="catalog-search-control">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
              <input v-model="productFilter.q" type="search" aria-label="Recherche produit, marque, catégorie, SKU" placeholder="Recherche produit, marque, catégorie, SKU" @keyup.enter="applyProductFilters">
              <button v-if="productFilter.q" type="button" aria-label="Effacer la recherche" @click="productFilter.q = ''; applyProductFilters()">×</button>
            </div>
            <div class="catalog-toolbar-buttons">
              <details class="catalog-menu catalog-menu--filters">
                <summary class="catalog-icon-summary" aria-label="Filtres produits" title="Filtres">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16l-6 7v5l-4 2v-7L4 6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </summary>
                <div class="catalog-menu-panel catalog-filter-panel">
                  <strong>Filtres</strong>
                  <label>
                    <select v-model="productFilter.type" aria-label="Type">
                      <option value="">Tous types</option>
                      <option v-for="type in productTypes" :key="type" :value="type">{{ productTypeLabels[type] || type }}</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.status" aria-label="Statut">
                      <option value="">Tous statuts</option>
                      <option v-for="status in statuses" :key="status" :value="status">{{ productStatusLabels[status] || status }}</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.brand_id" aria-label="Marque">
                      <option value="">Toutes marques</option>
                      <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.category_id" aria-label="Catégorie">
                      <option value="">Toutes catégories</option>
                      <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.channel" aria-label="Canal">
                      <option value="">Tous canaux</option>
                      <option value="public">Public</option>
                      <option value="ecommerce">E-commerce</option>
                      <option value="pos">POS</option>
                      <option value="catalogue">Brochure</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.archived" aria-label="Archivage">
                      <option value="">Masquer les archivés</option>
                      <option value="1">Inclure les archivés</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.image" aria-label="Images">
                      <option value="">Toutes images</option>
                      <option value="with">Avec image</option>
                      <option value="without">Sans image</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="productFilter.price" aria-label="Prix de vente">
                      <option value="">Tous prix</option>
                      <option value="with">Avec prix</option>
                      <option value="without">Sans prix</option>
                    </select>
                  </label>
                  <label><input v-model="productFilter.low_stock" type="checkbox"> Stock faible</label>
                  <label><input v-model="productFilter.purchase_price" type="checkbox"> Prix d’achat manquant</label>
                  <div class="catalog-filter-actions">
                    <button class="btn small" type="submit" :disabled="loading">Appliquer</button>
                    <button class="btn ghost small" type="button" :disabled="loading" @click="resetProductFilters">Réinitialiser</button>
                  </div>
                </div>
              </details>
              <details class="catalog-menu catalog-menu--columns">
                <summary class="catalog-icon-summary" aria-label="Colonnes produits" title="Colonnes">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4V5Zm5 0v14m6-14v14" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                </summary>
                <div class="catalog-menu-panel catalog-column-panel">
                  <strong>Colonnes</strong>
                  <label v-for="column in productColumns" :key="column.key"><input v-model="visibleProductColumns[column.key]" type="checkbox"> {{ column.label }}</label>
                </div>
              </details>
              <details class="catalog-menu catalog-menu--exports">
                <summary class="catalog-icon-summary" aria-label="Import / Export produits" title="Import / Export">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left-right" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M1 11.5a.5.5 0 0 0 .5.5h11.793l-3.147 3.146a.5.5 0 0 0 .708.708l4-4a.5.5 0 0 0 0-.708l-4-4a.5.5 0 0 0-.708.708L13.293 11H1.5a.5.5 0 0 0-.5.5m14-7a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 1 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 4H14.5a.5.5 0 0 1 .5.5"/></svg>
                </summary>
                <div class="catalog-menu-panel">
                  <a :href="exportIncompleteCsvUrl">Exporter les incomplets</a>
                  <a :href="exportPosCsvUrl">Exporter POS</a>
                  <a :href="exportEcommerceCsvUrl">Exporter e-commerce</a>
                  <a :href="exportCatalogueCsvUrl">Exporter catalogue</a>
                  <a :href="exportCataloguePdfUrl">Exporter brochure</a>
                  <button type="button" :disabled="!canWrite" @click="showImportPanel = !showImportPanel; closeCatalogMenus()">Importer CSV</button>
                </div>
              </details>
              <details class="catalog-menu catalog-menu--actions">
                <summary aria-label="Actions produits" title="Actions">...</summary>
                <div class="catalog-menu-panel">
                  <button type="button" :disabled="!canWrite" @click="openProductReference('type')">Type</button>
                  <button type="button" :disabled="!canWrite" @click="openProductReference('status')">Statut</button>
                  <button type="button" :disabled="!canWrite" @click="openProductReference('brand')">Marque</button>
                  <button type="button" :disabled="!canWrite" @click="openProductReference('category')">Catégorie</button>
                  <button type="button" :disabled="!canWrite" @click="openProductReference('tax')">TVA</button>
                  <button type="button" :disabled="!canWrite" @click="openAttributeSettings('groups')">Groupes</button>
                  <button type="button" :disabled="!canWrite" @click="openAttributeSettings('attributes')">Attributs</button>
                  <button type="button" :disabled="!canWrite" @click="openAttributeSettings('options')">Options</button>
                </div>
              </details>
            </div>
          </form>

          <nav class="catalog-quick-filters" aria-label="Filtres rapides produits">
            <button
              v-for="filter in quickProductFilters"
              :key="filter.key"
              type="button"
              :class="{ active: quickProductFilterActive(filter.key) }"
              @click="applyQuickProductFilter(filter.key)"
            >
              {{ filter.label }}
            </button>
          </nav>

          <div v-if="activeProductFilterChips.length" class="catalog-active-filters" aria-label="Filtres actifs">
            <button
              v-for="chip in activeProductFilterChips"
              :key="chip.key"
              type="button"
              class="catalog-filter-chip"
              :aria-label="`Retirer le filtre ${chip.label}`"
              @click="removeProductFilterChip(chip.key)"
            >
              <span aria-hidden="true">×</span>
              <strong>{{ chip.label }}</strong>
            </button>
          </div>

          <div v-if="showImportPanel" class="catalog-csv-panel">
            <div class="toolbar">
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

          <div v-if="selectedProductsCount > 0" class="catalog-bulk-panel">
            <div class="catalog-bulk-panel__summary">
              <strong>{{ selectedProductsCount }} produit(s) sélectionné(s)</strong>
              <button class="btn ghost btn-sm" type="button" @click="clearBulkSelection">Cacher</button>
            </div>
            <div class="catalog-bulk-grid">
              <label>Statut
                <select v-model="bulkProductForm.status" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="draft">Brouillon</option>
                  <option value="active">Actif</option>
                </select>
              </label>
              <label>Marque
                <select v-model="bulkProductForm.brand_id" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="0">Aucune marque</option>
                  <option v-for="brand in brands" :key="Number(brand.id)" :value="String(brand.id)">{{ brand.name }}</option>
                </select>
              </label>
              <label>Catégorie
                <select v-model="bulkProductForm.category_id" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="0">Aucune catégorie</option>
                  <option v-for="category in categories" :key="Number(category.id)" :value="String(category.id)">{{ category.name }}</option>
                </select>
              </label>
              <label>TVA
                <select v-model="bulkProductForm.tax_class_id" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="0">Aucune TVA</option>
                  <option v-for="taxClass in taxClasses" :key="Number(taxClass.id)" :value="String(taxClass.id)">{{ taxClassLabel(taxClass.id) }}</option>
                </select>
              </label>
              <label>Public
                <select v-model="bulkProductForm.is_public" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="1">Activer</option>
                  <option value="0">Désactiver</option>
                </select>
              </label>
              <label>E-commerce
                <select v-model="bulkProductForm.is_ecommerce_enabled" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="1">Activer</option>
                  <option value="0">Désactiver</option>
                </select>
              </label>
              <label>POS
                <select v-model="bulkProductForm.is_pos_enabled" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="1">Activer</option>
                  <option value="0">Désactiver</option>
                </select>
              </label>
              <label>Brochure
                <select v-model="bulkProductForm.is_catalogue_enabled" class="select" :disabled="!canWrite">
                  <option value="">Conserver</option>
                  <option value="1">Activer</option>
                  <option value="0">Désactiver</option>
                </select>
              </label>
              <label class="checkbox-inline catalog-bulk-checkbox"><input v-model="bulkProductForm.archive" type="checkbox" :disabled="!canWrite"> Archiver</label>
            </div>
            <div class="toolbar">
              <button class="btn ghost" type="button" :disabled="!canWrite || !bulkHasProductChanges || busy === 'bulk-preview'" @click="previewBulkProductUpdate">Prévisualiser</button>
              <button class="btn primary" type="button" :disabled="!canWrite || !bulkHasProductChanges || busy === 'bulk-apply'" @click="applyBulkProductUpdate">Appliquer</button>
              <button class="btn ghost" type="button" :disabled="!canWrite || busy === 'bulk-recalculate'" @click="recalculateSelectedProductCompleteness">Recalculer complétude</button>
              <button class="btn ghost" type="button" @click="resetBulkProductForm">Réinitialiser</button>
            </div>
            <small v-if="bulkReport" class="muted">
              Dry-run: {{ bulkReport.dry_run ? 'oui' : 'non' }} · Modifiés: {{ bulkReport.updated ?? 0 }} · Recalculés: {{ bulkReport.recalculated ?? 0 }}
            </small>
          </div>

          <div class="catalog-products-table table-wrap">
            <table class="table catalog-table">
              <thead>
                <tr>
                  <th class="catalog-select-col">
                    <input
                      type="checkbox"
                      :checked="allVisibleProductsSelected"
                      :indeterminate.prop="someVisibleProductsSelected && !allVisibleProductsSelected"
                      aria-label="Sélectionner les produits visibles"
                      @change="toggleVisibleProductSelection(checkedFromEvent($event))"
                    >
                  </th>
                  <th v-if="productColumnVisible('sku')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('sku')" @click="setProductSort('sku')">SKU<span :class="{ active: productSort.key === 'sku' }">{{ productSort.key === 'sku' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('product')" @click="setProductSort('product')">Produit<span :class="{ active: productSort.key === 'product' }">{{ productSort.key === 'product' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('brand')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('brand')" @click="setProductSort('brand')">Marque / Catégorie<span :class="{ active: productSort.key === 'brand' }">{{ productSort.key === 'brand' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('variants')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('variants')" @click="setProductSort('variants')">Variantes<span :class="{ active: productSort.key === 'variants' }">{{ productSort.key === 'variants' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('stock')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('stock')" @click="setProductSort('stock')">Stock<span :class="{ active: productSort.key === 'stock' }">{{ productSort.key === 'stock' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('price')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('price')" @click="setProductSort('price')">Vente / Achat<span :class="{ active: productSort.key === 'price' }">{{ productSort.key === 'price' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('image')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('image')" @click="setProductSort('image')">Image<span :class="{ active: productSort.key === 'image' }">{{ productSort.key === 'image' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('channels')" class="catalog-channel-col"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('channels')" @click="setProductSort('channels')">Canaux<span :class="{ active: productSort.key === 'channels' }">{{ productSort.key === 'channels' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('status')" class="catalog-status-col"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('status')" @click="setProductSort('status')">Statut<span :class="{ active: productSort.key === 'status' }">{{ productSort.key === 'status' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th v-if="productColumnVisible('quality')"><button class="catalog-sort-button" type="button" :aria-label="productSortLabel('quality')" @click="setProductSort('quality')">Complétude<span :class="{ active: productSort.key === 'quality' }">{{ productSort.key === 'quality' && productSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="product in sortedProducts"
                  :key="product.id"
                  class="catalog-table-row"
                  :class="{ active: product.id === selectedProductId, 'is-archived': productIsArchived(product) }"
                  @click="!productIsArchived(product) && selectProduct(product)"
                >
                  <td class="catalog-select-col" @click.stop>
                    <input
                      type="checkbox"
                      :checked="productSelected(product)"
                      :aria-label="`Sélectionner ${product.name}`"
                      @change="toggleProductSelection(product, checkedFromEvent($event))"
                    >
                  </td>
                  <td v-if="productColumnVisible('sku')" class="catalog-sku-cell" @click.stop="!productIsArchived(product) && openEditProduct(product, 'identity', 'sku')">
                    <code>{{ product.sku_base || '—' }}</code>
                  </td>
                  <td class="catalog-product-cell">
                    <button v-if="!productIsArchived(product)" class="catalog-product-name" type="button" @click.stop="openEditProduct(product, 'identity', 'name_type')">{{ product.name }}</button>
                    <strong v-else class="catalog-product-name catalog-product-name--static">{{ product.name }}</strong>
                    <small>{{ product.type || 'produit' }}</small>
                  </td>
                  <td v-if="productColumnVisible('brand')" @click.stop="!productIsArchived(product) && openEditProduct(product, 'classification')">
                    <span>{{ brandName(product.brand_id) }}</span>
                    <small>{{ categoryName(product.category_id) }}</small>
                  </td>
                  <td v-if="productColumnVisible('variants')" class="catalog-variant-cell" @click.stop>
                    <div class="catalog-variant-trigger">
                      <button class="catalog-variant-count" type="button" :disabled="productIsArchived(product)" @click="selectProduct(product)">{{ variantActivityLabel(product) }}</button>
                      <button class="catalog-icon-button catalog-icon-button--muted" type="button" :disabled="productIsArchived(product) || !canWrite" :aria-label="`Ajouter une variante à ${product.name}`" title="Ajouter une variante" @click="openNewVariantForProduct(product)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-plus-square" viewBox="0 0 16 16" aria-hidden="true"><path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4"/></svg>
                      </button>
                      <button class="catalog-variant-toggle" type="button" :disabled="productIsArchived(product) || numberValue(product.variant_count) < 1" :aria-label="`Afficher les variantes de ${product.name}`" title="Afficher les variantes" @click="toggleProductVariantMenu(product)">
                        <svg v-if="variantMenuProductId === Number(product.id || 0)" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-down" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708"/></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-right" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708"/></svg>
                      </button>
                    </div>
                    <div v-if="variantMenuProductId === Number(product.id || 0)" class="catalog-variant-menu-panel">
                      <div v-for="variant in productVariantMenuRows(product)" :key="`row-variant-${variant.id}`" class="catalog-variant-menu-row">
                        <div class="catalog-variant-menu-main">
                          <strong :class="{ 'is-archived': variantIsArchived(variant), 'is-inactive': variantIsInactive(variant) }">{{ variantDisplayName(variant) }}</strong>
                          <small v-if="variantCompactAttributeSummary(variant)">{{ variantCompactAttributeSummary(variant) }}</small>
                        </div>
                        <div class="catalog-variant-menu-actions">
                          <button class="catalog-icon-button" type="button" :aria-label="`Modifier les textes de ${variantDisplayName(variant)}`" title="Données texte" @click="openVariantEditModal(product, variant)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-card-text" viewBox="0 0 16 16" aria-hidden="true"><path d="M14.5 3a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5zm-13-1A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2z"/><path d="M3 5.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3 8a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 8m0 2.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5"/></svg>
                          </button>
                          <button class="catalog-icon-button" type="button" :aria-label="`Modifier les attributs de ${variantDisplayName(variant)}`" title="Attributs variante" @click="openVariantAttributesModal(product, variant)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-beaker" viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 1.5A.5.5 0 0 1 5 1h6a.5.5 0 0 1 0 1h-.5v4.2l3.18 5.724A2 2 0 0 1 11.93 15H4.07a2 2 0 0 1-1.75-3.076L5.5 6.2V2H5a.5.5 0 0 1-.5-.5M6.5 6.46 3.195 12.41A1 1 0 0 0 4.07 14h7.86a1 1 0 0 0 .875-1.59L9.5 6.46V2h-3z"/><path d="M5.33 10 4.07 12.27A.5.5 0 0 0 4.51 13h6.98a.5.5 0 0 0 .44-.73L10.67 10z"/></svg>
                          </button>
                          <button class="catalog-icon-button catalog-icon-button--danger" type="button" :disabled="!canWrite || busy === `delete-variant-${variant.id}`" :aria-label="`Effacer ${variantDisplayName(variant)}`" title="Effacer variante" @click="deleteVariantFromProduct(product, variant)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash2" viewBox="0 0 16 16" aria-hidden="true"><path d="M6.5 1h3a1 1 0 0 1 1 1v1H14a.5.5 0 0 1 0 1h-.55l-.78 9.34A2 2 0 0 1 10.68 15H5.32a2 2 0 0 1-1.99-1.66L2.55 4H2a.5.5 0 0 1 0-1h3.5V2a1 1 0 0 1 1-1m0 2h3V2h-3zM3.55 4l.77 9.17a1 1 0 0 0 1 .83h5.36a1 1 0 0 0 1-.83L12.45 4z"/><path d="M6.5 6.5a.5.5 0 0 1 .5.5v5a.5.5 0 0 1-1 0V7a.5.5 0 0 1 .5-.5m3 0a.5.5 0 0 1 .5.5v5a.5.5 0 0 1-1 0V7a.5.5 0 0 1 .5-.5"/></svg>
                          </button>
                        </div>
                      </div>
                      <p v-if="productVariantMenuRows(product).length === 0" class="muted">Aucune variante.</p>
                    </div>
                  </td>
                  <td v-if="productColumnVisible('stock')" class="catalog-stock-cell" @click.stop="!productIsArchived(product) && openEditProduct(product, 'stock')">
                    <div class="catalog-stock-display">
                      <button
                        class="catalog-stock-indicator"
                        type="button"
                        :class="`catalog-stock-indicator--${stockIndicatorTone(product)}`"
                        :disabled="productIsArchived(product) || numberValue(product.variant_count) <= 1"
                        :aria-label="stockIndicatorLabel(product)"
                        :title="stockIndicatorLabel(product)"
                        @click.stop="toggleProductStockMenu(product)"
                      ></button>
                      <span>{{ stockSummaryLabel(product) }}</span>
                    </div>
                    <div v-if="stockMenuProductId === Number(product.id || 0)" class="catalog-stock-menu-panel" @click.stop>
                      <div v-for="variant in productVariantMenuRows(product)" :key="`stock-variant-${variant.id}`" class="catalog-stock-menu-row">
                        <span class="catalog-stock-dot" :class="`catalog-stock-dot--${variantStockTone(product, variant)}`"></span>
                        <div>
                          <strong>{{ variantDisplayName(variant) }}</strong>
                          <small>{{ variantStockLabel(product, variant) }}</small>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td v-if="productColumnVisible('price')" @click.stop="!productIsArchived(product) && openEditProduct(product, 'prices')">
                    <div v-if="productHasAnyPrice(product)" class="catalog-cell-inline">
                      <span>{{ pricePairLabel(product) }}</span>
                    </div>
                    <div v-if="!hasProductSalePrice(product)" class="catalog-cell-inline">
                      <span class="catalog-signal catalog-signal--danger">Prix manquant</span>
                    </div>
                  </td>
                  <td v-if="productColumnVisible('image')" @click.stop="!productIsArchived(product) && openProductMedia(product)">
                    <div class="catalog-cell-inline">
                      <span class="catalog-signal" :class="hasProductImage(product) ? 'catalog-signal--success' : 'catalog-signal--warning'">
                        {{ hasProductImage(product) ? mediaCountLabel(product) : 'Image manquante' }}
                      </span>
                    </div>
                  </td>
                  <td v-if="productColumnVisible('channels')" class="catalog-channel-col" @click.stop="!productIsArchived(product) && openEditProduct(product, 'channels')">
                    <div class="catalog-cell-inline catalog-cell-inline--wrap">
                      <span v-for="(signal, index) in channelReadinessSignals(product)" :key="`${product.id}-channel-${signal.label}-${index}`" class="catalog-signal" :class="`catalog-signal--${signal.tone}`">{{ signal.label }}</span>
                    </div>
                  </td>
                  <td v-if="productColumnVisible('status')" class="catalog-status-col" @click.stop="!productIsArchived(product) && openEditProduct(product, 'classification', 'status')">
                    <div class="catalog-cell-inline">
                      <span class="badge" :class="product.status || 'draft'">{{ productStatusLabel(product.status) }}</span>
                    </div>
                  </td>
                  <td v-if="productColumnVisible('quality')" @click.stop="!productIsArchived(product) && openProductAttributes(product)">
                    <div class="catalog-cell-inline">
                      <span class="catalog-completeness" :class="{ 'is-low': Number(product.completeness_score ?? 0) < 80 }">{{ completenessLabel(product) }}</span>
                      <span v-for="signal in qualitySignals(product)" :key="`${product.id}-quality-${signal.label}`" class="catalog-signal" :class="`catalog-signal--${signal.tone}`">{{ signal.label }}</span>
                    </div>
                  </td>
                  <td class="action-cell catalog-actions-cell">
                    <button class="btn ghost btn-sm" type="button" @click.stop="openViewProduct(product)">Voir</button>
                    <details class="catalog-menu" @click.stop>
                      <summary class="catalog-row-menu-summary" :aria-label="`Actions pour ${product.name}`">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                      </summary>
                      <div class="catalog-menu-panel">
                        <template v-if="productIsArchived(product)">
                          <button type="button" :disabled="!canWrite || busy === `restore-product-${product.id}`" @click.stop="restoreProduct(product)">Désarchiver</button>
                          <button type="button" class="danger-action" :disabled="!canWrite || busy === `purge-product-${product.id}`" @click.stop="purgeProduct(product)">Effacer définitivement</button>
                        </template>
                        <template v-else>
                          <button type="button" :disabled="!canWrite" @click.stop="openEditProduct(product)">Éditer</button>
                          <button type="button" :disabled="!canWrite" @click.stop="openProductMedia(product)">Médias</button>
                          <button type="button" :disabled="!canWrite" @click.stop="openProductAttributes(product)">Attributs du produit</button>
                          <button type="button" :disabled="!canWrite" @click.stop="openProductTax(product)">Taux TVA</button>
                          <button type="button" :disabled="!canPriceWrite" @click.stop="openVariantPriceAdjustments(product)">Ajustements prix</button>
                          <button type="button" :disabled="!canWrite || busy === `duplicate-product-${product.id}`" @click.stop="duplicateProduct(product)">Dupliquer</button>
                          <button type="button" :disabled="!canWrite || busy === `archive-product-${product.id}`" @click.stop="archiveProduct(product)">Archiver</button>
                        </template>
                      </div>
                    </details>
                  </td>
                </tr>
                <tr v-if="sortedProducts.length === 0">
                  <td colspan="11" class="muted">Aucun produit ne correspond aux filtres.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="catalog-pagination" aria-label="Pagination produits">
            <label>
              Lignes
              <select class="select" :value="productPageSize" @change="onProductPageSizeChange">
                <option v-for="size in productPageSizeOptions" :key="String(size)" :value="size">{{ productPageSizeLabel(size) }}</option>
              </select>
            </label>
            <span>{{ productPaginationLabel }}</span>
            <div class="catalog-pagination-actions">
              <button class="btn ghost btn-sm" type="button" :disabled="productPage <= 1 || loading || productPageSize === 'all'" @click="setProductPage(productPage - 1)">Précédent</button>
              <strong>Page {{ productPage }} / {{ productPageCount }}</strong>
              <button class="btn ghost btn-sm" type="button" :disabled="productPage >= productPageCount || loading || productPageSize === 'all'" @click="setProductPage(productPage + 1)">Suivant</button>
            </div>
          </div>
        </section>

        <!--
        <main class="catalog-detail">
          <div class="card">
            <div class="panel__header">
              <div>
                <p class="eyebrow">Produit</p>
                <h2>{{ productData?.name || 'Sélectionnez un produit' }}</h2>
              </div>
              <button class="btn primary" type="button" :disabled="!canWrite || !selectedProduct" @click="openEditProduct()">Modifier</button>
            </div>

            <div class="catalog-detail-nav">
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'summary' }" type="button" @click="setProductSection('summary')">Résumé</button>
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'variants' }" type="button" @click="setProductSection('variants')">Variantes</button>
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'prices' }" type="button" @click="setProductSection('prices')">Prix</button>
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'media' }" type="button" @click="setProductSection('media')">Médias</button>
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'attributes' }" type="button" @click="setProductSection('attributes')">Attributs</button>
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'quality' }" type="button" @click="setProductSection('quality')">Qualité</button>
              <button class="btn ghost btn-sm" :class="{ active: activeProductSection === 'channels' }" type="button" @click="setProductSection('channels')">Canaux</button>
            </div>

            <div v-if="selectedProductSummary" class="catalog-summary-grid">
              <div class="catalog-summary-card">
                <span>Variantes actives</span>
                <strong>{{ selectedProductSummary.active_variant_count ?? 0 }} / {{ selectedProductSummary.variant_count ?? 0 }}</strong>
              </div>
              <div class="catalog-summary-card">
                <span>Prix de vente</span>
                <strong>{{ priceLabel(selectedProductSummary.sale_price_min) }}</strong>
              </div>
              <div class="catalog-summary-card">
                <span>Médias</span>
                <strong>{{ selectedProductSummary.image_count ?? 0 }}</strong>
              </div>
              <div class="catalog-summary-card">
                <span>Complétude</span>
                <strong>{{ selectedProductSummary.completeness_score === null || selectedProductSummary.completeness_score === undefined ? 'Non calculée' : `${selectedProductSummary.completeness_score}%` }}</strong>
              </div>
            </div>

            <div v-if="selectedProductSignals.length" class="catalog-quality-strip">
              <span v-for="signal in selectedProductSignals" :key="`selected-${signal.label}`" class="catalog-signal" :class="`catalog-signal--${signal.tone}`">{{ signal.label }}</span>
            </div>

            <div v-if="productData" class="catalog-read-grid" :class="{ 'catalog-section-focus': activeProductSection === 'summary' }">
              <div><span>Nom</span><strong>{{ productData.name }}</strong></div>
              <div><span>Slug</span><strong>{{ productData.slug || '—' }}</strong></div>
              <div><span>Type</span><strong>{{ productTypeLabels[String(productData.type || '')] || productData.type || '—' }}</strong></div>
              <div><span>Statut</span><StatusBadge :status="productData.status || 'draft'" /></div>
              <div><span>Marque</span><strong>{{ brandName(productData.brand_id) }}</strong></div>
              <div><span>Catégorie</span><strong>{{ categoryName(productData.category_id) }}</strong></div>
              <div class="wide"><span>Résumé</span><p>{{ productData.short_description || '—' }}</p></div>
              <div class="wide"><span>Description</span><p>{{ productData.description || '—' }}</p></div>
            </div>
            <p v-else class="muted">Choisissez un produit dans la liste pour afficher sa fiche.</p>

            <div v-if="productData" class="catalog-section" :class="{ 'catalog-section-focus': activeProductSection === 'channels' }">
              <h3>Canaux</h3>
              <div class="token-row token-row--wrap">
                <span v-for="channel in channelsFor(productData)" :key="`detail-${channel}`" class="token">{{ channel }}</span>
                <span v-if="channelsFor(productData).length === 0" class="muted">interne</span>
              </div>
            </div>

            <div v-if="productData" class="catalog-section" :class="{ 'catalog-section-focus': activeProductSection === 'prices' }">
              <h3>Schéma des prix</h3>
              <div class="catalog-read-grid catalog-price-grid">
                <div><span>Devise</span><strong>{{ productForm.currency || 'CHF' }}</strong></div>
                <div v-if="canPurchaseRead"><span>Prix d'achat (base TTC)</span><strong>{{ productForm.base_purchase_price || '—' }} {{ productForm.currency }}</strong></div>
                <div><span>Prix de vente (base TTC)</span><strong>{{ productForm.base_sale_price || priceLabel(selectedProductSummary?.sale_price_min) }}</strong></div>
              </div>
            </div>

            <div v-if="productData" class="catalog-section" :class="{ 'catalog-section-focus': activeProductSection === 'attributes' }">
              <h3>Options utilisées</h3>
              <div class="token-row token-row--wrap">
                <span v-for="option in selectedProduct?.options || []" :key="Number(option.id)" class="token">{{ option.name }}</span>
                <span v-if="!(selectedProduct?.options || []).length" class="muted">Aucune option.</span>
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
            <div class="table-wrap" :class="{ 'catalog-section-focus': activeProductSection === 'variants' }">
              <table class="table">
                <thead>
                  <tr>
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
                    <td colspan="7" class="muted">Aucune variante.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="catalog-form-grid variant-create">
              <label class="field">SKU<input v-model="variantForm.sku" class="input" data-variant-field="sku" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Barcode<input v-model="variantForm.barcode" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Nom<input v-model="variantForm.name" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field wide">Commentaire Vente / POS<textarea v-model="variantForm.sales_note" class="input" rows="2" :disabled="!canWrite || !selectedProductId"></textarea></label>
              <label class="field">Statut<select v-model="variantForm.status" class="select" :disabled="!canWrite || !selectedProductId"><option v-for="status in statuses" :key="status" :value="status">{{ status }}</option></select></label>
              <label class="field">Stock initial<input v-model="variantForm.stock_quantity" class="input" :disabled="!canWrite || !selectedProductId || !!selectedVariant"><small>Amorçage à la création ; ensuite géré dans Vente › Stock.</small></label>
              <label class="checkbox-inline"><input v-model="variantForm.track_stock" type="checkbox" :disabled="!canWrite || !selectedProductId"> Suivi stock</label>
              <label class="checkbox-inline"><input v-model="variantForm.allow_backorder" type="checkbox" :disabled="!canWrite || !selectedProductId"> Livraison différée</label>
              <label class="field">Délai hors stock<input v-model="variantForm.backorder_delivery_days" class="input" type="number" min="1" step="1" :disabled="!canWrite || !selectedProductId || !variantForm.allow_backorder" placeholder="Hérite du produit"></label>
              <label v-if="canPurchaseRead" class="field">Ajustement achat<select v-model="variantForm.purchase_adjustment_type" class="select" :disabled="!canWrite || !selectedProductId"><option v-for="type in adjustmentTypes" :key="type" :value="type">{{ type }}</option></select></label>
              <label v-if="canPurchaseRead" class="field">Valeur achat<input v-model="variantForm.purchase_adjustment_value" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <label class="field">Ajustement vente<select v-model="variantForm.sale_adjustment_type" class="select" :disabled="!canWrite || !selectedProductId"><option v-for="type in adjustmentTypes" :key="type" :value="type">{{ type }}</option></select></label>
              <label class="field">Valeur vente<input v-model="variantForm.sale_adjustment_value" class="input" :disabled="!canWrite || !selectedProductId"></label>
              <button class="btn ghost" type="button" :disabled="!canWrite || !selectedProductId" @click="startNewVariantForm">Nouvelle variante</button>
              <button class="btn ghost" type="button" :disabled="!canWrite || !selectedVariant || busy === `variant-${selectedVariant?.id}`" @click="saveSelectedVariant">Enregistrer variante sélectionnée</button>
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
                  <span>{{ variantStock?.stock_reserved ?? selectedVariant.stock_reserved ?? 0 }} engagé</span>
                  <span>{{ variantStock?.available_quantity ?? 0 }} disponible à la vente</span>
                  <span>{{ variantStock?.incoming_quantity ?? 0 }} entrant</span>
                </div>
                <p class="muted">La quantité constatée et les engagements proviennent du ledger Vente. Ils ne sont jamais réécrits directement.</p>
                <div v-if="stockByLocation.length" class="history-list" aria-label="Stock par emplacement">
                  <div v-for="item in stockByLocation" :key="`location-${item.id}`" class="history-row">
                    <strong>{{ item.location_name || item.location_code }}</strong>
                    <span>{{ item.on_hand_quantity }} en stock · {{ item.reserved_quantity }} engagé · {{ item.available_quantity }} disponible</span>
                  </div>
                </div>
                <form v-if="canStockWrite" class="stock-operation" @submit.prevent="previewStockMovement">
                  <label class="field">Opération<select v-model="stockForm.action" class="select" @change="stockPreview = null"><option value="count">Quantité comptée</option><option value="receipt">Réception</option><option value="loss">Perte ou casse</option><option value="return">Retour en stock</option><option value="correction">Correction compensatoire</option></select></label>
                  <label class="field">Emplacement<select v-model="stockForm.stock_location_id" class="select" @change="stockPreview = null"><option v-for="location in stockLocations" :key="Number(location.id)" :value="String(location.id)">{{ location.name || location.code }}</option></select></label>
                  <label class="field">{{ stockForm.action === 'count' ? 'Quantité comptée' : 'Quantité' }}<input v-model="stockForm.quantity" class="input" type="number" :min="stockForm.action === 'correction' ? undefined : 0" step="1" @input="stockPreview = null"></label>
                  <label class="field wide">Motif obligatoire<textarea v-model="stockForm.reason" class="input" rows="2" required @input="stockPreview = null"></textarea></label>
                  <button class="btn ghost" type="submit" :disabled="!stockForm.reason.trim() || busy === 'stock-preview'">Prévisualiser l’impact</button>
                </form>
                <div v-if="stockPreview" class="stock-impact" aria-live="polite">
                  <strong>Impact avant confirmation</strong>
                  <span>En stock : {{ stockPreview.before?.on_hand }} → {{ stockPreview.after?.on_hand }}</span>
                  <span>Engagé : {{ stockPreview.before?.engaged }} → {{ stockPreview.after?.engaged }}</span>
                  <span>Disponible à la vente : {{ stockPreview.before?.available_to_sell }} → {{ stockPreview.after?.available_to_sell }}</span>
                  <p>{{ stockPreview.explanation }}</p>
                  <button class="btn primary" type="button" :disabled="stockPreview.blocked || busy === 'stock'" @click="createStockMovement">Confirmer le mouvement audité</button>
                  <small v-if="stockPreview.blocked" class="catalog-signal catalog-signal--danger">Mouvement refusé : il rendrait le disponible négatif.</small>
                </div>
                <RouterLink v-if="context.can('business.advanced_tools.manage')" class="btn ghost" to="/sale/advanced/stock">Ouvrir le ledger détaillé</RouterLink>
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

          <div class="grid grid-2">
            <div class="card catalog-section" :class="{ 'catalog-section-focus': activeProductSection === 'media' }">
              <h3>Médias</h3>
              <p class="muted">Les médias produit dédiés arrivent au point 12. Pour l’instant, cette section signale surtout les produits sans image dans la liste.</p>
              <div class="catalog-quality-strip">
                <span class="catalog-signal" :class="Number(selectedProductSummary?.image_count || 0) > 0 ? 'catalog-signal--success' : 'catalog-signal--warning'">
                  {{ Number(selectedProductSummary?.image_count || 0) > 0 ? 'Image disponible' : 'Image manquante' }}
                </span>
              </div>
            </div>
            <div class="card catalog-section" :class="{ 'catalog-section-focus': activeProductSection === 'quality' }">
              <h3>Qualité</h3>
              <p class="muted">La complétude détaillée sera pilotée par les règles PIM du point 14. Les alertes visibles ici reprennent déjà les manques critiques.</p>
              <div class="catalog-quality-strip">
                <span v-for="signal in selectedProductSignals" :key="`quality-${signal.label}`" class="catalog-signal" :class="`catalog-signal--${signal.tone}`">{{ signal.label }}</span>
                <span v-if="selectedProductSignals.length === 0" class="catalog-signal catalog-signal--success">Aucun blocage visible</span>
              </div>
            </div>
          </div>
        </main>
        -->
      </div>

      <div v-else class="catalog-products-shell catalog-offers-shell">
        <section class="catalog-products-panel">
          <BusinessPageHeader eyebrow="Catalogue" title="Offres">
            <template #actions>
              <button class="btn primary small" type="button" :disabled="!canWrite" @click="openCreateBundleOffer">Nouveau bundle</button>
              <button class="btn ghost small" type="button" :disabled="!canDiscountWrite" @click="openCreateDiscountOffer">Nouvelle réduction</button>
            </template>
          </BusinessPageHeader>

          <form class="catalog-toolbar" @submit.prevent="applyOfferFilters">
            <div class="catalog-search-control">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
              <input v-model="offerFilter.q" type="search" aria-label="Recherche offre, bundle, réduction, SKU" placeholder="Recherche offre, bundle, réduction, SKU">
              <button v-if="offerFilter.q" type="button" aria-label="Effacer la recherche" @click="offerFilter.q = ''">×</button>
            </div>
            <div class="catalog-toolbar-buttons">
              <details class="catalog-menu catalog-menu--filters">
                <summary class="catalog-icon-summary" aria-label="Filtres offres" title="Filtres">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16l-6 7v5l-4 2v-7L4 6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </summary>
                <div class="catalog-menu-panel catalog-filter-panel">
                  <strong>Filtres</strong>
                  <label>
                    <select v-model="offerFilter.type" aria-label="Type">
                      <option value="">Tous types</option>
                      <option value="bundle">Bundle</option>
                      <option value="discount">Réduction</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="offerFilter.status" aria-label="Statut">
                      <option value="">Tous statuts</option>
                      <option value="draft">Brouillon</option>
                      <option value="active">Actif</option>
                      <option value="archived">Archivé</option>
                    </select>
                  </label>
                  <label>
                    <select v-model="offerFilter.channel" aria-label="Canal">
                      <option value="">Tous canaux</option>
                      <option value="all">Tous</option>
                      <option value="public">Public</option>
                      <option value="ecommerce">E-commerce</option>
                      <option value="pos">POS</option>
                      <option value="admin">Admin</option>
                    </select>
                  </label>
                  <button class="btn small" type="submit" :disabled="loading">Appliquer</button>
                </div>
              </details>
              <details class="catalog-menu catalog-menu--exports">
                <summary class="catalog-icon-summary" aria-label="Import / Export offres" title="Import / Export">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left-right" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M1 11.5a.5.5 0 0 0 .5.5h11.793l-3.147 3.146a.5.5 0 0 0 .708.708l4-4a.5.5 0 0 0 0-.708l-4-4a.5.5 0 0 0-.708.708L13.293 11H1.5a.5.5 0 0 0-.5.5m14-7a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 1 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 4H14.5a.5.5 0 0 1 .5.5"/></svg>
                </summary>
                <div class="catalog-menu-panel">
                  <a :href="exportOffersCsvUrl">Exporter offres</a>
                  <a :href="exportBundlesCsvUrl">Exporter bundles</a>
                  <a :href="exportDiscountsCsvUrl">Exporter réductions</a>
                  <button type="button" :disabled="!canWrite" @click="showOfferImportPanel = !showOfferImportPanel; closeCatalogMenus()">Importer CSV offres</button>
                </div>
              </details>
              <details class="catalog-menu catalog-menu--actions">
                <summary aria-label="Actions offres" title="Actions">...</summary>
                <div class="catalog-menu-panel">
                  <button type="button" :disabled="!canWrite" @click="openCreateBundleOffer">Nouveau bundle</button>
                  <button type="button" :disabled="!canDiscountWrite" @click="openCreateDiscountOffer">Nouvelle réduction</button>
                </div>
              </details>
            </div>
          </form>

          <div v-if="activeOfferFilterChips.length" class="catalog-active-filters" aria-label="Filtres actifs offres">
            <button
              v-for="chip in activeOfferFilterChips"
              :key="chip.key"
              type="button"
              class="catalog-filter-chip"
              :aria-label="`Retirer le filtre ${chip.label}`"
              @click="removeOfferFilterChip(chip.key)"
            >
              <span aria-hidden="true">×</span>
              <strong>{{ chip.label }}</strong>
            </button>
          </div>

          <div v-if="showOfferImportPanel" class="catalog-csv-panel">
            <div class="toolbar">
              <button class="btn ghost" type="button" :disabled="!canWrite || !offerImportCsvText.trim() || busy === 'offers-csv-preview'" @click="previewOffersImport">Prévisualiser</button>
              <button class="btn primary" type="button" :disabled="!canWrite || !offerImportCsvText.trim() || offerImportHasErrors || busy === 'offers-csv-apply'" @click="applyOffersImport">Importer</button>
            </div>
            <textarea v-model="offerImportCsvText" class="input catalog-csv-input" rows="5" :disabled="!canWrite" placeholder="Coller un CSV offres"></textarea>
            <div v-if="offerImportReport" class="catalog-import-report" :class="{ 'has-errors': offerImportHasErrors }">
              <strong>{{ offerImportReport.valid_rows || 0 }} ligne(s) valides / {{ offerImportReport.rows_total || 0 }}</strong>
              <span>{{ offerImportReport.skipped || 0 }} erreur(s)</span>
              <small v-for="row in offerImportReport.rows || []" :key="Number(row.line || 0)">
                Ligne {{ row.line }} · {{ row.status }} · {{ row.action || '' }}
              </small>
            </div>
          </div>

          <div v-if="selectedOffersCount > 0" class="catalog-bulk-panel">
            <div class="catalog-bulk-panel__summary">
              <strong>{{ selectedOffersCount }} offre(s) sélectionnée(s)</strong>
              <button class="btn ghost btn-sm" type="button" @click="clearOfferBulkSelection">Vider</button>
            </div>
            <div class="catalog-bulk-grid">
              <label>Statut
                <select v-model="bulkOfferForm.status" class="select" :disabled="!canBulkOffersWrite">
                  <option value="">Conserver</option>
                  <option value="draft">Brouillon</option>
                  <option value="active">Actif</option>
                </select>
              </label>
              <label>Canal
                <select v-model="bulkOfferForm.channel" class="select" :disabled="!canBulkOffersWrite">
                  <option value="">Conserver</option>
                  <option value="all">Tous</option>
                  <option value="public">Public</option>
                  <option value="ecommerce">E-commerce</option>
                  <option value="pos">POS</option>
                  <option value="admin">Admin</option>
                </select>
              </label>
              <label class="checkbox-inline catalog-bulk-checkbox"><input v-model="bulkOfferForm.archive" type="checkbox" :disabled="!canBulkOffersWrite"> Archiver</label>
            </div>
            <div class="toolbar">
              <button class="btn ghost" type="button" :disabled="!canBulkOffersWrite || !bulkHasOfferChanges || busy === 'bulk-offer-preview'" @click="previewBulkOfferUpdate">Prévisualiser</button>
              <button class="btn primary" type="button" :disabled="!canBulkOffersWrite || !bulkHasOfferChanges || busy === 'bulk-offer-apply'" @click="applyBulkOfferUpdate">Appliquer</button>
              <button class="btn ghost" type="button" @click="resetBulkOfferForm">Réinitialiser</button>
            </div>
            <small v-if="offerBulkReport" class="muted">
              Dry-run: {{ offerBulkReport.dry_run ? 'oui' : 'non' }} · Modifiées: {{ offerBulkReport.updated ?? 0 }}
            </small>
          </div>

          <div class="catalog-products-table table-wrap catalog-offers-table-wrap">
            <table class="table catalog-table">
              <thead>
                <tr>
                  <th class="catalog-select-col">
                    <input
                      type="checkbox"
                      :checked="allVisibleOffersSelected"
                      :indeterminate.prop="someVisibleOffersSelected && !allVisibleOffersSelected"
                      aria-label="Sélectionner les offres visibles"
                      @change="toggleVisibleOfferSelection(checkedFromEvent($event))"
                    >
                  </th>
                  <th><button class="catalog-sort-button" type="button" :aria-label="offerSortLabel('type')" @click="setOfferSort('type')">TYPE<span :class="{ active: offerSort.key === 'type' }">{{ offerSort.key === 'type' && offerSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th><button class="catalog-sort-button" type="button" :aria-label="offerSortLabel('name')" @click="setOfferSort('name')">NOM<span :class="{ active: offerSort.key === 'name' }">{{ offerSort.key === 'name' && offerSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th><button class="catalog-sort-button" type="button" :aria-label="offerSortLabel('status')" @click="setOfferSort('status')">STATUT<span :class="{ active: offerSort.key === 'status' }">{{ offerSort.key === 'status' && offerSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th><button class="catalog-sort-button" type="button" :aria-label="offerSortLabel('channels')" @click="setOfferSort('channels')">CANAUX<span :class="{ active: offerSort.key === 'channels' }">{{ offerSort.key === 'channels' && offerSort.direction === 'desc' ? '↓' : '↑' }}</span></button></th>
                  <th>ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in paginatedOfferRows" :key="row.key">
                  <td class="catalog-select-col" @click.stop>
                    <input
                      type="checkbox"
                      :checked="offerSelected(row)"
                      :aria-label="`Sélectionner ${row.name}`"
                      @change="toggleOfferSelection(row, checkedFromEvent($event))"
                    >
                  </td>
                  <td><span class="catalog-signal" :class="row.kind === 'bundle' ? 'catalog-signal--success' : 'catalog-signal--muted'">{{ offerKindLabel(row.kind) }}</span></td>
                  <td>
                    <div class="catalog-product-name">
                      <strong>{{ row.name }}</strong>
                      <span>{{ row.detail }}</span>
                    </div>
                  </td>
                  <td><span class="badge" :class="row.status">{{ productStatusLabel(row.status) }}</span></td>
                  <td>
                    <div class="catalog-cell-inline catalog-cell-inline--wrap">
                      <span v-for="channel in row.channels" :key="`${row.key}-${channel}`" class="catalog-signal catalog-signal--muted">{{ offerChannelLabel(channel) }}</span>
                    </div>
                  </td>
                  <td class="action-cell catalog-actions-cell">
                    <button class="btn ghost btn-sm" type="button" @click.stop="openViewOffer(row)">Voir</button>
                    <details class="catalog-menu" @click.stop>
                      <summary class="catalog-row-menu-summary" :aria-label="`Actions pour ${row.name}`">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                      </summary>
                      <div class="catalog-menu-panel">
                        <button type="button" :disabled="row.kind === 'bundle' ? !canWrite : !canDiscountWrite" @click.stop="openEditOffer(row)">Éditer</button>
                        <button type="button" :disabled="row.kind === 'bundle' ? !canWrite : !canDiscountWrite" @click.stop="duplicateOffer(row)">Dupliquer</button>
                        <button type="button" class="danger-action" :disabled="row.kind === 'bundle' ? !canWrite : !canDiscountWrite" @click.stop="archiveOffer(row)">Archiver</button>
                      </div>
                    </details>
                  </td>
                </tr>
                <tr v-if="filteredOfferRows.length === 0">
                  <td colspan="6" class="muted">Aucune offre ne correspond aux filtres.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="catalog-pagination" aria-label="Pagination offres">
            <label>
              Lignes
              <select class="select" :value="offerPageSize" @change="onOfferPageSizeChange">
                <option v-for="size in offerPageSizeOptions" :key="String(size)" :value="size">{{ offerPageSizeLabel(size) }}</option>
              </select>
            </label>
            <span>{{ offerPaginationLabel }}</span>
            <div class="catalog-pagination-actions">
              <button class="btn ghost btn-sm" type="button" :disabled="offerPage <= 1 || loading || offerPageSize === 'all'" @click="setOfferPage(offerPage - 1)">Précédent</button>
              <strong>Page {{ offerPage }} / {{ offerPageCount }}</strong>
              <button class="btn ghost btn-sm" type="button" :disabled="offerPage >= offerPageCount || loading || offerPageSize === 'all'" @click="setOfferPage(offerPage + 1)">Suivant</button>
            </div>
          </div>
        </section>
      </div>

      <div v-if="offerViewModalOpen && selectedOfferRow" class="catalog-modal-backdrop" role="presentation" @click.self="offerViewModalOpen = false">
        <section class="catalog-modal catalog-modal--product" role="dialog" aria-modal="true" :aria-label="`Voir ${selectedOfferRow.name}`">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">{{ selectedOfferRow.kind === 'bundle' ? 'Bundle' : 'Réduction' }}</p>
              <h2>{{ selectedOfferRow.name }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="offerViewModalOpen = false">×</button>
          </header>
          <div v-if="selectedOfferRow.kind === 'bundle'" class="stack">
            <div class="catalog-read-grid">
              <div><span>SKU</span><strong>{{ bundleIdentityForm.sku_base || '—' }}</strong></div>
              <div><span>Statut</span><strong>{{ productStatusLabel(bundleIdentityForm.status) }}</strong></div>
              <div><span>Prix</span><strong>{{ bundlePriceForm.base_sale_price || '—' }} {{ bundlePriceForm.currency }}</strong></div>
              <div><span>Disponibilité</span><strong>{{ selectedBundle ? bundleStockLabel(selectedBundle.stock_strategy || selectedBundle.stock_mode) : '—' }}</strong></div>
            </div>
            <p class="muted">{{ bundleIdentityForm.short_description || bundleIdentityForm.description || 'Aucun descriptif.' }}</p>
            <div class="catalog-quality-strip">
              <span v-for="channel in selectedOfferRow.channels" :key="`view-offer-channel-${channel}`" class="catalog-signal catalog-signal--muted">{{ offerChannelLabel(channel) }}</span>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>Composant</th><th>Quantité</th><th>Requis</th></tr></thead>
                <tbody>
                  <tr v-for="component in selectedBundle?.components || []" :key="component.id">
                    <td><strong>{{ component.component_name }}</strong><small v-if="component.component_sku"> · {{ component.component_sku }}</small></td>
                    <td>{{ component.quantity }}</td>
                    <td>{{ component.is_required ? 'Oui' : 'Non' }}</td>
                  </tr>
                  <tr v-if="!(selectedBundle?.components || []).length"><td colspan="3" class="muted">Aucun composant défini.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div v-else class="catalog-read-grid">
            <div><span>Type</span><strong>{{ discountForm.type }}</strong></div>
            <div><span>Valeur</span><strong>{{ discountForm.value }} {{ discountForm.type === 'amount' ? discountForm.currency : '%' }}</strong></div>
            <div><span>Portée</span><strong>{{ discountForm.scope }} #{{ discountForm.scope_id || '—' }}</strong></div>
            <div><span>Canal</span><strong>{{ offerChannelLabel(discountForm.channel) }}</strong></div>
            <div><span>Début</span><strong>{{ discountForm.starts_at || '—' }}</strong></div>
            <div><span>Fin</span><strong>{{ discountForm.ends_at || '—' }}</strong></div>
          </div>
        </section>
      </div>

      <div v-if="offerEditModalOpen" class="catalog-modal-backdrop" role="presentation" @click.self="offerEditModalOpen = false">
        <section class="catalog-modal catalog-modal--product" role="dialog" aria-modal="true" :aria-label="offerEditKind === 'bundle' ? 'Éditer bundle' : 'Éditer réduction'">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">Édition offre</p>
              <h2>{{ offerEditKind === 'bundle' ? (bundleIdentityForm.name || 'Nouveau bundle') : (discountForm.name || 'Nouvelle réduction') }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="offerEditModalOpen = false">×</button>
          </header>

          <div v-if="offerEditKind === 'bundle'" class="stack">
            <div class="catalog-form-grid">
              <label class="field">SKU bundle<input v-model="bundleIdentityForm.sku_base" class="input" :disabled="!canWrite" @blur="bundleIdentityForm.sku_base = normalizeSkuInput(bundleIdentityForm.sku_base)"></label>
              <label class="field wide">Nom du bundle<input v-model="bundleIdentityForm.name" class="input" :disabled="!canWrite"></label>
              <label class="field">Slug<input v-model="bundleIdentityForm.slug" class="input" :disabled="!canWrite"></label>
              <label class="field">Statut<select v-model="bundleIdentityForm.status" class="select" :disabled="!canWrite"><option value="draft">Brouillon</option><option value="active">Actif</option><option value="archived">Archivé</option></select></label>
              <label class="field wide">Descriptif court<input v-model="bundleIdentityForm.short_description" class="input" :disabled="!canWrite"></label>
              <label class="field wide">Descriptif e-commerce / catalogue<textarea v-model="bundleIdentityForm.description" class="input" rows="3" :disabled="!canWrite"></textarea></label>
              <label class="checkbox-inline"><input v-model="bundleIdentityForm.is_public" type="checkbox" :disabled="!canWrite"> Public</label>
              <label class="checkbox-inline"><input v-model="bundleIdentityForm.is_ecommerce_enabled" type="checkbox" :disabled="!canWrite"> E-commerce</label>
              <label class="checkbox-inline"><input v-model="bundleIdentityForm.is_pos_enabled" type="checkbox" :disabled="!canWrite"> POS</label>
              <label class="checkbox-inline"><input v-model="bundleIdentityForm.is_catalogue_enabled" type="checkbox" :disabled="!canWrite"> Brochure</label>
              <label class="field">Mode de prix<select v-model="bundleForm.pricing_mode" class="select" :disabled="!canWrite"><option value="fixed">Prix fixe</option><option value="sum_components">Somme des composants</option><option value="discount_components">Somme remisée</option></select></label>
              <fieldset class="field wide bundle-strategies"><legend>{{ t('business.bundle.strategy.title') }}</legend><label v-for="strategy in ['OWN_STOCK','COMPONENT_DERIVED','NON_STOCKED']" :key="strategy" class="bundle-strategy"><input v-model="bundleForm.stock_strategy" type="radio" :value="strategy" :disabled="!canWrite"><span><strong>{{ bundleStockLabel(strategy) }}</strong><small>{{ t(`business.bundle.strategy.${strategy === 'OWN_STOCK' ? 'ownStockHelp' : strategy === 'NON_STOCKED' ? 'nonStockedHelp' : 'componentDerivedHelp'}` as never) }}</small></span></label></fieldset>
              <label v-if="bundleForm.stock_strategy === 'COMPONENT_DERIVED'" class="field">{{ t('business.bundle.partialPolicy') }}<select v-model="bundleForm.partial_availability_policy" class="select" :disabled="!canWrite"><option value="REQUIRE_ALL">{{ t('business.bundle.requireAll') }}</option><option value="ALLOW_PARTIAL" disabled>{{ t('business.bundle.allowPartialUnsupported') }}</option></select></label>
              <label v-if="bundleForm.stock_strategy === 'COMPONENT_DERIVED'" class="field">{{ t('business.bundle.returnPolicy') }}<select v-model="bundleForm.component_return_policy" class="select" :disabled="!canWrite"><option value="BUNDLE_ONLY">{{ t('business.bundle.returnBundleOnly') }}</option><option value="COMPONENTS_ALLOWED">{{ t('business.bundle.returnComponents') }}</option></select></label>
              <label v-if="bundleForm.stock_strategy" class="checkbox-inline"><input v-model="bundleForm.components_public" type="checkbox" :disabled="!canWrite"> {{ t('business.bundle.componentsPublic') }}</label>
              <label class="field">Devise<input v-model="bundlePriceForm.currency" class="input" :disabled="!canPriceWrite"></label>
              <label v-if="canPurchaseRead" class="field">Prix d'achat (base TTC)<input v-model="bundlePriceForm.base_purchase_price" class="input" inputmode="decimal" :disabled="!canPriceWrite"></label>
              <label class="field">Prix de vente (base TTC)<input v-model="bundlePriceForm.base_sale_price" class="input" inputmode="decimal" :disabled="!canPriceWrite"></label>
              <label class="checkbox-inline"><input v-model="bundleForm.is_active" type="checkbox" :disabled="!canWrite"> Bundle actif</label>
            </div>
            <aside v-if="selectedBundle" class="bundle-estimate" aria-live="polite"><div><small>{{ t('business.bundle.estimate') }}</small><strong>{{ bundleAvailabilityLabel(bundleStockEstimate?.status) }}</strong><span v-if="bundleStockEstimate?.available_quantity !== null && bundleStockEstimate?.available_quantity !== undefined">{{ bundleStockEstimate.available_quantity }} {{ t('business.bundle.bundleUnits') }}</span></div><p v-if="bundleLimitingFactor"><strong>{{ t('business.bundle.limitingFactor') }}</strong> {{ bundleLimitingFactor.name || bundleLimitingFactor.sku }}</p><ul v-if="selectedBundle.configuration_errors?.length"><li v-for="issue in selectedBundle.configuration_errors" :key="issue">{{ bundleConfigurationErrorLabel(issue) }}</li></ul></aside>
            <div v-if="selectedBundle" class="table-wrap">
              <table class="table">
                <thead><tr><th>Composant</th><th>Quantité</th><th>Requis</th><th>Actions</th></tr></thead>
                <tbody>
                  <tr v-for="component in selectedBundle.components || []" :key="component.id">
                    <td><strong>{{ component.component_name }}</strong><small v-if="component.component_sku"> · {{ component.component_sku }}</small></td>
                    <td>{{ component.quantity }}</td>
                    <td>{{ component.is_required ? 'Oui' : 'Non' }}</td>
                    <td><button class="btn ghost btn-sm" type="button" :aria-label="t('business.bundle.moveUp')" :disabled="!canWrite || busy === `bundle-component-${component.id}`" @click="moveBundleComponent(component,-1)">↑</button><button class="btn ghost btn-sm" type="button" :aria-label="t('business.bundle.moveDown')" :disabled="!canWrite || busy === `bundle-component-${component.id}`" @click="moveBundleComponent(component,1)">↓</button><button class="btn ghost btn-sm" type="button" :disabled="!canWrite || busy === `bundle-component-${component.id}`" @click="removeBundleComponent(component)">Retirer</button></td>
                  </tr>
                  <tr v-if="!(selectedBundle.components || []).length"><td colspan="4" class="muted">Aucun composant défini.</td></tr>
                </tbody>
              </table>
            </div>
            <div v-if="selectedBundle" class="catalog-form-grid catalog-bundle-component-form">
              <label class="field wide">{{ t('business.bundle.componentSearch') }}<input v-model="bundleComponentSearch" class="input" type="search" :placeholder="t('business.bundle.componentSearchPlaceholder')"></label>
              <label class="field wide">Produit composant<select v-model="bundleComponentForm.component_product_id" class="select" :disabled="!canWrite"><option value="">Sélectionner</option><option v-for="product in bundleComponentProducts" :key="`component-product-${product.id}`" :value="product.id">{{ product.sku_base || '—' }} · {{ product.name }}</option></select></label>
              <label class="field">Quantité<input v-model="bundleComponentForm.quantity" class="input" type="number" min="0.0001" step="0.0001" :disabled="!canWrite"></label>
              <label class="checkbox-inline"><input v-model="bundleComponentForm.is_required" type="checkbox" :disabled="!canWrite"> Requis</label>
              <button class="btn ghost" type="button" :disabled="!canWrite || busy === 'bundle-component' || !bundleComponentForm.component_product_id" @click="addBundleComponent">Ajouter composant</button>
            </div>
            <footer class="catalog-modal-actions">
              <button class="btn ghost" type="button" @click="offerEditModalOpen = false">Fermer</button>
              <button class="btn primary" type="button" :disabled="!canWrite || busy === 'bundle' || !bundleIdentityForm.name.trim()" @click="saveBundle">Enregistrer</button>
            </footer>
          </div>

          <div v-else class="stack">
            <ol class="offer-wizard-steps" aria-label="Étapes de création d’une offre">
              <li v-for="(label,index) in ['Objectif','Périmètre','Avantage','Période','Cumul','Aperçu','Activation']" :key="label" :class="{ active: offerWizardStep === index + 1, done: offerWizardStep > index + 1 }"><button type="button" @click="offerWizardStep = index + 1">{{ index + 1 }}. {{ label }}</button></li>
            </ol>
            <div class="catalog-form-grid offer-wizard-panel">
              <template v-if="offerWizardStep === 1">
                <label class="field">Objectif<select v-model="discountForm.objective" class="select" :disabled="!canDiscountWrite"><option value="increase_sales">Développer les ventes</option><option value="clear_stock">Écouler un stock</option><option value="loyalty">Fidéliser</option><option value="launch">Lancer un produit</option></select></label>
                <label class="field wide">Nom de l’offre<input v-model="discountForm.name" class="input" :disabled="!canDiscountWrite"></label>
              </template>
              <template v-else-if="offerWizardStep === 2">
                <label class="field">Portée<select v-model="discountForm.scope" class="select" :disabled="!canDiscountWrite"><option v-for="scope in discountScopes" :key="scope" :value="scope">{{ scope }}</option></select></label>
                <label class="field">Objet concerné<input v-model="discountForm.scope_id" class="input" inputmode="numeric" :disabled="!canDiscountWrite" placeholder="Identifiant produit, variante, catégorie ou marque"></label>
                <label class="field">Canal<select v-model="discountForm.channel" class="select" :disabled="!canDiscountWrite"><option v-for="channel in discountChannels" :key="channel" :value="channel">{{ offerChannelLabel(channel) }}</option></select></label>
                <label class="field">Audience facultative<input v-model="discountForm.customer_segment" class="input" :disabled="!canDiscountWrite" placeholder="Nom de l’audience"></label>
                <p class="muted wide">Une Audience personnalise le périmètre. Elle ne constitue jamais un consentement marketing et n’est pas requise pour une offre publique.</p>
              </template>
              <template v-else-if="offerWizardStep === 3">
                <label class="field">Avantage<select v-model="discountForm.type" class="select" :disabled="!canDiscountWrite"><option v-for="type in discountTypes" :key="type" :value="type">{{ type }}</option></select></label>
                <label class="field">Valeur<input v-model="discountForm.value" class="input" :disabled="!canDiscountWrite"></label>
                <label v-if="discountForm.type === 'amount'" class="field">Devise<input v-model="discountForm.currency" class="input" :disabled="!canDiscountWrite"></label>
              </template>
              <template v-else-if="offerWizardStep === 4">
                <label class="field">Début<input v-model="discountForm.starts_at" class="input" type="datetime-local" :disabled="!canDiscountWrite"></label>
                <label class="field">Fin<input v-model="discountForm.ends_at" class="input" type="datetime-local" :disabled="!canDiscountWrite"></label>
              </template>
              <template v-else-if="offerWizardStep === 5">
                <label class="field">Priorité de cumul<input v-model="discountForm.priority" class="input" type="number" :disabled="!canDiscountWrite"></label>
                <p class="muted wide">La priorité la plus basse est évaluée en premier. L’aperçu signale les offres actives de même portée et période.</p>
              </template>
              <template v-else-if="offerWizardStep === 6">
                <p class="wide">Vérifiez les produits, le canal, la période, l’Audience autorisée et les conflits avant toute activation.</p>
                <button class="btn primary wide" type="button" :disabled="busy === 'discount-preview'" @click="previewDiscount">Générer l’aperçu</button>
              </template>
              <template v-else>
                <div v-if="offerPreview" class="offer-preview wide" aria-live="polite">
                  <strong>{{ offerPreview.affected_products }} produit(s) concerné(s)</strong>
                  <span>Canal : {{ offerChannelLabel(String(offerPreview.channel || 'all')) }}</span>
                  <span v-if="offerPreview.audience">Audience : {{ offerPreview.audience.rule }} · {{ offerPreview.audience.estimated_count ?? 'volume masqué' }}</span>
                  <small v-if="offerPreview.audience">Cette estimation n’est pas une preuve de consentement.</small>
                  <div v-if="offerPreview.conflicts?.length" class="catalog-signal catalog-signal--danger">{{ offerPreview.conflicts.length }} conflit(s) potentiel(s) détecté(s).</div>
                  <label v-if="offerPreview.conflicts?.length" class="checkbox-inline"><input v-model="offerConflictAcknowledged" type="checkbox" @change="previewDiscount"> J’ai examiné la priorité et le cumul de ces offres.</label>
                </div>
                <label class="field">Activation<select v-model="discountForm.status" class="select" :disabled="!canDiscountWrite"><option value="draft">Conserver en brouillon</option><option value="active">Activer ou programmer</option></select></label>
              </template>
            </div>
            <footer class="catalog-modal-actions">
              <button class="btn ghost" type="button" @click="offerEditModalOpen = false">Fermer</button>
              <button v-if="offerWizardStep > 1" class="btn ghost" type="button" @click="offerWizardStep--">Précédent</button>
              <button v-if="offerWizardStep < 6" class="btn primary" type="button" @click="offerWizardStep++">Suivant</button>
              <button v-if="offerWizardStep === 6" class="btn primary" type="button" :disabled="busy === 'discount-preview'" @click="previewDiscount">Prévisualiser</button>
              <button v-if="offerWizardStep === 7" class="btn primary" type="button" :disabled="!canDiscountWrite || !offerPreview || busy === 'discount' || (discountForm.status === 'active' && offerPreview.activation_allowed === false)" @click="saveDiscount">Enregistrer</button>
            </footer>
          </div>
        </section>
      </div>

      <div v-if="referenceModalOpen" class="catalog-modal-backdrop" role="presentation" @click.self="closeReferenceModal">
        <section class="catalog-modal catalog-modal--reference" role="dialog" aria-modal="true" :aria-label="referenceTitle">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">Référentiel produit</p>
              <h2>{{ referenceTitle }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeReferenceModal">×</button>
          </header>

          <div v-if="referenceKind === 'type'" class="catalog-reference-grid">
            <label v-for="type in productTypes" :key="type" class="field">
              {{ type }}
              <input v-model="productTypeLabels[type]" class="input" :disabled="!canWrite">
            </label>
            <footer class="catalog-modal-actions wide">
              <button class="btn ghost" type="button" @click="Object.assign(productTypeLabels, defaultProductTypeLabels)">Réinitialiser</button>
              <button class="btn primary" type="button" :disabled="!canWrite" @click="saveReferenceLabels('type')">Enregistrer</button>
            </footer>
          </div>

          <div v-else-if="referenceKind === 'status'" class="catalog-reference-grid">
            <label v-for="status in statuses" :key="status" class="field">
              {{ status }}
              <input v-model="productStatusLabels[status]" class="input" :disabled="!canWrite">
            </label>
            <footer class="catalog-modal-actions wide">
              <button class="btn ghost" type="button" @click="Object.assign(productStatusLabels, defaultProductStatusLabels)">Réinitialiser</button>
              <button class="btn primary" type="button" :disabled="!canWrite" @click="saveReferenceLabels('status')">Enregistrer</button>
            </footer>
          </div>

          <div v-else-if="referenceKind === 'brand'" class="catalog-reference-layout">
            <div class="catalog-reference-list">
              <button v-for="brand in brands" :key="brand.id" type="button" @click="fillBrandForm(brand)">
                <strong>{{ brand.name }}</strong>
                <span>{{ brand.company_name || brand.slug || '—' }}</span>
              </button>
              <p v-if="brands.length === 0" class="muted">Aucune marque.</p>
            </div>
            <div class="catalog-form-grid">
              <label class="field">Nom<input v-model="brandForm.name" class="input" :disabled="!canWrite"></label>
              <label class="field">Slug<input v-model="brandForm.slug" class="input" :disabled="!canWrite"></label>
              <label class="field">Entreprise CRM
                <select v-model="brandForm.company_id" class="select" :disabled="!canWrite">
                  <option value="">Aucune liaison</option>
                  <option v-for="company in brandCompanies" :key="company.id" :value="String(company.id)">{{ company.name }}</option>
                </select>
              </label>
              <label class="field">Statut<select v-model="brandForm.status" class="select" :disabled="!canWrite"><option v-for="status in statuses" :key="status" :value="status">{{ productStatusLabels[status] || status }}</option></select></label>
              <label class="field">Ordre<input v-model="brandForm.sort_order" class="input" :disabled="!canWrite"></label>
              <label class="field wide">Site web<input v-model="brandForm.website_url" class="input" :disabled="!canWrite"></label>
              <label class="field wide">Description<textarea v-model="brandForm.description" class="input" rows="3" :disabled="!canWrite"></textarea></label>
              <footer class="catalog-modal-actions wide">
                <button class="btn ghost" type="button" @click="fillBrandForm(null)">Nouvelle marque</button>
                <button class="btn ghost" type="button" :disabled="!canWrite || !brandForm.id || busy === 'brand-delete'" @click="deleteCurrentBrand">Effacer</button>
                <button class="btn primary" type="button" :disabled="!canWrite || !brandForm.name.trim() || busy === 'brand'" @click="saveBrand">Enregistrer</button>
              </footer>
            </div>
          </div>

          <div v-else-if="referenceKind === 'tax'" class="catalog-reference-layout">
            <div class="catalog-reference-list">
              <button v-for="taxClass in taxClasses" :key="taxClass.id" type="button" @click="fillTaxClassForm(taxClass)">
                <strong>{{ taxClassLabel(taxClass.id) }}</strong>
                <span>{{ taxClass.code || '—' }} · {{ taxClass.country || 'CH' }} · {{ Number(taxClass.usage_count || 0) }} produit(s)</span>
              </button>
              <p v-if="taxClasses.length === 0" class="muted">Aucun taux TVA.</p>
            </div>
            <div class="catalog-form-grid">
              <label class="field">Nom<input v-model="taxClassForm.name" class="input" :disabled="!canWrite" placeholder="Standard"></label>
              <label class="field">Code<input v-model="taxClassForm.code" class="input" :disabled="!canWrite" placeholder="standard"></label>
              <label class="field">Taux (%)<input v-model="taxClassForm.rate" class="input" type="number" min="0" step="0.01" :disabled="!canWrite"></label>
              <label class="field">Pays<input v-model="taxClassForm.country" class="input" maxlength="2" :disabled="!canWrite" placeholder="CH"></label>
              <label class="checkbox-inline"><input v-model="taxClassForm.is_default" type="checkbox" :disabled="!canWrite"> Taux par défaut</label>
              <p v-if="taxClassForm.id && taxClassForm.usage_count > 0" class="muted wide">{{ taxClassForm.usage_count }} produit(s) utilisent ce taux. Il ne peut pas être effacé tant qu’il est utilisé.</p>
              <footer class="catalog-modal-actions wide">
                <button class="btn ghost" type="button" @click="fillTaxClassForm(null)">Nouveau taux</button>
                <button class="btn ghost" type="button" :disabled="!canWrite || !taxClassForm.id || taxClassForm.usage_count > 0 || busy === 'tax-class-delete'" @click="deleteCurrentTaxClass">Effacer</button>
                <button class="btn primary" type="button" :disabled="!canWrite || !taxClassForm.name.trim() || busy === 'tax-class'" @click="saveTaxClass">Enregistrer</button>
              </footer>
            </div>
          </div>

          <div v-else class="catalog-reference-layout">
            <div class="catalog-reference-list">
              <button v-for="category in categories" :key="category.id" type="button" @click="fillCategoryForm(category)">
                <strong>{{ category.name }}</strong>
                <span>{{ category.slug || '—' }}</span>
              </button>
              <p v-if="categories.length === 0" class="muted">Aucune catégorie.</p>
            </div>
            <div class="catalog-form-grid">
              <label class="field">Nom<input v-model="categoryForm.name" class="input" :disabled="!canWrite"></label>
              <label class="field">Slug<input v-model="categoryForm.slug" class="input" :disabled="!canWrite"></label>
              <label class="field">Parent<select v-model="categoryForm.parent_id" class="select" :disabled="!canWrite"><option value="">Aucun</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
              <label class="field">Ordre<input v-model="categoryForm.sort_order" class="input" :disabled="!canWrite"></label>
              <label class="field wide">Description<textarea v-model="categoryForm.description" class="input" rows="3" :disabled="!canWrite"></textarea></label>
              <footer class="catalog-modal-actions wide">
                <button class="btn ghost" type="button" @click="fillCategoryForm(null)">Nouvelle catégorie</button>
                <button class="btn ghost" type="button" :disabled="!canWrite || !categoryForm.id || busy === 'category-delete'" @click="deleteCurrentCategory">Effacer</button>
                <button class="btn primary" type="button" :disabled="!canWrite || !categoryForm.name.trim() || busy === 'category'" @click="saveCategory">Enregistrer</button>
              </footer>
            </div>
          </div>
        </section>
      </div>

      <div v-if="attributeSettingsModal" class="catalog-modal-backdrop" role="presentation" @click.self="closeAttributeSettings">
        <section class="catalog-modal catalog-modal--attributes" role="dialog" aria-modal="true" aria-label="Réglages attributs">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">Réglages catalogue</p>
              <h2>{{ attributeSettingsTitle }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeAttributeSettings">×</button>
          </header>

          <div v-if="attributeSettingsModal !== 'groups' && attributeDefinitionWarnings.length" class="catalog-quality-strip catalog-definition-warnings">
            <span v-for="warning in attributeDefinitionWarnings" :key="`definition-warning-${warning}`" class="catalog-signal catalog-signal--warning">{{ warning }}</span>
          </div>

          <div class="catalog-attribute-settings-grid catalog-attribute-settings-grid--single">
            <section v-if="attributeSettingsModal === 'groups'" class="catalog-settings-card">
              <div class="panel__header compact">
                <div>
                  <h3>Groupes</h3>
                  <p class="muted">Ex. Général, Textile, Service, Technique, SEO, Logistique.</p>
                </div>
                <button class="btn ghost btn-sm" type="button" @click="fillAttributeGroupForm(null)">Nouveau</button>
              </div>
              <div class="catalog-reference-list compact-list">
                <button v-for="group in attributeGroups" :key="group.id" type="button" @click="fillAttributeGroupForm(group)">
                  <strong>{{ group.name }}</strong>
                  <span>{{ group.code || '—' }}</span>
                </button>
                <p v-if="attributeGroups.length === 0" class="muted">Aucun groupe.</p>
              </div>
              <div class="catalog-form-grid catalog-form-grid--single">
                <label class="field">Nom<input v-model="attributeGroupForm.name" class="input" :disabled="!canWrite"></label>
                <label class="field">Code<input v-model="attributeGroupForm.code" class="input" :disabled="!canWrite"></label>
                <label class="field">Ordre<input v-model="attributeGroupForm.sort_order" class="input" :disabled="!canWrite"></label>
                <label class="field">Description<textarea v-model="attributeGroupForm.description" class="input" rows="2" :disabled="!canWrite"></textarea></label>
                <button class="btn primary" type="button" :disabled="!canWrite || !attributeGroupForm.name.trim() || busy === 'attribute-group'" @click="saveAttributeGroup">Enregistrer groupe</button>
              </div>
            </section>

            <section v-if="attributeSettingsModal === 'attributes'" class="catalog-settings-card">
              <div class="panel__header compact">
                <div>
                  <h3>Attributs</h3>
                  <p class="muted">Visibilité publique, filtre, recherche et complétude.</p>
                </div>
                <button class="btn ghost btn-sm" type="button" @click="fillAttributeForm(null)">Nouveau</button>
              </div>
              <div class="catalog-reference-list compact-list">
                <button v-for="attribute in attributes" :key="attribute.id" type="button" @click="fillAttributeForm(attribute)">
                  <strong>{{ attribute.name }}</strong>
                  <span>{{ attribute.group_name }} · {{ attributeTypeLabel(attribute.data_type) }}</span>
                </button>
                <p v-if="attributes.length === 0" class="muted">Aucun attribut.</p>
              </div>
              <div class="catalog-form-grid">
                <label class="field">Nom<input v-model="attributeForm.name" class="input" :disabled="!canWrite"></label>
                <label class="field">Code<input v-model="attributeForm.code" class="input" :disabled="!canWrite"></label>
                <label class="field">Groupe<select v-model="attributeForm.group_id" class="select" :disabled="!canWrite"><option value="" disabled>Sélectionner un groupe</option><option v-for="group in attributeGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select></label>
                <label class="field">Type<select v-model="attributeForm.data_type" class="select" :disabled="!canWrite"><option v-for="type in attributeTypes" :key="type" :value="type">{{ attributeTypeLabel(type) }}</option></select></label>
                <label class="field">Unité<input v-model="attributeForm.unit" class="input" :disabled="!canWrite"></label>
                <label class="field">Ordre<input v-model="attributeForm.sort_order" class="input" :disabled="!canWrite"></label>
                <div class="catalog-flag-grid wide">
                  <label class="checkbox-inline"><input v-model="attributeForm.is_required" type="checkbox" :disabled="!canWrite"> Requis</label>
                  <label class="checkbox-inline"><input v-model="attributeForm.is_public" type="checkbox" :disabled="!canWrite"> Public</label>
                  <label class="checkbox-inline"><input v-model="attributeForm.is_filterable" type="checkbox" :disabled="!canWrite"> Filtrable</label>
                  <label class="checkbox-inline"><input v-model="attributeForm.is_searchable" type="checkbox" :disabled="!canWrite"> Recherchable</label>
                </div>
                <div class="catalog-quality-strip wide" role="status">
                  <div>
                    <strong>Impact public</strong>
                    <p v-if="!attributeForm.is_public" class="muted">Interne : absent du produit public, de la recherche et des facettes.</p>
                    <p v-else-if="attributeForm.is_filterable && attributeForm.is_searchable" class="muted">Visible sur le produit, indexé pour la recherche et proposé comme facette après sélection de son groupe.</p>
                    <p v-else-if="attributeForm.is_filterable" class="muted">Visible sur le produit et proposé comme facette après sélection de son groupe.</p>
                    <p v-else-if="attributeForm.is_searchable" class="muted">Visible sur le produit et indexé pour la recherche, sans facette.</p>
                    <p v-else class="muted">Visible sur le produit, sans incidence sur la recherche ni les facettes.</p>
                  </div>
                  <span v-for="warning in attributeFormImpactWarnings" :key="warning" class="catalog-signal catalog-signal--warning">{{ warning }}</span>
                </div>
                <button class="btn primary wide" type="button" :disabled="!canWrite || !attributeForm.name.trim() || attributeFormImpactWarnings.length > 0 || busy === 'attribute'" @click="saveAttributeDefinition">Enregistrer attribut</button>
              </div>
            </section>

            <section v-if="attributeSettingsModal === 'options'" class="catalog-settings-card">
              <div class="panel__header compact">
                <div>
                  <h3>Options</h3>
                  <p class="muted">Les attributs de choix utilisent des options; les autres restent visibles pour vérification.</p>
                </div>
                <button class="btn ghost btn-sm" type="button" :disabled="!selectedOptionAttribute" @click="resetAttributeOptionForm()">Nouvelle</button>
              </div>
              <label class="field">Attribut
                <select v-model="attributeOptionForm.attribute_id" class="select" :disabled="!canWrite" @change="resetAttributeOptionForm()">
                  <option value="">Choisir un attribut</option>
                  <option v-for="attribute in selectableAttributes" :key="attribute.id" :value="attribute.id">{{ attribute.name }}</option>
                </select>
              </label>
              <div class="catalog-reference-list compact-list">
                <button v-for="option in selectedOptionAttributeOptions" :key="attributeOptionKey(option)" type="button" @click="selectedOptionAttribute && fillAttributeOptionForm(selectedOptionAttribute, option)">
                  <strong>{{ attributeOptionLabel(option) }}</strong>
                  <span>{{ option.code || '—' }} · {{ attributeOptionValue(option) || '—' }}</span>
                </button>
                <p v-if="!selectedOptionAttribute" class="muted">Sélectionner un attribut.</p>
                <p v-else-if="selectedOptionAttributeOptions.length === 0" class="muted">Aucune option.</p>
                <p v-if="selectedOptionAttribute && !isChoiceAttribute(selectedOptionAttribute)" class="muted">Cet attribut n’est pas un choix; les options sont généralement inutiles pour ce type.</p>
              </div>
              <div class="catalog-form-grid catalog-form-grid--single">
                <label class="field">Libellé<input v-model="attributeOptionForm.label" class="input" :disabled="!canWrite || !selectedOptionAttribute"></label>
                <label class="field">Valeur<input v-model="attributeOptionForm.value" class="input" :disabled="!canWrite || !selectedOptionAttribute"></label>
                <label class="field">Code<input v-model="attributeOptionForm.code" class="input" :disabled="!canWrite || !selectedOptionAttribute"></label>
                <div v-if="attributeOptionUsesColor" class="field catalog-color-field">
                  <span>Couleur</span>
                  <div class="catalog-color-picker">
                    <input v-model="attributeOptionForm.color_hex" type="color" :disabled="!canWrite || !selectedOptionAttribute" :value="attributeOptionForm.color_hex || '#000000'" @input="setAttributeOptionColor(eventValue($event))">
                    <input v-model="attributeOptionForm.color_hex" class="input" placeholder="#000000" :disabled="!canWrite || !selectedOptionAttribute">
                  </div>
                  <div class="catalog-color-swatches">
                    <button v-for="color in colorSwatches" :key="color" type="button" :class="{ active: attributeOptionForm.color_hex.toUpperCase() === color }" :style="{ background: color }" :aria-label="`Choisir ${color}`" :disabled="!canWrite || !selectedOptionAttribute" @click="setAttributeOptionColor(color)"></button>
                  </div>
                </div>
                <label v-else class="field">Couleur<input v-model="attributeOptionForm.color_hex" class="input" placeholder="#000000" :disabled="!canWrite || !selectedOptionAttribute"></label>
                <label class="field">Ordre<input v-model="attributeOptionForm.sort_order" class="input" :disabled="!canWrite || !selectedOptionAttribute"></label>
                <button class="btn ghost" type="button" :disabled="!canWrite || !attributeOptionForm.id || busy === 'attribute-option-delete'" @click="deleteCurrentAttributeOption">Effacer option</button>
                <button class="btn primary" type="button" :disabled="!canWrite || !selectedOptionAttribute || !attributeOptionForm.label.trim() || busy === 'attribute-option'" @click="saveAttributeOption">Enregistrer option</button>
              </div>
            </section>
          </div>

          <footer class="catalog-modal-actions">
            <button class="btn ghost" type="button" @click="closeAttributeSettings">Fermer</button>
          </footer>
        </section>
      </div>

      <div v-if="productViewModalOpen" class="catalog-modal-backdrop" role="presentation" @click.self="closeProductViewModal">
        <section class="catalog-modal catalog-modal--view" role="dialog" aria-modal="true" aria-label="Voir le produit">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">Produit</p>
              <h2>{{ productData?.name || 'Produit' }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeProductViewModal">×</button>
          </header>

          <div v-if="selectedProductSummary" class="catalog-summary-grid">
            <div class="catalog-summary-card">
              <span>Variantes actives</span>
              <strong>{{ selectedProductSummary.active_variant_count ?? 0 }} / {{ selectedProductSummary.variant_count ?? 0 }}</strong>
            </div>
            <div class="catalog-summary-card">
              <span>Prix de vente</span>
              <strong>{{ priceLabel(selectedProductSummary.sale_price_min) }}</strong>
            </div>
            <div class="catalog-summary-card">
              <span>Médias</span>
              <strong>{{ selectedProductSummary.image_count ?? 0 }}</strong>
            </div>
            <div class="catalog-summary-card">
              <span>Complétude</span>
              <strong>{{ selectedProductSummary.completeness_score === null || selectedProductSummary.completeness_score === undefined ? 'Non calculée' : `${selectedProductSummary.completeness_score}%` }}</strong>
            </div>
          </div>

          <div v-if="selectedProductSignals.length" class="catalog-quality-strip">
            <span v-for="signal in selectedProductSignals" :key="`modal-${signal.label}`" class="catalog-signal" :class="`catalog-signal--${signal.tone}`">{{ signal.label }}</span>
          </div>

          <div v-if="productData" class="catalog-read-grid">
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'identity', 'name')"><span>Nom</span><strong>{{ productData.name }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'identity', 'slug')"><span>Slug</span><strong>{{ productData.slug || '—' }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'classification', 'type')"><span>Type</span><strong>{{ productTypeLabels[String(productData.type || '')] || productData.type || '—' }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'classification', 'status')"><span>Statut</span><StatusBadge :status="productData.status || 'draft'" /></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'classification', 'brand')"><span>Marque</span><strong>{{ brandName(productData.brand_id) }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'classification', 'category')"><span>Catégorie</span><strong>{{ categoryName(productData.category_id) }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'tax')"><span>TVA</span><strong>{{ taxClassLabel(productData.tax_class_id) }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'stock')"><span>Stock</span><strong>{{ productForm.track_stock ? 'Suivi' : 'Non suivi' }} · {{ productForm.allow_backorder ? `livraison sous ${productForm.backorder_delivery_days || 7} j` : 'nous contacter' }}</strong></button>
            <button type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'identity')"><span>Unité</span><strong>{{ productData.unit || 'unit' }}</strong></button>
            <button class="wide" type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'identity')"><span>Résumé</span><p>{{ productData.short_description || '—' }}</p></button>
            <button class="wide" type="button" @click="productViewModalOpen = false; openEditProduct(undefined, 'identity')"><span>Description</span><p>{{ productData.description || '—' }}</p></button>
          </div>

          <div class="catalog-modal-sections">
            <section>
              <h3>Variantes</h3>
              <div class="catalog-compact-list">
                <div v-for="variant in selectedVariants" :key="variant.id" class="catalog-compact-row catalog-variant-summary-row">
                  <div>
                    <strong>{{ variantDisplayName(variant) }}</strong>
                    <span>{{ money(variant.computed_prices?.final_sale_price) }} · stock {{ variant.stock_quantity ?? 0 }}</span>
                  </div>
                  <div class="catalog-variant-menu-actions">
                    <button class="catalog-icon-button" type="button" :aria-label="`Modifier les textes de ${variantDisplayName(variant)}`" title="Données texte" @click="openVariantEditModal(undefined, variant)">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-card-text" viewBox="0 0 16 16" aria-hidden="true"><path d="M14.5 3a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5zm-13-1A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2z"/><path d="M3 5.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3 8a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 8m0 2.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5"/></svg>
                    </button>
                    <button class="catalog-icon-button" type="button" :aria-label="`Modifier les attributs de ${variantDisplayName(variant)}`" title="Attributs variante" @click="openVariantAttributesModal(undefined, variant)">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-beaker" viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 1.5A.5.5 0 0 1 5 1h6a.5.5 0 0 1 0 1h-.5v4.2l3.18 5.724A2 2 0 0 1 11.93 15H4.07a2 2 0 0 1-1.75-3.076L5.5 6.2V2H5a.5.5 0 0 1-.5-.5M6.5 6.46 3.195 12.41A1 1 0 0 0 4.07 14h7.86a1 1 0 0 0 .875-1.59L9.5 6.46V2h-3z"/><path d="M5.33 10 4.07 12.27A.5.5 0 0 0 4.51 13h6.98a.5.5 0 0 0 .44-.73L10.67 10z"/></svg>
                    </button>
                    <button class="catalog-icon-button catalog-icon-button--danger" type="button" :disabled="!canWrite || busy === `delete-variant-${variant.id}`" :aria-label="`Effacer ${variantDisplayName(variant)}`" title="Effacer variante" @click="deleteVariantFromProduct(undefined, variant)">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash2" viewBox="0 0 16 16" aria-hidden="true"><path d="M6.5 1h3a1 1 0 0 1 1 1v1H14a.5.5 0 0 1 0 1h-.55l-.78 9.34A2 2 0 0 1 10.68 15H5.32a2 2 0 0 1-1.99-1.66L2.55 4H2a.5.5 0 0 1 0-1h3.5V2a1 1 0 0 1 1-1m0 2h3V2h-3zM3.55 4l.77 9.17a1 1 0 0 0 1 .83h5.36a1 1 0 0 0 1-.83L12.45 4z"/><path d="M6.5 6.5a.5.5 0 0 1 .5.5v5a.5.5 0 0 1-1 0V7a.5.5 0 0 1 .5-.5m3 0a.5.5 0 0 1 .5.5v5a.5.5 0 0 1-1 0V7a.5.5 0 0 1 .5-.5"/></svg>
                    </button>
                  </div>
                </div>
                <p v-if="selectedVariants.length === 0" class="muted">Aucune variante.</p>
              </div>
            </section>
            <section class="catalog-clickable-section" @click="productViewModalOpen = false; openEditProduct(undefined, 'channels')">
              <h3>Canaux</h3>
              <div v-if="productData" class="token-row token-row--wrap">
                <span v-for="channel in channelsFor(productData)" :key="`modal-channel-${channel}`" class="token">{{ channel }}</span>
                <span v-if="channelsFor(productData).length === 0" class="muted">interne</span>
              </div>
            </section>
            <section class="catalog-clickable-section" @click="productViewModalOpen = false; openEditProduct(undefined, 'stock')">
              <h3>Stock</h3>
              <div v-if="productData" class="catalog-quality-strip">
                <span class="catalog-stock-dot" :class="`catalog-stock-dot--${stockIndicatorTone(productData)}`"></span>
                <span class="catalog-signal catalog-signal--muted">{{ stockSummaryLabel(productData) }}</span>
              </div>
            </section>
            <section class="catalog-clickable-section" @click="productViewModalOpen = false; openProductAttributes()">
              <h3>Options</h3>
              <div class="token-row token-row--wrap">
                <span v-for="option in selectedProduct?.options || []" :key="Number(option.id)" class="token">{{ option.name }}</span>
                <span v-if="!(selectedProduct?.options || []).length" class="muted">Aucune option.</span>
              </div>
            </section>
            <section class="catalog-clickable-section" @click="productViewModalOpen = false; openProductMedia()">
              <h3>Médias</h3>
              <div class="catalog-asset-overview">
                <div>
                  <span>Image principale</span>
                  <strong>{{ mainProductAsset ? assetMediaLabel(mainProductAsset.media_id) : 'Manquante' }}</strong>
                </div>
                <div>
                  <span>Galerie</span>
                  <strong>{{ galleryProductAssets.length }}</strong>
                </div>
                <div>
                  <span>Images variantes</span>
                  <strong>{{ variantProductAssets.length }}</strong>
                </div>
                <div>
                  <span>Documents</span>
                  <strong>{{ documentProductAssets.length }}</strong>
                </div>
              </div>
              <div v-if="assetWarnings.length" class="catalog-quality-strip">
                <span v-for="warning in assetWarnings.slice(0, 3)" :key="`asset-warning-${warning}`" class="catalog-signal catalog-signal--warning">{{ warning }}</span>
              </div>
            </section>
            <section class="catalog-content-links-section">
              <h3>Contenus CMS</h3>
              <div class="catalog-compact-list">
                <div v-for="link in productContentLinks" :key="link.id" class="catalog-compact-row">
                  <div>
                    <strong>{{ link.entry_key || `Contenu #${link.content_entry_id}` }}</strong>
                    <span>{{ link.content_type }} · {{ link.relation_type }}<template v-if="link.locale"> · {{ link.locale }}</template><template v-if="link.is_canonical"> · canonique</template></span>
                  </div>
                  <button class="catalog-icon-button catalog-icon-button--danger" type="button" :disabled="!canWrite || busy === `content-link-${link.id}`" aria-label="Supprimer la liaison" title="Supprimer la liaison" @click="deleteProductContentLink(link)">×</button>
                </div>
                <p v-if="productContentLinks.length === 0" class="muted">Aucun contenu éditorial lié.</p>
              </div>
              <form v-if="canWrite" class="catalog-content-link-form" @submit.prevent="createProductContentLink">
                <label class="field">Contenu
                  <select v-model="contentLinkForm.content_entry_id" class="select" required>
                    <option value="">Choisir…</option>
                    <option v-for="content in contentCandidates" :key="content.id" :value="String(content.id)">{{ content.title || content.entry_key }} · {{ content.type_key }}</option>
                  </select>
                </label>
                <label class="field">Relation
                  <select v-model="contentLinkForm.relation_type" class="select"><option v-for="relation in contentRelationTypes" :key="relation" :value="relation">{{ relation }}</option></select>
                </label>
                <label class="field">Langue<input v-model.trim="contentLinkForm.locale" class="input" placeholder="Toutes ou fr"></label>
                <label class="field">Donnée structurée<select v-model="contentLinkForm.schema_type" class="select"><option value="Product">Produit</option><option value="Service">Service</option></select></label>
                <label class="checkbox-inline"><input v-model="contentLinkForm.is_canonical" type="checkbox"> Contenu canonique</label>
                <button class="btn primary btn-sm" type="submit" :disabled="busy === 'content-link' || !contentLinkForm.content_entry_id">Lier</button>
              </form>
            </section>
            <section class="catalog-content-links-section" aria-labelledby="product-relations-title">
              <h3 id="product-relations-title">Produits liés</h3>
              <p class="muted">Relations ordonnées de cette fiche. Les cibles non publiques ne sont jamais exposées dans la boutique.</p>
              <div class="catalog-compact-list">
                <div v-for="relation in productRelations" :key="String(relation.id)" class="catalog-compact-row">
                  <div>
                    <strong>{{ relation.target_name || `Produit #${relation.related_product_id}` }}</strong>
                    <span>{{ productRelationLabels[String(relation.relation_type)] || relation.relation_type }} · ordre {{ relation.sort_order || 0 }} · {{ relation.source === 'automatic' ? 'automatique' : 'manuel' }}<template v-if="relation.target_slug"> · /{{ relation.target_slug }}</template><template v-if="relation.target_status !== 'active'"> · {{ relation.target_status }}</template></span>
                  </div>
                  <button v-if="relation.source === 'manual'" class="catalog-icon-button catalog-icon-button--danger" type="button" :disabled="!canWrite || busy === `product-relation-${relation.id}`" aria-label="Retirer la relation produit" title="Retirer" @click="deleteProductRelation(relation)">×</button>
                </div>
                <p v-if="productRelations.length === 0" class="muted">Aucun produit lié.</p>
              </div>
              <form v-if="canWrite" class="catalog-content-link-form" @submit.prevent="createProductRelation">
                <label class="field">Rechercher<input v-model.trim="productRelationForm.search" class="input" placeholder="Nom, SKU ou slug"></label>
                <label class="field">Produit
                  <select v-model="productRelationForm.target_product_id" class="select" required><option value="">Choisir…</option><option v-for="candidate in productRelationCandidates" :key="candidate.id" :value="String(candidate.id)">{{ candidate.name }} · {{ candidate.sku_base || candidate.slug }} · {{ candidate.status }}</option></select>
                </label>
                <label class="field">Type<select v-model="productRelationForm.relation_type" class="select"><option v-for="relationType in productRelationTypes" :key="relationType" :value="relationType">{{ productRelationLabels[relationType] }}</option></select></label>
                <label class="field">Ordre<input v-model="productRelationForm.sort_order" class="input" type="number" min="0"></label>
                <button class="btn primary btn-sm" type="submit" :disabled="busy === 'product-relation' || !productRelationForm.target_product_id">Ajouter</button>
              </form>
              <h4>Règles automatiques explicites</h4>
              <p class="muted">Une règle ajoute, dans le même site uniquement, des produits actifs et publics appartenant à une catégorie ou à un groupe.</p>
              <div class="catalog-compact-list">
                <div v-for="rule in productRelationRules" :key="rule.id" class="catalog-compact-row"><div><strong>{{ productRelationLabels[String(rule.relation_type)] || rule.relation_type }}</strong><span>{{ rule.match_type === 'group' ? 'Groupe' : 'Catégorie' }} : {{ productRelationRuleTargetName(rule) }} · {{ rule.result_limit }} maximum · ordre {{ rule.sort_order || 0 }}</span></div><button class="catalog-icon-button catalog-icon-button--danger" type="button" :disabled="!canWrite || busy === `product-relation-rule-${rule.id}`" aria-label="Supprimer la règle automatique" title="Supprimer" @click="deleteProductRelationRule(rule)">×</button></div>
                <p v-if="productRelationRules.length === 0" class="muted">Aucune règle automatique.</p>
              </div>
              <form v-if="canWrite" class="catalog-content-link-form" @submit.prevent="createProductRelationRule">
                <label class="field">Type<select v-model="productRelationRuleForm.relation_type" class="select"><option v-for="relationType in productRelationTypes" :key="relationType" :value="relationType">{{ productRelationLabels[relationType] }}</option></select></label>
                <label class="field">Correspondance<select v-model="productRelationRuleForm.match_type" class="select" @change="productRelationRuleForm.match_id = ''"><option value="category">Catégorie</option><option value="group">Groupe</option></select></label>
                <label class="field">Valeur<select v-model="productRelationRuleForm.match_id" class="select" required><option value="">Choisir…</option><option v-for="target in productRelationRuleTargets" :key="target.id" :value="String(target.id)">{{ target.name }}</option></select></label>
                <label class="field">Maximum<input v-model="productRelationRuleForm.result_limit" class="input" type="number" min="1" max="24"></label>
                <label class="field">Ordre<input v-model="productRelationRuleForm.sort_order" class="input" type="number" min="0"></label>
                <button class="btn primary btn-sm" type="submit" :disabled="busy === 'product-relation-rule' || !productRelationRuleForm.match_id">Ajouter la règle</button>
              </form>
            </section>
            <section class="catalog-clickable-section" @click="productViewModalOpen = false; openProductAttributes()">
              <h3>Qualité</h3>
              <div class="catalog-quality-strip">
                <span v-for="signal in selectedProductSignals" :key="`modal-quality-${signal.label}`" class="catalog-signal" :class="`catalog-signal--${signal.tone}`">{{ signal.label }}</span>
                <span v-if="selectedProductSignals.length === 0" class="catalog-signal catalog-signal--success">Aucun blocage visible</span>
              </div>
            </section>
          </div>
          <footer class="catalog-modal-actions">
            <button class="btn ghost" type="button" @click="closeProductViewModal">Fermer</button>
            <button class="btn primary" type="button" :disabled="!canWrite || !productData" @click="productViewModalOpen = false; openEditProduct()">Modifier</button>
          </footer>
        </section>
      </div>

      <div v-if="productModalOpen" class="catalog-modal-backdrop" role="presentation" @click.self="closeProductModal">
        <section class="catalog-modal" role="dialog" aria-modal="true" :aria-label="productModalTitle()">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">{{ variantAttributesOnlyModal ? 'Variante' : 'Produit' }}</p>
              <h2>{{ productModalTitle() }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeProductModal">×</button>
          </header>
          <div v-if="productModalShows('identity')" class="catalog-form-grid">
            <label v-if="productModalScope === 'all' || productModalMode === 'create' || !productModalFocus || productModalFocus === 'sku'" class="field">SKU produit<input v-model="productForm.sku_base" class="input" data-product-field="sku" :disabled="!canWrite" placeholder="SKU ou préfixe" @input="productForm.sku_base = normalizeSkuInput(productForm.sku_base)"></label>
            <label v-if="productModalScope === 'all' || productModalMode === 'create' || !productModalFocus || productModalFocus === 'name' || productModalFocus === 'name_type'" class="field">Nom<input v-model="productForm.name" class="input" data-product-field="name" :disabled="!canWrite"></label>
            <label v-if="productModalFocus === 'name_type'" class="field">Type<select v-model="productForm.type" class="select" data-product-field="type" :disabled="!canWrite"><option v-for="type in productTypes" :key="type" :value="type">{{ productTypeLabels[type] || type }}</option></select></label>
            <label v-if="productModalScope === 'all' || productModalMode === 'create' || !productModalFocus || productModalFocus === 'slug'" class="field">Slug<input v-model="productForm.slug" class="input" data-product-field="slug" :disabled="!canWrite"></label>
          </div>
          <div v-if="productModalShows('classification')" class="catalog-form-grid">
            <label class="field">Type<select v-model="productForm.type" class="select" data-product-field="type" :disabled="!canWrite"><option v-for="type in productTypes" :key="type" :value="type">{{ productTypeLabels[type] || type }}</option></select></label>
            <label class="field">Statut<select v-model="productForm.status" class="select" data-product-field="status" :disabled="!canWrite"><option v-for="status in statuses" :key="status" :value="status">{{ productStatusLabels[status] || status }}</option></select></label>
            <label class="field">Marque<select v-model="productForm.brand_id" class="select" data-product-field="brand" :disabled="!canWrite"><option value="">Aucune</option><option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option></select></label>
            <label class="field">Catégorie<select v-model="productForm.category_id" class="select" data-product-field="category" :disabled="!canWrite"><option value="">Aucune</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
          </div>
          <div v-if="productModalShows('identity') && (productModalScope === 'all' || productModalMode === 'create' || !productModalFocus)" class="catalog-form-grid">
            <label class="field wide">Résumé<textarea v-model="productForm.short_description" class="input" rows="2" :disabled="!canWrite"></textarea></label>
            <label class="field wide">Description<textarea v-model="productForm.description" class="input" rows="4" :disabled="!canWrite"></textarea></label>
          </div>
          <div v-if="productModalShows('channels')" class="catalog-section">
            <h3>Canaux</h3>
            <div class="toolbar">
              <label class="checkbox-inline"><input v-model="productForm.is_public" type="checkbox" :disabled="!canWrite"> Public</label>
              <label class="checkbox-inline"><input v-model="productForm.is_ecommerce_enabled" type="checkbox" :disabled="!canWrite"> E-commerce</label>
              <label class="checkbox-inline"><input v-model="productForm.is_pos_enabled" type="checkbox" :disabled="!canWrite"> POS</label>
              <label class="checkbox-inline"><input v-model="productForm.is_catalogue_enabled" type="checkbox" :disabled="!canWrite"> Brochure</label>
            </div>
          </div>
          <div v-if="productModalShows('tax')" class="catalog-section">
            <h3>TVA</h3>
            <div class="catalog-form-grid">
              <label class="field">Classe TVA
                <select v-model="productForm.tax_class_id" class="select" :disabled="!canWrite">
                  <option value="">Aucune TVA</option>
                  <option v-for="taxClass in taxClasses" :key="Number(taxClass.id)" :value="String(taxClass.id)">{{ taxClassLabel(taxClass.id) }}</option>
                </select>
              </label>
            </div>
            <p class="muted">La classe TVA sélectionnée est reprise par les snapshots de vente, le POS et les exports.</p>
          </div>
          <div v-if="productModalShows('stock')" class="catalog-section">
            <h3>Stock des variantes</h3>
            <div class="catalog-variant-stock-list">
              <article v-for="variant in selectedVariants" :key="`edit-stock-${variant.id}`">
                <div class="stock-summary">
                  <strong>{{ variant.sku }}</strong>
                  <span>{{ Number(variant.stock_quantity || 0) }} en stock</span>
                  <span>{{ Number(variant.stock_reserved || 0) }} engagé</span>
                  <span>{{ variantStockAvailable(variant) }} disponible à la vente</span>
                </div>
                <button v-if="canStockWrite" class="btn ghost btn-sm" type="button" @click="openInventoryAdjustment(variant)">Modifier l’inventaire</button>
              </article>
              <p v-if="!selectedVariants.length" class="muted">Aucune variante.</p>
            </div>
          </div>
          <div v-if="productModalShows('prices')" class="catalog-section">
            <h3>Schéma des prix</h3>
            <div class="catalog-form-grid catalog-price-grid">
              <label class="field">Devise<input v-model="productForm.currency" class="input" :disabled="!canPriceWrite"></label>
              <label v-if="canPurchaseRead" class="field">Prix d'achat (base TTC)<input v-model="productForm.base_purchase_price" class="input" :disabled="!canPriceWrite"></label>
              <label class="field">Prix de vente (base TTC)<input v-model="productForm.base_sale_price" class="input" :disabled="!canPriceWrite"></label>
            </div>
          </div>
          <div v-if="productModalShows('media')" class="catalog-section catalog-asset-manager">
            <div class="panel__header compact">
              <div>
                <h3>Images et médias du produit</h3>
                <p class="muted">Choisissez d’abord l’usage souhaité. Les images publiques sont ensuite répercutées automatiquement dans la boutique.</p>
              </div>
            </div>
            <div v-if="assetWarnings.length" class="catalog-quality-strip">
              <span v-for="warning in assetWarnings" :key="`edit-asset-warning-${warning}`" class="catalog-signal catalog-signal--warning">{{ warning }}</span>
            </div>
            <section class="catalog-storefront-media-preview" aria-labelledby="storefront-media-preview-title">
              <div>
                <h4 id="storefront-media-preview-title">Aperçu de la galerie boutique</h4>
                <p class="muted">La première image est utilisée dans les listes de produits. Les médias propres à une variante apparaissent lorsqu’elle est sélectionnée.</p>
              </div>
              <div v-if="storefrontProductAssets.length" class="catalog-storefront-media-preview__content">
                <div class="catalog-storefront-media-preview__main">
                  <img v-if="assetPreviewUrl(storefrontProductAssets[0]) && assetIsImage(storefrontProductAssets[0])" :src="assetPreviewUrl(storefrontProductAssets[0])" :alt="String(storefrontProductAssets[0].alt_text || '')">
                  <span v-else aria-hidden="true">▶</span>
                </div>
                <div class="catalog-storefront-media-preview__thumbs">
                  <button v-for="asset in storefrontProductAssets" :key="`preview-${asset.asset_id || asset.id}`" type="button" :aria-label="`Modifier ${assetMediaLabel(asset.media_id)}`" @click="resetAssetForm(asset)">
                    <img v-if="assetPreviewUrl(asset) && assetIsImage(asset)" :src="assetPreviewUrl(asset)" alt="">
                    <span v-else>Vidéo</span>
                  </button>
                </div>
              </div>
              <div v-else class="catalog-storefront-media-preview__empty">
                <strong>Aucune image visible dans la boutique</strong>
                <span>Ajoutez une image principale pour rendre les cartes produit immédiatement reconnaissables.</span>
              </div>
            </section>
            <div v-if="!assetForm.id" class="catalog-media-presets" aria-label="Ajouter un média selon son usage">
              <button type="button" class="catalog-media-preset" @click="startAssetPreset('main')"><strong>Image principale</strong><span>Cartes et première image de la fiche</span></button>
              <button type="button" class="catalog-media-preset" @click="startAssetPreset('gallery')"><strong>Galerie</strong><span>Photo ou vidéo supplémentaire</span></button>
              <button type="button" class="catalog-media-preset" :disabled="selectedVariants.length === 0" @click="startAssetPreset('variant')"><strong>Média d’une variante</strong><span>{{ selectedVariants.length ? 'Affiché au choix de la variante' : 'Créez d’abord une variante' }}</span></button>
              <button type="button" class="catalog-media-preset" @click="startAssetPreset('document')"><strong>Document</strong><span>Notice ou fiche technique, hors galerie</span></button>
            </div>
            <div class="catalog-asset-groups">
              <div class="catalog-asset-group">
                <h4>Image principale</h4>
                <div v-if="mainProductAsset" class="catalog-asset-card">
                  <img v-if="assetPreviewUrl(mainProductAsset) && assetIsImage(mainProductAsset)" :src="assetPreviewUrl(mainProductAsset)" alt="">
                  <div>
                    <strong>{{ assetMediaLabel(mainProductAsset.media_id) }}</strong>
                    <span>{{ assetChannelLabel(mainProductAsset.channel_scope) }} · {{ mainProductAsset.alt_text || 'Texte alternatif manquant' }}</span>
                  </div>
                  <button class="btn ghost btn-sm" type="button" @click="resetAssetForm(mainProductAsset)">Modifier</button>
                </div>
                <button v-else class="catalog-asset-empty-action" type="button" @click="startAssetPreset('main')">+ Ajouter l’image principale</button>
              </div>
              <div class="catalog-asset-group">
                <h4>Galerie générale</h4>
                <div v-for="asset in galleryProductAssets" :key="asset.asset_id || asset.id" class="catalog-asset-card">
                  <img v-if="assetPreviewUrl(asset) && assetIsImage(asset)" :src="assetPreviewUrl(asset)" alt="">
                  <div>
                    <strong>{{ assetMediaLabel(asset.media_id) }}</strong>
                    <span>{{ assetChannelLabel(asset.channel_scope) }} · {{ asset.alt_text || 'Alt manquant' }}</span>
                  </div>
                  <button class="btn ghost btn-sm" type="button" @click="resetAssetForm(asset)">Modifier</button>
                </div>
                <button v-if="galleryProductAssets.length === 0" class="catalog-asset-empty-action" type="button" @click="startAssetPreset('gallery')">+ Ajouter à la galerie</button>
              </div>
              <div class="catalog-asset-group">
                <h4>Médias des variantes</h4>
                <div v-for="asset in variantProductAssets" :key="asset.asset_id || asset.id" class="catalog-asset-card">
                  <img v-if="assetPreviewUrl(asset) && assetIsImage(asset)" :src="assetPreviewUrl(asset)" alt="">
                  <div>
                    <strong>{{ assetMediaLabel(asset.media_id) }}</strong>
                    <span>{{ assetVariantLabel(asset) }} · {{ assetChannelLabel(asset.channel_scope) }}</span>
                  </div>
                  <button class="btn ghost btn-sm" type="button" @click="resetAssetForm(asset)">Modifier</button>
                </div>
                <button v-if="variantProductAssets.length === 0 && selectedVariants.length" class="catalog-asset-empty-action" type="button" @click="startAssetPreset('variant')">+ Illustrer une variante</button>
                <p v-else-if="variantProductAssets.length === 0" class="muted">Aucune variante à illustrer.</p>
              </div>
              <div class="catalog-asset-group">
                <h4>Documents liés</h4>
                <div v-for="asset in documentProductAssets" :key="asset.asset_id || asset.id" class="catalog-asset-card">
                  <div>
                    <strong>{{ assetMediaLabel(asset.media_id) }}</strong>
                    <span>{{ assetRoleLabel(asset.role) }} · {{ assetChannelLabel(asset.channel_scope) }}</span>
                  </div>
                  <button class="btn ghost btn-sm" type="button" @click="resetAssetForm(asset)">Modifier</button>
                </div>
                <button v-if="documentProductAssets.length === 0" class="catalog-asset-empty-action" type="button" @click="startAssetPreset('document')">+ Ajouter un document</button>
              </div>
              <details v-if="internalProductAssets.length" class="catalog-asset-group catalog-asset-group--details">
                <summary>Fichiers internes ({{ internalProductAssets.length }})</summary>
                <h4>Fichiers internes</h4>
                <div v-for="asset in internalProductAssets" :key="asset.asset_id || asset.id" class="catalog-asset-card">
                  <div>
                    <strong>{{ assetMediaLabel(asset.media_id) }}</strong>
                    <span>{{ assetRoleLabel(asset.role) }} · {{ assetChannelLabel(asset.channel_scope) }}</span>
                  </div>
                  <button class="btn ghost btn-sm" type="button" @click="resetAssetForm(asset)">Modifier</button>
                </div>
              </details>
            </div>
            <div class="catalog-asset-editor">
              <div class="catalog-asset-editor__head">
                <div><h4>{{ assetForm.id ? 'Modifier ce média' : 'Ajouter un média' }}</h4><p>{{ assetUsageExplanation }}</p></div>
                <button v-if="assetForm.id" class="btn ghost btn-sm" type="button" @click="resetAssetForm(null)">Ajouter un autre média</button>
              </div>
              <MediaPicker
                :media-id="Number(assetForm.media_id || 0)"
                :accept-type="['document','technical_sheet'].includes(assetForm.role) ? 'document' : assetForm.role === 'internal' ? 'any' : 'visual'"
                :label="['document','technical_sheet'].includes(assetForm.role) ? 'Choisir ou téléverser le document' : 'Choisir ou téléverser l’image ou la vidéo'"
                help="Vous pouvez rechercher dans la médiathèque ou téléverser un nouveau fichier ici."
                preferred-set="content"
                compact
                :disabled="!canWrite"
                @update:media-id="assetForm.media_id = $event ? String($event) : ''"
                @update:alt="assetForm.alt_text = $event"
                @update:caption="assetForm.caption = $event"
                @update:title="assetForm.title = $event"
                @select="selectProductMedia"
              />
              <p v-if="assetForm.media_id && !assetSelectionValid" class="notice notice--warning">Ce type de fichier ne peut pas être affiché comme image ou vidéo. Choisissez « Document » ou sélectionnez un média visuel.</p>
              <div class="catalog-form-grid">
                <label class="field">S’applique à
                  <select v-model="assetForm.variant_id" class="select" :disabled="!canWrite" @change="onAssetVariantChange">
                    <option value="">Toutes les variantes (média général)</option>
                    <option v-for="variant in selectedVariants" :key="variant.id" :value="variant.id">{{ variantDisplayName(variant) }}</option>
                  </select>
                </label>
                <label class="field">Utilisation
                  <select v-model="assetForm.role" class="select" :disabled="!canWrite" @change="onAssetRoleChange">
                    <option v-for="role in assetRoleChoices" :key="role" :value="role">{{ assetRoleLabel(role) }}</option>
                  </select>
                </label>
                <label class="field">Visible dans
                  <select v-model="assetForm.channel_scope" class="select" :disabled="!canWrite">
                    <option v-for="channel in assetChannels" :key="channel" :value="channel">{{ assetChannelLabel(channel) }}</option>
                  </select>
                </label>
                <label class="field">Position dans la galerie<input v-model="assetForm.sort_order" class="input" type="number" min="0" step="10" :disabled="!canWrite"><small>Les valeurs les plus petites apparaissent en premier.</small></label>
                <label class="field wide">Titre interne (optionnel)<input v-model="assetForm.title" class="input" :disabled="!canWrite"></label>
                <label v-if="assetNeedsVisualMedia" class="field wide">Description de l’image pour l’accessibilité<input v-model="assetForm.alt_text" class="input" :disabled="!canWrite" placeholder="Décrire ce qui est utile pour comprendre l’image"><small>Ce texte est lu par les lecteurs d’écran. Évitez « image de ».</small></label>
                <label class="field wide">Légende<textarea v-model="assetForm.caption" class="input" rows="2" :disabled="!canWrite"></textarea></label>
                <label class="checkbox-inline"><input v-model="assetForm.is_public" type="checkbox" :disabled="!canWrite || assetForm.role === 'internal'"> Autoriser la publication hors administration</label>
              </div>
              <div class="catalog-modal-actions">
                <button v-if="assetForm.id" class="btn ghost" type="button" :disabled="!canWrite || busy === `asset-${assetForm.id}`" @click="archiveCurrentAsset">Retirer du produit</button>
                <button v-if="assetForm.id && !assetForm.variant_id && assetNeedsVisualMedia && assetForm.role !== 'main'" class="btn ghost" type="button" :disabled="!canWrite" @click="setCurrentAssetAsMain">Utiliser comme image principale</button>
                <button class="btn primary" type="button" :disabled="!canWrite || !assetSelectionValid || busy === 'asset'" @click="saveProductAsset">{{ busy === 'asset' ? 'Actualisation…' : assetForm.id ? 'Enregistrer et actualiser la boutique' : 'Ajouter et actualiser la boutique' }}</button>
              </div>
            </div>
          </div>
          <div v-if="productModalShows('attributes')" class="catalog-section catalog-attribute-manager">
            <div class="panel__header compact">
              <div>
                <h3>{{ variantAttributesOnlyModal ? `Attributs de ${variantDisplayName(selectedVariant || {})}` : 'Attributs' }}</h3>
                <p class="muted">{{ variantAttributesOnlyModal ? 'Renseigner uniquement les attributs propres à cette variante.' : 'Choisir les groupes applicables, puis renseigner uniquement les attributs correspondants.' }}</p>
              </div>
            </div>
            <section v-if="!variantAttributesOnlyModal" class="catalog-attribute-scope">
              <div class="catalog-attribute-scope-head">
                <div>
                  <h4>Groupes d’attributs du produit</h4>
                </div>
              </div>
              <div class="catalog-option-buttons">
                <label v-for="group in attributeGroups" :key="`product-attribute-group-${group.id}`" class="catalog-option-button" :class="{ active: productForm.attribute_group_ids.includes(Number(group.id || 0)) }">
                  <input v-model="productForm.attribute_group_ids" type="checkbox" :value="Number(group.id || 0)" :disabled="!canWrite">
                  <span>{{ group.name }}</span>
                </label>
              </div>
              <p v-if="attributeGroups.length === 0" class="muted">Aucun groupe d’attributs configuré.</p>
            </section>
            <div class="catalog-attribute-groups">
              <section v-if="!variantAttributesOnlyModal" class="catalog-attribute-scope">
                <div class="catalog-attribute-scope-head">
                  <div>
                    <h4>Produit</h4>
                    <p class="muted">Attributs communs à toutes les variantes.</p>
                  </div>
                </div>
                <div v-if="productAttributeWarnings.length" class="catalog-quality-strip">
                  <span v-for="warning in productAttributeWarnings" :key="`product-attribute-warning-${warning}`" class="catalog-signal catalog-signal--warning">{{ warning }}</span>
                </div>
                <section v-for="group in groupedAttributes" :key="`product-${group.group.id || group.group.code}`" class="catalog-attribute-group">
                  <h5>{{ group.group.name }}</h5>
                  <div v-for="attribute in group.attributes" :key="`product-${attribute.id}`" class="catalog-attribute-row catalog-attribute-row--single">
                    <div>
                      <strong>{{ attribute.name }}</strong>
                      <span>{{ attributeTypeLabel(attribute.data_type) }}<template v-if="attribute.unit"> · {{ attribute.unit }}</template></span>
                      <div class="token-row token-row--wrap">
                        <span v-if="attribute.is_required" class="token">Requis</span>
                        <span v-if="attribute.is_public" class="token">Public</span>
                        <span v-if="attribute.is_filterable" class="token">Filtrable</span>
                        <span v-if="attribute.is_searchable" class="token">Recherchable</span>
                      </div>
                    </div>
                    <label class="field">
                      Valeur produit
                      <div v-if="isChoiceAttribute(attribute)" class="catalog-option-buttons">
                        <button v-for="option in attributeOptions(attribute)" :key="attributeOptionKey(option)" type="button" class="catalog-option-button" :class="{ active: attributeOptionSelected(attribute, 'product', option) }" :disabled="!canWrite" @click="toggleAttributeOption(attribute, 'product', option)">
                          <span v-if="option.color_hex" class="catalog-color-dot" :style="{ background: String(option.color_hex) }"></span>
                          <span>{{ attributeOptionLabel(option) }}</span>
                        </button>
                        <span v-if="attributeOptions(attribute).length === 0" class="muted">Aucune option définie.</span>
                      </div>
                      <select v-else-if="attribute.data_type === 'boolean'" class="select" :value="attributeValue(attribute.id, 'product')" :disabled="!canWrite" @change="setAttributeValue(attribute.id, 'product', eventValue($event))">
                        <option value="">—</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                      </select>
                      <textarea v-else-if="attribute.data_type === 'textarea' || attribute.data_type === 'rich_text'" class="input" rows="2" :value="attributeValue(attribute.id, 'product')" :disabled="!canWrite" @input="setAttributeValue(attribute.id, 'product', eventValue($event))"></textarea>
                      <input v-else class="input" :type="attributeInputType(attribute)" :value="attributeValue(attribute.id, 'product')" :disabled="!canWrite" @input="setAttributeValue(attribute.id, 'product', eventValue($event))">
	                    </label>
	                  </div>
	                </section>
	              </section>

              <section v-if="variantAttributesOnlyModal" class="catalog-attribute-scope catalog-attribute-scope--variant">
                <div class="catalog-attribute-scope-head">
                  <div>
                    <h4>Variante</h4>
                    <p class="muted">Attributs propres à la variante choisie.</p>
                  </div>
                </div>
                <div v-if="variantAttributeWarnings.length" class="catalog-quality-strip">
                  <span v-for="warning in variantAttributeWarnings" :key="`variant-attribute-warning-${warning}`" class="catalog-signal catalog-signal--warning">{{ warning }}</span>
                </div>
                <template v-if="selectedVariant">
                  <section v-for="group in groupedAttributes" :key="`variant-${group.group.id || group.group.code}`" class="catalog-attribute-group">
                    <h5>{{ group.group.name }}</h5>
                    <div v-for="attribute in group.attributes" :key="`variant-${attribute.id}`" class="catalog-attribute-row catalog-attribute-row--single">
                      <div>
                        <strong>{{ attribute.name }}</strong>
                        <span>{{ attributeTypeLabel(attribute.data_type) }}<template v-if="attribute.unit"> · {{ attribute.unit }}</template></span>
                        <div class="token-row token-row--wrap">
                          <span v-if="attribute.is_required" class="token">Requis</span>
                          <span v-if="attribute.is_public" class="token">Public</span>
                          <span v-if="attribute.is_filterable" class="token">Filtrable</span>
                          <span v-if="attribute.is_searchable" class="token">Recherchable</span>
                        </div>
                      </div>
                      <label class="field">
                        Valeur variante
                        <div v-if="isChoiceAttribute(attribute)" class="catalog-option-buttons">
                          <button v-for="option in attributeOptions(attribute)" :key="attributeOptionKey(option)" type="button" class="catalog-option-button" :class="{ active: attributeOptionSelected(attribute, 'variant', option) }" :disabled="!canWrite" @click="toggleAttributeOption(attribute, 'variant', option)">
                            <span v-if="option.color_hex" class="catalog-color-dot" :style="{ background: String(option.color_hex) }"></span>
                            <span>{{ attributeOptionLabel(option) }}</span>
                          </button>
                          <span v-if="attributeOptions(attribute).length === 0" class="muted">Aucune option définie.</span>
                        </div>
                        <select v-else-if="attribute.data_type === 'boolean'" class="select" :value="attributeValue(attribute.id, 'variant')" :disabled="!canWrite" @change="setAttributeValue(attribute.id, 'variant', eventValue($event))">
                          <option value="">—</option>
                          <option value="1">Oui</option>
                          <option value="0">Non</option>
                        </select>
                        <textarea v-else-if="attribute.data_type === 'textarea' || attribute.data_type === 'rich_text'" class="input" rows="2" :value="attributeValue(attribute.id, 'variant')" :disabled="!canWrite" @input="setAttributeValue(attribute.id, 'variant', eventValue($event))"></textarea>
                        <input v-else class="input" :type="attributeInputType(attribute)" :value="attributeValue(attribute.id, 'variant')" :disabled="!canWrite" @input="setAttributeValue(attribute.id, 'variant', eventValue($event))">
                      </label>
                    </div>
                  </section>
                </template>
                <p v-else class="muted">Aucune variante sélectionnée.</p>
              </section>
            </div>
            <p v-if="!groupedAttributes.length" class="muted">Aucun attribut PIM sélectionné pour ce produit.</p>
          </div>
          <footer class="catalog-modal-actions">
            <button class="btn ghost" type="button" @click="closeProductModal">Fermer</button>
            <button v-if="productModalScope !== 'media' || productModalMode === 'create'" class="btn primary" type="button" :disabled="!canWrite || busy === 'product' || busy === 'product-attributes' || busy === 'variant-attributes'" @click="saveProductModal">Enregistrer</button>
          </footer>
        </section>
      </div>

      <div v-if="inventoryAdjustmentOpen && selectedVariant" class="catalog-modal-backdrop catalog-modal-backdrop--inventory" role="presentation" @click.self="closeInventoryAdjustment">
        <section class="catalog-modal catalog-modal--inventory" role="dialog" aria-modal="true" aria-label="Modifier l’inventaire">
          <header class="catalog-modal-head">
            <div><p class="eyebrow">Variation auditée</p><h2>{{ selectedVariant.sku }}</h2></div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeInventoryAdjustment">×</button>
          </header>
          <div class="stock-summary">
            <span>{{ variantStock?.stock_quantity ?? selectedVariant.stock_quantity ?? 0 }} en stock</span>
            <span>{{ variantStock?.stock_reserved ?? selectedVariant.stock_reserved ?? 0 }} engagé</span>
            <span>{{ variantStock?.available_quantity ?? variantStockAvailable(selectedVariant) }} disponible à la vente</span>
          </div>
          <form class="stock-operation" @submit.prevent="previewStockMovement">
            <label class="field">Emplacement<select v-model="stockForm.stock_location_id" class="select" @change="stockPreview = null"><option v-for="location in stockLocations" :key="Number(location.id)" :value="String(location.id)">{{ location.name || location.code }}</option></select></label>
            <label class="field">Variation<input v-model="stockForm.quantity" class="input" type="number" step="1" placeholder="Ex. +5 ou -2" @input="stockPreview = null"><small>Saisissez la différence à ajouter ou retirer, jamais le nouveau total.</small></label>
            <label class="field wide">Motif obligatoire<textarea v-model="stockForm.reason" class="input" rows="2" required @input="stockPreview = null"></textarea></label>
            <button class="btn ghost" type="submit" :disabled="Number(stockForm.quantity) === 0 || !stockForm.reason.trim() || busy === 'stock-preview'">Prévisualiser l’impact</button>
          </form>
          <div v-if="stockPreview" class="stock-impact" aria-live="polite">
            <strong>Impact avant confirmation</strong>
            <span>En stock : {{ stockPreview.before?.on_hand }} → {{ stockPreview.after?.on_hand }}</span>
            <span>Engagé : {{ stockPreview.before?.engaged }} → {{ stockPreview.after?.engaged }}</span>
            <span>Disponible à la vente : {{ stockPreview.before?.available_to_sell }} → {{ stockPreview.after?.available_to_sell }}</span>
            <p>{{ stockPreview.explanation }}</p>
            <button class="btn primary" type="button" :disabled="stockPreview.blocked || busy === 'stock'" @click="createStockMovement">Confirmer la variation</button>
            <small v-if="stockPreview.blocked" class="catalog-signal catalog-signal--danger">Mouvement refusé : il rendrait le disponible négatif.</small>
          </div>
          <footer class="catalog-modal-actions"><button class="btn ghost" type="button" @click="closeInventoryAdjustment">Annuler</button></footer>
        </section>
      </div>

      <div v-if="variantEditModalOpen" class="catalog-modal-backdrop" role="presentation" @click.self="closeVariantEditModal">
        <section class="catalog-modal" role="dialog" aria-modal="true" :aria-label="selectedVariant ? 'Modifier la variante' : 'Créer une variante'">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">Variante</p>
              <h2>{{ selectedVariant?.name || selectedVariant?.sku || 'Nouvelle variante' }}</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeVariantEditModal">×</button>
          </header>

          <div v-if="selectedVariant || selectedProductId" class="catalog-section">
            <div class="catalog-form-grid">
              <label class="field">SKU<input v-model="variantForm.sku" class="input" data-variant-field="sku" :disabled="!canWrite"></label>
              <label class="field">Nom<input v-model="variantForm.name" class="input" :disabled="!canWrite"></label>
              <label class="field">Barcode<input v-model="variantForm.barcode" class="input" :disabled="!canWrite"></label>
              <label class="field">Statut
                <select v-model="variantForm.status" class="select" :disabled="!canWrite">
                  <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                </select>
              </label>
              <label class="field wide">Commentaire Vente / POS<textarea v-model="variantForm.sales_note" class="input" rows="3" :disabled="!canWrite"></textarea></label>
              <template v-if="!selectedVariant">
                <label class="field">Stock initial<input v-model="variantForm.stock_quantity" class="input" type="number" step="1" :disabled="!canWrite"></label>
                <label class="checkbox-inline"><input v-model="variantForm.track_stock" type="checkbox" :disabled="!canWrite"> Suivi stock</label>
                <label class="checkbox-inline"><input v-model="variantForm.allow_backorder" type="checkbox" :disabled="!canWrite"> Livraison différée</label>
                <label class="field">Délai hors stock<input v-model="variantForm.backorder_delivery_days" class="input" type="number" min="1" step="1" :disabled="!canWrite || !variantForm.allow_backorder" placeholder="Hérite du produit"></label>
              </template>
            </div>
          </div>
          <p v-else class="muted">Aucun produit sélectionné.</p>

          <footer class="catalog-modal-actions">
            <button class="btn ghost" type="button" @click="closeVariantEditModal">Fermer</button>
            <button
              class="btn primary"
              type="button"
              :disabled="!canWrite || (!selectedVariant && !selectedProductId) || busy === 'variant' || busy === `variant-${selectedVariant?.id}`"
              @click="selectedVariant ? saveSelectedVariant() : createVariant()"
            >{{ selectedVariant ? 'Enregistrer variante' : 'Créer la variante' }}</button>
          </footer>
        </section>
      </div>

      <div v-if="variantPriceModalOpen" class="catalog-modal-backdrop" role="presentation" @click.self="closeVariantPriceModal">
        <section class="catalog-modal" role="dialog" aria-modal="true" aria-label="Ajustements de prix par variante">
          <header class="catalog-modal-head">
            <div>
              <p class="eyebrow">Prix</p>
              <h2>Ajustements par variante</h2>
            </div>
            <button class="catalog-modal-close" type="button" aria-label="Fermer" @click="closeVariantPriceModal">×</button>
          </header>
          <div v-if="selectedVariants.length > 0" class="catalog-section">
            <p class="muted">Les prix de base restent sur le produit. Ces ajustements s’appliquent uniquement à la variante sélectionnée.</p>
            <div class="catalog-form-grid">
              <label class="field wide">Variante
                <select class="select" :value="selectedVariant?.id || ''" :disabled="!canPriceWrite" @change="selectVariantById(eventValue($event))">
                  <option v-for="variant in selectedVariants" :key="variant.id" :value="variant.id">{{ variantDisplayName(variant) }}</option>
                </select>
              </label>
              <label v-if="canPurchaseRead" class="field">Ajustement achat
                <select v-model="variantForm.purchase_adjustment_type" class="select" :disabled="!canPriceWrite || !selectedVariant">
                  <option v-for="type in adjustmentTypes" :key="`modal-purchase-${type}`" :value="type">{{ type }}</option>
                </select>
              </label>
              <label v-if="canPurchaseRead" class="field">Valeur achat
                <span class="catalog-positive-input">
                  <span aria-hidden="true">+</span>
                  <input v-model="variantForm.purchase_adjustment_value" type="number" min="0" step="0.01" class="input" :disabled="!canPriceWrite || !selectedVariant || variantForm.purchase_adjustment_type === 'none'">
                </span>
              </label>
              <label class="field">Ajustement vente
                <select v-model="variantForm.sale_adjustment_type" class="select" :disabled="!canPriceWrite || !selectedVariant">
                  <option v-for="type in adjustmentTypes" :key="`modal-sale-${type}`" :value="type">{{ type }}</option>
                </select>
              </label>
              <label class="field">Valeur vente
                <span class="catalog-positive-input">
                  <span aria-hidden="true">+</span>
                  <input v-model="variantForm.sale_adjustment_value" type="number" min="0" step="0.01" class="input" :disabled="!canPriceWrite || !selectedVariant || variantForm.sale_adjustment_type === 'none'">
                </span>
              </label>
            </div>
            <div v-if="selectedVariant?.computed_prices" class="catalog-quality-strip">
              <span v-if="canPurchaseRead" class="catalog-signal catalog-signal--muted">Achat {{ selectedVariant.computed_prices.regular_purchase_price || '—' }}</span>
              <span class="catalog-signal catalog-signal--muted">Vente {{ selectedVariant.computed_prices.final_sale_price || selectedVariant.computed_prices.regular_sale_price || '—' }}</span>
            </div>
          </div>
          <p v-else class="muted">Créez une variante pour appliquer un ajustement de prix par taille, couleur ou option.</p>
          <footer class="catalog-modal-actions">
            <button class="btn ghost" type="button" @click="closeVariantPriceModal">Annuler</button>
            <button class="btn primary" type="button" :disabled="!canPriceWrite || !selectedVariant || busy === `variant-${selectedVariant?.id}`" @click="saveSelectedVariantAdjustments">Enregistrer ajustements</button>
          </footer>
        </section>
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

.catalog-products-shell,
.catalog-products-panel {
  display: grid;
  gap: .9rem;
}

.catalog-products-panel {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 8px;
  background: #fff;
  padding: 1rem;
}

.catalog-toolbar,
.catalog-toolbar-buttons,
.catalog-quick-filters,
.catalog-active-filters {
  display: flex;
  gap: .55rem;
  align-items: center;
  flex-wrap: wrap;
}

.catalog-toolbar {
  display: grid;
  grid-template-columns: minmax(280px, 1fr) auto;
}

.catalog-toolbar-buttons {
  justify-content: flex-end;
}

.catalog-search-control {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: .55rem;
  min-height: 2.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, .04), inset 0 1px 0 rgba(255, 255, 255, .85);
  padding: .25rem .45rem .25rem .85rem;
}

.catalog-search-control:focus-within {
  border-color: #2563eb;
  background: #fff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
}

.catalog-search-control svg {
  width: 1.05rem;
  height: 1.05rem;
  color: #64748b;
}

.catalog-search-control input {
  appearance: none;
  -webkit-appearance: none;
  width: 100%;
  min-width: 0;
  min-height: 2.1rem;
  border: 0;
  outline: 0 !important;
  box-shadow: none !important;
  background: transparent;
  color: #0f172a;
  font: inherit;
}

.catalog-search-control button {
  width: 1.85rem;
  height: 1.85rem;
  border: 0;
  border-radius: 999px;
  background: #e2e8f0;
  color: #334155;
  line-height: 1;
}

.catalog-quick-filters {
  gap: .35rem;
}

.catalog-quick-filters button {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 999px;
  background: #fff;
  color: #344054;
  min-height: 1.95rem;
  padding: .25rem .65rem;
  font-size: .84rem;
}

.catalog-quick-filters button.active {
  border-color: #2563eb;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 700;
}

.catalog-filter-chip {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  min-height: 1.85rem;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  padding: .22rem .6rem .22rem .38rem;
}

.catalog-filter-chip span {
  display: inline-grid;
  place-items: center;
  width: 1.05rem;
  height: 1.05rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1e40af;
  font-size: .9rem;
  line-height: 1;
}

.catalog-filter-chip strong {
  font-size: .82rem;
  font-weight: 800;
  line-height: 1.1;
}

.catalog-layout {
  display: grid;
  grid-template-columns: minmax(540px, 1.18fr) minmax(380px, .82fr);
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

.catalog-bulk-panel {
  border: 1px solid #dbe3ec;
  border-radius: .75rem;
  background: #f8fafc;
  display: grid;
  gap: .65rem;
  padding: .75rem;
}

.catalog-bulk-panel__summary,
.catalog-bulk-grid {
  display: flex;
  align-items: end;
  gap: .65rem;
  flex-wrap: wrap;
}

.catalog-bulk-panel__summary {
  justify-content: space-between;
  align-items: center;
}

.catalog-bulk-grid label {
  color: #475467;
  display: grid;
  font-size: .82rem;
  gap: .25rem;
  min-width: 9rem;
}

.catalog-bulk-checkbox {
  align-self: center;
  min-width: auto;
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

.catalog-products-table {
  border: 1px solid #eef2f6;
  border-radius: 8px;
  background: #fff;
  overflow: visible;
}

.catalog-table {
  width: 100%;
  min-width: 1080px;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
  font-size: .92rem;
}

.catalog-table th,
.catalog-table td {
  border-bottom: 1px solid #eef2f6;
  padding: .5rem .45rem;
  text-align: left;
  vertical-align: top;
}

.catalog-select-col {
  width: 2.4rem;
  text-align: center;
}

.catalog-table th {
  color: #667085;
  font-size: .78rem;
  text-transform: uppercase;
  background: #f8fafc;
  white-space: nowrap;
}

.catalog-sort-button {
  align-items: center;
  background: transparent;
  border: 0;
  color: inherit;
  display: inline-flex;
  font: inherit;
  gap: .32rem;
  min-width: 0;
  padding: 0;
  text-align: left;
  text-transform: inherit;
}

.catalog-sort-button span {
  align-items: center;
  border: 1px solid #d0d5dd;
  border-radius: 999px;
  color: #98a2b3;
  display: inline-flex;
  font-size: .68rem;
  height: 1.05rem;
  justify-content: center;
  line-height: 1;
  width: 1.05rem;
}

.catalog-sort-button span.active {
  border-color: #0f172a;
  color: #0f172a;
}

.catalog-pagination {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: .75rem;
  justify-content: space-between;
  padding: .75rem .1rem 0;
}

.catalog-pagination label,
.catalog-pagination-actions {
  align-items: center;
  display: inline-flex;
  gap: .5rem;
}

.catalog-pagination label {
  color: #667085;
  font-size: .84rem;
  font-weight: 700;
}

.catalog-pagination .select {
  min-width: 5.5rem;
}

.catalog-pagination > span,
.catalog-pagination-actions strong {
  color: #475467;
  font-size: .84rem;
  white-space: nowrap;
}

.catalog-table-row {
  cursor: pointer;
}

.catalog-table-row.is-archived {
  cursor: default;
}

.catalog-table-row:hover,
.catalog-table-row.active {
  background: #f8fafc;
}

.catalog-product-cell {
  min-width: 14rem;
}

.catalog-sku-cell {
  width: 9rem;
}

.catalog-channel-col {
  width: 13rem;
}

.catalog-status-col {
  width: 5.8rem;
}

.catalog-status-col .badge.active {
  background: #fff;
  border: 1px solid #344054;
  color: #344054;
}

.catalog-status-col .badge.draft {
  background: #b45309;
  border: 1px solid #b45309;
  color: #fff;
}

.catalog-status-col .badge.archived {
  background: #344054;
  border: 1px solid #344054;
  color: #fff;
}

.catalog-sku-cell code {
  background: #f8fafc;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  color: #475467;
  display: inline-block;
  font-size: .78rem;
  max-width: 100%;
  overflow-wrap: anywhere;
  padding: .12rem .35rem;
}

.catalog-product-name {
  border: 0;
  background: transparent;
  color: #0f172a;
  font-weight: 700;
  line-height: 1.2;
  min-width: 0;
  overflow-wrap: anywhere;
  padding: 0;
  text-align: left;
}

.catalog-product-name:hover,
.catalog-product-name:focus-visible {
  text-decoration: underline;
  text-underline-offset: .16em;
}

.catalog-product-name--static,
.catalog-product-name--static:hover,
.catalog-product-name--static:focus-visible {
  display: block;
  text-decoration: none;
}

.catalog-product-cell strong,
.catalog-product-cell small,
.catalog-table td > span,
.catalog-table td > small {
  display: block;
}

.catalog-cell-inline {
  display: flex;
  align-items: center;
  gap: .35rem;
  min-height: 1.65rem;
}

.catalog-variant-cell {
  position: relative;
  width: 10rem;
}

.catalog-variant-trigger {
  align-items: center;
  display: inline-flex;
  gap: .25rem;
  white-space: nowrap;
}

.catalog-variant-count,
.catalog-variant-toggle {
  border: 0;
  background: transparent;
  color: #344054;
  font: inherit;
}

.catalog-variant-count {
  font-weight: 800;
  padding: .2rem .15rem;
}

.catalog-variant-toggle {
  align-items: center;
  display: inline-flex;
  font-size: .9rem;
  height: 1.55rem;
  justify-content: center;
  line-height: 1;
  padding: 0;
  width: 1.55rem;
}

.catalog-variant-count:hover,
.catalog-variant-toggle:hover {
  color: #0f172a;
}

.catalog-stock-cell {
  position: relative;
}

.catalog-stock-display {
  align-items: center;
  display: inline-flex;
  gap: .45rem;
  white-space: nowrap;
}

.catalog-stock-indicator {
  border: 0;
  border-radius: 999px;
  display: inline-flex;
  flex: 0 0 auto;
  height: .72rem;
  padding: 0;
  width: .72rem;
}

.catalog-stock-indicator:disabled {
  cursor: default;
}

.catalog-stock-dot {
  border-radius: 999px;
  display: inline-flex;
  flex: 0 0 auto;
  height: .62rem;
  width: .62rem;
}

.catalog-stock-indicator--success,
.catalog-stock-dot--success {
  background: #16a34a;
}

.catalog-stock-indicator--warning,
.catalog-stock-dot--warning {
  background: #f59e0b;
}

.catalog-stock-indicator--danger,
.catalog-stock-dot--danger {
  background: #dc2626;
}

.catalog-stock-indicator--muted,
.catalog-stock-dot--muted {
  background: #98a2b3;
}

.catalog-stock-menu-panel {
  background: #fff;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  box-shadow: 0 16px 36px rgba(15, 23, 42, .16);
  display: grid;
  gap: .25rem;
  left: 0;
  min-width: 15rem;
  padding: .45rem;
  position: absolute;
  top: calc(100% + .25rem);
  z-index: 30;
}

.catalog-stock-menu-row {
  align-items: center;
  border-radius: 6px;
  display: grid;
  gap: .5rem;
  grid-template-columns: auto minmax(0, 1fr);
  padding: .35rem .5rem;
}

.catalog-stock-menu-row:hover {
  background: #f8fafc;
}

.catalog-stock-menu-row div {
  display: grid;
  min-width: 0;
}

.catalog-stock-menu-row strong {
  color: #0f172a;
  overflow-wrap: anywhere;
}

.catalog-stock-menu-row small {
  color: #667085;
  font-weight: 700;
}

.catalog-variant-menu-panel {
  background: #fff;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  box-shadow: 0 16px 36px rgba(15, 23, 42, .16);
  display: grid;
  gap: .25rem;
  left: 0;
  min-width: 16rem;
  padding: .45rem;
  position: absolute;
  top: calc(100% + .25rem);
  z-index: 30;
}

.catalog-variant-menu-row,
.catalog-variant-summary-row {
  align-items: center;
  display: grid;
  gap: .5rem;
  grid-template-columns: minmax(0, 1fr) auto;
}

.catalog-variant-summary-row {
  border: 0;
  background: #fff;
  padding: .35rem 0;
}

.catalog-variant-menu-row {
  border-radius: 6px;
  padding: .35rem .35rem .35rem .5rem;
}

.catalog-variant-menu-row:hover {
  background: #f8fafc;
}

.catalog-variant-menu-row strong,
.catalog-variant-summary-row strong {
  color: #0f172a;
  overflow-wrap: anywhere;
}

.catalog-variant-menu-main {
  display: grid;
  gap: .1rem;
  min-width: 0;
}

.catalog-variant-menu-main small {
  color: #98a2b3;
  font-size: .68rem;
  font-weight: 800;
  letter-spacing: 0;
  line-height: 1.2;
  overflow-wrap: anywhere;
}

.catalog-variant-menu-row strong.is-archived {
  color: #d98b8b;
}

.catalog-variant-menu-row strong.is-inactive {
  color: #98a2b3;
}

.catalog-variant-menu-actions {
  align-items: center;
  display: inline-flex;
  gap: .15rem;
}

.catalog-icon-button {
  align-items: center;
  background: transparent;
  border: 0;
  border-radius: 6px;
  color: #475467;
  display: inline-flex;
  height: 1.75rem;
  justify-content: center;
  padding: 0;
  width: 1.75rem;
}

.catalog-icon-button:hover,
.catalog-icon-button:focus-visible {
  background: #f1f5f9;
  color: #0f172a;
}

.catalog-icon-button:disabled {
  cursor: not-allowed;
  opacity: .45;
}

.catalog-icon-button--danger {
  color: #b42318;
}

.catalog-icon-button--danger:hover,
.catalog-icon-button--danger:focus-visible {
  background: #fef3f2;
  color: #912018;
}

.catalog-icon-button--muted {
  color: #667085;
}

.catalog-cell-inline--wrap {
  flex-wrap: wrap;
}

.catalog-signal-row,
.catalog-quality-strip,
.catalog-detail-nav {
  display: flex;
  flex-wrap: wrap;
  gap: .35rem;
  align-items: center;
}

.catalog-signal-row {
  margin-top: .35rem;
}

.catalog-signal {
  border: 1px solid #d8dee8;
  border-radius: 999px;
  color: #475467;
  display: inline-flex;
  font-size: .74rem;
  font-weight: 700;
  line-height: 1;
  padding: .28rem .45rem;
  white-space: nowrap;
}

.catalog-signal--success {
  background: #ecfdf3;
  border-color: #abefc6;
  color: #067647;
}

.catalog-signal--warning {
  background: #fffaeb;
  border-color: #fedf89;
  color: #b54708;
}

.catalog-signal--danger {
  background: #fef3f2;
  border-color: #fecdca;
  color: #b42318;
}

.catalog-signal--muted {
  background: #f2f4f7;
  border-color: #d0d5dd;
  color: #344054;
}

.catalog-signal-button {
  cursor: pointer;
  font-family: inherit;
}

.catalog-linked-offers {
  border-top: 1px solid #eaecf0;
  margin-top: .85rem;
  padding-top: .85rem;
}

.catalog-image-state,
.catalog-completeness {
  color: #067647;
  font-weight: 700;
}

.catalog-image-state.is-missing,
.catalog-completeness.is-low {
  color: #b54708;
}

.catalog-actions-cell {
  min-width: 7rem;
  border-bottom-color: transparent !important;
  cursor: default;
}

.catalog-actions-cell .btn,
.catalog-actions-cell .btn:hover,
.catalog-actions-cell .btn:focus-visible,
.catalog-actions-cell summary {
  text-decoration: none;
}

.catalog-menu {
  position: relative;
  z-index: 3;
}

.catalog-menu[open] {
  z-index: 20;
}

.catalog-menu summary {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 6px;
  cursor: pointer;
  list-style: none;
  min-width: 2.1rem;
  min-height: 2.1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  user-select: none;
}

.catalog-menu summary::-webkit-details-marker {
  display: none;
}

.catalog-icon-summary svg,
.catalog-row-menu-summary svg {
  width: 1.1rem;
  height: 1.1rem;
  fill: currentColor;
}

.catalog-menu-panel {
  position: absolute;
  right: 0;
  z-index: 8;
  min-width: 170px;
  margin-top: .35rem;
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 14px 28px rgba(15, 23, 42, .16);
  padding: .35rem;
  display: grid;
  gap: .2rem;
}

.catalog-menu-panel button,
.catalog-menu-panel a {
  border: 0;
  background: transparent;
  border-radius: 6px;
  color: #344054;
  padding: .45rem .55rem;
  text-align: left;
  text-decoration: none;
}

.catalog-menu-panel button:not(:disabled):hover,
.catalog-menu-panel a:hover {
  background: #f2f4f7;
}

.catalog-menu-panel button:disabled {
  color: #98a2b3;
}

.catalog-menu-panel .danger-action {
  color: #b42318;
}

.catalog-menu-panel .danger-action:not(:disabled):hover {
  background: #fef3f2;
}

.catalog-filter-panel {
  min-width: 280px;
}

.catalog-filter-panel label {
  display: grid;
  gap: .25rem;
  color: #344054;
  padding: .35rem .45rem;
}

.catalog-filter-panel label:has(input[type="checkbox"]) {
  grid-template-columns: auto 1fr;
  align-items: center;
}

.catalog-filter-actions {
  display: flex;
  gap: .45rem;
  padding: .35rem .45rem;
}

.catalog-filter-panel select,
.catalog-column-panel label {
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 6px;
  min-height: 2.25rem;
  padding: .4rem .55rem;
}

.catalog-column-panel {
  min-width: 210px;
}

.catalog-column-panel strong,
.catalog-filter-panel strong {
  color: #344054;
  font-size: .82rem;
  padding: .3rem .45rem;
}

.catalog-column-panel label {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: .45rem;
  align-items: center;
  border: 0;
  color: #344054;
}

.catalog-detail-nav {
  border-bottom: 1px solid #e5e7eb;
  margin: .5rem 0 1rem;
  padding-bottom: .75rem;
}

.catalog-detail-nav .btn.active {
  background: #0f172a;
  color: #fff;
}

.catalog-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .65rem;
  margin-bottom: .85rem;
}

.catalog-summary-card {
  border: 1px solid #e5e7eb;
  border-radius: .75rem;
  background: #f8fafc;
  padding: .7rem;
}

.catalog-summary-card span {
  color: #64748b;
  display: block;
  font-size: .78rem;
  font-weight: 700;
  margin-bottom: .25rem;
}

.catalog-summary-card strong {
  color: #0f172a;
  font-size: 1rem;
}

.catalog-section-focus {
  border: 1px solid rgba(37, 99, 235, .32);
  border-radius: .75rem;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, .08);
  padding: .75rem;
}

.catalog-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
}

.catalog-form-grid > *,
.offer-wizard-steps > *,
.catalog-modal :where(input, select, textarea) {
  min-width: 0;
  max-width: 100%;
}

.catalog-form-grid .wide {
  grid-column: 1 / -1;
}

.catalog-price-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
  align-items: end;
}

.catalog-positive-input {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: .4rem;
}

.catalog-positive-input > span {
  color: #0f172a;
  font-weight: 700;
}

.catalog-option-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: .45rem;
  align-items: center;
}

.catalog-option-button {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #fff;
  color: #334155;
  padding: .35rem .65rem;
  font-size: .86rem;
  font-weight: 650;
  line-height: 1;
  cursor: pointer;
}

.catalog-option-button input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.catalog-option-button.active {
  border-color: #0f172a;
  background: #f8fafc;
  color: #0f172a;
  box-shadow: inset 0 0 0 1px #0f172a;
}

.catalog-option-button:disabled {
  cursor: not-allowed;
  opacity: .55;
}

.catalog-color-dot {
  width: .75rem;
  height: .75rem;
  border: 1px solid rgba(15, 23, 42, .22);
  border-radius: 999px;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .45);
}

.catalog-color-field {
  display: grid;
  gap: .4rem;
}

.catalog-color-picker {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  gap: .45rem;
  align-items: center;
}

.catalog-color-picker input[type="color"] {
  width: 2.75rem;
  height: 2.4rem;
  border: 0;
  border-radius: .6rem;
  background: #fff;
  padding: 0;
}

.catalog-color-swatches {
  display: flex;
  flex-wrap: wrap;
  gap: .35rem;
}

.catalog-color-swatches button {
  width: 1.35rem;
  height: 1.35rem;
  border: 1px solid #94a3b8;
  border-radius: 999px;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .65);
}

.catalog-color-swatches button.active {
  outline: 2px solid #0f172a;
  outline-offset: 2px;
}

.catalog-read-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
}

.catalog-read-grid > div,
.catalog-read-grid > button {
  border: 1px solid #e5e7eb;
  border-radius: .75rem;
  background: #f8fafc;
  padding: .7rem;
}

.catalog-read-grid > button {
  text-align: left;
}

.catalog-read-grid > button:hover,
.catalog-compact-row:hover,
.catalog-clickable-section:hover {
  border-color: #bfdbfe;
  background: #eff6ff;
}

.catalog-read-grid .wide {
  grid-column: 1 / -1;
}

.catalog-read-grid span {
  color: #64748b;
  display: block;
  font-size: .78rem;
  font-weight: 700;
  margin-bottom: .25rem;
}

.catalog-read-grid strong,
.catalog-read-grid p {
  color: #0f172a;
  margin: 0;
  overflow-wrap: anywhere;
}

.catalog-variant-count {
  white-space: nowrap;
}

.catalog-asset-overview {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .5rem;
}

.catalog-asset-overview div {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #fff;
  padding: .55rem;
}

.catalog-asset-overview span {
  color: #64748b;
  display: block;
  font-size: .76rem;
  font-weight: 700;
  margin-bottom: .2rem;
}

.catalog-asset-manager {
  display: grid;
  gap: .75rem;
}

.catalog-storefront-media-preview {
  align-items: start;
  background: linear-gradient(135deg, #f8fafc, #eff6ff);
  border: 1px solid #bfdbfe;
  border-radius: 10px;
  display: grid;
  gap: .75rem;
  grid-template-columns: minmax(13rem, .8fr) minmax(16rem, 1.2fr);
  padding: .8rem;
}

.catalog-storefront-media-preview h4,
.catalog-storefront-media-preview p { margin: 0; }

.catalog-storefront-media-preview__content { display: grid; gap: .5rem; grid-template-columns: minmax(8rem, 1fr) minmax(7rem, .7fr); }
.catalog-storefront-media-preview__main { align-items: center; aspect-ratio: 4 / 3; background: #fff; border: 1px solid #dbeafe; border-radius: 8px; display: flex; justify-content: center; overflow: hidden; }
.catalog-storefront-media-preview__main img { height: 100%; object-fit: contain; width: 100%; }
.catalog-storefront-media-preview__thumbs { align-content: start; display: grid; gap: .35rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
.catalog-storefront-media-preview__thumbs button { aspect-ratio: 1; background: #fff; border: 1px solid #cbd5e1; border-radius: 7px; cursor: pointer; overflow: hidden; padding: 0; }
.catalog-storefront-media-preview__thumbs img { height: 100%; object-fit: cover; width: 100%; }
.catalog-storefront-media-preview__empty { background: #fff; border: 1px dashed #93c5fd; border-radius: 8px; display: grid; gap: .25rem; padding: 1rem; }
.catalog-storefront-media-preview__empty span { color: #64748b; }

.catalog-media-presets { display: grid; gap: .5rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
.catalog-media-preset { background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; color: #0f172a; cursor: pointer; display: grid; gap: .2rem; padding: .65rem; text-align: left; }
.catalog-media-preset:hover,.catalog-media-preset:focus-visible { border-color: #2563eb; box-shadow: 0 0 0 2px #dbeafe; }
.catalog-media-preset:disabled { cursor: not-allowed; opacity: .55; }
.catalog-media-preset span { color: #64748b; font-size: .78rem; }

.panel__header.compact {
  margin: 0;
}

.panel__header.compact h3,
.panel__header.compact p {
  margin: 0;
}

.catalog-asset-groups {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: .65rem;
}

.catalog-asset-group {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #f8fafc;
  display: grid;
  align-content: start;
  gap: .45rem;
  padding: .65rem;
}

.catalog-asset-group h4 {
  color: #0f172a;
  font-size: .88rem;
  margin: 0;
}

.catalog-asset-group--details summary { cursor: pointer; font-size: .88rem; font-weight: 700; }
.catalog-asset-empty-action { background: #fff; border: 1px dashed #94a3b8; border-radius: 7px; color: #1d4ed8; cursor: pointer; padding: .65rem; text-align: left; }

.catalog-asset-card {
  align-items: center;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
  display: grid;
  gap: .5rem;
  grid-template-columns: 42px minmax(0, 1fr) auto;
  padding: .45rem;
}

.catalog-asset-card img {
  width: 42px;
  height: 42px;
  border-radius: 6px;
  object-fit: cover;
}

.catalog-asset-card strong,
.catalog-asset-card span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-asset-card span {
  color: #64748b;
  font-size: .78rem;
}

.catalog-asset-editor {
  border: 1px solid #dbeafe;
  border-radius: 8px;
  background: #eff6ff;
  padding: .75rem;
}

.catalog-asset-editor__head { align-items: start; display: flex; gap: 1rem; justify-content: space-between; margin-bottom: .75rem; }
.catalog-asset-editor__head h4,.catalog-asset-editor__head p { margin: 0; }
.catalog-asset-editor__head p { color: #475569; font-size: .85rem; }

@media (max-width: 760px) {
  .catalog-storefront-media-preview { grid-template-columns: 1fr; }
  .catalog-media-presets { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .catalog-asset-editor__head { flex-direction: column; }
}

.catalog-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1050;
  display: grid;
  place-items: start center;
  background: rgba(15, 23, 42, .42);
  padding: 1rem;
  overflow-y: auto;
  overflow-x: hidden;
}
.catalog-modal-backdrop--inventory {
  z-index: 1090;
}

.catalog-modal {
  box-sizing: border-box;
  width: min(920px, 100%);
  max-width: 100%;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
  margin: auto 0;
  overflow: visible;
  padding: 1rem;
}

.catalog-stock-operation {
  border-top: 1px solid #e5e7eb;
  margin-top: 1rem;
  padding-top: 1rem;
}

.catalog-modal--view {
  width: min(1060px, 100%);
}
.catalog-modal--inventory {
  display: grid;
  gap: 1rem;
  width: min(640px, 100%);
}
.catalog-variant-stock-list {
  display: grid;
  gap: .65rem;
}
.catalog-variant-stock-list article {
  align-items: center;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  padding: .75rem;
}

.catalog-modal--reference {
  width: min(980px, 100%);
}

.catalog-modal--attributes {
  width: min(1180px, 100%);
}

.catalog-modal-head,
.catalog-modal-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
}

.catalog-modal-head {
  border-bottom: 1px solid #eef2f6;
  margin-bottom: 1rem;
  padding-bottom: .75rem;
}

.catalog-modal-head h2 {
  margin: 0;
}

.catalog-modal-close {
  width: 2.25rem;
  height: 2.25rem;
  border: 1px solid var(--business-border, #d0d5dd);
  border-radius: 999px;
  background: #fff;
  color: #344054;
  font-size: 1.35rem;
  line-height: 1;
}

.catalog-modal-actions {
  border-top: 1px solid #eef2f6;
  justify-content: flex-end;
  margin-top: 1rem;
  padding-top: .75rem;
}

.catalog-modal-actions.wide {
  grid-column: 1 / -1;
}

.catalog-reference-grid,
.catalog-reference-layout {
  display: grid;
  gap: .75rem;
}

.catalog-reference-grid {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.catalog-reference-layout {
  grid-template-columns: minmax(220px, 280px) 1fr;
}

.catalog-reference-list {
  display: grid;
  align-content: start;
  gap: .35rem;
  max-height: min(56vh, 520px);
  overflow: auto;
  padding-right: .25rem;
}

.catalog-reference-list button {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
  color: #0f172a;
  display: grid;
  gap: .15rem;
  padding: .55rem .65rem;
  text-align: left;
}

.catalog-reference-list button:hover {
  border-color: #bfdbfe;
  background: #eff6ff;
}

.catalog-reference-list span {
  color: #64748b;
  font-size: .78rem;
}

.catalog-reference-list.compact-list {
  max-height: 13rem;
}

.catalog-attribute-settings-grid {
  display: grid;
  grid-template-columns: minmax(220px, .8fr) minmax(360px, 1.25fr) minmax(260px, .95fr);
  gap: .75rem;
  align-items: start;
}

.catalog-attribute-settings-grid--single {
  grid-template-columns: minmax(0, 1fr);
}

.catalog-settings-card {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #f8fafc;
  display: grid;
  gap: .75rem;
  padding: .75rem;
}

.catalog-settings-card .catalog-reference-list button {
  background: #fff;
}

.catalog-form-grid--single {
  grid-template-columns: 1fr;
}

.catalog-flag-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .4rem .65rem;
}

.catalog-definition-warnings {
  margin-bottom: .75rem;
}

.catalog-attribute-manager {
  display: grid;
  gap: .75rem;
}

.catalog-attribute-groups {
  display: grid;
  gap: .75rem;
}

.catalog-attribute-scope {
  border: 1px solid #dbeafe;
  border-radius: 8px;
  background: #eff6ff;
  display: grid;
  gap: .75rem;
  padding: .75rem;
}

.catalog-attribute-scope--variant {
  background: #f8fafc;
  border-color: #e5e7eb;
}

.catalog-attribute-scope-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: .65rem;
  align-items: end;
}

.catalog-attribute-scope-head h4,
.catalog-attribute-scope-head p {
  margin: 0;
}

.catalog-attribute-scope-head h4 {
  color: #0f172a;
  font-size: 1rem;
}

.catalog-attribute-scope-head .catalog-variant-picker {
  min-width: 220px;
}

.catalog-attribute-group {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #f8fafc;
  display: grid;
  gap: .6rem;
  padding: .7rem;
}

.catalog-attribute-group h5 {
  color: #0f172a;
  font-size: .9rem;
  margin: 0;
}

.catalog-attribute-row {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
  display: grid;
  grid-template-columns: minmax(180px, .85fr) minmax(180px, 1fr) minmax(180px, 1fr);
  gap: .65rem;
  padding: .65rem;
}

.catalog-attribute-row--single {
  grid-template-columns: minmax(180px, .9fr) minmax(220px, 1fr);
}

.catalog-attribute-row strong,
.catalog-attribute-row span {
  display: block;
}

.catalog-attribute-row > div > span {
  color: #64748b;
  font-size: .8rem;
  margin-top: .12rem;
}

.catalog-modal-sections {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
  margin-top: .85rem;
}

.catalog-modal-sections section,
.catalog-compact-row {
  border: 1px solid #e5e7eb;
  border-radius: .75rem;
  background: #f8fafc;
  padding: .7rem;
}

.catalog-modal-sections h3 {
  margin: 0 0 .55rem;
  color: #0f172a;
  font-size: .95rem;
}

.catalog-compact-list {
  display: grid;
  gap: .4rem;
}

.catalog-compact-row {
  display: grid;
  gap: .15rem;
  background: #fff;
  text-align: left;
  width: 100%;
}

.catalog-compact-row span {
  color: #667085;
  font-size: .84rem;
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

.catalog-offers-table-wrap.table-wrap {
  overflow: visible;
}

.catalog-offers-table-wrap .catalog-menu-panel {
  z-index: 60;
}

.catalog-products-table.table-wrap {
  overflow: visible;
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

.stock-operation {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
  padding: .85rem;
  border: 1px solid #dbe4ef;
  border-radius: .9rem;
  background: #f8fafc;
}

.stock-operation .wide,
.stock-operation .btn {
  grid-column: 1 / -1;
}

.stock-impact {
  display: grid;
  gap: .45rem;
  padding: .85rem;
  border: 1px solid #93c5fd;
  border-radius: .9rem;
  background: #eff6ff;
}

.offer-wizard-steps {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: .35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.offer-wizard-steps button {
  width: 100%;
  min-height: 3rem;
  border: 1px solid #dbe4ef;
  border-radius: .65rem;
  background: #fff;
  font-size: .78rem;
}

.offer-wizard-steps li.active button { border-color: #2563eb; background: #eff6ff; font-weight: 700; }
.offer-wizard-steps li.done button { border-color: #86efac; }
.offer-wizard-panel { min-height: 12rem; align-content: start; }
.offer-preview { display: grid; gap: .45rem; padding: .85rem; border: 1px solid #93c5fd; border-radius: .8rem; background: #eff6ff; }

@media (max-width: 720px) {
  .stock-operation { grid-template-columns: 1fr; }
  .offer-wizard-steps { grid-template-columns: 1fr 1fr; }
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

.catalog-content-links-section {
  display: grid;
  gap: .75rem;
}

.catalog-content-link-form {
  display: grid;
  grid-template-columns: minmax(12rem, 2fr) repeat(3, minmax(8rem, 1fr));
  gap: .65rem;
  align-items: end;
}

.catalog-content-link-form .checkbox-inline {
  min-height: 2.5rem;
}

.bundle-strategies { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.55rem; border:0; padding:0; }
.bundle-strategies legend { grid-column:1/-1; font-weight:700; }
.bundle-strategy { display:flex; gap:.55rem; align-items:flex-start; padding:.7rem; border:1px solid #dbe3ef; border-radius:.75rem; background:#f8fafc; }
.bundle-strategy span { display:grid; gap:.2rem; }
.bundle-strategy small { color:#64748b; }
.bundle-estimate { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; padding:.85rem; border:1px solid #bfdbfe; border-radius:.8rem; background:#eff6ff; }
.bundle-estimate div { display:grid; }
.bundle-estimate ul { margin:0; color:#b91c1c; }

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
  .catalog-products-shell,
  .catalog-products-panel,
  .catalog-toolbar,
  .catalog-search-control {
    min-width: 0;
    max-width: 100%;
  }

  .catalog-toolbar {
    grid-template-columns: minmax(0, 1fr);
  }

  .catalog-products-table.table-wrap,
  .catalog-offers-table-wrap.table-wrap {
    overflow-x: auto;
    overflow-y: visible;
  }

  .catalog-form-grid,
  .catalog-price-grid,
  .option-grid {
    grid-template-columns: 1fr;
  }

  .catalog-content-link-form {
    grid-template-columns: 1fr;
  }

  .bundle-strategies { grid-template-columns:1fr; }
}
</style>
