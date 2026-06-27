-- IAM permissions for the Business CRM system module.
-- Safe to run repeatedly; the module keeps its data in business.sqlite.

INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES
('business.crm.read', 'Lire le CRM Business', 'Lire les entreprises, contacts, tags, consentements et données CRM autorisées.'),
('business.crm.manage', 'Gérer le CRM Business', 'Créer, modifier et archiver entreprises, contacts, tags et consentements.'),
('business.memo.read', 'Lire les mémos CRM', 'Consulter les mémos CRM accessibles et leurs partages internes.'),
('business.memo.manage', 'Gérer les mémos CRM', 'Créer, modifier, commenter et archiver les mémos CRM.'),
('business.memo.share', 'Partager les mémos CRM', 'Créer ou révoquer des partages internes et liens publics de mémos.'),
('business.mailing.read', 'Lire le mailing Business', 'Consulter listes, campagnes et historiques de diffusion.'),
('business.mailing.manage', 'Gérer le mailing Business', 'Gérer listes, campagnes simples, destinataires et désabonnements.'),
('business.messaging.send', 'Envoyer des messages Business', 'Planifier ou déclencher un envoi après contrôle du consentement.'),
('business.messaging.admin', 'Administrer le messaging Business', 'Configurer providers, templates et outbox messaging sans stocker de secret en clair.');

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM iam_roles r
JOIN iam_permissions p ON p.permission_key IN (
    'business.crm.read', 'business.crm.manage',
    'business.memo.read', 'business.memo.manage', 'business.memo.share',
    'business.mailing.read', 'business.mailing.manage',
    'business.messaging.send', 'business.messaging.admin'
)
WHERE r.role_key IN ('super_admin', 'admin');
