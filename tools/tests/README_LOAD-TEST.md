# Test de montée en charge traçable et reproductible pour webeLi

Ce script permet d’exécuter une montée en charge HTTP contrôlée sur une instance de webeLi. Il génère un dossier de résultats reproductible et vérifiable pouvant être joint à un diagnostic technique indépendant.

Le test produit une charge réelle sur le serveur. Il ne doit être exécuté que sur une infrastructure pour laquelle vous disposez d’une autorisation explicite.

## Installation

### Linux et macOS

```bash
python3 -m venv .venv
source .venv/bin/activate
python3 -m pip install requests
```

### Windows PowerShell

```powershell
python -m venv .venv
.venv\Scripts\Activate.ps1
python -m pip install requests
```

## Exécution interactive

```bash
python load_test.py --credentials-file users.csv
```

Le programme demande :

1. le nombre maximal d’utilisateurs virtuels ;
2. la durée de chaque palier ;
3. le mode de test : `public`, `admin` ou `both` ;
4. les identifiants du compte administrateur de test, lorsque le scénario admin est activé sans fichier CSV ;
5. la confirmation que le test est autorisé.

Lorsqu’un fichier est fourni avec `--credentials-file`, les identifiants sont chargés depuis ce fichier et ne sont pas demandés de manière interactive.

Pour un maximum de 20 utilisateurs virtuels, les paliers proposés automatiquement sont :

```text
1, 5, 10, 15, 20
```

Un utilisateur virtuel exécute des requêtes successives pendant toute la durée du palier. Le nombre d’utilisateurs virtuels ne correspond donc pas directement au nombre de requêtes par seconde.

## Exécution reproductible

Les paramètres peuvent être fournis directement dans la commande afin de rendre une campagne reproductible.

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --credentials-file users.csv \
  --users 15 \
  --ramp 1,5,10,15 \
  --duration 30 \
  --mode public \
  --max-error-rate 1 \
  --max-p95 1000 \
  --yes
```

L’option `--yes` supprime uniquement la demande interactive de confirmation. Elle ne constitue pas une preuve d’autorisation et doit être utilisée uniquement dans un environnement maîtrisé.

## Campagne progressive recommandée

La commande suivante utilise une montée progressive et arrête le scénario lorsqu’au moins 5 % des réponses sont des erreurs HTTP 429.

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --credentials-file users.csv \
  --users 20 \
  --ramp 1,5,10,15,20 \
  --duration 60 \
  --mode both \
  --think-min 0.5 \
  --think-max 1.5 \
  --pause-between-stages 10 \
  --stop-on-429-rate 5
```

Cette méthode permet d’identifier le premier palier auquel la protection de débit ou la capacité du service commence à refuser des requêtes.

Une réponse HTTP 429 indique une limitation volontaire du trafic. Elle ne démontre pas, à elle seule, une saturation de PHP, de SQLite ou du CMS.

## Séparation des campagnes publique et administrative

Il est recommandé d’exécuter le test public et le test administratif dans deux campagnes distinctes.

Chaque campagne devrait produire son propre dossier de preuve et son propre manifeste SHA-256. Cette séparation évite qu’un échec administratif rende ambigu ou incomplet un résultat public pourtant valide.

Une campagne publique importante peut déclencher une limitation temporaire par adresse IP. Cette limitation risque ensuite de fausser ou d’empêcher l’initialisation des sessions administratives.

### Campagne publique

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --users 20 \
  --ramp 1,5,10,15,20 \
  --duration 60 \
  --mode public \
  --think-min 0.5 \
  --think-max 1.5 \
  --pause-between-stages 5 \
  --stop-on-429-rate 5
```

### Campagne administrative

La campagne administrative doit être exécutée après expiration complète d’une éventuelle limitation de débit.

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --users 10 \
  --ramp 1,5,10,15 \
  --duration 60 \
  --mode admin \
  --think-min 1 \
  --think-max 3 \
  --pause-between-stages 15 \
  --stop-on-429-rate 5
```

Une durée de réflexion plus élevée est utilisée pour le scénario administratif afin de mieux représenter le comportement d’un éditeur humain.

Pour tester plusieurs éditeurs avec des comptes distincts :

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --credentials-file users.csv \
  --users 10 \
  --ramp 1,5,10,15 \
  --duration 60 \
  --mode admin \
  --think-min 1 \
  --think-max 3 \
  --pause-between-stages 15 \
  --stop-on-429-rate 5
