<?php

declare(strict_types=1);

namespace App\EditorialPackage;

use App\Application\Content\PublishContentEntry;
use App\Application\Media\GenerateMediaVariants;
use App\Application\Media\Storage\StorageDriverFactory;
use App\Core\Database;

final class EditorialImportExecutor
{
    public function __construct(
        private readonly Database $db,
        private readonly PublishContentEntry $publisher,
        private readonly ?GenerateMediaVariants $variants = null,
        private readonly ?StorageDriverFactory $storageFactory = null,
    ) {}

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function execute(string $root, array $plan, string $mode, int $siteId, string $siteKey, int $userId, string $importId): array
    {
        if(!in_array($mode,['create','upsert','replace'],true)){throw new \InvalidArgumentException('Mode d’import non supporté.');}
        if((array)($plan['blocking_errors']??[])!==[]){throw new \RuntimeException('Le plan contient des erreurs bloquantes.');}
        $started=microtime(true); $created=[];$updated=[];$ignored=[];$blueprintsCreated=[];$mediaAdded=[];$mediaReused=[];$projectionRows=[];$warnings=[];$storedObjects=[];$entryMap=[];$relationQueue=[];
        $blueprints=$this->readJsonDirectory($root.'/blueprints'); $entries=$this->readJsonDirectory($root.'/entries');
        $mediaManifest=EditorialPackageJson::decode((string)file_get_contents($root.'/media/manifest.json'));
        try {
            $this->db->transaction(function() use ($root,$mode,$siteId,$siteKey,$userId,$importId,$blueprints,$entries,$mediaManifest,&$created,&$updated,&$ignored,&$blueprintsCreated,&$mediaAdded,&$mediaReused,&$projectionRows,&$warnings,&$storedObjects,&$entryMap,&$relationQueue): void {
                foreach($blueprints as $bp){$key=(string)($bp['blueprint_key']??'');if($key===''||$this->blueprintExists($key,$siteId)){continue;}$this->createBlueprint($bp,$siteId,$userId);$blueprintsCreated[]=$key;}
                $mediaMap=$this->importMedia($root,$mediaManifest,$siteId,$userId,$mediaAdded,$mediaReused,$storedObjects);
                foreach($entries as $entry){
                    $entry=$this->normalizeEntryKeys($entry);
                    $match=$this->matchEntry($entry,$siteId); $entryKey=(string)($entry['entry_key']??'');
                    if($match!==null&&$mode==='create'){$ignored[]=$entryKey;continue;}
                    if($match===null&&$mode==='replace'){$ignored[]=$entryKey;continue;}
                    $entryId=$match!==null?(int)$match['id']:$this->createEntry($entry,$siteId,$userId);
                    if($match===null){$created[]=$entryKey;}else{$updated[]=$entryKey;}
                    $entryMap[$entryKey]=$entryId;
                    $relationQueue[]=['entry_id'=>$entryId,'entry_key'=>$entryKey,'relations'=>(array)($entry['relations']??[])];
                    foreach((array)($entry['localizations']??[]) as $loc){if(!is_array($loc)){continue;}$lang=(string)($loc['language']??'');if($lang===''){continue;}
                        $this->assertRouteAvailable($siteId,$lang,(string)($loc['route']??''),$entryId);
                        $document=$this->document($entry,$loc,$mediaMap,$warnings);
                        $revisionId=$this->writeRevision($entryId,$lang,$document,$userId,$importId);
                        $this->upsertLocalization($entryId,$lang,$loc,$revisionId,$userId);
                        $this->publisher->execute($entryId,$lang,$userId,$revisionId,true);
                        $projectionRows[]=['entry_id'=>$entryId,'language'=>$lang,'revision_id'=>$revisionId];
                    }
                }
                foreach($relationQueue as $queued){
                    $sourceId=(int)$queued['entry_id'];
                    $this->db->run('DELETE FROM content_relations WHERE source_entry_id=:id',['id'=>$sourceId]);
                    foreach((array)$queued['relations'] as $index=>$relation){
                        if(!is_array($relation)){continue;}
                        $targetKey=$this->canonicalEntryKey((string)($relation['target_entry_key']??''),'');
                        $targetId=$entryMap[$targetKey]??null;
                        if($targetId===null){$target=$this->db->one('SELECT id FROM content_entries WHERE site_id=:site AND entry_key=:key AND is_active=1 LIMIT 1',['site'=>$siteId,'key'=>$targetKey]);$targetId=$target['id']??null;}
                        if($targetId===null){throw new \RuntimeException('Relation non résolue : '.(string)$queued['entry_key'].' -> '.$targetKey);}
                        $this->db->run('INSERT INTO content_relations(source_entry_id,target_entry_id,relation_type,sort_order,metadata_json) VALUES(:source,:target,:type,:sort,:metadata) ON CONFLICT(source_entry_id,target_entry_id,relation_type) DO UPDATE SET sort_order=excluded.sort_order,metadata_json=excluded.metadata_json',['source'=>$sourceId,'target'=>(int)$targetId,'type'=>(string)($relation['relation_type']??'related'),'sort'=>(int)($relation['sort_order']??$index),'metadata'=>$this->json((array)($relation['metadata']??[]))]);
                    }
                }
            });
            foreach($mediaAdded as $row){if($this->variants!==null&&!empty($row['media_id'])){try{$this->variants->execute($siteId,(int)$row['media_id']);}catch(\Throwable $e){$warnings[]='Variantes média: '.$e->getMessage();}}}
        } catch(\Throwable $e){$this->cleanupStoredObjects($storedObjects,$siteId);throw $e;}
        return ['import_id'=>$importId,'status'=>'succeeded','mode'=>$mode,'site_id'=>$siteId,'site_key'=>$siteKey,'duration_seconds'=>round(microtime(true)-$started,3),'contents_created'=>$created,'contents_updated'=>$updated,'contents_ignored'=>$ignored,'blueprints_created'=>$blueprintsCreated,'media_added'=>$mediaAdded,'media_reused'=>$mediaReused,'warnings'=>$warnings,'errors'=>[],'projections_rebuilt'=>$projectionRows,'finished_at'=>gmdate('c')];
    }

