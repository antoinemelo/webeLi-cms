<?php

declare(strict_types=1);

namespace App\Modules\Business;

use App\Module\ModuleProvider;

/**
 * Module systeme Business CRM.
 *
 * Le provider reste declaratif a ce stade : il publie la base business.sqlite,
 * les permissions, l'entree de navigation et les contrats prevus. Les
 * repositories, services, controleurs et l'interface seront ajoutes par les
 * prompts suivants.
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
    public function blueprints(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function contentTypes(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function adminNavigation(): array
    {
        return [[
            'key' => 'business.crm',
            'label' => 'Business / CRM',
            'navLabel' => 'Business',
            'route' => '/business/crm',
            'permission' => 'business.crm.read',
            'section' => 'Modules',
            'hint' => 'Entreprises, contacts, memos, consentements, mailing simple et outbox messaging.',
            'sort_order' => 140,
        ]];
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public function adminRoutes(): array { return []; }

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
            $this->contract('admin.business.companies.index.v1', 'GET', '/admin/api/business/companies', 'business.crm.read'),
            $this->contract('admin.business.companies.export_csv.v1', 'GET', '/admin/api/business/companies/export.csv', 'business.crm.manage'),
            $this->contract('admin.business.companies.store.v1', 'POST', '/admin/api/business/companies', 'business.crm.manage'),
            $this->contract('admin.business.companies.show.v1', 'GET', '/admin/api/business/companies/{id}', 'business.crm.read'),
            $this->contract('admin.business.companies.update.v1', 'PATCH', '/admin/api/business/companies/{id}', 'business.crm.manage'),
            $this->contract('admin.business.companies.archive.v1', 'POST', '/admin/api/business/companies/{id}/archive', 'business.crm.manage'),
            $this->contract('admin.business.contacts.index.v1', 'GET', '/admin/api/business/contacts', 'business.crm.read'),
            $this->contract('admin.business.contacts.export_csv.v1', 'GET', '/admin/api/business/contacts/export.csv', 'business.crm.manage'),
            $this->contract('admin.business.contacts.import_csv.v1', 'POST', '/admin/api/business/contacts/import.csv', 'business.crm.manage'),
            $this->contract('admin.business.contacts.store.v1', 'POST', '/admin/api/business/contacts', 'business.crm.manage'),
            $this->contract('admin.business.contacts.show.v1', 'GET', '/admin/api/business/contacts/{id}', 'business.crm.read'),
            $this->contract('admin.business.contacts.update.v1', 'PATCH', '/admin/api/business/contacts/{id}', 'business.crm.manage'),
            $this->contract('admin.business.contacts.archive.v1', 'POST', '/admin/api/business/contacts/{id}/archive', 'business.crm.manage'),
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
            $this->contract('admin.business.memo_comments.index.v1', 'GET', '/admin/api/business/memos/{id}/comments', 'business.memo.read'),
            $this->contract('admin.business.memo_comments.store.v1', 'POST', '/admin/api/business/memos/{id}/comments', 'business.memo.manage'),
            $this->contract('admin.business.memo_shares.index.v1', 'GET', '/admin/api/business/memos/{id}/shares', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.store.v1', 'POST', '/admin/api/business/memos/{id}/shares', 'business.memo.share'),
            $this->contract('admin.business.memo_shares.delete.v1', 'DELETE', '/admin/api/business/memos/{id}/shares/{shareId}', 'business.memo.share'),
            $this->contract('admin.business.memo_public_share.create.v1', 'POST', '/admin/api/business/memos/{id}/public-share', 'business.memo.share'),
            $this->contract('admin.business.memo_public_share.delete.v1', 'DELETE', '/admin/api/business/memos/{id}/public-share', 'business.memo.share'),
            $this->contract('admin.business.contacts.consents.index.v1', 'GET', '/admin/api/business/contacts/{id}/consents', 'business.crm.read'),
            $this->contract('admin.business.contacts.consents.update.v1', 'PATCH', '/admin/api/business/contacts/{id}/consents/{channel}', 'business.crm.manage'),
            $this->contract('admin.business.messaging.providers.v1', 'GET', '/admin/api/business/messaging/providers', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.outbox.v1', 'GET', '/admin/api/business/messaging/outbox', 'business.messaging.admin'),
            $this->contract('admin.business.messaging.send_test.v1', 'POST', '/admin/api/business/messaging/send-test', 'business.messaging.admin'),
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
        ];
    }

    /** @return array<string,string> */
    private function contract(string $key, string $method, string $path, string $permission): array
    {
        return ['key' => $key, 'version' => '1', 'scope' => 'admin', 'method' => $method, 'path' => $path, 'permission' => $permission];
    }
}
