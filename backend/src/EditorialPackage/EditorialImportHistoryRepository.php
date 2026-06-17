<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialImportHistoryRepository
{
    public function __construct(private readonly string $root = '') {}

    public function root(): string
    {
        return $this->root !== '' ? $this->root : base_path('storage/imports/editorial');
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $plan @param array<string,mixed> $report */
    public function write(string $importId, array $manifest, array $plan, array $report): void
    {
        $dir = $this->root() . '/' . $importId;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer l’historique d’import.');
        }
        foreach (['import-manifest.json'=>$manifest,'import-plan.json'=>$plan,'import-report.json'=>$report] as $name=>$data) {
            $tmp = $dir . '/.' . $name . '.tmp-' . bin2hex(random_bytes(4));
            file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if (!rename($tmp, $dir . '/' . $name)) { @unlink($tmp); throw new \RuntimeException('Écriture atomique impossible : ' . $name); }
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 20, ?int $siteId = null): array
    {
        $root=$this->root(); if(!is_dir($root)){return [];}
        $rows=[];
        foreach(array_reverse(glob($root.'/*',GLOB_ONLYDIR)?:[]) as $dir){
            $report=$dir.'/import-report.json'; if(!is_file($report)){continue;}
            $data=json_decode((string)file_get_contents($report),true); if(!is_array($data)){continue;}
            if($siteId!==null && (int)($data['site_id']??0)!==$siteId){continue;}
            $rows[]=$data; if(count($rows)>=$limit){break;}
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(string $importId): ?array
    {
        if(!preg_match('/^[A-Za-z0-9._-]+$/',$importId)){return null;}
        $file=$this->root().'/'.$importId.'/import-report.json';
        if(!is_file($file)){return null;}
        $data=json_decode((string)file_get_contents($file),true);
        return is_array($data)?$data:null;
    }

    public function delete(string $importId): bool
    {
        if(!preg_match('/^[A-Za-z0-9._-]+$/',$importId)){return false;}
        $dir=$this->root().'/'.$importId; if(!is_dir($dir)){return false;}
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        $ok=true;
        foreach($it as $f){$removed=$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());if(!$removed){$ok=false;}}
        if(!@rmdir($dir)){$ok=false;}
        $root=$this->root();if(is_dir($root) && (scandir($root)?:[])===['.','..']){@rmdir($root);}
        return $ok && !is_dir($dir);
    }
}
