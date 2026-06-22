---
title: Éditeur visuel intégré
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - core
document_type: guide
source_paths:
  - backend/src/Application/Api/Admin/VisualEditingApiController.php
  - backend/src/Application/VisualEditing/VisualEditingMapBuilder.php
  - backend/src/Application/VisualEditing/SaveVisualField.php
  - backend/routes/api.php
  - frontend/admin-vue/src/components/editor/VisualEditorShell.vue
  - frontend/admin-vue/src/views/content/ContentEditorView.vue
  - frontend/theme-default/assets/js/visual-editing-bridge.js
  - frontend/theme-default/assets/css/visual-editing.css
generated: false
---

# Éditeur visuel intégré

L’éditeur visuel permet de modifier un contenu depuis son rendu dans le thème, sans remplacer l’éditeur structuré. Le back-office charge une prévisualisation dans une iframe, identifie les champs et les blocs rendus par Twig, puis enregistre les changements par l’API administrative.

Cette page décrit le contrat technique de cette intégration. Pour le parcours éditorial, consultez le guide de création et de modification des contenus.

## Composants principaux

Le dispositif repose sur quatre ensembles :

- `VisualEditorShell.vue` affiche l’iframe, le panneau de sélection, les commandes de viewport et les outils d’édition ;
- `visual-editing-bridge.js` relie le document prévisualisé au back-office avec des messages contrôlés ;
- `VisualEditingApiController.php` expose la carte éditable, la prévisualisation, la résolution d’une cible et les opérations d’écriture ;
- les templates Twig portent les attributs `data-amcms-*` nécessaires pour relier un élément rendu à son champ, son bloc et son état éditorial.

L’éditeur structuré reste la solution de référence pour les modifications complexes, les champs non rendus directement et la réorganisation complète des blocs.

## Cycle d’ouverture

1. La vue de contenu ouvre l’onglet **Visuel**.
2. Le back-office demande la carte éditable du contenu.
3. Une URL de prévisualisation de la révision de travail est chargée dans l’iframe.
4. Le pont visuel détecte les éléments portant les attributs `data-amcms-editable` ou `data-amcms-block`.
5. Un clic dans l’iframe transmet une ancre au back-office.
6. Le back-office résout cette ancre et affiche le champ ou le bloc correspondant.

La recherche publique est désactivée dans cette prévisualisation afin d’éviter qu’une interaction de recherche quitte le contexte de la révision en cours.

## Routes administratives

Les routes sont déclarées dans `backend/routes/api.php` :

- `GET /admin/api/visual/entries/{id}/map` : carte des cibles éditables ;
- `GET /admin/api/visual/entries/{id}/preview` : prévisualisation de la révision de travail ;
- `POST /admin/api/visual/resolve` : résolution d’une cible issue de l’iframe ;
- `PATCH /admin/api/visual/entries/{id}/field` : enregistrement d’un champ ;
- `GET /admin/api/visual/entries/{id}/block-locks` : état des verrous de blocs ;
- `POST /admin/api/visual/entries/{id}/block-lock` : acquisition ou libération d’un verrou ;
- `GET /admin/api/visual/entries/{id}/translation-status` : état des traductions du contenu.

Ces routes appartiennent à l’API administrative. Elles ne font pas partie de l’API publique headless.

## Attributs du rendu Twig

Les templates doivent exposer des attributs stables, sans dépendre d’un sélecteur CSS de présentation :

- `data-amcms-editable` identifie une valeur éditable ;
- `data-amcms-field-path` fournit le chemin du champ ;
- `data-amcms-block="1"` identifie le conteneur d’un bloc ;
- `data-amcms-editorial-status` porte l’état éditorial du bloc ;
- les attributs média identifient uniquement l’élément média concerné, jamais le conteneur entier du bloc.

Lorsqu’un nouveau composant Twig doit être éditable visuellement, ajoutez ces attributs au plus près de la valeur rendue. Vérifiez ensuite qu’un clic sélectionne le bon champ et non son bloc parent.

## Enregistrement et révisions

Le premier enregistrement crée le brouillon. Les changements suivants créent ou mettent à jour une révision de travail selon le cycle éditorial normal. L’éditeur visuel ne publie pas implicitement le contenu.

Après l’enregistrement :

1. le message de succès ou d’erreur est envoyé au système global de notifications ;
2. la prévisualisation est rechargée ;
3. l’ancre du champ sélectionné est restaurée ;
4. la sélection reste cohérente après un changement de langue lorsque le champ existe dans la nouvelle localisation.

