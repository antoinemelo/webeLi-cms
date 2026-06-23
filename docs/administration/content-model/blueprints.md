---
title: Gérer le cycle de vie des blueprints
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-23
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - core
document_type: guide
source_paths:
  - backend/routes/api.php
  - backend/src/Blueprints
  - database/schema/core.sql
  - `python3 tools/cms.py validate --category content`
generated: false
---
# Gérer le cycle de vie des blueprints

**Permissions :** `blueprints.read`, `blueprints.manage`, `admin.blueprints.write`, `admin.blueprints.versions.write`, `admin.blueprints.delete` selon l’action.

Un blueprint versionné définit une structure éditoriale : type de ressource, sections, champs, groupes partagés, validation, interface d’édition, capacités SEO, routage, workflow, traduction et permissions. La version active pilote les formulaires de contenu et le contrat consommé par l’API ou les modules concernés.

## Structures, portée et identité

Une structure possède une clé technique stable. Cette clé ne doit pas être renommée silencieusement : si une structure existante doit changer de sens, créez une nouvelle structure ou dupliquez-la explicitement avant d’adapter son contenu.

La portée indique où la structure s’applique :

- **globale** : la même clé s’applique à l’ensemble de l’instance ;
- **site** : la même clé peut exister pour plusieurs sites sans mélanger leurs versions, brouillons ou usages.

Vérifiez toujours le site actif avant de modifier une structure locale. Deux structures avec la même clé mais des portées ou sites différents doivent rester isolées.

## Sections, champs et groupes partagés

Une structure est organisée en sections. Une section contient des champs directs et peut monter un ou plusieurs groupes de champs réutilisables.

Un champ direct appartient à la structure. Un groupe partagé reste un objet distinct, référencé par montage. Modifier un groupe partagé peut donc préparer un changement commun à plusieurs structures, mais les éditeurs de contenu continuent d’utiliser la version active de chaque structure tant qu’un nouveau brouillon n’a pas été activé.

Avant de modifier un groupe partagé, consultez sa liste d’usages. Elle indique les structures concernées avec leur libellé, leur clé, leur type, leur site et leur portée. Si l’objectif est une variante locale, créez une variante plutôt que de modifier le groupe commun.

## Brouillon, activation et retour arrière

**Enregistrer le brouillon** sauvegarde le design de travail et crée ou remplace une version en brouillon. Cette action ne change pas la version active et ne modifie donc pas immédiatement les formulaires utilisés par les éditeurs.

**Activer le brouillon** valide la structure côté serveur, archive l’ancienne version active et rend la nouvelle version disponible aux formulaires de contenu. Si la validation échoue, le brouillon reste disponible pour correction.

L’historique permet de réactiver une ancienne version. Ce retour arrière est volontaire : il doit être déclenché explicitement, après vérification des impacts sur les contenus existants.

## Procédure recommandée

1. Ouvrez la structure dans le bon site et la bonne portée.
2. Dupliquez explicitement si vous devez créer une variation.
3. Ajustez les sections, champs et groupes partagés.
4. Enregistrez le brouillon sans activer.
5. Vérifiez les erreurs, la prévisualisation, les données existantes et les usages des groupes partagés.
6. Activez le brouillon seulement lorsque le contrat est cohérent.
7. Contrôlez l’éditeur, la publication et les surfaces API concernées.

## Risques

Une activation incompatible peut rendre des données non éditables ou non publiables. Testez les contenus existants avant de rendre un champ obligatoire, de changer un type ou de retirer un groupe partagé. Gardez une version de retour identifiée avant activation.
