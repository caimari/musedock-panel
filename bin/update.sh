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

mkdir -p "${PANEL_DIR}/storage/logs" 2>/dev/null || true
UPDATE_RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
UPDATE_AUDIT_LOG="${PANEL_DIR}/storage/logs/update-audit.log"
UPDATE_START_TS="$(date +%s)"
UPDATE_FINALIZED=0
UPDATE_STARTED_VERSION=""
UPDATE_TARGET_VERSION=""
UPDATE_LAST_STEP="startup"

# Keep a durable update audit log for manual runs too. Web updates still write
# their live output to storage/logs/update.log via UpdateService.
exec > >(tee -a "$UPDATE_AUDIT_LOG") 2>&1

panel_update_php() {
    local status="$1"
    local details="$2"
    local error="$3"
    local finished_at
    finished_at="$(date -u '+%Y-%m-%d %H:%M:%S UTC')"

    "$PHP_BIN" -r '
define("PANEL_ROOT", $argv[1]);
spl_autoload_register(function ($c) {
    $p = "MuseDockPanel\\";
    if (strncmp($p, $c, strlen($p)) !== 0) return;
    $f = PANEL_ROOT . "/app/" . str_replace("\\", "/", substr($c, strlen($p))) . ".php";
    if (file_exists($f)) require $f;
});
if (file_exists(PANEL_ROOT . "/.env")) MuseDockPanel\Env::load(PANEL_ROOT . "/.env");
$runId = $argv[2];
$status = $argv[3];
$from = $argv[4];
$to = $argv[5];
$details = $argv[6];
$error = $argv[7];
$finishedAt = $argv[8];
$host = trim((string)@shell_exec("hostname 2>/dev/null")) ?: "unknown";
try {
    MuseDockPanel\Settings::set("update_last_run_id", $runId);
    MuseDockPanel\Settings::set("update_last_status", $status);
    MuseDockPanel\Settings::set("update_last_finished_at", $finishedAt);
    MuseDockPanel\Settings::set("update_last_from_version", $from);
    MuseDockPanel\Settings::set("update_last_to_version", $to);
    MuseDockPanel\Settings::set("update_last_error", $error);
    MuseDockPanel\Settings::set("update_in_progress", "0");
    $action = $status === "success" ? "panel.update.success" : "panel.update.failed";
    MuseDockPanel\Database::insert("panel_log", [
        "admin_id" => null,
        "action" => $action,
        "target" => "update",
        "details" => trim("run_id={$runId}; {$details}" . ($error !== "" ? "; error={$error}" : "")),
        "ip_address" => "127.0.0.1",
    ]);
    if ($status !== "success") {
        $subject = "[MuseDock] Update FAILED on {$host}";
        $body = "Host: {$host}\nRun ID: {$runId}\nFrom: {$from}\nTarget/current: {$to}\nFinished: {$finishedAt}\n\nDetails:\n{$details}\n\nError:\n{$error}\n\nLog: " . PANEL_ROOT . "/storage/logs/update-audit.log";
        MuseDockPanel\Services\NotificationService::sendEventEmail("panel_update_failed_" . preg_replace("/[^a-z0-9_.-]+/i", "_", $host), $subject, $body, 300);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[update-audit] could not persist update status: " . $e->getMessage() . "\n");
}
' "$PANEL_DIR" "$UPDATE_RUN_ID" "$status" "${UPDATE_STARTED_VERSION:-unknown}" "${UPDATE_TARGET_VERSION:-unknown}" "$details" "$error" "$finished_at" >/dev/null 2>&1 || true
}

panel_update_started_php() {
    local started_at
    started_at="$(date -u '+%Y-%m-%d %H:%M:%S UTC')"

    "$PHP_BIN" -r '
define("PANEL_ROOT", $argv[1]);
spl_autoload_register(function ($c) {
    $p = "MuseDockPanel\\";
    if (strncmp($p, $c, strlen($p)) !== 0) return;
    $f = PANEL_ROOT . "/app/" . str_replace("\\", "/", substr($c, strlen($p))) . ".php";
    if (file_exists($f)) require $f;
});
if (file_exists(PANEL_ROOT . "/.env")) MuseDockPanel\Env::load(PANEL_ROOT . "/.env");
try {
    MuseDockPanel\Settings::set("update_last_run_id", $argv[2]);
    MuseDockPanel\Settings::set("update_last_status", "running");
    MuseDockPanel\Settings::set("update_last_started_at", $argv[4]);
    MuseDockPanel\Settings::set("update_in_progress", "1");
    MuseDockPanel\Database::insert("panel_log", [
        "admin_id" => null,
        "action" => "panel.update.running",
        "target" => "update",
        "details" => "run_id={$argv[2]}; from={$argv[3]}",
        "ip_address" => "127.0.0.1",
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "[update-audit] could not persist update start: " . $e->getMessage() . "\n");
}
' "$PANEL_DIR" "$UPDATE_RUN_ID" "${UPDATE_STARTED_VERSION:-unknown}" "$started_at" >/dev/null 2>&1 || true
}

update_finalize() {
    local rc=$?
    if [ "$UPDATE_FINALIZED" = "1" ]; then
        exit "$rc"
    fi
    UPDATE_FINALIZED=1

    local elapsed
    elapsed=$(( $(date +%s) - UPDATE_START_TS ))
    if [ "$rc" -eq 0 ]; then
        panel_update_php "success" "Update completed; step=${UPDATE_LAST_STEP}; elapsed=${elapsed}s" ""
    else
        panel_update_php "failed" "Update failed; step=${UPDATE_LAST_STEP}; elapsed=${elapsed}s" "Exit code ${rc}"
    fi
    exit "$rc"
}
trap update_finalize EXIT

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

env_value() {
    local key="$1"
    grep -E "^${key}=" "${PANEL_DIR}/.env" 2>/dev/null | tail -1 | cut -d= -f2- | sed 's/^["'\'']//; s/["'\'']$//'
}

is_local_db_host() {
    case "$1" in
        ""|"127.0.0.1"|"localhost"|"::1"|"/var/run/postgresql"|"/tmp") return 0 ;;
        *) return 1 ;;
    esac
}

