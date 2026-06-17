<?php

declare(strict_types=1);

namespace App\StaticExport;

final class StaticExportManifestWriter
{
    /** @param array<string,mixed> $report */
    public function write(string $releaseDir, StaticExportManifest $manifest, array $report): void
    {
        $this->ensureDirectory($releaseDir);
        $this->writeJson($releaseDir . '/static-export-manifest.json', $manifest->toArray());
        $this->writeJson($releaseDir . '/static-export-report.json', $report);
    }

    /** @param array<string,mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Impossible d’encoder le JSON: ' . $path);
        }
        file_put_contents($path, $json . "\n");
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier: ' . $dir);
        }
    }
}
