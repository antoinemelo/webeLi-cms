<?php

declare(strict_types=1);

$envName = strtolower((string) env('APP_ENV', 'production'));
$isProduction = $envName === 'production' || $envName === 'prod';
$boolEnv = static function (string $key, bool $default): bool {
    $value = env($key, $default ? '1' : '0');
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};
$stringEnv = static function (string $key, string $default): string {
    $value = env($key, null);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};
$intEnv = static function (string $key, int $default): int {
    $value = env($key, null);
    return is_numeric($value) ? (int) $value : $default;
};

$commonPermissionsPolicy = $stringEnv(
    'SECURITY_PERMISSIONS_POLICY',
    'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=()'
);

$frontCsp = $stringEnv(
    'SECURITY_CSP_FRONT',
    "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' https: data: blob:; font-src 'self' https: data:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; connect-src 'self'; frame-src 'self' https://www.youtube.com https://youtube.com https://youtu.be https://player.vimeo.com https://vimeo.com https://www.openstreetmap.org https://openstreetmap.org; upgrade-insecure-requests"
);
$apiCsp = $stringEnv(
    'SECURITY_CSP_API',
    "default-src 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'none'"
);
$adminCsp = $stringEnv(
    'SECURITY_CSP_ADMIN',
    "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-src 'self'; upgrade-insecure-requests"
);

return [
    'enabled' => $boolEnv('SECURITY_HEADERS_ENABLED', true),
    'environment' => $envName,
    'strip_upgrade_insecure_requests_in_dev' => $boolEnv('SECURITY_STRIP_UPGRADE_INSECURE_REQUESTS_IN_DEV', true),
    'x_content_type_options' => true,
    'permissions_policy' => $commonPermissionsPolicy,
    'cross_origin_opener_policy' => $stringEnv('SECURITY_COOP', 'same-origin'),
    'hsts' => [
        'enabled' => $boolEnv('SECURITY_HSTS_ENABLED', $isProduction),
        'mode' => $stringEnv('SECURITY_HSTS_MODE', 'https_only'), // https_only|always|never
        'max_age' => $intEnv('SECURITY_HSTS_MAX_AGE', 15552000),
        'include_subdomains' => $boolEnv('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => $boolEnv('SECURITY_HSTS_PRELOAD', false),
    ],
    'profiles' => [
        'front' => [
            'content_security_policy' => $frontCsp,
            'referrer_policy' => $stringEnv('SECURITY_REFERRER_POLICY_FRONT', 'strict-origin-when-cross-origin'),
            // Kept for compatibility with older browsers; CSP frame-ancestors is the primary control.
            'x_frame_options' => $stringEnv('SECURITY_X_FRAME_OPTIONS_FRONT', 'SAMEORIGIN'),
            'cross_origin_resource_policy' => $stringEnv('SECURITY_CORP_FRONT', 'same-site'),
        ],
        'api' => [
            'content_security_policy' => $apiCsp,
            'referrer_policy' => $stringEnv('SECURITY_REFERRER_POLICY_API', 'no-referrer'),
            'x_frame_options' => $stringEnv('SECURITY_X_FRAME_OPTIONS_API', 'DENY'),
            'cross_origin_resource_policy' => $stringEnv('SECURITY_CORP_API', 'same-origin'),
            'extra' => [
                'Cache-Control' => 'no-store',
            ],
        ],
        'admin' => [
            'content_security_policy' => $adminCsp,
            'referrer_policy' => $stringEnv('SECURITY_REFERRER_POLICY_ADMIN', 'same-origin'),
            'x_frame_options' => $stringEnv('SECURITY_X_FRAME_OPTIONS_ADMIN', 'SAMEORIGIN'),
            'cross_origin_resource_policy' => $stringEnv('SECURITY_CORP_ADMIN', 'same-origin'),
            'extra' => [
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ],
        ],
    ],
];
