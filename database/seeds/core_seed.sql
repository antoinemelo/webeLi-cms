INSERT INTO languages(code, name, native_name, locale, is_default, sort_order) VALUES
('fr', 'French', 'Français', 'fr-CH', 1, 1),
('en', 'English', 'English', 'en-GB', 0, 2),
('de', 'German', 'Deutsch', 'de-CH', 0, 3);

INSERT INTO sites(site_key, name, default_language_code) VALUES
('main', 'Main Site', 'fr');


INSERT INTO site_languages(site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, sort_order)
SELECT s.id, 'fr', 'fr-CH', '', 'fr-CH', NULL, 1, 1, 1 FROM sites s WHERE s.site_key='main';
INSERT INTO site_languages(site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, sort_order)
SELECT s.id, 'en', 'en-GB', '/en', 'en-GB', 'fr', 0, 1, 2 FROM sites s WHERE s.site_key='main';
INSERT INTO site_languages(site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, sort_order)
SELECT s.id, 'de', 'de-CH', '/de', 'de-CH', 'fr', 0, 1, 3 FROM sites s WHERE s.site_key='main';
INSERT INTO site_domains(site_id, host, base_path, scheme, is_primary, is_active, enforce_https)
SELECT id, 'webe.li', '', 'https', 1, 1, 1 FROM sites WHERE site_key='main';

INSERT INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,route_prefix,is_default,status)
SELECT 3,s.id,d.id,'/',1,'active'
FROM sites s JOIN site_domains d ON d.site_id=s.id AND d.is_primary=1
WHERE s.site_key='main';

-- La boutique de démonstration est explicitement publiée et activée en français.
-- Les autres langues restent inactives jusqu'à une action dans Ventes > Réglages > E-Commerce.
INSERT INTO cms_shop_configurations(
    site_id,language_code,channel_id,channel_code,status,currency,route_path,theme_key,menu_key,menu_label,menu_position,
    cart_visible,show_quantities,last_available_threshold,draft_json,published_json,config_version,published_version,
    activated_at,published_at,last_rebuild_at
)
SELECT s.id,'fr',3,'web-main','active','CHF','/shop','default','main','Boutique',100,
       1,0,1,
       '{"title":"Boutique","introduction":"Découvrez nos produits.","seo":{"title":"Boutique","description":"Catalogue de la boutique","robots":"index,follow"},"sections":[{"key":"search","enabled":true,"title":"Recherche, filtres et tri"},{"key":"groups","enabled":true,"title":"Groupes de produits"},{"key":"promotions","enabled":true,"title":"Meilleures promotions"},{"key":"collections","enabled":true,"title":"Explorer les catégories"},{"key":"popular","enabled":true,"title":"Produits populaires"},{"key":"keywords","enabled":true,"title":"Mots-clés populaires"},{"key":"new","enabled":true,"title":"Nouveaux produits"},{"key":"catalog","enabled":true,"title":"Tous les produits"}]}',
       '{"title":"Boutique","introduction":"Découvrez nos produits.","seo":{"title":"Boutique","description":"Catalogue de la boutique","robots":"index,follow"},"sections":[{"key":"search","enabled":true,"title":"Recherche, filtres et tri"},{"key":"groups","enabled":true,"title":"Groupes de produits"},{"key":"promotions","enabled":true,"title":"Meilleures promotions"},{"key":"collections","enabled":true,"title":"Explorer les catégories"},{"key":"popular","enabled":true,"title":"Produits populaires"},{"key":"keywords","enabled":true,"title":"Mots-clés populaires"},{"key":"new","enabled":true,"title":"Nouveaux produits"},{"key":"catalog","enabled":true,"title":"Tous les produits"}]}',
       1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
FROM sites s WHERE s.site_key='main';

