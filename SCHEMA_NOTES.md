# SCHEMA_NOTES.md — CMS data sources used by Precision Ink Insights

This document lists every CMS (MSSQL) table/column/view that **Precision Ink
Insights** queries, why, and the assumptions behind those queries. It exists
so future tabs can re-use the same join patterns and so the numbers can be
tied out against other CMS reports.

The CMS connection is **read-only** — this app never writes to CMS.

---

## 1. Primary data source: `CMS.dbo.ShipmentDetails` (view)

All Margin Watchdog queries hit this view. The view itself is a 12-table
join over `InvMovement`, `InvMovementDtl`, `ChangeSet`, `ChangeSetShipment`,
`Trans`, `Ordr`, `OrdDetail`, `OrdDetailPricing`, `Item` (×2), `Entities`,
`Entity`, `Waybill`, and `ItemCustom`, filtered to:

  - `InvMovement.Context  = 'SH'`           (shipments)
  - `InvMovementDtl.Context IN ('US','USH')` (shipment line detail)

### Columns we use

| View column   | What it is                                                      | Why we use it |
|---------------|------------------------------------------------------------------|---------------|
| `BillTo`      | `Entities.EntityCode` (via `Ordr.BillTo`)                        | Customer grouping key |
| `ItemName`    | Alias item code (`Item.ItemCode` via `OrdDetail.ItemName`)       | Customer-facing alias / line grouping |
| `OrderedUnit` | Per-line UoM (from `OrdDetailPricing`)                           | Item drill-down "Unit" column + mixed-UoM detection |
| `QtyShipped`  | `-InvMovementDtl.Qty` (positive for outbound; **negative = return/credit**) | Quantity sold per line; sign drives the returns filter |
| `UnitCost`    | `InvMovementDtl.Value / InvMovementDtl.Qty`                      | **Canonical packed raw cost per unit** at time of shipment |
| `UnitPrice`   | Currency-converted unit price                                    | Reference; we don't multiply this — see caveat #3 |
| `TotalAmount` | Calculated line revenue (`-Qty × Price × package factors`)       | **Canonical revenue** — used directly, never recomputed |
| `DateShipped` | `Waybill.DateShipped` if the waybill is `Status='CMP'`, otherwise falls back to `ChangeSet.ChangeDate` | The single date filter for both ranges |
| `ChangeSet`   | FK to `ChangeSet.ChangeSet`                                      | Used to join through to `Trans` for the void filter |

> Note: the view also exposes a `Description` column from the inventory item.
> We do **not** use it; instead we LEFT-JOIN `CMS.dbo.Item` on `ItemCode = sd.ItemName`
> to get the alias's own description (see caveat #2).

### Auxiliary joins

| Join                                                                 | Reason |
|----------------------------------------------------------------------|--------|
| `LEFT JOIN CMS.dbo.Entities e ON e.EntityCode = sd.BillTo`           | Look up `e.EntityName` for Bill To display. NOTE: the `Entities` **view** exposes `EntityName`; the `Entity` **base table** does not. |
| `LEFT JOIN CMS.dbo.Item alias ON alias.ItemCode = sd.ItemName`       | Get the alias's own `Description` (item-drill-down only) |
| `LEFT JOIN CMS.dbo.Item inv ON inv.Item = ISNULL(alias.ReplacedBy, alias.Item)` | Resolve to the inventory item to read `ReplacementCost` (item-drill-down only) — see caveat #5 |
| `LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet`      | Walk up to the invoice header |
| `LEFT JOIN CMS.dbo.[Trans] t ON t.[Trans] = cs.[Trans]`              | Reach `Trans.IsReversed` for the void filter |

### Filtering rules (applied in every Margin Watchdog query)

1. **`sd.QtyShipped > 0`** — excludes returns and credits, which CMS records
   as shipments with negative quantity. Per business owner: returns/credits
   are intentionally **not** netted into Margin Watchdog totals because
   they would distort the period-over-period comparison.
