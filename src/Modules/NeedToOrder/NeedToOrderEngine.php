<?php

declare(strict_types=1);

namespace PII\Modules\NeedToOrder;

/**
 * NeedToOrderEngine — what has to be BOUGHT so that (a) every open batch
 * ticket can run and (b) the batches that still need to be cut for open
 * sales orders can run too (Matt, 2026-11-02). Pure function.
 *
 * Demands explode level by level, MRP-style:
 *   - net each item against remaining stock, then against net open-ticket
 *     output (a batch already cut supplies its bulk);
 *   - an item we BUY (purchase history) — or that has no recipe — is a
 *     leaf: it goes on the buy list;
 *   - an item we MAKE (recipe, never purchased) becomes a batch of its
 *     own: its remaining need is rounded up to the standard batch size
 *     (when known) and exploded into its ingredients.
 * A pound of stock or ticket output never satisfies two demands, and the
 * root reference (which batch / which order) rides along so the buyer
 * can see WHY each material is on the list.
 *
 * Input:
 *   demands:    [['item','qty','due' => ?Y-m-d,'ref' => label,'src' => 'ticket'|'order']]
 *   stock:      [item => qty]   leftover usable AFTER open-ticket claims
 *   incoming:   [item => qty]   net open-ticket output per item (>0 only)
 *   recipes:    [item => [['item' => ingredient, 'qty_per' => float], …]]
 *               (qty_per is per 1 unit of the parent)
 *   buyable:    [item => true]  items with any purchase history
 *   batchSizes: [item => standard batch size]
 *
 * Output: [item => ['qty','due','srcs' => ['ticket'=>bool,'order'=>bool],
 *                   'refs' => list<['ref','src']>, 'via' => list<string>]]
 */
class NeedToOrderEngine
{
    private const EPS = 1e-6;
    private const MAX_DEPTH = 6;

    public static function compute(
        array $demands,
        array $stock,
        array $incoming,
        array $recipes,
        array $buyable,
        array $batchSizes = []
    ): array {
        $pool = [];
        foreach ($stock as $code => $q) {
            $pool[$code] = max(0.0, (float) $q);
        }
        $supply = [];
        foreach ($incoming as $code => $q) {
            $supply[$code] = max(0.0, (float) $q);
        }

        // Aggregate the roots per item, earliest due first so netting is fair.
        $frontier = [];
        foreach ($demands as $d) {
            self::merge($frontier, $d['item'], (float) $d['qty'], $d['due'] ?? null,
                [['ref' => (string) $d['ref'], 'src' => (string) $d['src']]], []);
        }

        $out = [];
        for ($depth = 0; $frontier !== [] && $depth < self::MAX_DEPTH; $depth++) {
            uasort($frontier, fn($a, $b) => [$a['due'] ?? '9999'] <=> [$b['due'] ?? '9999']);
            $next = [];
            foreach ($frontier as $code => $d) {
                $need = $d['qty'];

                // 1) leftover stock, 2) output already coming on open tickets.
                if ($need > self::EPS && ($pool[$code] ?? 0.0) > self::EPS) {
                    $take = min($need, $pool[$code]);
                    $pool[$code] -= $take;
                    $need -= $take;
                }
                if ($need > self::EPS && ($supply[$code] ?? 0.0) > self::EPS) {
                    $take = min($need, $supply[$code]);
                    $supply[$code] -= $take;
                    $need -= $take;
                }
                if ($need <= self::EPS) {
                    continue;
                }

                if (isset($buyable[$code]) || empty($recipes[$code])) {
                    self::merge($out, $code, $need, $d['due'], $d['refs'], $d['via']);
                    continue;
                }

                // Made in-house: this is a batch to cut — explode it.
                $gross = $need;
                if (($batchSizes[$code] ?? 0.0) > self::EPS) {
                    $gross = ceil($need / $batchSizes[$code] - self::EPS) * $batchSizes[$code];
                }
                $via = array_values(array_unique(array_merge($d['via'], [$code])));
                foreach ($recipes[$code] as $ing) {
                    if ($ing['item'] === $code) {
                        continue;   // self-reference guard (PK lines etc.)
                    }
                    self::merge($next, $ing['item'], $gross * (float) $ing['qty_per'], $d['due'], $d['refs'], $via);
                }
            }
            $frontier = $next;
        }

        foreach ($out as &$o) {
            $o['qty'] = round($o['qty'], 4);
            $o['refs'] = array_values($o['refs']);
            $o['srcs'] = ['ticket' => false, 'order' => false];
            foreach ($o['refs'] as $r) {
                $o['srcs'][$r['src']] = true;
            }
        }
        unset($o);
        return $out;
    }

    private static function merge(array &$bag, string $code, float $qty, ?string $due, array $refs, array $via): void
    {
        if ($qty <= self::EPS) {
            return;
        }
        if (!isset($bag[$code])) {
            $bag[$code] = ['qty' => 0.0, 'due' => null, 'refs' => [], 'via' => []];
        }
        $bag[$code]['qty'] += $qty;
        if ($due !== null && ($bag[$code]['due'] === null || $due < $bag[$code]['due'])) {
            $bag[$code]['due'] = $due;
        }
        foreach ($refs as $r) {
            $key = $r['src'] . '|' . $r['ref'];
            $bag[$code]['refs'][$key] = $r;
        }
        $bag[$code]['via'] = array_values(array_unique(array_merge($bag[$code]['via'], $via)));
    }
}
