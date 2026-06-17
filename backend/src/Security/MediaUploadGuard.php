<?php

declare(strict_types=1);

namespace App\Security;

final class MediaUploadGuard
{
    /**
     * SVG is intentionally excluded for V1: sanitizing SVG safely is a separate
     * hard problem. Prefer raster images or PDF until a dedicated sanitizer exists.
     */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/pdf' => 'pdf',
    ];

    private const MAX_IMAGE_PIXELS = 24000000;

    /** @param list<string>|null $allowedMimeTypes @return array{tmp:string,size:int,mime:string,extension:string,original:string} */
    public static function validate(array $file, int $maxBytes = 16777216, ?array $allowedMimeTypes = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('UPLOAD_ERROR');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
            throw new \RuntimeException('UPLOAD_INVALID_SOURCE');
        }
        $real = realpath($tmp);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('UPLOAD_INVALID_SOURCE');
        }
        $size = (int) ($file['size'] ?? filesize($real));
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException('UPLOAD_SIZE_INVALID');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($real));
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new \RuntimeException('UPLOAD_MIME_FORBIDDEN');
        }
        $allowedMimeTypes = $allowedMimeTypes === null ? array_keys(self::ALLOWED_MIME) : array_values(array_unique(array_map('strtolower', $allowedMimeTypes)));
        if (!in_array($mime, $allowedMimeTypes, true)) {
            throw new \RuntimeException('UPLOAD_MIME_FORBIDDEN_BY_SITE_POLICY');
        }

        $original = basename((string) ($file['name'] ?? 'upload'));
        $safe = trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $original) ?: 'upload', '.-_');
        if (substr_count($safe, '.') > 1 && preg_match('/\.(php[0-9]?|phtml|phar|cgi|pl|exe|sh|bat|cmd)\./i', $safe . '.') === 1) {
            throw new \RuntimeException('UPLOAD_FILENAME_FORBIDDEN');
        }
        if ($safe === '') {
            $safe = 'upload';
        }
        $originalExtension = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        $expectedExtension = self::ALLOWED_MIME[$mime];
        $allowedExtensions = match ($expectedExtension) {
            'jpg' => ['jpg', 'jpeg'],
            'm4a' => ['m4a', 'mp4'],
            'ogg' => ['ogg', 'oga'],
            default => [$expectedExtension],
        };
        if ($originalExtension !== '' && !in_array($originalExtension, $allowedExtensions, true)) {
            throw new \RuntimeException('UPLOAD_EXTENSION_MISMATCH');
        }

        if (str_starts_with($mime, 'image/')) {
            $imageSize = @getimagesize($real);
            if (!is_array($imageSize)) {
                throw new \RuntimeException('UPLOAD_IMAGE_INVALID');
            }
            $width = (int) ($imageSize[0] ?? 0);
            $height = (int) ($imageSize[1] ?? 0);
            if ($width <= 0 || $height <= 0 || ($width * $height) > self::MAX_IMAGE_PIXELS) {
                throw new \RuntimeException('UPLOAD_IMAGE_DIMENSIONS_INVALID');
            }
        }

        if ($mime === 'application/pdf') {
            $header = (string) file_get_contents($real, false, null, 0, 5);
            if ($header !== '%PDF-') {
                throw new \RuntimeException('UPLOAD_PDF_INVALID');
            }
        }

        $head = (string) file_get_contents($real, false, null, 0, min(4096, $size));
        if (preg_match('/<\?(php|=)|<script\b/i', $head) === 1 && !in_array($mime, ['application/pdf'], true)) {
            throw new \RuntimeException('UPLOAD_ACTIVE_CONTENT_FORBIDDEN');
        }

        return ['tmp' => $real, 'size' => $size, 'mime' => $mime, 'extension' => $expectedExtension, 'original' => $safe];
    }
}
