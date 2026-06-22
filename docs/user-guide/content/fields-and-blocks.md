---
title: Utiliser les champs et les blocs de contenu
audience:
  - editor
  - publisher
status: stable
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - backend/src
  - frontend/admin-vue/src

owners:
  - editorial
document_type: procedure
source_paths:
  - backend/src/Application/Content/BlockDocumentNormalizer.php
  - backend/src/Core/NativeHtmlRenderer.php
  - frontend/admin-vue/src/components/editor/BlockEditor.vue
  - frontend/theme-default/templates/partials/content-block.twig
  - database/seeds/native_blueprints.json
generated: false
---
# Utiliser les champs et les blocs de contenu

Cette page explique comment remplir un contenu structuré sans perdre d’information lors de l’enregistrement, de la prévisualisation ou de la publication.

## Profils et droits

Cette procédure concerne principalement les éditeurs et les publicateurs. La permission `content.update` est nécessaire pour modifier les valeurs. L’emploi de HTML brut reste soumis à la permission spécialisée `content.html_raw.manage`.

## Avant de commencer

Ouvrez un contenu dans le bon site et la bonne langue. Le formulaire affiché dépend du blueprint actif : deux types de contenu peuvent donc proposer des champs et des blocs différents.

## Comprendre les trois niveaux

Un **champ** contient une valeur précise, par exemple un titre, une date, une relation ou un média. Un **fieldset** regroupe plusieurs champs réutilisables. Un **bloc** est une unité de contenu ordonnée que l’on peut ajouter, déplacer, désactiver ou compléter séparément.

Les indications placées à côté des libellés décrivent le format attendu. Respectez-les avant d’enregistrer : elles proviennent du modèle de contenu et peuvent correspondre à une contrainte de publication.

## Remplir les champs

1. Vérifiez la langue active avant toute saisie.
2. Complétez d’abord les champs obligatoires.
3. Utilisez le sélecteur de média ou de relation lorsqu’il est proposé, plutôt que de saisir un identifiant à la main.
4. Conservez une URL, une ancre ou une clé technique stable lorsqu’elle est déjà utilisée publiquement.
5. Enregistrez le brouillon avant d’ouvrir la prévisualisation.

Un champ localisé ne modifie que la langue active. Une valeur absente dans une traduction n’est pas automatiquement remplacée par une traduction improvisée.

## Blocs disponibles

Le CMS reconnaît les blocs natifs suivants :

- `markdown` : texte rédigé en Markdown ;
- `richtext` : texte enrichi produit par l’éditeur ;
- `html_safe` : HTML limité au contrat sûr du CMS ;
- `html_raw` : HTML brut réservé aux personnes autorisées ;
- `iframe` : contenu externe affiché dans une iframe ;
- `embed` : intégration d’un contenu externe compatible ;
- `image` : image unique et ses informations éditoriales ;
- `video` : vidéo issue de la médiathèque ou d’une source autorisée ;
- `audio` : fichier ou source audio ;
- `hero` : en-tête visuel avec titre, texte, image et boutons ;
- `gallery` : série d’images ;
- `buttons` : groupe d’actions ;
- `card` : carte éditoriale ;
- `columns` : colonnes contenant d’autres blocs ;
- `form` : formulaire référencé par sa clé ;
- `plan` : liste structurée de pages, taxonomies ou contenus ;
- `articles` : sélection ou liste d’articles.

La liste réellement proposée dans l’éditeur dépend du blueprint. L’absence d’un bloc dans le sélecteur signifie généralement qu’il n’est pas autorisé pour ce type de contenu.

## Ajouter et organiser un bloc

1. Choisissez **Ajouter un bloc**.
2. Sélectionnez un type autorisé.
3. Renseignez les données demandées.
4. Placez le bloc à l’endroit voulu.
5. Enregistrez le brouillon.
6. Contrôlez le résultat dans la prévisualisation.

Dans un bloc `columns`, chaque colonne peut contenir ses propres sous-blocs. Vérifiez le rendu sur une largeur étroite : un ordre logique sur ordinateur doit rester compréhensible sur mobile.

## Points de vigilance par type

- Un bloc `iframe` doit avoir une source valide.
- Un bloc `form` doit référencer une `form_key` existante.
- Les blocs `image`, `video` et `audio` doivent référencer un média ou une source utilisable.
- Une `gallery` vide et un groupe `buttons` sans action ne doivent pas être publiés.
- Un bouton doit toujours avoir un libellé et une destination.
- Le bloc `hero` doit contenir au moins un titre, un texte, une image ou une action.
- Une page publique ne doit comporter qu’un titre principal cohérent. Lorsque le `hero` porte ce titre, utilisez le niveau `h1` une seule fois.

## État éditorial d’un bloc

Un bloc peut être conservé dans le document sans être rendu publiquement. Seuls les blocs activés et dans un état publiable sont intégrés à la projection publique. Cette règle permet de préparer une section sans l’exposer immédiatement.

Un bloc inconnu, incomplet ou désactivé peut rester visible dans une révision tout en étant absent du rendu public. Ne concluez donc pas qu’un enregistrement réussi garantit sa publication.

## Résultat attendu

Après enregistrement, les champs et blocs sont présents dans le brouillon. La prévisualisation affiche leur ordre et leur rendu. Après publication, seuls les blocs valides et publiables apparaissent sur le site et dans les représentations publiques concernées.

## En cas de problème

**Le bloc n’apparaît pas dans le sélecteur.** Il n’est probablement pas autorisé par le blueprint du contenu.

**Le bloc est enregistré mais absent de la page publique.** Vérifiez qu’il est activé, publiable et complet, puis republiez le contenu.

**Une image ou une vidéo ne s’affiche pas.** Contrôlez le média associé, sa disponibilité et la prévisualisation.

**La publication est refusée.** Corrigez les champs obligatoires et les erreurs propres au bloc indiquées par l’interface.

Pour comprendre le statut général du contenu et ses révisions, consultez [Statuts, révisions et recherche](statuses-revisions-search.md).
