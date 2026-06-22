# Prompt 1 — Audit de référence et plan

Effectue un audit approfondi, **strictement en lecture seule**, de l'expérience Blueprints/Fieldsets du CMS. Ne modifie aucun fichier, n'installe aucune dépendance et ne touche ni à la base de données ni à Git.

Objectif : produire un plan d'amélioration fondé sur le code réellement présent, sans commencer l'implémentation.

Analyse au minimum :

- l'architecture de la vue et de ses composants ;
- les flux de création, modification, réorganisation, suppression et activation ;
- la distinction entre sauvegarde automatique, brouillon et version active ;
- le partage et les usages des fieldsets ;
- l'accessibilité clavier et les comportements sur petits écrans ;
- les tests existants, les contrats backend et la documentation concernée ;
- les risques de régression, notamment pour les contenus existants et les installations sous un préfixe variable comme `/mod`, `/eve` ou `/edu`.

Propose ensuite un plan par petites phases réversibles. Pour chaque phase, indique : fichiers concernés, comportement attendu, critères d'acceptation, tests à ajouter ou adapter et risques. Distingue clairement les améliorations purement frontend de celles qui nécessiteraient un changement backend.

Ne conclus pas qu'une modification de base de données est nécessaire sans preuve. Si plusieurs orientations UX sont possibles, présente leurs compromis et recommande la plus simple compatible avec l'architecture actuelle.

Termine par un état explicite confirmant qu'aucun fichier n'a été modifié.

