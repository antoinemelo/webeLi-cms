<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;
use App\Modules\Business\Contracts\ProductContentProjectionPort;
use App\Modules\Business\Contracts\ProductContentSourcePort;
use InvalidArgumentException;
use PDOException;

final class ProductContentLinkService implements ProductContentProjectionPort
{
    private const RELATIONS = ['product_page', 'storytelling', 'faq', 'guide', 'comparison', 'seo', 'related'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductContentSourcePort $products,
        private readonly CmsContentSourcePort $contents,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForProduct(int $siteId, int $productId): array
    {
        return $this->db->all(
            'SELECT l.*, ce.entry_key, ce.status AS content_status, ct.type_key AS content_type,
                    CASE WHEN pp.id IS NULL THEN 0 ELSE 1 END AS projection_ready
             FROM business_product_content_links l
             INNER JOIN content_entries ce ON ce.id = l.content_entry_id AND ce.site_id = l.site_id
             INNER JOIN content_types ct ON ct.id = ce.content_type_id
             LEFT JOIN business_product_public_projections pp ON pp.link_id = l.id
             WHERE l.site_id = ? AND l.product_id = ?
             ORDER BY l.is_canonical DESC, l.relation_type, l.locale, l.id',
            [$siteId, $productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function contentCandidates(int $siteId, string $query = ''): array
    {
        return $this->contents->searchContent($siteId, $query);
    }

    /** @return list<array<string,mixed>> */
    public function storefrontProductCandidates(int $siteId,string $locale,string $query='',int $limit=20): array
    {
        if(!$this->db->tableExists('storefront_product_projections'))return[];
        $channelId=0;
        if($this->db->tableExists('cms_shop_configurations'))$channelId=(int)($this->db->one("SELECT channel_id FROM cms_shop_configurations WHERE site_id=? AND language_code=? AND status='active' AND published_json IS NOT NULL LIMIT 1",[$siteId,$locale])['channel_id']??0);
        if($channelId<1)return[];
        $needle=mb_strtolower(trim($query));$result=[];$limit=max(1,min(50,$limit));
        foreach($this->db->all('SELECT dto_json FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=? AND is_indexable=1 ORDER BY slug',[$siteId,$channelId,$locale])as$row){
            $dto=json_decode((string)$row['dto_json'],true)?:[];$sku=(string)($dto['sku']??'');
            if($needle!==''&&!str_contains(mb_strtolower((string)($dto['name']??'').' '.$sku),$needle))continue;
            $result[]=['product_id'=>(int)($dto['product_id']??0),'name'=>(string)($dto['name']??''),'sku'=>$sku,'image'=>public_asset_url_path((string)($dto['media'][0]['url']??'')),'sellables'=>array_values(array_map(static fn(array $sellable):array=>['sellable_id'=>(int)($sellable['sellable_id']??0),'name'=>(string)($sellable['name']??''),'sku'=>(string)($sellable['sku']??''),'orderable'=>!empty($sellable['orderable'])],array_filter((array)($dto['sellables']??[]),'is_array'))),'status'=>'published','status_label'=>$locale==='en'?'Published':'Publié','availability'=>(string)($dto['availability']['label']??''),'availability_status'=>(string)($dto['availability']['display_status']??''),'channel_id'=>$channelId,'locale'=>$locale,'projected'=>true,'admin_url'=>url_path('/admin/app/business/products?product_id='.(int)($dto['product_id']??0))];
            if(count($result)>=$limit)break;
        }
        return$result;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId, int $productId, array $payload, int $actorId): array
    {
        $data = $this->normalize($siteId, $productId, $payload);
        try {
            $this->db->run(
                'INSERT INTO business_product_content_links(
                site_id, product_id, content_entry_id, relation_type, locale, is_canonical,
                status, seo_config_json, created_by_iam_user_id, updated_by_iam_user_id
             ) VALUES(:site_id, :product_id, :content_entry_id, :relation_type, :locale, :is_canonical,
                :status, :seo_config_json, :actor, :actor)',
                $data + ['actor' => $actorId > 0 ? $actorId : null]
            );
        } catch (PDOException $e) {
            throw new InvalidArgumentException('business.pim.content_link_conflict', 0, $e);
        }
        $id = $this->db->lastInsertId();
        $this->rebuildLink($id);
        return $this->find($siteId, $id) ?? [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(int $siteId, int $id, array $payload, int $actorId): array
    {
        $current = $this->find($siteId, $id);
        if ($current === null) {
            throw new InvalidArgumentException('business.pim.content_link_not_found');
        }
        $data = $this->normalize($siteId, (int) $current['product_id'], $payload + $current);
        try {
            $this->db->run(
                'UPDATE business_product_content_links SET content_entry_id=:content_entry_id, relation_type=:relation_type,
                locale=:locale, is_canonical=:is_canonical, status=:status, seo_config_json=:seo_config_json,
                updated_by_iam_user_id=:actor, updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND site_id=:site_id',
                $data + ['id' => $id, 'actor' => $actorId > 0 ? $actorId : null]
            );
        } catch (PDOException $e) {
            throw new InvalidArgumentException('business.pim.content_link_conflict', 0, $e);
        }
        $this->rebuildLink($id);
        return $this->find($siteId, $id) ?? [];
    }

    public function delete(int $siteId, int $id): bool
    {
        if ($this->find($siteId, $id) === null) {
            return false;
        }
        $this->db->run('DELETE FROM business_product_content_links WHERE id = ? AND site_id = ?', [$id, $siteId]);
        return true;
    }

    public function refreshProduct(int $siteId, int $productId): void
    {
        foreach ($this->db->all('SELECT id FROM business_product_content_links WHERE site_id=? AND product_id=? AND status=\'active\'', [$siteId, $productId]) as $row) {
            $this->rebuildLink((int) $row['id']);
        }
    }

    public function deactivateProduct(int $siteId, int $productId): void
    {
        $this->db->run('UPDATE business_product_public_projections SET is_active=0, projected_at=CURRENT_TIMESTAMP WHERE site_id=? AND product_id=?', [$siteId, $productId]);
    }

    public function assertProductCanBeDeleted(int $siteId, int $productId): void
    {
        if ($this->db->one('SELECT 1 FROM business_product_content_links WHERE site_id=? AND product_id=? LIMIT 1', [$siteId, $productId]) !== null) {
            throw new InvalidArgumentException('business.pim.product_content_links_must_be_removed_first');
        }
    }

    /** @return list<array<string,mixed>> */
    public function publicProductsForContent(int $siteId, int $contentEntryId, string $locale): array
    {
        $rows = $this->db->all(
            'SELECT pp.product_json, pp.structured_data_json, l.relation_type, l.is_canonical, l.locale
             FROM business_product_public_projections pp
             INNER JOIN business_product_content_links l ON l.id=pp.link_id AND l.status=\'active\'
             WHERE pp.site_id=? AND pp.content_entry_id=? AND pp.is_active=1
               AND (pp.locale IS NULL OR pp.locale=?)
             ORDER BY l.is_canonical DESC, l.id',
            [$siteId, $contentEntryId, $locale]
        );
        return array_map(static function (array $row): array {
            $product = json_decode((string) $row['product_json'], true) ?: [];
            $product['content_relation'] = ['type' => $row['relation_type'], 'canonical' => (bool) $row['is_canonical'], 'locale' => $row['locale']];
            $product['structured_data'] = json_decode((string) $row['structured_data_json'], true) ?: null;
            return $product;
        }, $rows);
    }

    /**
     * Hydrates Commerce blocks from the current Storefront projection only.
     * Revisions keep stable references and rules; product DTOs are never their source of truth.
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    public function hydrateStorefrontBlocks(array $blocks, int $siteId, string $locale, array $context = []): array
    {
        $activeChannelId=0;
        if ($this->db->tableExists('cms_shop_configurations')) $activeChannelId=(int)($this->db->one("SELECT channel_id FROM cms_shop_configurations WHERE site_id=? AND language_code=? AND status='active' AND published_json IS NOT NULL LIMIT 1",[$siteId,$locale])['channel_id']??0);
        $channelId=(int)($context['channel_id']??$activeChannelId);
        if ($activeChannelId<1||$channelId!==$activeChannelId) return $this->unavailableCommerceBlocks($blocks);

        $products=[]; $newest=[];
        $projectionSql=$this->db->tableExists('storefront_product_projections')&&$this->db->tableExists('storefront_product_query_index')
            ? 'SELECT p.dto_json,q.newest_at FROM storefront_product_projections p LEFT JOIN storefront_product_query_index q ON q.site_id=p.site_id AND q.channel_id=p.channel_id AND q.locale=p.locale AND q.product_id=p.product_id WHERE p.site_id=? AND p.channel_id=? AND p.locale=? AND p.is_indexable=1 ORDER BY p.slug'
            : ($this->db->tableExists('storefront_product_projections')?'SELECT dto_json,NULL newest_at FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=? AND is_indexable=1 ORDER BY slug':'');
        foreach ($projectionSql!==''?$this->db->all($projectionSql,[$siteId,$channelId,$locale]):[] as $row) {
            $dto=json_decode((string)$row['dto_json'],true)?:[]; $productId=(int)($dto['product_id']??0);
            if ($productId<1) continue;
            $dto=$this->localizeProductUrls($dto,$locale);
            $products[$productId]=$dto;$newest[$productId]=(string)($row['newest_at']??$dto['updated_at']??'');
        }
        $runtime=['site_id'=>$siteId,'channel_id'=>$channelId,'locale'=>$locale,'products'=>$products,'newest'=>$newest,'context'=>$context];
        foreach ($blocks as $index=>$block) if (is_array($block)) $blocks[$index]=$this->hydrateStorefrontBlock($block,$runtime);
        return $blocks;
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $runtime @return array<string,mixed> */
    private function hydrateStorefrontBlock(array $block,array $runtime): array
    {
        $type=(string)($block['type']??$block['block_type']??''); $data=is_array($block['data']??null)?$block['data']:[];
        $products=(array)$runtime['products'];
        if ($type==='commerce_product') {
            $productId=(int)($data['product_id']??0); $product=$products[$productId]??null; $sellableId=(int)($data['sellable_id']??0);
            if(is_array($product)&&$sellableId>0)$product=$this->productForSellable($product,$sellableId);
            $data['items']=$product===null?[]:[$product];
            $data['selection']=['mode'=>'explicit','count'=>count($data['items']),'total'=>count($data['items']),'missing_product_ids'=>$productId>0&&$product===null?[$productId]:[]];
        } elseif ($type==='commerce_product_variants') {
            $productId=(int)($data['product_id']??0);$product=$products[$productId]??null;$all=[];
            if(is_array($product))foreach((array)($product['sellables']??[])as$sellable)if(is_array($sellable))$all[]=$this->productForSellable($product,(int)($sellable['sellable_id']??0));
            $all=array_values(array_filter($all));$pageParam=trim((string)($data['page_param']??''));
            if($pageParam==='')$pageParam='commerce_variants_'.preg_replace('/[^a-z0-9_]/','_',strtolower((string)($block['id']??'page')));
            $page=!empty($data['pagination'])?max(1,(int)($runtime['context']['query'][$pageParam]??1)):1;$limit=max(1,min(100,(int)($data['limit']??12)));$total=count($all);$pages=$total===0?0:(int)ceil($total/$limit);
            if($pages>0)$page=min($page,$pages);
            $data['items']=array_slice($all,($page-1)*$limit,$limit);$data['page_param']=$pageParam;
            $data['selection']=['mode'=>'variants','count'=>count($data['items']),'total'=>$total,'limit'=>$limit,'page'=>$page,'pages'=>$pages,'page_param'=>$pageParam,'missing_product_ids'=>$productId>0&&$product===null?[$productId]:[]];
        } elseif ($type==='commerce_product_list') {
            $pageParam=trim((string)($data['page_param']??''));
            if($pageParam==='')$pageParam='commerce_'.preg_replace('/[^a-z0-9_]/','_',strtolower((string)($block['id']??'page')));
            if(!empty($data['pagination'])&&isset($runtime['context']['query'][$pageParam]))$data['page']=max(1,(int)$runtime['context']['query'][$pageParam]);
            $data['page_param']=$pageParam;
            [$items,$selection]=$this->selectStorefrontProducts($data,$runtime);
            $data['items']=$items; $data['selection']=$selection;
        } elseif ($type==='storytelling') {
            $storyId=(int)($data['storytelling_id']??0);$story=null;
            if($storyId>0&&$this->db->tableExists('business_storytellings'))$story=$this->db->one("SELECT s.*,m.public_path,m.path AS media_path FROM business_storytellings s LEFT JOIN media_assets m ON m.id=s.image_media_id AND m.site_id=s.site_id WHERE s.id=? AND s.site_id=? AND s.language_code=? AND s.status='published' LIMIT 1",[$storyId,(int)$runtime['site_id'],(string)$runtime['locale']]);
            if($story!==null){$path=(string)($story['public_path']??$story['media_path']??'');$story['image_url']=$path!==''?url_path('/storage/media/'.ltrim($path,'/')):'';$cta=(string)($story['cta_url']??'');if(str_starts_with($cta,'/'))$story['cta_url']=localized_path($cta,(string)$runtime['locale']);}
            $data['storytelling']=$story;
        }
        // Commerce blocks remain supported inside native Columns blocks.
        if (is_array($data['columns']??null)) foreach ($data['columns'] as $columnIndex=>$column) {
            if (!is_array($column)||!is_array($column['blocks']??null)) continue;
            $data['columns'][$columnIndex]['blocks']=$this->hydrateStorefrontBlocks((array)$column['blocks'],(int)$runtime['site_id'],(string)$runtime['locale'],(array)$runtime['context']+['channel_id'=>(int)$runtime['channel_id']]);
        }
        if(in_array($type,['commerce_product_variants','commerce_product_list'],true)&&!empty($data['pagination'])){
            $anchor=$this->commerceBlockAnchor($block);$data['block_anchor']=$anchor;
            $data['selection']=array_replace((array)($data['selection']??[]),$this->paginationLinks((array)($data['selection']??[]),(array)($runtime['context']['query']??[]),(string)($data['page_param']??''),$anchor));
        }
        if(in_array($type,['commerce_product','commerce_product_variants','commerce_product_list','storytelling'],true)){$data['language_code']=(string)$runtime['locale'];$data['commerce_available']=true;}
        $block['data']=$data; return $block;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function unavailableCommerceBlocks(array $blocks): array
    {
        foreach($blocks as$index=>$block){if(!is_array($block))continue;$type=(string)($block['type']??'');$data=is_array($block['data']??null)?$block['data']:[];
            if(in_array($type,['commerce_product','commerce_product_variants','commerce_product_list','storytelling'],true)){$data['commerce_available']=false;$data['items']=[];$data['storytelling']=null;$block['data']=$data;}
            if($type==='columns'&&is_array($data['columns']??null))foreach($data['columns']as$columnIndex=>$column)if(is_array($column)&&is_array($column['blocks']??null))$block['data']['columns'][$columnIndex]['blocks']=$this->unavailableCommerceBlocks($column['blocks']);
            $blocks[$index]=$block;
        }return$blocks;
    }

    /** @param array<string,mixed> $product @return array<string,mixed>|null */
    private function productForSellable(array $product,int $sellableId): ?array
    {
        foreach((array)($product['sellables']??[])as$sellable){if(!is_array($sellable)||(int)($sellable['sellable_id']??0)!==$sellableId)continue;
            $copy=$product;$copy['selected_sellable_id']=$sellableId;$copy['name']=(string)$product['name'].((string)($sellable['name']??'')!==''?' — '.(string)$sellable['name']:'');
            $copy['sku']=$sellable['sku']??$copy['sku']??null;$copy['price']=$sellable['price']??$copy['price']??null;$copy['availability']=$sellable['availability']??$copy['availability']??null;
            if(!empty($sellable['media']))$copy['media']=$sellable['media'];
            $label=str_starts_with((string)($product['locale']??'fr'),'en')?'Add to cart':'Ajouter au panier';
            $copy['cta']=!empty($sellable['orderable'])?['sellable_id'=>$sellableId,'label'=>$label]:null;
            return$copy;
        }return null;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function localizeProductUrls(array $product,string $locale): array
    {
        foreach(['url','canonical_url']as$key)if(isset($product[$key])&&is_string($product[$key]))$product[$key]=localized_path($product[$key],$locale);
        foreach(['category','collection','brand']as$key)if(is_array($product[$key]??null)&&isset($product[$key]['url'])&&is_string($product[$key]['url']))$product[$key]['url']=localized_path($product[$key]['url'],$locale);
        foreach(['image_url','media_url','thumbnail_url']as$key)if(is_string($product[$key]??null))$product[$key]=public_asset_url_path($product[$key]);
        foreach(['media','images','documents']as$collection)foreach((array)($product[$collection]??[])as$index=>$media){if(!is_array($media))continue;foreach(['url','src','image_url','thumbnail_url']as$key)if(is_string($media[$key]??null))$product[$collection][$index][$key]=public_asset_url_path($media[$key]);}
        foreach((array)($product['sellables']??[])as$index=>$sellable)if(is_array($sellable))$product['sellables'][$index]=$this->localizeProductUrls($sellable,$locale);
        return$product;
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $runtime @return array{0:list<array<string,mixed>>,1:array<string,mixed>} */
    private function selectStorefrontProducts(array $data,array $runtime): array
    {
        $products=(array)$runtime['products']; $mode=strtolower(trim((string)($data['selection_mode']??'')));
        if ($mode==='' && (int)($data['collection_id']??0)>0) $mode='category';
        if ($mode==='') $mode='explicit';
        $allowed=['explicit','brand','category','group','attribute','promotion','new','popular','relation'];
        if (!in_array($mode,$allowed,true)) $mode='explicit';
        $items=[]; $missing=[];
        if ($mode==='explicit') {
            $ids=$this->integerList($data['product_ids']??[]);
            foreach ($ids as $id) isset($products[$id])?$items[]=$products[$id]:$missing[]=$id;
        } elseif ($mode==='brand') {
            $criterion=(string)($data['brand']??$data['brand_id']??'');
            $items=$this->filterProducts($products,static fn(array $p):bool=>in_array($criterion,array_map('strval',[(int)($p['brand']['brand_id']??0),(string)($p['brand']['slug']??''),(string)($p['brand']['name']??'')]),true));
        } elseif ($mode==='category') {
            $criterion=(string)($data['category']??$data['category_id']??$data['collection_id']??'');
            $items=$this->filterProducts($products,static fn(array $p):bool=>in_array($criterion,array_map('strval',[(int)($p['collection']['collection_id']??0),(string)($p['collection']['slug']??''),(string)($p['collection']['name']??'')]),true));
        } elseif ($mode==='group') {
            $criterion=(string)($data['group']??$data['group_id']??'');
            $items=$this->filterProducts($products,static fn(array $p):bool=>count(array_filter((array)($p['groups']??[]),static fn(mixed $g):bool=>is_array($g)&&in_array($criterion,array_map('strval',[$g['group_id']??0,$g['code']??'',$g['name']??'']),true)))>0);
        } elseif ($mode==='attribute') {
            $code=(string)($data['attribute_code']??''); $values=array_map('strval',(array)($data['attribute_values']??[]));
            $items=$this->filterProducts($products,static function(array $p)use($code,$values):bool{foreach((array)($p['attributes']??[])as $attribute){if(!is_array($attribute)||!$attribute['filterable']||!in_array($code,array_map('strval',[$attribute['attribute_id']??0,$attribute['code']??'']),true))continue;$keys=[];foreach((array)($attribute['values']??[])as$value)if(is_array($value))$keys=array_merge($keys,array_map('strval',[$value['key']??'',$value['value']??'',$value['label']??'']));return $values===[]||array_intersect($values,$keys)!==[];}return false;});
        } elseif ($mode==='promotion') {
            $items=$this->filterProducts($products,static fn(array $p):bool=>(int)($p['price']['discount_amount_minor']??max(0,(int)($p['price']['regular_minor']??0)-(int)($p['price']['final_minor']??0)))>0&&!empty($p['availability']['is_orderable']));
        } elseif ($mode==='relation') {
            $sourceId=(int)($data['source_product_id']??$runtime['context']['current_product_id']??0); $relationType=(string)($data['relation_type']??'related');
            $source=$products[$sourceId]??null; $ids=[];
            if (is_array($source)) foreach((array)($source['relations']??[])as$relation)if(is_array($relation)&&($relation['type']??'')===$relationType)foreach((array)($relation['items']??[])as$item)if(is_array($item))$ids[]=(int)($item['product_id']??0);
            foreach(array_values(array_unique($ids))as$id)if(isset($products[$id]))$items[]=$products[$id];
        } else {
            $items=array_values($products);
        }
        $manual=$this->integerList($data['manual_product_ids']??[]);
        $sort=(string)($data['sort']??'name');
        if ($mode==='new') $sort='newest';
        if ($mode==='promotion') $sort=(string)($data['promotion_rule']??'percent')==='amount'?'promo_amount':'promo_percent';
        if ($mode==='popular') $items=$this->popularProductsFirst($items,(int)$runtime['site_id'],(string)$runtime['locale'],max(1,min(365,(int)($data['window_days']??30))),(array)($runtime['newest']??[]));
        elseif ($sort!=='manual' && !($mode==='explicit'&&$manual===[])) $this->sortProducts($items,$sort,(array)($runtime['newest']??[]));
        if($manual!==[])$items=$this->manualProductsFirst($items,$manual,$products);
        $total=count($items); $limit=max(1,min(100,(int)($data['limit']??12))); $page=max(1,(int)($data['page']??1));$pages=$total===0?0:(int)ceil($total/$limit);if($pages>0)$page=min($page,$pages);$offset=!empty($data['pagination'])?($page-1)*$limit:0;
        $items=array_slice($items,$offset,$limit);
        return [$items,['mode'=>$mode,'count'=>count($items),'total'=>$total,'limit'=>$limit,'page'=>$page,'pages'=>$pages,'page_param'=>(string)($data['page_param']??'commerce_page'),'missing_product_ids'=>$missing,'site_id'=>(int)$runtime['site_id'],'channel_id'=>(int)$runtime['channel_id'],'locale'=>(string)$runtime['locale']]];
    }

    /** @param array<string,mixed> $block */
    private function commerceBlockAnchor(array $block): string
    {
        $candidate=trim((string)($block['anchor']??''));
        if($candidate==='')$candidate='commerce-'.(string)($block['id']??'products');
        $candidate=trim((string)preg_replace('/[^a-zA-Z0-9_-]+/','-',$candidate),'-');
        return $candidate!==''?$candidate:'commerce-products';
    }

    /** @param array<string,mixed> $selection @param array<string,mixed> $query @return array<string,mixed> */
    private function paginationLinks(array $selection,array $query,string $pageParam,string $anchor): array
    {
        $page=max(1,(int)($selection['page']??1));$pages=max(0,(int)($selection['pages']??0));$links=[];
        for($number=1;$number<=$pages;$number++)$links[]=['page'=>$number,'current'=>$number===$page,'url'=>$this->paginationUrl($query,$pageParam,$number,$anchor)];
        return ['links'=>$links,'previous_url'=>$page>1?$this->paginationUrl($query,$pageParam,$page-1,$anchor):null,'next_url'=>$page<$pages?$this->paginationUrl($query,$pageParam,$page+1,$anchor):null];
    }

    /** @param array<string,mixed> $query */
    private function paginationUrl(array $query,string $pageParam,int $page,string $anchor): string
    {
        if($page<=1)unset($query[$pageParam]);else$query[$pageParam]=$page;
        $encoded=http_build_query($query,'','&',PHP_QUERY_RFC3986);
        return ($encoded!==''?'?'.$encoded:'').'#'.rawurlencode($anchor);
    }

    /** @return list<int> */
    private function integerList(mixed $values): array
    { if(is_string($values)){$decoded=json_decode($values,true);$values=is_array($decoded)?$decoded:explode(',',$values);}if(!is_array($values))return[];$result=[];foreach($values as$value){$id=(int)$value;if($id>0&&!in_array($id,$result,true))$result[]=$id;}return$result; }

    /** @param array<int,array<string,mixed>> $products @return list<array<string,mixed>> */
    private function filterProducts(array $products,callable $predicate): array
    { return array_values(array_filter($products,$predicate)); }

    /** @param list<array<string,mixed>> $items @param list<int> $manual @param array<int,array<string,mixed>> $products @return list<array<string,mixed>> */
    private function manualProductsFirst(array $items,array $manual,array $products): array
    { $byId=[];foreach($items as$item)$byId[(int)$item['product_id']]=$item;$result=[];foreach($manual as$id)if(isset($products[$id])){$result[]=$products[$id];unset($byId[$id]);}foreach($items as$item)if(isset($byId[(int)$item['product_id']])){$result[]=$item;unset($byId[(int)$item['product_id']]);}return$result; }

    /** @param list<array<string,mixed>> $items */
    private function sortProducts(array &$items,string $sort,array $newest=[]): void
    { usort($items,static function(array$a,array$b)use($sort,$newest):int{$av=match($sort){'newest'=>strtotime((string)($newest[(int)$a['product_id']]??$a['updated_at']??''))?:0,'price_asc','price_desc'=>(int)($a['price']['final_minor']??PHP_INT_MAX),'promo_amount'=>(int)($a['price']['discount_amount_minor']??0),'promo_percent'=>(int)($a['price']['discount_percent_bps']??0),default=>mb_strtolower((string)($a['name']??''))};$bv=match($sort){'newest'=>strtotime((string)($newest[(int)$b['product_id']]??$b['updated_at']??''))?:0,'price_asc','price_desc'=>(int)($b['price']['final_minor']??PHP_INT_MAX),'promo_amount'=>(int)($b['price']['discount_amount_minor']??0),'promo_percent'=>(int)($b['price']['discount_percent_bps']??0),default=>mb_strtolower((string)($b['name']??''))};$comparison=$av<=>$bv;if(in_array($sort,['newest','price_desc','promo_amount','promo_percent'],true))$comparison=-$comparison;return$comparison!==0?$comparison:(int)$a['product_id']<=>(int)$b['product_id'];}); }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function popularProductsFirst(array $items,int $siteId,string $locale,int $windowDays,array $newest=[]): array
    { if(!$this->db->tableExists('storefront_analytics_daily')){$this->sortProducts($items,'newest',$newest);return$items;}$scores=[];$since=gmdate('Y-m-d',time()-$windowDays*86400);foreach($this->db->all("SELECT entity_key,SUM(event_count) score FROM storefront_analytics_daily WHERE site_id=? AND locale=? AND event_type='product_view' AND event_date>=? GROUP BY entity_key",[$siteId,$locale,$since])as$row)$scores[(int)$row['entity_key']]=(int)$row['score'];usort($items,static function(array$a,array$b)use($scores,$newest):int{$score=($scores[(int)$b['product_id']]??0)<=>($scores[(int)$a['product_id']]??0);if($score!==0)return$score;$aTime=strtotime((string)($newest[(int)$a['product_id']]??$a['updated_at']??''))?:0;$bTime=strtotime((string)($newest[(int)$b['product_id']]??$b['updated_at']??''))?:0;return$bTime<=>$aTime?:((int)$b['product_id']<=>(int)$a['product_id']);});return$items; }

    /** @return array<string,mixed>|null */
    private function find(int $siteId, int $id): ?array
    {
        return $this->db->one('SELECT * FROM business_product_content_links WHERE id=? AND site_id=? LIMIT 1', [$id, $siteId]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalize(int $siteId, int $productId, array $payload): array
    {
        $contentEntryId = max(0, (int) ($payload['content_entry_id'] ?? 0));
        if ($this->products->productSnapshot($siteId, $productId, 'und') === null) {
            throw new InvalidArgumentException('business.pim.product_not_found');
        }
        if ($this->contents->contentEntry($siteId, $contentEntryId) === null) {
            throw new InvalidArgumentException('business.pim.content_not_found_or_cross_site');
        }
        $relation = strtolower(trim((string) ($payload['relation_type'] ?? 'product_page')));
        if (!in_array($relation, self::RELATIONS, true)) {
            throw new InvalidArgumentException('business.pim.content_relation_type_invalid');
        }
        $locale = strtolower(trim((string) ($payload['locale'] ?? '')));
        if ($locale !== '' && (!preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $locale) || !$this->contents->supportsLocale($siteId, $locale))) {
            throw new InvalidArgumentException('business.pim.content_locale_invalid');
        }
        $seo = $payload['seo_config'] ?? $payload['seo_config_json'] ?? [];
        if (is_string($seo)) {
            $seo = json_decode($seo, true) ?: [];
        }
        return [
            'site_id' => $siteId,
            'product_id' => $productId,
            'content_entry_id' => $contentEntryId,
            'relation_type' => $relation,
            'locale' => $locale === '' ? null : $locale,
            'is_canonical' => !empty($payload['is_canonical']) ? 1 : 0,
            'status' => ($payload['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'seo_config_json' => json_encode(is_array($seo) ? $seo : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private function rebuildLink(int $linkId): void
    {
        $link = $this->db->one('SELECT * FROM business_product_content_links WHERE id=?', [$linkId]);
        if ($link === null) {
            return;
        }
        $locale = (string) ($link['locale'] ?: 'und');
        $snapshot = $this->products->productSnapshot((int) $link['site_id'], (int) $link['product_id'], $locale);
        if ($snapshot === null) {
            $this->db->run('DELETE FROM business_product_public_projections WHERE link_id=?', [$linkId]);
            return;
        }
        $product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
        $active = $link['status'] === 'active' && ($product['status'] ?? '') === 'active' && !empty($product['is_public']);
        $seoConfig = json_decode((string) $link['seo_config_json'], true) ?: [];
        $schemaType = (string) ($seoConfig['schema_type'] ?? (($product['type'] ?? '') === 'service' ? 'Service' : 'Product'));
        if (!in_array($schemaType, ['Product', 'Service'], true)) {
            $schemaType = 'Product';
        }
        $structured = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            'name' => (string) ($product['name'] ?? ''),
            'sku' => (string) ($product['sku_base'] ?? ''),
            'description' => (string) ($product['short_description'] ?? ''),
        ];
        if (!empty($product['brand_name'])) {
            $structured['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand_name']];
        }
        $this->db->run(
            'INSERT INTO business_product_public_projections(link_id,site_id,product_id,content_entry_id,relation_type,locale,is_canonical,is_active,product_json,structured_data_json,source_product_updated_at,projected_at)
             VALUES(:link_id,:site_id,:product_id,:content_entry_id,:relation_type,:locale,:is_canonical,:is_active,:product_json,:structured_data_json,:source_updated,CURRENT_TIMESTAMP)
             ON CONFLICT(link_id) DO UPDATE SET site_id=excluded.site_id,product_id=excluded.product_id,content_entry_id=excluded.content_entry_id,
                relation_type=excluded.relation_type,locale=excluded.locale,is_canonical=excluded.is_canonical,is_active=excluded.is_active,
                product_json=excluded.product_json,structured_data_json=excluded.structured_data_json,source_product_updated_at=excluded.source_product_updated_at,projected_at=CURRENT_TIMESTAMP',
            [
                'link_id' => $linkId, 'site_id' => $link['site_id'], 'product_id' => $link['product_id'], 'content_entry_id' => $link['content_entry_id'],
                'relation_type' => $link['relation_type'], 'locale' => $link['locale'], 'is_canonical' => $link['is_canonical'], 'is_active' => $active ? 1 : 0,
                'product_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'structured_data_json' => json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'source_updated' => $product['updated_at'] ?? null,
            ]
        );
    }
}
