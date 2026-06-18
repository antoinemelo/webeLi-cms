---
title: Exploiter et maintenir une instance
audience:
  - administrator
  - superadministrator
  - operator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - tools/python/operations
  - tools/python/qualification/run_all.py

owners:
  - operations
  - core
document_type: guide
permissions:
  - maintenance.manage
source_paths:
  - backend/src/Application/Api/Admin/MaintenanceApiController.php
  - backend/routes/api.php
  - tools/cms.py
  - tools/python/operations
  - tools/python/validators
generated: false
---

# Exploiter et maintenir une instance

Ce runbook rassemble les opérations courantes qui permettent de vérifier l’état d’une instance, préparer une intervention et revenir à une situation stable en cas d’échec.

## Public concerné

Cette page s’adresse aux administrateurs techniques, aux superadministrateurs et aux personnes chargées de l’exploitation. Les commandes exécutées sur le serveur nécessitent un accès au projet et aux fichiers de configuration locaux.

## Avant toute intervention

1. Identifiez l’instance, le site et la langue concernés.
2. Vérifiez que personne n’effectue une publication ou une opération d’administration sensible.
3. Contrôlez l’espace disque disponible.
4. Créez une sauvegarde lorsque l’opération touche aux bases, aux médias, à la configuration ou à une release.
5. Notez la version installée et la commande que vous allez exécuter.

Utilisez la façade `python3 tools/cms.py` lorsqu’une commande équivalente existe. Elle centralise les contrôles et limite les écarts entre une intervention locale, la CI et la préparation d’une release.

Pour une intervention interactive, utilisez le menu officiel :

```bash
python3 tools/admin.py
```

Ce menu appelle la façade stable `tools/cms.py` pour les qualifications, tests, sauvegardes, préparations de release et déploiements FTP.

## Contrôle rapide de l’instance

Depuis la racine du projet :

```bash
python3 tools/cms.py validate
python3 tools/cms.py test
python3 tools/cms.py docs check
```

Une commande interrompue ou terminée avec un code différent de zéro doit être considérée comme un échec. Corrigez la cause avant de poursuivre une publication, une sauvegarde de référence ou une création de release.

## Contexte site de l’API de maintenance

L’endpoint administratif `/admin/api/maintenance` fonctionne toujours dans le contexte d’un site déterminé, même pour un `super_admin`.

Le contrôleur résout le site actif avant de calculer l’état de maintenance ou d’appeler une opération. Les services reçoivent donc un `site_id` concret et non une valeur implicite ou indéfinie.

Avant d’utiliser l’écran ou l’API de maintenance :

1. sélectionnez le site concerné dans le back-office ;
2. vérifiez la langue active lorsque l’opération dépend des projections ou de la recherche ;
3. rechargez l’état de maintenance ;
4. n’exécutez l’action qu’après avoir confirmé que le site affiché est le bon.

Si aucun site valide ne peut être résolu, l’API doit retourner une erreur structurée. Il ne faut pas contourner cette erreur en lançant une opération globale ou en inventant un identifiant de site.

## Sauvegarder avant une opération sensible

Utilisez la commande documentée par la façade :

```bash
python3 tools/cms.py backup --help
```

Consultez l’aide avant la première exécution sur une instance. Vérifiez ensuite que l’archive produite contient les bases, la configuration locale attendue et les autres données prévues par le contrat de sauvegarde.

Une sauvegarde n’est considérée comme fiable qu’après un test de restauration sur une copie isolée.

## Vider un cache

Le vidage de cache doit rester ciblé et être suivi d’un contrôle du site concerné.

Après l’opération :

1. ouvrez une page publique du site ;
2. vérifiez une page du back-office ;
3. contrôlez qu’aucune erreur n’apparaît dans les journaux ;
4. confirmez que le site et la langue actifs sont ceux attendus.

Évitez d’effacer manuellement des répertoires dont le rôle n’est pas établi. Une suppression directe peut retirer des fichiers qui ne seront pas régénérés automatiquement.

