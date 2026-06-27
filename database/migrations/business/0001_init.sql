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
    ON business_contacts(iam_user_id)
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
