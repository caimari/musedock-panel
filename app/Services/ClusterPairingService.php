<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Emparejamiento de dos paneles (master ↔ slave) SIN que ningún secreto pase por
 * el chat ni por el usuario. Pensado para las herramientas MCP, pero sin depender
 * de ellas.
 *
 * Flujo (como emparejar un dispositivo):
 *  1. MASTER  openWindow()      → abre una ventana de 30 min para recibir solicitudes.
 *  2. SLAVE   requestPairing()  → genera (si falta) su token de cluster y lo envía por
 *                                 TLS al endpoint público /api/pair/request del master.
 *                                 Recibe un CÓDIGO corto (no secreto) que ve el usuario.
 *  3. MASTER  approve(code)     → prueba la API del slave con su token, lo registra
 *                                 como nodo y le envía (acción autenticada pair-accept)
 *                                 su propio token + un nonce que solo conocen los dos.
 *  4. SLAVE   acceptPairing()   → comprueba el nonce, registra al master y pasa a slave.
 *
 * El endpoint público solo responde mientras la ventana está abierta (404 si no),
 * limita solicitudes por IP y guarda como mucho 5 pendientes. Recibir una solicitud
 * NO da acceso a nada: unirse exige que alguien con acceso de escritura al master
 * (MCP con "Permitir acciones que modifican") apruebe su código.
 */
final class ClusterPairingService
{
    private const WINDOW_SECONDS = 1800;
    private const MAX_PENDING = 5;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // ── Utilidades ───────────────────────────────────────────────────────

    /** URL pública de ESTE panel (https://hostname:puerto), o '' si no se conoce. */
    public static function localPanelUrl(): string
    {
        $host = trim(Settings::get('panel_hostname', ''));
        $port = (int)(Settings::get('panel_port', '8444') ?: 8444);
        return $host !== '' ? "https://{$host}:{$port}" : '';
    }

    /** Token de cluster de este panel (lo genera y guarda cifrado si no existe). */
    public static function ensureLocalToken(): string
    {
        $raw = Settings::get('cluster_local_token', '');
        $token = $raw !== '' ? ReplicationService::decryptPassword($raw) : '';
        if ($token === '') {
            $token = ClusterService::generateToken();
            Settings::set('cluster_local_token', ReplicationService::encryptPassword($token));
        }
        return $token;
    }

    public static function currentRole(): string
    {
        return Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    }

    private static function newCode(): string
    {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return substr($c, 0, 4) . '-' . substr($c, 4);
    }

    private static function pending(): array
    {
        $list = json_decode(Settings::get('cluster_pair_pending', '[]'), true);
        $list = is_array($list) ? $list : [];
        // Caducan con la ventana.
        return array_values(array_filter($list, static fn($p) => (int)($p['expires'] ?? 0) > time()));
    }

    private static function savePending(array $list): void
    {
        Settings::set('cluster_pair_pending', json_encode(array_values($list), JSON_UNESCAPED_SLASHES));
    }

    public static function windowOpenUntil(): int
    {
        return (int)Settings::get('cluster_pair_window_until', '0');
    }

    public static function isWindowOpen(): bool
    {
        return self::windowOpenUntil() > time();
    }

    // ── MASTER ───────────────────────────────────────────────────────────

    public static function openWindow(): array
    {
        self::ensureLocalToken();
        $until = time() + self::WINDOW_SECONDS;
        Settings::set('cluster_pair_window_until', (string)$until);
        LogService::log('cluster.pair', 'window-open', 'Ventana de emparejamiento abierta hasta ' . date('H:i', $until));
        return ['ok' => true, 'open_until' => date('Y-m-d H:i:s', $until), 'master_url' => self::localPanelUrl()];
    }

    public static function closeWindow(): void
    {
        Settings::set('cluster_pair_window_until', '0');
    }

