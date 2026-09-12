# `test_load.py` — test de charge concurrente webeLi

`test_load.py` complète `test_perf.py`.

- `test_perf.py` mesure des latences répétées à faible concurrence et compare local/distant.
- `test_load.py` mesure le comportement sous utilisateurs virtuels concurrents : débit, p95/p99, erreurs, 429, 5xx, timeouts et premier palier problématique.

Le script reste **non destructif par défaut** : il exécute uniquement des lectures HTTP. Il ne publie pas, ne modifie pas les contenus et ne génère pas artificiellement une grosse base.

Il produit un dossier de preuve vérifiable : `load_report.md`, `results.csv`, `results.json`, `summary.json`, `route_summary.json`, `dataset_profile.json`, `scope_verdict.json`, `target_evidence.json`, `environment.json`, `metadata.json`, `executed_script.py` et `manifest.sha256`.

Les identifiants, cookies et tokens ne sont jamais enregistrés dans les preuves.

---

## Installation

```bash
python3 -m venv .venv
source .venv/bin/activate
python3 -m pip install requests
```

Windows PowerShell :

```powershell
python -m venv .venv
.venv\Scripts\Activate.ps1
python -m pip install requests
```

---

## Cibles

Par défaut, le script cible l’instance locale :

```text
http://127.0.0.1:8080/cms/
```

Cela évite de lancer une charge distante par accident.

Cible locale :

```bash
python3 test_load.py --profile smoke --target local --yes
```

Cible distante :

```bash
python3 test_load.py --profile smoke --target remote --mode public --yes
```

URL explicite :

```bash
python3 test_load.py \
  --base-url http://127.0.0.1:8080/cms/ \
  --profile smoke \
  --mode public \
  --yes
```

Pour une URL locale `127.0.0.1`, le script ajoute automatiquement, sauf désactivation :

```text
Host: webe.li
X-Forwarded-Proto: https
```

Cela permet de tester le runtime local tout en respectant la résolution canonique du CMS. Pour désactiver ce comportement :

```bash
python3 test_load.py --target local --no-auto-local-canonical-headers --yes
```

---

## Profils de charge

| Profil | Paliers par défaut | Durée par palier | Usage |
|---|---:|---:|---|
| `smoke` | `1,2` | 15 s | Validation rapide, peu agressive |
| `baseline` | `1,5,10,25` | 60 s | Qualification prudente d’une instance |
| `stress` | `1,5,10,25,50,100` | 45 s | Recherche du palier de dégradation |
| `spike` | `1,50` | 30 s | Montée brutale, protections et timeouts |
| `soak` | `10` | 900 s | Tenue dans le temps |

Tous les paramètres peuvent être surchargés :

```bash
python3 test_load.py \
  --profile baseline \
  --users 20 \
  --ramp 1,5,10,20 \
  --duration 45 \
  --mode public \
  --yes
```

---

## Modes

```text
public  : pages SSR publiques
api     : API anonyme + API Bearer si token disponible
admin   : back-office en lecture avec sessions admin réelles
all     : public + api + admin
```

Par défaut, `--mode public`.

---

## `token.csv` pour l’API Bearer

Le script détecte automatiquement `token.csv` dans le dossier courant ou à côté du script.

Format recommandé :

```csv
url,nom,token
http://127.0.0.1:8080/cms/,local,amcms_LOCAL...
https://webe.li/cms/,remote,amcms_REMOTE...
```

Le script accepte aussi un token explicite :

```bash
python3 test_load.py --mode api --bearer-token amcms_xxx --yes
```

Avant d’inclure les routes API Bearer dans la charge, le script exécute un préflight :

```text
/api/v1/search?q=cms
```

Si le préflight Bearer échoue, les routes Bearer sont exclues du scénario et le rapport indique `INCONCLUSIVE` ou une couverture partielle. Cela évite de confondre un token mal configuré avec une défaillance de performance.

---

## `users.csv` pour l’admin

Le script détecte automatiquement `users.csv` dans le dossier courant ou à côté du script.

Format recommandé :

```csv
email,password,role,comment
admin1@example.test,secret,admin,compte de charge 1
admin2@example.test,secret,admin,compte de charge 2
```

Colonnes acceptées pour l’identifiant :

```text
email, username, login, user, nom, name
```

Colonnes acceptées pour le mot de passe :

```text
password, mot_de_passe, motdepasse, pass
```

Par défaut, le scénario admin exige un compte distinct par utilisateur virtuel. C’est plus représentatif de plusieurs éditeurs simultanés.

Pour réutiliser les comptes si le CSV en contient moins que le nombre de VU :

```bash
python3 test_load.py --mode admin --allow-credential-reuse --yes
```

La réutilisation est utile pour un test technique, mais elle ne prouve pas fidèlement la capacité multi-utilisateurs réelle.

---

## Critères de verdict

Le verdict par palier peut être :

| Statut | Signification |
|---|---|
| `PASS` | Critères respectés |
| `WARN` | Route ou palier exploitable, mais protection 429 ou autre avertissement |
| `FAIL` | Critère dépassé : p95, taux d’erreur, 5xx, réseau, 429 trop élevé |
| `INCONCLUSIVE` | Initialisation impossible ou couverture insuffisante |

