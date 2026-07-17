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
    ON business_contacts(site_id, iam_user_id)
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

-- Relation 360 keeps business roles and follow-up actions in CRM. These rows
-- do not duplicate Sale or Forms aggregates.
CREATE TABLE IF NOT EXISTS business_relation_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL CHECK(relation_type IN ('contact','company')),
    contact_id INTEGER,
    company_id INTEGER,
    role_key TEXT NOT NULL CHECK(role_key IN ('prospect','client','supplier','partner','other')),
    source TEXT NOT NULL DEFAULT 'operator' CHECK(source IN ('operator','import','sale_projection','system')),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, relation_type, contact_id, role_key),
    UNIQUE(site_id, relation_type, company_id, role_key),
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(company_id) REFERENCES business_companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK((relation_type='contact' AND contact_id IS NOT NULL AND company_id IS NULL)
       OR (relation_type='company' AND company_id IS NOT NULL AND contact_id IS NULL))
);

CREATE INDEX IF NOT EXISTS idx_business_relation_roles_view
    ON business_relation_roles(site_id, role_key, relation_type);

CREATE TABLE IF NOT EXISTS business_relation_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL CHECK(relation_type IN ('contact','company')),
    contact_id INTEGER,
    company_id INTEGER,
    title TEXT NOT NULL,
    due_at TEXT,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','completed','cancelled')),
    priority TEXT NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high')),
    assigned_to_iam_user_id INTEGER,
    created_by_iam_user_id INTEGER,
    completed_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT,
    updated_at TEXT,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(company_id) REFERENCES business_companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK((relation_type='contact' AND contact_id IS NOT NULL AND company_id IS NULL)
       OR (relation_type='company' AND company_id IS NOT NULL AND contact_id IS NULL)),
    CHECK(trim(title)<>''),
    CHECK(completed_at IS NULL OR status='completed')
);

CREATE INDEX IF NOT EXISTS idx_business_relation_tasks_due
    ON business_relation_tasks(site_id, status, due_at, id);

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
    product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','bundle')),
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
    external_id TEXT,
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
    sales_note TEXT,
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

CREATE TABLE IF NOT EXISTS business_sellables (
    sellable_id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, product_id INTEGER NOT NULL, variant_id INTEGER NOT NULL UNIQUE,
    kind TEXT NOT NULL CHECK(kind IN ('simple','variant','service','gift_card','bundle')), is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive','archived')), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE, CHECK(sellable_id=variant_id), CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_business_sellables_product ON business_sellables(site_id,product_id,status,is_default);
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_sellables_default ON business_sellables(product_id) WHERE is_default=1 AND status='active';

CREATE TABLE IF NOT EXISTS business_storefront_projection_invalidations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, product_id INTEGER,
    reason TEXT NOT NULL CHECK(reason IN ('product','variant','price','visibility','availability','media','collection')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, processed_at TEXT, CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_business_storefront_invalidations_pending ON business_storefront_projection_invalidations(processed_at,site_id,product_id);

-- Projection reconstruisible de la disponibilité Sale. Les colonnes de stock
-- historiques des variantes restent des données d'amorçage catalogue et ne
-- sont jamais une source transactionnelle après initialisation de Sale.
CREATE TABLE IF NOT EXISTS business_inventory_availability_projections (
    sellable_id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, tracked INTEGER NOT NULL CHECK(tracked IN (0,1)),
    on_hand_quantity INTEGER NOT NULL, reserved_quantity INTEGER NOT NULL, available_quantity INTEGER NOT NULL,
    availability_status TEXT NOT NULL CHECK(availability_status IN ('in_stock','deliverable','backorder','unavailable')),
    source_version INTEGER NOT NULL DEFAULT 0, projected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(sellable_id) REFERENCES business_sellables(sellable_id) ON DELETE CASCADE,
    CHECK(site_id>0), CHECK(available_quantity=on_hand_quantity-reserved_quantity)
);
CREATE INDEX IF NOT EXISTS idx_business_inventory_projection_site ON business_inventory_availability_projections(site_id,availability_status);

CREATE UNIQUE INDEX IF NOT EXISTS idx_business_products_external_id
    ON business_products(site_id, external_id)
    WHERE external_id IS NOT NULL AND archived_at IS NULL;

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
    customer_segment TEXT,
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
    CHECK(customer_segment IS NULL OR customer_segment = lower(trim(customer_segment))),
    CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);

CREATE TABLE IF NOT EXISTS business_price_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    currency TEXT NOT NULL CHECK(currency IN ('CHF','EUR','USD')),
    channel TEXT NOT NULL DEFAULT 'all' CHECK(channel IN ('all','ecommerce','pos','catalogue','admin')),
    customer_segment TEXT,
    priority INTEGER NOT NULL DEFAULT 100,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    starts_at TEXT,
    ends_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    CHECK(site_id > 0),
    CHECK(trim(name) <> ''),
    CHECK(customer_segment IS NULL OR customer_segment = lower(trim(customer_segment))),
    CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);

