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
            'jwt'       => $currentJwt,
            'server_ip' => $serverIp,
            'hostname'  => $hostname,
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
            Settings::set('portal_license_jwt', $newJwt);

            // Also update .license file
            $licenseFile = '/opt/musedock-portal/.license';
            if (is_dir(dirname($licenseFile))) {
                @file_put_contents($licenseFile, $newJwt);
                @chmod($licenseFile, 0600);
            }
        }

        $message = 'License renewed successfully';
        if (!empty($data['update_available'])) {
            $message .= '. Update available: v' . ($data['latest_version'] ?? '?');
        }

        return ['ok' => true, 'message' => $message];
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
