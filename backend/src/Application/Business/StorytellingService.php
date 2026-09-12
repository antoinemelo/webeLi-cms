<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Core\Database;
use App\Core\RuntimeCachePurger;
use InvalidArgumentException;
use PDOException;

final class StorytellingService
{
    public function __construct(private readonly Database $db) {}

    /** @return array{shop_active:bool,items:list<array<string,mixed>>} */
    public function list(int $siteId,string $languageCode,bool $publishedOnly=false): array
    {
        $languageCode=$this->language($siteId,$languageCode);
        if (!$this->db->tableExists('business_storytellings')) return ['shop_active'=>$this->shopActive($siteId,$languageCode),'items'=>[]];
        $where=$publishedOnly?" AND status='published'":'';
        return [
            'shop_active'=>$this->shopActive($siteId,$languageCode),
            'items'=>$this->db->all('SELECT * FROM business_storytellings WHERE site_id=? AND language_code=?'.$where.' ORDER BY title,storytelling_key,id',[$siteId,$languageCode]),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(int $siteId,string $languageCode,array $payload,int $actorId): array
    {
        $data=$this->normalize($siteId,$languageCode,$payload);
        try{$this->db->run(
            'INSERT INTO business_storytellings(site_id,language_code,storytelling_key,title,eyebrow,body_markdown,image_media_id,image_alt,cta_label,cta_url,status,created_by_iam_user_id,updated_by_iam_user_id)
             VALUES(:site_id,:language_code,:storytelling_key,:title,:eyebrow,:body_markdown,:image_media_id,:image_alt,:cta_label,:cta_url,:status,:actor,:actor)',
            $data+['actor'=>$actorId>0?$actorId:null]
        );}catch(PDOException $e){throw new InvalidArgumentException('business.storytelling_key_conflict',0,$e);}
        RuntimeCachePurger::purgeTwigCache();
        return $this->find($siteId,$this->db->lastInsertId())??[];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(int $siteId,int $id,array $payload,int $actorId): array
    {
        $current=$this->find($siteId,$id);
        if ($current===null) throw new InvalidArgumentException('business.storytelling_not_found');
        $data=$this->normalize($siteId,(string)$current['language_code'],$payload+$current);
        try{$this->db->run(
            'UPDATE business_storytellings SET language_code=:language_code,storytelling_key=:storytelling_key,title=:title,eyebrow=:eyebrow,body_markdown=:body_markdown,image_media_id=:image_media_id,image_alt=:image_alt,cta_label=:cta_label,cta_url=:cta_url,status=:status,updated_by_iam_user_id=:actor,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND site_id=:site_id',
            $data+['id'=>$id,'actor'=>$actorId>0?$actorId:null]
        );}catch(PDOException $e){throw new InvalidArgumentException('business.storytelling_key_conflict',0,$e);}
        RuntimeCachePurger::purgeTwigCache();
        return $this->find($siteId,$id)??[];
    }

    public function delete(int $siteId,int $id): bool
    {
        if ($this->find($siteId,$id)===null) return false;
        $this->db->run('DELETE FROM business_storytellings WHERE id=? AND site_id=?',[$id,$siteId]);
        RuntimeCachePurger::purgeTwigCache();
        return true;
    }

    /** @return array<string,mixed>|null */
    public function published(int $siteId,string $languageCode,int $id): ?array
    {
        $languageCode=$this->language($siteId,$languageCode);
        if (!$this->shopActive($siteId,$languageCode) || !$this->db->tableExists('business_storytellings')) return null;
        return $this->db->one("SELECT * FROM business_storytellings WHERE id=? AND site_id=? AND language_code=? AND status='published' LIMIT 1",[$id,$siteId,$languageCode]);
    }

    public function shopActive(int $siteId,string $languageCode): bool
    {
        if (!$this->db->tableExists('cms_shop_configurations')) return false;
        return $this->db->one("SELECT 1 FROM cms_shop_configurations WHERE site_id=? AND language_code=? AND status='active' AND published_json IS NOT NULL LIMIT 1",[$siteId,strtolower(trim($languageCode))])!==null;
    }

    /** @return array<string,mixed>|null */
    private function find(int $siteId,int $id): ?array
    { return $this->db->one('SELECT * FROM business_storytellings WHERE id=? AND site_id=? LIMIT 1',[$id,$siteId]); }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalize(int $siteId,string $languageCode,array $payload): array
    {
        $languageCode=$this->language($siteId,(string)($payload['language_code']??$languageCode));
        $title=trim((string)($payload['title']??''));
        if ($title==='') throw new InvalidArgumentException('business.storytelling_title_required');
        $key=strtolower(trim((string)($payload['storytelling_key']??'')));
        if ($key==='') $key=$this->slug($title);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$key)) throw new InvalidArgumentException('business.storytelling_key_invalid');
        $status=(string)($payload['status']??'draft');
        if (!in_array($status,['draft','published'],true)) $status='draft';
        $mediaId=(int)($payload['image_media_id']??0);
        if ($mediaId>0 && $this->db->one('SELECT 1 FROM media_assets WHERE id=? AND site_id=? LIMIT 1',[$mediaId,$siteId])===null) throw new InvalidArgumentException('business.storytelling_media_invalid');
        return [
            'site_id'=>$siteId,'language_code'=>$languageCode,'storytelling_key'=>$key,'title'=>$title,
            'eyebrow'=>trim((string)($payload['eyebrow']??'')),'body_markdown'=>trim((string)($payload['body_markdown']??'')),
            'image_media_id'=>$mediaId>0?$mediaId:null,'image_alt'=>trim((string)($payload['image_alt']??'')),
            'cta_label'=>trim((string)($payload['cta_label']??'')),'cta_url'=>$this->url((string)($payload['cta_url']??'')),'status'=>$status,
        ];
    }

    private function language(int $siteId,string $languageCode): string
    {
        $languageCode=strtolower(trim($languageCode));
        if ($languageCode==='' || $this->db->one('SELECT 1 FROM site_languages WHERE site_id=? AND language_code=? AND is_active=1 LIMIT 1',[$siteId,$languageCode])===null) {
            throw new InvalidArgumentException('business.storytelling_language_invalid');
        }
        return $languageCode;
    }

    private function slug(string $value): string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;
        return trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($ascii)),'-')?:'storytelling';
    }

    private function url(string $value): string
    {
        $value=trim($value);
        if($value===''||str_starts_with($value,'/')||preg_match('#^https?://#i',$value))return$value;
        throw new InvalidArgumentException('business.storytelling_url_invalid');
    }
}
