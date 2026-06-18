---
title: Dépanner une installation
audience:
  - installer
  - superadministrator
status: stable
version: 1.2
last_verified: 2026-06-15
source_of_truth: procedure
source_paths:
  - ops/.env.example
  - backend/bootstrap/runtime.php
  - backend/src/Core/Database.php
  - tools/python/operations
  - tools/python/qualification/run_all.py
owners:
  - operations
document_type: procedure
permissions: []
generated: false
---
# Dépanner une installation

- **`pdo_sqlite` indisponible :** activez l’extension PHP correspondante. L’image `Dockerfile.audit` l’installe et vérifie sa présence pendant sa construction.
- **Erreur `Cannot redeclare class ...ModuleProvider` :** le bootstrap précharge désormais de manière idempotente le contrat `ModuleProvider` et les providers natifs déclarés dans `backend/config/modules.php`, avant l’enregistrement des autoloaders. Le registre fusionne et déduplique aussi les providers issus de la configuration et de la base. Vérifiez enfin que `APP_TWIG_VENDOR_PATH` ne pointe pas vers un Composer racine exposant `App\\`.
- **Assets 404 en sous-répertoire :** vérifiez `APP_BASE_PATH` et les règles du serveur.
- **Twig introuvable :** exécutez `composer install --working-dir backend`, ou configurez `APP_TWIG_VENDOR_PATH=../vendor/twig/` lorsqu'une instance sous sous-répertoire partage un `vendor` parent. N’utilisez `APP_TWIG_VENDOR_PATH` que pour une installation Twig autonome sans mapping `App\\`.
- **Écriture refusée :** corrigez le propriétaire et les droits de `storage/` sans rendre le code globalement inscriptible.
- **Connexion impossible :** vérifiez les sessions, les cookies HTTPS, l’URL de base et les tables IAM.
- **CORS :** configurez l’origine du site concerné et vérifiez la requête preflight.

## Erreur `Cannot redeclare class` sur un provider de module

Lors d’une reconstruction SQLite, `provider_class` doit contenir un FQCN PHP canonique avec un seul antislash entre chaque segment, par exemple `App\Modules\Forms\FormsModuleProvider`. Contrairement aux chaînes PHP ou JSON, SQLite ne traite pas l’antislash comme un caractère d’échappement : écrire `App\\Modules...` dans un fichier SQL stocke réellement deux antislashs.

Le registre des modules normalise désormais les anciennes valeurs mal formées avant tout appel à `class_exists()`. Les scripts SQL natifs utilisent également la représentation SQLite correcte. Il ne faut pas contourner ce défaut en remplaçant PDO SQLite : l’image d’audit officielle vérifie déjà `pdo_sqlite` et le pilote `sqlite`.
