#!/bin/bash
# ============================================================
# Precision Ink Insights — Update Script
# Pulls the latest code from origin/main, refreshes composer
# dependencies, runs any pending migrations, and resets file
# permissions.
#
# Usage (run as root, NOT as www-data):
#   sudo bash /var/www/precision-ink-insights/update.sh
# ============================================================
set -e

INSTALL_DIR="${INSTALL_DIR:-/var/www/precision-ink-insights}"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
say()  { echo -e "${GREEN}[*]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
fail() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[ "$EUID" -eq 0 ] || fail "Run as root (sudo bash update.sh)"
[ -d "$INSTALL_DIR" ] || fail "Install dir not found: $INSTALL_DIR"
[ -d "$INSTALL_DIR/.git" ] || fail "Not a git checkout: $INSTALL_DIR"

# 1. Pull (reset --hard so chmod-induced mode drift never blocks the pull)
say "Fetching origin/main..."
sudo -u www-data git -C "$INSTALL_DIR" fetch --quiet origin main

OLD=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse HEAD)
NEW=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse origin/main)
if [ "$OLD" = "$NEW" ]; then
    say "Already at $(echo $NEW | cut -c1-7) — nothing to update."
    exit 0
fi

say "Updating $(echo $OLD | cut -c1-7) → $(echo $NEW | cut -c1-7)"
sudo -u www-data git -C "$INSTALL_DIR" reset --hard origin/main --quiet

# 2. Composer (only if composer.json or composer.lock changed)
CHANGED=$(sudo -u www-data git -C "$INSTALL_DIR" diff --name-only "$OLD" "$NEW")
if echo "$CHANGED" | grep -qE '^composer\.(json|lock)$'; then
    say "composer.json/lock changed — running composer install..."
    sudo -u www-data composer install -d "$INSTALL_DIR" --no-dev -o --quiet
else
    say "No composer changes."
fi

# 3. Migrations (always — migrate.php is idempotent)
if ls "$INSTALL_DIR"/migrations/*.sql >/dev/null 2>&1; then
    say "Running migrations (idempotent)..."
    sudo -u www-data php "$INSTALL_DIR"/migrations/migrate.php
fi

# 4. Permissions — keep www-data ownership, restore writable storage dirs
say "Resetting permissions..."
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR"/storage 2>/dev/null || true
[ -f "$INSTALL_DIR/config/config.php" ] && chmod 640 "$INSTALL_DIR/config/config.php"

# 4b. Refresh logrotate config so policy changes propagate on every pull.
LOGROTATE_SRC="$INSTALL_DIR/installer/logrotate.conf"
LOGROTATE_DST="/etc/logrotate.d/precision-ink-insights"
if [ -f "$LOGROTATE_SRC" ]; then
    say "Refreshing logrotate config at $LOGROTATE_DST…"
    sed "s|/var/www/precision-ink-insights|$INSTALL_DIR|g" "$LOGROTATE_SRC" > "$LOGROTATE_DST"
    chmod 0644 "$LOGROTATE_DST"
    chown root:root "$LOGROTATE_DST"
    # Parser check — without this a typo in the .conf rots silently for weeks.
    LR_ERRORS=$(logrotate -d "$LOGROTATE_DST" 2>&1 | grep '^error:' || true)
    if [ -n "$LR_ERRORS" ]; then
        warn "Logrotate config has parser errors (log rotation will fail nightly):"
        echo "$LR_ERRORS" | sed 's/^/    /'
    fi
fi

# 5. Ensure inventory-snapshot cron is installed at the canonical schedule.
# Always rewrite the entry so changes to the schedule propagate to existing
# deployments on the next update.sh run.
if [ -f "$INSTALL_DIR/cron/snapshot-inventory.php" ]; then
    say "Refreshing inventory-snapshot cron entry (03:00 daily)..."
    # ASCII-only comment — some Ubuntu cron builds silently drop the
    # entire crontab on non-ASCII input (em-dash bit us for weeks).
    CRON_ENTRY="# Precision Ink Insights - nightly inventory snapshot
0 3 * * * cd $INSTALL_DIR && /usr/bin/php cron/snapshot-inventory.php >> storage/logs/snapshot-inventory.log 2>&1"
    ( crontab -u www-data -l 2>/dev/null | grep -v 'Precision Ink Insights' | grep -v 'snapshot-inventory'; echo "$CRON_ENTRY" ) | crontab -u www-data -

    # Read back and check — crontab exits 0 even on silent reject.
    if ! crontab -u www-data -l 2>/dev/null | grep -q 'snapshot-inventory.php'; then
        warn "Cron install appeared to succeed but 'crontab -u www-data -l' shows no entry."
        warn "Nightly snapshots will not run until this is fixed. Investigate with:"
        warn "  sudo crontab -u www-data -l"
    fi
fi

# 6. If inventory_snapshots is empty, hint at backfill
if [ -f "$INSTALL_DIR/cron/snapshot-inventory.php" ] && [ -f "$INSTALL_DIR/config/config.php" ]; then
    EMPTY=$(sudo -u www-data php -r "
        \$c = require '$INSTALL_DIR/config/config.php';
        try {
            \$pdo = new PDO('mysql:host='.\$c['db']['host'].';port='.(\$c['db']['port']??3306).';dbname='.\$c['db']['name'], \$c['db']['user'], \$c['db']['password']);
            \$r = \$pdo->query('SELECT COUNT(*) FROM inventory_snapshots')->fetchColumn();
            echo \$r == 0 ? 'YES' : 'NO';
        } catch (Throwable \$e) { echo 'UNKNOWN'; }
    " 2>/dev/null)
    if [ "$EMPTY" = "YES" ]; then
        warn "Inventory snapshots table is empty — dashboard will show 'not yet captured' until you run:"
        echo "    sudo -u www-data php $INSTALL_DIR/cron/snapshot-inventory.php --backfill-days=30"
        echo "  This takes ~25 minutes. Run it now in the background with nohup if you want."
    fi
fi

say "Done. Updated to $(echo $NEW | cut -c1-7)."
