<?php

declare(strict_types=1);

namespace App\EditorialPackage;

use App\Core\Database;

/** Builds a read-only import plan. It never opens a transaction nor writes data. */
final class EditorialImportPlanner
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function plan(string $root, int $targetSiteId, string $targetSiteKey): array
    {
        $manifest = EditorialPackageJson::decode((string) file_get_contents($root . '/editorial-package.json'));
        $site = EditorialPackageJson::decode((string) file_get_contents($root . '/site.json'));
        $blueprints = $this->readJsonDirectory($root . '/blueprints');
        $entries = $this->readJsonDirectory($root . '/entries');
        $mediaManifest = EditorialPackageJson::decode((string) file_get_contents($root . '/media/manifest.json'));
        $packageLanguages=array_values(array_filter((array)($manifest['languages']??[]),'is_string'));
        $targetLanguages=array_fill_keys(array_map(static fn(array $row): string=>(string)$row['language_code'],$this->db->all('SELECT language_code FROM site_languages WHERE site_id=:site AND is_active=1',['site'=>$targetSiteId])),true);
        $languageErrors=[];
        foreach($packageLanguages as $language){if(!isset($targetLanguages[$language])){$languageErrors[]='Langue absente ou inactive sur le site cible : '.$language;}}
        $availableContentTypes=array_fill_keys(array_map(static fn(array $row): string=>(string)$row['type_key'],$this->db->all('SELECT type_key FROM content_types')),true);

        $blueprintExisting = []; $blueprintImport = []; $blueprintIncompatible = [];
        foreach ($blueprints as $blueprint) {
            $key = (string) ($blueprint['blueprint_key'] ?? '');
            $existing = $this->db->one(
                'SELECT b.id,b.blueprint_key,b.resource_type,b.label,bv.version,bv.schema_json,bv.is_active '
                . 'FROM blueprints b JOIN blueprint_versions bv ON bv.id=b.active_version_id '
                . 'WHERE b.blueprint_key=:key AND (b.site_id=:site OR b.site_id IS NULL) AND b.is_active=1 '
                . 'ORDER BY b.site_id DESC LIMIT 1', ['key'=>$key,'site'=>$targetSiteId]
            );
            if ($existing === null) { $blueprintImport[] = ['blueprint_key'=>$key,'label'=>$blueprint['label'] ?? $key,'version'=>$blueprint['version'] ?? null]; continue; }
            $reasons = $this->blueprintCompatibilityErrors($blueprint, $existing);
            if ($reasons !== []) { $blueprintIncompatible[] = ['blueprint_key'=>$key,'reasons'=>$reasons]; }
            else { $blueprintExisting[] = ['blueprint_key'=>$key,'existing_version'=>(int)($existing['version'] ?? 0),'policy'=>'keep_existing']; }
        }
        $incompatibleKeys = array_fill_keys(array_map(static fn(array $r): string => (string)$r['blueprint_key'], $blueprintIncompatible), true);

        $mediaNew = []; $mediaExisting = [];
        foreach ((array) ($mediaManifest['items'] ?? []) as $media) {
            if (!is_array($media)) { continue; }
            $hash = (string) ($media['sha256'] ?? '');
            $found = $hash !== '' ? $this->db->one('SELECT id,uuid,filename,mime_type,size_bytes FROM media_assets WHERE site_id=:site AND sha256=:hash AND deleted_at IS NULL LIMIT 1', ['site'=>$targetSiteId,'hash'=>$hash]) : null;
            $row = ['media_key'=>$media['media_key'] ?? null,'sha256'=>$hash,'filename'=>$media['filename'] ?? null,'mime_type'=>$media['mime_type'] ?? null,'size'=>$media['size'] ?? null];
            if ($found === null) { $mediaNew[] = $row; } else { $mediaExisting[] = $row + ['existing_media_id'=>(int)$found['id'],'existing_uuid'=>$found['uuid'] ?? null]; }
        }

        $toCreate=[]; $existingEntries=[]; $conflicts=[]; $routeConflicts=[]; $unresolvedRelations=[];
        foreach ($entries as $entry) {
            $entryKey = (string) ($entry['entry_key'] ?? '');
            $typeKey = $this->portableSuffix((string) ($entry['content_type_key'] ?? ''));
            $match = $this->matchEntry($entry, $targetSiteId, $typeKey);
            $localizations = is_array($entry['localizations'] ?? null) ? $entry['localizations'] : [];
            $entryRoutes=[];
            foreach ($localizations as $loc) {
                if (!is_array($loc)) { continue; }
                $language=(string)($loc['language'] ?? ''); $route=(string)($loc['route'] ?? '');
                $entryRoutes[]=['language'=>$language,'route'=>$route,'slug'=>$loc['slug'] ?? null];
                $routeOwner=$this->db->one('SELECT id,resource_type,resource_id,status FROM routes WHERE site_id=:site AND language_code=:lang AND full_path=:route LIMIT 1',['site'=>$targetSiteId,'lang'=>$language,'route'=>$route]);
                if ($routeOwner !== null && ($match === null || (int)$routeOwner['resource_id'] !== (int)$match['id'] || (string)$routeOwner['resource_type'] !== 'content_entry')) {
                    $routeConflicts[]=['entry_key'=>$entryKey,'language'=>$language,'route'=>$route,'owner_resource_type'=>$routeOwner['resource_type'],'owner_resource_id'=>(int)$routeOwner['resource_id']];
                }
            }
            $summary=['entry_key'=>$entryKey,'content_type_key'=>$typeKey,'blueprint_key'=>$entry['blueprint_key'] ?? null,'routes'=>$entryRoutes];
            if(!isset($availableContentTypes[$typeKey])){
                $conflicts[]=$summary+['reason'=>'content_type_missing'];
            } elseif (isset($incompatibleKeys[(string)($entry['blueprint_key'] ?? '')])) {
                $conflicts[]=$summary+['reason'=>'blueprint_incompatible'];
            } elseif ($match === null) {
                $toCreate[]=$summary;
            } else {
                $existingEntries[]=$summary+['existing_entry_id'=>(int)$match['id'],'existing_entry_key'=>$match['entry_key'],'matched_by'=>$match['_matched_by']];
            }
            foreach ((array)($entry['relations'] ?? []) as $relation) {
                if (!is_array($relation)) { continue; }
                $target=(string)($relation['target_entry_key'] ?? $relation['entry_key'] ?? '');
                if ($target === '') { continue; }
                $resolved=$this->db->one('SELECT id FROM content_entries WHERE site_id=:site AND entry_key=:key AND is_active=1 LIMIT 1',['site'=>$targetSiteId,'key'=>$target]);
                if ($resolved===null && !$this->packageContainsEntry($entries,$target)) { $unresolvedRelations[]=['entry_key'=>$entryKey,'target_entry_key'=>$target]; }
            }
        }

        $blocking=$languageErrors;
        foreach($conflicts as $conflict){if(($conflict['reason']??'')==='content_type_missing'){$blocking[]='Type de contenu absent : '.$conflict['content_type_key'];}}
        foreach ($blueprintIncompatible as $r) { $blocking[]='Blueprint incompatible : '.$r['blueprint_key']; }
        foreach ($routeConflicts as $r) { $blocking[]='Route déjà occupée : '.$r['language'].' '.$r['route']; }
        foreach ($unresolvedRelations as $r) { $blocking[]='Relation non résolue : '.$r['entry_key'].' -> '.$r['target_entry_key']; }

        return [
            'inspection'=>['format'=>$manifest['format'] ?? null,'format_version'=>$manifest['format_version'] ?? null,'release_id'=>$manifest['release_id'] ?? null,'generated_at'=>$manifest['generated_at'] ?? null,'source_site'=>$site['site_key'] ?? $manifest['source_site_key'] ?? null,'target_site'=>$targetSiteKey,'target_site_id'=>$targetSiteId,'languages'=>$manifest['languages'] ?? [],'scope'=>$manifest['scope'] ?? null,'published_only'=>$manifest['published_only'] ?? null],
            'modes'=>[
                'create'=>['creates'=>count($toCreate),'skips'=>count($existingEntries),'updates'=>0,'description'=>'Créer uniquement les contenus absents.'],
                'upsert'=>['creates'=>count($toCreate),'updates'=>count($existingEntries),'description'=>'Créer les absents et mettre à jour les correspondants.'],
                'replace'=>['creates'=>0,'skips'=>count($toCreate),'replaces'=>count($existingEntries),'description'=>'Modifier uniquement les contenus existants; les contenus absents sont ignorés.'],
            ],
            'contents'=>['to_create'=>$toCreate,'existing'=>$existingEntries,'conflicts'=>$conflicts],
            'blueprints'=>['existing'=>$blueprintExisting,'to_import'=>$blueprintImport,'incompatible'=>$blueprintIncompatible,'existing_policy'=>'keep_existing'],
            'media'=>['new'=>$mediaNew,'existing_by_sha256'=>$mediaExisting],
            'routes'=>['conflicts'=>$routeConflicts],
            'relations'=>['unresolved'=>$unresolvedRelations],
            'warnings'=>$existingEntries!==[]?['Des contenus existants ont été détectés. Le mode create les ignorera.']:[],
            'blocking_errors'=>array_values(array_unique($blocking)),
            'can_execute_later'=>$blocking===[],
            'writes_performed'=>false,
            'archive_retained'=>false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function readJsonDirectory(string $directory): array
    {
        $rows=[]; if(!is_dir($directory)){return $rows;}
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if($file->isFile()&&strtolower($file->getExtension())==='json'){$rows[]=EditorialPackageJson::decode((string)file_get_contents($file->getPathname()));}}
        return $rows;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed>|null */
    private function matchEntry(array $entry,int $siteId,string $typeKey): ?array
    {
        $portable=(string)($entry['entry_key']??'');
        $exact=$this->db->one('SELECT ce.id,ce.entry_key FROM content_entries ce JOIN content_types ct ON ct.id=ce.content_type_id WHERE ce.site_id=:site AND ce.entry_key=:key AND ct.type_key=:type AND ce.is_active=1 LIMIT 1',['site'=>$siteId,'key'=>$portable,'type'=>$typeKey]);
        if($exact!==null){$exact['_matched_by']='portable_key';return $exact;}
        foreach((array)($entry['localizations']??[]) as $loc){if(!is_array($loc)){continue;}$lang=(string)($loc['language']??'');$route=(string)($loc['route']??'');
            if($route!==''){$row=$this->db->one('SELECT ce.id,ce.entry_key FROM routes r JOIN content_entries ce ON ce.id=r.resource_id JOIN content_types ct ON ct.id=ce.content_type_id WHERE r.site_id=:site AND r.language_code=:lang AND r.full_path=:route AND r.resource_type=:resource_type AND ct.type_key=:type AND ce.is_active=1 LIMIT 1',['site'=>$siteId,'lang'=>$lang,'route'=>$route,'resource_type'=>'content_entry','type'=>$typeKey]);if($row!==null){$row['_matched_by']='site_type_language_route';return $row;}}
            $slug=(string)($loc['slug']??'');if($slug!==''){$row=$this->db->one('SELECT ce.id,ce.entry_key FROM content_entry_localizations cel JOIN content_entries ce ON ce.id=cel.entry_id JOIN content_types ct ON ct.id=ce.content_type_id WHERE ce.site_id=:site AND cel.language_code=:lang AND cel.draft_slug=:slug AND ct.type_key=:type AND ce.is_active=1 AND cel.is_active=1 LIMIT 1',['site'=>$siteId,'lang'=>$lang,'slug'=>$slug,'type'=>$typeKey]);if($row!==null){$row['_matched_by']='site_type_language_slug';return $row;}}
        }
        return null;
    }

    /** @param array<string,mixed> $imported @param array<string,mixed> $existing @return list<string> */
    private function blueprintCompatibilityErrors(array $imported,array $existing): array
    {
        $errors=[];if((string)($imported['resource_type']??'')!==(string)($existing['resource_type']??'')){$errors[]='resource_type différent';}
        $importSchema=(array)(($imported['definition']['schema']??[]));$existingSchema=json_decode((string)($existing['schema_json']??'{}'),true);if(!is_array($existingSchema)){$existingSchema=[];}
        $importFields=$this->fieldTypes($importSchema);$existingFields=$this->fieldTypes($existingSchema);
        foreach($importFields as $key=>$type){if(!array_key_exists($key,$existingFields)){$errors[]='champ absent : '.$key;}elseif($type!==''&&$existingFields[$key]!==''&&$type!==$existingFields[$key]){$errors[]='type différent pour '.$key;}}
        return $errors;
    }
    /** @return array<string,string> */
    private function fieldTypes(array $schema): array { $out=[];$fields=$schema['fields']??$schema['properties']??[];if(is_array($fields)){foreach($fields as $key=>$field){if(is_int($key)&&is_array($field)){$key=(string)($field['key']??$field['name']??'');}if($key!==''&&is_array($field)){$out[(string)$key]=(string)($field['type']??$field['field_type']??'');}}}return $out; }
    private function portableSuffix(string $key): string { $parts=explode(':',$key);return (string)end($parts); }
    /** @param list<array<string,mixed>> $entries */
    private function packageContainsEntry(array $entries,string $target): bool { foreach($entries as $entry){if((string)($entry['entry_key']??'')===$target){return true;}}return false; }
}
