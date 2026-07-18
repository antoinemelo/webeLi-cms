<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\BusinessModuleProvider;

$h = new TestHarness();
$provider = new BusinessModuleProvider();
$blueprints = $provider->blueprints();
$byKey = [];

foreach ($blueprints as $blueprint) {
    $byKey[(string) ($blueprint['blueprint_key'] ?? '')] = $blueprint;
}

function pimFieldsByKey(array $blueprint): array
{
    return array_column($blueprint['fields'] ?? [], null, 'key');
}

function assertPimFields(TestHarness $h, array $blueprint, array $expectedFields, string $messagePrefix): void
{
    $fields = pimFieldsByKey($blueprint);
    foreach ($expectedFields as $field) {
        $h->assertTrue(isset($fields[$field]), $messagePrefix . ' declares field ' . $field);
    }
}

function assertPimEnum(TestHarness $h, array $blueprint, string $fieldKey, array $expected, string $message): void
{
    $fields = pimFieldsByKey($blueprint);
    $h->assertSame($expected, $fields[$fieldKey]['validation']['enum'] ?? null, $message);
}

$expectedKeys = [
    'business_product' => 'business.product',
    'business_variant' => 'business.variant',
    'business_product_asset' => 'business.product_asset',
    'business_asset_metadata' => 'business.asset_metadata',
    'business_attribute_group' => 'business.attribute_group',
    'business_attribute' => 'business.attribute',
    'business_attribute_option' => 'business.attribute_option',
    'business_product_attribute_value' => 'business.product_attribute_value',
    'business_variant_attribute_value' => 'business.variant_attribute_value',
    'business_completeness_rule' => 'business.completeness_rule',
    'business_completeness_score' => 'business.completeness_score',
    'business_product_relation' => 'business.product_relation',
    'business_product_bundle' => 'business.product_bundle',
    'business_bundle_component' => 'business.bundle_component',
    'business_sellable_variant_snapshot' => 'business.sellable_variant_snapshot',
];
$permissionKeys = array_column($provider->permissions(), 'key');

foreach ($expectedKeys as $key => $resourceKey) {
    $h->assertTrue(isset($byKey[$key]), 'PIM-lite admin blueprint is declared: ' . $key);
    $blueprint = $byKey[$key] ?? [];
    $h->assertSame($resourceKey, $blueprint['contract']['resource_key'] ?? null, $key . ' exposes expected resource key');
    $h->assertSame('module_resource', $blueprint['resource_type'] ?? null, $key . ' is a module resource');
    $h->assertSame('business', $blueprint['storage']['database'] ?? null, $key . ' uses business.sqlite');
    $h->assertTrue((string) ($blueprint['storage']['table'] ?? '') !== '', $key . ' documents storage table or read model');
    $h->assertTrue((string) ($blueprint['label'] ?? '') !== '', $key . ' has a readable label');
    $h->assertTrue((string) ($blueprint['description'] ?? '') !== '', $key . ' has a description');
    $h->assertTrue(($blueprint['fields'] ?? []) !== [], $key . ' declares fields');
    $h->assertTrue(($blueprint['permissions'] ?? []) !== [], $key . ' declares permissions');
    foreach (($blueprint['permissions'] ?? []) as $action => $permissionKey) {
        $h->assertTrue(in_array($permissionKey, $permissionKeys, true), $key . ' permission ' . $action . ' is declared by provider');
    }
    $h->assertSame(true, $blueprint['capabilities']['admin'] ?? null, $key . ' is admin-capable');
    $h->assertSame(false, $blueprint['capabilities']['headless'] ?? null, $key . ' disables headless capability');
    $h->assertSame(false, $blueprint['capabilities']['public'] ?? null, $key . ' disables public capability');
    $h->assertSame(false, $blueprint['headless']['enabled'] ?? null, $key . ' disables headless exposure');
    $h->assertSame(false, $blueprint['headless']['public'] ?? null, $key . ' disables public exposure');
    $h->assertSame([], $blueprint['contract']['public_headless_routes'] ?? null, $key . ' documents no public headless route');
    $h->assertSame(false, $blueprint['admin']['schema_driven'] ?? null, $key . ' keeps the dedicated Opérations UI');
    $h->assertSame(false, $blueprint['admin']['generated_form'] ?? null, $key . ' does not generate admin forms');
}

