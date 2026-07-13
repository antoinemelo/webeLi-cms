PRAGMA foreign_keys = ON;

-- Module Vente: core commercial, canaux, reglages et caches de references.
--
-- Ce schema ne declare aucune cle etrangere vers business.sqlite. Les
-- references CRM/catalogue venant du module Operations sont conservees comme
-- identifiants stables plus snapshots JSON afin que les commandes Vente
-- restent reproductibles apres validation.

CREATE TABLE IF NOT EXISTS sale_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    channel_type TEXT NOT NULL CHECK(channel_type IN ('ecommerce','pos','admin')),
    channel_kind TEXT NOT NULL CHECK(channel_kind IN ('storefront','pos','admin','partner')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    default_language TEXT NOT NULL DEFAULT 'fr',
    tax_mode TEXT NOT NULL DEFAULT 'tax_included' CHECK(tax_mode IN ('tax_included','tax_excluded')),
    price_tax_included INTEGER NOT NULL DEFAULT 1 CHECK(price_tax_included IN (0,1)),
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, code),
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(default_language = lower(trim(default_language)) AND default_language GLOB '[a-z][a-z]*'),
    CHECK((tax_mode = 'tax_included' AND price_tax_included = 1)
       OR (tax_mode = 'tax_excluded' AND price_tax_included = 0)),
    CHECK(is_public = 0 OR channel_type = 'ecommerce'),
    CHECK(status <> 'archived' OR archived_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_channels_site_type_status
    ON sale_channels(site_id, channel_type, status);
CREATE INDEX IF NOT EXISTS idx_sale_channels_site_public
    ON sale_channels(site_id, is_public, status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_channels_default_kind ON sale_channels(site_id,channel_kind) WHERE is_default=1 AND status<>'archived';

CREATE TABLE IF NOT EXISTS sale_channel_checkout_configs (
    channel_id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, cart_enabled INTEGER NOT NULL DEFAULT 1 CHECK(cart_enabled IN (0,1)),
    checkout_enabled INTEGER NOT NULL DEFAULT 1 CHECK(checkout_enabled IN (0,1)), guest_checkout_enabled INTEGER NOT NULL DEFAULT 1 CHECK(guest_checkout_enabled IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')), updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE, CHECK(site_id>0)
);

CREATE TABLE IF NOT EXISTS sale_channel_catalog_scopes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL,
    scope_type TEXT NOT NULL CHECK(scope_type IN ('all','brand','category','product','variant')),
    scope_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK((scope_type = 'all' AND scope_id IS NULL)
       OR (scope_type <> 'all' AND scope_id IS NOT NULL AND scope_id > 0))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_channel_catalog_scopes_all
    ON sale_channel_catalog_scopes(channel_id)
    WHERE scope_type = 'all';
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_channel_catalog_scopes_specific
    ON sale_channel_catalog_scopes(channel_id, scope_type, scope_id)
    WHERE scope_type <> 'all';
CREATE INDEX IF NOT EXISTS idx_sale_channel_catalog_scopes_scope
    ON sale_channel_catalog_scopes(scope_type, scope_id);

CREATE TABLE IF NOT EXISTS sale_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    setting_key TEXT NOT NULL,
    setting_value_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(setting_value_json)),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, setting_key),
    CHECK(site_id > 0),
    CHECK(setting_key = lower(trim(setting_key)) AND setting_key GLOB '[a-z0-9_.-]*')
);

CREATE TABLE IF NOT EXISTS sale_fulfillment_zones (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL,
    country_codes_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(country_codes_json)),
    postal_prefixes_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(postal_prefixes_json)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','disabled')),
    active_from TEXT, active_until TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT,
    UNIQUE(site_id,code), CHECK(site_id>0), CHECK(trim(code)<>''), CHECK(trim(name)<>'')
);
CREATE TABLE IF NOT EXISTS sale_fulfillment_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, zone_id INTEGER, code TEXT NOT NULL,
    label_fr TEXT NOT NULL, label_en TEXT NOT NULL, fulfillment_type TEXT NOT NULL CHECK(fulfillment_type IN ('shipping','pickup','none')),
    flat_rate_minor INTEGER NOT NULL DEFAULT 0 CHECK(flat_rate_minor>=0), free_above_minor INTEGER CHECK(free_above_minor IS NULL OR free_above_minor>=0),
    requires_shipping_address INTEGER NOT NULL DEFAULT 1 CHECK(requires_shipping_address IN (0,1)),
    allow_non_physical INTEGER NOT NULL DEFAULT 0 CHECK(allow_non_physical IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('draft','active','disabled')), active_from TEXT, active_until TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT,
    FOREIGN KEY(zone_id) REFERENCES sale_fulfillment_zones(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE(site_id,code), CHECK(site_id>0), CHECK(trim(code)<>''), CHECK(trim(label_fr)<>''), CHECK(trim(label_en)<>'')
);
CREATE INDEX IF NOT EXISTS idx_sale_fulfillment_zones_active ON sale_fulfillment_zones(site_id,status,active_from,active_until);
CREATE INDEX IF NOT EXISTS idx_sale_fulfillment_methods_active ON sale_fulfillment_methods(site_id,status,sort_order,code);

CREATE TABLE IF NOT EXISTS sale_customer_refs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    company_id INTEGER,
    contact_id INTEGER,
    display_name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    billing_address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(billing_address_json)),
    shipping_address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_address_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    CHECK(site_id > 0),
    CHECK(company_id IS NULL OR company_id > 0),
    CHECK(contact_id IS NULL OR contact_id > 0),
    CHECK(company_id IS NOT NULL OR contact_id IS NOT NULL),
    CHECK(trim(display_name) <> '')
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_customer_refs_business_identity
    ON sale_customer_refs(site_id, company_id, contact_id);
CREATE INDEX IF NOT EXISTS idx_sale_customer_refs_company_contact
    ON sale_customer_refs(site_id, company_id, contact_id);
CREATE INDEX IF NOT EXISTS idx_sale_customer_refs_email
    ON sale_customer_refs(site_id, email);

CREATE TABLE IF NOT EXISTS sale_catalog_variant_refs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    business_product_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
    sellable_id INTEGER,
    sku TEXT,
    barcode TEXT,
    product_name TEXT NOT NULL,
    variant_name TEXT,
    product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','bundle','other')),
    track_stock INTEGER NOT NULL DEFAULT 1 CHECK(track_stock IN (0,1)),
    tax_class_id INTEGER,
    last_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(last_snapshot_json)),
    synced_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    UNIQUE(site_id, business_variant_id),
    UNIQUE(site_id, sellable_id),
    CHECK(site_id > 0),
    CHECK(business_product_id > 0),
    CHECK(business_variant_id > 0),
    CHECK(sku IS NULL OR trim(sku) <> ''),
    CHECK(barcode IS NULL OR trim(barcode) <> ''),
    CHECK(trim(product_name) <> ''),
    CHECK(tax_class_id IS NULL OR tax_class_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_catalog_variant_refs_product
    ON sale_catalog_variant_refs(site_id, business_product_id, archived_at);
CREATE INDEX IF NOT EXISTS idx_sale_catalog_variant_refs_sku
    ON sale_catalog_variant_refs(site_id, sku);
CREATE INDEX IF NOT EXISTS idx_sale_catalog_variant_refs_barcode
    ON sale_catalog_variant_refs(site_id, barcode);

CREATE TABLE IF NOT EXISTS sale_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    order_number TEXT NOT NULL,
    source TEXT NOT NULL CHECK(source IN ('ecommerce','pos','admin')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','pending_payment','placed','confirmed','completed','cancelled')),
    payment_status TEXT NOT NULL DEFAULT 'unpaid' CHECK(payment_status IN ('unpaid','pending','authorized','partially_paid','paid','partially_refunded','refunded','failed')),
    fulfillment_status TEXT NOT NULL DEFAULT 'not_required' CHECK(fulfillment_status IN ('not_required','unfulfilled','partially_fulfilled','fulfilled','returned')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    customer_company_id INTEGER,
    customer_contact_id INTEGER,
    customer_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(customer_snapshot_json)),
    billing_address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(billing_address_json)),
    shipping_address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_address_json)),
    shipping_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_method_snapshot_json)),
    terms_accepted INTEGER NOT NULL DEFAULT 0 CHECK(terms_accepted IN (0,1)),
    terms_accepted_at TEXT,
    marketing_consent INTEGER CHECK(marketing_consent IS NULL OR marketing_consent IN (0,1)),
    marketing_consent_at TEXT,
    payment_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payment_method_snapshot_json)),
    source_cart_id INTEGER,
    stock_location_id INTEGER,
    pos_register_id INTEGER,
    pos_device_id INTEGER,
    pos_session_id INTEGER,
    pos_operator_iam_user_id INTEGER,
    correlation_id TEXT,
    subtotal_minor INTEGER NOT NULL DEFAULT 0 CHECK(subtotal_minor >= 0),
    discount_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(discount_total_minor >= 0),
    tax_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(tax_total_minor >= 0),
    shipping_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(shipping_total_minor >= 0),
    grand_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(grand_total_minor >= 0),
    paid_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(paid_total_minor >= 0),
    refunded_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_total_minor >= 0),
    placed_at TEXT,
    completed_at TEXT,
    cancelled_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    UNIQUE(site_id, order_number),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(pos_register_id) REFERENCES sale_pos_registers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(pos_device_id) REFERENCES sale_pos_devices(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(pos_session_id) REFERENCES sale_cash_sessions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(trim(order_number) <> ''),
    CHECK(customer_company_id IS NULL OR customer_company_id > 0),
    CHECK(customer_contact_id IS NULL OR customer_contact_id > 0),
    CHECK(pos_operator_iam_user_id IS NULL OR pos_operator_iam_user_id > 0),
    CHECK(source = 'pos' OR (pos_register_id IS NULL AND pos_device_id IS NULL AND pos_session_id IS NULL AND pos_operator_iam_user_id IS NULL)),
    CHECK(refunded_total_minor <= paid_total_minor),
    CHECK(cancelled_at IS NULL OR status = 'cancelled'),
    CHECK(completed_at IS NULL OR status = 'completed')
);

