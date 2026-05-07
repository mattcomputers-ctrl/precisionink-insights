<?php
/** @var \PII\Core\Module[] $modules */
$user = $_SESSION['_user'] ?? null;
?>
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
