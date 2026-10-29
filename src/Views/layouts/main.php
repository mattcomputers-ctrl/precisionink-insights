<?php
/**
 * App shell (SPEC §4): topbar + always-visible sync status bar + side nav.
 *
 * @var string $pageTitle
 * @var string $bodyContent  pre-rendered HTML
 * @var string|null $activeKey  module key currently active (null on Home / non-module pages)
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

// Per-user presentation preferences
$colorsOn = true;
$theme    = 'light';
if ($user) {
    $prefs = \PII\Core\Database::getInstance()->fetchAll(
        "SELECT `key`, `value` FROM user_preferences WHERE user_id = ? AND `key` IN ('colors_enabled','theme')",
        [(int) $user['id']]
    );
    foreach ($prefs as $p) {
        if ($p['key'] === 'colors_enabled' && $p['value'] === '0') $colorsOn = false;
        if ($p['key'] === 'theme' && $p['value'] === 'dark')       $theme = 'dark';
    }
}
$bodyClasses = trim(($colorsOn ? '' : 'colors-off ') . ($theme === 'dark' ? 'theme-dark ' : '') . ($user ? '' : 'no-nav'));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset_url('favicon.svg')) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="<?= e($bodyClasses) ?>">
    <header class="topbar">
        <?php if ($user): ?>
            <button type="button" id="nav-toggle" class="nav-toggle" aria-label="Toggle navigation">☰</button>
        <?php endif; ?>
        <a href="<?= e($homeUrl) ?>" class="brand">
            <span class="brand-mark"><?= insights_logo(34) ?></span>
            <span class="brand-name"><?= e($appName) ?></span>
        </a>
        <?php if ($user): ?>
        <div class="user-menu">
            <button type="button" class="user-menu-btn">
                <span class="user-name"><?= e($user['display_name'] ?: $user['username']) ?></span>
                <?php if (!empty($user['is_admin'])): ?>
                    <span class="user-badge user-badge-admin">Admin</span>
                <?php endif; ?>
                <span aria-hidden="true">▾</span>
            </button>
            <div class="user-menu-panel">
                <?php if (user_can_see_module('margin_watchdog')): ?>
                    <a href="/preferences">⚙ Preferences</a>
                <?php endif; ?>
                <a href="/password">🔑 Change password</a>
                <form method="POST" action="/theme" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit"><?= $theme === 'dark' ? '☀ Switch to light mode' : '🌙 Switch to dark mode' ?></button>
                </form>
                <div class="user-menu-divider"></div>
                <a href="/logout">↩ Log out</a>
            </div>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($user): ?>
    <div id="sync-bar" class="sync-bar sync-bar-paused" role="status" aria-live="polite">
        <span class="sync-icon">⟳</span>
        <span class="sync-text">Checking data status…</span>
        <span class="sync-progress" style="display:none;"><span style="width:0%"></span></span>
        <button type="button" id="sync-try-btn" class="btn btn-sm" style="display:none;">Try again</button>
        <button type="button" id="sync-now-btn" class="btn btn-sm" style="display:none;">⟳ Sync now</button>
    </div>

    <div class="nav-backdrop"></div>
    <nav class="side-nav" aria-label="Main navigation">
        <a href="/" class="<?= $uri === '/' ? 'active' : '' ?>">
            <span class="nav-icon">⌂</span><span class="nav-label">Home</span>
        </a>
        <?php foreach ($modules as $module):
            $key  = $module->key();
            $base = $module->basePath();
            $isActive = ($active === $key) || str_starts_with($uri, $base);
        ?>
            <a href="<?= e($base) ?>" class="<?= $isActive ? 'active' : '' ?>" title="<?= e($module->description()) ?>">
                <span class="nav-icon"><?= $module->icon() !== '' ? $module->icon() : '▪' ?></span>
                <span class="nav-label"><?= e($module->name()) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (user_can_see_module('margin_watchdog')): ?>
            <a href="/presets" class="<?= str_starts_with($uri, '/presets') ? 'active' : '' ?>">
                <span class="nav-icon">📌</span><span class="nav-label">Date presets</span>
            </a>
        <?php endif; ?>

        <?php
        $adminLinks = [];
        if (user_can('admin.users'))  { $adminLinks['/admin/users'] = ['👥', 'Users & groups']; }
        if (user_can('admin.cms'))    { $adminLinks['/admin/cms'] = ['🔌', 'CMS & Sync']; }
        if (user_can('admin.system')) { $adminLinks['/admin/settings'] = ['🛠', 'System settings']; }
        if (user_can('admin.network')) { $adminLinks['/admin/network'] = ['🌐', 'Network']; }
        if (user_can('admin.system')) { $adminLinks['/admin/audit-log'] = ['📜', 'Audit log']; }
        ?>
        <?php if ($adminLinks !== []): ?>
            <div class="nav-section">Administration</div>
            <?php foreach ($adminLinks as $href => [$icon, $label]): ?>
                <a href="<?= e($href) ?>" class="<?= str_starts_with($uri, $href) ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $icon ?></span><span class="nav-label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
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

    <script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
