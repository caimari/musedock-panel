<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Protección de DNS: aunque el token de Cloudflare de este panel llegue a todas las zonas,
 * cada panel solo debe tocar el DNS de lo suyo. Todas las escrituras a /zones/{id}/dns_records
 * (relevo, cambio de rol, MCP, correo, pantalla de DNS, migraciones) pasan por
 * CloudflareService::apiRequest, que pregunta aquí antes de enviarlas.
 *
 * Reglas (todas opcionales; sin nada configurado no se bloquea nada):
 *  - zones_deny:  zonas en las que este panel NUNCA escribe.
 *  - zones_allow: si no está vacía, SOLO se escribe en estas zonas.
 *  - rules:       registros protegidos: nombre (con * como comodín), tipos y acciones
 *                 (create / update / delete); vacío = todos.
 * Modos: off (no hace nada), audit (deja pasar pero apunta lo que habría bloqueado),
 * enforce (bloquea). Los patrones usan * como comodín (example.*, mail.*, *).
 *
 * La configuración viaja del master a sus slaves (FailoverService::getSyncableConfigKeys):
 * si un slave toma el mando, hereda las mismas reglas.
 */
final class DnsGuardService
{
    public const SETTING = 'dns_guard';
    public const LOG_SETTING = 'dns_guard_log';
    public const MODES = ['off', 'audit', 'enforce'];
    public const ACTIONS = ['create', 'update', 'delete'];
    public const TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'SRV', 'CAA', 'PTR'];
    private const LOG_MAX = 40;
    private const SPECIAL_IDS = ['batch', 'import', 'scan'];

    /** @var array<string,string>|null zoneId → nombre de zona */
    private static ?array $zoneNames = null;
    /** @var array<string,array|null> */
    private static array $recordCache = [];

    // ── Configuración ────────────────────────────────────────────────────

    public static function config(): array
    {
        $raw = json_decode((string)Settings::get(self::SETTING, ''), true);
        return self::normalize($raw);
    }

    /** Deja la configuración en su forma válida (descarta lo que no entiende). */
    public static function normalize($raw): array
    {
        $out = ['mode' => 'off', 'zones_allow' => [], 'zones_deny' => [], 'rules' => []];
        if (!is_array($raw)) {
            return $out;
        }
        $mode = strtolower((string)($raw['mode'] ?? 'off'));
        $out['mode'] = in_array($mode, self::MODES, true) ? $mode : 'off';
        $out['zones_allow'] = self::cleanPatterns($raw['zones_allow'] ?? []);
        $out['zones_deny'] = self::cleanPatterns($raw['zones_deny'] ?? []);
        foreach ((array)($raw['rules'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = self::cleanPattern((string)($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $types = array_values(array_intersect(self::TYPES, array_map(static fn($t) => strtoupper(trim((string)$t)), (array)($r['types'] ?? []))));
            $actions = array_values(array_intersect(self::ACTIONS, array_map(static fn($a) => strtolower(trim((string)$a)), (array)($r['actions'] ?? []))));
            $out['rules'][] = [
                'name'    => $name,
                'types'   => $types,
                'actions' => $actions,
                'note'    => mb_substr(trim(strip_tags((string)($r['note'] ?? ''))), 0, 120),
            ];
            if (count($out['rules']) >= 200) {
                break;
            }
        }
        return $out;
    }

    private static function cleanPattern(string $p): string
    {
        $p = strtolower(rtrim(trim($p), '.'));
        return preg_match('/^[a-z0-9*._-]{1,253}$/', $p) ? $p : '';
    }

    /** @param array|string $v lista, o texto separado por saltos de línea, comas o espacios */
    private static function cleanPatterns($v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[\s,;]+/', $v, -1, PREG_SPLIT_NO_EMPTY);
        }
        $out = [];
        foreach ((array)$v as $p) {
            $c = self::cleanPattern((string)$p);
            if ($c !== '') {
                $out[$c] = true;
            }
        }
        return array_slice(array_keys($out), 0, 500);
    }

    public static function save(array $cfg): void
    {
        Settings::set(self::SETTING, json_encode(self::normalize($cfg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Reglas de registros ⇄ texto del formulario, una por línea:
     *   patrón | tipos | acciones | nota        (tipos y acciones separados por comas; vacío = todos)
     * Acciones: crear/create, cambiar/update, borrar/delete.
     */
    public static function rulesToText(array $rules): string
    {
        $lines = [];
        foreach ($rules as $r) {
            $lines[] = $r['name'] . ' | ' . implode(',', $r['types']) . ' | ' . implode(',', $r['actions'])
                . ($r['note'] !== '' ? ' | ' . $r['note'] : '');
        }
        return implode("\n", $lines);
    }

    public static function rulesFromText(string $text): array
    {
        $map = ['crear' => 'create', 'alta' => 'create', 'cambiar' => 'update', 'modificar' => 'update', 'editar' => 'update',
                'borrar' => 'delete', 'eliminar' => 'delete', 'quitar' => 'delete'];
        $rules = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $p = array_map('trim', explode('|', $line, 4));
            $actions = [];
            foreach (preg_split('/[\s,;]+/', strtolower($p[2] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
                $actions[] = $map[$a] ?? $a;
            }
            $rules[] = [
                'name'    => $p[0],
                'types'   => preg_split('/[\s,;]+/', $p[1] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [],
                'actions' => $actions,
                'note'    => $p[3] ?? '',
            ];
        }
        return $rules;
    }

    // ── Evaluación (sin red: se puede probar sola) ───────────────────────

    /** ¿Encaja el valor con el patrón (con * como comodín)? */
    public static function matches(string $pattern, string $value): bool
    {
        $re = '#^' . str_replace('\*', '.*', preg_quote(strtolower($pattern), '#')) . '$#';
        return (bool)preg_match($re, strtolower(rtrim($value, '.')));
    }

    /**
     * @param string|null $name registro afectado (null = no se pudo averiguar)
     * @param string|null $type idem
     * @return string|null motivo del bloqueo, o null si se puede escribir
     */
    public static function evaluate(array $cfg, string $zone, ?string $name, ?string $type, string $action): ?string
    {
        if (($cfg['mode'] ?? 'off') === 'off') {
            return null;
        }
        $zone = strtolower(rtrim($zone, '.'));
        foreach ($cfg['zones_deny'] ?? [] as $p) {
            if (self::matches($p, $zone)) {
                return "la zona {$zone} está protegida: este servidor no escribe en ella (zonas prohibidas: {$p})";
            }
        }
        $allow = $cfg['zones_allow'] ?? [];
        if ($allow) {
            $ok = false;
            foreach ($allow as $p) {
                if (self::matches($p, $zone)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return "la zona {$zone} no está entre las que este servidor puede modificar";
            }
        }
        foreach ($cfg['rules'] ?? [] as $r) {
            if ($r['actions'] && !in_array($action, $r['actions'], true)) {
                continue;
            }
            if ($name !== null && !self::matches($r['name'], $name)) {
                continue;
            }
            if ($type !== null && $r['types'] && !in_array(strtoupper($type), $r['types'], true)) {
                continue;
            }
            $what = $name ?? 'un registro de la zona';
            return "el registro {$what}" . ($type ? " ({$type})" : '') . " está protegido (regla «{$r['name']}»"
                . ($r['note'] !== '' ? ': ' . $r['note'] : '') . ')';
        }
        return null;
    }

    // ── Enganche con Cloudflare ──────────────────────────────────────────

    /**
     * Lo llama CloudflareService::apiRequest antes de enviar nada que no sea una lectura.
     * @return array|null null = adelante; si no, la respuesta de error que recibe quien llamó
     */
    public static function blockFor(string $token, string $method, string $endpoint, array $data): ?array
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }
        if (!preg_match('#^/zones/([A-Za-z0-9]+)/dns_records(?:/([A-Za-z0-9_-]+))?(?:/.*)?$#', $endpoint, $m)) {
            return null;
        }
        $cfg = self::config();
        if ($cfg['mode'] === 'off' || (!$cfg['zones_allow'] && !$cfg['zones_deny'] && !$cfg['rules'])) {
            return null;
        }
        $zoneId = $m[1];
        $recId = $m[2] ?? '';
        $action = match ($method) {
            'POST' => 'create',
            'DELETE' => 'delete',
            default => 'update',
        };

        $zone = self::zoneName($token, $zoneId);
        if ($zone === null) {
            return self::decide($cfg, $zoneId, null, null, $action, 'no se pudo comprobar a qué zona pertenece la operación');
        }

        // Operaciones masivas: no se ve qué registros tocan; con reglas de registros no se permiten.
        if (in_array($recId, self::SPECIAL_IDS, true)) {
            $reason = self::evaluate($cfg, $zone, null, null, $action)
                ?? ($cfg['rules'] ? 'las operaciones masivas de DNS no se permiten mientras haya registros protegidos' : null);
            return $reason === null ? null : self::decide($cfg, $zone, null, null, $action, $reason);
        }

        // Registro tal como está hoy (para update/delete) y tal como quedaría (datos enviados).
        $candidates = [];
        if ($recId !== '' && $cfg['rules']) {
            $cur = self::currentRecord($token, $zoneId, $recId);
            if ($cur === null) {
                return self::decide($cfg, $zone, null, null, $action, 'no se pudo leer el registro afectado y hay registros protegidos');
            }
            $candidates[] = [strtolower((string)($cur['name'] ?? '')), strtoupper((string)($cur['type'] ?? ''))];
        }
        if (isset($data['name']) || isset($data['type'])) {
            $candidates[] = [
                isset($data['name']) ? strtolower((string)$data['name']) : ($candidates[0][0] ?? null),
                isset($data['type']) ? strtoupper((string)$data['type']) : ($candidates[0][1] ?? null),
            ];
        }
        if (!$candidates) {
            $candidates[] = [null, null];
        }
        foreach ($candidates as [$name, $type]) {
            $reason = self::evaluate($cfg, $zone, $name, $type, $action);
            if ($reason !== null) {
                return self::decide($cfg, $zone, $name, $type, $action, $reason);
            }
        }
        return null;
    }

    /** Apunta el intento; en modo enforce devuelve el error, en audit deja pasar. */
    private static function decide(array $cfg, string $zone, ?string $name, ?string $type, string $action, string $reason): ?array
    {
        $enforce = $cfg['mode'] === 'enforce';
        self::record($zone, $name, $type, $action, $reason, $enforce);
        if (!$enforce) {
            return null;
        }
        return [
            'ok' => false, 'result' => null, 'errors' => [], 'http_code' => 403, 'blocked' => true,
            'error' => 'Bloqueado por la protección de DNS: ' . $reason . '.',
        ];
    }

    private static function record(string $zone, ?string $name, ?string $type, string $action, string $reason, bool $blocked): void
    {
        try {
            $what = ($name ?? '?') . ($type ? " {$type}" : '');
            LogService::log('dns.guard', $zone, ($blocked ? 'BLOQUEADO' : 'AUDITORÍA (habría bloqueado)') . " {$action} {$what}: {$reason}");
            $log = json_decode((string)Settings::get(self::LOG_SETTING, '[]'), true);
            $log = is_array($log) ? $log : [];
            array_unshift($log, [
                'at' => date('Y-m-d H:i:s'), 'zone' => $zone, 'name' => $name, 'type' => $type,
                'action' => $action, 'blocked' => $blocked, 'reason' => $reason,
            ]);
            Settings::set(self::LOG_SETTING, json_encode(array_slice($log, 0, self::LOG_MAX), JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // El registro nunca debe impedir ni provocar un bloqueo.
        }
    }

    public static function recentLog(int $limit = 20): array
    {
        $log = json_decode((string)Settings::get(self::LOG_SETTING, '[]'), true);
        return array_slice(is_array($log) ? $log : [], 0, $limit);
    }

    private static function zoneName(string $token, string $zoneId): ?string
    {
        if (self::$zoneNames === null) {
            self::$zoneNames = [];
            foreach (CloudflareService::getConfiguredAccounts() as $acct) {
                foreach (($acct['zones'] ?? []) as $z) {
                    if (!empty($z['id']) && !empty($z['name'])) {
                        self::$zoneNames[(string)$z['id']] = strtolower((string)$z['name']);
                    }
                }
            }
        }
        if (isset(self::$zoneNames[$zoneId])) {
            return self::$zoneNames[$zoneId];
        }
        $r = CloudflareService::apiRequest($token, 'GET', "/zones/{$zoneId}");
        $name = $r['ok'] ? strtolower((string)($r['result']['name'] ?? '')) : '';
        if ($name === '') {
            return null;
        }
        return self::$zoneNames[$zoneId] = $name;
    }

    private static function currentRecord(string $token, string $zoneId, string $recId): ?array
    {
        $key = $zoneId . '/' . $recId;
        if (!array_key_exists($key, self::$recordCache)) {
            $r = CloudflareService::apiRequest($token, 'GET', "/zones/{$zoneId}/dns_records/{$recId}");
            self::$recordCache[$key] = ($r['ok'] && is_array($r['result'] ?? null)) ? $r['result'] : null;
        }
        return self::$recordCache[$key];
    }

    // ── Comprobación contra el plan de relevo ────────────────────────────

    /** Pares primario → relevo configurados en este panel (como failover_dns_plan). */
    public static function failoverPairs(): array
    {
        $pairs = [];
        foreach (FailoverService::getServers() as $srv) {
            if (($srv['role'] ?? '') !== 'primary' || empty($srv['enabled'] ?? true)) {
                continue;
            }
            $to = FailoverService::getServer((string)($srv['failover_to'] ?? ''));
            if ($to) {
                $pairs[] = ['from' => (string)$srv['ip'], 'from_name' => (string)$srv['name'], 'to' => (string)$to['ip'], 'to_name' => (string)$to['name']];
            }
        }
        return $pairs;
    }

    /**
     * Registros que el relevo de ESTE panel movería y que estas reglas bloquearían.
     * Se usa al guardar: unas reglas que impidan el propio relevo no se aceptan.
     * @return array{checked:int,blocked:array<int,array{zone:string,name:string,reason:string}>,error:?string}
     */
    public static function planConflicts(array $cfg): array
    {
        $out = ['checked' => 0, 'blocked' => [], 'error' => null];
        if (($cfg['mode'] ?? 'off') === 'off') {
            return $out;
        }
        $pairs = self::failoverPairs();
        if (!$pairs) {
            $out['error'] = 'Este panel no tiene servidor primario con relevo: no hay plan de DNS que comprobar.';
            return $out;
        }
        $plan = DnsPlanService::plan($pairs);
        if (empty($plan['ok'])) {
            $out['error'] = (string)($plan['error'] ?? 'No se pudo calcular el plan de relevo.');
            return $out;
        }
        foreach ($plan['zones'] ?? [] as $z) {
            foreach ($z['records'] ?? [] as $rec) {
                $name = preg_replace('/ \(proxy\)$/', '', (string)$rec);
                $out['checked']++;
                $reason = self::evaluate($cfg, (string)$z['zone'], $name, 'A', 'update');
                if ($reason !== null) {
                    $out['blocked'][] = ['zone' => (string)$z['zone'], 'name' => $name, 'reason' => $reason];
                }
            }
        }
        return $out;
    }
}
