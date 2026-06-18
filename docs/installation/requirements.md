---
title: Vérifier les prérequis
audience:
  - installer
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: configuration
source_paths:
  - backend/composer.json
  - frontend/admin-vue/package.json
  - config

owners:
  - operations
document_type: procedure
permissions:
source_paths:
  - backend/composer.json
  - ops/.env.example
  - tools/cms.py
  - frontend/admin-vue/package.json
  - frontend/admin-vue/package-lock.json
  - frontend/admin-vue/.nvmrc
generated: false
---
# Vérifier les prérequis

## Runtime recommandé

Le socle de validation du projet utilise les versions suivantes :

- **PHP 8.4.16** pour le moteur applicatif et les commandes PHP ;
- **Node.js 22.16.0** pour construire le back-office ;
- **npm 10.9.2** pour installer les dépendances frontend.

La contrainte Composer reste compatible avec PHP `^8.2`, mais utiliser les versions ci-dessus permet de reproduire l’environnement contrôlé par les validateurs et la chaîne de release.

- Extension `pdo_sqlite` obligatoire pour les bases SQLite.
- Twig `^3.0`, fourni par Composer, par le chemin configuré `APP_TWIG_VENDOR_PATH`, ou par un `../vendor/twig/` partagé entre plusieurs instances sous sous-répertoire.
- Serveur web capable de servir `index.php` et d’appliquer les règles d’accès.
- Python 3 pour les outils de maintenance.

## Construction du back-office

Node.js et npm ne sont nécessaires que pour reconstruire le back-office. Une release officielle peut déjà contenir les assets compilés.

Depuis `frontend/admin-vue/`, installez les dépendances avec :

```bash
npm ci
```

Cette commande utilise strictement le fichier `package-lock.json`. Ne le remplacez pas par une installation flottante avec des versions recalculées : le verrouillage garantit que le build local, la CI et la release utilisent le même arbre de dépendances. Le fichier `.nvmrc` fixe également Node.js 22.16.0.

Composer n’est nécessaire sur la cible que si les dépendances PHP ne sont pas incluses dans la release. L’option `--include-vendor` embarque `vendor/`, `backend/vendor/` et le chemin Twig de runtime configuré, mais jamais `frontend/admin-vue/node_modules/` : une release distribue uniquement les assets du back-office déjà compilés.

## Filesystem

Le processus PHP doit pouvoir écrire dans `storage/` et les bases configurées, mais pas modifier le code applicatif. Les répertoires sensibles doivent être inaccessibles depuis le web.