$product = $byKey['business_product'];
assertPimFields($h, $product, ['site_id', 'brand_id', 'category_id', 'type', 'status', 'visibility', 'sku_base', 'name', 'slug', 'tax_class_id', 'is_public', 'is_ecommerce_enabled', 'is_pos_enabled', 'is_catalogue_enabled'], 'business_product');
assertPimEnum($h, $product, 'type', ['physical', 'service', 'gift_card', 'bundle'], 'product types are declared');
$h->assertTrue(in_array('catalog.find_products', $product['ai']['allowed_actions'] ?? [], true), 'product blueprint prepares catalog.find_products');
$h->assertTrue(in_array('catalog.suggest_product_description', $product['ai']['allowed_actions'] ?? [], true), 'product blueprint prepares catalog.suggest_product_description');
$h->assertSame(true, $product['ai']['default_prompt_safe'] ?? null, 'product blueprint is prompt-safe by default');

$variant = $byKey['business_variant'];
assertPimFields($h, $variant, ['product_id', 'status', 'sku', 'barcode', 'name', 'stock_quantity', 'stock_reserved', 'track_stock', 'allow_backorder', 'backorder_delivery_days'], 'business_variant');
$h->assertTrue(in_array('catalog.prepare_sale_snapshot', $variant['ai']['allowed_actions'] ?? [], true), 'variant blueprint prepares catalog.prepare_sale_snapshot');
$h->assertTrue(in_array('catalog.check_ecommerce_readiness', $variant['ai']['allowed_actions'] ?? [], true), 'variant blueprint prepares catalog.check_ecommerce_readiness');

$productAsset = $byKey['business_product_asset'];
$h->assertSame('business_product_assets', $productAsset['storage']['table'] ?? null, 'product asset blueprint points to the native PIM asset table');
assertPimFields($h, $productAsset, ['product_id', 'variant_id', 'media_id', 'role', 'title', 'alt_text', 'is_public', 'channel_scope'], 'business_product_asset');
assertPimEnum($h, $productAsset, 'role', ['main', 'gallery', 'variant', 'thumbnail', 'document', 'technical_sheet', 'brand_logo', 'packaging', 'seo', 'internal'], 'product asset roles are declared');
assertPimEnum($h, $productAsset, 'channel_scope', ['all', 'public', 'ecommerce', 'pos', 'catalogue', 'admin', 'pdf'], 'product asset channel scopes are declared');
$h->assertTrue(in_array('catalog.suggest_alt_text', $productAsset['ai']['allowed_actions'] ?? [], true), 'product asset blueprint prepares catalog.suggest_alt_text');

$metadata = $byKey['business_asset_metadata'];
$metadataFields = pimFieldsByKey($metadata);
assertPimFields($h, $metadata, ['media_id', 'asset_type', 'usage_rights', 'license', 'credit', 'source', 'expires_at', 'internal_notes', 'metadata_json'], 'business_asset_metadata');
$h->assertSame(true, $metadataFields['internal_notes']['sensitive'] ?? null, 'asset metadata internal notes are sensitive');
$h->assertSame(true, $metadataFields['internal_notes']['admin_only'] ?? null, 'asset metadata internal notes are admin-only');

$attribute = $byKey['business_attribute'];
assertPimFields($h, $attribute, ['group_id', 'code', 'name', 'data_type', 'unit', 'is_required', 'is_filterable', 'is_searchable', 'is_public', 'validation_json'], 'business_attribute');
assertPimEnum($h, $attribute, 'data_type', ['text', 'textarea', 'rich_text', 'number', 'decimal', 'boolean', 'select', 'multi_select', 'date', 'url', 'file', 'dimension', 'weight', 'color'], 'attribute data types are declared');

