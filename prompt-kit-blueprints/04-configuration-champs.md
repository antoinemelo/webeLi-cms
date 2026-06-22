# Prompt 4 — Configuration des champs sans JSON imposé

Améliore progressivement la fenêtre de configuration des champs. Le parcours courant doit être utilisable sans éditer du JSON, tout en conservant un mode expert compatible avec les configurations existantes.

Dans la vue principale, privilégie : libellé, type, obligatoire, multilingue, aide et largeur. Place l'identifiant technique, l'état désactivé, les options avancées, les conditions et les représentations JSON dans une zone experte clairement signalée.

Pour les types de champs courants, fournis des contrôles typés pour les options et validations réellement supportées par le backend. Ne déduis pas le contrat : retrouve les schémas, validateurs et tests existants. La conversion entre contrôles et payload doit être déterministe et sans perte. Une configuration inconnue ou historique doit rester visible et sauvegardable sans être silencieusement supprimée.

Garde-fous :

- aucun changement de format persistant ou d'API ;
- aucune normalisation destructive du JSON existant ;
- aucune valeur par défaut appliquée rétroactivement sans action utilisateur ;
- validation claire des erreurs avant envoi ;
- possibilité de revenir au contenu initial tant que la modification n'est pas confirmée ;
- tests de round-trip pour configurations simples, avancées, inconnues et invalides ;
- compatibilité avec les structures et fieldsets existants.

Procède par types de champs prioritaires et limite explicitement la portée si tous les types ne peuvent pas être traités proprement dans cette phase. Exécute les contrôles automatisés pertinents et documente les cas encore réservés au mode expert. Ne modifie pas la base de données et n'effectue aucun commit.

