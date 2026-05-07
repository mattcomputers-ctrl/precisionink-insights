<?php
/**
 * @var array|null $user
 * @var array $groups
 * @var array $memberOf  array of group ids the user belongs to
 */
$isEdit = $user !== null;
$action = $isEdit ? '/admin/users/' . $user['id'] : '/admin/users';
?>
<div class="card">
    <form method="POST" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autocomplete="off"
                       value="<?= e($user['username'] ?? old('username')) ?>">
            </div>
            <div class="form-group">
                <label>Display name</label>
                <input type="text" name="display_name"
                       value="<?= e($user['display_name'] ?? old('display_name')) ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email"
                       value="<?= e($user['email'] ?? old('email')) ?>">
            </div>
            <div class="form-group">
                <label>Password <?= $isEdit ? '(leave blank to keep)' : '(min 8 chars)' ?></label>
                <input type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
            </div>
        </div>

        <div class="form-group">
            <label>Permission groups</label>
            <div class="flex gap-2" style="flex-wrap:wrap;">
                <?php foreach ($groups as $g): ?>
                    <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;font-weight:500;">
                        <input type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"
                               <?= in_array((int) $g['id'], array_map('intval', $memberOf), true) ? 'checked' : '' ?>>
                        <?= e($g['name']) ?>
                        <?php if ($g['is_admin']): ?><span class="pill pill-active">admin</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label style="display:inline-flex;gap:0.4rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="is_active" value="1"
                       <?= ($user === null || $user['is_active']) ? 'checked' : '' ?>>
                Active
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
            <a href="/admin/users" class="btn">Cancel</a>
            <?php if ($isEdit && (int) $user['id'] !== (int) current_user_id()): ?>
                <span style="flex:1;"></span>
                <form method="POST" action="<?= e('/admin/users/' . $user['id'] . '/delete') ?>"
                      onsubmit="return confirm('Delete user “<?= e(addslashes($user['username'])) ?>”? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger">Delete user</button>
                </form>
            <?php endif; ?>
        </div>
    </form>
</div>
