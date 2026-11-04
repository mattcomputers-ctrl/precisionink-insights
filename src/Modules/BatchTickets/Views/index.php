<?php
/**
 * Batch ticket browser (live CMS, full history).
 * @var string $q  @var string $tab  @var array $tickets
 * @var bool $truncated  @var ?string $error  @var ?string $asOf
 */
$pill = fn(string $s): string => match ($s) {
    'RLS' => '<span class="pill pill-active">Released (RLS)</span>',
    'NST' => '<span class="pill">Not started (NST)</span>',
    'BAT' => '<span class="pill pill-info">Closing out (BAT)</span>',
    'CMP' => '<span class="pill pill-info">Completed (CMP)</span>',
    'CLS' => '<span class="pill">Closed (CLS)</span>',
    default => '<span class="pill">' . e($s) . '</span>',
};
$tabs = ['open' => 'Open', 'completed' => 'Completed', 'all' => 'All'];
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Batch Tickets</h2>
            <div class="card-subtitle">
                Every ticket ever cut, live from CMS. For "what's open and can it run", use
                <?php if (user_can('orders.tickets_view')): ?><a href="/orders/tickets">Order Readiness → Batch tickets</a><?php else: ?>Order Readiness<?php endif; ?>.
            </div>
        </div>
        <div class="flex gap-1">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="/batch-tickets?tab=<?= e($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="/batch-tickets" class="flex gap-1">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Ticket #, item, lot #…" style="min-width:240px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($tickets === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span><?= $q !== '' ? 'No tickets match your search.' : 'No tickets in this tab.' ?></div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Showing the latest <?= count($tickets) ?> tickets — narrow the search for older ones.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ticket #</th><th>Type</th><th>Status</th><th>Makes</th><th>Description</th>
                        <th class="text-right">Qty</th><th>Required</th><th>Completed</th><th>Cut for</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td class="nowrap">
                            <a href="/batch-tickets/<?= (int) $t['Ordr'] ?>"><strong><?= (int) $t['Ordr'] ?></strong></a>
                            <?php if ($t['BatchOrdr'] !== null && (int) $t['BatchOrdr'] !== (int) $t['Ordr']): ?>
                                <a class="tag" href="/batch-tickets/<?= (int) $t['BatchOrdr'] ?>" title="The bulk batch this pack runs off">batch <?= (int) $t['BatchOrdr'] ?></a>
                            <?php endif; ?>
                        </td>
                        <td><span class="tag"><?= e($t['Context'] === 'MFBA' ? 'Bulk' : 'Packaging') ?></span></td>
                        <td class="nowrap"><?= $pill((string) $t['Status']) ?></td>
                        <td class="nowrap"><?= item_ctx((string) $t['ItemCode']) ?></td>
                        <td><?= e((string) ($t['Description'] ?? '')) ?></td>
                        <td class="text-right tabular"><?= $t['Qty'] !== null ? fmt_number((float) $t['Qty'], 1) . ' ' . e((string) $t['Unit']) : '—' ?></td>
                        <td><?= e(fmt_date($t['DateRequired'])) ?></td>
                        <td><?= e(fmt_date($t['DateCompleted'])) ?></td>
                        <td class="text-muted"><?= e((string) ($t['CustomerCode'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
