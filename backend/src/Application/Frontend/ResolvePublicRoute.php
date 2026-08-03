<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Application\Cookies\CookieConsentRepository;
use App\Application\Content\BlockDocumentNormalizer;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Application\Media\Storage\MediaUrlGenerator;
use App\Application\Search\PublicSearchReadRepository;
use App\Core\Database;
use App\Application\Business\ProductContentLinkService;
use App\Application\Business\StorefrontProjectionRepository;
use App\Application\Commerce\StorefrontMerchandisingService;

final class ResolvePublicRoute
{
    private MediaUrlGenerator $mediaUrls;

    public function __construct(
        private readonly PublicContentReadRepository $content,
        private readonly PublicSearchReadRepository $search,
        private readonly \App\Application\Routing\PublicRouteReadRepository $routes,
        private readonly SiteReadRepository $sites,
        private readonly TaxonomyReadRepository $taxonomies,
        private readonly Database $db,
        private readonly ?CookieConsentRepository $cookies = null,
        private readonly ?BlockDocumentNormalizer $blocks = null,
        private readonly ?ProductContentLinkService $productContentLinks = null,
        private readonly ?StorefrontProjectionRepository $storefront = null,
        private readonly ?StorefrontMerchandisingService $merchandising = null,
    ) {
        $this->mediaUrls = new MediaUrlGenerator($db);
    }

    public function execute(array $site, string $languageCode, string $path, array $query = []): array
    {
        $isShopPath = $path === '/shop'
            || str_starts_with($path, '/shop/products/')
            || str_starts_with($path, '/shop/collections/');
        if ($storefront = $this->storefrontPayload($site,$languageCode,$path,$query)) {
            return ['type'=>'payload','status'=>200,'payload'=>$storefront];
        }
        // /shop is a protected system route. An inactive or unavailable Shop
        // must never fall through to an ordinary CMS page using the same path.
        if ($isShopPath) {
            return ['type'=>'payload','status'=>404,'payload'=>$this->notFoundPayload($site,$languageCode,$path)];
        }
        if ($redirect = $this->routes->findRedirect((int) $site['id'], $path, $languageCode)) {
            return ['type' => 'redirect', 'to' => (string) $redirect['new_path'], 'status' => (int) $redirect['http_code']];
        }
        if ($tombstone = $this->routes->findTombstone((int) $site['id'], $path, $languageCode)) {
            return ['type' => 'payload', 'status' => 410, 'payload' => $this->tombstonePayload($site, $languageCode, $path, $tombstone)];
        }
        if ($path === '/articles') {
            return ['type' => 'payload', 'status' => 200, 'payload' => $this->articleIndexPayload($site, $languageCode, $path, $query)];
        }
        if ($archive = $this->taxonomies->findArchiveByPath((int) $site['id'], $path, $languageCode)) {
            return ['type' => 'payload', 'status' => 200, 'payload' => $this->archivePayload($site, $languageCode, $path, $archive)];
        }
        $aggregate = $this->content->getPublishedByPath((int) $site['id'], $path, $languageCode);
        if (!$aggregate) {
            return ['type' => 'payload', 'status' => 404, 'payload' => $this->notFoundPayload($site, $languageCode, $path)];
        }

        $payload = $this->entryPayload($site, $languageCode, $aggregate, false, $query);
        if ((string) ($aggregate['entry']['entry_key'] ?? '') === 'sitemap' || (string) ($payload['template'] ?? '') === 'sitemap') {
            $payload = $this->sitemapPagePayload($site, $languageCode, $payload);
        }

        return ['type' => 'payload', 'status' => 200, 'payload' => $payload];
    }

