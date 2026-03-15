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
# Detect conflicting services (nginx, Apache, Plesk)
# ============================================================
header "Checking for conflicting services"

PLESK_DETECTED=false
NGINX_DETECTED=false
APACHE_DETECTED=false
NGINX_ON_HTTP=false
APACHE_ON_HTTP=false

# --- Plesk detection ---
if [ -d /usr/local/psa ] || command -v psa &> /dev/null || [ -f /etc/init.d/psa ]; then
    PLESK_DETECTED=true
    PLESK_VERSION=$(plesk version 2>/dev/null | head -1 || echo "unknown version")
    echo ""
    echo -e "  ${RED}${BOLD}╔══════════════════════════════════════════════════╗${NC}"
    echo -e "  ${RED}${BOLD}║  WARNING: Plesk detected on this server!         ║${NC}"
    echo -e "  ${RED}${BOLD}╚══════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "  ${YELLOW}Plesk version: ${PLESK_VERSION}${NC}"
    echo -e "  ${YELLOW}Plesk manages its own web server (nginx/Apache), databases,${NC}"
    echo -e "  ${YELLOW}and PHP. Installing MuseDock Panel alongside Plesk may${NC}"
    echo -e "  ${YELLOW}cause port conflicts and service interference.${NC}"
    echo ""
    echo "  Options:"
    echo "    1) Abort installation (recommended)"
    echo "    2) Continue anyway (advanced users only — you must resolve conflicts manually)"
    echo ""
    read -rp "  Choose [1/2] (default: 1): " PLESK_CHOICE
    PLESK_CHOICE=${PLESK_CHOICE:-1}

    if [ "$PLESK_CHOICE" = "1" ]; then
        echo ""
        echo -e "  Installation aborted. Plesk and MuseDock Panel are not compatible on the same server."
        exit 0
    else
        warn "Continuing with Plesk present — manual conflict resolution required"
    fi
fi

# --- Nginx detection ---
if command -v nginx &> /dev/null; then
    NGINX_DETECTED=true
    NGINX_VERSION=$(nginx -v 2>&1 | head -1 || echo "unknown")

    # Check if nginx is listening on 80 or 443
    NGINX_PORTS=""
    if ss -tlnp 2>/dev/null | grep -q 'nginx.*:80\b'; then
        NGINX_ON_HTTP=true
        NGINX_PORTS="80"
    fi
    if ss -tlnp 2>/dev/null | grep -q 'nginx.*:443\b'; then
        NGINX_ON_HTTP=true
        NGINX_PORTS="${NGINX_PORTS:+$NGINX_PORTS, }443"
    fi

    # Check if nginx is managed by Plesk
    NGINX_IS_PLESK=false
    if [ "$PLESK_DETECTED" = true ]; then
        if nginx -V 2>&1 | grep -qi plesk 2>/dev/null || [ -f /etc/nginx/plesk.conf.d/server.conf ]; then
            NGINX_IS_PLESK=true
        fi
    fi

    echo ""
    echo -e "  ${YELLOW}${BOLD}Nginx detected: ${NGINX_VERSION}${NC}"
    if [ "$NGINX_IS_PLESK" = true ]; then
        echo -e "  ${YELLOW}  ⚠ This nginx is managed by Plesk — do NOT disable it without Plesk${NC}"
    fi
    if [ -n "$NGINX_PORTS" ]; then
        echo -e "  ${YELLOW}  Listening on ports: ${NGINX_PORTS}${NC}"
        echo -e "  ${YELLOW}  Caddy needs ports 80/443 — this will cause a conflict.${NC}"
    else
        echo -e "  ${GREEN}  Nginx is installed but NOT listening on ports 80/443${NC}"
        echo -e "  ${GREEN}  No conflict with Caddy.${NC}"
    fi

    if [ "$NGINX_ON_HTTP" = true ] && [ "$NGINX_IS_PLESK" = false ]; then
        echo ""
        echo "  Options:"
        echo "    1) Stop & disable nginx permanently (recommended — frees ports for Caddy)"
        echo "    2) Keep nginx running (you must reconfigure ports manually)"
        echo "    3) Abort installation"
        echo ""
        read -rp "  Choose [1/2/3] (default: 1): " NGINX_CHOICE
        NGINX_CHOICE=${NGINX_CHOICE:-1}

        case "$NGINX_CHOICE" in
            1)
                # Backup nginx config before disabling
                BACKUP_TS=$(date +%Y%m%d%H%M%S)
                if [ -d /etc/nginx ]; then
                    tar czf "/etc/nginx.backup.${BACKUP_TS}.tar.gz" /etc/nginx 2>/dev/null || true
                    ok "Nginx config backed up to /etc/nginx.backup.${BACKUP_TS}.tar.gz"
                fi
                systemctl stop nginx 2>/dev/null || true
                systemctl disable nginx 2>/dev/null || true
                ok "Nginx stopped and disabled (will not start on reboot)"
                echo -e "  ${CYAN}  To re-enable: systemctl enable --now nginx${NC}"
                echo -e "  ${CYAN}  Config backup: /etc/nginx.backup.${BACKUP_TS}.tar.gz${NC}"
                ;;
            2)
                warn "Nginx kept running — you MUST move nginx off ports 80/443 before Caddy starts"
                warn "Otherwise Caddy will fail to bind and websites will not work"
                ;;
            3)
                echo ""
                echo -e "  Installation aborted."
                exit 0
                ;;
        esac
    elif [ "$NGINX_ON_HTTP" = true ] && [ "$NGINX_IS_PLESK" = true ]; then
        warn "Nginx is managed by Plesk — cannot disable automatically"
        warn "You must resolve the port conflict manually or remove Plesk first"
        read -rp "  Continue anyway? [y/N] " NGINX_PLESK_CONTINUE
        if [[ ! "$NGINX_PLESK_CONTINUE" =~ ^[Yy]$ ]]; then
            echo "  Installation aborted."
            exit 0
        fi
    fi
