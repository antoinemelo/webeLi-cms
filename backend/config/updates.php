<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('APP_UPDATES_ENABLED', true),
    'http_timeout_seconds' => (int) env('APP_UPDATES_HTTP_TIMEOUT', 4),
    'github_repo' => (string) env('APP_UPDATES_GITHUB_REPO', 'antoinemelo/webeLi-cms'),
    'stable_updater' => [
        'enabled' => (bool) env('APP_STABLE_UPDATER_ENABLED', true),
        'github_api_url' => (string) env(
            'APP_STABLE_UPDATER_GITHUB_API_URL',
            'https://api.github.com/repos/antoinemelo/webeLi-cms/releases/latest'
        ),
        'catalog_asset_name' => (string) env('APP_STABLE_UPDATER_CATALOG_ASSET', 'dec-cms-stable.json'),
        'cache_ttl_seconds' => (int) env('APP_STABLE_UPDATER_CACHE_TTL', 900),
        'max_catalog_bytes' => (int) env('APP_STABLE_UPDATER_MAX_CATALOG_BYTES', 2000000),
        'max_archive_bytes' => (int) env('APP_STABLE_UPDATER_MAX_ARCHIVE_BYTES', 150000000),
    ],
    'dependencies' => [
        'latest_enabled' => (bool) env('APP_DEPENDENCIES_LATEST_ENABLED', true),
        'http_timeout_seconds' => (int) env('APP_DEPENDENCIES_HTTP_TIMEOUT', 1),
        'latest_request_budget_seconds' => (float) env('APP_DEPENDENCIES_LATEST_BUDGET', 3),
        'refresh_http_timeout_seconds' => (int) env('APP_DEPENDENCIES_REFRESH_HTTP_TIMEOUT', 6),
        'refresh_request_budget_seconds' => (float) env('APP_DEPENDENCIES_REFRESH_BUDGET', 45),
        'latest_cache_ttl_seconds' => (int) env('APP_DEPENDENCIES_LATEST_CACHE_TTL', 43200),
    ],
    'channels' => [
        'dev' => [
            'label' => 'Dev',
            'instance_url' => (string) env('APP_UPDATES_DEV_INSTANCE_URL', 'https://webe.li/cms'),
            'manifest_url' => (string) env('APP_UPDATES_DEV_MANIFEST_URL', 'https://webe.li/cms/updates/manifest.json'),
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
