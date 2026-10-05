<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Reglas de los avisos (Ajustes → Avisos, MCP alerts_*): qué tipos se silencian,
 * controles de hardening dados por buenos, umbral propio o silencio por disco y
 * servidor, y cuánto debe durar un fallo del nodo de correo antes de avisar.
 *
 * Se configura en el master y se copia a sus nodos (acción de cluster
 * set-alert-policy, sin secretos). Silenciar solo quita el correo/Telegram: el aviso
 * sigue quedando en el monitor del panel.
 */
class AlertPolicyService
{
    /** Tipos que se pueden silenciar. Los del relevo (failover, cambio de rol) no: siempre avisan. */
    public const TYPES = [
        'CPU_HIGH'           => ['CPU alta', 'CPU por encima del umbral un rato seguido.'],
        'RAM_HIGH'           => ['RAM alta', 'Memoria por encima del umbral un rato seguido.'],
        'DISK_HIGH'          => ['Disco lleno', 'Un disco por encima del umbral. Mejor ajustar por disco abajo que silenciarlo entero.'],
        'NET_HIGH'           => ['Tráfico alto', 'Entrada de red por encima del umbral.'],
        'GPU_TEMP'           => ['Temperatura de GPU', 'GPU por encima de la temperatura máxima.'],
        'GPU_HIGH'           => ['Uso de GPU', 'GPU al máximo un rato seguido.'],
        'security_hardening' => ['Hardening degradado', 'Controles de seguridad del servidor fuera de lo recomendado. Mejor aceptar los controles que quieras así.'],
        'config_drift'       => ['Cambios en ficheros críticos', 'sshd_config, sudoers, etc. cambiados fuera del panel.'],
        'firewall_change'    => ['Cambio en el firewall', 'Reglas de ufw/iptables cambiadas fuera del panel.'],
        'public_exposure'    => ['Puerto expuesto', 'Un servicio sensible escucha abierto a internet.'],
        'login_anomaly'      => ['Acceso raro al panel', 'Entrada al panel desde un país o red poco habitual.'],
        'server_reboot'      => ['Reinicio del servidor', 'El servidor se ha reiniciado.'],
        'monitor_gap'        => ['Monitor sin medidas', 'El monitor estuvo un rato sin tomar medidas.'],
        'mail_node'          => ['Nodo de correo con problemas', 'Servicios de correo de un nodo sin responder (avisa solo si dura, ver abajo).'],
        'mail_queue'         => ['Cola de correo pausada', 'Altas/cambios de correo hacia un nodo llevan más de 24 h en pausa (el nodo no las recibe).'],
        'replication'        => ['Réplica con problemas', 'PostgreSQL, MariaDB o Redis de este nodo no replican bien (o se recuperan). Mejor no silenciarlo: un relevo podría perder datos.'],
        'lsyncd'             => ['Copia de ficheros (lsyncd)', 'La copia de ficheros a los nodos falla o se recupera.'],
        'system_changes'     => ['Cambios del sistema (posible intruso)', 'Algo nuevo o cambiado donde se instalan apps o se esconde un intruso: carpetas de /opt, /srv, /var/www y /etc, servicios, tareas programadas, /usr/local/bin, claves SSH, ejecutables en /tmp. Se avisa una vez de cada cosa. Mejor no silenciarlo: ignora rutas concretas abajo.'],
        'license'            => ['Licencia del portal', 'La licencia del portal de clientes caduca pronto (14, 7, 3 y 1 días), entra en periodo de gracia o caduca. Una vez por etapa.'],
        'filesync_unsynced'  => ['Carpetas sin copia al relevo', 'En el master: una carpeta de app en /opt o /srv que no se copia al servidor de relevo ni está marcada como propia de la máquina (avisa solo cuando cambia la lista).'],
        'cert'               => ['Certificados', 'Una web sin certificado válido o que no se ha renovado (con proxy en strict, error 526).'],
        'config_mirror'      => ['Copia de configuración del master', 'En una copia: algo de la configuración del master no se pudo copiar (avisa solo cuando cambia la lista).'],
        'witness'            => ['Testigos', 'Un testigo externo no responde o vuelve.'],
        'node_down'          => ['Nodo caído / recuperado', 'Un servidor del cluster deja de responder a los demás (o vuelve).'],
    ];

