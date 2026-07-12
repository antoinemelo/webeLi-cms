PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS sale_order_claim_proofs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    order_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    email_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','consumed','revoked','expired')),
    expires_at TEXT NOT NULL,
    consumed_by_iam_user_id INTEGER,
    consumed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(site_id > 0), CHECK(length(token_hash)>=32), CHECK(length(email_hash)>=32)
);

CREATE TABLE IF NOT EXISTS sale_customer_account_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    iam_user_id INTEGER NOT NULL,
    crm_company_id INTEGER,
    crm_contact_id INTEGER,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','merged')),
    linked_by TEXT NOT NULL CHECK(linked_by IN ('post_purchase_proof','verified_email','admin','merge')),
    merged_into_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(site_id,iam_user_id),
    CHECK(site_id>0), CHECK(iam_user_id>0),
    CHECK(crm_company_id IS NULL OR crm_company_id>0), CHECK(crm_contact_id IS NULL OR crm_contact_id>0)
);

CREATE TABLE IF NOT EXISTS sale_customer_order_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    order_id INTEGER NOT NULL,
    iam_user_id INTEGER NOT NULL,
    claim_proof_id INTEGER,
    link_source TEXT NOT NULL CHECK(link_source IN ('post_purchase_proof','verified_email','admin','merge')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','revoked')),
    linked_by_iam_user_id INTEGER,
    linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TEXT,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(claim_proof_id) REFERENCES sale_order_claim_proofs(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE(order_id),
    CHECK(site_id>0), CHECK(iam_user_id>0)
);

CREATE TABLE IF NOT EXISTS sale_customer_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    iam_user_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    address_type TEXT NOT NULL DEFAULT 'both' CHECK(address_type IN ('billing','shipping','both')),
    address_json TEXT NOT NULL CHECK(json_valid(address_json)),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    archived_at TEXT,
    CHECK(site_id>0), CHECK(iam_user_id>0), CHECK(trim(label)<>'')
);

CREATE TABLE IF NOT EXISTS sale_customer_merge_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    source_iam_user_id INTEGER NOT NULL,
    target_iam_user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'applied' CHECK(status IN ('applied','reversed')),
    reason TEXT NOT NULL,
    before_json TEXT NOT NULL CHECK(json_valid(before_json)),
    after_json TEXT NOT NULL CHECK(json_valid(after_json)),
    actor_iam_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_at TEXT,
    CHECK(site_id>0), CHECK(source_iam_user_id<>target_iam_user_id), CHECK(trim(reason)<>'')
);

CREATE INDEX IF NOT EXISTS idx_sale_claim_proofs_order ON sale_order_claim_proofs(order_id,status,expires_at);
CREATE INDEX IF NOT EXISTS idx_sale_customer_order_links_account ON sale_customer_order_links(site_id,iam_user_id,status,linked_at);
CREATE INDEX IF NOT EXISTS idx_sale_customer_addresses_account ON sale_customer_addresses(site_id,iam_user_id,is_default,archived_at);