assertPimFields($h, $byKey['business_attribute_option'], ['attribute_id', 'code', 'label', 'value', 'color_hex', 'sort_order'], 'business_attribute_option');
assertPimFields($h, $byKey['business_product_attribute_value'], ['product_id', 'attribute_id', 'language', 'value_text', 'value_number', 'value_json'], 'business_product_attribute_value');
assertPimFields($h, $byKey['business_variant_attribute_value'], ['variant_id', 'attribute_id', 'language', 'value_text', 'value_number', 'value_json'], 'business_variant_attribute_value');

$rule = $byKey['business_completeness_rule'];
assertPimFields($h, $rule, ['code', 'name', 'scope', 'required_field', 'required_attribute_id', 'channel', 'weight', 'is_active'], 'business_completeness_rule');
assertPimEnum($h, $rule, 'scope', ['product', 'variant', 'asset', 'price', 'tax', 'channel'], 'completeness rule scopes are declared');
assertPimEnum($h, $rule, 'channel', ['all', 'public', 'ecommerce', 'pos', 'catalogue', 'admin', 'pdf'], 'completeness rule channels are declared');
$h->assertSame(['required_field', 'required_attribute_id'], $rule['validation']['any_required'] ?? null, 'completeness rule documents required-field fallback');

$score = $byKey['business_completeness_score'];
assertPimFields($h, $score, ['product_id', 'variant_id', 'channel', 'score', 'is_sellable', 'missing_json', 'calculated_at'], 'business_completeness_score');
$h->assertSame('business_product_completeness_scores', $score['storage']['table'] ?? null, 'completeness score points to native score table');
$h->assertTrue(in_array('catalog.explain_missing_requirements', $score['ai']['allowed_actions'] ?? [], true), 'completeness score blueprint prepares missing-requirements explanation');

$relation = $byKey['business_product_relation'];
assertPimFields($h, $relation, ['product_id', 'related_product_id', 'relation_type', 'sort_order'], 'business_product_relation');
assertPimEnum($h, $relation, 'relation_type', ['related', 'accessory', 'alternative', 'bundle_candidate', 'replacement', 'upsell', 'cross_sell', 'similar'], 'product relation types are declared');

$bundle = $byKey['business_product_bundle'];
assertPimFields($h, $bundle, ['bundle_product_id', 'bundle_variant_id', 'pricing_mode', 'stock_mode', 'is_active'], 'business_product_bundle');
assertPimEnum($h, $bundle, 'pricing_mode', ['fixed', 'sum_components', 'discount_components'], 'bundle pricing modes are declared');
assertPimEnum($h, $bundle, 'stock_mode', ['components', 'virtual', 'none'], 'bundle stock modes are declared');
$h->assertSame(false, $bundle['headless']['public'] ?? null, 'bundle blueprint is not public headless');
$h->assertSame(true, $bundle['admin_only'] ?? null, 'bundle blueprint is documented as admin-only');

$component = $byKey['business_bundle_component'];
assertPimFields($h, $component, ['bundle_id', 'component_product_id', 'component_variant_id', 'quantity', 'is_required', 'sort_order', 'metadata_json'], 'business_bundle_component');
$h->assertSame('positive', $component['validation']['quantity'] ?? null, 'bundle component quantity rule is documented');
$h->assertSame(true, $component['validation']['forbid_cycles'] ?? null, 'bundle component cycle rule is documented');

