<?php
/**
 * @var string $appNameValue    current DB override (empty if not set)
 * @var string $configAppName   the config.php fallback value
 * @var array  $snapshotInfo    ['latest' => YYYY-MM-DD|null, 'earliest' => YYYY-MM-DD|null, 'days_captured' => int]
 */
$daysCaptured = (int) ($snapshotInfo['days_captured'] ?? 0);
$latest       = $snapshotInfo['latest']   ?? null;
$earliest     = $snapshotInfo['earliest'] ?? null;
?>
<form method="POST" action="/admin/settings">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">System name</h2>
                <div class="card-subtitle">
                    The name shown in the browser tab, the topbar brand, and the login screen.
                    Affects every signed-in user immediately.
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Display name</label>
            <input type="text" name="app_name" maxlength="128"
                   value="<?= e($appNameValue) ?>"
                   placeholder="<?= e($configAppName) ?>">
            <small class="text-muted" style="display:block;margin-top:0.4rem;">
                <?php if ($appNameValue !== ''): ?>
                    Currently overriding the config default of "<strong><?= e($configAppName) ?></strong>".
                    Clear this field and save to revert to the config value.
                <?php else: ?>
                    No override set — using the config default of "<strong><?= e($configAppName) ?></strong>".
                <?php endif; ?>
            </small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save settings</button>
            <a href="/admin/users" class="btn">Cancel</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inventory snapshots</h2>
            <div class="card-subtitle">
                Daily inventory captures power the dashboard's "as of yesterday" totals and 30-day comparison.
                A nightly cron runs automatically at <strong>03:00</strong>; the buttons below let you trigger one ad-hoc.
            </div>
        </div>
    </div>

    <div class="stat-grid" style="margin-bottom:1rem;">
        <div class="stat-card">
            <div class="stat-label">Days captured</div>
            <div class="stat-value"><?= fmt_number($daysCaptured) ?></div>
            <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                <?php if ($daysCaptured === 0): ?>
                    No snapshots yet — click "Backfill 30 days" to populate
                <?php else: ?>
                    <?= e(fmt_date($earliest, 'M j, Y')) ?> → <?= e(fmt_date($latest, 'M j, Y')) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Latest snapshot</div>
            <div class="stat-value"><?= $latest ? e(fmt_date($latest, 'M j')) : '—' ?></div>
            <div class="text-muted" style="font-size:0.78rem;margin-top:0.4rem;">
                <?php if ($latest && $latest === date('Y-m-d', strtotime('-1 day'))): ?>
                    Up to date (yesterday)
                <?php elseif ($latest): ?>
                    <?= e(fmt_date($latest, 'l, F j, Y')) ?>
                <?php else: ?>
                    No snapshots yet
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="form-actions" style="border-top:none;padding-top:0;">
        <form method="POST" action="/admin/settings/run-snapshot" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary"
                    title="Captures yesterday's snapshot. Skips if already captured. Takes ~45 seconds.">
                ▶ Run snapshot for yesterday
            </button>
        </form>
        <form method="POST" action="/admin/settings/backfill-snapshot" style="display:inline;"
              onsubmit="return confirm('Start a 30-day inventory backfill? This will take about 25 minutes (CMS query is slow). It runs in the background — you can keep using the system. Continue?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn"
                    title="Captures every day in the last 30 that's not already cached. ~25 minutes total.">
                ↻ Backfill last 30 days
            </button>
        </form>
        <span style="flex:1;"></span>
        <a href="/admin/audit-log" class="btn btn-sm btn-outline">View audit log</a>
    </div>

    <div class="text-muted" style="font-size:0.78rem;margin-top:0.75rem;">
        Background runs append to <code>storage/logs/snapshot-inventory.log</code>.
        Both buttons skip dates that are already cached, so re-clicking is safe.
    </div>
</div>
