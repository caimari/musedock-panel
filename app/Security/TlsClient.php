<?php
namespace MuseDockPanel\Security;

use MuseDockPanel\Env;

/**
 * Central TLS policy for internal HTTP clients.
 *
 * Enforces certificate verification and supports optional host pinning/CA overrides.
 *
 * Supported env vars:
 * - INTERNAL_TLS_CA_FILE=/path/to/ca-bundle.pem
 * - INTERNAL_TLS_CA_MAP={"host":"...pem","host:8444":"...pem"}
 * - INTERNAL_TLS_PIN=sha256//<base64_spki_hash>
 * - INTERNAL_TLS_PINS_JSON={"host":"sha256//...","host:8444":"sha256//..."}
 *
 * Context overrides (highest priority):
 * - ['tls_ca_file' => '/path/to/ca.pem']
 * - ['tls_pin' => 'sha256//...']
 * - ['metadata' => ['tls_ca_file' => ..., 'tls_pin' => ...]]
 */
final class TlsClient
{
    private const DEFAULT_CA_BUNDLES = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ];

    private function __construct()
    {
    }

    public static function forUrl(string $url, array $context = []): array
    {
        if (!preg_match('#^https://#i', $url)) {
            return [];
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $port = (string) (parse_url($url, PHP_URL_PORT) ?? '');

        $opts = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $caFile = self::resolveCaFile($host, $port, $context);
        if ($caFile !== null) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }

        $pin = self::resolvePin($host, $port, $context);
        if ($pin !== null) {
            $opts[CURLOPT_PINNEDPUBLICKEY] = $pin;
        }

        return $opts;
    }

    public static function detectLocalCaddyCaFile(): ?string
    {
        $candidates = [
            '/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt',
            '/root/.local/share/caddy/pki/authorities/local/root.crt',
            '/home/caddy/.local/share/caddy/pki/authorities/local/root.crt',
        ];

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function resolveCaFile(string $host, string $port, array $context): ?string
    {
        $contextCa = self::firstNonEmpty([
            self::contextValue($context, ['tls_ca_file', 'ca_file', 'tls_cafile']),
        ]);
        if ($contextCa !== null && self::isReadableFile($contextCa)) {
            return $contextCa;
        }

        $map = self::readJsonMap((string) Env::get('INTERNAL_TLS_CA_MAP', ''));
        $hostKey = $host . ($port !== '' ? ':' . $port : '');
        $mapped = self::firstNonEmpty([
            $map[$hostKey] ?? null,
            $map[$host] ?? null,
        ]);
        if ($mapped !== null && self::isReadableFile($mapped)) {
            return $mapped;
        }

        $globalCa = trim((string) Env::get('INTERNAL_TLS_CA_FILE', ''));
        if ($globalCa !== '' && self::isReadableFile($globalCa)) {
            return $globalCa;
        }

        foreach (self::DEFAULT_CA_BUNDLES as $bundle) {
            if (self::isReadableFile($bundle)) {
                return $bundle;
            }
        }

        return null;
    }

    private static function resolvePin(string $host, string $port, array $context): ?string
    {
        $contextPin = self::firstNonEmpty([
            self::contextValue($context, ['tls_pin', 'pin', 'tls_public_key_pin']),
        ]);
        $normalized = self::normalizePin($contextPin);
        if ($normalized !== null) {
            return $normalized;
        }

        $map = self::readJsonMap((string) Env::get('INTERNAL_TLS_PINS_JSON', ''));
        $hostKey = $host . ($port !== '' ? ':' . $port : '');
        $mappedPin = self::firstNonEmpty([
            isset($map[$hostKey]) ? (string) $map[$hostKey] : null,
            isset($map[$host]) ? (string) $map[$host] : null,
        ]);
        $normalized = self::normalizePin($mappedPin);
        if ($normalized !== null) {
            return $normalized;
        }

        return self::normalizePin((string) Env::get('INTERNAL_TLS_PIN', ''));
    }

    private static function normalizePin(?string $pin): ?string
    {
        $pin = trim((string) $pin);
        if ($pin === '') {
            return null;
        }

        // cURL also accepts a local public key file path.
        if (str_starts_with($pin, '/') && self::isReadableFile($pin)) {
            return $pin;
        }

        if (str_starts_with($pin, 'sha256//')) {
            return $pin;
        }

        // Allow raw SPKI hash (base64), normalize to curl format.
        if (preg_match('/^[A-Za-z0-9+\/=]{32,}$/', $pin)) {
            return 'sha256//' . $pin;
        }

        return null;
    }

    private static function contextValue(array $context, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($context[$k]) && trim((string) $context[$k]) !== '') {
                return trim((string) $context[$k]);
            }
        }

        $meta = $context['metadata'] ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }

        foreach ($keys as $k) {
            if (isset($meta[$k]) && trim((string) $meta[$k]) !== '') {
                return trim((string) $meta[$k]);
            }
        }

        return null;
    }

    private static function readJsonMap(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function isReadableFile(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * @param array<int, string|null> $values
     */
    private static function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }
}
