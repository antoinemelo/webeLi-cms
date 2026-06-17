---
title: Limites et risques observables
document_type: evaluation
audience:
  - evaluator
  - ai-evaluator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable

generated: false
owners:
  - core
evidence_scope:
  - code
  - tests
  - validators
  - documentation
---

# Limites et risques observables

| Risque / limite | Impact | Probabilité | Détectabilité | Contournement | Priorité |
|---|---|---|---|---|---|
| concurrence et échelle SQLite non qualifiées | élevé si forte charge | moyenne selon usage | moyenne | tests de charge, architecture adaptée | haute pour gros trafic |
| observabilité sans métriques/traces centralisées | moyen | élevée en production | faible | collecte externe, alertes | haute |
| opérations cross-database | élevé en cas de panne | faible à moyenne | moyenne | tests de panne, sauvegardes cohérentes | haute |
| assistant IA dépendant d’un tiers | moyen à élevé | élevée | élevée | désactivation, politique données, quotas | haute |
| HTML brut/iframe/embed | élevé | moyenne | moyenne | permissions, CSP, sanitisation, revue | haute |
| restauration non testée régulièrement | élevé | moyenne | faible avant incident | exercices de restauration | haute |
| parité export statique | moyen | moyenne | élevée | tests de sortie et crawl | moyenne |
| compatibilité hébergeurs mutualisés | moyen | moyenne | élevée | préflight sur cible | moyenne |
| API publique sans SLA formel | moyen | moyenne | élevée | politique de version/changelog | moyenne |
| UX/accessibilité non démontrées exhaustivement | moyen | moyenne | moyenne | audit manuel et utilisateurs | moyenne |
| stockage S3, e-mail, webhooks externes | variable | moyenne | moyenne | tests d’intégration et monitoring | moyenne |
| documentation générée susceptible de dériver | moyen | moyenne | élevée | `docs check` en CI | moyenne |

Les fonctionnalités expérimentales ou partielles ne doivent pas être utilisées comme dépendances critiques sans tests ciblés. Aucun résultat de performance, de pentest ou de conformité réglementaire n’est fourni ici.