Critères par défaut :

```text
taux d’erreur maximal : 1 %
taux 429 maximal      : 5 %
p95 public maximal    : 1000 ms
p95 API maximal       : 1200 ms
p95 admin maximal     : 1500 ms
5xx                   : FAIL
```

Surcharges possibles :

```bash
python3 test_load.py \
  --max-error-rate 0.5 \
  --max-429-rate 2 \
  --max-p95-public 800 \
  --max-p95-api 1000 \
  --max-p95-admin 1500 \
  --yes
```

Une réponse `429` indique souvent une protection de trafic ou une limite d’infrastructure. Elle ne démontre pas à elle seule une saturation de PHP, SQLite ou du CMS.

---

## Profil de données

Le script essaie de mesurer le profil SQLite local en lecture seule.

Détection automatique dans plusieurs chemins courants, ou chemin explicite :

```bash
python3 test_load.py --db-root ../storage/database --profile baseline --mode public --yes
```

Le rapport classe le volume :

```text
small, medium, large, very_large, unknown
```

Le verdict de performance vaut uniquement pour le profil mesuré. Si le profil est `small`, il ne faut pas conclure que la charge est validée sur base volumineuse.

---

# Propositions claires de tests à effectuer

## 1. Smoke local complet

Objectif : vérifier rapidement que le script, les tokens, les comptes admin et le serveur local fonctionnent.

```bash
python3 test_load.py \
  --target local \
  --profile smoke \
  --mode all \
  --yes
```

Conclusion attendue : pas de `FAIL`, idéalement pas d’`INCONCLUSIVE`. Si l’API Bearer ou l’admin sont non concluants, corriger `token.csv` ou `users.csv` avant d’aller plus loin.

## 2. Baseline publique locale

Objectif : qualifier le SSR public local jusqu’à 25 VU.

```bash
python3 test_load.py \
  --target local \
  --profile baseline \
  --mode public \
  --yes
```

À regarder : p95, p99, req/s utile, 5xx, timeouts.

## 3. Baseline API locale

Objectif : qualifier l’API anonyme et Bearer locale.

```bash
python3 test_load.py \
  --target local \
  --profile baseline \
  --mode api \
  --yes
```

À regarder : `/api/v1/content`, `/api/v1/search`, `/api/v1/menus`, `/api/v1/media`, p95 par route dans `route_summary.json`.

## 4. Baseline admin locale

Objectif : qualifier l’admin en lecture avec sessions réelles.

```bash
python3 test_load.py \
  --target local \
  --profile baseline \
  --mode admin \
  --yes
```

Prévoir au moins autant de comptes dans `users.csv` que de VU du palier maximal, ou utiliser volontairement `--allow-credential-reuse`.

## 5. Baseline publique distante prudente

Objectif : mesurer l’expérience publique distante sans attaquer agressivement l’hébergement.

```bash
python3 test_load.py \
  --target remote \
  --profile baseline \
  --mode public \
  --max-429-rate 5 \
  --yes
```

Si des `429` apparaissent, interpréter comme protection de trafic ou limite d’hébergement, pas directement comme bug CMS.

## 6. API distante

Objectif : vérifier que le token distant et les endpoints API tiennent une charge prudente.

```bash
python3 test_load.py \
  --target remote \
  --profile baseline \
  --mode api \
  --yes
```

Le préflight Bearer doit être `OK`. Sinon, vérifier le token, le site associé, les scopes et l’URL.

## 7. Stress local ou staging uniquement

Objectif : trouver le premier palier de dégradation.

```bash
python3 test_load.py \
  --target local \
  --profile stress \
  --mode all \
  --yes
```

À éviter sur production mutualisée sans accord explicite.

## 8. Soak test local ou staging

Objectif : vérifier la stabilité sur la durée.

```bash
python3 test_load.py \
  --target local \
  --profile soak \
  --mode public \
  --yes
```

À regarder : dérive p95/p99, erreurs réseau, 5xx, croissance des temps de réponse.

---

## Lecture du rapport

Le fichier principal est :

```text
load_report.md
```

Les fichiers les plus utiles :

```text
summary.json          résumé par palier
route_summary.json    résumé par route et palier
results.csv           mesures brutes
scope_verdict.json    portée démontrée / non démontrée
dataset_profile.json  volume de données local mesuré
verdict.json          verdict global et raisons
metadata.json         paramètres exacts
```

Vérification :

```bash
cd <dossier-de-preuve>
sha256sum -c manifest.sha256
```

---

## Ce que le script ne démontre pas

Par défaut, `test_load.py` ne démontre pas :

```text
écriture / publication concurrente
upload média concurrent
édition simultanée du même contenu
base volumineuse si le profil mesuré est small
cause interne exacte d’un ralentissement
capacité enterprise ou trafic internet massif
```

Ces scénarios doivent être définis séparément, avec une base de test dédiée et des données jetables.
