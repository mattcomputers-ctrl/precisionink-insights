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
| `LEFT JOIN CMS.dbo.Entity e ON e.EntityCode = sd.BillTo`             | Look up `e.EntityName` for Bill To display (the view exposes `ShipToName` but not `BillToName`) |
| `LEFT JOIN CMS.dbo.Item alias ON alias.ItemCode = sd.ItemName`       | Get the alias's own `Description` (item-drill-down only) |
| `LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet`      | Walk up to the invoice header |
| `LEFT JOIN CMS.dbo.[Trans] t ON t.[Trans] = cs.[Trans]`              | Reach `Trans.IsReversed` for the void filter |

### Filtering rules (applied in every Margin Watchdog query)

1. **`sd.QtyShipped > 0`** — excludes returns and credits, which CMS records
   as shipments with negative quantity. Per business owner: returns/credits
   are intentionally **not** netted into Margin Watchdog totals because
   they would distort the period-over-period comparison.
2. **`COALESCE(t.IsReversed, 0) = 0`** — excludes voided shipments. CMS
   marks the associated invoice's "Is Reversed" flag (`Trans.IsReversed`)
   when a shipment is voided. Voids are typically pricing-error reversals
   and have no place in trend analysis. The `COALESCE` keeps shipments
   that don't yet have an invoice (where `t.IsReversed` is NULL).

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
on the line, and voids by flagging the **associated invoice's `IsReversed`
field**. Per the business owner the rules for Margin Watchdog are:

  - **Returns and credits are intentionally excluded.** They distort period
    comparisons because a return in one period offsets revenue from a
    completely different period. The query filter is `sd.QtyShipped > 0`.
  - **Voided shipments are excluded.** Voids in CMS are predominantly used
    to undo pricing errors before the customer is billed, so they aren't
    real activity worth analysing. The query filter is
    `COALESCE(t.IsReversed, 0) = 0` against `CMS.dbo.[Trans]` joined via
    `ChangeSet.Trans`. (`COALESCE` keeps shipments whose invoice has not
    been generated yet — they have no `Trans` row, so `IsReversed` is
    NULL, which we treat as not-reversed.)

If your environment uses a different column name than `IsReversed` on the
`Trans` table, update both the WHERE clauses and the join in
[ShipmentService.php](src/Modules/MarginWatchdog/ShipmentService.php). The
column name is the only piece of this rule that's environment-specific —
the rest of the logic is portable.

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

## 4. How to add a new module / tab

The module abstraction lives in `src/Core/Module.php`. To add a tab:

1. Create a class under `src/Modules/{Name}/{Name}Module.php` extending `\PII\Core\Module`.
2. Implement `key()`, `name()`, `basePath()`, `permissionKey()`, `registerRoutes()`.
3. Register it in `src/Core/App.php` → `buildModuleRegistry()`.
4. Optionally grant the new permission key to existing groups in
   `/admin/groups`.

The tab nav, permission check, and route dispatch all pick up the new module
automatically. No global wiring to update.
