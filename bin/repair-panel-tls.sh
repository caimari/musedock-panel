#!/bin/bash
# MuseDock Panel — standalone TLS repair for admin port.
# Does not require panel DB connectivity. Safe to run over SSH when :8444 returns
# ERR_SSL_PROTOCOL_ERROR because Caddy is serving plain HTTP or stale runtime config.

set -euo pipefail

if [ "${EUID}" -ne 0 ]; then
    echo "Run as root: sudo bash bin/repair-panel-tls.sh" >&2
    exit 1
fi

PANEL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${PANEL_DIR}/.env"
CADDY_FILE="/etc/caddy/Caddyfile"

if [ ! -f "$ENV_FILE" ]; then
    echo "No .env found in ${PANEL_DIR}" >&2
    exit 1
fi
if ! command -v caddy >/dev/null 2>&1; then
    echo "Caddy is not installed" >&2
    exit 1
fi
if [ ! -f "$CADDY_FILE" ]; then
    echo "Caddyfile not found: ${CADDY_FILE}" >&2
    exit 1
fi

env_value() {
    local key="$1"
    grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

PANEL_PORT="$(env_value PANEL_PORT)"
PANEL_PORT="${PANEL_PORT:-8444}"
PANEL_INTERNAL_PORT="$(env_value PANEL_INTERNAL_PORT)"
PANEL_INTERNAL_PORT="${PANEL_INTERNAL_PORT:-$((PANEL_PORT + 1))}"
SERVER_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -1)"
ALL_PANEL_IPS="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u | tr '\n' ' ')"
[ -n "$SERVER_IP" ] || SERVER_IP="$(echo "$ALL_PANEL_IPS" | awk '{print $1}')"
[ -n "$SERVER_IP" ] || SERVER_IP="127.0.0.1"
PANEL_SITE_LABELS="https://${SERVER_IP}:${PANEL_PORT}"
for ip in $ALL_PANEL_IPS 127.0.0.1; do
    [ -n "$ip" ] || continue
    case ",$PANEL_SITE_LABELS," in
        *,"https://${ip}:${PANEL_PORT}",*) ;;
        *) PANEL_SITE_LABELS="${PANEL_SITE_LABELS}, https://${ip}:${PANEL_PORT}" ;;
    esac
done
PANEL_SITE_LABELS="${PANEL_SITE_LABELS}, https://localhost:${PANEL_PORT}"

if ! [[ "$PANEL_PORT" =~ ^[0-9]+$ ]] || ! [[ "$PANEL_INTERNAL_PORT" =~ ^[0-9]+$ ]]; then
    echo "Invalid ports: PANEL_PORT=${PANEL_PORT}, PANEL_INTERNAL_PORT=${PANEL_INTERNAL_PORT}" >&2
    exit 1
fi

TMP_FILE="$(mktemp)"
BACKUP_FILE="${CADDY_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp "$CADDY_FILE" "$BACKUP_FILE"

EXISTING_SITES=$(awk -v panel_port="$PANEL_PORT" '
    function starts_panel(line) {
        return line ~ ("^https?://:" panel_port) || line ~ ("^https?://[^ ]*:" panel_port) || line ~ ("^:" panel_port)
    }
    /^{$/ && NR<=5 { in_global=1; next }
    in_global && /^}$/ { in_global=0; next }
    in_global { next }
    starts_panel($0) {
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
' "$CADDY_FILE" 2>/dev/null)

cat > "$TMP_FILE" <<CADDYEOF
{
    auto_https disable_redirects
    admin localhost:2019
}

${PANEL_SITE_LABELS} {
    tls internal
    reverse_proxy 127.0.0.1:${PANEL_INTERNAL_PORT} {
        header_up X-Forwarded-Proto https
        header_up X-Real-Ip {remote_host}
    }
}
CADDYEOF

if [ -n "$(echo "$EXISTING_SITES" | tr -d '[:space:]')" ]; then
    echo "" >> "$TMP_FILE"
    echo "$EXISTING_SITES" >> "$TMP_FILE"
fi

if ! caddy validate --adapter caddyfile --config "$TMP_FILE" >/tmp/musedock-panel-caddy-validate.log 2>&1; then
    cat /tmp/musedock-panel-caddy-validate.log >&2
    rm -f "$TMP_FILE"
    echo "Caddy validation failed; original Caddyfile kept at ${CADDY_FILE}" >&2
    exit 1
fi

mv "$TMP_FILE" "$CADDY_FILE"
rm -f /etc/systemd/system/caddy.service.d/override-resume.conf
systemctl daemon-reload
if ! systemctl restart caddy; then
    echo "Caddy restart failed after writing repaired Caddyfile. Restoring backup: ${BACKUP_FILE}" >&2
    cp "$BACKUP_FILE" "$CADDY_FILE"
    systemctl restart caddy >/dev/null 2>&1 || true
    systemctl status caddy --no-pager >&2 || true
    journalctl -u caddy -n 80 --no-pager >&2 || true
    exit 3
fi
sleep 2

HTTPS_CODE="$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || echo "000")"
if [ "$HTTPS_CODE" != "200" ] && [ "$HTTPS_CODE" != "301" ] && [ "$HTTPS_CODE" != "302" ]; then
    echo "WARNING: HTTPS check returned ${HTTPS_CODE}. Backup: ${BACKUP_FILE}" >&2
    exit 2
fi

echo "OK: panel TLS repaired on https://${SERVER_IP}:${PANEL_PORT}"
echo "Backup: ${BACKUP_FILE}"
