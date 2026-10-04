<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Testigos externos ("solo ojos", bin/witness-agent.py): servidores ajenos al cluster que
 * miran desde fuera si llegan a los servidores (por cada entrada: ONO, Orange…) y con qué
 * latencia y pérdidas. El panel les pregunta por HTTPS con su certificado FIJADO por la
 * huella SHA-256 (autofirmado, sin depender de nadie) y una clave propia de cada testigo.
 *
 * Registro por panel (la clave se cifra con la de este panel y nunca sale de él):
 *   Settings witness_agents = [{"name","url","fingerprint","key"(cifrada)}]
 */
class WitnessService
{
    public static function all(): array
    {
        $list = json_decode((string)Settings::get('witness_agents', '[]'), true);
        return is_array($list) ? array_values($list) : [];
    }

    public static function add(string $name, string $url, string $fingerprint, string $key): array
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $name)) {
            return ['ok' => false, 'error' => 'nombre no válido (letras, números y guiones)'];
        }
        if (!preg_match('#^https://[^/\s]+(:\d+)?/?$#', $url)) {
            return ['ok' => false, 'error' => 'la URL debe ser https://IP:puerto'];
        }
        $fp = self::normFingerprint($fingerprint);
        if (strlen($fp) !== 64) {
            return ['ok' => false, 'error' => 'huella SHA-256 no válida (64 cifras hexadecimales, con o sin ":")'];
        }
        if (strlen($key) < 32) {
            return ['ok' => false, 'error' => 'clave demasiado corta'];
        }
        $entry = ['name' => $name, 'url' => rtrim($url, '/'), 'fingerprint' => $fp, 'key' => ReplicationService::encryptPassword($key)];
        // Antes de guardar: que conteste con esa huella y esa clave.
        $probe = self::query($entry);
        if (empty($probe['ok'])) {
            return ['ok' => false, 'error' => 'el testigo no responde con esa huella y clave: ' . ($probe['error'] ?? '?')];
        }
        $list = array_values(array_filter(self::all(), static fn($w) => ($w['name'] ?? '') !== $name));
        $list[] = $entry;
        Settings::set('witness_agents', json_encode($list));
        LogService::log('witness', 'add', "Testigo {$name} registrado ({$entry['url']})");
        return ['ok' => true, 'witness' => $probe['witness'] ?? $name, 'targets' => array_keys($probe['targets'] ?? [])];
    }

    public static function remove(string $name): bool
    {
        $before = self::all();
        $list = array_values(array_filter($before, static fn($w) => ($w['name'] ?? '') !== strtolower($name)));
        Settings::set('witness_agents', json_encode($list));
        return count($list) < count($before);
    }

    /** Pregunta a un testigo. ['ok'=>bool, 'targets'=>[id=>{addr,name,ok,latency_avg_ms,loss_pct,samples…}]] */
    public static function query(array $w, int $timeout = 6): array
    {
        $key = ReplicationService::decryptPassword((string)($w['key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'sin clave'];
        }
        $ctx = stream_context_create([
            'http' => ['header' => "Authorization: Bearer {$key}\r\n", 'timeout' => $timeout, 'ignore_errors' => true],
            // Certificado propio del testigo: se acepta SOLO si su huella es la registrada.
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                      'peer_fingerprint' => ['sha256' => (string)($w['fingerprint'] ?? '')]],
        ]);
        $raw = @file_get_contents(rtrim((string)$w['url'], '/') . '/v1/status', false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'no responde (o la huella no coincide)'];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || empty($d['ok'])) {
            return ['ok' => false, 'error' => 'respuesta no válida o clave incorrecta'];
        }
        return $d;
    }

    /** Pregunta a todos. [name => respuesta] */
    public static function queryAll(): array
    {
        $out = [];
        foreach (self::all() as $w) {
            $out[$w['name']] = self::query($w);
        }
        return $out;
    }

    /**
     * Cómo ven los testigos una dirección (IP): por cada testigo que responde y tiene una
     * comprobación de esa dirección, 'up' (llega bien), 'down' (no llega, con muestras
     * suficientes) o 'degraded' (llega, pero con latencia/pérdidas por encima de los
     * umbrales). $nameFilter limita a comprobaciones de ese nombre (p. ej. el de salud).
     */
    public static function verdicts(string $addr, ?string $nameFilter = null, int $maxLatency = 0, int $maxLoss = 0, ?array $answers = null): array
    {
        $answers ??= self::queryAll();
        $v = [];
        foreach ($answers as $wname => $a) {
            if (empty($a['ok'])) {
                continue;
            }
            foreach ((array)($a['targets'] ?? []) as $id => $t) {
                if ((string)($t['addr'] ?? '') !== $addr || ($nameFilter !== null && (string)($t['name'] ?? '') !== $nameFilter)) {
                    continue;
                }
                if ((int)($t['samples'] ?? 0) < 3) {
                    continue;   // aún sin datos suficientes
                }
                $loss = (int)($t['loss_pct'] ?? 0);
                $lat = (int)($t['latency_avg_ms'] ?? 0);
                if (empty($t['ok']) && $loss >= 50) {
                    $v[$wname] = 'down';
                } elseif (($maxLoss > 0 && $loss > $maxLoss) || ($maxLatency > 0 && $lat > $maxLatency)) {
                    $v[$wname] = 'degraded';
                } elseif (!empty($t['ok'])) {
                    $v[$wname] = 'up';
                }
            }
        }
        return $v;
    }

    private static function normFingerprint(string $fp): string
    {
        return strtolower(preg_replace('/[^0-9a-f]/i', '', $fp));
    }
}