    private function blueprintExists(string $key,int $siteId): bool{return $this->db->one('SELECT id FROM blueprints WHERE blueprint_key=:key AND (site_id=:site OR site_id IS NULL) AND is_active=1 LIMIT 1',['key'=>$key,'site'=>$siteId])!==null;}
    /** @param array<string,mixed> $bp */
    private function createBlueprint(array $bp,int $siteId,int $userId): void
    {
        $definition=(array)($bp['definition']??[]);$this->db->run('INSERT INTO blueprints(blueprint_key,resource_type,site_id,label,description,is_active,created_at,updated_at) VALUES(:key,:resource_type,:site,:label,:description,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',['key'=>$bp['blueprint_key'],'resource_type'=>$bp['resource_type']??'content_type','site'=>$siteId,'label'=>$bp['label']??$bp['blueprint_key'],'description'=>$bp['description']??null]);$id=$this->db->lastInsertId();
        $schema=$definition['schema']??$definition['schema_json']??$definition;
        $this->db->run('INSERT INTO blueprint_versions(blueprint_id,version,version_label,status,schema_json,ui_schema_json,validation_json,seo_policy_json,routing_policy_json,workflow_policy_json,translation_policy_json,permissions_policy_json,is_active,created_by_iam_user_id,created_at,activated_at) VALUES(:id,:version,:label,\'active\',:schema,:ui,:validation,:seo,:routing,:workflow,:translation,:permissions,1,:user,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',['id'=>$id,'version'=>(int)($bp['version']??1),'label'=>'Imported ' . (string) ($bp['version'] ?? '1'),'schema'=>$this->json($schema),'ui'=>$this->json($definition['ui_schema']??[]),'validation'=>$this->json($definition['validation']??[]),'seo'=>$this->json($definition['seo_policy']??[]),'routing'=>$this->json($definition['routing_policy']??[]),'workflow'=>$this->json($definition['workflow_policy']??[]),'translation'=>$this->json($definition['translation_policy']??[]),'permissions'=>$this->json($definition['permissions_policy']??[]),'user'=>$userId]);$vid=$this->db->lastInsertId();$this->db->run('UPDATE blueprints SET active_version_id=:vid WHERE id=:id',['vid'=>$vid,'id'=>$id]);
    }

