<?php

declare(strict_types=1);

namespace App\Application\Support;

final class ContentPathBuilder
{
    public function build(string $typeKey, string $slug): string
    {
        $typeKey = $this->slugify($typeKey);
        $slug = $this->slugify($slug);
        if ($typeKey === 'page' && in_array($slug, ['home', 'accueil'], true)) {
            return '/';
        }
        if ($typeKey === 'page') {
            return '/' . $slug;
        }
        return '/' . $typeKey . 's/' . $slug;
    }

    public function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('~[^\pL\d]+~u', '-', $value) ?? $value;
        $value = iconv('utf-8', 'us-ascii//TRANSLIT', $value) ?: $value;
        $value = preg_replace('~[^-\w]+~', '', $value) ?? $value;
        $value = trim($value, '-');
        return $value !== '' ? $value : 'item-' . time();
    }
}
