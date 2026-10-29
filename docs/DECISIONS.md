# DECISIONS.md — recorded decisions for Precision Ink Insights

Format: date, decision, why. Spec references are to `docs/SPEC-order-readiness.md`.

## 2026-10-29 — Answers from Matt (spec §14)

1. **CMS login:** keep Insights' current CMS login (`sds_readonly`, shared with the SDS
   system). No new CMS login is created. (§14 Q1)
2. **Role migration:** there is currently **one user** (Matt, Administrators). Admin gets
   everything always; Matt will reconfigure permissions after the build. So migration =
   carry the one account + its preferences/presets forward; seed the new groups fresh.
   (§14 Q2)
3. **Hidden vs granted:** when one of a user's groups hides a module another grants,
   **hidden wins** (admins excepted — they always see everything). (§14 Q2a)
4. **Sales order statuses:** `CMP` = completed (closed). `CSH` = open, credit hold.
   `RTS` = open, released to ship. (§14 Q3)
5. **Batch/packaging ticket statuses:** `RLS` = released. `CLS` = closed-not-completed
   (e.g. cancelled). `CMP` = completed. `NST` = cut but not released to production —
   **flag it**. `BAT` = production done, being closed — **flag if BAT > 1 day** (something
   is wrong). (§14 Q3)
6. **Available to ship:** whatever is in inventory right now counts as available — no CMS
   commitment/allocation layer. Order priority is a drag-and-drop list; a new order
   defaults into position by DateRequired and everything recalculates. (§14 Q4)
7. **Authoritative minimum stock:** `ItemEntity.MinimumStock` where `Context='ST'`
   (verified in earlier Insights work as the row the CMS ShortInventory view uses).
   Re-verify against Matt's examples E4456-50 and E3736-50 at step 6. `99` = discontinued.
   (§14 Q5)
8. **Bulk vs packaged:** inventory does not move until a ticket reaches `BAT` or `CMP` —
   so open tickets contribute *future* coverage; on-hand is only what's physically there.
   (§14 Q6)
9. **Standard batch sizes:** entered in Insights, not read from CMS. The Scheduling module's
   `sched_item_config.standard_batch_size` already stores per-bulk-item batch sizes — reuse
   rather than a second table. (§14 Q7)
10. **Negative projected stock, no minimum:** yes, on the production list, as a priority.
    (§14 Q8)
11. **Blackouts:** block manual sync for normal users (admins get Override with
    confirmation), and pause Margin Watchdog's live CMS queries too, with a friendly
    "paused for nightly backup" message. (§14 Q9)
12. **Theme:** light by default, per-user dark toggle. (§14 Q10)

## 2026-10-29 — Decisions made during §0 (mine, flagged for review)

13. **Margin Watchdog regression contract** = the fixtures in
    `tests/fixtures/margin-watchdog/`: pure-function equality of
    `MetricsCalculator::compute()` against frozen raw rows (delta 1e-9), plus sort and
    view-mode checks. Live re-capture is a manual, optional check only, because
    `ReplacementCost`/alias descriptions drift and late voids can change history.
14. **"SDS System CMS notes" merged from memory + SDS code, not SDS docs/.** The SDS repo's
    `docs/` are about SDS content generation, not CMS schema; its CMS knowledge lives in
    `src/Services/CMSImportService.php` and in working notes. The relevant schema facts
    (items, recipes, aliases, shipment chain) are now in `SCHEMA_NOTES.md` §6 — one source
    of truth, as §0.3 asks.
15. **"Has a recipe" / current active recipe** (for Order Readiness §7): an item has a
    recipe iff `Item.CostingRecipe IS NOT NULL`; the active recipe is
    `Recipe.Recipe = Item.CostingRecipe`. This is exactly how the SDS importer
    distinguishes finished goods from raw materials. Re-verify sample items at step 6.

## 2026-10-29 — Step-3 decisions (mine, flagged for review)

19. **Dashboard metric cards require `margin.view`.** The dashboard shows yesterday's
    revenue, packed-cost % and inventory value — margin-sensitive numbers the spec locks
    behind Margin Watchdog's permission. Users without it see the dashboard as a plain
    tab launcher (no cards, no CMS-error banner). Interim rule until step 5's Home page.
20. **Production group also gets Scheduling** (`scheduling.view` + `scheduling.manage`).
    The spec's §11 table predates the Scheduling tool; the production team obviously
    needs its own mill schedule. Margin Watchdog stays hidden from them.