INSERT INTO themes(theme_key, name, version, is_default, is_active, config_json) VALUES
('default', 'Default Theme', '2.0.0', 1, 1, '{"path":"frontend/theme-default","supports":{"custom_blocks":true,"seo_meta_preview":true},"appearance_defaults":{"body_font_family":"system","heading_font_family":"system","color_background":"#fbfcfe","color_surface":"#ffffff","color_text":"#102033","color_muted":"#627086","color_primary":"#1b5fc1","color_accent":"#ffb21e","main_heading_font_family":"heading","main_heading_letter_spacing":"normal","show_site_title_in_header":true}}'),
('aurora', 'Aurora Fullscreen', '1.0.0', 0, 1, '{"path":"frontend/theme-aurora","supports":{"custom_blocks":true,"seo_meta_preview":true,"fullscreen_hero":true,"theme_preview":true},"appearance_defaults":{"body_font_family":"system","heading_font_family":"serif","color_background":"#fbf7ef","color_surface":"#fffaf2","color_text":"#162026","color_muted":"#66747d","color_primary":"#13242b","color_accent":"#ef8f4e","main_heading_font_family":"arial","main_heading_letter_spacing":"normal","show_site_title_in_header":true}}'),
('pulse', 'Pulse', '1.0.0', 0, 1, '{"path":"frontend/theme-pulse","supports":{"custom_blocks":true,"seo_meta_preview":true,"fullscreen_hero":true,"theme_preview":true,"visual_editing":true},"appearance_defaults":{"body_font_family":"system","heading_font_family":"system","color_background":"#f6fbff","color_surface":"#ffffff","color_text":"#102033","color_muted":"#5d6f86","color_primary":"#2667ff","color_accent":"#ff6b4a","main_heading_font_family":"arial","main_heading_letter_spacing":"normal","show_site_title_in_header":true}}');

INSERT INTO modules(module_key, name, version, is_system, is_enabled) VALUES
('core', 'Core', '2.0.0', 1, 1),
('pages', 'Pages', '2.0.0', 1, 1),
('articles', 'Articles', '2.0.0', 1, 1),
('seo', 'SEO', '2.0.0', 1, 1),
('taxonomy', 'Taxonomy', '2.0.0', 1, 1),
('media', 'Media', '2.0.0', 1, 1),
('editor', 'Editorial Blocks', '2.0.0', 1, 1),
('forms', 'Formulaires', '1.0.0', 1, 1);

INSERT INTO editor_block_types(block_type, label, category, schema_json, sort_order) VALUES
('markdown', 'Markdown', 'content', '{"fields":["text"],"required":["text"],"search":["text"]}', 10),
('html', 'HTML Bootstrap', 'content', '{"fields":["html"],"required":["html"],"search":["html"]}', 20),
('image', 'Image', 'media', '{"fields":["media_id","src","alt","caption","ratio","loading"],"required_any":[["media_id","src"]],"media":["media_id"],"search":["alt","caption"]}', 30),
('video', 'Vidéo', 'media', '{"fields":["media_id","src","title","poster_media_id","poster","caption","controls"],"required_any":[["media_id","src"]],"media":["media_id","poster_media_id"],"search":["title","caption"]}', 40),
('audio', 'Audio', 'media', '{"fields":["media_id","src","title","caption","controls"],"required_any":[["media_id","src"]],"media":["media_id"],"search":["title","caption"]}', 50),
('iframe', 'Iframe', 'embed', '{"fields":["src","title","height","allow","sandbox"],"required":["src"],"search":["title"]}', 60),
('hero', 'Hero', 'layout', '{"fields":["eyebrow","title","lead","heading_level","layout","image_media_id","image_src","image_alt","image_width","image_height","image_loading","buttons"],"required_any":[["title","lead","image_media_id","image_src","buttons"]],"media":["image_media_id"],"search":["eyebrow","title","lead","image_alt","buttons.label"]}', 70),
('gallery', 'Galerie', 'media', '{"fields":["items","columns"],"required":["items"],"media":["items.media_id"],"search":["items.alt","items.caption"]}', 80),
('buttons', 'Boutons', 'cta', '{"fields":["items"],"required":["items"],"search":["items.label"]}', 90),
('card', 'Carte', 'content', '{"fields":["icon","title","text","url","link_label","style"],"required_any":[["title","text"]],"search":["title","text","link_label"]}', 95),
('columns', 'Colonnes', 'layout', '{"fields":["layout","columns"],"required":["columns"],"layouts":["2","3","cards-3","sidebar-left","sidebar-right"],"search":["columns.blocks"]}', 100),
('form', 'Formulaire', 'module', '{"fields":["form_key","title","intro","layout"],"required":["form_key"],"search":["title","intro","form_key"]}', 110),
('plan', 'Plan', 'navigation', '{"fields":["title","source","taxonomy_key","limit","show_pagination","page_param"],"sources":["pages","articles","taxonomies"],"search":["title"]}', 120),
('articles', 'Articles', 'content', '{"fields":["title","limit","category","tag","show_more_button","more_label"],"search":["title","more_label"]}', 130);

