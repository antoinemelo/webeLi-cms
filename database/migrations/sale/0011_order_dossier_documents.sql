CREATE TABLE IF NOT EXISTS sale_order_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    document_number TEXT NOT NULL,
    document_type TEXT NOT NULL CHECK(document_type IN ('order_confirmation','invoice','credit_note','delivery_note','pos_receipt')),
    status TEXT NOT NULL DEFAULT 'issued' CHECK(status IN ('issued','cancelled')),
    language TEXT NOT NULL DEFAULT 'fr' CHECK(language IN ('fr','en')),
    version INTEGER NOT NULL DEFAULT 1 CHECK(version > 0),
    snapshot_json TEXT NOT NULL CHECK(json_valid(snapshot_json)),
    text_snapshot TEXT NOT NULL,
    html_snapshot TEXT NOT NULL,
    issued_by_iam_user_id INTEGER,
    issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at TEXT,
    cancellation_reason TEXT,
    UNIQUE(order_id, document_type, language, version),
    UNIQUE(document_number),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(trim(document_number) <> ''),
    CHECK(status <> 'cancelled' OR (cancelled_at IS NOT NULL AND trim(COALESCE(cancellation_reason,'')) <> ''))
);
CREATE INDEX IF NOT EXISTS idx_sale_order_documents_order ON sale_order_documents(order_id, document_type, issued_at);
CREATE TRIGGER IF NOT EXISTS trg_sale_order_documents_no_delete BEFORE DELETE ON sale_order_documents BEGIN SELECT RAISE(ABORT, 'sale order documents are immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_sale_order_documents_immutable
BEFORE UPDATE ON sale_order_documents
WHEN NOT (
    OLD.status='issued' AND NEW.status='cancelled'
    AND NEW.cancelled_at IS NOT NULL AND trim(COALESCE(NEW.cancellation_reason,'')) <> ''
    AND NEW.order_id=OLD.order_id AND NEW.document_number=OLD.document_number
    AND NEW.document_type=OLD.document_type AND NEW.language=OLD.language
    AND NEW.version=OLD.version AND NEW.snapshot_json=OLD.snapshot_json
    AND NEW.text_snapshot=OLD.text_snapshot AND NEW.html_snapshot=OLD.html_snapshot
)
BEGIN SELECT RAISE(ABORT, 'sale issued documents are immutable'); END;

CREATE TABLE IF NOT EXISTS sale_order_payment_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL UNIQUE,
    mode TEXT NOT NULL CHECK(mode IN ('deferred_availability','deposit_balance')),
    status TEXT NOT NULL DEFAULT 'waiting_availability' CHECK(status IN ('waiting_availability','payment_due','partially_paid','paid','expired','cancelled')),
    price_policy TEXT NOT NULL DEFAULT 'frozen' CHECK(price_policy IN ('frozen','recalculate_on_availability')),
    provider_key TEXT NOT NULL,
    deposit_minor INTEGER NOT NULL DEFAULT 0 CHECK(deposit_minor >= 0),
    balance_minor INTEGER NOT NULL CHECK(balance_minor >= 0),
    payment_intent_id INTEGER,
    expected_availability_at TEXT,
    available_at TEXT,
    payment_requested_at TEXT,
    expires_at TEXT,
    reminder_count INTEGER NOT NULL DEFAULT 0 CHECK(reminder_count >= 0),
    idempotency_key TEXT NOT NULL UNIQUE,
    terms_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(terms_snapshot_json)),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(metadata_json)),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(payment_intent_id) REFERENCES sale_payment_intents(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK(trim(provider_key) <> ''),
    CHECK(trim(idempotency_key) <> '')
);
CREATE INDEX IF NOT EXISTS idx_sale_order_payment_plans_due ON sale_order_payment_plans(status, expected_availability_at, expires_at);
