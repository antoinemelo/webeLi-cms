# Prompt 3 — Hiérarchie claire de l'éditeur

Améliore l'éditeur des Structures de contenu afin que la hiérarchie `sections → champs → groupes réutilisables` soit immédiatement compréhensible. Appuie-toi sur les composants et conventions existants ; évite une réécriture globale.

Résultat attendu :

- la structure complète est lisible dans une zone principale cohérente ;
- la sélection d'un élément ouvre ses propriétés dans un inspecteur clairement identifié ;
- les actions ajouter, dupliquer, déplacer et supprimer restent proches de l'élément concerné ;
- le glisser-déposer reste disponible, avec une alternative clavier accessible ;
- le focus, les libellés accessibles, les états vides et les confirmations sont corrects ;
- sur écran étroit, les panneaux ne deviennent pas inutilisables et aucune action essentielle ne disparaît ;
- les champs système protégés restent protégés ;
- l'ordre et les identifiants persistés restent strictement compatibles avec les données actuelles.

Contraintes : aucun changement de schéma de base, aucune migration, aucun changement d'API ou de payload sans arrêt préalable et justification. Ne déplace pas des responsabilités métier dans le frontend. Préserve les installations sous préfixe dynamique et les modifications étrangères déjà présentes dans le worktree.

Commence par identifier les tests qui verrouillent le comportement actuel. Ajoute ensuite des tests ciblés pour la sélection, la réorganisation, l'alternative clavier, les éléments protégés et le responsive. Exécute typecheck, lint, tests et build pertinents. Si les tests E2E exigent des secrets ou une URL indisponibles, ne les invente pas : indique précisément la commande et les variables nécessaires.

Termine par un compte rendu du diff, des validations réalisées et des limites restantes. N'effectue aucun commit et ne commence pas la phase de configuration avancée des champs.

