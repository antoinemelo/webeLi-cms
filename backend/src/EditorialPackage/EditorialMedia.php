<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialMedia
{
    /** @param array<string,mixed> $metadata @param array<string,string> $altTexts @param list<string> $references */
    public function __construct(
        public readonly string $mediaKey,
        public readonly string $path,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $sha256,
        public readonly array $metadata = [],
        public readonly array $altTexts = [],
        public readonly array $references = [],
    ) {
        new PortableKey($mediaKey);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Chemin média non portable.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) { throw new \InvalidArgumentException('SHA-256 média invalide.'); }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['media_key'=>$this->mediaKey,'path'=>$this->path,'filename'=>$this->filename,'mime_type'=>$this->mimeType,'size'=>$this->size,'sha256'=>$this->sha256,'metadata'=>$this->metadata,'alt_texts'=>$this->altTexts,'references'=>array_values($this->references)];
    }
}
