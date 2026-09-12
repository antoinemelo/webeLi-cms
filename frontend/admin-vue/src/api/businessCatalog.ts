import { adminApi } from './client';

export type CatalogRecord = Record<string, unknown> & { id: number; name?: string; slug?: string; status?: string };
export type CatalogProduct = CatalogRecord & {
  sku_base?: string | null;
  type?: string;
  brand_id?: number | null;
  category_id?: number | null;
  tax_class_id?: number | null;
  is_public?: boolean;
  is_ecommerce_enabled?: boolean;
  is_pos_enabled?: boolean;
  is_catalogue_enabled?: boolean;
  track_stock?: boolean;
  allow_backorder?: boolean;
  backorder_delivery_days?: number | string | null;
  variant_count?: number;
  active_variant_count?: number;
  stock_quantity_total?: number | string | null;
  stock_reserved_total?: number | string | null;
  stock_tracked_variant_count?: number;
  low_stock_variant_count?: number;
  sale_price_min?: number | string | null;
  purchase_price_min?: number | string | null;
  image_count?: number;
  completeness_score?: number | null;
  is_sellable_summary?: boolean | null;
  missing_summary_json?: string | Array<Record<string, unknown>> | null;
};
export type CatalogVariant = CatalogRecord & {
  product_id?: number;
  sku?: string;
  barcode?: string | null;
  stock_quantity?: number;
  stock_reserved?: number;
  track_stock?: boolean | null;
  allow_backorder?: boolean | null;
  backorder_delivery_days?: number | string | null;
  sales_note?: string | null;
  computed_prices?: Record<string, unknown> | null;
  option_values?: Array<Record<string, unknown>>;
};
export type CatalogProductAsset = Record<string, unknown> & {
  id: number;
  asset_id?: number;
  product_id?: number;
  variant_id?: number | null;
  media_id?: number;
  role?: string;
  title?: string | null;
  alt_text?: string | null;
  caption?: string | null;
  sort_order?: number;
  is_public?: boolean;
  channel_scope?: string;
};
export type CatalogAttributeGroup = CatalogRecord & { code?: string; description?: string | null; sort_order?: number };
export type CatalogAttribute = CatalogRecord & {
  code?: string;
  group_id?: number;
  group_name?: string;
  data_type?: string;
  unit?: string | null;
  is_required?: boolean;
  is_filterable?: boolean;
  is_searchable?: boolean;
  is_public?: boolean;
  options?: Array<CatalogRecord & { code?: string; label?: string; value?: string; color_hex?: string | null }>;
};
export type CatalogAttributeValue = CatalogRecord & {
  attribute_id?: number;
  attribute_code?: string;
  attribute_name?: string;
  data_type?: string;
  language?: string;
  value?: unknown;
};
export type CatalogTaxClass = CatalogRecord & {
  code?: string;
  rate?: number | string;
  country?: string;
  is_default?: boolean;
  usage_count?: number;
};
export type CatalogDiscount = CatalogRecord & {
  discount_type?: string;
  discount_value?: number;
  scope_type?: string;
  scope_id?: number;
  channel?: string;
  starts_at?: string | null;
  ends_at?: string | null;
  priority?: number;
};
export type CatalogBundleComponent = Record<string, unknown> & {
  id: number;
  bundle_id?: number;
  component_product_id?: number;
  component_variant_id?: number | null;
  quantity?: number;
  is_required?: boolean;
  sort_order?: number;
  component_name?: string;
  component_variant_name?: string | null;
  component_sku?: string | null;
};
export type CatalogProductBundle = Record<string, unknown> & {
  id: number;
  bundle_product_id?: number;
  bundle_variant_id?: number | null;
  pricing_mode?: string;
  stock_mode?: string;
  stock_strategy?: 'OWN_STOCK' | 'COMPONENT_DERIVED' | 'NON_STOCKED';
  partial_availability_policy?: 'REQUIRE_ALL' | 'ALLOW_PARTIAL';
  partial_fulfillment_supported?: boolean;
  component_return_policy?: 'BUNDLE_ONLY' | 'COMPONENTS_ALLOWED';
  components_public?: boolean;
  stock_estimate?: Record<string, unknown>;
  inventory_plan?: Array<Record<string, unknown>>;
  configuration_errors?: string[];
  is_active?: boolean;
  components?: CatalogBundleComponent[];
};
export type ProductContentLink = Record<string, unknown> & {
  id: number;
  product_id?: number;
  content_entry_id?: number;
  entry_key?: string;
  content_type?: string;
  relation_type?: string;
  locale?: string | null;
  is_canonical?: boolean;
  status?: string;
  projection_ready?: boolean;
};
export type ProductContentCandidate = Record<string, unknown> & {
  id: number;
  entry_key?: string;
  title?: string | null;
  type_key?: string;
  status?: string;
};
export type ProductRelation = Record<string, unknown> & {
  id: number | string;
  related_product_id?: number;
  relation_type?: 'related' | 'alternative' | 'accessory' | 'upsell' | 'cross_sell';
  sort_order?: number;
  source?: 'manual' | 'automatic';
  target_name?: string;
  target_slug?: string;
  target_status?: string;
};
export type ProductRelationRule = Record<string, unknown> & {
  id: number;
  relation_type?: ProductRelation['relation_type'];
  match_type?: 'category' | 'group';
  match_id?: number;
  result_limit?: number;
  sort_order?: number;
};

