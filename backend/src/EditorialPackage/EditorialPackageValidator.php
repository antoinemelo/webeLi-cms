<?php

declare(strict_types=1);

namespace App\EditorialPackage;

use App\Application\Content\BlockDocumentNormalizer;
use App\EditorialPackage\Exception\EditorialPackageValidationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EditorialPackageValidator
{
    /** @return list<string> */
    public function validateDirectory(string $directory): array
    {
        $errors = [];
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        foreach (['editorial-package.json','site.json','blueprints','entries','media/manifest.json'] as $required) {
            if (!file_exists($root . DIRECTORY_SEPARATOR . $required)) { $errors[] = 'Élément obligatoire absent : ' . $required; }
        }
        if ($errors !== []) { return $errors; }

        $manifest = $this->readObject($root . '/editorial-package.json', $errors);
        $site = $this->readObject($root . '/site.json', $errors);
        if ($manifest === null || $site === null) { return $errors; }

        $this->validateManifest($manifest, $errors);
        $siteKey = $site['site_key'] ?? null;
        if (!PortableKey::isValid($siteKey)) { $errors[] = 'site.json : site_key portable invalide.'; }
        if (($manifest['source_site_key'] ?? null) !== $siteKey) { $errors[] = 'Le site source du manifeste ne correspond pas à site.json.'; }

        $declared = [];
        foreach (($manifest['files'] ?? []) as $index => $file) {
            if (!is_array($file)) { $errors[] = "Manifest files[$index] invalide."; continue; }
            $relative = $file['path'] ?? null;
            if (!$this->isPortablePath($relative)) { $errors[] = "Manifest files[$index].path non portable."; continue; }
            if (isset($declared[$relative])) { $errors[] = 'Fichier déclaré deux fois : ' . $relative; }
            $declared[$relative] = true;
            $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($absolute)) { $errors[] = 'Fichier déclaré mais absent : ' . $relative; continue; }
            $actualHash = hash_file('sha256', $absolute);
            if (!hash_equals((string) ($file['sha256'] ?? ''), $actualHash)) { $errors[] = 'Hash incorrect : ' . $relative; }
            if ((int) ($file['size'] ?? -1) !== (filesize($absolute) ?: 0)) { $errors[] = 'Taille incorrecte : ' . $relative; }
        }

        $actualFiles=[];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $path) {
            if(!$path->isFile()){continue;}
            $relative=str_replace('\\','/',substr($path->getPathname(),strlen($root)+1));
            if($relative!=='editorial-package.json'){$actualFiles[$relative]=true;}
        }
        foreach(array_keys($actualFiles) as $relative){if(!isset($declared[$relative])){$errors[]='Fichier non déclaré dans le manifeste : '.$relative;}}
        foreach(array_keys($declared) as $relative){if(!isset($actualFiles[$relative])){$errors[]='Fichier déclaré sans présence réelle : '.$relative;}}

        $forbiddenNames = ['users','roles','permissions','sessions','secrets','audit','history','revisions'];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $path) {
            if (!$path->isFile()) { continue; }
            $relative = str_replace('\\', '/', substr($path->getPathname(), strlen($root) + 1));
            $lower = strtolower($relative);
            foreach ($forbiddenNames as $forbidden) {
                if (preg_match('~(^|[/_.-])' . preg_quote($forbidden, '~') . '([/_.-]|$)~', $lower)) { $errors[] = 'Donnée interdite détectée : ' . $relative; break; }
            }
        }

        $blueprintKeys = [];
        foreach ($this->jsonFiles($root . '/blueprints') as $path) {
            $blueprint = $this->readObject($path, $errors);
            if ($blueprint === null) { continue; }
            $key = $blueprint['blueprint_key'] ?? null;
            if (!PortableKey::isValid($key)) { $errors[] = basename($path) . ' : blueprint_key invalide.'; continue; }
            if (isset($blueprintKeys[$key])) { $errors[] = 'Blueprint portable dupliqué : ' . $key; }
            $blueprintKeys[$key] = true;
            if (($blueprint['status'] ?? null) !== 'published' || ($blueprint['is_active'] ?? null) !== true) { $errors[] = $key . ' : seul le blueprint actif publié est autorisé.'; }
            if (!is_array($blueprint['definition'] ?? null)) { $errors[] = $key . ' : définition complète absente.'; }
        }

        $mediaManifest = $this->readObject($root . '/media/manifest.json', $errors) ?? [];
        $mediaKeys = [];
        foreach (($mediaManifest['items'] ?? []) as $index => $media) {
            if (!is_array($media)) { $errors[] = "media.items[$index] invalide."; continue; }
            $key = $media['media_key'] ?? null;
            if (!PortableKey::isValid($key)) { $errors[] = "media.items[$index].media_key invalide."; continue; }
            if (isset($mediaKeys[$key])) { $errors[] = 'Média portable dupliqué : ' . $key; }
            $mediaKeys[$key] = true;
            $path = $media['path'] ?? null;
            if (!$this->isPortablePath($path)) { $errors[] = $key . ' : chemin média non portable.'; continue; }
            $absolute = $root . '/' . $path;
            if (!is_file($absolute)) { $errors[] = $key . ' : fichier média absent.'; continue; }
            if (!hash_equals((string) ($media['sha256'] ?? ''), hash_file('sha256', $absolute))) { $errors[] = $key . ' : hash média incorrect.'; }
            if ((int)($media['size']??-1)!==(filesize($absolute)?:0)){$errors[]=$key.' : taille média incorrecte.';}
            if(!is_string($media['mime_type']??null)||trim((string)$media['mime_type'])===''){$errors[]=$key.' : type MIME absent.';}
        }

        $entryKeys = [];
        $relationRefs = [];
        $languages = array_fill_keys(is_array($manifest['languages'] ?? null) ? $manifest['languages'] : [], true);
        foreach ($this->jsonFiles($root . '/entries') as $path) {
            $entry = $this->readObject($path, $errors);
            if ($entry === null) { continue; }
            $key = $entry['entry_key'] ?? null;
            if (!PortableKey::isValid($key)) { $errors[] = basename($path) . ' : entry_key invalide.'; continue; }
            if (isset($entryKeys[$key])) { $errors[] = 'Entrée portable dupliquée : ' . $key; }
            $entryKeys[$key] = true;
            if (($entry['status'] ?? null) !== 'published') { $errors[] = $key . ' : brouillon ou état non publié interdit.'; }
            $blueprintKey = $entry['blueprint_key'] ?? null;
            if (!is_string($blueprintKey) || !isset($blueprintKeys[$blueprintKey])) { $errors[] = $key . ' : blueprint manquant ' . (string) $blueprintKey; }
            if (($entry['source_site_key'] ?? null) !== $siteKey) { $errors[] = $key . ' : site source incohérent.'; }
            foreach (($entry['localizations'] ?? []) as $localization) {
                if (!is_array($localization)) { $errors[] = $key . ' : localisation invalide.'; continue; }
                $language = $localization['language'] ?? null;
                if (!is_string($language) || !isset($languages[$language])) { $errors[] = $key . ' : langue incohérente ' . (string) $language; }
                $route = $localization['route'] ?? null;
                if (!is_string($route) || !$this->isPublicRoute($route)) { $errors[] = $key . ' : route publique invalide.'; }
                if (!PortableKey::isValid($localization['localization_key'] ?? null)) { $errors[] = $key . ' : localization_key invalide.'; }
            }
            foreach ($this->walkBlocks(is_array($entry['blocks'] ?? null) ? $entry['blocks'] : []) as $block) {
                if (!PortableKey::isValid($block['block_key'] ?? null)) { $errors[] = $key . ' : block_key invalide.'; }
                if (!in_array((string) ($block['type'] ?? ''), BlockDocumentNormalizer::supportedTypes(), true)) { $errors[] = $key . ' : type de bloc inconnu ' . (string) ($block['type'] ?? ''); }
                if (($block['editorial_status'] ?? 'published') !== 'published') { $errors[] = $key . ' : bloc non publié interdit.'; }
            }
            foreach (($entry['media_keys'] ?? []) as $mediaKey) {
                if (!is_string($mediaKey) || !isset($mediaKeys[$mediaKey])) { $errors[] = $key . ' : référence média cassée ' . (string) $mediaKey; }
            }
            if (!is_array($entry['seo'] ?? null)) { $errors[] = $key . ' : données SEO sources absentes.'; }
            $localizedContent=is_array($entry['localized_content']??null)?$entry['localized_content']:[];
            foreach($localizedContent as $language=>$payload){
                if(!isset($languages[$language])||!is_array($payload)){$errors[]=$key.' : contenu localisé invalide pour '.(string)$language;continue;}
                if(!is_array($payload['fields']??null)||!is_array($payload['blocks']??null)||!is_array($payload['seo']??null)){$errors[]=$key.' : contenu localisé incomplet pour '.$language;}
                foreach($this->walkBlocks(is_array($payload['blocks']??null)?$payload['blocks']:[]) as $block){
                    if(!PortableKey::isValid($block['block_key']??null)){$errors[]=$key.' : block_key localisé invalide.';}
                    if(!in_array((string)($block['type']??''),BlockDocumentNormalizer::supportedTypes(),true)){$errors[]=$key.' : type de bloc localisé inconnu '.(string)($block['type']??'');}
                }
                foreach((array)($payload['media_keys']??[]) as $mediaKey){if(!is_string($mediaKey)||!isset($mediaKeys[$mediaKey])){$errors[]=$key.' : référence média localisée cassée '.(string)$mediaKey;}}
                if($this->containsScriptClosingSequence($payload['seo']??[])){$errors[]=$key.' : données SEO structurées dangereuses pour '.$language;}
            }
            if($this->containsScriptClosingSequence($entry['seo']??[])){$errors[]=$key.' : données SEO structurées dangereuses.';}
            foreach((array)($entry['relations']??[]) as $relation){if(!is_array($relation)){continue;}$target=(string)($relation['target_entry_key']??'');if(!PortableKey::isValid($target)){$errors[]=$key.' : relation cible invalide.';}else{$relationRefs[]=['source'=>$key,'target'=>$target];}}
        }

        $counts = $manifest['counts'] ?? [];
        if ((int) ($counts['entries'] ?? -1) !== count($entryKeys)) { $errors[] = 'Compte entries incohérent.'; }
        if ((int) ($counts['blueprints'] ?? -1) !== count($blueprintKeys)) { $errors[] = 'Compte blueprints incohérent.'; }
        if ((int) ($counts['media'] ?? -1) !== count($mediaKeys)) { $errors[] = 'Compte media incohérent.'; }
        return array_values(array_unique($errors));
    }

    public function assertValidDirectory(string $directory): void
    {
        $errors = $this->validateDirectory($directory);
        if ($errors !== []) { throw new EditorialPackageValidationException($errors); }
    }

    private function validateManifest(array $manifest, array &$errors): void
    {
        if (($manifest['format'] ?? null) !== EditorialPackageManifest::FORMAT) { $errors[] = 'Nom de format absent ou invalide.'; }
        if (($manifest['format_version'] ?? null) !== EditorialPackageManifest::VERSION) { $errors[] = 'Version absente ou incompatible.'; }
        if (!is_string($manifest['minimum_cms_version'] ?? null) || trim((string)$manifest['minimum_cms_version'])==='') { $errors[] = 'minimum_cms_version absent ou invalide.'; }
        if (($manifest['hash_algorithm'] ?? null) !== 'sha256') { $errors[] = 'Algorithme de hash non supporté.'; }
        if (($manifest['published_only'] ?? null) !== true) { $errors[] = 'published_only doit valoir true.'; }
        if (($manifest['contains_drafts'] ?? null) !== false) { $errors[] = 'contains_drafts doit valoir false.'; }
        if (($manifest['contains_history'] ?? null) !== false) { $errors[] = 'contains_history doit valoir false.'; }
        if (!PortableKey::isValid($manifest['source_site_key'] ?? null)) { $errors[] = 'source_site_key invalide.'; }
        if (!in_array($manifest['scope']['type'] ?? null, ['page','language','site'], true)) { $errors[] = 'Périmètre invalide.'; }
        $date = $manifest['generated_at'] ?? null;
        if (!is_string($date) || !preg_match('/Z$/', $date) || strtotime($date) === false) { $errors[] = 'Date UTC generated_at invalide.'; }
    }

    private function readObject(string $path, array &$errors): ?array
    {
        try { $data = EditorialPackageJson::decode((string) file_get_contents($path)); }
        catch (\Throwable $e) { $errors[] = basename($path) . ' : JSON invalide (' . $e->getMessage() . ').'; return null; }
        if (array_is_list($data)) { $errors[] = basename($path) . ' : objet JSON attendu.'; return null; }
        return $data;
    }
    /** @return list<string> */
    private function jsonFiles(string $directory): array
    {
        if (!is_dir($directory)) { return []; }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $path) {
            if ($path->isFile() && strtolower($path->getExtension()) === 'json') { $files[] = $path->getPathname(); }
        }
        sort($files, SORT_STRING); return $files;
    }
    private function isPortablePath(mixed $path): bool { return is_string($path) && $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..') && !str_contains($path, '\\'); }
    private function isPublicRoute(string $route): bool { return str_starts_with($route, '/') && !str_contains($route, '..') && !preg_match('~^/(?:admin|api|preview)(?:/|$)~', $route); }
    private function containsScriptClosingSequence(mixed $value): bool
    {
        if(is_string($value)){return stripos($value,'</script')!==false || stripos($value,'javascript:')!==false;}
        if(!is_array($value)){return false;}
        foreach($value as $child){if($this->containsScriptClosingSequence($child)){return true;}}
        return false;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function walkBlocks(array $blocks): array
    {
        $all = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) { continue; }
            $all[] = $block;
            if (($block['type'] ?? null) === 'columns') {
                foreach (($block['data']['columns'] ?? []) as $column) { if (is_array($column)) { $all = array_merge($all, $this->walkBlocks(is_array($column['blocks'] ?? null) ? $column['blocks'] : [])); } }
            }
        }
        return $all;
    }
}
