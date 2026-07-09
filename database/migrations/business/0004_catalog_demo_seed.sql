PRAGMA foreign_keys = ON;

INSERT OR IGNORE INTO business_product_brands(site_id, name, slug, description, website_url, is_public, status, sort_order)
VALUES (1, 'NOUVELLE MARQUE', 'nouvelle-marque', 'Marque de demonstration du catalogue Business.', 'https://example.test', 1, 'active', 20);

INSERT OR IGNORE INTO business_product_categories(site_id, parent_id, name, slug, description, is_public, sort_order)
VALUES
    (1, NULL, 'Services', 'services', 'Services de demonstration du catalogue.', 1, 30),
    (1, NULL, 'Marchandises', 'marchandises', 'Marchandises de demonstration du catalogue.', 1, 40);

INSERT OR IGNORE INTO business_tax_classes(site_id, code, name, rate, country, is_default)
VALUES (1, 'catalog_standard', 'Catalogue standard CH', 8.1, 'CH', 0);

INSERT OR IGNORE INTO business_product_options(site_id, code, name, type, sort_order)
VALUES
    (1, 'model', 'Modèle', 'select', 40),
    (1, 'size', 'Taille', 'select', 50),
    (1, 'color', 'Couleur', 'color', 60);

INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'classic', 'Classic', 'classic', 10 FROM business_product_options WHERE site_id = 1 AND code = 'model';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'premium', 'Premium', 'premium', 20 FROM business_product_options WHERE site_id = 1 AND code = 'model';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 's', 'S', 's', 10 FROM business_product_options WHERE site_id = 1 AND code = 'size';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'm', 'M', 'm', 20 FROM business_product_options WHERE site_id = 1 AND code = 'size';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, sort_order)
SELECT id, 'l', 'L', 'l', 30 FROM business_product_options WHERE site_id = 1 AND code = 'size';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, color_hex, sort_order)
SELECT id, 'blue', 'Bleu', 'blue', '#0066CC', 10 FROM business_product_options WHERE site_id = 1 AND code = 'color';
INSERT OR IGNORE INTO business_product_option_values(option_id, code, label, value, color_hex, sort_order)
SELECT id, 'black', 'Noir', 'black', '#000000', 20 FROM business_product_options WHERE site_id = 1 AND code = 'color';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'physical', 'active', 'public', 'TSHIRT-DEMO', 'T-shirt Demo', 't-shirt-demo', 'Marchandise de demonstration avec variantes.', 'piece', t.id, 1, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'marchandises' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'service', 'active', 'public', 'CONSULTATION', 'Consultation', 'consultation', 'Service de demonstration du catalogue.', 'service', t.id, 0, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'gift_card', 'active', 'public', 'GIFT-DEMO', 'Bon cadeau simple', 'bon-cadeau-simple', 'Bon cadeau simple de demonstration du catalogue.', 'piece', t.id, 0, 0, 1, 1, 1
FROM business_product_brands b, business_product_categories c, business_tax_classes t
WHERE b.site_id = 1 AND b.slug = 'nouvelle-marque' AND c.site_id = 1 AND c.slug = 'services' AND t.site_id = 1 AND t.code = 'catalog_standard';

INSERT OR IGNORE INTO business_products(site_id, brand_id, category_id, type, status, visibility, sku_base, name, slug, short_description, unit, tax_class_id, track_stock, allow_backorder, is_public, is_ecommerce_enabled, is_pos_enabled)
SELECT 1, b.id, c.id, 'bundle', 'active', 'public', 'BUNDLE-DEMO', 'Pack demo', 'pack-demo', 'Offre composee de demonstration regroupant un produit et un service.', 'bundle', t.id, 0, 0, 1, 1, 1
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

INSERT OR IGNORE INTO business_product_option_links(product_id, option_id, is_required, sort_order)
SELECT p.id, o.id, 1, o.sort_order
FROM business_products p, business_product_options o
WHERE p.site_id = 1 AND p.slug = 't-shirt-demo' AND o.site_id = 1 AND o.code IN ('model','size','color');

INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-CLASSIC-M-BLUE', 'Classic / M / Bleu', 1, 15, 0, 0, 10 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-CLASSIC-L-BLUE', 'Classic / L / Bleu', 1, 10, 0, 0, 20 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
INSERT OR IGNORE INTO business_product_variants(product_id, status, sku, name, track_stock, stock_quantity, stock_reserved, allow_backorder, sort_order)
SELECT id, 'active', 'TSHIRT-DEMO-PREMIUM-M-BLACK', 'Premium / M / Noir', 1, 8, 0, 0, 30 FROM business_products WHERE site_id = 1 AND slug = 't-shirt-demo';
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
INNER JOIN business_product_variants v ON v.product_id = p.id AND v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE'
WHERE bundle_product.slug = 'pack-demo';

INSERT OR IGNORE INTO business_bundle_components(bundle_id, component_product_id, component_variant_id, quantity, is_required, sort_order, metadata_json)
SELECT b.id, p.id, v.id, 1, 1, 20, '{"fixture":"pim_lite","component":"service"}'
FROM business_product_bundles b
INNER JOIN business_products bundle_product ON bundle_product.id = b.bundle_product_id
INNER JOIN business_products p ON p.site_id = b.site_id AND p.slug = 'consultation'
INNER JOIN business_product_variants v ON v.product_id = p.id AND v.sku = 'CONSULTATION-STANDARD'
WHERE bundle_product.slug = 'pack-demo';

INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'amount_delta', 2.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'amount_delta', 5.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'purchase', 'percent_delta', 15.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK';
INSERT OR IGNORE INTO business_product_variant_price_adjustments(variant_id, price_kind, adjustment_type, adjustment_value, currency)
SELECT v.id, 'sale', 'percent_delta', 25.00, NULL FROM business_product_variants v WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE' AND o.site_id = 1 AND o.code = 'model' AND ov.option_id = o.id AND ov.code = 'classic';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE' AND o.site_id = 1 AND o.code = 'size' AND ov.option_id = o.id AND ov.code = 'm';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-M-BLUE' AND o.site_id = 1 AND o.code = 'color' AND ov.option_id = o.id AND ov.code = 'blue';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE' AND o.site_id = 1 AND o.code = 'model' AND ov.option_id = o.id AND ov.code = 'classic';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE' AND o.site_id = 1 AND o.code = 'size' AND ov.option_id = o.id AND ov.code = 'l';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-CLASSIC-L-BLUE' AND o.site_id = 1 AND o.code = 'color' AND ov.option_id = o.id AND ov.code = 'blue';

INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK' AND o.site_id = 1 AND o.code = 'model' AND ov.option_id = o.id AND ov.code = 'premium';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK' AND o.site_id = 1 AND o.code = 'size' AND ov.option_id = o.id AND ov.code = 'm';
INSERT OR IGNORE INTO business_product_variant_option_values(variant_id, option_id, option_value_id)
SELECT v.id, o.id, ov.id FROM business_product_variants v, business_product_options o, business_product_option_values ov
WHERE v.sku = 'TSHIRT-DEMO-PREMIUM-M-BLACK' AND o.site_id = 1 AND o.code = 'color' AND ov.option_id = o.id AND ov.code = 'black';
