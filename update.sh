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

# Self-relocate: git reset --hard below swaps THIS FILE mid-run. Bash keeps
# reading the old inode, so the remainder of the run would execute stale
# code — a new fix in update.sh would never run on the very update that
# delivers it. Re-exec from a temp copy so a run is one consistent version.
if [ -z "${PII_UPDATE_RELOCATED:-}" ]; then
    TMP_SELF=$(mktemp /tmp/pii-update.XXXXXX.sh)
    cp "$0" "$TMP_SELF"
    PII_UPDATE_RELOCATED="$TMP_SELF" exec bash "$TMP_SELF" "$@"
fi
trap 'rm -f "$PII_UPDATE_RELOCATED"' EXIT

# 1. Pull (reset --hard so chmod-induced mode drift never blocks the pull)
say "Fetching origin/main..."
sudo -u www-data git -C "$INSTALL_DIR" fetch --quiet origin main

OLD=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse HEAD)
NEW=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse origin/main)
if [ "$OLD" = "$NEW" ]; then
    # Code is current, but DON'T exit — the maintenance steps below
    # (permissions, logrotate, cron entry, migrations) must still run so
    # "run update.sh" always repairs machine state, not just code drift.
    say "Already at $(echo $NEW | cut -c1-7) — code current; running maintenance steps."
    CHANGED=""
else
    say "Updating $(echo $OLD | cut -c1-7) → $(echo $NEW | cut -c1-7)"
    sudo -u www-data git -C "$INSTALL_DIR" reset --hard origin/main --quiet
    CHANGED=$(sudo -u www-data git -C "$INSTALL_DIR" diff --name-only "$OLD" "$NEW")
fi

# 2. Composer (only if composer.json or composer.lock changed)
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
    # File-based install, strictly sequential. The previous piped form
    # (subshell listing + writer in one pipeline) failed silently on this
    # cron build even after removing non-ASCII characters.
    TMP_CRON=$(mktemp)
    crontab -u www-data -l 2>/dev/null | grep -v 'Precision Ink Insights' | grep -v 'snapshot-inventory' > "$TMP_CRON" || true
    {
        echo "# Precision Ink Insights - nightly inventory snapshot"
        echo "0 3 * * * cd $INSTALL_DIR && /usr/bin/php cron/snapshot-inventory.php >> storage/logs/snapshot-inventory.log 2>&1"
    } >> "$TMP_CRON"
    if ! crontab -u www-data "$TMP_CRON"; then
        warn "crontab rejected the file. Contents were:"
        sed 's/^/    /' "$TMP_CRON"
    fi
    rm -f "$TMP_CRON"

    # Read back and check — belt and suspenders.
    if ! crontab -u www-data -l 2>/dev/null | grep -q 'snapshot-inventory.php'; then
        warn "Cron entry STILL not present after file-based install."
        warn "Nightly snapshots will not run. Diagnose with:"
        warn "  sudo crontab -u www-data -l"
        warn "  printf '* * * * * true\\n' | sudo crontab -u www-data - ; echo exit=\$?"
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
