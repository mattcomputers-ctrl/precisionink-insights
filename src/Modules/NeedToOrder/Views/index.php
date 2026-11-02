<?php
/**
 * Need to Order — the buy list. Flat (priority order) or grouped by
 * preferred vendor / last-purchased-from vendor (Matt, 2026-11-02).
 * @var array $rows  @var array $groups  @var string $groupBy  ''|'preferred'|'last'
 * @var string $search  @var ?string $asOf
 */
$today = date('Y-m-d');
$qs = fn(array $extra) => '/need-to-order?' . http_build_query(array_filter(
    ['q' => $search, 'group' => $groupBy] + $extra, fn($v) => $v !== ''));

$renderRows = function (array $list) use ($today): void {
    foreach ($list as $r):
        $overdue = $r['first_needed'] !== null && $r['first_needed'] < $today;
    ?>
        <tr>
            <td class="nowrap"><strong><?= e($r['item_code']) ?></strong>
                <?php if ((int) $r['has_recipe'] === 1): ?>
                    <div class="tag" title="This item has a recipe — it can also be made in-house instead of bought">can be made</div>
                <?php endif; ?>
            </td>
            <td><?= e($r['description']) ?></td>
            <td class="nowrap">
                <?php if (!empty($r['preferred_vendor'])): ?>
                    <?= e($r['preferred_vendor']) ?>
                <?php else: ?>
                    <span class="text-muted" title="No preferred vendor set on the item in CMS">—</span>
                <?php endif; ?>
            </td>
            <td class="nowrap">
                <?php if ($r['vendor'] !== null && $r['vendor'] !== ''): ?>
                    <?= e($r['vendor']) ?>
                    <?php if ($r['vendor_code']): ?><div class="tag" title="Their item code on the last PO"><?= e($r['vendor_code']) ?></div><?php endif; ?>
                    <?php if ((int) $r['vendor_count'] > 1): ?><div class="text-muted" style="font-size:0.78rem;"><?= (int) $r['vendor_count'] ?> vendors in history</div><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted" title="Never purchased — no vendor on file">—</span>
                <?php endif; ?>
            </td>
            <td class="text-right tabular text-danger"><strong><?= fmt_number((float) $r['qty_needed'], 1) ?></strong> <?= e($r['unit']) ?></td>
            <td class="text-right tabular"><?= (float) $r['on_po'] > 0 ? fmt_number((float) $r['on_po'], 1) : '<span class="text-muted">—</span>' ?></td>
            <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= e(fmt_date($r['first_needed'])) ?><?= $overdue ? ' ⚠' : '' ?></td>
            <td class="nowrap">
                <?php if ((int) $r['src_ticket'] === 1): ?><span class="pill pill-danger" title="An open batch ticket is waiting on this">ticket</span><?php endif; ?>
                <?php if ((int) $r['src_order'] === 1): ?><span class="pill pill-info" title="A batch still to be cut for an open sales order needs this">new batch</span><?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:0.85rem;">
                <?php foreach (array_slice($r['refs_list'], 0, 6) as $ref): ?>
                    <div class="nowrap"><?= e($ref['ref']) ?></div>
                <?php endforeach; ?>
                <?php if (count($r['refs_list']) > 6): ?>
                    <div>… and <?= count($r['refs_list']) - 6 ?> more</div>
                <?php endif; ?>
                <?php if ($r['via_list'] !== []): ?>
                    <div title="This material is consumed by making these in-house items">to make <?= e(implode(' → ', $r['via_list'])) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
};
$thead = '<tr>'
    . '<th>Material</th><th>Description</th><th>Preferred vendor</th><th>Last purchased from</th>'
    . '<th class="text-right">To order</th><th class="text-right">On open POs</th>'
    . '<th>First needed</th><th>Why</th><th>Needed by</th></tr>';
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Need to Order</h2>
            <div class="card-subtitle">
                Buy this and every open ticket — and every batch still to be cut for open orders — can run.
                Quantities are already netted against stock and open tickets; nothing is counted twice.
                <?= $asOf !== null ? 'Data as of ' . e(fmt_date($asOf, 'M j, g:i A')) . '.' : '' ?>
            </div>
        </div>
        <form method="GET" action="/need-to-order" class="flex gap-1">
            <?php if ($groupBy !== ''): ?><input type="hidden" name="group" value="<?= e($groupBy) ?>"><?php endif; ?>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Material, vendor or vendor code…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
        <div class="flex gap-1 items-center">
            <a href="<?= e($qs(['group' => $groupBy === 'preferred' ? '' : 'preferred'])) ?>"
               class="btn btn-sm <?= $groupBy === 'preferred' ? 'btn-primary' : '' ?>"><?= $groupBy === 'preferred' ? '✔ ' : '' ?>By preferred vendor</a>
            <a href="<?= e($qs(['group' => $groupBy === 'last' ? '' : 'last'])) ?>"
               class="btn btn-sm <?= $groupBy === 'last' ? 'btn-primary' : '' ?>"><?= $groupBy === 'last' ? '✔ ' : '' ?>By last vendor</a>
            <?php if (user_can('export')): ?>
                <a href="<?= e('/need-to-order/export?' . http_build_query(array_filter(['q' => $search, 'group' => $groupBy]))) ?>"
                   class="btn btn-sm" data-export="1">⬇ Excel</a>
            <?php endif; ?>
            <?= sync_now_button() ?>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state">
            <span class="empty-icon">✅</span>
            <?= $search !== ''
                ? 'Nothing on the buy list matches your search.'
                : ($asOf === null ? 'No data yet — run a sync.' : 'Nothing to order — every open ticket and every pending batch is covered.') ?>
        </div>
    <?php elseif ($groupBy !== ''): ?>
        <?php foreach ($groups as $vendor => $list): ?>
            <h3 style="margin:1rem 0 0.4rem;">
                <?= $vendor === ''
                    ? ($groupBy === 'preferred' ? '— No preferred vendor set' : '— Never purchased')
                    : e($vendor) ?>
                <span class="text-muted" style="font-weight:normal;font-size:0.85rem;"><?= count($list) ?> item<?= count($list) === 1 ? '' : 's' ?></span>
            </h3>
            <div class="table-scroll">
                <table class="table">
                    <thead><?= $thead ?></thead>
                    <tbody><?php $renderRows($list); ?></tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><?= $thead ?></thead>
                <tbody><?php $renderRows($rows); ?></tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
