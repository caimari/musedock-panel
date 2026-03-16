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
                'version' => '0.5.2',
                'date' => '2026-03-16',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Notificaciones — Nueva pestaña Settings > Notificaciones con Email (SMTP/PHP mail) y Telegram unificados',
                            'Email SMTP avanzado — Selector de cifrado STARTTLS/SSL/Ninguno, test de envio inline con AJAX',
                            'Email destinatario inteligente — Por defecto usa el email del perfil del admin, con override manual opcional',
                            'Firewall editable — Las reglas del firewall ahora se pueden editar (antes solo eliminar), modal de edicion con todos los campos',
                            'Firewall interfaces de red — Muestra todas las interfaces del servidor con IP real (ya no 127.0.0.1)',
                            'Firewall direccion — Columna IN/OUT visible en el listado de reglas',
                            'Databases multi-instancia — Vista de BBDD muestra PostgreSQL Hosting (5432, replicable), PostgreSQL Panel (5433) y MySQL agrupados',
                            'Databases reales — Lista todas las bases de datos reales del sistema, no solo las gestionadas por el panel',
                            'Activity Log filtros — Busqueda por texto, filtro por accion y por admin, paginacion de 50 registros',
                            'Activity Log limpieza — Boton para limpiar logs antiguos (7/30/90/180 dias) y vaciar todo con verificacion de password',
                            'Visor de logs limpieza — Boton para vaciar archivos de log individuales con confirmacion SweetAlert',
                            'Caddy access logs — Los logs de acceso de Caddy por dominio ahora aparecen en el visor de logs',
                        ],
                        'en' => [
                            'Notifications — New Settings > Notifications tab with unified Email (SMTP/PHP mail) and Telegram',
                            'Advanced SMTP email — STARTTLS/SSL/None encryption selector, inline send test with AJAX',
                            'Smart recipient email — Defaults to admin profile email, with optional manual override',
                            'Editable firewall — Firewall rules can now be edited (previously delete-only), edit modal with all fields',
                            'Firewall network interfaces — Shows all server interfaces with real IP (no longer 127.0.0.1)',
                            'Firewall direction — IN/OUT column visible in rule listing',
                            'Multi-instance databases — DB view shows PostgreSQL Hosting (5432, replicable), PostgreSQL Panel (5433) and MySQL grouped',
                            'Real databases — Lists all real system databases, not just panel-managed ones',
                            'Activity Log filters — Text search, action filter, admin filter, 50-record pagination',
                            'Activity Log cleanup — Button to clean old logs (7/30/90/180 days) and clear all with password verification',
                            'Log viewer cleanup — Button to truncate individual log files with SweetAlert confirmation',
                            'Caddy access logs — Per-domain Caddy access logs now appear in the log viewer',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Firewall reglas DENY — Las reglas DENY/REJECT ahora aparecen correctamente en el listado (regex corregido)',
                            'Firewall borde blanco — Eliminado borde blanco de la tabla de interfaces de red',
                            'Email remitente — El From por defecto ahora usa el email del admin (antes usaba panel@hostname)',
                            'Email destinatario — Corregido "No hay email configurado" que ocurria porque la query filtraba por role=admin en vez de superadmin',
                            'PHP mail() error claro — Muestra mensaje especifico cuando sendmail/postfix no esta instalado',
                            'Session key password — Corregido $_SESSION[admin_id] inexistente por $_SESSION[panel_user][id] en verificacion de password (Vaciar todo logs y Eliminar BD)',
                            'Icono busqueda invisible — Añadido color blanco al icono de lupa en Activity Log (era oscuro sobre fondo oscuro)',
                            'Notificaciones migradas — Las config de SMTP/Telegram se movieron de Cluster a la nueva pestaña Notificaciones con migracion automatica de claves',
                        ],
                        'en' => [
                            'Firewall DENY rules — DENY/REJECT rules now appear correctly in the listing (regex fixed)',
                            'Firewall white border — Removed white border from network interfaces table',
                            'Sender email — Default From now uses admin email (was using panel@hostname)',
                            'Recipient email — Fixed "No email configured" caused by query filtering role=admin instead of superadmin',
                            'PHP mail() clear error — Shows specific message when sendmail/postfix is not installed',
                            'Session key password — Fixed non-existent $_SESSION[admin_id] to $_SESSION[panel_user][id] in password verification (Clear all logs and Delete DB)',
                            'Invisible search icon — Added white color to search icon in Activity Log (was dark on dark background)',
                            'Notifications migrated — SMTP/Telegram config moved from Cluster to new Notifications tab with automatic key migration',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.5.1',
                'date' => '2026-03-17',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Replicacion 3 modos — Master manual (solo config, sin credenciales), Slave manual, Auto-cluster',
                            'Usuarios de replicacion — CRUD con password autogenerado y boton copiar, se muestra una sola vez',
                            'IPs autorizadas — Gestion de pg_hba.conf y MySQL GRANT desde el panel, añadir/eliminar IPs de slaves',
                            'Auto-configuracion cluster — El slave pide credenciales al master via API, pg_basebackup automatico',
                            'Dashboard procesos — Click en card CPU/RAM abre modal con top procesos en tiempo real (auto-refresh 3s)',
                            'Dashboard monitor — Tabla de procesos con PID, usuario, CPU%, RAM%, RSS, estado, comando, barras de color',
                        ],
                        'en' => [
                            'Replication 3 modes — Manual master (config only, no credentials), manual slave, auto-cluster',
                            'Replication users — CRUD with auto-generated password and copy button, shown once only',
                            'Authorized IPs — pg_hba.conf and MySQL GRANT management from the panel, add/remove slave IPs',
                            'Cluster auto-configure — Slave requests credentials from master via API, automatic pg_basebackup',
                            'Dashboard processes — Click CPU/RAM card opens real-time top processes modal (auto-refresh 3s)',
                            'Dashboard monitor — Process table with PID, user, CPU%, RAM%, RSS, state, command, color bars',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Replicacion UX — Eliminado formulario confuso de credenciales al activar master',
                            'Rutas replicacion — Corregidas todas las rutas de formularios (404 en version anterior)',
                            'Vista replicacion — Corregido View::csrfField() inexistente por View::csrf()',
                        ],
                        'en' => [
                            'Replication UX — Removed confusing credentials form when activating master',
                            'Replication routes — Fixed all form routes (404 in previous version)',
                            'Replication view — Fixed non-existent View::csrfField() to View::csrf()',
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
                            'Roles de replicacion por motor — repl_pg_role y repl_mysql_role independientes',
                        ],
                        'en' => [
                            'Multi-server cluster — Master/slave architecture between panels with bidirectional API and Bearer token',
                            'Cluster API — /api/cluster/status, heartbeat and action endpoints for inter-node communication',
                            'Hosting sync — cluster_queue for propagating account creation/deletion/suspension across nodes',
                            'Heartbeat and monitoring — Automatic detection of unreachable nodes, email SMTP and Telegram alerts',
                            'Failover — Promote slave to master and demote master to slave from the panel',
                            'Cron worker — bin/cluster-worker.php for queue processing, heartbeats and automatic alerts',
                            'Dual PostgreSQL installer — Dedicated cluster on port 5433, automatic migration from 5432',
                            'Per-engine replication roles — Independent repl_pg_role and repl_mysql_role',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Instalador Caddy HTTPS — tls internal on_demand, resuelto conflicto con nginx en puerto 80',
                            'Instalador verify mode — set +e para que los checks no maten el script, 10 secciones diagnostico',
                            'Instalador crons — Cron cluster-worker cada minuto, pg_dump backup cada hora con retencion 48h',
                        ],
                        'en' => [
                            'Installer Caddy HTTPS — tls internal on_demand, resolved nginx port 80 conflict',
                            'Installer verify mode — set +e so checks don\'t kill the script, 10 diagnostic sections',
                            'Installer crons — Cluster-worker cron every minute, hourly pg_dump backup with 48h retention',
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
