<?php

declare(strict_types=1);

namespace App\EditorialPackage;

use App\Core\Database;
use App\Application\Media\Storage\StorageDriverFactory;
use App\StaticExport\StaticExportRoute;

/** Builds a validated Editorial Package from published projections only. */
final class EditorialExportService
{
    public function __construct(
        private readonly Database $db,
        private readonly EditorialPackageWriter $writer = new EditorialPackageWriter(),
        private readonly EditorialPackageValidator $validator = new EditorialPackageValidator(),
        private readonly ?StorageDriverFactory $storageFactory = null,
    ) {}

    /** @param list<StaticExportRoute> $routes @return array<string,mixed> */
    public function export(string $releaseDir, string $releaseId, array $routes, string $scopeType, ?string $scopeValue, ?string $cmsVersion): array
    {
        if ($routes === []) {
            throw new EditorialPackageException('Aucune route publiée ne peut alimenter le paquet éditorial.');
        }
        $tmpRoot = $releaseDir . '/.editorial-package-' . bin2hex(random_bytes(5));
        $packageRoot = $tmpRoot . '/package';
        $zipTmp = $releaseDir . '/.editorial-export-' . bin2hex(random_bytes(5)) . '.zip.tmp';
        $zipFinal = $releaseDir . '/editorial-export.zip';
        $siteKey = $routes[0]->siteKey;
        $siteId = $routes[0]->siteId;
        $languages = array_values(array_unique(array_map(static fn(StaticExportRoute $r): string => $r->languageCode, $routes)));
        sort($languages);

        try {
            $documents = [];
            $documents['site.json'] = $this->siteDocument($siteId, $siteKey, $languages);
            $entryIds = [];
            $entriesByKey = [];
            $blueprintIds = [];
            $mediaIds = [];

            foreach ($routes as $route) {
                if ($route->resourceType !== 'content_entry' || $route->resourceId <= 0) {
                    continue;
                }
                $snapshot = $this->publishedSnapshot($route);
                if ($snapshot === null) {
                    throw new EditorialPackageException('Projection publiée absente pour ' . $route->path . '.');
                }
                $entry = $this->entryDocument($route, $snapshot, $mediaIds, $blueprintIds);
                $key=(string)$entry['entry_key'];
                $entriesByKey[$key]=isset($entriesByKey[$key])?$this->mergeEntryDocument($entriesByKey[$key],$entry):$entry;
                $entryIds[$key] = true;
            }
            foreach($entriesByKey as $key=>$entry){
                $documents['entries/' . $this->safeFilename((string)$key) . '.json'] = $entry;
            }

            foreach (array_keys($blueprintIds) as $blueprintId) {
                $blueprint = $this->blueprintDocument((int) $blueprintId);
                $documents['blueprints/' . $this->safeFilename((string) $blueprint['blueprint_key']) . '.json'] = $blueprint;
            }

            $mediaItems = [];
            foreach (array_keys($mediaIds) as $mediaId) {
                $media = $this->mediaDocument((int) $mediaId, $languages);
                if ($media === null) {
                    continue;
                }
                $relative = 'media/files/' . $media['sha256'] . '-' . $this->portableFilename($media['filename']);
                $target = $packageRoot . '/' . $relative;
                $this->ensureDirectory(dirname($target));
                $factory=$this->storageFactory ?? new StorageDriverFactory($this->db);
                $storage=$factory->forDisk((string)($media['_storage_disk']??'local'),$siteId);
                if(!$storage->exists((string)$media['_storage_path'])){throw new EditorialPackageException('Original média introuvable : ' . $media['_storage_path']);}
                $input=$storage->readStream((string)$media['_storage_path']);
                $output=fopen($target,'xb');
                if(!is_resource($output)){if(is_resource($input)){fclose($input);}throw new EditorialPackageException('Impossible de préparer le média ' . $media['filename']);}
                try{if(stream_copy_to_stream($input,$output)===false){throw new EditorialPackageException('Impossible de copier le média ' . $media['filename']);}}finally{fclose($input);fclose($output);}
                unset($media['_storage_path'],$media['_storage_disk']);
                $media['path'] = $relative;
                $media['size'] = filesize($target) ?: 0;
                $media['sha256'] = hash_file('sha256', $target);
                $mediaItems[] = $media;
            }
            $documents['media/manifest.json'] = ['format' => EditorialPackageManifest::FORMAT, 'format_version' => EditorialPackageManifest::VERSION, 'items' => $mediaItems];

            $this->ensureDirectory($packageRoot);
            foreach ($documents as $relative => $document) {
                $path = $packageRoot . '/' . $relative;
                $this->ensureDirectory(dirname($path));
                file_put_contents($path, EditorialPackageJson::encode($document), LOCK_EX);
            }
            $descriptors = [];
            foreach (array_keys($documents) as $relative) {
                $descriptors[] = EditorialPackageWriter::fileDescriptor($packageRoot, $relative);
            }
            foreach ($mediaItems as $mediaItem) {
                $descriptors[] = EditorialPackageWriter::fileDescriptor($packageRoot, (string) $mediaItem['path']);
            }
            usort($descriptors, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
            $manifest = new EditorialPackageManifest(
                $cmsVersion ?: '1.0.0',
                $releaseId,
                gmdate('Y-m-d\TH:i:s\Z'),
                $siteKey,
                $languages,
                ['type' => $scopeType, 'value' => $scopeValue],
                ['entries' => count($entryIds), 'blueprints' => count($blueprintIds), 'media' => count($mediaItems)],
                $descriptors,
            );
            file_put_contents($packageRoot . '/editorial-package.json', EditorialPackageJson::encode($manifest->toArray()), LOCK_EX);

            $errors = $this->validator->validateDirectory($packageRoot);
            if ($errors !== []) {
                throw new EditorialPackageException('Paquet éditorial invalide : ' . implode(' | ', $errors));
            }
            $this->createZip($packageRoot, $zipTmp);
            if (is_file($zipFinal)) { unlink($zipFinal); }
            if (!rename($zipTmp, $zipFinal)) {
                throw new EditorialPackageException('Impossible de publier atomiquement editorial-export.zip.');
            }
            return [
                'path' => $zipFinal,
                'filename' => 'editorial-export.zip',
                'sha256' => hash_file('sha256', $zipFinal),
                'size' => filesize($zipFinal) ?: 0,
                'format' => EditorialPackageManifest::FORMAT,
                'format_version' => EditorialPackageManifest::VERSION,
                'counts' => $manifest->toArray()['counts'],
            ];
        } finally {
            if (is_file($zipTmp)) { @unlink($zipTmp); }
            $this->removeDirectory($tmpRoot);
        }
    }

    /** @return array<string,mixed> */
    private function siteDocument(int $siteId, string $siteKey, array $languages): array
    {
        $site = $this->db->one(
            'SELECT s.site_key, s.name, s.created_at, s.updated_at, '
            . 'sd.scheme, sd.host, sd.base_path '
            . 'FROM sites s '
            . 'LEFT JOIN site_domains sd ON sd.id = ('
            . 'SELECT preferred.id FROM site_domains preferred '
            . 'WHERE preferred.site_id = s.id AND preferred.is_active = 1 '
            . 'ORDER BY preferred.is_primary DESC, preferred.id ASC LIMIT 1'
            . ') '
            . 'WHERE s.id = :id',
            ['id' => $siteId],
        ) ?? [];
        $scheme = trim((string) ($site['scheme'] ?? ''));
        $host = trim((string) ($site['host'] ?? ''));
        $basePath = trim((string) ($site['base_path'] ?? ''));
        $baseUrl = $host !== ''
            ? rtrim(($scheme !== '' ? $scheme : 'https') . '://' . $host . ($basePath !== '' ? '/' . ltrim($basePath, '/') : ''), '/')
            : '';

        return [
            'site_key' => $siteKey,
            'name' => (string) ($site['name'] ?? $siteKey),
            'base_url' => $baseUrl,
            'languages' => $languages,
            'dates' => [
                'created_at' => $site['created_at'] ?? null,
                'updated_at' => $site['updated_at'] ?? null,
            ],
        ];
    }

    private function publishedSnapshot(StaticExportRoute $route): ?array
    {
        return $this->db->one('SELECT * FROM public_content_snapshots WHERE site_id=:site AND language_code=:lang AND resource_type=:type AND resource_id=:id LIMIT 1', ['site'=>$route->siteId,'lang'=>$route->languageCode,'type'=>$route->resourceType,'id'=>$route->resourceId]);
    }

    /** @param array<int,bool> $mediaIds @param array<int,bool> $blueprintIds @return array<string,mixed> */
    private function entryDocument(StaticExportRoute $route, array $snapshot, array &$mediaIds, array &$blueprintIds): array
    {
        $base = $this->db->one('SELECT ce.entry_key, ce.created_at, ce.updated_at, ce.published_at, ct.type_key, ct.name FROM content_entries ce JOIN content_types ct ON ct.id=ce.content_type_id WHERE ce.id=:id AND ce.site_id=:site AND ce.status=:published_status AND ce.is_active=1', ['id'=>$route->resourceId,'site'=>$route->siteId,'published_status'=>'published']);
        if ($base === null) { throw new EditorialPackageException('Entrée publiée introuvable pour ' . $route->path); }
        $bp = $this->db->one('SELECT b.id,b.blueprint_key FROM blueprint_usage bu JOIN blueprints b ON b.id=bu.blueprint_id JOIN blueprint_versions bv ON bv.id=b.active_version_id WHERE bu.usage_type=:usage_type AND bu.resource_id=:id AND (bu.site_id=:site OR bu.site_id IS NULL) AND b.is_active=1 AND bv.is_active=1 ORDER BY bu.site_id DESC LIMIT 1', ['id'=>$route->resourceId,'site'=>$route->siteId,'usage_type'=>'content_entry']);
        if ($bp === null) {
            $bp = $this->db->one('SELECT b.id,b.blueprint_key FROM blueprints b JOIN blueprint_versions bv ON bv.id=b.active_version_id WHERE b.resource_type=:resource_type AND b.legacy_content_type_id=(SELECT content_type_id FROM content_entries WHERE id=:id) AND (b.site_id=:site OR b.site_id IS NULL) AND b.is_active=1 AND bv.is_active=1 ORDER BY b.site_id DESC LIMIT 1', ['id'=>$route->resourceId,'site'=>$route->siteId,'resource_type'=>'content_type']);
        }
        if ($bp === null) { throw new EditorialPackageException('Blueprint actif introuvable pour ' . $base['entry_key']); }
        $blueprintIds[(int)$bp['id']] = true;

        $entryMediaIds = [];
        foreach ($this->db->all('SELECT DISTINCT media_id FROM media_usages WHERE site_id=:site AND resource_type=:resource_type AND resource_id=:id AND (language_code=:lang OR language_code IS NULL)', ['site'=>$route->siteId,'id'=>$route->resourceId,'lang'=>$route->languageCode,'resource_type'=>'content_entry']) as $row) { $entryMediaIds[(int)$row['media_id']] = true; $mediaIds[(int)$row['media_id']] = true; }
        $rawSeo = $this->decode((string)($snapshot['seo_json'] ?? '{}'), []);
        foreach (['og_image_media_id','twitter_image_media_id'] as $key) { if (isset($rawSeo[$key]) && is_numeric($rawSeo[$key])) { $entryMediaIds[(int)$rawSeo[$key]] = true; $mediaIds[(int)$rawSeo[$key]] = true; } }
        $entryKey = $this->canonicalEntryKey((string) $base['entry_key'], $route->languageCode);
        $blocks = $this->normalizeBlocks($this->decode((string)($snapshot['blocks_json'] ?? '[]'), []), $entryKey, $route->languageCode);
        $document = $this->decode((string)($snapshot['document_json'] ?? '{}'), []);
        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : $document;
        $seo=$this->normalizeSeo(is_array($rawSeo) ? $rawSeo : [], $entryMediaIds);
        $mediaKeys=array_map(fn(int $id): string => $this->portableKey('media', (string)($this->db->one('SELECT uuid FROM media_assets WHERE id=:id',['id'=>$id])['uuid'] ?? $id)), array_keys($entryMediaIds));
        $entry=(new EditorialEntry(
            $entryKey,
            $this->portableKey('type', (string)$base['type_key']),
            (string)$bp['blueprint_key'],
            $route->siteKey,
            [[
                'localization_key'=>$this->portableKey('loc', $route->siteKey . ':' . $entryKey . ':' . $route->languageCode),
                'language'=>$route->languageCode,'slug'=>(string)($snapshot['slug'] ?? ''),'route'=>$route->path,'title'=>(string)($snapshot['title'] ?? ''),
            ]],
            $fields,
            is_array($blocks) ? $blocks : [],
            $seo,
            $this->relationDocuments($route->resourceId),
            $mediaKeys,
            ['created_at'=>$base['created_at'] ?? null,'updated_at'=>$base['updated_at'] ?? null,'published_at'=>$snapshot['published_at'] ?? $base['published_at'] ?? null,'projected_at'=>$snapshot['projected_at'] ?? null],
        ))->toArray();
        $entry['localized_content']=[$route->languageCode=>['fields'=>$fields,'blocks'=>is_array($blocks)?$blocks:[],'seo'=>$seo,'media_keys'=>$mediaKeys]];
        return $entry;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $incoming @return array<string,mixed> */
    private function mergeEntryDocument(array $base,array $incoming): array
    {
        foreach((array)($incoming['localizations']??[]) as $localization){
            if(!is_array($localization)){continue;}
            $language=(string)($localization['language']??'');
            $base['localizations']=array_values(array_filter((array)($base['localizations']??[]),static fn($row): bool=>!is_array($row)||(string)($row['language']??'')!==$language));
            $base['localizations'][]=$localization;
        }
        $base['languages']=array_values(array_unique(array_merge((array)($base['languages']??[]),(array)($incoming['languages']??[]))));
        sort($base['languages']);
        $base['localized_content']=array_replace((array)($base['localized_content']??[]),(array)($incoming['localized_content']??[]));
        $base['media_keys']=array_values(array_unique(array_merge((array)($base['media_keys']??[]),(array)($incoming['media_keys']??[]))));
        $base['relations']=array_values(array_unique(array_merge((array)($base['relations']??[]),(array)($incoming['relations']??[])),SORT_REGULAR));
        return $base;
    }

    /** @return list<array<string,mixed>> */
    private function relationDocuments(int $entryId): array
    {
        $rows=$this->db->all('SELECT cr.relation_type,cr.sort_order,cr.metadata_json,target.entry_key AS target_entry_key FROM content_relations cr JOIN content_entries target ON target.id=cr.target_entry_id WHERE cr.source_entry_id=:id AND target.is_active=1 ORDER BY cr.sort_order,cr.id',['id'=>$entryId]);
        $out=[];
        foreach($rows as $row){$out[]=['target_entry_key'=>(string)$row['target_entry_key'],'relation_type'=>(string)$row['relation_type'],'sort_order'=>(int)$row['sort_order'],'metadata'=>$this->decode((string)($row['metadata_json']??'{}'),[])];}
        return $out;
    }

    /** @return array<string,mixed> */
    private function blueprintDocument(int $id): array
    {
        $row = $this->db->one('SELECT b.blueprint_key,b.resource_type,b.label,b.description,bv.version,bv.schema_json,bv.ui_schema_json,bv.validation_json,bv.seo_policy_json,bv.routing_policy_json,bv.workflow_policy_json,bv.translation_policy_json,bv.permissions_policy_json FROM blueprints b JOIN blueprint_versions bv ON bv.id=b.active_version_id WHERE b.id=:id AND b.is_active=1 AND bv.is_active=1 LIMIT 1',['id'=>$id]);
        if ($row === null) { throw new EditorialPackageException('Blueprint actif absent.'); }
        $definition = ['description'=>$row['description'] ?? null,'schema'=>$this->decode($row['schema_json'],'{}'),'ui_schema'=>$this->decode($row['ui_schema_json'],'{}'),'validation'=>$this->decode($row['validation_json'],'{}'),'seo_policy'=>$this->decode($row['seo_policy_json'],'{}'),'routing_policy'=>$this->decode($row['routing_policy_json'],'{}'),'workflow_policy'=>$this->decode($row['workflow_policy_json'],'{}'),'translation_policy'=>$this->decode($row['translation_policy_json'],'{}'),'permissions_policy'=>$this->decode($row['permissions_policy_json'],'{}')];
        return (new EditorialBlueprint((string)$row['blueprint_key'],(string)$row['resource_type'],(int)$row['version'],(string)$row['label'],$definition,[]))->toArray();
    }

    /** @return array<string,mixed>|null */
    private function mediaDocument(int $id, array $languages): ?array
    {
        $row = $this->db->one('SELECT * FROM media_assets WHERE id=:id AND lifecycle_status=:lifecycle_status AND validation_status=:validation_status AND deleted_at IS NULL',['id'=>$id,'lifecycle_status'=>'ready','validation_status'=>'valid']);
        if ($row === null) { return null; }
        $alts=[]; foreach($this->db->all('SELECT language_code,alt_text FROM media_asset_localizations WHERE media_id=:id',['id'=>$id]) as $loc){ if(in_array($loc['language_code'],$languages,true)&&trim((string)$loc['alt_text'])!==''){$alts[(string)$loc['language_code']]=(string)$loc['alt_text'];}}
        return ['media_key'=>$this->portableKey('media',(string)$row['uuid']),'path'=>'','filename'=>(string)$row['filename'],'mime_type'=>(string)$row['mime_type'],'size'=>(int)$row['size_bytes'],'sha256'=>(string)$row['sha256'],'metadata'=>['media_type'=>$row['media_type'],'width'=>$row['width'],'height'=>$row['height'],'copyright'=>$row['copyright_text'],'license'=>$row['license_type'],'source_url'=>$row['source_url'],'metadata'=>$this->decode((string)($row['metadata_json'] ?? '{}'),[])],'alt_texts'=>$alts,'references'=>[],'_storage_path'=>(string)$row['path'],'_storage_disk'=>(string)($row['storage_disk']??'local')];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeBlocks(mixed $blocks, string $entryKey, string $language): array
    {
        if (!is_array($blocks)) { return []; }
        $out = [];
        $index = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) { continue; }
            $type = (string) ($block['type'] ?? $block['block_type'] ?? 'richtext');
            $data = $block['data'] ?? $block['settings'] ?? $block;
            if (!is_array($data)) { $data = ['value' => $data]; }
            unset($data['id'], $data['type'], $data['block_type'], $data['editorial_status'], $data['enabled'], $data['sort_order'], $data['block_key']);
            if ($type === 'columns' && is_array($data['columns'] ?? null)) {
                foreach ($data['columns'] as $columnIndex => $column) {
                    if (!is_array($column)) { continue; }
                    $column['blocks'] = $this->normalizeBlocks($column['blocks'] ?? [], $entryKey . ':column:' . $columnIndex, $language);
                    $data['columns'][$columnIndex] = $column;
                }
            }
            $out[] = [
                'block_key' => $this->portableKey('block', $entryKey . ':' . $language . ':' . $this->blockIdentitySuffix((string) ($block['block_key'] ?? $block['id'] ?? $index))),
                'type' => $type,
                'editorial_status' => 'published',
                'enabled' => true,
                'data' => $data,
                'sort_order' => (int) ($block['sort_order'] ?? $index),
            ];
            $index++;
        }
        return $out;
    }

    /** @param array<int,bool> $mediaIds @return array<string,mixed> */
    private function normalizeSeo(array $seo, array $mediaIds): array
    {
        $robots = strtolower((string) ($seo['meta_robots'] ?? $seo['robots'] ?? 'index,follow'));
        $structured = $seo['structured_data'] ?? $seo['json_ld'] ?? [];
        if (is_string($structured)) { $structured = $this->decode($structured, []); }
        if (!is_array($structured)) { $structured = []; }
        if ($structured !== [] && !array_is_list($structured)) { $structured = [$structured]; }
        $imageKeys = array_map(fn(int $id): string => $this->portableKey('media', (string)($this->db->one('SELECT uuid FROM media_assets WHERE id=:id',['id'=>$id])['uuid'] ?? $id)), array_keys($mediaIds));
        return [
            'title' => $seo['meta_title'] ?? $seo['title'] ?? null,
            'description' => $seo['meta_description'] ?? $seo['description'] ?? null,
            'canonical' => $seo['canonical_url'] ?? $seo['canonical'] ?? null,
            'robots' => ['index' => !str_contains($robots, 'noindex'), 'follow' => !str_contains($robots, 'nofollow')],
            'open_graph' => ['title'=>$seo['og_title'] ?? null,'description'=>$seo['og_description'] ?? null],
            'twitter' => ['title'=>$seo['twitter_title'] ?? null,'description'=>$seo['twitter_description'] ?? null],
            'structured_data' => array_values($structured),
            'blueprint_metadata' => is_array($seo['blueprint_metadata'] ?? null) ? $seo['blueprint_metadata'] : [],
            'image_media_keys' => array_values(array_unique($imageKeys)),
        ];
    }

    private function blockIdentitySuffix(string $blockKey): string
    {
        $value = trim($blockKey);
        if ($value === '') { return 'block'; }
        $parts = explode(':', $value);
        $suffix = trim((string) end($parts));
        return $suffix !== '' ? $suffix : 'block';
    }

    private function canonicalEntryKey(string $entryKey, string $language): string
    {
        $key = trim($entryKey);
        $language = strtolower(trim($language));
        while (preg_match('/^entry:(.+):([a-z]{2}(?:-[a-z0-9]+)?)$/i', $key, $matches) === 1) {
            if (strtolower((string) $matches[2]) !== $language) {
                break;
            }
            $next = trim((string) $matches[1]);
            if ($next === '' || $next === $key) {
                break;
            }
            $key = $next;
        }
        if ($key === '') {
            throw new EditorialPackageException('Clé d’entrée vide après normalisation.');
        }
        return $key;
    }

    private function portableKey(string $prefix,string $value): string { $v=strtolower(preg_replace('/[^a-z0-9._:-]+/i','-',trim($value))??''); $v=trim($v,'-._:'); return $prefix . ':' . ($v!==''?$v:hash('sha256',$value)); }
    private function safeFilename(string $value): string { return preg_replace('/[^A-Za-z0-9._-]+/','_',str_replace(':','-',$value)) ?: 'item'; }
    private function portableFilename(string $value): string { return basename(str_replace('\\','/',$value)); }
    private function decode(string $json, mixed $fallback): mixed { $v=json_decode($json,true); return json_last_error()===JSON_ERROR_NONE?$v:$fallback; }
    private function ensureDirectory(string $dir): void { if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir)){throw new EditorialPackageException('Impossible de créer '.$dir);} }
    private function createZip(string $root,string $target): void { if(!class_exists(\ZipArchive::class)){throw new EditorialPackageException('Extension ZipArchive indisponible.');}$z=new \ZipArchive();if($z->open($target,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){throw new EditorialPackageException('Impossible de créer le ZIP éditorial.');}$len=strlen(rtrim($root,DIRECTORY_SEPARATOR))+1;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f){if($f->isFile()){$z->addFile($f->getPathname(),str_replace('\\','/',substr($f->getPathname(),$len)));}}$z->close();if(!is_file($target)){throw new EditorialPackageException('ZIP éditorial non créé.');}}
    private function removeDirectory(string $dir): void { if(!is_dir($dir)){return;}$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($dir);}
}
