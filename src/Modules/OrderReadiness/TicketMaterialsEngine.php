<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

/**
 * TicketMaterialsEngine — can each open batch ticket actually be made
 * with the materials on hand? (Matt, 2026-10-29.)
 *
 * Pure function, same spirit as CoverageEngine: tickets claim shared
 * inventory in due-date order (earliest DateRequired first, then ticket
 * number), and a pound of material never counts toward two tickets.
 *
 * An MFPP's one material line is the bulk it packages; that bulk comes
 * from its linked MFBA batch first (that is what the batch was cut for),
 * and only the remainder competes for on-hand stock. An MFBA whose own
 * raw materials are short poisons its MFPPs too (mfba_short).
 *
 * Input:
 *   tickets:   [['ordr','context','batch_ordr','bulk_code','date_required','qty']]
 *   materials: [ordr => [item_code => ['qty' => float, 'unit' => string]]]
 *   inventory: [item_code => float available]
 *
 * Output: [ordr => ['status' => 'ok'|'short',
 *                   'shortages' => [{item_code, need, short, unit}],
 *                   'from_mfba' => float, 'mfba_short' => 0|1]]
 */
class TicketMaterialsEngine
{
    private const EPS = 1e-6;

    public static function compute(array $tickets, array $materials, array $inventory): array
    {
        $invPool = [];
        foreach ($inventory as $code => $qty) {
            $invPool[$code] = max(0.0, (float) $qty);
        }

        // Each open MFBA's output pool: the bulk its MFPPs may draw on.
        $mfbaOut = [];
        foreach ($tickets as $t) {
            if ($t['context'] === 'MFBA') {
                $mfbaOut[(int) $t['ordr']] = max(0.0, (float) $t['qty']);
            }
        }

        $sorted = $tickets;
        usort($sorted, fn($a, $b) =>
            [$a['date_required'] ?? '9999-12-31', $a['ordr']] <=> [$b['date_required'] ?? '9999-12-31', $b['ordr']]);

        $results = [];
        foreach ($sorted as $t) {
            $ordr = (int) $t['ordr'];
            $shortages = [];
            $fromMfba = 0.0;

            foreach ($materials[$ordr] ?? [] as $code => $m) {
                $need = max(0.0, (float) $m['qty']);
                if ($need <= self::EPS) {
                    continue;
                }
                $covered = 0.0;

                // MFPP bulk line: its own batch's output first.
                if ($t['context'] === 'MFPP' && $code === ($t['bulk_code'] ?? null)) {
                    $batch = (int) ($t['batch_ordr'] ?? 0);
                    if ($batch !== 0 && ($mfbaOut[$batch] ?? 0.0) > self::EPS) {
                        $take = min($need, $mfbaOut[$batch]);
                        $mfbaOut[$batch] -= $take;
                        $covered += $take;
                        $fromMfba += $take;
                    }
                }

                if ($need - $covered > self::EPS && ($invPool[$code] ?? 0.0) > self::EPS) {
                    $take = min($need - $covered, $invPool[$code]);
                    $invPool[$code] -= $take;
                    $covered += $take;
                }

                if ($need - $covered > self::EPS) {
                    $shortages[] = [
                        'item_code' => $code,
                        'need'      => round($need, 4),
                        'short'     => round($need - $covered, 4),
                        'unit'      => (string) ($m['unit'] ?? ''),
                    ];
                }
            }

            $results[$ordr] = [
                'status'     => $shortages === [] ? 'ok' : 'short',
                'shortages'  => $shortages,
                'from_mfba'  => round($fromMfba, 4),
                'mfba_short' => 0,
            ];
        }

        // Chain: a packaging ticket whose batch can't be mixed is in trouble
        // even when its own bulk line looks covered by that batch's output.
        foreach ($tickets as $t) {
            if ($t['context'] !== 'MFPP') {
                continue;
            }
            $batch = (int) ($t['batch_ordr'] ?? 0);
            if ($batch !== 0 && ($results[$batch]['status'] ?? '') === 'short') {
                $results[(int) $t['ordr']]['mfba_short'] = 1;
            }
        }

        return $results;
    }
}
