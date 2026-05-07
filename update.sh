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

say "Done. Updated to $(echo $NEW | cut -c1-7)."
