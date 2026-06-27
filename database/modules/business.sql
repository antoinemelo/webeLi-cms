PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS business_companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    normalized_name TEXT NOT NULL,
    company_kind TEXT NOT NULL DEFAULT 'organization' CHECK(company_kind IN ('organization','system_individuals')),
    status TEXT NOT NULL DEFAULT 'prospect' CHECK(status IN ('prospect','client','supplier','former_client','other')),
    email TEXT,
    phone TEXT,
    website_url TEXT,
    address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(address_json)),
    notes TEXT NOT NULL DEFAULT '',
    is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(normalized_name = lower(trim(normalized_name)))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_companies_system_individuals
    ON business_companies(site_id, company_kind)
    WHERE company_kind = 'system_individuals' AND archived_at IS NULL;

CREATE TABLE IF NOT EXISTS business_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    company_id INTEGER NOT NULL,
    iam_user_id INTEGER,
    first_name TEXT,
    last_name TEXT,
    display_name TEXT NOT NULL,
    normalized_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'prospect' CHECK(status IN ('prospect','client','supplier','former_client','other')),
    preferred_language TEXT,
    email TEXT,
    phone TEXT,
    mobile TEXT,
    job_title TEXT,
    notes TEXT NOT NULL DEFAULT '',
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    FOREIGN KEY(company_id) REFERENCES business_companies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(trim(display_name) <> ''),
    CHECK(normalized_name = lower(trim(normalized_name))),
    CHECK(preferred_language IS NULL OR preferred_language = lower(trim(preferred_language))),
    CHECK(iam_user_id IS NULL OR iam_user_id > 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_contacts_active_iam_user
    ON business_contacts(iam_user_id)
    WHERE iam_user_id IS NOT NULL AND archived_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_business_companies_site_status ON business_companies(site_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_companies_name ON business_companies(site_id, normalized_name);
CREATE INDEX IF NOT EXISTS idx_business_companies_email ON business_companies(site_id, email);
CREATE INDEX IF NOT EXISTS idx_business_companies_archived ON business_companies(archived_at);
CREATE INDEX IF NOT EXISTS idx_business_contacts_site_status ON business_contacts(site_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_contacts_company ON business_contacts(company_id, archived_at, normalized_name);
CREATE INDEX IF NOT EXISTS idx_business_contacts_name ON business_contacts(site_id, normalized_name);
CREATE INDEX IF NOT EXISTS idx_business_contacts_email ON business_contacts(site_id, email);
CREATE INDEX IF NOT EXISTS idx_business_contacts_mobile ON business_contacts(site_id, mobile);
CREATE INDEX IF NOT EXISTS idx_business_contacts_archived ON business_contacts(archived_at);

CREATE TABLE IF NOT EXISTS business_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    tag_key TEXT NOT NULL,
    label TEXT NOT NULL,
    color TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, tag_key),
    CHECK(site_id > 0),
    CHECK(tag_key = lower(trim(tag_key)) AND tag_key GLOB '[a-z0-9_-]*'),
    CHECK(trim(label) <> '')
);

CREATE TABLE IF NOT EXISTS business_tag_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id INTEGER NOT NULL,
    target_type TEXT NOT NULL CHECK(target_type IN ('company','contact')),
    company_id INTEGER,
    contact_id INTEGER,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tag_id, target_type, company_id),
    UNIQUE(tag_id, target_type, contact_id),
    FOREIGN KEY(tag_id) REFERENCES business_tags(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(company_id) REFERENCES business_companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK((target_type = 'company' AND company_id IS NOT NULL AND contact_id IS NULL)
       OR (target_type = 'contact' AND contact_id IS NOT NULL AND company_id IS NULL))
);

CREATE INDEX IF NOT EXISTS idx_business_tags_site_label ON business_tags(site_id, label);
CREATE INDEX IF NOT EXISTS idx_business_tag_links_company ON business_tag_links(company_id);
CREATE INDEX IF NOT EXISTS idx_business_tag_links_contact ON business_tag_links(contact_id);

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
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','ecommerce','pos')),
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

CREATE TABLE IF NOT EXISTS business_product_brands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    website_url TEXT,
    logo_media_id INTEGER,
    is_public INTEGER NOT NULL DEFAULT 1 CHECK(is_public IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    UNIQUE(site_id, slug),
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(slug = lower(trim(slug)) AND slug GLOB '[a-z0-9_-]*')
);

