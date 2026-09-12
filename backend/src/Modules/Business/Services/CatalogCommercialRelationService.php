<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Core\Database;
use InvalidArgumentException;

final class CatalogCommercialRelationService
{
    private const PUBLIC_RELATION_TYPES = ['related','accessory','alternative','upsell','cross_sell'];
    private const STORED_RELATION_TYPES = ['related','accessory','alternative','bundle_candidate','replacement','upsell','cross_sell','similar'];

    public function __construct(private readonly Database $db) {}

    /** @return list<array<string,mixed>> */
    public function relations(int $siteId, int $productId, ?string $type = null): array
    {
        $params = [$siteId, $productId];
        $where = '';
        if ($type !== null) {
            $where = ' AND r.relation_type=?';
            $params[] = $this->relationType($type);
        }
        $manual = $this->db->all(
            'SELECT r.*, p.name AS target_name, p.slug AS target_slug, p.status AS target_status
             FROM business_product_relations r
             INNER JOIN business_products p ON p.id=r.related_product_id AND p.site_id=r.site_id
             WHERE r.site_id=? AND r.product_id=?' . $where . '
             ORDER BY r.sort_order, r.id',
            $params
        );
        foreach ($manual as &$row) $row['source']='manual';
        unset($row);
        if (!$this->db->tableExists('business_product_relation_rules')) return $manual;
        $automatic=[];
        foreach ($this->rules($siteId,$productId) as $rule) {
            if ($type!==null && (string)$rule['relation_type']!==$type) continue;
            $join=(string)$rule['match_type']==='group'
                ? 'INNER JOIN business_product_attribute_group_links gl ON gl.product_id=p.id AND gl.group_id=:match_id'
                : '';
            $where=(string)$rule['match_type']==='category'?' AND p.category_id=:match_id':'';
            $rows=$this->db->all(
                "SELECT p.id AS related_product_id,p.name AS target_name,p.slug AS target_slug,p.status AS target_status
                 FROM business_products p {$join}
                 WHERE p.site_id=:site_id AND p.id<>:product_id AND p.archived_at IS NULL
                   AND p.status='active' AND p.is_public=1 AND p.is_ecommerce_enabled=1 {$where}
                 ORDER BY p.name,p.id LIMIT :result_limit",
                ['match_id'=>(int)$rule['match_id'],'site_id'=>$siteId,'product_id'=>$productId,'result_limit'=>(int)$rule['result_limit']]
            );
            foreach ($rows as $index=>$row) $automatic[]=$row+[
                'id'=>'rule-'.(int)$rule['id'].'-'.(int)$row['related_product_id'],'site_id'=>$siteId,'product_id'=>$productId,
                'relation_type'=>(string)$rule['relation_type'],'sort_order'=>(int)$rule['sort_order']+$index,
                'source'=>'automatic','rule_id'=>(int)$rule['id'],'match_type'=>(string)$rule['match_type'],'match_id'=>(int)$rule['match_id'],
            ];
        }
        $seen=[]; $result=[];
        foreach (array_merge($manual,$automatic) as $row) {
            $key=(string)$row['relation_type'].':'.(int)$row['related_product_id'];
            if (isset($seen[$key])) continue;
            $seen[$key]=true; $result[]=$row;
        }
        usort($result,static fn(array $a,array $b):int=>[(int)$a['sort_order'],(string)$a['target_name'],(int)$a['related_product_id']]<=>[(int)$b['sort_order'],(string)$b['target_name'],(int)$b['related_product_id']]);
        return $result;
    }

    /** @return array<string,mixed> */
    public function link(int $siteId, int $sourceProductId, int $targetProductId, string $type, int $sortOrder = 0): array
    {
        if ($sourceProductId === $targetProductId) {
            throw new InvalidArgumentException('business.catalog.product_relation_self');
        }
        $this->requireProduct($siteId, $sourceProductId);
        $this->requireProduct($siteId, $targetProductId);
        $type = $this->relationType($type);
        $this->db->run(
            'INSERT INTO business_product_relations(site_id,product_id,related_product_id,relation_type,sort_order)
             VALUES(?,?,?,?,?)
             ON CONFLICT(site_id,product_id,related_product_id,relation_type)
             DO UPDATE SET sort_order=excluded.sort_order',
            [$siteId, $sourceProductId, $targetProductId, $type, max(0, $sortOrder)]
        );
        $this->invalidate($siteId,$sourceProductId,'product_relation');
        return $this->db->one(
            'SELECT * FROM business_product_relations WHERE site_id=? AND product_id=? AND related_product_id=? AND relation_type=?',
            [$siteId, $sourceProductId, $targetProductId, $type]
        ) ?? [];
    }