    /**
     * Qué significa cada aviso y qué hacer, para el final del correo. Escrito para quien
     * lo recibe, no para quien programa el panel.
     */
    /**
     * Avisos de "algo no responde" que se callan durante un mantenimiento programado
     * (reinicios, pruebas de relevo, mudanzas de VM): se apuntan, pero no se envían.
     */
    public const MAINTENANCE_TYPES = ['replication', 'node_down', 'mail_node', 'mail_queue', 'witness', 'lsyncd', 'cert',
        'config_mirror', 'monitor_gap', 'server_reboot'];

    public const EXPLAIN = [
        'node_down' => ['Un servidor del cluster ha dejado de responder a los demás (o ha vuelto).',
            'El correo dice qué responde y qué no (VPN, panel, webs, testigos). Si todo falla, está apagado o reiniciándose. Si es un trabajo programado, activa antes el modo mantenimiento para no recibir estos avisos.'],
        'CPU_HIGH' => ['El procesador de este servidor lleva varios minutos seguidos por encima del límite.',
            'Mira en el correo qué procesos gastan más. Si es una tarea puntual (copia, compilación), no hay que hacer nada. Si se repite cada día, conviene revisar ese proceso o subir el límite.'],
        'RAM_HIGH' => ['La memoria de este servidor lleva varios minutos casi llena.',
            'Mira qué procesos usan más memoria. Si llega al 100 %, el sistema puede cerrar programas (bases de datos, PHP). Reinicia el proceso que crece sin control o amplía la memoria.'],
        'DISK_HIGH' => ['Un disco de este servidor está casi lleno. Si llega al 100 %, las bases de datos y las webs dejan de poder guardar datos.',
            'Libera espacio (copias viejas, registros, ficheros temporales). Si ese disco está lleno a propósito (p. ej. un disco de trabajo), ponle su propio límite o quítale el aviso en Avisos → Discos.'],
        'NET_HIGH' => ['Está entrando mucho tráfico de red en este servidor.',
            'Si no esperabas tráfico (una descarga, una copia), puede ser un ataque: revisa las webs con más peticiones en el monitor.'],
        'GPU_TEMP' => ['La tarjeta gráfica está más caliente de lo recomendado.', 'Revisa la ventilación y el ventilador de la tarjeta. Si sigue subiendo, para el trabajo que la usa.'],
        'GPU_HIGH' => ['La tarjeta gráfica lleva un rato al máximo.', 'Normal si está haciendo un trabajo pesado (vídeo, IA). Si no, revisa qué la usa.'],
        'security_hardening' => ['Algunos ajustes de seguridad del servidor no están como se recomienda (por ejemplo, SSH que acepta contraseña).',
            'Si están así a propósito, márcalos como aceptados en Avisos → Hardening y no volverá a avisar. Si no, corrígelos (Ajustes → Seguridad). Solo vuelve a avisar si falla uno nuevo.'],
        'config_drift' => ['Han cambiado ficheros de configuración sensibles (SSH, sudo, firewall…) fuera del panel.',
            'Si lo hiciste tú o alguien de confianza, no hay que hacer nada. Si no sabes quién lo cambió, revísalo ya: puede ser una intrusión.'],
        'firewall_change' => ['Han cambiado las reglas del firewall de este servidor fuera del panel.',
            'Si lo hiciste tú (o un asistente con tu permiso), no hay que hacer nada. Si no, revisa el cambio que viene en el correo.'],
        'public_exposure' => ['Un servicio delicado (base de datos, Redis, panel…) está abierto a todo internet.',
            'Ciérralo en el firewall o limítalo a las IPs que lo necesitan (Ajustes → Firewall).'],
        'login_anomaly' => ['Alguien ha entrado al panel desde un país o una red poco habitual.',
            'Si fuiste tú (viaje, otra conexión), no hay que hacer nada. Si no, cambia tu contraseña y revisa los accesos.'],
        'server_reboot' => ['Este servidor se ha reiniciado.', 'Si no lo reiniciaste tú, puede haber sido un corte de luz o del proveedor. Comprueba que las webs y el correo funcionan.'],
        'monitor_gap' => ['El monitor estuvo un rato sin tomar medidas (el servidor estuvo muy ocupado o parado).', 'Normalmente no hay que hacer nada. Si se repite, revisa la carga del servidor.'],
        'mail_node' => ['El correo de un servidor no responde bien desde hace unos minutos (puertos de correo, su panel o su base de datos).',
            'Si en el correo pone que la API no respondió, suele ser la red o la VPN entre servidores, no el correo. Si dura, comprueba que puedes enviar y recibir. Al recuperarse llega otro aviso.'],
        'mail_queue' => ['Hay cambios de correo (buzones, alias, dominios) que llevan más de un día sin poder llegar a otro servidor.',
            'Comprueba que ese servidor está encendido y conectado. Mientras tanto, esos buzones no existen allí: en un relevo no funcionarían.'],
        'replication' => ['Las bases de datos de este servidor no se están copiando bien desde el principal (o se han recuperado).',
            'Mira el diagnóstico del correo: si el principal está caído o reiniciándose, la réplica se reengancha sola al volver. Si el principal responde, revisa Ajustes → Replicación o pide failover_preflight por MCP. Para trabajos programados, activa antes el modo mantenimiento.'],
        'lsyncd' => ['La copia de ficheros de las webs hacia el otro servidor va mal (o se ha recuperado).',
            'Mientras dure, el otro servidor no tiene los últimos cambios de las webs. Revisa Cluster → Archivos.'],
        'witness' => ['Un testigo externo (el servidor que confirma las caídas antes de un relevo) no responde, o vuelve.',
            'Si cae uno, decide el otro. Si caen todos, los relevos se deciden sin testigos. Comprueba que ese servidor está encendido.'],
        'cert' => ['Una o varias webs de este servidor no tienen un certificado válido para su nombre, o el suyo está a punto de caducar sin haberse renovado.',
            'Mira en el correo qué webs son. Suele ser un dominio que ya no apunta a ningún sitio (márcalo inactivo) o un fallo al renovar (revisa journalctl -u caddy). Por MCP: cert_status dice el estado de todas.'],
        'config_mirror' => ['Este servidor de reserva no ha podido copiar algo de la configuración del principal (un servicio, una tarea programada…).',
            'Si es algo propio de la máquina principal (su hardware, sus líneas de internet), exclúyelo de la copia. Si no, instala lo que falta aquí para que el relevo funcione.'],
        'system_changes' => ['Ha aparecido o cambiado algo en un sitio donde se instalan aplicaciones o donde suele esconderse un intruso (una carpeta, un servicio, una tarea programada, un programa, una clave SSH o un ejecutable en /tmp).',
            'Si lo has hecho tú o alguien de confianza, no hay que hacer nada: se avisa una sola vez. Si no lo reconoces, revísalo cuanto antes (el correo trae comandos para empezar). Si una ruta cambia a menudo y es normal, añádela a "Cambios del sistema: ignorar" en Avisos.'],
        'license' => ['La licencia del portal de clientes está a punto de caducar o ya ha caducado. Cuando caduque y pasen los días de gracia, los clientes no podrán entrar al portal.',
            'Pulsa "Renovar ahora" en Ajustes → Portal Clientes. Si la licencia está ligada a otro servidor, transfiérela en el servidor de licencias y pulsa "Activar en este servidor". Si ha caducado de verdad, hay que alargarla en el servidor de licencias o con tu proveedor.'],
        'filesync_unsynced' => ['En el servidor principal hay una carpeta de aplicación (en /opt o /srv) que no se copia al servidor de relevo. Si el principal cae, esa app no estaría en el otro.',
            'Si la app debe seguir funcionando tras un relevo, añádela a la copia (sync-add o MCP filesync_extra_paths) y guarda su base de datos en una base replicada. Si es solo de esta máquina, márcala como propia (sync-local) y no se volverá a avisar.'],
    ];

