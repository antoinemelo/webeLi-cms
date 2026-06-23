---
title: Utiliser l’éditeur visuel
audience:
  - editor
  - publisher
  - seo
status: stable
last_verified: 2026-06-22
source_of_truth: manual
source_paths:
  - frontend/admin-vue/src/components/editor/VisualEditorShell.vue
  - frontend/admin-vue/src/views/content/ContentEditorView.vue
  - frontend/theme-default/assets/js/visual-editing-bridge.js
  - frontend/theme-default/assets/css/visual-editing.css
  - backend/src/Application/Api/Admin/VisualEditingApiController.php
  - backend/src/Application/VisualEditing
  - backend/routes/api.php
owners:
  - editorial
document_type: procedure
generated: false
---
# Utiliser l’éditeur visuel

L’éditeur visuel complète l’éditeur structuré. Il affiche une prévisualisation de la page dans son thème réel, permet de sélectionner les zones déclarées comme éditables, puis d’enregistrer les changements dans le cycle normal des brouillons et révisions.

Il ne s’agit pas d’un constructeur de page libre. La structure du contenu reste définie par les blueprints, les blocs autorisés et les templates du thème.

## Quand l’utiliser

Utilisez l’éditeur visuel pour :

- relire un contenu dans son rendu réel ;
- corriger rapidement un titre, un texte court ou une image exposée par le template ;
- vérifier la cohérence entre contenu, blocs et page publique ;
- contrôler le rendu desktop, tablette et mobile avant transmission ou publication.

Utilisez plutôt l’éditeur structuré pour :

- créer une entrée complète ;
- réorganiser fortement les blocs ;
- remplir des champs techniques ou non visibles dans le rendu ;
- corriger les validations de blueprint ;
- traiter les métadonnées SEO avancées.

## Public et droits

**Profils concernés :** éditeur, publicateur, responsable SEO, administrateur.  
**Permissions nécessaires :** lecture du contenu, création ou modification de brouillon, accès au contexte site/langue concerné. La publication reste soumise aux permissions de publication.

## Procédure

1. Ouvrez **Contenus**, choisissez le site, la langue et l’entrée à modifier.
2. Enregistrez d’abord le contenu si aucun brouillon n’existe.
3. Ouvrez l’onglet **Visuel**.
4. Attendez le chargement de la prévisualisation dans l’iframe.
5. Cliquez sur une zone éditable. Le panneau latéral affiche le champ ou le bloc correspondant.
6. Modifiez la valeur proposée.
7. Cliquez sur **Enregistrer**.
8. Vérifiez que la prévisualisation se recharge et que la zone sélectionnée reste cohérente.
9. Contrôlez le rendu avec les boutons **Desktop**, **Tablette** et **Mobile**.
10. Transmettez au publicateur ou publiez selon votre rôle et le workflow prévu.

## Ce que l’éditeur visuel modifie réellement

Un enregistrement depuis l’éditeur visuel met à jour le brouillon ou la révision de travail. Il ne publie pas automatiquement le contenu public. Le site continue de servir la dernière projection publiée tant qu’une publication n’a pas été effectuée.

## Zones éditables

Une zone n’est éditable que si le template du thème l’expose explicitement. Les champs éditables sont reliés au contenu par des attributs techniques produits dans le HTML rendu. Si une zone n’est pas sélectionnable, cela signifie généralement que le champ n’est pas exposé visuellement ou qu’il doit être modifié dans l’éditeur structuré.

## Viewports

Les boutons **Desktop**, **Tablette** et **Mobile** changent la largeur de la prévisualisation. Ils servent à repérer les problèmes de mise en page courants. Ils ne remplacent pas un test complet sur appareils réels.

## Verrous et collaboration

Lorsqu’un bloc est ouvert en édition, le CMS peut le verrouiller pour éviter deux modifications concurrentes. Si un autre utilisateur détient le verrou, l’interface doit empêcher l’écriture et indiquer l’état du bloc. Libérez votre verrou lorsque vous avez terminé.

## Limites à connaître

- seuls les champs exposés par le template sont éditables visuellement ;
- l’éditeur visuel ne remplace pas les blueprints ;
- l’éditeur visuel ne publie pas implicitement ;
- certains champs complexes, listes ou propriétés de blocs restent plus adaptés à l’éditeur structuré ;
- une prévisualisation desktop/tablette/mobile ne garantit pas le comportement exact de tous les navigateurs et appareils.

## Dépannage

**Je ne peux pas cliquer sur une zone.**  
Le champ n’est probablement pas déclaré comme éditable dans le template. Modifiez-le dans l’éditeur structuré ou demandez au développeur d’exposer ce champ.

**J’ai enregistré, mais le site public ne change pas.**  
L’enregistrement a modifié le brouillon ou la révision de travail. Publiez le contenu pour remplacer la projection publique.

**La prévisualisation ne charge pas.**  
Régénérez la prévisualisation depuis l’éditeur. Si le problème persiste, vérifiez vos droits, le site actif, la langue et la configuration de prévisualisation.

**Le rendu mobile semble différent d’un téléphone réel.**  
Le viewport mobile aide à contrôler la largeur et la lisibilité. Il ne simule pas toutes les particularités d’un appareil réel.