    public function deleteRelation(int $siteId,int $relationId): bool
    {
        $row=$this->db->one('SELECT product_id FROM business_product_relations WHERE site_id=? AND id=?',[$siteId,$relationId]);
        if ($row===null) return false;
        $this->db->run('DELETE FROM business_product_relations WHERE site_id=? AND id=?',[$siteId,$relationId]);
        $this->invalidate($siteId,(int)$row['product_id'],'product_relation_deleted');
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function rules(int $siteId,int $productId): array
    {
        if (!$this->db->tableExists('business_product_relation_rules')) return [];
        return $this->db->all('SELECT * FROM business_product_relation_rules WHERE site_id=? AND product_id=? ORDER BY sort_order,id',[$siteId,$productId]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveRule(int $siteId,int $productId,array $payload): array
    {
        $this->requireProduct($siteId,$productId);
        $type=$this->publicRelationType((string)($payload['relation_type']??'related'));
        $matchType=strtolower(trim((string)($payload['match_type']??'')));
        $matchId=(int)($payload['match_id']??0);
        if (!in_array($matchType,['category','group'],true)||$matchId<1) throw new InvalidArgumentException('business.catalog.product_relation_rule_invalid');
        $targetTable=$matchType==='category'?'business_product_categories':'business_attribute_groups';
        if ($this->db->one("SELECT 1 FROM {$targetTable} WHERE site_id=? AND id=? AND archived_at IS NULL",[$siteId,$matchId])===null) throw new InvalidArgumentException('business.catalog.product_relation_rule_target_invalid');
        $limit=max(1,min(24,(int)($payload['result_limit']??6))); $sort=max(0,(int)($payload['sort_order']??0));
        $this->db->run(
            'INSERT INTO business_product_relation_rules(site_id,product_id,relation_type,match_type,match_id,result_limit,sort_order,is_active)
             VALUES(?,?,?,?,?,?,?,1) ON CONFLICT(site_id,product_id,relation_type,match_type,match_id)
             DO UPDATE SET result_limit=excluded.result_limit,sort_order=excluded.sort_order,is_active=1,updated_at=CURRENT_TIMESTAMP',
            [$siteId,$productId,$type,$matchType,$matchId,$limit,$sort]
        );
        $this->invalidate($siteId,$productId,'product_relation_rule');
        return $this->db->one('SELECT * FROM business_product_relation_rules WHERE site_id=? AND product_id=? AND relation_type=? AND match_type=? AND match_id=?',[$siteId,$productId,$type,$matchType,$matchId])??[];
    }

    public function deleteRule(int $siteId,int $ruleId): bool
    {
        $row=$this->db->one('SELECT product_id FROM business_product_relation_rules WHERE site_id=? AND id=?',[$siteId,$ruleId]);
        if ($row===null) return false;
        $this->db->run('DELETE FROM business_product_relation_rules WHERE site_id=? AND id=?',[$siteId,$ruleId]);
        $this->invalidate($siteId,(int)$row['product_id'],'product_relation_rule_deleted');
        return true;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function configureGiftCard(int $siteId, int $productId, array $payload): array
    {
        $product = $this->requireProduct($siteId, $productId);
        if (($product['type'] ?? '') !== 'gift_card') {
            throw new InvalidArgumentException('business.gift_card.product_type_required');
        }
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'CHF')));
        $mode = strtolower(trim((string) ($payload['value_mode'] ?? 'fixed')));
        if (!in_array($currency, ['CHF', 'EUR', 'USD'], true) || !in_array($mode, ['fixed', 'open'], true)) {
            throw new InvalidArgumentException('business.gift_card.policy_invalid');
        }
        $minimum = isset($payload['minimum_amount']) ? (float) $payload['minimum_amount'] : null;
        $maximum = isset($payload['maximum_amount']) ? (float) $payload['maximum_amount'] : null;
        if (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0) || ($minimum !== null && $maximum !== null && $maximum < $minimum)) {
            throw new InvalidArgumentException('business.gift_card.amount_range_invalid');
        }
        $expires = isset($payload['expires_after_days']) ? (int) $payload['expires_after_days'] : null;
        if ($expires !== null && $expires < 1) {
            throw new InvalidArgumentException('business.gift_card.expiry_invalid');
        }
        $this->db->run(
            'INSERT INTO business_gift_card_policies(site_id,product_id,currency,value_mode,minimum_amount,maximum_amount,expires_after_days,is_active)
             VALUES(?,?,?,?,?,?,?,1)
             ON CONFLICT(product_id) DO UPDATE SET site_id=excluded.site_id,currency=excluded.currency,value_mode=excluded.value_mode,
                minimum_amount=excluded.minimum_amount,maximum_amount=excluded.maximum_amount,expires_after_days=excluded.expires_after_days,
                is_active=1,updated_at=CURRENT_TIMESTAMP',
            [$siteId, $productId, $currency, $mode, $minimum, $maximum, $expires]
        );
        return $this->db->one('SELECT * FROM business_gift_card_policies WHERE product_id=?', [$productId]) ?? [];
    }

    /** @return array<string,mixed> */
    private function requireProduct(int $siteId, int $productId): array
    {
        $row = $this->db->one('SELECT * FROM business_products WHERE site_id=? AND id=? AND archived_at IS NULL', [$siteId, $productId]);
        if ($row === null) {
            throw new InvalidArgumentException('business.catalog.product_not_found');
        }
        return $row;
    }

    private function relationType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::STORED_RELATION_TYPES, true)) {
            throw new InvalidArgumentException('business.catalog.product_relation_type_invalid');
        }
        return $type;
    }

    private function publicRelationType(string $type): string
    {
        $type=$this->relationType($type);
        if (!in_array($type,self::PUBLIC_RELATION_TYPES,true)) throw new InvalidArgumentException('business.catalog.product_relation_type_invalid');
        return $type;
    }

    private function invalidate(int $siteId,int $productId,string $reason): void
    {
        if (!$this->db->tableExists('business_storefront_projection_invalidations')) return;
        $this->db->run('INSERT INTO business_storefront_projection_invalidations(site_id,product_id,reason) VALUES(?,?,\'product\')',[$siteId,$productId]);
    }
}
