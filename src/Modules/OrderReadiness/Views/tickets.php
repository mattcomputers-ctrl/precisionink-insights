<?php
/**
 * Open batch tickets and which order lines each is covering.
 * @var array $tickets  @var array $covering  @var ?string $asOf
 * @var array $readiness  ticket ordr => materials check
 * @var array $matNames   item_code => description (for shortages)
 */
require __DIR__ . '/_subnav.php';

$today = date('Y-m-d');
$pill = function (array $t) use ($today): string {
    // NST is NORMAL for packaging — it only starts when its batch goes
    // into BAT (Matt, 2026-10-29) — so no warning on MFPP rows.
    if ($t['status'] === 'NST' && $t['context'] === 'MFPP') {
        return '<span class="pill" title="Packaging starts when the batch goes into BAT">⏸ Waits for the batch (NST)</span>';
    }
    $out = match ($t['status']) {
        'RLS' => '<span class="pill pill-active">✔ Released (RLS)</span>',
        'NST' => '<span class="pill pill-warn">⚠ Not released (NST)</span>',
        'BAT' => '<span class="pill pill-info">⏳ Closing out (BAT)</span>',
        default => '<span class="pill">' . e($t['status']) . '</span>',
    };
    if ($t['status'] === 'BAT' && $t['bat_since'] !== null
        && strtotime($t['bat_since']) < strtotime('-1 day')) {
        $out .= ' <span class="pill pill-danger">⚠ stuck in BAT &gt; 1 day</span>';
    }
    return $out;
};
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Open batch tickets</h2>
            <div class="card-subtitle">
                MFBA makes bulk ink; MFPP packages it. Inventory only moves when a ticket finishes
                (BAT/CMP) — until then these are future supply.
                <?php
                // Count MASTER BATCH numbers, not rows (Matt, 2026-10-29).
                $shortBatches = [];
                foreach ($tickets as $tt) {
                    $rr = $readiness[(int) $tt['ordr']] ?? null;
                    if ($rr !== null && $rr['status'] === 'short') {
                        $shortBatches[(int) ($tt['batch_ordr'] ?? $tt['ordr'])] = true;
                    }
                }
                $nShort = count($shortBatches);
                ?>
                <?php if ($nShort > 0): ?>
                    <strong class="text-danger"><?= $nShort ?> batch<?= $nShort === 1 ? ' is' : 'es are' ?> short the materials needed to make <?= $nShort === 1 ? 'it' : 'them' ?>.</strong>
                <?php endif; ?>
            </div>
        </div>
        <?php if (user_can('export')): ?>
            <a href="/orders/export/tickets" class="btn btn-sm" data-export="1">⬇ Excel</a>
        <?php endif; ?>
    </div>

    <?php if ($tickets === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span>No open batch tickets.</div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Ticket #</th><th>Type</th><th>Status</th><th>Materials</th><th>Item</th><th>Description</th>
                    <th class="text-right">Qty</th><th>Required</th><th>Cut for</th><th>Covering</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t):
                $overdue = $t['date_required'] !== null && $t['date_required'] < $today;
            ?>
                <tr>
                    <td>
                        <strong><?= (int) ($t['batch_ordr'] ?? $t['ordr']) ?></strong>
                        <?php if ($t['context'] === 'MFPP' && !empty($t['batch_ordr']) && (int) $t['batch_ordr'] !== (int) $t['ordr']): ?>
                            <div class="tag" title="Packaging order on the same batch ticket">pack <?= (int) $t['ordr'] ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="tag"><?= e($t['context'] === 'MFBA' ? 'Bulk (MFBA)' : 'Packaging (MFPP)') ?></span></td>
                    <td class="nowrap"><?= $pill($t) ?></td>
                    <td style="font-size:0.85rem;">
                        <?php $r = $readiness[(int) $t['ordr']] ?? null; ?>
                        <?php if ($r === null): ?>
                            <span class="text-muted" title="Materials check runs on the next sync">—</span>
                        <?php elseif ($t['context'] === 'MFPP' && $t['status'] === 'NST' && $r['status'] !== 'short'): ?>
                            <span class="text-muted" title="Checked once the packaging starts (when the batch goes into BAT)">— with the batch</span>
                        <?php elseif ($r['status'] === 'short'): ?>
                            <span class="pill pill-danger">⚠ Short materials</span>
                            <?php foreach ($r['shortages'] as $s): ?>
                                <div class="nowrap" title="<?= e((string) ($matNames[$s['item_code']] ?? '')) ?> — needs <?= fmt_number((float) $s['need'], 1) ?> <?= e($s['unit']) ?>">
                                    <?= e($s['item_code']) ?> <span class="text-danger">−<?= fmt_number((float) $s['short'], 1) ?> <?= e($s['unit']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="pill pill-active" title="<?= !empty($r['from_mfba']) ? 'Bulk comes from this batch; everything else is' : 'All materials are' ?> on hand">✔ On hand</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e($t['item_code']) ?><?= $t['bulk_code'] ? '<div class="tag" title="Bulk consumed">' . e($t['bulk_code']) . '</div>' : '' ?></td>
                    <td><?= e((string) ($t['description'] ?? '')) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $t['qty'], 1) ?> <?= e($t['unit']) ?></td>
                    <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= e(fmt_date($t['date_required'])) ?><?= $overdue ? ' ⚠' : '' ?></td>
                    <td class="text-muted"><?= e((string) ($t['customer_hint'] ?? '')) ?></td>
                    <td class="text-muted" style="font-size:0.85rem;">
                        <?php if (!empty($covering[(int) $t['ordr']])): ?>
                            <?php foreach ($covering[(int) $t['ordr']] as $cv): ?>
                                <div>Order <?= (int) $cv['ordr'] ?> (<?= e((string) $cv['bill_to_name']) ?>): <?= fmt_number($cv['qty'], 1) ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span>— builds stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
