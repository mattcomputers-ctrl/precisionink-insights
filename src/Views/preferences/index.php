<?php
/**
 * @var array $thresholds  per-metric config from Thresholds::forUser()
 * @var bool  $colorsOn
 * @var array $labels      metric → human-readable label
 */
use PII\Modules\MarginWatchdog\Thresholds;
?>
<form method="POST" action="/preferences">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Color indicators</h2>
                <div class="card-subtitle">When on, difference cells in Margin Watchdog turn green/yellow/red based on the thresholds below. The N/A indicator (item present in only one period) is never colored regardless.</div>
            </div>
        </div>
        <label style="display:inline-flex;gap:0.6rem;align-items:center;cursor:pointer;font-weight:500;">
            <span class="switch">
                <input type="checkbox" name="colors_enabled" value="1" <?= $colorsOn ? 'checked' : '' ?>>
                <span class="slider"></span>
            </span>
            <span>Show color indicators on difference cells</span>
        </label>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Margin Watchdog thresholds</h2>
                <div class="card-subtitle">
                    Difference is colored <span class="text-good">green</span> when it crosses the green threshold in the good direction,
                    <span class="text-bad">red</span> when it crosses the red threshold in the bad direction, and
                    <span class="text-warn">yellow</span> in between.
                </div>
            </div>
        </div>

        <div class="threshold-row" style="font-weight:600;color:var(--text-muted);">
            <div>Metric</div>
            <div>Direction (what's good)</div>
            <div class="text-right">Green threshold (good ≥)</div>
            <div class="text-right">Red threshold (bad ≤ −)</div>
        </div>
        <?php foreach ($labels as $metric => $label):
            $cfg  = $thresholds[$metric] ?? Thresholds::DEFAULTS[$metric];
            // pp-unit thresholds are point deltas; we display as "%" because the
            // adjacent two cells are already percentages so context disambiguates.
            $unit = '%';
            $dirClass = $cfg['direction'] === 'up_good' ? 'good-up' : 'good-down';
            $dirText  = $cfg['direction'] === 'up_good' ? 'UP is good' : 'DOWN is good';
        ?>
        <div class="threshold-row">
            <div><strong><?= e($label) ?></strong></div>
            <div class="threshold-direction <?= $dirClass ?>" title="<?= e($dirText) ?>"></div>
            <div class="text-right">
                <input type="number" step="0.01" name="thresholds[<?= e($metric) ?>][green]"
                       value="<?= e((string) $cfg['green']) ?>"
                       style="width:120px;display:inline-block;">
                <span class="text-muted"><?= e($unit) ?></span>
            </div>
            <div class="text-right">
                <input type="number" step="0.01" name="thresholds[<?= e($metric) ?>][red]"
                       value="<?= e((string) $cfg['red']) ?>"
                       style="width:120px;display:inline-block;">
                <span class="text-muted"><?= e($unit) ?></span>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save preferences</button>
        </div>
    </div>
</form>

<form method="POST" action="/preferences/reset" class="mt-2"
      onsubmit="return confirm('Reset all Margin Watchdog thresholds to defaults?');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline">Reset thresholds to defaults</button>
</form>