CREATE TABLE IF NOT EXISTS business_price_list_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    price_list_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER,
    adjustment_type TEXT NOT NULL DEFAULT 'fixed' CHECK(adjustment_type IN ('fixed','amount_delta','percent_delta')),
    adjustment_value REAL NOT NULL,
    compare_at_amount REAL,
    priority INTEGER NOT NULL DEFAULT 100,
    starts_at TEXT,
    ends_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    FOREIGN KEY(price_list_id) REFERENCES business_price_lists(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(variant_id) REFERENCES business_product_variants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK((adjustment_type = 'fixed' AND adjustment_value >= 0)
       OR adjustment_type = 'amount_delta'
       OR (adjustment_type = 'percent_delta' AND adjustment_value >= -100 AND adjustment_value <= 1000)),
    CHECK(compare_at_amount IS NULL OR compare_at_amount >= 0),
    CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at),
    UNIQUE(price_list_id, product_id, variant_id)
);

CREATE TABLE IF NOT EXISTS business_product_bundles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    bundle_product_id INTEGER NOT NULL,
    bundle_variant_id INTEGER,
    pricing_mode TEXT NOT NULL DEFAULT 'fixed' CHECK(pricing_mode IN ('fixed','sum_components','discount_components')),
    stock_mode TEXT NOT NULL DEFAULT 'components' CHECK(stock_mode IN ('components','virtual','none')),
    stock_strategy TEXT NOT NULL DEFAULT 'COMPONENT_DERIVED' CHECK(stock_strategy IN ('OWN_STOCK','COMPONENT_DERIVED','NON_STOCKED')),
    partial_availability_policy TEXT NOT NULL DEFAULT 'REQUIRE_ALL' CHECK(partial_availability_policy IN ('REQUIRE_ALL','ALLOW_PARTIAL')),
    partial_fulfillment_supported INTEGER NOT NULL DEFAULT 0 CHECK(partial_fulfillment_supported IN (0,1)),
    component_return_policy TEXT NOT NULL DEFAULT 'BUNDLE_ONLY' CHECK(component_return_policy IN ('BUNDLE_ONLY','COMPONENTS_ALLOWED')),
    components_public INTEGER NOT NULL DEFAULT 1 CHECK(components_public IN (0,1)),
    composition_type TEXT NOT NULL DEFAULT 'bundle' CHECK(composition_type IN ('bundle','kit')),
    unavailable_strategy TEXT NOT NULL DEFAULT 'reject' CHECK(unavailable_strategy IN ('reject','backorder','contact')),
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
    CHECK(bundle_variant_id IS NULL OR bundle_variant_id > 0),
    CHECK((stock_strategy='OWN_STOCK' AND stock_mode='virtual') OR (stock_strategy='COMPONENT_DERIVED' AND stock_mode='components') OR (stock_strategy='NON_STOCKED' AND stock_mode='none')),
    CHECK(partial_availability_policy<>'ALLOW_PARTIAL' OR partial_fulfillment_supported=1)
);

CREATE TABLE IF NOT EXISTS business_gift_card_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL UNIQUE,
    currency TEXT NOT NULL CHECK(currency IN ('CHF','EUR','USD')),
    value_mode TEXT NOT NULL DEFAULT 'fixed' CHECK(value_mode IN ('fixed','open')),
    minimum_amount REAL,
    maximum_amount REAL,
    expires_after_days INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(minimum_amount IS NULL OR minimum_amount >= 0),
    CHECK(maximum_amount IS NULL OR maximum_amount >= 0),
    CHECK(maximum_amount IS NULL OR minimum_amount IS NULL OR maximum_amount >= minimum_amount),
    CHECK(expires_after_days IS NULL OR expires_after_days > 0)
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

CREATE TRIGGER IF NOT EXISTS trg_business_stock_movements_no_update
BEFORE UPDATE ON business_stock_movements
BEGIN SELECT RAISE(ABORT, 'business stock movements are immutable'); END;

CREATE TRIGGER IF NOT EXISTS trg_business_stock_movements_no_delete
BEFORE DELETE ON business_stock_movements
BEGIN SELECT RAISE(ABORT, 'business stock movements are immutable'); END;

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
CREATE INDEX IF NOT EXISTS idx_business_catalog_discounts_segment ON business_catalog_discounts(site_id, customer_segment, channel, status, priority);
CREATE INDEX IF NOT EXISTS idx_business_price_lists_context ON business_price_lists(site_id, currency, channel, customer_segment, status, priority);
CREATE INDEX IF NOT EXISTS idx_business_price_list_items_target ON business_price_list_items(product_id, variant_id, priority, archived_at);
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
    product_type TEXT NOT NULL DEFAULT 'all' CHECK(product_type IN ('all','physical','service','gift_card','bundle')),
    severity TEXT NOT NULL DEFAULT 'block' CHECK(severity IN ('block','warn')),
    required_language TEXT,
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

CREATE TABLE IF NOT EXISTS business_product_channel_visibility (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('public','ecommerce','pos','catalogue')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','archived')),
    starts_at TEXT,
    ends_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id, product_id, channel),
    CHECK(site_id > 0),
    CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);

CREATE INDEX IF NOT EXISTS idx_business_product_channel_visibility_context
    ON business_product_channel_visibility(site_id, channel, status, starts_at, ends_at);

