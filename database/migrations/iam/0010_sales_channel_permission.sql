PRAGMA foreign_keys = ON;

INSERT OR IGNORE INTO iam_permissions(permission_key,name,description)
VALUES('sale.channels.manage','Gérer les canaux de vente','Résoudre, configurer et contrôler les références SalesChannel intermodules.');

INSERT OR IGNORE INTO iam_role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM iam_roles r CROSS JOIN iam_permissions p
WHERE r.role_key='super_admin' AND p.permission_key='sale.channels.manage';