local_pg_port_listening() {
    local port="${1:-5433}"
    ss -tln 2>/dev/null | grep -qE "127\\.0\\.0\\.1:${port}\\b|\\*:${port}\\b|0\\.0\\.0\\.0:${port}\\b|\\[::\\]:${port}\\b"
}

install_caddy_backup_cron() {
    if [ ! -x "${PANEL_DIR}/bin/backup-caddy-config.sh" ]; then
        return 0
    fi

    mkdir -p "${PANEL_DIR}/storage/backups/caddy" "${PANEL_DIR}/storage/logs" 2>/dev/null || true
    chown root:root "${PANEL_DIR}/storage/backups/caddy" 2>/dev/null || true
    chmod 0750 "${PANEL_DIR}/storage/backups/caddy" 2>/dev/null || true
    cat > /etc/cron.d/musedock-caddy-backup << CRONEOF
# MuseDock Panel — Daily Caddy config backup (15d rotation + last-known-good)
17 3 * * * root ${PANEL_DIR}/bin/backup-caddy-config.sh >> ${PANEL_DIR}/storage/logs/caddy-backup.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-caddy-backup
    ok "Caddy config backup cron installed/updated"

    # Create an immediate snapshot after update so recovery is available before
    # the next daily cron window.
    "${PANEL_DIR}/bin/backup-caddy-config.sh" >> "${PANEL_DIR}/storage/logs/caddy-backup.log" 2>&1 || \
        warn "Caddy config backup snapshot failed; see storage/logs/caddy-backup.log"
}

install_caddy_runtime_repair_override() {
    if ! command -v systemctl >/dev/null 2>&1 || ! systemctl list-unit-files caddy.service >/dev/null 2>&1; then
        return 0
    fi
    if [ ! -f "${PANEL_DIR}/cli/repair-caddy-routes.php" ]; then
        return 0
    fi

    mkdir -p /etc/systemd/system/caddy.service.d
    cat > /etc/systemd/system/caddy.service.d/zz-musedock-panel-repair.conf << OVERRIDEEOF
[Service]
ExecStartPost=/bin/sleep 5
ExecStartPost=${PHP_BIN} ${PANEL_DIR}/cli/repair-caddy-routes.php
ExecReload=/bin/sleep 5
ExecReload=${PHP_BIN} ${PANEL_DIR}/cli/repair-caddy-routes.php
OVERRIDEEOF
    chmod 644 /etc/systemd/system/caddy.service.d/zz-musedock-panel-repair.conf
    systemctl daemon-reload 2>/dev/null || true
    ok "Caddy runtime repair hook installed/updated"
}

