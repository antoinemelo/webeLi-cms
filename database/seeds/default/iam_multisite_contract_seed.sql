-- Seed IAM pour verifier les permissions par site.
PRAGMA foreign_keys = ON;
BEGIN TRANSACTION;
DELETE FROM iam_user_site_roles WHERE user_id IN (10,11);
DELETE FROM iam_user_roles WHERE user_id IN (10,11);
DELETE FROM iam_users WHERE id IN (10,11);
INSERT INTO iam_users(id, email, email_normalized, password_hash, first_name, last_name, locale, is_active, created_at, updated_at) VALUES(10,'editor.sitea@example.test','editor.sitea@example.test','$2y$12$IQ1A5lwkoWrPfypHOTGlT.aPMvbImII5mHeLb/wC4b/DaCmUyvLba','Editor','Site A','fr-CH',1,'2026-05-16 12:00:00','2026-05-16 12:00:00');
INSERT INTO iam_users(id, email, email_normalized, password_hash, first_name, last_name, locale, is_active, created_at, updated_at) VALUES(11,'editor.siteb@example.test','editor.siteb@example.test','$2y$12$IQ1A5lwkoWrPfypHOTGlT.aPMvbImII5mHeLb/wC4b/DaCmUyvLba','Editor','Site B','fr-CH',1,'2026-05-16 12:00:00','2026-05-16 12:00:00');
INSERT INTO iam_user_site_roles(user_id, site_id, role_id, created_at) SELECT 10, 10, id, '2026-05-16 12:00:00' FROM iam_roles WHERE role_key='editor';
INSERT INTO iam_user_site_roles(user_id, site_id, role_id, created_at) SELECT 11, 11, id, '2026-05-16 12:00:00' FROM iam_roles WHERE role_key='editor';
COMMIT;
