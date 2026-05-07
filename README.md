# Precision Ink Insights — Executive Dashboard System

A web-based, multi-tab executive analytics platform that pulls data from
the existing CMS database (MSSQL on 10.10.10.11) and presents it to
executives. Built to be installed on Ubuntu Server alongside (and
independently of) the SDS system.

The first (and currently only) tab is **Margin Watchdog**, a side-by-side
two-date-range comparison of revenue, packed-cost, and dollars-over-cost
at the company, Bill To, and item-alias levels.

The codebase is structured so additional tabs can be added cleanly later —
each tab is a self-contained Module with its own routes, views, and
permission key. See SCHEMA_NOTES.md for how to add one.

---

## Stack

- **OS:** Ubuntu Server 20.04, 22.04, or 24.04
- **Web server:** Apache 2 with mod_rewrite + mod_php
- **Local DB:** MariaDB / MySQL — auth, preferences, presets, audit
- **CMS DB:** SQL Server (read-only, via `pdo_dblib`/FreeTDS on Linux)
- **Language:** PHP 8.1+ with PSR-4 autoloading via Composer
- **Excel export:** `phpoffice/phpspreadsheet`

---

## Quick install (automated)

The `install-ubuntu.sh` script does everything — installs Apache, MariaDB,
PHP 8.x with the SQL Server PDO driver, sets up the database, runs
migrations, creates an admin user, and configures the Apache vhost.

On a fresh Ubuntu Server:

```bash
# Copy this directory onto the target server, e.g. to /tmp/pii
sudo bash /tmp/pii/install-ubuntu.sh
```

The script will prompt for:

- Install directory (default `/var/www/precision-ink-insights`)
- MariaDB root password (leave blank for socket auth on a fresh install)
- Local DB name / user / password (defaults sensible)
- Server IP / hostname for the Apache vhost
- Initial admin username + password
- CMS SQL Server host, port, database name, read-only credentials

When it finishes you can log in at `http://<server-ip>` using the admin
account you specified.

---

## Manual install (advanced)

If you'd rather wire it up yourself:

1. Install dependencies:
   ```bash
   sudo apt install apache2 mariadb-server \
        php8.1 php8.1-cli php8.1-mysql php8.1-xml php8.1-mbstring \
        php8.1-curl php8.1-zip php8.1-intl php8.1-bcmath \
        php8.1-sybase libapache2-mod-php8.1 \
        composer unzip
   sudo a2enmod rewrite headers
   ```
2. Place the project at `/var/www/precision-ink-insights`, owned by
   `www-data:www-data`.
3. Run `composer install --no-dev -o` in the project root.
4. Copy `config/config.example.php` → `config/config.php` and edit. The
   important fields are `db.*` (local MariaDB) and `cms_db.*` (CMS MSSQL).
5. Run migrations: `php migrations/migrate.php`.
6. Create your initial admin user (see "Admin user setup" below).
7. Add an Apache virtual host that points `DocumentRoot` to the
   `public/` directory and allows `.htaccess` overrides.

---

## Admin user setup (manual)

If you didn't use the installer:

```sql
-- Hash the password with PHP first
-- $ php -r "echo password_hash('YourPassword', PASSWORD_ARGON2ID), PHP_EOL;"
-- Then run:
INSERT INTO users (username, email, password_hash, display_name, is_active)
VALUES ('admin', 'admin@example.com',
        '<paste hash here>', 'Administrator', 1);

INSERT INTO user_group_members (user_id, group_id)
SELECT u.id, g.id
FROM users u, permission_groups g
WHERE u.username = 'admin' AND g.name = 'Administrators';
```

The `Administrators` and `Standard Users` groups are seeded by the
initial migration.

---

## Configuration

`config/config.php` is the only file you need to edit:

| Section    | Notes |
|------------|-------|
| `app.url`  | Public URL (used in absolute links) |
| `db.*`     | Local MariaDB — auth, presets, audit |
| `cms_db.*` | Read-only MSSQL — host, port, name, user, password. Margin Watchdog won't run if this is missing or contains `CHANGE_ME`. |

The CMS connection uses PDO. On Linux that means the `dblib` driver
(FreeTDS); on Windows it can use `sqlsrv`. The CMSDatabase class probes
for whichever is available.

---

## Adding new tabs (high level)

Each tab is a "Module". Add one in three steps:

1. Create `src/Modules/MyTab/MyTabModule.php` extending `\PII\Core\Module`:
   ```php
   class MyTabModule extends \PII\Core\Module {
       public function key(): string      { return 'my_tab'; }
       public function name(): string     { return 'My Tab'; }
       public function basePath(): string { return '/my-tab'; }
       public function icon(): string     { return '📊'; }
       public function registerRoutes(\PII\Core\Router $r): void {
           $r->get('/my-tab', MyTabController::class . '@index');
       }
   }
   ```
2. Register it in `src/Core/App.php` → `buildModuleRegistry()`.
3. Optional: in `/admin/groups`, grant the new module's permission key to
   the relevant groups.

The tab nav, permission middleware, and router pick up the new module
automatically.

See **SCHEMA_NOTES.md** for the documented CMS join patterns to reuse.

---

## Maintenance

- **Logs:** application errors land in Apache's error log
  (`/var/log/apache2/precision-ink-insights-error.log` if you used the
  installer). The audit log lives in the `audit_log` MySQL table and is
  viewable at `/admin/audit-log`.
- **Backups:** the local MySQL database is small (only auth/prefs/audit) —
  a nightly `mysqldump` is sufficient. The CMS database is the source of
  truth for analytics; we never write to it.
- **Updating:** pull new code, run `composer install --no-dev -o`, then
  `php migrations/migrate.php`.

---

## Known TODOs

- Returns / credits / voids: see SCHEMA_NOTES.md caveat #4 — needs
  ERP-admin verification of which `InvMovement.Context` values represent
  these so they can be netted into Margin Watchdog totals.
- Per-day normalisation of unequal date ranges is intentionally out of
  scope for v1.
- Ship To breakdown is intentionally out of scope (Bill To only for v1).

---

## License

Internal — for use within the deploying company. Not redistributed.