    /** @param array<string,mixed> $manifest @param list<array<string,mixed>> $added @param list<array<string,mixed>> $reused @param list<array{disk:string,path:string}> $storedObjects @return array<string,int> */
    private function importMedia(string $root,array $manifest,int $siteId,int $userId,array &$added,array &$reused,array &$storedObjects): array
    {
        $map=[];
        $folder=$this->ensureImportFolder($siteId);
        $factory=$this->storageFactory ?? new StorageDriverFactory($this->db);
        $storage=$factory->forSite($siteId);
        if(!$storage->isConfigured()){throw new \RuntimeException('Stockage média cible non configuré.');}
        $storage->preflight();
        foreach((array)($manifest['items']??[]) as $item){
            if(!is_array($item)){continue;}
            $key=(string)($item['media_key']??'');$hash=(string)($item['sha256']??'');
            $existing=$this->db->one('SELECT id FROM media_assets WHERE site_id=:site AND sha256=:hash AND deleted_at IS NULL LIMIT 1',['site'=>$siteId,'hash'=>$hash]);
            if($existing){$map[$key]=(int)$existing['id'];$reused[]=['media_key'=>$key,'media_id'=>(int)$existing['id'],'sha256'=>$hash];continue;}
            $src=$root.'/'.ltrim((string)($item['path']??''),'/');
            if(!is_file($src)){throw new \RuntimeException('Média requis absent : '.$key);}
            if(hash_file('sha256',$src)!==$hash){throw new \RuntimeException('Hash média invalide : '.$key);}
            $filename=basename((string)($item['filename']??basename($src)));$ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
            $rel='public/'.$siteId.'/imports/'.gmdate('Y/m').'/'.$hash.'-'.$this->safeName($filename);
            $mime=(string)($item['mime_type']??'application/octet-stream');
            $storage->putFile($rel,$src,$mime);
            $storedObjects[]=['disk'=>$storage->disk(),'path'=>$rel];
            $meta=(array)($item['metadata']??[]);$uuid=$this->uuid();
            $this->db->run('INSERT INTO media_assets(uuid,site_id,storage_disk,path,public_path,filename,original_filename,extension,mime_type,media_type,size_bytes,width,height,sha256,lifecycle_status,validation_status,variants_status,metadata_status,copyright_text,license_type,source_url,folder_id,metadata_json,uploaded_by_user_id,validated_at,created_at,updated_at) VALUES(:uuid,:site,:disk,:path,:path,:filename,:original,:extension,:mime,:media_type,:size,:width,:height,:hash,\'ready\',\'valid\',\'pending\',\'complete\',:copyright,:license,:source,:folder,:metadata,:user,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',['uuid'=>$uuid,'site'=>$siteId,'disk'=>$storage->disk(),'path'=>$rel,'filename'=>$filename,'original'=>$filename,'extension'=>$ext,'mime'=>$mime,'media_type'=>$meta['media_type']??'image','size'=>(int)$item['size'],'width'=>$meta['width']??null,'height'=>$meta['height']??null,'hash'=>$hash,'copyright'=>$meta['copyright']??null,'license'=>$meta['license']??null,'source'=>$meta['source_url']??null,'folder'=>$folder,'metadata'=>$this->json($meta),'user'=>$userId]);
            $id=$this->db->lastInsertId();$map[$key]=$id;$added[]=['media_key'=>$key,'media_id'=>$id,'sha256'=>$hash,'storage_disk'=>$storage->disk()];
            foreach((array)($item['alt_texts']??[]) as $lang=>$alt){if(is_string($alt)&&trim($alt)!==''){$this->db->run('INSERT OR REPLACE INTO media_asset_localizations(media_id,language_code,alt_text,is_alt_verified,updated_at) VALUES(:id,:lang,:alt,1,CURRENT_TIMESTAMP)',['id'=>$id,'lang'=>(string)$lang,'alt'=>$alt]);}}
        }
        return $map;
    }

