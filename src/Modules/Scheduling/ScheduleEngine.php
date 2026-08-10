<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

/**
 * ScheduleEngine — builds the weekly production schedule.
 *
 * Inputs: pack positions + min stocks (CMS), item configs (color, passes,
 * batch sizes), mills, color ladder, popularity, enabled days.
 *
 * Algorithm (documented for tie-out; see SCHEMA_NOTES.md §Scheduling):
 *
 * 1. NEED (per pack):
 *      need_lbs  = max(0, min_stock − open_to_sell)
 *      tier 1 if open_to_sell < 0 (can't cover open customer orders even
 *      counting released production), else tier 2 when need_lbs > 0.
 *    open_to_sell already includes released production (QtyOnOrder), so a
 *    released batch that covers an order keeps the item OUT of tier 1.
 *
 * 2. AGGREGATE to bulk item (recipe map). A bulk is tier 1 if any of its
 *    packs is tier 1. Packs with need but no bulk config → warnings list.
 *
 * 3. BATCHES: cover bulk need_lbs with standard batch sizes (greedy:
 *    fill with size1 (larger) while remaining > size1; finish with the
 *    smallest standard size that covers the remainder). Always whole
 *    standard batches — rounding UP into stock is intended.
 *
 * 4. SEQUENCE: tier-1 batches first (sorted by color ladder among
 *    themselves — they all must run, so we still minimise washups),
 *    then tier-2 batches in ladder order. When capacity runs out,
 *    tier-2 items are dropped least-popular-first (trailing-91-day
 *    shipped lbs) and reported as unscheduled.
 *
 * 5. PLACEMENT: for each batch in sequence, pick the (mill, start day)
 *    that can run it (mill max batch ≥ batch lbs) with the earliest
 *    completion; ties broken by smallest washup penalty against that
 *    mill's last colour. All passes stay on the chosen mill.
 *    Batches MAY carry over into following day(s); continuation rows are
 *    flagged carryover so production knows it's the same batch.
 *
 * 6. WASHUPS (charged before a run): same color → like; forward move
 *    down the ladder → next; backward move → deep. First run of the
 *    week on a mill has no washup.
 *
 * Times drive what fits in a day but are NOT exposed on the output —
 * the schedule sets expectations, not stopwatch targets.
 */
class ScheduleEngine
{
    private const MIN_SPLIT_HOURS = 0.5;  // don't start a batch with less than this left in a day

    /** @var list<string> */
    private array $colorOrder;
    /** @var array<string,int> color → ladder index */
    private array $colorIndex;

    public function __construct(array $colorOrder)
    {
        $this->colorOrder = array_values($colorOrder);
        $this->colorIndex = array_flip($this->colorOrder);
    }

