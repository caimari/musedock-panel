#!/bin/bash
# ============================================================
# MuseDock Panel — Automated Installer
# Supported: Ubuntu 22.04/24.04, Debian 12
# Run as root: sudo bash install.sh
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
ask()    { read -rp "  $1: " "$2"; }

# ============================================================
# Pre-flight checks
# ============================================================

# 1. Must be root
if [ "$EUID" -ne 0 ]; then
    fail "This installer must be run as root (sudo bash install.sh)"
fi

# 2. Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_ID="$ID"
    OS_VERSION="$VERSION_ID"
else
    fail "Cannot detect OS. Only Ubuntu 22.04+ and Debian 12+ are supported."
fi

case "$OS_ID" in
    ubuntu)
        if [[ "$OS_VERSION" < "22.04" ]]; then
            fail "Ubuntu $OS_VERSION not supported. Minimum: 22.04"
        fi
        ;;
    debian)
        if [[ "$OS_VERSION" < "12" ]]; then
            fail "Debian $OS_VERSION not supported. Minimum: 12"
        fi
        ;;
    *)
        fail "OS '$OS_ID' not supported. Only Ubuntu 22.04+ and Debian 12+ are supported."
        ;;
esac

# Resolve PANEL_DIR (directory where this script lives)
PANEL_DIR="$(cd "$(dirname "$0")" && pwd)"

echo ""
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════╗"
echo "  ║         MuseDock Panel Installer         ║"
echo "  ║            v0.1.0 — $(date +%Y)                ║"
echo "  ╚══════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  OS:          ${BOLD}${OS_ID} ${OS_VERSION}${NC}"
echo -e "  Install dir: ${BOLD}${PANEL_DIR}${NC}"
echo ""

# 3. Check for existing installation
REINSTALL=false
if [ -f "${PANEL_DIR}/.env" ]; then
    echo -e "  ${YELLOW}${BOLD}An existing installation has been detected.${NC}"
    echo -e "  ${YELLOW}Found: ${PANEL_DIR}/.env${NC}"
    echo ""
    read -rp "  Reinstall? This will NOT delete the existing database. [y/N] " REINSTALL_CONFIRM
    if [[ ! "$REINSTALL_CONFIRM" =~ ^[Yy]$ ]]; then
        echo ""
        echo -e "  Installation cancelled. Existing installation preserved."
        exit 0
    fi
    REINSTALL=true
    echo ""
    ok "Reinstall mode — existing .env will be backed up"
fi

# ============================================================
# Configuration prompts
# ============================================================
header "Configuration"

PANEL_PORT=8444

# Panel port
ask "Panel port (default: 8444)" USER_PORT
PANEL_PORT=${USER_PORT:-8444}

# PHP version
echo ""
echo -e "  ${BOLD}PHP Version${NC}"
echo "  Available: 8.1, 8.2, 8.3, 8.4"
ask "PHP version (default: 8.3)" PHP_VER
PHP_VER=${PHP_VER:-8.3}

# Validate PHP version
case "$PHP_VER" in
    8.1|8.2|8.3|8.4) ;;
    *) warn "Invalid PHP version. Using 8.3"; PHP_VER="8.3" ;;
esac

echo ""
echo -e "  ${BOLD}Summary:${NC}"
echo "  - Panel port:   $PANEL_PORT"
echo "  - PHP version:  $PHP_VER"
echo "  - Install dir:  $PANEL_DIR"
echo -e "  - Admin setup:  ${CYAN}via web wizard on first access${NC}"
echo ""

read -rp "  Proceed with installation? [Y/n] " CONFIRM
CONFIRM=${CONFIRM:-Y}
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "  Installation cancelled."
    exit 0
fi

# ============================================================
# Generate database password
# ============================================================
DB_PASS=$(openssl rand -hex 16)
DB_NAME="musedock_panel"
DB_USER="musedock_panel"

# ============================================================
# Step 1: System packages
# ============================================================
header "Step 1/7 — Installing system packages"

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
ok "Package lists updated"

apt-get install -y -qq curl wget gnupg2 lsb-release apt-transport-https ca-certificates \
    software-properties-common unzip git acl > /dev/null 2>&1
ok "Essential packages installed"

# ============================================================
# Step 2: PHP
# ============================================================
header "Step 2/7 — Installing PHP $PHP_VER"

