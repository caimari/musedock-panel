<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * LicenseService — Feature gating for MuseDock Panel.
 *
 * Admin panel tier: Source Available (Provider Use), no Pro gating
 * Portal tier: customer-facing commercial module (separate license)
 *
 * Portal license is verified via JWT signed by license.musedock.com.
 */
class LicenseService
{
    // Admin-panel feature constants (kept for compatibility; always enabled)
    public const FEATURE_MULTI_SLAVE    = 'multi-slave';
    public const FEATURE_ELECTION       = 'election';
    public const FEATURE_CHAIN_FAILOVER = 'chain-failover';
    public const FEATURE_PROXY_ROUTES   = 'proxy-routes';

    // Portal feature constants (commercial license)
    public const FEATURE_PORTAL             = 'customer-portal';
    public const FEATURE_PORTAL_FILEMANAGER = 'portal-filemanager';
    public const FEATURE_PORTAL_DATABASES   = 'portal-databases';
    public const FEATURE_PORTAL_EMAIL       = 'portal-email';
    public const FEATURE_PORTAL_BACKUPS     = 'portal-backups';
    public const FEATURE_PORTAL_TICKETS     = 'portal-tickets';

    // Features that require Portal license (commercial, separate from core panel)
    private static array $portalFeatures = [
        self::FEATURE_PORTAL,
        self::FEATURE_PORTAL_FILEMANAGER,
        self::FEATURE_PORTAL_DATABASES,
        self::FEATURE_PORTAL_EMAIL,
        self::FEATURE_PORTAL_BACKUPS,
        self::FEATURE_PORTAL_TICKETS,
    ];

    // Cached JWT payload (verified once per request)
    private static ?array $cachedJwt = null;
    private static bool $jwtChecked = false;

    /**
     * Check if a feature is available under the current license.
     */
    public static function hasFeature(string $feature): bool
    {
        // Portal features — check portal license JWT
        if (in_array($feature, self::$portalFeatures, true)) {
            return self::isPortalLicensed($feature);
        }

        // Core panel: all non-portal features are always available.
        return true;
    }

    /**
     * Backward-compatible alias.
     * Admin panel no longer has Pro gating in the core distribution.
     */
    public static function isProLicense(): bool
    {
        return true;
    }

    /**
     * Check if a portal feature is licensed.
     * Verifies cached JWT payload for the specific feature.
     */
    public static function isPortalLicensed(string $feature = self::FEATURE_PORTAL): bool
    {
        $jwt = self::getCachedJwt();
        if (!$jwt) {
            return false;
        }

        // Check expiration
        if (isset($jwt['exp']) && $jwt['exp'] < time()) {
            return false;
        }

        // Check feature: support both 'features' array and 'product' field
        $features = $jwt['features'] ?? [];
        if (in_array($feature, $features, true) || in_array('all', $features, true)) {
            return true;
        }

        // New JWT format: product='portal' grants all portal features
        $product = $jwt['product'] ?? '';
        if ($product === 'portal' && (str_starts_with($feature, 'customer-portal') || str_starts_with($feature, 'portal-'))) {
            return true;
        }
        if ($product === 'portal' && $feature === self::FEATURE_PORTAL) {
            return true;
        }

        return false;
    }

    /**
     * Get the cached JWT payload, verifying once per request.
     * Reads from panel_settings where the JWT is cached after verification.
     */
    private static function getCachedJwt(): ?array
    {
        if (self::$jwtChecked) {
            return self::$cachedJwt;
        }
        self::$jwtChecked = true;

        $cached = Settings::get('portal_license_jwt', '');
        if (empty($cached)) {
            self::$cachedJwt = null;
            return null;
        }

        $payload = self::verifyJwt($cached);
        self::$cachedJwt = $payload;
        return $payload;
    }

    /**
     * Verify a JWT token with RS256 signature and return its payload.
     *
     * Uses the public key from the Portal installation or panel config.
     * Verification is offline — no internet needed.
     *
     * @return array|null Decoded payload or null if invalid
     */
    public static function verifyJwt(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify header
        $header = json_decode(self::base64url_decode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256') {
            return null;
        }

        // Load public key (from Portal config or Panel config)
        $publicKey = self::loadPublicKey();
        if ($publicKey === null) {
            return null;
        }

        // Verify RS256 signature
        $data = $headerB64 . '.' . $payloadB64;
        $signature = self::base64url_decode($signatureB64);

        $pkeyId = openssl_pkey_get_public($publicKey);
        if ($pkeyId === false) {
            return null;
        }

        $valid = openssl_verify($data, $signature, $pkeyId, OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            return null;
        }

        // Decode payload
        $payload = json_decode(self::base64url_decode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        // Verify issuer
        if (($payload['iss'] ?? '') !== 'license.musedock.com') {
            return null;
        }

        return $payload;
    }

    /**
     * Load the RSA public key for JWT verification.
     */
    private static function loadPublicKey(): ?string
    {
        // Try Portal's bundled key first
        $paths = [
            '/opt/musedock-portal/config/license-public.pem',
            PANEL_ROOT . '/config/license-public.pem',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $key = file_get_contents($path);
                if ($key !== false && str_contains($key, 'PUBLIC KEY')) {
                    return $key;
                }
            }
        }

        return null;
    }

