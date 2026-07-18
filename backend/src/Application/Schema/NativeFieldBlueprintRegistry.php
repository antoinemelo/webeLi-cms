<?php

declare(strict_types=1);

namespace App\Application\Schema;

/**
 * Native schema registry for the lightweight CMS field/fieldset/blueprint system.
 *
 * This registry deliberately stays code-first as a bootstrap/fallback contract:
 * it gives old installs, validators and seed scripts a stable native definition
 * from which the active v1 tables (`blueprints` + `blueprint_versions` and the
 * normalized `blueprint_sections` / `blueprint_fields` model) are generated.
 *
 * The removed legacy blueprint cache must not be reintroduced. New edition and
 * schema-builder features must not treat it as the editorial source of truth.
 */
final class NativeFieldBlueprintRegistry
{
    /** @return list<array<string,mixed>> */
    public static function fieldTypes(): array
    {
        return [
            self::fieldType('text', 'Text', 'scalar', 'TextField', true, true),
            self::fieldType('markdown', 'Markdown', 'scalar', 'MarkdownField', true, true),
            self::fieldType('bard', 'Bard / rich text', 'json', 'BardField', false, true),
            self::fieldType('integer', 'Integer', 'scalar', 'IntegerField', true, false),
            self::fieldType('toggle', 'Toggle', 'scalar', 'ToggleField', true, false),
            self::fieldType('select', 'Select', 'scalar', 'SelectField', true, false),
            self::fieldType('radio', 'Radio', 'scalar', 'RadioField', true, false),
            self::fieldType('checkboxes', 'Checkboxes', 'json', 'CheckboxesField', true, false),
            self::fieldType('assets', 'Assets', 'json', 'AssetsField', true, false),
            self::fieldType('link', 'Link', 'scalar', 'LinkField', true, true),
            self::fieldType('list', 'List', 'json', 'ListField', true, true),
            self::fieldType('replicator', 'Replicator', 'json', 'ReplicatorField', true, true),
            self::fieldType('color', 'Color', 'scalar', 'ColorField', true, false),
            self::fieldType('date', 'Date', 'scalar', 'DateField', true, false),
            self::fieldType('time', 'Time', 'scalar', 'TimeField', true, false),
            self::fieldType('code', 'Code', 'scalar', 'CodeField', true, false),
            self::fieldType('yaml', 'YAML', 'scalar', 'YamlField', true, false),
            self::fieldType('table', 'Table', 'json', 'TableField', true, true),
            self::fieldType('entries', 'Entries', 'json', 'EntriesField', true, false),
            self::fieldType('taxonomy', 'Taxonomy', 'json', 'TaxonomyField', true, false),
            self::fieldType('video', 'Video', 'json', 'VideoField', true, false),
            self::fieldType('button_group', 'Button Group', 'scalar', 'ButtonGroupField', false, false),
            self::fieldType('range', 'Range', 'scalar', 'RangeField', false, false),
            self::fieldType('revealer', 'Revealer', 'scalar', 'RevealerField', false, false),
            self::fieldType('sites', 'Sites', 'json', 'SitesField', false, false),
            self::fieldType('slug', 'Slug', 'scalar', 'SlugField', true, true),
            self::fieldType('structures', 'Structures', 'json', 'StructuresField', false, false),
            self::fieldType('template', 'Template', 'scalar', 'TemplateField', false, false),
            self::fieldType('users', 'Users', 'json', 'UsersField', false, false),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function fieldTypesByHandle(): array
    {
        $types = [];
        foreach (self::fieldTypes() as $type) {
            $types[(string) $type['handle']] = $type;
        }
        return $types;
    }

    /** @return array<string,array<string,mixed>> */
    public static function fieldsets(): array
    {
        return [
            'button_item' => self::fieldset('button_item', 'Bouton', [[
                'key' => 'main', 'display' => 'Bouton', 'fields' => [
                    self::field('label', 'text', 'Libellé', ['required' => true, 'width' => 50]),
                    self::field('url', 'link', 'Lien', ['required' => true, 'width' => 50, 'validate' => ['url_or_path' => true]]),
                    self::field('style', 'select', 'Style', ['default' => 'primary', 'width' => 50, 'config' => ['options' => self::options(['primary' => 'Principal', 'secondary' => 'Secondaire', 'ghost' => 'Fantôme', 'light' => 'Clair', 'link' => 'Lien'])]]),
                    self::field('target', 'select', 'Cible', ['default' => '_self', 'width' => 50, 'config' => ['options' => self::options(['_self' => 'Même fenêtre', '_blank' => 'Nouvelle fenêtre'])]]),
                ],
            ]]),
            'button_group' => self::fieldset('button_group', 'Groupe de boutons', [[
                'key' => 'main', 'display' => 'Boutons', 'fields' => [
                    self::field('items', 'replicator', 'Boutons', ['config' => ['fieldset' => 'button_item', 'max_sets' => 4]]),
                ],
            ]]),
            'image_fields' => self::fieldset('image_fields', 'Image', [[
                'key' => 'media', 'display' => 'Image', 'fields' => [
                    self::field('media_id', 'assets', 'Image', ['config' => ['kind' => 'image', 'max_files' => 1]]),
                    self::field('src', 'link', 'URL image'),
                    self::field('alt', 'text', 'Texte alternatif', ['localizable' => true]),
                    self::field('caption', 'markdown', 'Légende', ['localizable' => true]),
                ],
            ]]),
            'common_block_settings' => self::fieldset('common_block_settings', 'Réglages communs', [[
                'key' => 'settings', 'display' => 'Réglages', 'fields' => [
                    self::field('label', 'text', 'Libellé interne'),
                    self::field('enabled', 'toggle', 'Actif', ['default' => true]),
                    self::field('editorial_status', 'select', 'État éditorial', ['default' => 'published', 'config' => ['options' => self::options(['draft' => 'Brouillon', 'review' => 'Relecture', 'published' => 'Publié', 'archived' => 'Archivé'])]]),
                    self::field('anchor', 'slug', 'Ancre HTML'),
                    self::field('css_class', 'text', 'Classes CSS'),
                ],
            ]]),
            'layout_options' => self::fieldset('layout_options', 'Options de layout', [[
                'key' => 'layout', 'display' => 'Affichage', 'fields' => [
                    self::field('layout', 'select', 'Disposition'),
                    self::field('gap', 'select', 'Espacement', ['default' => 'md', 'config' => ['options' => self::options(['sm' => 'Petit', 'md' => 'Moyen', 'lg' => 'Grand'])]]),
                    self::field('stack_on_mobile', 'toggle', 'Empiler sur mobile', ['default' => true]),
                ],
            ]]),
            'spacing_options' => self::fieldset('spacing_options', 'Espacements', [[
                'key' => 'spacing', 'display' => 'Espacements', 'fields' => [
                    self::field('padding_top', 'select', 'Marge haute', ['default' => 'md']),
                    self::field('padding_bottom', 'select', 'Marge basse', ['default' => 'md']),
                ],
            ]]),
            'accessibility_fields' => self::fieldset('accessibility_fields', 'Accessibilité', [[
                'key' => 'a11y', 'display' => 'Accessibilité', 'fields' => [
                    self::field('aria_label', 'text', 'Libellé ARIA'),
                    self::field('heading_level', 'select', 'Niveau de titre', ['default' => 'h2', 'config' => ['options' => self::options(['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3'])]]),
                ],
            ]]),
            'seo_fields' => self::fieldset('seo_fields', 'SEO', [[
                'key' => 'seo', 'display' => 'SEO', 'fields' => [
                    self::field('meta_title', 'text', 'Meta title', ['validate' => ['max' => 70]]),
                    self::field('meta_description', 'text', 'Meta description', ['validate' => ['max' => 160]]),
                    self::field('meta_robots', 'select', 'Robots', ['default' => 'index,follow', 'config' => ['options' => self::options(['index,follow' => 'Index, follow', 'noindex,follow' => 'Noindex, follow', 'noindex,nofollow' => 'Noindex, nofollow'])]]),
                ],
            ]]),
            'link_fields' => self::fieldset('link_fields', 'Lien', [[
                'key' => 'main', 'display' => 'Lien', 'fields' => [
                    self::field('label', 'text', 'Libellé'),
                    self::field('url', 'link', 'URL'),
                    self::field('target', 'select', 'Cible', ['default' => '_self', 'config' => ['options' => self::options(['_self' => 'Même fenêtre', '_blank' => 'Nouvelle fenêtre'])]]),
                ],
            ]]),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function blockBlueprints(): array
    {
        return [
            'markdown' => self::makeBlockBlueprint('markdown', 'Markdown', [[
                'key' => 'content', 'display' => 'Contenu', 'fields' => [self::field('text', 'markdown', 'Texte', ['required' => true, 'localizable' => true])],
            ]], ['text' => '']),
            'richtext' => self::makeBlockBlueprint('richtext', 'Texte enrichi', [[
                'key' => 'content', 'display' => 'Contenu', 'fields' => [self::field('html', 'richtext', 'Texte enrichi', ['required' => true, 'localizable' => true])],
            ]], ['html' => '']),
            'html_safe' => self::makeBlockBlueprint('html_safe', 'HTML sûr', [[
                'key' => 'content', 'display' => 'HTML filtré', 'fields' => [self::field('html', 'code', 'HTML filtré', ['required' => true, 'config' => ['language' => 'html', 'security_policy' => 'strict_html_safe']])],
            ]], ['html' => '']),
            'html_raw' => self::makeBlockBlueprint('html_raw', 'HTML brut', [[
                'key' => 'content', 'display' => 'HTML brut', 'fields' => [self::field('html', 'code', 'HTML brut', ['required' => true, 'instructions' => 'Réservé aux rôles disposant de content.html_raw.manage.', 'config' => ['language' => 'html', 'requires_permission' => 'content.html_raw.manage']])],
            ]], ['html' => '']),
            'image' => self::makeBlockBlueprint('image', 'Image', [[
                'key' => 'media', 'display' => 'Image', 'fields' => [
                    self::field('media_id', 'assets', 'Image', ['config' => ['kind' => 'image', 'max_files' => 1]]),
                    self::field('src', 'link', 'URL image'),
                    self::field('alt', 'text', 'Texte alternatif', ['localizable' => true]),
                    self::field('caption', 'markdown', 'Légende', ['localizable' => true]),
                    self::field('ratio', 'select', 'Ratio', ['default' => 'auto', 'config' => ['options' => self::options(['auto' => 'Auto', '1x1' => '1:1', '4x3' => '4:3', '16x9' => '16:9', '21x9' => '21:9'])]]),
                    self::field('loading', 'select', 'Chargement', ['default' => 'lazy', 'config' => ['options' => self::options(['lazy' => 'Lazy', 'eager' => 'Eager'])]]),
                ],
            ]], ['media_id' => 0, 'src' => '', 'alt' => '', 'caption' => '', 'ratio' => 'auto', 'loading' => 'lazy']),
            'hero' => self::makeBlockBlueprint('hero', 'Hero', [[
                'key' => 'content', 'display' => 'Contenu', 'fields' => [
                    self::field('eyebrow', 'text', 'Surtitre', ['localizable' => true]),
                    self::field('title', 'text', 'Titre', ['localizable' => true, 'validate' => ['max' => 140]]),
                    self::field('lead', 'markdown', 'Texte d’introduction', ['localizable' => true]),
                    self::field('buttons', 'replicator', 'Boutons', ['config' => ['fieldset' => 'button_item', 'max_sets' => 3]]),
                ],
            ], [
                'key' => 'media', 'display' => 'Image', 'fields' => [
                    self::field('image_media_id', 'assets', 'Image', ['config' => ['kind' => 'image', 'max_files' => 1]]),
                    self::field('image_src', 'link', 'URL image'),
                    self::field('image_alt', 'text', 'Texte alternatif', ['localizable' => true]),
                ],
            ], [
                'key' => 'layout', 'display' => 'Affichage', 'fields' => [
                    self::field('heading_level', 'select', 'Niveau de titre', ['default' => 'h2', 'config' => ['options' => self::options(['h1' => 'H1', 'h2' => 'H2'])]]),
                    self::field('layout', 'select', 'Disposition', ['default' => 'simple', 'config' => ['options' => self::options(['simple' => 'Simple', 'split' => 'Texte + image', 'cover' => 'Cover'])]]),
                ],
            ]], ['eyebrow' => '', 'title' => '', 'lead' => '', 'heading_level' => 'h2', 'layout' => 'simple', 'image_media_id' => 0, 'image_src' => '', 'image_alt' => '', 'image_width' => 1280, 'image_height' => 853, 'image_loading' => 'eager', 'buttons' => []]),
            'columns' => self::makeBlockBlueprint('columns', 'Colonnes', [[
                'key' => 'layout', 'display' => 'Disposition', 'fields' => [
                    self::field('layout', 'select', 'Disposition', ['default' => '2_equal', 'config' => ['options' => self::options(['2_equal' => '2 colonnes égales', '2_left_wide' => '2 colonnes, gauche large', '2_right_wide' => '2 colonnes, droite large', '3_equal' => '3 colonnes égales', '4_equal' => '4 colonnes égales', 'cards_3' => '3 cartes'])]]),
                    self::field('gap', 'select', 'Espacement', ['default' => 'md', 'config' => ['options' => self::options(['sm' => 'Petit', 'md' => 'Moyen', 'lg' => 'Grand'])]]),
                    self::field('stack_on_mobile', 'toggle', 'Empiler sur mobile', ['default' => true]),
                ],
            ], [
                'key' => 'content', 'display' => 'Colonnes', 'fields' => [
                    self::field('columns', 'replicator', 'Colonnes', ['required' => true, 'validate' => ['min_sets' => 1, 'max_sets' => 4], 'config' => [
                        'set_label_field' => 'label',
                        'fields' => [
                            self::field('label', 'text', 'Nom de la colonne'),
                            self::field('width', 'select', 'Largeur', ['default' => 'auto', 'config' => ['options' => self::options(['auto' => 'Automatique', '25' => '25 %', '33' => '33 %', '50' => '50 %', '66' => '66 %', '75' => '75 %', '100' => '100 %'])]]),
                            self::field('blocks', 'replicator', 'Sous-blocs', ['config' => ['mode' => 'blocks', 'allowed_block_types' => ['markdown', 'richtext', 'html_safe', 'iframe', 'embed', 'card', 'image', 'buttons', 'video', 'form', 'articles', 'featured_product', 'product_card', 'product_grid', 'collection_grid', 'product_detail', 'add_to_cart'], 'disallowed_block_types' => ['columns']]]),
                        ],
                    ]]),
                ],
            ]], ['layout' => '2_equal', 'gap' => 'md', 'stack_on_mobile' => true, 'columns' => [['label' => '', 'width' => 'auto', 'blocks' => []], ['label' => '', 'width' => 'auto', 'blocks' => []]]]),
            'gallery' => self::makeBlockBlueprint('gallery', 'Galerie', [[
                'key' => 'content', 'display' => 'Images', 'fields' => [
                    self::field('columns', 'integer', 'Colonnes', ['default' => 3, 'validate' => ['min' => 1, 'max' => 4]]),
                    self::field('items', 'replicator', 'Images', ['required' => true, 'config' => [
                        'set_label_field' => 'alt',
                        'fields' => [
                            self::field('media_id', 'assets', 'Image', ['config' => ['kind' => 'image', 'max_files' => 1]]),
                            self::field('src', 'link', 'URL image'),
                            self::field('alt', 'text', 'Texte alternatif', ['localizable' => true]),
                            self::field('caption', 'markdown', 'Légende', ['localizable' => true]),
                        ],
                    ]]),
                ],
            ]], ['columns' => 3, 'items' => []]),
            'buttons' => self::makeBlockBlueprint('buttons', 'Boutons', [[
                'key' => 'content', 'display' => 'Boutons', 'fields' => [self::field('items', 'replicator', 'Boutons', ['required' => true, 'config' => ['fieldset' => 'button_item']])],
            ]], ['items' => []]),
            'card' => self::makeBlockBlueprint('card', 'Carte', [[
                'key' => 'content', 'display' => 'Contenu', 'fields' => [
                    self::field('icon_media_id', 'assets', 'Icône', ['instructions' => 'Choisissez une image ou un pictogramme depuis la médiathèque.', 'config' => ['kind' => 'image', 'max_files' => 1, 'preferred_set' => 'content']]),
                    self::field('icon_src', 'link', 'URL icône', ['width' => 50, 'instructions' => 'Champ technique synchronisé par le sélecteur média.']),
                    self::field('icon_alt', 'text', 'Texte alternatif de l’icône', ['width' => 50, 'localizable' => true, 'instructions' => 'Champ technique synchronisé par le sélecteur média.']),
                    self::field('title', 'text', 'Titre', ['required' => true, 'width' => 50, 'localizable' => true]),
                    self::field('text', 'markdown', 'Texte', ['localizable' => true]),
                    self::field('url', 'link', 'Lien', ['width' => 50]),
                    self::field('link_label', 'text', 'Libellé du lien', ['width' => 50, 'localizable' => true]),
                    self::field('style', 'select', 'Style', ['default' => 'default', 'config' => ['options' => self::options(['default' => 'Standard', 'feature' => 'Carte force', 'compact' => 'Compact'])]]),
                ],
            ]], ['icon_media_id' => 0, 'icon_src' => '', 'icon_alt' => '', 'icon' => '', 'title' => '', 'text' => '', 'url' => '', 'link_label' => '', 'style' => 'default']),
            'video' => self::mediaBlock('video', 'Vidéo'),
            'audio' => self::mediaBlock('audio', 'Audio'),
            'iframe' => self::makeBlockBlueprint('iframe', 'Iframe allowlist', [[
                'key' => 'content', 'display' => 'Iframe', 'fields' => [self::field('src', 'link', 'URL autorisée', ['required' => true, 'instructions' => 'Le domaine doit être présent dans cms.editorial_security.iframe_allowed_hosts.']), self::field('title', 'text', 'Titre'), self::field('height', 'integer', 'Hauteur', ['default' => 420])],
            ]], ['src' => '', 'title' => '', 'height' => 420]),
            'embed' => self::makeBlockBlueprint('embed', 'Embed externe', [[
                'key' => 'content', 'display' => 'Lien intégré', 'fields' => [self::field('provider', 'select', 'Fournisseur', ['default' => 'generic', 'config' => ['options' => self::options(['generic' => 'Générique', 'youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'openstreetmap' => 'OpenStreetMap'])]]), self::field('url', 'link', 'URL', ['required' => true]), self::field('title', 'text', 'Titre')],
            ]], ['provider' => 'generic', 'url' => '', 'title' => '']),
            'form' => self::makeBlockBlueprint('form', 'Formulaire', [[
                'key' => 'content', 'display' => 'Formulaire', 'fields' => [self::field('form_key', 'text', 'Clé formulaire', ['required' => true]), self::field('title', 'text', 'Titre'), self::field('intro', 'markdown', 'Introduction'), self::field('layout', 'select', 'Layout', ['default' => 'default', 'config' => ['options' => self::options(['default' => 'Standard', 'compact' => 'Compact', 'card' => 'Carte'])]])],
            ]], ['form_key' => '', 'title' => '', 'intro' => '', 'layout' => 'default']),
            'plan' => self::makeBlockBlueprint('plan', 'Plan', [[
                'key' => 'content', 'display' => 'Plan', 'fields' => [
                    self::field('title', 'text', 'Titre'),
                    self::field('source', 'select', 'Source', ['default' => 'pages', 'width' => 50, 'config' => ['options' => self::options(['pages' => 'Pages', 'articles' => 'Articles', 'taxonomies' => 'Taxonomies'])]]),
                    self::field('taxonomy_key', 'slug', 'Clé de taxonomie', ['width' => 50, 'instructions' => 'Optionnel. Utilisé seulement lorsque la source est « Taxonomies » : categories, tags, ou vide pour toutes les taxonomies.']),
                    self::field('limit', 'integer', 'Limite', ['default' => 8, 'width' => 50]),
                    self::field('show_pagination', 'toggle', 'Afficher la pagination', ['default' => true, 'width' => 50]),
                    self::field('page_param', 'slug', 'Paramètre de pagination', ['instructions' => 'Optionnel. Permet plusieurs blocs Plan paginés sur une même page, par exemple plan_pages ou plan_articles.']),
                ],
            ]], ['title' => 'Plan', 'source' => 'pages', 'taxonomy_key' => '', 'limit' => 8, 'show_pagination' => true, 'page_param' => '']),
            'articles' => self::makeBlockBlueprint('articles', 'Articles', [[
                'key' => 'content', 'display' => 'Articles', 'fields' => [self::field('title', 'text', 'Titre'), self::field('limit', 'integer', 'Limite'), self::field('category', 'slug', 'Catégorie'), self::field('tag', 'slug', 'Tag')],
            ]], ['title' => 'Articles', 'limit' => 3, 'category' => '', 'tag' => '', 'show_more_button' => true]),
            'featured_product' => self::commerceProductBlock('featured_product', 'Produit vedette', false, ['layout' => 'featured', 'limit' => 1]),
            'product_card' => self::commerceProductBlock('product_card', 'Carte produit', false, ['layout' => 'card', 'limit' => 1]),
            'product_grid' => self::commerceProductBlock('product_grid', 'Liste de produits', true),
            'product_detail' => self::commerceProductBlock('product_detail', 'Détail produit', false, ['layout' => 'detail', 'limit' => 1]),
            'collection_grid' => self::makeBlockBlueprint('collection_grid', 'Liste de catégories', [[
                'key' => 'selection', 'display' => 'Sélection', 'fields' => [
                    self::field('collection_ids', 'json', 'Catégories sélectionnées', ['instructions' => 'Références stables de catégories, dans l’ordre éditorial.']),
                    self::field('limit', 'integer', 'Nombre maximal', ['default' => 12, 'width' => 50, 'validate' => ['min' => 1, 'max' => 100]]),
                    self::field('columns', 'integer', 'Colonnes', ['default' => 3, 'width' => 50, 'validate' => ['min' => 1, 'max' => 6]]),
                ],
            ], [
                'key' => 'display', 'display' => 'Affichage', 'fields' => [
                    self::field('empty_state', 'select', 'État vide', ['default' => 'message', 'config' => ['options' => self::options(['hide' => 'Masquer le bloc', 'message' => 'Afficher un message'])]]),
                    self::field('empty_message', 'text', 'Message vide', ['default' => 'Aucune catégorie à afficher.', 'localizable' => true]),
                ],
            ]], ['collection_ids' => [], 'limit' => 12, 'columns' => 3, 'empty_state' => 'message', 'empty_message' => 'Aucune catégorie à afficher.']),
            'add_to_cart' => self::makeBlockBlueprint('add_to_cart', 'Ajout au panier', [[
                'key' => 'selection', 'display' => 'Produit vendable', 'fields' => [
                    self::field('product_id', 'integer', 'Produit', ['instructions' => 'Référence stable. Si plusieurs variantes sont possibles, le bouton ouvre la fiche produit.']),
                    self::field('sellable_id', 'integer', 'Vendable précis', ['instructions' => 'Optionnel. Référence stable d’une variante ou d’un produit simple vendable.']),
                    self::field('variant_rule', 'select', 'Règle de variante', ['default' => 'default_if_unambiguous', 'config' => ['options' => self::options(['default_if_unambiguous' => 'Ajouter seulement si le choix est sans ambiguïté', 'exact_sellable' => 'Exiger le vendable indiqué'])]]),
                ],
            ], [
                'key' => 'display', 'display' => 'Bouton', 'fields' => [
                    self::field('quantity', 'integer', 'Quantité', ['default' => 1, 'width' => 50, 'validate' => ['min' => 1, 'max' => 100]]),
                    self::field('label', 'text', 'Libellé', ['default' => 'Ajouter au panier', 'width' => 50, 'localizable' => true]),
                ],
            ]], ['product_id' => null, 'sellable_id' => null, 'variant_rule' => 'default_if_unambiguous', 'quantity' => 1, 'label' => 'Ajouter au panier']),
        ];
    }

    /** @return list<string> */
    public static function supportedBlockTypes(): array
    {
        return array_keys(self::blockBlueprints());
    }

    /** @return array<string,mixed>|null */
    public static function blockBlueprint(string $type): ?array
    {
        $blueprints = self::blockBlueprints();
        return $blueprints[$type] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function blockSchemas(): array
    {
        $schemas = [];
        foreach (self::blockBlueprints() as $type => $blueprint) {
            $fields = [];
            $required = [];
            foreach ((array) ($blueprint['sections'] ?? []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    $handle = (string) ($field['handle'] ?? '');
                    if ($handle === '') {
                        continue;
                    }
                    $fields[] = $handle;
                    if (!empty($field['required'])) {
                        $required[] = $handle;
                    }
                }
            }
            $schema = ['fields' => $fields];
            if ($required !== []) {
                $schema['required'] = $required;
            }
            if (isset($blueprint['required_any'])) {
                $schema['required_any'] = $blueprint['required_any'];
            }
            $schemas[$type] = $schema;
        }
        return $schemas;
    }

    /** @return array<string,mixed> */
    private static function fieldType(string $handle, string $display, string $storage, string $component, bool $implemented, bool $localizable): array
    {
        return ['handle' => $handle, 'display' => $display, 'storage' => $storage, 'component' => $component, 'implemented' => $implemented, 'localizable' => $localizable];
    }

    /** @param list<array<string,mixed>> $sections @return array<string,mixed> */
    private static function fieldset(string $key, string $title, array $sections): array
    {
        return ['key' => $key, 'title' => $title, 'sections' => $sections, 'schema_version' => 1];
    }

    /** @param list<array<string,mixed>> $sections @param array<string,mixed> $defaults @return array<string,mixed> */
    private static function makeBlockBlueprint(string $key, string $title, array $sections, array $defaults = []): array
    {
        return ['key' => $key, 'title' => $title, 'type' => 'block', 'icon' => $key, 'schema_version' => 1, 'sections' => $sections, 'defaults' => $defaults];
    }

    /** @return array<string,mixed> */
    private static function mediaBlock(string $key, string $title): array
    {
        return self::makeBlockBlueprint($key, $title, [[
            'key' => 'media', 'display' => $title, 'fields' => [self::field('media_id', 'assets', $title), self::field('src', 'link', 'URL'), self::field('title', 'text', 'Titre'), self::field('caption', 'markdown', 'Légende')],
        ]], ['media_id' => 0, 'src' => '', 'title' => '', 'caption' => '']);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function commerceProductBlock(string $key, string $title, bool $multiple, array $overrides = []): array
    {
        $selectionModes = $multiple
            ? ['explicit' => 'Produits choisis', 'brand' => 'Marque', 'category' => 'Catégorie', 'group' => 'Groupe', 'attribute' => 'Attribut public', 'promotion' => 'Promotion active', 'new' => 'Nouveautés', 'popular' => 'Popularité', 'relation' => 'Relation au produit courant']
            : ['explicit' => 'Produit choisi'];
        $defaults = array_replace([
            'selection_mode' => 'explicit', 'product_id' => null, 'product_ids' => [], 'brand' => '', 'category' => '', 'group' => '',
            'attribute_code' => '', 'attribute_values' => [], 'promotion_rule' => 'percent', 'relation_type' => 'related', 'source_product_id' => null,
            'manual_product_ids' => [], 'window_days' => 30, 'limit' => $multiple ? 12 : 1, 'sort' => 'name', 'columns' => $multiple ? 3 : 1,
            'show_price' => true, 'show_promotion' => true, 'show_availability' => true, 'show_cta' => true,
            'pagination' => false, 'empty_state' => 'message', 'empty_message' => 'Aucun produit à afficher.',
        ], $overrides);
        return self::makeBlockBlueprint($key, $title, [[
            'key' => 'selection', 'display' => 'Sélection', 'fields' => [
                self::field('selection_mode', 'select', 'Mode principal', ['default' => 'explicit', 'instructions' => 'Un seul mode principal est appliqué. Une surcharge manuelle ordonnée peut ensuite compléter la sélection.', 'config' => ['options' => self::options($selectionModes)]]),
                self::field('product_id', 'integer', 'Produit', ['instructions' => 'Référence stable utilisée par les blocs à produit unique.']),
                self::field('product_ids', 'json', 'Produits choisis', ['instructions' => 'Références stables et ordonnées.']),
                self::field('brand', 'slug', 'Marque', ['width' => 50]),
                self::field('category', 'slug', 'Catégorie', ['width' => 50]),
                self::field('group', 'slug', 'Groupe', ['width' => 50]),
                self::field('attribute_code', 'slug', 'Attribut public', ['width' => 50]),
                self::field('attribute_values', 'json', 'Valeurs publiques'),
                self::field('promotion_rule', 'select', 'Tri des promotions', ['default' => 'percent', 'width' => 50, 'config' => ['options' => self::options(['percent' => 'Pourcentage de remise', 'amount' => 'Montant de remise'])]]),
                self::field('relation_type', 'select', 'Type de relation', ['default' => 'related', 'width' => 50, 'config' => ['options' => self::options(['related' => 'Produits liés', 'alternative' => 'Alternatives', 'accessory' => 'Accessoires', 'upsell' => 'Montée en gamme', 'cross_sell' => 'Vente croisée'])]]),
                self::field('source_product_id', 'integer', 'Produit source', ['instructions' => 'Optionnel : le produit lié à la page est utilisé par défaut.']),
                self::field('window_days', 'integer', 'Fenêtre de popularité (jours)', ['default' => 30, 'width' => 50, 'validate' => ['min' => 1, 'max' => 365]]),
                self::field('manual_product_ids', 'json', 'Surcharge manuelle ordonnée', ['instructions' => 'Produits prioritaires, sans copier leurs données Commerce dans la révision.']),
            ],
        ], [
            'key' => 'display', 'display' => 'Affichage', 'fields' => [
                self::field('limit', 'integer', 'Nombre maximal', ['default' => $defaults['limit'], 'width' => 50, 'validate' => ['min' => 1, 'max' => 100]]),
                self::field('sort', 'select', 'Ordre', ['default' => 'name', 'width' => 50, 'config' => ['options' => self::options(['manual' => 'Ordre manuel', 'name' => 'Nom', 'newest' => 'Nouveautés', 'price_asc' => 'Prix croissant', 'price_desc' => 'Prix décroissant', 'promo_percent' => 'Promotion (%)', 'promo_amount' => 'Promotion (montant)'])]]),
                self::field('columns', 'integer', 'Colonnes', ['default' => $defaults['columns'], 'width' => 50, 'validate' => ['min' => 1, 'max' => 6]]),
                self::field('pagination', 'toggle', 'Pagination', ['default' => false, 'width' => 50]),
                self::field('show_price', 'toggle', 'Afficher le prix', ['default' => true, 'width' => 50]),
                self::field('show_promotion', 'toggle', 'Afficher la promotion', ['default' => true, 'width' => 50]),
                self::field('show_availability', 'toggle', 'Afficher la disponibilité', ['default' => true, 'width' => 50]),
                self::field('show_cta', 'toggle', 'Afficher l’action', ['default' => true, 'width' => 50]),
                self::field('empty_state', 'select', 'État vide', ['default' => 'message', 'config' => ['options' => self::options(['hide' => 'Masquer le bloc', 'message' => 'Afficher un message'])]]),
                self::field('empty_message', 'text', 'Message vide', ['default' => 'Aucun produit à afficher.', 'localizable' => true]),
            ],
        ]], $defaults);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private static function field(string $handle, string $type, string $display, array $extra = []): array
    {
        return array_replace_recursive([
            'handle' => $handle,
            'type' => $type,
            'display' => $display,
            'instructions' => '',
            'required' => false,
            'default' => null,
            'localizable' => false,
            'listable' => false,
            'sortable' => false,
            'visibility' => 'visible',
            'width' => 100,
            'validate' => new \stdClass(),
            'config' => new \stdClass(),
        ], $extra);
    }

    /** @param array<string,string> $items @return list<array{value:string,label:string}> */
    private static function options(array $items): array
    {
        $out = [];
        foreach ($items as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => $label];
        }
        return $out;
    }
}