```

## Utilisateurs administrateurs

Chaque utilisateur virtuel ouvre une session HTTP indépendante et réalise sa propre authentification.

Les mots de passe, cookies de session et jetons d’authentification ne sont jamais enregistrés dans les rapports.

### Utilisation d’un même compte

L’utilisation du même compte dans plusieurs sessions permet de vérifier :

* si plusieurs sessions indépendantes sont autorisées pour un même compte ;
* si une nouvelle connexion invalide une session existante ;
* si une limitation est appliquée par compte ou par adresse IP ;
* si un verrou ou une protection de sécurité empêche les connexions simultanées.

Cette méthode ne reproduit toutefois pas fidèlement plusieurs éditeurs réels.

Un échec lors de l’initialisation du deuxième utilisateur ne démontre pas que l’administration ne supporte qu’un seul utilisateur. Il démontre uniquement que plusieurs sessions indépendantes n’ont pas pu être établies avec le même compte dans les conditions du test.

Les causes possibles incluent notamment :

* une seule session active autorisée par compte ;
* l’invalidation de la session précédente lors d’une nouvelle connexion ;
* une protection contre les connexions rapprochées ;
* une limitation par compte ou par adresse IP ;
* un mécanisme d’authentification que le parseur générique ne reproduit pas entièrement.

Dans ce cas, le scénario administratif multiutilisateur doit être considéré comme **non concluant**, et non comme un échec de performance.

### Utilisation de plusieurs comptes

Pour tester réellement plusieurs éditeurs simultanés, il est recommandé d’utiliser un compte distinct par utilisateur virtuel.

Les comptes doivent disposer de rôles et de permissions explicitement documentés. Pour une comparaison homogène, plusieurs comptes peuvent être créés avec le même rôle. Pour une simulation fonctionnelle, ils peuvent représenter différents profils :

* éditeur ;
* publicateur ;
* responsable SEO ;
* administrateur ;
* superadministrateur.

Le script accepte un fichier CSV contenant un compte distinct par utilisateur virtuel au moyen de l’option `--credentials-file`.

Exemple :

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --credentials-file users.csv \
  --users 3 \
  --ramp 1,2,3 \
  --duration 60 \
  --mode admin \
  --think-min 1 \
  --think-max 3
```

Le fichier CSV doit contenir les colonnes `email` et `password` :

```csv
email,password
loadtest-admin-01@example.test,MotDePasseTemporaire1
loadtest-admin-02@example.test,MotDePasseTemporaire2
loadtest-admin-03@example.test,MotDePasseTemporaire3
```

Le fichier est lu en UTF-8. L’encodage `utf-8-sig` est également accepté, ce qui permet d’utiliser un CSV exporté depuis Excel avec une marque BOM.

Le script vérifie que :

* les colonnes `email` et `password` existent ;
* chaque ligne contient une adresse et un mot de passe ;
* le fichier contient au moins un compte ;
* le nombre de comptes distincts est suffisant pour le palier demandé.

Lorsqu’un palier demande plus d’utilisateurs virtuels que le nombre de comptes disponibles, le palier n’est pas exécuté et son statut devient `INCONCLUSIVE`.

Exemple :

```text
5 utilisateurs virtuels demandés
3 comptes disponibles
Résultat : INCONCLUSIVE
```

Le fichier CSV contient des secrets et ne doit jamais être :

* ajouté au dossier de preuve ;
* ajouté à un dépôt Git ;
* inclus dans une archive partagée ;
* transmis avec les résultats ;
* copié dans `executed_script.py`.

Il est recommandé d’ajouter son nom au fichier `.gitignore` :

```gitignore
users.csv
*_credentials.csv
```

Le chemin peut être relatif :

```bash
python load_test.py \
  --credentials-file users.csv \
  --users 3 \
  --mode admin
```

ou absolu :

```bash
python load_test.py \
  --credentials-file /home/antoine/tests/users.csv \
  --users 3 \
  --mode admin
```

Une campagne utilisant plusieurs comptes distincts permet de différencier :

* une limitation propre à un compte ;
* une limitation propre aux sessions ;
* une limitation globale de l’administration ;
* une saturation réelle du serveur ou du CMS.

### Statut d’un scénario administratif

Les résultats administratifs devraient utiliser les statuts suivants :

* `PASS` : tous les utilisateurs ont été initialisés et les seuils sont respectés ;
* `FAIL` : les utilisateurs ont été initialisés, mais les seuils de performance ou d’erreur sont dépassés ;
* `INCONCLUSIVE` : un nombre suffisant de sessions n’a pas pu être initialisé ;
* `SKIPPED` : le scénario n’a pas été exécuté.

Un échec d’authentification pendant l’initialisation ne doit pas être assimilé automatiquement à un échec de performance.

### Campagne de stabilité avec un seul compte

