<?php

declare(strict_types=1);

namespace App\Modules\Business\Services;

use App\Modules\Sale\Services\SaleDatabaseConnection;

/**
 * Cross-module read model for the Operations work queue.
 * Business and Sale remain canonical in their own databases; this service
 * never writes transactional state.
 */
final class BusinessOperationsDashboardService
{
    public function __construct(
        private readonly BusinessDatabaseConnection $business,
        private readonly SaleDatabaseConnection $sale,
    ) {}

    /** @param array<string,bool> $capabilities @return array{tasks:list<array<string,mixed>>,summary:array<string,int>,generated_at:string} */
    public function actionable(int $siteId, array $capabilities): array
    {
        $tasks = [];
        if (($capabilities['catalog'] ?? false) === true) {
            $tasks = array_merge($tasks, $this->catalogTasks($siteId), $this->offerTasks($siteId));
        }
        if (($capabilities['relations'] ?? false) === true) {
            $tasks = array_merge($tasks, $this->relationTasks($siteId));
        }
        if (($capabilities['inventory'] ?? false) === true) {
            $tasks = array_merge($tasks, $this->inventoryTasks($siteId, (bool) ($capabilities['advanced'] ?? false)));
        }
        usort($tasks, static fn(array $a, array $b): int => [$a['priority'], $a['key']] <=> [$b['priority'], $b['key']]);
        $summary = [];
        foreach ($tasks as $task) $summary[(string) $task['queue']] = ($summary[(string) $task['queue']] ?? 0) + (int) $task['count'];
        return ['tasks' => $tasks, 'summary' => $summary, 'generated_at' => gmdate('c')];
    }

    /** @return list<array<string,mixed>> */
    private function catalogTasks(int $siteId): array
    {
        $db = $this->business->database();
        if ($db === null) return [];
        $incomplete = (int) ($db->one(
            "SELECT COUNT(DISTINCT p.id) AS count FROM business_products p
             LEFT JOIN business_product_completeness_scores cs ON cs.product_id=p.id AND cs.variant_id IS NULL AND cs.channel='ecommerce'
             WHERE p.site_id=? AND p.archived_at IS NULL AND (p.status='draft' OR COALESCE(cs.is_sellable,0)=0 OR COALESCE(cs.score,0)<100)", [$siteId]
        )['count'] ?? 0);
        $variants = (int) ($db->one(
            "SELECT COUNT(DISTINCT v.id) AS count FROM business_product_variants v
             INNER JOIN business_products p ON p.id=v.product_id
             LEFT JOIN business_product_completeness_scores cs ON cs.variant_id=v.id AND cs.channel='ecommerce'
             WHERE p.site_id=? AND p.archived_at IS NULL AND v.archived_at IS NULL AND (COALESCE(cs.is_sellable,0)=0 OR COALESCE(cs.score,0)<100)", [$siteId]
        )['count'] ?? 0);
        $tasks = [];
        if ($incomplete > 0) $tasks[] = $this->task('catalog.incomplete', 'products', 20, $incomplete, 'Produits incomplets ou non publiables', 'Compléter le contenu, le prix, le média ou les canaux requis.', '/business/products-stock?view=to_complete');
        if ($variants > 0) $tasks[] = $this->task('catalog.variants', 'products', 25, $variants, 'Variantes à compléter', 'Vérifier prix, média et disponibilité avant publication Shop.', '/business/products-stock?view=to_complete');
        return $tasks;
    }

