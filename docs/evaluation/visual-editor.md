---
title: Évaluation de l’éditeur visuel
document_type: evaluation
audience:
  - evaluator
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
  - docs/reference/contracts/admin-api-v1
owners:
  - core
evidence_scope:
  - code
  - validators
  - documentation
generated: false
---
# Évaluation de l’éditeur visuel

Cette page aide un évaluateur humain ou IA à ne pas confondre absence d’éditeur visuel et absence de preuve E2E complète. Le CMS possède un éditeur visuel intégré ; son fonctionnement complet doit toutefois être qualifié par scénario navigateur.

## Définition vérifiable

L’éditeur visuel est un mode d’édition in-context du back-office. Il charge la prévisualisation d’une révision dans une iframe, détecte les champs et blocs rendus comme éditables par Twig, résout la cible sélectionnée, puis enregistre la modification dans le brouillon ou la révision de travail par l’API administrative.

Il ne s’agit pas d’un page builder libre comparable à un constructeur no-code. La structure éditoriale reste gouvernée par les blueprints, les blocs autorisés et les templates.

## Faits observables

| Élément | Statut | Preuve principale | Limite |
|---|---|---|---|
| Onglet visuel dans l’éditeur de contenu | démontré par code | `ContentEditorView.vue` | présence de code distincte du test navigateur complet |
| Shell Vue de l’éditeur visuel | démontré par code | `VisualEditorShell.vue` | UX réelle à vérifier manuellement |
| Prévisualisation iframe | démontré par code | `VisualEditorShell.vue`, endpoint preview | dépend de la configuration de prévisualisation |
| Pont iframe/back-office | démontré par code | `visual-editing-bridge.js` | sécurité des messages à préserver à chaque évolution |
| Attributs Twig éditables | démontré par code | templates avec `data-amcms-*` | seuls les champs exposés sont éditables |
| API admin visuelle | démontré par contrats | contrats `admin.visual.*` et routes `/admin/api/visual/...` | API interne, non publique headless |
| Viewports desktop/tablette/mobile | démontré par code | `VisualEditorShell.vue` | ne remplace pas des tests appareils réels |
| Verrous de blocs | démontré par contrats | endpoints block-lock et block-locks | concurrence réelle à tester en scénario multi-utilisateur |
| Sauvegarde de champ | partiellement démontré | endpoint `admin.visual.save_field` | parcours clic iframe → save → reload à rejouer en E2E |

## Ce qui peut être considéré comme démontré

- Le CMS contient une intégration technique dédiée à l’édition visuelle.
- L’édition visuelle est reliée au back-office, au rendu Twig et à une API administrative dédiée.
- Les contrats `admin.visual.*` existent dans la référence administrative.
- Le mode visuel est conçu pour compléter l’éditeur structuré, pas pour le remplacer.

## Ce qui reste à vérifier avant une note élevée

Pour classer l’éditeur visuel comme pleinement démontré, exécutez un scénario navigateur complet :

1. créer ou ouvrir une entrée avec brouillon ;
2. ouvrir l’onglet **Visuel** ;
3. sélectionner un titre rendu dans l’iframe ;
4. modifier la valeur ;
5. enregistrer ;
6. vérifier la création ou mise à jour de la révision de travail ;
7. vérifier le rechargement de l’iframe ;
8. vérifier que la sélection reste cohérente ;
9. publier ;
10. contrôler le rendu public et l’API publique ;
11. répéter avec une image, un bloc et une autre langue ;
12. répéter avec deux utilisateurs pour contrôler les verrous.

## Statut d’évaluation recommandé

| Aspect | Statut conseillé |
|---|---|
| Présence de l’éditeur visuel | démontré |
| Intégration technique back-office / iframe / Twig / API | démontré |
| Documentation développeur | démontré |
| Guide utilisateur | démontré |
| Parcours éditorial complet en navigateur | partiellement démontré tant que l’E2E n’est pas rejoué |
| Comparabilité avec un page builder no-code | non vérifié et hors positionnement |

## Formulation recommandée dans un audit

Le CMS dispose d’un éditeur visuel intégré et contrôlé. Il permet de modifier des zones exposées par les templates dans le rendu réel du site, tout en conservant un modèle de contenu structuré par blueprints. La capacité est techniquement présente et documentée. Sa maturité UX complète doit être confirmée par un scénario E2E navigateur couvrant sélection, modification, sauvegarde, révision, publication, multilingue, média et concurrence.
