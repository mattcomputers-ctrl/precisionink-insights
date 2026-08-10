<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

use PII\Core\CMSDatabase;
use PII\Core\Database;

/**
 * SchedulingDataService — all CMS reads for the Scheduling module.
 *
 * Data sources (see SCHEMA_NOTES.md §Scheduling):
 *   - InventoryTotal (per Item, summed across owners):
 *       QtySOH        physical stock on hand
 *       QtyBooked     open customer order demand   (OrdDetail UI/SH/KD/UAI, IsOpen=1)
 *       QtyOnOrder    incoming supply — released production / POs (PK/PKA/PO, IsOpen=1)
 *       QtyOpenToSell (SOH − unusable) + OnOrder − Booked  → negative = can't cover orders
 *   - ItemEntity.MinimumStock with Context='ST'  → per-pack min stock level
 *   - Pack items: Item.Context = 'PP'
 *   - Pack → bulk: the pack's CostingRecipe UI line with Unit='lb' and the
 *     largest QtyReqd (≈1.0) — verified across sampled packs
 *   - Popularity: trailing POPULARITY_DAYS shipped lbs (ShipmentDetails,
 *     same returns/void filters as Margin Watchdog), aggregated to bulk
 */
class SchedulingDataService
{
    /** Popularity window (days of shipment history). */
    public const POPULARITY_DAYS = 91;

    private CMSDatabase $cms;
    private Database $db;

    public function __construct()
    {
        $this->cms = CMSDatabase::getInstance();
        $this->db  = Database::getInstance();
    }

