<?php
/**
 * @var string $appNameValue    current DB override (empty if not set)
 * @var string $configAppName   the config.php fallback value
 */
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
