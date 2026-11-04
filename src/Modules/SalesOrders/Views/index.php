<?php
/**
 * Sales order browser (live CMS).
 * @var string $q  @var string $tab  @var array $orders
 * @var bool $truncated  @var ?string $error  @var ?string $asOf
 */
$pill = fn(string $s): string => match ($s) {
    'RTS' => '<span class="pill pill-active">Open (RTS)</span>',
    'CSH' => '<span class="pill pill-warn">Credit hold (CSH)</span>',
    'CMP' => '<span class="pill pill-info">Shipped (CMP)</span>',
    'CLS' => '<span class="pill">Closed (CLS)</span>',
    default => '<span class="pill">' . e($s) . '</span>',
};
$tabs = ['open' => 'Open', 'completed' => 'Completed', 'all' => 'All'];
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Sales Orders</h2>
            <div class="card-subtitle">Live from CMS — headers, lines, shipments and invoice numbers.</div>
        </div>
        <div class="flex gap-1">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="/sales-orders?tab=<?= e($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="/sales-orders" class="flex gap-1">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Order #, customer, PO #, item…" style="min-width:240px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($orders === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span><?= $q !== '' ? 'No orders match your search.' : 'No orders in this tab.' ?></div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Showing the latest <?= count($orders) ?> orders — narrow the search for older ones.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order #</th><th>Status</th><th>Customer</th><th>Ship to</th><th>PO #</th>
                        <th>Ordered</th><th>Required</th><th class="text-right">Lines</th><th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><a href="/sales-orders/<?= (int) $o['Ordr'] ?>"><strong><?= (int) $o['Ordr'] ?></strong></a></td>
                        <td class="nowrap"><?= $pill((string) $o['Status']) ?></td>
                        <td><?= e((string) ($o['BillToName'] ?: $o['BillTo'])) ?></td>
                        <td class="text-muted"><?= e((string) ($o['ShipToName'] ?? '')) ?></td>
                        <td class="text-muted"><?= e((string) ($o['PoNumber'] ?? '')) ?></td>
                        <td><?= e(fmt_date($o['DateOrdered'])) ?></td>
                        <td><?= e(fmt_date($o['DateRequired'])) ?></td>
                        <td class="text-right tabular"><?= (int) $o['line_count'] ?></td>
                        <td class="text-right tabular"><?= $o['amount'] !== null ? fmt_money((float) $o['amount']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
