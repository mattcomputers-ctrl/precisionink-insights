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
