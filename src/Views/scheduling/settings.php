<?php
/**
 * @var array $mills         all mills (incl. inactive)
 * @var array $colorOrder    configured ladder order
 * @var array $colors        canonical color list
 * @var array $configs       sched_item_config rows
 * @var array $needsConfig   bulks with min-stock packs not yet configured
 * @var string|null $worklistError
 * @var array $dryTriggers   [{id, pattern}]
 * @var int   $dryPasses     global dry-grind pass count
 */
?>
<p><a href="/scheduling" class="btn btn-sm">← Back to schedule</a></p>

<!-- ── Equipment (mills) ─────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Equipment — mills</h2>
            <div class="card-subtitle">
                Washup minutes: <strong>like</strong> = same color → same color · <strong>next</strong> = any forward move down the ladder ·
                <strong>deep</strong> = backward move / restarting the ladder.
            </div>
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th class="text-right" title="Standard (non-dry-grind) throughput">Lbs/hr std</th>
                <th class="text-right" title="Throughput while dry grinding">Lbs/hr dry</th>
                <th class="text-right">Washup like (min)</th><th class="text-right">Washup next (min)</th><th class="text-right">Washup deep (min)</th>
                <th class="text-right">Hours/day</th><th class="text-right">Max batch (lbs)</th>
                <th title="Can this mill run dry-grind inks?">Dry grind</th>
                <th>Active</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mills as $m): ?>
            <tr>
                <form method="POST" action="<?= e('/scheduling/settings/mills/' . $m['id']) ?>">
                <?= csrf_field() ?>
                <td><input type="text" name="name" value="<?= e($m['name']) ?>" style="width:130px;"></td>
                <td class="text-right"><input type="number" step="1" name="lbs_per_hour" value="<?= e((string) $m['lbs_per_hour']) ?>" style="width:80px;"></td>
                <td class="text-right"><input type="number" step="1" name="lbs_per_hour_dry" value="<?= e((string) ($m['lbs_per_hour_dry'] ?? 0)) ?>" style="width:80px;"></td>
                <td class="text-right"><input type="number" step="1" name="washup_like_minutes" value="<?= e((string) $m['washup_like_minutes']) ?>" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="1" name="washup_next_minutes" value="<?= e((string) $m['washup_next_minutes']) ?>" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="1" name="washup_deep_minutes" value="<?= e((string) $m['washup_deep_minutes']) ?>" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="0.5" name="hours_per_day" value="<?= e((string) $m['hours_per_day']) ?>" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="1" name="max_batch_lbs" value="<?= e((string) $m['max_batch_lbs']) ?>" style="width:90px;" title="0 = unlimited"></td>
                <td><input type="checkbox" name="dry_grind_capable" value="1" <?= !empty($m['dry_grind_capable']) ? 'checked' : '' ?>></td>
                <td><input type="checkbox" name="is_active" value="1" <?= $m['is_active'] ? 'checked' : '' ?>></td>
                <td class="nowrap">
                    <input type="hidden" name="sort_order" value="<?= (int) $m['sort_order'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                </form>
                    <form method="POST" action="<?= e('/scheduling/settings/mills/' . $m['id'] . '/delete') ?>" style="display:inline;"
                          onsubmit="return confirm('Delete mill <?= e(addslashes($m['name'])) ?>?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">✕</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
            <tr>
                <form method="POST" action="/scheduling/settings/mills">
                <?= csrf_field() ?>
                <td><input type="text" name="name" placeholder="New mill…" style="width:130px;"></td>
                <td class="text-right"><input type="number" step="1" name="lbs_per_hour" placeholder="std" style="width:80px;"></td>
                <td class="text-right"><input type="number" step="1" name="lbs_per_hour_dry" placeholder="dry" style="width:80px;"></td>
                <td class="text-right"><input type="number" step="1" name="washup_like_minutes" placeholder="min" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="1" name="washup_next_minutes" placeholder="min" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="1" name="washup_deep_minutes" placeholder="min" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="0.5" name="hours_per_day" value="8" style="width:70px;"></td>
                <td class="text-right"><input type="number" step="1" name="max_batch_lbs" value="0" style="width:90px;" title="0 = unlimited"></td>
                <td><input type="checkbox" name="dry_grind_capable" value="1" checked></td>
                <td><input type="checkbox" name="is_active" value="1" checked></td>
                <td><button type="submit" class="btn btn-sm btn-primary">+ Add</button></td>
                </form>
            </tr>
        </tbody>
    </table>
</div>

