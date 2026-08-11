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

$runtimePath = static function (string $key, string $default, array $aliases = []): string {
    $value = env($key, null);
    foreach ($aliases as $alias) {
        if ($value !== null && $value !== '') {
            break;
        }
        $value = env($alias, null);
    }
    $value = is_string($value) && $value !== '' ? $value : $default;
    if (!str_starts_with($value, DIRECTORY_SEPARATOR) && !preg_match('#^[A-Z]:[\\/]#i', $value)) {
        $value = base_path(preg_replace('#^[.][\\/]#', '', $value) ?? $value);
    }
    return rtrim($value, DIRECTORY_SEPARATOR . '/');
};

$firstExistingDependencyPath = static function (array $candidates, string $fallback): string {
    foreach (array_values(array_unique(array_map(
        static fn(string $path): string => rtrim($path, DIRECTORY_SEPARATOR . '/'),
        $candidates,
    ))) as $candidate) {
        if ($candidate !== '' && is_dir($candidate)) {
            return $candidate;
        }
    }
    return rtrim($fallback, DIRECTORY_SEPARATOR . '/');
};

$configuredNodeModulesPath = $runtimePath('APP_VUE_NODE_MODULES_PATH', './vendor/node_modules/', ['APP_NODE_MODULES_PATH']);
$configuredTwigVendorPath = $runtimePath('APP_TWIG_VENDOR_PATH', './vendor/twig/', ['APP_TWIG_PATH']);
$vendorRoots = function_exists('cms_vendor_roots')
    ? cms_vendor_roots()
    : [base_path('backend/vendor'), base_path('vendor'), base_path('../vendor')];
$vueNodeModulesPath = $firstExistingDependencyPath([
    ...array_map(static fn(string $root): string => $root . '/node_modules', $vendorRoots),
    $configuredNodeModulesPath,
], $configuredNodeModulesPath);
$twigVendorPath = $firstExistingDependencyPath([
    ...array_map(static fn(string $root): string => $root . '/twig', $vendorRoots),
    $configuredTwigVendorPath,
], $configuredTwigVendorPath);
$primaryPaymentProvider = strtolower(trim((string) env('PAYMENT_REAL_PROVIDER', '')));
$secondPaymentProvider = strtolower(trim((string) env('PROVIDER_REAL_2', '')));
$configuredPaymentProviders = trim((string) env('PAYMENT_REAL_PROVIDERS', $primaryPaymentProvider));
if ($secondPaymentProvider !== '') {
    $configuredPaymentProviders = trim($configuredPaymentProviders . ' ' . $secondPaymentProvider);
}

$twigCache = env('APP_TWIG_CACHE', base_path('storage/cache/twig'));
if (is_string($twigCache) && $twigCache !== '' && $twigCache !== '0' && !str_starts_with($twigCache, DIRECTORY_SEPARATOR) && !preg_match('#^[A-Z]:[\\/]#i', $twigCache)) {
    $twigCache = base_path($twigCache);
}