# Add Ondrej PPA for PHP (check multiple possible filenames)
PHP_REPO_EXISTS=false
for f in /etc/apt/sources.list.d/ondrej-*.list /etc/apt/sources.list.d/php.list /etc/apt/sources.list.d/ondrej-*.sources; do
    if [ -f "$f" ] 2>/dev/null; then
        PHP_REPO_EXISTS=true
        break
    fi
done

if [ "$PHP_REPO_EXISTS" = false ]; then
    if [ "$OS_ID" = "ubuntu" ]; then
        add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
    else
        curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb 2>/dev/null
        dpkg -i /tmp/debsuryorg-archive-keyring.deb > /dev/null 2>&1
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        rm -f /tmp/debsuryorg-archive-keyring.deb
    fi
    apt-get update -qq
    ok "PHP repository added"
else
    ok "PHP repository already configured"
fi

apt-get install -y -qq \
    php${PHP_VER}-cli php${PHP_VER}-fpm php${PHP_VER}-pgsql php${PHP_VER}-curl \
    php${PHP_VER}-mbstring php${PHP_VER}-xml php${PHP_VER}-zip php${PHP_VER}-gd \
    php${PHP_VER}-intl php${PHP_VER}-bcmath php${PHP_VER}-mysql > /dev/null 2>&1
ok "PHP $PHP_VER + extensions installed"

systemctl enable php${PHP_VER}-fpm > /dev/null 2>&1
systemctl start php${PHP_VER}-fpm
ok "PHP-FPM $PHP_VER started"

# ============================================================
# Step 3: PostgreSQL
# ============================================================
header "Step 3/7 — Installing PostgreSQL"

if ! command -v psql &> /dev/null; then
    apt-get install -y -qq postgresql postgresql-client > /dev/null 2>&1
    ok "PostgreSQL installed"
else
    ok "PostgreSQL already installed"
fi

systemctl enable postgresql > /dev/null 2>&1
systemctl start postgresql
ok "PostgreSQL running"

# Create database and user (skip if reinstalling — preserve existing data)
if [ "$REINSTALL" = true ]; then
    # Read existing DB credentials from .env backup
    EXISTING_DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    if [ -n "$EXISTING_DB_PASS" ]; then
        DB_PASS="$EXISTING_DB_PASS"
        ok "Reusing existing database credentials"
    else
        warn "Could not read existing DB password — generating new one"
    fi
else
    # Fresh install — create user and database
    sudo -u postgres psql -c "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" 2>/dev/null | grep -q 1 || \
        sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" > /dev/null 2>&1

    sudo -u postgres psql -lqt | cut -d \| -f 1 | grep -qw "${DB_NAME}" 2>/dev/null || \
        sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};" > /dev/null 2>&1

    sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};" > /dev/null 2>&1
    ok "Database '${DB_NAME}' created (user: ${DB_USER})"
fi

# ============================================================
# Step 4: Caddy
# ============================================================
header "Step 4/7 — Installing & configuring Caddy"

CADDY_FILE="/etc/caddy/Caddyfile"
CADDY_WAS_RUNNING=false
CADDY_EXISTING=false

