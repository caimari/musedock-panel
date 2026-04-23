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

read_panel_version() {
    local ver=""
    # Preferred source (current architecture)
    if [ -f "${PANEL_DIR}/config/panel.php" ]; then
        ver=$(sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\\([0-9][0-9.]*\\)'.*/\\1/p" "${PANEL_DIR}/config/panel.php" | head -1)
    fi
    # Legacy fallback
    if [ -z "$ver" ] && [ -f "${PANEL_DIR}/public/index.php" ]; then
        ver=$(sed -n "s/.*PANEL_VERSION'.*'\\([0-9][0-9.]*\\)'.*/\\1/p" "${PANEL_DIR}/public/index.php" | head -1)
    fi
    echo "$ver"
}

# Get current version
CURRENT_VERSION=$(read_panel_version)

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
NEW_VERSION=$(read_panel_version)

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

# Install/update bandwidth collector cron
cat > /etc/cron.d/musedock-bandwidth << CRONEOF
# MuseDock Panel — Bandwidth + Web Stats collector (staggered: +20s, every 10 min)
*/10 * * * * root sleep 20 && /usr/bin/php ${PANEL_DIR}/bin/bandwidth-collector.php >> ${PANEL_DIR}/storage/logs/bandwidth-collector.log 2>&1
CRONEOF
chmod 644 /etc/cron.d/musedock-bandwidth

# Stagger crons to avoid thundering herd (all starting at :00)
# Monitor stays at :00/:30, others offset by 5s each
CRON_UPDATED=false

# Cluster worker: +5s
if ! grep -q 'sleep 5' /etc/cron.d/musedock-cluster 2>/dev/null; then
    cat > /etc/cron.d/musedock-cluster << CRONEOF
# MuseDock Panel — Cluster worker (staggered: +5s)
* * * * * root sleep 5 && /usr/bin/php ${PANEL_DIR}/bin/cluster-worker.php >> ${PANEL_DIR}/storage/logs/cluster-worker.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-cluster
    CRON_UPDATED=true
fi

# Failover worker: +10s
if ! grep -q 'sleep 10' /etc/cron.d/musedock-failover 2>/dev/null; then
    cat > /etc/cron.d/musedock-failover << CRONEOF
# MuseDock Panel — Failover worker (staggered: +10s)
* * * * * root sleep 10 && /usr/bin/php ${PANEL_DIR}/bin/failover-worker.php >> ${PANEL_DIR}/storage/logs/failover-worker.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-failover
    CRON_UPDATED=true
fi

# Filesync worker: +15s
if ! grep -q 'sleep 15' /etc/cron.d/musedock-filesync 2>/dev/null; then
    cat > /etc/cron.d/musedock-filesync << CRONEOF
# MuseDock Panel — File sync worker (staggered: +15s)
* * * * * root sleep 15 && /usr/bin/php ${PANEL_DIR}/bin/filesync-worker.php >> ${PANEL_DIR}/storage/logs/filesync-worker.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-filesync
    CRON_UPDATED=true
fi

if [ "$CRON_UPDATED" = true ]; then
    systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
    ok "Crons staggered to avoid CPU spikes"
fi

# Stagger CMS crons in user crontab (verify-caddy-status and cron-plugins run at same */15)
CMS_DIR="/var/www/vhosts/musedock.com/httpdocs"
if [ -d "$CMS_DIR" ]; then
    CMS_USER=$(stat -c '%U' "$CMS_DIR/public/index.php" 2>/dev/null)
    if [ -n "$CMS_USER" ] && crontab -u "$CMS_USER" -l >/dev/null 2>&1; then
        CURRENT_CRONTAB=$(crontab -u "$CMS_USER" -l 2>/dev/null)
        NEEDS_UPDATE=false

        # verify-caddy-status: */15 -> 3,18,33,48 (offset +3 min)
        if echo "$CURRENT_CRONTAB" | grep -q '^\*/15.*verify-caddy-status'; then
            CURRENT_CRONTAB=$(echo "$CURRENT_CRONTAB" | sed 's|^\*/15\(.*verify-caddy-status\)|3,18,33,48\1|')
            NEEDS_UPDATE=true
        fi

        # cron-plugins: */15 -> 8,23,38,53 (offset +8 min)
        if echo "$CURRENT_CRONTAB" | grep -q '^\*/15.*cron-plugins'; then
            CURRENT_CRONTAB=$(echo "$CURRENT_CRONTAB" | sed 's|^\*/15\(.*cron-plugins\)|8,23,38,53\1|')
            NEEDS_UPDATE=true
        fi

        # cleanup-expired-cloudflare-zones: 0 -> 12 (offset to :12 each hour)
        if echo "$CURRENT_CRONTAB" | grep -q '^0 \*.*cleanup-expired-cloudflare'; then
            CURRENT_CRONTAB=$(echo "$CURRENT_CRONTAB" | sed 's|^0 \*\(.*cleanup-expired-cloudflare\)|12 *\1|')
            NEEDS_UPDATE=true
        fi

        if [ "$NEEDS_UPDATE" = true ]; then
            echo "$CURRENT_CRONTAB" | crontab -u "$CMS_USER" -
            ok "CMS crons staggered for user $CMS_USER"
        fi
    fi
fi

# Stagger hourly panel backup to avoid collision with monitor at :00
if grep -q '^0 \* \* \* \* postgres pg_dump' /etc/cron.d/musedock-backup 2>/dev/null; then
    cat > /etc/cron.d/musedock-backup << CRONEOF
# MuseDock Panel — Hourly panel DB backup (at :02 to avoid cron storm at :00)
2 * * * * postgres pg_dump -p 5433 musedock_panel | gzip > ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz 2>/dev/null
# Cleanup backups older than 48 hours (at :07)
7 * * * * root find ${PANEL_DIR}/storage/backups/ -name "panel-*.sql.gz" -mmin +2880 -delete 2>/dev/null
CRONEOF
    chmod 644 /etc/cron.d/musedock-backup
    ok "Panel backup cron staggered to :02/:07"
fi

# Install audit log purge cron if missing
if [ ! -f /etc/cron.d/musedock-audit-purge ]; then
    cat > /etc/cron.d/musedock-audit-purge << CRONEOF
# MuseDock Panel — Purge file audit logs older than 2 years (weekly, Sunday 4am)
0 4 * * 0 root /usr/bin/php ${PANEL_DIR}/bin/purge-audit-logs.php >> /var/log/musedock-audit-purge.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-audit-purge
    ok "Audit log purge cron installed"
fi

# Compile musedock-listdir if source changed
if [ -f "${PANEL_DIR}/bin/musedock-listdir.c" ]; then
    CURRENT_MD5=$(md5sum "${PANEL_DIR}/bin/musedock-listdir.c" 2>/dev/null | cut -d' ' -f1)
    STORED_MD5=$(cat "${PANEL_DIR}/bin/.musedock-listdir.md5" 2>/dev/null || echo "")
    if [ "$CURRENT_MD5" != "$STORED_MD5" ] && command -v gcc >/dev/null 2>&1; then
        gcc -O2 -Wall -o "${PANEL_DIR}/bin/musedock-listdir" "${PANEL_DIR}/bin/musedock-listdir.c" 2>/dev/null && \
            echo "$CURRENT_MD5" > "${PANEL_DIR}/bin/.musedock-listdir.md5" && \
            chmod 755 "${PANEL_DIR}/bin/musedock-listdir" && \
            ok "musedock-listdir recompiled" || warn "musedock-listdir compilation failed"
    fi
fi

# Sync Fail2Ban configs if fail2ban is installed
if command -v fail2ban-client >/dev/null 2>&1 && [ -d "${PANEL_DIR}/config/fail2ban" ]; then
    F2B_CHANGED=false

    # Create log files if missing
    touch /var/log/musedock-panel-auth.log /var/log/musedock-portal-auth.log 2>/dev/null
    chmod 644 /var/log/musedock-panel-auth.log /var/log/musedock-portal-auth.log 2>/dev/null
    mkdir -p /var/log/caddy 2>/dev/null
    touch /var/log/caddy/hosting-access.log 2>/dev/null
    chmod 644 /var/log/caddy/hosting-access.log 2>/dev/null

    # Sync filters
    for f in "${PANEL_DIR}"/config/fail2ban/filter.d/*.conf; do
        [ -f "$f" ] || continue
        FNAME=$(basename "$f")
        if ! cmp -s "$f" "/etc/fail2ban/filter.d/${FNAME}" 2>/dev/null; then
            cp "$f" "/etc/fail2ban/filter.d/${FNAME}"
            F2B_CHANGED=true
        fi
    done

    # Sync jail config
    if ! cmp -s "${PANEL_DIR}/config/fail2ban/musedock.conf" /etc/fail2ban/jail.d/musedock.conf 2>/dev/null; then
        cp "${PANEL_DIR}/config/fail2ban/musedock.conf" /etc/fail2ban/jail.d/musedock.conf
        F2B_CHANGED=true
    fi

    # Sync logrotate
    if [ -f "${PANEL_DIR}/config/fail2ban/logrotate-musedock-auth" ]; then
        cp "${PANEL_DIR}/config/fail2ban/logrotate-musedock-auth" /etc/logrotate.d/musedock-auth 2>/dev/null
    fi

    if [ "$F2B_CHANGED" = true ] && systemctl is-active --quiet fail2ban 2>/dev/null; then
        fail2ban-client reload >/dev/null 2>&1
        ok "Fail2Ban configs updated and reloaded"
    else
        ok "Fail2Ban configs up to date"
    fi
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

# ============================================================
# Step 5: Clear update flags in panel DB
# ============================================================
$PHP_BIN -r "
define('PANEL_ROOT', '${PANEL_DIR}');
spl_autoload_register(function (\$c) {
    \$p = 'MuseDockPanel\\\\';
    if (strncmp(\$p, \$c, strlen(\$p)) !== 0) return;
    \$f = PANEL_ROOT.'/app/'.str_replace('\\\\','/',substr(\$c,strlen(\$p))).'.php';
    if (file_exists(\$f)) require \$f;
});
if (file_exists(PANEL_ROOT.'/.env')) MuseDockPanel\Env::load(PANEL_ROOT.'/.env');
MuseDockPanel\Settings::set('update_has_update', '0');
MuseDockPanel\Settings::set('update_in_progress', '0');
MuseDockPanel\Settings::set('update_remote_version', '${NEW_VERSION:-$CURRENT_VERSION}');
MuseDockPanel\Settings::set('update_last_check', (string)time());
" 2>/dev/null && ok "Update flags cleared" || warn "Could not clear update flags (non-critical)"

# ============================================================
# Step 6: Update Portal if installed
# ============================================================
PORTAL_DIR="/opt/musedock-portal"
if [ -d "$PORTAL_DIR" ] && [ -f "${PORTAL_DIR}/bin/update.sh" ]; then
    echo ""
    echo -e "${CYAN}${BOLD}[Portal]${NC} Updating MuseDock Portal..."
    bash "${PORTAL_DIR}/bin/update.sh"
elif [ -d "$PORTAL_DIR" ]; then
    echo ""
    warn "Portal installed but no update.sh found. Update manually."
fi

echo ""
echo -e "${GREEN}${BOLD}  Update complete!${NC}"
echo -e "  Version: ${BOLD}${NEW_VERSION:-$CURRENT_VERSION}${NC}"
echo ""