    /**
     * Pack-level inventory position for every PP item that has stock,
     * orders, or a minimum stock level.
     *
     * @return list<array{pack:string, description:string, unit:string,
     *   min_stock:float, soh:float, booked:float, on_order:float, open_to_sell:float}>
     */
    public function packPositions(): array
    {
        $sql = "
            SELECT
                i.ItemCode                          AS pack,
                i.Description                       AS description,
                i.Unit                              AS unit,
                COALESCE(ms.MinimumStock, 0)        AS min_stock,
                COALESCE(SUM(it.QtySOH), 0)         AS soh,
                COALESCE(SUM(it.QtyBooked), 0)      AS booked,
                COALESCE(SUM(it.QtyOnOrder), 0)     AS on_order,
                COALESCE(SUM(it.QtyOpenToSell), 0)  AS open_to_sell
            FROM CMS.dbo.Item i
            LEFT JOIN (
                SELECT Item, MAX(MinimumStock) AS MinimumStock
                  FROM CMS.dbo.ItemEntity
                 WHERE Context = 'ST'
                 GROUP BY Item
            ) ms ON ms.Item = i.Item
            LEFT JOIN CMS.dbo.InventoryTotal it ON it.Item = i.Item
            WHERE i.Context = 'PP'
            GROUP BY i.ItemCode, i.Description, i.Unit, ms.MinimumStock
            HAVING COALESCE(SUM(it.QtySOH), 0)     <> 0
                OR COALESCE(SUM(it.QtyBooked), 0)  <> 0
                OR COALESCE(SUM(it.QtyOnOrder), 0) <> 0
                OR COALESCE(ms.MinimumStock, 0)     > 0
        ";
        $rows = $this->cms->fetchAll($sql);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'pack'         => (string) $r['pack'],
                'description'  => (string) ($r['description'] ?? ''),
                'unit'         => (string) ($r['unit'] ?? ''),
                'min_stock'    => (float)  ($r['min_stock'] ?? 0),
                'soh'          => (float)  ($r['soh'] ?? 0),
                'booked'       => (float)  ($r['booked'] ?? 0),
                'on_order'     => (float)  ($r['on_order'] ?? 0),
                'open_to_sell' => (float)  ($r['open_to_sell'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Pack → bulk item map for every PP item, via the pack recipe's main
     * lb-unit UI ingredient. Falls back to code-prefix (E1055-50 → E1055)
     * when the recipe is missing.
     *
     * @return array<string, string>  pack ItemCode → bulk ItemCode
     */
    public function packToBulkMap(): array
    {
        $sql = "
            SELECT pack.ItemCode AS pack, bulk.ItemCode AS bulk, rd.QtyReqd
              FROM CMS.dbo.Item pack
              JOIN CMS.dbo.Recipe r        ON r.Recipe  = pack.CostingRecipe
              JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = r.Recipe AND rd.Context = 'UI'
              JOIN CMS.dbo.Item bulk       ON bulk.Item = rd.Item
             WHERE pack.Context = 'PP'
               AND bulk.Unit = 'lb'
        ";
        $rows = $this->cms->fetchAll($sql);

        // Keep the ingredient with the largest QtyReqd per pack (the base ink ≈ 1.0/lb)
        $best = [];
        foreach ($rows as $r) {
            $p = (string) $r['pack'];
            $q = (float)  $r['QtyReqd'];
            if (!isset($best[$p]) || $q > $best[$p]['qty']) {
                $best[$p] = ['bulk' => (string) $r['bulk'], 'qty' => $q];
            }
        }

        $map = [];
        foreach ($best as $pack => $b) {
            $map[$pack] = $b['bulk'];
        }
        return $map;
    }

    /** Code-prefix fallback: E1055-50 → E1055. Returns null when no dash. */
    public static function bulkFromCode(string $packCode): ?string
    {
        $pos = strrpos($packCode, '-');
        return $pos !== false && $pos > 0 ? substr($packCode, 0, $pos) : null;
    }

    /**
     * Trailing shipped lbs per bulk item (popularity ranking input).
     * Resolves shipment alias → pack (ReplacedBy) → bulk (recipe map).
     *
     * @param array<string,string> $packToBulk
     * @return array<string, float>  bulk ItemCode → shipped lbs
     */
    public function popularityByBulk(array $packToBulk): array
    {
        $start = date('Y-m-d', strtotime('-' . self::POPULARITY_DAYS . ' days'));
        $sql = "
            SELECT
                ISNULL(inv.ItemCode, sd.ItemName) AS pack_code,
                COALESCE(SUM(sd.QtyShipped), 0)   AS shipped
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.Item alias ON alias.ItemCode = sd.ItemName
            LEFT JOIN CMS.dbo.Item inv   ON inv.Item = alias.ReplacedBy
            LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet
            LEFT JOIN CMS.dbo.[Trans]    t ON t.[Trans]    = cs.[Trans]
            LEFT JOIN (
                SELECT DISTINCT ReversedTrans FROM CMS.dbo.[Trans] WHERE ReversedTrans IS NOT NULL
            ) ro ON ro.ReversedTrans = t.[Trans]
            WHERE sd.QtyShipped > 0
              AND t.ReversedTrans IS NULL
              AND ro.ReversedTrans IS NULL
              AND sd.DateShipped >= ?
            GROUP BY ISNULL(inv.ItemCode, sd.ItemName)
        ";
        $rows = $this->cms->fetchAll($sql, [$start]);

        $byBulk = [];
        foreach ($rows as $r) {
            $pack = (string) $r['pack_code'];
            $bulk = $packToBulk[$pack] ?? self::bulkFromCode($pack) ?? $pack;
            $byBulk[$bulk] = ($byBulk[$bulk] ?? 0.0) + (float) $r['shipped'];
        }
        return $byBulk;
    }

    /**
     * Search CMS items for the item-config UI. Bulk ink items are
     * Context='SUNDRY' Unit='lb' but we search broadly and show context.
     */
    public function searchItems(string $q, int $limit = 20): array
    {
        $sql = "
            SELECT TOP {$limit} i.ItemCode, i.Description, i.Context, i.Unit
              FROM CMS.dbo.Item i
             WHERE i.ItemCode LIKE ? AND i.ReplacedBy IS NULL
             ORDER BY i.ItemCode
        ";
        return $this->cms->fetchAll($sql, [$q . '%']);
    }

    /* ------------------------------------------------------------------
     *  Local config (MySQL)
     * ----------------------------------------------------------------*/

    /** @return list<array<string,mixed>> active mills in sort order */
    public function mills(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->fetchAll(
            "SELECT * FROM sched_mills {$where} ORDER BY sort_order, name"
        );
    }

    /** @return array<string, array{color:string,batch_size_1:float,batch_size_2:?float,not_milled:bool}> keyed by bulk code */
    public function itemConfigs(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT * FROM sched_item_config") as $r) {
            $out[(string) $r['bulk_item_code']] = [
                'color'        => (string) $r['color'],
                'batch_size_1' => (float)  $r['batch_size_1'],
                'batch_size_2' => $r['batch_size_2'] !== null ? (float) $r['batch_size_2'] : null,
                'not_milled'   => !empty($r['not_milled']),
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     *  Dry-grind pass derivation
     * ----------------------------------------------------------------*/

    /** @return list<array{id:int,pattern:string}> */
    public function dryGrindTriggers(): array
    {
        return array_map(
            fn($r) => ['id' => (int) $r['id'], 'pattern' => (string) $r['pattern']],
            $this->db->fetchAll("SELECT id, pattern FROM sched_dry_grind_triggers ORDER BY pattern")
        );
    }

    public function addDryGrindTrigger(string $pattern): void
    {
        $pattern = strtoupper(trim($pattern));
        if ($pattern === '' || strlen($pattern) > 30) {
            throw new \InvalidArgumentException('Pattern must be 1-30 characters.');
        }
        $exists = $this->db->fetch("SELECT 1 FROM sched_dry_grind_triggers WHERE pattern = ?", [$pattern]);
        if (!$exists) {
            $this->db->insert('sched_dry_grind_triggers', ['pattern' => $pattern]);
        }
    }

    public function deleteDryGrindTrigger(int $id): void
    {
        $this->db->delete('sched_dry_grind_triggers', 'id = ?', [$id]);
    }

    /** Global dry-grind pass count (settings; default 3). */
    public function dryGrindPasses(): int
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'sched.dry_grind_passes'");
        $v = $row ? (int) $row['value'] : 0;
        return $v > 0 ? $v : 3;
    }

    public function saveDryGrindPasses(int $passes): void
    {
        $passes = max(1, $passes);
        $exists = $this->db->fetch("SELECT 1 FROM settings WHERE `key` = 'sched.dry_grind_passes'");
        if ($exists) {
            $this->db->update('settings', ['value' => (string) $passes], '`key` = ?', ['sched.dry_grind_passes']);
        } else {
            $this->db->insert('settings', ['key' => 'sched.dry_grind_passes', 'value' => (string) $passes]);
        }
    }

    /**
     * LIKE-style match: '%' is a wildcard (PGK% = starts with PGK).
     * Case-insensitive; no '%' = exact match. preg_quote leaves '%'
     * untouched, so replacing it with .* after quoting is safe.
     */
    public static function matchesTrigger(string $code, string $pattern): bool
    {
        $regex = '/^' . str_replace('%', '.*', preg_quote(strtoupper($pattern), '/')) . '$/';
        return (bool) preg_match($regex, strtoupper($code));
    }

    /**
     * Derive passes for the given bulk items from their DIRECT recipe
     * ingredients (Context='UI'). Dry grind if any direct ingredient
     * matches any trigger; intermediates never propagate their own
     * dry-grind status (they arrive pre-ground).
     *
     * @param list<string> $bulkCodes
     * @return array{passes: array<string,int>, dry: array<string,bool>}
     */
    public function derivePasses(array $bulkCodes): array
    {
        $triggers  = array_column($this->dryGrindTriggers(), 'pattern');
        $dryPasses = $this->dryGrindPasses();

        $passes = [];
        $dry    = [];
        foreach ($bulkCodes as $c) {
            $passes[$c] = 1;
            $dry[$c]    = false;
        }
        if (empty($bulkCodes) || empty($triggers)) {
            return ['passes' => $passes, 'dry' => $dry];
        }

        // Direct UI ingredients for all requested bulks, chunked IN clause
        foreach (array_chunk($bulkCodes, 300) as $chunk) {
            $ph  = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "
                SELECT b.ItemCode AS bulk_code, ing.ItemCode AS ingredient
                  FROM CMS.dbo.Item b
                  JOIN CMS.dbo.Recipe r        ON r.Recipe  = b.CostingRecipe
                  JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = r.Recipe AND rd.Context = 'UI'
                  JOIN CMS.dbo.Item ing        ON ing.Item  = rd.Item
                 WHERE b.ItemCode IN ({$ph})
            ";
            foreach ($this->cms->fetchAll($sql, $chunk) as $row) {
                $bulk = (string) $row['bulk_code'];
                if ($dry[$bulk] ?? false) continue;
                foreach ($triggers as $t) {
                    if (self::matchesTrigger((string) $row['ingredient'], $t)) {
                        $dry[$bulk]    = true;
                        $passes[$bulk] = $dryPasses;
                        break;
                    }
                }
            }
        }

        return ['passes' => $passes, 'dry' => $dry];
    }

    /**
     * Worklist: bulk items whose packs carry a minimum stock level,
     * with current need — for the settings page "needs configuration"
     * card (caller filters out already-configured codes).
     *
     * @return list<array{bulk:string, packs_with_min:int, total_min_lbs:float, current_need_lbs:float, packs_short_on_orders:int}>
     */
    public function bulksWithMinStock(): array
    {
        $sql = "
            WITH PackMin AS (
                SELECT i.Item, i.ItemCode AS pack,
                       ms.MinimumStock,
                       COALESCE(it.OpenToSell, 0) AS open_to_sell
                  FROM CMS.dbo.Item i
                  JOIN (SELECT Item, MAX(MinimumStock) AS MinimumStock
                          FROM CMS.dbo.ItemEntity WHERE Context='ST' GROUP BY Item) ms
                    ON ms.Item = i.Item AND ms.MinimumStock > 0
                  LEFT JOIN (SELECT Item, SUM(QtyOpenToSell) AS OpenToSell
                               FROM CMS.dbo.InventoryTotal GROUP BY Item) it
                    ON it.Item = i.Item
                 WHERE i.Context = 'PP'
            ),
            PackBulk AS (
                SELECT pm.pack, pm.MinimumStock, pm.open_to_sell,
                       b.ItemCode AS bulk_code, b.Description AS bulk_desc, rd.QtyReqd,
                       ROW_NUMBER() OVER (PARTITION BY pm.pack ORDER BY rd.QtyReqd DESC) AS rn
                  FROM PackMin pm
                  JOIN CMS.dbo.Item pi ON pi.ItemCode = pm.pack
                  JOIN CMS.dbo.Recipe r ON r.Recipe = pi.CostingRecipe
                  JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe = r.Recipe AND rd.Context='UI'
                  JOIN CMS.dbo.Item b ON b.Item = rd.Item AND b.Unit = 'lb'
            )
            SELECT bulk_code,
                   MAX(bulk_desc) AS bulk_desc,
                   COUNT(1) AS packs_with_min,
                   SUM(MinimumStock) AS total_min_lbs,
                   SUM(CASE WHEN MinimumStock - open_to_sell > 0 THEN MinimumStock - open_to_sell ELSE 0 END) AS current_need_lbs,
                   SUM(CASE WHEN open_to_sell < 0 THEN 1 ELSE 0 END) AS packs_short_on_orders
              FROM PackBulk
             WHERE rn = 1
             GROUP BY bulk_code
             ORDER BY current_need_lbs DESC, bulk_code
        ";
        $out = [];
        foreach ($this->cms->fetchAll($sql) as $r) {
            $out[] = [
                'bulk'                  => (string) $r['bulk_code'],
                'description'           => (string) ($r['bulk_desc'] ?? ''),
                'packs_with_min'        => (int)    $r['packs_with_min'],
                'total_min_lbs'         => (float)  $r['total_min_lbs'],
                'current_need_lbs'      => (float)  $r['current_need_lbs'],
                'packs_short_on_orders' => (int)    $r['packs_short_on_orders'],
            ];
        }
        return $out;
    }

    /** The 12 fixed ladder colors (canonical keys). */
    public const COLORS = [
        'extender', 'opaque white', 'yellow', 'orange', 'warm red', 'red',
        'violet', 'reflex blue', 'blue', 'green', 'brown', 'black',
    ];

    /** Configured color order (settings override, else the default ladder). */
    public function colorOrder(): array
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'sched.color_order'");
        if ($row) {
            $decoded = json_decode((string) $row['value'], true);
            if (is_array($decoded) && count($decoded) === count(self::COLORS)) {
                return $decoded;
            }
        }
        return self::COLORS;
    }

    public function saveColorOrder(array $order): void
    {
        // Validate: must be a permutation of COLORS
        $sorted1 = $order;      sort($sorted1);
        $sorted2 = self::COLORS; sort($sorted2);
        if ($sorted1 !== $sorted2) {
            throw new \InvalidArgumentException('Color order must contain each ladder color exactly once.');
        }
        $val = json_encode(array_values($order));
        $existing = $this->db->fetch("SELECT 1 FROM settings WHERE `key` = 'sched.color_order'");
        if ($existing) {
            $this->db->update('settings', ['value' => $val], '`key` = ?', ['sched.color_order']);
        } else {
            $this->db->insert('settings', ['key' => 'sched.color_order', 'value' => $val]);
        }
    }
}
