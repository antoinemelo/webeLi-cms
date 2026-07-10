PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS business_catalog_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(currency IN ('CHF','EUR','USD')),
    base_purchase_price REAL,
    base_sale_price REAL,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, slug),
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(slug = lower(trim(slug)) AND slug GLOB '[a-z0-9_-]*'),
    CHECK(base_purchase_price IS NULL OR base_purchase_price >= 0),
    CHECK(base_sale_price IS NULL OR base_sale_price >= 0)
);

CREATE TABLE IF NOT EXISTS business_catalog_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    sku TEXT NOT NULL,
    barcode TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK(stock_quantity >= 0),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(product_id, sku),
    FOREIGN KEY(product_id) REFERENCES business_catalog_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(trim(sku) <> '')
);

CREATE TABLE IF NOT EXISTS business_catalog_variant_price_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL,
    price_kind TEXT NOT NULL CHECK(price_kind IN ('purchase','sale')),
    adjustment_type TEXT NOT NULL DEFAULT 'none' CHECK(adjustment_type IN ('none','amount_delta','percent_delta','fixed_override')),
    adjustment_value REAL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(variant_id, price_kind),
    FOREIGN KEY(variant_id) REFERENCES business_catalog_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK((adjustment_type = 'none' AND adjustment_value IS NULL)
       OR (adjustment_type = 'amount_delta' AND adjustment_value IS NOT NULL)
       OR (adjustment_type = 'percent_delta' AND adjustment_value IS NOT NULL AND adjustment_value >= -100 AND adjustment_value <= 1000)
       OR (adjustment_type = 'fixed_override' AND adjustment_value IS NOT NULL AND adjustment_value >= 0))
);

CREATE TABLE IF NOT EXISTS business_catalog_offers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    offer_type TEXT NOT NULL CHECK(offer_type IN ('percent','amount')),
    offer_value REAL NOT NULL,
    scope_type TEXT NOT NULL CHECK(scope_type IN ('product','variant','category','brand')),
    product_id INTEGER,
    variant_id INTEGER,
    category_id INTEGER,
    brand_id INTEGER,
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','ecommerce','pos','catalogue','admin')),
    starts_at TEXT,
    ends_at TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','archived')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    FOREIGN KEY(product_id) REFERENCES business_catalog_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_catalog_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK((offer_type = 'percent' AND offer_value > 0 AND offer_value <= 100)
       OR (offer_type = 'amount' AND offer_value > 0)),
    CHECK((scope_type = 'product' AND product_id IS NOT NULL AND variant_id IS NULL AND category_id IS NULL AND brand_id IS NULL)
       OR (scope_type = 'variant' AND variant_id IS NOT NULL AND product_id IS NULL AND category_id IS NULL AND brand_id IS NULL)
       OR (scope_type = 'category' AND category_id IS NOT NULL AND product_id IS NULL AND variant_id IS NULL AND brand_id IS NULL)
       OR (scope_type = 'brand' AND brand_id IS NOT NULL AND product_id IS NULL AND variant_id IS NULL AND category_id IS NULL))
);

CREATE INDEX IF NOT EXISTS idx_business_catalog_products_site_status ON business_catalog_products(site_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_catalog_variants_product_status ON business_catalog_variants(product_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_catalog_adjustments_variant ON business_catalog_variant_price_adjustments(variant_id, price_kind);
CREATE INDEX IF NOT EXISTS idx_business_catalog_offers_site_status ON business_catalog_offers(site_id, status, archived_at, channel);
CREATE INDEX IF NOT EXISTS idx_business_catalog_offers_product ON business_catalog_offers(product_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_catalog_offers_variant ON business_catalog_offers(variant_id, status, archived_at);
