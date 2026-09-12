<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;
use InvalidArgumentException;

final class StorefrontProjectionRepository
{
    public function __construct(private readonly Database $db) {}

    public function defaultChannelId(int $siteId, ?string $locale = null): int
    {
        if ($locale !== null && $this->db->tableExists('cms_shop_configurations')) {
            return (int) ($this->db->one(
                "SELECT channel_id FROM cms_shop_configurations
                 WHERE site_id=? AND language_code=? AND status='active' AND published_json IS NOT NULL LIMIT 1",
                [$siteId,strtolower($locale)]
            )['channel_id'] ?? 0);
        }
        return (int)($this->db->one("SELECT channel_id FROM cms_sales_channel_storefronts WHERE site_id=? AND is_default=1 AND status='active' LIMIT 1",[$siteId])['channel_id']??0);
    }

    /** @return array<string,mixed>|null */
    public function activeShop(int $siteId, string $locale): ?array
    {
        if (!$this->db->tableExists('cms_shop_configurations')) return null;
        $row = $this->db->one(
            "SELECT * FROM cms_shop_configurations
             WHERE site_id=? AND language_code=? AND status='active' AND published_json IS NOT NULL LIMIT 1",
            [$siteId,strtolower($locale)]
        );
        if (!$row) return null;
        $published = json_decode((string)$row['published_json'],true);
        return [
            'id'=>(int)$row['id'],'site_id'=>(int)$row['site_id'],'language_code'=>(string)$row['language_code'],
            'channel_id'=>(int)$row['channel_id'],'channel_code'=>(string)($row['channel_code']??''),'currency'=>(string)$row['currency'],'route_path'=>(string)$row['route_path'],
            'theme_key'=>(string)$row['theme_key'],'menu_key'=>(string)$row['menu_key'],'menu_label'=>(string)$row['menu_label'],
            'menu_position'=>(int)$row['menu_position'],'cart_visible'=>(bool)$row['cart_visible'],
            'show_quantities'=>(bool)$row['show_quantities'],'last_available_threshold'=>(int)$row['last_available_threshold'],
            'published'=>is_array($published)?$published:[],
        ];
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,mixed>,selection:array<string,mixed>,facets:list<array<string,mixed>>,sorts:list<array<string,string>>} */
    public function products(int $siteId, int $channelId, string $locale, array $filters = []): array
    {
        if (!$this->db->tableExists('storefront_product_query_index') || !$this->db->tableExists('storefront_product_facet_values')) {
            return ['items'=>[],'pagination'=>['total'=>0,'limit'=>24,'offset'=>0,'page'=>1,'pages'=>0,'has_more'=>false],'selection'=>[],'facets'=>[],'sorts'=>$this->sortOptions($locale)];
        }
        $selection = $this->selection($filters);
        $limit=max(1,min(100,(int)($filters['limit']??24))); $offset=max(0,(int)($filters['offset']??0));
        [$where,$params]=$this->conditions($siteId,$channelId,$locale,$selection,(int)($filters['collection_id']??0),'result');
        $from=' FROM storefront_product_projections p INNER JOIN storefront_product_query_index q
                ON q.site_id=p.site_id AND q.channel_id=p.channel_id AND q.locale=p.locale AND q.product_id=p.product_id ';
        $total=(int)($this->db->one('SELECT COUNT(*) c'.$from.' WHERE '.implode(' AND ',$where),$params)['c']??0);
        [$order,$orderParams]=$this->orderBy((string)$selection['sort'],(string)$selection['q']);
        $rows=$this->db->all(
            'SELECT p.dto_json'.$from.' WHERE '.implode(' AND ',$where).' ORDER BY '.$order.' LIMIT :result_limit OFFSET :result_offset',
            $params+$orderParams+['result_limit'=>$limit,'result_offset'=>$offset]
        );
        $selection['attributes'] = count($selection['group']) === 1 ? $selection['attributes'] : [];
        return [
            'items'=>array_map($this->decode(...),$rows),
            'pagination'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset,'page'=>(int)floor($offset/$limit)+1,'pages'=>$total===0?0:(int)ceil($total/$limit),'has_more'=>$offset+$limit<$total],
            'selection'=>$selection,
            'facets'=>$this->facets($siteId,$channelId,$locale,$selection,(int)($filters['collection_id']??0)),
            'sorts'=>$this->sortOptions($locale),
        ];
    }

    public function product(int $siteId,int $channelId,string $locale,string $slug): ?array
    { $row=$this->db->one('SELECT dto_json FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=? AND slug=? AND is_indexable=1 LIMIT 1',[$siteId,$channelId,$locale,$slug]); return $row?$this->decode($row):null; }

    /** @return list<array<string,mixed>> */
    public function collections(int $siteId,int $channelId,string $locale): array
    { return array_map($this->decode(...),$this->db->all('SELECT dto_json FROM storefront_collection_projections WHERE site_id=? AND channel_id=? AND locale=? ORDER BY slug',[$siteId,$channelId,$locale])); }

    public function collection(int $siteId,int $channelId,string $locale,string $slug): ?array
    { $row=$this->db->one('SELECT dto_json FROM storefront_collection_projections WHERE site_id=? AND channel_id=? AND locale=? AND slug=? LIMIT 1',[$siteId,$channelId,$locale,$slug]); return $row?$this->decode($row):null; }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode(array $row): array
    {
        $dto=json_decode((string)$row['dto_json'],true)?:[];
        $dto=$this->localizeMediaUrls($dto);
        if (($dto['contract']??null)==='storefront.product.v3') {
            $generatedAt=(string)($dto['projection']['generated_at']??'');
            $timestamp=$generatedAt!==''?strtotime($generatedAt):false;
            $dto['projection']['state']=$timestamp===false||$timestamp<time()-86400?'delayed':'current';
        }
        return $dto;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function localizeMediaUrls(array $item): array
    {
        foreach (['image_url','media_url','thumbnail_url'] as $key) {
            if (is_string($item[$key]??null)) $item[$key]=public_asset_url_path($item[$key]);
        }
        foreach (['media','images','documents'] as $collection) {
            foreach ((array)($item[$collection]??[]) as $index=>$media) {
                if (!is_array($media)) continue;
                foreach (['url','src','image_url','thumbnail_url'] as $key) {
                    if (is_string($media[$key]??null)) $item[$collection][$index][$key]=public_asset_url_path($media[$key]);
                }
            }
        }
        foreach ((array)($item['sellables']??[]) as $index=>$sellable) {
            if (is_array($sellable)) $item['sellables'][$index]=$this->localizeMediaUrls($sellable);
        }
        return $item;
    }

    /** @return array<string,mixed> */
    private function selection(array $filters): array
    {
        $q = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 200);
        $sort = trim((string) ($filters['sort'] ?? ($q !== '' ? 'relevance' : 'name')));
        $aliases = ['price-asc'=>'price_asc','price-desc'=>'price_desc','promotion'=>'promo_percent','promo'=>'promo_percent'];
        $sort = $aliases[$sort] ?? $sort;
        $allowed = array_column($this->sortOptions(), 'key');
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('storefront.sort_invalid');
        $attributes = $filters['attributes'] ?? $filters['attr'] ?? [];
        $attributeSelection = [];
        if (is_array($attributes)) {
            foreach ($attributes as $key => $values) {
                $key = $this->filterKey((string) $key);
                if ($key !== '') $attributeSelection[$key] = $this->filterValues($values);
            }
            $attributeSelection = array_filter($attributeSelection);
        }
        return [
            'q'=>$q,'sort'=>$sort,
            'brand'=>$this->filterValues($filters['brand'] ?? $filters['brands'] ?? []),
            'category'=>$this->filterValues($filters['category'] ?? $filters['categories'] ?? []),
            'group'=>$this->filterValues($filters['group'] ?? $filters['groups'] ?? []),
            'availability'=>$this->filterValues($filters['availability'] ?? []),
            'attributes'=>$attributeSelection,
        ];
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function conditions(int $siteId,int $channelId,string $locale,array $selection,int $collectionId,string $prefix,?string $excludeType=null,?string $excludeKey=null): array
    {
        $where=['p.site_id=:'.$prefix.'_site','p.channel_id=:'.$prefix.'_channel','p.locale=:'.$prefix.'_locale','p.is_indexable=1'];
        $params=[$prefix.'_site'=>$siteId,$prefix.'_channel'=>$channelId,$prefix.'_locale'=>$locale];
        if ($collectionId>0) { $where[]='p.collection_id=:'.$prefix.'_collection'; $params[$prefix.'_collection']=$collectionId; }
        $tokens=array_values(array_filter(preg_split('/\s+/u',$this->normalizedText((string)$selection['q']))?:[]));
        foreach ($tokens as $index=>$token) {
            $name=$prefix.'_search_'.$index; $where[]="q.search_text LIKE :{$name} ESCAPE '\\'"; $params[$name]='%'.$this->escapeLike($token).'%';
        }
        $simple=['brand'=>'brand','category'=>'category','group'=>'group','availability'=>'availability'];
        $counter=0;
        foreach ($simple as $selectionKey=>$facetType) {
            if ($excludeType===$facetType || ($selection[$selectionKey]??[])===[]) continue;
            $this->appendFacetCondition($where,$params,$selection[$selectionKey],$facetType,$selectionKey,$prefix.'_f'.(++$counter));
        }
        if (count((array)($selection['group']??[]))===1) {
            foreach ((array)($selection['attributes']??[]) as $key=>$values) {
                if ($values===[] || ($excludeType==='attribute' && $excludeKey===$key)) continue;
                $this->appendFacetCondition($where,$params,$values,'attribute',(string)$key,$prefix.'_f'.(++$counter));
            }
        }
        return [$where,$params];
    }

    /** @param list<string> $where @param array<string,mixed> $params @param list<string> $values */
    private function appendFacetCondition(array &$where,array &$params,array $values,string $type,string $key,string $prefix): void
    {
        $placeholders=[];
        foreach (array_values($values) as $index=>$value) { $name=$prefix.'_v'.$index; $params[$name]=$value; $placeholders[]=':'.$name; }
        $params[$prefix.'_type']=$type; $params[$prefix.'_key']=$key;
        $where[]='EXISTS (SELECT 1 FROM storefront_product_facet_values '.$prefix.' WHERE '.$prefix.'.site_id=p.site_id AND '.$prefix.'.channel_id=p.channel_id AND '.$prefix.'.locale=p.locale AND '.$prefix.'.product_id=p.product_id AND '.$prefix.'.facet_type=:'.$prefix.'_type AND '.$prefix.'.facet_key=:'.$prefix.'_key AND '.$prefix.'.value_key IN ('.implode(',',$placeholders).'))';
    }

    /** @return list<array<string,mixed>> */
    private function facets(int $siteId,int $channelId,string $locale,array $selection,int $collectionId): array
    {
        $english=str_starts_with(strtolower($locale),'en');
        $definitions=$english?[
            ['type'=>'brand','key'=>'brand','label'=>'Brands'],['type'=>'category','key'=>'category','label'=>'Categories'],
            ['type'=>'group','key'=>'group','label'=>'Product groups'],['type'=>'availability','key'=>'availability','label'=>'Availability'],
        ]:[
            ['type'=>'brand','key'=>'brand','label'=>'Marques'],['type'=>'category','key'=>'category','label'=>'Catégories'],
            ['type'=>'group','key'=>'group','label'=>'Groupes de produits'],['type'=>'availability','key'=>'availability','label'=>'Disponibilité'],
        ];
        $result=[];
        foreach ($definitions as $definition) {
            $result[]=$definition+['options'=>$this->facetOptions($siteId,$channelId,$locale,$selection,$collectionId,$definition['type'],$definition['key'])];
        }
        if (count((array)$selection['group'])===1) {
            $group=(string)$selection['group'][0];
            $keys=$this->db->all(
                'SELECT facet_key, MIN(sort_order) sort_order, MIN(group_label) group_label
                 FROM storefront_product_facet_values WHERE site_id=? AND channel_id=? AND locale=? AND facet_type=\'attribute\' AND group_key=?
                 GROUP BY facet_key ORDER BY sort_order, facet_key',
                [$siteId,$channelId,$locale,$group]
            );
            foreach ($keys as $row) {
                $key=(string)$row['facet_key'];
                $options=$this->facetOptions($siteId,$channelId,$locale,$selection,$collectionId,'attribute',$key,$group);
                $label=$options[0]['facet_label']??ucfirst(str_replace(['_','-'],' ',$key));
                $result[]=['type'=>'attribute','key'=>$key,'label'=>$label,'group_key'=>$group,'group_label'=>(string)($row['group_label']??''),'options'=>$options];
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function facetOptions(int $siteId,int $channelId,string $locale,array $selection,int $collectionId,string $type,string $key,string $group=''): array
    {
        [$where,$params]=$this->conditions($siteId,$channelId,$locale,$selection,$collectionId,'count_'.$this->filterKey($type.'_'.$key),$type,$key);
        $prefix='option_'.$this->filterKey($type.'_'.$key);
        $where[]='f.facet_type=:'.$prefix.'_type'; $where[]='f.facet_key=:'.$prefix.'_key';
        $params[$prefix.'_type']=$type; $params[$prefix.'_key']=$key;
        if ($group!=='') { $where[]='f.group_key=:'.$prefix.'_group'; $params[$prefix.'_group']=$group; }
        $rows=$this->db->all(
            'SELECT f.value_key,MIN(f.value_label) value_label,MIN(f.facet_label) facet_label,MIN(f.sort_order) sort_order,COUNT(DISTINCT p.product_id) result_count
             FROM storefront_product_projections p INNER JOIN storefront_product_query_index q
               ON q.site_id=p.site_id AND q.channel_id=p.channel_id AND q.locale=p.locale AND q.product_id=p.product_id
             INNER JOIN storefront_product_facet_values f
               ON f.site_id=p.site_id AND f.channel_id=p.channel_id AND f.locale=p.locale AND f.product_id=p.product_id
             WHERE '.implode(' AND ',$where).'
             GROUP BY f.value_key ORDER BY sort_order,value_label,value_key',
            $params
        );
        $selected=$type==='attribute'?(array)($selection['attributes'][$key]??[]):(array)($selection[$type]??[]);
        return array_map(static function(array $row)use($type,$locale,$selected):array{
            $label=(string)$row['value_label'];
            if ($type==='availability'&&str_starts_with(strtolower($locale),'en')) $label=['in_stock'=>'In stock','deliverable'=>'Deliverable','backorder'=>'Backorder','unavailable'=>'Unavailable','contact_us'=>'Contact us'][(string)$row['value_key']]??$label;
            return [
            'key'=>(string)$row['value_key'],'label'=>$label,'facet_label'=>(string)$row['facet_label'],
            'count'=>(int)$row['result_count'],'selected'=>in_array((string)$row['value_key'],$selected,true),
        ];},$rows);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function orderBy(string $sort,string $query): array
    {
        return match ($sort) {
            'relevance' => $query==='' ? ['q.name_sort ASC,q.product_id ASC',[]] : ["CASE WHEN q.name_sort=:sort_exact THEN 0 WHEN q.name_sort LIKE :sort_prefix ESCAPE '\\' THEN 1 ELSE 2 END ASC,q.name_sort ASC,q.product_id ASC",['sort_exact'=>$this->normalizedText($query),'sort_prefix'=>$this->escapeLike($this->normalizedText($query)).'%']],
            'newest' => ['q.newest_at DESC,q.product_id DESC',[]],
            'price_asc' => ['q.final_price_minor IS NULL ASC,q.currency ASC,q.final_price_minor ASC,q.name_sort ASC,q.product_id ASC',[]],
            'price_desc' => ['q.final_price_minor IS NULL ASC,q.currency ASC,q.final_price_minor DESC,q.name_sort ASC,q.product_id ASC',[]],
            'promo_amount' => ['q.discount_amount_minor IS NULL ASC,q.currency ASC,q.discount_amount_minor DESC,q.name_sort ASC,q.product_id ASC',[]],
            'promo_percent' => ['q.discount_percent_bps IS NULL ASC,q.discount_percent_bps DESC,q.discount_amount_minor DESC,q.name_sort ASC,q.product_id ASC',[]],
            default => ['q.name_sort ASC,q.product_id ASC',[]],
        };
    }

    /** @return list<array{key:string,label:string}> */
    private function sortOptions(string $locale='fr'): array
    {
        if (str_starts_with(strtolower($locale),'en')) return [
            ['key'=>'relevance','label'=>'Relevance'],['key'=>'name','label'=>'Name'],['key'=>'newest','label'=>'Newest'],
            ['key'=>'price_asc','label'=>'Price: low to high'],['key'=>'price_desc','label'=>'Price: high to low'],
            ['key'=>'promo_amount','label'=>'Promotion amount'],['key'=>'promo_percent','label'=>'Promotion percentage'],
        ];
        return [
            ['key'=>'relevance','label'=>'Pertinence'],['key'=>'name','label'=>'Nom'],['key'=>'newest','label'=>'Nouveautés'],
            ['key'=>'price_asc','label'=>'Prix croissant'],['key'=>'price_desc','label'=>'Prix décroissant'],
            ['key'=>'promo_amount','label'=>'Montant de promotion'],['key'=>'promo_percent','label'=>'Pourcentage de promotion'],
        ];
    }

    /** @return list<string> */
    private function filterValues(mixed $value): array
    {
        $values=is_array($value)?$value:explode(',',(string)$value); $result=[];
        foreach ($values as $item) {
            if (is_array($item)) continue;
            $item=mb_substr(trim((string)$item),0,120); if ($item!=='' && !in_array($item,$result,true)) $result[]=$item;
            if (count($result)>=30) break;
        }
        return $result;
    }

    private function filterKey(string $value): string
    { return preg_replace('/[^a-z0-9_]/','_',strtolower(trim($value)))??''; }

    private function normalizedText(string $value): string
    { $value=mb_strtolower(trim($value),'UTF-8'); return preg_replace('/\s+/u',' ',$value)??$value; }

    private function escapeLike(string $value): string
    { return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$value); }
}
