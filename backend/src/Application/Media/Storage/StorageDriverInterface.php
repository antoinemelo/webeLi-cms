<?php

declare(strict_types=1);

namespace App\Application\Media\Storage;

interface StorageDriverInterface
{
    public function disk(): string;

    public function isConfigured(): bool;

    /** @return array<string,mixed> */
    public function preflight(): array;

    public function putFile(string $relativePath, string $absoluteSourcePath, string $mimeType = 'application/octet-stream'): void;

    /** @return resource */
    public function readStream(string $relativePath);

    public function exists(string $relativePath): bool;

    public function delete(string $relativePath): void;

    public function publicUrl(string $relativePath): string;
}
