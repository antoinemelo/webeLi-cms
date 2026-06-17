<?php

declare(strict_types=1);

namespace App\EditorialPackage;

use ZipArchive;

/** Secure, read-only inspection of an uploaded Editorial Package ZIP. */
final class EditorialImportArchiveInspector
{
    private const ALLOWED_EXTENSIONS = ['json','png','jpg','jpeg','gif','webp','svg','avif','pdf','txt','csv','xml'];
    private const FORBIDDEN_EXTENSIONS = ['php','phtml','phar','cgi','pl','py','rb','sh','bash','exe','dll','so','dylib','com','bat','cmd','js','mjs','cjs','html','htm'];

    public function __construct(
        private readonly EditorialPackageValidator $validator = new EditorialPackageValidator(),
        private readonly int $maxArchiveBytes = 52428800,
        private readonly int $maxFiles = 2000,
        private readonly int $maxUncompressedBytes = 268435456,
    ) {}

    /** @return array{root:string,cleanup:callable():void,manifest:array<string,mixed>} */
    public function inspect(string $uploadedPath, string $originalName = ''): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new EditorialImportInspectionException('Inspection ZIP indisponible : extension ZipArchive absente.');
        }
        if (!is_file($uploadedPath) || !is_readable($uploadedPath)) {
            throw new EditorialImportInspectionException('Archive absente ou illisible.');
        }
        $size = filesize($uploadedPath);
        if ($size === false || $size <= 0) { throw new EditorialImportInspectionException('Archive vide.'); }
        if ($size > $this->maxArchiveBytes) { throw new EditorialImportInspectionException('Archive trop volumineuse.'); }
        if ($originalName !== '' && strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new EditorialImportInspectionException('Seules les archives ZIP sont acceptées.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($uploadedPath, ZipArchive::RDONLY);
        if ($opened !== true) { throw new EditorialImportInspectionException('Archive corrompue ou format ZIP invalide.'); }
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'amcms-editorial-inspect-' . bin2hex(random_bytes(12));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) { $zip->close(); throw new EditorialImportInspectionException('Impossible de créer le répertoire temporaire sécurisé.'); }

        try {
            if ($zip->numFiles > $this->maxFiles) { throw new EditorialImportInspectionException('Archive refusée : nombre maximal de fichiers dépassé.'); }
            $total = 0;
            $names = [];
            $normalizedNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) { throw new EditorialImportInspectionException('Archive corrompue : entrée ZIP illisible.'); }
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $opsys = 0; $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes, ZipArchive::FL_UNCHANGED)) { $stat['external_attributes'] = $attributes; }
                $this->assertSafeEntry($name, $stat);
                $normalizedName=$this->normalizedArchiveName($name);
                if(isset($normalizedNames[$normalizedName])){throw new EditorialImportInspectionException('Archive refusée : collision de noms Unicode ou de casse pour '.$name.'.');}
                $normalizedNames[$normalizedName]=true;
                if (isset($names[$name])) { throw new EditorialImportInspectionException('Archive refusée : chemin dupliqué ' . $name . '.'); }
                $names[$name] = true;
                $total += max(0, (int) ($stat['size'] ?? 0));
                if ($total > $this->maxUncompressedBytes) { throw new EditorialImportInspectionException('Archive refusée : taille décompressée maximale dépassée.'); }
            }
            if (!isset($names['editorial-package.json'])) {
                throw new EditorialImportInspectionException('Archive non éditoriale : editorial-package.json est absent.');
            }
            foreach (array_keys($names) as $name) {
                if (str_ends_with($name, '/')) { continue; }
                $target = $tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new EditorialImportInspectionException('Impossible de préparer l’extraction sécurisée.');
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) { throw new EditorialImportInspectionException('Archive corrompue : lecture impossible de ' . $name . '.'); }
                $out = fopen($target, 'xb');
                if (!is_resource($out)) { fclose($stream); throw new EditorialImportInspectionException('Collision de fichier lors de l’extraction.'); }
                stream_copy_to_stream($stream, $out);
                fclose($stream); fclose($out); @chmod($target, 0600);
                $this->assertMime($target, $name);
                if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='svg'){$this->assertSafeSvg($target,$name);}
            }
        } catch (\Throwable $e) {
            $zip->close(); $this->removeDirectory($tmp);
            throw $e;
        }
        $zip->close();

        $errors = $this->validator->validateDirectory($tmp);
        if ($errors !== []) {
            $this->removeDirectory($tmp);
            throw new EditorialImportInspectionException('Editorial Package invalide.', $errors);
        }
        $manifest = EditorialPackageJson::decode((string) file_get_contents($tmp . '/editorial-package.json'));
        return ['root' => $tmp, 'manifest' => $manifest, 'cleanup' => fn() => $this->removeDirectory($tmp)];
    }

    /** @param array<string,mixed> $stat */
    private function assertSafeEntry(string $name, array $stat): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:/~', $name)) {
            throw new EditorialImportInspectionException('Archive refusée : chemin absolu ou invalide.');
        }
        $pathForParts = rtrim($name, '/');
        foreach (explode('/', $pathForParts) as $part) {
            if ($part === '..' || $part === '') { throw new EditorialImportInspectionException('Archive refusée : chemin Zip Slip détecté.'); }
        }
        $mode = ((int) ($stat['external_attributes'] ?? 0)) >> 16;
        if (($mode & 0170000) === 0120000) { throw new EditorialImportInspectionException('Archive refusée : lien symbolique détecté.'); }
        if (str_ends_with($name, '/')) { return; }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::FORBIDDEN_EXTENSIONS, true) || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new EditorialImportInspectionException('Archive refusée : type de fichier interdit (' . basename($name) . ').');
        }
    }

    private function assertMime(string $path, string $name): void
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = class_exists(\finfo::class) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : null;
        if (!is_string($mime) || $mime === '') { return; }
        $allowed = match ($ext) {
            'json' => ['application/json','text/plain'], 'png' => ['image/png'], 'jpg','jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'], 'webp' => ['image/webp','application/octet-stream'], 'svg' => ['image/svg+xml','text/xml','application/xml','text/plain'],
            'avif' => ['image/avif','application/octet-stream'], 'pdf' => ['application/pdf'], 'txt','csv' => ['text/plain','text/csv'], 'xml' => ['application/xml','text/xml','text/plain'], default => [],
        };
        if ($allowed !== [] && !in_array($mime, $allowed, true)) {
            throw new EditorialImportInspectionException('Archive refusée : type MIME incohérent pour ' . basename($name) . '.');
        }
    }

    private function normalizedArchiveName(string $name): string
    {
        $normalized=class_exists(\Normalizer::class)?\Normalizer::normalize($name,\Normalizer::FORM_C):$name;
        return mb_strtolower((string)$normalized,'UTF-8');
    }

    private function assertSafeSvg(string $path,string $name): void
    {
        $content=(string)file_get_contents($path);
        if(preg_match('/<(script|foreignObject|iframe|object|embed)\b|\son[a-z]+\s*=|(?:href|xlink:href)\s*=\s*["\']\s*(?:javascript:|https?:|\/\/)/iu',$content)){
            throw new EditorialImportInspectionException('Archive refusée : SVG actif ou externe interdit (' . basename($name) . ').');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($directory);
    }
}
