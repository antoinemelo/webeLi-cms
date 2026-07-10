---
title: Dépendances frontend de qualification
audience:
  - developer
  - administrator
status: current
last_verified: 2026-07-08
source_of_truth: frontend/admin-vue/package-lock.json
owners:
  - core-team
document_type: troubleshooting
---

# Dépendances frontend de qualification

Le build frontend exige une installation cohérente avec `package-lock.json` et un audit npm sans vulnérabilité `high` ou `critical`.

Depuis le correctif P0-06D, la qualification **ne lance jamais `npm ci` automatiquement**. Ce choix évite qu’un profil `complete` ou `release` devienne lent, dépendant du réseau, ou bloqué par un registre mal configuré. L’étape `frontend-dependencies` vérifie uniquement :

1. que `package-lock.json` ne contient pas d’URL de registre interne ou non distribuable ;
2. que `npm audit --audit-level=high` retourne 0.

Le build frontend est contrôlé ensuite par l’étape `frontend-build`. Si `node_modules` est absent, incomplet ou corrompu, le build échoue explicitement et indique que l’installation locale doit être recréée.

Quand les sources frontend, `package.json`, `package-lock.json`, `tsconfig.json`, `vite.config.ts` et `index.html` n’ont pas changé depuis un build réussi, la qualification peut réutiliser le cache local `storage/qualification/cache/frontend-build.json` et les assets existants sous `admin-app/`. Ce cache ne saute pas `npm audit`; il évite seulement une recompilation inutile. Utiliser `python3 tools/cms.py qualify --profile complete --no-cache` pour forcer la recompilation.

## Remise en état locale

Après une modification de `package.json` ou `package-lock.json`, ou après application d’un patch de dépendances, recréer localement `node_modules` une seule fois :

```bash
cd frontend/admin-vue
rm -rf node_modules
npm ci
npm audit --audit-level=high
npm run build
```

Puis relancer la qualification depuis la racine du dépôt :

```bash
python3 tools/cms.py qualify --profile complete
# ou, avant diffusion :
python3 tools/cms.py qualify --profile release
```

## Contrôle intégré

Les profils `complete` et `release` exécutent :

```bash
npm audit --audit-level=high
```

La CI doit installer les dépendances explicitement avec `npm ci` avant d’appeler la qualification. Le contrôle de qualification ne modifie pas `node_modules` ; il vérifie l’audit de sécurité et laisse le build prouver que l’installation locale est utilisable.

Pour la release v1, la règle est stricte : aucune exception n'est acceptée par défaut. Une exception éventuelle doit indiquer la date, le paquet concerné, le chemin de dépendance, la raison pour laquelle le paquet n'est pas livré au runtime, et une date de réévaluation.
