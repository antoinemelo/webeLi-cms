PRAGMA foreign_keys = ON;

ALTER TABLE sale_payment_transactions ADD COLUMN correlation_id TEXT;
ALTER TABLE sale_payment_transactions ADD COLUMN created_by_iam_user_id INTEGER;
ALTER TABLE sale_returns ADD COLUMN idempotency_key TEXT;
ALTER TABLE sale_returns ADD COLUMN request_hash TEXT;
ALTER TABLE sale_receipts ADD COLUMN language TEXT NOT NULL DEFAULT 'fr' CHECK(language IN ('fr','en'));
ALTER TABLE sale_receipts ADD COLUMN operator_iam_user_id INTEGER;
ALTER TABLE sale_receipts ADD COLUMN snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(snapshot_json));

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_returns_idempotency ON sale_returns(order_id, idempotency_key) WHERE idempotency_key IS NOT NULL;

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
    CHECK(trim(reason) <> ''), CHECK(trim(idempotency_key) <> ''), CHECK(trim(correlation_id) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_financial_corrections_order ON sale_financial_corrections(order_id, created_at, id);

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

CREATE INDEX IF NOT EXISTS idx_sale_order_customer_reconciliations_order ON sale_order_customer_reconciliations(order_id, created_at, id);

CREATE TRIGGER IF NOT EXISTS trg_sale_payment_transactions_no_delete
BEFORE DELETE ON sale_payment_transactions BEGIN SELECT RAISE(ABORT, 'sale financial transactions are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_payment_allocations_no_delete
BEFORE DELETE ON sale_payment_allocations BEGIN SELECT RAISE(ABORT, 'sale payment allocations are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_refunds_no_delete
BEFORE DELETE ON sale_refunds BEGIN SELECT RAISE(ABORT, 'sale refunds are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_financial_corrections_no_delete
BEFORE DELETE ON sale_financial_corrections BEGIN SELECT RAISE(ABORT, 'sale financial corrections are immutable'); END;

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