ensure_database_ready() {
    local db_host db_port db_name db_user cluster_line pg_ver pg_cluster pg_status

    db_host=$(env_value DB_HOST)
    db_port=$(env_value DB_PORT)
    db_name=$(env_value DB_NAME)
    db_user=$(env_value DB_USER)

    db_host=${db_host:-127.0.0.1}
    db_port=${db_port:-5433}
    db_name=${db_name:-musedock_panel}
    db_user=${db_user:-musedock_panel}

    if ! command -v pg_isready >/dev/null 2>&1; then
        warn "pg_isready not found; skipping PostgreSQL readiness preflight"
        return 0
    fi

    if pg_isready -h "$db_host" -p "$db_port" -d "$db_name" -U "$db_user" -t 5 >/dev/null 2>&1; then
        ok "PostgreSQL panel DB is reachable (${db_host}:${db_port})"
        return 0
    fi
    if is_local_db_host "$db_host" && local_pg_port_listening "$db_port"; then
        ok "PostgreSQL panel port is listening (${db_host}:${db_port}); continuing to migration auth check"
        return 0
    fi

    warn "PostgreSQL panel DB is not reachable yet (${db_host}:${db_port})"

    if is_local_db_host "$db_host" && command -v pg_lsclusters >/dev/null 2>&1 && command -v pg_ctlcluster >/dev/null 2>&1; then
        cluster_line=$(pg_lsclusters --no-header 2>/dev/null | awk -v port="$db_port" '$3 == port {print $1" "$2" "$4; exit}')
        if [ -n "$cluster_line" ]; then
            read -r pg_ver pg_cluster pg_status <<< "$cluster_line"
            if [ "$pg_status" != "online" ]; then
                warn "Starting PostgreSQL cluster ${pg_ver}/${pg_cluster} on port ${db_port}"
                pg_ctlcluster "$pg_ver" "$pg_cluster" start >/dev/null 2>&1 || true
                sleep 2
            else
                warn "PostgreSQL cluster ${pg_ver}/${pg_cluster} is online but did not answer readiness check"
            fi
        else
            warn "No local PostgreSQL cluster found on port ${db_port}"
        fi
    fi

    if pg_isready -h "$db_host" -p "$db_port" -d "$db_name" -U "$db_user" -t 5 >/dev/null 2>&1; then
        ok "PostgreSQL panel DB recovered (${db_host}:${db_port})"
        return 0
    fi
    if is_local_db_host "$db_host" && local_pg_port_listening "$db_port"; then
        ok "PostgreSQL panel port recovered (${db_host}:${db_port}); continuing to migration auth check"
        return 0
    fi

    echo ""
    warn "Database is still unavailable. Run these diagnostics on the node:"
    echo "    sudo pg_lsclusters"
    echo "    sudo systemctl status 'postgresql@*-panel' --no-pager"
    echo "    sudo tail -n 80 /var/log/postgresql/postgresql-*-panel.log"
    echo ""
    fail "Cannot run migrations until PostgreSQL ${db_host}:${db_port} is reachable. If this was a partial install, run: sudo bash ${PANEL_DIR}/install.sh and choose Reinstalar."
}

# Get current version
CURRENT_VERSION=$(read_panel_version)
UPDATE_STARTED_VERSION="${CURRENT_VERSION:-unknown}"
UPDATE_TARGET_VERSION="${CURRENT_VERSION:-unknown}"
panel_update_started_php

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
UPDATE_LAST_STEP="pull"
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
set +e
PULL_OUTPUT=$(git pull --ff-only origin main 2>&1)
PULL_RC=$?
set -e
echo "$PULL_OUTPUT" | sed 's/^/    /'
if [ $PULL_RC -ne 0 ]; then
    fail "git pull failed. Resolve local/untracked conflicts and retry."
