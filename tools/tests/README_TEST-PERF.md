# Benchmark comparatif local/distant pour webeLi

Ce fichier documente `test_perf.py`, un outil **indépendant** de `test_load.py`.

- `test_load.py` sert aux tests de charge : utilisateurs virtuels, paliers, montée progressive, 429, saturation éventuelle.
- `test_perf.py` sert au benchmark comparatif : mesures répétées à faible concurrence entre une cible locale et une cible distante.

L'objectif est de distinguer :

```text
127.0.0.1/mod   = moteur CMS + PHP + SQLite + machine locale
webe.li/mod     = expérience hébergée réelle : réseau, TLS, proxy, hébergeur, protections éventuelles
```

Le script est non destructif par défaut : il ne publie pas de contenu, ne crée pas d'entrée, ne modifie pas les bases et ne génère pas de jeu de données artificiel.

## Installation

```bash
python3 -m venv .venv
source .venv/bin/activate
python3 -m pip install requests
```

## Exécution par défaut

Par défaut, `test_perf.py` lance un benchmark complet **en lecture** :

```bash
python3 test_perf.py --yes
```

Valeurs par défaut :

```text
remote-url : https://webe.li/mod/
local-url  : http://127.0.0.1:8080/mod/
mode       : all
iterations : 30
warmup     : 5
```

Le seuil local API par défaut est maintenant fixé à `300 ms` en p95. Le seuil distant API reste à `700 ms`. Ces valeurs évitent de transformer un léger dépassement local non significatif, par exemple 205 ms sur une route santé, en avertissement bruité.

Le mode `all` couvre :

```text
public SSR
API lecture anonyme
API lecture protégée Bearer si token.csv est présent ou si --bearer-token est fourni
admin lecture si users.csv est présent ou si --credentials-file est fourni
profil de données local si les bases SQLite sont détectées
```

Le script continue à exclure explicitement :

```text
charge concurrente
écriture / publication
création artificielle d'une base volumineuse
```

La charge concurrente relève de `test_load.py`. L'écriture/publication doit rester un scénario volontaire séparé, car elle modifie l'état du CMS.

## CSV administrateur

Le script accepte un ou plusieurs comptes administrateur. Par défaut, s'il trouve un fichier `users.csv` dans le même dossier que `test_perf.py`, il l'utilise automatiquement pour tenter le scénario admin.

Format recommandé :

```csv
email,password,role,comment
admin@example.test,MotDePasseTemporaire,admin,compte de test
```

Colonnes acceptées pour l'identifiant :

```text
email, username, login, user, nom, name
```

Colonnes acceptées pour le mot de passe :

```text
password, mot_de_passe, motdepasse, pass
```

Exécution explicite :

```bash
python3 test_perf.py \
  --credentials-file users.csv \
  --yes
```

Si plusieurs comptes sont présents, le script tente les comptes dans l'ordre jusqu'à obtenir une session admin valide pour chaque cible. Les mots de passe, cookies et en-têtes `Authorization` ne sont jamais écrits dans les fichiers de preuve. Les identifiants ne sont conservés que sous forme d'empreinte courte non réversible.

## CSV de tokens API

Le script détecte automatiquement `token.csv` dans le même dossier que `test_perf.py`.

Format recommandé :

```csv
url,nom,token
http://127.0.0.1:8080/mod/,perf,TOKEN_LOCAL
https://webe.li/mod/,perf,TOKEN_DISTANT
```

La colonne `url` permet d'associer le token à la bonne cible :

```text
http://127.0.0.1:8080/mod/ -> cible local
https://webe.li/mod/       -> cible remote
```

Le token n'est jamais écrit dans les preuves. Le rapport indique seulement si un token était disponible pour chaque cible, avec une empreinte courte non réversible.

Le script accepte aussi les valeurs déjà préfixées par `Bearer ` dans le CSV ou via `--bearer-token` ; le préfixe est retiré avant l'envoi HTTP pour éviter `Authorization: Bearer Bearer ...`.

Un préflight Bearer est exécuté une fois par cible avant l'interprétation des routes protégées. Par défaut, il utilise :

```text
/api/v1/search?q=cms
```

Si le préflight Bearer échoue en 401/403 pour une cible, les routes API protégées de cette cible sont considérées comme **non mesurables avec ce token** plutôt que comme des défauts individuels de chaque route. Cela évite de confondre un token expiré ou mal associé avec une lenteur ou une route cassée.