    /**
     * @param list<array> $packPositions   from SchedulingDataService::packPositions()
     * @param array<string,string> $packToBulk
     * @param array<string,array> $itemConfigs   bulk → {color,batch_size_1,batch_size_2}
     * @param list<array> $mills           sched_mills rows (active)
     * @param array<string,float> $popularity    bulk → trailing shipped lbs
     * @param string $weekStart            Monday date YYYY-MM-DD
     * @param list<int> $enabledDays       7 flags Mon..Sun (1 = run)
     * @param array<string,int> $passesByBulk   derived (dry-grind) passes; absent = 1
     * @param array<string,bool> $dryByBulk     derived dry-grind flag (display only)
     */
    public function build(
        array $packPositions,
        array $packToBulk,
        array $itemConfigs,
        array $mills,
        array $popularity,
        string $weekStart,
        array $enabledDays,
        array $passesByBulk = [],
        array $dryByBulk = []
    ): array {
        $warnings = [];

        // ── 1+2. need per pack → aggregate to bulk ─────────────────────
        $bulkNeeds = [];   // bulk => {need_lbs, tier, packs:[{pack,desc,need_lbs}], desc}
        foreach ($packPositions as $p) {
            $needLbs = max(0.0, $p['min_stock'] - $p['open_to_sell']);
            if ($needLbs <= 0) continue;

            $tier1 = $p['open_to_sell'] < 0;
            $bulk  = $packToBulk[$p['pack']] ?? SchedulingDataService::bulkFromCode($p['pack']);
            if ($bulk === null) {
                $warnings[] = "Pack {$p['pack']} has need ({$needLbs} lbs) but no bulk item could be resolved — skipped.";
                continue;
            }
            if (strtolower($p['unit']) !== 'lb') {
                $warnings[] = "Pack {$p['pack']} unit is '{$p['unit']}' (not lb) — treated as lbs, verify.";
            }

            if (!isset($bulkNeeds[$bulk])) {
                $bulkNeeds[$bulk] = [
                    'bulk' => $bulk, 'need_lbs' => 0.0, 'tier1' => false, 'packs' => [],
                ];
            }
            $bulkNeeds[$bulk]['need_lbs'] += $needLbs;
            $bulkNeeds[$bulk]['tier1']     = $bulkNeeds[$bulk]['tier1'] || $tier1;
            $bulkNeeds[$bulk]['packs'][]   = [
                'pack'        => $p['pack'],
                'description' => $p['description'],
                'need_lbs'    => $needLbs,
                'tier1'       => $tier1,
            ];
        }

        // ── 3. explode into batches ────────────────────────────────────
        $batches = [];   // each: bulk, desc, color, colorIdx, passes, lbs, tier1, popularity, packs share
        foreach ($bulkNeeds as $bulk => $bn) {
            $cfg = $itemConfigs[$bulk] ?? null;
            if ($cfg === null) {
                $warnings[] = "Bulk item {$bulk} has need (" . round($bn['need_lbs']) . " lbs across " . count($bn['packs']) . " pack(s)) but no scheduling config (color/batch size) — NOT scheduled. Configure it in Scheduling → Settings.";
                continue;
            }
            if (!isset($this->colorIndex[$cfg['color']])) {
                $warnings[] = "Bulk item {$bulk} has unknown color '{$cfg['color']}' — not scheduled.";
                continue;
            }

            $sizes = [$cfg['batch_size_1']];
            if (!empty($cfg['batch_size_2'])) $sizes[] = (float) $cfg['batch_size_2'];
            rsort($sizes);                       // sizes[0] = largest
            $small = min($sizes);

            $batchLbsList = [];
            $remaining = $bn['need_lbs'];
            while ($remaining > 1e-9) {
                if ($remaining > $sizes[0]) {
                    $batchLbsList[] = $sizes[0];
                    $remaining -= $sizes[0];
                } else {
                    // Smallest standard size that covers the remainder
                    $chosen = $sizes[0];
                    foreach ($sizes as $s) {
                        if ($s >= $remaining && $s <= $chosen) $chosen = $s;
                    }
                    // If even the smallest doesn't cover it, smallest still rounds up past need? no —
                    // smallest may be < remaining when remaining ≤ sizes[0]; guard:
                    if ($chosen < $remaining) $chosen = $sizes[0];
                    $batchLbsList[] = $chosen;
                    $remaining = 0.0;
                }
            }

            // Pack share: distribute each batch's lbs across packs pro-rata
            // to their remaining need (assigned batch by batch below).
            $totalBatchLbs = array_sum($batchLbsList);
            foreach ($batchLbsList as $i => $lbs) {
                $batches[] = [
                    'id'         => $bulk . '#' . ($i + 1),
                    'bulk'       => $bulk,
                    'batch_no'   => $i + 1,
                    'batch_count'=> count($batchLbsList),
                    'color'      => $cfg['color'],
                    'color_idx'  => $this->colorIndex[$cfg['color']],
                    'passes'     => max(1, (int) ($passesByBulk[$bulk] ?? 1)),
                    'dry_grind'  => (bool) ($dryByBulk[$bulk] ?? false),
                    'lbs'        => $lbs,
                    'tier1'      => $bn['tier1'],
                    'popularity' => $popularity[$bulk] ?? 0.0,
                    'packs_all'  => $bn['packs'],
                    'total_need' => $bn['need_lbs'],
                    'total_prod' => $totalBatchLbs,
                ];
            }
        }

        // Pack breakdown per batch: pro-rata to pack need share of the bulk's
        // total production (overshoot lands proportionally = stock build).
        foreach ($batches as &$b) {
            $breakdown = [];
            foreach ($b['packs_all'] as $pk) {
                $share = $b['total_need'] > 0 ? $pk['need_lbs'] / $b['total_need'] : 0;
                $breakdown[] = [
                    'pack'        => $pk['pack'],
                    'description' => $pk['description'],
                    'lbs'         => round($b['lbs'] * $share, 1),
                ];
            }
            $b['pack_breakdown'] = $breakdown;
            unset($b['packs_all']);
        }
        unset($b);

        // ── 4. sequence ────────────────────────────────────────────────
        $tier1Batches = array_values(array_filter($batches, fn($b) => $b['tier1']));
        $tier2Batches = array_values(array_filter($batches, fn($b) => !$b['tier1']));

        $ladderSort = function (array $a, array $b): int {
            return [$a['color_idx'], $a['bulk'], $a['batch_no']] <=> [$b['color_idx'], $b['bulk'], $b['batch_no']];
        };
        usort($tier1Batches, $ladderSort);
        usort($tier2Batches, $ladderSort);

        $sequence = array_merge($tier1Batches, $tier2Batches);

        // ── 5. placement ───────────────────────────────────────────────
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = [
                'date'    => date('Y-m-d', strtotime($weekStart . " +{$i} day")),
                'dow'     => date('D', strtotime($weekStart . " +{$i} day")),
                'enabled' => !empty($enabledDays[$i]),
            ];
        }