<!-- ── Color order ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Color ladder order</h2>
            <div class="card-subtitle">The wash-minimizing sequence. Runs move forward down this ladder with a standard washup; going backwards costs a deep wash.</div>
        </div>
    </div>

    <form method="POST" action="/scheduling/settings/color-order" id="color-order-form">
        <?= csrf_field() ?>
        <ol id="color-order-list" style="list-style:none;display:flex;flex-direction:column;gap:0.35rem;max-width:340px;">
            <?php foreach ($colorOrder as $i => $c): ?>
                <li draggable="true" class="color-row"
                    style="display:flex;align-items:center;gap:0.6rem;background:var(--bg-elev1);border:1px solid var(--border);border-radius:6px;padding:0.4rem 0.7rem;cursor:grab;">
                    <span class="text-dim" style="width:1.4rem;text-align:right;"><?= $i + 1 ?>.</span>
                    <span style="flex:1;font-weight:500;"><?= e(ucwords($c)) ?></span>
                    <input type="hidden" name="color_order[]" value="<?= e($c) ?>">
                    <span class="text-dim" title="drag to reorder">⋮⋮</span>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="form-actions" style="max-width:340px;">
            <button type="submit" class="btn btn-primary">Save color order</button>
        </div>
    </form>
</div>

<!-- ── Dry grind ─────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Dry grind</h2>
            <div class="card-subtitle">
                A formula is a <strong>dry grind</strong> when any raw material in its <strong>direct</strong> formula matches a trigger below —
                it then gets the global pass count; everything else runs a single pass.
                Intermediates never propagate their own dry-grind status (they were ground out when the intermediate was made).
                Use <code>%</code> as a wildcard: <code>PGK%</code> matches anything starting with PGK.
            </div>
        </div>
    </div>

    <form method="POST" action="/scheduling/settings/dry-grind" class="form-row" style="align-items:flex-end;">
        <?= csrf_field() ?>
        <div class="form-group" style="flex:0 0 180px;">
            <label>Dry grind passes</label>
            <input type="number" name="dry_grind_passes" min="1" step="1" value="<?= (int) $dryPasses ?>" required>
        </div>
        <div class="form-group" style="flex:0 0 220px;">
            <label>Add trigger pattern</label>
            <input type="text" name="new_pattern" placeholder="e.g. PGK%" maxlength="30">
        </div>
        <div class="form-group" style="flex:0 0 auto;">
            <button type="submit" class="btn btn-primary">Save dry grind settings</button>
        </div>
    </form>

    <?php if (empty($dryTriggers)): ?>
        <p class="muted-empty">No trigger patterns yet — every formula is treated as a single pass until you add some.</p>
    <?php else: ?>
        <div class="flex gap-1" style="flex-wrap:wrap;">
            <?php foreach ($dryTriggers as $t): ?>
                <form method="POST" action="<?= e('/scheduling/settings/dry-grind/' . $t['id'] . '/delete') ?>" style="display:inline;"
                      onsubmit="return confirm('Remove trigger <?= e(addslashes($t['pattern'])) ?>?');">
                    <?= csrf_field() ?>
                    <span class="tag" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.3rem 0.6rem;">
                        <?= e($t['pattern']) ?>
                        <button type="submit" class="btn btn-sm btn-danger" style="padding:0 0.35rem;font-size:0.7rem;">✕</button>
                    </span>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ── Needs configuration worklist ──────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Bulk items needing configuration</h2>
            <div class="card-subtitle">
                Items whose packs carry a CMS minimum stock level but that have no scheduling config yet —
                they can't be scheduled until configured. Sorted by current need. Click Configure to prefill the form below.
            </div>
        </div>
    </div>

    <?php if ($worklistError !== null): ?>
        <div class="alert alert-warning">Worklist unavailable: <?= e($worklistError) ?></div>
    <?php elseif (empty($needsConfig)): ?>
        <p class="muted-empty">✓ All bulk items with minimum stock levels are configured.</p>
    <?php else: ?>
        <p class="text-muted" style="font-size:0.85rem;margin-bottom:0.5rem;"><?= count($needsConfig) ?> item(s) to configure</p>
        <table class="table">
            <thead>
                <tr><th>Bulk item</th><th>Description</th><th class="text-right">Packs w/ min</th><th class="text-right">Total min (lbs)</th>
                    <th class="text-right">Current need (lbs)</th><th>Order shortfall?</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($needsConfig as $b): ?>
                <tr>
                    <td><span class="tag"><?= e($b['bulk']) ?></span></td>
                    <td><?= e($b['description'] ?? '') ?></td>
                    <td class="text-right"><?= (int) $b['packs_with_min'] ?></td>
                    <td class="text-right"><?= fmt_number($b['total_min_lbs'], 0) ?></td>
                    <td class="text-right <?= $b['current_need_lbs'] > 0 ? 'text-warn' : 'text-dim' ?>"><?= fmt_number($b['current_need_lbs'], 0) ?></td>
                    <td><?= $b['packs_short_on_orders'] > 0 ? '<span class="pill" style="background:rgba(231,76,60,0.2);color:var(--bad);">YES</span>' : '<span class="text-dim">no</span>' ?></td>
                    <td class="text-right">
                        <button type="button" class="btn btn-sm btn-primary needs-config-btn" data-code="<?= e($b['bulk']) ?>">Configure ↓</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ── Item scheduling config ────────────────────────────────── -->
