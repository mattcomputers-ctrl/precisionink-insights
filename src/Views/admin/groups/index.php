<?php /** @var array $groups */ ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= count($groups) ?> permission group<?= count($groups) === 1 ? '' : 's' ?></h2>
        <a href="/admin/groups/create" class="btn btn-primary btn-sm">+ New group</a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Members</th>
                <th>Admin?</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <tr>
                <td><strong><?= e($g['name']) ?></strong></td>
                <td class="text-muted"><?= e($g['description']) ?></td>
                <td><?= (int) $g['member_count'] ?></td>
                <td><?= $g['is_admin'] ? '<span class="pill pill-active">yes</span>' : '<span class="text-muted">no</span>' ?></td>
                <td class="text-right">
                    <a href="<?= e('/admin/groups/' . $g['id'] . '/edit') ?>" class="btn btn-sm">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
