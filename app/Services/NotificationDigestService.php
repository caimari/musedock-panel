<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Agrupa en UN correo los avisos de nodo que llegan casi a la vez (un incidente sacaba ~20 correos: servicio
 * caído, réplica, copia de ficheros, nodo…). Los avisos de los tipos agrupables se apuntan en una carpeta y
 * el cron del clúster (cada minuto) los envía juntos cuando el más antiguo cumple la ventana.
 *
 * Seguro por diseño: si no se puede apuntar el aviso, sale como siempre (nunca se pierde); si el cron no
 * funciona, el siguiente aviso lo vacía; con ventana 0 (Ajustes → alerts_digest_seconds) no agrupa nada.
 * Los avisos de seguridad, informes diarios y demás tipos no agrupables salen al momento.
 */
class NotificationDigestService
{
    /** Tipos de avisos de «algo pasa en un nodo»: se pueden juntar sin perder nada. */
    public const GROUPABLE = ['replication', 'node_down', 'mail_node', 'mail_queue', 'witness', 'lsyncd', 'service_down',
        'ha_readiness', 'web_permissions', 'web_errors', 'config_mirror', 'monitor_gap', 'server_reboot'];

    public const DEFAULT_WINDOW = 60;

    public static function window(): int
    {
        return max(0, min(600, (int)Settings::get('alerts_digest_seconds', (string)self::DEFAULT_WINDOW)));
    }

    public static function dir(): string
    {
        return (defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2)) . '/storage/notify-digest';
    }

    public static function isGroupable(string $type): bool
    {
        return in_array($type, self::GROUPABLE, true);
    }

    /** Apunta el aviso para el próximo envío conjunto. false = no se pudo/no procede: que salga ya. */
    public static function enqueue(string $type, string $subject, string $message): bool
    {
        if (!self::isGroupable($type) || self::window() <= 0) {
            return false;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $file = $dir . '/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(3)) . '.json';
        $json = json_encode(['type' => $type, 'subject' => $subject, 'message' => $message, 'at' => time()], JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($file, $json, LOCK_EX) === false) {
            return false;
        }
        // Si el cron no vacía la carpeta (parado), el propio aviso siguiente la vacía pasado el doble de ventana.
        self::flush(false, 2);
        return true;
    }

    /**
     * Envía lo pendiente si el aviso más antiguo ya cumplió la ventana (o siempre con $force).
     * @return int número de avisos enviados (agrupados o sueltos)
     */
    public static function flush(bool $force = false, int $factor = 1): int
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        if (!$files) {
            return 0;
        }
        sort($files);
        $oldest = (int)@filemtime($files[0]);
        if (!$force && time() - $oldest < self::window() * max(1, $factor)) {
            return 0;
        }
        $items = [];
        foreach ($files as $f) {
            $taken = $f . '.taking';
            if (!@rename($f, $taken)) {
                continue;   // otro proceso lo cogió
            }
            $d = json_decode((string)@file_get_contents($taken), true);
            @unlink($taken);
            if (is_array($d) && isset($d['subject'], $d['message'])) {
                $items[] = $d;
            }
        }
        if (!$items) {
            return 0;
        }
        [$subject, $body, $type] = self::compose($items);
        NotificationService::deliver($subject, $body, $type);
        return count($items);
    }

    /** Une los avisos en un asunto y un cuerpo. Con uno solo, sale tal cual. @return array{0:string,1:string,2:string} */
    public static function compose(array $items): array
    {
        $first = $items[0];
        if (count($items) === 1) {
            return [(string)$first['subject'], (string)$first['message'], (string)($first['type'] ?? '')];
        }
        $types = array_values(array_unique(array_map(fn($i) => (string)($i['type'] ?? ''), $items)));
        $labels = [];
        foreach ($types as $t) {
            $labels[] = AlertPolicyService::TYPES[$t][0] ?? $t;
        }
        $n = count($items);
        $subject = "{$n} avisos del clúster: " . implode(', ', array_slice($labels, 0, 3)) . (count($labels) > 3 ? '…' : '');
        $body = "Estos {$n} avisos han llegado casi a la vez (suele ser un único incidente) y se envían juntos:\n";
        foreach ($items as $k => $i) {
            $when = isset($i['at']) ? gmdate('H:i:s', (int)$i['at']) . ' UTC' : '';
            $body .= "\n──────── " . ($k + 1) . '/' . $n . ($when !== '' ? " · {$when}" : '') . " ────────\n"
                . $i['subject'] . "\n\n" . trim((string)$i['message']) . "\n";
        }
        return [$subject, $body, $types[0] ?? ''];
    }
}