fi
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
    UPDATE_FINALIZED=1
    exec bash "$SELF_SCRIPT" ${1:+"$1"}
fi

echo ""

# Read new version
NEW_VERSION=$(read_panel_version)
UPDATE_TARGET_VERSION="${NEW_VERSION:-$CURRENT_VERSION}"

if [ -n "$NEW_VERSION" ] && [ "$NEW_VERSION" != "$CURRENT_VERSION" ]; then
    echo -e "  ${GREEN}${BOLD}Version: ${CURRENT_VERSION} → ${NEW_VERSION}${NC}"
    echo ""
fi

# ============================================================
# Step 2: Database migrations
# ============================================================
UPDATE_LAST_STEP="migrations"
echo -e "${CYAN}${BOLD}[2/4]${NC} Running database migrations..."
echo ""

ensure_database_ready

set +e
MIG_OUT=$($PHP_BIN "${PANEL_DIR}/bin/migrate.php" 2>&1)
MIG_RC=$?
set -e
echo "$MIG_OUT" | sed 's/^/  /'
if [ $MIG_RC -ne 0 ]; then
    fail "Database migrations failed. Fix migrations and retry update."
fi

echo ""

# ============================================================
# Step 3: Clear cache
# ============================================================
UPDATE_LAST_STEP="maintenance"
echo -e "${CYAN}${BOLD}[3/4]${NC} Clearing cache..."

rm -rf "${PANEL_DIR}/storage/cache/"* 2>/dev/null || true
ok "Cache cleared"

# Enforce monitor cron (network/cpu/ram + event watch: firewall/reboot/gaps)
cat > /etc/cron.d/musedock-monitor << CRONEOF
# MuseDock Panel — Network/system monitoring collector (every 30s)
# Includes event watchers: firewall external changes, server reboot, monitor gaps
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
* * * * * root sleep 30 && /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
CRONEOF
chmod 644 /etc/cron.d/musedock-monitor
systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
ok "Monitor cron ensured"

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

        # verify-caddy-status: force safe mode by default (no background Caddy rewrites)
        if echo "$CURRENT_CRONTAB" | grep -Eq '^[^#].*verify-caddy-status\.php' && ! echo "$CURRENT_CRONTAB" | grep -Eq '^[^#].*verify-caddy-status\.php.*MUSEDOCK_CADDY_CRON_AUTOREPAIR='; then
            CURRENT_CRONTAB=$(echo "$CURRENT_CRONTAB" | sed -E 's#^([0-9*/,.-]+[[:space:]]+[0-9*/,.-]+[[:space:]]+[0-9*/,.-]+[[:space:]]+[0-9*/,.-]+[[:space:]]+[0-9*/,.-]+[[:space:]]+)(.*verify-caddy-status\.php.*)$#\1MUSEDOCK_CADDY_CRON_AUTOREPAIR=0 \2#')
            NEEDS_UPDATE=true
        fi

        # verify-caddy-status: normalize duplicated env prefix from old updater runs
        NORMALIZED_VERIFY=$(echo "$CURRENT_CRONTAB" | sed -E '/verify-caddy-status\.php/ s#(MUSEDOCK_CADDY_CRON_AUTOREPAIR=0[[:space:]]+)+#MUSEDOCK_CADDY_CRON_AUTOREPAIR=0 #g')
        if [ "$NORMALIZED_VERIFY" != "$CURRENT_CRONTAB" ]; then
            CURRENT_CRONTAB="$NORMALIZED_VERIFY"
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

        # If verify-caddy-status cron uses legacy hard require and plugin path is missing,
        # disable the cron line to avoid fatal loop on nodes without that plugin.
        VERIFY_SCRIPT="${CMS_DIR}/cron/verify-caddy-status.php"
        LEGACY_REQUIRE=false
        if [ -f "$VERIFY_SCRIPT" ] && grep -q "require_once \$pluginPath . '/Services/CaddyService.php'" "$VERIFY_SCRIPT" 2>/dev/null; then
            LEGACY_REQUIRE=true
        fi
        CADDY_PLUGIN_FILE="${CMS_DIR}/plugins/superadmin/caddy-domain-manager/Services/CaddyService.php"
        if [ "$LEGACY_REQUIRE" = true ] && [ ! -f "$CADDY_PLUGIN_FILE" ] && echo "$CURRENT_CRONTAB" | grep -Eq '^[^#].*verify-caddy-status\.php'; then
            CURRENT_CRONTAB=$(echo "$CURRENT_CRONTAB" | sed -E 's#^([^#].*verify-caddy-status\.php.*)$## DISABLED_BY_MUSEDOCK_UPDATE: missing caddy-domain-manager plugin -> \1#')
            NEEDS_UPDATE=true
            warn "Disabled CMS verify-caddy-status cron for $CMS_USER (missing caddy-domain-manager plugin)"
        fi

        if [ "$NEEDS_UPDATE" = true ]; then
            echo "$CURRENT_CRONTAB" | crontab -u "$CMS_USER" -
            ok "CMS crons staggered for user $CMS_USER"
        fi
    fi
