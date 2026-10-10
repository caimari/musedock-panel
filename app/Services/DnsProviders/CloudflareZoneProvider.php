<?php
namespace MuseDockPanel\Services\DnsProviders;

use MuseDockPanel\Services\CloudflareService;

final class CloudflareZoneProvider implements ZoneProvider
{
    public function id(): string { return 'cloudflare'; }
    public function findZone(string $name): ?array { return CloudflareService::findZoneForDomain($name); }
    public function refreshZones(): bool { return CloudflareService::refreshZones(); }
    public function getZone(array $zone): array { return CloudflareService::getZone($zone['token'], $zone['zone_id']); }
    public function listRecords(array $zone): array { return CloudflareService::listRecordsAll($zone['token'], $zone['zone_id'], []); }
    public function createRecord(array $zone, array $record): array { return CloudflareService::createRecord($zone['token'], $zone['zone_id'], $record); }
    public function updateRecord(array $zone, string $id, array $record): array { return CloudflareService::updateRecord($zone['token'], $zone['zone_id'], $id, $record); }
    public function deleteRecord(array $zone, string $id): array { return CloudflareService::deleteRecord($zone['token'], $zone['zone_id'], $id); }
}