CREATE TABLE IF NOT EXISTS business_sales_channel_configs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL UNIQUE, site_id INTEGER NOT NULL,
    catalog_channel TEXT NOT NULL CHECK(catalog_channel IN ('public','ecommerce','pos','catalogue','admin','partner')),
    price_list_code TEXT, visibility_policy TEXT NOT NULL DEFAULT 'published' CHECK(visibility_policy IN ('published','private','all')),
    default_currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(default_currency)=3 AND default_currency=upper(default_currency)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT, CHECK(channel_id>0), CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_business_sales_channel_configs_site ON business_sales_channel_configs(site_id,status,catalog_channel);

CREATE TRIGGER IF NOT EXISTS trg_business_product_channel_visibility_site_insert
BEFORE INSERT ON business_product_channel_visibility
BEGIN
    SELECT RAISE(ABORT, 'business channel visibility product must belong to site')
    WHERE NOT EXISTS (SELECT 1 FROM business_products p WHERE p.id = NEW.product_id AND p.site_id = NEW.site_id);
END;

CREATE TRIGGER IF NOT EXISTS trg_business_product_channel_visibility_site_update
BEFORE UPDATE OF site_id, product_id ON business_product_channel_visibility
BEGIN
    SELECT RAISE(ABORT, 'business channel visibility product must belong to site')
    WHERE NOT EXISTS (SELECT 1 FROM business_products p WHERE p.id = NEW.product_id AND p.site_id = NEW.site_id);
END;

CREATE TABLE IF NOT EXISTS business_catalog_import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    idempotency_key TEXT NOT NULL,
    format_version TEXT NOT NULL,
    checksum TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('applied','rejected')),
    rows_total INTEGER NOT NULL DEFAULT 0 CHECK(rows_total >= 0),
    changed_rows INTEGER NOT NULL DEFAULT 0 CHECK(changed_rows >= 0),
    summary_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(summary_json)),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, idempotency_key),
    CHECK(site_id > 0),
    CHECK(trim(idempotency_key) <> ''),
    CHECK(trim(format_version) <> ''),
    CHECK(trim(checksum) <> '')
);

CREATE INDEX IF NOT EXISTS idx_business_catalog_import_runs_site_created
    ON business_catalog_import_runs(site_id, created_at DESC);

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

INSERT OR IGNORE INTO business_product_brands(site_id, name, slug, description, website_url, is_public, status, sort_order)
VALUES (1, 'NOUVELLE MARQUE', 'nouvelle-marque', 'Marque de demonstration du catalogue Business.', 'https://example.test', 1, 'active', 20);

INSERT OR IGNORE INTO business_product_categories(site_id, parent_id, name, slug, description, is_public, sort_order)
VALUES
    (1, NULL, 'Services', 'services', 'Services de demonstration du catalogue.', 1, 30),
    (1, NULL, 'Marchandises', 'marchandises', 'Marchandises de demonstration du catalogue.', 1, 40);

INSERT OR IGNORE INTO business_tax_classes(site_id, code, name, rate, country, is_default)
VALUES (1, 'catalog_standard', 'Catalogue standard CH', 8.1, 'CH', 0);

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'physical', 'active', 'public', 'TSHIRT-DEMO', 'T-shirt Demo', 't-shirt-demo', 'Marchandise de demonstration avec variantes.', 'piece', t.id, 1, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'marchandises' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'service', 'active', 'public', 'CONSULTATION', 'Consultation', 'consultation', 'Service de demonstration du catalogue.', 'service', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'gift_card', 'active', 'public', 'GIFT-DEMO', 'Bon cadeau simple', 'bon-cadeau-simple', 'Bon cadeau simple de demonstration du catalogue.', 'piece', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'bundle', 'active', 'public', 'BUNDLE-DEMO', 'Pack demo', 'pack-demo', 'Offre composee de demonstration regroupant un produit et un service.', 'bundle', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'marchandises' AND t.site_id = 1 AND t.code = 'catalog_standard';

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
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 59.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'pack-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 119.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-M-BLUE', 'M / Bleu', 1, 15, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-L-BLUE', 'L / Bleu', 1, 10, 0, 0, 20 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-M-BLACK', 'M / Noir', 1, 8, 0, 0, 30 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'CONSULTATION-STANDARD', 'Consultation standard', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'GIFT-DEMO-100', 'Bon cadeau simple 100 CHF', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'BUNDLE-DEMO-STANDARD', 'Pack demo standard', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_bundles(site_id, bundle_product_id, bundle_variant_id, pricing_mode, stock_mode, is_active)
SELECT 1, p.id, v.id, 'fixed', 'components', 1
FROM business_products p
INNER JOIN business_product_variants v ON v.product_id = p.id
WHERE p.site_id = 1 AND p.slug = 'pack-demo' AND v.sku = 'BUNDLE-DEMO-STANDARD';

INSERT OR IGNORE INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required, sort_order, metadata_json)
SELECT b.id, p.id, v.id, 1, 1, 10, '{"fixture":"pim_lite","component":"product"}'
FROM business_product_bundles b
INNER JOIN business_products bundle_product ON bundle_product.id = b.bundle_product_id
INNER JOIN business_products p ON p.site_id = b.site_id AND p.slug = 't-shirt-demo'
INNER JOIN business_product_variants v ON v.product_id = p.id AND v.sku = 'TSHIRT-DEMO-M-BLUE'
WHERE bundle_product.slug = 'pack-demo';

