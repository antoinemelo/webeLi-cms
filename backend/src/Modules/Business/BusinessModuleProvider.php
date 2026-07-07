<?php

declare(strict_types=1);

namespace App\Modules\Business;

use App\Module\ModuleProvider;

/**
 * Module systeme Business.
 *
 * Le provider declare la base business.sqlite, les permissions, la navigation,
 * les contrats admin et les blueprints metier. Les blueprints CRM restent une
 * description admin/schema discovery : ils ne generent pas l'UX Relations et
 * n'exposent aucune route headless publique.
 */
final class BusinessModuleProvider implements ModuleProvider
{
    public function key(): string { return 'business'; }

    public function name(): string { return 'Business CRM'; }

    public function version(): string { return '0.1.0'; }

    public function description(): string
    {
        return 'CRM leger pour entreprises, contacts, memos, consentements, mailing simple et outbox messaging dans business.sqlite.';
    }

    /** @return list<string> */
    public function dependencies(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function databases(): array
    {
        return [[
            'key' => 'business',
            'driver' => 'sqlite',
            'path' => 'storage/database/business.sqlite',
            'schema' => 'database/modules/business.sql',
            'required' => true,
        ]];
    }

    /** @return list<array<string,string>> */
    public function permissions(): array
    {
        return [
            ['key' => 'business.crm.read', 'name' => 'Lire le CRM Business', 'description' => 'Lire les entreprises, contacts, tags, consentements et donnees CRM autorisees.'],
            ['key' => 'business.crm.manage', 'name' => 'Gerer le CRM Business', 'description' => 'Creer, modifier et archiver entreprises, contacts, tags et consentements.'],
            ['key' => 'business.memo.read', 'name' => 'Lire les memos CRM', 'description' => 'Consulter les memos CRM accessibles et leurs partages internes.'],
            ['key' => 'business.memo.manage', 'name' => 'Gerer les memos CRM', 'description' => 'Creer, modifier, commenter et archiver les memos CRM.'],
            ['key' => 'business.memo.share', 'name' => 'Partager les memos CRM', 'description' => 'Creer ou revoquer des partages internes et liens publics de memos.'],
            ['key' => 'business.mailing.read', 'name' => 'Lire le mailing Business', 'description' => 'Consulter listes, campagnes et historiques de diffusion.'],
            ['key' => 'business.mailing.manage', 'name' => 'Gerer le mailing Business', 'description' => 'Gerer listes, campagnes simples, destinataires et desabonnements.'],
            ['key' => 'business.messaging.send', 'name' => 'Envoyer des messages Business', 'description' => 'Planifier ou declencher un envoi apres controle du consentement.'],
            ['key' => 'business.messaging.admin', 'name' => 'Administrer le messaging Business', 'description' => 'Configurer providers, templates et outbox messaging sans stocker de secret en clair.'],
            ['key' => 'business.catalog.read', 'name' => 'Lire le catalogue Business', 'description' => 'Consulter marques, categories, produits, variantes, options, prix publics et reductions catalogue.'],
            ['key' => 'business.catalog.write', 'name' => 'Gerer le catalogue Business', 'description' => 'Creer, modifier et archiver marques, categories, produits, options et variantes.'],
            ['key' => 'business.catalog.prices.read', 'name' => 'Lire les prix catalogue', 'description' => 'Consulter les prix de vente et prix calcules du catalogue.'],
            ['key' => 'business.catalog.prices.write', 'name' => 'Gerer les prix catalogue', 'description' => 'Modifier prix de base et ajustements de variantes.'],
            ['key' => 'business.catalog.purchase_prices.read', 'name' => 'Lire les prix achat catalogue', 'description' => 'Consulter les prix d achat et marges catalogue proteges.'],
            ['key' => 'business.catalog.discounts.write', 'name' => 'Gerer les reductions catalogue', 'description' => 'Creer, modifier et archiver les reductions et offres catalogue.'],
            ['key' => 'business.catalog.stock.write', 'name' => 'Gerer le stock catalogue', 'description' => 'Modifier les mouvements de stock simples du catalogue.'],
        ];
    }

    /** @return array<string,mixed> */
    public function settingsSchema(): array
    {
        return [
            'schema_version' => 1,
            'database' => ['key' => 'business', 'path' => 'storage/database/business.sqlite'],
            'defaults' => [
                'system_individuals_company' => 'Individus',
                'channels' => ['email', 'whatsapp', 'telegram'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function blueprints(): array
    {
        return [
            $this->crmBlueprint(
                'relation',
                'Relation Business',
                'Agrégat de lecture pour la vue Relations regroupant entreprises et individus.',
                'business_relations_aggregate',
                $this->relationFields(),
                ['read' => 'business.crm.read', 'export' => 'business.crm.manage'],
                [
                    'aggregate' => true,
                    'create' => false,
                    'update' => false,
                    'delete' => false,
                    'sources' => ['business_companies', 'business_contacts', 'crm_memos', 'crm_memo_shares'],
                ]
            ),
            $this->crmBlueprint(
                'company',
                'Entreprise',
                'Entreprise CRM stockée dans business.sqlite. L’entreprise système Individus reste protégée par le service métier.',
                'business_companies',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('site_id', 'Site', 'number', 'identity', true, 'site_id', ['system' => true]),
                    $this->field('name', 'Nom', 'text', 'identity', true, 'name'),
                    $this->field('legal_name', 'Nom légal', 'text', 'identity', false, 'name'),
                    $this->enumField('status', 'Statut', $this->crmStatuses(), 'identity', true, 'status'),
                    $this->field('email', 'E-mail', 'email', 'contact', false, 'email'),
                    $this->field('phone', 'Téléphone', 'text', 'contact', false, 'phone'),
                    $this->field('website', 'Site web', 'url', 'contact', false, 'website_url'),
                    $this->field('address_line1', 'Adresse ligne 1', 'text', 'address', false, 'address_json'),
                    $this->field('address_line2', 'Adresse ligne 2', 'text', 'address', false, 'address_json'),
                    $this->field('postal_code', 'Code postal', 'text', 'address', false, 'address_json'),
                    $this->field('city', 'Ville', 'text', 'address', false, 'address_json'),
                    $this->field('country', 'Pays', 'text', 'address', false, 'address_json'),
                    $this->field('vat_number', 'TVA', 'text', 'identity', false, 'address_json'),
                    $this->field('notes_private', 'Notes privées', 'textarea', 'notes', false, 'notes', ['sensitive' => true]),
                    $this->field('created_by_iam_user_id', 'Créé par', 'number', 'audit', false, 'created_by_iam_user_id', ['system' => true]),
                    $this->field('updated_by_iam_user_id', 'Modifié par', 'number', 'audit', false, 'updated_by_iam_user_id', ['system' => true]),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                    $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
                    $this->field('archived_at', 'Archivé le', 'datetime', 'audit', false, 'archived_at', ['system' => true]),
                ],
                ['read' => 'business.crm.read', 'create' => 'business.crm.manage', 'update' => 'business.crm.manage', 'archive' => 'business.crm.manage', 'delete' => 'business.crm.manage', 'export' => 'business.crm.manage'],
                ['protected_rows' => ['is_system' => 1, 'company_kind' => 'system_individuals']]
            ),
            $this->crmBlueprint(
                'contact',
                'Contact',
                'Individu ou contact lié à une entreprise, éventuellement rattaché à un compte IAM.',
                'business_contacts',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('site_id', 'Site', 'number', 'identity', true, 'site_id', ['system' => true]),
                    $this->field('company_id', 'Entreprise', 'relation', 'identity', true, 'company_id'),
                    $this->field('iam_user_id', 'Compte IAM', 'relation', 'identity', false, 'iam_user_id', ['nullable' => true, 'unique_active' => true, 'source_endpoint' => '/admin/api/business/iam/available-users']),
                    $this->field('first_name', 'Prénom', 'text', 'identity', false, 'first_name'),
                    $this->field('last_name', 'Nom', 'text', 'identity', false, 'last_name'),
                    $this->field('display_name', 'Nom affiché', 'text', 'identity', true, 'display_name'),
                    $this->field('email', 'E-mail', 'email', 'contact', false, 'email'),
                    $this->field('phone', 'Téléphone', 'text', 'contact', false, 'phone'),
                    $this->field('mobile', 'Mobile', 'text', 'contact', false, 'mobile'),
                    $this->field('job_title', 'Fonction', 'text', 'identity', false, 'job_title'),
                    $this->enumField('status', 'Statut', $this->crmStatuses(), 'identity', true, 'status'),
                    $this->field('language', 'Langue', 'text', 'identity', false, 'preferred_language'),
                    $this->field('notes_private', 'Notes privées', 'textarea', 'notes', false, 'notes', ['sensitive' => true]),
                    $this->field('created_by_iam_user_id', 'Créé par', 'number', 'audit', false, 'created_by_iam_user_id', ['system' => true]),
                    $this->field('updated_by_iam_user_id', 'Modifié par', 'number', 'audit', false, 'updated_by_iam_user_id', ['system' => true]),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                    $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
                    $this->field('archived_at', 'Archivé le', 'datetime', 'audit', false, 'archived_at', ['system' => true]),
                ],
                ['read' => 'business.crm.read', 'create' => 'business.crm.manage', 'update' => 'business.crm.manage', 'archive' => 'business.crm.manage', 'delete' => 'business.crm.manage', 'export' => 'business.crm.manage'],
                ['relations' => [['resource' => 'company', 'type' => 'belongs_to', 'foreign_key' => 'company_id']]]
            ),
            $this->crmBlueprint(
                'memo',
                'Mémo CRM',
                'Mémo interne lié à une entreprise, un contact ou les deux. Les tokens de partage ne sont jamais exportés en clair.',
                'crm_memos',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('site_id', 'Site', 'number', 'identity', true, 'site_id', ['system' => true]),
                    $this->field('company_id', 'Entreprise', 'relation', 'target', false, 'company_id'),
                    $this->field('contact_id', 'Contact', 'relation', 'target', false, 'contact_id'),
                    $this->field('author_iam_user_id', 'Auteur', 'relation', 'identity', true, 'author_iam_user_id', ['system' => true]),
                    $this->field('title', 'Titre', 'text', 'content', true, 'title'),
                    $this->field('body', 'Contenu', 'textarea', 'content', false, 'body'),
                    $this->enumField('visibility', 'Visibilité', ['private', 'internal', 'public_link'], 'sharing', true, 'visibility'),
                    $this->field('is_shared', 'Partagé', 'boolean', 'sharing', false, 'id', ['computed' => true]),
                    $this->field('share_token_hash', 'Hash token de partage', 'text', 'sharing', false, 'id', ['sensitive' => true, 'exportable' => false, 'computed' => true]),
                    $this->field('share_expires_at', 'Expiration du partage', 'datetime', 'sharing', false, 'id', ['computed' => true]),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                    $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
                    $this->field('archived_at', 'Archivé le', 'datetime', 'audit', false, 'archived_at', ['system' => true]),
                ],
                ['read' => 'business.memo.read', 'create' => 'business.memo.manage', 'update' => 'business.memo.manage', 'archive' => 'business.memo.manage', 'share' => 'business.memo.share', 'export' => 'business.memo.manage'],
                [
                    'validation' => ['any_required' => ['company_id', 'contact_id']],
                    'relations' => [
                        ['resource' => 'company', 'type' => 'belongs_to', 'foreign_key' => 'company_id'],
                        ['resource' => 'contact', 'type' => 'belongs_to', 'foreign_key' => 'contact_id'],
                        ['resource' => 'memo_comment', 'type' => 'has_many', 'foreign_key' => 'memo_id'],
                    ],
                    'noindex_public_share' => true,
                ]
            ),
            $this->crmBlueprint(
                'memo_comment',
                'Commentaire de mémo',
                'Commentaire authentifié sur un mémo CRM. Aucun commentaire anonyme n’est accepté via lien public.',
                'crm_memo_comments',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('memo_id', 'Mémo', 'relation', 'identity', true, 'memo_id'),
                    $this->field('author_iam_user_id', 'Auteur IAM', 'relation', 'identity', true, 'author_iam_user_id', ['system' => true]),
                    $this->field('body', 'Commentaire', 'textarea', 'content', true, 'body'),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                    $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
                    $this->field('deleted_at', 'Supprimé le', 'datetime', 'audit', false, 'archived_at', ['system' => true]),
                ],
                ['read' => 'business.memo.read', 'create' => 'business.memo.manage', 'update' => 'business.memo.manage', 'delete' => 'business.memo.manage'],
                ['relations' => [['resource' => 'memo', 'type' => 'belongs_to', 'foreign_key' => 'memo_id']]]
            ),
            $this->crmBlueprint(
                'message',
                'Message sortant CRM',
                'Outbox des messages CRM envoyés via email, WhatsApp ou Telegram derrière les providers Business.',
                'crm_message_outbox',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('site_id', 'Site', 'number', 'identity', true, 'site_id', ['system' => true]),
                    $this->field('relation_type', 'Type de relation', 'select', 'target', false, 'contact_id', ['enum' => ['company', 'contact'], 'computed' => true]),
                    $this->field('relation_id', 'Relation', 'number', 'target', false, 'contact_id', ['computed' => true]),
                    $this->field('provider', 'Provider', 'relation', 'delivery', false, 'provider_id'),
                    $this->enumField('channel', 'Canal', $this->channels(), 'delivery', true, 'channel'),
                    $this->field('recipient', 'Destinataire', 'text', 'delivery', true, 'recipient_value'),
                    $this->field('subject', 'Sujet', 'text', 'content', false, 'subject'),
                    $this->field('body', 'Message', 'textarea', 'content', false, 'body_text'),
                    $this->enumField('status', 'Statut', ['pending', 'queued', 'sent', 'failed', 'cancelled', 'skipped'], 'delivery', true, 'status'),
                    $this->field('sent_by_iam_user_id', 'Envoyé par', 'relation', 'audit', false, 'created_by_iam_user_id', ['system' => true]),
                    $this->field('sent_at', 'Envoyé le', 'datetime', 'audit', false, 'sent_at'),
                    $this->field('error_message', 'Erreur', 'textarea', 'delivery', false, 'last_error'),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                ],
                ['read' => 'business.messaging.admin', 'create' => 'business.messaging.send', 'send' => 'business.messaging.send', 'manage' => 'business.messaging.admin', 'export' => 'business.messaging.admin'],
                ['relations' => [['resource' => 'contact', 'type' => 'belongs_to', 'foreign_key' => 'contact_id']]]
            ),
            $this->crmBlueprint(
                'consent',
                'Consentement de communication',
                'Consentement exploitable par les messages directs et le mailing simple.',
                'crm_consents',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('contact_id', 'Contact', 'relation', 'identity', true, 'contact_id'),
                    $this->enumField('channel', 'Canal', $this->channels(), 'identity', true, 'channel'),
                    $this->enumField('status', 'Statut', ['unknown', 'opted_in', 'opted_out'], 'identity', true, 'consent_status', ['storage_values' => ['unknown', 'opt_in', 'opt_out']]),
                    $this->enumField('source', 'Source', ['manual', 'form', 'import', 'unsubscribe', 'api'], 'identity', true, 'source'),
                    $this->field('confirmed_at', 'Confirmé le', 'datetime', 'timeline', false, 'granted_at'),
                    $this->field('revoked_at', 'Révoqué le', 'datetime', 'timeline', false, 'revoked_at'),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                    $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
                ],
                ['read' => 'business.crm.read', 'update' => 'business.crm.manage', 'export' => 'business.crm.manage'],
                ['relations' => [['resource' => 'contact', 'type' => 'belongs_to', 'foreign_key' => 'contact_id']]]
            ),
            $this->crmBlueprint(
                'mailing_list',
                'Liste de diffusion',
                'Liste de diffusion simple utilisée par le mailing Business.',
                'crm_mailing_lists',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('site_id', 'Site', 'number', 'identity', true, 'site_id', ['system' => true]),
                    $this->field('name', 'Nom', 'text', 'identity', true, 'name'),
                    $this->field('description', 'Description', 'textarea', 'identity', false, 'description'),
                    $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
                    $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
                    $this->field('archived_at', 'Archivé le', 'datetime', 'audit', false, 'archived_at', ['system' => true]),
                ],
                ['read' => 'business.mailing.read', 'create' => 'business.mailing.manage', 'update' => 'business.mailing.manage', 'archive' => 'business.mailing.manage', 'export' => 'business.mailing.manage'],
                ['relations' => [['resource' => 'mailing_list_member', 'type' => 'has_many', 'foreign_key' => 'list_id']]]
            ),
            $this->crmBlueprint(
                'mailing_list_member',
                'Membre de liste de diffusion',
                'Association entre une liste de diffusion et un contact CRM.',
                'crm_mailing_list_members',
                [
                    $this->field('id', 'ID', 'number', 'identity', false, 'id', ['system' => true]),
                    $this->field('list_id', 'Liste', 'relation', 'identity', true, 'list_id'),
                    $this->field('contact_id', 'Contact', 'relation', 'identity', true, 'contact_id'),
                    $this->enumField('status', 'Statut', ['subscribed', 'unsubscribed', 'bounced', 'archived'], 'identity', true, 'status'),
                    $this->field('subscribed_at', 'Inscrit le', 'datetime', 'audit', false, 'subscribed_at', ['system' => true]),
                    $this->field('unsubscribed_at', 'Désinscrit le', 'datetime', 'audit', false, 'unsubscribed_at'),
                ],
                ['read' => 'business.mailing.read', 'create' => 'business.mailing.manage', 'update' => 'business.mailing.manage', 'delete' => 'business.mailing.manage', 'export' => 'business.mailing.manage'],
                [
                    'relations' => [
                        ['resource' => 'mailing_list', 'type' => 'belongs_to', 'foreign_key' => 'list_id'],
                        ['resource' => 'contact', 'type' => 'belongs_to', 'foreign_key' => 'contact_id'],
                    ],
                ]
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function contentTypes(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function adminNavigation(): array
    {
        return [
            [
                'key' => 'business.crm',
                'label' => 'Business',
                'navLabel' => 'Business',
                'route' => '/business',
                'anyPermission' => [
                    'business.crm.read',
                    'business.memo.read',
                    'business.mailing.read',
                    'business.messaging.admin',
                    'business.catalog.read',
                ],
                'section' => 'Modules',
                'hint' => 'CRM, produits, offres, mailing simple et outbox messaging.',
                'sort_order' => 140,
            ],
        ];
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public function adminRoutes(): array
    {
        return [
            $this->route('GET', '/admin/api/business/schema', 'BusinessCrmApiController@schema'),
            $this->route('GET', '/admin/api/business/dashboard', 'BusinessCrmApiController@dashboard'),
            $this->route('GET', '/admin/api/business/search', 'BusinessCrmApiController@search'),
            $this->route('GET', '/admin/api/business/relations', 'BusinessCrmApiController@relations'),
            $this->route('POST', '/admin/api/business/relations', 'BusinessCrmApiController@storeRelation'),
            $this->route('GET', '/admin/api/business/relations/{type}/{id}/activity', 'BusinessCrmApiController@relationActivity'),
            $this->route('POST', '/admin/api/business/relations/{type}/{id}/summary', 'BusinessCrmApiController@summarizeRelation'),
            $this->route('GET', '/admin/api/business/relations/{type}/{id}/memos', 'BusinessCrmApiController@relationMemos'),
            $this->route('POST', '/admin/api/business/relations/{type}/{id}/memos', 'BusinessCrmApiController@storeRelationMemo'),
            $this->route('GET', '/admin/api/business/relations/{type}/{id}/comments', 'BusinessCrmApiController@relationComments'),
            $this->route('GET', '/admin/api/business/relations/{type}/{id}/messages', 'BusinessMessagingApiController@relationMessages'),
            $this->route('POST', '/admin/api/business/relations/{type}/{id}/messages', 'BusinessMessagingApiController@sendRelationMessage'),
            $this->route('PATCH', '/admin/api/business/relations/{type}/{id}', 'BusinessCrmApiController@updateRelation'),
            $this->route('POST', '/admin/api/business/relations/{type}/{id}/archive', 'BusinessCrmApiController@archiveRelation'),
            $this->route('DELETE', '/admin/api/business/relations/{type}/{id}', 'BusinessCrmApiController@deleteRelation'),
            $this->route('GET', '/admin/api/business/relations/{type}/{id}', 'BusinessCrmApiController@showRelation'),
            $this->route('GET', '/admin/api/business/iam/available-users', 'BusinessCrmApiController@availableIamUsers'),
            $this->route('GET', '/admin/api/business/contacts/available-iam-users', 'BusinessCrmApiController@availableIamUsers'),
            $this->route('GET', '/admin/api/business/companies', 'BusinessCrmApiController@companies'),
            $this->route('GET', '/admin/api/business/companies/export.csv', 'BusinessCrmApiController@exportCompaniesCsv'),
            $this->route('POST', '/admin/api/business/companies', 'BusinessCrmApiController@storeCompany'),
            $this->route('GET', '/admin/api/business/companies/{id}/contacts', 'BusinessCrmApiController@companyContacts'),
            $this->route('GET', '/admin/api/business/companies/{id}', 'BusinessCrmApiController@showCompany'),
            $this->route('PATCH', '/admin/api/business/companies/{id}', 'BusinessCrmApiController@updateCompany'),
            $this->route('POST', '/admin/api/business/companies/{id}/archive', 'BusinessCrmApiController@archiveCompany'),
            $this->route('DELETE', '/admin/api/business/companies/{id}', 'BusinessCrmApiController@deleteCompany'),
            $this->route('GET', '/admin/api/business/contacts', 'BusinessCrmApiController@contacts'),
            $this->route('GET', '/admin/api/business/contacts/export.csv', 'BusinessCrmApiController@exportContactsCsv'),
            $this->route('POST', '/admin/api/business/contacts/import.csv', 'BusinessCrmApiController@importContactsCsv'),
            $this->route('POST', '/admin/api/business/contacts', 'BusinessCrmApiController@storeContact'),
            $this->route('GET', '/admin/api/business/contacts/{id}', 'BusinessCrmApiController@showContact'),
            $this->route('PATCH', '/admin/api/business/contacts/{id}', 'BusinessCrmApiController@updateContact'),
            $this->route('POST', '/admin/api/business/contacts/{id}/archive', 'BusinessCrmApiController@archiveContact'),
            $this->route('DELETE', '/admin/api/business/contacts/{id}', 'BusinessCrmApiController@deleteContact'),
            $this->route('GET', '/admin/api/business/tags', 'BusinessCrmApiController@tags'),
            $this->route('POST', '/admin/api/business/tags', 'BusinessCrmApiController@storeTag'),
            $this->route('PATCH', '/admin/api/business/tags/{id}', 'BusinessCrmApiController@updateTag'),
            $this->route('DELETE', '/admin/api/business/tags/{id}', 'BusinessCrmApiController@deleteTag'),
            $this->route('POST', '/admin/api/business/tag-links', 'BusinessCrmApiController@storeTagLink'),
            $this->route('DELETE', '/admin/api/business/tag-links', 'BusinessCrmApiController@deleteTagLink'),
            $this->route('GET', '/admin/api/business/memos', 'BusinessCrmApiController@memos'),
            $this->route('POST', '/admin/api/business/memos', 'BusinessCrmApiController@storeMemo'),
            $this->route('GET', '/admin/api/business/memos/{id}', 'BusinessCrmApiController@showMemo'),
            $this->route('PATCH', '/admin/api/business/memos/{id}', 'BusinessCrmApiController@updateMemo'),
            $this->route('POST', '/admin/api/business/memos/{id}/archive', 'BusinessCrmApiController@archiveMemo'),
            $this->route('DELETE', '/admin/api/business/memos/{id}', 'BusinessCrmApiController@deleteMemo'),
            $this->route('GET', '/admin/api/business/memos/{id}/comments', 'BusinessCrmApiController@memoComments'),
            $this->route('POST', '/admin/api/business/memos/{id}/comments', 'BusinessCrmApiController@storeMemoComment'),
            $this->route('PATCH', '/admin/api/business/memos/{id}/comments/{commentId}', 'BusinessCrmApiController@updateMemoComment'),
            $this->route('POST', '/admin/api/business/memos/{id}/share', 'BusinessCrmApiController@createPublicMemoShare'),
            $this->route('DELETE', '/admin/api/business/memos/{id}/share', 'BusinessCrmApiController@deletePublicMemoShare'),
            $this->route('GET', '/admin/api/business/memos/{id}/shares', 'BusinessCrmApiController@memoShares'),
            $this->route('POST', '/admin/api/business/memos/{id}/shares/users', 'BusinessCrmApiController@storeMemoShare'),
            $this->route('DELETE', '/admin/api/business/memos/{id}/shares/users/{iamUserId}', 'BusinessCrmApiController@deleteMemoShareUser'),
            $this->route('POST', '/admin/api/business/memos/{id}/shares', 'BusinessCrmApiController@storeMemoShare'),
            $this->route('PATCH', '/admin/api/business/memos/{id}/shares/{shareId}', 'BusinessCrmApiController@updateMemoShare'),
            $this->route('DELETE', '/admin/api/business/memos/{id}/shares/{shareId}', 'BusinessCrmApiController@deleteMemoShare'),
            $this->route('POST', '/admin/api/business/memos/{id}/public-share', 'BusinessCrmApiController@createPublicMemoShare'),
            $this->route('DELETE', '/admin/api/business/memos/{id}/public-share', 'BusinessCrmApiController@deletePublicMemoShare'),
            $this->route('GET', '/admin/api/business/consents', 'BusinessCrmApiController@consents'),
            $this->route('POST', '/admin/api/business/consents', 'BusinessCrmApiController@storeConsent'),
            $this->route('PATCH', '/admin/api/business/consents/{id}', 'BusinessCrmApiController@updateConsent'),
            $this->route('GET', '/admin/api/business/contacts/{id}/consents', 'BusinessCrmApiController@contactConsents'),
            $this->route('PATCH', '/admin/api/business/contacts/{id}/consents/{channel}', 'BusinessCrmApiController@updateContactConsent'),
            $this->route('POST', '/admin/api/business/import/relations', 'BusinessCrmApiController@importRelations'),
            $this->route('POST', '/admin/api/business/import/contacts', 'BusinessCrmApiController@importContacts'),
            $this->route('POST', '/admin/api/business/import/companies', 'BusinessCrmApiController@importCompanies'),
            $this->route('GET', '/admin/api/business/export/relations', 'BusinessCrmApiController@exportRelations'),
            $this->route('GET', '/admin/api/business/export/contacts', 'BusinessCrmApiController@exportContacts'),
            $this->route('GET', '/admin/api/business/export/companies', 'BusinessCrmApiController@exportCompanies'),
            $this->route('GET', '/admin/api/business/export/memos', 'BusinessCrmApiController@exportMemos'),
            $this->route('GET', '/admin/api/business/messaging/providers', 'BusinessMessagingApiController@providers'),
            $this->route('POST', '/admin/api/business/messaging/providers', 'BusinessMessagingApiController@storeProvider'),
            $this->route('PATCH', '/admin/api/business/messaging/providers/{id}', 'BusinessMessagingApiController@updateProvider'),
            $this->route('DELETE', '/admin/api/business/messaging/providers/{id}', 'BusinessMessagingApiController@deleteProvider'),
            $this->route('GET', '/admin/api/business/messaging/outbox', 'BusinessMessagingApiController@outbox'),
            $this->route('POST', '/admin/api/business/messaging/send-test', 'BusinessMessagingApiController@sendTest'),
            $this->route('GET', '/admin/api/business/messages', 'BusinessMessagingApiController@messages'),
            $this->route('POST', '/admin/api/business/messages', 'BusinessMessagingApiController@storeMessage'),
            $this->route('GET', '/admin/api/business/messages/{id}', 'BusinessMessagingApiController@showMessage'),
            $this->route('POST', '/admin/api/business/messages/test', 'BusinessMessagingApiController@testMessage'),
            $this->route('POST', '/admin/api/business/messages/preview', 'BusinessMessagingApiController@previewMessage'),
            $this->route('POST', '/admin/api/business/messages/send', 'BusinessMessagingApiController@sendMessage'),
            $this->route('POST', '/admin/api/business/contacts/{id}/messages', 'BusinessMessagingApiController@sendContactMessage'),
            $this->route('GET', '/admin/api/business/mailing/lists', 'BusinessMailingApiController@lists'),
            $this->route('POST', '/admin/api/business/mailing/lists', 'BusinessMailingApiController@storeList'),
            $this->route('GET', '/admin/api/business/mailing/lists/{id}', 'BusinessMailingApiController@showList'),
            $this->route('PATCH', '/admin/api/business/mailing/lists/{id}', 'BusinessMailingApiController@updateList'),
            $this->route('POST', '/admin/api/business/mailing/lists/{id}/members', 'BusinessMailingApiController@addMember'),
            $this->route('DELETE', '/admin/api/business/mailing/lists/{id}/members/{contactId}', 'BusinessMailingApiController@removeMember'),
            $this->route('GET', '/admin/api/business/mailing/campaigns', 'BusinessMailingApiController@campaigns'),
            $this->route('POST', '/admin/api/business/mailing/campaigns', 'BusinessMailingApiController@storeCampaign'),
            $this->route('GET', '/admin/api/business/mailing/campaigns/{id}', 'BusinessMailingApiController@showCampaign'),
            $this->route('PATCH', '/admin/api/business/mailing/campaigns/{id}', 'BusinessMailingApiController@updateCampaign'),
            $this->route('POST', '/admin/api/business/mailing/campaigns/{id}/preview-recipients', 'BusinessMailingApiController@previewRecipients'),
            $this->route('POST', '/admin/api/business/mailing/campaigns/{id}/enqueue', 'BusinessMailingApiController@enqueueCampaign'),
            $this->route('POST', '/admin/api/business/mailing/campaigns/{id}/cancel', 'BusinessMailingApiController@cancelCampaign'),
        ];
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public function apiRoutes(): array { return []; }

    /** @return list<array{0:string,1:string,2:string}> */
    public function publicHeadlessRoutes(): array { return []; }

    /** @return array<string,list<callable|string>> */
    public function hooks(): array { return []; }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    public function migrations(): array { return []; }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    public function seeds(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function apiContracts(): array
    {
        return [
            $this->contract('admin.business.schema.v1', 'GET', '/admin/api/business/schema', 'business.crm.read'),
            $this->contract('admin.business.dashboard.v1', 'GET', '/admin/api/business/dashboard', 'business.crm.read'),
            $this->contract('admin.business.relations.index.v1', 'GET', '/admin/api/business/relations', 'business.crm.read'),
            $this->contract('admin.business.relations.store.v1', 'POST', '/admin/api/business/relations', 'business.crm.manage'),
            $this->contract('admin.business.relations.show.v1', 'GET', '/admin/api/business/relations/{type}/{id}', 'business.crm.read'),
            $this->contract('admin.business.relations.update.v1', 'PATCH', '/admin/api/business/relations/{type}/{id}', 'business.crm.manage'),
            $this->contract('admin.business.relations.archive.v1', 'POST', '/admin/api/business/relations/{type}/{id}/archive', 'business.crm.manage'),
            $this->contract('admin.business.relations.delete.v1', 'DELETE', '/admin/api/business/relations/{type}/{id}', 'business.crm.manage'),
            $this->contract('admin.business.relations.activity.v1', 'GET', '/admin/api/business/relations/{type}/{id}/activity', 'business.crm.read'),
            $this->contract('admin.business.relations.summary.v1', 'POST', '/admin/api/business/relations/{type}/{id}/summary', 'business.crm.read'),
            $this->contract('admin.business.relations.memos.v1', 'GET', '/admin/api/business/relations/{type}/{id}/memos', 'business.memo.read'),
            $this->contract('admin.business.relations.memos.store.v1', 'POST', '/admin/api/business/relations/{type}/{id}/memos', 'business.memo.manage'),
            $this->contract('admin.business.relations.comments.v1', 'GET', '/admin/api/business/relations/{type}/{id}/comments', 'business.memo.read'),
            $this->contract('admin.business.iam.available_users.v1', 'GET', '/admin/api/business/iam/available-users', 'business.crm.read'),
            $this->contract('admin.business.search.v1', 'GET', '/admin/api/business/search', 'business.crm.read'),
            $this->contract('admin.business.companies.index.v1', 'GET', '/admin/api/business/companies', 'business.crm.read'),
            $this->contract('admin.business.companies.export_csv.v1', 'GET', '/admin/api/business/companies/export.csv', 'business.crm.manage'),
            $this->contract('admin.business.companies.store.v1', 'POST', '/admin/api/business/companies', 'business.crm.manage'),
            $this->contract('admin.business.companies.contacts.v1', 'GET', '/admin/api/business/companies/{id}/contacts', 'business.crm.read'),
            $this->contract('admin.business.companies.show.v1', 'GET', '/admin/api/business/companies/{id}', 'business.crm.read'),
            $this->contract('admin.business.companies.update.v1', 'PATCH', '/admin/api/business/companies/{id}', 'business.crm.manage'),
            $this->contract('admin.business.companies.archive.v1', 'POST', '/admin/api/business/companies/{id}/archive', 'business.crm.manage'),
            $this->contract('admin.business.companies.delete.v1', 'DELETE', '/admin/api/business/companies/{id}', 'business.crm.manage'),
            $this->contract('admin.business.contacts.index.v1', 'GET', '/admin/api/business/contacts', 'business.crm.read'),
            $this->contract('admin.business.contacts.export_csv.v1', 'GET', '/admin/api/business/contacts/export.csv', 'business.crm.manage'),
            $this->contract('admin.business.contacts.import_csv.v1', 'POST', '/admin/api/business/contacts/import.csv', 'business.crm.manage'),
            $this->contract('admin.business.contacts.available_iam_users.v1', 'GET', '/admin/api/business/contacts/available-iam-users', 'business.crm.read'),
            $this->contract('admin.business.contacts.store.v1', 'POST', '/admin/api/business/contacts', 'business.crm.manage'),
            $this->contract('admin.business.contacts.show.v1', 'GET', '/admin/api/business/contacts/{id}', 'business.crm.read'),
            $this->contract('admin.business.contacts.update.v1', 'PATCH', '/admin/api/business/contacts/{id}', 'business.crm.manage'),
            $this->contract('admin.business.contacts.archive.v1', 'POST', '/admin/api/business/contacts/{id}/archive', 'business.crm.manage'),
            $this->contract('admin.business.contacts.delete.v1', 'DELETE', '/admin/api/business/contacts/{id}', 'business.crm.manage'),
            $this->contract('admin.business.tags.index.v1', 'GET', '/admin/api/business/tags', 'business.crm.read'),
            $this->contract('admin.business.tags.store.v1', 'POST', '/admin/api/business/tags', 'business.crm.manage'),
            $this->contract('admin.business.tags.update.v1', 'PATCH', '/admin/api/business/tags/{id}', 'business.crm.manage'),
            $this->contract('admin.business.tags.delete.v1', 'DELETE', '/admin/api/business/tags/{id}', 'business.crm.manage'),
            $this->contract('admin.business.tag_links.store.v1', 'POST', '/admin/api/business/tag-links', 'business.crm.manage'),
            $this->contract('admin.business.tag_links.delete.v1', 'DELETE', '/admin/api/business/tag-links', 'business.crm.manage'),
            $this->contract('admin.business.memos.index.v1', 'GET', '/admin/api/business/memos', 'business.memo.read'),
            $this->contract('admin.business.memos.store.v1', 'POST', '/admin/api/business/memos', 'business.memo.manage'),
            $this->contract('admin.business.memos.show.v1', 'GET', '/admin/api/business/memos/{id}', 'business.memo.read'),
            $this->contract('admin.business.memos.update.v1', 'PATCH', '/admin/api/business/memos/{id}', 'business.memo.manage'),
            $this->contract('admin.business.memos.archive.v1', 'POST', '/admin/api/business/memos/{id}/archive', 'business.memo.manage'),
            $this->contract('admin.business.memos.delete.v1', 'DELETE', '/admin/api/business/memos/{id}', 'business.memo.manage'),
            $this->contract('admin.business.memo_comments.index.v1', 'GET', '/admin/api/business/memos/{id}/comments', 'business.memo.read'),
            $this->contract('admin.business.memo_comments.store.v1', 'POST', '/admin/api/business/memos/{id}/comments', 'business.memo.manage'),
            $this->contract('admin.business.memo_comments.update.v1', 'PATCH', '/admin/api/business/memos/{id}/comments/{commentId}', 'business.memo.manage'),
            $this->contract('admin.business.memo_public_share.short_create.v1', 'POST', '/admin/api/business/memos/{id}/share', 'business.memo.share'),
            $this->contract('admin.business.memo_public_share.short_delete.v1', 'DELETE', '/admin/api/business/memos/{id}/share', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.index.v1', 'GET', '/admin/api/business/memos/{id}/shares', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.users.store.v1', 'POST', '/admin/api/business/memos/{id}/shares/users', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.users.delete.v1', 'DELETE', '/admin/api/business/memos/{id}/shares/users/{iamUserId}', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.store.v1', 'POST', '/admin/api/business/memos/{id}/shares', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.update.v1', 'PATCH', '/admin/api/business/memos/{id}/shares/{shareId}', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.delete.v1', 'DELETE', '/admin/api/business/memos/{id}/shares/{shareId}', 'business.memo.share'),
            $this->contract('admin.business.memo_public_share.create.v1', 'POST', '/admin/api/business/memos/{id}/public-share', 'business.memo.share'),
            $this->contract('admin.business.memo_public_share.delete.v1', 'DELETE', '/admin/api/business/memos/{id}/public-share', 'business.memo.share'),
            $this->contract('admin.business.consents.index.v1', 'GET', '/admin/api/business/consents', 'business.crm.read'),
            $this->contract('admin.business.consents.store.v1', 'POST', '/admin/api/business/consents', 'business.crm.manage'),
            $this->contract('admin.business.consents.update.v1', 'PATCH', '/admin/api/business/consents/{id}', 'business.crm.manage'),
            $this->contract('admin.business.contacts.consents.index.v1', 'GET', '/admin/api/business/contacts/{id}/consents', 'business.crm.read'),
            $this->contract('admin.business.contacts.consents.update.v1', 'PATCH', '/admin/api/business/contacts/{id}/consents/{channel}', 'business.crm.manage'),
            $this->contract('admin.business.import.relations.v1', 'POST', '/admin/api/business/import/relations', 'business.crm.manage'),
            $this->contract('admin.business.import.contacts.v1', 'POST', '/admin/api/business/import/contacts', 'business.crm.manage'),
            $this->contract('admin.business.import.companies.v1', 'POST', '/admin/api/business/import/companies', 'business.crm.manage'),
            $this->contract('admin.business.export.relations.v1', 'GET', '/admin/api/business/export/relations', 'business.crm.manage'),
            $this->contract('admin.business.export.contacts.v1', 'GET', '/admin/api/business/export/contacts', 'business.crm.manage'),
            $this->contract('admin.business.export.companies.v1', 'GET', '/admin/api/business/export/companies', 'business.crm.manage'),
            $this->contract('admin.business.export.memos.v1', 'GET', '/admin/api/business/export/memos', 'business.memo.manage'),
            $this->contract('admin.business.messaging.providers.v1', 'GET', '/admin/api/business/messaging/providers', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.providers.store.v1', 'POST', '/admin/api/business/messaging/providers', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.providers.update.v1', 'PATCH', '/admin/api/business/messaging/providers/{id}', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.providers.delete.v1', 'DELETE', '/admin/api/business/messaging/providers/{id}', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.outbox.v1', 'GET', '/admin/api/business/messaging/outbox', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.send_test.v1', 'POST', '/admin/api/business/messaging/send-test', 'business.messaging.admin'),
            $this->contract('admin.business.messages.index.v1', 'GET', '/admin/api/business/messages', 'business.messaging.admin'),
            $this->contract('admin.business.messages.store.v1', 'POST', '/admin/api/business/messages', 'business.messaging.send'),
            $this->contract('admin.business.messages.show.v1', 'GET', '/admin/api/business/messages/{id}', 'business.messaging.admin'),
            $this->contract('admin.business.messages.test.v1', 'POST', '/admin/api/business/messages/test', 'business.messaging.admin'),
            $this->contract('admin.business.messages.preview.v1', 'POST', '/admin/api/business/messages/preview', 'business.messaging.send'),
            $this->contract('admin.business.messages.send.v1', 'POST', '/admin/api/business/messages/send', 'business.messaging.send'),
            $this->contract('admin.business.relations.messages.v1', 'GET', '/admin/api/business/relations/{type}/{id}/messages', 'business.messaging.send'),
            $this->contract('admin.business.relations.messages.send.v1', 'POST', '/admin/api/business/relations/{type}/{id}/messages', 'business.messaging.send'),
            $this->contract('admin.business.contacts.messages.send.v1', 'POST', '/admin/api/business/contacts/{id}/messages', 'business.messaging.send'),
            $this->contract('admin.business.mailing.lists.index.v1', 'GET', '/admin/api/business/mailing/lists', 'business.mailing.read'),
            $this->contract('admin.business.mailing.lists.store.v1', 'POST', '/admin/api/business/mailing/lists', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.lists.show.v1', 'GET', '/admin/api/business/mailing/lists/{id}', 'business.mailing.read'),
            $this->contract('admin.business.mailing.lists.update.v1', 'PATCH', '/admin/api/business/mailing/lists/{id}', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.members.store.v1', 'POST', '/admin/api/business/mailing/lists/{id}/members', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.members.delete.v1', 'DELETE', '/admin/api/business/mailing/lists/{id}/members/{contactId}', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.campaigns.index.v1', 'GET', '/admin/api/business/mailing/campaigns', 'business.mailing.read'),
            $this->contract('admin.business.mailing.campaigns.store.v1', 'POST', '/admin/api/business/mailing/campaigns', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.campaigns.show.v1', 'GET', '/admin/api/business/mailing/campaigns/{id}', 'business.mailing.read'),
            $this->contract('admin.business.mailing.campaigns.update.v1', 'PATCH', '/admin/api/business/mailing/campaigns/{id}', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.campaigns.preview_recipients.v1', 'POST', '/admin/api/business/mailing/campaigns/{id}/preview-recipients', 'business.mailing.read'),
            $this->contract('admin.business.mailing.campaigns.enqueue.v1', 'POST', '/admin/api/business/mailing/campaigns/{id}/enqueue', 'business.mailing.manage'),
            $this->contract('admin.business.mailing.campaigns.cancel.v1', 'POST', '/admin/api/business/mailing/campaigns/{id}/cancel', 'business.mailing.manage'),
            $this->contract('admin.business.catalog.export.v1', 'GET', '/admin/api/business/catalog/export.csv', 'business.catalog.read'),
            $this->contract('admin.business.catalog.import.preview.v1', 'POST', '/admin/api/business/catalog/import/preview', 'business.catalog.write'),
            $this->contract('admin.business.catalog.import.apply.v1', 'POST', '/admin/api/business/catalog/import/apply', 'business.catalog.write'),
            $this->contract('admin.business.catalog.brands.index.v1', 'GET', '/admin/api/business/catalog/brands', 'business.catalog.read'),
            $this->contract('admin.business.catalog.brands.store.v1', 'POST', '/admin/api/business/catalog/brands', 'business.catalog.write'),
            $this->contract('admin.business.catalog.brands.show.v1', 'GET', '/admin/api/business/catalog/brands/{id}', 'business.catalog.read'),
            $this->contract('admin.business.catalog.brands.update.v1', 'PATCH', '/admin/api/business/catalog/brands/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.brands.delete.v1', 'DELETE', '/admin/api/business/catalog/brands/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.categories.index.v1', 'GET', '/admin/api/business/catalog/categories', 'business.catalog.read'),
            $this->contract('admin.business.catalog.categories.store.v1', 'POST', '/admin/api/business/catalog/categories', 'business.catalog.write'),
            $this->contract('admin.business.catalog.categories.show.v1', 'GET', '/admin/api/business/catalog/categories/{id}', 'business.catalog.read'),
            $this->contract('admin.business.catalog.categories.update.v1', 'PATCH', '/admin/api/business/catalog/categories/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.categories.delete.v1', 'DELETE', '/admin/api/business/catalog/categories/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.products.index.v1', 'GET', '/admin/api/business/catalog/products', 'business.catalog.read'),
            $this->contract('admin.business.catalog.products.store.v1', 'POST', '/admin/api/business/catalog/products', 'business.catalog.write'),
            $this->contract('admin.business.catalog.products.show.v1', 'GET', '/admin/api/business/catalog/products/{id}', 'business.catalog.read'),
            $this->contract('admin.business.catalog.products.update.v1', 'PATCH', '/admin/api/business/catalog/products/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.products.delete.v1', 'DELETE', '/admin/api/business/catalog/products/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.variants.index.v1', 'GET', '/admin/api/business/catalog/products/{id}/variants', 'business.catalog.read'),
            $this->contract('admin.business.catalog.variants.store.v1', 'POST', '/admin/api/business/catalog/products/{id}/variants', 'business.catalog.write'),
            $this->contract('admin.business.catalog.variants.show.v1', 'GET', '/admin/api/business/catalog/variants/{id}', 'business.catalog.read'),
            $this->contract('admin.business.catalog.variants.update.v1', 'PATCH', '/admin/api/business/catalog/variants/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.variants.delete.v1', 'DELETE', '/admin/api/business/catalog/variants/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.variants.stock.v1', 'GET', '/admin/api/business/catalog/variants/{id}/stock', 'business.catalog.read'),
            $this->contract('admin.business.catalog.stock_movements.store.v1', 'POST', '/admin/api/business/catalog/variants/{id}/stock-movements', 'business.catalog.stock.write'),
            $this->contract('admin.business.catalog.stock_movements.index.v1', 'GET', '/admin/api/business/catalog/stock-movements', 'business.catalog.read'),
            $this->contract('admin.business.catalog.options.index.v1', 'GET', '/admin/api/business/catalog/options', 'business.catalog.read'),
            $this->contract('admin.business.catalog.options.store.v1', 'POST', '/admin/api/business/catalog/options', 'business.catalog.write'),
            $this->contract('admin.business.catalog.options.update.v1', 'PATCH', '/admin/api/business/catalog/options/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.options.delete.v1', 'DELETE', '/admin/api/business/catalog/options/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.option_values.store.v1', 'POST', '/admin/api/business/catalog/options/{id}/values', 'business.catalog.write'),
            $this->contract('admin.business.catalog.option_values.update.v1', 'PATCH', '/admin/api/business/catalog/option-values/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.option_values.delete.v1', 'DELETE', '/admin/api/business/catalog/option-values/{id}', 'business.catalog.write'),
            $this->contract('admin.business.catalog.prices.index.v1', 'GET', '/admin/api/business/catalog/products/{id}/prices', 'business.catalog.prices.read'),
            $this->contract('admin.business.catalog.prices.update.v1', 'PUT', '/admin/api/business/catalog/products/{id}/base-prices', 'business.catalog.prices.write'),
            $this->contract('admin.business.catalog.variants.prices.v1', 'PUT', '/admin/api/business/catalog/variants/{id}/price-adjustments', 'business.catalog.prices.write'),
            $this->contract('admin.business.catalog.variants.computed_prices.v1', 'GET', '/admin/api/business/catalog/variants/{id}/computed-prices', 'business.catalog.prices.read'),
            $this->contract('admin.business.catalog.discounts.index.v1', 'GET', '/admin/api/business/catalog/discounts', 'business.catalog.read'),
            $this->contract('admin.business.catalog.discounts.store.v1', 'POST', '/admin/api/business/catalog/discounts', 'business.catalog.discounts.write'),
            $this->contract('admin.business.catalog.discounts.show.v1', 'GET', '/admin/api/business/catalog/discounts/{id}', 'business.catalog.read'),
            $this->contract('admin.business.catalog.discounts.update.v1', 'PATCH', '/admin/api/business/catalog/discounts/{id}', 'business.catalog.discounts.write'),
            $this->contract('admin.business.catalog.discounts.delete.v1', 'DELETE', '/admin/api/business/catalog/discounts/{id}', 'business.catalog.discounts.write'),
        ];
    }

    /** @return array<string,string> */
    private function contract(string $key, string $method, string $path, string $permission): array
    {
        return ['key' => $key, 'version' => '1', 'scope' => 'admin', 'method' => $method, 'path' => $path, 'permission' => $permission];
    }

    /** @return array{0:string,1:string,2:string} */
    private function route(string $method, string $path, string $handler): array
    {
        return [$method, $path, 'App\\Application\\Api\\Admin\\' . $handler];
    }

    /** @param list<array<string,mixed>> $fields @param array<string,string> $permissions @param array<string,mixed> $options */
    private function crmBlueprint(string $resource, string $label, string $description, string $table, array $fields, array $permissions, array $options = []): array
    {
        $aggregate = (bool) ($options['aggregate'] ?? false);
        return [
            'module' => 'business',
            'resource' => $resource,
            'blueprint_key' => 'business_' . $resource,
            'resource_type' => 'module_resource',
            'label' => $label,
            'description' => $description,
            'version' => 1,
            'storage' => [
                'database' => 'business',
                'table' => $table,
                'primary_key' => $aggregate ? 'relation_id' : 'id',
                'mode' => $aggregate ? 'aggregate_read_model' : 'external_table',
                'source_tables' => $options['sources'] ?? [$table],
            ],
            'capabilities' => [
                'admin' => true,
                'headless' => false,
                'public' => false,
                'localized' => false,
                'revisions' => false,
                'workflow' => false,
                'seo' => false,
                'export' => true,
            ],
            'permissions' => $permissions,
            'headless' => ['enabled' => false, 'public' => false],
            'admin' => [
                'route' => '/business',
                'component' => 'BusinessCrmView',
                'schema_driven' => false,
                'resource_key' => 'business.' . $resource,
                'dedicated_ux' => true,
                'generated_form' => false,
                'list' => (bool) ($options['list'] ?? true),
                'read' => true,
                'create' => (bool) ($options['create'] ?? !$aggregate),
                'update' => (bool) ($options['update'] ?? !$aggregate),
                'delete' => (bool) ($options['delete'] ?? false),
            ],
            'sections' => $this->sectionsFromFields($fields),
            'fields' => $fields,
            'relations' => $options['relations'] ?? [],
            'validation' => $options['validation'] ?? [],
            'export' => [
                'enabled' => true,
                'formats' => ['json', 'csv'],
                'exclude_sensitive' => true,
                'columns' => array_values(array_filter(array_map(
                    static fn(array $field): ?string => ($field['exportable'] ?? true) ? (string) $field['key'] : null,
                    $fields
                ))),
            ],
            'contract' => [
                'resource_key' => 'business.' . $resource,
                'admin_schema_endpoint' => '/admin/api/modules/business/resources/business_' . $resource . '/schema',
                'admin_blueprints_endpoint' => '/admin/api/modules/business/blueprints',
                'public_headless_routes' => [],
                'schema_discovery' => true,
                'ai_discovery' => true,
                'notes' => $options['protected_rows'] ?? null,
                'noindex_public_share' => (bool) ($options['noindex_public_share'] ?? false),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function relationFields(): array
    {
        return [
            $this->enumField('relation_type', 'Type', ['individual', 'company'], 'identity', true, 'relation_type', ['system' => true]),
            $this->field('relation_id', 'ID relation', 'number', 'identity', true, 'relation_id', ['system' => true]),
            $this->field('display_name', 'Nom affiché', 'text', 'identity', true, 'display_name'),
            $this->field('company_name', 'Entreprise', 'text', 'identity', false, 'company_name'),
            $this->field('email', 'E-mail', 'email', 'contact', false, 'email'),
            $this->field('phone', 'Téléphone', 'text', 'contact', false, 'phone'),
            $this->field('mobile', 'Mobile', 'text', 'contact', false, 'mobile'),
            $this->enumField('status', 'Statut', $this->crmStatuses(), 'identity', true, 'status'),
            $this->field('language', 'Langue', 'text', 'identity', false, 'language'),
            $this->field('city', 'Ville', 'text', 'address', false, 'city'),
            $this->field('country', 'Pays', 'text', 'address', false, 'country'),
            $this->field('memo_count', 'Mémos', 'number', 'activity', false, 'memo_count', ['computed' => true]),
            $this->field('shared_memo_count', 'Mémos partagés', 'number', 'activity', false, 'shared_memo_count', ['computed' => true]),
            $this->field('linked_contacts_count', 'Contacts liés', 'number', 'activity', false, 'linked_contacts_count', ['computed' => true]),
            $this->field('created_by', 'Créé par', 'relation', 'audit', false, 'created_by', ['system' => true]),
            $this->field('updated_by', 'Modifié par', 'relation', 'audit', false, 'updated_by', ['system' => true]),
            $this->field('created_at', 'Créé le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
            $this->field('updated_at', 'Modifié le', 'datetime', 'audit', false, 'updated_at', ['system' => true]),
            $this->field('archived_at', 'Archivé le', 'datetime', 'audit', false, 'archived_at', ['system' => true]),
        ];
    }

    /** @param list<string> $enum @param array<string,mixed> $options */
    private function enumField(string $key, string $label, array $enum, string $section, bool $required, string $column, array $options = []): array
    {
        return $this->field($key, $label, 'select', $section, $required, $column, $options + ['enum' => $enum]);
    }

    /** @param array<string,mixed> $options */
    private function field(string $key, string $label, string $type, string $section, bool $required, string $column, array $options = []): array
    {
        $system = (bool) ($options['system'] ?? false);
        return [
            'key' => $key,
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'type' => $type,
            'section' => $section,
            'tab_key' => $section,
            'is_required' => $required,
            'required' => $required,
            'nullable' => (bool) ($options['nullable'] ?? !$required),
            'column' => $column,
            'is_system' => $system,
            'is_deletable' => false,
            'field_scope' => $system ? 'system_context' : 'business_crm',
            'ui_visibility' => $system ? 'summary' : 'form',
            'validation' => array_filter([
                'required' => $required ?: null,
                'enum' => $options['enum'] ?? null,
                'storage_values' => $options['storage_values'] ?? null,
                'unique_active' => $options['unique_active'] ?? null,
            ], static fn(mixed $value): bool => $value !== null),
            'sensitive' => (bool) ($options['sensitive'] ?? false),
            'exportable' => (bool) ($options['exportable'] ?? true),
            'computed' => (bool) ($options['computed'] ?? false),
            'source_endpoint' => $options['source_endpoint'] ?? null,
        ];
    }

    /** @param list<array<string,mixed>> $fields @return list<array<string,mixed>> */
    private function sectionsFromFields(array $fields): array
    {
        $sections = [];
        foreach ($fields as $field) {
            $key = (string) ($field['section'] ?? 'main');
            $sections[$key] ??= ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'fields' => []];
            $sections[$key]['fields'][] = (string) $field['key'];
        }
        return array_values($sections);
    }

    /** @return list<string> */
    private function crmStatuses(): array
    {
        return ['prospect', 'client', 'supplier', 'former_client', 'other'];
    }

    /** @return list<string> */
    private function channels(): array
    {
        return ['email', 'whatsapp', 'telegram'];
    }
}
