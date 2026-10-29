<?php
/**
 * @var string $pageTitle
 * @var string $bodyContent  pre-rendered HTML
 * @var string|null $activeKey  module key currently active (null on dashboard / non-module pages)
 */
$appName = app_name();
$user    = $_SESSION['_user'] ?? null;
// Hidden, not disabled: the nav only ever lists what this user may open.
$modules = $user
    ? \PII\Services\PermissionService::visibleModules((int) $user['id'])
    : [];
$uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active  = $activeKey ?? null;
$homeUrl = $user ? landing_path() : '/';

// Color preference
$colorsOn = true;
if ($user) {
    $row = \PII\Core\Database::getInstance()->fetch(
        "SELECT `value` FROM user_preferences WHERE user_id = ? AND `key` = 'colors_enabled'",
        [(int) $user['id']]
    );
    if ($row && $row['value'] === '0') {
        $colorsOn = false;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="/css/app.css">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="<?= $colorsOn ? '' : 'colors-off' ?>">
    <header class="topbar">
        <a href="<?= e($homeUrl) ?>" class="brand">
            <span class="brand-mark">PII</span>
            <span><?= e($appName) ?></span>
        </a>
        <?php if ($user): ?>
        <div class="user-menu">
            <span class="user-name"><?= e($user['display_name'] ?: $user['username']) ?></span>
            <?php if (!empty($user['is_admin'])): ?>
                <span class="user-badge user-badge-admin">Admin</span>
            <?php endif; ?>
            <?php if (user_can_see_module('margin_watchdog')): ?>
                <a href="/preferences" class="btn btn-sm" title="Preferences">⚙ Preferences</a>
            <?php endif; ?>
            <?php if (user_can('admin.system')): ?>
                <a href="/admin/settings" class="btn btn-sm" title="System settings">⚙ Settings</a>
            <?php endif; ?>
            <?php if (user_can('admin.users')): ?>
                <a href="/admin/users" class="btn btn-sm">Users</a>
            <?php endif; ?>
            <?php if (user_can('admin.system')): ?>
                <a href="/admin/audit-log" class="btn btn-sm">Audit</a>
            <?php endif; ?>
            <a href="/password" class="btn btn-sm" title="Change password">Password</a>
            <a href="/logout" class="btn btn-sm">Logout</a>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($user): ?>
    <nav class="tab-nav">
        <a href="/" class="tab-nav-item <?= $uri === '/' ? 'active' : '' ?>">
            <span class="tab-icon">⌂</span> Dashboard
        </a>
        <?php foreach ($modules as $module):
            $key  = $module->key();
            $base = $module->basePath();
            $isActive = ($active === $key) || str_starts_with($uri, $base);
        ?>
            <a href="<?= e($base) ?>" class="tab-nav-item <?= $isActive ? 'active' : '' ?>">
                <?php if ($module->icon() !== ''): ?>
                    <span class="tab-icon"><?= $module->icon() ?></span>
                <?php endif; ?>
                <?= e($module->name()) ?>
            </a>
        <?php endforeach; ?>
        <span class="tab-nav-spacer"></span>
        <?php if (user_can_see_module('margin_watchdog')): ?>
            <a href="/presets" class="tab-nav-aux <?= str_starts_with($uri, '/presets') ? 'active' : '' ?>">Presets</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <main class="content">
        <?= flash_messages() ?>
        <?php if (!empty($pageTitle)): ?>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <?php endif; ?>
        <?= $bodyContent ?? '' ?>
    </main>

    <script src="/js/app.js"></script>
</body>
</html>
