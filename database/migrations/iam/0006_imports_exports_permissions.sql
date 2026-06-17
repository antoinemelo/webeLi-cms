-- IAM permissions for static exports as an editorial publication/deployment capability.
-- Safe to run repeatedly; no dedicated static export database is created.

INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES
('imports_exports.read', 'Lire l’historique des imports et exports', 'Accéder à la page Imports / Exports et consulter uniquement l’historique filesystem.'),
('imports_exports.write', 'Créer et télécharger les exports', 'Créer, relancer et télécharger les exports statiques.'),
('imports_exports.manage', 'Gérer les imports et exports', 'Inclut les opérations d’écriture et permet de supprimer ou nettoyer les releases.');

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM iam_roles r
JOIN iam_permissions p ON p.permission_key IN ('imports_exports.read', 'imports_exports.write', 'imports_exports.manage')
WHERE r.role_key IN ('super_admin', 'admin');