fi

# Stagger and harden hourly panel backup. The cron must run as root because
# shell redirection happens before/around pg_dump; pg_dump itself still runs
# as postgres.
if [ ! -f /etc/cron.d/musedock-backup ] || grep -q 'postgres pg_dump -p 5433 musedock_panel' /etc/cron.d/musedock-backup 2>/dev/null || ! grep -q 'install -d -o postgres -g www-data -m 0770' /etc/cron.d/musedock-backup 2>/dev/null; then
    install -d -o postgres -g www-data -m 0770 "${PANEL_DIR}/storage/backups" 2>/dev/null || true
    cat > /etc/cron.d/musedock-backup << CRONEOF
# MuseDock Panel — Hourly panel DB backup (at :02 to avoid cron storm at :00)
2 * * * * root install -d -o postgres -g www-data -m 0770 ${PANEL_DIR}/storage/backups && runuser -u postgres -- pg_dump -p 5433 musedock_panel | gzip > ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chown postgres:www-data ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chmod 0640 ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz
# Cleanup backups older than 48 hours (at :07)
7 * * * * root find ${PANEL_DIR}/storage/backups/ -name "panel-*.sql.gz" -mmin +2880 -delete 2>/dev/null
CRONEOF
    chmod 644 /etc/cron.d/musedock-backup
    ok "Panel backup cron hardened and staggered to :02/:07"
fi

install_caddy_backup_cron
install_caddy_runtime_repair_override

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

repair_panel_tls_caddy() {
    if ! command -v caddy >/dev/null 2>&1; then
        warn "Caddy not installed; skipping panel TLS repair"
        return 0
    fi

    local caddy_file="/etc/caddy/Caddyfile"
    if [ ! -f "$caddy_file" ]; then
        warn "Caddyfile not found; skipping panel TLS repair"
        return 0
    fi

    local panel_port panel_internal_port server_ip all_panel_ips panel_site_labels existing_sites backup_file ip
    panel_port=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    panel_port=${panel_port:-8444}
    panel_internal_port=$((panel_port + 1))
    server_ip=$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -1)
    all_panel_ips=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u | tr '\n' ' ')
    [ -z "$server_ip" ] && server_ip=$(echo "$all_panel_ips" | awk '{print $1}')
    [ -z "$server_ip" ] && server_ip="127.0.0.1"
    panel_site_labels="https://${server_ip}:${panel_port}"
    for ip in $all_panel_ips 127.0.0.1; do
        [ -n "$ip" ] || continue
        case ",$panel_site_labels," in
            *,"https://${ip}:${panel_port}",*) ;;
            *) panel_site_labels="${panel_site_labels}, https://${ip}:${panel_port}" ;;
        esac
    done
    panel_site_labels="${panel_site_labels}, https://localhost:${panel_port}"

    existing_sites=$(awk '
        /^{$/ && NR<=5 { in_global=1; next }
        in_global && /^}$/ { in_global=0; next }
        in_global { next }
        /^https?:\/\/:'"${panel_port}"'/ || /^https?:\/\/[^ ]*:'"${panel_port}"'/ || /^:'"${panel_port}"'/ {
            in_panel=1
            line=$0
            opens=gsub(/\{/, "{", line)
            line=$0
            closes=gsub(/\}/, "}", line)
            depth=opens-closes
            if(depth<=0) depth=1
            next
        }
        in_panel {
            line=$0
            opens=gsub(/\{/, "{", line)
            line=$0
            closes=gsub(/\}/, "}", line)
            depth += opens-closes
            if(depth<=0) in_panel=0
            next
        }
        { print }
    ' "$caddy_file" 2>/dev/null)

    backup_file="${caddy_file}.bak.$(date +%Y%m%d%H%M%S)"
    cp "$caddy_file" "$backup_file" 2>/dev/null || true

    cat > "$caddy_file" << CADDYEOF
{
    auto_https disable_redirects
    admin localhost:2019
}

${panel_site_labels} {
    tls internal
    reverse_proxy 127.0.0.1:${panel_internal_port} {
        header_up X-Forwarded-Proto https
        header_up X-Real-Ip {remote_host}
    }
}
CADDYEOF

    if [ -n "$(echo "$existing_sites" | tr -d '[:space:]')" ]; then
        echo "" >> "$caddy_file"
        echo "$existing_sites" >> "$caddy_file"
    fi

    if caddy validate --config "$caddy_file" >/dev/null 2>&1; then
        systemctl daemon-reload 2>/dev/null || true
        systemctl restart caddy 2>/dev/null || true
        ok "Panel TLS Caddy block repaired for https://${server_ip}:${panel_port}"
    else
        warn "Generated Caddyfile failed validation; restoring previous file"
        caddy validate --config "$caddy_file" 2>&1 | sed 's/^/    /' || true
        cp "$backup_file" "$caddy_file" 2>/dev/null || true
    fi
}

