<?php

declare(strict_types=1);

namespace App\Modules\Sale;

use App\Module\ModuleProvider;

/**
 * Module systeme Vente.
 *
 * Vente porte les paniers, commandes, paiements, POS, stock transactionnel,
 * recus, retours, remboursements et evenements dans sale.sqlite. Le catalogue
 * et le CRM restent fournis par Operations via des identifiants et snapshots.
 */
final class SaleModuleProvider implements ModuleProvider
{
    public function key(): string { return 'sale'; }

    public function name(): string { return 'Vente'; }

    public function version(): string { return '0.1.0'; }

    public function description(): string
    {
        return 'Vente, commandes, paiements, POS et stock transactionnel dans sale.sqlite.';
    }

    /** @return list<string> */
    public function dependencies(): array { return ['business']; }

    /** @return list<array<string,mixed>> */
    public function databases(): array
    {
        return [[
            'key' => 'sale',
            'driver' => 'sqlite',
            'path' => 'storage/database/sale.sqlite',
            'schema' => 'database/modules/sale.sql',
            'required' => true,
        ]];
    }

    /** @return list<array<string,string>> */
    public function permissions(): array
    {
        return [
            ['key' => 'sale.read', 'name' => 'Lire Vente', 'description' => 'Consulter le tableau de bord Vente et les donnees commerciales autorisees.'],
            ['key' => 'sale.manage', 'name' => 'Gerer Vente', 'description' => 'Administrer les donnees et reglages generaux du module Vente.'],
            ['key' => 'sale.orders.read', 'name' => 'Lire les commandes', 'description' => 'Consulter les paniers convertis, commandes, lignes et statuts.'],
            ['key' => 'sale.orders.manage', 'name' => 'Gerer les commandes', 'description' => 'Creer, confirmer, completer ou annuler des commandes.'],
            ['key' => 'sale.payments.read', 'name' => 'Lire les paiements', 'description' => 'Consulter moyens de paiement, intentions, transactions et allocations.'],
            ['key' => 'sale.payments.manage', 'name' => 'Gerer les paiements', 'description' => 'Enregistrer, capturer, annuler ou rapprocher des paiements.'],
            ['key' => 'sale.refunds.manage', 'name' => 'Gerer les remboursements', 'description' => 'Creer et suivre les remboursements sans modifier la transaction originale.'],
            ['key' => 'sale.pos.use', 'name' => 'Utiliser le POS', 'description' => 'Utiliser une caisse et finaliser une vente POS.'],
            ['key' => 'sale.pos.manage', 'name' => 'Gerer le POS', 'description' => 'Configurer registres, terminaux et appareils de caisse.'],
            ['key' => 'sale.cash.manage', 'name' => 'Gerer la caisse', 'description' => 'Ouvrir, fermer et ajuster les sessions de caisse.'],
            ['key' => 'sale.stock.read', 'name' => 'Lire le stock Vente', 'description' => 'Consulter les stocks transactionnels, disponibilites et reservations Vente.'],
            ['key' => 'sale.stock.manage', 'name' => 'Gerer le stock Vente', 'description' => 'Creer reservations, mouvements et corrections de stock transactionnel.'],
            ['key' => 'sale.reports.read', 'name' => 'Lire les rapports Vente', 'description' => 'Consulter les rapports commerciaux et POS.'],
            ['key' => 'sale.settings.manage', 'name' => 'Gerer les reglages Vente', 'description' => 'Configurer canaux, moyens de paiement et reglages du module Vente.'],
        ];
    }

