---
title: Matrice légère rôles-permissions
audience:
  - developer
  - evaluator
status: stable
last_verified: 2026-06-25
source_of_truth: tests
source_paths:
  - tools/php/tests/integration/roles_matrix_http_test.php
  - database/seeds/iam_seed.sql
owners:
  - security
document_type: reference
generated: false
---
# Matrice légère rôles-permissions

P1-03 adds a lightweight HTTP smoke check for the native IAM roles. It is not
an exhaustive authorization matrix and does not redefine business permissions.

| Role | Allowed route tested | Forbidden route tested |
| --- | --- | --- |
| `super_admin` | `GET /admin/api/iam/users` | Not applicable: full access role |
| `admin` | `GET /admin/api/configuration` | `GET /admin/api/iam/users` |
| `editor` | `GET /admin/api/entries` on its assigned site | `GET /admin/api/entries` on another site; `GET /admin/api/iam/users` |
| `translator` | `GET /admin/api/entries` | `POST /admin/api/entries/{id}/publish` |
| `publication` | `POST /admin/api/entries/{id}/publish` reaches route authorization | `GET /admin/api/iam/users` |
| `seo` | `GET /admin/api/seo/audit` | `POST /admin/api/entries/{id}/publish` |
| `user` | `GET /admin/api/profile` | `GET /admin/api/entries`; `GET /admin/api/iam/users` |

The editor check also verifies multisite isolation by assigning the test editor
to one active site only and asserting that another active site returns `403`.

Run only this smoke:

```bash
php tools/php/tests/integration/roles_matrix_http_test.php
```

Run it through the regular PHP functional suite:

```bash
php tools/php/tests/run.php
```
