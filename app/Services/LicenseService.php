<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * LicenseService — Feature gating for MuseDock Panel.
 *
 * Free tier:  Admin panel (MIT), 1 master + 1 active slave
 * Pro tier:   Multi-slave, election, chain failover, proxy routes
 * Portal tier: Customer portal, file manager, DB management (separate commercial license)
 *
 * License key is stored in panel_settings as 'license_key'.
 * Portal license verified via JWT from musedock.com/api/license/check.
 */
class LicenseService
{
    // Admin panel feature constants
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

    // Features included in free tier (always available)
    private static array $freeFeatures = [
        // Basic failover: 1 master + 1 slave + passive replicas
    ];

    // Features that require Pro license (admin panel)
    private static array $proFeatures = [
        self::FEATURE_MULTI_SLAVE,
        self::FEATURE_ELECTION,
        self::FEATURE_CHAIN_FAILOVER,
        self::FEATURE_PROXY_ROUTES,
    ];

    // Features that require Portal license (commercial, separate from Pro)
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
        // Free features are always available
        if (in_array($feature, self::$freeFeatures, true)) {
            return true;
        }

        // Pro features — check admin license
        if (in_array($feature, self::$proFeatures, true)) {
            return self::isProLicense();
        }

        // Portal features — check portal license JWT
        if (in_array($feature, self::$portalFeatures, true)) {
            return self::isPortalLicensed($feature);
        }

        // Unknown features default to allowed
        return true;
    }

    /**
     * Check if the current installation has a valid Pro license.
     * TODO: Implement actual license key validation for admin Pro features.
     */
    public static function isProLicense(): bool
    {
        $licenseKey = Settings::get('license_key', '');
        // TODO: Validate license key against licensing server
        // For now, all installations are treated as Pro (development mode)
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

        // Check feature is in the licensed features list
        $features = $jwt['features'] ?? [];
        return in_array($feature, $features, true) || in_array('all', $features, true);
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
     * Verify a JWT token and return its payload.
     *
     * Expected JWT payload structure:
     * {
     *   "sub": "license_id",
     *   "iss": "musedock.com",
     *   "domain": "panel.example.com",
     *   "ip": "1.2.3.4",
     *   "features": ["customer-portal", "portal-filemanager", ...],
     *   "exp": 1735689600,
     *   "verified_at": 1704067200
     * }
     *
     * TODO: Implement RS256 signature verification with public key.
     * For now, returns null (no portal license active).
     *
     * @return array|null Decoded payload or null if invalid
     */
    public static function verifyJwt(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        // TODO: Implement proper JWT RS256 verification
        // 1. Split token into header.payload.signature
        // 2. Verify signature with public key (stored in config or hardcoded)
        // 3. Decode payload
        // 4. Verify 'iss' === 'musedock.com'
        // 5. Verify 'domain' matches this panel's domain
        // 6. Verify 'exp' > time()
        // 7. Return payload array

        // Placeholder: decode without verification (INSECURE, development only)
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * Refresh the portal license by contacting the licensing server.
     * Called periodically (every 24h) by the cluster worker.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function refreshPortalLicense(): array
    {
        $licenseKey = Settings::get('portal_license_key', '');
        if (empty($licenseKey)) {
            Settings::set('portal_license_jwt', '');
            return ['ok' => false, 'message' => 'No portal license key configured'];
        }

        // TODO: Implement actual API call to musedock.com
        // POST musedock.com/api/license/check
        // Body: { key: $licenseKey, domain: $panelDomain, ip: $serverIp }
        // Response: { ok: true, jwt: "eyJ..." } or { ok: false, error: "..." }

        return ['ok' => false, 'message' => 'License verification not yet implemented'];
    }

    /**
     * Get the current license tier name.
     */
    public static function getTier(): string
    {
        return self::isProLicense() ? 'pro' : 'free';
    }

    /**
     * Get portal license status for display.
     */
    public static function getPortalStatus(): array
    {
        $jwt = self::getCachedJwt();
        if (!$jwt) {
            return ['active' => false, 'features' => [], 'expires' => null];
        }
        return [
            'active' => !isset($jwt['exp']) || $jwt['exp'] > time(),
            'features' => $jwt['features'] ?? [],
            'expires' => $jwt['exp'] ?? null,
            'domain' => $jwt['domain'] ?? '',
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
        if (self::hasFeature(self::FEATURE_MULTI_SLAVE)) {
            return true;
        }
        return self::countActiveSlaves() < 1;
    }
}
