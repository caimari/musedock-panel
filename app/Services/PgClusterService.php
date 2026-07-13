<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Env;

/**
 * PgClusterService — Explicit PostgreSQL cluster identity.
 *
 * Solves the core defect of the old ReplicationService: it derived the
 * PostgreSQL version from the *client* (`psql --version`) and the cluster
 * name from the *first* `pg_lsclusters` row, producing impossible paths like
 * /etc/postgresql/16/main and, worse, wiping the wrong data directory.
 *
 * Every operation in the replication module must go through a fully-resolved
 * cluster descriptor obtained here — never through client-version guessing or
 * an implicit "default" cluster.
 *
 * A descriptor is an associative array:
 *   [
 *     'version'    => '14',                         // major version (server, from pg_lsclusters)
 *     'cluster'    => 'main',                       // cluster name
 *     'port'       => 5432,                         // TCP port
 *     'status'     => 'online',                     // online|down
 *     'owner'      => 'postgres',
 *     'data_dir'   => '/var/lib/postgresql/14/main',
 *     'config_dir' => '/etc/postgresql/14/main',
 *     'config_file'=> '/etc/postgresql/14/main/postgresql.conf',
 *     'hba_file'   => '/etc/postgresql/14/main/pg_hba.conf',
 *     'unit'       => 'postgresql@14-main',         // systemd unit for THIS cluster only
 *     'key'        => '14/main',                    // stable identifier
 *   ]
 */
class PgClusterService
{
    /**
     * List every registered PostgreSQL cluster with a fully-resolved identity.
     * Parses `pg_lsclusters -h` which prints:
     *   Ver Cluster Port Status Owner Data-directory Log-file
     *
     * @return array<int,array<string,mixed>> descriptors keyed numerically
     */
    public static function listClusters(): array
    {
        $raw = trim((string)shell_exec('pg_lsclusters -h 2>/dev/null'));
        if ($raw === '') {
            return [];
        }

        $clusters = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Columns are whitespace-separated; data-directory has no spaces on Debian/Ubuntu.
            $cols = preg_split('/\s+/', $line);
            if (count($cols) < 6) continue;

            $version = $cols[0];
            $cluster = $cols[1];
            $port    = (int)$cols[2];
            $status  = strtolower($cols[3]);
            $owner   = $cols[4];
            $dataDir = $cols[5];

            // Only accept a plausible "<major>/<name>" identity.
            if (!preg_match('/^\d+$/', $version) || $cluster === '') {
                continue;
            }

            $clusters[] = static::describe($version, $cluster, $port, $status, $owner, $dataDir);
        }

        return $clusters;
    }

    /**
     * Build a descriptor from raw fields, deriving config/hba/unit consistently
     * from the SAME (version, cluster) pair — the fix for the mismatched path bug.
     */
    private static function describe(
        string $version,
        string $cluster,
        int $port,
        string $status = 'unknown',
        string $owner = 'postgres',
        string $dataDir = ''
    ): array {
        $configDir = "/etc/postgresql/{$version}/{$cluster}";
        if ($dataDir === '') {
            $dataDir = "/var/lib/postgresql/{$version}/{$cluster}";
        }

        return [
            'version'     => $version,
            'cluster'     => $cluster,
            'port'        => $port,
            'status'      => $status,
            'owner'       => $owner,
            'data_dir'    => $dataDir,
            'config_dir'  => $configDir,
            'config_file' => "{$configDir}/postgresql.conf",
            'hba_file'    => "{$configDir}/pg_hba.conf",
            'unit'        => "postgresql@{$version}-{$cluster}",
            'key'         => "{$version}/{$cluster}",
        ];
    }

    /**
     * Resolve a single cluster by (version, cluster) or by "version/cluster" key.
     * Returns null if that exact cluster is not registered — callers MUST treat
     * null as "refuse to act", never as "fall back to a default".
     */
    public static function get(string $version, string $cluster = ''): ?array
    {
        if ($cluster === '' && str_contains($version, '/')) {
            [$version, $cluster] = explode('/', $version, 2);
        }
        foreach (static::listClusters() as $c) {
            if ($c['version'] === $version && $c['cluster'] === $cluster) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Resolve the cluster that owns a given TCP port. Unambiguous on this host
     * (5432→14/main, 5433→14/panel, 5434→16/musemind).
     */
    public static function getByPort(int $port): ?array
    {
        foreach (static::listClusters() as $c) {
            if ($c['port'] === $port) {
                return $c;
            }
        }
        return null;
    }

    /**
     * The cluster that backs the panel's own DB (from .env DB_PORT). Used to
     * distinguish "the panel database" from the other clusters when monitoring.
     */
    public static function getPanelCluster(): ?array
    {
        $port = (int)Env::get('DB_PORT', '5433');
        return static::getByPort($port);
    }

    /**
     * Does the data directory contain a real cluster (PG_VERSION present)?
     * Used to refuse destructive operations on a populated directory without
     * explicit confirmation.
     */
    public static function dataDirHasData(array $cluster): bool
    {
        $dir = $cluster['data_dir'] ?? '';
        if ($dir === '' || !is_dir($dir)) return false;
        // A live/initialised cluster always has PG_VERSION at its root.
        if (is_file("{$dir}/PG_VERSION")) return true;
        // Any base/ content also indicates data.
        return is_dir("{$dir}/base") && count((array)@scandir("{$dir}/base")) > 2;
    }

    /**
     * Approximate on-disk size of a cluster's data directory in bytes.
     * Best-effort; returns 0 if it cannot be measured.
     */
    public static function dataDirSizeBytes(array $cluster): int
    {
        $dir = $cluster['data_dir'] ?? '';
        if ($dir === '' || !is_dir($dir)) return 0;
        $out = trim((string)shell_exec('du -sb ' . escapeshellarg($dir) . ' 2>/dev/null'));
        if (preg_match('/^(\d+)/', $out, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Free space in bytes on the filesystem holding the cluster's data dir.
     */
    public static function dataDirFreeBytes(array $cluster): int
    {
        $dir = $cluster['data_dir'] ?? '/var/lib/postgresql';
        $parent = is_dir($dir) ? $dir : dirname($dir);
        $free = @disk_free_space($parent);
        return $free === false ? 0 : (int)$free;
    }

    /**
     * Whether a cluster is currently running, checked via its OWN systemd unit,
     * never the umbrella `postgresql.service`.
     */
    public static function isRunning(array $cluster): bool
    {
        $unit = $cluster['unit'] ?? '';
        if ($unit === '') return false;
        $state = trim((string)shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
        if ($state === 'active') return true;
        // Fall back to pg_isready on the port (unit naming can differ on some installs).
        $port = (int)($cluster['port'] ?? 0);
        if ($port > 0) {
            $ready = trim((string)shell_exec('pg_isready -h 127.0.0.1 -p ' . $port . ' 2>/dev/null'));
            return str_contains($ready, 'accepting connections');
        }
        return false;
    }

    /**
     * Human label for UI/logs, e.g. "PostgreSQL 14/main (:5432)".
     */
    public static function label(array $cluster): string
    {
        return sprintf(
            'PostgreSQL %s/%s (:%d)',
            $cluster['version'] ?? '?',
            $cluster['cluster'] ?? '?',
            $cluster['port'] ?? 0
        );
    }
}