Le bouton d’action du panneau de champ est libellé **Enregistrer**. La publication reste pilotée par le panneau de publication commun à l’éditeur structuré et à l’éditeur visuel.

## Verrous collaboratifs

Un bloc sélectionné peut être verrouillé pendant son édition. Le client conserve le `lock_token` retourné par l’API et l’envoie lors des opérations qui nécessitent de prouver la possession du verrou.

Si un autre utilisateur détient le verrou :

- le champ ne doit pas être modifiable ;
- le panneau affiche l’identité fournie par le serveur ;
- aucune écriture forcée ne doit être tentée depuis l’interface.

L’utilisateur peut libérer son propre verrou avec **Déverrouiller**. Cette action ferme immédiatement l’édition du champ sélectionné et oublie l’ancre locale. Les verrous expirés ou abandonnés sont traités par le service serveur, pas par une suppression arbitraire côté navigateur.

## États éditoriaux des blocs

Le thème affiche les états `draft`, `review` et `ready` sur les blocs. Leur signal visuel doit rester prioritaire sur la sélection technique du bloc. Le badge du type de bloc est masqué au repos et apparaît au survol ou lorsque le bloc est sélectionné.

La synchronisation des états après une modification passe par le message `amcms:visual-sync-block-statuses` entre le back-office et le pont de l’iframe.

## Langues et état de traduction

L’API `translation-status` fournit l’état des localisations et, lorsqu’une traduction est complète, son horodatage `completed_at`. L’interface affiche cet instant sous la forme **Complété le …**.

L’horodatage suit en priorité la dernière révision de travail, puis la date de création de cette révision, et enfin la date de localisation disponible. Une traduction complète ne signifie pas que le contenu est publié.

## Viewports

Les boutons **Desktop**, **Tablette** et **Mobile** modifient la largeur de l’iframe. Ils sont intégrés à la barre des onglets et ne sont visibles qu’en mode visuel. Ils servent à contrôler la mise en page ; ils ne simulent pas toutes les caractéristiques d’un appareil réel.

## Ajouter un champ éditable visuellement

1. Vérifiez que le champ appartient au blueprint et qu’il est exposé dans la révision de travail.
2. Rendez sa valeur dans Twig avec un `data-amcms-field-path` stable.
3. Évitez de placer l’attribut sur un conteneur plus large que la valeur concernée.
4. Vérifiez la résolution de la cible avec l’endpoint `/admin/api/visual/resolve`.
5. Enregistrez la valeur et contrôlez la création de la révision.
6. Rechargez l’iframe et vérifiez la restauration de la sélection.
7. Répétez le test dans chaque langue applicable.
8. Vérifiez le comportement lorsqu’un autre utilisateur verrouille le bloc.

## Ajouter un bloc compatible

Un bloc compatible doit :

- être rendu par un template portant `data-amcms-block="1"` ;
- exposer séparément ses champs directement éditables ;
- transmettre son état éditorial ;
- ne pas confondre le clic sur le bloc avec un clic sur un média enfant ;
- conserver un accès à **Édition du bloc** pour les propriétés qui ne sont pas éditables en place.

## Sécurité

Le pont n’accepte que les messages attendus du contexte de prévisualisation. Toute évolution doit préserver :

- la vérification de l’origine et du contexte de l’iframe ;
- les permissions de lecture et de modification du contenu ;
- le contexte du site et de la langue ;
- la validation serveur du chemin de champ ;
- les contrôles de concurrence et de verrou ;
- l’échappement normal des valeurs dans Twig.

Ne faites jamais confiance à un chemin de champ, un identifiant de bloc ou une valeur transmis uniquement par le navigateur.

## Vérifications

Après une modification de l’éditeur visuel, exécutez au minimum :

```bash
python3 -m tools.python.validators.g10_validate_visual_editor
python3 tools/cms.py docs check
```

Contrôlez également manuellement :

- la sélection d’un champ texte, d’un surtitre et d’un média ;
- l’enregistrement du premier brouillon puis d’une révision ;
- la restauration de la sélection après rechargement ;
- le changement de langue ;
- les trois viewports ;
- le verrouillage concurrent d’un bloc ;
- l’affichage des états éditoriaux ;
- la désactivation de la recherche dans la prévisualisation ;
- les notifications globales de succès et d’erreur.

## Sources de vérité

Les routes déclarées dans `backend/routes/api.php`, les contrôleurs et services PHP, le composant `VisualEditorShell.vue`, le pont `visual-editing-bridge.js` et les attributs produits par les templates Twig constituent les sources de vérité. Cette page explique leur usage mais ne remplace pas leurs contrats exécutables.
