<?php

declare(strict_types=1);

$boolEnv = static function (string $key, bool $default): bool {
    $value = env($key, $default ? '1' : '0');
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};

return [
    'enabled' => $boolEnv('APP_HEALTH_ENABLED', true),
    'live_path' => '/health/live',
    'ready_path' => '/health/ready',
    'timeout_ms' => max(100, (int) env('APP_HEALTH_READY_TIMEOUT_MS', 1600)),
    'database_busy_timeout_ms' => max(10, (int) env('APP_HEALTH_DB_BUSY_TIMEOUT_MS', 100)),
    'check_databases' => $boolEnv('APP_HEALTH_CHECK_DATABASES', true),
    'check_storage' => $boolEnv('APP_HEALTH_CHECK_STORAGE', true),
    'required_storage_paths' => [
        base_path('storage/database'),
        base_path('storage/cache'),
        base_path('storage/logs'),
        base_path('storage/uploads'),
    ],
];
