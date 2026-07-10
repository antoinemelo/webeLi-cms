PRAGMA foreign_keys = ON;

INSERT OR IGNORE INTO business_product_brands(site_id, name, slug, description, website_url, is_public, status, sort_order)
VALUES (1, 'NOUVELLE MARQUE', 'nouvelle-marque', 'Marque de demonstration du catalogue Business.', 'https://example.test', 1, 'active', 20);

INSERT OR IGNORE INTO business_product_categories(site_id, parent_id, name, slug, description, is_public, sort_order)
VALUES
    (1, NULL, 'Services', 'services', 'Services de demonstration du catalogue.', 1, 30),
    (1, NULL, 'Marchandises', 'marchandises', 'Marchandises de demonstration du catalogue.', 1, 40);

INSERT OR IGNORE INTO business_tax_classes(site_id, code, name, rate, country, is_default)
VALUES (1, 'catalog_standard', 'Catalogue standard CH', 8.1, 'CH', 0);

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'physical', 'active', 'public', 'TSHIRT-DEMO', 'T-shirt Demo', 't-shirt-demo', 'Marchandise de demonstration avec variantes.', 'piece', t.id, 1, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'marchandises' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'service', 'active', 'public', 'CONSULTATION', 'Consultation', 'consultation', 'Service de demonstration du catalogue.', 'service', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'gift_card', 'active', 'public', 'GIFT-DEMO', 'Bon cadeau simple', 'bon-cadeau-simple', 'Bon cadeau simple de demonstration du catalogue.', 'piece', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
SELECT 1, b.id, c.id, 'bundle', 'active', 'public', 'BUNDLE-DEMO', 'Pack demo', 'pack-demo', 'Offre composee de demonstration regroupant un produit et un service.', 'bundle', t.id, 0, 0, 1, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'marchandises' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 14.00, 0 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 29.00, 1 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 45.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 90.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 0.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 100.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'purchase', 'CHF', 59.00, 0 FROM business_products WHERE site_id = 1 AND slug = 'pack-demo';
INSERT OR IGNORE INTO business_product_base_prices(product_id, price_kind, currency, amount, tax_included)
SELECT id, 'sale', 'CHF', 119.00, 1 FROM business_products WHERE site_id = 1 AND slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-M-BLUE', 'M / Bleu', 1, 15, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-L-BLUE', 'L / Bleu', 1, 10, 0, 0, 20 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-M-BLACK', 'M / Noir', 1, 8, 0, 0, 30 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'CONSULTATION-STANDARD', 'Consultation standard', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'consultation';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'GIFT-DEMO-100', 'Bon cadeau simple 100 CHF', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'bon-cadeau-simple';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'BUNDLE-DEMO-STANDARD', 'Pack demo standard', 0, 0, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_bundles(site_id, bundle_product_id, bundle_variant_id, pricing_mode, stock_mode, is_active)
SELECT 1, p.id, v.id, 'fixed', 'components', 1
FROM business_products p
INNER JOIN business_product_variants v ON v.product_id = p.id
WHERE p.site_id = 1 AND p.slug = 'pack-demo' AND v.sku = 'BUNDLE-DEMO-STANDARD';

INSERT OR IGNORE INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required, sort_order, metadata_json)
SELECT b.id, p.id, v.id, 1, 1, 10, '{"fixture":"pim_lite","component":"product"}'
FROM business_product_bundles b
INNER JOIN business_products bundle_product ON bundle_product.id = b.bundle_product_id
INNER JOIN business_products p ON p.site_id = b.site_id AND p.slug = 't-shirt-demo'
INNER JOIN business_product_variants v ON v.product_id = p.id AND v.sku = 'TSHIRT-DEMO-M-BLUE'
WHERE bundle_product.slug = 'pack-demo';

INSERT OR IGNORE INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required, sort_order, metadata_json)
SELECT b.id, p.id, v.id, 1, 1, 20, '{"fixture":"pim_lite","component":"service"}'
FROM business_product_bundles b
INNER JOIN business_products bundle_product ON bundle_product.id = b.bundle_product_id
INNER JOIN business_products p ON p.site_id = b.site_id AND p.slug = 'consultation'
INNER JOIN business_product_variants v ON v.product_id = p.id AND v.sku = 'CONSULTATION-STANDARD'
WHERE bundle_product.slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'amount_delta', 2.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'amount_delta', 5.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'percent_delta', 15.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-M-BLACK';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'percent_delta', 25.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-M-BLACK';