    private static function base64url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Refresh the portal license by contacting the licensing server.
     * Called periodically by the cluster worker or cron.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function refreshPortalLicense(): array
    {
        $currentJwt = Settings::get('portal_license_jwt', '');
        if (empty($currentJwt)) {
            // Try reading from portal .license file
            $licenseFile = '/opt/musedock-portal/.license';
            if (file_exists($licenseFile)) {
                $currentJwt = trim(file_get_contents($licenseFile));
            }
        }

        if (empty($currentJwt)) {
            return ['ok' => false, 'message' => 'No portal license JWT found'];
        }

        // Detect server IP
        $serverIp = @file_get_contents('https://ifconfig.me', false,
            stream_context_create(['http' => ['timeout' => 5]]));
        if (!$serverIp) {
            $serverIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        }
        if (!$serverIp) {
            return ['ok' => false, 'message' => 'Cannot detect server IP'];
        }
        $serverIp = trim($serverIp);
        $hostname = trim((string)(gethostname() ?: ''));

        // Call renewal API
        $apiUrl = 'https://license.musedock.com/api/v1/renew';
        $postData = json_encode([
            'jwt'         => $currentJwt,
            'server_ip'   => $serverIp,
            'hostname'    => $hostname,
            'instance_id' => self::instanceId(),
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $postData,
                'timeout' => 15,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $ctx);
        if ($response === false) {
            return ['ok' => false, 'message' => 'Cannot reach license server'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['success'])) {
            return ['ok' => false, 'message' => $data['error'] ?? 'Renewal failed'];
        }

        // Save new JWT
        $newJwt = $data['jwt'] ?? '';
        if (!empty($newJwt)) {
            self::storePortalJwt($newJwt);
        }

        $message = 'License renewed successfully';
        if (!empty($data['update_available'])) {
            $message .= '. Update available: v' . ($data['latest_version'] ?? '?');
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Identificador de esta instalación (el clúster entero) ante el servidor de licencias.
     * Se crea una vez en el que manda y se copia a las réplicas con los ajustes del portal:
     * así la licencia se renueva desde cualquier servidor del clúster tras un relevo.
     */
    public static function instanceId(): string
    {
        $id = (string)Settings::get('portal_instance_id', '');
        if (!preg_match('/^[a-f0-9]{32,64}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            Settings::set('portal_instance_id', $id);
        }
        return $id;
    }

    /**
     * En el servidor que sirve el portal: renueva la licencia si le quedan menos de 23 días
     * (como mucho cada 6 h). Tras un cambio de rol, el nuevo principal la renueva él mismo y
     * el servidor de licencias se la pasa (mismo clúster), sin transferirla a mano.
     */
    public static function autoRenewPortal(): ?array
    {
        if (!PortalService::shouldServe() || time() - (int)Settings::get('portal_license_renew_at', '0') < 21600) {
            return null;
        }
        $st = self::getPortalStatus();
        $exp = (int)($st['expires'] ?? 0);
        $boundHere = ($st['hostname'] ?? '') === '' || ($st['hostname'] ?? '') === trim((string)gethostname());
        if ($exp > time() + 23 * 86400 && $boundHere) {
            return null;
        }
        Settings::set('portal_license_renew_at', (string)time());
        $r = self::refreshPortalLicense();
        LogService::log('portal.license', null, 'Renovación automática: ' . $r['message']);
        return $r;
    }

    /** IP pública y nombre de este servidor, como los ve el servidor de licencias. */
    private static function serverIdentity(): array
    {
        $ip = trim((string)@file_get_contents('https://ifconfig.me', false, stream_context_create(['http' => ['timeout' => 5]])));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        }
        return [$ip, trim((string)(gethostname() ?: ''))];
    }

    /** Guarda la licencia del portal donde la leen el panel y el portal (.license). */
    private static function storePortalJwt(string $jwt): void
    {
        Settings::set('portal_license_jwt', $jwt);
        $file = '/opt/musedock-portal/.license';
        if (is_dir(dirname($file))) {
            @file_put_contents($file, $jwt);
            @chmod($file, 0600);
        }
    }

    /**
     * Activa (o reactiva tras una transferencia) la clave del portal en ESTE servidor, sin
     * reinstalar el portal. Si la clave sigue asignada a otro servidor, el servidor de
     * licencias lo rechaza: hay que transferirla antes desde su administración.
     *
     * @return array{ok:bool, message:string}
     */
    public static function activatePortalKey(string $key): array
    {
        $key = strtoupper(trim($key));
        if (!preg_match('/^MDCK-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $key)) {
            return ['ok' => false, 'message' => 'Formato de clave inválido. Esperado: MDCK-XXXX-XXXX-XXXX'];
        }
        [$ip, $hostname] = self::serverIdentity();
        $response = @file_get_contents('https://license.musedock.com/api/v1/activate', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'timeout' => 20, 'ignore_errors' => true,
            'content' => json_encode(['key' => $key, 'server_ip' => $ip, 'hostname' => $hostname, 'instance_id' => self::instanceId()]),
        ]]));
        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['success']) || empty($data['jwt'])) {
            return ['ok' => false, 'message' => is_array($data) ? (string)($data['error'] ?? 'Activación rechazada') : 'No se puede contactar con el servidor de licencias'];
        }
        self::storePortalJwt((string)$data['jwt']);
        return ['ok' => true, 'message' => "Licencia activada en {$hostname} ({$ip})" . (!empty($data['valid_until']) ? ', válida hasta ' . $data['valid_until'] : '')];
    }

    /**
     * Aviso al administrador antes de que caduque la licencia del portal (14, 7, 3 y 1 días),
     * al entrar en el periodo de gracia y al caducar. Una vez por etapa. Solo en el servidor
     * que sirve el portal (en las copias está parado y no renueva).
     */
    public static function checkExpiryAndAlert(): ?string
    {
        if (!PortalService::shouldServe()) {
            return null;
        }
        $st = self::getPortalStatus();
        $exp = (int)($st['expires'] ?? 0);
        if ($exp <= 0) {
            return null;
        }
        $days = (int)floor(($exp - time()) / 86400);
        $stage = $exp <= time() ? (($st['status'] ?? '') === 'grace' ? 'grace' : 'expired')
            : ($days < 1 ? 'd1' : ($days < 3 ? 'd3' : ($days < 7 ? 'd7' : ($days < 14 ? 'd14' : ''))));
        $prev = Settings::get('portal_license_alert_stage', '');
        if ($stage === $prev) {
            return $stage;
        }
        Settings::set('portal_license_alert_stage', $stage);
        if ($stage === '') {
            return $stage; // renovada: se reinician las etapas
        }
        $key = (string)($st['license_key'] ?? '');
        $when = date('d/m/Y H:i', $exp);
        $what = match ($stage) {
            'expired' => "La licencia del portal de clientes CADUCÓ el {$when} y ya pasó el periodo de gracia: los clientes no pueden entrar al portal.",
            'grace' => "La licencia del portal de clientes caducó el {$when}. El portal sigue funcionando unos días de gracia (15 desde la caducidad); después los clientes no podrán entrar.",
            default => "La licencia del portal de clientes caduca el {$when} (en {$days} día(s)).",
        };
        $msg = $what . "\n\nClave: {$key}\nServidor al que está ligada: " . ($st['hostname'] ?? '?')
            . "\n\nNormalmente se renueva sola cada semana. Si no lo ha hecho:\n"
            . "  - Ajustes → Portal Clientes → \"Renovar ahora\".\n"
            . "  - Si dice \"Server changed\" (la licencia está ligada a otro servidor, p. ej. tras un cambio de rol): transfiérela en el servidor de licencias y pulsa \"Activar en este servidor\".\n"
            . "  - Si dice \"License expired\": hay que renovar la licencia (alargar su fecha) en el servidor de licencias o con tu proveedor.";
        NotificationService::send('Licencia del portal: ' . match ($stage) { 'expired' => 'caducada', 'grace' => 'en periodo de gracia', default => "caduca en {$days} día(s)" }, $msg, 'license');
        return $stage;
    }

    /**
     * Get the current license tier name.
     */
    public static function getTier(): string
    {
        return 'mit';
    }

    /**
     * Get portal license status for display.
     */
    public static function getPortalStatus(): array
    {
        $jwt = self::getCachedJwt();

        // Also try .license file if no JWT in settings
        if (!$jwt) {
            $licenseFile = '/opt/musedock-portal/.license';
            if (file_exists($licenseFile)) {
                $token = trim(file_get_contents($licenseFile));
                if (!empty($token)) {
                    $jwt = self::verifyJwt($token);
                    // Cache it in settings too
                    if ($jwt) {
                        Settings::set('portal_license_jwt', $token);
                    }
                }
            }
        }

        if (!$jwt) {
            return ['active' => false, 'features' => [], 'expires' => null, 'license_key' => '', 'max_accounts' => 0];
        }

        $graceDays = 15;
        $exp = $jwt['exp'] ?? 0;
        $graceEnd = $exp + ($graceDays * 86400);
        $isActive = $exp > time();
        $isGrace = !$isActive && $graceEnd > time();

        return [
            'active'       => $isActive || $isGrace,
            'status'       => $isActive ? 'active' : ($isGrace ? 'grace' : 'expired'),
            'features'     => $jwt['features'] ?? [],
            'expires'      => $exp,
            'license_key'  => $jwt['sub'] ?? '',
            'hostname'     => $jwt['hostname'] ?? '',
            'max_accounts' => $jwt['max_accounts'] ?? 0,
        ];
    }

    /**
     * Get count of active failover slaves.
     */
    public static function countActiveSlaves(): int
    {
        $servers = FailoverService::getServersByRole(FailoverService::ROLE_FAILOVER);
        return count($servers);
    }

    /**
     * Check if adding another active slave is allowed.
     */
    public static function canAddActiveSlaves(): bool
    {
        return true;
    }
}
