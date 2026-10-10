<?php
namespace MuseDockPanel\Services\DnsProviders;

/** Add future zone-management adapters here, independently of Caddy DNS-01 modules. */
final class ProviderRegistry
{
    /** @return ZoneProvider[] */
    public static function providers(): array { return [new CloudflareZoneProvider()]; }

    public static function locate(string $name, bool $refresh = false): ?array
    {
        foreach (self::providers() as $provider) {
            $zone = $provider->findZone($name);
            if (!$zone && $refresh && $provider->refreshZones()) $zone = $provider->findZone($name);
            if ($zone) return ['provider' => $provider, 'zone' => $zone];
        }
        return null;
    }
}
