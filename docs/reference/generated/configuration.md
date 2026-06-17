---
title: Variables de configuration
audience:
  - developer
  - installer
  - evaluator
status: stable
version: 1.0
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
| `APP_BASE_PATH` | `backend/config/app.php`, `backend/src/Shared/Support/helpers.php`, `tools/python/operations/database/b2_cleanup_noindex_search_documents.py` |
| `APP_EDITORIAL_IMPORT_MAX_ARCHIVE_BYTES` | `backend/config/app.php` |
| `APP_EDITORIAL_IMPORT_MAX_FILES` | `backend/config/app.php` |
| `APP_EDITORIAL_IMPORT_MAX_UNCOMPRESSED_BYTES` | `backend/config/app.php` |
| `APP_ENV` | `backend/config/app.php`, `backend/config/security.php`, `backend/src/Security/PreviewSigner.php` |
| `APP_FALLBACK_LOCALE` | `backend/config/app.php` |
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
| `APP_PUBLIC_API_RATE_LIMIT_CLEANUP_PROBABILITY` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_DEFAULT_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_DEFAULT_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_MEDIA_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_MEDIA_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_SEARCH_MAX` | `backend/config/app.php` |
| `APP_PUBLIC_API_RATE_LIMIT_SEARCH_WINDOW` | `backend/config/app.php` |
| `APP_PUBLIC_BASE_URL` | `backend/config/app.php` |
| `APP_SESSION_IDLE_TIMEOUT` | `backend/config/app.php` |
| `APP_SESSION_NAME` | `backend/config/app.php` |
| `APP_TEMPLATE_ENGINE` | `backend/config/app.php` |
| `APP_THEME` | `backend/config/app.php` |
| `APP_TIMEZONE` | `backend/config/app.php` |
| `APP_TWIG_CACHE` | `backend/config/app.php` |
| `APP_WORKER_MAX_ATTEMPTS` | `backend/config/app.php` |
| `CMS_TOTP_KEY` | `backend/src/Repository/AuthRepository.php` |
| `DEC_CMS_AUDIT_INTERNAL` | `tools/python/operations/deployment/d_deploy.py` |
| `FTP_PASSIVE` | `tools/python/operations/deployment/d11_ci_release.py` |
| `FTP_PORT` | `tools/python/operations/deployment/d11_ci_release.py` |
| `FTP_TIMEOUT` | `tools/python/operations/deployment/d11_ci_release.py` |
| `FTP_TLS` | `tools/python/operations/deployment/d11_ci_release.py` |
| `MAIL_FROM_EMAIL` | `backend/config/app.php` |
| `MAIL_FROM_NAME` | `backend/config/app.php` |
| `MAIL_TRANSPORT` | `backend/config/app.php` |
| `SFTP_PORT` | `tools/python/operations/deployment/d11_ci_release.py` |

Les secrets et valeurs propres à un environnement ne sont jamais inclus dans cette page.