## Recherche et projections publiées

Après une modification structurelle, une reconstruction ou une restauration :

1. vérifiez les projections publiées ;
2. contrôlez l’index de recherche ;
3. recherchez un contenu publié connu ;
4. vérifiez qu’un brouillon non publié n’est pas exposé publiquement ;
5. contrôlez le résultat pour chaque site et chaque langue concernés.

Utilisez l’aide de la commande avant de lancer une reconstruction :

```bash
python3 tools/cms.py rebuild --help
```

## Journaux et audits

Consultez les journaux après toute opération sensible, notamment :

- connexion ou modification des droits ;
- publication, dépublication ou archivage ;
- maintenance et vidage de cache ;
- sauvegarde ou restauration ;
- création et vérification d’une release.

Conservez les journaux selon la politique de l’instance. Ne les supprimez pas uniquement pour libérer de l’espace sans avoir identifié leur utilité opérationnelle et réglementaire.

## Préparer une release

Pour suivre l'assistant interactif :

```bash
python3 tools/admin.py
```

Pour exécuter les contrôles séparément ou dans une automatisation :

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py validate
python3 tools/cms.py test
python3 tools/cms.py release --help
```

La release doit être créée depuis un état propre. Son archive doit ensuite être vérifiée avec l’outil prévu par le projet. Une archive créée mais non vérifiée ne doit pas être déployée.

## Publier une mise à jour

Deux modes d'exploitation sont supportés :

- `Git sur serveur` : le serveur suit une branche GitHub (`staging` pour `/mod`, `main` pour `/eve`) et l'opérateur exécute `git pull --ff-only`, puis le préflight local ;
- `FTP/FTPS piloté` : le poste de développement prépare une release avec les scripts Python, puis `d_deploy.py ftp-dry-run` et `d_deploy.py ftp-deploy` transfèrent le staging validé.

Après un FTP réussi depuis `tools/admin.py`, le menu peut proposer de créer un commit puis de pousser la branche si le dépôt Git local est configuré pour cela. Acceptez uniquement après avoir vérifié que l'état Git affiché correspond bien aux fichiers à conserver.

Pour Hostpoint et les chemins `webe.li/mod` et `webe.li/eve`, suivez la procédure dédiée : [Déployer sur Hostpoint avec Git ou FTP](hostpoint-git-ftp.md).

Dans les deux cas, une publication de code ne remplace pas une sauvegarde. Avant les migrations, restaurations, changements de configuration ou mises à jour majeures, créez une sauvegarde SQLite et notez le point Git ou la release associée.

## Après une intervention

1. Vérifiez le site public et le back-office.
2. Contrôlez les pages principales dans les langues actives.
3. Testez une recherche et l’accès à un média.
4. Consultez les journaux.
5. Notez l’opération, son résultat et la sauvegarde associée.
6. En cas de dégradation, arrêtez les interventions successives et appliquez la procédure de restauration ou de rollback documentée.

## Erreurs fréquentes

### L’API de maintenance indique qu’aucun site n’est disponible

Revenez au sélecteur de site, choisissez un site accessible à votre compte, puis rechargez l’écran. Pour un superadministrateur également, l’opération exige un contexte de site concret.

### Une commande n’existe pas

Exécutez :

```bash
python3 tools/cms.py --help
```

N’utilisez pas le nom d’un ancien script trouvé dans une note ou un historique. La référence des commandes générée sous `docs/reference/` constitue la liste vérifiable.

### Une validation échoue après une modification documentaire

Lancez d’abord :

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py validate
```

Corrigez la source du problème. Ne recréez pas une ancienne page uniquement pour satisfaire un chemin devenu obsolète ; lorsqu’un chemin reste contractuel dans le code, il doit pointer vers une page normative réelle.

## Voir aussi

- [Consulter les audits, maintenir et préparer les releases](runbook.md)
- [Sauvegarder, restaurer et revenir à une version précédente](backup-restore.md)
- [Utiliser la façade Python](../development/python-tooling/cli.md)
