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
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','archived')),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    default_language TEXT NOT NULL DEFAULT 'fr',
    tax_mode TEXT NOT NULL DEFAULT 'tax_included' CHECK(tax_mode IN ('tax_included','tax_excluded')),
    price_tax_included INTEGER NOT NULL DEFAULT 1 CHECK(price_tax_included IN (0,1)),
    is_public INTEGER NOT NULL DEFAULT 0 CHECK(is_public IN (0,1)),
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
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','placed','confirmed','completed','cancelled')),
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
    CHECK(site_id > 0),
    CHECK(trim(order_number) <> ''),
    CHECK(customer_company_id IS NULL OR customer_company_id > 0),
    CHECK(customer_contact_id IS NULL OR customer_contact_id > 0),
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
CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_orders_source_cart
    ON sale_orders(source_cart_id)
    WHERE source_cart_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS sale_order_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    line_number INTEGER NOT NULL,
    business_product_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
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
    from_status TEXT CHECK(from_status IN ('draft','placed','confirmed','completed','cancelled')),
    to_status TEXT NOT NULL CHECK(to_status IN ('draft','placed','confirmed','completed','cancelled')),
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
    grand_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(grand_total_minor >= 0),
    expires_at TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    converted_order_id INTEGER,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(converted_order_id) REFERENCES sale_orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(cart_token_hash IS NULL OR length(cart_token_hash) >= 32),
    CHECK(customer_company_id IS NULL OR customer_company_id > 0),
    CHECK(customer_contact_id IS NULL OR customer_contact_id > 0),
    CHECK((status = 'converted' AND converted_order_id IS NOT NULL)
       OR (status <> 'converted' AND converted_order_id IS NULL))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_carts_token
    ON sale_carts(cart_token_hash)
    WHERE cart_token_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sale_carts_site_channel_status
    ON sale_carts(site_id, channel_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_carts_customer
    ON sale_carts(customer_company_id, customer_contact_id);
CREATE INDEX IF NOT EXISTS idx_sale_carts_expires
    ON sale_carts(status, expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_carts_checkout_step
    ON sale_carts(status, checkout_step, expires_at);

CREATE TABLE IF NOT EXISTS sale_cart_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cart_id INTEGER NOT NULL,
    line_key TEXT NOT NULL,
    business_product_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
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
    tax_rate_basis_points INTEGER NOT NULL DEFAULT 0 CHECK(tax_rate_basis_points >= 0),
    tax_included INTEGER NOT NULL DEFAULT 1 CHECK(tax_included IN (0,1)),
    line_subtotal_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_subtotal_minor >= 0),
    line_discount_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_discount_minor >= 0),
    line_tax_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_tax_minor >= 0),
    line_total_minor INTEGER NOT NULL DEFAULT 0 CHECK(line_total_minor >= 0),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
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
    provider_key TEXT,
    method_type TEXT NOT NULL CHECK(method_type IN ('cash','manual_card','external_terminal','bank_transfer','online_provider','test')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','archived')),
    config_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(config_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, channel_id, code),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(provider_key IS NULL OR (provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_.-]*')),
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
    status TEXT NOT NULL DEFAULT 'requires_payment' CHECK(status IN ('requires_payment','requires_action','authorized','captured','cancelled','failed','expired')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    idempotency_key TEXT,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    UNIQUE(provider_key, intent_reference),
    UNIQUE(site_id, idempotency_key),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(provider_key = lower(trim(provider_key)) AND provider_key GLOB '[a-z0-9_.-]*'),
    CHECK(intent_reference IS NULL OR trim(intent_reference) <> ''),
    CHECK(idempotency_key IS NULL OR trim(idempotency_key) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_intents_order
    ON sale_payment_intents(order_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_payment_intents_site_status
    ON sale_payment_intents(site_id, status, created_at DESC);

CREATE TABLE IF NOT EXISTS sale_payment_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_intent_id INTEGER,
    order_id INTEGER NOT NULL,
    transaction_type TEXT NOT NULL CHECK(transaction_type IN ('authorization','capture','payment','refund','void')),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','succeeded','failed','cancelled')),
    amount_minor INTEGER NOT NULL CHECK(amount_minor >= 0),
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    provider_transaction_id TEXT,
    provider_payload_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(provider_payload_json)),
    error_code TEXT,
    error_message TEXT,
    correlation_id TEXT,
    created_by_iam_user_id INTEGER,
    processed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(provider_transaction_id IS NULL OR trim(provider_transaction_id) <> ''),
    CHECK(error_code IS NULL OR trim(error_code) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_payment_transactions_order
    ON sale_payment_transactions(order_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_payment_transactions_intent
    ON sale_payment_transactions(payment_intent_id, transaction_type, status);

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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    UNIQUE(site_id, code),
    FOREIGN KEY(channel_id) REFERENCES sale_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(code = lower(trim(code)) AND code GLOB '[a-z0-9_-]*'),
    CHECK(trim(name) <> ''),
    CHECK(status <> 'archived' OR archived_at IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sale_pos_registers_site_status
    ON sale_pos_registers(site_id, status);
CREATE INDEX IF NOT EXISTS idx_sale_pos_registers_channel
    ON sale_pos_registers(channel_id, status);

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
    opened_by_iam_user_id INTEGER NOT NULL,
    closed_by_iam_user_id INTEGER,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closing','closed','cancelled')),
    opening_cash_minor INTEGER NOT NULL DEFAULT 0 CHECK(opening_cash_minor >= 0),
    expected_cash_minor INTEGER NOT NULL DEFAULT 0 CHECK(expected_cash_minor >= 0),
    counted_cash_minor INTEGER CHECK(counted_cash_minor IS NULL OR counted_cash_minor >= 0),
    difference_minor INTEGER NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TEXT,
    notes TEXT,
    FOREIGN KEY(register_id) REFERENCES sale_pos_registers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(opened_by_iam_user_id > 0),
    CHECK(closed_by_iam_user_id IS NULL OR closed_by_iam_user_id > 0),
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

CREATE TABLE IF NOT EXISTS sale_inventory_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    business_variant_id INTEGER NOT NULL,
    stock_location_id INTEGER NOT NULL,
    sku TEXT,
    tracked INTEGER NOT NULL DEFAULT 1 CHECK(tracked IN (0,1)),
    on_hand_quantity INTEGER NOT NULL DEFAULT 0,
    reserved_quantity INTEGER NOT NULL DEFAULT 0 CHECK(reserved_quantity >= 0),
    available_quantity INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, business_variant_id, stock_location_id),
    FOREIGN KEY(stock_location_id) REFERENCES sale_stock_locations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0),
    CHECK(business_variant_id > 0),
    CHECK(sku IS NULL OR trim(sku) <> ''),
    CHECK(available_quantity = on_hand_quantity - reserved_quantity)
);

CREATE INDEX IF NOT EXISTS idx_sale_inventory_items_variant
    ON sale_inventory_items(site_id, business_variant_id);
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
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','released','consumed','expired')),
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at TEXT,
    consumed_at TEXT,
    UNIQUE(inventory_item_id, reservation_key),
    FOREIGN KEY(inventory_item_id) REFERENCES sale_inventory_items(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(cart_id) REFERENCES sale_carts(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(cart_id IS NOT NULL OR order_id IS NOT NULL),
    CHECK(reservation_key = lower(trim(reservation_key)) AND reservation_key GLOB '[a-z0-9_.:-]*'),
    CHECK(status <> 'released' OR released_at IS NOT NULL),
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
    movement_type TEXT NOT NULL CHECK(movement_type IN ('initial','adjustment','reservation','release','sale','return','refund','correction')),
    quantity INTEGER NOT NULL,
    reference_type TEXT,
    reference_id INTEGER,
    reason TEXT,
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(inventory_item_id) REFERENCES sale_inventory_items(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(quantity <> 0),
    CHECK(reference_type IS NULL OR (reference_type = lower(trim(reference_type)) AND reference_type GLOB '[a-z0-9_.:-]*')),
    CHECK(reference_id IS NULL OR reference_id > 0),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0)
);

CREATE INDEX IF NOT EXISTS idx_sale_stock_movements_item
    ON sale_stock_movements(inventory_item_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_stock_movements_reference
    ON sale_stock_movements(reference_type, reference_id);

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
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    updated_at TEXT,
    version INTEGER NOT NULL DEFAULT 0 CHECK(version >= 0),
    UNIQUE(order_id, refund_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(payment_transaction_id) REFERENCES sale_payment_transactions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(trim(refund_number) <> ''),
    CHECK(created_by_iam_user_id IS NULL OR created_by_iam_user_id > 0),
    CHECK(processed_at IS NULL OR status IN ('succeeded','failed','cancelled'))
);

CREATE INDEX IF NOT EXISTS idx_sale_refunds_order
    ON sale_refunds(order_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sale_refunds_payment_transaction
    ON sale_refunds(payment_transaction_id);

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

CREATE TRIGGER IF NOT EXISTS trg_sale_payment_transactions_no_delete
BEFORE DELETE ON sale_payment_transactions BEGIN SELECT RAISE(ABORT, 'sale financial transactions are immutable'); END;
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
    scope TEXT NOT NULL CHECK(scope IN ('cart.add_line','checkout.place_order','payment.capture','pos.complete_sale','refund.create')),
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
        'sale.order.cancelled',
        'sale.payment.recorded',
        'sale.payment.failed',
        'sale.pos.session.opened',
        'sale.pos.session.closed',
        'sale.refund.created',
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
    status,
    currency,
    default_language,
    tax_mode,
    price_tax_included,
    is_public
) VALUES
    (1, 'admin-manual', 'Saisie admin manuelle', 'admin', 'active', 'CHF', 'fr', 'tax_included', 1, 0),
    (1, 'pos-main', 'Caisse principale', 'pos', 'draft', 'CHF', 'fr', 'tax_included', 1, 0),
    (1, 'web-main', 'Boutique web principale', 'ecommerce', 'active', 'CHF', 'fr', 'tax_included', 1, 1);

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
