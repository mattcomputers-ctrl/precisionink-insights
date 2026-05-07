<?php
/**
 * @var array|null $group
 * @var array $permissions  page_key → 'none'|'read'|'full'
 * @var array $modules      \PII\Core\Module[]
 */
$isEdit = $group !== null;
$action = $isEdit ? '/admin/groups/' . $group['id'] : '/admin/groups';
?>
<div class="card">
    <form method="POST" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" required value="<?= e($group['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" value="<?= e($group['description'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="is_admin" value="1" <?= ($group['is_admin'] ?? 0) ? 'checked' : '' ?>>
                Administrator group (members get full access to all areas)
            </label>
        </div>

        <h3 style="margin-top:1.25rem;margin-bottom:0.5rem;">Module access</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-bottom:0.75rem;">
            For each tab module, choose what level of access this group has.
            Admin groups bypass these checks.
        </p>

        <table class="table">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>None</th>
                    <th>Read</th>
                    <th>Full</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $m):
                $key = $m->permissionKey();
                $level = $permissions[$key] ?? 'none';
            ?>
                <tr>
                    <td><strong><?= e($m->name()) ?></strong> <span class="tag"><?= e($key) ?></span></td>
                    <td><label><input type="radio" name="permissions[<?= e($key) ?>]" value="none" <?= $level === 'none' ? 'checked' : '' ?>></label></td>
                    <td><label><input type="radio" name="permissions[<?= e($key) ?>]" value="read" <?= $level === 'read' ? 'checked' : '' ?>></label></td>
                    <td><label><input type="radio" name="permissions[<?= e($key) ?>]" value="full" <?= $level === 'full' ? 'checked' : '' ?>></label></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create group' ?></button>
            <a href="/admin/groups" class="btn">Cancel</a>
            <?php if ($isEdit && empty($group['is_admin'])): ?>
                <span style="flex:1;"></span>
                <form method="POST" action="<?= e('/admin/groups/' . $group['id'] . '/delete') ?>"
                      onsubmit="return confirm('Delete group “<?= e(addslashes($group['name'])) ?>”?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger">Delete group</button>
                </form>
            <?php endif; ?>
        </div>
    </form>
</div>
