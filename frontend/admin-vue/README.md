# Back-office Vue

Le back-office Vue est l’interface d’administration du CMS. Les sources sont dans `frontend/admin-vue/`; le build livré au runtime PHP est dans `admin-app/`.


## Chemin `node_modules` configurable

Le build lit `ops/.env` depuis la racine du projet. Par défaut, les dépendances Vue sont recherchées dans :

```env
APP_VUE_NODE_MODULES_PATH=./vendor/node_modules/
```

Pour un développement local classique, on peut utiliser :

```env
APP_VUE_NODE_MODULES_PATH=./frontend/admin-vue/node_modules/
```

Le même chemin est utilisé par les scripts Python de packaging/déploiement afin d’éviter d’inclure ou de supprimer accidentellement les dépendances.

## Développement

```bash
cd frontend/admin-vue
npm install
npm run dev
```

## Build livré

```bash
cd frontend/admin-vue
npm run build
```

Après build, vérifier que `admin-app/` contient l’application compilée servie par `/admin/app`.


## Édition des contenus

Dans `ContentEditorView`, l’onglet Structure doit rester volontairement sobre : champs principaux, éditeur natif des blocs JSON et notes de changement. Le renderer générique `SchemaFormRenderer` ne doit pas être ajouté en bas de l’écran page/article, car il recrée un menu local Contenu / Hero / SEO redondant avec les onglets principaux de l’éditeur.

## Responsabilités

Le back-office permet actuellement de gérer :

- contenus, brouillons, publications et prévisualisations ;
- blocs éditoriaux natifs ;
- menus et items ;
- taxonomies et termes ;
- médiathèque, métadonnées, variantes et usages ;
- audit SEO ;
- configuration ;
- profil ;
- IAM : utilisateurs, rôles, permissions, sessions et audit.

## Contrats API

Vue consomme uniquement les endpoints `/admin/api/*`. Les contrats sont documentés dans :

```text
docs/contracts/admin-api-v1/
```

Toute route admin active doit avoir un contrat v1 et être validée par :

```bash
python3 tools/python/v_validate_admin_contracts.py
```

## Principes UI

- L’état affiché par Vue ne remplace jamais l’état serveur.
- Toute mutation passe par l’API admin.
- Les permissions serveur restent l’autorité.
- La langue de contenu active vient du contexte admin.
- Les opérations de tri ou drag-and-drop doivent persister l’ordre dans la base via API.
- Une évolution visible en production nécessite la modification des sources Vue et du build `admin-app/`.

## Menus et taxonomies

Les écrans menus et taxonomies utilisent une logique en panneaux : liste, structure/éléments, édition et réglages. Cette organisation doit rester lisible sur desktop et acceptable sur mobile, sans masquer les champs essentiels comme l’ordre, les traductions ou le SEO.