Pour changer l'endpoint de préflight :

```bash
python3 test_perf.py \
  --api-bearer-preflight-path api/v1/languages \
  --yes
```

Exécution explicite :

```bash
python3 test_perf.py \
  --tokens-file token.csv \
  --yes
```

Alternative avec un token unique pour les deux cibles :

```bash
python3 test_perf.py \
  --bearer-token "$WEBELI_API_TOKEN" \
  --yes
```

## Politique d'authentification API

Les routes API sont typées pour éviter de déclarer une route `PASS` alors qu'elle retourne 401.

Routes API anonymes :

```text
/api/v1/health
/api/v1/cookies/config
/api/v1/forms/contact
/api/v1/media
```

Routes API protégées Bearer :

```text
/api/v1/languages
/api/v1/menus
/api/v1/content
/api/v1/search?q=cms
```

Règles de verdict :

```text
401/403 sur route anonymous                      -> FAIL
401/403 sur route bearer sans token               -> INCONCLUSIVE
401/403 sur route bearer avec préflight refusé    -> INCONCLUSIVE_AUTH / non mesurable avec ce token
401/403 sur route bearer avec préflight OK        -> FAIL
404 sur route bearer avec préflight OK            -> FAIL, route par défaut probablement incorrecte ou absente
0 % de succès réseau                              -> INCONCLUSIVE
0 % de succès avec erreur applicative             -> FAIL
```

Cela évite qu'une API non réellement testée soit comptée comme démontrée, et évite aussi de multiplier artificiellement les erreurs lorsqu'un token Bearer est refusé globalement par une cible.

## Profil de données / base volumineuse

Par défaut, le script tente de mesurer le profil de données local en lecture seule.

Il cherche automatiquement les bases SQLite dans des chemins proches de l'arborescence courante, notamment :

```text
../storage/database
storage/database
mod/storage/database
```

Il est possible de forcer le chemin :

```bash
python3 test_perf.py \
  --db-root ../storage/database \
  --yes
```

Pour désactiver cette mesure :

```bash
python3 test_perf.py --no-measure-dataset --yes
```

Le script ouvre les bases en mode SQLite read-only et compte notamment :

```text
sites
languages
content_entries
content_entry_localizations
public_content_snapshots
search_documents
routes
seo_metadata
media_assets
media_asset_variants
menus
menu_items
revisions
redirects
iam_users
api_tokens
forms
form_submissions
cookie_consent_logs
```

Il produit :

```text
dataset_profile.json
```

et ajoute une section `Profil de données mesuré` dans `comparison.md`.

Profils automatiques :

```text
small       : moins de 100 contenus, 250 médias, 250 routes, 500 révisions et <25 MB
medium      : à partir de 100 contenus ou 250 médias/routes ou 500 révisions ou 25 MB
large       : à partir de 1'000 contenus ou 2'000 médias/routes ou 5'000 révisions ou 250 MB
very_large  : à partir de 10'000 contenus ou 20'000 médias/routes ou 50'000 révisions ou 2 GB
unknown     : bases non détectées ou compteurs indisponibles
```

La conclusion devient donc plus précise :

```text
Volume de données : profil small/medium/large mesuré
Performance démontrée sur le profil courant
Aucune extrapolation automatique vers un profil plus gros
```

Si le profil détecté est `large` ou `very_large`, le rapport peut conclure que la performance sur base volumineuse est démontrée **pour les lectures testées**. Le script ne génère pas lui-même une grosse base par défaut.

## Exécution public uniquement

```bash
python3 test_perf.py \
  --remote-url https://webe.li/mod/ \
  --local-url http://127.0.0.1:8080/mod/ \
  --mode public \
  --iterations 30 \
  --warmup 5 \
  --yes
```

## Exécution API uniquement

```bash
python3 test_perf.py \
  --remote-url https://webe.li/mod/ \
  --local-url http://127.0.0.1:8080/mod/ \
  --mode api \
  --tokens-file token.csv \
  --iterations 30 \
  --warmup 5 \
  --yes
```

## Exécution admin uniquement

```bash
python3 test_perf.py \
  --remote-url https://webe.li/mod/ \
  --local-url http://127.0.0.1:8080/mod/ \
  --mode admin \
  --credentials-file users.csv \
  --iterations 20 \
  --warmup 3 \
  --yes
```