INSERT OR IGNORE INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required, sort_order, metadata_json)
SELECT b.id, p.id, v.id, 1, 1, 20, '{"fixture":"pim_lite","component":"service"}'
FROM business_product_bundles b
INNER JOIN business_products bundle_product ON bundle_product.id = b.bundle_product_id
INNER JOIN business_products p ON p.site_id = b.site_id AND p.slug = 'consultation'
INNER JOIN business_product_variants v ON v.product_id = p.id AND v.sku = 'CONSULTATION-STANDARD'
WHERE bundle_product.slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'amount_delta', 2.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'amount_delta', 5.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'percent_delta', 15.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-M-BLACK';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'percent_delta', 25.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-M-BLACK';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
VALUES (1, NULL, NULL, 'physical', 'draft', 'internal', 'INCOMPLETE-DEMO', 'Produit incomplet demo', 'produit-incomplet-demo', 'Produit volontairement incomplet pour tester la qualite catalogue.', 'piece', 1, 0, 0, 0, 0, 1);

INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'draft', 'INCOMPLETE-DEMO-DRAFT', 'Brouillon incomplet', 1, 0, 0, 0, 10
FROM business_products
WHERE site_id = 1 AND slug = 'produit-incomplet-demo';

INSERT OR IGNORE INTO business_asset_metadata(site_id, media_id, asset_type, usage_rights, license, credit, source, metadata_json)
VALUES
    (1, 4, 'image', 'owned', 'demo', 'DEC CMS demo', 'seed', '{"fixture":"pim_lite","kind":"main_image"}'),
    (1, 5, 'image', 'owned', 'demo', 'DEC CMS demo', 'seed', '{"fixture":"pim_lite","kind":"variant_image"}'),
    (1, 6, 'document', 'owned', 'demo', 'DEC CMS demo', 'seed', '{"fixture":"pim_lite","kind":"technical_sheet"}');

INSERT OR IGNORE INTO business_asset_renditions(site_id, media_id, channel, rendition_key, width, height, format, file_size, generated_media_id)
VALUES
    (1, 4, 'ecommerce', 'card', 800, 600, 'webp', 120000, NULL),
    (1, 5, 'pos', 'thumb', 320, 320, 'webp', 42000, NULL),
    (1, 6, 'pdf', 'original', NULL, NULL, 'pdf', 64000, NULL);

INSERT OR IGNORE INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
SELECT 1, p.id, 4, 'main', 'Image principale gourde', 'Gourde demo bleue', 'Image principale de demonstration.', 10, 1, 'all'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 'gourde-demo';

INSERT OR IGNORE INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
SELECT 1, p.id, 5, 'main', 'Image principale textile', 'T-shirt demo', 'Image principale textile.', 10, 1, 'all'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo';

INSERT OR IGNORE INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
SELECT 1, p.id, 4, 'main', 'Image principale service', 'Consultation demo', 'Image principale service.', 10, 1, 'ecommerce'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 'consultation';

INSERT OR IGNORE INTO business_product_assets(site_id, product_id, variant_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
SELECT 1, p.id, v.id, 5, 'main', 'Image variante gourde bleue', 'Variante gourde bleue', 'Image specifique POS.', 10, 1, 'pos'
FROM business_products p
INNER JOIN business_product_variants v ON v.product_id = p.id
WHERE p.site_id = 1 AND v.sku = 'DEMO-GOURDE-BLEU';

INSERT OR IGNORE INTO business_product_assets(site_id, product_id, media_id, role, title, alt_text, caption, sort_order, is_public, channel_scope)
SELECT 1, p.id, 6, 'technical_sheet', 'Fiche technique fictive', 'Fiche technique de demonstration', 'Document technique fictif pour tester le PIM-lite.', 50, 0, 'pdf'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo';

INSERT OR IGNORE INTO business_attribute_groups(site_id, code, name, description, sort_order)
VALUES
    (1, 'textile', 'Textile', 'Attributs de demonstration pour les produits textiles.', 10),
    (1, 'service', 'Service', 'Attributs de demonstration pour les prestations.', 20),
    (1, 'technique', 'Technique', 'Attributs techniques communs.', 30),
    (1, 'seo', 'SEO', 'Attributs de qualification SEO catalogue.', 40);

INSERT OR IGNORE INTO business_product_attribute_group_links(product_id, group_id, sort_order)
SELECT p.id, g.id, 10
FROM business_products p, business_attribute_groups g
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_product_attribute_group_links(product_id, group_id, sort_order)
SELECT p.id, g.id, 20
FROM business_products p, business_attribute_groups g
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND g.site_id = 1 AND g.code = 'technique';
INSERT OR IGNORE INTO business_product_attribute_group_links(product_id, group_id, sort_order)
SELECT p.id, g.id, 10
FROM business_products p, business_attribute_groups g
WHERE p.site_id = 1 AND p.slug IN ('vol-decouverte', 'consultation') AND g.site_id = 1 AND g.code = 'service';

INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'couleur', 'Couleur', 'color', NULL, 1, 1, 1, 1, 10, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'taille', 'Taille', 'select', NULL, 1, 1, 1, 1, 20, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'duree', 'Duree', 'number', 'min', 0, 1, 0, 1, 10, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'service';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'matiere', 'Matiere', 'select', NULL, 1, 1, 1, 1, 30, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'niveau', 'Niveau', 'select', NULL, 0, 1, 1, 1, 20, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'service';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'poids', 'Poids', 'weight', 'g', 0, 1, 0, 0, 10, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'technique';

INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, color_hex, sort_order)
SELECT id, 'bleu', 'Bleu', 'bleu', '#0066CC', 10 FROM business_attributes WHERE site_id = 1 AND code = 'couleur';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, color_hex, sort_order)
SELECT id, 'noir', 'Noir', 'noir', '#000000', 20 FROM business_attributes WHERE site_id = 1 AND code = 'couleur';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'm', 'M', 'm', 20 FROM business_attributes WHERE site_id = 1 AND code = 'taille';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'l', 'L', 'l', 30 FROM business_attributes WHERE site_id = 1 AND code = 'taille';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'coton', 'Coton', 'coton', 10 FROM business_attributes WHERE site_id = 1 AND code = 'matiere';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'debutant', 'Debutant', 'debutant', 10 FROM business_attributes WHERE site_id = 1 AND code = 'niveau';

INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_text)
SELECT p.id, a.id, 'fr', 'Coton'
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND a.site_id = 1 AND a.code = 'matiere';
INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_number)
SELECT p.id, a.id, 'und', 180
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND a.site_id = 1 AND a.code = 'poids';
INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_number)
SELECT p.id, a.id, 'und', 60
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 'consultation' AND a.site_id = 1 AND a.code = 'duree';
INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_text)
SELECT p.id, a.id, 'fr', 'Debutant'
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 'vol-decouverte' AND a.site_id = 1 AND a.code = 'niveau';

INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'M'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLUE' AND a.site_id = 1 AND a.code = 'taille';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'Bleu'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLUE' AND a.site_id = 1 AND a.code = 'couleur';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'L'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-L-BLUE' AND a.site_id = 1 AND a.code = 'taille';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'Bleu'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-L-BLUE' AND a.site_id = 1 AND a.code = 'couleur';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'M'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLACK' AND a.site_id = 1 AND a.code = 'taille';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'Noir'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLACK' AND a.site_id = 1 AND a.code = 'couleur';

INSERT OR IGNORE INTO business_product_completeness_rules(site_id, code, name, scope, required_field, channel, weight, is_active)
VALUES
    (1, 'name-required', 'Nom requis', 'product', 'name', 'all', 2, 1),
    (1, 'variant-sku-required', 'SKU variante requis', 'variant', 'sku', 'all', 2, 1),
    (1, 'sale-price-required', 'Prix de vente requis', 'price', 'sale_price', 'all', 3, 1),
    (1, 'tax-required', 'TVA requise', 'tax', 'tax_class_id', 'all', 1, 1),
    (1, 'main-image-pos-required', 'Image principale POS requise', 'asset', 'main_asset', 'pos', 2, 1),
    (1, 'active-variant-required', 'Variante active requise', 'variant', 'active_variant', 'all', 2, 1),
    (1, 'pos-channel-required', 'Canal POS active', 'channel', 'is_pos_enabled', 'pos', 2, 1),
    (1, 'ecommerce-channel-required', 'Canal e-commerce active', 'channel', 'is_ecommerce_enabled', 'ecommerce', 2, 1);

INSERT OR IGNORE INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json)
SELECT p.id, 'pos', 100, 1, '[]'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 'gourde-demo';
INSERT OR IGNORE INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json)
SELECT p.id, v.id, 'pos', 100, 1, '[]'
FROM business_products p
INNER JOIN business_product_variants v ON v.product_id = p.id
WHERE p.site_id = 1 AND v.sku = 'DEMO-GOURDE-BLEU';
INSERT OR IGNORE INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json)
SELECT p.id, 'ecommerce', 95, 1, '[]'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 'consultation';
INSERT OR IGNORE INTO business_product_completeness_scores(product_id, channel, score, is_sellable, missing_json)
SELECT p.id, 'all', 30, 0, '["brand","category","tax_class","sale_price","main_asset","active_channel"]'
FROM business_products p
WHERE p.site_id = 1 AND p.slug = 'produit-incomplet-demo';
INSERT OR IGNORE INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json)
SELECT p.id, v.id, 'all', 20, 0, '["active_variant","stock","sale_price"]'
FROM business_products p
INNER JOIN business_product_variants v ON v.product_id = p.id
WHERE p.site_id = 1 AND v.sku = 'INCOMPLETE-DEMO-DRAFT';

INSERT OR IGNORE INTO business_product_relations(site_id, product_id, related_product_id, relation_type, sort_order)
SELECT 1, source.id, target.id, 'cross_sell', 10
FROM business_products source, business_products target
WHERE source.site_id = 1 AND target.site_id = 1 AND source.slug = 't-shirt-demo' AND target.slug = 'gourde-demo';

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

