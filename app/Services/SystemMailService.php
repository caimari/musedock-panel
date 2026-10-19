<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Correo que genera el propio servidor (cron, root…): que se entregue EN el servidor y no salga.
 *
 * Caso 2026-10-18: myorigin = mail.musedock.com y mydestination = localhost. Una tarea de cron que fallaba cada
 * minuto generaba usuario@mail.musedock.com; Postfix lo veía como ajeno, lo mandaba por el relayhost a otro nodo,
 * que lo rechazaba («Relay access denied»), y se acumularon 1.305 mensajes reintentándose.
 *
 * Qué hace: si el dominio de origen del correo del sistema no es local, lo añade a mydestination. Sin nombres
 * escritos en el código: todo sale de lo que dice el Postfix de ese nodo.
 *
 * Seguridad (si cualquiera falla, NO se toca nada y el aviso del preflight sigue ahí):
 *  - el dominio no puede ser de buzones virtuales, alias virtuales, relay ni transporte propio (Postfix no admite
 *    el mismo dominio en mydestination y en los virtuales);
 *  - mydestination solo se toca si es el que deja el instalador del panel (localhost) o el de Postfix por defecto;
 *    una configuración propia del administrador no se pisa;
 *  - copia de main.cf antes, «postfix check» después y, si algo falla, se restaura y se avisa;
 *  - modo por ajuste mail_system_local_mode: off | warn (por defecto: solo avisa y da el comando) | auto.
 */
class SystemMailService
{
    public const BACKUP_DIR = '/var/lib/musedock/backup-postfix';

    /** mydestination que el panel o Postfix dejan por defecto: los únicos que se amplían solos. */
    private const SAFE_DEST = ['', 'localhost', 'localhost.localdomain, localhost', '$myhostname, localhost.$mydomain, localhost',
        '$myhostname, localhost.$mydomain, localhost, $mydomain'];

    public static function mode(): string
    {
        $m = strtolower(trim((string)Settings::get('mail_system_local_mode', 'warn')));
        return in_array($m, ['off', 'warn', 'auto'], true) ? $m : 'warn';
    }

