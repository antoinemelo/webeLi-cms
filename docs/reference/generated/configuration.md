---
title: Variables de configuration
audience:
  - developer
  - installer
  - evaluator
status: stable
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Variables de configuration

> Fichier généré. Ne pas modifier directement.

| Variable | Références dans le dépôt |
|---|---|
| `AMCMS_M0_PERF_BASE_URL` | `tools/python/qualification/performance_baseline.py` |
| `AMCMS_M0_PERF_CRITICAL_MS` | `tools/python/qualification/performance_baseline.py` |
| `AMCMS_M0_PERF_REPEAT` | `tools/python/qualification/performance_baseline.py` |
| `AMCMS_M0_PERF_VARIANT_ID` | `tools/python/qualification/performance_baseline.py` |
| `APP_BASE_PATH` | `backend/config/app.php`, `backend/src/Shared/Support/helpers.php`, `tools/python/operations/database/b2_cleanup_noindex_search_documents.py` |
| `APP_DEPENDENCIES_HTTP_TIMEOUT` | `backend/config/updates.php` |
| `APP_DEPENDENCIES_LATEST_BUDGET` | `backend/config/updates.php` |
| `APP_DEPENDENCIES_LATEST_CACHE_TTL` | `backend/config/updates.php` |
| `APP_DEPENDENCIES_LATEST_ENABLED` | `backend/config/updates.php` |
| `APP_DEPENDENCIES_REFRESH_BUDGET` | `backend/config/updates.php` |
| `APP_DEPENDENCIES_REFRESH_HTTP_TIMEOUT` | `backend/config/updates.php` |
| `APP_EDITORIAL_IMPORT_MAX_ARCHIVE_BYTES` | `backend/config/app.php` |
| `APP_EDITORIAL_IMPORT_MAX_FILES` | `backend/config/app.php` |
| `APP_EDITORIAL_IMPORT_MAX_UNCOMPRESSED_BYTES` | `backend/config/app.php` |
| `APP_ENV` | `backend/config/app.php`, `backend/config/security.php`, `backend/src/Application/PublicApi/PublicSaleApiHandler.php`, `backend/src/Modules/Sale/Payments/PaymentProviderRegistry.php`, `backend/src/Security/PreviewSigner.php` |
| `APP_FALLBACK_LOCALE` | `backend/config/app.php` |
| `APP_FORM_RELATION_SIGNING_KEY` | `backend/config/app.php` |
| `APP_HEALTH_DB_BUSY_TIMEOUT_MS` | `backend/config/health.php` |
| `APP_HEALTH_READY_TIMEOUT_MS` | `backend/config/health.php` |
| `APP_KEY` | `backend/src/Repository/AuthRepository.php` |
| `APP_LOCALE` | `backend/config/app.php` |
| `APP_LOGIN_RATE_LIMIT_ATTEMPTS` | `backend/config/app.php` |
| `APP_LOGIN_RATE_LIMIT_WINDOW` | `backend/config/app.php` |
| `APP_MEDIA_UPLOAD_MAX_BYTES` | `backend/config/app.php` |
| `APP_NAME` | `backend/config/app.php` |
| `APP_PASSWORD_MIN_LENGTH` | `backend/config/app.php` |
| `APP_PASSWORD_RESET_COOLDOWN_SECONDS` | `backend/config/app.php` |
| `APP_PASSWORD_RESET_LIFETIME_MINUTES` | `backend/config/app.php` |
| `APP_PASSWORD_RESET_RATE_LIMIT_ATTEMPTS` | `backend/config/app.php` |
| `APP_PASSWORD_RESET_RATE_LIMIT_WINDOW` | `backend/config/app.php` |
| `APP_PREVIEW_SIGNING_KEY` | `backend/config/app.php` |
| `APP_PUBLIC_API_AUTH_DEFAULT_SCOPE` | `backend/config/app.php` |
| `APP_PUBLIC_API_CORS_DEFAULT_ORIGINS` | `backend/config/app.php` |
| `APP_PUBLIC_API_CORS_MAX_AGE` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_CHECKOUT_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_CHECKOUT_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_CLEANUP_PROBABILITY` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_CUSTOMER_AUTH_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_CUSTOMER_AUTH_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_DEFAULT_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_DEFAULT_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_MEDIA_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_MEDIA_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_SALE_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_SALE_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_SEARCH_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_SEARCH_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_BASE_URL` | `backend/config/app.php`, `backend/src/Application/Maintenance/VersionInventoryService.php` |
| `APP_SESSION_IDLE_TIMEOUT` | `backend/config/app.php` |
| `APP_SESSION_NAME` | `backend/config/app.php` |
| `APP_TEMPLATE_ENGINE` | `backend/config/app.php` |
| `APP_THEME` | `backend/config/app.php` |
| `APP_TIMEZONE` | `backend/config/app.php` |
| `APP_TWIG_CACHE` | `backend/config/app.php` |
| `APP_UPDATES_DEV_GIT_BRANCH` | `backend/config/updates.php` |
| `APP_UPDATES_DEV_INSTANCE_URL` | `backend/config/updates.php` |
| `APP_UPDATES_DEV_MANIFEST_URL` | `backend/config/updates.php` |
| `APP_UPDATES_ENABLED` | `backend/config/updates.php` |
| `APP_UPDATES_GITHUB_REPO` | `backend/config/updates.php` |
| `APP_UPDATES_GITHUB_TOKEN` | `backend/src/Application/Maintenance/VersionInventoryService.php` |
| `APP_UPDATES_HTTP_TIMEOUT` | `backend/config/updates.php` |
| `APP_UPDATES_STABLE_GIT_BRANCH` | `backend/config/updates.php` |
| `APP_UPDATES_STABLE_INSTANCE_URL` | `backend/config/updates.php` |
| `APP_UPDATES_STABLE_MANIFEST_URL` | `backend/config/updates.php` |
| `APP_WORKER_MAX_ATTEMPTS` | `backend/config/app.php` |
| `BUSINESS_TELEGRAM_BOT_TOKEN` | `backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php` |
| `BUSINESS_TELEGRAM_ENABLED` | `backend/src/Modules/Business/Messaging/TelegramBotProvider.php`, `backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php` |
| `BUSINESS_WHATSAPP_ACCESS_TOKEN` | `backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php` |
| `BUSINESS_WHATSAPP_API_VERSION` | `backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php` |
| `BUSINESS_WHATSAPP_ENABLED` | `backend/src/Modules/Business/Messaging/WhatsAppCloudApiProvider.php`, `backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php` |
| `BUSINESS_WHATSAPP_PHONE_NUMBER_ID` | `backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php` |
| `CMS_TOTP_KEY` | `backend/src/Repository/AuthRepository.php` |
| `DEC_CMS_AUDIT_INTERNAL` | `tools/python/operations/deployment/d_deploy.py` |
| `FTP_PASSIVE` | `tools/python/operations/deployment/d11_ci_release.py` |
| `FTP_PORT` | `tools/python/operations/deployment/d11_ci_release.py` |
| `FTP_TIMEOUT` | `tools/python/operations/deployment/d11_ci_release.py` |
| `FTP_TLS` | `tools/python/operations/deployment/d11_ci_release.py` |
| `MAIL_FROM_EMAIL` | `backend/config/app.php` |
| `MAIL_FROM_NAME` | `backend/config/app.php` |
| `MAIL_TRANSPORT` | `backend/config/app.php` |
| `PAYMENT_REAL_PROVIDER` | `backend/config/app.php` |
| `PAYMENT_REAL_PROVIDERS` | `backend/config/app.php` |
| `PAYMENT_REVOLUT_ENV` | `backend/config/app.php` |
| `PAYMENT_STRIPE_ENV` | `backend/config/app.php` |
| `PAYMENT_STRIPE_TWINT_MODE` | `backend/config/app.php` |
| `PAYMENT_WEBHOOK_RATE_LIMIT_MAX` | `backend/config/app.php` |
| `PAYMENT_WEBHOOK_RATE_LIMIT_WINDOW` | `backend/config/app.php` |
| `PROVIDER_REAL_2` | `backend/config/app.php` |
| `REVOLUT_API_TIMEOUT_SECONDS` | `backend/config/app.php` |
| `REVOLUT_API_VERSION` | `backend/config/app.php` |
| `REVOLUT_MERCHANT_SECRET_KEY` | `backend/config/app.php` |
| `REVOLUT_WEBHOOK_SECRET` | `backend/config/app.php` |
| `REVOLUT_WEBHOOK_SECRET_PREVIOUS` | `backend/config/app.php` |
| `REVOLUT_WEBHOOK_TOLERANCE_SECONDS` | `backend/config/app.php` |
| `SALE_SANDBOX_WEBHOOK_SECRET` | `backend/src/Modules/Sale/Payments/PaymentProviderRegistry.php` |
| `SFTP_PORT` | `tools/python/operations/deployment/d11_ci_release.py` |
| `STRIPE_API_VERSION` | `backend/config/app.php` |
| `STRIPE_SECRET_KEY` | `backend/config/app.php` |
| `STRIPE_WEBHOOK_SECRET` | `backend/config/app.php` |
| `STRIPE_WEBHOOK_SECRET_PREVIOUS` | `backend/config/app.php` |
| `STRIPE_WEBHOOK_TOLERANCE_SECONDS` | `backend/config/app.php` |

Les secrets et valeurs propres à un environnement ne sont jamais inclus dans cette page.
