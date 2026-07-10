---
title: Utiliser les champs et les blocs de contenu
audience:
  - editor
  - publisher
status: stable
last_verified: 2026-06-23
source_of_truth: manual
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - backend/src/Application/Content/BlockDocumentNormalizer.php
  - backend/src/Core/NativeHtmlRenderer.php
  - frontend/admin-vue/src/components/editor/BlockEditor.vue
  - frontend/admin-vue/src/components/blueprints/BlueprintFieldEditorModal.vue
  - frontend/theme-default/templates/partials/content-block.twig
  - database/seeds/native_blueprints.json

owners:
  - editorial
document_type: procedure
generated: false
---
# Utiliser les champs et les blocs de contenu

Cette page explique comment remplir un contenu structuré sans perdre d’information lors de l’enregistrement, de la prévisualisation ou de la publication.

## Profils et droits

Cette procédure concerne principalement les éditeurs et les publicateurs. La permission `content.update` est nécessaire pour modifier les valeurs. L’emploi de HTML brut reste soumis à la permission spécialisée `content.html_raw.manage`.

## Avant de commencer

Ouvrez un contenu dans le bon site et la bonne langue. Le formulaire affiché dépend du blueprint actif : deux types de contenu peuvent donc proposer des champs et des blocs différents.

## Comprendre la structure active

Une structure de contenu définit les sections, champs, groupes réutilisables et blocs proposés par l’éditeur. Elle peut être globale ou propre à un site. Deux sites peuvent donc afficher des formulaires différents même si les contenus portent une clé technique identique.

Les éditeurs utilisent toujours la version active de la structure. Un administrateur peut préparer un brouillon de structure sans modifier immédiatement votre formulaire ; les changements apparaissent seulement après activation explicite.

## Comprendre les trois niveaux

Un **champ** contient une valeur précise, par exemple un titre, une date, une relation ou un média. Un **fieldset** regroupe plusieurs champs réutilisables. Un **bloc** est une unité de contenu ordonnée que l’on peut ajouter, déplacer, désactiver ou compléter séparément.

Pour les pages et les articles natifs, la composition éditoriale se fait dans **Composition par blocs**. Il n’existe pas de champ séparé nommé « Blocs de contenu » à remplir : les blocs, le hero et le SEO disposent de leurs interfaces natives afin d’éviter les doublons et les formulaires ambigus.

Les indications placées à côté des libellés décrivent le format attendu. Respectez-les avant d’enregistrer : elles proviennent du modèle de contenu et peuvent correspondre à une contrainte de publication.

## Configurer un champ dans un blueprint

Dans l’éditeur de blueprints, la fiche d’un champ affiche d’abord les réglages courants : libellé, type, largeur, caractère obligatoire, localisation et texte d’aide. Lorsque le type le permet, une configuration guidée expose les options réellement consommées par le CMS, par exemple les choix d’une liste, les bornes d’un nombre, la politique de texte alternatif d’un média ou les limites d’une relation.

La zone experte reste disponible pour les valeurs techniques : identifiant, cycle de vie, conditions, options, validation et configuration JSON. Elle sert à relire ou ajuster les cas avancés sans masquer les clés historiques ou spécifiques à un projet. Un JSON invalide bloque l’application locale et le texte saisi reste affiché pour correction. Annuler ferme la fiche sans conserver la saisie locale non appliquée.

Changer le type d’un champ ne supprime pas automatiquement les anciennes options. Si certaines valeurs risquent de ne plus être consommées par le nouveau type, elles restent visibles dans la zone experte afin de pouvoir décider explicitement quoi conserver.

## Utiliser un groupe de champs réutilisable

Un fieldset est un groupe partagé. La liste et le détail indiquent les structures qui l’utilisent avec leur libellé, leur clé technique, leur type et leur portée. Avant de modifier un groupe déjà utilisé, vérifiez cette liste : la sauvegarde modifie le groupe commun, mais les contenus continuent d’utiliser la version active de leur structure tant qu’un nouveau brouillon de blueprint n’a pas été activé.

Si l’intention est de créer une adaptation locale, utilisez **Créer une variante** plutôt que de modifier le groupe partagé. Les groupes système sont consultables mais protégés : ils ne peuvent pas être modifiés ou supprimés directement depuis l’interface.

Lorsqu’un fieldset est monté dans une structure, ses champs rejoignent le schéma éditeur comme des champs issus du groupe au moment où le blueprint est enregistré puis activé. Ils ne sont pas recopiés comme champs directs du blueprint ; le montage reste la relation canonique.

## Brouillons et versions de blueprints

Dans l’éditeur de blueprints, **Enregistrer le brouillon** conserve le design de travail et crée ou remplace une version `draft`. Cette action ne modifie pas la version active utilisée par les formulaires de contenu.

**Activer le brouillon** valide la version côté serveur, archive l’ancienne version active et rend la nouvelle version disponible aux éditeurs de contenu. En cas de refus, le brouillon reste disponible pour correction. L’historique des versions permet aussi de réactiver une ancienne version pour revenir en arrière.

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
