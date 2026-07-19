<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;
use App\Modules\Business\Contracts\ProductContentSourcePort;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use InvalidArgumentException;

/** Builds immutable public DTOs; storefront rendering subsequently reads core.sqlite only. */
final class StorefrontProjectionService
{
    public function __construct(
        private readonly Database $core,
        private readonly Database $business,
        private readonly ProductContentSourcePort $products,
        private readonly PublicCatalogRepository $catalog,
        private readonly BusinessCatalogSellableReadService $sellables,
        private readonly ?SaleDatabaseConnection $saleConnection = null,
    ) {}

    /** @return list<array{products:int,collections:int,channel_id:int,locale:string}> */
    public function rebuildActiveStorefronts(int $siteId): array
    {
        $configurations=$this->core->all(
            "SELECT language_code,channel_id FROM cms_shop_configurations WHERE site_id=? AND status='active' ORDER BY language_code",
            [$siteId]
        );
        $results=[];
        foreach($configurations as $configuration){
            $channelId=(int)($configuration['channel_id']??0);
            $results[]=$this->rebuild($siteId,$channelId>0?$channelId:null,(string)$configuration['language_code']);
        }
        return $results;
    }

    /** @return array{products:int,collections:int,channel_id:int,locale:string} */
    public function rebuild(int $siteId, ?int $channelId = null, string $locale = 'fr'): array
    {
        $locale = strtolower(trim($locale));
        if (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $locale)) {
            throw new InvalidArgumentException('storefront.locale_invalid');
        }
        $channelId ??= (int) ($this->core->one(
            "SELECT channel_id FROM cms_sales_channel_storefronts WHERE site_id=? AND is_default=1 AND status='active' LIMIT 1",
            [$siteId]
        )['channel_id'] ?? 0);
        if ($channelId < 1) {
            throw new InvalidArgumentException('storefront.channel_missing');
        }

        $productDtos = [];
        $offset = 0;
        do {
            $page = $this->catalog->products($siteId, [], 100, $offset);
            foreach ($page['items'] as $product) {
                $dto = $this->productDto($siteId, $channelId, $locale, (int) $product['id']);
                if ($dto !== null) {
                    $productDtos[] = $dto;
                }
            }
            $offset += 100;
        } while ($page['has_more']);
        $productDtos = $this->hydrateProductRelations($productDtos);

        $collections = [];
        foreach ($this->catalog->categories($siteId) as $category) {
            $count = count(array_filter($productDtos, static fn(array $dto): bool => (int) ($dto['collection']['collection_id'] ?? 0) === (int) $category['id']));
            $collections[] = [
                'contract' => 'storefront.collection.v1', 'version' => 1,
                'collection_id' => (int) $category['id'], 'site_id' => $siteId, 'channel_id' => $channelId, 'locale' => $locale,
                'slug' => (string) $category['slug'], 'name' => (string) $category['name'], 'description' => (string) ($category['description'] ?? ''),
                'product_count' => $count, 'url' => '/shop/collections/' . $category['slug'],
                'seo' => ['canonical' => '/shop/collections/' . $category['slug'], 'robots' => $count > 0 ? 'index,follow' : 'noindex,follow'],
            ];
        }

