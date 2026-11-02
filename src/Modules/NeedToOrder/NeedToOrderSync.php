<?php

declare(strict_types=1);

namespace PII\Modules\NeedToOrder;

use PII\Services\Sync\SyncContext;

/**
 * NeedToOrderSync — builds the consolidated buy list each sync.
 *
 * Consumes the Order Readiness job's hand-off (same run, via
 * SyncContext::share): ticket material shortages, sales-order coverage
 * shortfalls, the leftover material pool, net ticket output and standard
 * batch sizes. Adds from CMS: recipe explosion (level by level), purchase
 * history (buy vs make + primary vendor), open-PO quantities, and item
 * names/units. The pure math lives in NeedToOrderEngine.
 */
class NeedToOrderSync
{
    public function steps(): array
    {
        return [
            ['name' => 'Building the buy list', 'run' => fn(SyncContext $c) => $this->build($c)],
        ];
    }

    private function build(SyncContext $c): array
    {
        $demands = array_merge(
            $c->shared('or.ticket_demands', []),
            $c->shared('or.order_demands', [])
        );
        $stock      = $c->shared('or.leftover_materials', []);
        $incoming   = $c->shared('or.ticket_supply', []);
        $batchSizes = $c->shared('or.batch_sizes', []);

        $c->stage('nto_items', 'CREATE TABLE {table} LIKE nto_items');
        if ($demands === []) {
            $c->queueSwap('nto_items', 0);
            return ['rows' => 0];
        }

        // Walk the recipe tree level by level: anything with an ACTIVE
        // recipe is made, never bought (Matt, 2026-11-02), so every recipe
        // found descends into its ingredients.
        $recipes = [];     // item => [['item','qty_per'], …]
        $known   = [];     // item => true (already looked up)
        $frontier = array_values(array_unique(array_column($demands, 'item')));

        for ($depth = 0; $frontier !== [] && $depth < 6; $depth++) {
            $frontier = array_values(array_diff($frontier, array_keys($known)));
            if ($frontier === []) {
                break;
            }
            foreach ($frontier as $code) {
                $known[$code] = true;
            }
            $in = implode(',', array_fill(0, count($frontier), '?'));

            $next = [];
            foreach ($c->cmsFetchAll('recipes (level ' . ($depth + 1) . ')', "
                SELECT i.ItemCode AS Parent, ing.ItemCode AS Ing, rd.QtyReqd
                FROM CMS.dbo.Item i
                JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = i.CostingRecipe AND rd.Context = 'UI'
                JOIN CMS.dbo.Item ing ON ing.Item = rd.Item
                WHERE i.ItemCode IN ({$in})", $frontier) as $r) {
                $recipes[$r['Parent']][] = ['item' => (string) $r['Ing'], 'qty_per' => (float) $r['QtyReqd']];
                $next[] = (string) $r['Ing'];
            }
            $frontier = array_values(array_unique($next));
        }

        $needs = NeedToOrderEngine::compute($demands, $stock, $incoming, $recipes, $batchSizes);
        if ($needs === []) {
            $c->queueSwap('nto_items', 0);
            return ['rows' => 0];
        }

        // Last-purchased vendor (+ their code) for the final buy list.
        $codes = array_keys($needs);
        $in = implode(',', array_fill(0, count($codes), '?'));
        $lastVendor = [];
        foreach ($c->cmsFetchAll('last-purchased vendors', "
            SELECT pod.ItemCode, e.EntityName AS Vendor, pod.TheirCode,
                   ROW_NUMBER() OVER (PARTITION BY pod.ItemCode ORDER BY o.DateOrdered DESC, o.Ordr DESC) AS rn
            FROM CMS.dbo.PurchaseOrderDetail pod
            JOIN CMS.dbo.Ordr o ON o.Ordr = pod.Ordr
            LEFT JOIN CMS.dbo.Entities e ON e.Entity = o.Entity
            WHERE pod.ItemCode IN ({$in})", $codes) as $r) {
            if ((int) $r['rn'] !== 1) {
                continue;
            }
            $lastVendor[$r['ItemCode']] = [
                'vendor'      => trim((string) ($r['Vendor'] ?? '')) !== '' ? trim((string) $r['Vendor']) : null,
                'vendor_code' => trim((string) ($r['TheirCode'] ?? '')) !== '' ? trim((string) $r['TheirCode']) : null,
            ];
        }

        // Names, units, preferred vendor, distinct-vendor counts, open-PO qty.
        $meta = [];
        foreach ($c->cmsFetchAll('item names & units', "
            SELECT i.ItemCode, i.Description, i.Unit,
                   CASE WHEN i.CostingRecipe IS NULL THEN 0 ELSE 1 END AS HasRecipe,
                   pe.EntityName AS PreferredVendor
            FROM CMS.dbo.Item i
            LEFT JOIN CMS.dbo.Entities pe ON pe.Entity = i.Supplier
            WHERE i.ItemCode IN ({$in})", $codes) as $r) {
            $meta[$r['ItemCode']] = $r;
        }
        $vendorCounts = [];
        foreach ($c->cmsFetchAll('vendor counts', "
            SELECT pod.ItemCode, COUNT(DISTINCT o.Entity) AS n
            FROM CMS.dbo.PurchaseOrderDetail pod
            JOIN CMS.dbo.Ordr o ON o.Ordr = pod.Ordr
            WHERE pod.ItemCode IN ({$in})
            GROUP BY pod.ItemCode", $codes) as $r) {
            $vendorCounts[$r['ItemCode']] = (int) $r['n'];
        }
        $onPo = [];
        foreach ($c->cmsFetchAll('already on open POs', "
            SELECT i.ItemCode, SUM(od.QtyReqd - COALESCE(od.QtyUsed, 0)) AS qty
            FROM CMS.dbo.OrdDetail od
            JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE o.Context = 'PO' AND (o.Status IS NULL OR o.Status NOT IN ('CMP','CLS'))
              AND od.Context = 'PO' AND od.IsOpen = 1 AND i.ItemCode IN ({$in})
            GROUP BY i.ItemCode", $codes) as $r) {
            $onPo[$r['ItemCode']] = (float) $r['qty'];
        }

        $rows = [];
        foreach ($needs as $code => $n) {
            $rows[] = [
                'item_code'    => $code,
                'description'  => (string) ($meta[$code]['Description'] ?? ''),
                'unit'         => (string) ($meta[$code]['Unit'] ?? ''),
                'qty_needed'   => $n['qty'],
                'on_po'        => $onPo[$code] ?? 0.0,
                'first_needed' => $n['due'],
                'src_ticket'   => $n['srcs']['ticket'] ? 1 : 0,
                'src_order'    => $n['srcs']['order'] ? 1 : 0,
                'has_recipe'   => (int) ($meta[$code]['HasRecipe'] ?? 0),
                'preferred_vendor' => isset($meta[$code]['PreferredVendor']) && trim((string) $meta[$code]['PreferredVendor']) !== ''
                    ? trim((string) $meta[$code]['PreferredVendor']) : null,
                'vendor'       => $lastVendor[$code]['vendor'] ?? null,
                'vendor_code'  => $lastVendor[$code]['vendor_code'] ?? null,
                'vendor_count' => $vendorCounts[$code] ?? 0,
                'refs'         => json_encode($n['refs'], JSON_UNESCAPED_SLASHES),
                'via'          => json_encode($n['via'], JSON_UNESCAPED_SLASHES),
            ];
        }
        $c->insertRows('nto_items', $rows);
        $c->queueSwap('nto_items', 0);

        return ['rows' => count($rows)];
    }
}
