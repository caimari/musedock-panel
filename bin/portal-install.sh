#!/bin/bash
# ============================================================
# MuseDock Portal — Installer from Panel
# Usage: sudo bash /opt/musedock-panel/bin/portal-install.sh MDCK-XXXX-XXXX-XXXX
#
# Downloads, installs, and activates MuseDock Portal using
# a license key purchased from musedock.com
# ============================================================

set -e

# Colors
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

LICENSE_KEY="${1:-}"
PANEL_DIR="/opt/musedock-panel"
PORTAL_DIR="/opt/musedock-portal"
LICENSE_API="https://license.musedock.com/api/v1"
BACKUP_DIR="/opt/musedock-backups"

# ============================================================
# Validations
# ============================================================

if [ "$EUID" -ne 0 ]; then
    fail "Run as root: sudo bash portal-install.sh MDCK-XXXX-XXXX-XXXX"
fi

if [ -z "$LICENSE_KEY" ]; then
    echo ""
    echo -e "${CYAN}${BOLD}  MuseDock Portal — Customer Portal Installer${NC}"
    echo ""
    echo "  Usage: sudo bash portal-install.sh MDCK-XXXX-XXXX-XXXX"
    echo ""
    echo "  Get a license key at: https://musedock.com/portal"
    echo ""
    exit 1
fi

# Validate key format
if ! echo "$LICENSE_KEY" | grep -qE '^MDCK-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$'; then
    fail "Invalid license key format. Expected: MDCK-XXXX-XXXX-XXXX"
fi

if [ ! -f "${PANEL_DIR}/.env" ]; then
    fail "MuseDock Panel not found at ${PANEL_DIR}. Install the panel first."
fi

if ! command -v curl &>/dev/null; then
    fail "curl is required. Install with: apt install -y curl"
fi

echo ""
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║     MuseDock Portal — Customer Portal        ║"
echo "  ╚══════════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  License Key: ${BOLD}${LICENSE_KEY}${NC}"
echo ""

# Read PHP binary from panel config
PHP_BIN="/usr/bin/php"
PHP_VER=$(grep -E '^FPM_PHP_VERSION=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
[ -n "$PHP_VER" ] && [ -x "/usr/bin/php${PHP_VER}" ] && PHP_BIN="/usr/bin/php${PHP_VER}"

# ============================================================
# Step 1: Check existing installation
# ============================================================
echo -e "${CYAN}${BOLD}[1/10]${NC} Checking existing installation..."

if [ -d "$PORTAL_DIR" ] && [ -f "${PORTAL_DIR}/bootstrap.php" ]; then
    warn "Portal already installed at ${PORTAL_DIR}"
    if [ -t 0 ]; then
        read -rp "  Reinstall? Current data will be backed up. (y/N) " CONFIRM
        if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
            echo "  Cancelled."
            exit 0
        fi
    fi

    # Backup existing installation
    mkdir -p "$BACKUP_DIR"
    BACKUP_NAME="portal-pre-reinstall-$(date +%Y%m%d-%H%M%S).tar.gz"
    tar czf "${BACKUP_DIR}/${BACKUP_NAME}" -C /opt musedock-portal/ 2>/dev/null
    ok "Backup: ${BACKUP_DIR}/${BACKUP_NAME}"
else
    ok "Fresh installation"
fi

# ============================================================
# Step 2: Detect server info
# ============================================================
echo -e "${CYAN}${BOLD}[2/10]${NC} Detecting server info..."

SERVER_IP=$(curl -s --connect-timeout 5 ifconfig.me 2>/dev/null || curl -s --connect-timeout 5 icanhazip.com 2>/dev/null || echo "")
SERVER_HOSTNAME=$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "unknown")