export type ProductDetail = Record<string, unknown> & {
  data?: CatalogProduct;
  brand?: CatalogRecord | null;
  category?: CatalogRecord | null;
  attribute_groups?: CatalogAttributeGroup[];
  variants?: CatalogVariant[];
  prices?: Array<Record<string, unknown>>;
  stock?: Array<Record<string, unknown>>;
  offers?: CatalogDiscount[];
  options?: Array<Record<string, unknown>>;
  media?: Array<Record<string, unknown>>;
  tags?: Array<Record<string, unknown>>;
};
export type CatalogImportReport = Record<string, unknown> & {
  dry_run?: boolean;
  rows_total?: number;
  valid_rows?: number;
  skipped?: number;
  errors?: Array<Record<string, unknown>>;
  rows?: Array<Record<string, unknown>>;
  writes_performed?: boolean;
};
export type CatalogBulkReport = Record<string, unknown> & {
  dry_run?: boolean;
  product_ids?: number[];
  changes?: Record<string, unknown>;
  updated?: number;
  recalculated?: number;
  assigned_count?: number;
};

export const businessCatalogApi = {
  brandCompanies() {
    return adminApi.get<{ companies: CatalogRecord[] }>('/business/companies', { limit: 200 });
  },
  brands() {
    return adminApi.get<{ brands: CatalogRecord[] }>('/business/catalog/brands', { limit: 200 });
  },
  createBrand(payload: Record<string, unknown>) {
    return adminApi.post<{ brand: CatalogRecord }>('/business/catalog/brands', payload);
  },
  updateBrand(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ brand: CatalogRecord }>(`/business/catalog/brands/${id}`, payload);
  },
  deleteBrand(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean; id: number }>(`/business/catalog/brands/${id}`);
  },
  categories() {
    return adminApi.get<{ categories: CatalogRecord[] }>('/business/catalog/categories', { limit: 200 });
  },
  createCategory(payload: Record<string, unknown>) {
    return adminApi.post<{ category: CatalogRecord }>('/business/catalog/categories', payload);
  },
  updateCategory(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ category: CatalogRecord }>(`/business/catalog/categories/${id}`, payload);
  },
  deleteCategory(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean; id: number }>(`/business/catalog/categories/${id}`);
  },
  options() {
    return adminApi.get<{ options: CatalogRecord[] }>('/business/catalog/options', { limit: 200 });
  },
  products(query: Record<string, string | number | boolean | undefined>) {
    return adminApi.get<{ products: CatalogProduct[]; pagination?: Record<string, unknown> }>('/business/catalog/products', query);
  },
  exportCsvUrl(query: Record<string, string | number | boolean | undefined | null> = {}) {
    return adminApi.href('/business/catalog/export.csv', query);
  },
  exportPdfUrl(query: Record<string, string | number | boolean | undefined | null> = {}) {
    return adminApi.href('/business/catalog/export.pdf', query);
  },
  previewImport(payload: Record<string, unknown>) {
    return adminApi.post<{ import: CatalogImportReport }>('/business/catalog/import/preview', payload);
  },
  applyImport(payload: Record<string, unknown>) {
    return adminApi.post<{ import: CatalogImportReport; message?: string }>('/business/catalog/import/apply', payload);
  },
  product(id: number) {
    return adminApi.get<{ product: ProductDetail }>(`/business/catalog/products/${id}`);
  },
  createProduct(payload: Record<string, unknown>) {
    return adminApi.post<{ product: ProductDetail }>('/business/catalog/products', payload);
  },
  updateProduct(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ product: ProductDetail }>(`/business/catalog/products/${id}`, payload);
  },
  deleteProduct(id: number) {
    return adminApi.delete<{ deleted: false; archived: boolean; id: number }>(`/business/catalog/products/${id}`);
  },
  restoreProduct(id: number) {
    return adminApi.post<{ restored: boolean; archived: false; id: number }>(`/business/catalog/products/${id}/restore`, {});
  },
  purgeProduct(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: false; id: number }>(`/business/catalog/products/${id}/permanent`);
  },
  productAssets(id: number, query: Record<string, string | number | boolean | undefined> = {}) {
    return adminApi.get<{ assets: CatalogProductAsset[] }>(`/business/pim/products/${id}/assets`, query);
  },
  productContentLinks(id: number) {
    return adminApi.get<{ links: ProductContentLink[] }>(`/business/pim/products/${id}/content-links`);
  },
  productRelations(id: number) {
    return adminApi.get<{ relations: ProductRelation[]; rules: ProductRelationRule[] }>(`/business/pim/products/${id}/relations`);
  },
  createProductRelation(productId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ relation: ProductRelation }>(`/business/pim/products/${productId}/relations`, payload);
  },
  deleteProductRelation(id: number) {
    return adminApi.delete<{ deleted: boolean }>(`/business/pim/product-relations/${id}`);
  },
  createProductRelationRule(productId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ rule: ProductRelationRule }>(`/business/pim/products/${productId}/relation-rules`, payload);
  },
  deleteProductRelationRule(id: number) {
    return adminApi.delete<{ deleted: boolean }>(`/business/pim/product-relation-rules/${id}`);
  },
  contentCandidates(query = '') {
    return adminApi.get<{ contents: ProductContentCandidate[] }>('/business/pim/content-candidates', { q: query });
  },
  createProductContentLink(productId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ link: ProductContentLink }>(`/business/pim/products/${productId}/content-links`, payload);
  },
  updateProductContentLink(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ link: ProductContentLink }>(`/business/pim/content-links/${id}`, payload);
  },
  deleteProductContentLink(id: number) {
    return adminApi.delete<{ deleted: boolean; id: number }>(`/business/pim/content-links/${id}`);
  },
  assignProductAsset(productId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ asset: CatalogProductAsset }>(`/business/pim/products/${productId}/assets`, payload);
  },
  updateProductAsset(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ asset: CatalogProductAsset }>(`/business/pim/assets/${id}`, payload);
  },
  archiveProductAsset(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean }>(`/business/pim/assets/${id}`);
  },
  bulkUpdateProducts(payload: Record<string, unknown>) {
    return adminApi.post<{ bulk: CatalogBulkReport }>('/business/pim/products/bulk-update', payload);
  },
  taxClasses() {
    return adminApi.get<{ tax_classes: CatalogTaxClass[] }>('/business/pim/tax-classes');
  },
  createTaxClass(payload: Record<string, unknown>) {
    return adminApi.post<{ tax_class: CatalogTaxClass }>('/business/pim/tax-classes', payload);
  },
  updateTaxClass(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ tax_class: CatalogTaxClass }>(`/business/pim/tax-classes/${id}`, payload);
  },
  deleteTaxClass(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean; id: number }>(`/business/pim/tax-classes/${id}`);
  },
  bulkRecalculateProducts(payload: Record<string, unknown>) {
    return adminApi.post<{ bulk: CatalogBulkReport }>('/business/pim/products/bulk-recalculate', payload);
  },
  bulkUpdateOffers(payload: Record<string, unknown>) {
    return adminApi.post<{ bulk: CatalogBulkReport }>('/business/pim/offers/bulk-update', payload);
  },
  exportOffersCsvUrl(query: Record<string, string | number | boolean | undefined | null> = {}) {
    return adminApi.href('/business/pim/offers/export.csv', query);
  },
  previewOffersImport(payload: Record<string, unknown>) {
    return adminApi.post<{ import: CatalogImportReport }>('/business/pim/offers/import/preview', payload);
  },
  applyOffersImport(payload: Record<string, unknown>) {
    return adminApi.post<{ import: CatalogImportReport; message?: string }>('/business/pim/offers/import/apply', payload);
  },
  bulkAssignProductAsset(payload: Record<string, unknown>) {
    return adminApi.post<{ bulk: CatalogBulkReport }>('/business/pim/products/bulk-asset-assign', payload);
  },
  setMainProductAsset(id: number) {
    return adminApi.post<{ asset: CatalogProductAsset }>(`/business/pim/assets/${id}/set-main`, {});
  },
  productBundle(id: number) {
    return adminApi.get<{ bundle: CatalogProductBundle | null }>(`/business/pim/products/${id}/bundle`);
  },
  updateProductBundle(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ bundle: CatalogProductBundle }>(`/business/pim/products/${id}/bundle`, payload);
  },
  deleteProductBundle(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean; id: number }>(`/business/pim/products/${id}/bundle`);
  },
  addBundleComponent(id: number, payload: Record<string, unknown>) {
    return adminApi.post<{ component: CatalogBundleComponent }>(`/business/pim/bundles/${id}/components`, payload);
  },
  updateBundleComponent(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ component: CatalogBundleComponent }>(`/business/pim/bundle-components/${id}`, payload);
  },
  deleteBundleComponent(id: number) {
    return adminApi.delete<{ deleted: boolean; id: number }>(`/business/pim/bundle-components/${id}`);
  },
  media(query: Record<string, string | number | boolean | undefined> = {}) {
    return adminApi.get<unknown>('/media', query);
  },
  uploadMedia(form: FormData) {
    return adminApi.upload<{ media_id?: number; asset?: Record<string, unknown>; status?: string }>('/media', form);
  },
  attributeGroups() {
    return adminApi.get<{ attribute_groups: CatalogAttributeGroup[] }>('/business/pim/attribute-groups');
  },
  createAttributeGroup(payload: Record<string, unknown>) {
    return adminApi.post<{ attribute_group: CatalogAttributeGroup }>('/business/pim/attribute-groups', payload);
  },
  updateAttributeGroup(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ attribute_group: CatalogAttributeGroup }>(`/business/pim/attribute-groups/${id}`, payload);
  },
  attributes() {
    return adminApi.get<{ attributes: CatalogAttribute[] }>('/business/pim/attributes');
  },
  createAttribute(payload: Record<string, unknown>) {
    return adminApi.post<{ attribute: CatalogAttribute }>('/business/pim/attributes', payload);
  },
  updateAttribute(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ attribute: CatalogAttribute }>(`/business/pim/attributes/${id}`, payload);
  },
  createAttributeOption(attributeId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ attribute_option: CatalogRecord }>(`/business/pim/attributes/${attributeId}/options`, payload);
  },
  updateAttributeOption(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ attribute_option: CatalogRecord }>(`/business/pim/attribute-options/${id}`, payload);
  },
  deleteAttributeOption(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean; id: number }>(`/business/pim/attribute-options/${id}`);
  },
  productAttributes(id: number) {
    return adminApi.get<{ attributes: CatalogAttributeValue[] }>(`/business/pim/products/${id}/attributes`);
  },
  updateProductAttributes(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ attributes: CatalogAttributeValue[] }>(`/business/pim/products/${id}/attributes`, payload);
  },
  variantAttributes(id: number) {
    return adminApi.get<{ attributes: CatalogAttributeValue[] }>(`/business/pim/variants/${id}/attributes`);
  },
  updateVariantAttributes(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ attributes: CatalogAttributeValue[] }>(`/business/pim/variants/${id}/attributes`, payload);
  },
  updateBasePrices(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ prices: Array<Record<string, unknown>> }>(`/business/catalog/products/${id}/base-prices`, payload);
  },
  createVariant(productId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ variant: CatalogVariant }>(`/business/catalog/products/${productId}/variants`, payload);
  },
  updateVariant(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ variant: CatalogVariant }>(`/business/catalog/variants/${id}`, payload);
  },
  deleteVariant(id: number) {
    return adminApi.delete<{ deleted: boolean; archived: boolean; id: number }>(`/business/catalog/variants/${id}`);
  },
  updateVariantPriceAdjustments(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ variant: CatalogVariant }>(`/business/catalog/variants/${id}/price-adjustments`, payload);
  },
  stock(id: number) {
    return adminApi.get<{ stock: Record<string, unknown>; locations?: Array<Record<string, unknown>>; location_stock?: Array<Record<string, unknown>>; movements: Array<Record<string, unknown>> }>(`/business/catalog/variants/${id}/stock`);
  },
  createStockMovement(id: number, payload: Record<string, unknown>) {
    return adminApi.post<{ stock?: Record<string, unknown>; movement?: Record<string, unknown>; preview?: Record<string, unknown> }>(`/business/catalog/variants/${id}/stock-movements`, payload);
  },
  discounts() {
    return adminApi.get<{ discounts: CatalogDiscount[] }>('/business/catalog/discounts', { limit: 200 });
  },
  createDiscount(payload: Record<string, unknown>) {
    return adminApi.post<{ discount: CatalogDiscount }>('/business/catalog/discounts', payload);
  },
  previewDiscount(payload: Record<string, unknown>) {
    return adminApi.post<{ preview: Record<string, unknown> }>('/business/catalog/discounts/preview', payload);
  },
  updateDiscount(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ discount: CatalogDiscount }>(`/business/catalog/discounts/${id}`, payload);
  },
  archiveDiscount(id: number) {
    return adminApi.delete<{ deleted: boolean }>(`/business/catalog/discounts/${id}`);
  }
};
