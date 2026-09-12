<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\BusinessPimApiController;
use App\Application\Business\ProductContentLinkService;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Modules\Business\BusinessModuleProvider;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessPimAdminService;
use App\Modules\Business\Services\BusinessProductAssetService;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Modules\Business\Services\CatalogCommercialRelationService;
use App\Modules\Business\Repositories\ProductContentSourceRepository;
use App\Infrastructure\Persistence\Sql\SqlCmsContentSource;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$iamDir, $iamPath] = test_temp_db(__DIR__ . '/../../../../database/iam.sql');
$coreDir = sys_get_temp_dir() . '/amcms-business-pim-api-core-' . bin2hex(random_bytes(6));
mkdir($coreDir, 0775, true);

try {
    $provider = new BusinessModuleProvider();
    $routePaths = array_map(static fn(array $route): string => $route[0] . ' ' . $route[1], $provider->adminRoutes());
    foreach ([
        'GET /admin/api/business/pim/products/{id}/assets',
        'POST /admin/api/business/pim/products/{id}/assets',
        'GET /admin/api/business/pim/products/{id}/bundle',
        'PUT /admin/api/business/pim/products/{id}/bundle',
        'POST /admin/api/business/pim/bundles/{id}/components',
        'GET /admin/api/business/pim/tax-classes',
        'POST /admin/api/business/pim/tax-classes',
        'PATCH /admin/api/business/pim/tax-classes/{id}',
        'DELETE /admin/api/business/pim/tax-classes/{id}',
        'GET /admin/api/business/pim/attribute-groups',
        'GET /admin/api/business/pim/attributes',
        'GET /admin/api/business/pim/products/{id}/attributes',
        'GET /admin/api/business/pim/variants/{id}/sellable-snapshot',
        'GET /admin/api/business/pim/sellable-variants',
        'POST /admin/api/business/pim/products/bulk-update',
        'POST /admin/api/business/pim/products/bulk-asset-assign',
        'POST /admin/api/business/pim/products/bulk-recalculate',
        'POST /admin/api/business/pim/offers/bulk-update',
        'GET /admin/api/business/pim/offers/export.csv',
        'POST /admin/api/business/pim/offers/import/preview',
        'POST /admin/api/business/pim/offers/import/apply',
        'GET /admin/api/business/pim/products/{id}/relations',
        'POST /admin/api/business/pim/products/{id}/relations',
        'DELETE /admin/api/business/pim/product-relations/{id}',
        'POST /admin/api/business/pim/products/{id}/relation-rules',
        'DELETE /admin/api/business/pim/product-relation-rules/{id}',
        'GET /admin/api/business/pim/storefront-blocks/candidates',
        'POST /admin/api/business/pim/storefront-blocks/preview',
    ] as $expectedRoute) {
        $h->assertTrue(in_array($expectedRoute, $routePaths, true), 'Business provider declares admin PIM route ' . $expectedRoute);
    }
    $h->assertSame([], $provider->publicHeadlessRoutes(), 'Business PIM does not declare public headless routes');
    $contractKeys = array_column($provider->apiContracts(), 'key');
    foreach (['admin.business.pim.product_assets.index.v1', 'admin.business.pim.product_bundle.show.v1', 'admin.business.pim.bundle_components.store.v1', 'admin.business.pim.tax_classes.index.v1', 'admin.business.pim.tax_classes.store.v1', 'admin.business.pim.tax_classes.show.v1', 'admin.business.pim.tax_classes.delete.v1', 'admin.business.pim.attributes.index.v1', 'admin.business.pim.sellable_snapshot.show.v1', 'admin.business.pim.offers.bulk_update.v1', 'admin.business.pim.offers.export.v1', 'admin.business.pim.offers.import.preview.v1', 'admin.business.pim.offers.import.apply.v1', 'admin.business.pim.product_relations.index.v1', 'admin.business.pim.product_relations.store.v1', 'admin.business.pim.product_relations.delete.v1', 'admin.business.pim.product_relation_rules.store.v1', 'admin.business.pim.product_relation_rules.delete.v1', 'admin.business.pim.storefront_block_candidates.v1', 'admin.business.pim.storefront_block_preview.v1'] as $contractKey) {
        $h->assertTrue(in_array($contractKey, $contractKeys, true), 'Business provider declares PIM contract ' . $contractKey);
    }
    $projectionContract = array_values(array_filter($provider->apiContracts(), static fn(array $contract): bool => ($contract['key'] ?? '') === 'admin.business.pim.storefront_projections.rebuild.v1'))[0] ?? [];
    $h->assertSame('business.advanced_tools.manage', $projectionContract['permission'] ?? null, 'storefront projection contract advertises its dedicated advanced guard');

    $core = new Database($coreDir . '/core.sqlite', 1000);
    $core->run("CREATE TABLE sites(id INTEGER PRIMARY KEY, site_key TEXT NOT NULL, name TEXT NOT NULL, default_language_code TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
    $core->run("CREATE TABLE site_domains(id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, host TEXT NOT NULL, base_path TEXT NOT NULL DEFAULT '', scheme TEXT NOT NULL DEFAULT 'https', is_primary INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1, enforce_https INTEGER NOT NULL DEFAULT 0)");
    $core->run("CREATE TABLE languages(code TEXT PRIMARY KEY, name TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)");
    $core->run("CREATE TABLE site_languages(site_id INTEGER NOT NULL, language_code TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, fallback_language_code TEXT, url_prefix TEXT, hreflang_code TEXT, is_rtl INTEGER NOT NULL DEFAULT 0)");
    $core->run("INSERT INTO sites(id, site_key, name, default_language_code, is_active) VALUES(1, 'main', 'Main', 'fr', 1)");
    $core->run("INSERT INTO site_domains(id, site_id, host, base_path, scheme, is_primary, is_active) VALUES(1, 1, 'example.test', '', 'https', 1, 1)");
    $core->run("INSERT INTO languages(code, name, is_default, is_active, sort_order) VALUES('fr', 'Français', 1, 1, 1)");
    $core->run("INSERT INTO site_languages(site_id, language_code, is_default, is_active, sort_order) VALUES(1, 'fr', 1, 1, 1)");

    $iam = new Database($iamPath, 1000);
    $iam->run("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES(1,'pim-admin@example.test','pim-admin@example.test','x',1,'password'),(2,'pim-reader@example.test','pim-reader@example.test','x',1,'password'),(3,'pim-empty@example.test','pim-empty@example.test','x',1,'password'),(4,'pim-operator@example.test','pim-operator@example.test','x',1,'password')");
    $iam->run("INSERT INTO iam_roles(id,role_key,name) VALUES(1,'pim_admin','PIM admin'),(2,'pim_reader','PIM reader'),(3,'pim_empty','PIM empty'),(4,'pim_operator','PIM operator')");
    $permissions = ['business.catalog.read', 'business.catalog.write', 'business.catalog.purchase_prices.read', 'business.advanced_tools.manage'];
    foreach ($permissions as $index => $permission) {
        $iam->run('INSERT INTO iam_permissions(id, permission_key, name) VALUES(?, ?, ?)', [$index + 1, $permission, $permission]);
    }
    foreach (range(1, count($permissions)) as $permissionId) {
        $iam->run('INSERT INTO iam_role_permissions(role_id, permission_id) VALUES(1, ?)', [$permissionId]);
    }
    $iam->run('INSERT INTO iam_role_permissions(role_id, permission_id) VALUES(2, 1)');
    $iam->run('INSERT INTO iam_role_permissions(role_id, permission_id) VALUES(4, 1),(4, 2)');
    $iam->run('INSERT INTO iam_user_site_roles(user_id, site_id, role_id) VALUES(1,1,1),(2,1,2),(3,1,3),(4,1,4)');
    foreach ([1, 2, 3, 4] as $userId) {
        $token = 'business-pim-api-test-token-' . $userId;
        $iam->run(
            'INSERT INTO iam_sessions(user_id, session_token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at)
             VALUES(:user_id, :hash, :ip, :ua, :last_seen, :expires, :created)',
            [
                'user_id' => $userId,
                'hash' => hash('sha256', $token),
                'ip' => '127.0.0.1',
                'ua' => 'business-pim-api-controller-test',
                'last_seen' => gmdate('Y-m-d H:i:s'),
                'expires' => gmdate('Y-m-d H:i:s', time() + 3600),
                'created' => gmdate('Y-m-d H:i:s', time() - 60),
            ]
        );
    }

    $sites = new SiteRepository($core, ['cms' => ['default_site_key' => 'main'], 'app' => ['default_locale' => 'fr']]);
    $authRepository = static fn(): AuthRepository => new AuthRepository($iam);
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $pricing = new CatalogPricingService($pricingRepository);
    $controllerFor = static function (int $userId, string $method, string $path, array $query = [], array $payload = []) use ($sites, $authRepository, $businessDb, $pricingRepository, $pricing, $core): BusinessPimApiController {
        $token = 'business-pim-api-test-token-' . $userId;
        $_SESSION['admin_user'] = ['id' => $userId, 'email' => 'pim-' . $userId . '@example.test', 'session_secret' => $token];
        $request = new Request($method, $path, $query, $payload === [] ? [] : ['data' => $payload], ['HTTP_HOST' => 'example.test'], [], []);
        $auth = $authRepository();
        return new BusinessPimApiController(
            $request,
            $sites,
            $auth,
            new Authorization($auth),
            new BusinessPimAdminService($businessDb),
            new BusinessProductAssetService($businessDb),
            new BusinessProductBundleService($businessDb),
            new BusinessCatalogSellableReadService($pricingRepository, $pricing, new PosCatalogRepository($businessDb), null, new BusinessProductBundleService($businessDb)),
            new ProductContentLinkService($core, new ProductContentSourceRepository($businessDb), new SqlCmsContentSource($core)),
            null,
            new CatalogCommercialRelationService($businessDb)
        );
    };

    $product = $businessDb->one("SELECT id FROM business_products WHERE slug = 'gourde-demo' LIMIT 1");
    $brand = $businessDb->one("SELECT id FROM business_product_brands WHERE site_id = 1 ORDER BY id ASC LIMIT 1");
    $category = $businessDb->one("SELECT id FROM business_product_categories WHERE site_id = 1 ORDER BY id ASC LIMIT 1");
    $taxClass = $businessDb->one("SELECT id FROM business_tax_classes WHERE site_id = 1 ORDER BY is_default DESC, id ASC LIMIT 1");
    $variant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");
    $componentProduct = $businessDb->one("SELECT id FROM business_products WHERE slug = 'bon-cadeau-demo' LIMIT 1");
    $componentVariant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GIFT-100' LIMIT 1");
    $secondComponentProduct = $businessDb->one("SELECT id FROM business_products WHERE slug = 'vol-decouverte' LIMIT 1");
    $secondComponentVariant = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-VOL-CLASSIC-20' LIMIT 1");
    $productId = (int) ($product['id'] ?? 0);
    $brandId = (int) ($brand['id'] ?? 0);
    $categoryId = (int) ($category['id'] ?? 0);
    $taxClassId = (int) ($taxClass['id'] ?? 0);
    $variantId = (int) ($variant['id'] ?? 0);
    $componentProductId = (int) ($componentProduct['id'] ?? 0);
    $componentVariantId = (int) ($componentVariant['id'] ?? 0);
    $secondComponentProductId = (int) ($secondComponentProduct['id'] ?? 0);
    $secondComponentVariantId = (int) ($secondComponentVariant['id'] ?? 0);
    $h->assertTrue($productId > 0 && $variantId > 0 && $componentProductId > 0 && $componentVariantId > 0 && $brandId > 0 && $categoryId > 0 && $taxClassId > 0, 'demo product, variant, taxonomy, tax class and bundle components are available');

    $relationCreate = $controllerFor(1, 'POST', '/admin/api/business/pim/products/' . $productId . '/relations', [], ['target_product_id'=>$componentProductId,'relation_type'=>'related','sort_order'=>4])->storeProductRelation($productId);
    $h->assertSame(201, $relationCreate->status(), 'catalog administrator can create a typed product relation');
    $relationData = json_decode($relationCreate->body(), true)['data']['relation'] ?? [];
    $relationList = $controllerFor(2, 'GET', '/admin/api/business/pim/products/' . $productId . '/relations')->productRelations($productId);
    $h->assertSame(200, $relationList->status(), 'catalog reader can inspect product relations and automatic rules');
    $h->assertTrue(count(json_decode($relationList->body(), true)['data']['relations'] ?? []) > 0, 'product relation listing returns the created relation');
    $ruleCreate = $controllerFor(1, 'POST', '/admin/api/business/pim/products/' . $productId . '/relation-rules', [], ['relation_type'=>'accessory','match_type'=>'category','match_id'=>$categoryId,'result_limit'=>3,'sort_order'=>10])->storeProductRelationRule($productId);
    $h->assertSame(201, $ruleCreate->status(), 'catalog administrator can create an explicit automatic category rule');
    $ruleData = json_decode($ruleCreate->body(), true)['data']['rule'] ?? [];
    $h->expectException(fn()=>$controllerFor(2, 'POST', '/admin/api/business/pim/products/' . $productId . '/relations', [], ['target_product_id'=>$componentProductId,'relation_type'=>'accessory'])->storeProductRelation($productId), ApiException::class, 'catalog reader cannot mutate product relations');
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/pim/product-relation-rules/' . ($ruleData['id']??0))->deleteProductRelationRule((int)($ruleData['id']??0))->status(), 'catalog administrator can delete an automatic relation rule');
    $h->assertSame(200, $controllerFor(1, 'DELETE', '/admin/api/business/pim/product-relations/' . ($relationData['id']??0))->deleteProductRelation((int)($relationData['id']??0))->status(), 'catalog administrator can delete a manual product relation');

    $assetList = $controllerFor(1, 'GET', '/admin/api/business/pim/products/' . $productId . '/assets')->productAssets($productId);
    $h->assertSame(200, $assetList->status(), 'catalog admin can list PIM product assets');
    $assetPayload = json_decode($assetList->body(), true);
    $h->assertTrue(count($assetPayload['data']['assets'] ?? []) > 0, 'PIM product assets endpoint returns assets');

    $taxList = $controllerFor(1, 'GET', '/admin/api/business/pim/tax-classes')->taxClasses();
    $h->assertSame(200, $taxList->status(), 'catalog admin can list PIM tax classes');
    $taxPayload = json_decode($taxList->body(), true);
    $h->assertTrue(count($taxPayload['data']['tax_classes'] ?? []) > 0, 'PIM tax classes endpoint returns rows');

    $taxCreate = $controllerFor(1, 'POST', '/admin/api/business/pim/tax-classes', [], [
        'code' => 'test_tax',
        'name' => 'Test TVA',
        'rate' => 3.7,
        'country' => 'CH',
        'is_default' => false,
    ])->storeTaxClass();
    $h->assertSame(201, $taxCreate->status(), 'catalog admin can create PIM tax class');
    $createdTaxClass = json_decode($taxCreate->body(), true)['data']['tax_class'];
    $createdTaxClassId = (int) ($createdTaxClass['id'] ?? 0);
    $h->assertTrue($createdTaxClassId > 0, 'PIM tax class creation returns id');

    $taxUpdate = $controllerFor(1, 'PATCH', '/admin/api/business/pim/tax-classes/' . $createdTaxClassId, [], [
        'name' => 'Test TVA réduit',
        'rate' => 2.5,
        'country' => 'CH',
        'is_default' => false,
    ])->updateTaxClass($createdTaxClassId);
    $h->assertSame(200, $taxUpdate->status(), 'catalog admin can update PIM tax class');
    $updatedTaxClass = json_decode($taxUpdate->body(), true)['data']['tax_class'];
    $h->assertSame('Test TVA réduit', $updatedTaxClass['name'] ?? '', 'PIM tax class update changes name');
    $h->assertSame(2.5, (float) ($updatedTaxClass['rate'] ?? 0), 'PIM tax class update changes rate');

    $taxDelete = $controllerFor(1, 'DELETE', '/admin/api/business/pim/tax-classes/' . $createdTaxClassId)->deleteTaxClass($createdTaxClassId);
    $h->assertSame(200, $taxDelete->status(), 'catalog admin can delete unused PIM tax class');

    $assetCreate = $controllerFor(1, 'POST', '/admin/api/business/pim/products/' . $productId . '/assets', [], [
        'variant_id' => $variantId,
        'media_id' => 7,
        'role' => 'thumbnail',
        'channel_scope' => 'admin',
        'alt_text' => 'PIM API thumbnail',
    ])->storeProductAsset($productId);
    $h->assertSame(201, $assetCreate->status(), 'catalog admin can add PIM product asset');
    $createdAsset = json_decode($assetCreate->body(), true)['data']['asset'];
    $assetId = (int) $createdAsset['asset_id'];
    $h->assertSame(7, (int) $createdAsset['media_id'], 'created PIM asset keeps media id');

    $assetUpdate = $controllerFor(1, 'PATCH', '/admin/api/business/pim/assets/' . $assetId, [], ['role' => 'gallery', 'alt_text' => 'Updated PIM API thumbnail'])->updateAsset($assetId);
    $h->assertSame(200, $assetUpdate->status(), 'catalog admin can update PIM product asset');
    $h->assertSame('gallery', json_decode($assetUpdate->body(), true)['data']['asset']['role'], 'updated PIM asset keeps role');

    $setMain = $controllerFor(1, 'POST', '/admin/api/business/pim/assets/' . $assetId . '/set-main')->setMainAsset($assetId);
    $h->assertSame(200, $setMain->status(), 'catalog admin can set PIM main asset');
    $h->assertSame('main', json_decode($setMain->body(), true)['data']['asset']['role'], 'set-main converts asset to main role');

    $businessDb->run("UPDATE business_products SET type='bundle' WHERE id=?", [$productId]);
    $emptyBundle = $controllerFor(1, 'GET', '/admin/api/business/pim/products/' . $productId . '/bundle')->productBundle($productId);
    $h->assertSame(200, $emptyBundle->status(), 'catalog admin can read empty product bundle');
    $h->assertSame(null, json_decode($emptyBundle->body(), true)['data']['bundle'], 'product without bundle returns null bundle');

    $bundleResponse = $controllerFor(1, 'PUT', '/admin/api/business/pim/products/' . $productId . '/bundle', [], [
        'pricing_mode' => 'fixed',
        'stock_mode' => 'components',
        'is_active' => true,
    ])->putProductBundle($productId);
    $h->assertSame(200, $bundleResponse->status(), 'catalog admin can activate product bundle');
    $bundlePayload = json_decode($bundleResponse->body(), true)['data']['bundle'];
    $bundleId = (int) $bundlePayload['id'];
    $h->assertSame('fixed', $bundlePayload['pricing_mode'] ?? null, 'bundle stores fixed pricing mode');
    $h->assertSame('components', $bundlePayload['stock_mode'] ?? null, 'bundle stores component stock mode');

    $componentResponse = $controllerFor(1, 'POST', '/admin/api/business/pim/bundles/' . $bundleId . '/components', [], [
        'component_product_id' => $componentProductId,
        'component_variant_id' => $componentVariantId,
        'quantity' => 2,
        'is_required' => true,
    ])->storeBundleComponent($bundleId);
    $h->assertSame(201, $componentResponse->status(), 'catalog admin can add first bundle component');
    $secondComponentResponse = $controllerFor(1, 'POST', '/admin/api/business/pim/bundles/' . $bundleId . '/components', [], [
        'component_product_id' => $secondComponentProductId,
        'component_variant_id' => $secondComponentVariantId,
        'quantity' => 1,
        'is_required' => true,
    ])->storeBundleComponent($bundleId);
    $h->assertSame(201, $secondComponentResponse->status(), 'catalog admin can add second bundle component');
    $invalidQuantity = $controllerFor(1, 'POST', '/admin/api/business/pim/bundles/' . $bundleId . '/components', [], [
        'component_product_id' => $componentProductId,
        'quantity' => 0,
    ])->storeBundleComponent($bundleId);
    $h->assertSame(422, $invalidQuantity->status(), 'bundle component refuses zero quantity');
    $h->assertSame('business.bundle_quantity_positive_required', json_decode($invalidQuantity->body(), true)['error']['fields']['business_pim'][0] ?? null, 'bundle quantity error is explicit');
    $loop = $controllerFor(1, 'POST', '/admin/api/business/pim/bundles/' . $bundleId . '/components', [], [
        'component_product_id' => $productId,
        'component_variant_id' => $variantId,
        'quantity' => 1,
    ])->storeBundleComponent($bundleId);
    $h->assertSame(422, $loop->status(), 'bundle component refuses direct loop');
    $h->assertSame('business.bundle_loop_detected', json_decode($loop->body(), true)['error']['fields']['business_pim'][0] ?? null, 'bundle loop error is explicit');

    $bundleAfterComponents = $controllerFor(1, 'GET', '/admin/api/business/pim/products/' . $productId . '/bundle')->productBundle($productId);
    $h->assertSame(2, count(json_decode($bundleAfterComponents->body(), true)['data']['bundle']['components'] ?? []), 'product bundle returns added components');

    $groupResponse = $controllerFor(1, 'POST', '/admin/api/business/pim/attribute-groups', [], ['code' => 'api_specs', 'name' => 'API specs'])->storeAttributeGroup();
    $h->assertSame(201, $groupResponse->status(), 'catalog admin can create PIM attribute group');
    $groupId = (int) json_decode($groupResponse->body(), true)['data']['attribute_group']['id'];

    $attributeResponse = $controllerFor(1, 'POST', '/admin/api/business/pim/attributes', [], [
        'group_id' => $groupId,
        'code' => 'api_material',
        'name' => 'Matière API',
        'data_type' => 'select',
        'is_filterable' => true,
        'is_public' => true,
    ])->storeAttribute();
    $h->assertSame(201, $attributeResponse->status(), 'catalog admin can create PIM attribute');
    $attributeId = (int) json_decode($attributeResponse->body(), true)['data']['attribute']['id'];

    $optionResponse = $controllerFor(1, 'POST', '/admin/api/business/pim/attributes/' . $attributeId . '/options', [], ['code' => 'coton_api', 'label' => 'Coton API', 'value' => 'coton'])->storeAttributeOption($attributeId);
    $h->assertSame(201, $optionResponse->status(), 'catalog admin can create PIM attribute option');
    $attributeOptionId = (int) json_decode($optionResponse->body(), true)['data']['attribute_option']['id'];

    $productValuesWithoutGroup = $controllerFor(1, 'PUT', '/admin/api/business/pim/products/' . $productId . '/attributes', [], [
        'values' => [['attribute_id' => $attributeId, 'language' => 'fr', 'value_text' => 'coton']],
    ])->putProductAttributes($productId);
    $h->assertSame(422, $productValuesWithoutGroup->status(), 'PIM product attributes require an assigned product attribute group');
    $h->assertSame('business.attribute_not_in_product_group', json_decode($productValuesWithoutGroup->body(), true)['error']['fields']['business_pim'][0] ?? null, 'PIM rejects attributes outside product groups');

    $businessDb->run('INSERT INTO business_product_attribute_group_links(product_id, group_id, sort_order) VALUES(?, ?, 10)', [$productId, $groupId]);

    $productValues = $controllerFor(1, 'PUT', '/admin/api/business/pim/products/' . $productId . '/attributes', [], [
        'values' => [['attribute_id' => $attributeId, 'language' => 'fr', 'value_text' => 'coton']],
    ])->putProductAttributes($productId);
    $h->assertSame(200, $productValues->status(), 'catalog admin can replace PIM product attributes');
    $h->assertSame('coton', json_decode($productValues->body(), true)['data']['attributes'][0]['value'], 'PIM product attribute value is returned');

    $invalidOption = $controllerFor(1, 'PUT', '/admin/api/business/pim/products/' . $productId . '/attributes', [], [
        'values' => [['attribute_id' => $attributeId, 'language' => 'fr', 'value_text' => 'lin']],
    ])->putProductAttributes($productId);
    $h->assertSame(422, $invalidOption->status(), 'PIM product attributes are constrained to declared attribute options');
    $h->assertSame('business.attribute_option_invalid', json_decode($invalidOption->body(), true)['error']['fields']['business_pim'][0] ?? null, 'PIM rejects values outside declared options');

    $businessDb->run(
        'INSERT OR REPLACE INTO business_product_completeness_scores(product_id, variant_id, channel, score, is_sellable, missing_json)
         VALUES(?, ?, "pos", 1, 0, ?)',
        [$productId, $variantId, json_encode(['stale_attribute_state'], JSON_THROW_ON_ERROR)]
    );
    $variantValues = $controllerFor(1, 'PUT', '/admin/api/business/pim/variants/' . $variantId . '/attributes', [], [
        'values' => [['attribute_id' => $attributeId, 'language' => 'fr', 'value_text' => 'coton']],
    ])->putVariantAttributes($variantId);
    $h->assertSame(200, $variantValues->status(), 'catalog admin can replace PIM variant attributes');
    $variantCompleteness = $businessDb->one('SELECT score, missing_json FROM business_product_completeness_scores WHERE variant_id = ? AND channel = "pos"', [$variantId]);
    $h->assertTrue((int) ($variantCompleteness['score'] ?? 1) !== 1 || (string) ($variantCompleteness['missing_json'] ?? '') !== '["stale_attribute_state"]', 'PIM variant attribute update recalculates stale completeness');

    $lockedType = $controllerFor(1, 'PATCH', '/admin/api/business/pim/attributes/' . $attributeId, [], [
        'name' => 'Matière API',
        'code' => 'api_material',
        'data_type' => 'number',
    ])->updateAttribute($attributeId);
    $h->assertSame(422, $lockedType->status(), 'PIM attribute type cannot change after values exist');
    $lockedTypePayload = json_decode($lockedType->body(), true);
    $h->assertSame('business.attribute_type_locked', $lockedTypePayload['error']['fields']['business_pim'][0] ?? null, 'PIM locked attribute type returns explicit validation code');

    $deleteOption = $controllerFor(1, 'DELETE', '/admin/api/business/pim/attribute-options/' . $attributeOptionId)->deleteAttributeOption($attributeOptionId);
    $h->assertSame(422, $deleteOption->status(), 'catalog admin cannot archive an attribute option still used by products or variants');
    $h->assertSame('business.attribute_option_in_use',json_decode($deleteOption->body(),true)['error']['fields']['business_pim'][0]??null,'used PIM option returns an explicit protection code');
    $unusedOptionResponse = $controllerFor(1, 'POST', '/admin/api/business/pim/attributes/' . $attributeId . '/options', [], ['code' => 'lin_api', 'label' => 'Lin API', 'value' => 'lin'])->storeAttributeOption($attributeId);
    $unusedOptionId=(int)(json_decode($unusedOptionResponse->body(),true)['data']['attribute_option']['id']??0);
    $archiveUnused=$controllerFor(1,'DELETE','/admin/api/business/pim/attribute-options/'.$unusedOptionId)->deleteAttributeOption($unusedOptionId);
    $h->assertSame(200,$archiveUnused->status(),'an unused PIM option remains archivable');
    $attributeListAfterDelete = $controllerFor(1, 'GET', '/admin/api/business/pim/attributes')->attributes();
    $attributesAfterDelete = json_decode($attributeListAfterDelete->body(), true)['data']['attributes'] ?? [];
    $deletedAttribute = array_values(array_filter($attributesAfterDelete, static fn(array $attribute): bool => (int) ($attribute['id'] ?? 0) === $attributeId))[0] ?? [];
    $h->assertSame(1,count($deletedAttribute['options'] ?? []),'used PIM option remains visible while the archived unused option is hidden');

    $complete = $controllerFor(1, 'POST', '/admin/api/business/pim/products/' . $productId . '/recalculate-completeness')->recalculateCompleteness($productId);
    $h->assertSame(200, $complete->status(), 'catalog admin can recalculate PIM completeness');
    $h->assertTrue(isset(json_decode($complete->body(), true)['data']['completeness']['scores']), 'PIM completeness endpoint returns scores');

    $snapshotPrivileged = $controllerFor(1, 'GET', '/admin/api/business/pim/variants/' . $variantId . '/sellable-snapshot')->variantSellableSnapshot($variantId);
    $h->assertSame(200, $snapshotPrivileged->status(), 'privileged catalog admin can read PIM sellable snapshot');
    $privilegedSnapshot = json_decode($snapshotPrivileged->body(), true)['data']['snapshot'];
    $h->assertTrue(array_key_exists('purchase_price_minor', $privilegedSnapshot), 'privileged snapshot includes purchase price');
    $h->assertSame(true, $privilegedSnapshot['is_bundle'] ?? null, 'privileged snapshot marks bundle variant');
    $h->assertSame(2, count($privilegedSnapshot['bundle_components'] ?? []), 'privileged snapshot includes bundle components');
    $h->assertTrue(!array_key_exists('purchase_price_minor', $privilegedSnapshot['bundle_components'][0] ?? []), 'bundle components do not expose purchase prices');

    $snapshotLimited = $controllerFor(2, 'GET', '/admin/api/business/pim/variants/' . $variantId . '/sellable-snapshot')->variantSellableSnapshot($variantId);
    $h->assertSame(200, $snapshotLimited->status(), 'limited catalog reader can read PIM sellable snapshot');
    $h->assertTrue(!array_key_exists('purchase_price_minor', json_decode($snapshotLimited->body(), true)['data']['snapshot']), 'limited snapshot hides purchase price');
    $h->assertSame(true, json_decode($snapshotLimited->body(), true)['data']['snapshot']['is_bundle'] ?? null, 'limited snapshot still includes bundle marker');

    $bulk = $controllerFor(1, 'POST', '/admin/api/business/pim/products/bulk-update', [], [
        'product_ids' => [$productId],
        'changes' => ['is_pos_enabled' => true],
        'dry_run' => true,
    ])->bulkUpdateProducts();
    $h->assertSame(200, $bulk->status(), 'catalog admin can dry-run PIM bulk update');
    $h->assertSame(true, json_decode($bulk->body(), true)['data']['bulk']['dry_run'], 'PIM bulk update defaults to dry-run result');

    $bulkApply = $controllerFor(1, 'POST', '/admin/api/business/pim/products/bulk-update', [], [
        'product_ids' => [$productId],
        'changes' => [
            'brand_id' => $brandId,
            'category_id' => $categoryId,
            'tax_class_id' => $taxClassId,
            'is_public' => true,
            'is_ecommerce_enabled' => true,
            'is_pos_enabled' => false,
        ],
        'dry_run' => false,
    ])->bulkUpdateProducts();
    $h->assertSame(200, $bulkApply->status(), 'catalog admin can apply PIM bulk update');
    $bulkApplyPayload = json_decode($bulkApply->body(), true)['data']['bulk'];
    $h->assertSame(false, $bulkApplyPayload['dry_run'], 'PIM bulk update apply is not dry-run');
    $h->assertSame(1, $bulkApplyPayload['updated'], 'PIM bulk update returns updated count');
    $h->assertSame(1, $bulkApplyPayload['recalculated'], 'PIM bulk update recalculates changed products');
    $updatedProduct = $businessDb->one('SELECT brand_id, category_id, tax_class_id, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled FROM business_products WHERE id = ?', [$productId]);
    $h->assertSame($brandId, (int) ($updatedProduct['brand_id'] ?? 0), 'PIM bulk update changes brand');
    $h->assertSame($categoryId, (int) ($updatedProduct['category_id'] ?? 0), 'PIM bulk update changes category');
    $h->assertSame($taxClassId, (int) ($updatedProduct['tax_class_id'] ?? 0), 'PIM bulk update changes tax class');
    $h->assertSame(1, (int) ($updatedProduct['is_public'] ?? 0), 'PIM bulk update changes public channel');
    $h->assertSame(1, (int) ($updatedProduct['is_ecommerce_enabled'] ?? 0), 'PIM bulk update changes ecommerce channel');
    $h->assertSame(0, (int) ($updatedProduct['is_pos_enabled'] ?? 1), 'PIM bulk update changes POS channel');

    $taxDeleteUsed = $controllerFor(1, 'DELETE', '/admin/api/business/pim/tax-classes/' . $taxClassId)->deleteTaxClass($taxClassId);
    $h->assertSame(422, $taxDeleteUsed->status(), 'catalog admin cannot delete used PIM tax class');

    $bulkCompleteness = $controllerFor(1, 'POST', '/admin/api/business/pim/products/bulk-recalculate', [], [
        'product_ids' => [$productId],
    ])->bulkRecalculate();
    $h->assertSame(200, $bulkCompleteness->status(), 'catalog admin can bulk recalculate PIM completeness');
    $h->assertSame(1, json_decode($bulkCompleteness->body(), true)['data']['bulk']['recalculated'], 'PIM bulk completeness returns recalculated count');

    $bulkArchive = $controllerFor(1, 'POST', '/admin/api/business/pim/products/bulk-update', [], [
        'product_ids' => [$productId],
        'changes' => ['archive' => true],
        'dry_run' => false,
    ])->bulkUpdateProducts();
    $h->assertSame(200, $bulkArchive->status(), 'catalog admin can archive products in bulk');
    $archivedProduct = $businessDb->one('SELECT status, archived_at FROM business_products WHERE id = ?', [$productId]);
    $h->assertSame('archived', $archivedProduct['status'] ?? null, 'PIM bulk archive changes status');
    $h->assertTrue(trim((string) ($archivedProduct['archived_at'] ?? '')) !== '', 'PIM bulk archive sets archived timestamp');

    $businessDb->run(
        "INSERT INTO business_products(site_id, type, status, visibility, sku_base, name, slug, unit, is_public, is_ecommerce_enabled, is_pos_enabled, is_catalogue_enabled)
         VALUES(1, 'bundle', 'draft', 'internal', 'BUNDLE-BULK-TEST', 'Bundle bulk test', 'bundle-bulk-test', 'unit', 0, 0, 0, 1)"
    );
    $bundleOfferId = (int) $businessDb->lastInsertId();
    $discount = $businessDb->one("SELECT id FROM business_catalog_discounts WHERE site_id = 1 AND archived_at IS NULL LIMIT 1");
    $discountId = (int) ($discount['id'] ?? 0);
    $h->assertTrue($bundleOfferId > 0 && $discountId > 0, 'bundle and discount offers are available for bulk update');

    $bulkOffersDryRun = $controllerFor(1, 'POST', '/admin/api/business/pim/offers/bulk-update', [], [
        'offers' => [
            ['kind' => 'bundle', 'id' => $bundleOfferId],
            ['kind' => 'discount', 'id' => $discountId],
        ],
        'changes' => ['status' => 'active', 'channel' => 'ecommerce'],
        'dry_run' => true,
    ])->bulkUpdateOffers();
    $h->assertSame(200, $bulkOffersDryRun->status(), 'catalog admin can dry-run offers bulk update');
    $bulkOffersDryRunPayload = json_decode($bulkOffersDryRun->body(), true)['data']['bulk'];
    $h->assertSame(true, $bulkOffersDryRunPayload['dry_run'], 'offers bulk update reports dry-run');
    $h->assertSame(1, $bulkOffersDryRunPayload['summary']['bundle'] ?? 0, 'offers bulk dry-run counts bundles');
    $h->assertSame(1, $bulkOffersDryRunPayload['summary']['discount'] ?? 0, 'offers bulk dry-run counts discounts');

    $bulkOffersApply = $controllerFor(1, 'POST', '/admin/api/business/pim/offers/bulk-update', [], [
        'offers' => [
            ['kind' => 'bundle', 'id' => $bundleOfferId],
            ['kind' => 'discount', 'id' => $discountId],
        ],
        'changes' => ['status' => 'active', 'channel' => 'ecommerce'],
        'dry_run' => false,
    ])->bulkUpdateOffers();
    $h->assertSame(200, $bulkOffersApply->status(), 'catalog admin can apply offers bulk update');
    $h->assertSame(2, json_decode($bulkOffersApply->body(), true)['data']['bulk']['updated'], 'offers bulk update reports mixed updates');
    $updatedBundleOffer = $businessDb->one('SELECT status, is_ecommerce_enabled FROM business_products WHERE id = ?', [$bundleOfferId]);
    $updatedDiscountOffer = $businessDb->one('SELECT status, channel FROM business_catalog_discounts WHERE id = ?', [$discountId]);
    $h->assertSame('active', $updatedBundleOffer['status'] ?? null, 'offers bulk update changes bundle status');
    $h->assertSame(1, (int) ($updatedBundleOffer['is_ecommerce_enabled'] ?? 0), 'offers bulk update changes bundle ecommerce channel');
    $h->assertSame('active', $updatedDiscountOffer['status'] ?? null, 'offers bulk update changes discount status');
    $h->assertSame('ecommerce', $updatedDiscountOffer['channel'] ?? null, 'offers bulk update changes discount channel');

    $exportOffers = $controllerFor(1, 'GET', '/admin/api/business/pim/offers/export.csv')->exportOffersCsv();
    $h->assertSame(200, $exportOffers->status(), 'catalog admin can export offers CSV');
    $h->assertTrue(str_contains($exportOffers->body(), 'offer_type;offer_id;name;status;channel'), 'offers export contains stable CSV headers');
    $h->assertTrue(str_contains($exportOffers->body(), 'bundle') && str_contains($exportOffers->body(), 'discount'), 'offers export contains bundle and discount rows');

    $offersCsv = implode("\n", [
        'offer_type;name;status;channel;sku;slug;pricing_mode;stock_mode;bundle_active;discount_type;discount_value;currency;scope_type;scope_id;priority',
        'bundle;Bundle import test;draft;all;BUNDLE-IMPORT-TEST;bundle-import-test;fixed;components;1;;;;;;',
        'discount;Réduction import test;active;pos;;;;;;percent;5;;product;' . $componentProductId . ';90',
    ]);
    $previewOffersImport = $controllerFor(1, 'POST', '/admin/api/business/pim/offers/import/preview', [], ['csv' => $offersCsv])->previewOffersImport();
    $h->assertSame(200, $previewOffersImport->status(), 'catalog admin can preview offers CSV import');
    $previewOffersPayload = json_decode($previewOffersImport->body(), true)['data']['import'];
    $h->assertSame(true, $previewOffersPayload['dry_run'], 'offers import preview is dry-run');
    $h->assertSame(2, $previewOffersPayload['valid_rows'], 'offers import preview validates bundle and discount rows');
    $h->assertSame(false, (bool) ($previewOffersPayload['writes_performed'] ?? false), 'offers import preview does not write');

    $applyOffersImport = $controllerFor(1, 'POST', '/admin/api/business/pim/offers/import/apply', [], ['csv' => $offersCsv])->applyOffersImport();
    $h->assertSame(200, $applyOffersImport->status(), 'catalog admin can apply offers CSV import');
    $applyOffersPayload = json_decode($applyOffersImport->body(), true)['data']['import'];
    $h->assertSame(false, $applyOffersPayload['dry_run'], 'offers import apply is not dry-run');
    $h->assertSame(1, $applyOffersPayload['created_bundles'], 'offers import creates bundle');
    $h->assertSame(1, $applyOffersPayload['created_discounts'], 'offers import creates discount');
    $h->assertTrue($businessDb->one("SELECT id FROM business_products WHERE slug = 'bundle-import-test' AND type = 'bundle'") !== null, 'offers import stores bundle product');
    $h->assertTrue($businessDb->one("SELECT id FROM business_catalog_discounts WHERE name = 'Réduction import test' AND channel = 'pos'") !== null, 'offers import stores discount');

    $bulkOffersArchive = $controllerFor(1, 'POST', '/admin/api/business/pim/offers/bulk-update', [], [
        'offers' => [
            ['kind' => 'bundle', 'id' => $bundleOfferId],
            ['kind' => 'discount', 'id' => $discountId],
        ],
        'changes' => ['archive' => true],
        'dry_run' => false,
    ])->bulkUpdateOffers();
    $h->assertSame(200, $bulkOffersArchive->status(), 'catalog admin can bulk archive offers');
    $archivedBundleOffer = $businessDb->one('SELECT status, archived_at FROM business_products WHERE id = ?', [$bundleOfferId]);
    $archivedDiscountOffer = $businessDb->one('SELECT status, archived_at FROM business_catalog_discounts WHERE id = ?', [$discountId]);
    $h->assertSame('archived', $archivedBundleOffer['status'] ?? null, 'offers bulk archive changes bundle status');
    $h->assertSame('archived', $archivedDiscountOffer['status'] ?? null, 'offers bulk archive changes discount status');
    $h->assertTrue(trim((string) ($archivedBundleOffer['archived_at'] ?? '')) !== '', 'offers bulk archive sets bundle archived timestamp');
    $h->assertTrue(trim((string) ($archivedDiscountOffer['archived_at'] ?? '')) !== '', 'offers bulk archive sets discount archived timestamp');

    $h->expectException(
        fn() => $controllerFor(4, 'POST', '/admin/api/business/pim/storefront-projections/rebuild', [], ['locale' => 'fr'])->rebuildStorefrontProjections(),
        ApiException::class,
        'ordinary catalog operator cannot rebuild storefront projections without the dedicated advanced-tools permission'
    );
    $h->assertSame(
        422,
        $controllerFor(1, 'POST', '/admin/api/business/pim/storefront-projections/rebuild', [], ['locale' => 'fr'])->rebuildStorefrontProjections()->status(),
        'advanced catalog administrator passes both permission guards before the optional projection service validation'
    );
    $h->assertSame(200,$controllerFor(2,'GET','/admin/api/business/pim/storefront-blocks/candidates',['locale'=>'fr'])->storefrontBlockCandidates()->status(),'catalog reader can search projected products for Studio');
    $previewResponse=$controllerFor(2,'POST','/admin/api/business/pim/storefront-blocks/preview',[],['locale'=>'fr','block'=>['type'=>'commerce_product_list','data'=>['selection_mode'=>'new']]])->previewStorefrontBlock();
    $h->assertSame(200,$previewResponse->status(),'catalog reader can preview a Commerce block');
    $h->assertSame('commerce_product_list',json_decode($previewResponse->body(),true)['data']['block']['type']??null,'Commerce preview preserves the stable block identifier');
    $h->expectException(fn()=>$controllerFor(3,'GET','/admin/api/business/pim/storefront-blocks/candidates')->storefrontBlockCandidates(),ApiException::class,'user without catalog read cannot search Studio Commerce candidates');

    $h->expectException(
        fn() => $controllerFor(3, 'GET', '/admin/api/business/pim/attributes')->attributes(),
        ApiException::class,
        'user without catalog read cannot access PIM endpoints'
    );
} finally {
    $_SESSION = [];
    $businessDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($iamDir);
    test_remove_tree($coreDir);
}

exit($h->finish('UNIT business PIM admin API'));