INSERT OR IGNORE INTO editor_block_types(block_type,label,category,schema_json,sort_order) VALUES
('featured_product','Produit vedette','commerce','{"fields":["product_id","product_ids","selection_mode","layout","limit","columns","show_price","show_promotion","show_availability","show_cta","empty_state","empty_message"],"selection_modes":["explicit"],"stable_references":["product_id","product_ids"]}',200),
('product_card','Carte produit','commerce','{"fields":["product_id","product_ids","selection_mode","limit","columns","show_price","show_promotion","show_availability","show_cta","empty_state","empty_message"],"selection_modes":["explicit"],"stable_references":["product_id","product_ids"]}',201),
('product_grid','Liste de produits','commerce','{"fields":["selection_mode","product_ids","brand","category","collection_id","group","attribute_code","attribute_values","promotion_rule","relation_type","source_product_id","manual_product_ids","limit","sort","columns","show_price","show_promotion","show_availability","show_cta","pagination","empty_state","empty_message"],"selection_modes":["explicit","brand","category","group","attribute","promotion","new","popular","relation"],"stable_references":["product_ids","source_product_id","manual_product_ids"],"legacy_aliases":{"collection_id":"category"}}',202),
('collection_grid','Liste de catégories','commerce','{"fields":["collection_ids","columns","limit","empty_state","empty_message"],"stable_references":["collection_ids"]}',203),
('product_detail','Détail produit','commerce','{"fields":["product_id","product_ids","selection_mode","show_price","show_promotion","show_availability","show_cta","empty_state","empty_message"],"selection_modes":["explicit"],"stable_references":["product_id","product_ids"]}',204),
('add_to_cart','Ajout au panier','commerce','{"fields":["product_id","sellable_id","variant_rule","quantity","label"],"required_any":[["product_id","sellable_id"]],"stable_references":["product_id","sellable_id"],"variant_rules":["default_if_unambiguous","exact_sellable"]}',205);

INSERT INTO content_types(module_id, type_key, name, singular_label, plural_label, has_layout, has_taxonomies, frontend_template)
SELECT id, 'page', 'Page', 'Page', 'Pages', 1, 1, 'page.twig' FROM modules WHERE module_key='pages';

INSERT INTO content_types(module_id, type_key, name, singular_label, plural_label, has_layout, has_taxonomies, frontend_template)
SELECT id, 'article', 'Article', 'Article', 'Articles', 1, 1, 'article.twig' FROM modules WHERE module_key='articles';


-- Champs natifs utilisés par l'éditeur d'articles et la page d'index éditoriale.
-- Ils sont masqués du formulaire générique car l'interface dédiée les rend avec
-- un contrôle plus clair, mais ils appartiennent au schéma pour conserver une
-- validation stricte du payload.
INSERT INTO field_groups(content_type_id, group_key, label, tab_key, sort_order)
SELECT id, 'native_article_publication', 'Publication publique', 'content', 20 FROM content_types WHERE type_key='article';
INSERT INTO field_groups(content_type_id, group_key, label, tab_key, sort_order)
SELECT id, 'native_article_index', 'Liste d’articles', 'content', 20 FROM content_types WHERE type_key='page';

INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'display_published_at', 'Date affichée', 'datetime', 'json', 'datetime', 'Date publique utilisée pour le tri et les métadonnées des articles. Rendu dans le panneau Identité éditoriale.', 1, 1, 10
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'author_name', 'Auteur', 'text', 'json', 'text', 'Nom public de l’auteur ou de l’équipe éditoriale. Rendu dans le panneau Identité éditoriale.', 1, 1, 20
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_use_custom_display_settings', 'Personnaliser l’affichage de cet article', 'boolean', 'json', 'toggle', 'Désactivé : l’article suit Configuration > Articles. Activé : les options suivantes surchargent les réglages globaux.', 'false', 1, 1, 30
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_show_published_date', 'Afficher la date de publication', 'boolean', 'json', 'toggle', 'Utilisé uniquement si l’affichage personnalisé de cet article est activé.', 'true', 1, 1, 40
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_show_author', 'Afficher l’auteur', 'boolean', 'json', 'toggle', 'Utilisé uniquement si l’affichage personnalisé de cet article est activé.', 'true', 1, 1, 50
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_show_updated_date', 'Afficher la date de mise à jour', 'boolean', 'json', 'toggle', 'Utilisé uniquement si l’affichage personnalisé de cet article est activé.', 'false', 1, 1, 60
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_show_type', 'Afficher le type de contenu', 'boolean', 'json', 'toggle', 'Utilisé uniquement si l’affichage personnalisé de cet article est activé.', 'true', 1, 1, 70
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, validation_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_date_format', 'Format de date personnalisé', 'select', 'json', 'select', 'Utilisé uniquement si l’affichage personnalisé de cet article est activé.', '"medium"', '{"in":["short","medium","long","iso"]}', 1, 1, 80
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_detail_include_author_in_schema', 'Conserver l’auteur dans le JSON-LD', 'boolean', 'json', 'toggle', 'Utilisé uniquement si l’affichage personnalisé de cet article est activé. Option B : l’auteur peut rester dans les données structurées même s’il est masqué visuellement.', 'true', 1, 1, 90
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_publication'
WHERE ct.type_key='article';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, validation_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_limit', 'Articles par page', 'number', 'json', 'number', 'Nombre d’articles affichés sur la page d’index.', '6', '{"integer":true,"min":1,"max":48}', 1, 1, 10
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_index'
WHERE ct.type_key='page';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, validation_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'tag_limit', 'Tags affichés', 'number', 'json', 'number', 'Nombre maximal de tags proposés dans les filtres.', '12', '{"integer":true,"min":1,"max":50}', 1, 1, 20
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_index'
WHERE ct.type_key='page';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_show_published_date', 'Afficher la date de publication', 'boolean', 'json', 'toggle', 'Contrôle l’affichage de la date sur les cartes d’articles.', 'true', 1, 1, 30
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_index'
WHERE ct.type_key='page';
INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, is_localized, is_hidden, sort_order)
SELECT ct.id, fg.id, 'article_show_author', 'Afficher l’auteur', 'boolean', 'json', 'toggle', 'Contrôle l’affichage de l’auteur sur les cartes d’articles.', 'false', 1, 1, 40
FROM content_types ct JOIN field_groups fg ON fg.content_type_id=ct.id AND fg.group_key='native_article_index'
WHERE ct.type_key='page';

INSERT INTO taxonomies(site_id, taxonomy_key, name, is_hierarchical)
SELECT id, 'categories', 'Categories', 1 FROM sites WHERE site_key='main';

INSERT INTO taxonomies(site_id, taxonomy_key, name, is_hierarchical)
SELECT id, 'tags', 'Tags', 0 FROM sites WHERE site_key='main';

-- Native editorial taxonomy policy: content types explicitly declare which vocabularies they accept.
INSERT INTO content_type_taxonomies(content_type_id, taxonomy_id, is_required, max_terms)
SELECT ct.id, tx.id, 0, 1
FROM content_types ct
JOIN taxonomies tx ON tx.taxonomy_key = 'categories'
WHERE ct.type_key = 'page';

INSERT INTO content_type_taxonomies(content_type_id, taxonomy_id, is_required, max_terms)
SELECT ct.id, tx.id, 1, 1
FROM content_types ct
JOIN taxonomies tx ON tx.taxonomy_key = 'categories'
WHERE ct.type_key = 'article';

INSERT INTO content_type_taxonomies(content_type_id, taxonomy_id, is_required, max_terms)
SELECT ct.id, tx.id, 0, NULL
FROM content_types ct
JOIN taxonomies tx ON tx.taxonomy_key = 'tags'
WHERE ct.type_key IN ('page', 'article');

INSERT INTO taxonomy_terms(taxonomy_id, term_key, sort_order)
SELECT id, 'actualites', 10 FROM taxonomies WHERE taxonomy_key = 'categories';
INSERT INTO taxonomy_terms(taxonomy_id, term_key, sort_order)
SELECT id, 'tutoriels', 20 FROM taxonomies WHERE taxonomy_key = 'categories';
INSERT INTO taxonomy_terms(taxonomy_id, term_key, sort_order)
SELECT id, 'cms', 10 FROM taxonomies WHERE taxonomy_key = 'tags';
INSERT INTO taxonomy_terms(taxonomy_id, term_key, sort_order)
SELECT id, 'seo', 20 FROM taxonomies WHERE taxonomy_key = 'tags';

INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description)
SELECT tx.site_id, tx.id, tt.id, 'fr', 'Actualités', 'actualites', '/categories/actualites', 'Articles et pages d’actualité.', 'Actualités', 'Retrouvez les contenus d’actualité.'
FROM taxonomies tx JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id AND tt.term_key = 'actualites' WHERE tx.taxonomy_key = 'categories';
INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description)
SELECT tx.site_id, tx.id, tt.id, 'fr', 'Tutoriels', 'tutoriels', '/categories/tutoriels', 'Guides pratiques et tutoriels.', 'Tutoriels', 'Guides pratiques et tutoriels.'
FROM taxonomies tx JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id AND tt.term_key = 'tutoriels' WHERE tx.taxonomy_key = 'categories';
INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description)
SELECT tx.site_id, tx.id, tt.id, 'fr', 'CMS', 'cms', '/tags/cms', 'Contenus liés au CMS.', 'CMS', 'Contenus liés au CMS.'
FROM taxonomies tx JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id AND tt.term_key = 'cms' WHERE tx.taxonomy_key = 'tags';
INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description)
SELECT tx.site_id, tx.id, tt.id, 'fr', 'SEO', 'seo', '/tags/seo', 'Contenus liés au référencement naturel.', 'SEO', 'Contenus liés au référencement naturel.'
FROM taxonomies tx JOIN taxonomy_terms tt ON tt.taxonomy_id = tx.id AND tt.term_key = 'seo' WHERE tx.taxonomy_key = 'tags';

INSERT INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description)
SELECT id, 'fr', 'Site principal', 'CMS éditorial SEO-first', 'Publication propre, runtime léger.', ' · Site principal', 'Un CMS éditorial simple, multi-site et multilingue.'
FROM sites WHERE site_key='main';

INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public)
SELECT id, 'seo', 'defaults', '{"robots":"index,follow","sitemap_enabled":true,"canonical_enabled":true,"redirect_to_primary_host":true,"force_https":true,"apple_touch_icon_required":true,"social_share_enabled":true,"single_h1_policy":true,"title_max_length":60,"description_max_length":160}', 1
FROM sites WHERE site_key='main';


INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public)
SELECT id, 'backoffice', 'defaults', '{"admin_ui_language_code":"fr"}', 0
FROM sites WHERE site_key='main';

INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public)
SELECT id, 'public_ui', 'defaults', '{"show_login_shortcut":false,"active_theme_key":"default","body_font_family":"system","heading_font_family":"system","color_background":"#fbfcfe","color_surface":"#ffffff","color_text":"#102033","color_muted":"#627086","color_primary":"#1b5fc1","color_accent":"#ffb21e","show_site_title_in_header":true,"main_heading_font_family":"heading","main_heading_letter_spacing":"normal"}', 1
FROM sites WHERE site_key='main';

INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public)
SELECT id, 'articles', 'defaults', '{"detail_show_published_date":true,"detail_show_author":true,"detail_show_updated_date":false,"detail_show_type":true,"detail_include_author_in_schema":true,"detail_date_format":"medium"}', 1
FROM sites WHERE site_key='main';

INSERT INTO site_preferences(site_id, preference_key, value_json)
SELECT id, 'admin.editor', '{"autosave_seconds":30,"preview_mode":"signed_url"}'
FROM sites WHERE site_key='main';

INSERT INTO global_variables(site_id, variable_key, variable_type, value_json, is_localized, is_public, description)
SELECT id, 'site_claim', 'text', '"CMS éditorial SEO-first"', 1, 1, 'Baseline courte réutilisable dans les templates.'
FROM sites WHERE site_key='main';


-- Variantes natives WebP. Les images sources restent conservées, mais les blocs publics
-- utilisent ces déclinaisons responsives générées côté serveur.
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'content_480', 480, NULL, 'webp', 82, 'fit', 10, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'content_768', 768, NULL, 'webp', 82, 'fit', 20, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'content_1024', 1024, NULL, 'webp', 82, 'fit', 30, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'content_1280', 1280, NULL, 'webp', 82, 'fit', 40, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'content_1600', 1600, NULL, 'webp', 82, 'fit', 50, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'hero_640', 640, NULL, 'webp', 82, 'fit', 110, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'hero_960', 960, NULL, 'webp', 82, 'fit', 120, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'hero_1280', 1280, NULL, 'webp', 82, 'fit', 130, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'hero_1920', 1920, NULL, 'webp', 82, 'fit', 140, 1 FROM sites WHERE site_key='main';
INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
SELECT id, 'og_1200x630', 1200, 630, 'webp', 82, 'crop', 210, 1 FROM sites WHERE site_key='main';

INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public)
SELECT id, 'media', 'defaults', '{"max_upload_mb":12,"allowed_mime_types":["image/jpeg","image/png","image/webp","image/gif","video/mp4","video/webm","audio/mpeg","audio/mp4","audio/ogg","application/pdf"],"auto_generate_variants":true,"variant_sets":{"content":[480,768,1024,1280,1600],"hero":[640,960,1280,1920],"open_graph":["1200x630"]},"require_alt_text":true,"default_folder_key":"general","logo_media_id":null,"favicon_media_id":null,"default_social_image_media_id":null,"social_image_policy":{"required_width":1200,"required_height":630,"variant_key":"og_1200x630"}}', 0
FROM sites WHERE site_key='main';

INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public)
SELECT id, 'relations', 'defaults', '{"allow_cross_type_relations":true,"max_related_items":24,"enable_bidirectional_hints":true,"allowed_relation_types":["related","parent","child","featured_media"],"media_storage":{"driver":"local","s3":{"endpoint":"","bucket":"","region":"us-east-1","access_key":"","secret_key":"","public_base_url":"","path_prefix":""}}}', 0
FROM sites WHERE site_key='main';
-- Templates SEO natifs : rendus au moment de la projection publiée.
INSERT INTO seo_templates(site_id, resource_type, resource_subtype, language_code, meta_title_template, meta_description_template, og_title_template, og_description_template, robots_default, json_ld_template)
SELECT s.id, 'content_entry', NULL, sl.language_code,
       '{{ title }} {{ default_meta_title_suffix }}',
       '{{ summary }}',
       '{{ title }}',
       '{{ summary }}',
       'index,follow',
       NULL
FROM sites s
JOIN site_languages sl ON sl.site_id = s.id
WHERE s.site_key = 'main'
ON CONFLICT(site_id, resource_type, language_code) WHERE resource_subtype IS NULL DO UPDATE SET
    meta_title_template = excluded.meta_title_template,
    meta_description_template = excluded.meta_description_template,
    og_title_template = excluded.og_title_template,
    og_description_template = excluded.og_description_template,
    robots_default = excluded.robots_default,
    json_ld_template = excluded.json_ld_template;

INSERT INTO seo_templates(site_id, resource_type, resource_subtype, language_code, meta_title_template, meta_description_template, og_title_template, og_description_template, robots_default, json_ld_template)
SELECT s.id, 'content_entry', 'article', sl.language_code,
       '{{ title }} {{ default_meta_title_suffix }}',
       '{{ summary }}',
       '{{ title }}',
       '{{ summary }}',
       'index,follow',
       '{"@context":"https://schema.org","@type":"Article","headline":"{{ title }}","description":"{{ summary }}"}'
FROM sites s
JOIN site_languages sl ON sl.site_id = s.id
WHERE s.site_key = 'main'
ON CONFLICT(site_id, resource_type, resource_subtype, language_code) WHERE resource_subtype IS NOT NULL DO UPDATE SET
    meta_title_template = excluded.meta_title_template,
    meta_description_template = excluded.meta_description_template,
    og_title_template = excluded.og_title_template,
    og_description_template = excluded.og_description_template,
    robots_default = excluded.robots_default,
    json_ld_template = excluded.json_ld_template;


INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public) VALUES (1, 'api', 'cors_allowed_origins', '[]', 0);

-- Product 1 is the deterministic first Business demo product (vol-decouverte).
-- The cross-database reference is validated and projected by b0_db_seed.py.
INSERT OR IGNORE INTO business_product_content_links(
    site_id, product_id, content_entry_id, relation_type, locale, is_canonical, status, seo_config_json
)
SELECT 1, 1, ce.id, 'storytelling', NULL, 1, 'active', '{"schema_type":"Service"}'
FROM content_entries ce
WHERE ce.site_id = 1 AND ce.entry_key = 'home'
LIMIT 1;