2. **Void filter via `Trans.ReversedTrans`** — excludes voided invoices.
   When CMS reverses an invoice it inserts a second `Trans` row with
   `ReversedTrans` pointing back to the original. Both rows of the pair
   share the same `TransDocument` and must be excluded:
   - `t.ReversedTrans IS NULL` rejects the reversal trans itself.
   - A LEFT JOIN to a derived table of distinct `ReversedTrans` values,
     plus `reversed_originals.ReversedTrans IS NULL`, rejects the original
     row that was pointed at.
   Both conditions also pass when there's no `Trans` row yet (the LEFT
   JOIN to `Trans` returns NULL), so shipments that haven't been invoiced
   yet still appear.

---

## 2. Caveats and known limitations

### Caveat #1 — Date semantics
`DateShipped` is the invoice / ship date. The view picks `Waybill.DateShipped`
if the waybill is completed (`Status='CMP'`), else `ChangeSet.ChangeDate`. We
treat this as the date on which `TotalAmount` becomes recognised revenue.

If your accounting prefers the invoice's `Trans` date specifically (rather
than the waybill date), update the date filter in
`src/Modules/MarginWatchdog/ShipmentService.php` to filter on the joined
`Trans.TransDate` column instead. Document the change here.

### Caveat #2 — Item description: alias's own, not inventory item's
The view's `Description` column resolves to the *inventory* `Item.Description`,
which can diverge from the alias's own description for relabeled /
private-label SKUs. We don't use the view column. Instead, the
item-drill-down query LEFT JOINs `CMS.dbo.Item alias ON alias.ItemCode =
sd.ItemName` and uses `alias.Description`. That's the description executives
see on order entry, packing slips, and customer-facing output.

### Caveat #3 — `TotalAmount` is the revenue source of truth
The spec is explicit: do **not** recompute revenue as `UnitPrice × QtyShipped`
unless reconciling. The view's `TotalAmount` already accounts for currency
conversion (`dbo.AltCurrencyToBaseCurrency()`) and package-vs-unit pricing
factors. We follow that rule.

For packed cost we **do** compute `UnitCost × QtyShipped` because the view
does not pre-aggregate cost. This is exactly what the line-item viewer in
CMS shows.

### Caveat #4 — Returns, credits, voids: confirmed handling
CMS records returns and credit memos as shipments with **negative quantity**
on the line. Voids are recorded by inserting a second `Trans` row whose
`ReversedTrans` column points back at the original; both rows in the pair
share the same `TransDocument`. Per the business owner the rules for
Margin Watchdog are:

  - **Returns and credits are intentionally excluded.** They distort period
    comparisons because a return in one period offsets revenue from a
    completely different period. The query filter is `sd.QtyShipped > 0`.
  - **Voided shipments are excluded.** Voids in CMS are predominantly used
    to undo pricing errors, so they aren't real activity worth analysing.
    Both halves of the `(original, reversal)` pair are filtered out:
    - the reversal row via `t.ReversedTrans IS NULL`
    - the original row via a LEFT JOIN to a derived table of `DISTINCT
      ReversedTrans` values, plus `reversed_originals.ReversedTrans IS NULL`

Verified directionality: `Trans.ReversedTrans` on row R contains the
`Trans` PK of the **original** that R is reversing. The original itself
has `ReversedTrans = NULL`. Confirmed against live data — the void filter
removes both halves and leaves un-invoiced shipments alone.

### Caveat #5 — Expected packed cost: lookup, not recalculation
The "Expected Packed Cost" column in the item drill-down comes from
`Item.ReplacementCost` on the **inventory item** (resolved via the alias's
`ReplacedBy` chain). CMS already pre-computes this value as
`bulk_replacement_cost + Σ (packaging ingredient qty × packaging
ReplacementCost)` and stores the result on the packaged inventory item, so
we don't walk the recipe tree at query time.

Verified example: `E1055-50` (5 lb packaged ink), `Item.ReplacementCost =
3.6655`, which equals `(1.0 × E1055@3.4344) + (0.215 × 5LBPT@1.08)` from
its `RecipeDetail` lines.

Two consequences:

