<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Application\PublicApi\PublicApiKernel;
use App\Application\PublicApi\PublicContentApiHandler;
use App\Application\PublicApi\PublicCookieConsentApiHandler;
use App\Application\PublicApi\PublicCatalogApiHandler;
use App\Application\PublicApi\PosCatalogApiHandler;
use App\Application\PublicApi\PublicSaleApiHandler;
use App\Application\PublicApi\PublicFormApiHandler;
use App\Application\PublicApi\PublicSearchApiHandler;
use App\Application\PublicApi\PublicTaxonomyApiHandler;
use App\Core\Response;

final class PublicHeadlessController
{
    public function __construct(
        private readonly PublicApiKernel $api,
        private readonly PublicContentApiHandler $content,
        private readonly PublicTaxonomyApiHandler $taxonomies,
        private readonly PublicSearchApiHandler $searchHandler,
        private readonly PublicFormApiHandler $forms,
        private readonly PublicCookieConsentApiHandler $cookies,
        private readonly PublicCatalogApiHandler $catalog,
        private readonly PosCatalogApiHandler $posCatalog,
        private readonly PublicSaleApiHandler $sale,
    ) {}

    public function health(): Response { return $this->api->health(); }
    public function byRoute(): Response { return $this->api->byRoute(); }
    public function contentIndex(): Response { return $this->api->contentIndex(); }
    public function contentByType(string $type): Response { return $this->api->contentByType($type); }
    public function contentShow(string $type, string $slug): Response { return $this->api->contentShow($type, $slug); }
    public function contentByPath(): Response { return $this->content->byPath(); }
    public function routes(): Response { return $this->api->routes(); }
    public function languages(): Response { return $this->api->languages(); }
    public function menu(string $key): Response { return $this->api->menu($key); }
    public function menusIndex(): Response { return $this->api->menusIndex(); }
    public function taxonomiesIndex(): Response { return $this->api->taxonomiesIndex(); }
    public function taxonomy(string $taxonomy): Response { return $this->api->taxonomy($taxonomy); }
    public function search(): Response { return $this->api->search(); }
    public function mediaIndex(): Response { return $this->api->mediaIndex(); }
    public function media(string|int $id): Response { return $this->api->media($id); }
    public function formShow(string $key): Response { return $this->forms->show($key); }
    public function formSubmit(string $key): Response { return $this->forms->submit($key); }
    public function cookieConfig(): Response { return $this->cookies->config(); }
    public function cookieConsent(): Response { return $this->cookies->log(); }
    public function catalogBrands(): Response { return $this->catalog->brands(); }
    public function catalogCategories(): Response { return $this->catalog->categories(); }
    public function catalogProducts(): Response { return $this->catalog->products(); }
    public function catalogProduct(string $slug): Response { return $this->catalog->product($slug); }
    public function catalogVariant(string|int $id): Response { return $this->catalog->variant($id); }
    public function posCatalogBootstrap(): Response { return $this->posCatalog->bootstrap(); }
    public function posCatalogProducts(): Response { return $this->posCatalog->products(); }
    public function posCatalogVariants(): Response { return $this->posCatalog->variants(); }
    public function posCatalogBrands(): Response { return $this->posCatalog->brands(); }
    public function posCatalogCategories(): Response { return $this->posCatalog->categories(); }
    public function saleChannelBootstrap(string $code): Response { return $this->sale->bootstrap($code); }
    public function saleCartStore(string $code): Response { return $this->sale->storeCart($code); }
    public function saleCartShow(string $code, string $token): Response { return $this->sale->cart($code, $token); }
    public function saleCartLineStore(string $code, string $token): Response { return $this->sale->addLine($code, $token); }
    public function saleCartLineUpdate(string $code, string $token, string|int $line_id): Response { return $this->sale->updateLine($code, $token, $line_id); }
    public function saleCartLineDelete(string $code, string $token, string|int $line_id): Response { return $this->sale->deleteLine($code, $token, $line_id); }
    public function saleCheckout(string $code): Response { return $this->sale->checkout($code); }
}
