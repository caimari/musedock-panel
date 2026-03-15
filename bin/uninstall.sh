#!/bin/bash
# ============================================================
# MuseDock Panel — Uninstaller
# Safely removes the panel and its configuration.
# Does NOT remove Caddy, PostgreSQL, MySQL, or PHP by default.
# Run as root: sudo bash uninstall.sh
# ============================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

header() { echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}\n"; }
ok()     { echo -e "  ${GREEN}✓${NC} $1"; }
warn()   { echo -e "  ${YELLOW}!${NC} $1"; }
fail()   { echo -e "  ${RED}✗ $1${NC}"; exit 1; }

# Must be root
if [ "$EUID" -ne 0 ]; then
    fail "This uninstaller must be run as root (sudo bash uninstall.sh)"
fi

# Resolve PANEL_DIR
PANEL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo ""
echo -e "${RED}${BOLD}"
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║        MuseDock Panel — Uninstaller              ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  Panel dir: ${BOLD}${PANEL_DIR}${NC}"
echo ""

# Check if panel is actually installed
if [ ! -f "${PANEL_DIR}/.env" ] && [ ! -f /etc/systemd/system/musedock-panel.service ]; then
    warn "No MuseDock Panel installation detected."
    exit 0
fi

# Read config from .env if available
DB_NAME="musedock_panel"
DB_USER="musedock_panel"
DB_PASS=""
PANEL_PORT="8444"

