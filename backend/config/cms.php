<?php

return [
    'default_site_key' => 'main',
    'default_theme' => 'default',
    'preview_ttl_minutes' => 60,
    'autosave_interval_seconds' => 25,
    'published_statuses' => ['published'],
    'editorial_security' => [
        'iframe_allowed_hosts' => ['www.youtube.com', 'youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com', 'www.openstreetmap.org', 'openstreetmap.org'],
        'allowed_url_schemes' => ['http', 'https', 'mailto', 'tel'],
        // data: est refusé par défaut, sauf types MIME ajoutés ici explicitement.
        'allowed_data_mime_types' => [],
        // Laisser vide pour utiliser la CSP native stricte du CMS.
        'content_security_policy' => '',
    ],
];
