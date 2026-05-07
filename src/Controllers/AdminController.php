<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Models\User;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class AdminController
{
    /* ------------------------------------------------------------------
     *  Users
     * ----------------------------------------------------------------*/

    public function users(): void
    {
        $users = User::all();
        $userGroups = [];
        foreach ($users as $u) {
            $userGroups[$u['id']] = User::getGroups((int) $u['id']);
        }

        layout('admin/users/index', [
            'pageTitle' => 'Users',
            'users'     => $users,
            'userGroups' => $userGroups,
        ]);
    }

    public function createUser(): void
    {
        $groups = Database::getInstance()->fetchAll(
            "SELECT id, name, is_admin FROM permission_groups ORDER BY name"
        );

        layout('admin/users/form', [
            'pageTitle' => 'New user',
            'user'      => null,
            'groups'    => $groups,
            'memberOf'  => [],
        ]);
    }

    public function storeUser(): void
    {
        CSRF::validateRequest();

        try {
            $id = User::create([
                'username'     => $_POST['username']     ?? '',
                'email'        => $_POST['email']        ?? '',
                'display_name' => $_POST['display_name'] ?? '',
                'password'     => $_POST['password']     ?? '',
                'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            ]);
            $groupIds = $_POST['groups'] ?? [];
            User::setGroups($id, is_array($groupIds) ? $groupIds : []);
            $_SESSION['_flash']['success'] = 'User created.';
            redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/admin/users/create');
        }
    }

    public function editUser(string $id): void
    {
        $user = User::findById((int) $id);
        if (!$user) {
            $_SESSION['_flash']['error'] = 'User not found.';
            redirect('/admin/users');
        }
        $groups   = Database::getInstance()->fetchAll("SELECT id, name, is_admin FROM permission_groups ORDER BY name");
        $memberOf = array_column(User::getGroups((int) $id), 'id');

        layout('admin/users/form', [
            'pageTitle' => 'Edit user — ' . ($user['display_name'] ?: $user['username']),
            'user'      => $user,
            'groups'    => $groups,
            'memberOf'  => $memberOf,
        ]);
    }

    public function updateUser(string $id): void
    {
        CSRF::validateRequest();
        $userId = (int) $id;

        try {
            $data = [
                'username'     => $_POST['username']     ?? null,
                'email'        => $_POST['email']        ?? null,
                'display_name' => $_POST['display_name'] ?? null,
                'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            ];
            // Skip blank field updates
            foreach (['username', 'display_name'] as $k) {
                if ($data[$k] === null || trim($data[$k]) === '') {
                    unset($data[$k]);
                }
            }
            if (!empty($_POST['password'])) {
                $data['password'] = $_POST['password'];
            }

            User::updateUser($userId, $data);

            $groupIds = $_POST['groups'] ?? [];
            User::setGroups($userId, is_array($groupIds) ? $groupIds : []);

            $_SESSION['_flash']['success'] = 'User updated.';
            redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/admin/users/' . $userId . '/edit');
        }
    }

    public function deleteUser(string $id): void
    {
        CSRF::validateRequest();
        $userId = (int) $id;
        $current = current_user_id();
        if ($userId === $current) {
            $_SESSION['_flash']['error'] = 'You cannot delete your own account.';
            redirect('/admin/users');
        }
        User::delete($userId);
        $_SESSION['_flash']['success'] = 'User deleted.';
        redirect('/admin/users');
    }

    /* ------------------------------------------------------------------
     *  Groups
     * ----------------------------------------------------------------*/

    public function groups(): void
    {
        $groups = Database::getInstance()->fetchAll(
            "SELECT pg.id, pg.name, pg.description, pg.is_admin,
                    (SELECT COUNT(*) FROM user_group_members ugm WHERE ugm.group_id = pg.id) AS member_count
             FROM permission_groups pg
             ORDER BY pg.name"
        );

        layout('admin/groups/index', [
            'pageTitle' => 'Permission groups',
            'groups'    => $groups,
        ]);
    }

    public function createGroup(): void
    {
        layout('admin/groups/form', [
            'pageTitle'   => 'New permission group',
            'group'       => null,
            'permissions' => [],
            'modules'     => App::modules()->all(),
        ]);
    }

    public function storeGroup(): void
    {
        CSRF::validateRequest();
        $db   = Database::getInstance();
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Group name is required.';
            redirect('/admin/groups/create');
        }
        try {
            $id = (int) $db->insert('permission_groups', [
                'name'        => $name,
                'description' => trim($_POST['description'] ?? ''),
                'is_admin'    => isset($_POST['is_admin']) ? 1 : 0,
            ]);
            $this->savePermissions($id, $_POST['permissions'] ?? []);
            $_SESSION['_flash']['success'] = 'Group created.';
            redirect('/admin/groups');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/admin/groups/create');
        }
    }

    public function editGroup(string $id): void
    {
        $db = Database::getInstance();
        $group = $db->fetch("SELECT * FROM permission_groups WHERE id = ?", [(int) $id]);
        if (!$group) {
            $_SESSION['_flash']['error'] = 'Group not found.';
            redirect('/admin/groups');
        }
        $permRows = $db->fetchAll("SELECT page_key, access_level FROM group_permissions WHERE group_id = ?", [(int) $id]);
        $perms = [];
        foreach ($permRows as $r) $perms[$r['page_key']] = $r['access_level'];

        layout('admin/groups/form', [
            'pageTitle'   => 'Edit group — ' . $group['name'],
            'group'       => $group,
            'permissions' => $perms,
            'modules'     => App::modules()->all(),
        ]);
    }

    public function updateGroup(string $id): void
    {
        CSRF::validateRequest();
        $db    = Database::getInstance();
        $gid   = (int) $id;
        $group = $db->fetch("SELECT * FROM permission_groups WHERE id = ?", [$gid]);
        if (!$group) {
            $_SESSION['_flash']['error'] = 'Group not found.';
            redirect('/admin/groups');
        }
        $db->update('permission_groups', [
            'name'        => trim($_POST['name'] ?? $group['name']),
            'description' => trim($_POST['description'] ?? ''),
            'is_admin'    => isset($_POST['is_admin']) ? 1 : 0,
        ], 'id = ?', [$gid]);
        $this->savePermissions($gid, $_POST['permissions'] ?? []);
        $_SESSION['_flash']['success'] = 'Group updated.';
        redirect('/admin/groups');
    }

    public function deleteGroup(string $id): void
    {
        CSRF::validateRequest();
        Database::getInstance()->delete('permission_groups', 'id = ?', [(int) $id]);
        $_SESSION['_flash']['success'] = 'Group deleted.';
        redirect('/admin/groups');
    }

    private function savePermissions(int $groupId, array $perms): void
    {
        $db = Database::getInstance();
        $db->delete('group_permissions', 'group_id = ?', [$groupId]);
        foreach ($perms as $pageKey => $level) {
            $level = in_array($level, ['none', 'read', 'full'], true) ? $level : 'none';
            if ($level === 'none') continue;
            $db->insert('group_permissions', [
                'group_id'     => $groupId,
                'page_key'     => $pageKey,
                'access_level' => $level,
            ]);
        }
    }

    /* ------------------------------------------------------------------
     *  System settings
     * ----------------------------------------------------------------*/

    public function settings(): void
    {
        $db = Database::getInstance();

        // Pull current overrides; if absent, fall through to config defaults
        // when displaying — this lets admins know which fields are using config
        // vs DB-overridden values without surprising them.
        $overrides = [];
        foreach ($db->fetchAll("SELECT `key`, `value` FROM settings") as $row) {
            $overrides[$row['key']] = (string) ($row['value'] ?? '');
        }

        // Inventory snapshot status for the manual-trigger UI
        $snapshotInfo = $db->fetch(
            "SELECT MAX(snapshot_date) AS latest, MIN(snapshot_date) AS earliest,
                    COUNT(DISTINCT snapshot_date) AS days_captured
               FROM inventory_snapshots"
        ) ?: [];

        layout('admin/settings/index', [
            'pageTitle'     => 'System settings',
            'appNameValue'  => $overrides['app.name'] ?? '',
            'configAppName' => (string) App::config('app.name', 'Precision Ink Insights'),
            'snapshotInfo'  => $snapshotInfo,
        ]);
    }

    /**
     * Kick off a snapshot run in the background. Returns immediately so
     * the click responds instantly even though the underlying CMS query
     * takes 45+ seconds (or 25 minutes for a 30-day backfill).
     */
    public function runInventorySnapshot(): void
    {
        CSRF::validateRequest();
        $this->spawnSnapshot([], 'manual_yesterday');
        $_SESSION['_flash']['success'] = 'Snapshot for yesterday started in the background. Refresh in about a minute.';
        redirect('/admin/settings');
    }

    public function backfillInventorySnapshots(): void
    {
        CSRF::validateRequest();
        $this->spawnSnapshot(['--backfill-days=30'], 'manual_backfill_30');
        $_SESSION['_flash']['success'] = '30-day backfill started in the background. This usually takes about 25 minutes — you can keep using the system while it runs. The progress bar below will update as it goes.';
        redirect('/admin/settings');
    }

    /**
     * GET /admin/settings/snapshot-status (JSON)
     *
     * Returns the current state of any in-flight or recently-finished
     * snapshot run. Polled by the settings-page progress bar.
     *
     * State machine:
     *   - "idle"     — no status file (nothing has run since boot)
     *   - "running"  — script is actively writing heartbeats (fresh < 90s)
     *   - "finished" — script wrote finished_at and exited cleanly
     *   - "stale"    — heartbeat is older than 90s and never finished
     *                  (likely the process was killed)
     */
    public function snapshotStatus(): void
    {
        $statusFile = App::basePath() . '/storage/cache/snapshot-status.json';
        if (!file_exists($statusFile)) {
            json_response(['state' => 'idle']);
        }

        $raw = @file_get_contents($statusFile);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            json_response(['state' => 'idle']);
        }

        $state = 'running';
        if (!empty($data['finished_at'])) {
            $state = 'finished';
        } elseif (!empty($data['heartbeat'])) {
            $heartbeatAge = time() - strtotime($data['heartbeat']);
            if ($heartbeatAge > 90) {
                $state = 'stale';
            }
        }

        $total     = max(1, (int) ($data['total_dates'] ?? 0));   // avoid div-by-zero
        $completed = (int) ($data['completed_dates'] ?? 0);
        $progress  = $total > 0 ? min(100, ($completed / $total) * 100) : 0;

        json_response([
            'state'        => $state,
            'progress_pct' => round($progress, 1),
            'data'         => $data,
        ]);
    }

    /**
     * Spawn cron/snapshot-inventory.php as a detached background process.
     * Stdout + stderr go to the same log the cron job uses. Falls back to
     * a synchronous run if exec() is disabled (rare but possible on
     * locked-down PHP installs).
     *
     * @param list<string> $extraArgs CLI args to pass through (e.g. --backfill-days=30)
     */
    private function spawnSnapshot(array $extraArgs, string $auditEntityId): void
    {
        $base       = App::basePath();
        $scriptPath = $base . '/cron/snapshot-inventory.php';
        $logPath    = $base . '/storage/logs/snapshot-inventory.log';

        $argString = '';
        foreach ($extraArgs as $a) {
            $argString .= ' ' . escapeshellarg($a);
        }
        $cmd = sprintf(
            'nohup php %s%s >> %s 2>&1 &',
            escapeshellarg($scriptPath),
            $argString,
            escapeshellarg($logPath)
        );

        if (function_exists('exec')) {
            exec($cmd);
        }

        Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'inventory_snapshot',
            'entity_id'   => $auditEntityId,
            'action'      => 'run',
            'details'     => json_encode(['args' => $extraArgs]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public function saveSettings(): void
    {
        CSRF::validateRequest();
        $db = Database::getInstance();

        $appName = trim((string) ($_POST['app_name'] ?? ''));

        // Empty value → DELETE the override so the config default takes over.
        if ($appName === '') {
            $db->delete('settings', '`key` = ?', ['app.name']);
        } else {
            $existing = $db->fetch("SELECT 1 FROM settings WHERE `key` = ?", ['app.name']);
            if ($existing) {
                $db->update('settings', ['value' => $appName], '`key` = ?', ['app.name']);
            } else {
                $db->insert('settings', ['key' => 'app.name', 'value' => $appName]);
            }
        }

        $db->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'settings',
            'entity_id'   => 'app.name',
            'action'      => 'update',
            'details'     => json_encode(['new_value' => $appName === '' ? '(cleared — using config default)' : $appName]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $_SESSION['_flash']['success'] = 'Settings saved.';
        redirect('/admin/settings');
    }

    /* ------------------------------------------------------------------
     *  Audit log
     * ----------------------------------------------------------------*/

    public function auditLog(): void
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT al.id, al.user_id, al.entity_type, al.entity_id, al.action,
                    al.details, al.ip_address, al.created_at, u.username
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.id DESC
             LIMIT 500"
        );

        layout('admin/audit/index', [
            'pageTitle' => 'Audit log',
            'rows'      => $rows,
        ]);
    }
}