if [ -f "${PANEL_DIR}/.env" ]; then
    DB_NAME=$(grep -E '^DB_NAME=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
    DB_USER=$(grep -E '^DB_USER=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
    DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
    PANEL_PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8444")
fi

# ============================================================
# Step 0: Check for active hosting accounts
# ============================================================
ACTIVE_ACCOUNTS=0
ACTIVE_DOMAINS=""

if [ -n "$DB_PASS" ]; then
    # Query active hosting accounts from the panel database
    ACTIVE_ACCOUNTS=$(PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h 127.0.0.1 -d "${DB_NAME}" -t -A \
        -c "SELECT COUNT(*) FROM hosting_accounts WHERE status = 'active';" 2>/dev/null || echo "0")
    ACTIVE_ACCOUNTS=$(echo "$ACTIVE_ACCOUNTS" | tr -d '[:space:]')

    if [ "$ACTIVE_ACCOUNTS" -gt 0 ] 2>/dev/null; then
        # Get list of active domains
        ACTIVE_DOMAINS=$(PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h 127.0.0.1 -d "${DB_NAME}" -t -A \
            -c "SELECT domain FROM hosting_accounts WHERE status = 'active' ORDER BY domain;" 2>/dev/null || echo "")
    fi
elif sudo -u postgres psql -d "${DB_NAME}" -c "SELECT 1;" > /dev/null 2>&1; then
    ACTIVE_ACCOUNTS=$(sudo -u postgres psql -d "${DB_NAME}" -t -A \
        -c "SELECT COUNT(*) FROM hosting_accounts WHERE status = 'active';" 2>/dev/null || echo "0")
    ACTIVE_ACCOUNTS=$(echo "$ACTIVE_ACCOUNTS" | tr -d '[:space:]')

    if [ "$ACTIVE_ACCOUNTS" -gt 0 ] 2>/dev/null; then
        ACTIVE_DOMAINS=$(sudo -u postgres psql -d "${DB_NAME}" -t -A \
            -c "SELECT domain FROM hosting_accounts WHERE status = 'active' ORDER BY domain;" 2>/dev/null || echo "")
    fi
fi

if [ "$ACTIVE_ACCOUNTS" -gt 0 ] 2>/dev/null; then
    echo -e "  ${RED}${BOLD}╔══════════════════════════════════════════════════╗${NC}"
    echo -e "  ${RED}${BOLD}║  WARNING: Active hosting accounts detected!      ║${NC}"
    echo -e "  ${RED}${BOLD}╚══════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "  ${YELLOW}There are ${BOLD}${ACTIVE_ACCOUNTS} active hosting account(s)${NC}${YELLOW} on this server:${NC}"
    echo ""

    # Show domains (max 15, then summarize)
    SHOWN=0
    while IFS= read -r domain; do
        [ -z "$domain" ] && continue
        echo -e "    ${BOLD}${domain}${NC}"
        SHOWN=$((SHOWN + 1))
        if [ "$SHOWN" -ge 15 ]; then
            REMAINING=$((ACTIVE_ACCOUNTS - SHOWN))
            if [ "$REMAINING" -gt 0 ]; then
                echo -e "    ${YELLOW}... and ${REMAINING} more${NC}"
            fi
            break
        fi
    done <<< "$ACTIVE_DOMAINS"

    echo ""
    echo -e "  ${YELLOW}These sites are currently live and served by Caddy.${NC}"
    echo -e "  ${YELLOW}After uninstalling the panel:${NC}"
    echo -e "  ${YELLOW}  - The sites will ${BOLD}continue working${NC}${YELLOW} (Caddy is not removed)${NC}"
    echo -e "  ${YELLOW}  - FPM pools will ${BOLD}continue running${NC}${YELLOW} (PHP-FPM is not removed)${NC}"
    echo -e "  ${YELLOW}  - You will ${BOLD}NOT be able to manage${NC}${YELLOW} them from the panel${NC}"
    echo -e "  ${YELLOW}  - No new accounts, domains, or databases can be created${NC}"
    echo ""
    read -rp "  Continue with uninstall? [y/N] " ACTIVE_CONFIRM
    if [[ ! "$ACTIVE_CONFIRM" =~ ^[Yy]$ ]]; then
        echo ""
        echo -e "  Uninstall cancelled. Panel preserved."
        exit 0
    fi
    echo ""
fi

echo -e "  ${YELLOW}${BOLD}This will remove the MuseDock Panel from this server.${NC}"
echo ""
echo -e "  What will be removed:"
echo -e "    - Panel systemd service (musedock-panel)"
echo -e "    - Panel .env configuration"
echo -e "    - Panel Caddy API routes (if any)"
echo -e "    - Caddy service override (--resume flag)"
echo ""
echo -e "  What will ${BOLD}NOT${NC} be removed (shared services):"
echo -e "    - Caddy (web server)"
echo -e "    - PostgreSQL (database server)"
echo -e "    - MySQL/MariaDB"
echo -e "    - PHP / PHP-FPM"
echo -e "    - Client vhosts in /var/www/vhosts/"
echo ""
echo -e "  ${YELLOW}${BOLD}You will be asked about the database separately.${NC}"
echo ""
read -rp "  Proceed with uninstall? [y/N] " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "  Uninstall cancelled."
    exit 0
fi

# ============================================================
# Step 1: Stop and disable panel service
# ============================================================
header "Step 1/5 — Stopping panel service"

if systemctl is-active --quiet musedock-panel 2>/dev/null; then
    systemctl stop musedock-panel
    ok "Panel service stopped"
else
    ok "Panel service was not running"
fi

if systemctl is-enabled --quiet musedock-panel 2>/dev/null; then
    systemctl disable musedock-panel > /dev/null 2>&1
    ok "Panel service disabled"
fi

if [ -f /etc/systemd/system/musedock-panel.service ]; then
    rm -f /etc/systemd/system/musedock-panel.service
    systemctl daemon-reload
    ok "Service file removed"
else
    ok "Service file already absent"
fi

# ============================================================
# Step 2: Remove panel Caddy routes (via API)
# ============================================================
header "Step 2/5 — Cleaning Caddy configuration"

# Remove routes added by the panel via Caddy API
CADDY_API="http://localhost:2019"
if curl -s -o /dev/null -w "%{http_code}" --max-time 3 "${CADDY_API}/config/" 2>/dev/null | grep -q "200"; then
    # Get all route IDs that belong to the panel (identified by panel port or panel markers)
    # The panel adds routes via the API — we attempt to remove them
    # This is best-effort; if Caddy is not running, routes are in autosave.json
    ROUTES_JSON=$(curl -s "${CADDY_API}/config/apps/http/servers/srv0/routes" 2>/dev/null || echo "[]")
    ROUTE_COUNT=$(echo "$ROUTES_JSON" | python3 -c "import sys,json; data=json.load(sys.stdin); print(len(data))" 2>/dev/null || echo "0")

    if [ "$ROUTE_COUNT" -gt 0 ] 2>/dev/null; then
        echo -e "  ${YELLOW}Found ${ROUTE_COUNT} Caddy API routes.${NC}"
        echo -e "  ${YELLOW}Note: Only panel-managed routes should be removed.${NC}"
        echo -e "  ${YELLOW}If other apps (MuseDock CMS) share this Caddy, their routes are preserved.${NC}"
        echo ""
        echo "  Options:"
        echo "    1) Leave all Caddy routes (safe — recommended if other apps use Caddy)"
        echo "    2) Remove ALL Caddy API routes (only if Caddy is used exclusively by the panel)"
        echo ""
        read -rp "  Choose [1/2] (default: 1): " CADDY_CLEAN_CHOICE
        CADDY_CLEAN_CHOICE=${CADDY_CLEAN_CHOICE:-1}

        if [ "$CADDY_CLEAN_CHOICE" = "2" ]; then
            curl -s -X DELETE "${CADDY_API}/config/apps/http/servers/srv0/routes" > /dev/null 2>&1
            ok "All Caddy API routes removed"
        else
            ok "Caddy routes preserved"
        fi
    else
        ok "No Caddy API routes to clean"
    fi
else
    warn "Caddy API not responding — skipping route cleanup"
    warn "If Caddy has panel routes in autosave.json, you may need to remove them manually"
fi

# Remove the --resume override ONLY if user wants to
if [ -f /etc/systemd/system/caddy.service.d/override-resume.conf ]; then
    echo ""
    echo -e "  ${YELLOW}Caddy has a --resume override installed by the panel.${NC}"
    echo -e "  ${YELLOW}This keeps API routes persistent across restarts.${NC}"
    echo ""
    echo "  Options:"
    echo "    1) Keep --resume override (recommended if other apps use Caddy API)"
    echo "    2) Remove --resume override (revert Caddy to default Caddyfile-only mode)"
    echo ""
    read -rp "  Choose [1/2] (default: 1): " RESUME_CHOICE
    RESUME_CHOICE=${RESUME_CHOICE:-1}

    if [ "$RESUME_CHOICE" = "2" ]; then
        rm -f /etc/systemd/system/caddy.service.d/override-resume.conf
        rmdir /etc/systemd/system/caddy.service.d 2>/dev/null || true
        systemctl daemon-reload
        systemctl restart caddy 2>/dev/null || true
        ok "Caddy --resume override removed, Caddy restarted"
    else
        ok "Caddy --resume override preserved"
    fi
fi

# ============================================================
# Step 3: Database cleanup
# ============================================================
header "Step 3/5 — Database cleanup"

echo -e "  ${YELLOW}${BOLD}The panel database contains all panel configuration and logs.${NC}"
echo ""
echo "  Options:"
echo "    1) Keep database '${DB_NAME}' (recommended — allows clean reinstall)"
echo "    2) Drop database AND user (permanent — all panel data will be lost)"
echo ""
read -rp "  Choose [1/2] (default: 1): " DB_CHOICE
DB_CHOICE=${DB_CHOICE:-1}

if [ "$DB_CHOICE" = "2" ]; then
    echo ""
    echo -e "  ${RED}${BOLD}WARNING: This will permanently delete ALL data in '${DB_NAME}'.${NC}"
    read -rp "  Type 'DELETE' to confirm: " DB_CONFIRM
    if [ "$DB_CONFIRM" = "DELETE" ]; then
        # Try peer auth, then password auth
        PG_DROPPED=false
        if sudo -u postgres psql -c "DROP DATABASE IF EXISTS ${DB_NAME};" > /dev/null 2>&1; then
            PG_DROPPED=true
        elif [ -n "$DB_PASS" ]; then
            # Try via panel user to get a connection, but we need superuser for DROP
            # Fall back to asking for postgres password
            echo -e "  ${YELLOW}Peer auth failed. Enter PostgreSQL superuser password:${NC}"
            read -rsp "  Password: " PG_DROP_PASS
            echo ""
            if PGPASSWORD="$PG_DROP_PASS" psql -U postgres -h 127.0.0.1 -c "DROP DATABASE IF EXISTS ${DB_NAME};" > /dev/null 2>&1; then
                PG_DROPPED=true
            fi
        fi

        if [ "$PG_DROPPED" = true ]; then
            ok "Database '${DB_NAME}' dropped"
            # Drop user
            sudo -u postgres psql -c "DROP USER IF EXISTS ${DB_USER};" > /dev/null 2>&1 || \
                PGPASSWORD="$PG_DROP_PASS" psql -U postgres -h 127.0.0.1 -c "DROP USER IF EXISTS ${DB_USER};" > /dev/null 2>&1 || true
            ok "User '${DB_USER}' dropped"
        else
            warn "Could not drop database — you may need to do this manually:"
            echo -e "    ${CYAN}sudo -u postgres psql -c \"DROP DATABASE IF EXISTS ${DB_NAME};\"${NC}"
            echo -e "    ${CYAN}sudo -u postgres psql -c \"DROP USER IF EXISTS ${DB_USER};\"${NC}"
        fi

        # Clean up pg_hba.conf entry
        PG_HBA=$(sudo -u postgres psql -t -c "SHOW hba_file;" 2>/dev/null | xargs || find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1)
        if [ -n "$PG_HBA" ] && [ -f "$PG_HBA" ]; then
            if grep -q "${DB_USER}" "$PG_HBA" 2>/dev/null; then
                sed -i "/${DB_USER}/d" "$PG_HBA"
                systemctl reload postgresql 2>/dev/null || true
                ok "Removed ${DB_USER} entry from pg_hba.conf"
            fi
        fi
    else
        ok "Database deletion cancelled — database preserved"
    fi
else
    ok "Database '${DB_NAME}' preserved (user: ${DB_USER})"
fi

# ============================================================
# Step 4: Remove panel configuration files
# ============================================================
header "Step 4/5 — Removing panel configuration"

# Backup .env before removing (in case user wants to recover)
if [ -f "${PANEL_DIR}/.env" ]; then
    UNINSTALL_TS=$(date +%Y%m%d%H%M%S)
    cp "${PANEL_DIR}/.env" "${PANEL_DIR}/.env.uninstall.${UNINSTALL_TS}"
    rm -f "${PANEL_DIR}/.env"
    ok ".env removed (backup: .env.uninstall.${UNINSTALL_TS})"
fi

# Remove sessions and cache (but keep logs)
if [ -d "${PANEL_DIR}/storage/sessions" ]; then
    rm -rf "${PANEL_DIR}/storage/sessions"
    mkdir -p "${PANEL_DIR}/storage/sessions"
    ok "Sessions cleared"
fi

if [ -d "${PANEL_DIR}/storage/cache" ]; then
    rm -rf "${PANEL_DIR}/storage/cache"
    mkdir -p "${PANEL_DIR}/storage/cache"
    ok "Cache cleared"
fi

# Keep logs for forensics
if [ -d "${PANEL_DIR}/storage/logs" ]; then
    ok "Logs preserved in ${PANEL_DIR}/storage/logs/"
fi

# ============================================================
# Step 5: Remove sudoers entries (if any)
# ============================================================
header "Step 5/5 — Cleaning up system entries"

# Check for any musedock-related sudoers files
SUDOERS_CLEANED=false
for sudoers_file in /etc/sudoers.d/musedock*; do
    if [ -f "$sudoers_file" ] 2>/dev/null; then
        rm -f "$sudoers_file"
        ok "Removed sudoers entry: $(basename "$sudoers_file")"
        SUDOERS_CLEANED=true
    fi
done
if [ "$SUDOERS_CLEANED" = false ]; then
    ok "No sudoers entries to clean"
fi

# Remove logrotate config if exists
if [ -f /etc/logrotate.d/musedock-panel ]; then
    rm -f /etc/logrotate.d/musedock-panel
    ok "Removed logrotate configuration"
fi

# ============================================================
# Done
# ============================================================
echo ""
echo -e "${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║        Uninstall completed!                      ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""
echo -e "  ${BOLD}What was removed:${NC}"
echo "    - musedock-panel systemd service"
echo "    - Panel .env configuration"
echo "    - Sessions and cache"
if [ "${DB_CHOICE}" = "2" ] && [ "${DB_CONFIRM}" = "DELETE" ]; then
echo "    - Database '${DB_NAME}' and user '${DB_USER}'"
fi
echo ""
echo -e "  ${BOLD}What was preserved:${NC}"
echo "    - Panel source code in ${PANEL_DIR}/"
echo "    - Panel logs in ${PANEL_DIR}/storage/logs/"
echo "    - Install snapshots in ${PANEL_DIR}/install-backup/"
if [ "${DB_CHOICE}" != "2" ] || [ "${DB_CONFIRM}" != "DELETE" ]; then
echo "    - Database '${DB_NAME}' (user: ${DB_USER})"
fi
echo "    - Caddy, PostgreSQL, MySQL, PHP (shared services)"
echo "    - Client vhosts in /var/www/vhosts/"
echo ""
echo -e "  ${BOLD}To reinstall:${NC}"
echo -e "    ${CYAN}sudo bash ${PANEL_DIR}/install.sh${NC}"
echo ""
echo -e "  ${BOLD}To fully remove source code:${NC}"
echo -e "    ${CYAN}rm -rf ${PANEL_DIR}${NC}"
echo ""