    /** @param list<array{disk:string,path:string}> $storedObjects */
    private function cleanupStoredObjects(array $storedObjects,int $siteId): void
    {
        $factory=$this->storageFactory ?? new StorageDriverFactory($this->db);
        foreach(array_reverse($storedObjects) as $object){
            try{$factory->forDisk((string)$object['disk'],$siteId)->delete((string)$object['path']);}catch(\Throwable){}
        }
    }
    private function ensureImportFolder(int $siteId): int{$row=$this->db->one('SELECT id FROM media_folders WHERE site_id=:site AND folder_key=:key LIMIT 1',['site'=>$siteId,'key'=>'imports/editorial']);if($row){return (int)$row['id'];}$this->db->run('INSERT INTO media_folders(site_id,folder_key,name,sort_order,created_at,updated_at) VALUES(:site,:key,:name,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',['site'=>$siteId,'key'=>'imports/editorial','name'=>'Imports éditoriaux']);return $this->db->lastInsertId();}
    /** @param array<string,mixed> $entry @return array<string,mixed>|null */
    private function matchEntry(array $entry,int $siteId): ?array
    {
        $typeKey=$this->portableSuffix((string)($entry['content_type_key']??''));
        $portable=(string)($entry['entry_key']??'');
        $exact=$this->db->one(
            'SELECT ce.id,ce.entry_key FROM content_entries ce JOIN content_types ct ON ct.id=ce.content_type_id '
            .'WHERE ce.site_id=:site AND ce.entry_key=:key AND ct.type_key=:type AND ce.is_active=1 LIMIT 1',
            ['site'=>$siteId,'key'=>$portable,'type'=>$typeKey]
        );
        if($exact!==null){$exact['_matched_by']='portable_key';return $exact;}
        foreach((array)($entry['localizations']??[]) as $loc){
            if(!is_array($loc)){continue;}
            $lang=(string)($loc['language']??'');
            $route=(string)($loc['route']??'');
            if($route!==''){
                $row=$this->db->one(
                    'SELECT ce.id,ce.entry_key FROM routes r JOIN content_entries ce ON ce.id=r.resource_id '
                    .'JOIN content_types ct ON ct.id=ce.content_type_id '
                    .'WHERE r.site_id=:site AND r.language_code=:lang AND r.full_path=:route '
                    .'AND r.resource_type=:resource_type AND ct.type_key=:type AND ce.is_active=1 LIMIT 1',
                    ['site'=>$siteId,'lang'=>$lang,'route'=>$route,'resource_type'=>'content_entry','type'=>$typeKey]
                );
                if($row!==null){$row['_matched_by']='site_type_language_route';return $row;}
            }
            $slug=(string)($loc['slug']??'');
            if($slug!==''){
                $row=$this->db->one(
                    'SELECT ce.id,ce.entry_key FROM content_entry_localizations cel '
                    .'JOIN content_entries ce ON ce.id=cel.entry_id JOIN content_types ct ON ct.id=ce.content_type_id '
                    .'WHERE ce.site_id=:site AND cel.language_code=:lang AND cel.draft_slug=:slug '
                    .'AND ct.type_key=:type AND ce.is_active=1 AND cel.is_active=1 LIMIT 1',
                    ['site'=>$siteId,'lang'=>$lang,'slug'=>$slug,'type'=>$typeKey]
                );
                if($row!==null){$row['_matched_by']='site_type_language_slug';return $row;}
            }
        }
        return null;
    }
    /** @param array<string,mixed> $entry */
    private function createEntry(array $entry,int $siteId,int $userId): int{$type=$this->portableSuffix((string)($entry['content_type_key']??''));$ct=$this->db->one('SELECT id FROM content_types WHERE type_key=:key LIMIT 1',['key'=>$type]);if(!$ct){throw new \RuntimeException('Type de contenu absent : '.$type);}$entryKey=$this->canonicalEntryKey((string)($entry['entry_key']??''),(string)(($entry['localizations'][0]['language']??'')));$this->db->run('INSERT INTO content_entries(site_id,content_type_id,entry_key,author_iam_user_id,owner_iam_user_id,created_by_iam_user_id,updated_by_iam_user_id,status,workflow_state,is_active,created_at,updated_at) VALUES(:site,:type,:key,:user,:user,:user,:user,\'draft\',\'draft\',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',['site'=>$siteId,'type'=>(int)$ct['id'],'key'=>$entryKey,'user'=>$userId]);return $this->db->lastInsertId();}
    private function assertRouteAvailable(int $siteId,string $lang,string $route,int $entryId): void{if($route===''){return;}$row=$this->db->one('SELECT resource_type,resource_id FROM routes WHERE site_id=:site AND language_code=:lang AND full_path=:route LIMIT 1',['site'=>$siteId,'lang'=>$lang,'route'=>$route]);if($row&&((string)$row['resource_type']!=='content_entry'||(int)$row['resource_id']!==$entryId)){throw new \RuntimeException('Route déjà occupée : '.$lang.' '.$route);}}
    /** @param array<string,mixed> $entry @param array<string,mixed> $loc @param array<string,int> $mediaMap @param list<string> $warnings @return array<string,mixed> */
    private function document(array $entry,array $loc,array $mediaMap,array &$warnings): array
    {
        $lang=(string)($loc['language']??'');
        $localized=(array)(((array)($entry['localized_content']??[]))[$lang]??[]);
        $blocks=(array)($localized['blocks']??$entry['blocks']??[]);
        $keys=array_values((array)($localized['media_keys']??$entry['media_keys']??[]));
        $ids=array_values(array_filter(array_map(fn($k)=>$mediaMap[(string)$k]??null,$keys)));
        $sourceIds=[];$this->collectMediaIds($blocks,$sourceIds);$idMap=[];
        foreach(array_values(array_unique($sourceIds)) as $i=>$old){if(isset($ids[$i])){$idMap[$old]=$ids[$i];}}
        $blocks=$this->replaceMediaIds($blocks,$idMap);
        if(count(array_unique($sourceIds))>count($ids)){$warnings[]='Certaines références média numériques n’ont pas pu être remappées pour '.$entry['entry_key'];}

        $title=trim((string)($loc['title']??''));
        $slug=trim((string)($loc['slug']??''));
        if($title===''){throw new \RuntimeException('Entrée éditoriale invalide : titre localisé absent pour '.(string)($entry['entry_key']??''));}
        if($slug===''){throw new \RuntimeException('Entrée éditoriale invalide : slug localisé absent pour '.(string)($entry['entry_key']??''));}

        $fields=(array)($localized['fields']??$entry['fields']??[]);
        $seoSource=(array)($localized['seo']??$entry['seo']??[]);
        $seo=[
            'meta_title'=>(string)($seoSource['title']??$seoSource['meta_title']??$title),
            'meta_description'=>(string)($seoSource['description']??$seoSource['meta_description']??''),
            'canonical_url'=>$seoSource['canonical']??$seoSource['canonical_url']??null,
            'meta_robots'=>$this->robotsValue((array)($seoSource['robots']??[]),$seoSource['meta_robots']??null),
            'open_graph'=>(array)($seoSource['open_graph']??[]),
            'twitter'=>(array)($seoSource['twitter']??[]),
            'structured_data'=>(array)($seoSource['structured_data']??[]),
            'blueprint_metadata'=>(array)($seoSource['blueprint_metadata']??[]),
        ];
        if(isset($ids[0])&&(array)($seoSource['image_media_keys']??[])!==[]){$seo['og_image_media_id']=$ids[0];$seo['twitter_image_media_id']=$ids[0];}

        return [
            'language_code'=>(string)($loc['language']??''),
            'content'=>[
                'title'=>$title,
                'slug'=>$slug,
                'summary'=>(string)($fields['summary']??$seo['meta_description']??''),
                'blocks'=>$blocks,
            ],
            'fields'=>$fields,
            'blocks'=>$blocks,
            'seo'=>$seo,
            'routing'=>['slug'=>$slug,'full_path'=>$loc['route']??null],
            'import'=>['source'=>'editorial_package','entry_key'=>$entry['entry_key']??null],
        ];
    }