fi

# --- Apache detection ---
if command -v apache2 &> /dev/null || command -v httpd &> /dev/null; then
    APACHE_DETECTED=true
    APACHE_VERSION=$(apache2 -v 2>/dev/null | head -1 || httpd -v 2>/dev/null | head -1 || echo "unknown")
    APACHE_SVC="apache2"
    command -v apache2 &> /dev/null || APACHE_SVC="httpd"

    # Check if Apache is listening on 80 or 443
    APACHE_PORTS=""
    if ss -tlnp 2>/dev/null | grep -qE '(apache2|httpd).*:80\b'; then
        APACHE_ON_HTTP=true
        APACHE_PORTS="80"
    fi
    if ss -tlnp 2>/dev/null | grep -qE '(apache2|httpd).*:443\b'; then
        APACHE_ON_HTTP=true
        APACHE_PORTS="${APACHE_PORTS:+$APACHE_PORTS, }443"
    fi

    # Check if Apache is managed by Plesk
    APACHE_IS_PLESK=false
    if [ "$PLESK_DETECTED" = true ]; then
        if [ -f /etc/apache2/plesk.conf.d/roundcube.conf ] || [ -d /usr/local/psa/admin/htdocs ]; then
            APACHE_IS_PLESK=true
        fi
    fi

    echo ""
    echo -e "  ${YELLOW}${BOLD}Apache detected: ${APACHE_VERSION}${NC}"
    if [ "$APACHE_IS_PLESK" = true ]; then
        echo -e "  ${YELLOW}  ⚠ This Apache is managed by Plesk — do NOT disable it without Plesk${NC}"
    fi
    if [ -n "$APACHE_PORTS" ]; then
        echo -e "  ${YELLOW}  Listening on ports: ${APACHE_PORTS}${NC}"
        echo -e "  ${YELLOW}  Caddy needs ports 80/443 — this will cause a conflict.${NC}"
    else
        echo -e "  ${GREEN}  Apache is installed but NOT listening on ports 80/443${NC}"
        echo -e "  ${GREEN}  No conflict with Caddy.${NC}"
    fi

    if [ "$APACHE_ON_HTTP" = true ] && [ "$APACHE_IS_PLESK" = false ]; then
        echo ""
        echo "  Options:"
        echo "    1) Stop & disable Apache permanently (recommended — frees ports for Caddy)"
        echo "    2) Keep Apache running (you must reconfigure ports manually)"
        echo "    3) Abort installation"
        echo ""
        read -rp "  Choose [1/2/3] (default: 1): " APACHE_CHOICE
        APACHE_CHOICE=${APACHE_CHOICE:-1}

        case "$APACHE_CHOICE" in
            1)
                # Backup Apache config before disabling
                BACKUP_TS=$(date +%Y%m%d%H%M%S)
                APACHE_CONF_DIR="/etc/${APACHE_SVC}"
                if [ -d "$APACHE_CONF_DIR" ]; then
                    tar czf "${APACHE_CONF_DIR}.backup.${BACKUP_TS}.tar.gz" "$APACHE_CONF_DIR" 2>/dev/null || true
                    ok "Apache config backed up to ${APACHE_CONF_DIR}.backup.${BACKUP_TS}.tar.gz"
                fi
                systemctl stop "$APACHE_SVC" 2>/dev/null || true
                systemctl disable "$APACHE_SVC" 2>/dev/null || true
                ok "Apache stopped and disabled (will not start on reboot)"
                echo -e "  ${CYAN}  To re-enable: systemctl enable --now ${APACHE_SVC}${NC}"
                echo -e "  ${CYAN}  Config backup: ${APACHE_CONF_DIR}.backup.${BACKUP_TS}.tar.gz${NC}"
                ;;
            2)
                warn "Apache kept running — you MUST move Apache off ports 80/443 before Caddy starts"
                warn "Otherwise Caddy will fail to bind and websites will not work"
                ;;
            3)
                echo ""
                echo -e "  Installation aborted."
                exit 0
                ;;
        esac
    elif [ "$APACHE_ON_HTTP" = true ] && [ "$APACHE_IS_PLESK" = true ]; then
        warn "Apache is managed by Plesk — cannot disable automatically"
        warn "You must resolve the port conflict manually or remove Plesk first"
        read -rp "  Continue anyway? [y/N] " APACHE_PLESK_CONTINUE
        if [[ ! "$APACHE_PLESK_CONTINUE" =~ ^[Yy]$ ]]; then
            echo "  Installation aborted."
            exit 0
        fi
    fi