# Detect existing Caddy
if command -v caddy &> /dev/null; then
    CADDY_EXISTING=true
    ok "Caddy already installed"

    # Check if Caddy is running
    if systemctl is-active --quiet caddy 2>/dev/null; then
        CADDY_WAS_RUNNING=true
    fi

    # Check if existing Caddy has routes (MuseDock CMS or other apps)
    CADDY_ROUTES=0
    if [ "$CADDY_WAS_RUNNING" = true ]; then
        CADDY_ROUTES=$(curl -s http://localhost:2019/config/apps/http/servers/srv0/routes 2>/dev/null | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
    fi

    if [ "$CADDY_ROUTES" -gt 0 ] 2>/dev/null; then
        echo ""
        echo -e "  ${YELLOW}${BOLD}Existing Caddy detected with ${CADDY_ROUTES} active routes.${NC}"
        echo -e "  ${YELLOW}This may be MuseDock CMS or another application.${NC}"
        echo ""
        echo "  Options:"
        echo "    1) Integrate — use existing Caddy (recommended if already configured)"
        echo "    2) Reconfigure — overwrite Caddyfile (WARNING: may break existing routes)"
        echo ""
        read -rp "  Choose [1/2] (default: 1): " CADDY_CHOICE
        CADDY_CHOICE=${CADDY_CHOICE:-1}

        if [ "$CADDY_CHOICE" = "1" ]; then
            ok "Integrating with existing Caddy (${CADDY_ROUTES} routes preserved)"
            # Just ensure --resume is enabled
            mkdir -p /etc/systemd/system/caddy.service.d
            if [ ! -f /etc/systemd/system/caddy.service.d/override-resume.conf ]; then
                cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF
                systemctl daemon-reload
                ok "Added --resume flag (routes will persist across restarts)"
            else
                ok "--resume flag already configured"
            fi
        else
            # Reconfigure Caddy
            warn "Reconfiguring Caddy — backing up existing Caddyfile"
            cp "$CADDY_FILE" "${CADDY_FILE}.bak.$(date +%Y%m%d%H%M%S)" 2>/dev/null || true

            cat > "$CADDY_FILE" << 'CADDYEOF'
{
    admin localhost:2019
    auto_https disable_redirects
}
CADDYEOF
            ok "Caddyfile reconfigured"

            mkdir -p /etc/systemd/system/caddy.service.d
            cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF
            systemctl daemon-reload
            systemctl restart caddy
            ok "Caddy restarted with new configuration"
        fi
    else
        # Caddy exists but no routes — safe to configure
        if ! grep -q "admin" "$CADDY_FILE" 2>/dev/null; then
            cat > "$CADDY_FILE" << 'CADDYEOF'
{
    admin localhost:2019
    auto_https disable_redirects
}
CADDYEOF
            ok "Caddyfile configured with admin API"
        else
            ok "Caddyfile already has admin API enabled"
        fi

        mkdir -p /etc/systemd/system/caddy.service.d
        if [ ! -f /etc/systemd/system/caddy.service.d/override-resume.conf ]; then
            cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF
            systemctl daemon-reload
        fi
        systemctl enable caddy > /dev/null 2>&1
        systemctl restart caddy
        ok "Caddy running with --resume"
    fi
else
    # Fresh Caddy install
    apt-get install -y -qq debian-keyring debian-archive-keyring > /dev/null 2>&1
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' 2>/dev/null | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>/dev/null
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' 2>/dev/null | tee /etc/apt/sources.list.d/caddy-stable.list > /dev/null 2>&1
    apt-get update -qq
    apt-get install -y -qq caddy > /dev/null 2>&1
    ok "Caddy installed"

    cat > "$CADDY_FILE" << 'CADDYEOF'
{
    admin localhost:2019
    auto_https disable_redirects
}
CADDYEOF
    ok "Caddyfile configured with admin API"

    mkdir -p /etc/systemd/system/caddy.service.d
    cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF

    systemctl daemon-reload
    systemctl enable caddy > /dev/null 2>&1
    systemctl restart caddy
    ok "Caddy running with --resume (routes persist across restarts)"
fi

# Verify Caddy API is accessible
sleep 1
CADDY_API_OK=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:2019/config/ 2>/dev/null || echo "000")
if [ "$CADDY_API_OK" = "200" ]; then
    ok "Caddy API accessible on localhost:2019"
else
    warn "Caddy API not responding yet (HTTP $CADDY_API_OK) — may need a moment to start"
fi

# ============================================================
# Step 5: MySQL (for hosting client databases)
# ============================================================
header "Step 5/7 — Installing MySQL"

if ! command -v mysql &> /dev/null; then
    apt-get install -y -qq mysql-server > /dev/null 2>&1 || \
    apt-get install -y -qq mariadb-server > /dev/null 2>&1
    ok "MySQL/MariaDB installed"
else
    ok "MySQL/MariaDB already installed"
fi

systemctl enable mysql > /dev/null 2>&1 || systemctl enable mariadb > /dev/null 2>&1
systemctl start mysql > /dev/null 2>&1 || systemctl start mariadb > /dev/null 2>&1
ok "MySQL running"

# ============================================================
# Step 6: Panel setup
# ============================================================
header "Step 6/7 — Setting up MuseDock Panel"

# Create directories
mkdir -p "${PANEL_DIR}/storage/sessions"
mkdir -p "${PANEL_DIR}/storage/logs"
mkdir -p "${PANEL_DIR}/storage/cache"
mkdir -p /var/www/vhosts
ok "Directories created"

# Backup existing .env if reinstalling
if [ "$REINSTALL" = true ] && [ -f "${PANEL_DIR}/.env" ]; then
    cp "${PANEL_DIR}/.env" "${PANEL_DIR}/.env.bak.$(date +%Y%m%d%H%M%S)"
    ok "Existing .env backed up"
fi

