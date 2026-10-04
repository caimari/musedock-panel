<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Testigos externos ("solo ojos", bin/witness-agent.py): servidores ajenos al cluster que
 * miran desde fuera si llegan a los servidores (por cada entrada: ONO, Orange…) y con qué
 * latencia y pérdidas. El panel les pregunta por HTTPS con su certificado FIJADO por la
 * huella SHA-256 (autofirmado, sin depender de nadie) y una clave propia de cada testigo.
 *
 * Registro por panel (la clave se cifra con la de este panel y nunca sale de él):
 *   Settings witness_agents = [{"name","url","fingerprint","key"(cifrada)}]
 */
class WitnessService
{
    public static function all(): array
    {
        $list = json_decode((string)Settings::get('witness_agents', '[]'), true);
        return is_array($list) ? array_values($list) : [];
    }

    public static function add(string $name, string $url, string $fingerprint, string $key): array
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $name)) {
            return ['ok' => false, 'error' => 'nombre no válido (letras, números y guiones)'];
        }
        if (!preg_match('#^https://[^/\s]+(:\d+)?/?$#', $url)) {
            return ['ok' => false, 'error' => 'la URL debe ser https://IP:puerto'];
        }
        $fp = self::normFingerprint($fingerprint);
        if (strlen($fp) !== 64) {
            return ['ok' => false, 'error' => 'huella SHA-256 no válida (64 cifras hexadecimales, con o sin ":")'];
        }
        if (strlen($key) < 32) {
            return ['ok' => false, 'error' => 'clave demasiado corta'];
        }
        $entry = ['name' => $name, 'url' => rtrim($url, '/'), 'fingerprint' => $fp, 'key' => ReplicationService::encryptPassword($key)];
        // Antes de guardar: que conteste con esa huella y esa clave.
        $probe = self::query($entry);
        if (empty($probe['ok'])) {
            return ['ok' => false, 'error' => 'el testigo no responde con esa huella y clave: ' . ($probe['error'] ?? '?')];
        }
        $list = array_values(array_filter(self::all(), static fn($w) => ($w['name'] ?? '') !== $name));
        $list[] = $entry;
        Settings::set('witness_agents', json_encode($list));
        LogService::log('witness', 'add', "Testigo {$name} registrado ({$entry['url']})");
        return ['ok' => true, 'witness' => $probe['witness'] ?? $name, 'targets' => array_keys($probe['targets'] ?? [])];
    }

    public static function remove(string $name): bool
    {
        $before = self::all();
        $list = array_values(array_filter($before, static fn($w) => ($w['name'] ?? '') !== strtolower($name)));
        Settings::set('witness_agents', json_encode($list));
        return count($list) < count($before);
    }

    /** Pregunta a un testigo. ['ok'=>bool, 'targets'=>[id=>{addr,name,ok,latency_avg_ms,loss_pct,samples…}]] */
    public static function query(array $w, int $timeout = 6): array
    {
        $key = ReplicationService::decryptPassword((string)($w['key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'sin clave'];
        }
        $ctx = stream_context_create([
            'http' => ['header' => "Authorization: Bearer {$key}\r\n", 'timeout' => $timeout, 'ignore_errors' => true],
            // Certificado propio del testigo: se acepta SOLO si su huella es la registrada.
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                      'peer_fingerprint' => ['sha256' => (string)($w['fingerprint'] ?? '')]],
        ]);
        $raw = @file_get_contents(rtrim((string)$w['url'], '/') . '/v1/status', false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'no responde (o la huella no coincide)'];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || empty($d['ok'])) {
            return ['ok' => false, 'error' => 'respuesta no válida o clave incorrecta'];
        }
        return $d;
    }

    /** Pregunta a todos. [name => respuesta] */
    public static function queryAll(): array
    {
        $out = [];
        foreach (self::all() as $w) {
            $out[$w['name']] = self::query($w);
        }
        return $out;
    }

    /**
     * Cómo ven los testigos una dirección (IP): por cada testigo que responde y tiene una
     * comprobación de esa dirección, 'up' (llega bien), 'down' (no llega, con muestras
     * suficientes) o 'degraded' (llega, pero con latencia/pérdidas por encima de los
     * umbrales). $nameFilter limita a comprobaciones de ese nombre (p. ej. el de salud).
     */
    public static function verdicts(string $addr, ?string $nameFilter = null, int $maxLatency = 0, int $maxLoss = 0, ?array $answers = null): array
    {
        $answers ??= self::queryAll();
        $v = [];
        foreach ($answers as $wname => $a) {
            if (empty($a['ok'])) {
                continue;
            }
            foreach ((array)($a['targets'] ?? []) as $id => $t) {
                if ((string)($t['addr'] ?? '') !== $addr || ($nameFilter !== null && (string)($t['name'] ?? '') !== $nameFilter)) {
                    continue;
                }
                if ((int)($t['samples'] ?? 0) < 3) {
                    continue;   // aún sin datos suficientes
                }
                $loss = (int)($t['loss_pct'] ?? 0);
                $lat = (int)($t['latency_avg_ms'] ?? 0);
                if (empty($t['ok']) && $loss >= 50) {
                    $v[$wname] = 'down';
                } elseif (($maxLoss > 0 && $loss > $maxLoss) || ($maxLatency > 0 && $lat > $maxLatency)) {
                    $v[$wname] = 'degraded';
                } elseif (!empty($t['ok'])) {
                    $v[$wname] = 'up';
                }
            }
        }
        return $v;
    }

    // ── Generador del script de instalación de un testigo ────────────────

    /**
     * Comprobaciones que se proponen para un testigo nuevo, sin nombres fijos: cada
     * servidor de Failover → Servidores (tcp a su IP pública, puerto 443) y, por cada
     * servidor que vigila el vigilante de entrada de este panel, su nombre de
     * comprobación por la entrada normal (IP fija) y por la alternativa (resolve_host).
     */
    public static function defaultTargets(): array
    {
        $t = [];
        $slug = static fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-') ?: 'srv';
        foreach (FailoverService::getServers() as $srv) {
            if (!filter_var((string)($srv['ip'] ?? ''), FILTER_VALIDATE_IP)) {
                continue;
            }
            $t[] = ['id' => $slug((string)$srv['name']), 'type' => 'tcp', 'host' => (string)$srv['ip'], 'port' => 443];
        }
        foreach ((array)(IngressWatchService::config()['servers'] ?? []) as $w) {
            $base = $slug(preg_replace('/^health-/', '', explode('.', (string)$w['health'])[0]));
            $t[] = ['id' => "{$base}-normal", 'type' => 'https', 'url' => "https://{$w['health']}/", 'resolve' => (string)$w['ip'], 'expect' => 'ok-'];
            $t[] = ['id' => "{$base}-alternativa", 'type' => 'https', 'url' => "https://{$w['health']}/", 'resolve_host' => (string)$w['alt_host'], 'expect' => 'ok-'];
        }
        return $t;
    }

    /** IPs que podrán preguntar al testigo: las públicas de los servidores del relevo. */
    public static function defaultAllowed(): array
    {
        $ips = [];
        foreach (FailoverService::getServers() as $srv) {
            if (filter_var((string)($srv['ip'] ?? ''), FILTER_VALIDATE_IP)) {
                $ips[] = (string)$srv['ip'];
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Script de bash que instala (o actualiza) el agente en el servidor testigo. No lleva
     * ningún secreto: la clave y el certificado se crean EN el testigo. Volver a
     * ejecutarlo actualiza el agente y la lista de comprobaciones y conserva la clave
     * y el certificado (la huella no cambia).
     */
    public static function installScript(string $listenIp, int $port, array $targets, array $allowed, int $interval = 15): array
    {
        if (!filter_var($listenIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'IP pública del testigo no válida'];
        }
        if ($port < 1024 || $port > 65535) {
            return ['ok' => false, 'error' => 'puerto no válido'];
        }
        $clean = [];
        foreach ($targets as $t) {
            $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($t['id'] ?? '')));
            $type = ($t['type'] ?? '') === 'https' ? 'https' : 'tcp';
            if ($id === '') {
                continue;
            }
            if ($type === 'tcp') {
                if (!filter_var((string)($t['host'] ?? ''), FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+$/i', (string)($t['host'] ?? ''))) {
                    continue;
                }
                $clean[] = ['id' => $id, 'type' => 'tcp', 'host' => (string)$t['host'], 'port' => max(1, min(65535, (int)($t['port'] ?? 443)))];
            } else {
                if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/[^\s"\']*$#i', (string)($t['url'] ?? ''))) {
                    continue;
                }
                $c = ['id' => $id, 'type' => 'https', 'url' => (string)$t['url']];
                if (filter_var((string)($t['resolve'] ?? ''), FILTER_VALIDATE_IP)) {
                    $c['resolve'] = (string)$t['resolve'];
                } elseif (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', (string)($t['resolve_host'] ?? ''))) {
                    $c['resolve_host'] = strtolower((string)$t['resolve_host']);
                }
                if (($t['expect'] ?? '') !== '' && preg_match('/^[\w .:-]{1,60}$/u', (string)$t['expect'])) {
                    $c['expect'] = (string)$t['expect'];
                }
                $clean[] = $c;
            }
        }
        if (!$clean) {
            return ['ok' => false, 'error' => 'no hay ninguna comprobación válida'];
        }
        $allowed = array_values(array_filter(array_map('trim', $allowed), static fn($x) => (bool)preg_match('#^[0-9a-f.:]+(/\d{1,3})?$#i', $x)));
        $agent = (string)@file_get_contents(PANEL_ROOT . '/bin/witness-agent.py');
        if ($agent === '') {
            return ['ok' => false, 'error' => 'no se encuentra bin/witness-agent.py'];
        }
        $b64 = chunk_split(base64_encode($agent), 76, "\n");
        $sha = hash('sha256', $agent);
        $targetsJson = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $allowList = implode(' ', array_map('escapeshellarg', $allowed));
        $panel = (string)(Settings::get('panel_hostname', '') ?: gethostname());
        $interval = max(5, min(300, $interval));

        $sh = <<<SH
#!/bin/bash
# Testigo MuseDock ("solo ojos") — generado por el panel de {$panel} el %DATE%.
# Instala o actualiza el agente: no tiene datos, ni acceso a otros servidores, ni guarda
# registros; solo mira una lista fija de comprobaciones y responde con clave por HTTPS.
# La clave y el certificado se crean AQUÍ y no salen de este servidor. Volver a
# ejecutar este script actualiza el agente y las comprobaciones y conserva clave y huella.
set -euo pipefail
[ "\$(id -u)" = 0 ] || { echo "Ejecútalo como root."; exit 1; }
for c in python3 openssl base64; do command -v \$c >/dev/null || { echo "Falta \$c (apt install python3 openssl coreutils)."; exit 1; }; done

LISTEN={$listenIp}
PORT={$port}
DIR=/etc/musedock-witness
BIN=/usr/local/bin/musedock-witness.py

id mdwitness >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d /nonexistent mdwitness
install -d -m 700 "\$DIR"

# Agente (sha256 {$sha})
base64 -d > "\$BIN.new" <<'AGENT'
{$b64}AGENT
echo "{$sha}  \$BIN.new" | sha256sum -c --quiet
install -m 755 "\$BIN.new" "\$BIN" && rm -f "\$BIN.new"

# Certificado propio (solo la primera vez: la huella registrada en el panel no cambia)
if [ ! -s "\$DIR/cert.pem" ]; then
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 3650 \\
    -subj "/CN=musedock-witness" -keyout "\$DIR/key.pem" -out "\$DIR/cert.pem" 2>/dev/null
fi

# Configuración: conserva la clave si ya existía; si no, crea una nueva aquí.
TARGETS=\$(cat <<'TARGETS_JSON'
{$targetsJson}
TARGETS_JSON
)
MDW_LISTEN="\$LISTEN" MDW_PORT="\$PORT" MDW_INTERVAL={$interval} MDW_TARGETS="\$TARGETS" python3 - <<'PY'
import json, os, secrets
p = "/etc/musedock-witness/config.json"
old = {}
if os.path.exists(p):
    with open(p) as f:
        old = json.load(f)
cfg = {
    "listen": os.environ["MDW_LISTEN"], "port": int(os.environ["MDW_PORT"]),
    "key": old.get("key") or secrets.token_hex(24),
    "interval": int(os.environ["MDW_INTERVAL"]), "timeout": 8, "window": 20,
    "tls_cert": "/etc/musedock-witness/cert.pem", "tls_key": "/etc/musedock-witness/key.pem",
    "targets": json.loads(os.environ["MDW_TARGETS"]),
}
fd = os.open(p + ".new", os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, "w") as f:
    json.dump(cfg, f, indent=2)
os.replace(p + ".new", p)
PY
chown -R mdwitness: "\$DIR"; chmod 600 "\$DIR/config.json" "\$DIR/key.pem"; chmod 644 "\$DIR/cert.pem"

cat > /etc/systemd/system/musedock-witness.service <<'UNIT'
[Unit]
Description=MuseDock testigo (solo ojos)
After=network-online.target
Wants=network-online.target
[Service]
User=mdwitness
ExecStart=/usr/bin/python3 /usr/local/bin/musedock-witness.py
Restart=always
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadOnlyPaths=/etc/musedock-witness
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable musedock-witness >/dev/null 2>&1
systemctl restart musedock-witness

# Cortafuegos: el puerto del testigo SOLO para estos orígenes (si hay ufw activo).
ALLOWED=({$allowList})
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  for ip in "\${ALLOWED[@]}"; do ufw allow from "\$ip" to any port "\$PORT" proto tcp comment "musedock-witness" >/dev/null; done
  echo "Cortafuegos: \$PORT abierto solo a: \${ALLOWED[*]}"
else
  echo "AVISO: no hay ufw activo. Abre el puerto \$PORT SOLO a: \${ALLOWED[*]} en tu cortafuegos."
fi

sleep 2
systemctl is-active --quiet musedock-witness || { journalctl -u musedock-witness -n 20 --no-pager; exit 1; }
echo
echo "== Testigo listo. Para registrarlo en el panel (Ajustes → Testigos):"
echo "   URL:    https://\$LISTEN:\$PORT"
echo "   Huella: \$(openssl x509 -in "\$DIR/cert.pem" -noout -fingerprint -sha256 | cut -d= -f2)"
echo "   Clave:  no se muestra aquí. Para verla y copiarla al formulario del panel:"
echo "           python3 -c \"import json;print(json.load(open('\$DIR/config.json'))['key'])\""
echo "   (no la pegues en ningún chat ni la guardes en otro sitio)"

SH;
        $sh = str_replace('%DATE%', date('Y-m-d H:i'), $sh);
        return ['ok' => true, 'script' => $sh, 'targets' => $clean, 'agent_sha256' => $sha];
    }

    private static function normFingerprint(string $fp): string
    {
        return strtolower(preg_replace('/[^0-9a-f]/i', '', $fp));
    }
}
