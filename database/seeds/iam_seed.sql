INSERT OR IGNORE INTO iam_roles(role_key, name, description) VALUES
('super_admin', 'Super admin', 'Accès complet sans restriction fonctionnelle.'),
('admin', 'Admin', 'Administration opérationnelle du CMS hors sécurité système sensible.'),
('editor', 'Éditeur', 'Création et modification des contenus, langue principale incluse.'),
('translator', 'Traducteur', 'Traductions et SEO éditorial simple dans les langues secondaires.'),
('publication', 'Publication', 'Validation finale, publication, dépublication et archivage.'),
('seo', 'SEO', 'SEO éditorial, structurel et avancé.'),
('user', 'Utilisateur', 'Utilisateur final limité à son espace personnel.');

INSERT OR IGNORE INTO iam_permissions(permission_key, name, description) VALUES
('*', 'Toutes les permissions', 'Réservé au super admin.'),
('content.read', 'Lire les contenus', 'Lire les entrées éditoriales.'),
('content.create', 'Créer des contenus', 'Créer de nouvelles entrées.'),
('content.update', 'Modifier des contenus', 'Permission historique conservée pour compatibilité ; préférer les permissions content.revisions.*.'),
('content.revisions.save', 'Enregistrer une révision', 'Créer ou mettre à jour une révision de travail sans publier.'),
('content.revisions.restore', 'Restaurer une révision', 'Restaurer une ancienne révision comme révision de travail sans publier.'),
('content.revisions.prune', 'Nettoyer les révisions', 'Supprimer les révisions obsolètes avant la version publiée.'),
('content.update_primary_language', 'Modifier la langue principale', 'Modifier le contenu source dans la langue principale.'),
('content.translate', 'Traduire les contenus', 'Créer et modifier les langues secondaires.'),
('content.duplicate', 'Dupliquer des contenus', 'Créer une copie éditoriale.'),
('content.delete', 'Supprimer des contenus', 'Supprimer définitivement un contenu selon les règles de gouvernance.'),
('content.approve', 'Approuver les contenus', 'Valider avant publication.'),
('content.publish', 'Publier les contenus', 'Publier et reconstruire les projections publiques.'),
('content.html_raw.manage', 'Gérer le HTML brut', 'Autoriser la création et la publication de blocs HTML brut non filtré.'),
('content.unpublish', 'Dépublier les contenus', 'Retirer un contenu publié.'),
('content.archive', 'Archiver les contenus', 'Archiver ou restaurer.'),
('content.preview', 'Prévisualiser les contenus', 'Générer des previews authentifiées.'),
('content.order', 'Organiser les contenus', 'Ordre, mise en avant, dates et visibilité.'),
('media.read', 'Lire les médias', 'Lister les assets média.'),
('media.upload', 'Ajouter des médias', 'Téléverser de nouveaux assets.'),
('media.update', 'Modifier les médias', 'Métadonnées, alt text et variantes.'),
('media.delete', 'Supprimer les médias', 'Suppression contrôlée.'),
('taxonomy.read', 'Lire les taxonomies', 'Lire taxonomies et termes.'),
('taxonomy.manage', 'Gérer les taxonomies', 'Créer, modifier et organiser les taxonomies.'),
('menu.read', 'Lire les menus', 'Lire les menus.'),
('menu.manage', 'Gérer les menus', 'Créer et organiser les navigations.'),
('fields.read', 'Lire les champs', 'Lire les champs éditoriaux historiques.'),
('fields.manage', 'Gérer les champs', 'Créer et modifier les champs éditoriaux historiques.'),
('blueprints.read', 'Lire les blueprints', 'Consulter les blueprints, modèles éditoriaux et fieldsets.'),
('blueprints.manage', 'Gérer les blueprints', 'Créer, modifier, versionner, activer ou supprimer les blueprints et fieldsets.'),
('seo.read', 'Lire le SEO', 'Consulter les champs et diagnostics SEO.'),
('seo.simple', 'SEO éditorial simple', 'Meta title, meta description, alt text, titres SEO simples.'),
('seo.manage', 'Gérer le SEO', 'Modifier les paramètres SEO autorisés par le rôle.'),
('seo.advanced', 'SEO avancé', 'Canonical, robots, indexation, sitemap, structured data.'),
('seo.redirects', 'Redirections SEO', 'Redirections, tombstones et règles URL.'),
('seo.audit', 'Audit SEO', 'Consulter et exécuter les audits SEO.'),
('settings.read', 'Lire les paramètres', 'Lire les réglages fonctionnels.'),
('settings.manage', 'Gérer les paramètres fonctionnels', 'Modifier les réglages non sensibles.'),
('settings.sensitive', 'Gérer les paramètres sensibles', 'Secrets, sécurité globale et opérations critiques.'),
('security.tokens.read', 'Lire les tokens API', 'Consulter les tokens API du site sélectionné.'),
('security.tokens.manage', 'Gérer les tokens API', 'Créer, modifier, activer, désactiver et supprimer les tokens API du site sélectionné.'),
('security.webhooks.read', 'Lire les webhooks', 'Consulter les webhooks de publication du site sélectionné.'),
('security.webhooks.manage', 'Gérer les webhooks', 'Créer, modifier, activer, désactiver et supprimer les webhooks de publication.'),
('security.cors.read', 'Lire le CORS par site', 'Consulter les origines CORS autorisées pour l’API headless du site.'),
('security.cors.manage', 'Gérer le CORS par site', 'Modifier les origines CORS autorisées pour l’API headless du site.'),
('users.email_2fa.manage', 'Gérer la connexion par code email', 'Activer ou désactiver la connexion par code email depuis la fiche utilisateur.'),
('themes.read', 'Lire les thèmes', 'Consulter les thèmes.'),
('themes.manage', 'Gérer les thèmes', 'Configurer les thèmes.'),
('modules.read', 'Lire les modules', 'Consulter le catalogue, l’état, les dépendances et le diagnostic des modules.'),
('modules.manage', 'Gérer les modules', 'Installer, activer, désactiver, migrer et diagnostiquer les modules.'),
('forms.read', 'Lire les formulaires', 'Lire formulaires et soumissions.'),
('forms.manage', 'Gérer les formulaires', 'Créer et configurer les formulaires.'),
('cookies.read', 'Lire les cookies et consentements', 'Consulter la configuration cookies, les services et les journaux.'),
('cookies.manage', 'Gérer les cookies et consentements', 'Configurer la bannière, les catégories, services et scripts soumis à consentement.'),
('users.read', 'Lire les utilisateurs', 'Consulter utilisateurs, rôles et accès.'),
('users.manage', 'Gérer les utilisateurs', 'Créer, modifier, activer/désactiver et réinitialiser les mots de passe.'),
('roles.read', 'Lire les rôles', 'Consulter rôles et permissions.'),
('roles.manage', 'Gérer les rôles', 'Créer et modifier les rôles et permissions.'),
('sessions.read', 'Lire les sessions', 'Consulter les sessions IAM.'),
('sessions.manage', 'Gérer les sessions', 'Révoquer les sessions.'),
('audit.read', 'Lire le journal d’audit', 'Consulter les actions sensibles journalisées.'),
('maintenance.manage', 'Maintenance', 'Activer maintenance et opérations système critiques.'),
('deployments.read', 'Lire les déploiements', 'Lire l’état de release.'),
('deployments.manage', 'Gérer les déploiements', 'Préparer, packager et déployer.'),
('imports_exports.read', 'Lire l’historique des imports et exports', 'Accéder à la page Imports / Exports et consulter uniquement l’historique filesystem.'),
('imports_exports.write', 'Créer et télécharger les exports', 'Créer, relancer et télécharger les exports statiques.'),
('imports_exports.manage', 'Gérer les imports et exports', 'Inclut les opérations d’écriture et permet de supprimer ou nettoyer les releases.'),
('profile.read', 'Lire son profil', 'Consulter son propre profil.'),
('profile.update', 'Modifier son profil', 'Modifier ses données personnelles autorisées.');

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r CROSS JOIN iam_permissions p WHERE r.role_key='super_admin';

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN (
'content.read','content.create','content.update','content.revisions.save','content.revisions.restore','content.revisions.prune','content.update_primary_language','content.translate','content.duplicate','content.delete','content.approve','content.publish','content.unpublish','content.archive','content.preview','content.order',
'media.read','media.upload','media.update','media.delete','taxonomy.read','taxonomy.manage','menu.read','menu.manage','fields.read','blueprints.read','blueprints.manage','seo.read','seo.simple','seo.manage','seo.audit','settings.read','settings.manage','themes.read','themes.manage','forms.read','forms.manage','cookies.read','cookies.manage','deployments.read','imports_exports.read','imports_exports.write','imports_exports.manage'
) WHERE r.role_key='admin';

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN (
'content.read','content.create','content.revisions.save','content.update_primary_language','content.translate','content.duplicate','content.preview','media.read','media.upload','media.update','taxonomy.read','menu.read','fields.read','seo.read','seo.simple'
) WHERE r.role_key='editor';

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN (
'content.read','content.revisions.save','content.translate','content.preview','media.read','taxonomy.read','fields.read','seo.read','seo.simple'
) WHERE r.role_key='translator';

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN (
'content.read','content.revisions.restore','content.revisions.prune','content.approve','content.publish','content.unpublish','content.archive','content.preview','content.order','media.read','taxonomy.read','menu.read','fields.read','seo.read','seo.audit'
) WHERE r.role_key='publication';

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN (
'content.read','content.preview','media.read','media.update','taxonomy.read','menu.read','fields.read','seo.read','seo.simple','seo.manage','seo.advanced','seo.redirects','seo.audit'
) WHERE r.role_key='seo';

INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
SELECT r.id, p.id FROM iam_roles r JOIN iam_permissions p ON p.permission_key IN ('profile.read','profile.update') WHERE r.role_key='user';

INSERT OR IGNORE INTO iam_user_roles(user_id, role_id)
SELECT u.id, r.id FROM iam_users u CROSS JOIN iam_roles r WHERE u.email_normalized='admin@example.test' AND r.role_key='super_admin';
