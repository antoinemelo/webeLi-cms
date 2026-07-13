<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;

final class StorefrontProjectionRepository
{
    public function __construct(private readonly Database $db) {}

    public function defaultChannelId(int $siteId): int
    { return (int)($this->db->one("SELECT channel_id FROM cms_sales_channel_storefronts WHERE site_id=? AND is_default=1 AND status='active' LIMIT 1",[$siteId])['channel_id']??0); }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,mixed>} */
    public function products(int $siteId, int $channelId, string $locale, array $filters = []): array
    {
        $where=['site_id=:site','channel_id=:channel','locale=:locale','is_indexable=1']; $params=['site'=>$siteId,'channel'=>$channelId,'locale'=>$locale];
        if ((int)($filters['collection_id']??0)>0) { $where[]='collection_id=:collection'; $params['collection']=(int)$filters['collection_id']; }
        if (trim((string)($filters['q']??''))!=='') { $where[]='dto_json LIKE :q'; $params['q']='%'.trim((string)$filters['q']).'%'; }
        $limit=max(1,min(100,(int)($filters['limit']??24))); $offset=max(0,(int)($filters['offset']??0));
        $sql=implode(' AND ',$where); $total=(int)($this->db->one('SELECT COUNT(*) c FROM storefront_product_projections WHERE '.$sql,$params)['c']??0);
        $sort=($filters['sort']??'name')==='newest'?'projected_at DESC':'slug ASC';
        $rows=$this->db->all('SELECT dto_json FROM storefront_product_projections WHERE '.$sql.' ORDER BY '.$sort.' LIMIT :limit OFFSET :offset',$params+['limit'=>$limit,'offset'=>$offset]);
        return ['items'=>array_map($this->decode(...),$rows),'pagination'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset,'has_more'=>$offset+$limit<$total]];
    }

    public function product(int $siteId,int $channelId,string $locale,string $slug): ?array
    { $row=$this->db->one('SELECT dto_json FROM storefront_product_projections WHERE site_id=? AND channel_id=? AND locale=? AND slug=? AND is_indexable=1 LIMIT 1',[$siteId,$channelId,$locale,$slug]); return $row?$this->decode($row):null; }

    /** @return list<array<string,mixed>> */
    public function collections(int $siteId,int $channelId,string $locale): array
    { return array_map($this->decode(...),$this->db->all('SELECT dto_json FROM storefront_collection_projections WHERE site_id=? AND channel_id=? AND locale=? ORDER BY slug',[$siteId,$channelId,$locale])); }

    public function collection(int $siteId,int $channelId,string $locale,string $slug): ?array
    { $row=$this->db->one('SELECT dto_json FROM storefront_collection_projections WHERE site_id=? AND channel_id=? AND locale=? AND slug=? LIMIT 1',[$siteId,$channelId,$locale,$slug]); return $row?$this->decode($row):null; }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode(array $row): array { return json_decode((string)$row['dto_json'],true)?:[]; }
}
