# DEC CMS — CMS éditorial SEO-first

DEC CMS est un CMS PHP/Twig multisite et multilingue avec back-office Vue, contenus versionnés, publication par projections, SEO natif, médias, recherche, API headless, modules et export statique.

## Documentation

- Point d’entrée : [`docs/README.md`](docs/README.md)
- Guide utilisateur : [`docs/user-guide/README.md`](docs/user-guide/README.md)
- Administration : [`docs/administration/README.md`](docs/administration/README.md)
- Installation : [`docs/installation/README.md`](docs/installation/README.md)
- Exploitation : [`docs/operations/README.md`](docs/operations/README.md)
- Développement : [`docs/development/README.md`](docs/development/README.md)
- Références générées : [`docs/reference/generated/README.md`](docs/reference/generated/README.md)
- Évaluation indépendante : [`docs/evaluation/README.md`](docs/evaluation/README.md)

## Commandes principales

Deux contextes sont volontairement séparés :

- **Archive release installée** : contrôles non destructifs et autonomes, sans tests source ni dépendances de build.
- **Dépôt source complet** : contrôles de développement, tests automatisés, qualification et packaging.

Depuis une archive release installée :

```bash
python3 tools/cms.py smoke
python3 tools/cms.py validate
python3 tools/cms.py docs check
```

Depuis le dépôt source complet :

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py test
python3 tools/cms.py qualify --profile complete
python3 tools/cms.py release --package --verify-archive --include-vendor
```

`python3 tools/cms.py test` est réservé au dépôt source complet. Si les tests source sont absents, la commande signale explicitement d'utiliser les contrôles release ci-dessus.

Pour une installation existante avec contenu, la mise à jour normale passe par sauvegarde, migrations incrémentales et validation ; voir [`docs/operations/existing-database-update.md`](docs/operations/existing-database-update.md). `python3 tools/cms.py rebuild` reste disponible pour le développement, les tests et la récupération contrôlée, mais ne doit pas servir à mettre à jour une instance contenant des données utiles.

La procédure d’installation publique est décrite dans [`docs/installation/README.md`](docs/installation/README.md). Une release destinée à être installée sans Composer doit être créée avec `--include-vendor`.
