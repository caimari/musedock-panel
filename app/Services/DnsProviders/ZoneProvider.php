<?php
namespace MuseDockPanel\Services\DnsProviders;

/** Zone management contract. Zone credentials remain server-side. */
interface ZoneProvider
{
    public function id(): string;
    public function findZone(string $name): ?array;
    public function refreshZones(): bool;
    /** Normalized result: ok, result{name_servers: string[], status: active|pending}. */
    public function getZone(array $zone): array;
    /** Normalized records: id,type,name,content,ttl,proxied,priority (optional). */
    public function listRecords(array $zone): array;
    public function createRecord(array $zone, array $record): array;
    public function updateRecord(array $zone, string $id, array $record): array;
    public function deleteRecord(array $zone, string $id): array;
}
