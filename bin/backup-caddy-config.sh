#!/bin/bash
# MuseDock Panel — Caddy configuration backup
# Keeps daily rotating snapshots plus one validated last-known-good copy.

set -euo pipefail

PANEL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_ROOT="${PANEL_DIR}/storage/backups/caddy"
DAILY_DIR="${BACKUP_ROOT}/daily"
GOOD_DIR="${BACKUP_ROOT}/last-known-good"
LOG_DIR="${PANEL_DIR}/storage/logs"
CADDY_FILE="/etc/caddy/Caddyfile"
CADDY_AUTOSAVE="/var/lib/caddy/.config/caddy/autosave.json"
RETENTION_DAYS="${CADDY_BACKUP_RETENTION_DAYS:-15}"
TS="$(date +%Y%m%d_%H%M%S)"
VALIDATION_LOG="${BACKUP_ROOT}/validate-${TS}.log"

mkdir -p "$DAILY_DIR" "$GOOD_DIR" "$LOG_DIR"
chmod 0750 "$BACKUP_ROOT" "$DAILY_DIR" "$GOOD_DIR" 2>/dev/null || true

if [ ! -f "$CADDY_FILE" ]; then
    echo "[$(date -Is)] Caddyfile not found at $CADDY_FILE; nothing to back up"
    exit 0
fi

SNAPSHOT="${DAILY_DIR}/Caddyfile.${TS}"
cp -a "$CADDY_FILE" "$SNAPSHOT"
chown root:root "$SNAPSHOT" 2>/dev/null || true
chmod 0640 "$SNAPSHOT" 2>/dev/null || true

echo "[$(date -Is)] Saved Caddyfile snapshot: $SNAPSHOT"

if [ -f "$CADDY_AUTOSAVE" ]; then
    AUTOSAVE_SNAPSHOT="${DAILY_DIR}/autosave.${TS}.json"
    cp -a "$CADDY_AUTOSAVE" "$AUTOSAVE_SNAPSHOT"
    chown root:root "$AUTOSAVE_SNAPSHOT" 2>/dev/null || true
    chmod 0640 "$AUTOSAVE_SNAPSHOT" 2>/dev/null || true
    echo "[$(date -Is)] Saved Caddy autosave snapshot: $AUTOSAVE_SNAPSHOT"
fi

if command -v caddy >/dev/null 2>&1; then
    if caddy validate --adapter caddyfile --config "$CADDY_FILE" >"$VALIDATION_LOG" 2>&1; then
        cp -a "$CADDY_FILE" "${GOOD_DIR}/Caddyfile"
        chown root:root "${GOOD_DIR}/Caddyfile" 2>/dev/null || true
        chmod 0640 "${GOOD_DIR}/Caddyfile" 2>/dev/null || true
        {
            echo "timestamp=${TS}"
            echo "source=${CADDY_FILE}"
            echo "validated_with=$(command -v caddy)"
        } > "${GOOD_DIR}/metadata.env"
        chmod 0640 "${GOOD_DIR}/metadata.env" 2>/dev/null || true
        if [ -f "$CADDY_AUTOSAVE" ]; then
            cp -a "$CADDY_AUTOSAVE" "${GOOD_DIR}/autosave.json"
            chown root:root "${GOOD_DIR}/autosave.json" 2>/dev/null || true
            chmod 0640 "${GOOD_DIR}/autosave.json" 2>/dev/null || true
        fi
        rm -f "$VALIDATION_LOG"
        echo "[$(date -Is)] Updated last-known-good Caddy backup"
    else
        chmod 0640 "$VALIDATION_LOG" 2>/dev/null || true
        echo "[$(date -Is)] Caddyfile snapshot saved, but validation failed; last-known-good was not changed"
        echo "[$(date -Is)] Validation log: $VALIDATION_LOG"
    fi
else
    echo "[$(date -Is)] caddy binary not found; snapshot saved, last-known-good not updated"
fi

find "$DAILY_DIR" -type f \( -name 'Caddyfile.*' -o -name 'autosave.*.json' -o -name 'validate-*.log' \) -mtime +"$RETENTION_DAYS" -delete 2>/dev/null || true
