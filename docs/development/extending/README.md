---
title: Étendre le CMS
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Étendre le CMS

Toute extension suit la même séquence :

1. identifier le domaine et la source de vérité ;
2. modifier le schéma ou le contrat avant l’interface lorsqu’ils sont concernés ;
3. ajouter la règle métier dans un service testable ;
4. appliquer les permissions côté serveur ;
5. préserver le contexte de site et de langue ;
6. mettre à jour les projections, l’API ou l’export si le contenu devient public ;
7. ajouter le bon niveau de test ;
8. régénérer les références et exécuter la qualification.

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py docs generate
python3 tools/cms.py validate --full
python3 tools/cms.py test
```

## Choisir la fiche adaptée

- [Route ou endpoint](route-endpoint.md)
- [Module, type de contenu ou blueprint](module-content-type.md)
- [Champ ou bloc](field-block.md)
- [Table, repository ou service](database-repository.md)
- [Commande, validateur ou test](command-validator.md)

Les comportements utilisateurs doivent être testés par intégration, API ou navigateur. Les validateurs Python restent réservés aux invariants transversaux et déterministes.
