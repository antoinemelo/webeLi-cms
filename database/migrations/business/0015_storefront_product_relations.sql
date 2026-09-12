PRAGMA foreign_keys = OFF;

CREATE TABLE business_product_relations_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    related_product_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL CHECK(relation_type IN ('related','accessory','alternative','bundle_candidate','replacement','upsell','cross_sell','similar')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(related_product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id,product_id,related_product_id,relation_type),
    CHECK(site_id > 0), CHECK(product_id > 0), CHECK(related_product_id > 0), CHECK(product_id <> related_product_id)
);
INSERT INTO business_product_relations_v2(id,site_id,product_id,related_product_id,relation_type,sort_order,created_at)
SELECT id,site_id,product_id,related_product_id,relation_type,sort_order,created_at FROM business_product_relations;
DROP TABLE business_product_relations;
ALTER TABLE business_product_relations_v2 RENAME TO business_product_relations;
CREATE INDEX idx_business_product_relations_product ON business_product_relations(site_id,product_id,relation_type,sort_order);
CREATE INDEX idx_business_product_relations_related ON business_product_relations(site_id,related_product_id,relation_type);

CREATE TABLE business_product_relation_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL CHECK(relation_type IN ('related','accessory','alternative','upsell','cross_sell')),
    match_type TEXT NOT NULL CHECK(match_type IN ('category','group')),
    match_id INTEGER NOT NULL,
    result_limit INTEGER NOT NULL DEFAULT 6 CHECK(result_limit BETWEEN 1 AND 24),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES business_products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(site_id,product_id,relation_type,match_type,match_id),
    CHECK(site_id > 0), CHECK(product_id > 0), CHECK(match_id > 0)
);
CREATE INDEX idx_business_product_relation_rules_product ON business_product_relation_rules(site_id,product_id,is_active,sort_order,id);

PRAGMA foreign_keys = ON;
