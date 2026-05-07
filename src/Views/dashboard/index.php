<?php
/**
 * @var \PII\Core\Module[] $modules
 * @var array|null $shipments    yesterdayShipments() result, or null
 * @var array|null $inventory    yesterdayInventory() result, or null
 * @var string|null $cmsError    set if CMS query failed or is unconfigured
 */
$user = $_SESSION['_user'] ?? null;
$dateLabel = $shipments
    ? fmt_date($shipments['date'], 'l, F j, Y')
    : fmt_date(date('Y-m-d', strtotime('-1 day')), 'l, F j, Y');
?>

<?php if ($cmsError): ?>
    <div class="alert alert-warning">
        <strong>CMS unavailable —</strong> dashboard metrics couldn't load. <span class="text-muted">(<?= e($cmsError) ?>)</span>
    </div>
<?php endif; ?>

<?php if ($shipments !== null || $inventory !== null): ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Yesterday — <?= e($dateLabel) ?></h2>
            <div class="card-subtitle">Snapshot of revenue, margin, and inventory as of end of day</div>
        </div>
    </div>

    <div class="stat-grid">
        <?php if ($shipments !== null): ?>
            <div class="stat-card">
                <div class="stat-label">Revenue</div>
                <div class="stat-value"><?= fmt_money($shipments['revenue']) ?></div>
                <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                    <?= fmt_number($shipments['lines']) ?> shipment line<?= $shipments['lines'] === 1 ? '' : 's' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Packed Cost % of Sales</div>
                <div class="stat-value">
                    <?= $shipments['cost_pct'] === null ? '—' : fmt_pct($shipments['cost_pct'], 1) ?>
                </div>
                <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                    Cost: <?= fmt_money($shipments['cost']) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($inventory !== null): ?>
            <div class="stat-card">
                <div class="stat-label">Inventory Total</div>
                <div class="stat-value"><?= fmt_money($inventory['total_value'], 0) ?></div>
                <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                    Replacement-cost basis · <?= count($inventory['by_gl_group']) ?> GL groups
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($inventory !== null && !empty($inventory['by_gl_group'])): ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inventory by GL Group</h2>
            <div class="card-subtitle">As of <?= e($dateLabel) ?> · replacement-cost basis</div>
        </div>
    </div>

    <table class="table tabular">
        <thead>
            <tr>
                <th>GL Group</th>
                <th class="text-right">Quantity</th>
                <th class="text-right">Replacement Value</th>
                <th class="text-right" style="width:160px;">% of Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($inventory['by_gl_group'] as $g): ?>
            <tr>
                <td><strong><?= e($g['gl_group']) ?></strong></td>
                <td class="text-right"><?= fmt_number($g['qty'], 0) ?></td>
                <td class="text-right"><?= fmt_money($g['value'], 0) ?></td>
                <td class="text-right">
                    <div style="display:flex;align-items:center;gap:0.5rem;justify-content:flex-end;">
                        <span style="display:inline-block;width:80px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                            <span style="display:block;height:100%;width:<?= number_format(min(100, $g['pct_of_total']), 2) ?>%;background:var(--primary);"></span>
                        </span>
                        <span style="min-width:48px;text-align:right;"><?= fmt_pct($g['pct_of_total'], 1) ?></span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:600;background:var(--bg-elev2);">
                <td>Total</td>
                <td class="text-right"><?= fmt_number($inventory['total_qty'], 0) ?></td>
                <td class="text-right"><?= fmt_money($inventory['total_value'], 0) ?></td>
                <td class="text-right">100.0%</td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Welcome, <?= e($user['display_name'] ?: $user['username']) ?></h2>
            <div class="card-subtitle">Choose an analytics tool from the tabs above, or click a card below.</div>
        </div>
    </div>

    <?php if (empty($modules)): ?>
        <p class="muted-empty">No analytics tools are currently available to your account. Contact an administrator if you believe this is wrong.</p>
    <?php else: ?>
        <div class="stat-grid">
            <?php foreach ($modules as $m): ?>
                <a href="<?= e($m->basePath()) ?>" class="stat-card" style="text-decoration:none;display:block;cursor:pointer;">
                    <div class="stat-label">
                        <?php if ($m->icon() !== ''): ?><?= $m->icon() ?> <?php endif; ?>
                        <?= e($m->name()) ?>
                    </div>
                    <div class="stat-value" style="font-size:1.05rem;font-weight:600;color:var(--text);">
                        Open →
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
