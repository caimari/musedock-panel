#!/bin/bash
# ============================================================
# MuseDock Panel — Install caddy-l4 (Caddy with Layer4 module)
# Used for SNI-based TCP proxy in ISP failover scenarios
# ============================================================

set -e

CADDY_L4_BIN="/usr/local/bin/caddy-l4"
CADDY_L4_VERSION="v2.11.1"
XCADDY_VERSION="0.4.4"

GREEN='\033[0;32m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

# Disable colors if not a terminal
if [ ! -t 1 ]; then
    GREEN="" CYAN="" RED="" BOLD="" NC=""
fi

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
err()  { echo -e "  ${RED}✗${NC} $1"; }
info() { echo -e "  ${CYAN}${BOLD}$1${NC}"; }

echo ""
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════╗"
echo "  ║      MuseDock caddy-l4 Installer         ║"
echo "  ╚══════════════════════════════════════════╝"
echo -e "${NC}"

# Check if already installed
if [ -x "$CADDY_L4_BIN" ]; then
    CURRENT=$($CADDY_L4_BIN version 2>/dev/null | head -1 | awk '{print $1}')
    if [ "$CURRENT" = "$CADDY_L4_VERSION" ]; then
        ok "caddy-l4 ${CADDY_L4_VERSION} already installed at ${CADDY_L4_BIN}"
        $CADDY_L4_BIN list-modules 2>/dev/null | grep -q "layer4" && ok "layer4 module verified"
        echo ""
        exit 0
    else
        info "Upgrading caddy-l4 from ${CURRENT} to ${CADDY_L4_VERSION}..."
    fi
fi

# Check/install Go
if ! command -v go &>/dev/null; then
    info "Go not found. Installing Go..."
    GO_VERSION="1.25.8"
    curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" -o /tmp/go.tar.gz
    rm -rf /usr/local/go
    tar -C /usr/local -xzf /tmp/go.tar.gz
    rm /tmp/go.tar.gz
    export PATH="/usr/local/go/bin:$PATH"
    if ! command -v go &>/dev/null; then
        err "Failed to install Go"
        exit 1
    fi
    ok "Go $(go version | awk '{print $3}') installed"
fi

# Check/install xcaddy
if ! command -v xcaddy &>/dev/null; then
    info "Installing xcaddy..."
    ARCH=$(uname -m)
    case "$ARCH" in
        x86_64)  ARCH="amd64" ;;
        aarch64) ARCH="arm64" ;;
    esac
    curl -fsSL -o /tmp/xcaddy.tar.gz \
        "https://github.com/caddyserver/xcaddy/releases/download/v${XCADDY_VERSION}/xcaddy_${XCADDY_VERSION}_linux_${ARCH}.tar.gz"
    tar xzf /tmp/xcaddy.tar.gz -C /usr/local/bin xcaddy
    chmod +x /usr/local/bin/xcaddy
    rm /tmp/xcaddy.tar.gz
    ok "xcaddy installed"
fi

# Build caddy-l4
info "Building Caddy ${CADDY_L4_VERSION} with layer4 module (this may take a few minutes)..."
export PATH="/usr/local/go/bin:$PATH"
xcaddy build "$CADDY_L4_VERSION" \
    --with github.com/mholt/caddy-l4 \
    --output /tmp/caddy-l4

# Verify
if ! /tmp/caddy-l4 list-modules 2>/dev/null | grep -q "layer4"; then
    err "Build succeeded but layer4 module not found"
    exit 1
fi

# Install
mv /tmp/caddy-l4 "$CADDY_L4_BIN"
chmod +x "$CADDY_L4_BIN"

# Create systemd service (disabled by default — only activated during failover)
cat > /etc/systemd/system/caddy-l4.service << 'UNIT'
[Unit]
Description=Caddy L4 SNI Proxy (MuseDock Failover)
Documentation=https://github.com/mholt/caddy-l4
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/caddy-l4 run --config /etc/caddy/caddy-l4.json
ExecReload=/usr/local/bin/caddy-l4 reload --config /etc/caddy/caddy-l4.json
Restart=on-failure
RestartSec=5
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload

# Create default (empty) config
if [ ! -f /etc/caddy/caddy-l4.json ]; then
    echo '{}' > /etc/caddy/caddy-l4.json
fi

INSTALLED_VERSION=$($CADDY_L4_BIN version 2>/dev/null | head -1 | awk '{print $1}')
MODULE_COUNT=$($CADDY_L4_BIN list-modules 2>/dev/null | grep -c "layer4")

echo ""
ok "caddy-l4 ${INSTALLED_VERSION} installed at ${CADDY_L4_BIN}"
ok "${MODULE_COUNT} layer4 modules available"
ok "Systemd service created (disabled — activate during failover)"
echo ""
