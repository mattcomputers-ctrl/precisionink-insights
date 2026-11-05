<?php

declare(strict_types=1);

/**
 * ONE-TIME backfill (Matt, 2026-11-05): for every item already in
 * sched_item_config, pull the batch sizes it has actually been made at
 * (most common planned quantities on its past MFBA tickets) and fill
 * batch_size_1..5 — largest first, so batch_size_1 stays the primary.
 *
 * Items with no batch history are left exactly as they are, as are
 * not-milled items (their sizes are placeholders and never schedule).
 *
 * Usage (on the server, from the app root):
 *   php bin/backfill-batch-sizes.php --dry-run   # show what would change
 *   php bin/backfill-batch-sizes.php             # apply
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use PII\Core\App;
use PII\Core\Database;
use PII\Modules\Scheduling\SchedulingDataService;

App::bootConsole();

$dryRun = in_array('--dry-run', $argv, true);
$db  = Database::getInstance();
$svc = new SchedulingDataService();

$configs = $db->fetchAll("SELECT * FROM sched_item_config ORDER BY bulk_item_code");
echo count($configs) . " configured item(s)" . ($dryRun ? ' — DRY RUN, nothing will be written' : '') . "\n\n";

$updated = 0;
$noHistory = 0;
$skippedNotMilled = 0;

foreach ($configs as $c) {
    $code = (string) $c['bulk_item_code'];

    if (!empty($c['not_milled'])) {
        $skippedNotMilled++;
        echo "  {$code}: not milled — skipped\n";
        continue;
    }

    $history = $svc->historicalBatchSizes($code);
    if ($history === []) {
        $noHistory++;
        echo "  {$code}: no batch history — left as is\n";
        continue;
    }

    $sizes = array_column($history, 'lbs');
    rsort($sizes);

    $old = [];
    for ($i = 1; $i <= 5; $i++) {
        $v = $c['batch_size_' . $i] ?? null;
        if ($v !== null && (float) $v > 0) {
            $old[] = round((float) $v);
        }
    }

    $fmt = fn(array $a) => $a === [] ? '(none)' : implode('/', array_map(fn($v) => (string) round((float) $v), $a));
    $evidence = implode(' · ', array_map(fn($h) => round($h['lbs']) . "×{$h['n']}", $history));
    echo "  {$code}: {$fmt($old)} -> {$fmt($sizes)}   [{$evidence}]\n";

    if (!$dryRun) {
        $db->update('sched_item_config', [
            'batch_size_1' => $sizes[0],
            'batch_size_2' => $sizes[1] ?? null,
            'batch_size_3' => $sizes[2] ?? null,
            'batch_size_4' => $sizes[3] ?? null,
            'batch_size_5' => $sizes[4] ?? null,
        ], 'bulk_item_code = ?', [$code]);
    }
    $updated++;
}

echo "\n" . ($dryRun ? 'Would update' : 'Updated') . " {$updated} item(s); "
    . "{$noHistory} with no history left untouched; {$skippedNotMilled} not-milled skipped.\n";