## Cas particulier du serveur local

Lorsque le CMS local est lancé sur `127.0.0.1`, le site peut rediriger vers son domaine canonique `webe.li`.

Par défaut, `test_perf.py` ajoute automatiquement pour la cible locale :

```text
Host: webe.li
X-Forwarded-Proto: https
```

Cela permet de tester `http://127.0.0.1:8080/mod/` tout en simulant la base canonique `https://webe.li/mod/`.

Le script vérifie toutefois les `final_url` et le nombre de redirections. Si le local bascule réellement vers un domaine externe, la comparaison est marquée `INCONCLUSIVE`.

Pour désactiver les en-têtes automatiques :

```bash
python3 test_perf.py --no-auto-local-canonical-headers --yes
```

Pour forcer manuellement :

```bash
python3 test_perf.py \
  --local-host-header webe.li \
  --local-forwarded-proto https \
  --yes
```

## Routes mesurées par défaut

### Public

```text
/
/articles
/search?q=cms
/sitemap.xml
/robots.txt
```

### API

```text
/api/v1/health                      anonymous
/api/v1/cookies/config              anonymous
/api/v1/forms/contact               anonymous
/api/v1/media                       anonymous
/api/v1/languages                   bearer
/api/v1/menus                  bearer
/api/v1/content               bearer
/api/v1/search?q=cms                bearer
```

Les routes plus spécifiques comme `/api/v1/menus/main` ou `/api/v1/content/pages` ne sont plus dans le jeu par défaut, car elles peuvent dépendre de clés ou types présents dans le jeu de données. Elles peuvent être ajoutées via `--routes-file` si tu veux contrôler ces variantes explicitement.

### Admin

```text
/admin/login
/admin/app
/admin/api/context
/admin/api/entries
/admin/api/media
```

Les routes admin authentifiées ne sont mesurées que si une session admin valide a été obtenue.

## Ajouter des routes

Créer un fichier JSON :

```json
{
  "routes": [
    {"area": "public", "name": "custom-page", "method": "GET", "path": "ma-page"},
    {"area": "api", "name": "custom-api", "method": "GET", "path": "api/v1/content/articles", "auth_policy": "bearer"}
  ]
}
```

Puis lancer :

```bash
python3 test_perf.py --routes-file perf_routes.json --yes
```

## Fichiers de preuve produits

Chaque run crée un dossier du type :

```text
performance-compare-YYYYMMDD-HHMMSS-xxxxxxxx/
```

Contenu principal :

```text
comparison.md           rapport lisible
comparison.json         comparaison structurée
summary.json            statistiques agrégées
raw_results.csv         une ligne par requête
raw_results.json        données brutes complètes
dataset_profile.json    profil de données local mesuré
scope_verdict.json      portée démontrée / non démontrée
metadata.json           paramètres du run
environment.json        environnement Python
routes.json             routes demandées
targets.json            probes local/distant
executed_script.py      copie du script exécuté
manifest.sha256         manifeste d'intégrité
```

Vérification :

```bash
cd performance-compare-YYYYMMDD-HHMMSS-xxxxxxxx
sha256sum -c manifest.sha256
```

## Ratios distant/local

Un ratio distant/local élevé n'est pas un avertissement bloquant par défaut si les statuts HTTP et les seuils absolus sont bons. Cela évite le bruit sur les très petites réponses comme `robots.txt`, où le coût réseau/TLS distant domine naturellement.

Pour transformer ces ratios en WARN stricts :

```bash
python3 test_perf.py --strict-ratio-warnings --yes
```

## Interprétation courte

Un résultat `PASS` signifie seulement que les lectures testées respectent les seuils définis dans ce run.

Un résultat `INCONCLUSIVE` n'est pas forcément un bug : il peut signifier token absent, session admin impossible, cible non joignable ou redirection externe.

Un résultat `FAIL` indique un défaut plus fort : erreur 5xx, accès refusé sur route anonyme, route introuvable alors que l'authentification est valide, ou 0 % de succès applicatif. Si le token Bearer est refusé au préflight, le script classe plutôt la partie protégée comme non concluante / non mesurable avec ce token.

Le script ne doit pas être utilisé pour affirmer une tenue en forte charge. Pour cela, utiliser `test_load.py`.
