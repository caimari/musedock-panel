<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Settings;

/**
 * Conmutación automática ante bloqueos de Cloudflare para los dominios de hosting del panel.
 *
 * Igual que en MuseDock CMS (mismo protocolo de sondas): unas sondas en conexiones reales (p. ej. una máquina
 * en España con dos operadoras) prueban cada minuto si llegan a las IPs de Cloudflare de estos dominios y, si
 * hace falta, directas al servidor de origen. Si una IP deja de responder desde alguna operadora (bloqueos de
 * LaLiga, caídas de Cloudflare…) y el origen responde con un certificado válido, se quita el proxy (nube
 * naranja) de los registros afectados; cuando la IP lleva un rato respondiendo, se vuelve a poner.
 *
 * Reglas que no se rompen:
 *  - Solo se toca lo que tenía el proxy puesto; lo que iba directo nunca entra en el ciclo.
 *  - Al volver, solo se reactiva si el registro sigue como lo dejó el sistema (si no, se respeta y se avisa).
 *  - Sin informes de sondas recientes no se cambia nada. En «simulación» no se toca Cloudflare.
 *  - Las sondas solo informan: las claves de Cloudflare no salen del panel.
 *  - Las cuentas con un MuseDock CMS que ya hace esto (EdgeGuard) se excluyen solas: lo gestiona el CMS.
 *  - Solo actúa el panel que manda (master o standalone, sin aislar).
 */
class CfGuardService
{
    public const MODES = ['off', 'simulate', 'auto'];
    private const PROBE_STALE = 300;
    private const FAIL_WINDOW = 600;
    private const FAILS_TO_BLOCK = 2;
    private const ORIGIN_FRESH = 420;
    private const INVENTORY_EVERY = 1800;

    // ------------------------------------------------------------------ ajustes

    public static function setting(string $key, string $default = ''): string
    {
        return Settings::get('cf_guard_' . $key, $default);
    }

    public static function setSetting(string $key, ?string $value): void
    {
        Settings::set('cf_guard_' . $key, $value ?? '');
    }

    /** Claves de configuración que siguen al que manda (nada de estado: bloqueos, observaciones, inventario). */
    public const SYNC_KEYS = ['cf_guard_mode', 'cf_guard_restore_minutes', 'cf_guard_excluded', 'cf_guard_display_tz', 'cf_guard_probes', 'cf_guard_extra_hosts'];

    /**
     * Configuración que sigue al que manda, para el otro nodo. Las sondas viajan solo como HUELLA (sha256), nunca el
     * token: sirve para reconocer a la sonda, no para hacerse pasar por ella. Sin claves de Cloudflare.
     */
    public static function exportConfig(): array
    {
        return [
            'mode' => self::mode(),
            'restore_minutes' => self::setting('restore_minutes', ''),
            'excluded' => self::setting('excluded', ''),
            'extra_hosts' => self::setting('extra_hosts', ''),
            'display_tz' => self::setting('display_tz', ''),
            'probes' => array_map(static fn($p) => ['name' => (string)($p['name'] ?? ''), 'hash' => (string)($p['hash'] ?? ''), 'created_at' => (string)($p['created_at'] ?? '')], self::probes()),
        ];
    }

    /** ¿Está en blanco (apagado, sin sondas ni exclusiones)? Un nodo así nunca debe pisar la configuración de otro. */
    public static function isConfigBlank(?array $cfg = null): bool
    {
        $cfg ??= self::exportConfig();
        return ($cfg['mode'] ?? 'off') === 'off' && empty($cfg['probes']) && trim((string)($cfg['excluded'] ?? '')) === '' && trim((string)($cfg['extra_hosts'] ?? '')) === '';
    }

    /**
     * Qué ajustes adoptar de otro nodo (nombre de ajuste => valor). Pura. Solo si la configuración de aquí está en
     * blanco y la del otro no: nunca se pisa una configuración que ya existe aquí.
     */
    public static function adoptionPlan(array $local, array $peer): array
    {
        if (!self::isConfigBlank($local) || self::isConfigBlank($peer)) {
            return [];
        }
        $plan = [
            'cf_guard_mode' => in_array($peer['mode'] ?? '', self::MODES, true) ? (string)$peer['mode'] : 'off',
            'cf_guard_probes' => json_encode(array_values(array_filter((array)($peer['probes'] ?? []), static fn($p) => !empty($p['hash']) && preg_match('/^[a-f0-9]{64}$/', (string)$p['hash'])))),
        ];
        foreach (['restore_minutes' => 'cf_guard_restore_minutes', 'excluded' => 'cf_guard_excluded', 'display_tz' => 'cf_guard_display_tz', 'extra_hosts' => 'cf_guard_extra_hosts'] as $k => $setting) {
            if (trim((string)($peer[$k] ?? '')) !== '') {
                $plan[$setting] = (string)$peer[$k];
            }
        }
        return $plan;
    }

    public static function mode(): string
    {
        $m = self::setting('mode', 'off');

        return in_array($m, self::MODES, true) ? $m : 'off';
    }

    public static function restoreMinutes(): int
    {
        return max(10, min(240, (int) self::setting('restore_minutes', '30')));
    }

