<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;

class ChangelogController
{
    public function index(): void
    {
        $versions = $this->getChangelog();

        View::render('changelog/index', [
            'layout' => 'main',
            'pageTitle' => 'Changelog',
            'versions' => $versions,
        ]);
    }

    private function getChangelog(): array
    {
        return [
            [
                'version' => '0.6.0',
                'date' => 'Pendiente / Upcoming',
                'badge' => 'warning',
                'changes' => [
                    'planned' => [
                        'es' => [
                            'Multi-idioma panel — Soporte ES/EN en toda la interfaz',
                        ],
                        'en' => [
                            'Multi-language panel — ES/EN support across the entire interface',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.5.0',
                'date' => '2026-03-16',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Cluster multi-servidor — Arquitectura master/slave entre paneles con API bidireccional y token Bearer',
                            'API de Cluster — Endpoints /api/cluster/status, heartbeat y action para comunicacion entre nodos',
                            'Sincronizacion de hostings — Cola cluster_queue para propagar creacion/eliminacion/suspension entre nodos',
                            'Heartbeat y monitoreo — Deteccion automatica de nodos caidos, alertas por email SMTP y Telegram',
                            'Failover — Promover slave a master y degradar master a slave desde el panel',
                            'Worker cron — bin/cluster-worker.php para procesar cola, heartbeats y alertas automaticas',
                            'Instalador dual PostgreSQL — Cluster dedicado en puerto 5433, migracion automatica desde 5432',
                        ],
                        'en' => [
                            'Multi-server cluster — Master/slave architecture between panels with bidirectional API and Bearer token',
                            'Cluster API — /api/cluster/status, heartbeat and action endpoints for inter-node communication',
                            'Hosting sync — cluster_queue for propagating account creation/deletion/suspension across nodes',
                            'Heartbeat and monitoring — Automatic detection of unreachable nodes, email SMTP and Telegram alerts',
                            'Failover — Promote slave to master and demote master to slave from the panel',
                            'Cron worker — bin/cluster-worker.php for queue processing, heartbeats and automatic alerts',
                            'Dual PostgreSQL installer — Dedicated cluster on port 5433, automatic migration from 5432',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.4.0',
                'date' => '2026-03-15',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Replicacion avanzada — Multiples slaves por master, IPs dual (primaria + fallback WireGuard)',
                            'Replicacion sincrona/asincrona — Modo configurable por slave en PostgreSQL',
                            'Replicacion logica PostgreSQL — Seleccionar bases de datos a replicar en vez de todo el cluster',
                            'GTID MySQL — Soporte GTID-based replication, mostrar GTID position en monitor',
                            'Monitor multi-slave — Estado individual por slave con lag, bytes pendientes, conexion activa',
                            'WireGuard VPN — Instalar, configurar wg0, CRUD de peers, generar claves y config remota, ping/latencia',
                            'Firewall — Auto-deteccion UFW/iptables, ver/añadir/eliminar reglas, boton de emergencia, sugerencias',
                        ],
                        'en' => [
                            'Advanced replication — Multiple slaves per master, dual IPs (primary + WireGuard fallback)',
                            'Sync/async replication — Configurable mode per slave in PostgreSQL',
                            'PostgreSQL logical replication — Select specific databases to replicate instead of full cluster',
                            'MySQL GTID — GTID-based replication support, show GTID position in monitor',
                            'Multi-slave monitor — Individual slave status with lag, pending bytes, active connection',
                            'WireGuard VPN — Install, configure wg0, peer CRUD, generate keys and remote config, ping/latency',
                            'Firewall — Auto-detect UFW/iptables, view/add/delete rules, emergency button, suggestions',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.3.0',
                'date' => '2026-03-15',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Backups — Backup/restore por cuenta (archivos + BD MySQL/PostgreSQL), descarga, eliminacion con confirmacion',
                            'Fail2Ban — Ver jails activos, IPs baneadas, desbloquear IPs desde el panel',
                            'Visor de logs — Logs de Caddy, FPM, cuentas y sistema con navegacion por archivo',
                            'Base de datos PostgreSQL — Crear/eliminar BD PostgreSQL por cuenta desde el panel',
                            'Base de datos protegida — La BD del sistema (musedock_panel) se muestra como protegida y no se puede borrar',
                            'Confirmacion con password — Borrar BD y backups requiere password del admin',
                            'Changelog — Pagina de versiones con toggle ES/EN, version clickeable en sidebar',
                        ],
                        'en' => [
                            'Backups — Per-account backup/restore (files + MySQL/PostgreSQL DB), download, deletion with confirmation',
                            'Fail2Ban — View active jails, banned IPs, unblock IPs from the panel',
                            'Log browser — Caddy, FPM, account and system logs with file navigation',
                            'PostgreSQL databases — Create/delete PostgreSQL databases per account from the panel',
                            'Protected database — System DB (musedock_panel) shown as protected and cannot be deleted',
                            'Password confirmation — Deleting databases and backups requires admin password',
                            'Changelog — Version history page with ES/EN toggle, clickable version in sidebar',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.2.0',
                'date' => '2026-03-15',
                'badge' => 'info',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Settings > Servidor — Info del servidor, zona horaria configurable, dominio del panel, selector HTTP/HTTPS',
                            'Settings > PHP — Configuracion global de php.ini por version, lista de extensiones, reinicio FPM',
                            'Settings > SSL/TLS — Certificados activos de Caddy, politicas TLS, info Let\'s Encrypt',
                            'Settings > Seguridad — IPs permitidas en vivo, sesiones activas, info de cookies',
                            'PHP por cuenta — memory_limit, upload_max, post_max, max_execution_time por hosting account',
                            'Gestion de bases de datos MySQL — Crear/eliminar BD MySQL por cuenta, usuarios con prefijo',
                            'Tabla panel_settings — Almacen clave-valor para configuracion del panel',
                            'Tabla servers — Preparacion para clustering (localhost por defecto)',
                            'Campo server_id en hosting_accounts — Preparacion para multi-servidor',
                            'Instalador HTTPS — Caddy como reverse proxy con certificado autofirmado',
                            'Instalador i18n — Seleccion de idioma ES/EN, todos los textos traducidos',
                            'Instalador firewall — Deteccion UFW/iptables, estado del puerto',
                            'Modo verificacion — Health check sin tocar nada',
                            'Desinstalador — bin/uninstall.sh con confirmaciones paso a paso',
                        ],
                        'en' => [
                            'Settings > Server — Server info, configurable timezone, panel domain, HTTP/HTTPS selector',
                            'Settings > PHP — Global php.ini config per version, extension list, FPM restart',
                            'Settings > SSL/TLS — Active Caddy certificates, TLS policies, Let\'s Encrypt info',
                            'Settings > Security — Live allowed IPs, active sessions, cookie info',
                            'Per-account PHP — memory_limit, upload_max, post_max, max_execution_time per hosting account',
                            'MySQL database management — Create/delete MySQL databases per account, prefixed users',
                            'panel_settings table — Key-value store for panel configuration',
                            'servers table — Clustering preparation (localhost default)',
                            'server_id field in hosting_accounts — Multi-server preparation',
                            'Installer HTTPS — Caddy as reverse proxy with self-signed certificate',
                            'Installer i18n — ES/EN language selection, all texts translated',
                            'Installer firewall — UFW/iptables detection, port status',
                            'Verify mode — Health check without changing anything',
                            'Uninstaller — bin/uninstall.sh with step-by-step confirmations',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Login HTTP — Cookie Secure solo en HTTPS (antes bloqueaba login en HTTP)',
                            'HSTS condicional — Header solo en conexiones HTTPS',
                            'Favicon — Eliminado punto verde, solo muestra la M',
                            'Health check — Fallback multi-URL (HTTPS, HTTP interno, HTTP directo)',
                            'Firewall iptables — Deteccion correcta de policy DROP y reglas ACCEPT all',
                        ],
                        'en' => [
                            'HTTP login — Secure cookie flag only on HTTPS (was blocking login on HTTP)',
                            'Conditional HSTS — Header only on HTTPS connections',
                            'Favicon — Removed green dot, shows only the M',
                            'Health check — Multi-URL fallback (HTTPS, internal HTTP, direct HTTP)',
                            'Firewall iptables — Correct policy DROP detection and ACCEPT all rules',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.1.0',
                'date' => '2026-03-14',
                'badge' => 'secondary',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Dashboard — CPU, RAM, disco, cuentas de hosting, info del sistema',
                            'Hosting Accounts — Crear, suspender, activar, eliminar cuentas con usuario Linux, pool FPM y ruta Caddy',
                            'Dominios — Gestion de dominios por cuenta, verificacion DNS, alias',
                            'Clientes — Vincular cuentas de hosting a clientes',
                            'Settings > Servicios — Iniciar/detener/reiniciar Caddy, MySQL, PostgreSQL, PHP-FPM',
                            'Settings > Cron — Crear, editar, eliminar tareas cron por usuario',
                            'Settings > Caddy — Visor de rutas API, politicas TLS, config raw JSON',
                            'Activity Log — Historial completo de acciones del admin',
                            'Perfil — Cambiar usuario, email, contraseña',
                            'Setup wizard — Asistente de primera configuracion',
                            'Tema oscuro — Interfaz moderna con Bootstrap 5',
                            'Seguridad — CSRF, sesiones seguras, prevencion de inyeccion, headers de seguridad',
                            'Instalador automatizado — Deteccion de OS, instalacion de dependencias',
                            'Servicio systemd — Restart automatico',
                        ],
                        'en' => [
                            'Dashboard — CPU, RAM, disk, hosting accounts, system info',
                            'Hosting Accounts — Create, suspend, activate, delete accounts with Linux user, FPM pool, and Caddy route',
                            'Domains — Domain management per account, DNS verification, aliases',
                            'Customers — Link hosting accounts to customers',
                            'Settings > Services — Start/stop/restart Caddy, MySQL, PostgreSQL, PHP-FPM',
                            'Settings > Cron — Create, edit, delete cron jobs per user',
                            'Settings > Caddy — API routes viewer, TLS policies, raw JSON config',
                            'Activity Log — Full admin action history',
                            'Profile — Change username, email, password',
                            'Setup wizard — First-time configuration assistant',
                            'Dark theme — Modern interface with Bootstrap 5',
                            'Security — CSRF, secure sessions, injection prevention, security headers',
                            'Automated installer — OS detection, dependency installation',
                            'Systemd service — Automatic restart',
                        ],
                    ],
                ],
            ],
        ];
    }
}