        // Per-mill state: remaining hours per day, last color idx, per-day run list
        $millState = [];
        foreach ($mills as $m) {
            $remain = [];
            foreach ($days as $i => $d) {
                $remain[$i] = $d['enabled'] ? (float) $m['hours_per_day'] : 0.0;
            }
            $millState[$m['id']] = [
                'mill'      => $m,
                'remain'    => $remain,
                'last_color'=> null,   // ladder idx of last batch placed
                'runs'      => array_fill(0, 7, []),
            ];
        }

        $unscheduled = [];

        foreach ($sequence as $batch) {
            $placed = $this->placeBatch($batch, $millState, $days);
            if (!$placed) {
                $batch['reason'] = $this->unscheduledReason($batch, $mills);
                $unscheduled[] = $batch;
            }
        }

        // If tier-2 got squeezed out, drop least-popular first is implicit:
        // sequence order was ladder-order; batches that failed to place are the
        // unscheduled ones. Re-sort unscheduled so the REPORT shows most-popular
        // first (what production would most want to squeeze in).
        usort($unscheduled, fn($a, $b) => $b['popularity'] <=> $a['popularity']);

        // ── output ─────────────────────────────────────────────────────
        $millsOut = [];
        foreach ($millState as $st) {
            $daysOut = [];
            foreach ($days as $i => $d) {
                $daysOut[] = [
                    'date'    => $d['date'],
                    'dow'     => $d['dow'],
                    'enabled' => $d['enabled'],
                    'hours_total'  => $d['enabled'] ? (float) $st['mill']['hours_per_day'] : 0.0,
                    'hours_used'   => $d['enabled'] ? round((float) $st['mill']['hours_per_day'] - $st['remain'][$i], 2) : 0.0,
                    'runs'    => $st['runs'][$i],
                ];
            }
            $millsOut[] = [
                'mill_id'   => (int) $st['mill']['id'],
                'mill_name' => (string) $st['mill']['name'],
                'max_batch_lbs' => (float) $st['mill']['max_batch_lbs'],
                'days'      => $daysOut,
            ];
        }