-- Marketing consent is deliberately distinct from contact preferences,
-- transactional necessity, account creation and purchases. The current state
-- remains convenient to query in crm_consents while this ledger is append-only.
CREATE TABLE IF NOT EXISTS crm_consent_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    consent_id INTEGER,
    site_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('email','whatsapp','telegram')),
    purpose TEXT NOT NULL DEFAULT 'marketing' CHECK(purpose = 'marketing'),
    scope_type TEXT NOT NULL DEFAULT 'site' CHECK(scope_type = 'site'),
    scope_id INTEGER NOT NULL,
    consent_status TEXT NOT NULL CHECK(consent_status IN ('unknown','opt_in','opt_out')),
    event_type TEXT NOT NULL CHECK(event_type IN ('recorded','granted','withdrawn')),
    source TEXT NOT NULL CHECK(source IN ('manual','form','import','unsubscribe','api')),
    evidence TEXT,
    proof_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(proof_json)),
    occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    retention_until TEXT,
    actor_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(consent_id) REFERENCES crm_consents(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0 AND scope_id = site_id),
    CHECK((consent_status = 'opt_in' AND event_type = 'granted')
       OR (consent_status = 'opt_out' AND event_type = 'withdrawn')
       OR (consent_status = 'unknown' AND event_type = 'recorded'))
);

CREATE INDEX IF NOT EXISTS idx_crm_consent_events_contact ON crm_consent_events(site_id, contact_id, occurred_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_crm_consent_events_retention ON crm_consent_events(retention_until);

CREATE TABLE IF NOT EXISTS crm_contact_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    preferred_channel TEXT CHECK(preferred_channel IS NULL OR preferred_channel IN ('email','whatsapp','telegram')),
    contact_window TEXT,
    do_not_contact INTEGER NOT NULL DEFAULT 0 CHECK(do_not_contact IN (0,1)),
    source TEXT NOT NULL DEFAULT 'manual' CHECK(source IN ('manual','form','import','api')),
    note TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id, contact_id),
    CHECK(site_id > 0),
    CHECK(contact_window IS NULL OR length(contact_window) <= 120),
    CHECK(note IS NULL OR length(note) <= 500)
);

CREATE INDEX IF NOT EXISTS idx_crm_contact_preferences_channel ON crm_contact_preferences(site_id, preferred_channel, do_not_contact);

CREATE TABLE IF NOT EXISTS crm_segments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    segment_kind TEXT NOT NULL CHECK(segment_kind IN ('calculated','manual')),
    criterion TEXT,
    operator TEXT,
    value_json TEXT NOT NULL DEFAULT 'null' CHECK(json_valid(value_json)),
    rule_version INTEGER NOT NULL DEFAULT 1 CHECK(rule_version > 0),
    explanation TEXT NOT NULL DEFAULT '',
    advanced_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(advanced_json)),
    retention_days INTEGER NOT NULL DEFAULT 730 CHECK(retention_days BETWEEN 30 AND 3650),
    result_count INTEGER NOT NULL DEFAULT 0 CHECK(result_count >= 0),
    last_source_activity_id INTEGER NOT NULL DEFAULT 0 CHECK(last_source_activity_id >= 0),
    last_calculated_at TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, name),
    CHECK(site_id > 0 AND trim(name) <> ''),
    CHECK((segment_kind = 'manual' AND criterion IS NULL AND operator IS NULL)
       OR (segment_kind = 'calculated' AND trim(criterion) <> '' AND trim(operator) <> ''))
);

CREATE TABLE IF NOT EXISTS crm_segment_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    segment_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    membership_kind TEXT NOT NULL CHECK(membership_kind IN ('calculated','manual')),
    rule_version INTEGER NOT NULL CHECK(rule_version > 0),
    explanation_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(explanation_json)),
    matched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(segment_id, contact_id),
    FOREIGN KEY(segment_id) REFERENCES crm_segments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES business_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_crm_segments_site_kind ON crm_segments(site_id, segment_kind, status, name);
CREATE INDEX IF NOT EXISTS idx_crm_segment_members_contact ON crm_segment_members(contact_id, expires_at);

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

CREATE TABLE IF NOT EXISTS business_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    actor_iam_user_id INTEGER,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    related_company_id INTEGER,
    related_contact_id INTEGER,
    action TEXT NOT NULL,
    summary TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(entity_id > 0),
    CHECK(trim(entity_type) <> ''),
    CHECK(trim(action) <> ''),
    CHECK(trim(summary) <> '')
);

