---
title: Dépanner une installation
audience:
  - installer
  - superadministrator
status: stable
last_verified: 2026-06-23
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
generated: false
---
# Dépanner une installation

- **`pdo_sqlite` indisponible :** activez l’extension PHP correspondante. L’image `Dockerfile.audit` l’installe et vérifie sa présence pendant sa construction.
- **Erreur `Cannot redeclare class ...ModuleProvider` :** le bootstrap précharge désormais de manière idempotente le contrat `ModuleProvider` et les providers natifs déclarés dans `backend/config/modules.php`, avant l’enregistrement des autoloaders. Le registre fusionne et déduplique aussi les providers issus de la configuration et de la base. Vérifiez enfin que `APP_TWIG_VENDOR_PATH` ne pointe pas vers un Composer racine exposant `App\\`.
- **Assets 404 en sous-répertoire :** vérifiez `APP_BASE_PATH` et les règles du serveur.
- **Icône d’aide affichée comme un carré au premier chargement du back-office :** vérifiez que `admin-app/index.html` charge les feuilles CSS critiques avant le script module et conserve le bloc `amcms-admin-critical-ui`. Ce garde-fou évite un flash d’icône non stylée sur `/admin/app/`, notamment quand l’instance est servie sous un préfixe comme `/cms`.
- **Liens publics de sous-site sans préfixe d’installation :** si l’administration ouvre `/site-a/` au lieu de `/cms/site-a/`, vérifiez `APP_BASE_PATH`, le `public_path` renvoyé par `/admin/api/context` et la configuration `site_domains.base_path`. Le moteur accepte `/site-a` ou `/cms/site-a`, mais les liens exposés doivent toujours inclure le préfixe public complet.
- **Twig introuvable :** exécutez `composer install --working-dir backend`. Le runtime cherche automatiquement dans `backend/vendor`, `vendor`, le `vendor` du parent, le `cms/vendor` conventionnel de la racine web, puis le `vendor` situé deux niveaux au-dessus. N’utilisez `APP_TWIG_VENDOR_PATH` que pour un emplacement personnalisé supplémentaire de Twig, sans mapping `App\\` provenant d’une autre instance.
- **Écriture refusée :** corrigez le propriétaire et les droits de `storage/` sans rendre le code globalement inscriptible.
- **Connexion impossible :** vérifiez les sessions, les cookies HTTPS, l’URL de base et les tables IAM.
- **CORS :** configurez l’origine du site concerné et vérifiez la requête preflight.

## Erreur `Cannot redeclare class` sur un provider de module

Lors d’une reconstruction SQLite, `provider_class` doit contenir un FQCN PHP canonique avec un seul antislash entre chaque segment, par exemple `App\Modules\Forms\FormsModuleProvider`. Contrairement aux chaînes PHP ou JSON, SQLite ne traite pas l’antislash comme un caractère d’échappement : écrire `App\\Modules...` dans un fichier SQL stocke réellement deux antislashs.

Le registre des modules normalise désormais les anciennes valeurs mal formées avant tout appel à `class_exists()`. Les scripts SQL natifs utilisent également la représentation SQLite correcte. Il ne faut pas contourner ce défaut en remplaçant PDO SQLite : l’image d’audit officielle vérifie déjà `pdo_sqlite` et le pilote `sqlite`.