    /** @return array<string,mixed>|null */
    private function storefrontPayload(array $site,string $locale,string $path,array $query): ?array
    {
        if ($this->storefront===null || !($path==='/shop' || str_starts_with($path,'/shop/products/') || str_starts_with($path,'/shop/collections/'))) return null;
        $siteId=(int)$site['id'];
        $shop=$this->storefront->activeShop($siteId,$locale);
        $channel=(int)($shop['channel_id']??0);
        if ($channel<1) return null;
        if (preg_match('#^/shop/products/([a-z0-9_-]+)$#',$path,$m)) {
            $product=$this->storefront->product($siteId,$channel,$locale,$m[1]); if (!$product) return null;
            $product=$this->localizeStorefrontItem($product,$locale);
            $base=$this->basePayload($site,$locale,$path,(string)$product['name']);
            $productLanguages=[];
            foreach ((array)($product['seo']['hreflang']??[]) as $alternate) {
                if (!is_array($alternate) || trim((string)($alternate['locale']??''))==='') continue;
                $code=(string)$alternate['locale'];
                $relative=(string)($alternate['url']??$path);
                $absolute=localized_absolute_url($relative,$code,(string)$site['base_url']);
                $productLanguages[]=['code'=>$code,'hreflang'=>$code,'url'=>$absolute,'absolute_url'=>$absolute,'label'=>strtoupper($code),'is_active'=>$code===$locale,'is_default'=>false];
            }
            if ($productLanguages!==[]) {
                $base['languages']=$productLanguages;
                $base['language_menu_items']=$productLanguages;
                $base['x_default_url']=$productLanguages[0]['absolute_url'];
            }
            $returnUrl=$this->storefrontReturnUrl($query['return']??null,$locale);
            return $base+['template'=>'storefront-product','storefront_product'=>$product,'storefront_return_url'=>$returnUrl,'entry_title'=>$product['name'],'meta_title'=>$product['name'],'meta_description'=>$product['summary'],'meta_robots'=>$product['seo']['robots'],'canonical'=>localized_absolute_url($product['seo']['canonical'],$locale,(string)$site['base_url']),'json_ld'=>json_encode($product['seo']['json_ld'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'resource'=>$product];
        }
        if (preg_match('#^/shop/collections/([a-z0-9_-]+)$#',$path,$m)) {
            $collection=$this->storefront->collection($siteId,$channel,$locale,$m[1]); if (!$collection) return null;
            $collection=$this->localizeStorefrontItem($collection,$locale);
            $page=$this->storefront->products($siteId,$channel,$locale,array_merge($query,['collection_id'=>$collection['collection_id'],'limit'=>max(1,min(100,(int)($query['limit']??24))),'offset'=>max(0,(int)($query['offset']??0))]));
            $page=$this->decorateCatalogPage($page,$path,$locale);
            $base=$this->basePayload($site,$locale,$path,(string)$collection['name']);
            return $base+['template'=>'storefront-collection','storefront_collection'=>$collection,'storefront_products'=>$page['items'],'storefront_catalog'=>$page,'storefront_facets'=>$page['facets'],'storefront_selection'=>$page['selection'],'storefront_sorts'=>$page['sorts'],'pagination'=>$page['pagination'],'entry_title'=>$collection['name'],'meta_title'=>$collection['name'],'meta_description'=>$collection['description'],'meta_robots'=>$this->catalogRobots($query,$collection['seo']['robots']),'canonical'=>localized_absolute_url($collection['seo']['canonical'],$locale,(string)$site['base_url']),'resource'=>['collection'=>$collection,'products'=>$page]];
        }
        $filters=array_merge($query,['limit'=>max(1,min(100,(int)($query['limit']??24))),'offset'=>max(0,(int)($query['offset']??0))]);
        $published=is_array($shop['published']??null)?$shop['published']:[];
        $seo=is_array($published['seo']??null)?$published['seo']:[];
        $title=trim((string)($published['title']??'')) ?: ($locale==='en'?'Shop':'Boutique');
        $page=$this->decorateCatalogPage($this->storefront->products($siteId,$channel,$locale,$filters),$path,$locale); $base=$this->basePayload($site,$locale,$path,$title);
        $sections=$this->merchandising?->sections($siteId,$channel,$locale,(array)($published['sections']??[]),$page) ?? [];
        foreach ($sections as $sectionIndex=>$section) {
            foreach ((array)($section['items']??[]) as $itemIndex=>$item) {
                if (is_array($item) && (int)($item['product_id']??0)>0 && (string)($item['url']??'')!=='') {
                    $sections[$sectionIndex]['items'][$itemIndex]['url_with_return']=(string)$item['url'].'?return='.rawurlencode($page['current_url']);
                }
            }
        }
        $collections=array_map(fn(array $item):array=>$this->localizeStorefrontItem($item,$locale),$this->storefront->collections($siteId,$channel,$locale));
        return $base+['template'=>'storefront-shop','storefront_products'=>$page['items'],'storefront_collections'=>$collections,'storefront_sections'=>$published['sections']??[],'storefront_merchandising_sections'=>$sections,'storefront_introduction'=>(string)($published['introduction']??''),'storefront_catalog'=>$page,'storefront_facets'=>$page['facets'],'storefront_selection'=>$page['selection'],'storefront_sorts'=>$page['sorts'],'pagination'=>$page['pagination'],'entry_title'=>$title,'meta_title'=>(string)($seo['title']??$title),'meta_description'=>(string)($seo['description']??''),'meta_robots'=>$this->catalogRobots($query,(string)($seo['robots']??'index,follow')),'canonical'=>localized_absolute_url('/shop',$locale,(string)$site['base_url']),'resource'=>['products'=>$page,'shop'=>$shop,'merchandising_sections'=>$sections]];
    }

    /** @param array<string,mixed> $query */
    private function catalogRobots(array $query,string $default): string
    {
        foreach (['q','sort','brand','brands','category','categories','group','groups','availability','attributes','attr','offset'] as $key) {
            if (array_key_exists($key,$query) && $query[$key] !== '' && $query[$key] !== []) return 'noindex,follow';
        }
        return $default;
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    private function decorateCatalogPage(array $page,string $path,string $locale): array
    {
        $selection=is_array($page['selection']??null)?$page['selection']:[];
        $params=$this->catalogQueryParams($selection);
        $path=localized_path($path,$locale);
        $page['reset_url']=$path;
        $page['current_url']=$this->catalogUrl($path,$params);
        $page['active_filters']=[];
        foreach ((array)($page['facets']??[]) as $facetIndex=>$facet) {
            if (!is_array($facet)) continue;
            foreach ((array)($facet['options']??[]) as $optionIndex=>$option) {
                if (!is_array($option)) continue;
                $next=$params; $key=(string)($facet['key']??''); $value=(string)($option['key']??'');
                if (($facet['type']??'')==='attribute') {
                    $values=(array)($next['attributes'][$key]??[]);
                    $next['attributes'][$key]=$this->toggleCatalogValue($values,$value);
                    if ($next['attributes'][$key]===[]) unset($next['attributes'][$key]);
                    if (($next['attributes']??[])===[]) unset($next['attributes']);
                } else {
                    $next[$key]=$this->toggleCatalogValue((array)($next[$key]??[]),$value);
                    if ($next[$key]===[]) unset($next[$key]);
                    if ($key==='group' && count((array)($next['group']??[]))!==1) unset($next['attributes']);
                }
                unset($next['offset']);
                $url=$this->catalogUrl($path,$next);
                $page['facets'][$facetIndex]['options'][$optionIndex]['toggle_url']=$url;
                if (!empty($option['selected'])) $page['active_filters'][]=['label'=>(string)($facet['label']??$key).': '.(string)($option['label']??$value),'remove_url'=>$url];
            }
        }
        $pagination=is_array($page['pagination']??null)?$page['pagination']:[];
        $offset=(int)($pagination['offset']??0); $limit=max(1,(int)($pagination['limit']??24));
        if ($offset>0) { $previous=$params; $previous['offset']=max(0,$offset-$limit); if ($previous['offset']===0) unset($previous['offset']); $page['pagination']['previous_url']=$this->catalogUrl($path,$previous); }
        if (!empty($pagination['has_more'])) { $next=$params; $next['offset']=$offset+$limit; $page['pagination']['next_url']=$this->catalogUrl($path,$next); }
        $return=$page['current_url'];
        foreach ((array)($page['items']??[]) as $index=>$item) if (is_array($item)) {
            $item=$this->localizeStorefrontItem($item,$locale);
            $item['url_with_return']=(string)($item['url']??'').'?return='.rawurlencode($return);
            $page['items'][$index]=$item;
        }
        return $page;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function localizeStorefrontItem(array $item,string $locale): array
    {
        if (is_string($item['url']??null) && str_starts_with($item['url'],'/shop')) {
            $item['url']=localized_path($item['url'],$locale);
        }
        $item=$this->localizeStorefrontMedia($item);
        foreach ((array)($item['relations']??[]) as $relationIndex=>$relation) {
            if (!is_array($relation)) continue;
            foreach ((array)($relation['items']??[]) as $relatedIndex=>$related) {
                if (is_array($related)) $item['relations'][$relationIndex]['items'][$relatedIndex]=$this->localizeStorefrontItem($related,$locale);
            }
        }
        return $item;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function localizeStorefrontMedia(array $item): array
    {
        foreach (['image_url','media_url','thumbnail_url'] as $key) if (is_string($item[$key]??null)) $item[$key]=public_asset_url_path($item[$key]);
        foreach (['media','images','documents'] as $collection) foreach ((array)($item[$collection]??[]) as $index=>$media) {
            if (!is_array($media)) continue;
            foreach (['url','src','image_url','thumbnail_url'] as $key) if (is_string($media[$key]??null)) $item[$collection][$index][$key]=public_asset_url_path($media[$key]);
        }
        foreach ((array)($item['sellables']??[]) as $index=>$sellable) if (is_array($sellable)) $item['sellables'][$index]=$this->localizeStorefrontMedia($sellable);
        return $item;
    }

    private function storefrontReturnUrl(mixed $candidate,string $locale): string
    {
        $publicShop=localized_path('/shop',$locale);
        if (!is_string($candidate) || str_contains($candidate,'//')) return $publicShop;
        if ($candidate==='/shop' || str_starts_with($candidate,'/shop?') || str_starts_with($candidate,'/shop/')) {
            return localized_path($candidate,$locale);
        }
        if ($candidate===$publicShop || str_starts_with($candidate,$publicShop.'?') || str_starts_with($candidate,$publicShop.'/')) {
            return $candidate;
        }
        return $publicShop;
    }

    /** @param array<string,mixed> $selection @return array<string,mixed> */
    private function catalogQueryParams(array $selection): array
    {
        $params=[];
        if (($selection['q']??'')!=='') $params['q']=$selection['q'];
        if (($selection['sort']??'name')!=='name') $params['sort']=$selection['sort'];
        foreach (['brand','category','group','availability'] as $key) if (($selection[$key]??[])!==[]) $params[$key]=array_values((array)$selection[$key]);
        if (($selection['attributes']??[])!==[]) $params['attributes']=$selection['attributes'];
        return $params;
    }

    /** @param list<string> $values @return list<string> */
    private function toggleCatalogValue(array $values,string $value): array
    {
        $values=array_values(array_map('strval',$values));
        if (in_array($value,$values,true)) return array_values(array_filter($values,static fn(string $item):bool=>$item!==$value));
        $values[]=$value; return array_values(array_unique($values));
    }

    /** @param array<string,mixed> $params */
    private function catalogUrl(string $path,array $params): string
    { $query=http_build_query($params,'','&',PHP_QUERY_RFC3986); return $path.($query===''?'':'?'.$query); }

    public function entryPayload(array $site, string $languageCode, array $aggregate, bool $isPreview, array $query = []): array
    {
        $entry = $aggregate['entry'];
        $loc = $aggregate['localization'] ?? [];
        $template = str_replace(['.php', '.twig'], '', $entry['frontend_template'] ?: ($entry['type_key'] === 'article' ? 'article' : 'page'));
        $path = (string) ($aggregate['route']['full_path'] ?? '/');
        $title = (string) ($loc['title'] ?? $entry['entry_key']);
        $summary = (string) ($aggregate['seo']['meta_description'] ?? '');
        $payload = $this->basePayload($site, $languageCode, $path, $title);
        $resourceSeoAlternates = $this->resourceLanguageSwitchItems($site, $languageCode, (string) ($entry['entry_key'] ?? ''), (int) ($entry['id'] ?? 0), false);
        $resourceNavigationAlternates = $this->resourceLanguageSwitchItems($site, $languageCode, (string) ($entry['entry_key'] ?? ''), (int) ($entry['id'] ?? 0), true);
        if ($resourceNavigationAlternates !== []) {
            // Le menu public des langues doit suivre la clé d'entrée, même pour les
            // ressources noindex comme les mentions légales. Les balises hreflang SEO
            // restent, elles, filtrées plus strictement ci-dessous.
            $payload['language_menu_items'] = $this->mergeLanguageMenuWithResourceAlternates($payload['language_menu_items'] ?? [], $resourceNavigationAlternates);
        }
        if ($resourceSeoAlternates !== []) {
            $payload['languages'] = $resourceSeoAlternates;
            $payload['x_default_url'] = $this->xDefaultUrl($resourceSeoAlternates);
        } elseif ($resourceNavigationAlternates !== []) {
            $payload['languages'] = [];
            $payload['x_default_url'] = '';
        }

        $commerceProducts = $this->productContentLinks?->publicProductsForContent((int)($site['id']??0),(int)($entry['id']??0),$languageCode) ?? [];
        $currentProductId=(int)($commerceProducts[0]['product']['id']??$commerceProducts[0]['product']['product_id']??$commerceProducts[0]['product_id']??0);
        $blocks = $this->entryBlocks($aggregate, $site, $languageCode, $query);
        $blocks = $this->productContentLinks?->hydrateStorefrontBlocks($blocks, (int)($site['id']??0), $languageCode, ['current_product_id'=>$currentProductId,'query'=>$query]) ?? $blocks;
        if ($isPreview) {
            $blocks = $this->annotatePreviewBlockStatuses($blocks, $aggregate);
        }
        $publicFields = $this->publicDocumentFields($aggregate);
        $articleDisplay = (string) ($entry['type_key'] ?? '') === 'article'
            ? $this->articleDetailDisplaySettings($aggregate, (int) ($site['id'] ?? 0))
            : $this->defaultArticleDetailDisplaySettings((int) ($site['id'] ?? 0));
        $entryPublishedAt = $this->displayPublishedAt($publicFields, (string) ($entry['published_at'] ?? ''));
        $entryUpdatedAt = (string) ($entry['updated_at'] ?? '');
        $entryAuthorName = $this->displayAuthorName($publicFields);
        $cookiePolicy = ((string) ($entry['entry_key'] ?? '') === 'cookies')
            ? $this->cookiePolicy((int) $site['id'], $languageCode, (string) ($site['default_language_code'] ?? 'fr'))
            : null;
        $baseUrl = (string) ($site['base_url'] ?? '');
        $ogImagePath = $this->seoMediaUrl(is_numeric($aggregate['seo']['og_image_media_id'] ?? null) ? (int) $aggregate['seo']['og_image_media_id'] : 0, 'open_graph') ?: (string) ($payload['og_image'] ?? '');
        $ogImage = $this->absolutePublicUrl($ogImagePath, $baseUrl);
        $twitterImagePath = $this->seoMediaUrl(is_numeric($aggregate['seo']['twitter_image_media_id'] ?? null) ? (int) $aggregate['seo']['twitter_image_media_id'] : 0, 'open_graph') ?: $ogImage;
        $twitterImage = $this->absolutePublicUrl($twitterImagePath, $baseUrl);
        $pageJsonLd = $this->jsonLd((string) ($aggregate['seo']['json_ld'] ?? ''), $site, $languageCode, $path, $title, $summary, (string) ($entry['type_key'] ?? 'page'), $entryPublishedAt, $entryUpdatedAt, $payload['breadcrumbs'] ?? [], $blocks, !empty($articleDisplay['include_author_in_schema']) ? $entryAuthorName : '');
        return $payload + [
            'title' => $title,
            'entry_key' => (string) ($entry['entry_key'] ?? ''),
            'entry_title' => $title,
            'entry_summary' => $summary,
            'entry_blocks' => $blocks,
            'cookie_policy' => $cookiePolicy,
            'entry_type_label' => (string) ($entry['singular_label'] ?? 'Contenu'),
            'entry_published_at' => $entryPublishedAt,
            'entry_published_at_label' => $this->formatPublicDate($entryPublishedAt, (string) $articleDisplay['date_format'], $languageCode),
            'entry_updated_at' => $entryUpdatedAt,
            'entry_updated_at_label' => $this->formatPublicDate($entryUpdatedAt, (string) $articleDisplay['date_format'], $languageCode),
            'entry_author_name' => $entryAuthorName,
            'article_display' => $articleDisplay,
            'is_home' => $path === '/',
            'is_preview' => $isPreview,
            'meta_title' => (string) ($aggregate['seo']['meta_title'] ?? $title),
            'meta_description' => (string) ($aggregate['seo']['meta_description'] ?? $summary),
            'meta_robots' => (string) ($aggregate['seo']['meta_robots'] ?? 'index,follow'),
            'canonical' => localized_absolute_url((string) ($aggregate['seo']['canonical_url'] ?? $path), $languageCode, (string) ($site['base_url'] ?? '')),
            'og_type' => $entry['type_key'] === 'article' ? 'article' : 'website',
            'article_published_time' => $entry['type_key'] === 'article' ? $entryPublishedAt : '',
            'article_modified_time' => $entry['type_key'] === 'article' ? $entryUpdatedAt : '',
            'og_title' => (string) ($aggregate['seo']['og_title'] ?? $aggregate['seo']['meta_title'] ?? $title),
            'og_description' => (string) ($aggregate['seo']['og_description'] ?? $aggregate['seo']['meta_description'] ?? $summary),
            'twitter_title' => (string) ($aggregate['seo']['twitter_title'] ?? $aggregate['seo']['meta_title'] ?? $title),
            'twitter_description' => (string) ($aggregate['seo']['twitter_description'] ?? $aggregate['seo']['meta_description'] ?? $summary),
            'json_ld' => $this->mergeCommerceJsonLd($pageJsonLd, $commerceProducts),
            'commerce_products' => $commerceProducts,
            'geo_summary' => $this->geoSummary($title, $summary),
            'ai_summary' => $this->geoSummary($title, $summary),
            'resource' => $aggregate,
            'template' => $template,
        ];
    }

    /** @param list<array<string,mixed>> $products */
    private function mergeCommerceJsonLd(string $pageJsonLd, array $products): string
    {
        if ($products === []) {
            return $pageJsonLd;
        }
        $graph = [];
        $page = json_decode($pageJsonLd, true);
        if (is_array($page)) {
            if (is_array($page['@graph'] ?? null)) {
                $graph = array_values($page['@graph']);
            } else {
                unset($page['@context']);
                $graph[] = $page;
            }
        }
        foreach ($products as $product) {
            if (is_array($product['structured_data'] ?? null)) {
                $structuredData = $product['structured_data'];
                unset($structuredData['@context']);
                $graph[] = $structuredData;
            }
        }
        return (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }


    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sitemapPagePayload(array $site, string $languageCode, array $payload): array
    {
        $siteId = (int) $site['id'];
        $baseUrl = (string) ($site['base_url'] ?? '');
        $texts = $this->sitemapUi($languageCode);
        $items = $this->routes->listPublishedRoutes($siteId, $languageCode);
        $seen = [];
        $groups = [];

        foreach ($items as $item) {
            $path = $this->safeSitemapPath((string) ($item['full_path'] ?? ''));
            if ($path === null) {
                continue;
            }

            $url = localized_path($path, $languageCode);
            $absoluteUrl = localized_absolute_url($path, $languageCode, $baseUrl);
            $key = strtolower($absoluteUrl);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
            $groupKey = $parts[0] ?? '__root__';
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = $path === '/' ? $texts['home'] : ucwords(str_replace(['-', '_'], ' ', (string) end($parts)));
            }

            $groups[$groupKey][] = [
                'title' => $title,
                'url' => $url,
                'absolute_url' => $absoluteUrl,
                'lastmod' => $this->sitemapLastModified($item),
            ];
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $cards = [];
        foreach ($groups as $groupKey => $links) {
            $cards[] = [
                'title' => $this->sitemapGroupTitle($groupKey, $texts),
                'count' => count($links),
                'links' => $links,
            ];
        }

        return $payload + [
            'template' => 'sitemap',
            'meta_robots' => (string) ($payload['meta_robots'] ?? 'index,follow'),
            'sitemap_cards' => $cards,
            'sitemap_count' => count($seen),
            'sitemap_xml_url' => localized_path('/sitemap.xml', $languageCode),
            'sitemap_home_url' => localized_path('/', $languageCode),
            'sitemap_ui' => $texts,
        ];
    }

    /** @return array<string,string> */
    private function sitemapUi(string $languageCode): array
    {
        $texts = [
            'fr' => [
                'eyebrow' => 'Plan du site',
                'title' => 'Explorer les contenus publiés',
                'intro' => 'Cette page présente les contenus publics du site dans une vue claire, hiérarchisée et accessible.',
                'xml' => 'Sitemap XML',
                'home_action' => 'Retour à l’accueil',
                'xml_count' => 'URL dans le sitemap XML',
                'empty_title' => 'Aucune URL indexable',
                'empty_text' => 'Le sitemap public ne contient actuellement aucune page publiée et indexable.',
                'updated' => 'Mis à jour',
                'home' => 'Accueil',
                'main_pages' => 'Pages principales',
                'articles' => 'Articles',
                'categories' => 'Catégories',
                'tags' => 'Tags',
                'section' => 'Section',
            ],
            'en' => [
                'eyebrow' => 'Sitemap',
                'title' => 'Explore published content',
                'intro' => 'This page presents the public content of the site in a clear, structured and accessible view.',
                'xml' => 'XML sitemap',
                'home_action' => 'Back to home',
                'xml_count' => 'URLs in the XML sitemap',
                'empty_title' => 'No indexable URL',
                'empty_text' => 'The public sitemap does not currently contain any published and indexable page.',
                'updated' => 'Updated',
                'home' => 'Home',
                'main_pages' => 'Main pages',
                'articles' => 'Articles',
                'categories' => 'Categories',
                'tags' => 'Tags',
                'section' => 'Section',
            ],
            'de' => [
                'eyebrow' => 'Sitemap',
                'title' => 'Veröffentlichte Inhalte entdecken',
                'intro' => 'Diese Seite zeigt die öffentlichen Inhalte der Website klar, strukturiert und zugänglich.',
                'xml' => 'XML-Sitemap',
                'home_action' => 'Zur Startseite',
                'xml_count' => 'URLs in der XML-Sitemap',
                'empty_title' => 'Keine indexierbare URL',
                'empty_text' => 'Die öffentliche Sitemap enthält derzeit keine veröffentlichte und indexierbare Seite.',
                'updated' => 'Aktualisiert',
                'home' => 'Startseite',
                'main_pages' => 'Hauptseiten',
                'articles' => 'Artikel',
                'categories' => 'Kategorien',
                'tags' => 'Tags',
                'section' => 'Rubrik',
            ],
        ];

        return $texts[strtolower($languageCode)] ?? $texts['en'];
    }

    /** @param array<string,string> $texts */
    private function sitemapGroupTitle(string $groupKey, array $texts): string
    {
        return match ($groupKey) {
            '__root__' => $texts['main_pages'],
            'articles' => $texts['articles'],
            'categories' => $texts['categories'],
            'tags' => $texts['tags'],
            default => ucwords(str_replace(['-', '_'], ' ', $groupKey)),
        };
    }

    private function safeSitemapPath(string $path): ?string
    {
        $path = '/' . ltrim(trim($path), '/');
        if ($path === '//') {
            $path = '/';
        }
        if ($path === '' || str_contains($path, '//')) {
            return null;
        }
        if (preg_match('#^/(admin|api|preview)(/|$)#', $path)) {
            return null;
        }
        if (preg_match('#\.(xml|xsl|json|txt)$#i', $path)) {
            return null;
        }
        return $path;
    }

    /** @param array<string,mixed> $item */
    private function sitemapLastModified(array $item): ?string
    {
        foreach (['published_at', 'updated_at', 'route_updated_at'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return gmdate('Y-m-d', $timestamp);
            }
        }
        return null;
    }

    public function searchPayload(array $site, string $languageCode, string $query, array $queryParams = []): array
    {
        $ui = $this->ui($languageCode);
        $filters = [
            'type' => $this->safeFilterSlug((string) ($queryParams['type'] ?? '')),
            'taxonomy' => $this->safeFilterSlug((string) ($queryParams['taxonomy'] ?? '')),
            'term' => $this->safeFilterSlug((string) ($queryParams['term'] ?? '')),
        ];
        $results = $query !== '' ? $this->search->searchDocuments($query, $languageCode, (int) $site['id'], $filters) : [];
        return $this->basePayload($site, $languageCode, '/search', $ui['search_title']) + [
            'title' => $ui['search_title'],
            'search_query' => $query,
            'search_results' => $this->normalizeSearchResults($results, $languageCode, $query),
            'search_filters' => $filters,
            'meta_title' => $ui['search_title'],
            'meta_description' => $ui['search_description'],
            'meta_robots' => 'noindex,follow',
            'canonical' => localized_absolute_url('/search', $languageCode, (string) ($site['base_url'] ?? '')),
            'json_ld' => $this->jsonLd('', $site, $languageCode, '/search', $ui['search_title'], $ui['search_description'], 'search', '', '', $this->breadcrumbs($languageCode, '/search', $ui['search_title'])),
            'template' => 'search',
        ];
    }


    private function articleIndexPayload(array $site, string $languageCode, string $path, array $query = []): array
    {
        $ui = $this->ui($languageCode);
        $pageAggregate = $this->content->getPublishedByPath((int) $site['id'], $path, $languageCode);
        $settings = $this->articleIndexSettings($pageAggregate);
        $articleDisplay = $this->defaultArticleDetailDisplaySettings((int) ($site['id'] ?? 0));
        $limit = max(1, min(48, (int) ($query['limit'] ?? $settings['article_limit'])));
        $page = max(1, (int) ($query['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $categorySlug = $this->safeFilterSlug((string) ($query['category'] ?? ''));
        $tagSlug = $this->safeFilterSlug((string) ($query['tag'] ?? ''));

        $listing = $this->content->listPublishedArticlesIndex(
            (int) $site['id'],
            $languageCode,
            $limit,
            $offset,
            $categorySlug !== '' ? $categorySlug : null,
            $tagSlug !== '' ? $tagSlug : null,
        );
        $facets = $this->taxonomies->articleFacets((int) $site['id'], $languageCode, (int) $settings['tag_limit']);
        $title = $pageAggregate ? (string) ($pageAggregate['localization']['title'] ?? $ui['articles_title']) : $ui['articles_title'];
        $summary = $pageAggregate ? (string) ($pageAggregate['seo']['meta_description'] ?? $ui['articles_description']) : $ui['articles_description'];
        $blocks = $pageAggregate ? $this->entryBlocks($pageAggregate, $site, $languageCode, $query) : [];
        $splitBlocks = $this->splitArticleIndexBlocks($blocks);
        $totalPages = $limit > 0 ? max(1, (int) ceil(((int) $listing['total']) / $limit)) : 1;
        $canonicalPath = $path;

        return $this->basePayload($site, $languageCode, $path, $title) + [
            'title' => $title,
            'entry_key' => $pageAggregate ? (string) ($pageAggregate['entry']['entry_key'] ?? 'articles') : 'articles',
            'entry_title' => $title,
            'entry_summary' => $summary,
            'entry_blocks' => $blocks,
            'article_index_blocks_before' => $splitBlocks['before'],
            'article_index_blocks_after' => $splitBlocks['after'],
            'article_items' => $this->normalizeArticleIndexItems($listing['items'], $languageCode, (string) $articleDisplay['date_format']),
            'article_facets' => $this->normalizeArticleFacets($facets, $languageCode),
            'article_display' => [
                'show_published_date' => (bool) $articleDisplay['show_published_date'],
                'show_author' => (bool) $articleDisplay['show_author'],
                'show_updated_date' => (bool) $articleDisplay['show_updated_date'],
                'show_type' => (bool) $articleDisplay['show_type'],
                'include_author_in_schema' => (bool) $articleDisplay['include_author_in_schema'],
                'date_format' => (string) $articleDisplay['date_format'],
            ],
            'article_filter' => [
                'category' => $categorySlug,
                'tag' => $tagSlug,
                'limit' => $limit,
                'page' => $page,
                'total' => (int) $listing['total'],
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => !empty($listing['has_more']),
                'previous_url' => $page > 1 ? $this->articleIndexUrl($languageCode, $categorySlug, $tagSlug, $limit, $page - 1) : '',
                'next_url' => !empty($listing['has_more']) ? $this->articleIndexUrl($languageCode, $categorySlug, $tagSlug, $limit, $page + 1) : '',
                'clear_url' => localized_path('/articles', $languageCode),
            ],
            'meta_title' => $pageAggregate ? (string) ($pageAggregate['seo']['meta_title'] ?? $title) : $title,
            'meta_description' => $summary,
            'meta_robots' => $page > 1 || $categorySlug !== '' || $tagSlug !== '' ? 'noindex,follow' : 'index,follow',
            'canonical' => localized_absolute_url($canonicalPath, $languageCode, (string) ($site['base_url'] ?? '')),
            'json_ld' => $this->jsonLd('', $site, $languageCode, $canonicalPath, $title, $summary, 'archive', '', '', $this->breadcrumbs($languageCode, $path, $title)),
            'resource' => ['page' => $pageAggregate, 'listing' => $listing, 'facets' => $facets],
            'template' => 'articles-index',
        ];
    }

    /** @return array{article_limit:int,tag_limit:int,article_show_published_date:bool,article_show_author:bool} */
    private function articleIndexSettings(?array $pageAggregate): array
    {
        $settings = [
            'article_limit' => 6,
            'tag_limit' => 12,
            'article_show_published_date' => true,
            'article_show_author' => false,
        ];
        if (!$pageAggregate || !is_array($pageAggregate['public_snapshot'] ?? null)) {
            return $settings;
        }
        $document = json_decode((string) ($pageAggregate['public_snapshot']['document_json'] ?? '{}'), true);
        if (!is_array($document)) {
            return $settings;
        }
        $candidates = [];
        foreach (['settings', 'fields', 'content'] as $key) {
            if (is_array($document[$key] ?? null)) {
                $candidates[] = $document[$key];
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($candidate['article_limit'])) {
                $settings['article_limit'] = max(1, min(48, (int) $candidate['article_limit']));
            }
            if (isset($candidate['tag_limit'])) {
                $settings['tag_limit'] = max(1, min(50, (int) $candidate['tag_limit']));
            }
            foreach (['article_show_published_date', 'show_published_date', 'show_date'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['article_show_published_date'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
            foreach (['article_show_author', 'show_author'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['article_show_author'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
        }
        return $settings;
    }

    /** @return array{show_published_date:bool,show_author:bool,show_updated_date:bool,show_type:bool,include_author_in_schema:bool,date_format:string} */
    private function defaultArticleDetailDisplaySettings(int $siteId = 0): array
    {
        $settings = [
            'show_published_date' => true,
            'show_author' => true,
            'show_updated_date' => false,
            'show_type' => true,
            'include_author_in_schema' => true,
            'date_format' => 'medium',
        ];
        if ($siteId <= 0) {
            return $settings;
        }
        $row = $this->db->one(
            "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'articles' AND setting_key = 'defaults' LIMIT 1",
            ['site_id' => $siteId]
        );
        $defaults = json_decode((string) ($row['value_json'] ?? '{}'), true);
        if (!is_array($defaults)) {
            return $settings;
        }
        return [
            'show_published_date' => $this->truthySetting($defaults['detail_show_published_date'] ?? $settings['show_published_date']),
            'show_author' => $this->truthySetting($defaults['detail_show_author'] ?? $settings['show_author']),
            'show_updated_date' => $this->truthySetting($defaults['detail_show_updated_date'] ?? $settings['show_updated_date']),
            'show_type' => $this->truthySetting($defaults['detail_show_type'] ?? $settings['show_type']),
            'include_author_in_schema' => $this->truthySetting($defaults['detail_include_author_in_schema'] ?? $settings['include_author_in_schema']),
            'date_format' => $this->safeDateFormat((string) ($defaults['detail_date_format'] ?? $settings['date_format'])),
        ];
    }

    /** @return array{show_published_date:bool,show_author:bool,show_updated_date:bool,show_type:bool,include_author_in_schema:bool,date_format:string} */
    private function articleDetailDisplaySettings(array $aggregate, int $siteId = 0): array
    {
        $settings = $this->defaultArticleDetailDisplaySettings($siteId);
        $document = $this->publicDocument($aggregate);
        $candidates = [];
        foreach (['settings', 'fields', 'content'] as $key) {
            if (is_array($document[$key] ?? null)) {
                $candidates[] = $document[$key];
            }
        }
        $usesCustomDisplaySettings = false;
        foreach ($candidates as $candidate) {
            if (array_key_exists('article_detail_use_custom_display_settings', $candidate)) {
                $usesCustomDisplaySettings = $this->truthySetting($candidate['article_detail_use_custom_display_settings']);
                break;
            }
        }
        if (!$usesCustomDisplaySettings) {
            return $settings;
        }
        foreach ($candidates as $candidate) {
            foreach (['article_detail_show_published_date', 'show_detail_published_date'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['show_published_date'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
            foreach (['article_detail_show_author', 'show_detail_author'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['show_author'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
            foreach (['article_detail_show_updated_date', 'show_detail_updated_date'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['show_updated_date'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
            foreach (['article_detail_show_type', 'show_detail_type'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['show_type'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
            foreach (['article_detail_include_author_in_schema', 'include_author_in_schema'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['include_author_in_schema'] = $this->truthySetting($candidate[$key]);
                    break;
                }
            }
            foreach (['article_detail_date_format', 'date_format'] as $key) {
                if (array_key_exists($key, $candidate)) {
                    $settings['date_format'] = $this->safeDateFormat((string) $candidate[$key]);
                    break;
                }
            }
        }
        return $settings;
    }

    /** @param array<string,mixed> $aggregate @return array<string,mixed> */
    private function publicDocument(array $aggregate): array
    {
        $document = json_decode((string) ($aggregate['public_snapshot']['document_json'] ?? '{}'), true);
        return is_array($document) ? $document : [];
    }

    /** @param array<string,mixed> $aggregate @return array<string,mixed> */
    private function publicDocumentFields(array $aggregate): array
    {
        $document = $this->publicDocument($aggregate);
        return is_array($document['fields'] ?? null) ? $document['fields'] : [];
    }

    /** @param array<string,mixed> $fields */
    private function displayPublishedAt(array $fields, string $fallback): string
    {
        foreach (['display_published_at', 'published_at', 'publication_date'] as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            return $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : $value;
        }
        return $fallback;
    }

    /** @param array<string,mixed> $fields */
    private function displayAuthorName(array $fields): string
    {
        foreach (['author_name', 'display_author_name', 'byline'] as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function safeDateFormat(string $format): string
    {
        $format = strtolower(trim($format));
        return in_array($format, ['short', 'medium', 'long', 'iso'], true) ? $format : 'medium';
    }

    private function formatPublicDate(string $date, string $format, string $languageCode): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }
        $format = $this->safeDateFormat($format);
        if ($format === 'iso') {
            return date('Y-m-d', $timestamp);
        }
        if ($format === 'short') {
            return date('d.m.Y', $timestamp);
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $locale = $this->dateLocale($languageCode);
            $dateType = $format === 'long' ? \IntlDateFormatter::FULL : \IntlDateFormatter::LONG;
            $formatter = new \IntlDateFormatter($locale, $dateType, \IntlDateFormatter::NONE);
            $formatted = $formatter->format($timestamp);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }
        return match (strtolower(substr($languageCode, 0, 2))) {
            'en' => date('F j, Y', $timestamp),
            'de' => date('d.m.Y', $timestamp),
            default => date('d.m.Y', $timestamp),
        };
    }

    private function dateLocale(string $languageCode): string
    {
        return match (strtolower(substr($languageCode, 0, 2))) {
            'en' => 'en_GB',
            'de' => 'de_CH',
            default => 'fr_CH',
        };
    }

    private function truthySetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'oui', 'on'], true);
    }

    /** @param list<array<string,mixed>> $blocks @return array{before:list<array<string,mixed>>,after:list<array<string,mixed>>} */
    private function splitArticleIndexBlocks(array $blocks): array
    {
        $before = [];
        $after = [];
        foreach ($blocks as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $placement = (string) ($data['article_index_placement'] ?? $data['placement'] ?? 'before');
            if (in_array($placement, ['after', 'below', 'after_articles'], true)) {
                $after[] = $block;
            } else {
                $before[] = $block;
            }
        }
        return ['before' => $before, 'after' => $after];
    }

    private function safeFilterSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }

    private function articleIndexUrl(string $languageCode, string $categorySlug = '', string $tagSlug = '', int $limit = 6, int $page = 1): string
    {
        $params = [];
        if ($categorySlug !== '') {
            $params['category'] = $categorySlug;
        }
        if ($tagSlug !== '') {
            $params['tag'] = $tagSlug;
        }
        if ($limit !== 6) {
            $params['limit'] = $limit;
        }
        if ($page > 1) {
            $params['page'] = $page;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return localized_path('/articles', $languageCode) . ($query !== '' ? '?' . $query : '');
    }

    private function tombstonePayload(array $site, string $languageCode, string $path, array $tombstone): array
    {
        $ui = $this->ui($languageCode);
        return $this->basePayload($site, $languageCode, $path, $ui['gone_title']) + [
            'title' => $ui['gone_title'],
            'replacement_url' => !empty($tombstone['replacement_path']) ? url_path((string) $tombstone['replacement_path']) : '',
            'meta_title' => $ui['gone_title'],
            'meta_description' => $ui['gone_text'],
            'meta_robots' => 'noindex,follow',
            'canonical' => localized_absolute_url($path, $languageCode, (string) ($site['base_url'] ?? '')),
            'status' => 410,
            'resource' => $tombstone,
            'template' => 'tombstone',
        ];
    }

    private function notFoundPayload(array $site, string $languageCode, string $path): array
    {
        $ui = $this->ui($languageCode);
        return $this->basePayload($site, $languageCode, $path, $ui['not_found_title']) + [
            'title' => $ui['not_found_title'],
            'error_title' => $ui['not_found_title'],
            'error_message' => $ui['not_found_text'],
            'meta_title' => $ui['not_found_title'],
            'meta_description' => $ui['not_found_text'],
            'meta_robots' => 'noindex,follow',
            'canonical' => localized_absolute_url($path, $languageCode, (string) ($site['base_url'] ?? '')),
            'status' => 404,
            'template' => 'error',
        ];
    }

    private function archivePayload(array $site, string $languageCode, string $path, array $archive): array
    {
        return $this->basePayload($site, $languageCode, $path, (string) $archive['term']['name']) + [
            'title' => (string) $archive['term']['name'],
            'archive_title' => (string) $archive['term']['name'],
            'archive_description' => (string) ($archive['term']['description'] ?? ''),
            'archive_items' => $this->normalizeArchiveItems($archive['items'], $languageCode),
            'meta_title' => (string) ($archive['term']['meta_title'] ?? $archive['term']['name']),
            'meta_description' => (string) ($archive['term']['meta_description'] ?? $archive['term']['description'] ?? ''),
            'canonical' => localized_absolute_url($path, $languageCode, (string) ($site['base_url'] ?? '')),
            'json_ld' => $this->jsonLd('', $site, $languageCode, $path, (string) $archive['term']['name'], (string) ($archive['term']['meta_description'] ?? $archive['term']['description'] ?? ''), 'archive', '', '', $this->breadcrumbs($languageCode, $path, (string) $archive['term']['name'])),
            'resource' => $archive,
            'template' => 'taxonomy-archive',
        ];
    }

    private function seoMediaUrl(int $mediaId, string $preferredSet): string
    {
        if ($mediaId < 1) { return ''; }
        $keys = $preferredSet === 'open_graph' ? ['og_1200x630', 'hero_1280', 'content_1280', 'original'] : ['content_1280', 'original'];
        $variants = $this->db->all('SELECT mav.variant_key, mav.path, ma.site_id, ma.storage_disk FROM media_asset_variants mav JOIN media_assets ma ON ma.id = mav.media_id WHERE mav.media_id = :id AND mav.generation_status = \'ready\' ORDER BY mav.width DESC, mav.variant_key ASC', ['id' => $mediaId]);
        foreach ($keys as $key) {
            foreach ($variants as $variant) {
                if (($variant['variant_key'] ?? '') === $key) {
                    return $this->mediaUrl((string) ($variant['path'] ?? ''), (int) ($variant['site_id'] ?? 0), (string) ($variant['storage_disk'] ?? 'local'));
                }
            }
        }
        $asset = $this->db->one('SELECT public_path, path FROM media_assets WHERE id = :id AND lifecycle_status = \'ready\' LIMIT 1', ['id' => $mediaId]);
        return $asset ? $this->mediaUrl((string) ($asset['public_path'] ?? $asset['path'] ?? ''), (int) ($asset['site_id'] ?? 0), (string) ($asset['storage_disk'] ?? 'local')) : '';
    }

    private function mediaUrl(string $relativePath, int $siteId = 0, string $disk = 'local'): string
    {
        $path = trim(str_replace('\\', '/', $relativePath));
        if ($path === '') { return ''; }
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) { return $path; }
        if ($siteId > 0 && $disk !== 'local') { return $this->mediaUrls->publicUrl($path, $siteId, $disk); }

        $siteBasePath = current_site_base_path();
        $appBasePath = app_base_path();

        if ($appBasePath !== '' && ($path === $appBasePath || str_starts_with($path, $appBasePath . '/'))) {
            return $path;
        }

        // Media files are application-scoped, even when the current request is
        // served from a sub-site base path such as /site-a or /cms/site-b.
        // Prefixing storage URLs with the sub-site path breaks logos, favicons
        // and content media on multisite frontends.
        foreach (['/storage/media/', '/storage/'] as $needle) {
            $pos = strrpos($path, $needle);
            if ($pos !== false) {
                return url_path(substr($path, $pos));
            }
        }
        foreach (['storage/media/', 'storage/'] as $needle) {
            $pos = strrpos($path, $needle);
            if ($pos !== false) {
                return url_path('/' . substr($path, $pos));
            }
        }

        if ($siteBasePath !== '' && ($path === $siteBasePath || str_starts_with($path, $siteBasePath . '/'))) {
            return url_path($path);
        }

        if (str_starts_with($path, '/')) {
            return url_path($path);
        }

        return url_path('/storage/media/' . $this->canonicalStorageMediaPath(ltrim($path, '/')));
    }

    private function canonicalStorageMediaPath(string $relativePath): string
    {
        $path = str_replace('\\', '/', trim($relativePath));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return $path;
        }

        $absolute = base_path('storage/media/' . $path);
        if (is_file($absolute)) {
            return $path;
        }

        $directory = dirname($absolute);
        $filename = basename($path);
        if (!is_dir($directory)) {
            return $path;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return $path;
        }

        foreach ($entries as $entry) {
            if (strcasecmp($entry, $filename) === 0) {
                $prefix = str_replace('\\', '/', dirname($path));
                return ($prefix === '.' ? '' : $prefix . '/') . $entry;
            }
        }

        return $path;
    }


    /** @return array{logo_url:string,favicon_url:string,apple_touch_icon_url:string} */
    private function siteBrandAssets(int $siteId, string $languageCode): array
    {
        $settings = $this->db->one(
            "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'media' AND setting_key = 'defaults' LIMIT 1",
            ['site_id' => $siteId]
        );
        $values = $settings ? json_decode((string) ($settings['value_json'] ?? '{}'), true) : [];
        $values = is_array($values) ? $values : [];
        $logoId = isset($values['logo_media_id']) ? (int) $values['logo_media_id'] : 0;
        $faviconId = isset($values['favicon_media_id']) ? (int) $values['favicon_media_id'] : 0;

        $localization = $this->db->one(
            "SELECT apple_touch_icon_media_id FROM site_localizations WHERE site_id = :site_id AND language_code = :language_code LIMIT 1",
            ['site_id' => $siteId, 'language_code' => $languageCode]
        );
        $appleTouchIconId = isset($localization['apple_touch_icon_media_id']) ? (int) $localization['apple_touch_icon_media_id'] : 0;

        $faviconUrl = $faviconId > 0 ? $this->seoMediaUrl($faviconId, 'content') : '';
        $appleTouchIconUrl = $appleTouchIconId > 0 ? $this->seoMediaUrl($appleTouchIconId, 'content') : $faviconUrl;

        return [
            'logo_url' => $logoId > 0 ? $this->seoMediaUrl($logoId, 'content') : '',
            'favicon_url' => $faviconUrl,
            'apple_touch_icon_url' => $appleTouchIconUrl,
        ];
    }


    /** @param array<string,mixed> $siteLocalization @param array<string,mixed> $site */
    private function siteBrandInitials(array $siteLocalization, array $site): string
    {
        $label = trim((string) ($siteLocalization['site_title'] ?? $site['name'] ?? $site['site_key'] ?? 'CMS'));
        $words = preg_split('/[^\pL\pN]+/u', $label) ?: [];
        $initials = '';

        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 2) {
                break;
            }
        }

        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr($label, 0, 2, 'UTF-8'), 'UTF-8');
        }

        return $initials !== '' ? $initials : 'CMS';
    }


    /** @return array<string,mixed> */
    private function publicUiSettings(int $siteId): array
    {
        $row = $this->db->one(
            "SELECT value_json FROM site_settings WHERE site_id = :site_id AND namespace = 'public_ui' AND setting_key = 'defaults' LIMIT 1",
            ['site_id' => $siteId]
        );
        $values = $row ? json_decode((string) ($row['value_json'] ?? '{}'), true) : [];
        $values = is_array($values) ? $values : [];
        $values = array_replace($this->defaultPublicUiSettings(), $values);
        $values['show_login_shortcut'] = filter_var($values['show_login_shortcut'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $values['show_site_title_in_header'] = filter_var($values['show_site_title_in_header'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $values['appearance_css'] = $this->appearanceCss($values);
        return $values;
    }

    /** @return array<string,mixed> */
    private function defaultPublicUiSettings(): array
    {
        return [
            'show_login_shortcut' => false,
            'show_site_title_in_header' => true,
            'active_theme_key' => 'default',
            'body_font_family' => 'system',
            'heading_font_family' => 'system',
            'main_heading_font_family' => 'heading',
            'main_heading_letter_spacing' => 'normal',
            'color_background' => '#fbfcfe',
            'color_surface' => '#ffffff',
            'color_text' => '#102033',
            'color_muted' => '#627086',
            'color_primary' => '#1b5fc1',
            'color_accent' => '#ffb21e',
        ];
    }

    /** @param array<string,mixed> $values */
    private function appearanceCss(array $values): string
    {
        $primary = $this->safeHex($values['color_primary'] ?? '#1b5fc1', '#1b5fc1');
        $vars = [
            '--bg' => $this->safeHex($values['color_background'] ?? '#fbfcfe', '#fbfcfe'),
            '--surface' => $this->safeHex($values['color_surface'] ?? '#ffffff', '#ffffff'),
            '--text' => $this->safeHex($values['color_text'] ?? '#102033', '#102033'),
            '--muted' => $this->safeHex($values['color_muted'] ?? '#627086', '#627086'),
            '--brand' => $primary,
            '--brand-dark' => $primary,
            '--accent' => $this->safeHex($values['color_accent'] ?? '#ffb21e', '#ffb21e'),
            '--font-body' => $this->fontStack((string) ($values['body_font_family'] ?? 'system')),
            '--font-heading' => $this->fontStack((string) ($values['heading_font_family'] ?? 'system')),
            '--font-main-heading' => $this->mainHeadingFontStack((string) ($values['main_heading_font_family'] ?? 'heading')),
            '--main-heading-letter-spacing' => $this->mainHeadingLetterSpacingCss((string) ($values['main_heading_letter_spacing'] ?? 'normal')),
        ];
        $css = [];
        foreach ($vars as $name => $value) {
            $css[] = $name . ':' . $value;
        }
        return ':root{' . implode(';', $css) . '}body{font-family:var(--font-body)}h1,h2,h3,h4,h5,h6,.brand{font-family:var(--font-heading)}body:not(#amcms-appearance-override) h1{font-family:var(--font-main-heading)!important;letter-spacing:var(--main-heading-letter-spacing)!important}';
    }

    private function safeHex(mixed $value, string $fallback): string
    {
        $color = trim((string) $value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
    }

    private function fontStack(string $fontKey): string
    {
        return match ($fontKey) {
            'serif' => 'Georgia, Cambria, "Times New Roman", Times, serif',
            'slab' => 'Roboto Slab, Rockwell, "Courier Bold", serif',
            'mono' => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace',
            'arial' => 'Arial, Helvetica, sans-serif',
            default => 'Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif',
        };
    }


    private function mainHeadingFontStack(string $fontKey): string
    {
        return $fontKey === 'heading' ? 'var(--font-heading)' : $this->fontStack($fontKey);
    }

    private function mainHeadingLetterSpacingCss(string $key): string
    {
        return match ($key) {
            'relaxed' => '.012em',
            'airy' => '.025em',
            default => '0em',
        };
    }

    private function basePayload(array $site, string $languageCode, string $currentPath, string $currentTitle = ''): array
    {
        $siteId = (int) $site['id'];
        $siteLocalization = $this->sites->getLocalization($siteId, $languageCode) ?? [];
        $languages = $this->languageSwitchItems($siteId, $languageCode, $currentPath, (string) ($site['base_url'] ?? ''));
        $siteAssets = $this->siteBrandAssets($siteId, $languageCode);
        $publicUi = $this->publicUiSettings($siteId);
        $shop = $this->storefront?->activeShop($siteId,$languageCode);
        $primaryMenu = $this->menuItems($siteId, $languageCode, 'primary', $currentPath);
        $footerMenu = $this->menuItems($siteId, $languageCode, 'footer', $currentPath);
        $footerTopMenu = $this->menuItems($siteId, $languageCode, 'footer_top', $currentPath);
        $footerBottomMenu = $this->menuItems($siteId, $languageCode, 'footer_bottom', $currentPath);
        $placedMenus = $this->placeShopMenuItem($primaryMenu,$footerMenu,$footerTopMenu,$footerBottomMenu,$shop,$languageCode,$currentPath);
        $primaryMenu=$placedMenus['primary']; $footerMenu=$placedMenus['footer'];
        $footerTopMenu=$placedMenus['footer_top']; $footerBottomMenu=$placedMenus['footer_bottom'];
        return [
            'site' => $site,
            'site_localization' => $siteLocalization,
            'languageCode' => $languageCode,
            'current_path' => $currentPath,
            'ui' => $this->ui($languageCode),
            'home_cards' => $this->homeCards($languageCode),
            'menu_items' => $primaryMenu,
            'footer_menu_items' => $footerMenu,
            'footer_top_menu_items' => $footerTopMenu,
            'footer_bottom_menu_items' => $footerBottomMenu,
            'localized_home_url' => localized_home_path($languageCode),
            'localized_search_url' => localized_path('/search', $languageCode),
            'sitemap_url' => localized_absolute_url('/sitemap.xml', $languageCode, (string) ($site['base_url'] ?? '')),
            'llms_txt_url' => localized_absolute_url('/llms.txt', $languageCode, (string) ($site['base_url'] ?? '')),
            // SEO keeps the complete language list for hreflang metadata, but the
            // visible frontend language menu is useful only when the visitor can
            // actually switch to another active language.
            'languages' => $languages,
            'language_menu_items' => $this->visibleLanguageSwitchItems($languages),
            'x_default_url' => $this->xDefaultUrl($languages),
            'site_logo_url' => $siteAssets['logo_url'],
            'site_favicon_url' => $siteAssets['favicon_url'],
            'site_apple_touch_icon_url' => $siteAssets['apple_touch_icon_url'],
            'site_brand_initials' => $this->siteBrandInitials($siteLocalization, $site),
            'public_ui' => $publicUi,
            'appearance_css' => (string) ($publicUi['appearance_css'] ?? ''),
            'show_login_shortcut' => (bool) ($publicUi['show_login_shortcut'] ?? false),
            'show_site_title_in_header' => (bool) ($publicUi['show_site_title_in_header'] ?? true),
            'storefront_cart_visible' => $shop !== null && !empty($shop['cart_visible']),
            'storefront_channel_id' => $shop['channel_id'] ?? null,
            'storefront_channel_code' => $shop['channel_code'] ?? null,
            'theme_override_key' => ($currentPath === '/shop' || str_starts_with($currentPath,'/shop/'))
                ? ($shop['theme_key'] ?? null)
                : null,
            'admin_login_url' => admin_url_path('/admin/login'),
            'breadcrumbs' => $this->breadcrumbs($languageCode, $currentPath, $currentTitle),
            'og_image' => absolute_url('/frontend/theme-default/assets/img/cms-hero-1280.png', (string) ($site['base_url'] ?? '')),
            'og_locale' => $this->ogLocale($languageCode, $languages),
            'og_locale_alternates' => $this->ogLocaleAlternates($languages, $languageCode),
        ];
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $shop @return list<array<string,mixed>> */
    private function appendShopMenuItem(array $items, array $shop, string $languageCode, string $currentPath): array
    {
        $url = localized_path('/shop',$languageCode);
        foreach ($items as $item) if (is_array($item) && (string)($item['url']??'')===$url) return $items;
        $items[] = [
            'id'=>'system-shop-' . (int)$shop['id'],'label'=>(string)($shop['menu_label']??'Boutique'),'url'=>$url,
            'target'=>'_self','sort_order'=>(int)($shop['menu_position']??100),'is_current'=>$currentPath==='/shop' || str_starts_with($currentPath,'/shop/'),
            'is_ancestor'=>false,'children'=>[],'css_class'=>'system-shop-menu-item',
        ];
        usort($items,static fn(array $a,array $b):int=>((int)($a['sort_order']??0))<=>((int)($b['sort_order']??0)));
        return $items;
    }

    /** @return array{primary:array,footer:array,footer_top:array,footer_bottom:array} */
    private function placeShopMenuItem(array $primary,array $footer,array $footerTop,array $footerBottom,?array $shop,string $languageCode,string $currentPath): array
    {
        if ($shop === null) return ['primary'=>$primary,'footer'=>$footer,'footer_top'=>$footerTop,'footer_bottom'=>$footerBottom];
        $target = (string) ($shop['menu_key'] ?? 'main');
        if (in_array($target,['main','primary'],true)) {
            $primary=$this->appendShopMenuItem($primary,$shop,$languageCode,$currentPath);
        } elseif ($target === 'footer_top') {
            $footerTop=$this->appendShopMenuItem($footerTop,$shop,$languageCode,$currentPath);
        } elseif ($target === 'footer_bottom') {
            $footerBottom=$this->appendShopMenuItem($footerBottom,$shop,$languageCode,$currentPath);
        } elseif ($target === 'footer') {
            if ($footerBottom !== []) $footerBottom=$this->appendShopMenuItem($footerBottom,$shop,$languageCode,$currentPath);
            elseif ($footerTop !== []) $footerTop=$this->appendShopMenuItem($footerTop,$shop,$languageCode,$currentPath);
            else $footer=$this->appendShopMenuItem($footer,$shop,$languageCode,$currentPath);
        }
        return ['primary'=>$primary,'footer'=>$footer,'footer_top'=>$footerTop,'footer_bottom'=>$footerBottom];
    }


    private function menuItems(int $siteId, string $languageCode, string $menuKey, string $currentPath = ''): array
    {
        $flatItems = [];

        foreach ($this->sites->menuItems($siteId, $menuKey, $languageCode) as $item) {
            $label = trim((string) ($item['label'] ?? 'Lien'));
            if ($label === '') {
                continue;
            }

            $url = $this->resolveMenuItemUrl($item, $languageCode);
            if ($url === '') {
                continue;
            }

            $isCurrent = $this->isCurrentMenuUrl($url, $currentPath, $languageCode);
            $flatItems[(int) $item['id']] = [
                'id' => (int) $item['id'],
                'parent_id' => isset($item['parent_id']) && $item['parent_id'] !== null ? (int) $item['parent_id'] : null,
                'label' => $label,
                'title_attr' => trim((string) ($item['title_attr'] ?? '')),
                'url' => $url,
                'target' => !empty($item['open_in_new_tab']) ? '_blank' : '_self',
                'css_class' => trim((string) ($item['css_class'] ?? '')),
                'is_current' => $isCurrent,
                'is_ancestor' => false,
                'children' => [],
            ];
        }

        foreach ($flatItems as $id => $item) {
            $parentId = $item['parent_id'];
            if ($parentId !== null && isset($flatItems[$parentId])) {
                if (!empty($flatItems[$id]['is_current']) || !empty($flatItems[$id]['is_ancestor'])) {
                    $flatItems[$parentId]['is_ancestor'] = true;
                }
                $flatItems[$parentId]['children'][] = &$flatItems[$id];
            }
        }
        unset($item);

        $tree = [];
        foreach ($flatItems as $item) {
            if ($item['parent_id'] === null || !isset($flatItems[$item['parent_id']])) {
                $tree[] = $item;
            }
        }

        return $tree;
    }


    private function isCurrentMenuUrl(string $url, string $currentPath, string $languageCode): bool
    {
        if ($url === '' || $url === '#' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return false;
        }

        $menuPath = parse_url($url, PHP_URL_PATH) ?: '/';
        $menuPath = strip_localized_path_prefix($menuPath);
        $menuPath = '/' . trim($menuPath, '/');
        $currentPath = strip_localized_path_prefix($currentPath ?: '/');
        $currentPath = '/' . trim($currentPath, '/');
        if ($menuPath === '//') { $menuPath = '/'; }
        if ($currentPath === '//') { $currentPath = '/'; }
        return rtrim($menuPath, '/') === rtrim($currentPath, '/');
    }

    /**
     * @param array<string,mixed> $item
     */
    private function resolveMenuItemUrl(array $item, string $languageCode): string
    {
        $manualUrl = trim((string) ($item['manual_url'] ?? ''));
        if ($manualUrl !== '') {
            if ($manualUrl === '#' || str_starts_with($manualUrl, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $manualUrl) === 1) {
                return $manualUrl;
            }
            return localized_path($manualUrl, $languageCode);
        }

        $targetPath = trim((string) ($item['target_path'] ?? ''));
        if ($targetPath !== '') {
            return localized_path($targetPath, $languageCode);
        }

        return '#';
    }

    private function resourceLanguageSwitchItems(array $site, string $activeLanguageCode, string $entryKey, int $entryId, bool $includeNoindex = false): array
    {
        $siteId = (int) $site['id'];
        $baseUrl = (string) ($site['base_url'] ?? '');
        $alternates = $this->contentRouteAlternatesByEntryKey($siteId, $entryKey, $entryId, $includeNoindex);
        $items = [];
        foreach ($alternates as $alternate) {
            $code = (string) ($alternate['language_code'] ?? '');
            $path = (string) ($alternate['full_path'] ?? '');
            if ($code === '' || $path === '') {
                continue;
            }
            $items[] = [
                'code' => $code,
                'hreflang' => (string) (($alternate['hreflang_code'] ?? '') ?: $code),
                'label' => (string) ($alternate['native_name'] ?? strtoupper($code)),
                'is_active' => $code === $activeLanguageCode,
                'url' => localized_path($path, $code),
                'absolute_url' => localized_absolute_url($path, $code, $baseUrl),
                'is_default' => !empty($alternate['is_default']),
            ];
        }
        return $items;
    }


    /**
     * Retourne les variantes publiées d'une page/article à partir de la clé d'entrée.
     *
     * Le slug est volontairement exclu de l'identité multilingue : il reste une URL
     * éditoriale propre à chaque langue. La clé d'entrée est la source de vérité qui
     * permet au sélecteur de langue de passer naturellement de /mentions-legales à
     * /legal-notice pour l'entrée stable "legal".
     *
     * @return list<array<string,mixed>>
     */
    private function contentRouteAlternatesByEntryKey(int $siteId, string $entryKey, int $fallbackEntryId, bool $includeNoindex = false): array
    {
        $entryKey = trim($entryKey);
        if ($entryKey === '') {
            return $fallbackEntryId > 0 ? $this->routes->listPublishedRouteAlternates($siteId, 'content_entry', $fallbackEntryId) : [];
        }

        $robotsFilter = $includeNoindex ? '' : "
               AND COALESCE(sm.meta_robots, 'index,follow') NOT LIKE '%noindex%'";
        $rows = $this->db->all(
            "SELECT DISTINCT r.language_code, r.full_path, sl.hreflang_code, sl.is_default, l.native_name
             FROM content_entries ce
             JOIN routes r ON r.site_id = ce.site_id
                AND r.resource_type = 'content_entry'
                AND r.resource_id = ce.id
                AND r.status = 'active'
                AND r.is_primary = 1
                AND r.is_canonical = 1
             JOIN site_languages sl ON sl.site_id = r.site_id
                AND sl.language_code = r.language_code
                AND sl.is_active = 1
             JOIN languages l ON l.code = r.language_code AND l.is_active = 1
             LEFT JOIN content_entry_localizations cel ON cel.entry_id = ce.id
                AND cel.language_code = r.language_code
                AND cel.is_active = 1
             LEFT JOIN seo_metadata sm ON sm.site_id = r.site_id
                AND sm.resource_type = 'content_entry'
                AND sm.resource_id = ce.id
                AND sm.language_code = r.language_code
             WHERE ce.site_id = :site_id
               AND ce.entry_key = :entry_key
               AND ce.is_active = 1
               AND ce.status = 'published'
               AND cel.id IS NOT NULL{$robotsFilter}
             ORDER BY sl.sort_order, r.language_code",
            ['site_id' => $siteId, 'entry_key' => $entryKey]
        );

        if ($rows !== []) {
            return $rows;
        }

        return $fallbackEntryId > 0 ? $this->routes->listPublishedRouteAlternates($siteId, 'content_entry', $fallbackEntryId) : [];
    }

    /**
     * @param list<array<string,mixed>> $menuItems
     * @param list<array<string,mixed>> $resourceAlternates
     * @return list<array<string,mixed>>
     */
    private function mergeLanguageMenuWithResourceAlternates(array $menuItems, array $resourceAlternates): array
    {
        $alternatesByCode = [];
        foreach ($resourceAlternates as $alternate) {
            $code = (string) ($alternate['code'] ?? '');
            if ($code !== '') {
                $alternatesByCode[$code] = $alternate;
            }
        }

        foreach ($menuItems as $index => $item) {
            $code = (string) ($item['code'] ?? '');
            if ($code !== '' && isset($alternatesByCode[$code])) {
                $menuItems[$index] = $item + $alternatesByCode[$code];
                $menuItems[$index]['url'] = (string) ($alternatesByCode[$code]['url'] ?? $item['url'] ?? '#');
                $menuItems[$index]['absolute_url'] = (string) ($alternatesByCode[$code]['absolute_url'] ?? $item['absolute_url'] ?? '');
                $menuItems[$index]['is_active'] = !empty($alternatesByCode[$code]['is_active']);
            }
        }

        return $menuItems;
    }


    /** @return array<string,mixed>|null */
    private function cookiePolicy(int $siteId, string $languageCode, string $defaultLanguage): ?array
    {
        if ($this->cookies === null) {
            return null;
        }

        $languages = $this->sites->getLanguages($siteId);
        $this->cookies->ensureSiteDefaults($siteId, $languages, $defaultLanguage);
        $config = $this->cookies->publicConfig($siteId, $languageCode, $defaultLanguage);
        if (empty($config['enabled'])) {
            return [
                'enabled' => false,
                'texts' => [],
                'categories' => [],
                'services' => [],
                'has_optional_services' => false,
                'legal_links' => $this->cookieLegalLinks($languageCode),
            ];
        }

        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        return [
            'enabled' => true,
            'texts' => is_array($config['texts'] ?? null) ? $config['texts'] : [],
            'setting' => is_array($config['setting'] ?? null) ? $config['setting'] : [],
            'categories' => is_array($config['categories'] ?? null) ? $config['categories'] : [],
            'services' => $services,
            'has_optional_services' => $this->cookieHasOptionalServices($services),
            'legal_links' => $this->cookieLegalLinks($languageCode),
        ];
    }

    /** @param list<array<string,mixed>> $services */
    private function cookieHasOptionalServices(array $services): bool
    {
        foreach ($services as $service) {
            if ((string) ($service['category_key'] ?? '') !== 'necessary') {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,string> */
    private function cookieLegalLinks(string $languageCode): array
    {
        return [
            'legal' => localized_path('/mentions-legales', $languageCode),
            'privacy' => localized_path('/politique-confidentialite', $languageCode),
            'cookies' => localized_path('/cookies', $languageCode),
        ];
    }

    private function jsonLd(string $storedJsonLd, array $site, string $languageCode, string $path, string $title, string $description, string $typeKey, string $publishedAt = '', string $modifiedAt = '', array $breadcrumbs = [], array $blocks = [], string $authorName = ''): string
    {
        $canonical = localized_absolute_url($path, $languageCode, (string) ($site['base_url'] ?? ''));
        $baseUrl = rtrim((string) ($site['base_url'] ?? $canonical), '/');
        $siteName = trim((string) ($site['name'] ?? 'Website')) ?: 'Website';
        $websiteUrl = $baseUrl !== '' ? $baseUrl : $canonical;
        $websiteId = $websiteUrl . '#website';
        $organizationId = $websiteUrl . '#organization';
        $resourceId = $canonical . '#main';
        $primaryImage = $this->primaryImageFromBlocks($blocks, (string) ($site['base_url'] ?? ''));
        $keywords = $this->keywordsFromText($title . ' ' . $description . ' ' . $this->blocksPlainText($blocks));

        $resourceNode = [
            '@type' => match ($typeKey) {
                'article' => 'Article',
                'search' => 'SearchResultsPage',
                'archive' => 'CollectionPage',
                default => 'WebPage',
            },
            '@id' => $resourceId,
            'url' => $canonical,
            'name' => $title,
            'headline' => $title,
            'description' => $description,
            'abstract' => $this->geoSummary($title, $description),
            'inLanguage' => $languageCode,
            'isPartOf' => ['@id' => $websiteId],
            'publisher' => ['@id' => $organizationId],
            'mainEntityOfPage' => ['@id' => $canonical],
        ];
        if ($keywords !== []) {
            $resourceNode['keywords'] = implode(', ', $keywords);
            $resourceNode['about'] = array_map(static fn (string $keyword): array => ['@type' => 'Thing', 'name' => $keyword], $keywords);
        }
        if ($primaryImage !== '') {
            $resourceNode['image'] = $primaryImage;
        }
        if ($typeKey === 'article' && trim($authorName) !== '') {
            $resourceNode['author'] = [
                '@type' => 'Person',
                'name' => trim($authorName),
            ];
        }

        $graph = [
            [
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => $siteName,
                'url' => $websiteUrl,
            ],
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'name' => $siteName,
                'url' => $websiteUrl,
                'inLanguage' => $languageCode,
                'publisher' => ['@id' => $organizationId],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => localized_absolute_url('/search?q={search_term_string}', $languageCode, (string) ($site['base_url'] ?? '')),
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            $this->breadcrumbJsonLd($breadcrumbs, $canonical, $languageCode, (string) ($site['base_url'] ?? '')),
            $resourceNode,
        ];

        if ($publishedAt !== '') {
            $graph[3]['datePublished'] = $this->schemaDate($publishedAt);
        }
        if ($modifiedAt !== '') {
            $graph[3]['dateModified'] = $this->schemaDate($modifiedAt);
        }

        $stored = $this->decodeStoredJsonLd($storedJsonLd);
        if ($stored !== null) {
            $graph[] = $stored;
        }

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => array_values(array_filter($graph)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return is_string($json) ? $json : '';
    }

    private function geoSummary(string $title, string $description): string
    {
        $title = trim($title);
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if ($description === '') {
            return $title;
        }
        if ($title === '') {
            return mb_strlen($description) > 260 ? mb_substr($description, 0, 257) . '…' : $description;
        }
        $summary = $title . ' — ' . $description;
        return mb_strlen($summary) > 320 ? mb_substr($summary, 0, 317) . '…' : $summary;
    }

    /** @param list<array<string,mixed>> $blocks @return list<string> */
    private function keywordsFromText(string $text): array
    {
        $text = mb_strtolower(trim(strip_tags($text)));
        if ($text === '') {
            return [];
        }
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]{3,}/u', $text, $matches);
        $stop = array_flip(['avec','dans','pour','plus','cette','vous','nous','page','site','sont','être','avoir','from','that','this','with','your','eine','einer','diese','und','oder','nicht']);
        $scores = [];
        foreach ($matches[0] ?? [] as $word) {
            $word = trim($word, "'’-");
            if ($word === '' || isset($stop[$word])) {
                continue;
            }
            $scores[$word] = ($scores[$word] ?? 0) + 1;
        }
        arsort($scores);
        return array_slice(array_keys($scores), 0, 8);
    }

    /** @param list<array<string,mixed>> $blocks */
    private function blocksPlainText(array $blocks): string
    {
        $chunks = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach (['eyebrow', 'title', 'subtitle', 'lead', 'text', 'markdown', 'html', 'caption', 'alt', 'image_alt'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $chunks[] = strip_tags($data[$key]);
                }
            }
            foreach (['items', 'buttons'] as $key) {
                if (!is_array($data[$key] ?? null)) {
                    continue;
                }
                foreach ($data[$key] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach (['label', 'title', 'caption', 'alt'] as $itemKey) {
                        if (isset($item[$itemKey]) && is_string($item[$itemKey])) {
                            $chunks[] = strip_tags($item[$itemKey]);
                        }
                    }
                }
            }
            if (($block['type'] ?? '') === 'columns' && is_array($data['columns'] ?? null)) {
                foreach ($data['columns'] as $column) {
                    if (is_array($column) && is_array($column['blocks'] ?? null)) {
                        $chunks[] = $this->blocksPlainText($column['blocks']);
                    }
                }
            }
        }
        return trim(preg_replace('/\s+/', ' ', implode(' ', $chunks)) ?? '');
    }

    /** @param list<array<string,mixed>> $blocks */
    private function primaryImageFromBlocks(array $blocks, string $baseUrl): string
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach (['image_src', 'src', 'poster'] as $key) {
                $url = trim((string) ($data[$key] ?? ''));
                if ($url !== '') {
                    return $this->absolutePublicUrl($url, $baseUrl);
                }
            }
            if (($block['type'] ?? '') === 'columns' && is_array($data['columns'] ?? null)) {
                foreach ($data['columns'] as $column) {
                    if (is_array($column) && is_array($column['blocks'] ?? null)) {
                        $url = $this->primaryImageFromBlocks($column['blocks'], $baseUrl);
                        if ($url !== '') {
                            return $url;
                        }
                    }
                }
            }
        }
        return '';
    }

    /** @param list<array{label:string,url:string}> $breadcrumbs */
    private function breadcrumbJsonLd(array $breadcrumbs, string $canonical, string $languageCode, string $baseUrl): array
    {
        $items = [];
        foreach ($breadcrumbs as $index => $crumb) {
            $url = trim((string) ($crumb['url'] ?? ''));
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => (string) ($crumb['label'] ?? ''),
                'item' => $url !== '' ? $this->absolutePublicUrl($url, $baseUrl) : $canonical,
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    /** @return array<string,mixed>|list<mixed>|null */
    private function decodeStoredJsonLd(string $storedJsonLd): array|null
    {
        $storedJsonLd = trim($storedJsonLd);
        if ($storedJsonLd === '') {
            return null;
        }

        $decoded = json_decode($storedJsonLd, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function schemaDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);
        return $timestamp !== false ? date(DATE_ATOM, $timestamp) : $date;
    }

    private function absolutePublicUrl(string $path, string $baseUrl): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return $path;
        }

        $path = '/' . ltrim($path, '/');
        $basePath = (string) (parse_url($baseUrl, PHP_URL_PATH) ?: '');
        $basePath = $basePath !== '' && $basePath !== '/' ? '/' . trim($basePath, '/') : '';
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        return $baseUrl . ($path === '/' ? '/' : $path);
    }

    /** @param array<string,mixed> $aggregate @return list<array<string,mixed>> */
    private function entryBlocks(array $aggregate, array $site = [], string $languageCode = '', array $query = []): array
    {
        // Runtime public atomique : en production, les blocs doivent être lus en priorité
        // depuis public_content_snapshots.document_json. Les révisions ne sont pas
        // nécessaires au rendu public et peuvent être absentes dans l’agrégat publié.
        if (is_array($aggregate['public_snapshot'] ?? null)) {
            $document = json_decode((string) ($aggregate['public_snapshot']['document_json'] ?? '{}'), true);
            if (is_array($document)) {
                $blocks = $this->blocksFromDocument($document);
                if ($blocks !== []) {
                    return $this->resolveDynamicBlocks($blocks, (int) ($site['id'] ?? ($aggregate['entry']['site_id'] ?? 0)), $languageCode, $query);
                }
            }
        }

        if (is_array($aggregate['layout_blocks'] ?? null)) {
            $blocks = $this->normalizeBlocks($aggregate['layout_blocks']);
            if ($blocks !== []) {
                return $this->resolveDynamicBlocks($blocks, (int) ($site['id'] ?? ($aggregate['entry']['site_id'] ?? 0)), $languageCode, $query);
            }
        }

        $revision = $aggregate['published_revision'] ?? $aggregate['working_revision'] ?? $aggregate['revision'] ?? null;
        if (is_array($revision)) {
            $document = json_decode((string) ($revision['document_json'] ?? '{}'), true);
            if (is_array($document)) {
                $blocks = $this->blocksFromDocument($document);
                if ($blocks !== []) {
                    return $this->resolveDynamicBlocks($blocks, (int) ($site['id'] ?? ($aggregate['entry']['site_id'] ?? 0)), $languageCode, $query);
                }
            }
        }

        return [];
    }



    /** @param list<array<string,mixed>> $blocks @param array<string,mixed> $aggregate @return list<array<string,mixed>> */
    private function annotatePreviewBlockStatuses(array $blocks, array $aggregate): array
    {
        $published = $this->publishedBlocksById($aggregate);
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            $status = $this->normaliseBlockEditorialStatus((string) ($block['editorial_status'] ?? 'published'));
            $publishedBlock = $id !== '' && isset($published[$id]) ? $published[$id] : null;
            $modified = $publishedBlock === null || $this->canonicalPreviewBlock($block) !== $this->canonicalPreviewBlock($publishedBlock);
            $visualStatus = match ($status) {
                'draft', 'review', 'ready' => $status,
                'published' => $modified && $publishedBlock !== null ? 'ready' : 'none',
                default => $status,
            };
            $blocks[$index]['_visual_editorial_status'] = $visualStatus;
            $blocks[$index]['_visual_modified'] = $modified;
            $blocks[$index]['_visual_status_label'] = match ($visualStatus) {
                'draft' => 'BLOC :: BROUILLON',
                'review' => 'BLOC :: EN RELECTURE',
                'ready' => 'BLOC :: PRÊT À PUBLIER',
                'archived' => 'BLOC :: ARCHIVÉ',
                default => '',
            };
        }
        return $blocks;
    }

    /** @param array<string,mixed> $aggregate @return array<string,array<string,mixed>> */
    private function publishedBlocksById(array $aggregate): array
    {
        $revision = is_array($aggregate['published_revision'] ?? null) ? $aggregate['published_revision'] : null;
        if (!$revision) {
            return [];
        }
        $document = json_decode((string) ($revision['document_json'] ?? '{}'), true);
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $indexed = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $block;
            }
        }
        return $indexed;
    }

    private function normaliseBlockEditorialStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'draft', 'brouillon' => 'draft',
            'review', 'relecture' => 'review',
            'ready', 'ready_to_publish', 'ready-to-publish', 'validated', 'approved' => 'ready',
            'archived', 'archive' => 'archived',
            default => 'published',
        };
    }

    /** @param array<string,mixed> $block */
    private function canonicalPreviewBlock(array $block): string
    {
        unset($block['editorial_status'], $block['_visual_editorial_status'], $block['_visual_status_label'], $block['_visual_modified']);
        ksort($block);
        return json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function resolveDynamicBlocks(array $blocks, int $siteId, string $languageCode, array $query): array
    {
        if ($siteId < 1 || $languageCode === '') {
            return $blocks;
        }
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'plan') {
                $blocks[$index]['data'] = $this->resolvePlanBlock(is_array($block['data'] ?? null) ? $block['data'] : [], $siteId, $languageCode, $query, (string) ($block['id'] ?? ''));
            } elseif ($type === 'articles') {
                $blocks[$index]['data'] = $this->resolveArticlesBlock(is_array($block['data'] ?? null) ? $block['data'] : [], $siteId, $languageCode, $query, (string) ($block['id'] ?? ''));
            } elseif (is_array($block['data']['columns'] ?? null)) {
                $data = $block['data'];
                foreach ($data['columns'] as $columnIndex => $column) {
                    if (is_array($column)) {
                        $column['blocks'] = $this->resolveDynamicBlocks(is_array($column['blocks'] ?? null) ? $column['blocks'] : [], $siteId, $languageCode, $query);
                        $data['columns'][$columnIndex] = $column;
                    }
                }
                $blocks[$index]['data'] = $data;
            }
        }
        return $blocks;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function resolvePlanBlock(array $data, int $siteId, string $languageCode, array $query, string $blockId): array
    {
        $source = $this->choiceString((string) ($data['source'] ?? 'pages'), ['pages', 'articles', 'taxonomies'], 'pages');
        $limit = max(1, min(50, (int) ($data['limit'] ?? 8)));
        $pageParam = $this->safeQueryParam((string) ($data['page_param'] ?? '')) ?: 'plan_' . substr(sha1($blockId !== '' ? $blockId : $source), 0, 8) . '_page';
        $page = max(1, (int) ($query[$pageParam] ?? 1));
        $offset = ($page - 1) * $limit;
        $items = [];
        $total = 0;

        if ($source === 'articles') {
            $listing = $this->content->listPublishedArticlesIndex($siteId, $languageCode, $limit, $offset);
            $items = array_map(fn(array $item): array => [
                'title' => (string) ($item['title'] ?? ''),
                'url' => localized_path((string) ($item['full_path'] ?? ''), $languageCode),
                'summary' => (string) ($item['summary'] ?? ''),
            ], $listing['items']);
            $total = (int) $listing['total'];
        } elseif ($source === 'taxonomies') {
            $taxonomyKey = trim((string) ($data['taxonomy_key'] ?? ''));
            $terms = array_values(array_filter($this->taxonomies->listTermsForSite($siteId, $languageCode), static function (array $term) use ($taxonomyKey): bool {
                return (int) ($term['is_active'] ?? 1) === 1 && ($taxonomyKey === '' || (string) ($term['taxonomy_key'] ?? '') === $taxonomyKey);
            }));
            $total = count($terms);
            foreach (array_slice($terms, $offset, $limit) as $term) {
                $items[] = [
                    'title' => (string) ($term['name'] ?? $term['term_key'] ?? ''),
                    'url' => localized_path((string) ($term['full_path'] ?? '/' . ($term['taxonomy_key'] ?? 'taxonomy') . '/' . ($term['term_key'] ?? '')), $languageCode),
                    'summary' => (string) ($term['description'] ?? ''),
                ];
            }
        } else {
            $all = array_values(array_filter($this->content->listPublishedByType($siteId, 'page', $languageCode), static fn(array $item): bool => (string) ($item['full_path'] ?? '') !== ''));
            usort($all, static fn(array $a, array $b): int => strcmp((string) ($a['full_path'] ?? ''), (string) ($b['full_path'] ?? '')));
            $total = count($all);
            foreach (array_slice($all, $offset, $limit) as $item) {
                $items[] = [
                    'title' => (string) ($item['title'] ?? ''),
                    'url' => localized_path((string) ($item['full_path'] ?? ''), $languageCode),
                    'summary' => (string) ($item['summary'] ?? ''),
                ];
            }
        }

        return array_merge($data, [
            'source' => $source,
            'limit' => $limit,
            'items' => $items,
            'pagination' => $this->blockPagination($query, $pageParam, $page, $limit, $total, !array_key_exists('show_pagination', $data) || (bool) $data['show_pagination']),
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function resolveArticlesBlock(array $data, int $siteId, string $languageCode, array $query, string $blockId): array
    {
        unset($query, $blockId);

        $limit = max(1, min(24, (int) ($data['limit'] ?? 3)));
        $category = $this->safeFilterSlug((string) ($data['category'] ?? ''));
        $tag = $this->safeFilterSlug((string) ($data['tag'] ?? ''));
        $articleDisplay = $this->defaultArticleDetailDisplaySettings($siteId);
        $listing = $this->content->listPublishedArticlesIndex($siteId, $languageCode, $limit, 0, $category ?: null, $tag ?: null);
        $moreQuery = [];
        if ($category !== '') { $moreQuery['category'] = $category; }
        if ($tag !== '') { $moreQuery['tag'] = $tag; }

        return array_merge($data, [
            'limit' => $limit,
            'display' => [
                'show_published_date' => (bool) $articleDisplay['show_published_date'],
                'show_author' => (bool) $articleDisplay['show_author'],
                'show_updated_date' => (bool) $articleDisplay['show_updated_date'],
                'show_type' => (bool) $articleDisplay['show_type'],
                'date_format' => (string) $articleDisplay['date_format'],
            ],
            'items' => $this->normalizeArticleIndexItems($listing['items'], $languageCode, (string) $articleDisplay['date_format']),
            'pagination' => ['enabled' => false],
            'more_url' => localized_path('/articles', $languageCode) . ($moreQuery !== [] ? '?' . http_build_query($moreQuery, '', '&', PHP_QUERY_RFC3986) : ''),
            'more_label_resolved' => trim((string) ($data['more_label'] ?? '')) ?: $this->ui($languageCode)['all_articles'],
            'read_label' => $this->ui($languageCode)['read_article'],
        ]);
    }

    /** @return array<string,mixed> */
    private function blockPagination(array $query, string $pageParam, int $page, int $limit, int $total, bool $enabled): array
    {
        $totalPages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;
        $base = $query;
        unset($base[$pageParam]);
        $urlFor = function (int $target) use ($base, $pageParam): string {
            $q = $base;
            if ($target > 1) { $q[$pageParam] = $target; }
            $queryString = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
            // Keep a non-empty URL for page 1. In Twig, an empty previous_url was
            // interpreted as "no previous page", which hid the back button on page 2.
            return $queryString === '' ? '?' : '?' . $queryString;
        };
        $hasPrevious = $enabled && $page > 1;
        $hasNext = $enabled && $page < $totalPages;
        return [
            'enabled' => $enabled && $totalPages > 1,
            'page' => $page,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $hasPrevious,
            'has_next' => $hasNext,
            'previous_url' => $hasPrevious ? $urlFor($page - 1) : '',
            'next_url' => $hasNext ? $urlFor($page + 1) : '',
        ];
    }

    private function safeQueryParam(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z][a-z0-9_]{1,40}$/', $value) ? $value : '';
    }

    /** @param list<string> $allowed */
    private function choiceString(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** @param array<string,mixed> $document @return list<array<string,mixed>> */
    private function blocksFromDocument(array $document): array
    {
        foreach (['blocks', 'content_blocks'] as $key) {
            if (is_array($document[$key] ?? null)) {
                $blocks = $this->normalizeBlocks($document[$key]);
                if ($blocks !== []) {
                    return $blocks;
                }
            }
        }

        if (is_array($document['content']['blocks'] ?? null)) {
            $blocks = $this->normalizeBlocks($document['content']['blocks']);
            if ($blocks !== []) {
                return $blocks;
            }
        }

        return [];
    }

    /** @param list<mixed> $blocks @return list<array<string,mixed>> */
    private function normalizeBlocks(array $blocks): array
    {
        $normalized = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? $block['block_type'] ?? 'markdown');
            $type = $type === 'rich_text' || $type === 'lead' ? 'markdown' : $type;
            $sortOrder = (int) ($block['sort_order'] ?? $index);

            if (is_array($block['data'] ?? null)) {
                $data = $block['data'];
                if (is_array($data['columns'] ?? null)) {
                    foreach ($data['columns'] as $columnIndex => $column) {
                        if (!is_array($column)) {
                            continue;
                        }
                        $column['blocks'] = $this->normalizeBlocks(is_array($column['blocks'] ?? null) ? $column['blocks'] : []);
                        $data['columns'][$columnIndex] = $column;
                    }
                }

                $normalized[] = [
                    'type' => $type,
                    'id' => (string) ($block['id'] ?? ''),
                    'enabled' => !array_key_exists('enabled', $block) || (bool) $block['enabled'],
                    'anchor' => (string) ($block['anchor'] ?? ''),
                    'css_class' => (string) ($block['css_class'] ?? ''),
                    'sort_order' => $sortOrder,
                    'data' => $data,
                ];
                continue;
            }

            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : json_decode((string) ($block['settings_json'] ?? '{}'), true);
            $settings = is_array($settings) ? $settings : [];
            $data = $settings + [
                'title' => (string) ($block['title'] ?? $settings['title'] ?? ''),
                'text' => (string) ($block['text'] ?? $block['content_text'] ?? $block['body'] ?? $settings['text'] ?? ''),
                'html' => (string) ($block['html'] ?? $block['embed_code'] ?? $settings['html'] ?? ''),
                'src' => (string) ($block['src'] ?? $block['url'] ?? $settings['src'] ?? $settings['url'] ?? $settings['background_src'] ?? ''),
                'alt' => (string) ($block['alt'] ?? $settings['alt'] ?? ''),
                'caption' => (string) ($block['caption'] ?? $settings['caption'] ?? ''),
                'tag' => $this->safeHeadingTag((string) ($block['tag'] ?? $settings['tag'] ?? 'h2')),
                'ordered' => (bool) ($block['ordered'] ?? $settings['ordered'] ?? false),
                'items' => is_array($block['items'] ?? null) ? $block['items'] : (is_array($settings['items'] ?? null) ? $settings['items'] : []),
                'height' => (int) ($settings['height'] ?? 420),
                'allow' => (string) ($settings['allow'] ?? ''),
                'sandbox' => (string) ($settings['sandbox'] ?? ''),
                'cite' => (string) ($block['cite'] ?? $settings['cite'] ?? ''),
            ];

            $normalized[] = [
                'type' => $type,
                'id' => (string) ($block['id'] ?? ''),
                'enabled' => !array_key_exists('enabled', $block) || (bool) $block['enabled'],
                'anchor' => (string) ($block['anchor'] ?? ''),
                'css_class' => (string) ($block['css_class'] ?? ''),
                'sort_order' => $sortOrder,
                'data' => $data,
            ];
        }

        usort($normalized, fn(array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']));
        return ($this->blocks ?? new BlockDocumentNormalizer())->normalize($normalized);
    }

    private function safeHeadingTag(string $tag): string
    {
        return in_array($tag, ['h2', 'h3', 'h4'], true) ? $tag : 'h2';
    }

    /** @param list<array<string,mixed>> $languages */
    private function ogLocale(string $languageCode, array $languages = []): string
    {
        foreach ($languages as $language) {
            $code = (string) ($language['code'] ?? '');
            if ($code === $languageCode) {
                $hreflang = (string) ($language['hreflang'] ?? '');
                if (preg_match('/^[a-z]{2}-[A-Z]{2}$/', $hreflang)) {
                    return str_replace('-', '_', $hreflang);
                }
            }
        }

        return match ($languageCode) {
            'en' => 'en_GB',
            'de' => 'de_CH',
            default => 'fr_CH',
        };
    }

    /** @param list<array<string,mixed>> $languages @return list<string> */
    private function ogLocaleAlternates(array $languages, string $activeLanguageCode): array
    {
        $locales = [];
        foreach ($languages as $language) {
            $code = (string) ($language['code'] ?? '');
            if ($code !== '' && $code !== $activeLanguageCode) {
                $locales[] = $this->ogLocale($code, $languages);
            }
        }
        return array_values(array_unique($locales));
    }

    private function languageSwitchItems(int $siteId, string $activeLanguageCode, string $currentPath, string $baseUrl): array
    {
        $items = [];
        foreach ($this->sites->getLanguages($siteId) as $language) {
            $code = (string) ($language['language_code'] ?? $language['code']);
            $label = (string) ($language['native_name'] ?? strtoupper($code));
            $relative = localized_path($currentPath, $code);
            $items[] = [
                'code' => $code,
                'hreflang' => (string) ($language['hreflang_code'] ?? $code),
                'label' => $label,
                'is_active' => $code === $activeLanguageCode,
                'url' => $relative,
                'absolute_url' => localized_absolute_url($currentPath, $code, $baseUrl),
            ];
        }
        return $items;
    }


    /**
     * @param list<array<string,mixed>> $languages
     * @return list<array<string,mixed>>
     */
    private function visibleLanguageSwitchItems(array $languages): array
    {
        return count($languages) > 1 ? $languages : [];
    }

    /** @param list<array<string,mixed>> $languages */
    private function xDefaultUrl(array $languages): string
    {
        foreach ($languages as $language) {
            if (($language['code'] ?? '') === 'fr') {
                return (string) ($language['absolute_url'] ?? '');
            }
        }
        return (string) ($languages[0]['absolute_url'] ?? '');
    }

    /** @return list<array{label:string,url:string}> */
    private function breadcrumbs(string $languageCode, string $currentPath, string $currentTitle): array
    {
        $ui = $this->ui($languageCode);
        $crumbs = [['label' => $ui['home'], 'url' => localized_path('/', $languageCode)]];
        if ($currentPath === '/') {
            return $crumbs;
        }
        $label = $currentTitle !== '' ? $currentTitle : trim(basename($currentPath));
        $crumbs[] = ['label' => $label, 'url' => ''];
        return $crumbs;
    }

    /** @return array<string,string> */
    private function ui(string $languageCode): array
    {
        $texts = [
            'fr' => [
                'skip_to_content' => 'Aller au contenu', 'home' => 'Accueil', 'search' => 'Recherche', 'search_placeholder' => 'Rechercher…', 'menu' => 'Menu', 'main_navigation' => 'Navigation principale', 'languages' => 'Langues', 'language_menu' => 'Langue', 'breadcrumbs' => 'Fil d’Ariane', 'footer_navigation' => 'Liens utiles', 'legal' => 'Mentions légales', 'privacy' => 'Politique de confidentialité', 'cookies' => 'Informations cookies', 'contact' => 'Contact', 'sitemap' => 'Plan du site', 'preview' => 'Prévisualisation de révision', 'discover' => 'Découvrir le CMS', 'hero_alt' => 'Illustration d’un CMS SEO-first avec contenus, recherche et publication', 'highlights' => 'Points forts', 'reference_front' => 'Front de référence', 'clean_publication' => 'Une publication propre, rapide et multilingue', 'search_title' => 'Recherche', 'search_results' => 'Résultats de recherche', 'taxonomy_archive' => 'Archive taxonomique', 'empty_archive' => 'Aucun contenu dans cette archive.', 'search_description' => 'Recherche dans le contenu publié du CMS.', 'no_results' => 'Aucun résultat trouvé.', 'search_hint' => 'Saisissez un mot-clé pour rechercher dans les contenus publiés.', 'gone_title' => 'Cette ressource n’est plus disponible', 'gone_text' => 'La page demandée a été retirée du site.', 'replacement' => 'Voir la ressource de remplacement', 'not_found_title' => 'Page introuvable', 'not_found_text' => 'La page demandée n’existe pas ou a été déplacée.', 'back_home' => 'Retour à l’accueil', 'articles_title' => 'Articles', 'articles_description' => 'Les derniers articles publiés.', 'article_filters' => 'Filtrer les articles', 'all_articles' => 'Tous les articles', 'categories' => 'Catégories', 'popular_tags' => 'Tags populaires', 'latest_articles' => 'Derniers articles', 'read_article' => 'Lire l’article', 'no_articles' => 'Aucun article publié pour ce filtre.', 'previous_page' => 'Page précédente', 'next_page' => 'Page suivante', 'by_author' => 'par',
            ],
            'en' => [
                'skip_to_content' => 'Skip to content', 'home' => 'Home', 'search' => 'Search', 'search_placeholder' => 'Search…', 'menu' => 'Menu', 'main_navigation' => 'Main navigation', 'languages' => 'Languages', 'language_menu' => 'Language', 'breadcrumbs' => 'Breadcrumbs', 'footer_navigation' => 'Useful links', 'legal' => 'Legal notice', 'privacy' => 'Privacy policy', 'cookies' => 'Cookie information', 'contact' => 'Contact', 'sitemap' => 'Sitemap', 'preview' => 'Revision preview', 'discover' => 'Explore the CMS', 'hero_alt' => 'Illustration of an SEO-first CMS with content, search and publishing', 'highlights' => 'Highlights', 'reference_front' => 'Reference front end', 'clean_publication' => 'Clean, fast and multilingual publishing', 'search_title' => 'Search', 'search_results' => 'Search results', 'taxonomy_archive' => 'Taxonomy archive', 'empty_archive' => 'No content in this archive.', 'search_description' => 'Search the published CMS content.', 'no_results' => 'No results found.', 'search_hint' => 'Enter a keyword to search published content.', 'gone_title' => 'This resource is no longer available', 'gone_text' => 'The requested page has been removed from the site.', 'replacement' => 'View the replacement resource', 'not_found_title' => 'Page not found', 'not_found_text' => 'The requested page does not exist or has moved.', 'back_home' => 'Back to home', 'articles_title' => 'Articles', 'articles_description' => 'The latest published articles.', 'article_filters' => 'Filter articles', 'all_articles' => 'All articles', 'categories' => 'Categories', 'popular_tags' => 'Popular tags', 'latest_articles' => 'Latest articles', 'read_article' => 'Read article', 'no_articles' => 'No published articles for this filter.', 'previous_page' => 'Previous page', 'next_page' => 'Next page', 'by_author' => 'by',
            ],
            'de' => [
                'skip_to_content' => 'Zum Inhalt springen', 'home' => 'Startseite', 'search' => 'Suche', 'search_placeholder' => 'Suchen…', 'menu' => 'Menü', 'main_navigation' => 'Hauptnavigation', 'languages' => 'Sprachen', 'language_menu' => 'Sprache', 'breadcrumbs' => 'Breadcrumbs', 'footer_navigation' => 'Nützliche Links', 'legal' => 'Impressum', 'privacy' => 'Datenschutzerklärung', 'cookies' => 'Cookie-Informationen', 'contact' => 'Kontakt', 'sitemap' => 'Sitemap', 'preview' => 'Revisionsvorschau', 'discover' => 'CMS entdecken', 'hero_alt' => 'Illustration eines SEO-first CMS mit Inhalten, Suche und Veröffentlichung', 'highlights' => 'Stärken', 'reference_front' => 'Referenz-Frontend', 'clean_publication' => 'Saubere, schnelle und mehrsprachige Veröffentlichung', 'search_title' => 'Suche', 'search_results' => 'Suchergebnisse', 'taxonomy_archive' => 'Taxonomie-Archiv', 'empty_archive' => 'Keine Inhalte in diesem Archiv.', 'search_description' => 'Suche in den veröffentlichten CMS-Inhalten.', 'no_results' => 'Keine Ergebnisse gefunden.', 'search_hint' => 'Geben Sie ein Stichwort ein, um veröffentlichte Inhalte zu durchsuchen.', 'gone_title' => 'Diese Ressource ist nicht mehr verfügbar', 'gone_text' => 'Die angeforderte Seite wurde von der Website entfernt.', 'replacement' => 'Ersatzressource anzeigen', 'not_found_title' => 'Seite nicht gefunden', 'not_found_text' => 'Die angeforderte Seite existiert nicht oder wurde verschoben.', 'back_home' => 'Zur Startseite', 'articles_title' => 'Artikel', 'articles_description' => 'Die neuesten veröffentlichten Artikel.', 'article_filters' => 'Artikel filtern', 'all_articles' => 'Alle Artikel', 'categories' => 'Kategorien', 'popular_tags' => 'Beliebte Tags', 'latest_articles' => 'Neueste Artikel', 'read_article' => 'Artikel lesen', 'no_articles' => 'Keine veröffentlichten Artikel für diesen Filter.', 'previous_page' => 'Vorherige Seite', 'next_page' => 'Nächste Seite', 'by_author' => 'von',
            ],
        ];
        return $texts[$languageCode] ?? $texts['fr'];
    }

    /** @return list<array{icon:string,title:string,text:string}> */
    private function homeCards(string $languageCode): array
    {
        return match ($languageCode) {
            'en' => [
                ['icon' => '⌁', 'title' => 'SEO-first', 'text' => 'Canonical URLs, hreflang links, Open Graph metadata and clean published routes.'],
                ['icon' => '文', 'title' => 'Multilingual', 'text' => 'French, English and German references with language-aware navigation.'],
                ['icon' => '⚡', 'title' => 'Lightweight runtime', 'text' => 'Native HTML, Twig templates, compact CSS and a minimal public layer.'],
            ],
            'de' => [
                ['icon' => '⌁', 'title' => 'SEO-first', 'text' => 'Canonical-URLs, hreflang-Links, Open-Graph-Metadaten und saubere veröffentlichte Routen.'],
                ['icon' => '文', 'title' => 'Mehrsprachig', 'text' => 'Referenzen auf Französisch, Englisch und Deutsch mit sprachsensibler Navigation.'],
                ['icon' => '⚡', 'title' => 'Leichtes Runtime', 'text' => 'Natives HTML, Twig-Templates, kompaktes CSS und eine minimale öffentliche Schicht.'],
            ],
            default => [
                ['icon' => '⌁', 'title' => 'SEO-first', 'text' => 'Canonical, hreflang, Open Graph, meta description et routes publiées propres.'],
                ['icon' => '文', 'title' => 'Multilingue', 'text' => 'Références en français, anglais et allemand avec navigation adaptée à la langue.'],
                ['icon' => '⚡', 'title' => 'Runtime léger', 'text' => 'HTML natif, templates Twig, CSS compact et couche publique minimale.'],
            ],
        };
    }


    private function normalizeArticleIndexItems(array $items, string $languageCode, string $dateFormat = 'medium'): array
    {
        $dateFormat = $this->safeDateFormat($dateFormat);
        return array_map(fn(array $item): array => [
            'id' => (int) ($item['id'] ?? 0),
            'type_key' => (string) ($item['type_key'] ?? 'article'),
            'title' => (string) ($item['title'] ?? ''),
            'path' => localized_path((string) ($item['full_path'] ?? ''), $languageCode),
            'summary' => (string) ($item['summary'] ?? ''),
            'published_at' => (string) ($item['published_at'] ?? ''),
            'published_at_label' => $this->formatPublicDate((string) ($item['published_at'] ?? ''), $dateFormat, $languageCode),
            'updated_at' => (string) ($item['updated_at'] ?? ''),
            'updated_at_label' => $this->formatPublicDate((string) ($item['updated_at'] ?? ''), $dateFormat, $languageCode),
            'author_name' => (string) ($item['author_name'] ?? ''),
            'categories' => array_map(fn(array $term): array => $this->normalizeArticleFacet($term, $languageCode, 'category'), is_array($item['categories'] ?? null) ? $item['categories'] : []),
            'tags' => array_map(fn(array $term): array => $this->normalizeArticleFacet($term, $languageCode, 'tag'), is_array($item['tags'] ?? null) ? $item['tags'] : []),
        ], $items);
    }

    /** @param array{categories:list<array<string,mixed>>,tags:list<array<string,mixed>>} $facets */
    private function normalizeArticleFacets(array $facets, string $languageCode): array
    {
        return [
            'categories' => array_map(fn(array $term): array => $this->normalizeArticleFacet($term, $languageCode, 'category'), $facets['categories'] ?? []),
            'tags' => array_map(fn(array $term): array => $this->normalizeArticleFacet($term, $languageCode, 'tag'), $facets['tags'] ?? []),
        ];
    }

    private function normalizeArticleFacet(array $term, string $languageCode, string $filterKey = ''): array
    {
        $slug = (string) ($term['slug'] ?? '');
        $url = localized_path('/articles', $languageCode);
        if ($filterKey !== '' && $slug !== '') {
            $url .= '?' . http_build_query([$filterKey => $slug], '', '&', PHP_QUERY_RFC3986);
        } elseif (!empty($term['full_path'])) {
            $url = localized_path((string) $term['full_path'], $languageCode);
        }
        return [
            'name' => (string) ($term['name'] ?? $term['term_key'] ?? ''),
            'slug' => $slug,
            'url' => $url,
            'count' => (int) ($term['article_count'] ?? 0),
        ];
    }

    private function normalizeSearchResults(array $results, string $languageCode, string $query): array
    {
        $terms = $this->searchHighlightTerms($query);
        return array_map(function (array $result) use ($languageCode, $terms): array {
            $title = (string) $result['title'];
            $summary = (string) $result['summary'];
            $searchText = (string) ($result['search_text'] ?? '');
            $visibleSummary = $summary !== '' ? $summary : $this->searchExcerpt($searchText, $terms);

            return [
                'type_key' => (string) $result['type_key'],
                'title' => $title,
                'title_html' => $this->highlightSearchText($title, $terms),
                'path' => localized_path((string) $result['path'], $languageCode),
                'summary' => $visibleSummary,
                'summary_html' => $this->highlightSearchText($visibleSummary, $terms),
                'taxonomy_labels' => (string) ($result['taxonomy_labels'] ?? ''),
                'rank' => isset($result['search_rank']) ? round((float) $result['search_rank'], 4) : null,
            ];
        }, $results);
    }

    /** @return list<string> */
    private function searchHighlightTerms(string $query): array
    {
        if (!preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $matches)) {
            return [];
        }
        $terms = [];
        foreach ($matches[0] as $term) {
            $term = trim($term);
            if (mb_strlen($term) < 2) {
                continue;
            }
            $terms[$term] = $term;
        }
        return array_values($terms);
    }

    private function highlightSearchText(string $text, array $terms): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($escaped === '' || $terms === []) {
            return $escaped;
        }

        $pattern = '~(' . implode('|', array_map(static fn(string $term): string => preg_quote($term, '~'), $terms)) . ')~iu';
        return preg_replace($pattern, '<mark class="search-highlight">$1</mark>', $escaped) ?? $escaped;
    }

    private function searchExcerpt(string $text, array $terms): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        if ($terms === []) {
            return mb_strlen($text) > 180 ? mb_substr($text, 0, 177) . '…' : $text;
        }

        $firstPosition = null;
        foreach ($terms as $term) {
            $position = mb_stripos($text, $term);
            if ($position !== false && ($firstPosition === null || $position < $firstPosition)) {
                $firstPosition = $position;
            }
        }

        if ($firstPosition === null) {
            return mb_strlen($text) > 180 ? mb_substr($text, 0, 177) . '…' : $text;
        }

        $start = max(0, $firstPosition - 70);
        $excerpt = mb_substr($text, $start, 180);
        if ($start > 0) {
            $excerpt = '…' . ltrim($excerpt);
        }
        if (($start + mb_strlen($excerpt)) < mb_strlen($text)) {
            $excerpt = rtrim($excerpt) . '…';
        }
        return $excerpt;
    }

    private function normalizeArchiveItems(array $items, string $languageCode): array
    {
        return array_map(fn(array $item): array => [
            'type_key' => (string) $item['type_key'],
            'title' => (string) $item['title'],
            'path' => localized_path((string) $item['full_path'], $languageCode),
            'summary' => (string) $item['summary'],
        ], $items);
    }
}