Tant que plusieurs comptes distincts ne sont pas disponibles, il reste possible de tester la stabilité prolongée d’une session administrative unique :

```bash
python load_test.py \
  --base-url https://webe.li/mod/ \
  --users 1 \
  --ramp 1 \
  --duration 300 \
  --mode admin \
  --think-min 1 \
  --think-max 3
```

Ce scénario vérifie la stabilité d’une session dans le temps, mais ne mesure pas la capacité multiéditeur.

## Lecture des résultats

Le rapport distingue plusieurs mesures.

Le rapport doit également distinguer une **capacité minimale démontrée** d’une **capacité maximale**.

Lorsqu’un palier est exécuté avec 100 % de succès, sans réponse 429 et sans dégradation significative, il démontre que le service supporte au moins ce niveau de charge dans les conditions du test.

Il ne démontre pas que ce niveau correspond à la capacité maximale si aucun point de saturation n’a été atteint.


### Débit brut

Le débit brut inclut toutes les réponses reçues :

* réponses réussies ;
* erreurs HTTP ;
* réponses 429 ;
* autres refus rapides.

Un débit brut élevé ne signifie donc pas nécessairement que le serveur traite correctement un grand nombre de pages.

### Débit utile

Le débit utile, ou `goodput`, comptabilise uniquement les requêtes considérées comme réussies.

Il constitue l’indicateur le plus pertinent pour évaluer la capacité effectivement fournie aux utilisateurs.

### Latence des requêtes réussies

Les percentiles p50, p90, p95 et p99 doivent être calculés sur les requêtes réussies.

Les réponses 429 sont souvent très rapides. Les inclure dans les calculs de latence peut artificiellement diminuer le p95 alors même que le taux d’échec augmente.

### Taux de réponses 429

Le taux de réponses HTTP 429 permet d’identifier le moment où une protection de débit devient active.

La source de cette limitation peut être :

* le CMS ;
* le serveur web ;
* PHP ;
* un reverse proxy ;
* un pare-feu applicatif ;
* un CDN ;
* l’hébergeur ;
* une règle par adresse IP ou par compte.

Les journaux serveur et les en-têtes HTTP sont nécessaires pour attribuer précisément la limitation.

## Interprétation d’un test interrompu

Une campagne peut être interrompue pour plusieurs raisons distinctes :

* dépassement du seuil de réponses HTTP 429 ;
* erreur réseau ;
* impossibilité d’initialiser les sessions administratives ;
* erreur inattendue du script ;
* arrêt manuel.

Le diagnostic doit conserver les résultats des paliers terminés avant l’interruption.

Par exemple, si le scénario public atteint 20 utilisateurs virtuels avec 100 % de succès, puis que le scénario admin échoue lors de l’initialisation du deuxième compte, les conclusions doivent être séparées :

* le résultat public reste valide et démontré ;
* le scénario admin à un utilisateur peut rester exploitable ;
* le scénario admin multiutilisateur est non concluant ;
* aucune conclusion ne doit être formulée sur une saturation de SQLite, PHP ou du serveur à partir du seul échec d’authentification.

## Dossier de preuve

Chaque campagne produit un dossier contenant notamment :

* `evidence.md` : rapport lisible à joindre au diagnostic ;
* `results.csv` : mesures brutes, une ligne par requête ;
* `results.json` : mesures brutes au format JSON ;
* `summary.json` : agrégats par scénario et par palier ;
* `metadata.json` : paramètres exacts de la campagne ;
* `environment.json` : environnement d’exécution et empreinte du script ;
* `target_evidence.json` : informations DNS, TLS et réponse de contrôle observées ;
* `executed_script.py` : copie du script réellement exécuté ;
* `verdict.json` : verdict établi selon les seuils déclarés ;
* `manifest.sha256` : empreintes de contrôle des fichiers produits.

Le dossier de preuve doit être archivé immédiatement après le test, sans modification.

## Vérification de l’intégrité

### Linux et macOS

Depuis le dossier produit :

```bash
sha256sum -c manifest.sha256
```

Une ligne `OK` pour chaque fichier confirme que son contenu correspond toujours à l’empreinte inscrite dans le manifeste.

### Windows PowerShell

PowerShell ne fournit pas directement l’équivalent de `sha256sum -c`. Les empreintes peuvent être vérifiées individuellement avec :

```powershell
Get-FileHash .\results.csv -Algorithm SHA256
```

La valeur obtenue doit être comparée à celle inscrite dans `manifest.sha256`.

Le manifeste SHA-256 permet de détecter une modification ultérieure. Il ne prouve toutefois pas à lui seul la date de création ni l’identité de la personne ayant exécuté le test.

