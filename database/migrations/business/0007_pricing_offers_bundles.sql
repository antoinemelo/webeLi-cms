PRAGMA foreign_keys = ON;

ALTER TABLE business_catalog_discounts ADD COLUMN customer_segment TEXT;
ALTER TABLE business_product_bundles ADD COLUMN composition_type TEXT NOT NULL DEFAULT 'bundle' CHECK(composition_type IN ('bundle','kit'));
ALTER TABLE business_product_bundles ADD COLUMN unavailable_strategy TEXT NOT NULL DEFAULT 'reject' CHECK(unavailable_strategy IN ('reject','backorder','contact'));

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

CREATE INDEX IF NOT EXISTS idx_business_price_lists_context
    ON business_price_lists(site_id, currency, channel, customer_segment, status, priority);
CREATE INDEX IF NOT EXISTS idx_business_price_list_items_target
    ON business_price_list_items(product_id, variant_id, priority, archived_at);
CREATE INDEX IF NOT EXISTS idx_business_catalog_discounts_segment
    ON business_catalog_discounts(site_id, customer_segment, channel, status, priority);