CREATE INDEX IF NOT EXISTS idx_sale_orders_site_status_payment
    ON sale_orders(site_id, status, payment_status);
CREATE INDEX IF NOT EXISTS idx_sale_orders_customer
    ON sale_orders(customer_company_id, customer_contact_id);
CREATE INDEX IF NOT EXISTS idx_sale_orders_site_created
    ON sale_orders(site_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_sale_orders_channel
    ON sale_orders(channel_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_orders_pos_context
    ON sale_orders(pos_session_id, pos_register_id, pos_operator_iam_user_id)
    WHERE source = 'pos';
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_orders_source_cart
    ON sale_orders(source_cart_id)
    WHERE source_cart_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS sale_order_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    line_number INTEGER NOT NULL,
    business_product_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
    sellable_id INTEGER,
    sku TEXT,
    barcode TEXT,
    product_name TEXT NOT NULL,
    variant_name TEXT,
    product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','bundle','other')),
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    fulfilled_quantity INTEGER NOT NULL DEFAULT 0 CHECK(fulfilled_quantity >= 0),
    returned_quantity INTEGER NOT NULL DEFAULT 0 CHECK(returned_quantity >= 0),
    unit_price_minor INTEGER NOT NULL DEFAULT 0 CHECK(unit_price_minor >= 0),
    regular_unit_price_minor INTEGER NOT NULL DEFAULT 0 CHECK(regular_unit_price_minor >= 0),
    unit_purchase_price_minor INTEGER CHECK(unit_purchase_price_minor IS NULL OR unit_purchase_price_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    tax_class_id INTEGER,
    tax_class_code TEXT NOT NULL DEFAULT 'standard',
    tax_rate_basis_points INTEGER NOT NULL DEFAULT 0 CHECK(tax_rate_basis_points >= 0),
    tax_included INTEGER NOT NULL DEFAULT 1 CHECK(tax_included IN (0,1)),
    line_subtotal_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_subtotal_minor >= 0),
    line_discount_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_discount_minor >= 0),
    line_tax_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_tax_minor >= 0),
    line_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_total_minor >= 0),
    snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(snapshot_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(order_id, line_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(line_number > 0),
    CHECK(business_product_id > 0),
    CHECK(business_variant_id > 0),
    CHECK(sku IS NULL OR trim(sku) <> ''),
    CHECK(barcode IS NULL OR trim(barcode) <> ''),
    CHECK(trim(product_name) <> ''),
    CHECK(tax_class_id IS NULL OR tax_class_id > 0),
    CHECK(fulfilled_quantity <= quantity),
    CHECK(returned_quantity <= quantity)
);

CREATE INDEX IF NOT EXISTS idx_sale_order_lines_order
    ON sale_order_lines(order_id, line_number);
CREATE INDEX IF NOT EXISTS idx_sale_order_lines_variant
    ON sale_order_lines(business_variant_id);
CREATE INDEX IF NOT EXISTS idx_sale_order_lines_sellable ON sale_order_lines(sellable_id);

CREATE TABLE IF NOT EXISTS sale_order_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    order_line_id INTEGER,
    adjustment_type TEXT NOT NULL CHECK(adjustment_type IN ('discount','manual_discount','promotion','rounding','surcharge')),
    source_type TEXT NOT NULL CHECK(source_type IN ('catalog_discount','sale_promotion','manual','coupon')),
    source_id INTEGER,
    label TEXT NOT NULL,
    amount_minor INTEGER NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(order_line_id) REFERENCES sale_order_lines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(source_id IS NULL OR source_id > 0),
    CHECK(trim(label) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_order_adjustments_order
    ON sale_order_adjustments(order_id, order_line_id);

CREATE TABLE IF NOT EXISTS sale_order_tax_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    order_line_id INTEGER,
    tax_class_code TEXT NOT NULL,
    tax_rate_basis_points INTEGER NOT NULL DEFAULT 0 CHECK(tax_rate_basis_points >= 0),
    taxable_amount_minor INTEGER NOT NULL DEFAULT 0 CHECK(taxable_amount_minor >= 0),
    tax_amount_minor INTEGER NOT NULL DEFAULT 0 CHECK(tax_amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(order_line_id) REFERENCES sale_order_lines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(tax_class_code = lower(trim(tax_class_code)) AND tax_class_code GLOB '[a-z0-9_.-]*')
);

CREATE INDEX IF NOT EXISTS idx_sale_order_tax_lines_order
    ON sale_order_tax_lines(order_id, order_line_id);

CREATE TABLE IF NOT EXISTS sale_order_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    from_status TEXT CHECK(from_status IN ('draft','pending_payment','placed','confirmed','completed','cancelled')),
    to_status TEXT NOT NULL CHECK(to_status IN ('draft','pending_payment','placed','confirmed','completed','cancelled')),
    changed_by_iam_user_id INTEGER,
    reason TEXT,
    correlation_id TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(from_status IS NULL OR from_status <> to_status)
);

CREATE INDEX IF NOT EXISTS idx_sale_order_status_history_order
    ON sale_order_status_history(order_id, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_carts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    cart_kind TEXT NOT NULL DEFAULT 'admin' CHECK(cart_kind IN ('web','pos','admin')),
    locale TEXT NOT NULL DEFAULT 'fr',
    customer_ref_id INTEGER,
    public_token_hash TEXT,
    register_session_id INTEGER,
    cart_token_hash TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','abandoned','converted','expired','cancelled')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    customer_company_id INTEGER,
    customer_contact_id INTEGER,
    customer_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(customer_snapshot_json)),
    billing_address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(billing_address_json)),
    shipping_address_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_address_json)),
    shipping_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_method_snapshot_json)),
    checkout_step TEXT NOT NULL DEFAULT 'cart' CHECK(checkout_step IN ('cart','identity','addresses','delivery','review','validated')),
    terms_accepted INTEGER NOT NULL DEFAULT 0 CHECK(terms_accepted IN (0,1)),
    terms_accepted_at TEXT,
    marketing_consent INTEGER CHECK(marketing_consent IS NULL OR marketing_consent IN (0,1)),
    marketing_consent_at TEXT,
    payment_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payment_method_snapshot_json)),
    checkout_validated_at TEXT,
    abandoned_at TEXT,
    subtotal_minor INTEGER NOT NULL DEFAULT 0 CHECK(subtotal_minor >= 0),
    discount_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(discount_total_minor >= 0),
    tax_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(tax_total_minor >= 0),
    shipping_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(shipping_total_minor >= 0),
    grand_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(grand_total_minor >= 0),
    expires_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    converted_order_id INTEGER,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    calculation_version INTEGER NOT NULL DEFAULT 1 CHECK(calculation_version >= 1),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(converted_order_id) REFERENCES sale_orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(register_session_id) REFERENCES sale_cash_sessions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(cart_token_hash IS NULL OR length(cart_token_hash) >= 32),
    CHECK(public_token_hash IS NULL OR length(public_token_hash) >= 32),
    CHECK((cart_kind='web') OR public_token_hash IS NULL),
    CHECK(customer_company_id IS NULL OR customer_company_id > 0),
    CHECK(customer_contact_id IS NULL OR customer_contact_id > 0),
    CHECK((status = 'converted' AND converted_order_id IS NOT NULL)
       OR (status <> 'converted' AND converted_order_id IS NULL))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_carts_token
    ON sale_carts(cart_token_hash)
    WHERE cart_token_hash IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_carts_public_token ON sale_carts(public_token_hash) WHERE public_token_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sale_carts_kind_status ON sale_carts(site_id,cart_kind,status,expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_carts_register_session ON sale_carts(register_session_id,status);
CREATE INDEX IF NOT EXISTS idx_sale_carts_site_channel_status
    ON sale_carts(site_id, channel_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_carts_customer
    ON sale_carts(customer_company_id, customer_contact_id);
CREATE INDEX IF NOT EXISTS idx_sale_carts_expires
    ON sale_carts(status, expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_carts_checkout_step
    ON sale_carts(status, checkout_step, expires_at);
CREATE TRIGGER IF NOT EXISTS trg_sale_carts_context_immutable
BEFORE UPDATE OF site_id,channel_id,cart_kind,currency ON sale_carts
WHEN NEW.site_id<>OLD.site_id OR NEW.channel_id<>OLD.channel_id OR NEW.cart_kind<>OLD.cart_kind OR NEW.currency<>OLD.currency
BEGIN SELECT RAISE(ABORT,'sale.cart_context_immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_carts_public_token_policy_insert
BEFORE INSERT ON sale_carts WHEN NEW.cart_kind<>'web' AND NEW.public_token_hash IS NOT NULL
BEGIN SELECT RAISE(ABORT,'sale.cart_public_token_forbidden'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_carts_public_token_policy_update
BEFORE UPDATE OF public_token_hash,cart_kind ON sale_carts WHEN NEW.cart_kind<>'web' AND NEW.public_token_hash IS NOT NULL
BEGIN SELECT RAISE(ABORT,'sale.cart_public_token_forbidden'); END;

CREATE TABLE IF NOT EXISTS sale_cart_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cart_id INTEGER NOT NULL,
    line_key TEXT NOT NULL,
    business_product_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
    sellable_id INTEGER NOT NULL,
    sku TEXT,
    barcode TEXT,
    product_name TEXT NOT NULL,
    variant_name TEXT,
    product_type TEXT NOT NULL DEFAULT 'physical' CHECK(product_type IN ('physical','service','gift_card','digital','bundle','other')),
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    unit_price_minor INTEGER NOT NULL DEFAULT 0 CHECK(unit_price_minor >= 0),
    regular_unit_price_minor INTEGER NOT NULL DEFAULT 0 CHECK(regular_unit_price_minor >= 0),
    unit_purchase_price_minor INTEGER CHECK(unit_purchase_price_minor IS NULL OR unit_purchase_price_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    tax_class_id INTEGER,
    tax_class_code TEXT NOT NULL DEFAULT 'standard',
    tax_rate_basis_points INTEGER NOT NULL DEFAULT 0 CHECK(tax_rate_basis_points >= 0),
    tax_included INTEGER NOT NULL DEFAULT 1 CHECK(tax_included IN (0,1)),
    line_subtotal_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_subtotal_minor >= 0),
    line_discount_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_discount_minor >= 0),
    line_tax_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_tax_minor >= 0),
    line_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_total_minor >= 0),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    options_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(options_json)),
    personalization_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(personalization_json)),
    fulfillment_class TEXT NOT NULL DEFAULT 'shipping' CHECK(fulfillment_class IN ('shipping','pickup','digital','none')),
    availability_state TEXT NOT NULL DEFAULT 'available' CHECK(availability_state IN ('available','backorder','unavailable','contact_us')),
    calculation_version INTEGER NOT NULL DEFAULT 1 CHECK(calculation_version >= 1),
    previous_unit_price_minor INTEGER CHECK(previous_unit_price_minor IS NULL OR previous_unit_price_minor >= 0),
    price_changed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(cart_id, line_key),
    FOREIGN KEY(cart_id) REFERENCES sale_carts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(line_key = lower(trim(line_key)) AND line_key GLOB '[a-z0-9_.:-]*'),
    CHECK(business_product_id > 0),
    CHECK(business_variant_id > 0),
    CHECK(sku IS NULL OR trim(sku) <> ''),
    CHECK(barcode IS NULL OR trim(barcode) <> ''),
    CHECK(trim(product_name) <> ''),
    CHECK(tax_class_id IS NULL OR tax_class_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_cart_lines_cart
    ON sale_cart_lines(cart_id);
CREATE INDEX IF NOT EXISTS idx_sale_cart_lines_variant
    ON sale_cart_lines(business_variant_id);
CREATE INDEX IF NOT EXISTS idx_sale_cart_lines_sellable ON sale_cart_lines(sellable_id);

CREATE TABLE IF NOT EXISTS sale_cart_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cart_id INTEGER NOT NULL,
    cart_line_id INTEGER,
    adjustment_type TEXT NOT NULL CHECK(adjustment_type IN ('discount','manual_discount','promotion','rounding','surcharge')),
    source_type TEXT NOT NULL CHECK(source_type IN ('catalog_discount','sale_promotion','manual','coupon')),
    source_id INTEGER,
    label TEXT NOT NULL,
    amount_minor INTEGER NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(cart_id) REFERENCES sale_carts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(cart_line_id) REFERENCES sale_cart_lines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(source_id IS NULL OR source_id > 0),
    CHECK(trim(label) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_cart_adjustments_cart
    ON sale_cart_adjustments(cart_id, cart_line_id);

CREATE TABLE IF NOT EXISTS sale_payment_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    channel_id INTEGER,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    label_fr TEXT,
    label_en TEXT,
    description_fr TEXT,
    description_en TEXT,
    provider_key TEXT,
    contract_version TEXT NOT NULL DEFAULT 'sale.payment_provider.v1',
    method_type TEXT NOT NULL CHECK(method_type IN ('cash','manual_card','external_terminal','bank_transfer','online_provider','test')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','archived')),
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
    currency TEXT CHECK(currency IS NULL OR (length(currency) = 3 AND currency = upper(currency))),
    min_amount_minor INTEGER CHECK(min_amount_minor IS NULL OR min_amount_minor >= 0),
    max_amount_minor INTEGER CHECK(max_amount_minor IS NULL OR max_amount_minor >= 0),
    sort_order INTEGER NOT NULL DEFAULT 100 CHECK(sort_order >= 0),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, channel_id, code),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(label_fr IS NULL OR trim(label_fr) <> ''),
    CHECK(label_en IS NULL OR trim(label_en) <> ''),
    CHECK(provider_key IS NULL OR (provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_.-]*')),
    CHECK(contract_version = 'sale.payment_provider.v1'),
    CHECK(max_amount_minor IS NULL OR min_amount_minor IS NULL OR max_amount_minor >= min_amount_minor),
    CHECK(status <> 'archived' OR archived_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_methods_site_channel
    ON sale_payment_methods(site_id, channel_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_payment_methods_type
    ON sale_payment_methods(method_type, status);

CREATE TABLE IF NOT EXISTS sale_payment_intents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    order_id INTEGER NOT NULL,
    provider_key TEXT NOT NULL,
    intent_reference TEXT,
    contract_version TEXT NOT NULL DEFAULT 'sale.payment_provider.v1',
    status TEXT NOT NULL DEFAULT 'requires_payment' CHECK(status IN ('requires_payment','requires_action','authorized','partially_captured','captured','cancelled','failed','expired')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    idempotency_key TEXT,
    checkout_url TEXT,
    return_url TEXT,
    cancel_url TEXT,
    authorized_minor INTEGER NOT NULL DEFAULT 0 CHECK(authorized_minor >= 0),
    captured_minor INTEGER NOT NULL DEFAULT 0 CHECK(captured_minor >= 0),
    refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor >= 0),
    last_provider_status TEXT,
    last_provider_event_at TEXT,
    provider_synced_at TEXT,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    public_action_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(public_action_json)),
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    UNIQUE(provider_key, intent_reference),
    UNIQUE(site_id, idempotency_key),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(authorized_minor <= amount_minor),
    CHECK(captured_minor <= amount_minor),
    CHECK(refunded_minor <= captured_minor),
    CHECK(provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_.-]*'),
    CHECK(intent_reference IS NULL OR trim(intent_reference) <> ''),
    CHECK(idempotency_key IS NULL OR trim(idempotency_key) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_intents_order
    ON sale_payment_intents(order_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_payment_intents_site_status
    ON sale_payment_intents(site_id, status, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_payment_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_intent_id INTEGER NOT NULL,
    attempt_number INTEGER NOT NULL CHECK(attempt_number > 0),
    status TEXT NOT NULL DEFAULT 'created' CHECK(status IN ('created','redirected','pending','succeeded','failed','cancelled','timed_out')),
    provider_reference TEXT,
    error_code TEXT,
    error_message TEXT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    UNIQUE(payment_intent_id, attempt_number),
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_attempts_intent_status
    ON sale_payment_attempts(payment_intent_id, status, started_at DESC);

-- Etat distant émulé par le provider sandbox. Aucune donnée carte ni secret
-- client n'est stocké : uniquement des références opaques et montants.
CREATE TABLE IF NOT EXISTS sale_sandbox_payment_states (
    provider_reference TEXT PRIMARY KEY,
    payment_intent_id INTEGER NOT NULL UNIQUE,
    status TEXT NOT NULL CHECK(status IN ('requires_action','authorized','partially_captured','captured','failed','cancelled','expired')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    authorized_minor INTEGER NOT NULL DEFAULT 0 CHECK(authorized_minor >= 0),
    captured_minor INTEGER NOT NULL DEFAULT 0 CHECK(captured_minor >= 0),
    refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor >= 0),
    currency TEXT NOT NULL CHECK(length(currency) = 3 AND currency = upper(currency)),
    sandbox_token_hash TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(authorized_minor <= amount_minor),
    CHECK(captured_minor <= amount_minor),
    CHECK(refunded_minor <= captured_minor)
);

CREATE TABLE IF NOT EXISTS sale_test_payment_states (
    provider_reference TEXT PRIMARY KEY,
    payment_intent_id INTEGER NOT NULL UNIQUE,
    scenario TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('requires_action','authorized','captured','failed','cancelled','expired')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    authorized_minor INTEGER NOT NULL DEFAULT 0 CHECK(authorized_minor >= 0),
    captured_minor INTEGER NOT NULL DEFAULT 0 CHECK(captured_minor >= 0),
    refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor >= 0),
    currency TEXT NOT NULL CHECK(length(currency)=3 AND currency=upper(currency)),
    action_token_hash TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_test_payment_operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_key TEXT NOT NULL,
    provider_reference TEXT NOT NULL,
    operation_kind TEXT NOT NULL CHECK(operation_kind IN ('capture','refund','void')),
    operation_key TEXT NOT NULL,
    amount_minor INTEGER NOT NULL DEFAULT 0 CHECK(amount_minor >= 0),
    result_json TEXT NOT NULL CHECK(json_valid(result_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(provider_key,provider_reference,operation_kind,operation_key)
);

CREATE TABLE IF NOT EXISTS sale_payment_webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    provider_key TEXT NOT NULL,
    provider_event_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    provider_reference TEXT NOT NULL,
    provider_occurred_at TEXT NOT NULL,
    signature_valid INTEGER NOT NULL DEFAULT 1 CHECK(signature_valid IN (0,1)),
    processing_status TEXT NOT NULL DEFAULT 'received' CHECK(processing_status IN ('received','processed','duplicate','ignored_out_of_order','rejected','failed')),
    payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payload_json)),
    error_code TEXT,
    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    retain_until TEXT NOT NULL DEFAULT (datetime('now','+30 days')),
    processed_at TEXT,
    UNIQUE(provider_key, provider_event_id),
    CHECK(site_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_webhooks_reference
    ON sale_payment_webhook_events(provider_key, provider_reference, provider_occurred_at);
CREATE INDEX IF NOT EXISTS idx_sale_payment_webhooks_status
    ON sale_payment_webhook_events(site_id, processing_status, received_at DESC);

CREATE TABLE IF NOT EXISTS sale_payment_reconciliation_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    payment_intent_id INTEGER,
    trigger_kind TEXT NOT NULL CHECK(trigger_kind IN ('manual','scheduled','webhook_retry','return_read')),
    status TEXT NOT NULL CHECK(status IN ('consistent','repaired','attention_required','failed')),
    provider_status TEXT,
    local_status TEXT,
    findings_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(findings_json)),
    priority TEXT NOT NULL DEFAULT 'low' CHECK(priority IN ('low','medium','high','critical')),
    amount_minor INTEGER NOT NULL DEFAULT 0 CHECK(amount_minor >= 0),
    currency TEXT CHECK(currency IS NULL OR (length(currency)=3 AND currency=upper(currency))),
    recommended_action TEXT,
    requires_human_action INTEGER NOT NULL DEFAULT 0 CHECK(requires_human_action IN (0,1)),
    resolution_status TEXT NOT NULL DEFAULT 'open' CHECK(resolution_status IN ('open','resolved','ignored')),
    resolution_note TEXT,
    resolved_by_iam_user_id INTEGER,
    resolved_at TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(resolved_at IS NULL OR resolution_status IN ('resolved','ignored'))
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_reconciliation_site
    ON sale_payment_reconciliation_runs(site_id, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_payment_observability (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    metric_key TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'info' CHECK(severity IN ('info','warning','critical')),
    payment_intent_id INTEGER,
    dimensions_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(dimensions_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(site_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_observability_metric
    ON sale_payment_observability(site_id, metric_key, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_payment_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_intent_id INTEGER,
    order_id INTEGER NOT NULL,
    transaction_type TEXT NOT NULL CHECK(transaction_type IN ('authorization','capture','payment','refund','void')),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','succeeded','failed','cancelled','dead_letter')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    provider_transaction_id TEXT,
    provider_payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(provider_payload_json)),
    error_code TEXT,
    error_message TEXT,
    correlation_id TEXT,
    operation_key TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK(attempt_count >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts > 0),
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT,
    dead_lettered_at TEXT,
    created_by_iam_user_id INTEGER,
    processed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(provider_transaction_id IS NULL OR trim(provider_transaction_id) <> ''),
    CHECK(error_code IS NULL OR trim(error_code) <> ''),
    CHECK(operation_key IS NULL OR trim(operation_key) <> ''),
    CHECK(status <> 'dead_letter' OR dead_lettered_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_transactions_order
    ON sale_payment_transactions(order_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_payment_transactions_intent
    ON sale_payment_transactions(payment_intent_id, transaction_type, status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_payment_transactions_provider_unique
    ON sale_payment_transactions(provider_transaction_id)
    WHERE provider_transaction_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_payment_transactions_operation_unique
    ON sale_payment_transactions(payment_intent_id, transaction_type, operation_key)
    WHERE operation_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sale_payment_transactions_retry
    ON sale_payment_transactions(status, available_at, attempt_count)
    WHERE status='pending' AND operation_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS sale_payment_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    payment_transaction_id INTEGER NOT NULL,
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(order_id, payment_transaction_id),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(payment_transaction_id) REFERENCES sale_payment_transactions(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_allocations_order
    ON sale_payment_allocations(order_id);

CREATE TABLE IF NOT EXISTS sale_pos_registers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','archived')),
    location_name TEXT,
    stock_location_id INTEGER,
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    locale TEXT NOT NULL DEFAULT 'fr' CHECK(locale IN ('fr','en')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, code),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(status <> 'archived' OR archived_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_pos_registers_site_status
    ON sale_pos_registers(site_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_pos_registers_channel
    ON sale_pos_registers(channel_id, status);

CREATE TABLE IF NOT EXISTS sale_pos_register_payment_methods (
    register_id INTEGER NOT NULL,
    payment_method_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(register_id, payment_method_id),
    FOREIGN KEY(register_id) REFERENCES sale_pos_registers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(payment_method_id) REFERENCES sale_payment_methods(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_pos_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    register_id INTEGER NOT NULL,
    device_name TEXT NOT NULL,
    device_token_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','revoked','disabled')),
    last_seen_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    revoked_at TEXT,
    UNIQUE(device_token_hash),
    FOREIGN KEY(register_id) REFERENCES sale_pos_registers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(trim(device_name) <> ''),
    CHECK(length(device_token_hash) >= 32),
    CHECK(status <> 'revoked' OR revoked_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_pos_devices_register
    ON sale_pos_devices(register_id, status);

CREATE TABLE IF NOT EXISTS sale_cash_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    register_id INTEGER NOT NULL,
    channel_id INTEGER NOT NULL,
    stock_location_id INTEGER NOT NULL,
    device_id INTEGER,
    opened_by_iam_user_id INTEGER NOT NULL,
    closed_by_iam_user_id INTEGER,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closing','closed','cancelled')),
    opening_cash_minor INTEGER NOT NULL DEFAULT 0 CHECK(opening_cash_minor >= 0),
    expected_cash_minor INTEGER NOT NULL DEFAULT 0 CHECK(expected_cash_minor >= 0),
    counted_cash_minor INTEGER CHECK(counted_cash_minor IS NULL OR counted_cash_minor >= 0),
    difference_minor INTEGER NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    locale TEXT NOT NULL DEFAULT 'fr' CHECK(locale IN ('fr','en')),
    opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TEXT,
    notes TEXT,
    difference_justification TEXT,
    FOREIGN KEY(register_id) REFERENCES sale_pos_registers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(device_id) REFERENCES sale_pos_devices(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(opened_by_iam_user_id > 0),
    CHECK(closed_by_iam_user_id IS NULL OR closed_by_iam_user_id > 0),
    CHECK(status <> 'closed' OR difference_minor = 0 OR trim(COALESCE(difference_justification, '')) <> ''),
    CHECK((status IN ('closed','cancelled') AND closed_at IS NOT NULL)
       OR (status IN ('open','closing') AND closed_at IS NULL))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_cash_sessions_open_register
    ON sale_cash_sessions(register_id)
    WHERE status IN ('open','closing');
CREATE INDEX IF NOT EXISTS idx_sale_cash_sessions_register_status
    ON sale_cash_sessions(register_id, status, opened_at DESC);

CREATE TABLE IF NOT EXISTS sale_cash_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cash_session_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL CHECK(movement_type IN ('opening','cash_sale','cash_in','cash_out','correction','closing')),
    amount_minor INTEGER NOT NULL,
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    reason TEXT,
    order_id INTEGER,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(cash_session_id) REFERENCES sale_cash_sessions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK((movement_type IN ('cash_out','correction') AND amount_minor <> 0)
       OR (movement_type NOT IN ('cash_out','correction') AND amount_minor >= 0)),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_cash_movements_session
    ON sale_cash_movements(cash_session_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_cash_movements_order
    ON sale_cash_movements(order_id);
CREATE TRIGGER IF NOT EXISTS trg_sale_cash_movements_immutable_update
BEFORE UPDATE ON sale_cash_movements BEGIN SELECT RAISE(ABORT,'sale.cash_movement_immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_cash_movements_immutable_delete
BEFORE DELETE ON sale_cash_movements BEGIN SELECT RAISE(ABORT,'sale.cash_movement_immutable'); END;

CREATE TABLE IF NOT EXISTS sale_stock_locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    location_type TEXT NOT NULL DEFAULT 'main' CHECK(location_type IN ('main','pos','event','external')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','archived')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, code),
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(status <> 'archived' OR archived_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_stock_locations_site_status
    ON sale_stock_locations(site_id, status, location_type);

CREATE TABLE IF NOT EXISTS sale_inventory_channel_configs (
    channel_id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, stock_location_id INTEGER NOT NULL,
    availability_policy TEXT NOT NULL DEFAULT 'available' CHECK(availability_policy IN ('available','on_hand','allow_backorder')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')), updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE,
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT, CHECK(site_id>0)
);

CREATE TABLE IF NOT EXISTS sale_inventory_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
    sellable_id INTEGER NOT NULL,
    stock_location_id INTEGER NOT NULL,
    sku TEXT,
    tracked INTEGER NOT NULL DEFAULT 1 CHECK(tracked IN (0,1)),
    allow_negative INTEGER NOT NULL DEFAULT 0 CHECK(allow_negative IN (0,1)),
    on_hand_quantity INTEGER NOT NULL DEFAULT 0,
    reserved_quantity INTEGER NOT NULL DEFAULT 0 CHECK(reserved_quantity >= 0),
    available_quantity INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, sellable_id, stock_location_id),
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(business_variant_id > 0),
    CHECK(sellable_id > 0),
    CHECK(sku IS NULL OR trim(sku) <> ''),
    CHECK(allow_negative = 1 OR on_hand_quantity >= 0),
    CHECK(allow_negative = 1 OR available_quantity >= 0),
    CHECK(available_quantity = on_hand_quantity - reserved_quantity)
);

CREATE INDEX IF NOT EXISTS idx_sale_inventory_items_variant
    ON sale_inventory_items(site_id, business_variant_id);
CREATE INDEX IF NOT EXISTS idx_sale_inventory_items_sellable
    ON sale_inventory_items(site_id, sellable_id);
CREATE INDEX IF NOT EXISTS idx_sale_inventory_items_location
    ON sale_inventory_items(stock_location_id, tracked);
CREATE INDEX IF NOT EXISTS idx_sale_inventory_items_sku
    ON sale_inventory_items(site_id, sku);

CREATE TABLE IF NOT EXISTS sale_stock_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inventory_item_id INTEGER NOT NULL,
    cart_id INTEGER,
    order_id INTEGER,
    reservation_key TEXT NOT NULL,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','confirmed','released','consumed','expired')),
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TEXT,
    released_at TEXT,
    consumed_at TEXT,
    UNIQUE(inventory_item_id, reservation_key),
    FOREIGN KEY(inventory_item_id) REFERENCES sale_inventory_items(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(cart_id) REFERENCES sale_carts(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(cart_id IS NOT NULL OR order_id IS NOT NULL),
    CHECK(reservation_key = lower(trim(reservation_key)) AND reservation_key GLOB '[a-z0-9_.:-]*'),
    CHECK(status <> 'released' OR released_at IS NOT NULL),
    CHECK(status <> 'confirmed' OR confirmed_at IS NOT NULL),
    CHECK(status <> 'consumed' OR consumed_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_stock_reservations_item_status
    ON sale_stock_reservations(inventory_item_id, status, expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_stock_reservations_cart
    ON sale_stock_reservations(cart_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_stock_reservations_order
    ON sale_stock_reservations(order_id, status);

CREATE TABLE IF NOT EXISTS sale_stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inventory_item_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL CHECK(movement_type IN ('initial','receipt','issue','adjustment','correction','return','transfer_in','transfer_out','reservation','release','consumption')),
    quantity INTEGER NOT NULL,
    idempotency_key TEXT,
    transfer_key TEXT,
    reference_type TEXT,
    reference_id INTEGER,
    reason TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(inventory_item_id) REFERENCES sale_inventory_items(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(idempotency_key),
    CHECK(quantity <> 0),
    CHECK(reference_type IS NULL OR (reference_type = lower(trim(reference_type)) AND reference_type GLOB '[a-z0-9_.:-]*')),
    CHECK(reference_id IS NULL OR reference_id > 0),
    CHECK(idempotency_key IS NULL OR (idempotency_key=lower(trim(idempotency_key)) AND idempotency_key GLOB '[a-z0-9_.:-]*')),
    CHECK(transfer_key IS NULL OR trim(transfer_key)<>''),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_stock_movements_item
    ON sale_stock_movements(inventory_item_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_stock_movements_reference
    ON sale_stock_movements(reference_type, reference_id);
CREATE TRIGGER IF NOT EXISTS trg_sale_stock_movements_immutable_update
BEFORE UPDATE ON sale_stock_movements BEGIN SELECT RAISE(ABORT,'sale.stock_movement_immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_stock_movements_immutable_delete
BEFORE DELETE ON sale_stock_movements BEGIN SELECT RAISE(ABORT,'sale.stock_movement_immutable'); END;

CREATE TABLE IF NOT EXISTS sale_inventory_reconciliation_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('running','clean','differences','failed')),
    items_checked INTEGER NOT NULL DEFAULT 0, differences_count INTEGER NOT NULL DEFAULT 0,
    repaired_count INTEGER NOT NULL DEFAULT 0, report_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(report_json)),
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT,
    created_by_iam_user_id INTEGER, CHECK(site_id>0)
);
CREATE INDEX IF NOT EXISTS idx_sale_inventory_reconciliation_runs_site ON sale_inventory_reconciliation_runs(site_id,started_at DESC);

CREATE TABLE IF NOT EXISTS sale_receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    receipt_number TEXT NOT NULL,
    receipt_type TEXT NOT NULL CHECK(receipt_type IN ('pos_receipt','order_confirmation','credit_receipt')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','issued','cancelled')),
    html_snapshot TEXT NOT NULL DEFAULT '',
    text_snapshot TEXT NOT NULL DEFAULT '',
    pdf_media_id INTEGER,
    language TEXT NOT NULL DEFAULT 'fr' CHECK(language IN ('fr','en')),
    operator_iam_user_id INTEGER,
    snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(snapshot_json)),
    issued_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(order_id, receipt_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(trim(receipt_number) <> ''),
    CHECK(pdf_media_id IS NULL OR pdf_media_id > 0),
    CHECK(status <> 'issued' OR issued_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_receipts_order
    ON sale_receipts(order_id, status);

CREATE TABLE IF NOT EXISTS sale_receipt_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    receipt_id INTEGER NOT NULL,
    action_type TEXT NOT NULL CHECK(action_type IN ('print','reprint','email')),
    pos_session_id INTEGER,
    operator_iam_user_id INTEGER NOT NULL,
    reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(receipt_id) REFERENCES sale_receipts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(pos_session_id) REFERENCES sale_cash_sessions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(operator_iam_user_id > 0),
    CHECK(action_type <> 'reprint' OR trim(COALESCE(reason, '')) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_receipt_actions_receipt
    ON sale_receipt_actions(receipt_id, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    return_number TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'requested' CHECK(status IN ('requested','approved','received','rejected','completed','cancelled')),
    reason TEXT,
    idempotency_key TEXT,
    request_hash TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT,
    updated_at TEXT,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    UNIQUE(order_id, return_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(trim(return_number) <> ''),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0),
    CHECK(completed_at IS NULL OR status = 'completed')
);

CREATE INDEX IF NOT EXISTS idx_sale_returns_order
    ON sale_returns(order_id, status, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_returns_idempotency
    ON sale_returns(order_id, idempotency_key)
    WHERE idempotency_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS sale_return_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    return_id INTEGER NOT NULL,
    order_line_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    reason TEXT,
    restock INTEGER NOT NULL DEFAULT 1 CHECK(restock IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(return_id, order_line_id),
    FOREIGN KEY(return_id) REFERENCES sale_returns(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(order_line_id) REFERENCES sale_order_lines(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sale_return_lines_return
    ON sale_return_lines(return_id);
CREATE INDEX IF NOT EXISTS idx_sale_return_lines_order_line
    ON sale_return_lines(order_line_id);

CREATE TABLE IF NOT EXISTS sale_refunds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    payment_transaction_id INTEGER,
    refund_number TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','pending','succeeded','failed','cancelled')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    reason TEXT,
    reason_code TEXT,
    reason_note TEXT,
    return_id INTEGER,
    idempotency_key TEXT,
    provider_reference TEXT,
    provider_status TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK(attempt_count >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts > 0),
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT,
    dead_lettered_at TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    updated_at TEXT,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    UNIQUE(order_id, refund_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(payment_transaction_id) REFERENCES sale_payment_transactions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(return_id) REFERENCES sale_returns(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(trim(refund_number) <> ''),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0),
    CHECK(processed_at IS NULL OR status IN ('succeeded','failed','cancelled')),
    CHECK(reason_code IS NULL OR (reason_code=lower(trim(reason_code)) AND reason_code GLOB '[a-z0-9_-]*')),
    CHECK(idempotency_key IS NULL OR trim(idempotency_key) <> ''),
    CHECK(dead_lettered_at IS NULL OR status IN ('pending','failed'))
);

CREATE INDEX IF NOT EXISTS idx_sale_refunds_order
    ON sale_refunds(order_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_refunds_payment_transaction
    ON sale_refunds(payment_transaction_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_refunds_idempotency
    ON sale_refunds(order_id,idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sale_refunds_retry
    ON sale_refunds(status,available_at,attempt_count) WHERE status='pending';

CREATE TABLE IF NOT EXISTS sale_financial_corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    payment_transaction_id INTEGER,
    amount_delta_minor INTEGER NOT NULL CHECK(amount_delta_minor <> 0),
    currency TEXT NOT NULL CHECK(length(currency)=3 AND currency=upper(currency)),
    reason TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    correlation_id TEXT NOT NULL,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(order_id, idempotency_key),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(payment_transaction_id) REFERENCES sale_payment_transactions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(trim(reason) <> ''),
    CHECK(trim(idempotency_key) <> ''),
    CHECK(trim(correlation_id) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_financial_corrections_order
    ON sale_financial_corrections(order_id, created_at, id);

CREATE TABLE IF NOT EXISTS sale_order_customer_reconciliations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    previous_company_id INTEGER,
    previous_contact_id INTEGER,
    company_id INTEGER,
    contact_id INTEGER,
    reason TEXT,
    correlation_id TEXT NOT NULL,
    linked_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(company_id IS NOT NULL OR contact_id IS NOT NULL),
    CHECK(trim(correlation_id) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_order_customer_reconciliations_order
    ON sale_order_customer_reconciliations(order_id, created_at, id);

CREATE TABLE IF NOT EXISTS sale_order_claim_proofs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, order_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE, email_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','consumed','revoked','expired')),
    expires_at TEXT NOT NULL, consumed_by_iam_user_id INTEGER, consumed_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id>0), CHECK(length(token_hash)>=32), CHECK(length(email_hash)>=32)
);
CREATE TABLE IF NOT EXISTS sale_customer_account_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, iam_user_id INTEGER NOT NULL,
    crm_company_id INTEGER, crm_contact_id INTEGER, status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','merged')),
    linked_by TEXT NOT NULL CHECK(linked_by IN ('post_purchase_proof','verified_email','admin','merge')),
    merged_into_iam_user_id INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT,
    UNIQUE(site_id,iam_user_id), CHECK(site_id>0), CHECK(iam_user_id>0)
);
CREATE TABLE IF NOT EXISTS sale_customer_order_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, order_id INTEGER NOT NULL, iam_user_id INTEGER NOT NULL,
    claim_proof_id INTEGER, link_source TEXT NOT NULL CHECK(link_source IN ('post_purchase_proof','verified_email','admin','merge')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','revoked')), linked_by_iam_user_id INTEGER,
    linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(claim_proof_id) REFERENCES sale_order_claim_proofs(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE(order_id), CHECK(site_id>0), CHECK(iam_user_id>0)
);
CREATE TABLE IF NOT EXISTS sale_customer_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, iam_user_id INTEGER NOT NULL, label TEXT NOT NULL,
    address_type TEXT NOT NULL DEFAULT 'both' CHECK(address_type IN ('billing','shipping','both')),
    address_json TEXT NOT NULL CHECK(json_valid(address_json)), is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, archived_at TEXT,
    CHECK(site_id>0), CHECK(iam_user_id>0), CHECK(trim(label)<>'')
);
CREATE TABLE IF NOT EXISTS sale_customer_merge_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, source_iam_user_id INTEGER NOT NULL, target_iam_user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'applied' CHECK(status IN ('applied','reversed')), reason TEXT NOT NULL,
    before_json TEXT NOT NULL CHECK(json_valid(before_json)), after_json TEXT NOT NULL CHECK(json_valid(after_json)),
    actor_iam_user_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, reversed_at TEXT,
    CHECK(site_id>0), CHECK(source_iam_user_id<>target_iam_user_id), CHECK(trim(reason)<>'')
);
CREATE INDEX IF NOT EXISTS idx_sale_claim_proofs_order ON sale_order_claim_proofs(order_id,status,expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_customer_order_links_account ON sale_customer_order_links(site_id,iam_user_id,status,linked_at);
CREATE INDEX IF NOT EXISTS idx_sale_customer_addresses_account ON sale_customer_addresses(site_id,iam_user_id,is_default,archived_at);

CREATE TRIGGER IF NOT EXISTS trg_sale_payment_transactions_no_delete
BEFORE DELETE ON sale_payment_transactions BEGIN SELECT RAISE(ABORT, 'sale financial transactions are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_payment_webhooks_no_update
BEFORE UPDATE ON sale_payment_webhook_events
WHEN OLD.processing_status IN ('processed','ignored_out_of_order','rejected')
 AND NOT (NEW.payload_json='{}' AND OLD.retain_until<=CURRENT_TIMESTAMP
          AND NEW.processing_status=OLD.processing_status AND NEW.provider_event_id=OLD.provider_event_id)
BEGIN SELECT RAISE(ABORT, 'sale processed payment webhooks are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_payment_webhooks_no_delete
BEFORE DELETE ON sale_payment_webhook_events BEGIN SELECT RAISE(ABORT, 'sale payment webhooks are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_payment_allocations_no_delete
BEFORE DELETE ON sale_payment_allocations BEGIN SELECT RAISE(ABORT, 'sale payment allocations are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_refunds_no_delete
BEFORE DELETE ON sale_refunds BEGIN SELECT RAISE(ABORT, 'sale refunds are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_financial_corrections_no_delete
BEFORE DELETE ON sale_financial_corrections BEGIN SELECT RAISE(ABORT, 'sale financial corrections are immutable'); END;

CREATE TABLE IF NOT EXISTS sale_fulfillments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    fulfillment_number TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','preparing','partially_shipped','shipped','delivered','cancelled','returned')),
    shipping_address_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_address_snapshot_json)),
    shipping_method_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(shipping_method_snapshot_json)),
    tracking_reference TEXT,
    correlation_id TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    shipped_at TEXT,
    delivered_at TEXT,
    cancelled_at TEXT,
    UNIQUE(order_id, fulfillment_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(trim(fulfillment_number) <> ''),
    CHECK(trim(correlation_id) <> ''),
    CHECK(shipped_at IS NULL OR status IN ('shipped','delivered','returned')),
    CHECK(delivered_at IS NULL OR status IN ('delivered','returned')),
    CHECK(cancelled_at IS NULL OR status = 'cancelled')
);

CREATE INDEX IF NOT EXISTS idx_sale_fulfillments_order
    ON sale_fulfillments(order_id, status, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_fulfillment_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL,
    order_line_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(fulfillment_id, order_line_id),
    FOREIGN KEY(fulfillment_id) REFERENCES sale_fulfillments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(order_line_id) REFERENCES sale_order_lines(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_state_transitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    aggregate_type TEXT NOT NULL CHECK(aggregate_type IN ('cart','order','payment_intent','fulfillment','return','refund')),
    aggregate_id INTEGER NOT NULL,
    from_status TEXT,
    to_status TEXT NOT NULL,
    correlation_id TEXT NOT NULL,
    changed_by_iam_user_id INTEGER,
    reason TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(aggregate_id > 0),
    CHECK(from_status IS NULL OR from_status <> to_status),
    CHECK(trim(to_status) <> ''),
    CHECK(trim(correlation_id) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_state_transitions_aggregate
    ON sale_state_transitions(aggregate_type, aggregate_id, id);
CREATE INDEX IF NOT EXISTS idx_sale_state_transitions_correlation
    ON sale_state_transitions(correlation_id, id);

CREATE TRIGGER IF NOT EXISTS trg_sale_order_snapshots_immutable
BEFORE UPDATE OF customer_snapshot_json, billing_address_json, shipping_address_json, shipping_method_snapshot_json, currency ON sale_orders
WHEN OLD.status <> 'draft'
BEGIN
    SELECT RAISE(ABORT, 'sale order snapshots are immutable after placement');
END;

CREATE TRIGGER IF NOT EXISTS trg_sale_orders_guest_checkout_immutable
BEFORE UPDATE OF terms_accepted,terms_accepted_at,marketing_consent,marketing_consent_at,payment_method_snapshot_json ON sale_orders
WHEN OLD.status IN ('placed','confirmed','completed','cancelled')
BEGIN SELECT RAISE(ABORT, 'sale guest checkout snapshot is immutable'); END;

CREATE TRIGGER IF NOT EXISTS trg_sale_order_line_snapshots_immutable
BEFORE UPDATE OF business_product_id, business_variant_id, sku, barcode, product_name, variant_name, product_type,
    unit_price_minor, regular_unit_price_minor, unit_purchase_price_minor, currency, tax_class_id,
    tax_rate_basis_points, tax_included, line_subtotal_minor, line_discount_minor, line_tax_minor, line_total_minor, snapshot_json
ON sale_order_lines
BEGIN
    SELECT RAISE(ABORT, 'sale order line snapshots are immutable');
END;

CREATE TRIGGER IF NOT EXISTS trg_sale_order_lines_delete_immutable
BEFORE DELETE ON sale_order_lines
WHEN EXISTS (SELECT 1 FROM sale_orders o WHERE o.id = OLD.order_id AND o.status <> 'draft')
BEGIN
    SELECT RAISE(ABORT, 'sale order lines cannot be deleted after placement');
END;

CREATE TABLE IF NOT EXISTS sale_promotions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    channel_id INTEGER,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    promotion_type TEXT NOT NULL CHECK(promotion_type IN ('cart_percent','cart_amount','coupon_percent','coupon_amount')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    starts_at TEXT,
    ends_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, code),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(status <> 'archived' OR archived_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_promotions_site_status
    ON sale_promotions(site_id, status, starts_at, ends_at);
CREATE INDEX IF NOT EXISTS idx_sale_promotions_channel
    ON sale_promotions(channel_id, status);

CREATE TABLE IF NOT EXISTS sale_coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    promotion_id INTEGER NOT NULL,
    coupon_code_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','expired')),
    usage_limit INTEGER,
    used_count INTEGER NOT NULL DEFAULT 0 CHECK(used_count >= 0),
    starts_at TEXT,
    ends_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(coupon_code_hash),
    FOREIGN KEY(promotion_id) REFERENCES sale_promotions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(length(coupon_code_hash) >= 32),
    CHECK(usage_limit IS NULL OR usage_limit > 0),
    CHECK(usage_limit IS NULL OR used_count <= usage_limit)
);

CREATE INDEX IF NOT EXISTS idx_sale_coupons_promotion
    ON sale_coupons(promotion_id, status);

CREATE TABLE IF NOT EXISTS sale_idempotency_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    key_hash TEXT NOT NULL,
    scope TEXT NOT NULL CHECK(scope IN ('cart.add_line','checkout.place_order','payment.capture','payment.confirm','pos.complete_sale','refund.create')),
    request_hash TEXT NOT NULL,
    response_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(response_json)),
    status TEXT NOT NULL DEFAULT 'processing' CHECK(status IN ('processing','completed','failed','expired')),
    locked_until TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(site_id, scope, key_hash),
    CHECK(site_id > 0),
    CHECK(length(key_hash) >= 32),
    CHECK(length(request_hash) >= 32)
);

CREATE INDEX IF NOT EXISTS idx_sale_idempotency_keys_status
    ON sale_idempotency_keys(status, locked_until);

CREATE TABLE IF NOT EXISTS sale_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    event_type TEXT NOT NULL CHECK(event_type IN (
        'sale.cart.created',
        'sale.cart.line_added',
        'sale.order.placed',
        'sale.order.confirmed',
        'sale.order.cancelled',
        'sale.payment.recorded',
        'sale.payment.confirmed',
        'sale.payment.capture.requested',
        'sale.payment.capture.completed',
        'sale.payment.capture.retry_scheduled',
        'sale.payment.capture.dead_lettered',
        'sale.payment.failed',
        'sale.fulfillment.completed',
        'sale.return.created',
        'sale.pos.session.opened',
        'sale.pos.session.closed',
        'sale.pos.order.completed',
        'sale.refund.created',
        'sale.refund.completed',
        'sale.refund.requested',
        'sale.refund.retry_scheduled',
        'sale.refund.dead_lettered',
        'sale.gift_card.issued',
        'sale.gift_card.redeemed',
        'sale.invoice.sent',
        'sale.stock.reserved',
        'sale.stock.consumed',
        'sale.stock.released'
    )),
    aggregate_type TEXT NOT NULL,
    aggregate_id INTEGER NOT NULL,
    payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payload_json)),
    correlation_id TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(aggregate_type = lower(trim(aggregate_type)) AND aggregate_type GLOB '[a-z0-9_.:-]*'),
    CHECK(aggregate_id > 0),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_events_site_created
    ON sale_events(site_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_sale_events_aggregate
    ON sale_events(aggregate_type, aggregate_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_events_type
    ON sale_events(event_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_events_correlation
    ON sale_events(correlation_id, id);

CREATE TABLE IF NOT EXISTS sale_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    topic TEXT NOT NULL,
    payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(payload_json)),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','processed','failed','cancelled')),
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK(attempt_count >= 0),
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    FOREIGN KEY(event_id) REFERENCES sale_events(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(topic = lower(trim(topic)) AND topic GLOB '[a-z0-9_.:-]*'),
    CHECK(processed_at IS NULL OR status = 'processed')
);

CREATE INDEX IF NOT EXISTS idx_sale_outbox_status_available
    ON sale_outbox(status, available_at, id);
CREATE INDEX IF NOT EXISTS idx_sale_outbox_event
    ON sale_outbox(event_id);

INSERT OR IGNORE INTO sale_channels (
    site_id,
    code,
    name,
    channel_type,
    channel_kind,
    is_default,
    status,
    currency,
    default_language,
    tax_mode,
    price_tax_included,
    is_public
) VALUES
    (1, 'admin-manual', 'Saisie admin manuelle', 'admin', 'admin', 1, 'active', 'CHF', 'fr', 'tax_included', 1, 0),
    (1, 'pos-main', 'Caisse principale', 'pos', 'pos', 1, 'draft', 'CHF', 'fr', 'tax_included', 1, 0),
    (1, 'web-main', 'Boutique web principale', 'ecommerce', 'storefront', 1, 'active', 'CHF', 'fr', 'tax_included', 1, 1);

INSERT OR IGNORE INTO sale_channel_checkout_configs(channel_id,site_id,cart_enabled,checkout_enabled,guest_checkout_enabled,status)
SELECT id,site_id,1,1,CASE WHEN channel_kind='storefront' THEN 1 ELSE 0 END,CASE WHEN status='active' THEN 'active' ELSE 'disabled' END FROM sale_channels;
INSERT OR IGNORE INTO sale_stock_locations(site_id,code,name,location_type,status)
SELECT DISTINCT site_id,'channel-default','Stock canal par défaut','main','active' FROM sale_channels;
INSERT OR IGNORE INTO sale_inventory_channel_configs(channel_id,site_id,stock_location_id,availability_policy,status)
SELECT c.id,c.site_id,l.id,'available',CASE WHEN c.status='active' THEN 'active' ELSE 'disabled' END FROM sale_channels c JOIN sale_stock_locations l ON l.site_id=c.site_id AND l.code='channel-default';

INSERT OR IGNORE INTO sale_payment_methods(site_id, channel_id, code, name, provider_key, method_type, status)
SELECT c.site_id, c.id, m.code, m.name, m.provider_key, m.method_type, 'active'
FROM sale_channels c
CROSS JOIN (
    SELECT 'cash' AS code, 'Espèces' AS name, 'cash' AS provider_key, 'cash' AS method_type
    UNION ALL SELECT 'bank-transfer', 'Virement', 'bank_transfer', 'bank_transfer'
    UNION ALL SELECT 'manual-payment', 'Paiement manuel', 'manual_card', 'manual_card'
    UNION ALL SELECT 'external-terminal', 'Terminal externe', 'external_terminal', 'external_terminal'
) m
WHERE c.channel_type IN ('admin','pos');

INSERT OR IGNORE INTO sale_payment_methods(
    site_id,channel_id,code,name,label_fr,label_en,description_fr,description_en,
    provider_key,method_type,status,is_public,currency,min_amount_minor,sort_order,config_json
)
SELECT c.site_id,c.id,m.code,m.name,m.label_fr,m.label_en,m.description_fr,m.description_en,
       m.provider_key,m.method_type,'active',1,c.currency,1,m.sort_order,m.config_json
FROM sale_channels c
CROSS JOIN (
    SELECT 'bank_transfer' AS code,'Virement bancaire' AS name,'Virement bancaire' AS label_fr,'Bank transfer' AS label_en,
           'Les instructions sont affichées après la commande.' AS description_fr,'Instructions are shown after the order.' AS description_en,
           'bank_transfer' AS provider_key,'bank_transfer' AS method_type,10 AS sort_order,
           '{"public_mode":"offline","next_action":"display_instructions","recoverable":true,"create_session":true,"defer_order_until_payment":true,"beneficiary":"Marchand de démonstration","iban":"CH00 0000 0000 0000 0000 0","expected_delay":"1–2 jours ouvrés","allow_partial":true,"ttl_seconds":172800}' AS config_json
    UNION ALL SELECT 'manual','Paiement à confirmer','Paiement à confirmer','Payment to confirm',
           'La commande est enregistrée puis confirmée par le marchand.','The order is recorded and then confirmed by the merchant.',
           'manual_card','manual_card',20,'{"public_mode":"manual","next_action":"await_confirmation","recoverable":true,"create_session":true,"defer_order_until_payment":true,"allow_partial":true,"ttl_seconds":604800}'
    UNION ALL SELECT 'sandbox_online','Paiement sandbox','Paiement en ligne (sandbox)','Online payment (sandbox)',
           'Environnement de démonstration sans saisie de carte.','Demo environment without card entry.',
           'sandbox','online_provider',90,'{"public_mode":"redirect","next_action":"redirect","recoverable":true,"test_mode":true}'
    UNION ALL SELECT 'stripe_checkout','Stripe','Carte ou TWINT','Card or TWINT',
           'Stripe affiche les moyens disponibles, dont TWINT pour les paiements CHF activés.','Stripe displays available methods, including TWINT for enabled CHF payments.',
           'stripe_checkout','online_provider',40,'{"public_mode":"redirect","next_action":"redirect","recoverable":true,"create_session":true,"defer_order_until_payment":true,"display_name":"Carte ou TWINT","logo":"stripe"}'
    UNION ALL SELECT 'revolut_checkout','Revolut Checkout','Revolut Checkout','Revolut Checkout',
           'Paiement sécurisé sur la page hébergée Revolut.','Secure payment on the Revolut-hosted checkout page.',
           'revolut_checkout','online_provider',50,'{"public_mode":"redirect","next_action":"redirect","recoverable":true,"create_session":true,"defer_order_until_payment":true,"display_name":"Revolut Checkout","logo":"revolut"}'
) m
WHERE c.channel_kind='storefront' AND c.status='active';

INSERT OR IGNORE INTO sale_payment_methods(site_id,channel_id,code,name,label_fr,label_en,description_fr,description_en,provider_key,method_type,status,is_public,currency,min_amount_minor,sort_order,config_json)
SELECT c.site_id,c.id,'test_deterministic','Paiement déterministe','Paiement déterministe — MODE TEST','Deterministic payment — TEST MODE',
       'Scénarios reproductibles réservés au développement.','Reproducible scenarios for development only.','test','test','active',1,c.currency,1,999,
       '{"public_mode":"developer","next_action":"run_test_scenario","recoverable":true,"create_session":true,"defer_order_until_payment":true,"test_mode":true,"scenarios":["success_immediate","authorize_then_capture","refused","temporary_error","timeout","cancelled","duplicate_webhook","out_of_order_webhook","reconciliation_divergence"]}'
FROM sale_channels c WHERE c.channel_kind='storefront' AND c.status='active';

INSERT OR IGNORE INTO sale_fulfillment_zones(site_id,code,name,country_codes_json,status)
SELECT DISTINCT site_id,'ch','Suisse','["CH"]','active' FROM sale_channels;
INSERT OR IGNORE INTO sale_fulfillment_methods(site_id,zone_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,free_above_minor,requires_shipping_address,allow_non_physical,status,sort_order)
SELECT c.site_id,z.id,'standard','Livraison standard','Standard delivery','shipping',900,10000,1,0,'active',10
FROM (SELECT DISTINCT site_id FROM sale_channels) c JOIN sale_fulfillment_zones z ON z.site_id=c.site_id AND z.code='ch';
INSERT OR IGNORE INTO sale_fulfillment_methods(site_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,requires_shipping_address,allow_non_physical,status,sort_order)
SELECT DISTINCT site_id,'pickup','Retrait local','Local pickup','pickup',0,0,0,'active',20 FROM sale_channels;
INSERT OR IGNORE INTO sale_fulfillment_methods(site_id,code,label_fr,label_en,fulfillment_type,flat_rate_minor,requires_shipping_address,allow_non_physical,status,sort_order)
SELECT DISTINCT site_id,'none','Aucun fulfillment','No fulfillment','none',0,0,1,'active',30 FROM sale_channels;
