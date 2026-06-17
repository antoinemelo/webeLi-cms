---
title: Dépendances frontend de qualification
audience:
  - developers
  - operators
status: current
source_of_truth: frontend/admin-vue/package-lock.json
owners:
  - core-team
document_type: troubleshooting
---

# Dépendances frontend de qualification

Le build frontend exige une installation cohérente avec `package-lock.json`.

Lorsqu'un paquet déclaré, par exemple `bootstrap`, est absent de `node_modules`,
la qualification retourne désormais le statut **incomplet** avant de lancer Vite.

Remise en état reproductible :

```bash
cd frontend/admin-vue
rm -rf node_modules
npm ci
```

Puis relancer :

```bash
python3 tools/cms.py qualify --profile complete
```

La qualification ne télécharge pas automatiquement des dépendances : elle vérifie
l'environnement, produit un diagnostic et ne considère jamais une étape ignorée
comme réussie.