    /** Solicitudes pendientes, SIN tokens. */
    public static function listPending(): array
    {
        return array_map(static fn($p) => [
            'code' => $p['code'],
            'name' => $p['name'],
            'api_url' => $p['api_url'],
            'from_ip' => $p['from_ip'],
            'services' => $p['services'],
            'panel_version' => $p['panel_version'] ?? null,
            'received_at' => date('Y-m-d H:i:s', (int)$p['created']),
        ], self::pending());
    }

    /**
     * Endpoint público POST /api/pair/request (lo llama el slave).
     * Devuelve [httpCode, body].
     */
    public static function handleIncomingRequest(array $in, string $fromIp): array
    {
        if (!self::isWindowOpen()) {
            return [404, ['ok' => false, 'error' => 'Not found']];
        }
        if (!self::rateLimit($fromIp)) {
            return [429, ['ok' => false, 'error' => 'Demasiadas solicitudes. Espera unos minutos.']];
        }
        $name = trim((string)($in['name'] ?? ''));
        $apiUrl = rtrim(trim((string)($in['api_url'] ?? '')), '/');
        $token = trim((string)($in['token'] ?? ''));
        $nonce = trim((string)($in['nonce'] ?? ''));
        $services = array_values(array_intersect((array)($in['services'] ?? ['web']), ['web', 'mail'])) ?: ['web'];

        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)
            || !filter_var($apiUrl, FILTER_VALIDATE_URL) || !str_starts_with($apiUrl, 'https://')
            || !preg_match('/^[a-f0-9]{64}$/', $token)
            || !preg_match('/^[a-f0-9]{32,64}$/', $nonce)) {
            return [400, ['ok' => false, 'error' => 'Solicitud no válida.']];
        }
        foreach (ClusterService::getNodes() as $n) {
            if (rtrim((string)$n['api_url'], '/') === $apiUrl) {
                return [409, ['ok' => false, 'error' => 'Ese nodo ya está registrado en este cluster.']];
            }
        }

        $list = array_values(array_filter(self::pending(), static fn($p) => $p['api_url'] !== $apiUrl));
        if (count($list) >= self::MAX_PENDING) {
            return [429, ['ok' => false, 'error' => 'Hay demasiadas solicitudes pendientes.']];
        }
        $code = self::newCode();
        $list[] = [
            'code' => $code,
            'name' => $name,
            'api_url' => $apiUrl,
            'token' => ReplicationService::encryptPassword($token),
            'nonce' => ReplicationService::encryptPassword($nonce),
            'services' => $services,
            'panel_version' => mb_substr((string)($in['panel_version'] ?? ''), 0, 20),
            'from_ip' => $fromIp,
            'created' => time(),
            'expires' => self::windowOpenUntil(),
        ];
        self::savePending($list);
        LogService::log('cluster.pair', 'request', "Solicitud de emparejamiento de {$name} ({$apiUrl}) desde {$fromIp}, código {$code}");
        try {
            NotificationService::send('Cluster: solicitud de emparejamiento',
                "El servidor {$name} ({$apiUrl}, IP {$fromIp}) pide unirse como nodo. Código: {$code}.\n"
                . 'Solo se une si lo apruebas (MCP cluster_pair_approve).');
        } catch (\Throwable) {
        }
        return [200, ['ok' => true, 'code' => $code, 'expires' => date('Y-m-d H:i:s', self::windowOpenUntil())]];
    }

    private static function rateLimit(string $ip): bool
    {
        $dir = (defined('PANEL_ROOT') ? PANEL_ROOT : '/opt/musedock-panel') . '/storage/cache';
        @mkdir($dir, 0750, true);
        $f = $dir . '/pair-ratelimit.json';
        $data = json_decode((string)@file_get_contents($f), true) ?: [];
        $now = time();
        foreach ($data as $k => $ts) {
            $data[$k] = array_values(array_filter((array)$ts, static fn($t) => $t > $now - 600));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $data[$ip][] = $now;
        @file_put_contents($f, json_encode($data), LOCK_EX);
        return count($data[$ip]) <= 5; // 5 solicitudes / 10 min por IP
    }

    /** Busca una solicitud pendiente por código (sin descifrar nada). */
    public static function findPending(string $code): ?array
    {
        $code = strtoupper(trim($code));
        foreach (self::pending() as $p) {
            if (hash_equals($p['code'], $code)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Aprueba una solicitud: prueba la API del slave, lo registra y le envía el
     * token del master. $masterUrl = URL por la que el slave llegará a este panel.
     * $masterPublicIp = IP pública de este servidor (la que vigilará el failover).
     */
    public static function approve(string $code, string $masterUrl, string $masterPublicIp): array
    {
        $p = self::findPending($code);
        if (!$p) {
            throw new \RuntimeException('No hay ninguna solicitud pendiente con ese código (o ha caducado).');
        }
        $slaveToken = ReplicationService::decryptPassword($p['token']);
        $nonce = ReplicationService::decryptPassword($p['nonce']);
        $steps = [];

        $test = ClusterService::callNodeDirect($p['api_url'], $slaveToken, 'POST', 'api/cluster/action', ['action' => 'test-connection'], 20);
        if (empty($test['ok'])) {
            throw new \RuntimeException("El master no llega a la API del slave ({$p['api_url']}): " . ($test['error'] ?: 'sin respuesta')
                . '. Revisa firewall y ALLOWED_IPS del slave (debe permitir la IP de este servidor).');
        }
        $steps[] = 'API del slave accesible con su token';

        $nodeId = ClusterService::addNode($p['name'], $p['api_url'], $slaveToken, $p['services']);
        $steps[] = "Nodo {$p['name']} registrado (id {$nodeId})";

        $masterToken = self::ensureLocalToken();
        $accept = ClusterService::callNodeDirect($p['api_url'], $slaveToken, 'POST', 'api/cluster/action', [
            'action' => 'pair-accept',
            'payload' => [
                'nonce' => $nonce,
                'master_name' => (string)gethostname(),
                'master_url' => $masterUrl,
                'master_token' => $masterToken,
                'master_public_ip' => $masterPublicIp,
            ],
        ], 30);
        if (empty($accept['ok']) || empty($accept['data']['ok'])) {
            ClusterService::removeNode($nodeId);
            $err = (string)($accept['data']['error'] ?? $accept['error'] ?? 'sin respuesta');
            throw new \RuntimeException("El slave rechazó o no completó el emparejamiento ({$err}). Se ha deshecho el registro del nodo.");
        }
        $steps[] = 'El slave registró a este master y pasó a rol slave';

        if (self::currentRole() !== 'master') {
            Settings::set('cluster_role', 'master');
            ClusterService::updateEnvRole('master');
            $steps[] = 'Este panel pasa a rol master';
        }
        self::savePending(array_filter(self::pending(), static fn($x) => $x['code'] !== $p['code']));
        if (!self::pending()) {
            self::closeWindow();
            $steps[] = 'Ventana de emparejamiento cerrada';
        }
        LogService::log('cluster.pair', 'approve', "Nodo {$p['name']} ({$p['api_url']}) emparejado como slave, id {$nodeId}");
        return ['ok' => true, 'node_id' => $nodeId, 'steps' => $steps];
    }

    // ── SLAVE ────────────────────────────────────────────────────────────

    /**
     * Envía la solicitud al master. Devuelve el código a confirmar en el master.
     */
    public static function requestPairing(string $masterUrl, string $name, string $selfUrl, array $services): array
    {
        $masterUrl = rtrim($masterUrl, '/');
        $token = self::ensureLocalToken();
        $nonce = bin2hex(random_bytes(24));

        $ch = curl_init($masterUrl . '/api/pair/request');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'name' => $name,
                'api_url' => $selfUrl,
                'token' => $token,
                'nonce' => $nonce,
                'services' => $services,
                'panel_version' => defined('PANEL_VERSION') ? PANEL_VERSION : '',
            ]),
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("No se pudo contactar con el master ({$masterUrl}): {$err}. "
                . 'Usa la URL pública del panel master (certificado válido) y comprueba que su 8444 admite la IP de este servidor.');
        }
        $data = json_decode((string)$body, true) ?: [];
        if ($http === 404) {
            throw new \RuntimeException('El master no tiene abierta la ventana de emparejamiento. Ábrela antes en el master (cluster_pairing_open).');
        }
        if ($http !== 200 || empty($data['ok'])) {
            throw new \RuntimeException('El master rechazó la solicitud: ' . ($data['error'] ?? "HTTP {$http}"));
        }

        Settings::set('cluster_pair_outgoing', json_encode([
            'master_host' => parse_url($masterUrl, PHP_URL_HOST),
            'nonce' => ReplicationService::encryptPassword($nonce),
            'code' => $data['code'],
            'expires' => time() + self::WINDOW_SECONDS,
        ], JSON_UNESCAPED_SLASHES));
        LogService::log('cluster.pair', 'request-sent', "Solicitud enviada a {$masterUrl}, código {$data['code']}");
        return ['ok' => true, 'code' => $data['code'], 'expires' => $data['expires'] ?? null];
    }

    /**
     * Acción de cluster pair-accept (autenticada con el token local de ESTE slave,
     * que solo conoce el master al que se lo enviamos).
     */
    public static function acceptPairing(array $payload): array
    {
        $out = json_decode(Settings::get('cluster_pair_outgoing', ''), true);
        if (!is_array($out) || (int)($out['expires'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'Este servidor no tiene ninguna solicitud de emparejamiento en curso.'];
        }
        $nonce = ReplicationService::decryptPassword((string)$out['nonce']);
        if ($nonce === '' || !hash_equals($nonce, (string)($payload['nonce'] ?? ''))) {
            return ['ok' => false, 'error' => 'El nonce no coincide.'];
        }
        $masterUrl = rtrim(trim((string)($payload['master_url'] ?? '')), '/');
        $masterToken = trim((string)($payload['master_token'] ?? ''));
        $masterName = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($payload['master_name'] ?? 'master')) ?: 'master';
        $masterIp = trim((string)($payload['master_public_ip'] ?? ''));
        if (!filter_var($masterUrl, FILTER_VALIDATE_URL) || !preg_match('/^[a-f0-9]{64}$/', $masterToken)) {
            return ['ok' => false, 'error' => 'Datos del master no válidos.'];
        }
        // El master que responde debe ser aquel al que se envió la solicitud.
        if (strcasecmp((string)parse_url($masterUrl, PHP_URL_HOST), (string)($out['master_host'] ?? '')) !== 0) {
            return ['ok' => false, 'error' => 'El master que responde no es al que se envió la solicitud.'];
        }

        $existing = null;
        foreach (ClusterService::getNodes() as $n) {
            if (rtrim((string)$n['api_url'], '/') === $masterUrl) {
                $existing = (int)$n['id'];
            }
        }
        if ($existing) {
            ClusterService::updateNode($existing, ['auth_token' => $masterToken, 'role' => 'master']);
        } else {
            $id = ClusterService::addNode($masterName, $masterUrl, $masterToken, ['web']);
            ClusterService::updateNode($id, ['role' => 'master']);
        }
        Settings::set('cluster_role', 'slave');
        ClusterService::updateEnvRole('slave');
        if (filter_var($masterIp, FILTER_VALIDATE_IP)) {
            Settings::set('cluster_master_ip', $masterIp);
        }
        Settings::set('cluster_pair_outgoing', '');
        LogService::log('cluster.pair', 'accept', "Emparejado como slave de {$masterName} ({$masterUrl})");
        return ['ok' => true, 'role' => 'slave'];
    }
}