    /** Pie del correo: qué significa, qué hacer y cómo silenciarlo. '' si el tipo no tiene explicación. */
    public static function emailFooter(string $type): string
    {
        $e = self::EXPLAIN[$type] ?? null;
        if (!$e) {
            return '';
        }
        $host = (string)(Settings::get('panel_hostname', '') ?: gethostname());
        $port = (string)(Settings::get('panel_port', '8444') ?: '8444');
        $label = self::TYPES[$type][0] ?? $type;
        return "\n\n──────────\nQué significa: {$e[0]}\n\nQué hacer: {$e[1]}\n\n"
            . "¿No quieres este aviso? En el panel del servidor principal: Ajustes → Avisos → silenciar \"{$label}\""
            . " (desde aquí: https://{$host}:{$port}/settings/alerts). Este correo lo envía {$host}.";
    }

    private static function json(string $key, $default)
    {
        $v = json_decode((string)Settings::get($key, ''), true);
        return is_array($v) ? $v : $default;
    }

    /**
     * ¿Ese tipo está silenciado en este servidor? En la lista: "TIPO" (en todos los
     * servidores) o "servidor:TIPO" (solo en ese, nombre corto, p. ej. "nitro:DISK_HIGH").
     */
    public static function muted(string $type): bool
    {
        if ($type === '') {
            return false;
        }
        $list = self::json('alerts_muted', []);
        return in_array($type, $list, true) || in_array(self::shortHost() . ':' . $type, $list, true) || self::hidden($type);
    }