Pour renforcer la valeur de la preuve, le manifeste peut être :

* signé électroniquement ;
* déposé dans un système d’archivage horodaté ;
* ajouté à un dépôt Git avec un commit signé ;
* transmis immédiatement à un tiers indépendant.

## Portée de la preuve

Le dossier produit fournit une preuve technique :

* documentée ;
* traçable ;
* reproductible ;
* accompagnée des résultats bruts ;
* vérifiable au moyen d’empreintes cryptographiques.

Il peut être annexé à un diagnostic ou à une évaluation indépendante.

Il ne constitue pas :

* une certification délivrée par un organisme tiers ;
* une preuve de la cause interne d’un ralentissement ;
* une mesure absolue indépendante du réseau et de la machine de test ;
* une comparaison SQLite/MySQL lorsque seul un backend a été testé ;
* une validation des écritures concurrentes lorsque seules des requêtes GET ont été exécutées.

## Formulation recommandée dans un diagnostic

Les conclusions doivent être limitées à ce qui a réellement été mesuré.

Exemple pour un scénario public réussi :

> La campagne publique a été exécutée jusqu’à 20 utilisateurs virtuels simultanés. Toutes les requêtes ont réussi, sans réponse HTTP 429 ni erreur réseau. Au dernier palier, le débit utile mesuré était de 18,29 requêtes par seconde et le p95 des requêtes réussies de 151 ms. Aucun point de saturation n’a été atteint. Le test démontre donc une capacité minimale de 20 utilisateurs virtuels dans les conditions de la campagne, mais ne détermine pas la capacité maximale du service.

Exemple pour un scénario administratif interrompu :

> Le scénario administratif a fonctionné avec un utilisateur virtuel. L’initialisation du deuxième utilisateur a échoué lors de l’utilisation simultanée du même compte. Le scénario administratif multiutilisateur est donc non concluant. Ce résultat ne démontre ni une saturation du CMS, ni une limite de SQLite, ni une incapacité générale à prendre en charge plusieurs éditeurs. Une campagne utilisant plusieurs comptes distincts est nécessaire.

## Méthodologie recommandée

Pour améliorer la fiabilité des résultats :

* utiliser une machine de test dédiée et stable ;
* privilégier une connexion réseau filaire ;
* fermer les applications consommant du processeur ou du réseau ;
* désactiver les téléchargements et synchronisations automatiques ;
* synchroniser l’horloge de la machine ;
* indiquer le lieu, le fournisseur d’accès et l’adresse IP publique de la machine de test ;
* indiquer la date, l’heure et le fuseau horaire ;
* relever la latence réseau de référence avant la campagne ;
* exécuter un palier de préchauffage non intégré aux résultats ;
* répéter chaque campagne au moins trois fois ;
* conserver les trois campagnes, et non uniquement la meilleure ;
* exécuter les campagnes à différents moments de la journée ;
* ne modifier qu’un paramètre lors d’une comparaison ;
* utiliser le même code, les mêmes données et la même infrastructure pour comparer SQLite et MySQL ;
* enregistrer simultanément les métriques CPU, mémoire, disque, réseau et PHP-FPM ;
* conserver les journaux du serveur web, de PHP et de la base de données ;
* documenter les règles de cache, de CDN et de limitation de débit ;
* utiliser une route isolée et des données temporaires pour tester les écritures concurrentes ;
* exécuter les campagnes publique et administrative séparément ;
* prévoir un compte distinct par utilisateur virtuel pour les tests multiéditeurs ;
* ne pas publier les fichiers contenant les identifiants de test ;
* conserver les résultats des paliers terminés même si un scénario suivant échoue ;
* distinguer systématiquement les statuts `PASS`, `FAIL`, `INCONCLUSIVE` et `SKIPPED`.

## Comparaison SQLite et MySQL

Une comparaison valable doit utiliser deux environnements aussi identiques que possible :

* même version du CMS ;
* mêmes contenus ;
* même version PHP ;
* même configuration du serveur web ;
* mêmes ressources CPU et mémoire ;
* même cache ;
* même localisation réseau ;
* mêmes scénarios ;
* mêmes durées ;
* mêmes paliers.

Le moteur de base de données doit être la seule différence significative.

Les tests de lecture publique ne suffisent pas pour évaluer les limites de SQLite en écriture. Un scénario distinct doit tester, sur des données temporaires et isolées :

* la création d’un brouillon ;
* la mise à jour d’un contenu de test ;
* l’enregistrement de métadonnées ;
* une transaction courte ;
* le nettoyage automatique des données créées.

Aucune campagne d’écriture ne doit être exécutée sur les contenus réels d’un site de production.