<div class="card" id="item-config-card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Item configuration</h2>
            <div class="card-subtitle">
                Per <strong>bulk</strong> item: color and standard batch sizes. Packs inherit from their bulk item.
                Passes are derived automatically from the dry-grind triggers above.
                Items with need but no config are flagged when generating, not scheduled.
            </div>
        </div>
    </div>

    <form method="POST" action="/scheduling/settings/items" class="form-row" style="align-items:flex-end;">
        <?= csrf_field() ?>
        <div class="form-group" style="flex:0 0 220px;position:relative;">
            <label>Bulk item code</label>
            <input type="text" name="bulk_item_code" id="item-search" autocomplete="off" placeholder="e.g. E1055" required>
            <div id="item-search-results" style="position:absolute;top:100%;left:0;right:0;background:var(--bg-elev2);border:1px solid var(--border);border-radius:6px;z-index:50;display:none;max-height:220px;overflow-y:auto;"></div>
        </div>
        <div class="form-group" style="flex:0 0 170px;">
            <label>Color</label>
            <select name="color" required>
                <?php foreach ($colors as $c): ?>
                    <option value="<?= e($c) ?>"><?= e(ucwords($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:0 0 140px;">
            <label>Batch size 1 (lbs)</label>
            <input type="number" name="batch_size_1" min="1" step="1" required>
        </div>
        <div class="form-group" style="flex:0 0 140px;">
            <label>Batch size 2 (lbs)</label>
            <input type="number" name="batch_size_2" min="1" step="1" placeholder="optional">
        </div>
        <div class="form-group" style="flex:0 0 auto;">
            <button type="submit" class="btn btn-primary">Save item</button>
        </div>
    </form>

    <?php if (empty($configs)): ?>
        <p class="muted-empty">No items configured yet. Items must be configured here before they can be scheduled.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>Bulk item</th><th>Color</th>
                <th class="text-right">Batch 1 (lbs)</th><th class="text-right">Batch 2 (lbs)</th>
                <th class="text-muted">Updated</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($configs as $c): ?>
            <tr>
                <td><span class="tag"><?= e($c['bulk_item_code']) ?></span></td>
                <td><?= e(ucwords($c['color'])) ?></td>
                <td class="text-right"><?= fmt_number((float) $c['batch_size_1'], 0) ?></td>
                <td class="text-right"><?= $c['batch_size_2'] !== null ? fmt_number((float) $c['batch_size_2'], 0) : '—' ?></td>
                <td class="text-muted"><?= e(fmt_date($c['updated_at'], 'm/d/Y')) ?></td>
                <td class="text-right">
                    <form method="POST" action="/scheduling/settings/items/delete" style="display:inline;"
                          onsubmit="return confirm('Remove scheduling config for <?= e(addslashes($c['bulk_item_code'])) ?>?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="bulk_item_code" value="<?= e($c['bulk_item_code']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    /* Color ladder drag-reorder */
    const list = document.getElementById('color-order-list');
    let dragEl = null;
    list.querySelectorAll('.color-row').forEach(row => {
        row.addEventListener('dragstart', () => { dragEl = row; row.style.opacity = '0.4'; });
        row.addEventListener('dragend',   () => { dragEl = null; row.style.opacity = ''; renumber(); });
        row.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragEl || dragEl === row) return;
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            row.parentNode.insertBefore(dragEl, before ? row : row.nextSibling);
        });
    });
    function renumber() {
        list.querySelectorAll('.color-row').forEach((row, i) => {
            row.querySelector('.text-dim').textContent = (i + 1) + '.';
        });
    }

    /* Item search autocomplete */
    const input   = document.getElementById('item-search');
    const results = document.getElementById('item-search-results');
    let timer = null;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(async () => {
            try {
                const resp = await fetch('/scheduling/settings/item-search?q=' + encodeURIComponent(q));
                const items = await resp.json();
                if (!Array.isArray(items) || items.length === 0) { results.style.display = 'none'; return; }
                results.innerHTML = items.map(it =>
                    '<div class="item-hit" data-code="' + it.ItemCode + '" style="padding:0.4rem 0.7rem;cursor:pointer;border-bottom:1px solid var(--border-light);">' +
                    '<strong>' + it.ItemCode + '</strong> <span class="text-muted" style="font-size:0.78rem;">' + (it.Description || '') + '</span></div>'
                ).join('');
                results.style.display = '';
                results.querySelectorAll('.item-hit').forEach(hit => {
                    hit.addEventListener('click', () => {
                        input.value = hit.dataset.code;
                        results.style.display = 'none';
                    });
                });
            } catch (e) { results.style.display = 'none'; }
        }, 250);
    });
    document.addEventListener('click', e => {
        if (!results.contains(e.target) && e.target !== input) results.style.display = 'none';
    });

    /* Worklist "Configure" buttons prefill the item form */
    document.querySelectorAll('.needs-config-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            input.value = btn.dataset.code;
            document.getElementById('item-config-card').scrollIntoView({ behavior: 'smooth' });
            input.focus();
        });
    });
})();
</script>