if [ -z "$SERVER_IP" ]; then
    # Fallback: read from panel .env or detect from interfaces
    SERVER_IP=$(grep -E '^PANEL_IP=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    [ -z "$SERVER_IP" ] && SERVER_IP=$(ip -4 route get 1 2>/dev/null | awk '{print $7; exit}')
fi

ok "IP: ${SERVER_IP}, Hostname: ${SERVER_HOSTNAME}"

# ============================================================
# Step 3: Activate license
# ============================================================
echo -e "${CYAN}${BOLD}[3/10]${NC} Activating license..."

RESPONSE=$(curl -s --connect-timeout 15 --max-time 30 \
    -X POST "${LICENSE_API}/activate" \
    -H "Content-Type: application/json" \
    -d "{\"key\":\"${LICENSE_KEY}\",\"server_ip\":\"${SERVER_IP}\",\"hostname\":\"${SERVER_HOSTNAME}\"}" 2>/dev/null)

if [ -z "$RESPONSE" ]; then
    fail "Cannot reach license server at ${LICENSE_API}. Check your internet connection."
fi

# Parse response
SUCCESS=$($PHP_BIN -r "echo json_decode('${RESPONSE//\'/\\\'}',true)['success'] ?? '';" 2>/dev/null || echo "")
JWT=$($PHP_BIN -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['jwt'] ?? '';" <<< "$RESPONSE" 2>/dev/null)
DOWNLOAD_URL=$($PHP_BIN -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['download_url'] ?? '';" <<< "$RESPONSE" 2>/dev/null)
API_VERSION=$($PHP_BIN -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['version'] ?? '';" <<< "$RESPONSE" 2>/dev/null)
MAX_ACCOUNTS=$($PHP_BIN -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['max_accounts'] ?? '';" <<< "$RESPONSE" 2>/dev/null)
VALID_UNTIL=$($PHP_BIN -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['valid_until'] ?? '';" <<< "$RESPONSE" 2>/dev/null)
API_ERROR=$($PHP_BIN -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['error'] ?? '';" <<< "$RESPONSE" 2>/dev/null)

if [ "$SUCCESS" != "1" ] && [ "$SUCCESS" != "true" ]; then
    fail "License activation failed: ${API_ERROR:-unknown error}"
fi

ok "License activated!"
echo -e "     Valid until: ${BOLD}${VALID_UNTIL}${NC}"
echo -e "     Max accounts: ${BOLD}${MAX_ACCOUNTS}${NC}"

# ============================================================
# Step 4: Download Portal
# ============================================================
echo -e "${CYAN}${BOLD}[4/10]${NC} Downloading Portal v${API_VERSION}..."

TARBALL="/tmp/portal-${API_VERSION}.tar.gz"
HTTP_CODE=$(curl -s -o "$TARBALL" -w '%{http_code}' --connect-timeout 15 --max-time 300 \
    -H "Authorization: Bearer ${JWT}" \
    "${LICENSE_API}/${DOWNLOAD_URL}" 2>/dev/null || echo "000")

if [ "$HTTP_CODE" != "200" ] || [ ! -s "$TARBALL" ]; then
    rm -f "$TARBALL"
    fail "Download failed (HTTP ${HTTP_CODE})"
fi

TARBALL_SIZE=$(du -h "$TARBALL" | cut -f1)
ok "Downloaded ${TARBALL_SIZE}"

# ============================================================
# Step 5: Extract
# ============================================================
echo -e "${CYAN}${BOLD}[5/10]${NC} Extracting..."

# Preserve existing config if reinstalling
PRESERVE_DIR=$(mktemp -d)
[ -f "${PORTAL_DIR}/.env" ] && cp "${PORTAL_DIR}/.env" "${PRESERVE_DIR}/.env"
[ -d "${PORTAL_DIR}/storage" ] && cp -a "${PORTAL_DIR}/storage" "${PRESERVE_DIR}/storage"

mkdir -p "$PORTAL_DIR"
tar xzf "$TARBALL" -C /opt/ 2>/dev/null || tar xzf "$TARBALL" -C "$PORTAL_DIR/" --strip-components=1 2>/dev/null
rm -f "$TARBALL"

# Restore preserved files
[ -f "${PRESERVE_DIR}/.env" ] && cp "${PRESERVE_DIR}/.env" "${PORTAL_DIR}/.env"
if [ -d "${PRESERVE_DIR}/storage" ]; then
    cp -a "${PRESERVE_DIR}/storage/logs" "${PORTAL_DIR}/storage/logs" 2>/dev/null || true
    cp -a "${PRESERVE_DIR}/storage/sessions" "${PORTAL_DIR}/storage/sessions" 2>/dev/null || true
fi
rm -rf "$PRESERVE_DIR"

ok "Extracted to ${PORTAL_DIR}"

# ============================================================
# Step 6: Save license JWT
# ============================================================
echo -e "${CYAN}${BOLD}[6/10]${NC} Saving license..."

echo "$JWT" > "${PORTAL_DIR}/.license"
chmod 600 "${PORTAL_DIR}/.license"
ok "License JWT saved"

# Also save key in panel settings for UI display
$PHP_BIN -r "
define('PANEL_ROOT', '${PANEL_DIR}');
spl_autoload_register(function (\$c) {
    \$p = 'MuseDockPanel\\\\';
    if (strncmp(\$p, \$c, strlen(\$p)) !== 0) return;
    \$f = PANEL_ROOT.'/app/'.str_replace('\\\\','/',substr(\$c,strlen(\$p))).'.php';
    if (file_exists(\$f)) require \$f;
});
if (file_exists(PANEL_ROOT.'/.env')) MuseDockPanel\Env::load(PANEL_ROOT.'/.env');
MuseDockPanel\Settings::set('portal_license_key', '${LICENSE_KEY}');
MuseDockPanel\Settings::set('portal_license_jwt', '${JWT}');
" 2>/dev/null && ok "License key stored in panel settings" || warn "Could not save key to panel settings"

# ============================================================
# Step 7: Run migrations
# ============================================================
echo -e "${CYAN}${BOLD}[7/10]${NC} Running migrations..."

if [ -f "${PORTAL_DIR}/install.php" ]; then
    $PHP_BIN "${PORTAL_DIR}/install.php" 2>&1 | grep -E '(OK|SKIP|ERROR|complete)' | sed 's/^/  /'
    ok "Migrations complete"
fi

# ============================================================
# Step 8: Compile C binary
# ============================================================
echo -e "${CYAN}${BOLD}[8/10]${NC} Compiling native file manager binary..."

if [ -f "${PORTAL_DIR}/bin/musedock-listdir.c" ]; then
    if ! command -v gcc &>/dev/null; then
        apt-get install -y -qq gcc 2>/dev/null
    fi

    if command -v gcc &>/dev/null; then
        gcc -O2 -Wall -Wextra -o "${PORTAL_DIR}/bin/musedock-listdir" "${PORTAL_DIR}/bin/musedock-listdir.c" 2>&1
        chmod 755 "${PORTAL_DIR}/bin/musedock-listdir"
        md5sum "${PORTAL_DIR}/bin/musedock-listdir.c" | cut -d' ' -f1 > "${PORTAL_DIR}/bin/musedock-listdir.md5"
        ok "musedock-listdir compiled"
    else
        warn "gcc not available — file listing will use Python fallback"
    fi
fi

# ============================================================
# Step 9: Permissions + systemd service
# ============================================================
echo -e "${CYAN}${BOLD}[9/10]${NC} Setting up service..."

# Storage permissions
mkdir -p "${PORTAL_DIR}/storage/logs" "${PORTAL_DIR}/storage/cache" "${PORTAL_DIR}/storage/sessions"
chown -R www-data:www-data "${PORTAL_DIR}/storage"
chmod -R 775 "${PORTAL_DIR}/storage"

# Systemd service (if not exists)
if [ ! -f /etc/systemd/system/musedock-portal.service ]; then
    PORTAL_PORT=$(grep -E '^PORTAL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    PORTAL_PORT=${PORTAL_PORT:-8446}
    PORTAL_INTERNAL=$((PORTAL_PORT + 1))

    cat > /etc/systemd/system/musedock-portal.service << SVCEOF
[Unit]
Description=MuseDock Portal - Customer Self-Service
After=network.target postgresql.service musedock-panel.service
Wants=musedock-panel.service

[Service]
Type=simple
User=root
ExecStart=${PHP_BIN} -S 127.0.0.1:${PORTAL_INTERNAL} -t ${PORTAL_DIR}/public ${PORTAL_DIR}/public/index.php
Restart=always
RestartSec=5
WorkingDirectory=${PORTAL_DIR}
Environment=PANEL_ROOT=${PANEL_DIR}

[Install]
WantedBy=multi-user.target
SVCEOF

    systemctl daemon-reload
    systemctl enable musedock-portal 2>/dev/null
    ok "Systemd service created"
else
    ok "Systemd service already exists"
fi

systemctl restart musedock-portal 2>/dev/null && ok "Portal service started" || warn "Could not start portal service"

# ============================================================
# Step 10: License renewal cron
# ============================================================
echo -e "${CYAN}${BOLD}[10/10]${NC} Setting up license renewal..."

CRON_FILE="/etc/cron.d/musedock-portal-license"
if [ ! -f "$CRON_FILE" ]; then
    cat > "$CRON_FILE" << CRONEOF
# MuseDock Portal — License JWT renewal (every 7 days at 3am)
0 3 */7 * * root /bin/bash ${PORTAL_DIR}/bin/renew-license.sh >> /var/log/musedock-portal-renew.log 2>&1
CRONEOF
    chmod 644 "$CRON_FILE"
    ok "License renewal cron installed"
else
    ok "License renewal cron already exists"
fi

# ============================================================
# Summary
# ============================================================
echo ""
echo -e "${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║    MuseDock Portal installed successfully!       ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""
echo -e "  Portal URL:    ${BOLD}https://${SERVER_HOSTNAME}:${PORTAL_PORT:-8446}${NC}"
echo -e "  License Key:   ${BOLD}${LICENSE_KEY}${NC}"
echo -e "  Valid Until:   ${BOLD}${VALID_UNTIL}${NC}"
echo -e "  Max Accounts:  ${BOLD}${MAX_ACCOUNTS}${NC}"
echo -e "  Version:       ${BOLD}${API_VERSION}${NC}"
echo ""
echo -e "  ${YELLOW}Next steps:${NC}"
echo -e "    1. Invite customers from Panel > Settings > Portal"
echo -e "    2. Update Portal: sudo bash ${PORTAL_DIR}/bin/update.sh"
echo ""
