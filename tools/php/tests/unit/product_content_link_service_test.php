<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Business\ProductContentLinkService;
use App\Core\Database;
use App\Infrastructure\Persistence\Sql\SqlCmsContentSource;
use App\Modules\Business\Repositories\ProductContentSourceRepository;

$h = new TestHarness();
$dir = sys_get_temp_dir() . '/dec-product-content-links-' . bin2hex(random_bytes(5));
mkdir($dir, 0775, true);

try {
    $core = new Database($dir . '/core.sqlite');
    $core->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/schema/core.sql'));
    $business = new Database($dir . '/business.sqlite');
    $business->pdo()->exec((string) file_get_contents(__DIR__ . '/../../../../database/modules/business.sql'));

    $core->run("INSERT INTO languages(code,name,locale,is_default,is_active,sort_order) VALUES('fr','Français','fr_CH',1,1,1),('en','English','en_GB',0,1,2)");
    $core->run("INSERT INTO sites(id,site_key,name,default_language_code,is_active) VALUES(1,'main','Main','fr',1),(2,'second','Second','en',1)");
    $core->run("INSERT INTO site_languages(site_id,language_code,url_prefix,is_default,is_active,sort_order) VALUES(1,'fr','',1,1,1),(1,'en','/en',0,1,2),(2,'en','',1,1,1)");
    $core->run("INSERT INTO content_types(id,type_key,name,singular_label,plural_label,is_system) VALUES(1,'page','Pages','Page','Pages',0)");
    $core->run("INSERT INTO content_entries(id,site_id,content_type_id,entry_key,status,workflow_state,is_active) VALUES(10,1,1,'product-page-fr','published','published',1),(11,1,1,'product-guide','draft','draft',1),(20,2,1,'other-site-page','draft','draft',1)");

    $product = $business->one("SELECT id FROM business_products WHERE site_id=1 AND status='active' AND is_public=1 AND archived_at IS NULL ORDER BY id LIMIT 1");
    $productId = (int) ($product['id'] ?? 0);
    $h->assertTrue($productId > 0, 'business seed exposes an active public product');

    $service = new ProductContentLinkService($core, new ProductContentSourceRepository($business), new SqlCmsContentSource($core));
    $link = $service->create(1, $productId, [
        'content_entry_id' => 10,
        'relation_type' => 'product_page',
        'locale' => 'fr',
        'is_canonical' => true,
        'seo_config' => ['schema_type' => 'Product'],
    ], 1);
    $h->assertTrue((int) ($link['id'] ?? 0) > 0, 'creates an explicit product/content link');
    $h->assertSame(1, count($service->listForProduct(1, $productId)), 'lists links in product context');

    $projection = $service->publicProductsForContent(1, 10, 'fr');
    $h->assertSame(1, count($projection), 'public content resolves its projected product without business reads');
    $h->assertSame('Product', $projection[0]['structured_data']['@type'] ?? null, 'projection contains configurable Product JSON-LD');
    $h->assertSame([], $service->publicProductsForContent(1, 10, 'en'), 'localized link is not exposed in another locale');

    $crossSiteRejected = false;
    try {
        $service->create(1, $productId, ['content_entry_id' => 20, 'relation_type' => 'guide'], 1);
    } catch (InvalidArgumentException) {
        $crossSiteRejected = true;
    }
    $h->assertTrue($crossSiteRejected, 'rejects cross-site content links');

    $missingProductRejected = false;
    try {
        $service->create(1, 999999, ['content_entry_id' => 11], 1);
    } catch (InvalidArgumentException) {
        $missingProductRejected = true;
    }
    $h->assertTrue($missingProductRejected, 'rejects missing products');

    $duplicateCanonicalRejected = false;
    try {
        $service->create(1, $productId, ['content_entry_id' => 11, 'relation_type' => 'guide', 'locale' => 'fr', 'is_canonical' => true], 1);
    } catch (Throwable) {
        $duplicateCanonicalRejected = true;
    }
    $h->assertTrue($duplicateCanonicalRejected, 'enforces one canonical content per product and locale');

    $deleteGuarded = false;
    try {
        $service->assertProductCanBeDeleted(1, $productId);
    } catch (InvalidArgumentException) {
        $deleteGuarded = true;
    }
    $h->assertTrue($deleteGuarded, 'prevents silent product deletion while links exist');
    $h->assertTrue($service->delete(1, (int) $link['id']), 'explicitly removes the link and projection');
    $service->assertProductCanBeDeleted(1, $productId);
    $h->assertSame([], $service->publicProductsForContent(1, 10, 'fr'), 'link deletion removes the public projection');
} finally {
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}

exit($h->finish('UNIT product content links'));