    /**
     * Decide qué hacer. Pura: recibe lo que dice Postfix.
     * @param array{origin:string,mydestination:string,origin_virtual?:?bool,relayhost?:string} $pf
     *        origin_virtual: true = el dominio es de buzones/alias virtuales, relay o transporte; null = no se pudo saber
     * @return array{action:string,reason:string,mydestination:string}  action: none | add | skip
     */
    public static function plan(array $pf): array
    {
        $origin = strtolower(trim((string)($pf['origin'] ?? '')));
        $cur = trim((string)($pf['mydestination'] ?? ''));
        $none = static fn(string $r) => ['action' => 'none', 'reason' => $r, 'mydestination' => $cur];
        $skip = static fn(string $r) => ['action' => 'skip', 'reason' => $r, 'mydestination' => $cur];

        if ($origin === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $origin)) {
            return $none('no se pudo determinar el dominio de origen del correo del sistema');
        }
        $tokens = array_filter(preg_split('/[\s,]+/', strtolower($cur)) ?: []);
        if (in_array($origin, $tokens, true)
            || (in_array('$myhostname', $tokens, true) && $origin === strtolower((string)($pf['myhostname'] ?? '')))) {
            return $none('el dominio de origen ya es local');
        }
        if (($pf['origin_virtual'] ?? null) === null) {
            return $skip('no se pudo comprobar si el dominio es de buzones virtuales (¿base de datos de correo sin respuesta?): no se toca nada');
        }
        if ($pf['origin_virtual'] === true) {
            return $skip("{$origin} es un dominio de buzones virtuales, alias, relay o transporte de este Postfix: añadirlo a mydestination "
                . 'rompería el correo. Hay que dirigir el correo del sistema a otro origen (myorigin) a mano.');
        }
        if (!in_array(strtolower($cur), array_map('strtolower', self::SAFE_DEST), true)) {
            return $skip("mydestination tiene una configuración propia ({$cur}): no se pisa. Añade {$origin} a mano si quieres entrega local.");
        }
        $new = $cur === '' ? $origin : $cur . ', ' . $origin;
        return ['action' => 'add', 'reason' => "se añade {$origin} a mydestination: el correo del sistema se entregará en este servidor", 'mydestination' => $new];
    }

    /** Lee de Postfix lo necesario para plan(). Solo lectura. */
    public static function inspect(): array
    {
        $pc = static fn(string $k, bool $expand = false): string
            => trim((string)@shell_exec('postconf -h ' . ($expand ? '-x ' : '') . escapeshellarg($k) . ' 2>/dev/null'));
        $origin = $pc('myorigin', true);
        if ($origin !== '' && $origin[0] === '/') {
            $origin = trim((string)strtok((string)@file_get_contents($origin), "\n"));
        }
        $origin = strtolower($origin);
        return [
            'origin' => $origin,
            'myhostname' => strtolower($pc('myhostname')),
            'mydestination' => $pc('mydestination'),
            'relayhost' => $pc('relayhost'),
            'origin_virtual' => self::domainIsHandledElsewhere($origin, $pc),
        ];
    }

    /** ¿El dominio lo gestiona otra cosa de Postfix (virtual, relay, transporte)? null = no se pudo saber. */
    private static function domainIsHandledElsewhere(string $domain, callable $pc): ?bool
    {
        if ($domain === '') {
            return null;
        }
        $found = false;
        foreach (['virtual_mailbox_domains', 'virtual_alias_domains', 'relay_domains', 'transport_maps'] as $key) {
            foreach (preg_split('/[\s,]+/', $pc($key, true)) ?: [] as $entry) {
                if ($entry === '') {
                    continue;
                }
                if (strpos($entry, ':') === false) {
                    if ($key !== 'transport_maps' && strtolower($entry) === $domain) {
                        $found = true;
                    }
                    continue;
                }
                // una tabla (pgsql:, hash:, mysql:…): se pregunta por el dominio; si la consulta falla, no se sabe
                $out = [];
                $rc = 0;
                @exec('postmap -q ' . escapeshellarg($domain) . ' ' . escapeshellarg($entry) . ' 2>&1', $out, $rc);
                // postmap devuelve 1 tanto para «no está» como para «no pude leer la tabla» (p. ej. sin permiso
                // sobre /etc/postfix/pgsql-*.cf): si dice algo de error, no se sabe.
                if (preg_match('/^postmap: (fatal|error|warning)/mi', implode("\n", $out))) {
                    return null;
                }
                if ($rc === 0) {
                    $found = true;
                } elseif ($rc !== 1) {
                    return null;
                }
            }
        }
        return $found;
    }

    /**
     * Aplica el plan. $force = ignorar el modo (lo usa la orden manual). Devuelve ['ok','changed','message'].
     */
    public static function apply(bool $force = false): array
    {
        if (trim((string)@shell_exec('command -v postconf 2>/dev/null')) === '') {
            return ['ok' => true, 'changed' => false, 'message' => 'Postfix no está instalado en este nodo'];
        }
        if (!$force && self::mode() !== 'auto') {
            return ['ok' => true, 'changed' => false, 'message' => 'modo ' . self::mode() . ': no se cambia nada solo'];
        }
        if (!(function_exists('posix_geteuid') ? posix_geteuid() === 0 : false)) {
            return ['ok' => false, 'changed' => false, 'message' => 'hay que ejecutarlo como root'];
        }
        $plan = self::plan(self::inspect());
        if ($plan['action'] !== 'add') {
            return ['ok' => $plan['action'] === 'none', 'changed' => false, 'message' => $plan['reason']];
        }

        $conf = trim((string)@shell_exec('postconf -h config_directory 2>/dev/null')) ?: '/etc/postfix';
        $mainCf = $conf . '/main.cf';
        if (!is_file($mainCf)) {
            return ['ok' => false, 'changed' => false, 'message' => "no se encuentra {$mainCf}"];
        }
        if (!is_dir(self::BACKUP_DIR)) {
            @mkdir(self::BACKUP_DIR, 0750, true);
        }
        $backup = self::BACKUP_DIR . '/main.cf.' . date('Ymd_His');
        if (!@copy($mainCf, $backup)) {
            return ['ok' => false, 'changed' => false, 'message' => 'no se pudo hacer copia de main.cf: no se cambia nada'];
        }

        $before = trim((string)@shell_exec('postconf -h mydestination 2>/dev/null'));
        $o1 = [];
        $rc1 = 0;
        @exec('postconf -e ' . escapeshellarg('mydestination = ' . $plan['mydestination']) . ' 2>&1', $o1, $rc1);
        $o2 = [];
        $rc2 = 0;
        @exec('postfix check 2>&1', $o2, $rc2);
        $now = trim((string)@shell_exec('postconf -h mydestination 2>/dev/null'));
        if ($rc1 !== 0 || $rc2 !== 0 || $now !== $plan['mydestination']) {
            @copy($backup, $mainCf);
            @shell_exec('postfix reload 2>&1');
            $why = 'postfix check falló: ' . trim(implode(' ', array_slice($o2 ?: $o1, 0, 2)));
            self::alert('No se pudo ajustar el correo del sistema de este nodo', "{$why}\nSe ha restaurado main.cf desde {$backup}.", 'fallo');
            return ['ok' => false, 'changed' => false, 'message' => "{$why}; main.cf restaurado"];
        }
        @shell_exec('postfix reload 2>&1');
        try {
            LogService::log('mail.system-local', 'mydestination', "mydestination: «{$before}» → «{$now}» (copia: {$backup})");
        } catch (\Throwable) {
        }
        return ['ok' => true, 'changed' => true, 'message' => "mydestination = {$now} (copia de main.cf en {$backup}; deshacer: cp {$backup} {$mainCf} && postfix reload)"];
    }

    /** Aviso (una vez por cambio) cuando falla el ajuste automático. */
    private static function alert(string $subject, string $message, string $key): void
    {
        try {
            $k = 'mail_system_local_alert_' . $key;
            $h = md5($subject . $message);
            if (Settings::get($k, '') === $h) {
                return;
            }
            Settings::set($k, $h);
            NotificationService::send($subject, $message, 'ha_readiness');
        } catch (\Throwable) {
        }
    }

    /** Desde el cron del clúster, como mucho una vez por hora y solo en modo auto. */
    public static function periodic(): string
    {
        if (self::mode() !== 'auto' || time() - (int)Settings::get('mail_system_local_run_at', '0') < 3600) {
            return '';
        }
        Settings::set('mail_system_local_run_at', (string)time());
        $r = self::apply();
        return $r['changed'] || !$r['ok'] ? 'correo del sistema local: ' . $r['message'] : '';
    }
}