fi

# Summary of detections
if [ "$NGINX_DETECTED" = false ] && [ "$APACHE_DETECTED" = false ] && [ "$PLESK_DETECTED" = false ]; then
    ok "No conflicting services detected (no nginx, no Apache, no Plesk)"
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
# Pre-installation snapshot
# ============================================================
header "Creating pre-installation snapshot"

SNAPSHOT_DIR="${PANEL_DIR}/install-backup"
SNAPSHOT_TS=$(date +%Y%m%d%H%M%S)
mkdir -p "${SNAPSHOT_DIR}/${SNAPSHOT_TS}"

# Running services
systemctl list-units --type=service --state=running --no-pager --no-legend \
    > "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/services-running.txt" 2>/dev/null
ok "Running services saved"

# Listening ports
ss -tlnp > "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/ports-listening.txt" 2>/dev/null
ok "Listening ports saved"

# Caddy config
if [ -f /etc/caddy/Caddyfile ]; then
    cp /etc/caddy/Caddyfile "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/Caddyfile.bak"
    ok "Caddyfile backed up"
fi
# Caddy autosave.json
SNAP_CADDY_HOME=$(caddy environ 2>/dev/null | grep 'caddy.AppConfigDir=' | cut -d= -f2 || echo "")
if [ -z "$SNAP_CADDY_HOME" ]; then
    for d in /var/lib/caddy/.config/caddy /root/.config/caddy /home/caddy/.config/caddy; do
        [ -f "${d}/autosave.json" ] && SNAP_CADDY_HOME="$d" && break
    done
fi
if [ -n "$SNAP_CADDY_HOME" ] && [ -f "${SNAP_CADDY_HOME}/autosave.json" ]; then
    cp "${SNAP_CADDY_HOME}/autosave.json" "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/autosave.json.bak"
    ok "Caddy autosave.json backed up"
fi

# Nginx config
if [ -d /etc/nginx ]; then
    tar czf "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/nginx-config.tar.gz" /etc/nginx 2>/dev/null && \
        ok "Nginx config backed up" || true
fi

# Apache config
for apache_dir in /etc/apache2 /etc/httpd; do
    if [ -d "$apache_dir" ]; then
        tar czf "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/apache-config.tar.gz" "$apache_dir" 2>/dev/null && \
            ok "Apache config backed up" || true
        break
    fi
done

# PostgreSQL pg_hba.conf
PG_HBA_SNAP=$(find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1)
if [ -n "$PG_HBA_SNAP" ] && [ -f "$PG_HBA_SNAP" ]; then
    cp "$PG_HBA_SNAP" "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/pg_hba.conf.bak"
    ok "pg_hba.conf backed up"
fi

# Existing .env
if [ -f "${PANEL_DIR}/.env" ]; then
    cp "${PANEL_DIR}/.env" "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/env.bak"
    ok "Existing .env backed up"
fi