    /** @param array<string,mixed> $robots */
    private function robotsValue(array $robots,mixed $fallback): string
    {
        if(is_string($fallback)&&trim($fallback)!==''){return trim($fallback);}
        return (($robots['index']??true)?'index':'noindex').','.(($robots['follow']??true)?'follow':'nofollow');
    }
    private function writeRevision(int $entryId,string $lang,array $document,int $userId,string $importId): int{$prev=$this->db->one('SELECT MAX(revision_number) n FROM revisions WHERE resource_type=\'content_entry\' AND resource_id=:id AND language_code=:lang',['id'=>$entryId,'lang'=>$lang]);$pub=$this->db->one('SELECT published_revision_id FROM content_entry_publications WHERE entry_id=:id AND language_code=:lang',['id'=>$entryId,'lang'=>$lang]);$json=$this->json($document);$this->db->run('INSERT INTO revisions(resource_type,resource_id,revision_number,language_code,workflow_status,document_schema_version,base_revision_id,source_published_revision_id,created_from_event,revision_label,summary,change_notes,document_json,checksum_sha256,created_by_iam_user_id,updated_by_iam_user_id,created_at,updated_at) VALUES(\'content_entry\',:id,:number,:lang,\'draft\',1,:base,:source,\'editorial_import\',:label,:summary,:notes,:document,:checksum,:user,:user,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',['id'=>$entryId,'number'=>(int)($prev['n']??0)+1,'lang'=>$lang,'base'=>$pub['published_revision_id']??null,'source'=>$pub['published_revision_id']??null,'label'=>'Import '.$importId,'summary'=>'Editorial Package import','notes'=>'import_id='.$importId,'document'=>$json,'checksum'=>hash('sha256',$json),'user'=>$userId]);$rid=$this->db->lastInsertId();$this->db->run('INSERT INTO content_entry_working_revisions(site_id,entry_id,language_code,working_revision_id,workflow_status,updated_by_iam_user_id,created_at,updated_at) SELECT site_id,id,:lang,:rid,\'draft\',:user,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM content_entries WHERE id=:id ON CONFLICT(entry_id,language_code) DO UPDATE SET working_revision_id=excluded.working_revision_id,workflow_status=\'draft\',updated_by_iam_user_id=excluded.updated_by_iam_user_id,updated_at=CURRENT_TIMESTAMP',['lang'=>$lang,'rid'=>$rid,'user'=>$userId,'id'=>$entryId]);$this->db->run('UPDATE content_entries SET status=\'draft\',workflow_state=\'draft\',updated_by_iam_user_id=:user,updated_at=CURRENT_TIMESTAMP,published_at=NULL WHERE id=:id',['user'=>$userId,'id'=>$entryId]);return $rid;}
    /** @param array<string,mixed> $loc */
    private function upsertLocalization(int $entryId,string $lang,array $loc,int $revisionId,int $userId): void{$route=(string)($loc['route']??'');$this->db->run('INSERT INTO content_entry_localizations(entry_id,language_code,title,draft_slug,draft_full_path,translation_status,source_language_code,translated_from_revision_id,localized_at,updated_by_iam_user_id,draft_status,is_active,updated_at) VALUES(:id,:lang,:title,:slug,:path,\'reviewed\',:lang,:rid,CURRENT_TIMESTAMP,:user,\'ready\',1,CURRENT_TIMESTAMP) ON CONFLICT(entry_id,language_code) DO UPDATE SET title=excluded.title,draft_slug=excluded.draft_slug,draft_full_path=excluded.draft_full_path,translation_status=\'reviewed\',translated_from_revision_id=excluded.translated_from_revision_id,updated_by_iam_user_id=excluded.updated_by_iam_user_id,draft_status=\'ready\',is_active=1,updated_at=CURRENT_TIMESTAMP',['id'=>$entryId,'lang'=>$lang,'title'=>$loc['title']??null,'slug'=>$loc['slug']??null,'path'=>$route,'rid'=>$revisionId,'user'=>$userId]);}
    /** @return list<array<string,mixed>> */ private function readJsonDirectory(string $dir): array{$rows=[];if(!is_dir($dir)){return $rows;}$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));foreach($it as $f){if($f->isFile()&&strtolower($f->getExtension())==='json'){$rows[]=EditorialPackageJson::decode((string)file_get_contents($f->getPathname()));}}return $rows;}
    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function normalizeEntryKeys(array $entry): array
    {
        $localizations = is_array($entry['localizations'] ?? null) ? $entry['localizations'] : [];
        $language = '';
        foreach ($localizations as $localization) {
            if (is_array($localization) && trim((string) ($localization['language'] ?? '')) !== '') {
                $language = (string) $localization['language'];
                break;
            }
        }
        $entryKey = $this->canonicalEntryKey((string) ($entry['entry_key'] ?? ''), $language);
        $entry['entry_key'] = $entryKey;
        foreach ($localizations as $index => $localization) {
            if (!is_array($localization)) { continue; }
            $lang = (string) ($localization['language'] ?? $language);
            $localization['localization_key'] = 'loc:' . trim((string) ($entry['source_site_key'] ?? 'site')) . ':' . $entryKey . ':' . $lang;
            $localizations[$index] = $localization;
        }
        $entry['localizations'] = $localizations;
        $entry['blocks'] = $this->normalizeBlockKeys((array) ($entry['blocks'] ?? []), $entryKey, $language);
        $localizedContent=(array)($entry['localized_content']??[]);
        foreach($localizedContent as $lang=>$payload){
            if(!is_array($payload)){continue;}
            $payload['blocks']=$this->normalizeBlockKeys((array)($payload['blocks']??[]),$entryKey,(string)$lang);
            $localizedContent[$lang]=$payload;
        }
        $entry['localized_content']=$localizedContent;
        $relations=[];
        foreach((array)($entry['relations']??[]) as $relation){
            if(!is_array($relation)){continue;}
            $relation['target_entry_key']=$this->canonicalEntryKey((string)($relation['target_entry_key']??''),'');
            $relations[]=$relation;
        }
        $entry['relations']=$relations;
        return $entry;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function normalizeBlockKeys(array $blocks, string $entryKey, string $language): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) { continue; }
            $sourceKey = trim((string) ($block['block_key'] ?? $block['id'] ?? $index));
            $parts = explode(':', $sourceKey);
            $suffix = trim((string) end($parts));
            if ($suffix === '') { $suffix = 'block_' . $index; }
            $block['block_key'] = 'block:' . $entryKey . ':' . $language . ':' . $suffix;
            $blocks[$index] = $block;
        }
        return $blocks;
    }

    private function canonicalEntryKey(string $entryKey, string $language): string
    {
        $key = trim($entryKey);
        $language = strtolower(trim($language));
        while (preg_match('/^entry:(.+):([a-z]{2}(?:-[a-z0-9]+)?)$/i', $key, $matches) === 1) {
            if ($language !== '' && strtolower((string) $matches[2]) !== $language) { break; }
            $next = trim((string) $matches[1]);
            if ($next === '' || $next === $key) { break; }
            $key = $next;
        }
        if ($key === '') { throw new \RuntimeException('Clé d’entrée invalide dans le paquet éditorial.'); }
        return $key;
    }

    private function portableSuffix(string $value): string{$parts=explode(':',$value);return (string)end($parts);}
    private function json(mixed $v): string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function safeName(string $n): string{return trim(preg_replace('/[^A-Za-z0-9._-]+/','-',basename($n))??'media','-')?:'media';}
    private function uuid(): string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
    private function collectMediaIds(mixed $v,array &$ids): void{if(!is_array($v)){return;}foreach($v as $k=>$x){if(is_string($k)&&str_ends_with($k,'_media_id')&&is_numeric($x)){$ids[]=(int)$x;}else{$this->collectMediaIds($x,$ids);}}}
    private function replaceMediaIds(mixed $v,array $map): mixed{if(!is_array($v)){return $v;}foreach($v as $k=>$x){if(is_string($k)&&str_ends_with($k,'_media_id')&&is_numeric($x)&&isset($map[(int)$x])){$v[$k]=$map[(int)$x];}else{$v[$k]=$this->replaceMediaIds($x,$map);}}return $v;}
}