        return [
            'week_start'  => $weekStart,
            'days'        => $days,
            'mills'       => $millsOut,
            'unscheduled' => array_map(fn($b) => [
                'bulk' => $b['bulk'], 'lbs' => $b['lbs'], 'color' => $b['color'],
                'tier1' => $b['tier1'], 'popularity' => round($b['popularity']),
                'dry_grind' => $b['dry_grind'] ?? false,
                'reason' => $b['reason'] ?? 'no capacity this week',
                'pack_breakdown' => $b['pack_breakdown'],
            ], $unscheduled),
            'warnings'    => $warnings,
            'color_order' => $this->colorOrder,
        ];
    }

    /**
     * Try to place a batch on the best (mill, day). Mutates $millState.
     * Supports carryover: a batch may span consecutive enabled days.
     */
    private function placeBatch(array $batch, array &$millState, array $days): bool
    {
        $best = null;   // [millId, startDay, endDay, washupTier, completionKey]

        foreach ($millState as $millId => $st) {
            $m = $st['mill'];
            if (!empty($batch['dry_grind']) && empty($m['dry_grind_capable'])) {
                continue;   // dry-grind ink on a mill that can't dry grind
            }
            if ((float) $m['max_batch_lbs'] > 0 && $batch['lbs'] > (float) $m['max_batch_lbs']) {
                continue;   // batch too big for this mill
            }
            // Mode-specific throughput: dry grinding runs at its own rate
            $rate = !empty($batch['dry_grind'])
                ? (float) ($m['lbs_per_hour_dry'] ?? 0)
                : (float) $m['lbs_per_hour'];
            if ($rate <= 0) continue;

            // Washup before this batch on this mill
            $washTier = $this->washupTier($st['last_color'], $batch['color_idx']);
            $washHours = $this->washupHours($m, $washTier);

            $millHours = ($batch['lbs'] * $batch['passes']) / $rate;
            $totalHours = $millHours + $washHours;

            // Find earliest start day with SOME room, then consume across days
            for ($d = 0; $d < 7; $d++) {
                if ($st['remain'][$d] < self::MIN_SPLIT_HOURS) continue;

                // Simulate consumption from day $d forward
                $needed = $totalHours;
                $endDay = null;
                for ($e = $d; $e < 7 && $needed > 1e-9; $e++) {
                    if ($st['remain'][$e] <= 0) {
                        // A disabled/full day in the middle breaks the carryover chain
                        // only if nothing was consumed yet on later days — we allow
                        // skipping fully-booked or disabled days (production resumes
                        // next run day).
                        continue;
                    }
                    $take = min($st['remain'][$e], $needed);
                    $needed -= $take;
                    $endDay = $e;
                }
                if ($needed > 1e-9) {
                    break;   // doesn't fit starting at $d (or any later day — week full for this mill)
                }

                $completionKey = [$endDay, $d, $washTier];  // finish earliest, start earliest, least washup
                if ($best === null || $completionKey < $best['key']) {
                    $best = [
                        'mill_id' => $millId, 'start' => $d, 'end' => $endDay,
                        'wash_tier' => $washTier, 'wash_hours' => $washHours,
                        'mill_hours' => $millHours, 'key' => $completionKey,
                    ];
                }
                break;   // earliest feasible start on this mill found; try next mill
            }
        }

        if ($best === null) {
            return false;
        }

        // Commit: consume hours + append run rows (continuation rows flagged carryover)
        $st = &$millState[$best['mill_id']];
        $needed = $best['mill_hours'] + $best['wash_hours'];
        $first  = true;
        for ($e = $best['start']; $e < 7 && $needed > 1e-9; $e++) {
            if ($st['remain'][$e] <= 0) continue;
            $take = min($st['remain'][$e], $needed);
            $st['remain'][$e] -= $take;
            $needed -= $take;

            $st['runs'][$e][] = [
                'run_id'         => $batch['id'],
                'bulk'           => $batch['bulk'],
                'batch_no'       => $batch['batch_no'],
                'batch_count'    => $batch['batch_count'],
                'color'          => $batch['color'],
                'lbs'            => $batch['lbs'],
                'passes'         => $batch['passes'],
                'dry_grind'      => $batch['dry_grind'] ?? false,
                'tier1'          => $batch['tier1'],
                'carryover'      => !$first,
                'washup'         => $first ? $best['wash_tier'] : null,
                'pack_breakdown' => $batch['pack_breakdown'],
            ];
            $first = false;
        }

        $st['last_color'] = $batch['color_idx'];
        unset($st);
        return true;
    }

    /** Why couldn't this batch place anywhere — for the Unscheduled report. */
    private function unscheduledReason(array $batch, array $mills): string
    {
        $eligible = array_filter($mills, function ($m) use ($batch) {
            if (!empty($batch['dry_grind'])) {
                if (empty($m['dry_grind_capable'])) return false;
                if ((float) ($m['lbs_per_hour_dry'] ?? 0) <= 0) return false;
            } elseif ((float) $m['lbs_per_hour'] <= 0) {
                return false;
            }
            return true;
        });
        if (empty($eligible)) {
            return !empty($batch['dry_grind'])
                ? 'no dry-grind-capable mill (or dry rate not set)'
                : 'no mill with a usable throughput rate';
        }
        $fitsSize = array_filter($eligible, fn($m) =>
            (float) $m['max_batch_lbs'] <= 0 || $batch['lbs'] <= (float) $m['max_batch_lbs']
        );
        if (empty($fitsSize)) {
            return 'exceeds max batch size of every eligible mill';
        }
        return 'no capacity this week';
    }

    /** 'like' | 'next' | 'deep' | 'none' (first run of week on the mill) */
    private function washupTier(?int $lastColorIdx, int $newColorIdx): string
    {
        if ($lastColorIdx === null)         return 'none';
        if ($newColorIdx === $lastColorIdx) return 'like';
        if ($newColorIdx > $lastColorIdx)   return 'next';   // any forward move down the ladder
        return 'deep';                                        // backward / restart
    }

    private function washupHours(array $mill, string $tier): float
    {
        return match ($tier) {
            'like' => ((float) $mill['washup_like_minutes']) / 60,
            'next' => ((float) $mill['washup_next_minutes']) / 60,
            'deep' => ((float) $mill['washup_deep_minutes']) / 60,
            default => 0.0,
        };
    }
}