    /**
     * Tipos que ni se apuntan en el monitor ni avisan (más que silenciar, que solo quita el
     * correo). "TIPO" en todos o "servidor:TIPO" solo en ese. Acepta el tipo del monitor
     * (DISK_HIGH, CONFIG_DRIFT…) o el de la política (config_drift…), sin distinguir mayúsculas.
     */
    public static function hidden(string $type): bool
    {
        if ($type === '') {
            return false;
        }
        $t = strtolower($type);
        foreach (self::json('alerts_hidden', []) as $e) {
            $e = strtolower((string)$e);
            if ($e === $t || $e === self::shortHost() . ':' . $t) {
                return true;
            }
        }
        return false;
    }

    /**
     * Títulos de controles de hardening dados por buenos EN ESTE servidor. En la lista:
     * "Título" (en todos) o "servidor:Título" (solo en ese, nombre corto; p. ej.
     * "nitro:SSHD PermitRootLogin").
     */
    public static function hardeningAccepted(): array
    {
        $me = self::shortHost();
        $out = [];
        foreach (array_map('strval', self::json('alerts_hardening_accepted', [])) as $e) {
            if (preg_match('/^([a-z0-9][a-z0-9-]*):(.+)$/', $e, $m)) {
                if ($m[1] === $me) {
                    $out[] = $m[2];
                }
            } else {
                $out[] = $e;
            }
        }
        return array_values(array_unique($out));
    }

    /** La lista tal cual está guardada (con las entradas "servidor:Título"), para exportar y editar. */
    public static function hardeningAcceptedRaw(): array
    {
        return array_values(array_map('strval', self::json('alerts_hardening_accepted', [])));
    }

    /** Nombre corto de este servidor para las reglas por servidor (p. ej. "nitro"). */
    public static function shortHost(?string $host = null): string
    {
        return strtolower(explode('.', $host ?? (gethostname() ?: 'localhost'))[0]);
    }

    /**
     * Umbral del disco montado en $mount de este servidor: el propio si lo tiene
     * (0 = silenciado) o $default. Reglas: {"nitro": {"/workspace": 99}, "*": {...}}.
     */
    public static function diskThreshold(string $mount, float $default): float
    {
        $rules = self::json('alerts_disk_overrides', []);
        foreach ([self::shortHost(), '*'] as $h) {
            if (isset($rules[$h][$mount]) && is_numeric($rules[$h][$mount])) {
                return (float)$rules[$h][$mount];
            }
        }
        return $default;
    }

    /** Minutos seguidos que debe fallar un nodo de correo antes de avisar. */
    public static function mailNodeAfterMinutes(): int
    {
        return max(1, min(120, (int)Settings::get('alerts_mail_node_after_minutes', '5')));
    }

    /** ¿Hay un mantenimiento programado en curso? */
    public static function inMaintenance(): bool
    {
        return (int)Settings::get('alerts_maintenance_until', '0') > time();
    }

    /** Minutos que debe durar una caída (nodo, réplica) antes de avisar. */
    public static function outageAfterMinutes(): int
    {
        return max(1, min(60, (int)Settings::get('alerts_outage_after_minutes', '5')));
    }