CREATE INDEX IF NOT EXISTS idx_business_activity_site_created ON business_activity_log(site_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_business_activity_company ON business_activity_log(site_id, related_company_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_business_activity_contact ON business_activity_log(site_id, related_contact_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_business_activity_entity ON business_activity_log(site_id, entity_type, entity_id, created_at DESC);

-- Rebuildable CRM read model fed exclusively from Sale integration events.
-- Sale remains the source of truth: this projection must never be used to write
-- customer or order snapshots back into the Sale database.
CREATE TABLE IF NOT EXISTS crm_sale_activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dto_version INTEGER NOT NULL DEFAULT 2 CHECK(dto_version = 2),
    contract_version TEXT NOT NULL DEFAULT 'crm.activity.v2' CHECK(contract_version = 'crm.activity.v2'),
    site_id INTEGER NOT NULL,
    activity_type TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    channel TEXT NOT NULL CHECK(channel IN ('web','pos','admin','unknown')),
    channel_id INTEGER,
    language_code TEXT,
    related_company_id INTEGER,
    related_contact_id INTEGER,
    source_event_id INTEGER NOT NULL,
    source_outbox_id INTEGER NOT NULL,
    source_type TEXT NOT NULL DEFAULT 'sale_event',
    source_id TEXT NOT NULL,
    source_event_type TEXT NOT NULL,
    source_aggregate_type TEXT NOT NULL,
    source_aggregate_id INTEGER NOT NULL,
    source_reference TEXT,
    summary TEXT NOT NULL,
    status TEXT NOT NULL,
    resolution_strategy TEXT NOT NULL CHECK(resolution_strategy IN ('explicit_event','event_correlation','manual','pending')),
    provenance_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(provenance_json)),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    retention_until TEXT,
    linked_by_iam_user_id INTEGER,
    linked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    CHECK(site_id > 0),
    CHECK(source_event_id > 0),
    CHECK(source_outbox_id > 0),
    CHECK(source_aggregate_id > 0),
    CHECK(trim(activity_type) <> ''),
    CHECK(trim(summary) <> ''),
    UNIQUE(source_type, source_id, contract_version),
    CHECK((resolution_strategy = 'pending' AND related_company_id IS NULL AND related_contact_id IS NULL)
       OR resolution_strategy <> 'pending')
);

CREATE INDEX IF NOT EXISTS idx_crm_sale_activities_contact ON crm_sale_activities(site_id, related_contact_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_sale_activities_company ON crm_sale_activities(site_id, related_company_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_sale_activities_unlinked ON crm_sale_activities(site_id, occurred_at DESC) WHERE resolution_strategy = 'pending';
CREATE INDEX IF NOT EXISTS idx_crm_sale_activities_source ON crm_sale_activities(source_event_type, source_aggregate_type, source_aggregate_id);

-- Rebuildable, privacy-minimised read model fed by the Forms port. The form
-- submission remains canonical in the Forms database; payloads are never
-- copied here.
CREATE TABLE IF NOT EXISTS crm_form_submission_activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_version TEXT NOT NULL DEFAULT 'crm.form_activity.v1',
    site_id INTEGER NOT NULL,
    form_id INTEGER NOT NULL,
    form_key TEXT NOT NULL,
    form_name TEXT,
    submission_id INTEGER NOT NULL,
    submission_status TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    related_company_id INTEGER,
    related_contact_id INTEGER,
    resolution_strategy TEXT NOT NULL CHECK(resolution_strategy IN ('explicit','verified_email','manual','pending','dismissed','postponed')),
    resolution_evidence_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(resolution_evidence_json)),
    candidate_count INTEGER NOT NULL DEFAULT 0 CHECK(candidate_count>=0),
    safe_summary TEXT NOT NULL,
    retention_until TEXT,
    linked_by_iam_user_id INTEGER,
    linked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(site_id, form_id, submission_id, contract_version),
    CHECK(site_id>0 AND form_id>0 AND submission_id>0),
    CHECK((resolution_strategy='pending' AND related_company_id IS NULL AND related_contact_id IS NULL)
       OR resolution_strategy<>'pending')
);

CREATE INDEX IF NOT EXISTS idx_crm_form_activity_relation_contact
    ON crm_form_submission_activities(site_id, related_contact_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_form_activity_relation_company
    ON crm_form_submission_activities(site_id, related_company_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_crm_form_activity_pending
    ON crm_form_submission_activities(site_id, occurred_at DESC) WHERE resolution_strategy='pending';

CREATE TABLE IF NOT EXISTS crm_form_submission_link_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    activity_id INTEGER NOT NULL,
    previous_company_id INTEGER,
    previous_contact_id INTEGER,
    company_id INTEGER,
    contact_id INTEGER,
    decision TEXT NOT NULL CHECK(decision IN ('link','unlink','postpone')),
    reason TEXT NOT NULL,
    decided_by_iam_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(activity_id) REFERENCES crm_form_submission_activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(trim(reason)<>'' AND decided_by_iam_user_id>0)
);

CREATE TRIGGER IF NOT EXISTS trg_crm_form_link_audit_no_update
BEFORE UPDATE ON crm_form_submission_link_audit BEGIN SELECT RAISE(ABORT, 'CRM form link audit is immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_crm_form_link_audit_no_delete
BEFORE DELETE ON crm_form_submission_link_audit BEGIN SELECT RAISE(ABORT, 'CRM form link audit is immutable'); END;

CREATE TABLE IF NOT EXISTS crm_sale_activity_link_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    activity_id INTEGER NOT NULL,
    previous_company_id INTEGER,
    previous_contact_id INTEGER,
    company_id INTEGER,
    contact_id INTEGER,
    reason TEXT NOT NULL,
    linked_by_iam_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(activity_id) REFERENCES crm_sale_activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(company_id IS NOT NULL OR contact_id IS NOT NULL),
    CHECK(linked_by_iam_user_id > 0),
    CHECK(trim(reason) <> '')
);

CREATE INDEX IF NOT EXISTS idx_crm_sale_activity_link_audit_activity ON crm_sale_activity_link_audit(activity_id, created_at, id);

CREATE TRIGGER IF NOT EXISTS trg_crm_sale_activity_link_audit_no_update
BEFORE UPDATE ON crm_sale_activity_link_audit BEGIN SELECT RAISE(ABORT, 'CRM sale activity link audit is immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_crm_sale_activity_link_audit_no_delete
BEFORE DELETE ON crm_sale_activity_link_audit BEGIN SELECT RAISE(ABORT, 'CRM sale activity link audit is immutable'); END;

CREATE TABLE IF NOT EXISTS crm_sale_activity_reconciliation_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    supported_events INTEGER NOT NULL DEFAULT 0,
    projected_events INTEGER NOT NULL DEFAULT 0,
    missing_events INTEGER NOT NULL DEFAULT 0,
    duplicate_events INTEGER NOT NULL DEFAULT 0,
    repaired_events INTEGER NOT NULL DEFAULT 0,
    report_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(report_json)),
    run_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0)
);

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

