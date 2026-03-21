#!/bin/bash
# ============================================================
# MuseDock Panel — Updater
# Usage: sudo bash /opt/musedock-panel/bin/update.sh
#
# What it does:
#   1. Pulls latest code from GitHub
#   2. Runs pending database migrations
#   3. Restarts the panel service
#
# Safe to run multiple times — idempotent.
# Never touches .env, storage/, or database data.
# ============================================================

set -e

# Disable colors when not running in a terminal (e.g. called from web panel)
if [ -t 1 ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    CYAN='\033[0;36m'
    BOLD='\033[1m'
    NC='\033[0m'
else
    RED='' GREEN='' YELLOW='' CYAN='' BOLD='' NC=''
fi

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
fail() { echo -e "  ${RED}✗ $1${NC}"; exit 1; }

# Must be root
if [ "$EUID" -ne 0 ]; then
    fail "Run as root: sudo bash bin/update.sh"
fi

# Resolve panel dir (script lives in bin/)
PANEL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Verify it's a valid panel installation
if [ ! -f "${PANEL_DIR}/.env" ]; then
    fail "No .env found in ${PANEL_DIR}. Is this a valid installation?"
fi

if [ ! -d "${PANEL_DIR}/.git" ]; then
    fail "No .git directory found. Updates require a git-based installation."
fi

# Read PHP version from .env
PHP_VER=$(grep -E '^FPM_PHP_VERSION=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
PHP_VER=${PHP_VER:-8.3}
PHP_BIN="/usr/bin/php${PHP_VER}"

if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="/usr/bin/php"
fi

# Get current version
CURRENT_VERSION=$(sed -n "s/.*PANEL_VERSION'.*'\\([0-9][0-9.]*\\)'.*/\\1/p" "${PANEL_DIR}/public/index.php" | head -1)

echo ""
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════╗"
echo "  ║         MuseDock Panel Updater           ║"
echo "  ╚══════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  Current version: ${BOLD}${CURRENT_VERSION:-unknown}${NC}"
echo -e "  Install dir:     ${BOLD}${PANEL_DIR}${NC}"
echo ""

# ============================================================
# Step 1: Pull latest code
# ============================================================
echo -e "${CYAN}${BOLD}[1/4]${NC} Pulling latest code from GitHub..."
echo ""

cd "${PANEL_DIR}"

# Check for local modifications (excluding .env and storage)
LOCAL_CHANGES=$(git status --porcelain --ignore-submodules 2>/dev/null | grep -v '^\?\?' | grep -v '.env' | grep -v 'storage/' || true)

AUTO_MODE=false
if [ "${1:-}" = "--auto" ]; then
    AUTO_MODE=true
fi

if [ -n "$LOCAL_CHANGES" ]; then
    if [ "$AUTO_MODE" = true ]; then
        git stash push -m "musedock-auto-update-$(date +%Y%m%d%H%M%S)" > /dev/null 2>&1
        ok "Local changes stashed automatically (use 'git stash pop' to recover)"
    else
        warn "Local modifications detected:"
        echo "$LOCAL_CHANGES" | head -10 | sed 's/^/    /'
        echo ""
        read -rp "  Stash local changes and continue? [Y/n] " STASH_CONFIRM
        STASH_CONFIRM=${STASH_CONFIRM:-Y}
        if [[ "$STASH_CONFIRM" =~ ^[Yy]$ ]]; then
            git stash push -m "musedock-update-$(date +%Y%m%d%H%M%S)" > /dev/null 2>&1
            ok "Local changes stashed (use 'git stash pop' to recover)"
        else
            fail "Update cancelled. Commit or stash your changes first."
        fi
    fi
fi

# Reset tracked storage files that may have local changes (runtime data, not config)
git checkout -- storage/ 2>/dev/null || true

# Checksum of this script BEFORE pull (to detect self-update)
SELF_SCRIPT="${PANEL_DIR}/bin/update.sh"
SELF_HASH_BEFORE=$(md5sum "$SELF_SCRIPT" 2>/dev/null | cut -d' ' -f1)

# Pull
BEFORE_HASH=$(git rev-parse HEAD 2>/dev/null)
git pull --ff-only origin main 2>&1 | sed 's/^/    /'
AFTER_HASH=$(git rev-parse HEAD 2>/dev/null)

if [ "$BEFORE_HASH" = "$AFTER_HASH" ]; then
    ok "Already up to date"
else
    # Show what changed
    COMMITS=$(git log --oneline "${BEFORE_HASH}..${AFTER_HASH}" 2>/dev/null | wc -l)
    ok "Updated: ${COMMITS} new commit(s)"
    git log --oneline "${BEFORE_HASH}..${AFTER_HASH}" 2>/dev/null | head -10 | sed 's/^/    /'
fi

# If update.sh itself changed, re-exec with the new version
SELF_HASH_AFTER=$(md5sum "$SELF_SCRIPT" 2>/dev/null | cut -d' ' -f1)
if [ "$SELF_HASH_BEFORE" != "$SELF_HASH_AFTER" ] && [ -z "${MUSEDOCK_RELAUNCH:-}" ]; then
    warn "update.sh changed — relaunching with new version..."
    echo ""
    export MUSEDOCK_RELAUNCH=1
    exec bash "$SELF_SCRIPT" ${1:+"$1"}
fi

echo ""

# Read new version
NEW_VERSION=$(sed -n "s/.*PANEL_VERSION'.*'\\([0-9][0-9.]*\\)'.*/\\1/p" "${PANEL_DIR}/public/index.php" | head -1)

if [ -n "$NEW_VERSION" ] && [ "$NEW_VERSION" != "$CURRENT_VERSION" ]; then
    echo -e "  ${GREEN}${BOLD}Version: ${CURRENT_VERSION} → ${NEW_VERSION}${NC}"
    echo ""
fi

# ============================================================
# Step 2: Database migrations
# ============================================================
echo -e "${CYAN}${BOLD}[2/4]${NC} Running database migrations..."
echo ""

$PHP_BIN "${PANEL_DIR}/bin/migrate.php" 2>&1 | sed 's/^/  /'

echo ""

# ============================================================
# Step 3: Clear cache
# ============================================================
echo -e "${CYAN}${BOLD}[3/4]${NC} Clearing cache..."

rm -rf "${PANEL_DIR}/storage/cache/"* 2>/dev/null || true
ok "Cache cleared"

# Install monitor cron if missing (added in monitoring feature)
if [ ! -f /etc/cron.d/musedock-monitor ]; then
    cat > /etc/cron.d/musedock-monitor << CRONEOF
# MuseDock Panel — Network/system monitoring collector (every 30s)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
* * * * * root sleep 30 && /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
CRONEOF
    chmod 644 /etc/cron.d/musedock-monitor
    systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
    ok "Monitor cron installed"
fi

echo ""

# ============================================================
# Step 4: Restart service
# ============================================================
echo -e "${CYAN}${BOLD}[4/4]${NC} Restarting panel service..."

if systemctl is-active --quiet musedock-panel 2>/dev/null; then
    # Regenerate service file in case port/paths changed
    if [ -f "${PANEL_DIR}/bin/musedock-panel.service" ]; then
        PANEL_PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
        PANEL_PORT=${PANEL_PORT:-8444}
        PANEL_INTERNAL_PORT=$((PANEL_PORT + 1))
        sed -e "s|__PANEL_DIR__|${PANEL_DIR}|g" \
            -e "s|__PANEL_INTERNAL_PORT__|${PANEL_INTERNAL_PORT}|g" \
            "${PANEL_DIR}/bin/musedock-panel.service" > /etc/systemd/system/musedock-panel.service
        systemctl daemon-reload
    fi
    systemctl restart musedock-panel
    ok "Panel service restarted"
else
    warn "Panel service not running — start it with: systemctl start musedock-panel"
fi

echo ""
echo -e "${GREEN}${BOLD}  Update complete!${NC}"
echo -e "  Version: ${BOLD}${NEW_VERSION:-$CURRENT_VERSION}${NC}"
echo ""