CREATE TABLE IF NOT EXISTS business_product_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    parent_id INTEGER,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    is_public INTEGER NOT NULL DEFAULT 1 CHECK(is_public IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(parent_id) REFERENCES business_product_categories(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE(site_id, parent_id, slug),
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(slug = lower(trim(slug)) AND slug GLOB '[a-z0-9_-]*'),
    CHECK(parent_id IS NULL OR parent_id <> id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_categories_root_slug
    ON business_product_categories(site_id, slug)
    WHERE parent_id IS NULL;

CREATE TABLE IF NOT EXISTS business_tax_classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    rate REAL NOT NULL DEFAULT 0 CHECK(rate >= 0),
    country TEXT NOT NULL DEFAULT 'CH',
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    UNIQUE(site_id, code),
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(country = upper(trim(country)) AND length(country) = 2)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_tax_classes_default
    ON business_tax_classes(site_id)
    WHERE is_default = 1 AND archived_at IS NULL;

CREATE TABLE IF NOT EXISTS business_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    brand_id INTEGER,
    category_id INTEGER,
    type TEXT NOT NULL CHECK(type IN ('physical','service','gift_card')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    visibility TEXT NOT NULL DEFAULT 'internal' CHECK(visibility IN ('private','internal','public')),
    sku_base TEXT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    short_description TEXT,
    description TEXT,
    unit TEXT NOT NULL DEFAULT 'unit',
    tax_class_id INTEGER,
    track_stock INTEGER NOT NULL DEFAULT 0 CHECK(track_stock IN (0,1)),
    allow_backorder INTEGER NOT NULL DEFAULT 0 CHECK(allow_backorder IN (0,1)),
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
    is_ecommerce_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_ecommerce_enabled IN (0,1)),
    is_pos_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_pos_enabled IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(brand_id) REFERENCES business_product_brands(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(category_id) REFERENCES business_product_categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(tax_class_id) REFERENCES business_tax_classes(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE(site_id, slug),
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(slug = lower(trim(slug)) AND slug GLOB '[a-z0-9_-]*'),
    CHECK(sku_base IS NULL OR trim(sku_base) <> '')
);

CREATE TABLE IF NOT EXISTS business_product_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'select' CHECK(type IN ('select','text','color','number','duration')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    UNIQUE(site_id, code),
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> '')
);

CREATE TABLE IF NOT EXISTS business_product_option_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    option_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    label TEXT NOT NULL,
    value TEXT NOT NULL,
    color_hex TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(option_id) REFERENCES business_product_options(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(option_id, code),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(label) <> ''),
    CHECK(trim(value) <> ''),
    CHECK(color_hex IS NULL OR color_hex GLOB '#[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]')
);

CREATE TABLE IF NOT EXISTS business_product_option_links (
    product_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    is_required INTEGER NOT NULL DEFAULT 1 CHECK(is_required IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(product_id, option_id),
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(option_id) REFERENCES business_product_options(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS business_product_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    sku TEXT NOT NULL,
    barcode TEXT,
    name TEXT NOT NULL,
    track_stock INTEGER,
    stock_quantity REAL NOT NULL DEFAULT 0 CHECK(stock_quantity >= 0),
    stock_reserved REAL NOT NULL DEFAULT 0 CHECK(stock_reserved >= 0),
    allow_backorder INTEGER,
    weight_grams INTEGER CHECK(weight_grams IS NULL OR weight_grams >= 0),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(product_id, sku),
    CHECK(trim(sku) <> ''),
    CHECK(trim(name) <> ''),
    CHECK(track_stock IS NULL OR track_stock IN (0,1)),
    CHECK(allow_backorder IS NULL OR allow_backorder IN (0,1)),
    CHECK(stock_reserved <= stock_quantity OR allow_backorder = 1)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_variants_sku_active
    ON business_product_variants(sku)
    WHERE archived_at IS NULL;

CREATE TABLE IF NOT EXISTS business_product_variant_option_values (
    variant_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    option_value_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(variant_id, option_id),
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(option_id) REFERENCES business_product_options(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(option_value_id) REFERENCES business_product_option_values(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS business_product_base_prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price_kind TEXT NOT NULL CHECK(price_kind IN ('purchase','sale')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(currency IN ('CHF','EUR','USD')),
    amount REAL NOT NULL CHECK(amount >= 0),
    tax_included INTEGER NOT NULL DEFAULT 0 CHECK(tax_included IN (0,1)),
    valid_from TEXT,
    valid_until TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(product_id, price_kind, currency, valid_from),
    CHECK(valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from)
);

CREATE TABLE IF NOT EXISTS business_product_variant_price_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL,
    price_kind TEXT NOT NULL CHECK(price_kind IN ('purchase','sale')),
    adjustment_type TEXT NOT NULL CHECK(adjustment_type IN ('amount_delta','percent_delta','fixed_override')),
    adjustment_value REAL NOT NULL,
    currency TEXT CHECK(currency IS NULL OR currency IN ('CHF','EUR','USD')),
    valid_from TEXT,
    valid_until TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(variant_id, price_kind, valid_from),
    CHECK((adjustment_type = 'amount_delta' AND currency IS NULL)
       OR (adjustment_type = 'percent_delta' AND currency IS NULL AND adjustment_value >= -100 AND adjustment_value <= 1000)
       OR (adjustment_type = 'fixed_override' AND currency IS NOT NULL AND adjustment_value >= 0)),
    CHECK(valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from)
);

CREATE TABLE IF NOT EXISTS business_catalog_discounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    discount_type TEXT NOT NULL CHECK(discount_type IN ('percent','amount')),
    discount_value REAL NOT NULL,
    currency TEXT CHECK(currency IS NULL OR currency IN ('CHF','EUR','USD')),
    scope_type TEXT NOT NULL CHECK(scope_type IN ('product','variant','category','brand')),
    scope_id INTEGER NOT NULL,
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','ecommerce','pos','admin')),
    starts_at TEXT,
    ends_at TEXT,
    priority INTEGER NOT NULL DEFAULT 100,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK((discount_type = 'percent' AND discount_value > 0 AND discount_value <= 100 AND currency IS NULL)
       OR (discount_type = 'amount' AND discount_value > 0 AND currency IS NOT NULL)),
    CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);

CREATE TABLE IF NOT EXISTS business_stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL CHECK(movement_type IN ('initial','purchase','sale','adjustment','return','reservation','release')),
    quantity REAL NOT NULL,
    reason TEXT,
    reference_type TEXT,
    reference_id INTEGER,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(quantity <> 0),
    CHECK(reference_type IS NULL OR trim(reference_type) <> '')
);

CREATE TABLE IF NOT EXISTS business_product_media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    variant_id INTEGER,
    media_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'gallery' CHECK(role IN ('main','gallery','thumbnail','variant','document')),
    alt_text TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(media_id > 0),
    CHECK(variant_id IS NULL OR role IN ('variant','gallery','thumbnail'))
);

CREATE TABLE IF NOT EXISTS business_product_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    color TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    UNIQUE(site_id, slug),
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(slug = lower(trim(slug)) AND slug GLOB '[a-z0-9_-]*')
);

CREATE TABLE IF NOT EXISTS business_product_tag_links (
    tag_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(tag_id, product_id),
    FOREIGN KEY(tag_id) REFERENCES business_product_tags(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_business_product_brands_site_status ON business_product_brands(site_id, status, archived_at, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_categories_parent ON business_product_categories(site_id, parent_id, archived_at, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_products_site_status ON business_products(site_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_products_brand ON business_products(brand_id, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_products_category ON business_products(category_id, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_products_sku_base ON business_products(site_id, sku_base);
CREATE INDEX IF NOT EXISTS idx_business_product_options_site ON business_product_options(site_id, archived_at, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_option_values_option ON business_product_option_values(option_id, archived_at, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_variants_product ON business_product_variants(product_id, status, archived_at, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_variants_barcode ON business_product_variants(barcode);
CREATE INDEX IF NOT EXISTS idx_business_product_base_prices_product ON business_product_base_prices(product_id, price_kind, currency, valid_from, valid_until);
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_base_prices_current_unique
    ON business_product_base_prices(product_id, price_kind, currency)
    WHERE valid_from IS NULL;
CREATE INDEX IF NOT EXISTS idx_business_variant_adjustments_variant ON business_product_variant_price_adjustments(variant_id, price_kind, valid_from, valid_until);
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_variant_adjustments_current_unique
    ON business_product_variant_price_adjustments(variant_id, price_kind)
    WHERE valid_from IS NULL;
CREATE INDEX IF NOT EXISTS idx_business_catalog_discounts_scope ON business_catalog_discounts(scope_type, scope_id, status, priority);
CREATE INDEX IF NOT EXISTS idx_business_catalog_discounts_site_channel ON business_catalog_discounts(site_id, channel, status, starts_at, ends_at);
CREATE INDEX IF NOT EXISTS idx_business_stock_movements_variant ON business_stock_movements(variant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_business_product_media_product ON business_product_media(product_id, role, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_media_variant ON business_product_media(variant_id, role, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_tags_site ON business_product_tags(site_id, archived_at, slug);

INSERT OR IGNORE INTO business_tax_classes(site_id, code, name, rate, country, is_default)
VALUES (1, 'standard', 'Standard CH', 8.1, 'CH', 1);

INSERT OR IGNORE INTO business_product_brands(site_id, name, slug, description, website_url, is_public, status, sort_order)
VALUES (1, 'Demo Outdoor', 'demo-outdoor', 'Marque de demonstration pour le catalogue Business.', 'https://example.test', 1, 'active', 10);

INSERT OR IGNORE INTO business_product_categories(site_id, parent_id, name, slug, description, is_public, sort_order)
VALUES
    (1, NULL, 'Experiences', 'experiences', 'Services et experiences de demonstration.', 1, 10),
    (1, NULL, 'Boutique', 'boutique', 'Produits physiques et bons cadeaux de demonstration.', 1, 20);

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'service', 'active', 'public', 'DEMO-VOL', 'Vol decouverte', 'vol-decouverte', 'Service catalogue de demonstration.', 'service', t.id, 0, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'demo-outdoor' AND c.site_id = 1 AND c.slug = 'experiences' AND t.site_id = 1 AND t.code = 'standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'physical', 'active', 'public', 'DEMO-GOURDE', 'Gourde demo', 'gourde-demo', 'Produit physique catalogue de demonstration.', 'piece', t.id, 1, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'demo-outdoor' AND c.site_id = 1 AND c.slug = 'boutique' AND t.site_id = 1 AND t.code = 'standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'gift_card', 'active', 'public', 'DEMO-GIFT', 'Bon cadeau demo', 'bon-cadeau-demo', 'Bon cadeau simple de demonstration.', 'piece', t.id, 0, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'demo-outdoor' AND c.site_id = 1 AND c.slug = 'boutique' AND t.site_id = 1 AND t.code = 'standard';

INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 80.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'vol-decouverte';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 149.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'vol-decouverte';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 12.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'gourde-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 29.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'gourde-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 0.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 100.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-demo';

INSERT OR IGNORE INTO business_product_options(site_id, code, name, type, sort_order)
VALUES (1, 'formule', 'Formule', 'select', 10), (1, 'duree', 'Duree', 'duration', 20), (1, 'couleur', 'Couleur', 'color', 30);

INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'classic', 'Classic', 'classic', 10 FROM business_product_options WHERE site_id = 1 AND code = 'formule';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'premium', 'Premium', 'premium', 20 FROM business_product_options WHERE site_id = 1 AND code = 'formule';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, '20_min', '20 min', '20_min', 10 FROM business_product_options WHERE site_id = 1 AND code = 'duree';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, '40_min', '40 min', '40_min', 20 FROM business_product_options WHERE site_id = 1 AND code = 'duree';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, color_hex, sort_order)
SELECT id, 'bleu', 'Bleu', 'bleu', '#0066CC', 10 FROM business_product_options WHERE site_id = 1 AND code = 'couleur';

INSERT OR IGNORE INTO business_product_option_links(product_id, option_id, is_required, sort_order)
SELECT p.id, o.id, 1, o.sort_order FROM business_products p, business_product_options o
WHERE p.site_id = 1 AND p.slug = 'vol-decouverte' AND o.site_id = 1 AND o.code IN ('formule','duree');

INSERT OR IGNORE INTO business_product_option_links(product_id, option_id, is_required, sort_order)
SELECT p.id, o.id, 1, o.sort_order FROM business_products p, business_product_options o
WHERE p.site_id = 1 AND p.slug = 'gourde-demo' AND o.site_id = 1 AND o.code = 'couleur';

INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'DEMO-VOL-CLASSIC-20', 'Classic 20 min', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'vol-decouverte';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'DEMO-VOL-PREMIUM-40', 'Premium 40 min', 0, 0, 0, 0, 20 FROM business_products WHERE site_id = 1 AND slug = 'vol-decouverte';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'DEMO-GOURDE-BLEU', 'Gourde bleue', 1, 25, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'gourde-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'DEMO-GIFT-100', 'Bon cadeau 100 CHF', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-demo';

INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'percent_delta', 20.00, NULL FROM business_product_variants v WHERE v.sku = 'DEMO-VOL-PREMIUM-40';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'amount_delta', 60.00, NULL FROM business_product_variants v WHERE v.sku = 'DEMO-VOL-PREMIUM-40';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'DEMO-VOL-CLASSIC-20' AND o.site_id = 1 AND o.code = 'formule' AND ov.option_id = o.id AND ov.code = 'classic';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'DEMO-VOL-CLASSIC-20' AND o.site_id = 1 AND o.code = 'duree' AND ov.option_id = o.id AND ov.code = '20_min';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'DEMO-VOL-PREMIUM-40' AND o.site_id = 1 AND o.code = 'formule' AND ov.option_id = o.id AND ov.code = 'premium';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'DEMO-VOL-PREMIUM-40' AND o.site_id = 1 AND o.code = 'duree' AND ov.option_id = o.id AND ov.code = '40_min';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'DEMO-GOURDE-BLEU' AND o.site_id = 1 AND o.code = 'couleur' AND ov.option_id = o.id AND ov.code = 'bleu';

INSERT OR IGNORE INTO business_catalog_discounts(site_id, name, status, discount_type, discount_value, currency, scope_type, scope_id, channel, priority)
SELECT 1, 'Lancement POS', 'active', 'percent', 10.00, NULL, 'product', p.id, 'pos', 100
FROM business_products p WHERE p.site_id = 1 AND p.slug = 'vol-decouverte';

INSERT OR IGNORE INTO business_product_brands(site_id, name, slug, description, website_url, is_public, status, sort_order)
VALUES (1, 'NOUVELLE MARQUE', 'nouvelle-marque', 'Marque de demonstration du catalogue Business.', 'https://example.test', 1, 'active', 20);

INSERT OR IGNORE INTO business_product_categories(site_id, parent_id, name, slug, description, is_public, sort_order)
VALUES
    (1, NULL, 'Services', 'services', 'Services de demonstration du catalogue.', 1, 30),
    (1, NULL, 'Marchandises', 'marchandises', 'Marchandises de demonstration du catalogue.', 1, 40);

INSERT OR IGNORE INTO business_tax_classes(site_id, code, name, rate, country, is_default)
VALUES (1, 'catalog_standard', 'Catalogue standard CH', 8.1, 'CH', 0);

INSERT OR IGNORE INTO business_product_options(site_id, code, name, type, sort_order)
VALUES
    (1, 'model', 'Model', 'select', 40),
    (1, 'size', 'Size', 'select', 50),
    (1, 'color', 'Color', 'color', 60);

INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'classic', 'Classic', 'classic', 10 FROM business_product_options WHERE site_id = 1 AND code = 'model';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'premium', 'Premium', 'premium', 20 FROM business_product_options WHERE site_id = 1 AND code = 'model';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 's', 'S', 's', 10 FROM business_product_options WHERE site_id = 1 AND code = 'size';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'm', 'M', 'm', 20 FROM business_product_options WHERE site_id = 1 AND code = 'size';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'l', 'L', 'l', 30 FROM business_product_options WHERE site_id = 1 AND code = 'size';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, color_hex, sort_order)
SELECT id, 'blue', 'Blue', 'blue', '#0066CC', 10 FROM business_product_options WHERE site_id = 1 AND code = 'color';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, color_hex, sort_order)
SELECT id, 'black', 'Black', 'black', '#000000', 20 FROM business_product_options WHERE site_id = 1 AND code = 'color';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'physical', 'active', 'public', 'TSHIRT-DEMO', 'T-shirt Demo', 't-shirt-demo', 'Marchandise de demonstration avec variantes.', 'piece', t.id, 1, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'marchandises' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'service', 'active', 'public', 'CONSULTATION', 'Consultation', 'consultation', 'Service de demonstration du catalogue.', 'service', t.id, 0, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'gift_card', 'active', 'public', 'GIFT-DEMO', 'Bon cadeau simple', 'bon-cadeau-simple', 'Bon cadeau simple de demonstration du catalogue.', 'piece', t.id, 0, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 14.00, 0 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 29.00, 1 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 45.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 90.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 0.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 100.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';

INSERT OR IGNORE INTO business_product_option_links(product_id, option_id, is_required, sort_order)
SELECT p.id, o.id, 1, o.sort_order
FROM business_products p, business_product_options o
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND o.site_id = 1 AND o.code IN ('model','size','color');

INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-CLASSIC-M-BLUE', 'Classic / M / Blue', 1, 15, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-CLASSIC-L-BLUE', 'Classic / L / Blue', 1, 10, 0, 0, 20 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-PREMIUM-M-BLACK', 'Premium / M / Black', 1, 8, 0, 0, 30 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'CONSULTATION-STANDARD', 'Consultation standard', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'GIFT-DEMO-100', 'Bon cadeau simple 100 CHF', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';

INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'amount_delta', 2.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'amount_delta', 5.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'percent_delta', 15.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'percent_delta', 25.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE' AND o.site_id = 1 AND o.code = 'model' AND ov.option_id = o.id AND ov.code = 'classic';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE' AND o.site_id = 1 AND o.code = 'size' AND ov.option_id = o.id AND ov.code = 'm';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE' AND o.site_id = 1 AND o.code = 'color' AND ov.option_id = o.id AND ov.code = 'blue';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE' AND o.site_id = 1 AND o.code = 'model' AND ov.option_id = o.id AND ov.code = 'classic';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE' AND o.site_id = 1 AND o.code = 'size' AND ov.option_id = o.id AND ov.code = 'l';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE' AND o.site_id = 1 AND o.code = 'color' AND ov.option_id = o.id AND ov.code = 'blue';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK' AND o.site_id = 1 AND o.code = 'model' AND ov.option_id = o.id AND ov.code = 'premium';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK' AND o.site_id = 1 AND o.code = 'size' AND ov.option_id = o.id AND ov.code = 'm';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK' AND o.site_id = 1 AND o.code = 'color' AND ov.option_id = o.id AND ov.code = 'black';

CREATE TABLE IF NOT EXISTS crm_memos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    company_id INTEGER,
    contact_id INTEGER,
    author_iam_user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    visibility TEXT NOT NULL DEFAULT 'private' CHECK(visibility IN ('private','internal','public_link')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    FOREIGN KEY(company_id) REFERENCES business_companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(author_iam_user_id > 0),
    CHECK(trim(title) <> ''),
    CHECK(company_id IS NOT NULL OR contact_id IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS crm_memo_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    memo_id INTEGER NOT NULL,
    share_type TEXT NOT NULL CHECK(share_type IN ('iam_user','public_link')),
    shared_with_iam_user_id INTEGER,
    public_token_hash TEXT,
    public_label TEXT,
    expires_at TEXT,
    revoked_at TEXT,
    created_by_iam_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at TEXT,
    access_count INTEGER NOT NULL DEFAULT 0 CHECK(access_count >= 0),
    FOREIGN KEY(memo_id) REFERENCES crm_memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(memo_id, shared_with_iam_user_id),
    UNIQUE(public_token_hash),
    CHECK(created_by_iam_user_id > 0),
    CHECK((share_type = 'iam_user' AND shared_with_iam_user_id IS NOT NULL AND public_token_hash IS NULL)
       OR (share_type = 'public_link' AND shared_with_iam_user_id IS NULL AND public_token_hash IS NOT NULL)),
    CHECK(shared_with_iam_user_id IS NULL OR shared_with_iam_user_id > 0),
    CHECK(public_token_hash IS NULL OR length(public_token_hash) >= 32)
);

CREATE TABLE IF NOT EXISTS crm_memo_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    memo_id INTEGER NOT NULL,
    author_iam_user_id INTEGER NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    FOREIGN KEY(memo_id) REFERENCES crm_memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(author_iam_user_id > 0),
    CHECK(trim(body) <> '')
);

CREATE INDEX IF NOT EXISTS idx_crm_memos_site_company ON crm_memos(site_id, company_id, archived_at, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_memos_site_contact ON crm_memos(site_id, contact_id, archived_at, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_memos_author ON crm_memos(author_iam_user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_memo_shares_memo ON crm_memo_shares(memo_id, share_type, revoked_at);
CREATE INDEX IF NOT EXISTS idx_crm_memo_shares_user ON crm_memo_shares(shared_with_iam_user_id, revoked_at);
CREATE INDEX IF NOT EXISTS idx_crm_memo_comments_memo ON crm_memo_comments(memo_id, created_at);

CREATE TABLE IF NOT EXISTS crm_contact_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('email','whatsapp','telegram')),
    channel_value TEXT NOT NULL,
    normalized_value TEXT NOT NULL,
    provider_ref TEXT,
    is_primary INTEGER NOT NULL DEFAULT 0 CHECK(is_primary IN (0,1)),
    is_verified INTEGER NOT NULL DEFAULT 0 CHECK(is_verified IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(contact_id, channel, normalized_value),
    CHECK(trim(channel_value) <> ''),
    CHECK(trim(normalized_value) <> '')
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_contact_channels_primary
    ON crm_contact_channels(contact_id, channel)
    WHERE is_primary = 1 AND archived_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_crm_contact_channels_value ON crm_contact_channels(channel, normalized_value, archived_at);

CREATE TABLE IF NOT EXISTS crm_consents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('email','whatsapp','telegram')),
    consent_status TEXT NOT NULL DEFAULT 'unknown' CHECK(consent_status IN ('unknown','opt_in','opt_out')),
    source TEXT NOT NULL DEFAULT 'manual' CHECK(source IN ('manual','form','import','unsubscribe','api')),
    evidence TEXT,
    granted_at TEXT,
    revoked_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(contact_id, channel),
    CHECK((consent_status = 'opt_in' AND granted_at IS NOT NULL AND revoked_at IS NULL)
       OR (consent_status = 'opt_out' AND revoked_at IS NOT NULL)
       OR consent_status = 'unknown')
);

CREATE INDEX IF NOT EXISTS idx_crm_consents_channel_status ON crm_consents(channel, consent_status);
CREATE INDEX IF NOT EXISTS idx_crm_consents_contact ON crm_consents(contact_id);

CREATE TABLE IF NOT EXISTS crm_mailing_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    list_key TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    channel TEXT NOT NULL DEFAULT 'email' CHECK(channel IN ('email','whatsapp','telegram')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, list_key),
    CHECK(site_id > 0),
    CHECK(list_key = lower(trim(list_key)) AND list_key GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> '')
);

CREATE TABLE IF NOT EXISTS crm_mailing_list_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    list_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'subscribed' CHECK(status IN ('subscribed','unsubscribed','bounced','archived')),
    subscribed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at TEXT,
    unsubscribe_token_hash TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    updated_at TEXT,
    UNIQUE(list_id, contact_id),
    UNIQUE(unsubscribe_token_hash),
    FOREIGN KEY(list_id) REFERENCES crm_mailing_lists(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(unsubscribe_token_hash IS NULL OR length(unsubscribe_token_hash) >= 32)
);

CREATE TABLE IF NOT EXISTS crm_mailings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    list_id INTEGER,
    mailing_key TEXT,
    name TEXT NOT NULL,
    channel TEXT NOT NULL DEFAULT 'email' CHECK(channel IN ('email','whatsapp','telegram')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','ready','sending','sent','cancelled','failed')),
    subject TEXT,
    body_text TEXT NOT NULL DEFAULT '',
    body_html TEXT,
    template_key TEXT,
    scheduled_at TEXT,
    sent_at TEXT,
    cancelled_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(list_id) REFERENCES crm_mailing_lists(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE(site_id, mailing_key),
    CHECK(site_id > 0),
    CHECK(mailing_key IS NULL OR (mailing_key = lower(trim(mailing_key)) AND mailing_key GLOB '[a-z0-9_-]*')),
    CHECK(trim(name) <> '')
);

CREATE TABLE IF NOT EXISTS crm_mailing_recipients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mailing_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    channel_id INTEGER,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','queued','sent','failed','skipped','cancelled','unsubscribed')),
    unsubscribe_token_hash TEXT,
    queued_at TEXT,
    sent_at TEXT,
    failed_at TEXT,
    skipped_reason TEXT,
    message_outbox_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(mailing_id, contact_id),
    UNIQUE(unsubscribe_token_hash),
    FOREIGN KEY(mailing_id) REFERENCES crm_mailings(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(channel_id) REFERENCES crm_contact_channels(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(unsubscribe_token_hash IS NULL OR length(unsubscribe_token_hash) >= 32)
);

CREATE INDEX IF NOT EXISTS idx_crm_mailing_lists_site ON crm_mailing_lists(site_id, status, archived_at);
CREATE INDEX IF NOT EXISTS idx_crm_mailing_members_list ON crm_mailing_list_members(list_id, status);
CREATE INDEX IF NOT EXISTS idx_crm_mailing_members_contact ON crm_mailing_list_members(contact_id, status);
CREATE INDEX IF NOT EXISTS idx_crm_mailings_site_status ON crm_mailings(site_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_mailing_recipients_mailing ON crm_mailing_recipients(mailing_id, status);
CREATE INDEX IF NOT EXISTS idx_crm_mailing_recipients_contact ON crm_mailing_recipients(contact_id, status);

CREATE TABLE IF NOT EXISTS crm_messaging_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER,
    provider_key TEXT NOT NULL,
    name TEXT NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('email','whatsapp','telegram')),
    provider_type TEXT NOT NULL DEFAULT 'null' CHECK(provider_type IN ('null','smtp','webhook','whatsapp_cloud','telegram_bot','custom')),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    secret_ref TEXT,
    is_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(site_id, provider_key),
    CHECK(site_id IS NULL OR site_id > 0),
    CHECK(provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(secret_ref IS NULL OR secret_ref GLOB 'env:[A-Z0-9_]*')
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_messaging_providers_default_site
    ON crm_messaging_providers(site_id, channel)
    WHERE is_default = 1;

CREATE TABLE IF NOT EXISTS crm_message_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    template_key TEXT NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('email','whatsapp','telegram')),
    name TEXT NOT NULL,
    subject TEXT,
    body_text TEXT NOT NULL DEFAULT '',
    body_html TEXT,
    provider_template_ref TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, template_key),
    CHECK(site_id > 0),
    CHECK(template_key = lower(trim(template_key)) AND template_key GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> '')
);

CREATE TABLE IF NOT EXISTS crm_message_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    provider_id INTEGER,
    template_id INTEGER,
    mailing_id INTEGER,
    contact_id INTEGER,
    channel TEXT NOT NULL CHECK(channel IN ('email','whatsapp','telegram')),
    recipient_value TEXT NOT NULL,
    subject TEXT,
    body_text TEXT NOT NULL DEFAULT '',
    body_html TEXT,
    payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payload_json)),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','queued','sent','failed','cancelled','skipped')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 3 CHECK(max_attempts >= 1),
    next_attempt_at TEXT,
    locked_at TEXT,
    sent_at TEXT,
    failed_at TEXT,
    last_error TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(provider_id) REFERENCES crm_messaging_providers(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(template_id) REFERENCES crm_message_templates(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(mailing_id) REFERENCES crm_mailings(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(trim(recipient_value) <> '')
);

CREATE TABLE IF NOT EXISTS crm_message_delivery_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    outbox_id INTEGER NOT NULL,
    event_type TEXT NOT NULL CHECK(event_type IN ('queued','sent','delivered','failed','bounced','opened','clicked','skipped','cancelled','provider_status')),
    provider_message_id TEXT,
    event_payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(event_payload_json)),
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(outbox_id) REFERENCES crm_message_outbox(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_crm_messaging_providers_site_channel ON crm_messaging_providers(site_id, channel, is_enabled);
CREATE INDEX IF NOT EXISTS idx_crm_message_templates_site_channel ON crm_message_templates(site_id, channel, status);
CREATE INDEX IF NOT EXISTS idx_crm_message_outbox_status ON crm_message_outbox(status, next_attempt_at, created_at);
CREATE INDEX IF NOT EXISTS idx_crm_message_outbox_site_contact ON crm_message_outbox(site_id, contact_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_message_outbox_mailing ON crm_message_outbox(mailing_id, status);
CREATE INDEX IF NOT EXISTS idx_crm_message_events_outbox ON crm_message_delivery_events(outbox_id, created_at);

INSERT OR IGNORE INTO business_companies (
    site_id,
    name,
    normalized_name,
    company_kind,
    status,
    is_system
) VALUES (
    1,
    'Individus',
    'individus',
    'system_individuals',
    'other',
    1
);