    /** @return array<string,mixed> */
    public function settingsSchema(): array
    {
        return [
            'schema_version' => 1,
            'database' => ['key' => 'sale', 'path' => 'storage/database/sale.sqlite'],
            'dependencies' => ['business'],
            'defaults' => [
                'currency' => 'CHF',
                'default_language' => 'fr',
                'public_ecommerce_enabled' => false,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function blueprints(): array
    {
        return [
            $this->blueprint('channel', 'Canal de vente', 'Canal admin, POS ou ecommerce. Aucun canal public actif par defaut.', 'sale_channels', [
                $this->field('code', 'Code', 'text', 'identity', true, 'code'),
                $this->field('name', 'Nom', 'text', 'identity', true, 'name'),
                $this->enumField('channel_type', 'Type', ['ecommerce', 'pos', 'admin'], 'identity', true, 'channel_type'),
                $this->enumField('status', 'Statut', ['draft', 'active', 'archived'], 'identity', true, 'status'),
                $this->field('currency', 'Devise', 'text', 'money', true, 'currency'),
                $this->field('is_public', 'Public', 'boolean', 'visibility', false, 'is_public'),
            ], ['read' => 'sale.read', 'create' => 'sale.settings.manage', 'update' => 'sale.settings.manage', 'archive' => 'sale.settings.manage']),
            $this->blueprint('cart', 'Panier', 'Panier temporaire et modifiable avant conversion en commande.', 'sale_carts', [
                $this->enumField('status', 'Statut', ['draft', 'active', 'abandoned', 'converted', 'expired', 'cancelled'], 'identity', true, 'status'),
                $this->field('channel_id', 'Canal', 'relation', 'identity', true, 'channel_id'),
                $this->field('customer_snapshot_json', 'Snapshot client', 'json', 'customer', false, 'customer_snapshot_json'),
                $this->field('grand_total_minor', 'Total', 'money_minor', 'totals', true, 'grand_total_minor'),
            ], ['read' => 'sale.orders.read', 'update' => 'sale.orders.manage']),
            $this->blueprint('order', 'Commande', 'Commande validee comme snapshot transactionnel stable.', 'sale_orders', [
                $this->field('order_number', 'Numero', 'text', 'identity', true, 'order_number'),
                $this->enumField('source', 'Source', ['ecommerce', 'pos', 'admin'], 'identity', true, 'source'),
                $this->enumField('status', 'Statut', ['draft', 'placed', 'confirmed', 'completed', 'cancelled'], 'identity', true, 'status'),
                $this->enumField('payment_status', 'Paiement', ['unpaid', 'pending', 'authorized', 'partially_paid', 'paid', 'partially_refunded', 'refunded', 'failed'], 'payment', true, 'payment_status'),
                $this->field('grand_total_minor', 'Total', 'money_minor', 'totals', true, 'grand_total_minor'),
                $this->field('metadata_json', 'Metadonnees', 'json', 'audit', false, 'metadata_json'),
            ], ['read' => 'sale.orders.read', 'create' => 'sale.orders.manage', 'update' => 'sale.orders.manage', 'cancel' => 'sale.orders.manage']),
            $this->blueprint('payment', 'Paiement', 'Intentions, transactions et allocations de paiement separees de la commande.', 'sale_payment_transactions', [
                $this->enumField('transaction_type', 'Type', ['authorization', 'capture', 'payment', 'refund', 'void'], 'identity', true, 'transaction_type'),
                $this->enumField('status', 'Statut', ['pending', 'succeeded', 'failed', 'cancelled'], 'identity', true, 'status'),
                $this->field('amount_minor', 'Montant', 'money_minor', 'money', true, 'amount_minor'),
                $this->field('provider_payload_json', 'Payload provider', 'json', 'provider', false, 'provider_payload_json', ['sensitive' => true]),
            ], ['read' => 'sale.payments.read', 'create' => 'sale.payments.manage', 'update' => 'sale.payments.manage']),
            $this->blueprint('pos_register', 'Caisse POS', 'Registre POS lie a un canal Vente.', 'sale_pos_registers', [
                $this->field('code', 'Code', 'text', 'identity', true, 'code'),
                $this->field('name', 'Nom', 'text', 'identity', true, 'name'),
                $this->enumField('status', 'Statut', ['active', 'disabled', 'archived'], 'identity', true, 'status'),
                $this->field('location_name', 'Lieu', 'text', 'identity', false, 'location_name'),
            ], ['read' => 'sale.pos.use', 'create' => 'sale.pos.manage', 'update' => 'sale.pos.manage']),
            $this->blueprint('stock_location', 'Emplacement stock', 'Emplacement de stock transactionnel Vente.', 'sale_stock_locations', [
                $this->field('code', 'Code', 'text', 'identity', true, 'code'),
                $this->field('name', 'Nom', 'text', 'identity', true, 'name'),
                $this->enumField('location_type', 'Type', ['main', 'pos', 'event', 'external'], 'identity', true, 'location_type'),
                $this->enumField('status', 'Statut', ['active', 'disabled', 'archived'], 'identity', true, 'status'),
            ], ['read' => 'sale.stock.read', 'create' => 'sale.stock.manage', 'update' => 'sale.stock.manage']),
            $this->blueprint('stock_movement', 'Mouvement stock', 'Journal immuable des changements de stock Vente.', 'sale_stock_movements', [
                $this->enumField('movement_type', 'Type', ['initial', 'adjustment', 'reservation', 'release', 'sale', 'return', 'refund', 'correction'], 'identity', true, 'movement_type'),
                $this->field('quantity', 'Quantite', 'number', 'quantity', true, 'quantity'),
                $this->field('reference_type', 'Reference', 'text', 'reference', false, 'reference_type'),
                $this->field('created_at', 'Cree le', 'datetime', 'audit', false, 'created_at', ['system' => true]),
            ], ['read' => 'sale.stock.read', 'create' => 'sale.stock.manage']),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function contentTypes(): array { return []; }

    /** @return list<array<string,mixed>> */
    public function adminNavigation(): array
    {
        return [[
            'key' => 'sale',
            'label' => 'Vente',
            'navLabel' => 'Vente',
            'route' => '/sale',
            'anyPermission' => [
                'sale.read',
                'sale.orders.read',
                'sale.pos.use',
                'sale.payments.read',
                'sale.stock.read',
                'sale.reports.read',
            ],
            'section' => 'Modules',
            'hint' => 'Commandes, POS, paiements et stock transactionnel.',
            'sort_order' => 150,
            'children' => [
                ['key' => 'sale.dashboard', 'label' => 'Tableau de bord', 'route' => '/sale', 'permission' => 'sale.read'],
                ['key' => 'sale.orders', 'label' => 'Commandes', 'route' => '/sale/orders', 'permission' => 'sale.orders.read'],
                ['key' => 'sale.pos', 'label' => 'POS', 'route' => '/sale/pos', 'permission' => 'sale.pos.use'],
                ['key' => 'sale.settings', 'label' => 'Reglages', 'route' => '/sale/settings', 'permission' => 'sale.settings.manage'],
            ],
        ]];
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public function adminRoutes(): array
    {
        $c = 'App\\Application\\Api\\Admin\\SaleAdminApiController@';
        return [
            $this->route('GET', '/admin/api/sale/schema', $c . 'schema'),
            $this->route('GET', '/admin/api/sale/dashboard', $c . 'dashboard'),
            $this->route('GET', '/admin/api/sale/channels', $c . 'channels'),
            $this->route('POST', '/admin/api/sale/channels', $c . 'storeChannel'),
            $this->route('GET', '/admin/api/sale/channels/{id}', $c . 'showChannel'),
            $this->route('PATCH', '/admin/api/sale/channels/{id}', $c . 'updateChannel'),
            $this->route('POST', '/admin/api/sale/channels/{id}/archive', $c . 'archiveChannel'),
            $this->route('GET', '/admin/api/sale/carts', $c . 'carts'),
            $this->route('POST', '/admin/api/sale/carts', $c . 'storeCart'),
            $this->route('GET', '/admin/api/sale/carts/{id}', $c . 'showCart'),
            $this->route('POST', '/admin/api/sale/carts/{id}/lines', $c . 'addCartLine'),
            $this->route('PATCH', '/admin/api/sale/carts/{id}/lines/{line_id}', $c . 'updateCartLine'),
            $this->route('DELETE', '/admin/api/sale/carts/{id}/lines/{line_id}', $c . 'deleteCartLine'),
            $this->route('POST', '/admin/api/sale/carts/{id}/recalculate', $c . 'recalculateCart'),
            $this->route('POST', '/admin/api/sale/carts/{id}/checkout', $c . 'checkoutCart'),
            $this->route('GET', '/admin/api/sale/orders', $c . 'orders'),
            $this->route('POST', '/admin/api/sale/orders', $c . 'storeOrder'),
            $this->route('GET', '/admin/api/sale/orders/{id}', $c . 'showOrder'),
            $this->route('PATCH', '/admin/api/sale/orders/{id}', $c . 'updateOrder'),
            $this->route('POST', '/admin/api/sale/orders/{id}/cancel', $c . 'cancelOrder'),
            $this->route('GET', '/admin/api/sale/orders/{id}/events', $c . 'orderEvents'),
            $this->route('GET', '/admin/api/sale/orders/{id}/receipt', $c . 'orderReceipt'),
            $this->route('GET', '/admin/api/sale/payments', $c . 'payments'),
            $this->route('GET', '/admin/api/sale/payment-methods', $c . 'paymentMethods'),
            $this->route('POST', '/admin/api/sale/payment-methods', $c . 'storePaymentMethod'),
            $this->route('POST', '/admin/api/sale/orders/{id}/payments', $c . 'storeOrderPayment'),
            $this->route('GET', '/admin/api/sale/orders/{id}/payments', $c . 'orderPayments'),
            $this->route('POST', '/admin/api/sale/payments/{transaction_id}/refund', $c . 'refundPayment'),
            $this->route('GET', '/admin/api/sale/pos/bootstrap', $c . 'posBootstrap'),
            $this->route('GET', '/admin/api/sale/pos/catalog', $c . 'posCatalog'),
            $this->route('GET', '/admin/api/sale/pos/variants', $c . 'posVariants'),
            $this->route('GET', '/admin/api/sale/pos/registers', $c . 'posRegisters'),
            $this->route('POST', '/admin/api/sale/pos/sessions/open', $c . 'openCashSession'),
            $this->route('POST', '/admin/api/sale/pos/sessions/{id}/close', $c . 'closeCashSession'),
            $this->route('POST', '/admin/api/sale/pos/carts', $c . 'posStoreCart'),
            $this->route('POST', '/admin/api/sale/pos/carts/{id}/lines', $c . 'posAddCartLine'),
            $this->route('PATCH', '/admin/api/sale/pos/carts/{id}/lines/{line_id}', $c . 'posUpdateCartLine'),
            $this->route('POST', '/admin/api/sale/pos/checkout', $c . 'posCheckout'),
            $this->route('GET', '/admin/api/sale/pos/orders/{id}/receipt', $c . 'posOrderReceipt'),
            $this->route('GET', '/admin/api/sale/stock', $c . 'stock'),
            $this->route('GET', '/admin/api/sale/stock/items', $c . 'stockItems'),
            $this->route('POST', '/admin/api/sale/stock/adjustments', $c . 'stockAdjustments'),
            $this->route('GET', '/admin/api/sale/stock/movements', $c . 'stockMovements'),
            $this->route('GET', '/admin/api/sale/returns', $c . 'returns'),
            $this->route('GET', '/admin/api/sale/reports/daily', $c . 'dailyReport'),
            $this->route('GET', '/admin/api/sale/reports/orders', $c . 'ordersReport'),
            $this->route('GET', '/admin/api/sale/reports/pos-sessions', $c . 'posSessionsReport'),
            $this->route('GET', '/admin/api/sale/settings', $c . 'settings'),
        ];
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public function apiRoutes(): array { return []; }

    /** @return list<array{0:string,1:string,2:string}> */
    public function publicHeadlessRoutes(): array
    {
        $c = 'App\\Application\\Api\\PublicHeadlessController@';
        return [
            $this->route('GET', '/api/v1/sale/channels/{code}/bootstrap', $c . 'saleChannelBootstrap'),
            $this->route('POST', '/api/v1/sale/channels/{code}/cart', $c . 'saleCartStore'),
            $this->route('GET', '/api/v1/sale/channels/{code}/cart/{token}', $c . 'saleCartShow'),
            $this->route('POST', '/api/v1/sale/channels/{code}/cart/{token}/lines', $c . 'saleCartLineStore'),
            $this->route('PATCH', '/api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}', $c . 'saleCartLineUpdate'),
            $this->route('DELETE', '/api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}', $c . 'saleCartLineDelete'),
            $this->route('POST', '/api/v1/sale/channels/{code}/checkout', $c . 'saleCheckout'),
        ];
    }

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
            $this->contract('admin.sale.schema.v1', 'GET', '/admin/api/sale/schema', 'sale.read'),
            $this->contract('admin.sale.dashboard.v1', 'GET', '/admin/api/sale/dashboard', 'sale.read'),
            $this->contract('admin.sale.channels.index.v1', 'GET', '/admin/api/sale/channels', 'sale.settings.manage'),
            $this->contract('admin.sale.channels.store.v1', 'POST', '/admin/api/sale/channels', 'sale.settings.manage'),
            $this->contract('admin.sale.channels.show.v1', 'GET', '/admin/api/sale/channels/{id}', 'sale.settings.manage'),
            $this->contract('admin.sale.channels.update.v1', 'PATCH', '/admin/api/sale/channels/{id}', 'sale.settings.manage'),
            $this->contract('admin.sale.channels.archive.v1', 'POST', '/admin/api/sale/channels/{id}/archive', 'sale.settings.manage'),
            $this->contract('admin.sale.carts.index.v1', 'GET', '/admin/api/sale/carts', 'sale.orders.read'),
            $this->contract('admin.sale.carts.store.v1', 'POST', '/admin/api/sale/carts', 'sale.orders.manage'),
            $this->contract('admin.sale.carts.show.v1', 'GET', '/admin/api/sale/carts/{id}', 'sale.orders.read'),
            $this->contract('admin.sale.carts.lines.store.v1', 'POST', '/admin/api/sale/carts/{id}/lines', 'sale.orders.manage'),
            $this->contract('admin.sale.carts.lines.update.v1', 'PATCH', '/admin/api/sale/carts/{id}/lines/{line_id}', 'sale.orders.manage'),
            $this->contract('admin.sale.carts.lines.delete.v1', 'DELETE', '/admin/api/sale/carts/{id}/lines/{line_id}', 'sale.orders.manage'),
            $this->contract('admin.sale.carts.recalculate.v1', 'POST', '/admin/api/sale/carts/{id}/recalculate', 'sale.orders.manage'),
            $this->contract('admin.sale.carts.checkout.v1', 'POST', '/admin/api/sale/carts/{id}/checkout', 'sale.orders.manage'),
            $this->contract('admin.sale.orders.index.v1', 'GET', '/admin/api/sale/orders', 'sale.orders.read'),
            $this->contract('admin.sale.orders.store.v1', 'POST', '/admin/api/sale/orders', 'sale.orders.manage'),
            $this->contract('admin.sale.orders.show.v1', 'GET', '/admin/api/sale/orders/{id}', 'sale.orders.read'),
            $this->contract('admin.sale.orders.update.v1', 'PATCH', '/admin/api/sale/orders/{id}', 'sale.orders.manage'),
            $this->contract('admin.sale.orders.cancel.v1', 'POST', '/admin/api/sale/orders/{id}/cancel', 'sale.orders.manage'),
            $this->contract('admin.sale.orders.events.v1', 'GET', '/admin/api/sale/orders/{id}/events', 'sale.orders.read'),
            $this->contract('admin.sale.orders.receipt.v1', 'GET', '/admin/api/sale/orders/{id}/receipt', 'sale.orders.read'),
            $this->contract('admin.sale.payments.index.v1', 'GET', '/admin/api/sale/payments', 'sale.payments.read'),
            $this->contract('admin.sale.payment_methods.index.v1', 'GET', '/admin/api/sale/payment-methods', 'sale.payments.read'),
            $this->contract('admin.sale.payment_methods.store.v1', 'POST', '/admin/api/sale/payment-methods', 'sale.payments.manage'),
            $this->contract('admin.sale.orders.payments.store.v1', 'POST', '/admin/api/sale/orders/{id}/payments', 'sale.payments.manage'),
            $this->contract('admin.sale.orders.payments.index.v1', 'GET', '/admin/api/sale/orders/{id}/payments', 'sale.payments.read'),
            $this->contract('admin.sale.payments.refund.v1', 'POST', '/admin/api/sale/payments/{transaction_id}/refund', 'sale.refunds.manage'),
            $this->contract('admin.sale.pos.bootstrap.v1', 'GET', '/admin/api/sale/pos/bootstrap', 'sale.pos.use'),
            $this->contract('admin.sale.pos.catalog.v1', 'GET', '/admin/api/sale/pos/catalog', 'sale.pos.use'),
            $this->contract('admin.sale.pos.variants.v1', 'GET', '/admin/api/sale/pos/variants', 'sale.pos.use'),
            $this->contract('admin.sale.pos.registers.v1', 'GET', '/admin/api/sale/pos/registers', 'sale.pos.use'),
            $this->contract('admin.sale.pos.sessions.open.v1', 'POST', '/admin/api/sale/pos/sessions/open', 'sale.cash.manage'),
            $this->contract('admin.sale.pos.sessions.close.v1', 'POST', '/admin/api/sale/pos/sessions/{id}/close', 'sale.cash.manage'),
            $this->contract('admin.sale.pos.carts.store.v1', 'POST', '/admin/api/sale/pos/carts', 'sale.pos.use'),
            $this->contract('admin.sale.pos.carts.lines.store.v1', 'POST', '/admin/api/sale/pos/carts/{id}/lines', 'sale.pos.use'),
            $this->contract('admin.sale.pos.carts.lines.update.v1', 'PATCH', '/admin/api/sale/pos/carts/{id}/lines/{line_id}', 'sale.pos.use'),
            $this->contract('admin.sale.pos.checkout.v1', 'POST', '/admin/api/sale/pos/checkout', 'sale.pos.use'),
            $this->contract('admin.sale.pos.receipt.v1', 'GET', '/admin/api/sale/pos/orders/{id}/receipt', 'sale.pos.use'),
            $this->contract('admin.sale.stock.index.v1', 'GET', '/admin/api/sale/stock', 'sale.stock.read'),
            $this->contract('admin.sale.stock.items.v1', 'GET', '/admin/api/sale/stock/items', 'sale.stock.read'),
            $this->contract('admin.sale.stock.adjustments.v1', 'POST', '/admin/api/sale/stock/adjustments', 'sale.stock.manage'),
            $this->contract('admin.sale.stock.movements.v1', 'GET', '/admin/api/sale/stock/movements', 'sale.stock.read'),
            $this->contract('admin.sale.returns.index.v1', 'GET', '/admin/api/sale/returns', 'sale.orders.read'),
            $this->contract('admin.sale.reports.daily.v1', 'GET', '/admin/api/sale/reports/daily', 'sale.reports.read'),
            $this->contract('admin.sale.reports.orders.v1', 'GET', '/admin/api/sale/reports/orders', 'sale.reports.read'),
            $this->contract('admin.sale.reports.pos_sessions.v1', 'GET', '/admin/api/sale/reports/pos-sessions', 'sale.reports.read'),
            $this->contract('admin.sale.settings.v1', 'GET', '/admin/api/sale/settings', 'sale.settings.manage'),
            $this->contract('public.sale.channels.bootstrap.v1', 'GET', '/api/v1/sale/channels/{code}/bootstrap', 'anonymous', 'headless'),
            $this->contract('public.sale.cart.store.v1', 'POST', '/api/v1/sale/channels/{code}/cart', 'anonymous', 'headless'),
            $this->contract('public.sale.cart.show.v1', 'GET', '/api/v1/sale/channels/{code}/cart/{token}', 'anonymous', 'headless'),
            $this->contract('public.sale.cart.lines.store.v1', 'POST', '/api/v1/sale/channels/{code}/cart/{token}/lines', 'anonymous', 'headless'),
            $this->contract('public.sale.cart.lines.update.v1', 'PATCH', '/api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}', 'anonymous', 'headless'),
            $this->contract('public.sale.cart.lines.delete.v1', 'DELETE', '/api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}', 'anonymous', 'headless'),
            $this->contract('public.sale.checkout.v1', 'POST', '/api/v1/sale/channels/{code}/checkout', 'anonymous', 'headless'),
        ];
    }

    /** @return array{0:string,1:string,2:string} */
    private function route(string $method, string $path, string $handler): array
    {
        return [$method, $path, $handler];
    }

    /** @return array<string,mixed> */
    private function contract(string $key, string $method, string $path, string $permission, string $scope = 'admin'): array
    {
        return [
            'key' => $key,
            'version' => '1',
            'scope' => $scope,
            'method' => $method,
            'path' => $path,
            'permission' => $permission,
            'module' => 'sale',
        ];
    }

    /** @param list<array<string,mixed>> $fields @param array<string,string> $permissions */
    private function blueprint(string $resource, string $label, string $description, string $table, array $fields, array $permissions): array
    {
        return [
            'module' => 'sale',
            'resource' => $resource,
            'blueprint_key' => 'sale_' . $resource,
            'resource_type' => 'module_resource',
            'label' => $label,
            'description' => $description,
            'version' => 1,
            'storage' => ['database' => 'sale', 'table' => $table, 'primary_key' => 'id', 'mode' => 'external_table'],
            'capabilities' => ['admin' => true, 'headless' => false, 'public' => false, 'localized' => false, 'revisions' => false, 'workflow' => true, 'seo' => false, 'export' => true],
            'permissions' => $permissions,
            'headless' => ['enabled' => false, 'public' => false],
            'admin' => ['route' => '/sale', 'component' => 'SaleView', 'schema_driven' => false, 'title' => 'Vente'],
            'sections' => $this->sectionsFromFields($fields),
            'fields' => $fields,
            'export' => ['enabled' => true, 'formats' => ['json', 'csv']],
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
            'field_scope' => $system ? 'system_context' : 'sale',
            'ui_visibility' => $system ? 'summary' : 'form',
            'validation' => array_filter([
                'required' => $required ?: null,
                'enum' => $options['enum'] ?? null,
            ], static fn(mixed $value): bool => $value !== null),
            'sensitive' => (bool) ($options['sensitive'] ?? false),
            'exportable' => (bool) ($options['exportable'] ?? true),
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
}
