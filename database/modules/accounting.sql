PRAGMA foreign_keys = ON;

-- Le plan comptable est propre à un site. Les comptes gardent un identifiant
-- interne stable: leur numéro peut ainsi être corrigé sans casser les soldes
-- d'ouverture ni les futures écritures.
CREATE TABLE IF NOT EXISTS accounting_charts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT 'Plan comptable',
    currency TEXT NOT NULL DEFAULT 'CHF' CHECK(length(currency) = 3 AND currency = upper(currency)),
    default_increase_side TEXT NOT NULL DEFAULT 'debit' CHECK(default_increase_side IN ('debit','credit')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id),
    CHECK(site_id > 0),
    CHECK(trim(name) <> '')
);

-- Équivalent normalisé de moinsplus.txt. Le préfixe le plus long gagne.
-- En comptabilité suisse classique, 2 et 3 augmentent au crédit.
CREATE TABLE IF NOT EXISTS accounting_balance_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chart_id INTEGER NOT NULL,
    account_prefix TEXT NOT NULL,
    increase_side TEXT NOT NULL DEFAULT 'credit' CHECK(increase_side IN ('debit','credit')),
    label TEXT,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(chart_id, account_prefix),
    FOREIGN KEY(chart_id) REFERENCES accounting_charts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(account_prefix <> '' AND account_prefix NOT GLOB '*[^0-9]*')
);

-- Rubriques hiérarchiques déterminées par préfixe (1, 10, 11, ...).
CREATE TABLE IF NOT EXISTS accounting_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chart_id INTEGER NOT NULL,
    account_prefix TEXT NOT NULL,
    label TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(chart_id, account_prefix),
    FOREIGN KEY(chart_id) REFERENCES accounting_charts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(account_prefix <> '' AND account_prefix NOT GLOB '*[^0-9]*'),
    CHECK(trim(label) <> '')
);

CREATE TABLE IF NOT EXISTS accounting_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chart_id INTEGER NOT NULL,
    account_number TEXT NOT NULL,
    label TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT,
    UNIQUE(chart_id, account_number),
    FOREIGN KEY(chart_id) REFERENCES accounting_charts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(account_number <> '' AND account_number NOT GLOB '*[^0-9]*'),
    CHECK(trim(label) <> ''),
    CHECK(is_active = 1 OR archived_at IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS accounting_fiscal_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chart_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    label TEXT NOT NULL,
    starts_on TEXT NOT NULL,
    ends_on TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','open','closed')),
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(chart_id, code),
    FOREIGN KEY(chart_id) REFERENCES accounting_charts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CHECK(trim(code) <> ''),
    CHECK(trim(label) <> ''),
    CHECK(starts_on <= ends_on)
);

-- Montant positif = solde du côté normal du compte; un montant négatif
-- représente exceptionnellement un solde du côté opposé.
CREATE TABLE IF NOT EXISTS accounting_opening_balances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiscal_period_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    amount_minor INTEGER NOT NULL DEFAULT 0,
    created_by_iam_user_id INTEGER,
    updated_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(fiscal_period_id, account_id),
    FOREIGN KEY(fiscal_period_id) REFERENCES accounting_fiscal_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Structure prévue pour la journalisation future. Les lignes pointent vers
-- l'identifiant stable du compte, jamais vers son numéro modifiable.
CREATE TABLE IF NOT EXISTS accounting_journal_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiscal_period_id INTEGER NOT NULL,
    entry_number INTEGER NOT NULL,
    entry_date TEXT NOT NULL,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','posted','reversed')),
    source_type TEXT,
    source_id TEXT,
    created_by_iam_user_id INTEGER,
    posted_by_iam_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at TEXT,
    UNIQUE(fiscal_period_id, entry_number),
    FOREIGN KEY(fiscal_period_id) REFERENCES accounting_fiscal_periods(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK(entry_number > 0),
    CHECK(trim(label) <> '')
);

CREATE TABLE IF NOT EXISTS accounting_journal_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    journal_entry_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    line_number INTEGER NOT NULL,
    debit_minor INTEGER NOT NULL DEFAULT 0 CHECK(debit_minor >= 0),
    credit_minor INTEGER NOT NULL DEFAULT 0 CHECK(credit_minor >= 0),
    label TEXT,
    FOREIGN KEY(journal_entry_id) REFERENCES accounting_journal_entries(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY(account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE(journal_entry_id, line_number),
    CHECK((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))
);

CREATE INDEX IF NOT EXISTS idx_accounting_rules_chart_prefix
    ON accounting_balance_rules(chart_id, account_prefix);
CREATE INDEX IF NOT EXISTS idx_accounting_categories_chart_prefix
    ON accounting_categories(chart_id, account_prefix);
CREATE INDEX IF NOT EXISTS idx_accounting_accounts_chart_active_number
    ON accounting_accounts(chart_id, is_active, account_number);
CREATE INDEX IF NOT EXISTS idx_accounting_periods_chart_dates
    ON accounting_fiscal_periods(chart_id, starts_on, ends_on);
CREATE INDEX IF NOT EXISTS idx_accounting_opening_period
    ON accounting_opening_balances(fiscal_period_id, account_id);
CREATE INDEX IF NOT EXISTS idx_accounting_lines_account
    ON accounting_journal_lines(account_id, journal_entry_id);

CREATE TRIGGER IF NOT EXISTS trg_accounting_opening_same_chart_insert
BEFORE INSERT ON accounting_opening_balances
WHEN (SELECT chart_id FROM accounting_fiscal_periods WHERE id=NEW.fiscal_period_id)
   <> (SELECT chart_id FROM accounting_accounts WHERE id=NEW.account_id)
BEGIN
    SELECT RAISE(ABORT, 'opening balance account must belong to fiscal period chart');
END;

CREATE TRIGGER IF NOT EXISTS trg_accounting_opening_same_chart_update
BEFORE UPDATE OF fiscal_period_id,account_id ON accounting_opening_balances
WHEN (SELECT chart_id FROM accounting_fiscal_periods WHERE id=NEW.fiscal_period_id)
   <> (SELECT chart_id FROM accounting_accounts WHERE id=NEW.account_id)
BEGIN
    SELECT RAISE(ABORT, 'opening balance account must belong to fiscal period chart');
END;

CREATE TRIGGER IF NOT EXISTS trg_accounting_line_same_chart_insert
BEFORE INSERT ON accounting_journal_lines
WHEN (
    SELECT p.chart_id
    FROM accounting_journal_entries e
    JOIN accounting_fiscal_periods p ON p.id=e.fiscal_period_id
    WHERE e.id=NEW.journal_entry_id
) <> (SELECT chart_id FROM accounting_accounts WHERE id=NEW.account_id)
BEGIN
    SELECT RAISE(ABORT, 'journal line account must belong to journal chart');
END;

CREATE TRIGGER IF NOT EXISTS trg_accounting_line_same_chart_update
BEFORE UPDATE OF journal_entry_id,account_id ON accounting_journal_lines
WHEN (
    SELECT p.chart_id
    FROM accounting_journal_entries e
    JOIN accounting_fiscal_periods p ON p.id=e.fiscal_period_id
    WHERE e.id=NEW.journal_entry_id
) <> (SELECT chart_id FROM accounting_accounts WHERE id=NEW.account_id)
BEGIN
    SELECT RAISE(ABORT, 'journal line account must belong to journal chart');
END;
