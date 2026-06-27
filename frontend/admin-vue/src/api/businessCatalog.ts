import { adminApi } from './client';

export type CatalogRecord = Record<string, unknown> & { id: number; name?: string; slug?: string; status?: string };
export type CatalogProduct = CatalogRecord & {
  type?: string;
  brand_id?: number | null;
  category_id?: number | null;
  is_public?: boolean;
  is_ecommerce_enabled?: boolean;
  is_pos_enabled?: boolean;
};
export type CatalogVariant = CatalogRecord & {
  product_id?: number;
  sku?: string;
  barcode?: string | null;
  stock_quantity?: number;
  stock_reserved?: number;
  computed_prices?: Record<string, unknown> | null;
  option_values?: Array<Record<string, unknown>>;
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

export type ProductDetail = Record<string, unknown> & {
  data?: CatalogProduct;
  brand?: CatalogRecord | null;
  category?: CatalogRecord | null;
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

export const businessCatalogApi = {
  brands() {
    return adminApi.get<{ brands: CatalogRecord[] }>('/business/catalog/brands', { limit: 200 });
  },
  categories() {
    return adminApi.get<{ categories: CatalogRecord[] }>('/business/catalog/categories', { limit: 200 });
  },
  options() {
    return adminApi.get<{ options: CatalogRecord[] }>('/business/catalog/options', { limit: 200 });
  },
  products(query: Record<string, string | number | boolean | undefined>) {
    return adminApi.get<{ products: CatalogProduct[]; pagination?: Record<string, unknown> }>('/business/catalog/products', query);
  },
  exportCsvUrl() {
    return adminApi.href('/business/catalog/export.csv');
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
  updateBasePrices(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ prices: Array<Record<string, unknown>> }>(`/business/catalog/products/${id}/base-prices`, payload);
  },
  createVariant(productId: number, payload: Record<string, unknown>) {
    return adminApi.post<{ variant: CatalogVariant }>(`/business/catalog/products/${productId}/variants`, payload);
  },
  updateVariantPriceAdjustments(id: number, payload: Record<string, unknown>) {
    return adminApi.put<{ variant: CatalogVariant }>(`/business/catalog/variants/${id}/price-adjustments`, payload);
  },
  stock(id: number) {
    return adminApi.get<{ stock: Record<string, unknown>; movements: Array<Record<string, unknown>> }>(`/business/catalog/variants/${id}/stock`);
  },
  createStockMovement(id: number, payload: Record<string, unknown>) {
    return adminApi.post<{ stock: Record<string, unknown>; movement: Record<string, unknown> }>(`/business/catalog/variants/${id}/stock-movements`, payload);
  },
  discounts() {
    return adminApi.get<{ discounts: CatalogDiscount[] }>('/business/catalog/discounts', { limit: 200 });
  },
  createDiscount(payload: Record<string, unknown>) {
    return adminApi.post<{ discount: CatalogDiscount }>('/business/catalog/discounts', payload);
  },
  updateDiscount(id: number, payload: Record<string, unknown>) {
    return adminApi.patch<{ discount: CatalogDiscount }>(`/business/catalog/discounts/${id}`, payload);
  },
  archiveDiscount(id: number) {
    return adminApi.delete<{ deleted: boolean }>(`/business/catalog/discounts/${id}`);
  }
};