# ============================================================
# Step 4: Restart service
# ============================================================
UPDATE_LAST_STEP="restart"
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

    # First repair the persistent IP fallback Caddyfile. This can restart Caddy and
    # drop runtime API routes, so the runtime repair must run after it.
    repair_panel_tls_caddy

    # Repair Caddy runtime routes/listeners after any Caddyfile restart (best effort).
    if [ -f "${PANEL_DIR}/cli/repair-caddy-routes.php" ]; then
        REPAIR_OUT=$($PHP_BIN "${PANEL_DIR}/cli/repair-caddy-routes.php" 2>&1 || true)
        if echo "$REPAIR_OUT" | grep -q "\[repair-caddy\] ERROR"; then
            warn "Caddy auto-repair reported issues (run: php ${PANEL_DIR}/cli/repair-caddy-routes.php)"
            echo "$REPAIR_OUT" | sed 's/^/    /'
        else
            ok "Caddy routes/listeners repaired"
        fi
    fi
else
    warn "Panel service not running — start it with: systemctl start musedock-panel"
fi

# ============================================================
# Step 5: Clear update flags in panel DB
# ============================================================
# Warm-up monitor collector once after update so security/event watchers
# (firewall drift/reboot/gap/hardening/exposure) initialize immediately
# without waiting for next cron tick.
echo -e "${CYAN}${BOLD}[Warm-up]${NC} Running monitor collector warm-up..."
UPDATE_LAST_STEP="monitor-warmup"
if [ -f "${PANEL_DIR}/bin/monitor-collector.php" ]; then
    set +e
    COLLECTOR_OUT=$($PHP_BIN "${PANEL_DIR}/bin/monitor-collector.php" 2>&1)
    COLLECTOR_RC=$?
    set -e
    if [ $COLLECTOR_RC -eq 0 ]; then
        ok "Monitor collector warm-up completed"
    else
        warn "Monitor collector warm-up failed (non-critical)"
        echo "$COLLECTOR_OUT" | sed 's/^/    /'
    fi
else
    warn "monitor-collector.php not found; skipping warm-up"
fi

# ============================================================
# Step 6: Clear update flags in panel DB
# ============================================================
UPDATE_LAST_STEP="clear-flags"
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
# Step 7: Update Portal if installed
# ============================================================
PORTAL_DIR="/opt/musedock-portal"
if [ -d "$PORTAL_DIR" ] && [ -f "${PORTAL_DIR}/bin/update.sh" ]; then
    echo ""
    UPDATE_LAST_STEP="portal-update"
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
