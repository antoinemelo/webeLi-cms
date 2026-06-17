# Base de données — vue d’ensemble

Le CMS utilise SQLite comme stockage local principal. Les schémas sont versionnés dans `database/` et les bases locales sont reconstruites dans `storage/database/`.

## Bases

| Base | Schéma | Fichier runtime | Contenu |
|---|---|---|---|
| Core | `database/schema/core.sql` | `storage/database/core.sqlite` | Sites, langues, contenus, révisions, routes, SEO, recherche, médias, menus, taxonomies, layouts, blueprints, jobs. |
| IAM | `database/iam.sql` | `storage/database/iam.sqlite` | Utilisateurs, rôles, permissions, sessions, audit, rate limits. |
| Forms | `database/modules/forms.sql` | `storage/database/forms.sqlite` | Formulaires, traductions, champs, soumissions, anti-spam, notifications. |
| Cookies | `database/modules/cookies.sql` | `storage/database/cookies.sqlite` | Bannière, catégories, services, scripts conditionnels, logs de consentement. |
| IA optionnelle | `database/modules/ai.sql` | `storage/database/ai.sqlite` | Providers, modèles, prompts, tâches, suggestions et usage du futur module IA. Base séparée, optionnelle et reconstructible. |

## Vérité éditoriale

La vérité éditoriale repose sur quatre tables clés :

| Table | Rôle |
|---|---|
| `content_entries` | Identité stable d'un contenu : site, type, clé, parent, état agrégé. |
| `revisions` | Versions éditoriales par langue, avec le document JSON et les blocs. |
| `content_entry_working_revisions` | Révision de travail actuelle par entrée et langue. |
| `content_entry_publications` | Révision publiée actuelle par entrée et langue. |

Le front public ne rend pas directement `content_entry_localizations` ni les brouillons. Ces données servent au back-office et aux workflows, mais la publication produit des projections dédiées au runtime public.

## Publication et projections

La publication transforme une révision validée en état public stable. Elle met à jour notamment :

- `routes` ;
- `redirects` ;
- `tombstones` ;
- `seo_metadata` ;
- `search_documents` et `search_documents_fts` ;
- `public_content_snapshots` ;
- les valeurs de champs projetées lorsque nécessaire.

Cette séparation évite de recalculer chaque page à partir des brouillons au moment de la requête publique.

## Blueprints et champs

Le schéma core contient deux générations complémentaires :

| Ensemble | Rôle |
|---|---|
| `schema_field_types` | Registre temporaire des types de champs. |
| `blueprints`, `blueprint_versions`, `blueprint_publications`, `blueprint_sections`, `blueprint_fields`, `fieldsets`, `fieldset_fields` | Blueprints versionnés utilisés par l'édition native et l'éditeur visuel. |

Règles stables :

- un blueprint actif ne doit pas rendre supprimable un champ système nécessaire ;
- les handles réservés comme `title`, `slug`, `status`, `language`, `site`, `type` doivent rester contrôlés ;
- les champs requis doivent être validés avant publication ;
- les blocs seedés doivent rester éditables, validables et rendus.

## Multisite et multilingue

Tables principales :

- `sites` ;
- `site_domains` ;
- `languages` ;
- `site_languages` ;
- `site_localizations` ;
- `site_settings` ;
- `site_preferences`.

Les unicités importantes sont site-scopées : menus, taxonomies, variables globales, routes, SEO et recherche doivent être isolés par site et langue lorsque nécessaire.

## Taxonomies

Les taxonomies sont natives et liées aux contenus via :

- `taxonomies` ;
- `taxonomy_terms` ;
- `taxonomy_term_localizations` ;
- `content_type_taxonomies` ;
- `content_entry_taxonomy_terms`.

Une taxonomie créée depuis le back-office est rattachée aux types de contenus configurés pour accepter les taxonomies. Le seed from scratch conserve les associations nécessaires pour les pages et articles de démonstration.

## Menus

Les menus reposent sur :

- `menus` ;
- `menu_items` ;
- `menu_item_localizations`.

Les sous-menus doivent être persistés en base avec leur parenté et leur ordre. Le front public ne doit pas reconstruire une hiérarchie à partir d'un ordre visuel non stocké.

## Médias

La médiathèque repose sur :

- `media_folders` ;
- `media_assets` ;
- `media_asset_localizations` ;
- `media_variant_presets` ;
- `media_asset_variants` ;
- `media_usages` ;
- `media_asset_events`.

Les fichiers physiques doivent rester confinés dans `storage/media/`. Les usages (`media_usages`) protègent contre les suppressions dangereuses et permettent l'hygiène de la médiathèque.

## Modules

### Formulaires

`forms.sqlite` porte les formulaires, champs, traductions et soumissions. Les pages publiques insèrent un bloc `form` qui référence un `form_key`. Le core ne duplique pas les soumissions.

### Cookies

`cookies.sqlite` porte la configuration de bannière, les catégories, les services, les scripts conditionnels et les logs minimaux de preuve.

### Analytics

`database/modules/analytics.sql` existe comme base de travail. Il ne doit pas être documenté comme module fonctionnel complet tant qu'il n'est pas câblé dans le runtime et le back-office.

### IA optionnelle

`ai.sqlite` est la base dédiée au futur module IA. Elle ne fait pas partie de la vérité éditoriale du CMS. En développement local, elle est créée automatiquement par `a_db_init.py` et seedée par `b0_db_seed.py`; au runtime, le CMS doit continuer à fonctionner si elle est absente lorsque le module IA n’est pas installé ou activé.

Sources associées :

- `database/modules/ai.sql` pour le schéma ;
- `database/seeds/default/ai_default_seed.sql` pour les seeds minimaux ;
- `tools/python/a_db_init.py` et `b0_db_seed.py` pour le cycle global from scratch, ainsi que `create_ai_database.py`, `reset_ai_database.py`, `validate_ai_database.py` et `c85_validate_ai_database.py` pour la gestion ciblée.

Aucune clé API IA ne doit y être stockée en clair. Les actions qui modifient le CMS doivent toujours passer par les capabilities du core et par `action_runs`.

## Reconstruction

Commande standard :

```bash
python3 tools/python/a_db_init.py
```

Cycle complet conseillé avant livraison :

```bash
python3 tools/python/a_db_init.py
python3 tools/cms.py qualify --profile complete
python3 tools/python/d_deploy.py
```

Toute évolution de schéma doit être reconstruisible depuis `database/`, seedable depuis `database/seeds/` et vérifiable par un script non destructif.

## Multilingue : clé d’entrée et slug

`content_entries.entry_key` est l’identité éditoriale stable d’une ressource. Les traductions d’une même page ou d’un même article partagent donc le même `entry_id` et la même clé d’entrée. Les slugs restent stockés par langue dans les localisations et peuvent diverger librement afin de produire des URLs lisibles pour chaque public.