INSERT OR IGNORE INTO business_sales_channel_configs(channel_id,site_id,catalog_channel,price_list_code,visibility_policy,default_currency,status)
VALUES
    (1,1,'admin',NULL,'published','CHF','active'),
    (2,1,'pos',NULL,'published','CHF','disabled'),
    (3,1,'ecommerce',NULL,'published','CHF','active');

INSERT INTO business_product_variants(product_id,status,sku,name,track_stock,allow_backorder)
SELECT p.id,CASE WHEN p.status='active' THEN 'active' ELSE 'draft' END,'AUTO-' || p.id,'Default',p.track_stock,p.allow_backorder
FROM business_products p WHERE p.archived_at IS NULL
AND NOT EXISTS(SELECT 1 FROM business_product_variants v WHERE v.product_id=p.id AND v.archived_at IS NULL);
INSERT OR IGNORE INTO business_sellables(sellable_id,site_id,product_id,variant_id,kind,is_default,status)
SELECT v.id,p.site_id,p.id,v.id,
 CASE p.type WHEN 'service' THEN 'service' WHEN 'gift_card' THEN 'gift_card' WHEN 'bundle' THEN 'bundle'
 ELSE CASE WHEN (SELECT COUNT(*) FROM business_product_variants vx WHERE vx.product_id=p.id AND vx.archived_at IS NULL)>1 THEN 'variant' ELSE 'simple' END END,
 CASE WHEN v.id=(SELECT MIN(vd.id) FROM business_product_variants vd WHERE vd.product_id=p.id AND vd.archived_at IS NULL) THEN 1 ELSE 0 END,
 CASE WHEN p.status='active' AND v.status='active' THEN 'active' WHEN p.status='archived' OR v.status='archived' THEN 'archived' ELSE 'inactive' END
FROM business_product_variants v JOIN business_products p ON p.id=v.product_id WHERE p.archived_at IS NULL AND v.archived_at IS NULL;

CREATE TRIGGER IF NOT EXISTS trg_storefront_product_invalidation AFTER UPDATE ON business_products BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(NEW.site_id,NEW.id,'product'); END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_variant_invalidation AFTER UPDATE ON business_product_variants BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'variant' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_price_invalidation_insert AFTER INSERT ON business_product_base_prices BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'price' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_price_invalidation_update AFTER UPDATE ON business_product_base_prices BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'price' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_visibility_invalidation AFTER INSERT ON business_product_channel_visibility BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(NEW.site_id,NEW.product_id,'visibility'); END;
CREATE TRIGGER IF NOT EXISTS trg_business_sellable_variant_insert AFTER INSERT ON business_product_variants BEGIN
 INSERT OR IGNORE INTO business_sellables(sellable_id,site_id,product_id,variant_id,kind,is_default,status)
 SELECT NEW.id,p.site_id,p.id,NEW.id,CASE p.type WHEN 'service' THEN 'service' WHEN 'gift_card' THEN 'gift_card' WHEN 'bundle' THEN 'bundle'
 ELSE CASE WHEN EXISTS(SELECT 1 FROM business_sellables s WHERE s.product_id=p.id) THEN 'variant' ELSE 'simple' END END,
 CASE WHEN EXISTS(SELECT 1 FROM business_sellables s WHERE s.product_id=p.id AND s.status='active') THEN 0 ELSE 1 END,
 CASE WHEN p.status='active' AND NEW.status='active' THEN 'active' WHEN NEW.status='archived' THEN 'archived' ELSE 'inactive' END
 FROM business_products p WHERE p.id=NEW.product_id;
END;
CREATE TRIGGER IF NOT EXISTS trg_business_sellable_variant_update AFTER UPDATE OF status,product_id ON business_product_variants BEGIN
 UPDATE business_sellables SET product_id=NEW.product_id,status=CASE WHEN NEW.status='active' AND (SELECT status FROM business_products WHERE id=NEW.product_id)='active' THEN 'active' WHEN NEW.status='archived' THEN 'archived' ELSE 'inactive' END,updated_at=CURRENT_TIMESTAMP WHERE variant_id=NEW.id;
END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_asset_invalidation AFTER INSERT ON business_product_assets BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,NEW.product_id,'media' FROM business_products p WHERE p.id=NEW.product_id; END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_visibility_invalidation_update AFTER UPDATE ON business_product_channel_visibility BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(NEW.site_id,NEW.product_id,'visibility'); END;
CREATE TRIGGER IF NOT EXISTS trg_storefront_price_invalidation_delete AFTER DELETE ON business_product_base_prices BEGIN
 INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) SELECT p.site_id,OLD.product_id,'price' FROM business_products p WHERE p.id=OLD.product_id; END;
