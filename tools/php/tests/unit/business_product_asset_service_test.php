<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Services\BusinessProductAssetService;

$h = new TestHarness();
[$dir, $path, $db] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');

try {
    $assets = new BusinessProductAssetService($db);
    $h->assertSame(false, $db->tableExists('business_product_media'), 'from-scratch schema does not keep legacy product media table');
    $h->assertSame(true, $db->tableExists('business_product_assets'), 'from-scratch schema uses product assets table');

    $variantRow = $db->one("SELECT id, product_id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");
    $variantId = (int) ($variantRow['id'] ?? 0);
    $productId = (int) ($variantRow['product_id'] ?? 0);
    $h->assertTrue($variantId > 0 && $productId > 0, 'demo product and variant exist');

    $main = $assets->mainAssetForProduct($productId, $variantId, 'pos');
    $h->assertSame(5, $main['media_id'] ?? null, 'variant POS asset is resolved first');
    $h->assertSame($variantId, $main['variant_id'] ?? null, 'variant POS asset keeps variant id');
    $h->assertSame('Variante gourde bleue', $main['alt_text'] ?? null, 'variant POS asset keeps alt text');

    $db->run('UPDATE business_product_assets SET archived_at = CURRENT_TIMESTAMP WHERE variant_id = ?', [$variantId]);
    $fallback = $assets->mainAssetForProduct($productId, $variantId, 'pos');
    $h->assertSame(4, $fallback['media_id'] ?? null, 'product asset is used as fallback after variant asset archive');
    $h->assertSame(null, $fallback['variant_id'] ?? null, 'fallback asset is product-level');

    $assigned = $assets->assignAsset([
        'site_id' => 1,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'media_id' => 6,
        'role' => 'thumbnail',
        'title' => 'Thumbnail test',
        'alt_text' => 'Alt thumbnail test',
        'caption' => 'Caption test',
        'sort_order' => 0,
        'is_public' => true,
        'channel_scope' => 'ecommerce',
        'actor_iam_user_id' => 1,
    ]);
    $h->assertTrue((int) $assigned['asset_id'] > 0, 'assignAsset returns persisted asset');
    $h->assertSame(6, $assigned['media_id'], 'assigned asset keeps media id');
    $h->assertSame('thumbnail', $assigned['role'], 'assigned asset keeps role');
    $h->assertSame('ecommerce', $assigned['channel_scope'], 'assigned asset keeps channel scope');

    $db->run('UPDATE business_storefront_projection_invalidations SET processed_at = CURRENT_TIMESTAMP WHERE processed_at IS NULL');
    $updated = $assets->updateAsset((int) $assigned['asset_id'], [
        'site_id' => 1,
        'role' => 'variant',
        'caption' => 'Nouvelle légende publique',
    ]);
    $h->assertSame('variant', $updated['role'], 'updateAsset persists a guided storefront role');
    $h->assertTrue(
        (int) ($db->one("SELECT COUNT(*) c FROM business_storefront_projection_invalidations WHERE processed_at IS NULL AND reason = 'media'")['c'] ?? 0) > 0,
        'updating product media invalidates the Storefront projection'
    );

    $ecommerceAssets = $assets->listAssetsForProduct($productId, [
        'variant_id' => $variantId,
        'include_product_assets' => true,
        'channel' => 'ecommerce',
        'public_only' => true,
    ]);
    $mediaIds = array_map(static fn(array $asset): int => (int) $asset['media_id'], $ecommerceAssets);
    $h->assertTrue(in_array(6, $mediaIds, true), 'listAssetsForProduct returns newly assigned ecommerce asset');
    $h->assertTrue(in_array(4, $mediaIds, true), 'listAssetsForProduct includes product fallback assets');

    $db->run('UPDATE business_storefront_projection_invalidations SET processed_at = CURRENT_TIMESTAMP WHERE processed_at IS NULL');
    $assets->archiveAsset((int) $assigned['asset_id'], 1);
    $h->assertTrue(
        (int) ($db->one("SELECT COUNT(*) c FROM business_storefront_projection_invalidations WHERE processed_at IS NULL AND reason = 'media'")['c'] ?? 0) > 0,
        'archiving product media invalidates the Storefront projection'
    );
    $afterArchive = $assets->listAssetsForProduct($productId, [
        'variant_id' => $variantId,
        'include_product_assets' => true,
        'channel' => 'ecommerce',
        'public_only' => true,
    ]);
    $afterArchiveIds = array_map(static fn(array $asset): int => (int) $asset['asset_id'], $afterArchive);
    $h->assertTrue(!in_array((int) $assigned['asset_id'], $afterArchiveIds, true), 'archived asset disappears from active listings');

    $h->expectException(
        fn() => $assets->assignAsset([
            'site_id' => 1,
            'product_id' => $productId,
            'media_id' => 7,
            'role' => 'poster',
            'channel_scope' => 'pos',
        ]),
        InvalidArgumentException::class,
        'invalid product asset role is rejected'
    );
    $otherVariant=(int)($db->one("SELECT v.id FROM business_product_variants v WHERE v.product_id<>? ORDER BY v.id LIMIT 1",[$productId])['id']??0);
    $h->expectException(
        fn()=>$assets->assignAsset(['site_id'=>1,'product_id'=>$productId,'variant_id'=>$otherVariant,'media_id'=>8,'role'=>'variant']),
        InvalidArgumentException::class,
        'a product media link cannot target a variant owned by another product'
    );
    $internal=$assets->assignAsset(['site_id'=>1,'product_id'=>$productId,'media_id'=>9,'role'=>'internal','channel_scope'=>'ecommerce','is_public'=>true]);
    $h->assertSame(false,$internal['is_public'],'an internal file is always kept private');
    $h->assertSame('admin',$internal['channel_scope'],'an internal file is always scoped to administration');
} finally {
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('UNIT business product asset service'));