        $this->core->transaction(function () use ($siteId, $channelId, $locale, $productDtos, $collections): void {
            $this->core->run('UPDATE storefront_product_publication_history SET is_active=0 WHERE site_id=? AND channel_id=? AND locale=?',[$siteId,$channelId,$locale]);
            foreach ($productDtos as $dto) {
                $this->core->run(
                    'INSERT INTO storefront_product_publication_history(site_id,channel_id,locale,product_id,first_published_at,last_published_at,is_active)
                     VALUES(?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1)
                     ON CONFLICT(site_id,channel_id,locale,product_id) DO UPDATE SET last_published_at=CURRENT_TIMESTAMP,is_active=1',
                    [$siteId,$channelId,$locale,$dto['product_id']]
                );
            }
            $this->core->run('DELETE FROM storefront_product_facet_values WHERE site_id=? AND channel_id=? AND locale=?', [$siteId,$channelId,$locale]);
            $this->core->run('DELETE FROM storefront_product_query_index WHERE site_id=? AND channel_id=? AND locale=?', [$siteId,$channelId,$locale]);
            $this->core->run('DELETE FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=?', [$siteId,$channelId,$locale]);
            $this->core->run('DELETE FROM storefront_collection_projections WHERE site_id=? AND channel_id=? AND locale=?', [$siteId,$channelId,$locale]);
            foreach ($productDtos as $dto) {
                $json = $this->json($dto);
                $this->core->run(
                    'INSERT INTO storefront_product_projections(site_id,channel_id,locale,product_id,slug,collection_id,dto_version,is_indexable,dto_json,source_hash)
                     VALUES(?,?,?,?,?,?,3,1,?,?)',
                    [$siteId,$channelId,$locale,$dto['product_id'],$dto['slug'],$dto['collection']['collection_id'] ?? null,$json,hash('sha256',$json)]
                );
                $this->insertQueryIndex($dto);
                foreach ($this->facetRows($dto) as $facet) {
                    $this->core->run(
                        'INSERT OR IGNORE INTO storefront_product_facet_values
                         (site_id,channel_id,locale,product_id,facet_type,facet_key,facet_label,value_key,value_label,group_key,group_label,sort_order)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$siteId,$channelId,$locale,$dto['product_id'],$facet['type'],$facet['key'],$facet['label'],$facet['value_key'],$facet['value_label'],$facet['group_key'],$facet['group_label'],$facet['sort_order']]
                    );
                }
            }
            foreach ($collections as $dto) {
                $json = $this->json($dto);
                $this->core->run(
                    'INSERT INTO storefront_collection_projections(site_id,channel_id,locale,collection_id,slug,dto_version,dto_json,source_hash)
                     VALUES(?,?,?,?,?,1,?,?)',
                    [$siteId,$channelId,$locale,$dto['collection_id'],$dto['slug'],$json,hash('sha256',$json)]
                );
            }
        });
        if ($this->business->tableExists('business_storefront_projection_invalidations')) {
            $this->business->run('UPDATE business_storefront_projection_invalidations SET processed_at=CURRENT_TIMESTAMP WHERE site_id=? AND processed_at IS NULL', [$siteId]);
        }
        return ['products' => count($productDtos), 'collections' => count($collections), 'channel_id' => $channelId, 'locale' => $locale];
    }

    /** @return array<string,mixed>|null */
    private function productDto(int $siteId, int $channelId, string $locale, int $productId): ?array
    {
        $source = $this->products->productSnapshot($siteId, $productId, $locale);
        $product = $source['product'] ?? null;
        if (!is_array($product) || ($product['status'] ?? null) !== 'active' || empty($product['is_public']) || empty($product['is_ecommerce_enabled'])) {
            return null;
        }
        $result = $this->sellables->listSellableVariants($siteId, [
            'product_id' => $productId, 'channel' => 'ecommerce', 'language' => $locale,
            'currency' => $this->channelCurrency($siteId,$channelId), 'limit' => 100, 'include_internal_fields' => false,
            'include_not_sellable' => true,
        ]);
        $assets = array_values(array_filter(array_map(fn(array $asset): ?array => $this->media($asset,$siteId), (array) ($source['assets'] ?? []))));
        $visualAssets = array_values(array_filter($assets, static fn(array $media): bool =>
            in_array((string)($media['role']??''), ['main','gallery','variant','thumbnail'], true)
            && in_array((string)($media['type']??''), ['image','video'], true)
        ));
        $productMedia = array_values(array_filter($visualAssets, static fn(array $media): bool => (int)($media['variant_id']??0)===0));
        $documentAssets=array_values(array_filter($assets,static fn(array $media):bool=>(int)($media['variant_id']??0)===0&&in_array((string)($media['role']??''),['document','technical_sheet'],true)));
        $sellables = [];
        foreach ($result['items'] as $item) {
            $regular=(int)$item['regular_sale_price_minor']; $final=(int)$item['sale_price_minor']; $discount=max(0,$regular-$final);
            $availability=$this->publicAvailability((array)($item['availability']??[]),(float)($item['metadata']['available_quantity']??0),$siteId,$locale);
            $variantId=(int)$item['business_variant_id'];
            $variantMedia=array_values(array_filter($visualAssets,static fn(array $media):bool=>(int)($media['variant_id']??0)===$variantId));
            if ($variantMedia===[]) $variantMedia=$productMedia;
            $orderable=!empty($item['is_sellable'])&&!empty($availability['is_orderable']);
            $sellables[] = [
                'contract' => 'storefront.sellable.v2', 'sellable_id' => (int) $item['sellable_id'],
                'variant_id' => (int) $item['business_variant_id'], 'sku' => (string) $item['sku'], 'name' => (string) $item['variant_name'],
                'options' => $item['variant_options'] ?? [],
                'price' => ['regular_minor'=>$regular,'final_minor'=>$final,'currency'=>(string)$item['currency'],'discount_amount_minor'=>$discount,'discount_percent_bps'=>$regular>0?(int)round(($discount/$regular)*10000):0,'discounts'=>$item['discounts_applied']??[]],
                'availability' => $availability,
                'media' => $variantMedia,
                'orderable'=>$orderable,
                'cta' => $orderable?['action'=>'add_to_cart','sellable_id'=>(int)$item['sellable_id'],'method'=>'POST','endpoint_template'=>'/api/v1/sale/channels/{channel}/cart/{token}/lines']:null,
            ];
        }
        $groups = array_values(array_map(static fn(array $group): array => [
            'group_id' => (int) ($group['id'] ?? 0),
            'code' => (string) ($group['code'] ?? ''),
            'name' => (string) ($group['name'] ?? ''),
        ], array_values(array_filter((array) ($source['attribute_groups'] ?? []), 'is_array'))));
        $attributes = $this->publicAttributes($source);
        $slug = (string) $product['slug'];
        $canonical = '/shop/products/' . $slug;
        $primary=null; foreach($sellables as $candidate) if(!empty($candidate['orderable'])){$primary=$candidate;break;} $primary??=$sellables[0]??null;
        $price=is_array($primary['price']??null)?$primary['price']:null;
        $availability=is_array($primary['availability']??null)?$primary['availability']:$this->publicAvailability(['status'=>'unavailable','is_orderable'=>false],0,$siteId,$locale);
        $offers=[]; foreach($sellables as $sellable) $offers[]=['@type'=>'Offer','sku'=>$sellable['sku'],'priceCurrency'=>$sellable['price']['currency'],'price'=>number_format($sellable['price']['final_minor']/100,2,'.',''),'availability'=>!empty($sellable['orderable'])?'https://schema.org/InStock':'https://schema.org/OutOfStock','url'=>$canonical.'?variant='.$sellable['sellable_id']];
        $displayMedia=$productMedia;
        if($displayMedia===[])foreach($sellables as$sellable)if(($sellable['media']??[])!==[]){$displayMedia=(array)$sellable['media'];break;}
        $jsonLdImages=array_values(array_map(static fn(array $media):string=>(string)$media['url'],array_filter($displayMedia,static fn(array $media):bool=>($media['type']??'')==='image')));
        $jsonLd = ['@context'=>'https://schema.org','@type'=>($product['type']??'')==='service'?'Service':'Product','name'=>$product['name'],'description'=>(string)($product['short_description']??''),'url'=>$canonical,'image'=>$jsonLdImages,'brand'=>!empty($product['brand_name'])?['@type'=>'Brand','name'=>$product['brand_name']]:null,'offers'=>$offers];
        $hreflang=[]; foreach($this->core->all('SELECT language_code FROM site_languages WHERE site_id=? AND is_active=1 ORDER BY is_default DESC,sort_order,language_code',[$siteId]) as $language) $hreflang[]=['locale'=>(string)$language['language_code'],'url'=>$canonical];
        return [
            'contract' => 'storefront.product.v3', 'version' => 3, 'product_id' => $productId, 'site_id' => $siteId, 'channel_id' => $channelId, 'locale' => $locale,
            'projection'=>['generated_at'=>gmdate('c'),'state'=>'current'],
            'slug' => $slug, 'type' => (string) $product['type'], 'name' => (string) $product['name'], 'summary' => (string) ($product['short_description'] ?? ''),
            'card_summary'=>mb_substr((string)($product['short_description']??''),0,70),
            'description' => (string) ($product['description'] ?? ''), 'brand' => ['brand_id' => isset($product['brand_id']) ? (int) $product['brand_id'] : null, 'name' => $product['brand_name'] ?? null, 'slug' => $product['brand_slug'] ?? null],
            'collection' => ['collection_id' => isset($product['category_id']) ? (int) $product['category_id'] : null, 'name' => $product['category_name'] ?? null, 'slug' => $product['category_slug'] ?? null],
            'groups' => $groups, 'attributes' => $attributes, 'updated_at' => (string) ($product['updated_at'] ?? gmdate('c')),
            'media' => $displayMedia, 'documents'=>$documentAssets, 'sellables' => $sellables, 'default_sellable_id' => $primary['sellable_id']??null, 'sku'=>$primary['sku']??null, 'price' => $price, 'availability' => $availability,
            'cta' => !empty($primary['cta'])?($primary['cta']+['label'=>$locale==='en'?'Add to cart':'Ajouter au panier']):null, 'url' => $canonical,
            'commerce'=>$this->commerceInformation($siteId,$channelId,$locale,(int)($price['final_minor']??0)),
            'content'=>$this->linkedContent($siteId,$productId,$locale),
            '_relation_refs'=>array_values(array_filter((array)($source['relations']??[]),'is_array')),
            'relations'=>[],
            'seo' => ['canonical'=>$canonical,'robots'=>'index,follow','hreflang'=>$hreflang,'json_ld'=>$jsonLd],
        ];
    }

    /** @param list<array<string,mixed>> $products @return list<array<string,mixed>> */
    private function hydrateProductRelations(array $products): array
    {
        $byId=[]; foreach($products as $index=>$product) $byId[(int)$product['product_id']]=['index'=>$index,'product'=>$product];
        foreach($products as $index=>$product) {
            $groups=[];
            foreach((array)($product['_relation_refs']??[]) as $relation) {
                if(!is_array($relation)) continue;
                $target=$byId[(int)($relation['related_product_id']??0)]['product']??null;
                if(!is_array($target)) continue;
                $type=(string)($relation['relation_type']??'related');
                $type=['similar'=>'related','replacement'=>'alternative','bundle_candidate'=>'related'][$type]??$type;
                if(!in_array($type,['related','alternative','accessory','upsell','cross_sell'],true)) continue;
                $groups[$type][]=$this->productCard($target)+(isset($relation['source'])?['relation_source'=>(string)$relation['source']]:[]);
            }
            $relations=[];
            foreach(['related','alternative','accessory','upsell','cross_sell'] as $type) if(($groups[$type]??[])!==[]) $relations[]=[
                'type'=>$type,'label'=>$this->relationLabel($type,(string)$product['locale']),'items'=>array_values($groups[$type]),
            ];
            unset($products[$index]['_relation_refs']); $products[$index]['relations']=$relations;
        }
        return $products;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function productCard(array $product): array
    {
        return [
            'contract'=>'storefront.product_card.v1','product_id'=>(int)$product['product_id'],'slug'=>(string)$product['slug'],
            'name'=>(string)$product['name'],'summary'=>(string)($product['summary']??''),'card_summary'=>(string)($product['card_summary']??''),
            'type'=>(string)$product['type'],'locale'=>(string)($product['locale']??'fr'),'brand'=>$product['brand']??null,'attributes'=>$product['attributes']??[],'media'=>array_slice((array)($product['media']??[]),0,1),'price'=>$product['price']??null,
            'availability'=>$product['availability']??null,'cta'=>$product['cta']??null,'projection'=>$product['projection']??null,'url'=>(string)$product['url'],
        ];
    }

    private function relationLabel(string $type,string $locale): string
    {
        $en=str_starts_with($locale,'en');
        return ($en?['related'=>'Related products','alternative'=>'Alternatives','accessory'=>'Accessories','upsell'=>'Recommended upgrade','cross_sell'=>'Frequently bought together']:['related'=>'Produits liés','alternative'=>'Alternatives','accessory'=>'Accessoires','upsell'=>'Monter en gamme','cross_sell'=>'À acheter ensemble'])[$type]??$type;
    }

    /** @param array<string,mixed> $availability @return array<string,mixed> */
    private function publicAvailability(array $availability,float $available,int $siteId,string $locale): array
    {
        $technical=(string)($availability['status']??'unavailable');
        $orderable=!empty($availability['is_orderable']);
        $threshold=(int)($this->core->one('SELECT last_available_threshold FROM cms_shop_configurations WHERE site_id=? AND language_code=? LIMIT 1',[$siteId,$locale])['last_available_threshold']??1);
        $display=match(true){!$orderable||in_array($technical,['unavailable','contact_us'],true)=>'unavailable',$technical==='backorder'=>'on_order',$technical==='in_stock'&&$available>0&&$available<=max(1,$threshold)=>'last_available',default=>'available'};
        $en=str_starts_with($locale,'en');
        $labels=$en?['unavailable'=>'Unavailable','on_order'=>'On order','last_available'=>'Last one available','available'=>'Available']:['unavailable'=>'Indisponible','on_order'=>'Sur commande','last_available'=>'Dernier disponible','available'=>'Disponible'];
        return array_merge($availability,[
            'status'=>$technical,'display_status'=>$display,'label'=>$labels[$display],
            'icon'=>['unavailable'=>'x-circle','on_order'=>'clock','last_available'=>'exclamation-circle','available'=>'check-circle'][$display],
            'tone'=>['unavailable'=>'danger','on_order'=>'warning','last_available'=>'warning','available'=>'success'][$display],
            'is_orderable'=>$orderable,'available_quantity'=>$available,
        ]);
    }

    private function channelCurrency(int $siteId,int $channelId): string
    {
        $db=$this->saleConnection?->database();
        $channel=$db?->one('SELECT currency FROM sale_channels WHERE site_id=? AND id=?',[$siteId,$channelId]);
        return strtoupper((string)($channel['currency']??'CHF'));
    }

    /** @return array<string,mixed> */
    private function commerceInformation(int $siteId,int $channelId,string $locale,int $amountMinor): array
    {
        $db=$this->saleConnection?->database(); $en=str_starts_with($locale,'en');
        if($db===null) return ['channel_id'=>$channelId,'delivery_methods'=>[],'payment_methods'=>[],'checkout_available'=>false];
        $delivery=[];
        if($db->tableExists('sale_fulfillment_methods')) foreach($db->all(
            "SELECT code,label_fr,label_en,fulfillment_type,flat_rate_minor,free_above_minor,requires_shipping_address FROM sale_fulfillment_methods
             WHERE site_id=? AND status='active' AND (active_from IS NULL OR active_from<=CURRENT_TIMESTAMP) AND (active_until IS NULL OR active_until>CURRENT_TIMESTAMP)
             ORDER BY sort_order,code",[$siteId]
        ) as $row) $delivery[]=['code'=>(string)$row['code'],'label'=>(string)$row[$en?'label_en':'label_fr'],'type'=>(string)$row['fulfillment_type'],'price_minor'=>(int)$row['flat_rate_minor'],'free_above_minor'=>$row['free_above_minor']!==null?(int)$row['free_above_minor']:null,'requires_address'=>(bool)$row['requires_shipping_address']];
        $payments=[];
        if($db->tableExists('sale_payment_methods')) foreach($db->all(
            "SELECT code,name,label_fr,label_en,description_fr,description_en,method_type,currency,min_amount_minor,max_amount_minor FROM sale_payment_methods
             WHERE site_id=? AND (channel_id=? OR channel_id IS NULL) AND status='active' AND archived_at IS NULL AND is_public=1
               AND (min_amount_minor IS NULL OR min_amount_minor<=?) AND (max_amount_minor IS NULL OR max_amount_minor>=?) ORDER BY sort_order,code",
            [$siteId,$channelId,$amountMinor,$amountMinor]
        ) as $row) $payments[]=['code'=>(string)$row['code'],'label'=>trim((string)$row[$en?'label_en':'label_fr'])?:(string)$row['name'],'description'=>(string)($row[$en?'description_en':'description_fr']??''),'type'=>(string)$row['method_type'],'currency'=>$row['currency']];
        $checkout=$db->tableExists('sale_channel_checkout_configs')?$db->one("SELECT checkout_enabled,status FROM sale_channel_checkout_configs WHERE site_id=? AND channel_id=?",[$siteId,$channelId]):null;
        return ['channel_id'=>$channelId,'delivery_methods'=>$delivery,'payment_methods'=>$payments,'checkout_available'=>$checkout===null||((int)$checkout['checkout_enabled']===1&&(string)$checkout['status']==='active')];
    }

    /** @return list<array<string,mixed>> */
    private function linkedContent(int $siteId,int $productId,string $locale): array
    {
        if(!$this->core->tableExists('business_product_content_links')||!$this->core->tableExists('public_content_snapshots')) return [];
        $rows=$this->core->all(
            "SELECT l.relation_type,l.is_canonical,pcs.title,pcs.route_path,pcs.blocks_json
             FROM business_product_content_links l INNER JOIN public_content_snapshots pcs
               ON pcs.site_id=l.site_id AND pcs.resource_id=l.content_entry_id AND pcs.resource_type='content_entry' AND pcs.language_code=?
             WHERE l.site_id=? AND l.product_id=? AND l.status='active' AND (l.locale IS NULL OR l.locale=?)
             ORDER BY l.is_canonical DESC,l.id",[$locale,$siteId,$productId,$locale]
        );
        return array_map(static fn(array $row):array=>['relation_type'=>(string)$row['relation_type'],'canonical'=>(bool)$row['is_canonical'],'title'=>(string)$row['title'],'url'=>(string)$row['route_path'],'blocks'=>json_decode((string)$row['blocks_json'],true)?:[]],$rows);
    }

    /** @param array<string,mixed> $source @return list<array<string,mixed>> */
    private function publicAttributes(array $source): array
    {
        $options = [];
        foreach ((array) ($source['attribute_options'] ?? []) as $option) {
            if (!is_array($option)) continue;
            $attributeId=(int)($option['attribute_id']??0); $optionValue=(string)($option['value']??'');
            $options[$attributeId][$optionValue]=$option;
            $options[$attributeId][$this->normalizedText($optionValue)]=$option;
        }
        $attributes = [];
        $grouped = [];
        foreach ((array) ($source['attributes'] ?? []) as $row) {
            if (!is_array($row) || empty($row['is_public'])) continue;
            $attributeId = (int) ($row['id'] ?? 0);
            if ($attributeId > 0) $grouped[$attributeId][] = $row;
        }
        foreach ($grouped as $attributeId => $rows) {
            $row=$rows[0]; // source rows put the requested locale before the "und" fallback
            $preferredLanguage=(string)($row['language']??'und');
            $values=[];
            foreach ($rows as $valueRow) {
                if ((string)($valueRow['language']??'und')!==$preferredLanguage) continue;
                $values=array_merge($values,$this->attributeValues($valueRow));
            }
            $values=array_values(array_unique($values));
            $items = [];
            foreach ($values as $value) {
                $option = $options[$attributeId][$value] ?? $options[$attributeId][$this->normalizedText($value)] ?? null;
                $items[] = [
                    'key' => is_array($option) ? (string) ($option['code'] ?? $value) : $this->valueKey($value),
                    'label' => is_array($option) ? (string) ($option['label'] ?? $value) : $value,
                    'value' => $value,
                    'color' => is_array($option) ? ($option['color_hex'] ?? null) : null,
                ];
            }
            $attributes[] = [
                'attribute_id' => $attributeId,
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['data_type'] ?? 'text'),
                'unit' => $row['unit'] ?? null,
                'filterable' => !empty($row['is_filterable']),
                'searchable' => !empty($row['is_searchable']),
                'group' => [
                    'group_id' => (int) ($row['group_id'] ?? 0),
                    'code' => (string) ($row['group_code'] ?? ''),
                    'name' => (string) ($row['group_name'] ?? ''),
                ],
                'values' => $items,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }
        return $attributes;
    }

    /** @param array<string,mixed> $row @return list<string> */
    private function attributeValues(array $row): array
    {
        if ($row['value_json'] ?? null) {
            $decoded = json_decode((string) $row['value_json'], true);
            if (is_array($decoded)) {
                return array_values(array_unique(array_filter(array_map(
                    static fn(mixed $value): string => trim((string) $value),
                    $decoded
                ), static fn(string $value): bool => $value !== '')));
            }
        }
        if ($row['value_text'] ?? null) {
            $value = trim((string) $row['value_text']);
            if ((string) ($row['data_type'] ?? '') === 'multi_select') {
                return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
            }
            return $value === '' ? [] : [$value];
        }
        return array_key_exists('value_number', $row) && $row['value_number'] !== null
            ? [rtrim(rtrim(number_format((float) $row['value_number'], 6, '.', ''), '0'), '.')]
            : [];
    }

    /** @param array<string,mixed> $dto */
    private function insertQueryIndex(array $dto): void
    {
        $price = is_array($dto['price'] ?? null) ? $dto['price'] : [];
        $regular = isset($price['regular_minor']) ? (int) $price['regular_minor'] : null;
        $final = isset($price['final_minor']) ? (int) $price['final_minor'] : null;
        $discount = $regular !== null && $final !== null ? max(0, $regular - $final) : null;
        $percent = $discount !== null && $regular > 0 ? (int) round(($discount / $regular) * 10000) : null;
        $availability = (string) ($dto['availability']['status'] ?? 'unavailable');
        if (!in_array($availability, ['in_stock','deliverable','backorder','unavailable','contact_us'], true)) $availability = 'unavailable';
        $searchParts = [$dto['name'] ?? '', $dto['summary'] ?? '', $dto['description'] ?? '', $dto['brand']['name'] ?? '', $dto['collection']['name'] ?? ''];
        foreach ((array) ($dto['groups'] ?? []) as $group) if (is_array($group)) $searchParts[] = $group['name'] ?? '';
        foreach ((array) ($dto['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute) || empty($attribute['searchable'])) continue;
            $searchParts[] = $attribute['name'] ?? '';
            foreach ((array) ($attribute['values'] ?? []) as $value) if (is_array($value)) $searchParts[] = $value['label'] ?? $value['value'] ?? '';
        }
        $firstPublished=(string)($this->core->one(
            'SELECT first_published_at FROM storefront_product_publication_history WHERE site_id=? AND channel_id=? AND locale=? AND product_id=?',
            [$dto['site_id'],$dto['channel_id'],$dto['locale'],$dto['product_id']]
        )['first_published_at']??$dto['updated_at']??gmdate('c'));
        $this->core->run(
            'INSERT INTO storefront_product_query_index
             (site_id,channel_id,locale,product_id,slug,name,name_sort,search_text,product_type,brand_id,brand_slug,brand_name,
              category_id,category_slug,category_name,regular_price_minor,final_price_minor,discount_amount_minor,discount_percent_bps,
              currency,availability_status,newest_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $dto['site_id'],$dto['channel_id'],$dto['locale'],$dto['product_id'],$dto['slug'],$dto['name'],
                $this->normalizedText((string) $dto['name']),$this->normalizedText(implode(' ', array_map('strval', $searchParts))),$dto['type'],
                $dto['brand']['brand_id'] ?? null,$dto['brand']['slug'] ?? null,$dto['brand']['name'] ?? null,
                $dto['collection']['collection_id'] ?? null,$dto['collection']['slug'] ?? null,$dto['collection']['name'] ?? null,
                $regular,$final,$discount,$percent,$price['currency'] ?? null,$availability,$firstPublished,
            ]
        );
    }

    /** @param array<string,mixed> $dto @return list<array{type:string,key:string,label:string,value_key:string,value_label:string,group_key:string,group_label:string,sort_order:int}> */
    private function facetRows(array $dto): array
    {
        $rows = [];
        $add = static function (array &$rows, string $type, string $key, string $label, mixed $valueKey, mixed $valueLabel, string $groupKey = '', string $groupLabel = '', int $sort = 0): void {
            $valueKey = trim((string) $valueKey); $valueLabel = trim((string) $valueLabel);
            if ($valueKey === '' || $valueLabel === '') return;
            $rows[] = ['type'=>$type,'key'=>$key,'label'=>$label,'value_key'=>$valueKey,'value_label'=>$valueLabel,'group_key'=>$groupKey,'group_label'=>$groupLabel,'sort_order'=>$sort];
        };
        $add($rows, 'brand', 'brand', 'Marques', $dto['brand']['slug'] ?? '', $dto['brand']['name'] ?? '');
        $add($rows, 'category', 'category', 'Catégories', $dto['collection']['slug'] ?? '', $dto['collection']['name'] ?? '');
        foreach ((array) ($dto['groups'] ?? []) as $index => $group) {
            if (is_array($group)) $add($rows, 'group', 'group', 'Groupes de produits', $group['code'] ?? '', $group['name'] ?? '', '', '', $index);
        }
        $availability = (string) ($dto['availability']['status'] ?? 'unavailable');
        $availabilityLabel = (string) ($dto['availability']['label'] ?? ucfirst(str_replace('_', ' ', $availability)));
        $add($rows, 'availability', 'availability', 'Disponibilité', $availability, $availabilityLabel);
        foreach ((array) ($dto['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute) || empty($attribute['filterable'])) continue;
            $groupKey = (string) ($attribute['group']['code'] ?? '');
            $groupLabel = (string) ($attribute['group']['name'] ?? '');
            foreach ((array) ($attribute['values'] ?? []) as $value) {
                if (is_array($value)) $add($rows, 'attribute', (string) ($attribute['code'] ?? ''), (string) ($attribute['name'] ?? $attribute['code'] ?? ''), $value['key'] ?? '', $value['label'] ?? '', $groupKey, $groupLabel, (int) ($attribute['sort_order'] ?? 0));
            }
        }
        return $rows;
    }

    private function normalizedText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function valueKey(string $value): string
    {
        return $this->normalizedText($value);
    }

    /** @param array<string,mixed>|null $asset @return array<string,mixed>|null */
    private function media(?array $asset,int $siteId): ?array
    {
        if (!$asset || (int) ($asset['media_id'] ?? 0) < 1) return null;
        $mediaId=(int)$asset['media_id'];
        $stored=$this->core->one("SELECT public_path,path,mime_type,media_type,width,height FROM media_assets WHERE id=? AND site_id=? AND lifecycle_status='ready' LIMIT 1",[$mediaId,$siteId]);
        $path=(string)($stored['public_path']??$stored['path']??'');
        $url=$path!==''?'/storage/media/'.ltrim($path,'/'):(string)($asset['url']??'/api/v1/media/'.$mediaId);
        return [
            'media_id'=>$mediaId,'variant_id'=>isset($asset['variant_id'])?(int)$asset['variant_id']:null,
            'role'=>(string)($asset['role']??'gallery'),'type'=>(string)($stored['media_type']??'image'),'mime_type'=>(string)($stored['mime_type']??''),
            'url'=>$url,'alt'=>(string)($asset['alt_text']??''),'title'=>(string)($asset['title']??''),'caption'=>(string)($asset['caption']??''),
            'width'=>isset($stored['width'])?(int)$stored['width']:null,'height'=>isset($stored['height'])?(int)$stored['height']:null,
        ];
    }

    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
}
