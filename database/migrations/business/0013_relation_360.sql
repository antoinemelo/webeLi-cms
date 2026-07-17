PRAGMA foreign_keys = ON;

-- Upgrade path for installed Business databases. Fresh installations use the
-- same definitions from database/modules/business.sql.
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
