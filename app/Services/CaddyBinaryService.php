<?php
namespace MuseDockPanel\Services;

/**
 * CaddyBinaryService — Caddy binary parity between master and slaves.
 *
 * Caddy's DNS provider modules (dns.providers.cloudflare, route53, …) are
 * COMPILED INTO the binary. They are not configuration, so they cannot be added
 * through Caddy's admin API, and they do not travel with the replicated database
 * or with lsyncd (which only mirrors /var/www/vhosts).
 *
 * Consequence: a freshly installed slave can join the cluster, look healthy, and
 * still be unable to issue DNS-01 certificates on failover — silently — because
 * its stock Caddy lacks the module the master has. This service makes that
 * mismatch VISIBLE (and, later, fixable) instead of something you discover by
 * hand at 3am.
 *
 * Read-only by design: it reports, it does not mutate any binary.
 */
class CaddyBinaryService
{
    /**
     * Describe the LOCAL Caddy binary: path, version, sha256 and DNS modules.
     * Safe to call on master or slave; this is exactly what a node answers to
     * the 'caddy-info' cluster action.
     */
    public static function localInfo(): array
    {
        $path = trim((string)shell_exec('command -v caddy 2>/dev/null')) ?: '/usr/bin/caddy';

        $versionRaw = trim((string)shell_exec(escapeshellarg($path) . ' version 2>/dev/null'));
        $version = '';
        if (preg_match('/^v?(\d+\.\d+\.\d+)/', $versionRaw, $m)) {
            $version = $m[1];
        }

        $hash = '';
        if (is_file($path)) {
            $out = trim((string)shell_exec('sha256sum ' . escapeshellarg($path) . ' 2>/dev/null'));
            if (preg_match('/^([a-f0-9]{64})\s/', $out, $m)) {
                $hash = $m[1];
            }
        }

        // DNS provider modules compiled into this binary.
        $modules = [];
        $modOut = trim((string)shell_exec(escapeshellarg($path) . ' list-modules 2>/dev/null'));
        foreach (preg_split('/\R+/', $modOut) ?: [] as $line) {
            $line = strtolower(trim((string)$line));
            if (!str_starts_with($line, 'dns.providers.')) continue;
            $name = substr($line, strlen('dns.providers.'));
            if ($name !== '' && preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $name)) {
                $modules[] = $name;
            }
        }
        sort($modules, SORT_NATURAL);