21. **Exports are double-gated:** the core `export` permission AND the tool-specific one
    (`margin.export` for Margin Watchdog; `scheduling.manage` is not required to export a
    schedule — `export` alone gates that button since viewing the schedule already
    exposes the data).
22. **Preferences and saved date presets follow Margin Watchdog's visibility** — they are
    its per-user settings (colour thresholds, date ranges), so users who can't see the
    module don't see those pages or nav links either.
23. **Rate limiting:** 5 failed logins → 15-minute lockout on that account; the correct
    password is also refused during lockout; counter resets after lockout/success.
24. **Old `group_permissions` table is kept (unused)** for rollback; dropped in a later
    migration once the new model has lived a while in production.
25. **Grants for not-yet-registered modules are preserved**: the group editor only
    touches permissions the catalog knows, so the seeded Order Readiness grants
    (`orders.*`, `production.*`) and the `order_readiness` default-module values survive
    admin edits and light up when that module ships (step 7).
26. **Integration crawl harness** (`tests/integration/`): Docker MariaDB + php:8.3 serving
    the app with a CMS-less test config; logs in as each seeded group and checks every
    route's status, nav links, in-page controls, plain-404 bodies, no-error-dump pages,
    landing paths, forced password change, lockout, and mid-session deactivation.
    Dev-box only (it seeds/deletes crawl_* users); it refuses to run unless the DB name
    contains "test". 350 checks green as of this entry.

## 2026-10-29 — Step-6 data-map decisions (verified against live CMS; flagged for review)

27. **Packaging tickets come from `Ordr`/`OrdDetail` directly** — the
    `BatchingOrders` view covers MFBA only (0 of 97 open MFPP rows appear in it).
    MFPP PK line = pack item produced; MFBA PK line = bulk produced.
28. **"Available to ship" = `InventoryTotal.QtyUsable` summed across owners**
    (SOH minus hold/restricted/unusable; ≈ SOH on today's data). SOH and hold
    components are snapshotted and shown. ⚠ Review: Matt said "in inventory right
    now" — if held/QC stock SHOULD count, switch to QtySOH (one-line change).
29. **No CMS link from tickets to sales orders exists** (`BatchSheetDemand` is
    MFBA→MFPP only), so allocation runs purely on the drag-and-drop order
    priority; a ticket's `CustomerCode` shows as a "cut for …" hint. The spec's
    "linked ticket serves its order first" rule is therefore inert until/unless
    such a link appears in CMS.
30. **Remaining qty on a line = `QtyReqd − QtyUsed` on `IsOpen=1` lines**;
    CMS's own `QtyCommitted` is displayed but not used for allocation (Matt's
    §14 Q4: Insights allocates by its own priority list).
31. **Units:** all open order lines are `lb` today; the coverage engine requires
    matching units and reports (rather than converts) a mismatch.
32. **Order notes** (Matt, 2026-10-29): free-text notes can be attached to any
    order, keyed by CMS order number in the Insights DB (`or_order_notes`), kept
    forever — searchable later even after the order completes, incl. a notes
    search page. Notes are visible to everyone who can see orders; adding needs
    `orders.view`.

## Open items to decide later (spec says "decide" — not yet decided)

- Sync overall timeout (spec default 5 min) and per-step timeouts — set at step 4.
- Whether Margin Watchdog keeps querying CMS live or moves to snapshots (§3) — leaning
  **keep live** (date-range reports over years of history don't snapshot well; queries are
  seconds-fast), decide at step 4 and record here.
- Reactivated/newly-discontinued badge duration (spec default 14 days) — default stands
  unless Matt objects.
- Per-user landing-page override: admin-only for now (spec's own recommendation).
## 2026-10-29 — Decisions from the step-1 review (Matt)

16. **Keep Apache + mod_php; do NOT migrate to nginx + PHP-FPM.** The webserver has never
    been a problem, traffic is tiny, and the sync worker is a CLI systemd service either
    way — swapping the webserver on the production box is risk with no payoff. Spec §5's
    installer is adapted accordingly (Apache vhost instead of nginx site; everything else
    per spec).
17. **Swap the CMS driver dblib → Microsoft ODBC 18 + `pdo_sqlsrv`** (that's where the real
    pain was: date-format trap, query-timeout kills). Keep the dblib code path as a
    fallback, and verify via the new Admin → CMS "Test connection" before anything depends
    on it.
18. **Local test execution via Docker** (`php:cli` container) on Matt's Windows box — the
    box has no PHP but has Docker, so the Margin Watchdog fixture regression runs locally
    before every push instead of only on the server.