    /**
     * Lista de nombres de máquina/dominio (uno por línea o separados por comas) limpia: minúsculas, sin puntos finales, solo nombres válidos
     * (letras, números, guiones y puntos; con al menos un punto), sin repetidos. Pura.
     */
    public static function normalizeHostList(string $raw, int $max = 200): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,\s]+/', strtolower($raw)) ?: [] as $h) {
            $h = trim($h, " \t.");
            if ($h !== '' && strlen($h) <= 253 && preg_match('/^(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h)) {
                $out[$h] = true;
            }
        }
        return array_slice(array_keys($out), 0, $max);
    }

    /**
     * Nombres EXTRA que esta guarda debe vigilar aunque no sean hostings, alias ni subdominios de este panel: p. ej. el dominio de un cliente
     * que una aplicación (un tenant) sirve por Caddy y que tiene un CNAME con proxy en una zona de las cuentas de este panel.
     */
    public static function extraHosts(): array
    {
        return self::normalizeHostList(self::setting('extra_hosts', ''));
    }

    /**
     * Si manda este panel, envía ya su configuración de relevo (incluida la de esta guarda) a las réplicas, sin esperar a la repetición
     * de cada 30 minutos del cron del clúster. Mejor esfuerzo: si falla, la repetición periódica lo envía.
     */
    public static function pushToPeers(): void
    {
        try {
            if (Settings::get('cluster_role', '') === 'master' && Settings::get('cluster_fenced', '0') !== '1') {
                FailoverService::pushConfigToSlaves();
            }
        } catch (\Throwable) {
        }
    }

    public static function addExtraHost(string $name): bool
    {
        $n = self::normalizeHostList($name);
        if (count($n) !== 1) {
            return false;
        }
        self::setSetting('extra_hosts', implode("\n", array_values(array_unique(array_merge(self::extraHosts(), $n)))));
        return true;
    }

    public static function removeExtraHost(string $name): void
    {
        $n = self::normalizeHostList($name);
        self::setSetting('extra_hosts', implode("\n", array_values(array_diff(self::extraHosts(), $n))));
    }

    public static function excluded(): array
    {
        return array_values(array_filter(array_map(fn ($l) => strtolower(trim($l, " \t.")), preg_split('/[\r\n,]+/', self::setting('excluded', '')))));
    }

    public static function isExcluded(string $name): bool
    {
        foreach (array_merge(self::excluded(), self::cmsManaged()) as $ex) {
            if ($name === $ex || str_ends_with($name, '.' . $ex)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Puede actuar este panel? Solo el que manda (no un slave, no aislado). */
    public static function isActive(): bool
    {
        $role = Settings::get('cluster_role', '') ?: (string) Env::get('PANEL_ROLE', 'standalone');

        return $role !== 'slave' && Settings::get('cluster_fenced', '0') !== '1';
    }

    public static function now(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public static function displayTimezone(): string
    {
        $tz = self::setting('display_tz', 'Europe/Madrid');

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Europe/Madrid';
    }

    /** «17/10 16:27 (14:27 UTC)» a partir de una fecha UTC. */
    public static function fmt(?string $utc): string
    {
        if (!$utc) {
            return '—';
        }
        $d = new \DateTime($utc, new \DateTimeZone('UTC'));
        $u = $d->format('H:i');
        $d->setTimezone(new \DateTimeZone(self::displayTimezone()));

        return $d->format('d/m H:i') . ' (' . $u . ' UTC)';
    }

    public static function event(string $level, string $message): void
    {
        Database::execute('INSERT INTO cf_guard_events (level, message, created_at) VALUES (:l, :m, :t)', ['l' => $level, 'm' => $message, 't' => self::now()]);
    }

    // ------------------------------------------------------------------ sondas (tokens)

    public static function probes(): array
    {
        $list = json_decode(self::setting('probes', '[]'), true);

        return is_array($list) ? $list : [];
    }

    public static function createProbe(string $name): string
    {
        $name = substr(preg_replace('/[^a-z0-9._-]/', '', strtolower($name)) ?: 'sonda', 0, 40);
        $token = 'egp_' . bin2hex(random_bytes(24));
        $list = array_values(array_filter(self::probes(), fn ($p) => $p['name'] !== $name));
        $list[] = ['name' => $name, 'hash' => hash('sha256', $token), 'created_at' => self::now()];
        self::setSetting('probes', json_encode($list));
        self::event('info', "Sonda «{$name}» creada.");

        return $token;
    }

    public static function revokeProbe(string $name): void
    {
        self::setSetting('probes', json_encode(array_values(array_filter(self::probes(), fn ($p) => $p['name'] !== $name))));
        self::event('info', "Sonda «{$name}» revocada.");
    }

    public static function probeForToken(string $token): ?string
    {
        $hash = hash('sha256', $token);
        foreach (self::probes() as $p) {
            if (hash_equals((string) $p['hash'], $hash)) {
                return (string) $p['name'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ inventario

    /** Dominios de las cuentas que tienen dentro un MuseDock CMS con su propia conmutación (los lleva el CMS). */
    public static function cmsManaged(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (Database::fetchAll("SELECT domain, home_dir FROM hosting_accounts WHERE status <> 'deleted'") as $a) {
            $home = rtrim((string) $a['home_dir'], '/');
            if ($home !== '' && @is_file($home . '/httpdocs/core/Services/EdgeGuard/EdgeGuard.php')) {
                $cache[] = strtolower((string) $a['domain']);
            }
        }

        return $cache;
    }

    /** Nombres que sirve el panel: dominio de cada cuenta, dominios extra, subdominios y alias (con www). */
    public static function panelHostnames(): array
    {
        $hosts = [];
        $add = function (?string $d, bool $www = true) use (&$hosts) {
            $d = strtolower(trim((string) $d, " .\t"));
            if ($d === '' || !str_contains($d, '.')) {
                return;
            }
            $hosts[$d] = true;
            if ($www && !str_starts_with($d, 'www.')) {
                $hosts['www.' . $d] = true;
            }
        };
        foreach (Database::fetchAll("SELECT domain FROM hosting_accounts WHERE status <> 'deleted'") as $r) {
            $add($r['domain']);
        }
        foreach (Database::fetchAll('SELECT domain FROM hosting_domains') as $r) {
            $add($r['domain']);
        }
        foreach (Database::fetchAll("SELECT subdomain FROM hosting_subdomains WHERE COALESCE(status, 'active') <> 'deleted'") as $r) {
            $add($r['subdomain'], false);
        }
        foreach (Database::fetchAll('SELECT domain FROM hosting_domain_aliases') as $r) {
            $add($r['domain']);
        }
        // Nombres extra puestos a mano (p. ej. dominios de tenants de otras aplicaciones): solo el nombre exacto, sin añadir su www.
        foreach (self::extraHosts() as $x) {
            $add($x, false);
        }

        return array_keys($hosts);
    }

    /** @return array{records:int, proxied:int, errors:string[]} */
    public static function refreshInventory(): array
    {
        $hosts = array_flip(self::panelHostnames());
        $seen = [];
        $errors = [];
        $count = 0;
        $proxied = 0;
        foreach (CloudflareService::getConfiguredAccounts() as $acc) {
            $token = (string) ($acc['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $zones = $acc['zones'] ?? [];
            if (!$zones) {
                $resp = CloudflareService::listAllZones($token);
                if (!($resp['ok'] ?? false)) {
                    $errors[] = ($acc['name'] ?? '?') . ': ' . ($resp['error'] ?? 'error');
                    continue;
                }
                $zones = array_map(fn ($z) => ['id' => $z['id'], 'name' => $z['name']], $resp['result'] ?? []);
            }
            foreach ($zones as $zone) {
                $zname = strtolower((string) ($zone['name'] ?? ''));
                $relevant = false;
                foreach ($hosts as $h => $_) {
                    if ($h === $zname || str_ends_with($h, '.' . $zname)) {
                        $relevant = true;
                        break;
                    }
                }
                if (!$relevant || isset($seen['zone:' . $zone['id']])) {
                    continue;
                }
                $seen['zone:' . $zone['id']] = true;
                $resp = CloudflareService::listRecordsAll($token, (string) $zone['id'], []);
                if (!($resp['ok'] ?? false)) {
                    $errors[] = $zname . ': ' . ($resp['error'] ?? 'error');
                    continue;
                }
                foreach ($resp['result'] ?? [] as $r) {
                    $name = strtolower((string) ($r['name'] ?? ''));
                    if (!isset($hosts[$name]) || !in_array($r['type'] ?? '', ['A', 'CNAME'], true)) {
                        continue;
                    }
                    $seen[$r['id']] = true;
                    $count++;
                    $proxied += !empty($r['proxied']) ? 1 : 0;
                    self::upsertRecord((string) ($acc['name'] ?? ''), ['id' => (string) $zone['id'], 'name' => $zname], $r);
                }
            }
        }
        if (!$errors) {
            foreach (Database::fetchAll('SELECT record_id FROM cf_guard_records') as $row) {
                if (!isset($seen[$row['record_id']])) {
                    Database::execute('DELETE FROM cf_guard_records WHERE record_id = :r', ['r' => $row['record_id']]);
                }
            }
        }
        // CNAME a otro nombre de la lista (www → raíz con proxy): el origen es el del registro al que apunta
        $byName = [];
        foreach (self::records() as $rec) {
            $byName[$rec['name']] = $rec;
        }
        foreach ($byName as $rec) {
            if ($rec['type'] !== 'CNAME') {
                continue;
            }
            $cur = $byName[strtolower(rtrim($rec['content'], '.'))] ?? null;
            for ($hop = 0; $cur && $hop < 5 && $cur['type'] === 'CNAME' && isset($byName[strtolower(rtrim($cur['content'], '.'))]); $hop++) {
                $cur = $byName[strtolower(rtrim($cur['content'], '.'))];
            }
            if (($cur['origin_ip'] ?? null) && $cur['origin_ip'] !== $rec['origin_ip']) {
                Database::execute('UPDATE cf_guard_records SET origin_ip = :o WHERE record_id = :r', ['o' => $cur['origin_ip'], 'r' => $rec['record_id']]);
            }
        }
        self::setSetting('inventory_at', (string) time());
        self::setSetting('inventory_errors', $errors ? implode("\n", $errors) : '');

        return ['records' => $count, 'proxied' => $proxied, 'errors' => $errors];
    }

    private static function upsertRecord(string $account, array $zone, array $r): void
    {
        $prev = Database::fetchOne('SELECT edge_ips, edge_ips_probe FROM cf_guard_records WHERE record_id = :r', ['r' => $r['id']]) ?? [];
        $name = strtolower((string) $r['name']);
        $isProxied = !empty($r['proxied']);
        $edge = $isProxied ? (implode(',', self::resolveA($name)) ?: (string) ($prev['edge_ips'] ?? '')) : (string) ($prev['edge_ips'] ?? '');
        $origin = $r['type'] === 'A' ? (string) $r['content'] : (self::resolveA((string) $r['content'])[0] ?? null);
        Database::execute('DELETE FROM cf_guard_records WHERE record_id = :r', ['r' => $r['id']]);
        Database::execute('INSERT INTO cf_guard_records (account_name, zone_id, zone_name, record_id, name, type, content, proxied, ttl, edge_ips, edge_ips_probe, origin_ip, refreshed_at)
            VALUES (:a, :zi, :zn, :ri, :n, :t, :c, :p, :ttl, :e, :ep, :o, :at)', [
            'a' => $account, 'zi' => $zone['id'], 'zn' => $zone['name'], 'ri' => $r['id'], 'n' => $name, 't' => $r['type'], 'c' => (string) $r['content'],
            'p' => $isProxied ? 1 : 0, 'ttl' => (int) ($r['ttl'] ?? 1), 'e' => $edge ?: null, 'ep' => ($prev['edge_ips_probe'] ?? null) ?: null, 'o' => $origin, 'at' => self::now(),
        ]);
    }

    private static function mergeIps(?string ...$lists): string
    {
        $all = [];
        foreach ($lists as $l) {
            foreach (array_filter(explode(',', (string) $l)) as $ip) {
                $all[$ip] = true;
            }
        }

        return implode(',', array_keys($all));
    }

    private static function resolveA(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips ? array_values(array_unique($ips)) : [];
    }

    /** Registros; edge_ips incluye las IPs vistas por las sondas desde su país. */
    public static function records(): array
    {
        $rows = Database::fetchAll('SELECT * FROM cf_guard_records ORDER BY name');
        foreach ($rows as &$r) {
            $r['edge_ips'] = self::mergeIps($r['edge_ips'] ?? '', $r['edge_ips_probe'] ?? '') ?: null;
        }

        return $rows;
    }

    public static function activeChanges(): array
    {
        return Database::fetchAll("SELECT * FROM cf_guard_changes WHERE status = 'active' ORDER BY changed_at");
    }

    public static function history(int $limit = 60): array
    {
        return Database::fetchAll('SELECT * FROM cf_guard_changes ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    public static function events(int $limit = 40): array
    {
        return Database::fetchAll('SELECT * FROM cf_guard_events ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    public static function clearLog(): void
    {
        Database::execute('DELETE FROM cf_guard_events');
        Database::execute("DELETE FROM cf_guard_changes WHERE status <> 'active'");
    }

    // ------------------------------------------------------------------ API de sondas

    public static function targets(): array
    {
        $targets = [];
        $suspect = self::suspectEdgeIps();
        foreach (self::records() as $r) {
            if (!(int) $r['proxied'] || self::isExcluded($r['name'])) {
                continue;
            }
            foreach (array_filter(explode(',', (string) $r['edge_ips'])) as $ip) {
                $targets['cf:' . $ip] ??= ['id' => 'cf:' . $ip, 'ip' => $ip, 'host' => $r['name']];
                if (isset($suspect[$ip]) && $r['origin_ip']) {
                    $targets['origin:' . $r['record_id']] = ['id' => 'origin:' . $r['record_id'], 'ip' => $r['origin_ip'], 'host' => $r['name']];
                }
            }
            $targets['dns:' . $r['record_id']] = ['id' => 'dns:' . $r['record_id'], 'ip' => '-', 'host' => $r['name']];
        }
        foreach (self::activeChanges() as $c) {
            foreach (array_filter(explode(',', (string) $c['edge_ips'])) as $ip) {
                $targets['cf:' . $ip] ??= ['id' => 'cf:' . $ip, 'ip' => $ip, 'host' => $c['name']];
            }
        }

        return array_values($targets);
    }

    public static function ingest(string $probe, array $paths, array $dns = []): int
    {
        $known = array_flip(array_column(self::targets(), 'id'));
        $now = self::now();
        foreach (array_slice($dns, 0, 1000) as $d) {
            $t = is_array($d) ? (string) ($d['t'] ?? '') : '';
            if (!isset($known[$t]) || !str_starts_with($t, 'dns:')) {
                continue;
            }
            $ips = array_values(array_unique(array_filter((array) ($d['ips'] ?? []), fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))));
            $ips = array_slice($ips, 0, 8);
            if ($ips) {
                sort($ips);
                Database::execute('UPDATE cf_guard_records SET edge_ips_probe = :e WHERE record_id = :r AND proxied = 1 AND (origin_ip IS NULL OR origin_ip <> :o)',
                    ['e' => implode(',', $ips), 'r' => substr($t, 4), 'o' => $ips[0]]);
            }
        }
        $n = 0;
        $ins = Database::connect()->prepare('INSERT INTO cf_guard_observations (probe, path, target, ok, code, ms, public_ip, created_at) VALUES (?,?,?,?,?,?,?,?)');
        foreach (array_slice($paths, 0, 6) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $path = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($p['path'] ?? '')) ?: 'default', 0, 40);
            $pub = substr(preg_replace('/[^0-9a-fA-F.:]/', '', (string) ($p['public_ip'] ?? '')), 0, 64) ?: null;
            $ins->execute([$probe, $path, 'control', !empty($p['control_ok']) ? 1 : 0, null, null, $pub, $now]);
            $n++;
            foreach (array_slice((array) ($p['results'] ?? []), 0, 500) as $res) {
                $t = is_array($res) ? (string) ($res['t'] ?? '') : '';
                if (!isset($known[$t])) {
                    continue;
                }
                $ins->execute([$probe, $path, $t, !empty($res['ok']) ? 1 : 0, substr((string) ($res['code'] ?? ''), 0, 10), isset($res['ms']) ? (int) $res['ms'] : null, $pub, $now]);
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ estado

    public static function livePaths(): array
    {
        $rows = Database::fetchAll("SELECT probe, path, MAX(created_at) AS last FROM cf_guard_observations WHERE target = 'control' AND ok = 1 AND created_at >= :s GROUP BY probe, path ORDER BY probe, path", ['s' => self::now(-self::PROBE_STALE)]);
        $out = [];
        foreach ($rows as $r) {
            $ip = Database::fetchOne("SELECT public_ip FROM cf_guard_observations WHERE probe = :p AND path = :pa AND target = 'control' ORDER BY id DESC LIMIT 1", ['p' => $r['probe'], 'pa' => $r['path']]);
            $out[$r['probe'] . '/' . $r['path']] = ['probe' => $r['probe'], 'path' => $r['path'], 'public_ip' => $ip['public_ip'] ?? null, 'last' => (string) $r['last']];
        }

        return $out;
    }

    public static function probePaths(): array
    {
        return Database::fetchAll("SELECT probe, path, MAX(created_at) AS last, MAX(public_ip) AS public_ip FROM cf_guard_observations WHERE target = 'control' GROUP BY probe, path ORDER BY probe, path");
    }

    private static function observations(string $target, string $since): array
    {
        $by = [];
        foreach (Database::fetchAll('SELECT probe, path, ok, created_at FROM cf_guard_observations WHERE target = :t AND created_at >= :s ORDER BY id DESC', ['t' => $target, 's' => $since]) as $o) {
            $by[$o['probe'] . '/' . $o['path']][] = $o;
        }

        return $by;
    }

    private static function suspectEdgeIps(): array
    {
        $out = [];
        foreach (Database::fetchAll("SELECT DISTINCT target FROM cf_guard_observations WHERE target LIKE 'cf:%' AND ok = 0 AND created_at >= :s", ['s' => self::now(-self::FAIL_WINDOW)]) as $r) {
            $out[substr($r['target'], 3)] = true;
        }

        return $out;
    }

    /** @return array<string, array{state:string, paths:array<string, ?bool>, had_fail:bool}> */
    public static function edgeStates(): array
    {
        $live = self::livePaths();
        $ips = [];
        foreach (self::records() as $r) {
            foreach (array_filter(explode(',', (string) $r['edge_ips'])) as $ip) {
                $ips[$ip] = true;
            }
        }
        foreach (self::activeChanges() as $c) {
            foreach (array_filter(explode(',', (string) $c['edge_ips'])) as $ip) {
                $ips[$ip] = true;
            }
        }
        $restoreSecs = self::restoreMinutes() * 60;
        $out = [];
        foreach (array_keys($ips) as $ip) {
            $recent = self::observations('cf:' . $ip, self::now(-self::FAIL_WINDOW));
            $long = self::observations('cf:' . $ip, self::now(-$restoreSecs));
            $paths = [];
            $blocked = false;
            $allClear = (bool) $live;
            $hadFail = false;
            foreach ($long as $obsList) {
                if (array_filter($obsList, fn ($o) => (int) $o['ok'] === 0)) {
                    $hadFail = true;
                }
            }
            foreach ($live as $key => $_) {
                $obs = $recent[$key] ?? [];
                $paths[$key] = $obs ? (int) $obs[0]['ok'] === 1 : null;
                $lastN = array_slice($obs, 0, self::FAILS_TO_BLOCK);
                if (count($lastN) >= self::FAILS_TO_BLOCK && !array_filter($lastN, fn ($o) => (int) $o['ok'] === 1)) {
                    $blocked = true;
                }
                $window = $long[$key] ?? [];
                $oldest = $window ? end($window)['created_at'] : null;
                $covered = $oldest !== null && strtotime($oldest . ' UTC') <= time() - $restoreSecs + 180;
                if (!$window || array_filter($window, fn ($o) => (int) $o['ok'] === 0) || !$covered) {
                    $allClear = false;
                }
            }
            $state = !$live ? 'unknown' : ($blocked ? 'blocked' : ($allClear ? 'clear' : 'recovering'));
            $out[$ip] = ['state' => $state, 'paths' => $paths, 'had_fail' => $hadFail];
        }
        ksort($out);

        return $out;
    }

    private static function originOk(string $recordId): ?bool
    {
        $rows = Database::fetchAll('SELECT ok FROM cf_guard_observations WHERE target = :t AND created_at >= :s ORDER BY id DESC LIMIT 6', ['t' => 'origin:' . $recordId, 's' => self::now(-self::ORIGIN_FRESH)]);
        if (!$rows) {
            return null;
        }

        return in_array(1, array_map(fn ($r) => (int) $r['ok'], $rows), true);
    }

    /**
     * Desde cuándo falla una IP sin interrupción, por operadora: para cada camino, el primer fallo tras su última
     * respuesta buena; se toma el más antiguo. (Con una operadora ya respondiendo y otra no, mirar todas juntas
     * daría la hora de la última respuesta de la que funciona.) atLeast: el camino ya fallaba al empezar a medir.
     * @return array{since:?string, atLeast:bool}
     */
    public static function blockedSinceInfo(string $ip): array
    {
        $since = null;
        $atLeast = false;
        foreach (Database::fetchAll('SELECT DISTINCT path FROM cf_guard_observations WHERE target = :t', ['t' => 'cf:' . $ip]) as $p) {
            $lastOk = Database::fetchOne('SELECT MAX(created_at) AS t FROM cf_guard_observations WHERE target = :t AND path = :p AND ok = 1', ['t' => 'cf:' . $ip, 'p' => $p['path']])['t'] ?? null;
            $first = Database::fetchOne('SELECT MIN(created_at) AS t FROM cf_guard_observations WHERE target = :t AND path = :p AND ok = 0 AND created_at > :s', ['t' => 'cf:' . $ip, 'p' => $p['path'], 's' => $lastOk ?: '1970-01-01 00:00:00'])['t'] ?? null;
            $last = Database::fetchOne('SELECT ok FROM cf_guard_observations WHERE target = :t AND path = :p ORDER BY id DESC LIMIT 1', ['t' => 'cf:' . $ip, 'p' => $p['path']]);
            if (!$first || (int) ($last['ok'] ?? 1) === 1) {
                continue; // este camino no está fallando ahora
            }
            if ($since === null || $first < $since) {
                $since = $first;
                $atLeast = $lastOk === null;
            }
        }

        return ['since' => $since, 'atLeast' => $atLeast];
    }

    public static function blockedSince(string $ip): ?string
    {
        return self::blockedSinceInfo($ip)['since'];
    }

    public static function openEpisodes(): array
    {
        return json_decode(self::setting('blocked_ips', '{}'), true) ?: [];
    }

    private static function trackEpisodes(array $states, array &$report): void
    {
        $open = self::openEpisodes();
        $changed = false;
        foreach ($states as $ip => $s) {
            if ($s['state'] === 'blocked' && !isset($open[$ip])) {
                $info = self::blockedSinceInfo($ip);
                $since = $info['since'] ?: self::now();
                $open[$ip] = $since;
                $changed = true;
                $msg = "IP {$ip} bloqueada desde " . ($info['atLeast'] ? 'al menos ' : '') . 'las ' . self::fmt($since) . '.';
                self::event('warn', $msg);
                $report['notes'][] = $msg;
            } elseif (isset($open[$ip]) && in_array($s['state'], ['clear', 'recovering'], true) && $s['paths'] && !in_array(false, $s['paths'], true) && !in_array(null, $s['paths'], true)) {
                $end = Database::fetchOne('SELECT MIN(created_at) AS t FROM cf_guard_observations WHERE target = :t AND ok = 1 AND created_at > :s', ['t' => 'cf:' . $ip, 's' => $open[$ip]])['t'] ?? self::now();
                $mins = (int) round((strtotime($end . ' UTC') - strtotime($open[$ip] . ' UTC')) / 60);
                $dur = $mins >= 60 ? intdiv($mins, 60) . ' h ' . ($mins % 60) . ' min' : $mins . ' min';
                $msg = "IP {$ip} vuelve a responder a las " . self::fmt($end) . '. Bloqueada desde las ' . self::fmt($open[$ip]) . " ({$dur}).";
                self::event('info', $msg);
                $report['notes'][] = $msg;
                unset($open[$ip]);
                $changed = true;
            }
        }
        if ($changed) {
            self::setSetting('blocked_ips', $open ? json_encode($open) : '');
        }
    }

    // ------------------------------------------------------------------ ciclo

    /** @return array{applied:string[], restored:string[], notes:string[]} */
    public static function run(bool $forceInventory = false): array
    {
        $report = ['applied' => [], 'restored' => [], 'notes' => []];
        $mode = self::mode();
        if (!$forceInventory && $mode === 'off' && !self::activeChanges() && !self::probes()) {
            return $report; // sin configurar: no se lee Cloudflare
        }
        if ($forceInventory || time() - (int) self::setting('inventory_at', '0') >= self::INVENTORY_EVERY) {
            $inv = self::refreshInventory();
            if ($inv['errors']) {
                self::noteOnce('inventory', 'Lectura de Cloudflare con errores: ' . implode('; ', $inv['errors']), $report);
            }
        }
        Database::execute('DELETE FROM cf_guard_observations WHERE created_at < :t', ['t' => self::now(-3 * 86400)]);
        Database::execute('DELETE FROM cf_guard_events WHERE created_at < :t', ['t' => self::now(-30 * 86400)]);
        if ($mode !== 'simulate') {
            Database::execute("UPDATE cf_guard_changes SET status = 'sim_closed', restored_at = :t WHERE status = 'active' AND simulated = 1", ['t' => self::now()]);
        }
        if (!self::livePaths()) {
            if ((self::activeChanges() || $mode !== 'off') && self::setting('stale_noted') !== '1') {
                self::setSetting('stale_noted', '1');
                $msg = 'Ninguna sonda ha informado en los últimos ' . (self::PROBE_STALE / 60) . ' minutos: no se cambia nada hasta que vuelvan.';
                self::event('warn', $msg);
                $report['notes'][] = $msg;
            }

            return $report;
        }
        self::setSetting('stale_noted', '');
        $states = self::edgeStates();
        self::trackEpisodes($states, $report);

        foreach (self::activeChanges() as $c) {
            $ips = array_filter(explode(',', (string) $c['edge_ips']));
            if ($ips && !array_filter($ips, fn ($ip) => ($states[$ip]['state'] ?? 'unknown') !== 'clear')) {
                $report['restored'][] = self::restore($c);
            }
        }
        if ($mode === 'off') {
            return $report;
        }
        $active = array_flip(array_column(self::activeChanges(), 'record_id'));
        foreach (self::records() as $r) {
            if (!(int) $r['proxied'] || isset($active[$r['record_id']]) || self::isExcluded($r['name'])) {
                continue;
            }
            $blockedIps = array_values(array_filter(array_filter(explode(',', (string) $r['edge_ips'])), fn ($ip) => ($states[$ip]['state'] ?? '') === 'blocked'));
            if (!$blockedIps) {
                continue;
            }
            $origin = self::originOk($r['record_id']);
            if ($origin === null) {
                continue;
            }
            if ($origin === false) {
                self::noteOnce('origin:' . $r['record_id'], "{$r['name']}: Cloudflare bloqueado, pero el servidor de origen no responde directo con un certificado válido; se deja como está.", $report);
                continue;
            }
            $report['applied'][] = self::unproxy($r, $blockedIps, $mode === 'simulate');
        }

        return $report;
    }

    private static function noteOnce(string $key, string $msg, array &$report): void
    {
        $k = 'note_' . md5($key);
        if ((int) self::setting($k, '0') > time() - 3600) {
            return;
        }
        self::setSetting($k, (string) time());
        self::event('warn', $msg);
        $report['notes'][] = $msg;
    }

    private static function cause(array $ips, string $host): string
    {
        foreach ($ips as $ip) {
            $ch = curl_init('https://' . $host . '/');
            curl_setopt_array($ch, [CURLOPT_RESOLVE => [$host . ':443:' . $ip], CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code > 0) {
                return 'bloqueo';
            }
        }

        return 'cloudflare';
    }

    private static function token(string $account): string
    {
        foreach (CloudflareService::getConfiguredAccounts() as $acc) {
            if ((string) ($acc['name'] ?? '') === $account && !empty($acc['token'])) {
                return (string) $acc['token'];
            }
        }
        throw new \RuntimeException('la cuenta de Cloudflare «' . $account . '» ya no está configurada');
    }

    private static function getRecord(string $token, string $zoneId, string $recordId): array
    {
        $resp = CloudflareService::apiRequest($token, 'GET', "/zones/{$zoneId}/dns_records/{$recordId}");
        if (!($resp['ok'] ?? false)) {
            throw new \RuntimeException($resp['error'] ?? 'no se pudo leer el registro');
        }
        $r = $resp['result'] ?? [];

        return ['type' => (string) ($r['type'] ?? ''), 'content' => (string) ($r['content'] ?? ''), 'proxied' => !empty($r['proxied'])];
    }

    private static function setProxied(string $token, string $zoneId, string $recordId, bool $proxied, int $ttl): void
    {
        $resp = CloudflareService::updateRecord($token, $zoneId, $recordId, ['proxied' => $proxied, 'ttl' => ($proxied || $ttl === 1) ? 1 : max(60, $ttl)]);
        if (!($resp['ok'] ?? false)) {
            throw new \RuntimeException($resp['error'] ?? 'Cloudflare rechazó el cambio');
        }
    }

    private static function unproxy(array $r, array $blockedIps, bool $simulated): string
    {
        $cause = self::cause($blockedIps, $r['name']);
        $why = $cause === 'bloqueo' ? 'IP de Cloudflare bloqueada por operadoras; desde fuera responde' : 'Cloudflare no responde, tampoco desde fuera';
        $status = 'active';
        $note = null;
        if (!$simulated) {
            try {
                $token = self::token($r['account_name']);
                $now = self::getRecord($token, $r['zone_id'], $r['record_id']);
                if (!$now['proxied'] || $now['content'] !== $r['content']) {
                    Database::execute('UPDATE cf_guard_records SET proxied = :p, content = :c WHERE record_id = :r', ['p' => $now['proxied'] ? 1 : 0, 'c' => $now['content'], 'r' => $r['record_id']]);

                    return "{$r['name']}: cambió en Cloudflare desde la última lectura; no se toca.";
                }
                self::setProxied($token, $r['zone_id'], $r['record_id'], false, 60);
                Database::execute('UPDATE cf_guard_records SET proxied = 0 WHERE record_id = :r', ['r' => $r['record_id']]);
            } catch (\Throwable $e) {
                $status = 'failed';
                $note = $e->getMessage();
            }
        }
        Database::execute('INSERT INTO cf_guard_changes (account_name, zone_id, zone_name, record_id, name, type, content, original_ttl, edge_ips, simulated, cause, status, changed_at, note)
            VALUES (:a, :zi, :zn, :ri, :n, :t, :c, :ttl, :e, :s, :cause, :st, :at, :note)', [
            'a' => $r['account_name'], 'zi' => $r['zone_id'], 'zn' => $r['zone_name'], 'ri' => $r['record_id'], 'n' => $r['name'], 't' => $r['type'], 'c' => $r['content'],
            'ttl' => (int) $r['ttl'], 'e' => (string) $r['edge_ips'], 's' => $simulated ? 1 : 0, 'cause' => $cause, 'st' => $status, 'at' => self::now(), 'note' => $note,
        ]);
        $ipList = implode(', ', $blockedIps);
        $msg = match (true) {
            $status === 'failed' => "{$r['name']}: no se pudo quitar el proxy ({$note}).",
            $simulated => "{$r['name']}: se habría quitado el proxy ({$why}; IPs {$ipList}).",
            default => "{$r['name']}: proxy quitado ({$why}; IPs {$ipList}).",
        };
        self::event($status === 'failed' ? 'error' : 'info', $msg);

        return $msg;
    }

    private static function restore(array $c): string
    {
        $mins = self::restoreMinutes();
        if ((int) $c['simulated'] === 1) {
            Database::execute("UPDATE cf_guard_changes SET status = 'restored', restored_at = :t WHERE id = :id", ['t' => self::now(), 'id' => $c['id']]);
            $msg = "{$c['name']}: se habría vuelto a poner el proxy (sus IPs llevan {$mins} min respondiendo).";
            self::event('info', $msg);

            return $msg;
        }
        try {
            $token = self::token($c['account_name']);
            $now = self::getRecord($token, $c['zone_id'], $c['record_id']);
            if ($now['proxied'] || $now['content'] !== $c['content'] || $now['type'] !== $c['type']) {
                Database::execute("UPDATE cf_guard_changes SET status = 'kept_manual', restored_at = :t, note = :n WHERE id = :id",
                    ['t' => self::now(), 'n' => 'El registro se cambió a mano mientras tanto; se respeta.', 'id' => $c['id']]);
                $msg = "{$c['name']}: alguien lo cambió durante el bloqueo; se respeta su cambio y no se toca.";
                self::event('warn', $msg);

                return $msg;
            }
            self::setProxied($token, $c['zone_id'], $c['record_id'], true, (int) $c['original_ttl']);
            Database::execute('UPDATE cf_guard_records SET proxied = 1 WHERE record_id = :r', ['r' => $c['record_id']]);
            Database::execute("UPDATE cf_guard_changes SET status = 'restored', restored_at = :t WHERE id = :id", ['t' => self::now(), 'id' => $c['id']]);
            $msg = "{$c['name']}: proxy puesto de nuevo (sus IPs llevan {$mins} min respondiendo).";
            self::event('info', $msg);

            return $msg;
        } catch (\Throwable $e) {
            $msg = "{$c['name']}: no se pudo volver a poner el proxy ({$e->getMessage()}); se reintentará.";
            self::event('error', $msg);

            return $msg;
        }
    }

    // ------------------------------------------------------------------ cambio manual de la configuración original

    public static function zoneUnhealthyIps(string $zoneId, ?array $states = null): array
    {
        $states ??= self::edgeStates();
        $ips = [];
        foreach (Database::fetchAll('SELECT edge_ips, edge_ips_probe FROM cf_guard_records WHERE zone_id = :z AND proxied = 1', ['z' => $zoneId]) as $row) {
            foreach (array_filter(explode(',', self::mergeIps($row['edge_ips'], $row['edge_ips_probe']))) as $ip) {
                $ips[$ip] = true;
            }
        }
        foreach (Database::fetchAll("SELECT edge_ips FROM cf_guard_changes WHERE zone_id = :z AND status = 'active' AND simulated = 0", ['z' => $zoneId]) as $row) {
            foreach (array_filter(explode(',', (string) $row['edge_ips'])) as $ip) {
                $ips[$ip] = true;
            }
        }
        $bad = [];
        foreach (array_keys($ips) as $ip) {
            $s = $states[$ip] ?? null;
            if ($s && ($s['state'] === 'blocked' || ($s['state'] !== 'clear' && !empty($s['had_fail'])))) {
                $bad[] = $ip;
            }
        }
        sort($bad);

        return $bad;
    }

    public static function setOriginal(string $recordId, bool $wantProxied, string $who): string
    {
        $r = Database::fetchOne('SELECT * FROM cf_guard_records WHERE record_id = :r', ['r' => $recordId]);
        if (!$r) {
            throw new \RuntimeException('Ese registro ya no está en el inventario; pulsa «Leer Cloudflare».');
        }
        $active = Database::fetchOne("SELECT * FROM cf_guard_changes WHERE record_id = :r AND status = 'active' AND simulated = 0 ORDER BY id DESC LIMIT 1", ['r' => $recordId]);
        $name = $r['name'];
        if ($wantProxied) {
            if ($active || (int) $r['proxied']) {
                return "{$name} ya tiene «con proxy» como configuración original; no hay nada que cambiar.";
            }
            $bad = self::isExcluded($name) ? [] : self::zoneUnhealthyIps($r['zone_id']);
            if ($bad) {
                Database::execute("INSERT INTO cf_guard_changes (account_name, zone_id, zone_name, record_id, name, type, content, original_ttl, edge_ips, simulated, cause, status, changed_at, note)
                    VALUES (:a, :zi, :zn, :ri, :n, :t, :c, 1, :e, 0, 'manual', 'active', :at, :note)", [
                    'a' => $r['account_name'], 'zi' => $r['zone_id'], 'zn' => $r['zone_name'], 'ri' => $r['record_id'], 'n' => $name, 't' => $r['type'], 'c' => $r['content'],
                    'e' => implode(',', $bad), 'at' => self::now(), 'note' => "Cambio manual de {$who}: pendiente de poner el proxy al acabar el bloqueo.",
                ]);
                $msg = "{$name}: configuración original cambiada a «con proxy» por {$who}. Su zona tiene IPs bloqueadas (" . implode(', ', $bad) . '), así que sigue directo y se le pondrá el proxy cuando acabe el bloqueo.';
                self::event('info', $msg);

                return $msg;
            }
            self::setProxied(self::token($r['account_name']), $r['zone_id'], $r['record_id'], true, 1);
            Database::execute('UPDATE cf_guard_records SET proxied = 1 WHERE record_id = :r', ['r' => $recordId]);
            self::setSetting('inventory_at', '0');
            $msg = "{$name}: configuración original cambiada a «con proxy» por {$who}; proxy puesto en Cloudflare.";
            self::event('info', $msg);

            return $msg;
        }
        if ($active) {
            Database::execute("UPDATE cf_guard_changes SET status = 'cancelled_user', restored_at = :t, note = :n WHERE id = :id",
                ['t' => self::now(), 'n' => "Cambiado a «sin proxy» a mano por {$who}: no se volverá a poner el proxy.", 'id' => $active['id']]);
            $msg = "{$name}: configuración original cambiada a «sin proxy» por {$who}. Ya estaba directo por el bloqueo; al acabar no se le volverá a poner el proxy.";
            self::event('info', $msg);

            return $msg;
        }
        if (!(int) $r['proxied']) {
            return "{$name} ya tiene «sin proxy» como configuración original; no hay nada que cambiar.";
        }
        self::setProxied(self::token($r['account_name']), $r['zone_id'], $r['record_id'], false, 1);
        Database::execute('UPDATE cf_guard_records SET proxied = 0 WHERE record_id = :r', ['r' => $recordId]);
        $msg = "{$name}: configuración original cambiada a «sin proxy» por {$who}; proxy quitado en Cloudflare.";
        self::event('info', $msg);

        return $msg;
    }
}
