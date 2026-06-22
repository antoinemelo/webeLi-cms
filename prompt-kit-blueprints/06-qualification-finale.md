# Prompt 6 — Qualification finale et documentation

Qualifie l'ensemble des améliorations Blueprints/Fieldsets déjà réalisées. Cette phase sert à détecter et corriger uniquement les régressions directement liées aux changements précédents ; elle ne doit pas lancer une nouvelle refonte.

Vérifie :

- création, édition, duplication, réorganisation et suppression ;
- champs système protégés et données historiques ;
- groupes partagés et visibilité de leurs usages ;
- sauvegarde automatique, brouillon, activation et erreurs ;
- clavier, focus, libellés accessibles, responsive et états vides ;
- installation à la racine et sous plusieurs préfixes variables, sans valeur `/mod` codée en dur ;
- build de production, typecheck, lint, tests unitaires/intégration et tests Playwright disponibles ;
- absence de régression sur l'accès public et complet à la documentation.

Utilise les scripts officiels du dépôt, notamment `tools/cms.py` lorsqu'ils sont adaptés. Pour Playwright, utilise uniquement des identifiants de test prévus à cet effet. Si `E2E_BASE_URL`, `E2E_ADMIN_EMAIL` ou `E2E_ADMIN_PASSWORD` manquent, n'invente aucune valeur : fournis la commande exacte à exécuter et marque clairement la qualification navigateur comme non démontrée.

Mets à jour la documentation utilisateur afin qu'elle explique simplement Structures de contenu, sections, champs, groupes réutilisables, brouillon et activation. Ne mentionne aucune version spécifique du produit. Mets à jour les scripts de création from scratch uniquement si le modèle natif a réellement changé ; aucune migration ne doit être créée.

Examine enfin le diff complet pour détecter code mort, textes incohérents, routes codées en dur, changements accidentels et fichiers générés indésirables. Fournis un rapport final avec : critères validés, commandes et résultats, éléments non testés, risques résiduels, fichiers modifiés et procédure de retour arrière. N'effectue aucun commit, déploiement ni modification de données de production.
