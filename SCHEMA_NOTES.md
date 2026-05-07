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
| `Description` | `Item.Description` (of the inventory item joined first in view)  | Display only — see caveat #2 |
| `OrderedUnit` | Per-line UoM (from `OrdDetailPricing`)                           | Item drill-down "Unit" column + mixed-UoM detection |
| `QtyShipped`  | `-InvMovementDtl.Qty` (positive after the view's negation)       | Quantity sold per line |
| `UnitCost`    | `InvMovementDtl.Value / InvMovementDtl.Qty`                      | **Canonical packed raw cost per unit** at time of shipment |
| `UnitPrice`   | Currency-converted unit price                                    | Reference; we don't multiply this — see caveat #3 |
| `TotalAmount` | Calculated line revenue (`-Qty × Price × package factors`)       | **Canonical revenue** — used directly, never recomputed |
| `DateShipped` | `Waybill.DateShipped` if the waybill is `Status='CMP'`, otherwise falls back to `ChangeSet.ChangeDate` | The single date filter for both ranges |

### Auxiliary join (for Bill To names)

We additionally LEFT-JOIN `CMS.dbo.Entity e ON e.EntityCode = sd.BillTo` to
look up `e.EntityName` because the `ShipmentDetails` view exposes
`ShipToName` but not `BillToName`. If a Bill To has no name on file, we fall
back to displaying the entity code.

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

### Caveat #2 — Item description is the *inventory* item's description
`ShipmentDetails.Description` resolves to the *inventory* `Item.Description`,
not the alias's own description. For most items the two match, but they can
diverge for relabeled / private-label SKUs. If the user wants the alias's own
description, do a separate join:

```sql
LEFT JOIN CMS.dbo.Item alias ON alias.ItemCode = sd.ItemName
-- and use alias.Description instead of sd.Description
```

### Caveat #3 — `TotalAmount` is the revenue source of truth
The spec is explicit: do **not** recompute revenue as `UnitPrice × QtyShipped`
unless reconciling. The view's `TotalAmount` already accounts for currency
conversion (`dbo.AltCurrencyToBaseCurrency()`) and package-vs-unit pricing
factors. We follow that rule.

For packed cost we **do** compute `UnitCost × QtyShipped` because the view
does not pre-aggregate cost. This is exactly what the line-item viewer in
CMS shows.

### Caveat #4 — Returns, credit memos, voids ⚠ verify before going live
The `ShipmentDetails` view filters to `InvMovement.Context='SH'`. **Any
return, credit memo, or void that uses a different context — for example,
`'SR'` (shipment return) or `'CR'` (credit) if those exist in your CMS — is
not currently included in Margin Watchdog totals.**

Action: before relying on the numbers operationally, confirm with the CMS ERP
admin (or check CMS source) which `InvMovement.Context` codes represent:
  - returns,
  - credit memos,
  - voided shipments.

If returns flow through a different context, expand `ShipmentService` to
union those rows (negated where appropriate). If voids are flagged on
existing shipment rows, add a `WHERE` clause to exclude them. Update this
file with the chosen approach.

Until that verification is done, treat Margin Watchdog as a **gross
revenue** report, not a net-of-returns one.

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
