# Prompt 2 — Clarification UX à faible risque

Implémente uniquement les améliorations UX à faible risque de la gestion des Blueprints/Fieldsets, à partir de l'audit précédent. Travaille par modifications ciblées ; ne réécris pas entièrement la vue et ne change aucun contrat backend, schéma de données, endpoint ou format de payload.

Objectifs :

- présenter « Structures de contenu » comme libellé utilisateur principal, tout en conservant les identifiants techniques internes nécessaires ;
- présenter « Groupes de champs réutilisables » à la place de « Fieldsets » dans l'interface destinée aux utilisateurs ;
- harmoniser les termes français visibles : identifiant technique, onglet, section, panneau latéral, usages ;
- ajouter les aides contextuelles réellement nécessaires ;
- masquer les paramètres experts derrière une section « Options avancées » fermée par défaut ;
- améliorer recherche, filtres et distinction entre structures éditoriales, modules et éléments système, sans changer leurs données ;
- clarifier les états « enregistré », « brouillon » et « actif » sans modifier leur sémantique.

Garde-fous obligatoires :

- préserver toutes les fonctionnalités et valeurs existantes ;
- ne pas renommer les clés, types, routes, événements ou propriétés persistées ;
- ne pas casser les URLs lorsque le CMS est installé sous un répertoire autre que `/mod` ;
- ne pas ajouter de dépendance sauf nécessité démontrée et accord explicite ;
- conserver l'accès public et complet à la documentation déjà prévu par le projet ;
- ajouter ou adapter des tests ciblés pour les comportements modifiés ;
- exécuter les contrôles disponibles (typecheck, lint, tests unitaires et build pertinents).

Avant toute modification, inspecte le worktree et préserve les changements qui ne t'appartiennent pas. Si une ambiguïté peut modifier des données ou un contrat public, arrête-toi et demande confirmation.

À la fin, fournis : résumé fonctionnel, liste des fichiers modifiés, tests exécutés avec résultats, tests non exécutés avec raison, risques résiduels et commandes de vérification manuelle. N'effectue aucun commit.

