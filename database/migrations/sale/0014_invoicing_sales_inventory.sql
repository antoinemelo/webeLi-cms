-- M8.9: politique de facturation, sequences non reutilisables et audit des exports.
-- Ces structures fournissent des garanties techniques. Elles ne constituent
-- pas, seules, une certification de conformite fiscale pour une juridiction.
CREATE TABLE IF NOT EXISTS sale_document_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL UNIQUE,
    invoice_trigger TEXT NOT NULL DEFAULT 'paid' CHECK(invoice_trigger IN ('paid','validated')),
    invoice_series TEXT NOT NULL DEFAULT 'INV',
    credit_note_series TEXT NOT NULL DEFAULT 'CRN',
    seller_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(seller_snapshot_json)),
    gift_card_policy TEXT NOT NULL DEFAULT 'unconfigured' CHECK(gift_card_policy IN ('unconfigured','sale','redemption')),
    legal_notice TEXT,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(trim(invoice_series) <> ''),
    CHECK(trim(credit_note_series) <> '')
);

CREATE TABLE IF NOT EXISTS sale_document_sequences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    document_type TEXT NOT NULL CHECK(document_type IN ('order_confirmation','invoice','credit_note','delivery_note','pos_receipt')),
    series_code TEXT NOT NULL,
    period_key TEXT NOT NULL,
    next_number INTEGER NOT NULL DEFAULT 1 CHECK(next_number > 0),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id,document_type,series_code,period_key),
    CHECK(site_id > 0),
    CHECK(trim(series_code) <> ''),
    CHECK(trim(period_key) <> '')
);

ALTER TABLE sale_order_documents ADD COLUMN site_id INTEGER;
ALTER TABLE sale_order_documents ADD COLUMN series_code TEXT;
ALTER TABLE sale_order_documents ADD COLUMN sequence_number INTEGER;
ALTER TABLE sale_order_documents ADD COLUMN document_hash TEXT;
ALTER TABLE sale_order_documents ADD COLUMN policy_snapshot_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(policy_snapshot_json));
ALTER TABLE sale_order_documents ADD COLUMN pdf_media_id INTEGER;

UPDATE sale_order_documents
SET site_id=(SELECT site_id FROM sale_orders WHERE sale_orders.id=sale_order_documents.order_id),
    document_hash=lower(hex(randomblob(32)))
WHERE site_id IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_sale_order_documents_sequence
    ON sale_order_documents(site_id,document_type,series_code,sequence_number)
    WHERE series_code IS NOT NULL AND sequence_number IS NOT NULL;

DROP TRIGGER IF EXISTS trg_sale_order_documents_immutable;
CREATE TRIGGER trg_sale_order_documents_immutable
BEFORE UPDATE ON sale_order_documents
WHEN NOT (
    OLD.status='issued' AND NEW.status='cancelled'
    AND NEW.cancelled_at IS NOT NULL AND trim(COALESCE(NEW.cancellation_reason,'')) <> ''
    AND NEW.order_id=OLD.order_id AND NEW.site_id=OLD.site_id AND NEW.document_number=OLD.document_number
    AND NEW.document_type=OLD.document_type AND NEW.language=OLD.language AND NEW.version=OLD.version
    AND NEW.series_code IS OLD.series_code AND NEW.sequence_number IS OLD.sequence_number
    AND NEW.document_hash IS OLD.document_hash AND NEW.policy_snapshot_json=OLD.policy_snapshot_json
    AND NEW.snapshot_json=OLD.snapshot_json AND NEW.text_snapshot=OLD.text_snapshot
    AND NEW.html_snapshot=OLD.html_snapshot AND NEW.pdf_media_id IS OLD.pdf_media_id
)
BEGIN SELECT RAISE(ABORT, 'sale issued documents are immutable'); END;

CREATE TABLE IF NOT EXISTS sale_admin_export_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    export_type TEXT NOT NULL,
    filters_json TEXT NOT NULL DEFAULT '{}' CHECK(json_valid(filters_json)),
    row_count INTEGER NOT NULL DEFAULT 0 CHECK(row_count >= 0),
    created_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(site_id > 0),
    CHECK(trim(export_type) <> '')
);

CREATE INDEX IF NOT EXISTS idx_sale_admin_export_audit_site
    ON sale_admin_export_audit(site_id,created_at DESC,id DESC);

CREATE TABLE IF NOT EXISTS sale_document_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    order_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    outbox_id INTEGER,
    recipient_hash TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT 'fr' CHECK(language IN ('fr','en')),
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed','cancelled')),
    idempotency_key TEXT NOT NULL,
    resend_of_id INTEGER,
    queued_by_iam_user_id INTEGER,
    queued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id,idempotency_key),
    FOREIGN KEY(order_id) REFERENCES sale_orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(document_id) REFERENCES sale_order_documents(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(outbox_id) REFERENCES sale_outbox(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY(resend_of_id) REFERENCES sale_document_deliveries(id) ON DELETE RESTRICT ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sale_document_deliveries_document ON sale_document_deliveries(document_id,queued_at DESC,id DESC);