return [
    'name' => env('APP_NAME', 'DEC CMS Runtime'),
    'env' => env('APP_ENV', 'production'),
    'debug' => $boolEnv('APP_DEBUG', !$isProduction),
    'timezone' => env('APP_TIMEZONE', 'Europe/Zurich'),
    'base_path' => env('APP_BASE_PATH', ''),
    'public_base_url' => env('APP_PUBLIC_BASE_URL', ''),
    'allow_local_hosts' => $boolEnv('APP_ALLOW_LOCAL_HOSTS', !$isProduction),
    'default_locale' => env('APP_LOCALE', 'fr'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'session_name' => env('APP_SESSION_NAME', 'amcms_cms'),
    'session_idle_timeout' => (int) env('APP_SESSION_IDLE_TIMEOUT', 3600),
    'login_rate_limit_attempts' => (int) env('APP_LOGIN_RATE_LIMIT_ATTEMPTS', 5),
    'login_rate_limit_window' => (int) env('APP_LOGIN_RATE_LIMIT_WINDOW', 900),
    'public_api_cors' => [
        // CORS headless v1 : seules les requêtes navigateur avec Origin sont contrôlées.
        // Les origines cross-site sont configurées par site dans site_settings:
        // namespace=api, setting_key=cors_allowed_origins, value_json=["https://front.example"].
        'enabled' => $boolEnv('APP_PUBLIC_API_CORS_ENABLED', true),
        'allow_current_site_origin' => $boolEnv('APP_PUBLIC_API_CORS_ALLOW_CURRENT_SITE', true),
        'default_allowed_origins' => env('APP_PUBLIC_API_CORS_DEFAULT_ORIGINS', '[]'),
        'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'Idempotency-Key'],
        'max_age' => (int) env('APP_PUBLIC_API_CORS_MAX_AGE', 600),
    ],

    'editorial_import' => [
        'max_archive_bytes' => (int) env('APP_EDITORIAL_IMPORT_MAX_ARCHIVE_BYTES', 52428800),
        'max_files' => (int) env('APP_EDITORIAL_IMPORT_MAX_FILES', 2000),
        'max_uncompressed_bytes' => (int) env('APP_EDITORIAL_IMPORT_MAX_UNCOMPRESSED_BYTES', 268435456),
    ],

    'public_api_auth' => [
        // Authentification headless stateless par Bearer token pour /api/v1/*.
        // Indépendante des sessions admin. Les endpoints techniques et les formulaires publics
        // restent anonymes afin que le frontend ne doive jamais exposer un Bearer token.
        'enabled' => $boolEnv('APP_PUBLIC_API_AUTH_ENABLED', $isProduction),
        'protect_all_v1_by_default' => $boolEnv('APP_PUBLIC_API_AUTH_PROTECT_ALL', true),
        'default_scope' => env('APP_PUBLIC_API_AUTH_DEFAULT_SCOPE', 'content:read'),
        'public_paths' => [
            '#^/api/v1/health$#',
            '#^/api/v1/openapi\.(?:json|yaml)$#',
            '#^/api/v1/cookies/config$#',
            '#^/api/v1/cookies/consent$#',
            '#^/api/v1/forms/[a-z0-9_-]+$#',
            '#^/api/v1/forms/[a-z0-9_-]+/submit$#',
            '#^/api/v1/sale/channels/[a-z0-9_-]+/(?:bootstrap|cart|checkout)(?:/|$)#',
            '#^/api/v1/sale/payments/webhooks/(?:stripe_checkout|revolut_checkout)$#',
            '#^/api/v1/sale/payments/return$#',
            '#^/api/v1/customer(?:/|$)#',
            '#^/api/v1/media$#',
        ],
        'protected_paths' => [
            '#^/api/v1/(?:route|content|content-by-path|routes|languages|menus|taxonomies|search|media|catalog|pos/catalog)(?:/|$)#',
        ],
        'scope_aliases' => [
            'headless:read' => ['routes:read', 'content:read', 'media:read', 'search:read', 'menus:read', 'taxonomies:read', 'catalog:read'],
            'content:read' => ['routes:read'],
        ],
        'endpoint_scopes' => [
            '#^/api/v1/(?:route|routes|languages)(?:/|$)#' => 'routes:read',
            '#^/api/v1/(?:content|content-by-path)(?:/|$)#' => 'content:read',
            '#^/api/v1/search$#' => 'search:read',
            '#^/api/v1/menus(?:/|$)#' => 'menus:read',
            '#^/api/v1/taxonomies(?:/|$)#' => 'taxonomies:read',
            '#^/api/v1/media(?:/|$)#' => 'media:read',
            '#^/api/v1/catalog(?:/|$)#' => 'catalog:read',
            '#^/api/v1/pos/catalog(?:/|$)#' => 'pos.catalog.read',
        ],
    ],

    'public_api_rate_limit' => [
        // Limiteur public headless v1 (/api/v1/*). Activé par défaut en production,
        // désactivable en développement via APP_PUBLIC_API_RATE_LIMIT_ENABLED=0.
        'enabled' => $boolEnv('APP_PUBLIC_API_RATE_LIMIT_ENABLED', $isProduction),
        'cleanup_probability' => (int) env('APP_PUBLIC_API_RATE_LIMIT_CLEANUP_PROBABILITY', 5),
        'trust_proxy_headers' => $boolEnv('APP_PUBLIC_API_RATE_LIMIT_TRUST_PROXY_HEADERS', false),
        'default' => [
            'limit' => (int) env('APP_PUBLIC_API_RATE_LIMIT_DEFAULT_MAX', 120),
            'window' => (int) env('APP_PUBLIC_API_RATE_LIMIT_DEFAULT_WINDOW', 60),
            'group' => 'route',
        ],
        'endpoints' => [
            '#^/api/v1/sale/payments/webhooks/(?:stripe_checkout|revolut_checkout)$#' => [
                'limit' => (int) env('PAYMENT_WEBHOOK_RATE_LIMIT_MAX', 180),
                'window' => (int) env('PAYMENT_WEBHOOK_RATE_LIMIT_WINDOW', 60),
                'group' => '/api/v1/sale/payments/webhooks/real-provider',
            ],
            '#^/api/v1/customer/(?:login|accounts/register)$#' => [
                'limit' => (int) env('APP_PUBLIC_API_RATE_LIMIT_CUSTOMER_AUTH_MAX', 10),
                'window' => (int) env('APP_PUBLIC_API_RATE_LIMIT_CUSTOMER_AUTH_WINDOW', 900),
                'group' => '/api/v1/customer/auth',
            ],
            '#^/api/v1/sale/channels/[a-z0-9_-]+/(?:checkout|cart/[A-Za-z0-9_-]+/checkout)$#' => [
                'limit' => (int) env('APP_PUBLIC_API_RATE_LIMIT_CHECKOUT_MAX', 20),
                'window' => (int) env('APP_PUBLIC_API_RATE_LIMIT_CHECKOUT_WINDOW', 60),
                'group' => '/api/v1/sale/checkout',
            ],
            '#^/api/v1/search$#' => [
                'limit' => (int) env('APP_PUBLIC_API_RATE_LIMIT_SEARCH_MAX', 60),
                'window' => (int) env('APP_PUBLIC_API_RATE_LIMIT_SEARCH_WINDOW', 60),
                'group' => '/api/v1/search',
            ],
            '#^/api/v1/media(?:/|$)#' => [
                'limit' => (int) env('APP_PUBLIC_API_RATE_LIMIT_MEDIA_MAX', 240),
                'window' => (int) env('APP_PUBLIC_API_RATE_LIMIT_MEDIA_WINDOW', 60),
                'group' => '/api/v1/media',
            ],
            '#^/api/v1/sale/channels/[a-z0-9_-]+(?:/|$)#' => [
                'limit' => (int) env('APP_PUBLIC_API_RATE_LIMIT_SALE_MAX', 120),
                'window' => (int) env('APP_PUBLIC_API_RATE_LIMIT_SALE_WINDOW', 60),
                'group' => '/api/v1/sale',
            ],
        ],
    ],

    'payments' => [
        'real_provider' => $primaryPaymentProvider,
        'real_providers' => array_values(array_filter(array_map(
            static fn(string $provider): string => strtolower(trim($provider)),
            preg_split('/[,\s]+/', $configuredPaymentProviders) ?: []
        ))),
        'public_base_url' => (string) env('APP_PUBLIC_BASE_URL', ''),
        'stripe' => [
            'enabled' => $boolEnv('PAYMENT_STRIPE_ENABLED', false),
            'environment' => strtolower((string) env('PAYMENT_STRIPE_ENV', 'test')),
            'secret_key' => (string) env('STRIPE_SECRET_KEY', ''),
            'webhook_secrets' => array_values(array_filter([
                (string) env('STRIPE_WEBHOOK_SECRET', ''),
                (string) env('STRIPE_WEBHOOK_SECRET_PREVIOUS', ''),
            ])),
            'signature_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
            'api_version' => (string) env('STRIPE_API_VERSION', ''),
            'twint_mode' => strtolower((string) env('PAYMENT_STRIPE_TWINT_MODE', 'dynamic')),
        ],
        'revolut' => [
            'enabled' => $boolEnv('PAYMENT_REVOLUT_ENABLED', false),
            'environment' => strtolower((string) env('PAYMENT_REVOLUT_ENV', 'sandbox')),
            'secret_key' => (string) env('REVOLUT_MERCHANT_SECRET_KEY', ''),
            'webhook_secrets' => array_values(array_filter([
                (string) env('REVOLUT_WEBHOOK_SECRET', ''),
                (string) env('REVOLUT_WEBHOOK_SECRET_PREVIOUS', ''),
            ])),
            'signature_tolerance' => (int) env('REVOLUT_WEBHOOK_TOLERANCE_SECONDS', 300),
            'api_version' => (string) env('REVOLUT_API_VERSION', '2026-04-20'),
            'timeout' => (int) env('REVOLUT_API_TIMEOUT_SECONDS', 15),
        ],
    ],

    'password_reset' => [
        'token_lifetime_minutes' => (int) env('APP_PASSWORD_RESET_LIFETIME_MINUTES', 30),
        'cooldown_seconds' => (int) env('APP_PASSWORD_RESET_COOLDOWN_SECONDS', 600),
        'minimum_password_length' => (int) env('APP_PASSWORD_MIN_LENGTH', 10),
        'revoke_all_sessions_on_reset' => $boolEnv('APP_PASSWORD_RESET_REVOKE_SESSIONS', true),
        'rate_limit_attempts' => (int) env('APP_PASSWORD_RESET_RATE_LIMIT_ATTEMPTS', 5),
        'rate_limit_window' => (int) env('APP_PASSWORD_RESET_RATE_LIMIT_WINDOW', 900),
    ],
    'mail' => [
        'transport' => env('MAIL_TRANSPORT', 'mail'),
        'from_email' => env('MAIL_FROM_EMAIL', 'no-reply@example.test'),
        'from_name' => env('MAIL_FROM_NAME', 'DEC CMS'),
    ],
    'media_upload_max_bytes' => (int) env('APP_MEDIA_UPLOAD_MAX_BYTES', 8388608),
    'template_engine' => env('APP_TEMPLATE_ENGINE', 'twig'),
    'theme' => env('APP_THEME', 'theme-default'),
    'twig_cache' => $twigCache,
    'vue_node_modules_path' => $vueNodeModulesPath,
    'twig_vendor_path' => $twigVendorPath,
    'preview_signing_key' => env('APP_PREVIEW_SIGNING_KEY', 'change-this-preview-key'),
    'gift_card_signing_key' => env('APP_GIFT_CARD_SIGNING_KEY', env('APP_PREVIEW_SIGNING_KEY', 'change-this-gift-card-key')),
    'form_relation_signing_key' => env('APP_FORM_RELATION_SIGNING_KEY', env('APP_PREVIEW_SIGNING_KEY', 'change-this-preview-key')),
    'worker_max_attempts' => (int) env('APP_WORKER_MAX_ATTEMPTS', 5),

    // Runtime public léger : en production, aucune maintenance automatique ne
    // tourne dans la requête HTTP. Les migrations, seeds et synchronisations
    // modules/content types sont exécutées par backend/bin/console ou les
    // scripts Python de préflight/déploiement. En développement, l'auto-heal
    // reste pratique et peut être forcé avec APP_AUTO_MAINTENANCE=1.
    'auto_maintenance' => $boolEnv('APP_AUTO_MAINTENANCE', !$isProduction),
    'public_module_routes' => $boolEnv('APP_PUBLIC_MODULE_ROUTES', !$isProduction),
    // Les routes Sale publiques sont nécessaires au panier du Storefront natif.
    // Le canal, le site et la langue restent contrôlés par les handlers publics.
    'public_api_module_routes' => $boolEnv('APP_PUBLIC_API_MODULE_ROUTES', true),
    'admin_module_routes' => $boolEnv('APP_ADMIN_MODULE_ROUTES', true),
    'start_session_for_public' => $boolEnv('APP_START_SESSION_FOR_PUBLIC', false),
];
