-- Seed snapshot genere depuis database.zip pour mod2_v02-e21e.
-- Ne contient pas de DDL durable; les structures restent dans database/schema, database/modules et database/iam.sql.
PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;
DELETE FROM "api_tokens";
DELETE FROM "iam_rate_limits";
DELETE FROM "iam_audit_logs";
DELETE FROM "iam_sessions";
DELETE FROM "iam_role_permissions";
DELETE FROM "iam_user_site_roles";
DELETE FROM "iam_user_roles";
DELETE FROM "iam_permissions";
DELETE FROM "iam_roles";
DELETE FROM "iam_users";
DELETE FROM "schema_migrations";
INSERT INTO "schema_migrations" ("id", "migration", "migrated_at") VALUES (1, '0001_init.sql', '2026-05-09 06:14:28');
INSERT INTO "schema_migrations" ("id", "migration", "migrated_at") VALUES (2, '0002_admin_security_hardening.sql', '2026-05-09 06:14:28');
INSERT INTO "schema_migrations" ("id", "migration", "migrated_at") VALUES (3, '0003_api_tokens.sql', '2026-05-31 00:00:00');
INSERT INTO "iam_users" ("id", "email", "email_normalized", "password_hash", "first_name", "last_name", "locale", "is_active", "disabled_at", "disabled_reason", "last_login_at", "password_reset_selector", "password_reset_token_hash", "password_reset_expires_at", "password_reset_requested_at", "password_reset_sent_at", "last_password_change_at", "created_at", "updated_at") VALUES (1, 'admin@example.test', 'admin@example.test', '$2y$12$IQ1A5lwkoWrPfypHOTGlT.aPMvbImII5mHeLb/wC4b/DaCmUyvLba', 'Super', 'Admin', 'fr-CH', 1, NULL, NULL, '2026-05-12 13:27:12', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-09 06:14:27', '2026-05-12 15:06:08');
INSERT INTO "iam_users" ("id", "email", "email_normalized", "password_hash", "first_name", "last_name", "locale", "is_active", "disabled_at", "disabled_reason", "last_login_at", "password_reset_selector", "password_reset_token_hash", "password_reset_expires_at", "password_reset_requested_at", "password_reset_sent_at", "last_password_change_at", "created_at", "updated_at") VALUES (2, 'demo.user@example.test', 'demo.user@example.test', '$2y$10$TGEH/AsOFmgvnFnxR3mjUOCy7OElnK14HvD8OEVcWNb4xG5yYrUQi', 'Demo', 'User', 'fr-CH', 1, NULL, NULL, '2026-05-12 18:54:20', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-12 15:06:50', '2026-05-12 18:54:20');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (1, 'super_admin', 'Super admin', 'Accès complet sans restriction fonctionnelle.');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (2, 'admin', 'Admin', 'Administration opérationnelle du CMS hors sécurité système sensible.');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (3, 'editor', 'Éditeur', 'Création et modification des contenus, langue principale incluse.');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (4, 'translator', 'Traducteur', 'Traductions et SEO éditorial simple dans les langues secondaires.');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (5, 'publication', 'Publication', 'Validation finale, publication, dépublication et archivage.');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (6, 'seo', 'SEO', 'SEO éditorial, structurel et avancé.');
INSERT INTO "iam_roles" ("id", "role_key", "name", "description") VALUES (7, 'user', 'Utilisateur', 'Utilisateur final limité à son espace personnel.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (1, '*', 'Toutes les permissions', 'Réservé au super admin.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (2, 'content.read', 'Lire les contenus', 'Lire les entrées éditoriales.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (3, 'content.create', 'Créer des contenus', 'Créer de nouvelles entrées.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (4, 'content.update', 'Modifier des contenus', 'Modifier les brouillons et contenus existants.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (5, 'content.update_primary_language', 'Modifier la langue principale', 'Modifier le contenu source dans la langue principale.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (6, 'content.translate', 'Traduire les contenus', 'Créer et modifier les langues secondaires.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (7, 'content.duplicate', 'Dupliquer des contenus', 'Créer une copie éditoriale.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (8, 'content.delete', 'Supprimer des contenus', 'Supprimer définitivement un contenu selon les règles de gouvernance.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (9, 'content.approve', 'Approuver les contenus', 'Valider avant publication.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (10, 'content.publish', 'Publier les contenus', 'Publier et reconstruire les projections publiques.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (11, 'content.unpublish', 'Dépublier les contenus', 'Retirer un contenu publié.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (12, 'content.archive', 'Archiver les contenus', 'Archiver ou restaurer.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (13, 'content.preview', 'Prévisualiser les contenus', 'Générer des previews authentifiées.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (14, 'content.order', 'Organiser les contenus', 'Ordre, mise en avant, dates et visibilité.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (15, 'media.read', 'Lire les médias', 'Lister les assets média.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (16, 'media.upload', 'Ajouter des médias', 'Téléverser de nouveaux assets.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (17, 'media.update', 'Modifier les médias', 'Métadonnées, alt text et variantes.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (18, 'media.delete', 'Supprimer les médias', 'Suppression contrôlée.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (19, 'taxonomy.read', 'Lire les taxonomies', 'Lire taxonomies et termes.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (20, 'taxonomy.manage', 'Gérer les taxonomies', 'Créer, modifier et organiser les taxonomies.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (21, 'menu.read', 'Lire les menus', 'Lire les menus.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (22, 'menu.manage', 'Gérer les menus', 'Créer et organiser les navigations.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (23, 'fields.read', 'Lire les champs', 'Lire les schémas éditoriaux.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (24, 'fields.manage', 'Gérer les champs', 'Créer et modifier les types de contenus.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (25, 'seo.read', 'Lire le SEO', 'Consulter les champs et diagnostics SEO.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (26, 'seo.simple', 'SEO éditorial simple', 'Meta title, meta description, alt text, titres SEO simples.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (27, 'seo.manage', 'Gérer le SEO', 'Modifier les paramètres SEO autorisés par le rôle.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (28, 'seo.advanced', 'SEO avancé', 'Canonical, robots, indexation, sitemap, structured data.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (29, 'seo.redirects', 'Redirections SEO', 'Redirections, tombstones et règles URL.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (30, 'seo.audit', 'Audit SEO', 'Consulter et exécuter les audits SEO.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (31, 'settings.read', 'Lire les paramètres', 'Lire les réglages fonctionnels.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (32, 'settings.manage', 'Gérer les paramètres fonctionnels', 'Modifier les réglages non sensibles.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (33, 'settings.sensitive', 'Gérer les paramètres sensibles', 'Secrets, sécurité globale et opérations critiques.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (34, 'themes.read', 'Lire les thèmes', 'Consulter les thèmes.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (35, 'themes.manage', 'Gérer les thèmes', 'Configurer les thèmes.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (36, 'modules.read', 'Lire les modules', 'Consulter le catalogue, l’état, les dépendances et le diagnostic des modules.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (37, 'modules.manage', 'Gérer les modules', 'Installer, activer, désactiver, migrer et diagnostiquer les modules.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (38, 'forms.read', 'Lire les formulaires', 'Lire formulaires et soumissions.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (39, 'forms.manage', 'Gérer les formulaires', 'Créer et configurer les formulaires.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (40, 'cookies.read', 'Lire les cookies et consentements', 'Consulter la configuration cookies, les services et les journaux.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (41, 'cookies.manage', 'Gérer les cookies et consentements', 'Configurer la bannière, les catégories, services et scripts soumis à consentement.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (42, 'users.read', 'Lire les utilisateurs', 'Consulter utilisateurs, rôles et accès.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (43, 'users.manage', 'Gérer les utilisateurs', 'Créer, modifier, activer/désactiver et réinitialiser les mots de passe.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (44, 'roles.read', 'Lire les rôles', 'Consulter rôles et permissions.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (45, 'roles.manage', 'Gérer les rôles', 'Créer et modifier les rôles et permissions.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (46, 'sessions.read', 'Lire les sessions', 'Consulter les sessions IAM.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (47, 'sessions.manage', 'Gérer les sessions', 'Révoquer les sessions.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (48, 'audit.read', 'Lire le journal d’audit', 'Consulter les actions sensibles journalisées.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (49, 'maintenance.manage', 'Maintenance', 'Activer maintenance et opérations système critiques.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (50, 'deployments.read', 'Lire les déploiements', 'Lire l’état de release.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (51, 'deployments.manage', 'Gérer les déploiements', 'Préparer, packager et déployer.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (52, 'profile.read', 'Lire son profil', 'Consulter son propre profil.');
INSERT INTO "iam_permissions" ("id", "permission_key", "name", "description") VALUES (53, 'profile.update', 'Modifier son profil', 'Modifier ses données personnelles autorisées.');
INSERT INTO "iam_user_roles" ("id", "user_id", "role_id") VALUES (1, 1, 1);
INSERT INTO "iam_user_roles" ("id", "user_id", "role_id") VALUES (3, 2, 1);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (1, 1, 1);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (2, 1, 48);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (3, 1, 9);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (4, 1, 12);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (5, 1, 3);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (6, 1, 8);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (7, 1, 7);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (8, 1, 14);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (9, 1, 13);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (10, 1, 10);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (11, 1, 2);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (12, 1, 6);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (13, 1, 11);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (14, 1, 4);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (15, 1, 5);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (16, 1, 41);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (17, 1, 40);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (18, 1, 51);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (19, 1, 50);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (20, 1, 24);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (21, 1, 23);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (22, 1, 39);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (23, 1, 38);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (24, 1, 49);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (25, 1, 18);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (26, 1, 15);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (27, 1, 17);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (28, 1, 16);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (29, 1, 22);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (30, 1, 21);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (31, 1, 37);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (32, 1, 36);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (33, 1, 52);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (34, 1, 53);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (35, 1, 45);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (36, 1, 44);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (37, 1, 28);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (38, 1, 30);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (39, 1, 27);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (40, 1, 25);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (41, 1, 29);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (42, 1, 26);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (43, 1, 47);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (44, 1, 46);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (45, 1, 32);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (46, 1, 31);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (47, 1, 33);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (48, 1, 20);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (49, 1, 19);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (50, 1, 35);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (51, 1, 34);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (52, 1, 43);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (53, 1, 42);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (54, 2, 9);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (55, 2, 12);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (56, 2, 3);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (57, 2, 8);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (58, 2, 7);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (59, 2, 14);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (60, 2, 13);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (61, 2, 10);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (62, 2, 2);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (63, 2, 6);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (64, 2, 11);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (65, 2, 4);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (66, 2, 5);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (67, 2, 41);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (68, 2, 40);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (69, 2, 50);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (70, 2, 23);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (71, 2, 39);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (72, 2, 38);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (73, 2, 18);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (74, 2, 15);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (75, 2, 17);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (76, 2, 16);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (77, 2, 22);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (78, 2, 21);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (81, 2, 30);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (82, 2, 27);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (83, 2, 25);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (84, 2, 26);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (85, 2, 32);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (86, 2, 31);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (87, 2, 20);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (88, 2, 19);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (89, 2, 35);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (90, 2, 34);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (91, 3, 3);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (92, 3, 7);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (93, 3, 13);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (94, 3, 2);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (95, 3, 6);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (96, 3, 4);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (97, 3, 5);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (98, 3, 23);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (99, 3, 15);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (100, 3, 17);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (101, 3, 16);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (102, 3, 21);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (103, 3, 25);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (104, 3, 26);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (105, 3, 19);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (106, 4, 13);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (107, 4, 2);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (108, 4, 6);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (109, 4, 23);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (110, 4, 15);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (111, 4, 25);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (112, 4, 26);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (113, 4, 19);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (114, 5, 9);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (115, 5, 12);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (116, 5, 14);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (117, 5, 13);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (118, 5, 10);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (119, 5, 2);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (120, 5, 11);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (121, 5, 23);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (122, 5, 15);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (123, 5, 21);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (124, 5, 30);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (125, 5, 25);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (126, 5, 19);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (127, 6, 13);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (128, 6, 2);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (129, 6, 23);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (130, 6, 15);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (131, 6, 17);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (132, 6, 21);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (133, 6, 28);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (134, 6, 30);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (135, 6, 27);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (136, 6, 25);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (137, 6, 29);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (138, 6, 26);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (139, 6, 19);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (140, 7, 52);
INSERT INTO "iam_role_permissions" ("id", "role_id", "permission_id") VALUES (141, 7, 53);
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (1, 1, 'e9be880f588ff471e350a27f4a7c814ae7e49e8ff2deda32877e5b744cd3ee0c', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 06:30:51', '2026-05-09 18:30:49', '2026-05-09 06:30:49');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (2, 1, '51b234bfa9f5f45e43e1f9c020455107838d4952445f607e52b23faea945a37d', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 09:47:04', '2026-05-09 21:38:46', '2026-05-09 09:38:46');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (3, 1, '60172f7d914b9a6519d318b9fa75d0dbeb130aac83aded4a3fe267b08b529141', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 19:36:43', '2026-05-10 07:33:03', '2026-05-09 19:33:03');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (5, 1, '150a9bc62775f7818653ca5d0e005d130b72b7f0991db9a678352170506849a5', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 04:44:18', '2026-05-10 07:52:10', '2026-05-09 19:52:10');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (6, 1, '2994d674e6d55ddf4c19f0162f6820010d9380c4593c0e5855551a2ea652ef02', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 04:54:10', '2026-05-10 16:49:06', '2026-05-10 04:49:06');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (7, 1, '3c35f94e2ffe9d2bae9fe20150cb2036f592565e03b70d039edf19c03561b25e', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-12 18:53:53', '2026-05-12 22:53:51', '2026-05-12 10:53:51');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (8, 1, '28717d92ab3be270d3a7009f3cec41beedb2289d44eac87fe49898659e0e4aca', '160.53.247.168', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 13:10:30', '2026-05-13 00:15:52', '2026-05-12 12:15:52');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (9, 1, '8279d9eb0037e5485d61efd37c979afed77eac87dbb068d8ccc401dc4145b309', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 13:20:02', '2026-05-13 01:17:08', '2026-05-12 13:17:08');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (10, 1, 'ba4f72049438b9b7183c5ca0f815acdd14b504e7de0ac585ebaefab38dbc2d67', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:12:05', '2026-05-13 01:27:12', '2026-05-12 13:27:12');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (13, 2, '464d5e58d33b5687dfc0acf796b69f7e135178980cf3afd17d950a43f2a7081d', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:38:22', '2026-05-13 03:24:27', '2026-05-12 15:24:27');
INSERT INTO "iam_sessions" ("id", "user_id", "session_token_hash", "ip_address", "user_agent", "last_seen_at", "expires_at", "created_at") VALUES (14, 2, '229ad7948d6b6066ddbec98b0809b5c75a5848debaaace64fe5498b9e645966c', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-12 19:33:07', '2026-05-13 06:54:20', '2026-05-12 18:54:20');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (1, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 06:30:49');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (2, NULL, 'auth.login_failed', 'iam_user', NULL, '{"email":"admin@example.test","ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 09:38:36');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (3, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 09:38:46');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (4, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 19:33:03');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (5, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 19:43:37');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (6, 1, 'auth.logout', 'iam_user', 1, '[]', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 19:52:08');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (7, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-09 19:52:10');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (8, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 04:49:06');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (9, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 05:35:08');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (10, 1, 'auth.logout', 'iam_user', 1, '[]', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 05:43:39');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (11, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 05:46:01');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (12, 1, 'auth.logout', 'iam_user', 1, '[]', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-10 05:59:49');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (13, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-12 10:53:51');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (14, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"160.53.247.168"}', '160.53.247.168', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 12:15:52');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (15, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"160.53.247.167"}', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 13:17:08');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (16, 1, 'auth.login_success', 'iam_user', 1, '{"ip":"160.53.247.167"}', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 13:27:12');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (17, 1, 'auth.profile_updated', 'iam_user', 1, '[]', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:06:08');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (18, 1, 'iam.user.created', 'iam_user', 2, '{"email":"demo.user@example.test"}', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:06:50');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (19, 1, 'iam.user.updated', 'iam_user', 2, '[]', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:06:56');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (20, 2, 'auth.login_success', 'iam_user', 2, '{"ip":"160.53.247.167"}', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:13:02');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (21, 2, 'auth.logout', 'iam_user', 2, '[]', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:14:40');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (22, 2, 'auth.login_success', 'iam_user', 2, '{"ip":"160.53.247.167"}', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:15:21');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (23, 2, 'auth.logout', 'iam_user', 2, '[]', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:17:45');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (24, 2, 'auth.login_success', 'iam_user', 2, '{"ip":"160.53.247.167"}', '160.53.247.167', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-12 15:24:27');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (25, NULL, 'auth.login_failed', 'iam_user', NULL, '{"email":"admin@example.test","ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-12 18:54:11');
INSERT INTO "iam_audit_logs" ("id", "actor_user_id", "action_key", "resource_type", "resource_id", "context_json", "ip_address", "user_agent", "created_at") VALUES (26, 2, 'auth.login_success', 'iam_user', 2, '{"ip":"2a02:1210:5c8e:c00:78c3:6251:7034:7b62"}', '2a02:1210:5c8e:c00:78c3:6251:7034:7b62', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-12 18:54:20');
INSERT INTO "iam_rate_limits" ("id", "rate_key", "created_at") VALUES (8, 'c34505f08a35a801de387610df9e8d85bb9d4e6cc9c3cbdb3602c372dbde9ce0', '2026-05-12 18:54:11');
DELETE FROM "sqlite_sequence";
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_audit_logs', 26);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_permissions', 53);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_rate_limits', 9);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_role_permissions', 141);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_roles', 7);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_sessions', 14);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_user_roles', 3);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('iam_users', 2);
INSERT INTO "sqlite_sequence" ("name", "seq") VALUES ('schema_migrations', 2);
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('content.html_raw.manage', 'Gérer le HTML brut', 'Autoriser la création et la publication de blocs HTML brut non filtré.');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id FROM "iam_roles" r JOIN "iam_permissions" p ON p.permission_key='content.html_raw.manage' WHERE r.role_key='super_admin';

INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('blueprints.read', 'Lire les blueprints', 'Consulter les blueprints, modèles éditoriaux et fieldsets.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('blueprints.manage', 'Gérer les blueprints', 'Créer, modifier, versionner, activer ou supprimer les blueprints et fieldsets.');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id FROM "iam_roles" r JOIN "iam_permissions" p ON p.permission_key IN ('blueprints.read','blueprints.manage') WHERE r.role_key IN ('super_admin','admin');

INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('security.tokens.read', 'Lire les tokens API', 'Consulter les tokens API du site sélectionné.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('security.tokens.manage', 'Gérer les tokens API', 'Créer, modifier, activer, désactiver et supprimer les tokens API du site sélectionné.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('security.webhooks.read', 'Lire les webhooks', 'Consulter les webhooks de publication du site sélectionné.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('security.webhooks.manage', 'Gérer les webhooks', 'Créer, modifier, activer, désactiver et supprimer les webhooks de publication.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('security.cors.read', 'Lire le CORS par site', 'Consulter les origines CORS autorisées pour l’API headless du site.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('security.cors.manage', 'Gérer le CORS par site', 'Modifier les origines CORS autorisées pour l’API headless du site.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('users.email_2fa.manage', 'Gérer les modes de connexion IAM', 'Modifier le mode de connexion password, email_code ou totp depuis la fiche utilisateur.');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id FROM "iam_roles" r JOIN "iam_permissions" p ON p.permission_key IN ('security.tokens.read','security.tokens.manage','security.webhooks.read','security.webhooks.manage','security.cors.read','security.cors.manage','users.email_2fa.manage') WHERE r.role_key='super_admin';


-- Editorial revisions: editors can save working revisions without publication rights.
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('content.revisions.save', 'Enregistrer une révision', 'Créer ou mettre à jour une révision de travail sans publier.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('content.revisions.restore', 'Restaurer une révision', 'Restaurer une ancienne révision comme révision de travail sans publier.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('content.revisions.prune', 'Nettoyer les révisions', 'Supprimer les révisions obsolètes avant la version publiée.');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id FROM "iam_roles" r JOIN "iam_permissions" p ON p.permission_key IN ('content.revisions.save','content.revisions.restore','content.revisions.prune') WHERE r.role_key IN ('super_admin','admin');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id FROM "iam_roles" r JOIN "iam_permissions" p ON p.permission_key='content.revisions.save' WHERE r.role_key IN ('editor','translator');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id FROM "iam_roles" r JOIN "iam_permissions" p ON p.permission_key IN ('content.revisions.restore','content.revisions.prune') WHERE r.role_key='publication';



-- Assistant IA: permissions IAM officielles du module optionnel ai-assistant.
-- Le module applicatif peut rester désactivé ; ces permissions doivent exister
-- pour que les contrôleurs admin et validateurs IAM restent cohérents.
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.use', 'Utiliser l’IA', 'Accéder aux fonctions IA non destructives et à la configuration visible du module Assistant IA.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.provider.manage', 'Gérer les providers IA', 'Configurer, activer, désactiver et tester les fournisseurs IA.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.suggestions.read', 'Lire les suggestions IA', 'Consulter les suggestions proposées par le module Assistant IA.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.suggestions.manage', 'Gérer les suggestions IA', 'Accepter, rejeter, archiver ou marquer comme appliquées les suggestions IA.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.content.suggest', 'Suggestions IA de contenu', 'Demander des suggestions IA liées aux contenus sans publication automatique.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.content.draft', 'Préparer des brouillons IA', 'Préparer des brouillons via IA en passant par les capabilities officielles du core.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.seo.suggest', 'Suggestions SEO IA', 'Demander des suggestions IA pour les métadonnées et diagnostics SEO.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.translation.suggest', 'Suggestions de traduction IA', 'Demander des suggestions IA de traduction lorsque le site l’autorise.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.actions.apply', 'Appliquer des actions IA', 'Appliquer une suggestion IA via dry-run, confirmation, permissions et journalisation.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('ai.logs.read', 'Lire les journaux IA', 'Consulter les journaux d’usage et d’actions IA sans exposer les secrets.');

INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id
FROM "iam_roles" r
JOIN "iam_permissions" p ON p.permission_key IN (
    'ai.use', 'ai.provider.manage', 'ai.suggestions.read', 'ai.suggestions.manage',
    'ai.content.suggest', 'ai.content.draft', 'ai.seo.suggest', 'ai.translation.suggest',
    'ai.actions.apply', 'ai.logs.read'
)
WHERE r.role_key IN ('super_admin','admin');


-- Export statique: capacité technique de publication réservée par défaut aux super admins et admins.
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('imports_exports.read', 'Lire l’historique des imports et exports', 'Accéder à la page Imports / Exports et consulter uniquement l’historique filesystem.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('imports_exports.write', 'Créer et télécharger les exports', 'Créer, relancer et télécharger les exports statiques.');
INSERT OR IGNORE INTO "iam_permissions" ("permission_key", "name", "description") VALUES ('imports_exports.manage', 'Gérer les imports et exports', 'Inclut les opérations d’écriture et permet de supprimer ou nettoyer les releases.');
INSERT OR IGNORE INTO "iam_role_permissions" ("role_id", "permission_id")
SELECT r.id, p.id
FROM "iam_roles" r
JOIN "iam_permissions" p ON p.permission_key IN ('imports_exports.read','imports_exports.write','imports_exports.manage')
WHERE r.role_key IN ('super_admin','admin');

COMMIT;
PRAGMA foreign_keys = ON;