# Generate .env
cat > "${PANEL_DIR}/.env" << ENVEOF
# MuseDock Panel — Generated $(date '+%Y-%m-%d %H:%M:%S')
PANEL_NAME="MuseDock Panel"
PANEL_PORT=${PANEL_PORT}
PANEL_DEBUG=false

DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

SESSION_LIFETIME=7200

CADDY_API_URL=http://localhost:2019

FPM_PHP_VERSION=${PHP_VER}
FPM_SOCKET_DIR=/run/php

VHOSTS_DIR=/var/www/vhosts

ALLOWED_IPS=
ENVEOF

chmod 600 "${PANEL_DIR}/.env"
ok ".env created (permissions: 600 — root only)"

# Run database schema (safe — uses IF NOT EXISTS)
PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h 127.0.0.1 -d "${DB_NAME}" -f "${PANEL_DIR}/database/schema.sql" > /dev/null 2>&1
ok "Database schema applied (existing tables preserved)"

# Set permissions
chmod -R 750 "${PANEL_DIR}/storage"
ok "Permissions set"

# ============================================================
# Step 7: Systemd service
# ============================================================
header "Step 7/7 — Configuring systemd service"

# Generate service file from template
sed -e "s|__PANEL_DIR__|${PANEL_DIR}|g" \
    -e "s|__PANEL_PORT__|${PANEL_PORT}|g" \
    "${PANEL_DIR}/bin/musedock-panel.service" > /etc/systemd/system/musedock-panel.service

systemctl daemon-reload
systemctl enable musedock-panel > /dev/null 2>&1
systemctl restart musedock-panel
ok "MuseDock Panel service started on port ${PANEL_PORT}"

# ============================================================
# Done!
# ============================================================
SERVER_IP=$(hostname -I | awk '{print $1}')

echo ""
echo -e "${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║         Installation completed!                  ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""
echo -e "  ${CYAN}${BOLD}>>> NEXT STEP: Create your admin account <<<${NC}"
echo ""
echo -e "  Open your browser and go to:"
echo ""
echo -e "  ${BOLD}  http://${SERVER_IP}:${PANEL_PORT}/setup${NC}"
echo ""
echo -e "  The setup wizard will guide you through creating"
echo -e "  the admin username and password."
echo ""
echo -e "  ┌──────────────────────────────────────────────┐"
echo -e "  │  ${BOLD}SAVE THESE CREDENTIALS${NC} (shown only once):     │"
echo -e "  │                                              │"
echo -e "  │  Database:   ${BOLD}${DB_NAME}${NC}"
echo -e "  │  DB User:    ${BOLD}${DB_USER}${NC}"
echo -e "  │  DB Password: ${BOLD}${DB_PASS}${NC}"
echo -e "  │  .env file:  ${BOLD}${PANEL_DIR}/.env${NC}"
echo -e "  │                                              │"
echo -e "  │  These are also stored in .env (root only).  │"
echo -e "  └──────────────────────────────────────────────┘"
echo ""
echo -e "  ${YELLOW}${BOLD}SECURITY — IMPORTANT:${NC}"
echo ""
echo -e "  ${YELLOW}1.${NC} ${BOLD}Protect the panel port with a firewall.${NC}"
echo -e "     The panel runs as root and has full system access."
echo -e "     Only trusted administrator IPs should reach port ${PANEL_PORT}."
echo ""
echo -e "     ${CYAN}# UFW example — allow only your IP:${NC}"
echo -e "     ${CYAN}ufw allow from YOUR_ADMIN_IP to any port ${PANEL_PORT}${NC}"
echo -e "     ${CYAN}ufw deny ${PANEL_PORT}${NC}"
echo ""
echo -e "  ${YELLOW}2.${NC} ${BOLD}Restrict IPs in .env${NC} (additional layer):"
echo -e "     ${CYAN}ALLOWED_IPS=1.2.3.4,5.6.7.8${NC}"
echo ""
echo -e "  ${YELLOW}3.${NC} ${BOLD}The panel port is for administrators only.${NC}"
echo -e "     Never expose it to the public internet without a firewall."
echo -e "     Hosting client sites are served by Caddy on ports 80/443."
echo ""
echo -e "  ${BOLD}Service management:${NC}"
echo -e "     systemctl status musedock-panel"
echo -e "     systemctl restart musedock-panel"
echo -e "     journalctl -u musedock-panel -f"
echo ""
echo -e "  ${GREEN}${BOLD}Enjoy MuseDock Panel!${NC}"
echo ""
