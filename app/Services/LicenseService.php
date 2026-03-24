<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * LicenseService — Feature gating for MuseDock Panel.
 *
 * Free tier:  1 master + 1 active slave + unlimited passive replicas
 * Pro tier:   Multi-slave with election, chain of succession, split-brain protection
 *
 * License key is stored in panel_settings as 'license_key'.
 * When no key is present or validation is not yet implemented, all features are unlocked.
 */
class LicenseService
{
    // Feature constants
    public const FEATURE_MULTI_SLAVE    = 'multi-slave';
    public const FEATURE_ELECTION       = 'election';
    public const FEATURE_CHAIN_FAILOVER = 'chain-failover';
    public const FEATURE_PROXY_ROUTES   = 'proxy-routes';

    // Features included in free tier
    private static array $freeFeatures = [
        // Basic failover: 1 master + 1 slave + passive replicas
    ];

    // Features that require Pro license
    private static array $proFeatures = [
        self::FEATURE_MULTI_SLAVE,
        self::FEATURE_ELECTION,
        self::FEATURE_CHAIN_FAILOVER,
        self::FEATURE_PROXY_ROUTES,
    ];

    /**
     * Check if a feature is available under the current license.
     *
     * Currently returns true for all features (no license enforcement yet).
     * When license validation is implemented, Pro features will require a valid key.
     */
    public static function hasFeature(string $feature): bool
    {
        // Free features are always available
        if (in_array($feature, self::$freeFeatures, true)) {
            return true;
        }

        // Pro features — check license
        if (in_array($feature, self::$proFeatures, true)) {
            return self::isProLicense();
        }

        // Unknown features default to allowed
        return true;
    }

    /**
     * Check if the current installation has a valid Pro license.
     *
     * TODO: Implement actual license key validation.
     * For now, returns true (all features unlocked during development).
     */
    public static function isProLicense(): bool
    {
        $licenseKey = Settings::get('license_key', '');

        // TODO: Validate license key against licensing server or local signature
        // For now, all installations are treated as Pro (development mode)
        return true;
    }

    /**
     * Get the current license tier name.
     */
    public static function getTier(): string
    {
        return self::isProLicense() ? 'pro' : 'free';
    }

    /**
     * Get count of active failover slaves (not replicas, not standby).
     * Used to enforce the free tier limit of 1 active slave.
     */
    public static function countActiveSlaves(): int
    {
        $servers = FailoverService::getServersByRole(FailoverService::ROLE_FAILOVER);
        return count($servers);
    }

    /**
     * Check if adding another active slave is allowed under the current license.
     */
    public static function canAddActiveSlaves(): bool
    {
        if (self::hasFeature(self::FEATURE_MULTI_SLAVE)) {
            return true;
        }
        // Free tier: max 1 active slave
        return self::countActiveSlaves() < 1;
    }
}
