<?php
/**
 * One batch ticket: header, batch sheet (formula + procedure + issued),
 * produced lots, their QC results, linked tickets (live CMS).
 * @var int $ordr  @var ?array $head  @var array $lines  @var array $lots
 * @var array $qc  @var array $linked  @var ?string $error  @var ?string $asOf
 */
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/batch-tickets" class="btn btn-sm">← Batch Tickets</a>
    <button type="button" class="btn btn-sm" onclick="window.print()">🖨 Print</button>
</div>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">
                <?= e($head['Context'] === 'MFBA' ? 'Batch' : 'Packaging') ?> ticket <?= (int) $ordr ?>
                <?php echo match ($head['Status']) {
                    'RLS' => '<span class="pill pill-active">Released</span>',
                    'NST' => '<span class="pill">Not started</span>',
                    'BAT' => '<span class="pill pill-info">Closing out</span>',
                    'CMP' => '<span class="pill pill-info">Completed</span>',
                    'CLS' => '<span class="pill">Closed</span>',
                    default => '<span class="pill">' . e((string) $head['Status']) . '</span>',
                }; ?>
            </h2>
            <div class="card-subtitle">
                Makes <?= item_ctx((string) $head['ItemCode']) ?>
                <?= $head['Description'] ? '— ' . e((string) $head['Description']) : '' ?>
                · <?= fmt_number((float) $head['Qty'], 1) ?> <?= e((string) $head['Unit']) ?>
                <br>
                <?php if ($head['DateRequired']): ?>required <?= e(fmt_date($head['DateRequired'])) ?><?php endif; ?>
                <?php if ($head['DateReleased']): ?> · released <?= e(fmt_date($head['DateReleased'])) ?><?php endif; ?>
                <?php if ($head['DateScheduled']): ?> · scheduled <?= e(fmt_date($head['DateScheduled'])) ?><?php endif; ?>
                <?php if ($head['DateCompleted']): ?> · completed <?= e(fmt_date($head['DateCompleted'])) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!empty($head['Comment'])): ?>
        <div class="alert alert-info" style="white-space:pre-wrap;"><?= e((string) $head['Comment']) ?></div>
    <?php endif; ?>

    <?php if ($linked !== []): ?>
        <div style="margin-bottom:0.75rem;font-size:0.9rem;">
            <?php foreach ($linked as $ln): ?>
                <?php if ((int) $ln['DemandOrdr'] === $ordr): ?>
                    <div>Runs off batch <a href="/batch-tickets/<?= (int) $ln['SupplyOrdr'] ?>"><strong><?= (int) $ln['SupplyOrdr'] ?></strong></a>
                        <?php if ($ln['CustomerCode']): ?><span class="text-muted">· cut for <?= e((string) $ln['CustomerCode']) ?></span><?php endif; ?></div>
                <?php else: ?>
                    <div>Packaging run: <a href="/batch-tickets/<?= (int) $ln['DemandOrdr'] ?>"><strong><?= (int) $ln['DemandOrdr'] ?></strong></a>
                        <?php if ($ln['MakeItemCode']): ?><span class="text-muted">(<?= e((string) $ln['MakeItemCode']) ?>)</span><?php endif; ?>
                        <?php if ($ln['CustomerCode']): ?><span class="text-muted">· cut for <?= e((string) $ln['CustomerCode']) ?></span><?php endif; ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 style="margin:0.5rem 0 0.4rem;">Batch sheet</h3>
    <?php if ($lines === []): ?>
        <div class="empty-state"><span class="empty-icon">📄</span>No lines on this ticket.</div>
    <?php else: ?>
    <?php $step = 0; ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th style="width:80px;" class="text-right">%</th><th>Ingredient / step</th><th>Description</th>
                    <th class="text-right">Required</th><th class="text-right">Issued</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <?php if ($l['Context'] === 'INSTR'): ?>
                    <tr class="recipe-step">
                        <td class="text-right text-muted tabular"><?= ++$step ?>.</td>
                        <td colspan="4" style="font-style:italic;"><?= e((string) ($l['InstrText'] ?? '')) ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td class="text-right tabular"><?= $l['Percent'] !== null ? rtrim(rtrim(number_format((float) $l['Percent'] * 100, 3, '.', ','), '0'), '.') : '' ?></td>
                        <td class="nowrap"><?= item_ctx((string) ($l['ItemCode'] ?? '')) ?></td>
                        <td><?= e((string) ($l['ItemDescription'] ?? '')) ?></td>
                        <td class="text-right tabular"><?= $l['QtyReqd'] !== null ? fmt_number((float) $l['QtyReqd'], 2) . ' ' . e((string) $l['Unit']) : '—' ?></td>
                        <td class="text-right tabular <?= $l['QtyUsed'] !== null && $l['QtyReqd'] !== null && abs((float) $l['QtyUsed'] - (float) $l['QtyReqd']) > 0.005 ? 'text-danger' : '' ?>"
                            <?= $l['QtyUsed'] !== null && $l['QtyReqd'] !== null && abs((float) $l['QtyUsed'] - (float) $l['QtyReqd']) > 0.005 ? 'title="Issued differs from required"' : '' ?>>
                            <?= $l['QtyUsed'] !== null ? fmt_number((float) $l['QtyUsed'], 2) : '<span class="text-muted">—</span>' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">Produced lots &amp; QC</h2>
    <?php if ($lots === []): ?>
        <div class="empty-state"><span class="empty-icon">🏷</span>No lots produced yet.</div>
    <?php else: ?>
        <?php
        $qcByLot = [];
        foreach ($qc as $r) {
            $qcByLot[(string) $r['Lot']][] = $r;
        }
        ?>
        <?php foreach ($lots as $lot): ?>
            <h3 style="margin:0.75rem 0 0.3rem;">Lot <?= e((string) $lot['Lot']) ?>
                <span class="text-muted" style="font-weight:normal;font-size:0.85rem;">
                    <?= e((string) ($lot['ItemCode'] ?? '')) ?><?= $lot['ManfDate'] ? ' · made ' . e(fmt_date($lot['ManfDate'])) : '' ?>
                </span>
            </h3>
            <?php $results = $qcByLot[(string) $lot['Lot']] ?? []; ?>
            <?php if ($results === []): ?>
                <p class="text-muted" style="font-size:0.9rem;">No QC results recorded for this lot.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead><tr><th>Test</th><th>Result</th><th>Pass</th><th>Tested</th><th>By</th><th>Comment</th></tr></thead>
                        <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><?= e((string) $r['TestName']) ?></td>
                                <td class="tabular"><?= e((string) ($r['Result'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= ((int) $r['Passed']) === 1 ? '<span class="pill pill-active">✔ pass</span>' : '<span class="pill pill-danger">✖ fail</span>' ?></td>
                                <td><?= e(fmt_date($r['TestedTime'], 'M j, g:i A')) ?></td>
                                <td class="text-muted"><?= e((string) ($r['TestedBy'] ?? '')) ?></td>
                                <td class="text-muted"><?= e((string) ($r['Comment'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
