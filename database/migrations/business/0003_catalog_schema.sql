PRAGMA foreign_keys = ON;

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
