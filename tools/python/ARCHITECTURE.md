# Architecture des outils DEC CMS

```text
tools/cms.py
  -> python/cms/cli.py
     -> python/commands/
        -> python/operations/
        -> python/validators/registry.py
        -> python/generators/
```

La CLI est publique. Tous les autres modules sont internes, sauf besoin d’exploitation explicitement documenté.
Les validateurs sont découverts exclusivement dans `tools/python/validators/` et exécutés comme modules Python afin de garantir des imports stables depuis n’importe quel répertoire courant.


## Qualification globale

La commande canonique est `python3 tools/cms.py qualify --profile complete`. Les profils `quick`, `complete` et `release` produisent des rapports JSON et Markdown et distinguent explicitement les étapes ignorées des succès. Aucun profil ne reconstruit les bases de données ; cette opération reste réservée à `python3 tools/cms.py rebuild`. Voir `docs/development/testing-validation/QUALIFICATION_COMMANDS.md`.
