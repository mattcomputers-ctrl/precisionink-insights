<?php

declare(strict_types=1);

namespace PII\Services;

use PII\Core\CMSDatabase;

/**
 * DashboardService — read-only metrics shown on the landing dashboard.
 *
 * Yesterday-anchored numbers (revenue, packed cost %, inventory snapshot)
 * because intra-day numbers are noisy and the inventory snapshot is only
 * stable as of the prior end-of-day. Same shipment filters as Margin
 * Watchdog (returns/voids excluded) so the figures reconcile.
 */
class DashboardService
{
    /**
     * Yesterday's revenue, packed cost, and cost-as-%-of-sales.
     *
     * @return array{date:string, revenue:float, cost:float, cost_pct:?float, lines:int}
     */
    public function yesterdayShipments(): array
    {
        $y   = date('Y-m-d', strtotime('-1 day'));
        $sql = "
            SELECT
                COALESCE(SUM(sd.TotalAmount), 0)               AS revenue,
                COALESCE(SUM(sd.UnitCost * sd.QtyShipped), 0)  AS cost,
                COUNT(*)                                       AS lines
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet
            LEFT JOIN CMS.dbo.[Trans]    t ON t.[Trans]    = cs.[Trans]
            LEFT JOIN (
                SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
            ) ro ON ro.ReversedTrans = t.[Trans]
            WHERE sd.QtyShipped > 0
              AND t.ReversedTrans IS NULL
              AND ro.ReversedTrans IS NULL
              AND sd.DateShipped >= ? AND sd.DateShipped < DATEADD(day, 1, ?)
        ";
        $row = CMSDatabase::getInstance()->fetch($sql, [$y, $y]) ?? [];
        $rev   = (float) ($row['revenue'] ?? 0);
        $cost  = (float) ($row['cost']    ?? 0);
        $lines = (int)   ($row['lines']   ?? 0);
        return [
            'date'     => $y,
            'revenue'  => $rev,
            'cost'     => $cost,
            'cost_pct' => $rev != 0.0 ? ($cost / $rev) * 100 : null,
            'lines'    => $lines,
        ];
    }

    /**
     * Yesterday's inventory snapshot — total actual value and breakdown by
     * GLGroup. Uses the GetInventoryAtDate TVF (NULL owner = all owners
     * aggregated).
     *
     * Uses ActualValue (book value of what was paid for the inventory on
     * hand) so the totals reconcile to CMS's Inventory Cost Set Viewer,
     * which is what people are used to seeing. ReplacementValue is also
     * available on the underlying view if a future feature wants the
     * "today's cost to replace" basis instead.
     *
     * @return array{date:string, total_value:float, total_qty:float, by_gl_group:list<array{gl_group:string, qty:float, value:float, pct_of_total:float}>}
     */
    public function yesterdayInventory(): array
    {
        $y   = date('Y-m-d', strtotime('-1 day'));
        $sql = "
            SELECT GLGroup,
                   COALESCE(SUM(Qty), 0)         AS qty,
                   COALESCE(SUM(ActualValue), 0) AS value
              FROM CMS.dbo.GetInventoryAtDate(?, NULL)
             WHERE Qty > 0
             GROUP BY GLGroup
        ";
        $rows = CMSDatabase::getInstance()->fetchAll($sql, [$y]);

        $totalValue = 0.0;
        $totalQty   = 0.0;
        foreach ($rows as $r) {
            $totalValue += (float) ($r['value'] ?? 0);
            $totalQty   += (float) ($r['qty']   ?? 0);
        }

        // Sort descending by value so the biggest GL group is at the top
        usort($rows, fn($a, $b) => ((float) $b['value']) <=> ((float) $a['value']));

        $byGroup = [];
        foreach ($rows as $r) {
            $val = (float) ($r['value'] ?? 0);
            $byGroup[] = [
                'gl_group'      => (string) ($r['GLGroup'] ?? '(unspecified)'),
                'qty'           => (float)  ($r['qty']     ?? 0),
                'value'         => $val,
                'pct_of_total'  => $totalValue > 0 ? ($val / $totalValue) * 100 : 0,
            ];
        }

        return [
            'date'        => $y,
            'total_value' => $totalValue,
            'total_qty'   => $totalQty,
            'by_gl_group' => $byGroup,
        ];
    }
}
