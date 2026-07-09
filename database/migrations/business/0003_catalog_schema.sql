PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS business_product_brands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    company_id INTEGER,
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
    FOREIGN KEY(company_id) REFERENCES business_companies(id) ON DELETE SET NULL ON UPDATE CASCADE,
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
    type TEXT NOT NULL CHECK(type IN ('physical','service','gift_card','bundle')),
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
    allow_backorder INTEGER NOT NULL DEFAULT 1 CHECK(allow_backorder IN (0,1)),
    backorder_delivery_days INTEGER NOT NULL DEFAULT 7 CHECK(backorder_delivery_days >= 0),
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
    is_ecommerce_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_ecommerce_enabled IN (0,1)),
    is_pos_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_pos_enabled IN (0,1)),
    is_catalogue_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_catalogue_enabled IN (0,1)),
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
    backorder_delivery_days INTEGER CHECK(backorder_delivery_days IS NULL OR backorder_delivery_days >= 0),
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
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','ecommerce','pos','catalogue','admin')),
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

CREATE TABLE IF NOT EXISTS business_product_bundles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    bundle_product_id INTEGER NOT NULL,
    bundle_variant_id INTEGER,
    pricing_mode TEXT NOT NULL DEFAULT 'fixed' CHECK(pricing_mode IN ('fixed','sum_components','discount_components')),
    stock_mode TEXT NOT NULL DEFAULT 'components' CHECK(stock_mode IN ('components','virtual','none')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(bundle_product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(bundle_variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(bundle_product_id > 0),
    CHECK(bundle_variant_id IS NULL OR bundle_variant_id > 0)
);

CREATE TABLE IF NOT EXISTS business_bundle_components (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bundle_id INTEGER NOT NULL,
    component_product_id INTEGER NOT NULL,
    component_variant_id INTEGER,
    quantity REAL NOT NULL DEFAULT 1 CHECK(quantity > 0),
    is_required INTEGER NOT NULL DEFAULT 1 CHECK(is_required IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(bundle_id) REFERENCES business_product_bundles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(component_product_id) REFERENCES business_products(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(component_variant_id) REFERENCES business_product_variants(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(component_product_id > 0),
    CHECK(component_variant_id IS NULL OR component_variant_id > 0)
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

CREATE TABLE IF NOT EXISTS business_product_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER,
    media_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'gallery' CHECK(role IN ('main','gallery','variant','thumbnail','document','technical_sheet','brand_logo','packaging','seo','internal')),
    title TEXT,
    alt_text TEXT,
    caption TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
    channel_scope TEXT NOT NULL DEFAULT 'all' CHECK(channel_scope IN ('all','public','ecommerce','pos','catalogue','admin','pdf')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(media_id > 0),
    CHECK(trim(role) <> ''),
    CHECK(variant_id IS NULL OR variant_id > 0),
    CHECK(product_id > 0)
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
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_bundles_product_active
    ON business_product_bundles(bundle_product_id)
    WHERE bundle_variant_id IS NULL AND archived_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_bundles_variant_active
    ON business_product_bundles(bundle_variant_id)
    WHERE bundle_variant_id IS NOT NULL AND archived_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_business_bundle_components_bundle
    ON business_bundle_components(bundle_id, archived_at, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_bundle_components_product
    ON business_bundle_components(component_product_id, component_variant_id, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_stock_movements_variant ON business_stock_movements(variant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_business_product_assets_product
    ON business_product_assets(site_id, product_id, channel_scope, role, sort_order)
    WHERE archived_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_business_product_assets_variant
    ON business_product_assets(site_id, variant_id, channel_scope, role, sort_order)
    WHERE variant_id IS NOT NULL AND archived_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_assets_main_product
    ON business_product_assets(product_id, channel_scope)
    WHERE variant_id IS NULL AND role = 'main' AND archived_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_assets_main_variant
    ON business_product_assets(variant_id, channel_scope)
    WHERE variant_id IS NOT NULL AND role = 'main' AND archived_at IS NULL;
CREATE TABLE IF NOT EXISTS business_asset_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    media_id INTEGER NOT NULL,
    asset_type TEXT NOT NULL DEFAULT 'image' CHECK(asset_type IN ('image','document','video','audio','archive','other')),
    usage_rights TEXT NOT NULL DEFAULT 'unknown' CHECK(usage_rights IN ('unknown','owned','licensed','third_party','restricted','expired')),
    license TEXT,
    credit TEXT,
    source TEXT,
    expires_at TEXT,
    internal_notes TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(media_id > 0)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_asset_metadata_media
    ON business_asset_metadata(site_id, media_id);
CREATE INDEX IF NOT EXISTS idx_business_asset_metadata_usage
    ON business_asset_metadata(site_id, usage_rights, expires_at);
CREATE TABLE IF NOT EXISTS business_asset_renditions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    media_id INTEGER NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('all','public','ecommerce','pos','catalogue','admin','pdf')),
    rendition_key TEXT NOT NULL,
    width INTEGER CHECK(width IS NULL OR width > 0),
    height INTEGER CHECK(height IS NULL OR height > 0),
    format TEXT CHECK(format IS NULL OR format IN ('jpg','jpeg','png','webp','avif','pdf')),
    file_size INTEGER CHECK(file_size IS NULL OR file_size >= 0),
    generated_media_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, media_id, channel, rendition_key),
    CHECK(site_id > 0),
    CHECK(media_id > 0),
    CHECK(rendition_key = lower(trim(rendition_key)) AND rendition_key GLOB '[a-z0-9_.-]*'),
    CHECK(generated_media_id IS NULL OR generated_media_id > 0)
);
CREATE INDEX IF NOT EXISTS idx_business_asset_renditions_media
    ON business_asset_renditions(site_id, media_id, channel);
CREATE TABLE IF NOT EXISTS business_attribute_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
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
CREATE TABLE IF NOT EXISTS business_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    data_type TEXT NOT NULL CHECK(data_type IN ('text','textarea','rich_text','number','decimal','boolean','select','multi_select','date','url','file','dimension','weight','color')),
    unit TEXT,
    is_required INTEGER NOT NULL DEFAULT 0 CHECK(is_required IN (0,1)),
    is_filterable INTEGER NOT NULL DEFAULT 0 CHECK(is_filterable IN (0,1)),
    is_searchable INTEGER NOT NULL DEFAULT 0 CHECK(is_searchable IN (0,1)),
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    validation_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(validation_json)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(group_id) REFERENCES business_attribute_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id, code),
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(unit IS NULL OR trim(unit) <> '')
);
CREATE INDEX IF NOT EXISTS idx_business_attributes_group
    ON business_attributes(site_id, group_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_attributes_public
    ON business_attributes(site_id, is_public, is_filterable, is_searchable)
    WHERE archived_at IS NULL;
CREATE TABLE IF NOT EXISTS business_product_attribute_group_links (
    product_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(product_id, group_id),
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(group_id) REFERENCES business_attribute_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(sort_order >= 0)
);
CREATE INDEX IF NOT EXISTS idx_business_product_attribute_group_links_group
    ON business_product_attribute_group_links(group_id, sort_order);
CREATE TABLE IF NOT EXISTS business_attribute_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attribute_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    label TEXT NOT NULL,
    value TEXT NOT NULL,
    color_hex TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(attribute_id) REFERENCES business_attributes(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(attribute_id, code),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(label) <> ''),
    CHECK(trim(value) <> ''),
    CHECK(color_hex IS NULL OR color_hex GLOB '#[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]')
);
CREATE TABLE IF NOT EXISTS business_product_attribute_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    attribute_id INTEGER NOT NULL,
    language TEXT NOT NULL DEFAULT 'und',
    value_text TEXT,
    value_number REAL,
    value_json TEXT CHECK(value_json IS NULL OR json_valid(value_json)),
    updated_by_iam_user_id INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(attribute_id) REFERENCES business_attributes(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(product_id, attribute_id, language),
    CHECK(language = lower(trim(language)) AND language GLOB '[a-z][a-z]*'),
    CHECK(value_text IS NOT NULL OR value_number IS NOT NULL OR value_json IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_business_product_attribute_values_attribute
    ON business_product_attribute_values(attribute_id, language);
CREATE TABLE IF NOT EXISTS business_variant_attribute_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL,
    attribute_id INTEGER NOT NULL,
    language TEXT NOT NULL DEFAULT 'und',
    value_text TEXT,
    value_number REAL,
    value_json TEXT CHECK(value_json IS NULL OR json_valid(value_json)),
    updated_by_iam_user_id INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(attribute_id) REFERENCES business_attributes(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(variant_id, attribute_id, language),
    CHECK(language = lower(trim(language)) AND language GLOB '[a-z][a-z]*'),
    CHECK(value_text IS NOT NULL OR value_number IS NOT NULL OR value_json IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_business_variant_attribute_values_attribute
    ON business_variant_attribute_values(attribute_id, language);
CREATE TABLE IF NOT EXISTS business_product_completeness_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    scope TEXT NOT NULL CHECK(scope IN ('product','variant','asset','price','tax','channel')),
    required_field TEXT,
    required_attribute_id INTEGER,
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','public','ecommerce','pos','catalogue','admin','pdf')),
    weight INTEGER NOT NULL DEFAULT 1 CHECK(weight > 0),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(required_attribute_id) REFERENCES business_attributes(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE(site_id, code),
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(required_field IS NOT NULL OR required_attribute_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_business_product_completeness_rules_scope
    ON business_product_completeness_rules(site_id, scope, channel, is_active);
CREATE TABLE IF NOT EXISTS business_product_completeness_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    variant_id INTEGER,
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','public','ecommerce','pos','catalogue','admin','pdf')),
    score INTEGER NOT NULL CHECK(score >= 0 AND score <= 100),
    is_sellable INTEGER NOT NULL DEFAULT 0 CHECK(is_sellable IN (0,1)),
    missing_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(missing_json)),
    calculated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(product_id > 0),
    CHECK(variant_id IS NULL OR variant_id > 0)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_completeness_scores_product
    ON business_product_completeness_scores(product_id, channel)
    WHERE variant_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_product_completeness_scores_variant
    ON business_product_completeness_scores(variant_id, channel)
    WHERE variant_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_business_product_completeness_scores_sellable
    ON business_product_completeness_scores(channel, is_sellable, score);
CREATE TABLE IF NOT EXISTS business_product_relations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    related_product_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL CHECK(relation_type IN ('accessory','alternative','bundle_candidate','replacement','upsell','cross_sell','similar')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(related_product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id, product_id, related_product_id, relation_type),
    CHECK(site_id > 0),
    CHECK(product_id > 0),
    CHECK(related_product_id > 0),
    CHECK(product_id <> related_product_id)
);
CREATE INDEX IF NOT EXISTS idx_business_product_relations_product
    ON business_product_relations(site_id, product_id, relation_type, sort_order);
CREATE INDEX IF NOT EXISTS idx_business_product_relations_related
    ON business_product_relations(site_id, related_product_id, relation_type);
CREATE INDEX IF NOT EXISTS idx_business_product_tags_site ON business_product_tags(site_id, archived_at, slug);

INSERT OR IGNORE INTO business_tax_classes(site_id, code, name, rate, country, is_default)
VALUES (1, 'standard', 'Standard CH', 8.1, 'CH', 1);

INSERT OR IGNORE INTO business_product_brands(site_id, name, slug, description, website_url, is_public, status, sort_order)
VALUES (1, 'Demo Outdoor', 'demo-outdoor', 'Marque de demonstration pour le catalogue Business.', 'https://example.test', 1, 'active', 10);

INSERT OR IGNORE INTO business_product_categories(site_id, parent_id, name, slug, description, is_public, sort_order)
VALUES
    (1, NULL, 'Experiences', 'experiences', 'Services et experiences de demonstration.', 1, 10),
    (1, NULL, 'Boutique', 'boutique', 'Produits physiques et bons cadeaux de demonstration.', 1, 20);

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'service', 'active', 'public', 'DEMO-VOL', 'Vol decouverte', 'vol-decouverte', 'Service catalogue de demonstration.', 'service', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'demo-outdoor' AND c.site_id = 1 AND c.slug = 'experiences' AND t.site_id = 1 AND t.code = 'standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'physical', 'active', 'public', 'DEMO-GOURDE', 'Gourde demo', 'gourde-demo', 'Produit physique catalogue de demonstration.', 'piece', t.id, 1, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'demo-outdoor' AND c.site_id = 1 AND c.slug = 'boutique' AND t.site_id = 1 AND t.code = 'standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'gift_card', 'active', 'public', 'DEMO-GIFT', 'Bon cadeau demo', 'bon-cadeau-demo', 'Bon cadeau simple de demonstration.', 'piece', t.id, 0, 0, 1, 1, 1, 1
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

INSERT OR IGNORE INTO business_catalog_discounts(site_id, name, status, discount_type, discount_value, currency, scope_type, scope_id, channel, priority)
SELECT 1, 'Lancement POS', 'active', 'percent', 10.00, NULL, 'product', p.id, 'pos', 100
FROM business_products p WHERE p.site_id = 1 AND p.slug = 'vol-decouverte';
