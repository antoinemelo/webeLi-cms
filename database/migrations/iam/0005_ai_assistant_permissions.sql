-- IAM permissions for the optional ai-assistant module.
-- Safe to run even if the module remains disabled.

INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.use', 'Utiliser l’IA', 'Accéder aux fonctions IA non destructives et à la configuration visible du module Assistant IA.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.provider.manage', 'Gérer les providers IA', 'Configurer, activer, désactiver et tester les fournisseurs IA.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.suggestions.read', 'Lire les suggestions IA', 'Consulter les suggestions proposées par le module Assistant IA.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.suggestions.manage', 'Gérer les suggestions IA', 'Accepter, rejeter, archiver ou marquer comme appliquées les suggestions IA.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.content.suggest', 'Suggestions IA de contenu', 'Demander des suggestions IA liées aux contenus sans publication automatique.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.content.draft', 'Préparer des brouillons IA', 'Préparer des brouillons via IA en passant par les capabilities officielles du core.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.seo.suggest', 'Suggestions SEO IA', 'Demander des suggestions IA pour les métadonnées et diagnostics SEO.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.translation.suggest', 'Suggestions de traduction IA', 'Demander des suggestions IA de traduction lorsque le site l’autorise.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.actions.apply', 'Appliquer des actions IA', 'Appliquer une suggestion IA via dry-run, confirmation, permissions et journalisation.');
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES ('ai.logs.read', 'Lire les journaux IA', 'Consulter les journaux d’usage et d’actions IA sans exposer les secrets.');

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM iam_roles r
JOIN iam_permissions p ON p.permission_key IN (
    'ai.use', 'ai.provider.manage', 'ai.suggestions.read', 'ai.suggestions.manage',
    'ai.content.suggest', 'ai.content.draft', 'ai.seo.suggest', 'ai.translation.suggest',
    'ai.actions.apply', 'ai.logs.read'
)
WHERE r.role_key IN ('super_admin', 'admin');