# Installed packages relevant to us
dpkg -l | grep -E '(caddy|nginx|apache2|postgresql|mysql|mariadb|php)' \
    > "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/packages-installed.txt" 2>/dev/null || true
ok "Package list saved"

# Symlink latest snapshot for easy access
ln -sfn "${SNAPSHOT_DIR}/${SNAPSHOT_TS}" "${SNAPSHOT_DIR}/latest"
ok "Snapshot saved to ${SNAPSHOT_DIR}/${SNAPSHOT_TS}/"

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
    # Try peer auth first (sudo -u postgres), fallback to password auth
    PG_AUTH_METHOD="peer"
    PG_PASS_ENV=""

    # Test if peer auth works
    if ! sudo -u postgres psql -c "SELECT 1;" > /dev/null 2>&1; then
        PG_AUTH_METHOD="password"
        echo ""
        echo -e "  ${YELLOW}${BOLD}PostgreSQL peer authentication is not available.${NC}"
        echo -e "  ${YELLOW}This may happen if pg_hba.conf uses md5/scram-sha-256 for local connections,${NC}"
        echo -e "  ${YELLOW}or if the 'postgres' system user is not configured for direct access.${NC}"
        echo ""

        # Try with empty password first (some default installs)
        if PGPASSWORD="" psql -U postgres -h 127.0.0.1 -c "SELECT 1;" > /dev/null 2>&1; then
            ok "Connected to PostgreSQL with default (empty) password"
            PG_PASS_ENV=""
        else
            echo -e "  ${BOLD}Enter the PostgreSQL superuser (postgres) password:${NC}"
            read -rsp "  Password: " PG_SUPERUSER_PASS
            echo ""

            # Validate the password
            if PGPASSWORD="$PG_SUPERUSER_PASS" psql -U postgres -h 127.0.0.1 -c "SELECT 1;" > /dev/null 2>&1; then
                ok "PostgreSQL password verified"
                PG_PASS_ENV="$PG_SUPERUSER_PASS"
            else
                fail "Cannot connect to PostgreSQL. Check the password and pg_hba.conf settings."
            fi
        fi
    fi

    # Function to run psql commands with the right auth method
    pg_exec() {
        if [ "$PG_AUTH_METHOD" = "peer" ]; then
            sudo -u postgres psql -c "$1" 2>/dev/null
        else
            PGPASSWORD="$PG_PASS_ENV" psql -U postgres -h 127.0.0.1 -c "$1" 2>/dev/null
        fi
    }

    pg_exec_quiet() {
        if [ "$PG_AUTH_METHOD" = "peer" ]; then
            sudo -u postgres psql -lqt 2>/dev/null
        else
            PGPASSWORD="$PG_PASS_ENV" psql -U postgres -h 127.0.0.1 -lqt 2>/dev/null
        fi
    }

    # Create user if not exists
    pg_exec "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 || \
        pg_exec "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" > /dev/null 2>&1

    # Create database if not exists
    pg_exec_quiet | cut -d \| -f 1 | grep -qw "${DB_NAME}" || \
        pg_exec "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};" > /dev/null 2>&1

    pg_exec "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};" > /dev/null 2>&1

    # Ensure pg_hba.conf allows password auth for the panel user on 127.0.0.1
    if [ "$PG_AUTH_METHOD" = "peer" ]; then
        PG_HBA=$(sudo -u postgres psql -t -c "SHOW hba_file;" 2>/dev/null || find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1)
    else
        PG_HBA=$(PGPASSWORD="$PG_PASS_ENV" psql -U postgres -h 127.0.0.1 -t -c "SHOW hba_file;" 2>/dev/null || find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1)
    fi
    PG_HBA=$(echo "$PG_HBA" | xargs)  # trim whitespace
    if [ -n "$PG_HBA" ] && [ -f "$PG_HBA" ]; then
        if ! grep -q "${DB_USER}" "$PG_HBA" 2>/dev/null; then
            # Add rule for the panel user BEFORE the first existing rule
            cp "$PG_HBA" "${PG_HBA}.bak.$(date +%Y%m%d%H%M%S)"
            sed -i "/^# IPv4 local connections/a host    ${DB_NAME}    ${DB_USER}    127.0.0.1/32    md5" "$PG_HBA" 2>/dev/null || \
                echo "host    ${DB_NAME}    ${DB_USER}    127.0.0.1/32    md5" >> "$PG_HBA"
            # Reload PostgreSQL to apply pg_hba changes
            systemctl reload postgresql 2>/dev/null || sudo -u postgres pg_ctl reload 2>/dev/null || true
            ok "pg_hba.conf updated for panel user access"
        fi
    fi

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

    # Check for existing configuration (API routes + Caddyfile domains + autosave.json)
    CADDY_ROUTES=0
    CADDY_HAS_CADDYFILE_DOMAINS=false
    CADDY_HAS_AUTOSAVE=false

    if [ "$CADDY_WAS_RUNNING" = true ]; then
        CADDY_ROUTES=$(curl -s http://localhost:2019/config/apps/http/servers/srv0/routes 2>/dev/null | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
    fi

    # Check if Caddyfile has domain blocks (not just global options)
    if [ -f "$CADDY_FILE" ]; then
        CADDYFILE_DOMAINS=$(grep -cE '^\s*[a-zA-Z0-9].*\{' "$CADDY_FILE" 2>/dev/null || echo "0")
        if [ "$CADDYFILE_DOMAINS" -gt 0 ] 2>/dev/null; then
            CADDY_HAS_CADDYFILE_DOMAINS=true
        fi
    fi

    # Check for autosave.json (API-created routes persisted to disk)
    CADDY_HOME=$(caddy environ 2>/dev/null | grep 'caddy.AppConfigDir=' | cut -d= -f2 || echo "")
    if [ -z "$CADDY_HOME" ]; then
        # Fallback: check common locations
        for autosave_dir in /var/lib/caddy/.config/caddy /root/.config/caddy /home/caddy/.config/caddy; do
            if [ -f "${autosave_dir}/autosave.json" ]; then
                CADDY_HOME="$autosave_dir"
                break
            fi
        done
    fi
    if [ -n "$CADDY_HOME" ] && [ -f "${CADDY_HOME}/autosave.json" ]; then
        CADDY_HAS_AUTOSAVE=true
        AUTOSAVE_SIZE=$(stat -c%s "${CADDY_HOME}/autosave.json" 2>/dev/null || echo "0")
        # autosave.json with meaningful content (>50 bytes = has real config)
        if [ "$AUTOSAVE_SIZE" -lt 50 ] 2>/dev/null; then
            CADDY_HAS_AUTOSAVE=false
        fi
    fi

    CADDY_HAS_EXISTING_CONFIG=false
    if [ "$CADDY_ROUTES" -gt 0 ] 2>/dev/null || [ "$CADDY_HAS_CADDYFILE_DOMAINS" = true ] || [ "$CADDY_HAS_AUTOSAVE" = true ]; then
        CADDY_HAS_EXISTING_CONFIG=true
    fi

    if [ "$CADDY_HAS_EXISTING_CONFIG" = true ]; then
        echo ""
        echo -e "  ${YELLOW}${BOLD}Existing Caddy configuration detected:${NC}"
        [ "$CADDY_ROUTES" -gt 0 ] 2>/dev/null && echo -e "  ${YELLOW}  - ${CADDY_ROUTES} active API routes${NC}"
        [ "$CADDY_HAS_CADDYFILE_DOMAINS" = true ] && echo -e "  ${YELLOW}  - Caddyfile has domain blocks (${CADDYFILE_DOMAINS} entries)${NC}"
        [ "$CADDY_HAS_AUTOSAVE" = true ] && echo -e "  ${YELLOW}  - autosave.json found (API-persisted routes)${NC}"
        echo -e "  ${YELLOW}This may be MuseDock CMS or another application.${NC}"
        echo ""
        echo "  Options:"
        echo "    1) Integrate — use existing Caddy, preserve ALL config (recommended)"
        echo "    2) Reconfigure — overwrite Caddyfile (WARNING: may break existing sites)"
        echo ""
        read -rp "  Choose [1/2] (default: 1): " CADDY_CHOICE
        CADDY_CHOICE=${CADDY_CHOICE:-1}

        if [ "$CADDY_CHOICE" = "1" ]; then
            ok "Integrating with existing Caddy (all existing config preserved)"
            # Ensure Caddyfile has admin API enabled (add if missing, don't overwrite)
            if ! grep -q "admin" "$CADDY_FILE" 2>/dev/null; then
                # Prepend admin block to existing Caddyfile
                cp "$CADDY_FILE" "${CADDY_FILE}.bak.$(date +%Y%m%d%H%M%S)" 2>/dev/null || true
                TEMP_CADDY=$(mktemp)
                cat > "$TEMP_CADDY" << 'ADMINEOF'
{
    admin localhost:2019
}

ADMINEOF
                cat "$CADDY_FILE" >> "$TEMP_CADDY"
                mv "$TEMP_CADDY" "$CADDY_FILE"
                ok "Added admin API to existing Caddyfile (backup created)"
            fi
            # Ensure --resume is enabled
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
            # Reconfigure Caddy — backup EVERYTHING first
            warn "Reconfiguring Caddy — creating backups"
            BACKUP_TS=$(date +%Y%m%d%H%M%S)
            cp "$CADDY_FILE" "${CADDY_FILE}.bak.${BACKUP_TS}" 2>/dev/null || true
            ok "Caddyfile backed up to ${CADDY_FILE}.bak.${BACKUP_TS}"
            # Also backup autosave.json if it exists
            if [ "$CADDY_HAS_AUTOSAVE" = true ] && [ -n "$CADDY_HOME" ]; then
                cp "${CADDY_HOME}/autosave.json" "${CADDY_HOME}/autosave.json.bak.${BACKUP_TS}" 2>/dev/null || true
                ok "autosave.json backed up to ${CADDY_HOME}/autosave.json.bak.${BACKUP_TS}"
            fi

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
        # Caddy exists but no config at all — safe to configure
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

MYSQL_FRESH_INSTALL=false
if ! command -v mysql &> /dev/null; then
    apt-get install -y -qq mysql-server > /dev/null 2>&1 || \
    apt-get install -y -qq mariadb-server > /dev/null 2>&1
    MYSQL_FRESH_INSTALL=true
    ok "MySQL/MariaDB installed"
else
    ok "MySQL/MariaDB already installed"
fi

systemctl enable mysql > /dev/null 2>&1 || systemctl enable mariadb > /dev/null 2>&1
systemctl start mysql > /dev/null 2>&1 || systemctl start mariadb > /dev/null 2>&1
ok "MySQL running"

# Determine MySQL root access method and store credentials for client DB management
MYSQL_ROOT_PASS=""
MYSQL_AUTH_METHOD="unknown"

if [ "$MYSQL_FRESH_INSTALL" = true ]; then
    # Fresh install — root uses unix_socket/auth_socket (no password needed from root)
    if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
        MYSQL_AUTH_METHOD="socket"
        ok "MySQL root uses socket authentication (no password needed)"
    fi
else
    # Existing install — test access methods
    if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
        # Socket auth works from root
        MYSQL_AUTH_METHOD="socket"
        ok "MySQL root access via socket authentication"
    elif [ "$REINSTALL" = true ]; then
        # Try reading from existing .env
        EXISTING_MYSQL_PASS=$(grep -E '^MYSQL_ROOT_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
        if [ -n "$EXISTING_MYSQL_PASS" ]; then
            if mysql -u root -p"${EXISTING_MYSQL_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
                MYSQL_ROOT_PASS="$EXISTING_MYSQL_PASS"
                MYSQL_AUTH_METHOD="password"
                ok "MySQL root password recovered from existing .env"
            fi
        fi
    fi

    if [ "$MYSQL_AUTH_METHOD" = "unknown" ]; then
        echo ""
        echo -e "  ${YELLOW}${BOLD}MySQL root access requires a password.${NC}"
        echo -e "  ${YELLOW}The panel needs MySQL root access to create client databases.${NC}"
        echo ""
        echo "  Options:"
        echo "    1) Enter the MySQL root password now"
        echo "    2) Skip — you can configure this later in .env (MYSQL_ROOT_PASS)"
        echo ""
        read -rp "  Choose [1/2] (default: 1): " MYSQL_PASS_CHOICE
        MYSQL_PASS_CHOICE=${MYSQL_PASS_CHOICE:-1}

        if [ "$MYSQL_PASS_CHOICE" = "1" ]; then
            MAX_ATTEMPTS=3
            for attempt in $(seq 1 $MAX_ATTEMPTS); do
                read -rsp "  MySQL root password (attempt ${attempt}/${MAX_ATTEMPTS}): " MYSQL_INPUT_PASS
                echo ""
                if mysql -u root -p"${MYSQL_INPUT_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
                    MYSQL_ROOT_PASS="$MYSQL_INPUT_PASS"
                    MYSQL_AUTH_METHOD="password"
                    ok "MySQL root password verified"
                    break
                else
                    warn "Invalid password"
                fi
            done
            if [ "$MYSQL_AUTH_METHOD" = "unknown" ]; then
                warn "Could not authenticate to MySQL — you can set MYSQL_ROOT_PASS in .env later"
                MYSQL_AUTH_METHOD="unconfigured"
            fi
        else
            warn "MySQL root password skipped — set MYSQL_ROOT_PASS in .env to enable client DB creation"
            MYSQL_AUTH_METHOD="unconfigured"
        fi
    fi
fi

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

# MySQL — for client database management
MYSQL_AUTH_METHOD=${MYSQL_AUTH_METHOD}
MYSQL_ROOT_PASS=${MYSQL_ROOT_PASS}
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
# Post-installation health check
# ============================================================
header "Health Check"

HEALTH_ERRORS=0

# 1. Panel service running?
if systemctl is-active --quiet musedock-panel 2>/dev/null; then
    ok "Panel service: running"
else
    echo -e "  ${RED}✗ Panel service: NOT running${NC}"
    echo -e "    ${YELLOW}Fix: systemctl start musedock-panel${NC}"
    echo -e "    ${YELLOW}Logs: journalctl -u musedock-panel --no-pager -n 20${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 2. Panel HTTP responding?
sleep 2
PANEL_HTTP=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "http://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || echo "000")
if [ "$PANEL_HTTP" = "200" ] || [ "$PANEL_HTTP" = "302" ] || [ "$PANEL_HTTP" = "301" ]; then
    ok "Panel HTTP: responding (HTTP ${PANEL_HTTP}) on port ${PANEL_PORT}"
elif [ "$PANEL_HTTP" = "403" ]; then
    ok "Panel HTTP: responding (HTTP 403 — IP restriction active) on port ${PANEL_PORT}"
else
    echo -e "  ${RED}✗ Panel HTTP: NOT responding (HTTP ${PANEL_HTTP}) on port ${PANEL_PORT}${NC}"
    echo -e "    ${YELLOW}Fix: Check if the service is running and the port is not blocked${NC}"
    echo -e "    ${YELLOW}  systemctl status musedock-panel${NC}"
    echo -e "    ${YELLOW}  ss -tlnp | grep ${PANEL_PORT}${NC}"
    echo -e "    ${YELLOW}  journalctl -u musedock-panel --no-pager -n 20${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 3. PostgreSQL connection?
if PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h 127.0.0.1 -d "${DB_NAME}" -c "SELECT 1;" > /dev/null 2>&1; then
    ok "PostgreSQL: connected to ${DB_NAME} as ${DB_USER}"
else
    echo -e "  ${RED}✗ PostgreSQL: cannot connect to ${DB_NAME} as ${DB_USER}${NC}"
    echo -e "    ${YELLOW}Fix: Check PostgreSQL is running and credentials are correct${NC}"
    echo -e "    ${YELLOW}  systemctl status postgresql${NC}"
    echo -e "    ${YELLOW}  PGPASSWORD='...' psql -U ${DB_USER} -h 127.0.0.1 -d ${DB_NAME}${NC}"
    echo -e "    ${YELLOW}  Check pg_hba.conf allows md5 auth for ${DB_USER} on 127.0.0.1${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 4. MySQL accessible?
if [ "$MYSQL_AUTH_METHOD" = "socket" ]; then
    if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
        ok "MySQL: connected via socket authentication"
    else
        echo -e "  ${RED}✗ MySQL: socket auth failed${NC}"
        echo -e "    ${YELLOW}Fix: systemctl status mysql (or mariadb)${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi
elif [ "$MYSQL_AUTH_METHOD" = "password" ]; then
    if mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
        ok "MySQL: connected via password authentication"
    else
        echo -e "  ${RED}✗ MySQL: password auth failed${NC}"
        echo -e "    ${YELLOW}Fix: Check MYSQL_ROOT_PASS in .env${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi
else
    warn "MySQL: skipped (authentication not configured — set MYSQL_ROOT_PASS in .env)"
fi

# 5. Caddy API?
CADDY_HC=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost:2019/config/ 2>/dev/null || echo "000")
if [ "$CADDY_HC" = "200" ]; then
    ok "Caddy API: responding on localhost:2019"
else
    echo -e "  ${RED}✗ Caddy API: NOT responding (HTTP ${CADDY_HC})${NC}"
    echo -e "    ${YELLOW}Fix: Check Caddy is running${NC}"
    echo -e "    ${YELLOW}  systemctl status caddy${NC}"
    echo -e "    ${YELLOW}  journalctl -u caddy --no-pager -n 20${NC}"
    echo -e "    ${YELLOW}  Ensure Caddyfile has: admin localhost:2019${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 6. Caddy ports 80/443 bindable? (only if no conflict services kept running)
if systemctl is-active --quiet caddy 2>/dev/null; then
    CADDY_ON_80=$(ss -tlnp 2>/dev/null | grep -c ':80\b.*caddy' || echo "0")
    CADDY_ON_443=$(ss -tlnp 2>/dev/null | grep -c ':443\b.*caddy' || echo "0")
    if [ "$CADDY_ON_80" -gt 0 ] || [ "$CADDY_ON_443" -gt 0 ]; then
        ok "Caddy ports: listening on 80/443"
    else
        # Only warn if Caddy has sites configured
        if [ "${CADDY_HAS_CADDYFILE_DOMAINS:-false}" = true ] || [ "${CADDY_HAS_AUTOSAVE:-false}" = true ]; then
            warn "Caddy: not listening on 80/443 — sites may not be reachable"
            echo -e "    ${YELLOW}Check: ss -tlnp | grep -E ':80|:443'${NC}"
            echo -e "    ${YELLOW}Another service may be blocking these ports${NC}"
        fi
    fi
fi

# 7. PHP-FPM running?
if systemctl is-active --quiet "php${PHP_VER}-fpm" 2>/dev/null; then
    ok "PHP-FPM ${PHP_VER}: running"
else
    echo -e "  ${RED}✗ PHP-FPM ${PHP_VER}: NOT running${NC}"
    echo -e "    ${YELLOW}Fix: systemctl start php${PHP_VER}-fpm${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# Health check summary
echo ""
if [ "$HEALTH_ERRORS" -eq 0 ]; then
    echo -e "  ${GREEN}${BOLD}All health checks passed! (${HEALTH_ERRORS} errors)${NC}"
else
    echo -e "  ${RED}${BOLD}Health check completed with ${HEALTH_ERRORS} error(s)${NC}"
    echo -e "  ${YELLOW}Review the errors above and fix them before using the panel.${NC}"
    echo -e "  ${YELLOW}Pre-install snapshot: ${SNAPSHOT_DIR}/${SNAPSHOT_TS}/${NC}"
fi

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
echo -e "  │                                              │"
echo -e "  │  MySQL auth: ${BOLD}${MYSQL_AUTH_METHOD}${NC}"
if [ "$MYSQL_AUTH_METHOD" = "password" ]; then
echo -e "  │  MySQL pass: ${BOLD}(stored in .env)${NC}"
elif [ "$MYSQL_AUTH_METHOD" = "socket" ]; then
echo -e "  │  MySQL pass: ${BOLD}(not needed — socket auth)${NC}"
elif [ "$MYSQL_AUTH_METHOD" = "unconfigured" ]; then
echo -e "  │  MySQL pass: ${BOLD}${YELLOW}NOT SET — edit .env later${NC}"
fi
echo -e "  │                                              │"
echo -e "  │  These are also stored in .env (root only).  │"
echo -e "  └──────────────────────────────────────────────┘"
echo ""

# Detection summary
if [ "$NGINX_DETECTED" = true ] || [ "$APACHE_DETECTED" = true ] || [ "$PLESK_DETECTED" = true ]; then
    echo -e "  ${YELLOW}${BOLD}SERVICE DETECTION SUMMARY:${NC}"
    [ "$PLESK_DETECTED" = true ] && echo -e "  ${YELLOW}  ⚠ Plesk: detected (manual conflict resolution)${NC}"
    [ "$NGINX_DETECTED" = true ] && echo -e "  ${YELLOW}  ⚠ Nginx: detected — $(systemctl is-active nginx 2>/dev/null || echo 'unknown status')${NC}"
    [ "$APACHE_DETECTED" = true ] && echo -e "  ${YELLOW}  ⚠ Apache: detected — $(systemctl is-active ${APACHE_SVC:-apache2} 2>/dev/null || echo 'unknown status')${NC}"
    echo ""
fi

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
echo -e "  ${BOLD}Pre-install snapshot:${NC}"
echo -e "     ${SNAPSHOT_DIR}/${SNAPSHOT_TS}/"
echo -e "     (services, ports, configs saved before installation)"
echo ""
echo -e "  ${BOLD}Uninstall:${NC}"
echo -e "     sudo bash ${PANEL_DIR}/bin/uninstall.sh"
echo ""
echo -e "  ${GREEN}${BOLD}Enjoy MuseDock Panel!${NC}"
echo ""