    /** @return list<array<string,mixed>> */
    private function offerTasks(int $siteId): array
    {
        $db = $this->business->database();
        if ($db === null) return [];
        $draft = (int) ($db->one("SELECT COUNT(*) AS count FROM business_catalog_discounts WHERE site_id=? AND status='draft' AND archived_at IS NULL", [$siteId])['count'] ?? 0);
        $upcoming = (int) ($db->one("SELECT COUNT(*) AS count FROM business_catalog_discounts WHERE site_id=? AND status='active' AND archived_at IS NULL AND starts_at>CURRENT_TIMESTAMP AND starts_at<=datetime('now','+7 days')", [$siteId])['count'] ?? 0);
        $conflicts = (int) ($db->one(
            "SELECT COUNT(DISTINCT a.id) AS count FROM business_catalog_discounts a
             INNER JOIN business_catalog_discounts b ON b.site_id=a.site_id AND b.id>a.id AND b.scope_type=a.scope_type AND b.scope_id=a.scope_id
              AND (a.channel='all' OR b.channel='all' OR a.channel=b.channel)
              AND COALESCE(a.ends_at,'9999-12-31')>=COALESCE(b.starts_at,'0000-01-01')
              AND COALESCE(b.ends_at,'9999-12-31')>=COALESCE(a.starts_at,'0000-01-01')
             WHERE a.site_id=? AND a.status='active' AND b.status='active' AND a.archived_at IS NULL AND b.archived_at IS NULL", [$siteId]
        )['count'] ?? 0);
        $tasks = [];
        if ($conflicts > 0) $tasks[] = $this->task('offers.conflicts', 'offers', 10, $conflicts, 'Offres potentiellement en conflit', 'Comparer la portée, la période, le canal et la priorité.', '/business/offers-marketing?filter=conflict');
        if ($draft > 0) $tasks[] = $this->task('offers.draft', 'offers', 35, $draft, 'Offres à valider', 'Prévisualiser les objets concernés avant activation.', '/business/offers-marketing?status=draft');
        if ($upcoming > 0) $tasks[] = $this->task('offers.upcoming', 'offers', 40, $upcoming, 'Offres bientôt actives', 'Contrôler la période et le cumul avant démarrage.', '/business/offers-marketing?filter=upcoming');
        return $tasks;
    }

    /** @return list<array<string,mixed>> */
    private function relationTasks(int $siteId): array
    {
        $db = $this->business->database();
        if ($db === null) return [];
        $pending = (int) ($db->one("SELECT COUNT(*) AS count FROM crm_form_submission_activities WHERE site_id=? AND resolution_strategy='pending'", [$siteId])['count'] ?? 0);
        return $pending > 0 ? [$this->task('relations.forms', 'relations', 15, $pending, 'Formulaires à rattacher', 'Décider à quelle Relation rattacher chaque soumission ambiguë.', '/business/relations/advanced/profiles?panel=form-links')] : [];
    }

    /** @return list<array<string,mixed>> */
    private function inventoryTasks(int $siteId, bool $advanced): array
    {
        $db = $this->sale->database();
        if ($db === null) return [];
        $low = (int) ($db->one('SELECT COUNT(*) AS count FROM sale_inventory_items WHERE site_id=? AND tracked=1 AND available_quantity>=0 AND available_quantity<=low_stock_threshold', [$siteId])['count'] ?? 0);
        $blocked = (int) ($db->one("SELECT COUNT(*) AS count FROM sale_stock_backorders b INNER JOIN sale_inventory_items i ON i.id=b.inventory_item_id WHERE i.site_id=? AND b.status IN ('active','confirmed')", [$siteId])['count'] ?? 0);
        $tasks = [];
        if ($blocked > 0) $tasks[] = $this->task('inventory.blocked', 'inventory', 5, $blocked, 'Commandes en attente de stock', 'Une réception peut débloquer une réservation ou une commande.', '/business/products-stock?view=low_stock');
        if ($low > 0) $tasks[] = $this->task('inventory.low', 'inventory', 12, $low, 'Stock faible ou épuisé', 'Compter, réceptionner ou corriger depuis la variante concernée.', '/business/products-stock?view=low_stock');
        if ($advanced) {
            $inconsistent = (int) ($db->one(
                "SELECT COUNT(*) AS count FROM sale_inventory_items i WHERE i.site_id=? AND
                 (i.on_hand_quantity<>(SELECT COALESCE(SUM(m.quantity),0) FROM sale_stock_movements m WHERE m.inventory_item_id=i.id AND m.movement_type NOT IN ('reservation','release'))
                  OR i.reserved_quantity<>(SELECT COALESCE(SUM(r.quantity),0) FROM sale_stock_reservations r WHERE r.inventory_item_id=i.id AND r.status IN ('active','confirmed')))", [$siteId]
            )['count'] ?? 0);
            if ($inconsistent > 0) $tasks[] = $this->task('inventory.inconsistent', 'advanced', 1, $inconsistent, 'Écarts de ledger à diagnostiquer', 'Réconcilier la projection sans réécrire les mouvements existants.', '/sale/advanced/stock?alert=inconsistent', true);
        }
        return $tasks;
    }

    /** @return array<string,mixed> */
    private function task(string $key, string $queue, int $priority, int $count, string $label, string $explanation, string $route, bool $advanced = false): array
    {
        return compact('key', 'queue', 'priority', 'count', 'label', 'explanation', 'route', 'advanced');
    }
}