    public static function export(): array
    {
        return [
            'maintenance_until' => (int)Settings::get('alerts_maintenance_until', '0') > time() ? date('Y-m-d H:i', (int)Settings::get('alerts_maintenance_until', '0')) : null,
            'maintenance_reason' => (string)Settings::get('alerts_maintenance_reason', ''),
            'maintenance_until_ts' => (int)Settings::get('alerts_maintenance_until', '0'),
            'outage_after_minutes' => self::outageAfterMinutes(),
            'muted' => self::json('alerts_muted', []),
            'hidden' => self::json('alerts_hidden', []),
            'hardening_accepted' => self::hardeningAcceptedRaw(),
            'disk_overrides' => self::json('alerts_disk_overrides', []),
            'mail_node_after_minutes' => self::mailNodeAfterMinutes(),
            'system_watch_ignore' => self::systemWatchIgnore(),
        ];
    }

    /** Rutas que el vigilante de cambios del sistema no mira (patrones; admite "host:patrón"). */
    public static function systemWatchIgnore(): array
    {
        return array_values(array_filter(array_map('strval', self::json('alerts_system_watch_ignore', []))));
    }

    /** Guarda las reglas (validadas). Lo que no venga se deja como está. */
    public static function save(array $p): array
    {
        if (array_key_exists('hidden', $p)) {
            $h = [];
            foreach (array_map('strval', (array)$p['hidden']) as $e) {
                [$host, $t] = str_contains($e, ':') ? explode(':', $e, 2) : ['', $e];
                $known = array_change_key_case(array_flip(array_keys(self::TYPES)), CASE_LOWER);
                if (isset($known[strtolower($t)]) && ($host === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $host = self::shortHost($host)))) {
                    $h[] = $host === '' ? $t : "{$host}:{$t}";
                }
            }
            Settings::set('alerts_hidden', json_encode(array_values(array_unique($h))));
        }
        if (array_key_exists('muted', $p)) {
            $m = [];
            foreach (array_map('strval', (array)$p['muted']) as $e) {
                [$h, $t] = str_contains($e, ':') ? explode(':', $e, 2) : ['', $e];
                if (isset(self::TYPES[$t]) && ($h === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $h = self::shortHost($h)))) {
                    $m[] = $h === '' ? $t : "{$h}:{$t}";
                }
            }
            Settings::set('alerts_muted', json_encode(array_values(array_unique($m))));
        }
        if (array_key_exists('hardening_accepted', $p)) {
            $a = array_values(array_unique(array_filter(array_map(static fn($t) => trim((string)$t), (array)$p['hardening_accepted']))));
            Settings::set('alerts_hardening_accepted', json_encode($a, JSON_UNESCAPED_UNICODE));
        }
        if (array_key_exists('disk_overrides', $p)) {
            $out = [];
            foreach ((array)$p['disk_overrides'] as $host => $mounts) {
                $host = strtolower(trim((string)$host));
                if ($host === '' || !preg_match('/^[a-z0-9*][a-z0-9.-]*$/', $host)) {
                    continue;
                }
                foreach ((array)$mounts as $mount => $thr) {
                    $mount = trim((string)$mount);
                    if ($mount !== '' && $mount[0] === '/' && is_numeric($thr) && $thr >= 0 && $thr <= 100) {
                        $out[self::shortHost($host)][$mount] = (float)$thr;
                    }
                }
            }
            Settings::set('alerts_disk_overrides', json_encode($out, JSON_UNESCAPED_SLASHES));
        }
        if (array_key_exists('maintenance_minutes', $p)) {
            // 0 = terminar ahora. Máximo 12 h: que no se quede olvidado.
            $min = max(0, min(720, (int)$p['maintenance_minutes']));
            Settings::set('alerts_maintenance_until', (string)($min > 0 ? time() + $min * 60 : 0));
            Settings::set('alerts_maintenance_reason', $min > 0 ? mb_substr(trim((string)($p['maintenance_reason'] ?? '')), 0, 200) : '');
        } elseif (array_key_exists('maintenance_until_ts', $p)) {
            // Copia desde el master: la misma hora de fin.
            Settings::set('alerts_maintenance_until', (string)(int)$p['maintenance_until_ts']);
            Settings::set('alerts_maintenance_reason', mb_substr((string)($p['maintenance_reason'] ?? ''), 0, 200));
        }
        if (array_key_exists('outage_after_minutes', $p)) {
            Settings::set('alerts_outage_after_minutes', (string)max(1, min(60, (int)$p['outage_after_minutes'])));
        }
        if (array_key_exists('system_watch_ignore', $p)) {
            $ig = [];
            foreach ((array)$p['system_watch_ignore'] as $e) {
                $e = trim((string)$e);
                [$h, $pat] = preg_match('#^([a-z0-9][a-z0-9.-]*):(/.*)$#i', $e, $m) ? [self::shortHost($m[1]) . ':', $m[2]] : ['', $e];
                if ($pat !== '' && $pat[0] === '/' && strlen($pat) <= 300) {
                    $ig[] = $h . $pat;
                }
            }
            Settings::set('alerts_system_watch_ignore', json_encode(array_values(array_unique($ig)), JSON_UNESCAPED_SLASHES));
        }
        if (array_key_exists('mail_node_after_minutes', $p)) {
            Settings::set('alerts_mail_node_after_minutes', (string)max(1, min(120, (int)$p['mail_node_after_minutes'])));
        }
        return self::export();
    }

    /**
     * Desde una copia: manda las reglas al master, que las guarda y las reparte a todos
     * (también a esta copia). Así se pueden cambiar desde cualquier nodo.
     */
    public static function saveViaMaster(array $p): array
    {
        foreach (ClusterService::getNodes() as $n) {
            if (($n['role'] ?? '') === 'master') {
                $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'set-alert-policy-master', 'payload' => $p]);
                if (!empty($r['ok']) && !empty($r['data']['ok'])) {
                    self::save((array)($r['data']['policy'] ?? $p)); // al momento aquí, sin esperar al reparto
                    return ['ok' => true, 'policy' => self::export(), 'copied' => (array)($r['data']['copied'] ?? [])];
                }
                return ['ok' => false, 'error' => 'El master no aceptó el cambio: ' . ($r['data']['error'] ?? $r['error'] ?? '?') . ' (¿panel del master anterior a 1.0.307?)'];
            }
        }
        return ['ok' => false, 'error' => 'Este servidor es copia y no tiene al master registrado: cámbialo en el master.'];
    }

    /** Acción de cluster set-alert-policy-master (en el master, pedida por una copia). */
    public static function importFromNode(array $p): array
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            return ['ok' => false, 'error' => 'este servidor no es el master'];
        }
        $policy = self::save($p);
        LogService::log('alerts.policy', 'node', 'Reglas de avisos cambiadas desde un nodo del cluster');
        return ['ok' => true, 'policy' => $policy, 'copied' => self::pushToNodes()];
    }

    /**
     * Acción de cluster set-alert-policy (en el nodo). Si este nodo tiene a su vez copias
     * colgando de él (p. ej. Nitro de mortadelo tras un relevo), se las pasa: en cascada,
     * como mucho 3 saltos, para que lleguen a todos aunque no estén registrados en el master.
     */
    public static function import(array $p): array
    {
        $depth = (int)($p['_depth'] ?? 0);
        unset($p['_depth']);
        $r = self::save($p);
        LogService::log('cluster.notify', 'alerts', 'Reglas de avisos recibidas del master');
        $cascade = [];
        if ($depth < 3) {
            foreach (ClusterService::getNodes() as $n) {
                if (($n['role'] ?? '') !== 'slave') {
                    continue;
                }
                $c = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action',
                    ['action' => 'set-alert-policy', 'payload' => self::export() + ['_depth' => $depth + 1]]);
                $cascade[(string)$n['name']] = !empty($c['ok']) && !empty($c['data']['ok']) ? 'copiadas' : 'ERROR: ' . ($c['data']['error'] ?? $c['error'] ?? '?');
            }
        }
        return ['ok' => true, 'policy' => $r, 'cascade' => $cascade];
    }

    /** En el master: copia las reglas a todos sus nodos. Devuelve el resultado por nodo. */
    public static function pushToNodes(): array
    {
        $out = [];
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            return $out;
        }
        foreach (ClusterService::getNodes() as $n) {
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'set-alert-policy', 'payload' => self::export() + ['_depth' => 1]]);
            $out[(string)$n['name']] = !empty($r['ok']) && !empty($r['data']['ok']) ? 'copiadas' : 'ERROR: ' . ($r['data']['error'] ?? $r['error'] ?? '?');
            foreach ((array)($r['data']['cascade'] ?? []) as $sub => $st) {
                $out["{$sub} (vía {$n['name']})"] = $st;
            }
        }
        return $out;
    }
}
