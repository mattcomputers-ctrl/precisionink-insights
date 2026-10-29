<?php
/**
 * Open batch tickets and which order lines each is covering.
 * @var array $tickets  @var array $covering  @var ?string $asOf
 */
require __DIR__ . '/_subnav.php';

$today = date('Y-m-d');
$pill = function (array $t) use ($today): string {
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
                    <th>Ticket #</th><th>Type</th><th>Status</th><th>Item</th><th>Description</th>
                    <th class="text-right">Qty</th><th>Required</th><th>Cut for</th><th>Covering</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t):
                $overdue = $t['date_required'] !== null && $t['date_required'] < $today;
            ?>
                <tr>
                    <td><strong><?= (int) $t['ordr'] ?></strong></td>
                    <td><span class="tag"><?= e($t['context'] === 'MFBA' ? 'Bulk (MFBA)' : 'Packaging (MFPP)') ?></span></td>
                    <td class="nowrap"><?= $pill($t) ?></td>
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
