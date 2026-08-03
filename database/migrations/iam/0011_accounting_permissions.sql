-- Permissions du module système Comptabilité.
INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES
('accounting.read', 'Lire la comptabilité', 'Consulter le plan comptable et les soldes d’ouverture.'),
('accounting.chart.manage', 'Gérer le plan comptable', 'Modifier les règles de sens, rubriques et comptes.'),
('accounting.opening.manage', 'Gérer les ouvertures comptables', 'Créer les exercices et modifier les soldes d’ouverture.');

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM iam_roles r
JOIN iam_permissions p ON p.permission_key IN (
    'accounting.read', 'accounting.chart.manage', 'accounting.opening.manage'
)
WHERE r.role_key IN ('super_admin','admin');
