#!/usr/bin/env bash
# Installs only a locally supplied, verified official client. No downloads/firewall changes.
set -euo pipefail
if [[ ${EUID} -ne 0 ]]; then echo 'Ejecuta este instalador como root.' >&2; exit 1; fi
if [[ $# -ne 2 ]]; then echo 'Uso: install-chatgpt-tunnel.sh /ruta/tunnel-client SHA256_VERIFICADO' >&2; exit 1; fi
php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("curl") ? 0 : 1);' || { echo 'Faltan PDO SQLite o curl en PHP.' >&2; exit 1; }
client_path=$(realpath "$1")
expected_hash=$2
panel_dir=$(cd -- "$(dirname -- "$0")/.." && pwd)
[[ -f "$client_path" && ! -L "$client_path" && "$expected_hash" =~ ^[a-fA-F0-9]{64}$ ]] || exit 1
actual_hash=$(sha256sum "$client_path"); actual_hash=${actual_hash%% *}
[[ ${actual_hash,,} == ${expected_hash,,} ]] || { echo 'SHA256 no coincide.' >&2; exit 1; }
getent passwd musedock-tunnel >/dev/null || useradd --system --user-group --home-dir /var/lib/musedock-tunnel --shell /usr/sbin/nologin musedock-tunnel
install -d -o root -g root -m 0755 /usr/local/lib/musedock-tunnel
install -o root -g root -m 0755 "$client_path" /usr/local/lib/musedock-tunnel/tunnel-client
install -d -o musedock-tunnel -g musedock-tunnel -m 0700 /var/lib/musedock-tunnel
mkdir -p "$panel_dir/storage/chatgpt"
chown root:musedock-tunnel "$panel_dir/storage/chatgpt"; chmod 0710 "$panel_dir/storage/chatgpt"
for file in oauth.sqlite oauth.sqlite-journal; do
    if [[ -f "$panel_dir/storage/chatgpt/$file" ]]; then chown root:root "$panel_dir/storage/chatgpt/$file"; chmod 0600 "$panel_dir/storage/chatgpt/$file"; fi
done
for file in openai-key profile.yaml; do
    if [[ -f "$panel_dir/storage/chatgpt/$file" ]]; then chown root:musedock-tunnel "$panel_dir/storage/chatgpt/$file"; chmod 0640 "$panel_dir/storage/chatgpt/$file"; fi
done
# Prevent shell/sed metacharacters in the installation root.
[[ "$panel_dir" =~ ^/[a-zA-Z0-9_./-]+$ ]] || exit 1
sed "s|__PANEL_DIR__|$panel_dir|g" "$panel_dir/config/chatgpt/musedock-chatgpt-tunnel.service" > /etc/systemd/system/musedock-chatgpt-tunnel.service
systemctl daemon-reload
# Remains stopped. Operator uses the panel to explicitly connect after validation.
echo 'Servicio instalado y detenido. Publica las rutas OAuth HTTPS y configura Ajustes → MCP.'
