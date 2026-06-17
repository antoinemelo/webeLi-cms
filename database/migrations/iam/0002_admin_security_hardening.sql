ALTER TABLE iam_audit_logs ADD COLUMN ip_address TEXT;
ALTER TABLE iam_audit_logs ADD COLUMN user_agent TEXT;

CREATE TABLE IF NOT EXISTS iam_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rate_key TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_iam_rate_limits_key_created ON iam_rate_limits(rate_key, created_at);

CREATE INDEX IF NOT EXISTS idx_iam_sessions_token_hash_expiry ON iam_sessions(session_token_hash, expires_at);
CREATE INDEX IF NOT EXISTS idx_iam_audit_logs_action_created ON iam_audit_logs(action_key, created_at);

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r CROSS JOIN iam_permissions p WHERE r.role_key='super_admin';

INSERT OR IGNORE INTO iam_user_roles(user_id, role_id)
SELECT u.id, r.id FROM iam_users u CROSS JOIN iam_roles r WHERE u.email_normalized='admin@example.test' AND r.role_key='super_admin';

-- Permissions natives pour sécurité API, webhooks, CORS et connexion par code email.
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('security.tokens.read', 'Lire les tokens API', 'Consulter les tokens API du site sélectionné.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('security.tokens.manage', 'Gérer les tokens API', 'Créer, modifier, activer, désactiver et supprimer les tokens API du site sélectionné.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('security.webhooks.read', 'Lire les webhooks', 'Consulter les webhooks de publication du site sélectionné.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('security.webhooks.manage', 'Gérer les webhooks', 'Créer, modifier, activer, désactiver et supprimer les webhooks de publication.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('security.cors.read', 'Lire le CORS par site', 'Consulter les origines CORS autorisées pour l’API headless du site.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('security.cors.manage', 'Gérer le CORS par site', 'Modifier les origines CORS autorisées pour l’API headless du site.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('users.email_2fa.manage', 'Gérer la connexion par code email', 'Activer ou désactiver la connexion par code email depuis la fiche utilisateur.');
INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN ('security.tokens.read','security.tokens.manage','security.webhooks.read','security.webhooks.manage','security.cors.read','security.cors.manage','users.email_2fa.manage') WHERE r.role_key='super_admin';
