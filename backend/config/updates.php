<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('APP_UPDATES_ENABLED', true),
    'http_timeout_seconds' => (int) env('APP_UPDATES_HTTP_TIMEOUT', 4),
    'github_repo' => (string) env('APP_UPDATES_GITHUB_REPO', 'antoinemelo/webeLi-cms'),
    'channels' => [
        'dev' => [
            'label' => 'Dev',
            'instance_url' => (string) env('APP_UPDATES_DEV_INSTANCE_URL', 'https://webe.li/mod'),
            'manifest_url' => (string) env('APP_UPDATES_DEV_MANIFEST_URL', 'https://webe.li/mod/updates/manifest.json'),
            'git_branch' => (string) env('APP_UPDATES_DEV_GIT_BRANCH', 'staging'),
        ],
        'stable' => [
            'label' => 'Stable',
            'instance_url' => (string) env('APP_UPDATES_STABLE_INSTANCE_URL', 'https://webe.li/maj'),
            'manifest_url' => (string) env('APP_UPDATES_STABLE_MANIFEST_URL', 'https://webe.li/maj/updates/manifest.json'),
            'git_branch' => (string) env('APP_UPDATES_STABLE_GIT_BRANCH', 'main'),
        ],
    ],
];
