<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialImportStagingService
{
    public function __construct(private readonly string $root = '', private readonly int $ttlSeconds = 1800) {}

    private function root(): string
    {
        return $this->root !== '' ? $this->root : base_path('storage/imports/editorial-staging');
    }

    /** @param array<string,mixed> $plan */
    public function retain(string $extractedRoot, int $userId, int $siteId, array $plan): string
    {
        $this->purgeExpired();
        $token = bin2hex(random_bytes(32));
        $dir = $this->root() . '/' . $token;
        $parent = dirname($dir);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new \RuntimeException('Stockage temporaire indisponible.');
        }
        if (!@rename($extractedRoot, $dir)) {
            $this->copyTree($extractedRoot, $dir);
            $this->removeTree($extractedRoot);
        }
        $this->writeMetadata($dir, [
            'user_id' => $userId,
            'site_id' => $siteId,
            'created_at' => time(),
            'expires_at' => time() + $this->ttlSeconds,
            'state' => 'ready',
            'attempts' => 0,
            'plan' => $plan,
        ]);
        return $token;
    }

    /**
     * Claims a staged package for one execution attempt.
     * A failed business execution may release it for another attempt until expiry.
     *
     * @return array{root:string,plan:array<string,mixed>}
     */
    public function claim(string $token, int $userId, int $siteId): array
    {
        [$dir, $meta] = $this->validatedMetadata($token, $userId, $siteId);
        if ((string) ($meta['state'] ?? 'ready') === 'running') {
            throw new \InvalidArgumentException('Jeton d’import déjà utilisé par une exécution en cours.');
        }
        $meta['state'] = 'running';
        $meta['claimed_at'] = time();
        $meta['attempts'] = (int) ($meta['attempts'] ?? 0) + 1;
        $this->writeMetadata($dir, $meta);
        return ['root' => $dir, 'plan' => (array) ($meta['plan'] ?? [])];
    }

    /** @deprecated Use claim(). @return array{root:string,plan:array<string,mixed>} */
    public function consume(string $token, int $userId, int $siteId): array
    {
        return $this->claim($token, $userId, $siteId);
    }

    public function release(string $token, int $userId, int $siteId, ?string $reason = null): void
    {
        try {
            [$dir, $meta] = $this->validatedMetadata($token, $userId, $siteId);
        } catch (\Throwable) {
            return;
        }
        $meta['state'] = 'ready';
        $meta['released_at'] = time();
        if ($reason !== null && $reason !== '') {
            $meta['last_error'] = mb_substr($reason, 0, 1000);
        }
        $this->writeMetadata($dir, $meta);
    }

    public function complete(string $token, int $userId, int $siteId): void
    {
        [$dir] = $this->validatedMetadata($token, $userId, $siteId);
        $this->removeTree($dir);
    }

    public function cleanup(string $root): void
    {
        $this->removeTree($root);
    }

    public function purgeExpired(): void
    {
        foreach (glob($this->root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = $this->readMetadata($dir);
            if (!is_array($meta) || (int) ($meta['expires_at'] ?? 0) < time()) {
                $this->removeTree($dir);
            }
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function validatedMetadata(string $token, int $userId, int $siteId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new \InvalidArgumentException('Jeton d’import invalide.');
        }
        $dir = $this->root() . '/' . $token;
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException('Jeton d’import introuvable ou déjà consommé.');
        }
        $meta = $this->readMetadata($dir);
        if (!is_array($meta)) {
            throw new \InvalidArgumentException('Métadonnées du jeton d’import absentes ou illisibles.');
        }
        if ((int) ($meta['expires_at'] ?? 0) < time()) {
            $this->removeTree($dir);
            throw new \InvalidArgumentException('Jeton d’import expiré.');
        }
        if ((int) ($meta['user_id'] ?? 0) !== $userId) {
            throw new \InvalidArgumentException('Jeton d’import non autorisé pour cet utilisateur.');
        }
        if ((int) ($meta['site_id'] ?? 0) !== $siteId) {
            throw new \InvalidArgumentException('Jeton d’import non autorisé pour ce site cible.');
        }
        return [$dir, $meta];
    }

    /** @return array<string,mixed>|null */
    private function readMetadata(string $dir): ?array
    {
        $file = $dir . '/.staging.json';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $metadata */
    private function writeMetadata(string $dir, array $metadata): void
    {
        $file = $dir . '/.staging.json';
        $tmp = $dir . '/.staging.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Impossible d’écrire les métadonnées du jeton d’import.');
        }
        @chmod($file, 0600);
    }

    private function copyTree(string $src, string $dst): void
    {
        if (!is_dir($dst) && !mkdir($dst, 0700, true) && !is_dir($dst)) {
            throw new \RuntimeException('Impossible de préparer le stockage temporaire.');
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $target = $dst . '/' . substr($file->getPathname(), strlen($src) + 1);
            if ($file->isDir()) {
                if (!is_dir($target)) { @mkdir($target, 0700, true); }
            } elseif (!copy($file->getPathname(), $target)) {
                throw new \RuntimeException('Copie temporaire incomplète.');
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
        $root=$this->root();
        if(is_dir($root) && (scandir($root)?:[])===['.','..']){@rmdir($root);}
    }
}
