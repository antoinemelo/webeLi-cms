# Prompt 5 — Impacts des groupes partagés et gestion des versions

Rends explicites et sûrs les effets d'une modification de groupe de champs réutilisable ainsi que le cycle brouillon/version active. Ne change pas la sémantique métier existante sans preuve et sans validation préalable.

Avant implémentation, retrace précisément les appels frontend/backend qui calculent les usages, enregistrent un brouillon et activent une version. Si les informations nécessaires ne sont pas exposées par l'API, arrête-toi et propose le plus petit changement de contrat possible avec tests et compatibilité, au lieu de l'inventer.

Résultat attendu :

- afficher les structures exactes utilisant un groupe partagé, pas seulement un compteur ;
- proposer une action claire pour consulter ces usages ;
- expliquer l'impact avant une modification ou suppression partagée ;
- distinguer visuellement une sauvegarde de brouillon d'une activation ;
- afficher la version active et les modifications en attente ;
- demander une confirmation contextualisée avant toute activation ou action à impact multiple ;
- empêcher les doubles soumissions et afficher correctement succès et erreurs.

Préserve tous les contrats, données et permissions actuels sauf changement minimal explicitement justifié. Aucune migration n'est attendue. Si un ajout backend est indispensable, il doit être rétrocompatible, testé, documenté et intégré aux scripts de création from scratch appropriés.

Ajoute des tests portant sur les usages partagés, les refus backend, l'enregistrement de brouillon, l'activation, les erreurs réseau et les actions répétées. Vérifie également les chemins avec préfixe dynamique. N'effectue aucun commit.

