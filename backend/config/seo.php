<?php

return [
    'default_meta_robots' => 'index,follow',
    'social_defaults' => [
        'twitter_card' => 'summary_large_image',
    ],
    'schema_enabled' => true,
    'templates_enabled' => true,
    'governance' => [
        'publication_mode' => 'warn_unless_invalid_projection',
        'block_only' => ['invalid_json_ld', 'invalid_canonical_path', 'missing_renderable_content'],
        'score_thresholds' => [
            'excellent' => 85,
            'good' => 70,
            'warning' => 50,
        ],
    ],
    'content_type_rules' => [
        'page' => [
            'min_words' => 120,
            'json_ld_types' => ['WebPage', 'BreadcrumbList'],
            'robots_default' => 'index,follow',
        ],
        'article' => [
            'min_words' => 250,
            'json_ld_types' => ['Article', 'BreadcrumbList'],
            'robots_default' => 'index,follow',
            'require_publication_date' => true,
        ],
    ],
    'audit_rules' => [
        'require_title' => true,
        'require_meta_description' => true,
        'warn_if_meta_description_under' => 80,
        'warn_if_title_under' => 25,
        'warn_if_slug_shorter_than' => 3,
        'warn_if_content_words_under' => 250,
    ],
];
