---
title: Registre de capacités
audience:
  - developer
status: stable
last_verified: 2026-07-12
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Registre de capacités

Le registre de capacités est le point d'extension contrôlé du CMS. Il complète les routes et hooks déclaratifs : un module annonce explicitement ce qu'il fournit ou consomme, avec un contrat versionné, une permission et des règles d'exécution.

## Contrat

Chaque capacité déclarée contient :

- `key` : identifiant stable, choisi dans le catalogue autorisé par le core.
- `version` : version de contrat fournie par le module.
- `module` : module provider responsable.
- `type` : `port`, `provider`, `workflow`, `event` ou `validator`.
- `contract` : identifiant de contrat versionné attendu avant exécution.
- `config` : configuration déclarative auditée.
- `permission` : permission IAM requise.
- `active` : état d'activation.
- `priority` : ordre déterministe lorsque plusieurs capacités doivent être triées.

Le registre refuse les clés inconnues, les collisions et les contrats incompatibles. Les refus sont exposés par `GET /admin/api/capabilities` dans `diagnostics`.

## Capacités autorisées

Le catalogue initial couvre les points natifs réellement utiles au runtime :

- `core.context.describe`
- `catalog.product.read`
- `catalog.product.extend`
- `pricing.calculate`
- `cart.validate`
- `checkout.validate`
- `order.after_place`
- `payment.provider`
- `fulfillment.provider`
- `notification.provider`
- `crm.activity.consume`

Un module ne doit pas inventer une nouvelle clé sans évolution du core. Cela évite qu'une extension se présente comme intégrée alors qu'aucun contrat runtime ne la protège.

## Règles

Une extension ne modifie pas directement les tables d'un autre module. Elle passe par un port, un provider, un workflow ou un événement documenté.

Un validateur ne mute pas une commande, un paiement ou un panier. Il renvoie un résultat de validation et laisse le workflow propriétaire appliquer les changements.

Une action après commande passe par un événement ou une outbox. Les effets externes restent isolés du placement de commande.

Un provider doit annoncer le contrat qu'il implémente. Si le client demande un contrat différent, l'exécuteur refuse avant d'appeler le handler.

Une erreur de provider est capturée, journalisée dans `action_runs` et renvoyée sans corrompre le panier, la commande ou le paiement.

## Exemple

Le module Vente déclare notamment :

- `cart.validate` et `checkout.validate` comme validateurs sans mutation.
- `payment.provider` comme provider compatible `sale.payment_provider.v1`.
- `order.after_place` comme événement publié via outbox.

Ces capacités ne déclarent aucune table étrangère dans `config.foreign_tables`. Les handlers d'exemple retournent des dry-runs contrôlés et ne capturent aucun paiement.

## Vérification

Avant d'ajouter une extension :

1. déclarer la capacité dans le provider du module ;
2. vérifier que la clé existe dans le catalogue core ;
3. associer une permission IAM existante ;
4. documenter le contrat et le mode d'erreur ;
5. ajouter un test de découverte, de conflit et de compatibilité ;
6. exécuter la qualification.

```bash
php tools/php/tests/run.php
python3 tools/cms.py validate --validator CAPABILITY_REGISTRY
python3 tools/cms.py validate --full --category shared --category operations --category documentation --category api
```