1. **Aliases store NULL replacement cost** — at probe time, all 7,197
   alias rows had `ReplacementCost IS NULL`, so the lookup MUST follow
   `alias.ReplacedBy` to the inventory item. The query uses
   `ISNULL(alias.ReplacedBy, alias.Item)` so the same join works for
   non-alias inventory items too.
2. **Some inventory items have no replacement cost on file** (e.g.
   shipping fees, credit-card fees, items without supplier prices). Those
   rows show "N/A" for the Expected Packed Cost column rather than $0.

If `Item.ReplacementCost` becomes stale relative to current input prices
in your environment, switch to walking the recipe at query time. The
fallback path is documented inline in
[ShipmentService.php](src/Modules/MarginWatchdog/ShipmentService.php).

### Caveat #5 — Mixed unit of measure on a single alias
A single alias can theoretically be sold in multiple units across different
shipments. We detect this with `COUNT(DISTINCT OrderedUnit)` per alias and
flag rows with more than one unit value as "mixed UoM" in the UI rather than
silently summing across incompatible units. Quantities ARE still summed for
display — the user is just warned.

---

## 3. Local MySQL schema (this app, not CMS)

Migrations live in `migrations/*.sql`. Tables this app owns:

| Table                  | Purpose |
|------------------------|---------|
| `users`                | Local auth — username, hashed password (Argon2id), display name |
| `permission_groups`    | Named groups (e.g. "Administrators", "Standard Users") |
| `user_group_members`   | Many-to-many of users ↔ groups |
| `group_permissions`    | (group, page_key) → access_level (`none`/`read`/`full`) |
| `settings`             | App-wide key/value settings |
| `user_preferences`     | Per-user prefs — colors_enabled, `mw.threshold.*` |
| `date_range_presets`   | Saved baseline+comparison ranges per user |
| `audit_log`            | Login events, report views, exports |

---

## 4. Scheduling module — CMS data sources

The Production Scheduling tab reads:

| Source | Used for |
|---|---|
| `InventoryTotal` (view, summed across owners per Item) | `QtySOH` on hand · `QtyBooked` open customer demand (OrdDetail contexts UI/SH/KD/UAI, IsOpen=1, non-quote) · `QtyOnOrder` incoming supply incl. **released production** (contexts PK/PKA/PO) · `QtyOpenToSell` = (SOH−unusable)+OnOrder−Booked |
| `ItemEntity.MinimumStock` where `Context='ST'` | Per-pack minimum stock level (verified: E1055-50 = 50) |
| `Item.Context='PP'` | Identifies pack items |
| Pack `CostingRecipe` → `RecipeDetail` `Context='UI'`, ingredient `Unit='lb'`, max QtyReqd | Pack → bulk item resolution (fallback: strip `-suffix` from code) |
| `ShipmentDetails` trailing 91 days (same returns/void filters as Margin Watchdog) | Popularity ranking (shipped lbs by bulk) for capacity-overflow priority |

Need formula per pack: `need = max(0, MinimumStock − QtyOpenToSell)`;
**tier 1** when `QtyOpenToSell < 0` (open orders not covered even counting
released production — released batches count via `QtyOnOrder`, so they
correctly keep an item out of tier 1).

Local config (MySQL): `sched_mills` (equipment), `sched_item_config`
(bulk item → color/passes/batch sizes), `settings['sched.color_order']`.
The full engine algorithm is documented at the top of
[ScheduleEngine.php](src/Modules/Scheduling/ScheduleEngine.php).

## 5. How to add a new module / tab

The module abstraction lives in `src/Core/Module.php`. To add a tab:

1. Create a class under `src/Modules/{Name}/{Name}Module.php` extending `\PII\Core\Module`.
2. Implement `key()`, `name()`, `basePath()`, `permissionKey()`, `registerRoutes()`.
3. Register it in `src/Core/App.php` → `buildModuleRegistry()`.
4. Optionally grant the new permission key to existing groups in
   `/admin/groups`.

The tab nav, permission check, and route dispatch all pick up the new module
automatically. No global wiring to update.
