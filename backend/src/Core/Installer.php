<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Installer
{
    public static function ensure(Database $coreDb, Database $iamDb, ?Logger $logger = null, ?Database $formsDb = null, ?Database $cookiesDb = null): void
    {
        self::ensureCoreSchema($coreDb->pdo(), $logger);
        if ($formsDb !== null) {
            self::ensureFormsSchema($formsDb->pdo(), $logger);
        }
        if ($cookiesDb !== null) {
            self::ensureCookiesSchema($cookiesDb->pdo(), $logger);
        }

        $appliedCore = (new Migrator($coreDb->pdo(), base_path('database/migrations/core')))->migrate();
        $appliedIam = (new Migrator($iamDb->pdo(), base_path('database/migrations/iam')))->migrate();
        if ($logger) {
            if ($appliedCore) {
                $logger->info('migrations.applied', ['db' => 'core', 'migrations' => $appliedCore]);
            }
            if ($appliedIam) {
                $logger->info('migrations.applied', ['db' => 'iam', 'migrations' => $appliedIam]);
            }
        }
        self::ensureSeeds($coreDb, $iamDb, $logger);
    }

    private static function ensureFormsSchema(PDO $pdo, ?Logger $logger): void
    {
        $hasSchema = (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='forms' LIMIT 1")->fetchColumn();
        if ($hasSchema) { return; }
        $sql = trim((string) file_get_contents(base_path('database/modules/forms.sql')));
        if ($sql === '') { throw new \RuntimeException('Schema forms introuvable ou vide: database/modules/forms.sql'); }
        $pdo->exec($sql);
        $logger?->info('schema.forms.installed', ['path' => 'database/modules/forms.sql']);
    }

    private static function ensureCookiesSchema(PDO $pdo, ?Logger $logger): void
    {
        $hasSchema = (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cookie_banner_settings' LIMIT 1")->fetchColumn();
        if ($hasSchema) { return; }
        $sql = trim((string) file_get_contents(base_path('database/modules/cookies.sql')));
        if ($sql === '') { throw new \RuntimeException('Schema cookies introuvable ou vide: database/modules/cookies.sql'); }
        $pdo->exec($sql);
        $logger?->info('schema.cookies.installed', ['path' => 'database/modules/cookies.sql']);
    }

    private static function ensureCoreSchema(PDO $pdo, ?Logger $logger): void
    {
        $hasCoreSchema = (bool) $pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sites' LIMIT 1")
            ->fetchColumn();

        if ($hasCoreSchema) {
            return;
        }

        $schemaPath = base_path('database/schema/core.sql');
        $sql = trim((string) file_get_contents($schemaPath));
        if ($sql === '') {
            throw new \RuntimeException('Schema core introuvable ou vide: ' . $schemaPath);
        }

        $pdo->exec($sql);
        $logger?->info('schema.core.installed', ['path' => 'database/schema/core.sql']);
    }

    private static function ensureSeeds(Database $coreDb, Database $iamDb, ?Logger $logger): void
    {
        $corePdo = $coreDb->pdo();
        $iamPdo = $iamDb->pdo();

        $hasSites = (int) $corePdo->query('SELECT COUNT(*) FROM sites')->fetchColumn();
        if ($hasSites === 0) {
            $sql = trim((string) file_get_contents(base_path('database/seeds/core_seed.sql')));
            if ($sql !== '') {
                $corePdo->exec($sql);
            }
            self::seedDemoContent($corePdo);
            $logger?->info('seed.core.done');
        }

        $hasUsers = (int) $iamPdo->query('SELECT COUNT(*) FROM iam_users')->fetchColumn();
        if ($hasUsers === 0) {
            $sql = trim((string) file_get_contents(base_path('database/seeds/iam_seed.sql')));
            if ($sql !== '') {
                $iamPdo->exec($sql);
            }
            $stmt = $iamPdo->prepare('INSERT INTO iam_users(email, email_normalized, password_hash, first_name, last_name, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)');
            $now = now_utc();
            $stmt->execute(['admin@example.test', 'admin@example.test', password_hash('admin123', PASSWORD_DEFAULT), 'Admin', 'User', 1, $now, $now]);
            $userId = (int) $iamPdo->lastInsertId();
            $roleId = (int) $iamPdo->query("SELECT id FROM iam_roles WHERE role_key='super_admin' LIMIT 1")->fetchColumn();
            if ($roleId > 0) {
                $roleStmt = $iamPdo->prepare('INSERT OR IGNORE INTO iam_user_roles(user_id, role_id) VALUES(?, ?)');
                $roleStmt->execute([$userId, $roleId]);
            }
            $logger?->info('seed.iam.done');
        }
    }

    private static function seedDemoContent(PDO $pdo): void
    {
        $siteId = (int) $pdo->query("SELECT id FROM sites WHERE site_key='main' LIMIT 1")->fetchColumn();
        $pageTypeId = (int) $pdo->query("SELECT id FROM content_types WHERE type_key='page' LIMIT 1")->fetchColumn();
        $articleTypeId = (int) $pdo->query("SELECT id FROM content_types WHERE type_key='article' LIMIT 1")->fetchColumn();
        $now = now_utc();

        $menuStmt = $pdo->prepare('INSERT INTO menus(site_id, menu_key, name, menu_location, language_mode, is_active) VALUES(?,?,?,?,?,1)');
        $menuStmt->execute([$siteId, 'primary', 'Navigation principale', 'header', 'shared']);
        $menuId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO menu_items(menu_id, manual_url, sort_order, is_active) VALUES(?,?,?,1)');
        $itemLocStmt = $pdo->prepare('INSERT INTO menu_item_localizations(menu_item_id, language_code, label) VALUES(?,?,?)');
        foreach ([['Accueil', '/'], ['Articles', '/articles/seo-multisite'], ['Contact', '/contact']] as $idx => $item) {
            $itemStmt->execute([$menuId, $item[1], $idx + 1]);
            $itemLocStmt->execute([(int) $pdo->lastInsertId(), 'fr', $item[0]]);
        }

        $taxonomyId = (int) $pdo->query("SELECT id FROM taxonomies WHERE site_id = {$siteId} AND taxonomy_key='categories' LIMIT 1")->fetchColumn();
        if ($taxonomyId === 0) {
            $taxStmt = $pdo->prepare('INSERT INTO taxonomies(site_id, taxonomy_key, name, description, is_hierarchical, is_localized, seo_enabled, archive_enabled, sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
            $taxStmt->execute([$siteId, 'categories', 'Catégories', 'Classement éditorial', 1, 1, 1, 1, 1]);
            $taxonomyId = (int) $pdo->lastInsertId();
        }
        $termStmt = $pdo->prepare('INSERT INTO taxonomy_terms(taxonomy_id, term_key, is_active, sort_order) VALUES(?,?,1,?)');
        $termLocStmt = $pdo->prepare('INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ([['seo', 'SEO', 'seo'], ['cms', 'CMS', 'cms'], ['multisite', 'Multi-site', 'multi-site']] as $idx => $term) {
            $termStmt->execute([$taxonomyId, $term[0], $idx + 1]);
            $termId = (int) $pdo->lastInsertId();
            $termLocStmt->execute([$siteId, $taxonomyId, $termId, 'fr', $term[1], $term[2], '/categories/' . $term[2], $term[1], $term[1], $term[1]]);
        }

        $insert = function (string $type, string $entryKey, string $title, string $slug, string $summary, string $body) use ($pdo, $siteId, $pageTypeId, $articleTypeId, $now): int {
            $contentTypeId = $type === 'article' ? $articleTypeId : $pageTypeId;
            $stmt = $pdo->prepare('INSERT INTO content_entries(site_id, content_type_id, entry_key, status, workflow_state, created_at, updated_at, published_at) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$siteId, $contentTypeId, $entryKey, 'published', 'published', $now, $now, $now]);
            $entryId = (int) $pdo->lastInsertId();
            $doc = json_encode([
                'schema_version' => 1,
                'entry_id' => $entryId,
                'language_code' => 'fr',
                'content' => ['title' => $title, 'slug' => $slug, 'blocks' => [['id' => $entryKey . '_body_01', 'type' => 'markdown', 'enabled' => true, 'editorial_status' => 'published', 'data' => ['text' => $body], 'sort_order' => 0]]],
                'blocks' => [['id' => $entryKey . '_body_01', 'type' => 'markdown', 'enabled' => true, 'editorial_status' => 'published', 'data' => ['text' => $body], 'sort_order' => 0]],
                'seo' => ['meta_title' => $title, 'meta_description' => $summary, 'meta_robots' => 'index,follow'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $checksum = hash('sha256', $doc);
            $revStmt = $pdo->prepare('INSERT INTO revisions(resource_type, resource_id, revision_number, language_code, workflow_status, document_json, checksum_sha256, revision_label, summary, created_at, updated_at, published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $revStmt->execute(['content_entry', $entryId, 1, 'fr', 'published', $doc, $checksum, 'Initial', $summary, $now, $now, $now]);
            $revId = (int) $pdo->lastInsertId();
            $locStmt = $pdo->prepare('INSERT INTO content_entry_localizations(entry_id, language_code, title, draft_slug, draft_status, admin_cache_published_at, updated_at) VALUES(?,?,?,?,?,?,?)');
            $locStmt->execute([$entryId, 'fr', $title, $slug, 'ready', $now, $now]);
            $pdo->prepare("INSERT INTO content_entry_working_revisions(site_id, entry_id, language_code, working_revision_id, workflow_status, updated_by_iam_user_id, created_at, updated_at) VALUES(?, ?, 'fr', ?, 'published', NULL, ?, ?)")->execute([$siteId, $entryId, $revId, $now, $now]);
            $pdo->prepare("INSERT INTO content_entry_publications(site_id, entry_id, language_code, published_revision_id, workflow_status, published_by_iam_user_id, published_at, updated_at) VALUES(?, ?, 'fr', ?, 'published', NULL, ?, ?)")->execute([$siteId, $entryId, $revId, $now, $now]);
            return $entryId;
        };

        $insert('page', 'home', 'Accueil', 'home', 'Bienvenue sur le runtime Mod2.', 'Cette base démontre un CMS éditorial SEO-first, multilingue, multi-site et Twig-ready.');
        $insert('page', 'contact', 'Contact', 'contact', 'Contactez l’équipe.', 'Cette page démontre un contenu de type page publié via projections.');
        $articleId = $insert('article', 'seo-multisite', 'SEO, multilingue et multi-site', 'seo-multisite', 'Article de démonstration.', 'Le CMS repose sur des révisions comme source de vérité et sur des projections publiées pour les routes, la recherche, les archives taxonomiques et le SEO.');

        $termId = (int) $pdo->query("SELECT tt.id FROM taxonomy_terms tt JOIN taxonomies t ON t.id=tt.taxonomy_id WHERE t.taxonomy_key='categories' AND tt.term_key='seo' LIMIT 1")->fetchColumn();
        if ($termId > 0) {
            $stmt = $pdo->prepare('INSERT INTO content_entry_taxonomy_terms(entry_id, term_id, sort_order) VALUES(?,?,1)');
            $stmt->execute([$articleId, $termId]);
        }

        foreach ($pdo->query("SELECT ce.id, ce.site_id, ct.type_key, cep.language_code, cep.published_revision_id, r.checksum_sha256, r.document_json, r.published_at FROM content_entries ce JOIN content_types ct ON ct.id = ce.content_type_id JOIN content_entry_publications cep ON cep.site_id = ce.site_id AND cep.entry_id = ce.id AND cep.workflow_status = 'published' JOIN revisions r ON r.id = cep.published_revision_id AND r.workflow_status = 'published'") as $row) {
            $document = json_decode((string) $row['document_json'], true) ?: [];
            $content = is_array($document['content'] ?? null) ? $document['content'] : [];
            $seo = is_array($document['seo'] ?? null) ? $document['seo'] : [];
            $slug = (string) ($content['slug'] ?? 'item');
            $title = (string) ($content['title'] ?? '');
            $blocksText = trim(strip_tags(json_encode($document['blocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
            $summary = (string) ($seo['meta_description'] ?? ($blocksText !== '' ? mb_substr($blocksText, 0, 220) : ''));
            $path = $row['type_key'] === 'page' ? (($slug === 'home' || $slug === 'accueil') ? '/' : '/' . ltrim($slug, '/')) : '/' . $row['type_key'] . 's/' . ltrim($slug, '/');
            $sourceRevisionId = (int) $row['published_revision_id'];
            $sourceChecksum = (string) $row['checksum_sha256'];
            $seoJson = json_encode([
                'meta_title' => (string) ($seo['meta_title'] ?? $title),
                'meta_description' => (string) ($seo['meta_description'] ?? $summary),
                'meta_robots' => (string) ($seo['meta_robots'] ?? 'index,follow'),
                'canonical_url' => $path,
                'seo_score' => 88,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $pdo->prepare("INSERT INTO public_content_snapshots(site_id, language_code, resource_type, resource_id, route_path, title, slug, document_json, seo_json, source_published_revision_id, source_revision_checksum_sha256, published_at, projected_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([(int) $row['site_id'], (string) $row['language_code'], 'content_entry', (int) $row['id'], $path, $title, $slug, (string) $row['document_json'], $seoJson, $sourceRevisionId, $sourceChecksum, (string) ($row['published_at'] ?? $now), $now]);
            $stmt = $pdo->prepare("INSERT INTO routes(site_id, language_code, resource_type, resource_id, route_type, slug, full_path, is_primary, is_canonical, status, source_published_revision_id, source_revision_checksum_sha256, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([(int) $row['site_id'], (string) $row['language_code'], 'content_entry', (int) $row['id'], 'content', $slug, $path, 1, 1, 'active', $sourceRevisionId, $sourceChecksum, $now, $now]);
            $stmt = $pdo->prepare("INSERT INTO search_documents(site_id, resource_type, resource_id, language_code, path, title, summary, search_text, source_published_revision_id, source_revision_checksum_sha256, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([(int) $row['site_id'], 'content_entry', (int) $row['id'], (string) $row['language_code'], $path, $title, $summary, trim($title . ' ' . $summary . ' ' . $blocksText), $sourceRevisionId, $sourceChecksum, $now]);
            $stmt = $pdo->prepare("INSERT INTO seo_metadata(site_id, resource_type, resource_id, language_code, meta_title, meta_description, meta_robots, canonical_url, og_title, og_description, twitter_title, twitter_description, seo_score, source_published_revision_id, source_revision_checksum_sha256, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([(int) $row['site_id'], 'content_entry', (int) $row['id'], (string) $row['language_code'], (string) ($seo['meta_title'] ?? $title), (string) ($seo['meta_description'] ?? $summary), (string) ($seo['meta_robots'] ?? 'index,follow'), $path, (string) ($seo['meta_title'] ?? $title), (string) ($seo['meta_description'] ?? $summary), (string) ($seo['meta_title'] ?? $title), (string) ($seo['meta_description'] ?? $summary), 88, $sourceRevisionId, $sourceChecksum, $now]);
        }
        foreach ($pdo->query("SELECT t.site_id, t.taxonomy_key, tt.id AS term_id, ttl.language_code, ttl.slug, ttl.full_path FROM taxonomies t JOIN taxonomy_terms tt ON tt.taxonomy_id=t.id JOIN taxonomy_term_localizations ttl ON ttl.site_id = t.site_id AND ttl.taxonomy_id = t.id AND ttl.term_id=tt.id") as $row) {
            $path = $row['full_path'] ?: '/' . $row['taxonomy_key'] . '/' . $row['slug'];
            $stmt = $pdo->prepare("INSERT INTO routes(site_id, language_code, resource_type, resource_id, route_type, slug, full_path, is_primary, is_canonical, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([(int) $row['site_id'], (string) $row['language_code'], 'taxonomy_term', (int) $row['term_id'], 'taxonomy', (string) $row['slug'], $path, 1, 1, 'active', $now, $now]);
        }
    }
}
