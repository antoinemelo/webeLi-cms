<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Core\Database;

/** Public merchandising selections and privacy-preserving aggregate counters. */
final class StorefrontMerchandisingService
{
    public function __construct(private readonly Database $db, private readonly string $hashSecret = 'storefront-analytics') {}

    /** @param array<string,mixed> $server @param array<string,mixed> $query */
    public function recordProductView(int $siteId,string $locale,int $productId,array $server,array $query=[]): bool
    {
        if ($productId<1) return false;
        return $this->record($siteId,$locale,'product_view',(string)$productId,$server,$query);
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $query */
    public function recordSearch(int $siteId,string $locale,string $term,int $resultCount,array $server,array $query=[]): bool
    {
        if ($resultCount<1 || ($term=$this->safeKeyword($term))==='') return false;
        return $this->record($siteId,$locale,'search',$term,$server,$query);
    }

    /** @param list<array<string,mixed>> $sections @param array<string,mixed> $catalog @return list<array<string,mixed>> */
    public function sections(int $siteId,int $channelId,string $locale,array $sections,array $catalog): array
    {
        $result=[];
        foreach ($sections as $position=>$configuration) {
            if (!is_array($configuration) || empty($configuration['enabled'])) continue;
            $key=(string)($configuration['key']??'');
            $configuration=array_merge($this->sectionDefaults($key,$locale),$configuration);
            $limit=max(1,min(24,(int)($configuration['limit']??6)));
            $items=[]; $fallback=false; $explanation='';
            if ($key==='groups') {
                $items=$this->taxonomyItems($siteId,$channelId,$locale,'group',$limit);
                $explanation=$locale==='en'?'Groups with at least one applicable public product.':'Groupes ayant au moins un produit public applicable.';
            } elseif ($key==='collections') {
                $items=$this->collections($siteId,$channelId,$locale,$limit);
                $explanation=$locale==='en'?'Categories with at least one applicable public product.':'Catégories ayant au moins un produit public applicable.';
            } elseif ($key==='promotions') {
                $rule=in_array((string)($configuration['rule']??'percent'),['amount','percent'],true)?(string)($configuration['rule']??'percent'):'percent';
                $items=$this->productsByRule($siteId,$channelId,$locale,$rule==='amount'?'promotion_amount':'promotion_percent',$limit,(array)($configuration['manual_product_ids']??[]));
                $explanation=$locale==='en'?($rule==='amount'?'Active offers sorted by discount amount.':'Active offers sorted by discount percentage.'):($rule==='amount'?'Offres actives triées par montant de remise.':'Offres actives triées par pourcentage de remise.');
            } elseif ($key==='popular') {
                $window=max(1,min(365,(int)($configuration['window_days']??30)));
                [$items,$fallback]=$this->popularProducts($siteId,$channelId,$locale,$limit,$window,(array)($configuration['manual_product_ids']??[]));
                $explanation=$locale==='en'?($fallback?'Insufficient data: deterministic selection completed with recent products.':'Most viewed public product pages over '.$window.' days.'):($fallback?'Données insuffisantes : sélection déterministe complétée par les produits récents.':'Fiches publiques les plus consultées sur '.$window.' jours.');
            } elseif ($key==='keywords') {
                $window=max(1,min(365,(int)($configuration['window_days']??30)));
                $items=$this->popularKeywords($siteId,$locale,$limit,$window);
                $explanation=$locale==='en'?'Normalized successful searches over '.$window.' days, without customer identifier.':'Recherches normalisées avec résultat sur '.$window.' jours, sans identifiant client.';
            } elseif ($key==='new') {
                $items=$this->productsByRule($siteId,$channelId,$locale,'newest',$limit,(array)($configuration['manual_product_ids']??[]));
                $explanation=$locale==='en'?'First e-commerce publication, distinct from an update or rebuild.':'Première publication e-commerce, distincte d’une mise à jour ou reconstruction.';
            } elseif ($key==='catalog') {
                $items=(array)($catalog['items']??[]); $explanation=$locale==='en'?'Results matching the active search and filters.':'Résultats correspondant à la recherche et aux filtres actifs.';
            } elseif ($key==='search') {
                $explanation=$locale==='en'?'Search, contextual facets and public catalogue sorting.':'Recherche, facettes contextuelles et tris du catalogue public.';
            }
            $items=$this->publicItems($items,$locale);
            $empty=(string)($configuration['empty_state']??'hide');
            if (!in_array($empty,['hide','message'],true)) $empty='hide';
            $result[]=[
                'key'=>$key,'position'=>$position,'title'=>(string)($configuration['title']??''),'enabled'=>true,
                'limit'=>$limit,'display'=>(string)($configuration['display']??'grid'),'rule'=>(string)($configuration['rule']??'automatic'),
                'items'=>array_values($items),'count'=>count($items),'empty_state'=>$empty,
                'empty_message'=>(string)($configuration['empty_message']??($locale==='en'?'Nothing to show yet.':'Aucun élément à afficher pour le moment.')),
                'view_all_label'=>(string)($configuration['view_all_label']??''),'view_all_url'=>localized_path($this->viewAllUrl($key),$locale),
                'explanation'=>$explanation,'fallback_used'=>$fallback,
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function preview(int $siteId,int $channelId,string $locale,array $draft): array
    {
        $catalog=['items'=>[]];
        if ($channelId>0 && $this->db->tableExists('storefront_product_projections')) {
            $rows=$this->db->all('SELECT dto_json FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=? AND is_indexable=1 ORDER BY slug LIMIT 24',[$siteId,$channelId,$locale]);
            $catalog['items']=array_map($this->decode(...),$rows);
        }
        if (!$this->db->tableExists('storefront_product_projections') || !$this->db->tableExists('storefront_product_query_index')) {
            return ['sections'=>array_values(array_map(static fn(array $section):array=>[
                'key'=>(string)($section['key']??''),'title'=>(string)($section['title']??''),'count'=>0,'items'=>[],
                'explanation'=>'Projection Storefront indisponible.','fallback_used'=>false,
            ],array_filter((array)($draft['sections']??[]),static fn(mixed $section):bool=>is_array($section)&&!empty($section['enabled'])))),'total_products'=>0];
        }
        $sections=$this->sections($siteId,$channelId,$locale,(array)($draft['sections']??[]),$catalog);
        return ['sections'=>$sections,'total_products'=>count($catalog['items'])];
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $query */
    private function record(int $siteId,string $locale,string $type,string $key,array $server,array $query): bool
    {
        if (!$this->db->tableExists('storefront_analytics_daily') || !$this->validPublicRequest($server,$query)) return false;
        $locale=strtolower(trim($locale)); if ($siteId<1 || !preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/',$locale)) return false;
        $policy=$this->policy($siteId,$locale); $now=time(); $minutes=$policy['dedupe_minutes'];
        $bucket=$now-($now%($minutes*60)); $bucketAt=gmdate('Y-m-d H:i:s',$bucket);
        $fingerprint=(string)($server['REMOTE_ADDR']??'').'|'.mb_substr((string)($server['HTTP_USER_AGENT']??''),0,300).'|'.(string)($server['HTTP_ACCEPT_LANGUAGE']??'');
        $hash=hash_hmac('sha256',gmdate('Y-m-d',$now).'|'.$siteId.'|'.$locale.'|'.$type.'|'.$key.'|'.$fingerprint,$this->hashSecret);
        $accepted=false;
        $this->db->transaction(function()use($siteId,$locale,$type,$key,$hash,$bucketAt,$now,$policy,&$accepted):void{
            $this->db->run('DELETE FROM storefront_analytics_dedup WHERE expires_at < CURRENT_TIMESTAMP');
            $this->db->run(
                'INSERT OR IGNORE INTO storefront_analytics_dedup(site_id,locale,event_type,entity_key,dedupe_hash,bucket_started_at,expires_at) VALUES(?,?,?,?,?,?,?)',
                [$siteId,$locale,$type,$key,$hash,$bucketAt,gmdate('Y-m-d H:i:s',$now+max(60,$policy['dedupe_minutes']*120))]
            );
            $accepted=(int)($this->db->one('SELECT changes() c')['c']??0)===1;
            if ($accepted) $this->db->run(
                'INSERT INTO storefront_analytics_daily(site_id,locale,event_date,event_type,entity_key,event_count) VALUES(?,?,?,?,?,1)
                 ON CONFLICT(site_id,locale,event_date,event_type,entity_key) DO UPDATE SET event_count=event_count+1,updated_at=CURRENT_TIMESTAMP',
                [$siteId,$locale,gmdate('Y-m-d',$now),$type,$key]
            );
            $cutoff=gmdate('Y-m-d',$now-$policy['retention_days']*86400);
            $this->db->run('DELETE FROM storefront_analytics_daily WHERE site_id=? AND locale=? AND event_date<?',[$siteId,$locale,$cutoff]);
        });
        return $accepted;
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $query */
    private function validPublicRequest(array $server,array $query): bool
    {
        if (strtoupper((string)($server['REQUEST_METHOD']??'GET'))!=='GET') return false;
        foreach (['_theme','preview','visual','token'] as $key) if (!empty($query[$key])) return false;
        if (!empty($server['HTTP_X_AMCMS_PREVIEW']) || !empty($server['CMS_ADMIN_USER_ID'])) return false;
        $ua=strtolower((string)($server['HTTP_USER_AGENT']??''));
        return !preg_match('/bot|crawler|spider|slurp|bingpreview|headlesschrome|lighthouse|facebookexternalhit|curl|wget/',$ua);
    }

    private function safeKeyword(string $term): string
    {
        $term=mb_strtolower(trim($term),'UTF-8'); $term=preg_replace('/\s+/u',' ',$term)??$term;
        if (mb_strlen($term)<2 || mb_strlen($term)>60 || str_contains($term,'@') || preg_match('#https?://|www\.#i',$term)) return '';
        if (preg_match('/(?:\D*\d){7,}/',$term) || preg_match('/^[a-z0-9_-]{32,}$/i',$term)) return '';
        return $term;
    }

    /** @return array{dedupe_minutes:int,retention_days:int} */
    private function policy(int $siteId,string $locale): array
    {
        $row=$this->db->one('SELECT published_json FROM cms_shop_configurations WHERE site_id=? AND language_code=? LIMIT 1',[$siteId,$locale]);
        $published=$row?json_decode((string)($row['published_json']??'{}'),true):[]; $analytics=is_array($published['analytics']??null)?$published['analytics']:[];
        return ['dedupe_minutes'=>max(1,min(1440,(int)($analytics['dedupe_minutes']??30))),'retention_days'=>max(7,min(365,(int)($analytics['retention_days']??90)))];
    }

    /** @return list<array<string,mixed>> */
    private function taxonomyItems(int $siteId,int $channelId,string $locale,string $type,int $limit): array
    {
        $rows=$this->db->all(
            'SELECT value_key,MIN(value_label) label,COUNT(DISTINCT product_id) product_count FROM storefront_product_facet_values
             WHERE site_id=? AND channel_id=? AND locale=? AND facet_type=? GROUP BY value_key ORDER BY product_count DESC,label,value_key LIMIT ?',
            [$siteId,$channelId,$locale,$type,$limit]
        );
        return array_map(static fn(array $row):array=>['key'=>(string)$row['value_key'],'name'=>(string)$row['label'],'product_count'=>(int)$row['product_count'],'url'=>'/shop?'.$type.'%5B%5D='.rawurlencode((string)$row['value_key'])],$rows);
    }

    /** @return list<array<string,mixed>> */
    private function collections(int $siteId,int $channelId,string $locale,int $limit): array
    {
        return array_map($this->decode(...),$this->db->all(
            'SELECT c.dto_json FROM storefront_collection_projections c WHERE c.site_id=? AND c.channel_id=? AND c.locale=?
             AND EXISTS(SELECT 1 FROM storefront_product_projections p WHERE p.site_id=c.site_id AND p.channel_id=c.channel_id AND p.locale=c.locale AND p.collection_id=c.collection_id AND p.is_indexable=1)
             ORDER BY c.slug LIMIT ?',[$siteId,$channelId,$locale,$limit]
        ));
    }

    /** @param list<mixed> $manual @return list<array<string,mixed>> */
    private function productsByRule(int $siteId,int $channelId,string $locale,string $rule,int $limit,array $manual=[]): array
    {
        $where="p.site_id=? AND p.channel_id=? AND p.locale=? AND p.is_indexable=1";
        $order=match($rule){'promotion_amount'=>'q.currency,q.discount_amount_minor DESC,q.discount_percent_bps DESC','promotion_percent'=>'q.discount_percent_bps DESC,q.discount_amount_minor DESC',default=>'q.newest_at DESC'};
        if (str_starts_with($rule,'promotion_')) $where.=" AND q.discount_amount_minor>0 AND q.availability_status IN ('in_stock','deliverable','backorder')";
        $rows=$this->db->all('SELECT p.dto_json,q.product_id FROM storefront_product_projections p INNER JOIN storefront_product_query_index q ON q.site_id=p.site_id AND q.channel_id=p.channel_id AND q.locale=p.locale AND q.product_id=p.product_id WHERE '.$where.' ORDER BY '.$order.',q.name_sort,q.product_id LIMIT 100',[$siteId,$channelId,$locale]);
        return $this->manualFirst($rows,$manual,$limit);
    }

    /** @param list<mixed> $manual @return array{0:list<array<string,mixed>>,1:bool} */
    private function popularProducts(int $siteId,int $channelId,string $locale,int $limit,int $window,array $manual): array
    {
        $since=gmdate('Y-m-d',time()-$window*86400);
        $rows=$this->db->all(
            "SELECT p.dto_json,q.product_id,COALESCE(SUM(a.event_count),0) popularity FROM storefront_product_projections p
             INNER JOIN storefront_product_query_index q ON q.site_id=p.site_id AND q.channel_id=p.channel_id AND q.locale=p.locale AND q.product_id=p.product_id
             LEFT JOIN storefront_analytics_daily a ON a.site_id=p.site_id AND a.locale=p.locale AND a.event_type='product_view' AND a.entity_key=CAST(p.product_id AS TEXT) AND a.event_date>=?
             WHERE p.site_id=? AND p.channel_id=? AND p.locale=? AND p.is_indexable=1 GROUP BY p.product_id ORDER BY popularity DESC,q.newest_at DESC,q.product_id LIMIT 100",
            [$since,$siteId,$channelId,$locale]
        );
        $hasData=(int)($rows[0]['popularity']??0)>0;
        return [$this->manualFirst($rows,$manual,$limit),!$hasData];
    }

    /** @return list<array<string,mixed>> */
    private function popularKeywords(int $siteId,string $locale,int $limit,int $window): array
    {
        $since=gmdate('Y-m-d',time()-$window*86400);
        return array_map(static fn(array $row):array=>['keyword'=>(string)$row['entity_key'],'count'=>(int)$row['popularity'],'url'=>'/shop?q='.rawurlencode((string)$row['entity_key'])],$this->db->all(
            "SELECT entity_key,SUM(event_count) popularity FROM storefront_analytics_daily WHERE site_id=? AND locale=? AND event_type='search' AND event_date>=? GROUP BY entity_key ORDER BY popularity DESC,entity_key LIMIT ?",
            [$siteId,$locale,$since,$limit]
        ));
    }

    /** @param list<array<string,mixed>> $rows @param list<mixed> $manual @return list<array<string,mixed>> */
    private function manualFirst(array $rows,array $manual,int $limit): array
    {
        $byId=[]; foreach($rows as $row)$byId[(int)$row['product_id']]=$row;
        $ordered=[]; foreach($manual as $id){$id=(int)$id;if(isset($byId[$id])){$ordered[]=$byId[$id];unset($byId[$id]);}}
        foreach($rows as $row)if(isset($byId[(int)$row['product_id']])){$ordered[]=$row;unset($byId[(int)$row['product_id']]);}
        return array_map($this->decode(...),array_slice($ordered,0,$limit));
    }

    private function viewAllUrl(string $key): string
    { return match($key){'promotions'=>'/shop?sort=promo_percent','new'=>'/shop?sort=newest',default=>'/shop'}; }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function publicItems(array $items,string $locale): array
    {
        return array_values(array_map(fn(array $item):array=>$this->publicItem($item,$locale),$items));
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function publicItem(array $item,string $locale): array
    {
        if (is_string($item['url']??null) && str_starts_with($item['url'],'/shop')) {
            $item['url']=localized_path($item['url'],$locale);
        }
        $item=$this->localizeMediaUrls($item);
        foreach ((array)($item['relations']??[]) as $relationIndex=>$relation) {
            if (!is_array($relation)) continue;
            foreach ((array)($relation['items']??[]) as $relatedIndex=>$related) {
                if (is_array($related)) $item['relations'][$relationIndex]['items'][$relatedIndex]=$this->publicItem($related,$locale);
            }
        }
        return $item;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function localizeMediaUrls(array $item): array
    {
        foreach (['image_url','media_url','thumbnail_url'] as $key) if (is_string($item[$key]??null)) $item[$key]=public_asset_url_path($item[$key]);
        foreach (['media','images','documents'] as $collection) foreach ((array)($item[$collection]??[]) as $index=>$media) {
            if (!is_array($media)) continue;
            foreach (['url','src','image_url','thumbnail_url'] as $key) if (is_string($media[$key]??null)) $item[$collection][$index][$key]=public_asset_url_path($media[$key]);
        }
        foreach ((array)($item['sellables']??[]) as $index=>$sellable) if (is_array($sellable)) $item['sellables'][$index]=$this->localizeMediaUrls($sellable);
        return $item;
    }

    /** @return array<string,mixed> */
    private function sectionDefaults(string $key,string $locale): array
    {
        $en=$locale==='en'; $all=$en?'View all':'Tout voir'; $empty=$en?'Nothing to show yet.':'Aucun élément à afficher pour le moment.';
        $values=[
            'search'=>[24,'list','automatic','', 'hide'], 'groups'=>[8,'chips','automatic',$all,'hide'],
            'promotions'=>[6,'grid','percent',$all,'hide'], 'collections'=>[8,'grid','automatic',$all,'hide'],
            'popular'=>[6,'grid','views',$all,'hide'], 'keywords'=>[8,'chips','automatic','','hide'],
            'new'=>[6,'grid','first_published',$all,'hide'], 'catalog'=>[24,'grid','automatic','','message'],
        ];
        [$limit,$display,$rule,$viewAll,$emptyState]=$values[$key]??[6,'grid','automatic','','hide'];
        return ['limit'=>$limit,'display'=>$display,'rule'=>$rule,'view_all_label'=>$viewAll,'empty_state'=>$emptyState,'empty_message'=>$empty,'window_days'=>30,'manual_product_ids'=>[]];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode(array $row): array { return json_decode((string)$row['dto_json'],true)?:[]; }
}