        return [
            'ok'          => $version !== '',
            'path'        => $path,
            'version'     => $version,
            'version_raw' => $versionRaw,
            'sha256'      => $hash,
            'dns_modules' => $modules,
        ];
    }

    /**
     * Ask a remote node for its Caddy info via the cluster API.
     */
    public static function remoteInfo(int $nodeId): array
    {
        $resp = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
            'action'  => 'caddy-info',
            'payload' => [],
        ]);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'Sin respuesta del nodo'];
        }
        $data = $resp['data'] ?? [];
        // callNode may wrap the action result under 'result' or return it flat.
        return is_array($data['result'] ?? null) ? $data['result'] : (is_array($data) ? $data : ['ok' => false, 'error' => 'Respuesta ilegible']);
    }

    /**
     * Compare a node's Caddy binary against this (master) one.
     *
     * severity:
     *   ok       — identical hash: same version, same modules, no action needed
     *   warning  — same version but different build (hash differs) or extra modules
     *   critical — the node is MISSING a DNS module the master has: DNS-01 will
     *              fail there on failover
     *   unknown  — could not read the node's binary info
     */
    public static function compareWithNode(int $nodeId, ?array $master = null): array
    {
        $master = $master ?? static::localInfo();
        $remote = static::remoteInfo($nodeId);

        if (empty($remote['ok'])) {
            return [
                'severity' => 'unknown',
                'message'  => 'No se pudo leer el binario de Caddy del nodo: ' . ($remote['error'] ?? 'error desconocido'),
                'master'   => $master,
                'remote'   => $remote,
                'missing_modules' => [],
            ];
        }

        $missing = array_values(array_diff($master['dns_modules'] ?? [], $remote['dns_modules'] ?? []));
        $extra   = array_values(array_diff($remote['dns_modules'] ?? [], $master['dns_modules'] ?? []));
        $sameHash    = ($master['sha256'] ?? '') !== '' && ($master['sha256'] ?? '') === ($remote['sha256'] ?? '');
        $sameVersion = ($master['version'] ?? '') === ($remote['version'] ?? '');

        if ($sameHash) {
            $severity = 'ok';
            $message  = "Binario idéntico al master (v{$remote['version']}, hash coincide).";
        } elseif (!empty($missing)) {
            $severity = 'critical';
            $message  = 'Faltan módulos DNS compilados en este nodo: ' . implode(', ', $missing)
                      . '. Los certificados por DNS-01 fallarán aquí en un failover.';
        } elseif (!$sameVersion) {
            $severity = 'warning';
            $message  = "Versión distinta: master v{$master['version']} vs nodo v{$remote['version']}. "
                      . 'Los módulos coinciden, pero conviene igualar el binario.';
        } else {
            $severity = 'warning';
            $message  = "Misma versión (v{$remote['version']}) y mismos módulos, pero build distinto (hash difiere).";
        }

        return [
            'severity'        => $severity,
            'message'         => $message,
            'same_hash'       => $sameHash,
            'same_version'    => $sameVersion,
            'missing_modules' => $missing,
            'extra_modules'   => $extra,
            'master'          => $master,
            'remote'          => $remote,
        ];
    }

    /**
     * Bring a node to parity by building the master's missing DNS modules into
     * its Caddy, remotely, via xcaddy on that node (reuses the panel's existing
     * idempotent installer — it no-ops if the module is already there).
     *
     * This replaces the manual "scp the master's binary" dance. It is preferred
     * over copying because it needs no architecture assumptions; the trade-off is
     * that the rebuilt binary won't share the master's exact hash (same modules,
     * different build), which auditNodes() reports as 'warning' rather than 'ok'.
     *
     * @param bool $dryRun report what would be installed without touching the node
     */
    public static function syncModulesToNode(int $nodeId, bool $dryRun = false): array
    {
        $cmp = static::compareWithNode($nodeId);
        if ($cmp['severity'] === 'unknown') {
            return ['ok' => false, 'error' => $cmp['message']];
        }

        $missing = $cmp['missing_modules'];
        if (empty($missing)) {
            return ['ok' => true, 'installed' => [], 'message' => 'El nodo ya tiene todos los módulos DNS del master.'];
        }

        if ($dryRun) {
            return [
                'ok' => true, 'dry_run' => true, 'missing' => $missing,
                'plan' => array_map(fn($m) => "xcaddy build con dns.providers.{$m} en el nodo, y reinicio de Caddy", $missing),
            ];
        }

        $results = [];
        foreach ($missing as $provider) {
            $resp = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
                'action'  => 'caddy-install-dns-module',
                'payload' => ['provider' => $provider],
            ]);
            $data = $resp['data']['result'] ?? $resp['data'] ?? [];
            $results[$provider] = [
                'ok'      => !empty($resp['ok']) && !empty($data['ok']),
                'message' => $data['message'] ?? ($data['error'] ?? ($resp['error'] ?? 'sin respuesta')),
            ];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        return ['ok' => $allOk, 'installed' => $results, 'verify' => static::compareWithNode($nodeId)['severity']];
    }

    /**
     * Compare every active node against the master. Used by the cluster UI to
     * surface binary drift before it bites during a failover.
     */
    public static function auditNodes(): array
    {
        $master = static::localInfo();
        $rows = [];
        foreach (ClusterService::getActiveNodes() as $node) {
            $cmp = static::compareWithNode((int)$node['id'], $master);
            $rows[] = [
                'node_id'         => (int)$node['id'],
                'node_name'       => $node['name'] ?? '',
                'severity'        => $cmp['severity'],
                'message'         => $cmp['message'],
                'missing_modules' => $cmp['missing_modules'],
                'remote_version'  => $cmp['remote']['version'] ?? '',
                'remote_hash'     => substr((string)($cmp['remote']['sha256'] ?? ''), 0, 12),
            ];
        }
        return ['master' => $master, 'nodes' => $rows];
    }
}