INSERT OR IGNORE INTO business_attribute_groups(site_id, code, name, description, sort_order)
VALUES
    (1, 'textile', 'Textile', 'Attributs de demonstration pour les produits textiles.', 10),
    (1, 'service', 'Service', 'Attributs de demonstration pour les prestations.', 20),
    (1, 'technique', 'Technique', 'Attributs techniques communs.', 30);

INSERT OR IGNORE INTO business_product_attribute_group_links(product_id, group_id, sort_order)
SELECT p.id, g.id, 10
FROM business_products p, business_attribute_groups g
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_product_attribute_group_links(product_id, group_id, sort_order)
SELECT p.id, g.id, 20
FROM business_products p, business_attribute_groups g
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND g.site_id = 1 AND g.code = 'technique';
INSERT OR IGNORE INTO business_product_attribute_group_links(product_id, group_id, sort_order)
SELECT p.id, g.id, 10
FROM business_products p, business_attribute_groups g
WHERE p.site_id = 1 AND p.slug IN ('vol-decouverte', 'consultation') AND g.site_id = 1 AND g.code = 'service';

INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'couleur', 'Couleur', 'color', NULL, 1, 1, 1, 1, 10, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'taille', 'Taille', 'select', NULL, 1, 1, 1, 1, 20, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'matiere', 'Matiere', 'select', NULL, 1, 1, 1, 1, 30, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'textile';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'duree', 'Duree', 'number', 'min', 0, 1, 0, 1, 10, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'service';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'niveau', 'Niveau', 'select', NULL, 0, 1, 1, 1, 20, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'service';
INSERT OR IGNORE INTO business_attributes(site_id, group_id, code, name, data_type, unit, is_required, is_filterable, is_searchable, is_public, sort_order, validation_json)
SELECT 1, g.id, 'poids', 'Poids', 'weight', 'g', 0, 1, 0, 0, 10, '{}'
FROM business_attribute_groups g WHERE g.site_id = 1 AND g.code = 'technique';

INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, color_hex, sort_order)
SELECT id, 'bleu', 'Bleu', 'bleu', '#0066CC', 10 FROM business_attributes WHERE site_id = 1 AND code = 'couleur';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, color_hex, sort_order)
SELECT id, 'noir', 'Noir', 'noir', '#000000', 20 FROM business_attributes WHERE site_id = 1 AND code = 'couleur';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'm', 'M', 'm', 20 FROM business_attributes WHERE site_id = 1 AND code = 'taille';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'l', 'L', 'l', 30 FROM business_attributes WHERE site_id = 1 AND code = 'taille';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'coton', 'Coton', 'coton', 10 FROM business_attributes WHERE site_id = 1 AND code = 'matiere';
INSERT OR IGNORE INTO business_attribute_options(attribute_id, code, label, value, sort_order)
SELECT id, 'debutant', 'Debutant', 'debutant', 10 FROM business_attributes WHERE site_id = 1 AND code = 'niveau';

INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_text)
SELECT p.id, a.id, 'fr', 'Coton'
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND a.site_id = 1 AND a.code = 'matiere';
INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_number)
SELECT p.id, a.id, 'und', 180
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND a.site_id = 1 AND a.code = 'poids';
INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_number)
SELECT p.id, a.id, 'und', 60
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 'consultation' AND a.site_id = 1 AND a.code = 'duree';
INSERT OR IGNORE INTO business_product_attribute_values(product_id, attribute_id, language, value_text)
SELECT p.id, a.id, 'fr', 'Debutant'
FROM business_products p, business_attributes a
WHERE p.site_id = 1 AND p.slug = 'vol-decouverte' AND a.site_id = 1 AND a.code = 'niveau';

INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'M'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLUE' AND a.site_id = 1 AND a.code = 'taille';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'Bleu'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLUE' AND a.site_id = 1 AND a.code = 'couleur';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'L'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-L-BLUE' AND a.site_id = 1 AND a.code = 'taille';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'Bleu'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-L-BLUE' AND a.site_id = 1 AND a.code = 'couleur';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'M'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLACK' AND a.site_id = 1 AND a.code = 'taille';
INSERT OR IGNORE INTO business_variant_attribute_values(variant_id, attribute_id, language, value_text)
SELECT v.id, a.id, 'fr', 'Noir'
FROM business_product_variants v, business_attributes a
WHERE v.sku = 'TSHIRT-DEMO-M-BLACK' AND a.site_id = 1 AND a.code = 'couleur';
