<?php

declare(strict_types=1);

namespace App\EditorialPackage;

/** Sérialise un répertoire Editorial Package déterministe. Ne crée pas encore l'export depuis la DB. */
final class EditorialPackageWriter
{
    /** @param array<string,array<string,mixed>> $documents chemins relatifs => documents JSON */
    public function write(string $directory, array $documents, EditorialPackageManifest $manifest): void
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) { throw new EditorialPackageException('Impossible de créer le répertoire du paquet.'); }
        foreach ($documents as $relative => $document) {
            $this->assertRelativePath($relative);
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) { throw new EditorialPackageException('Impossible de créer un sous-répertoire du paquet.'); }
            file_put_contents($path, EditorialPackageJson::encode($document), LOCK_EX);
        }
        file_put_contents($root . '/editorial-package.json', EditorialPackageJson::encode($manifest->toArray()), LOCK_EX);
    }

    public static function fileDescriptor(string $root, string $relative): array
    {
        $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) { throw new EditorialPackageException('Fichier absent : ' . $relative); }
        return ['path'=>$relative,'sha256'=>hash_file('sha256', $path),'size'=>filesize($path) ?: 0];
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) { throw new EditorialPackageException('Chemin de paquet non portable : ' . $path); }
    }
}