$snapshot = $byKey['business_sellable_variant_snapshot'];
$snapshotFields = pimFieldsByKey($snapshot);
$h->assertSame('aggregate_read_model', $snapshot['storage']['mode'] ?? null, 'sellable variant snapshot is an aggregate read model');
$h->assertSame('variant_id', $snapshot['storage']['primary_key'] ?? null, 'sellable variant snapshot uses variant_id as primary key');
$h->assertSame(false, $snapshot['admin']['create'] ?? null, 'sellable variant snapshot is not direct-create');
$h->assertSame(false, $snapshot['admin']['update'] ?? null, 'sellable variant snapshot is not direct-update');
$h->assertSame(false, $snapshot['admin']['delete'] ?? null, 'sellable variant snapshot is not direct-delete');
assertPimFields($h, $snapshot, [
    'site_id',
    'product_id',
    'variant_id',
    'sku',
    'barcode',
    'product_name',
    'variant_name',
    'brand_name',
    'category_name',
    'product_type',
    'unit',
    'currency',
    'sale_price_minor',
    'regular_sale_price_minor',
    'purchase_price_minor',
    'purchase_price_visible',
    'margin_minor',
    'margin_percent_basis_points',
    'tax_class_id',
    'tax_rate_basis_points',
    'tax_included',
    'main_asset_id',
    'main_media_id',
    'main_media_url',
    'track_stock',
    'is_public',
    'allow_backorder',
    'backorder_delivery_days',
    'availability',
    'is_ecommerce_enabled',
    'is_pos_enabled',
    'is_sellable',
    'missing_requirements',
    'is_bundle',
    'bundle_components',
    'bundle_pricing_mode',
    'bundle_stock_mode',
    'bundle_available_quantity',
    'snapshot_json',
], 'business_sellable_variant_snapshot');
$h->assertSame(true, $snapshotFields['purchase_price_minor']['sensitive'] ?? null, 'purchase price is sensitive');
$h->assertSame(true, $snapshotFields['purchase_price_minor']['admin_only'] ?? null, 'purchase price is admin-only');
$h->assertSame('business.catalog.purchase_prices.read', $snapshotFields['purchase_price_minor']['permission'] ?? null, 'purchase price has dedicated read permission');
$h->assertSame('business.catalog.purchase_prices.read', $snapshotFields['margin_minor']['permission'] ?? null, 'margin has dedicated purchase-price permission');
$h->assertSame(true, $snapshotFields['snapshot_json']['sensitive'] ?? null, 'snapshot JSON is sensitive');
$h->assertSame(true, $snapshotFields['snapshot_json']['admin_only'] ?? null, 'snapshot JSON is admin-only');
$h->assertTrue(in_array('purchase_price_minor', $snapshot['ai']['sensitive_fields'] ?? [], true), 'AI discovery marks purchase price as sensitive');
$h->assertTrue(in_array('purchase_price_minor', $snapshot['ai']['excluded_by_default'] ?? [], true), 'AI discovery excludes purchase price by default');
$h->assertTrue(in_array('catalog.prepare_sale_snapshot', $snapshot['ai']['allowed_actions'] ?? [], true), 'snapshot blueprint prepares catalog.prepare_sale_snapshot');
$h->assertTrue(in_array('business_product_base_prices', $snapshot['storage']['source_tables'] ?? [], true), 'snapshot documents price source table');
$h->assertTrue(in_array('business_product_completeness_scores', $snapshot['storage']['source_tables'] ?? [], true), 'snapshot documents completeness source table');

$preparedAiActions = [];
foreach ($blueprints as $blueprint) {
    foreach (($blueprint['ai']['allowed_actions'] ?? []) as $action) {
        $preparedAiActions[$action] = true;
    }
}
foreach ([
    'catalog.find_products',
    'catalog.explain_missing_requirements',
    'catalog.suggest_product_description',
    'catalog.suggest_alt_text',
    'catalog.summarize_product',
    'catalog.prepare_sale_snapshot',
    'catalog.check_ecommerce_readiness',
] as $action) {
    $h->assertTrue(isset($preparedAiActions[$action]), 'PIM AI-ready action is declared: ' . $action);
}

$forbiddenPublicRoutes = [
    '/api/v1/business/product-assets',
    '/api/v1/business/attributes',
    '/api/v1/business/completeness',
    '/api/v1/business/sellable-variant-snapshot',
    '/api/v1/business/product-bundles',
    '/api/v1/business/bundle-components',
    '/api/v1/pim/bundles',
    '/api/v1/pim/products',
    '/api/v1/pim/attributes',
];
$apiRoutes = file_get_contents(__DIR__ . '/../../../../backend/routes/api.php') ?: '';
$webRoutes = file_get_contents(__DIR__ . '/../../../../backend/routes/web.php') ?: '';
foreach ($forbiddenPublicRoutes as $route) {
    $h->assertTrue(!str_contains($apiRoutes, $route), 'public API PIM route is absent: ' . $route);
    $h->assertTrue(!str_contains($webRoutes, $route), 'public web PIM route is absent: ' . $route);
}

exit($h->finish('UNIT business PIM-lite blueprints'));
