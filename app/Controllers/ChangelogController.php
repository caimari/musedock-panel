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
                'version' => '1.0.23',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Listado de hostings: contador total con badges de activos/suspendidos, buscador en tiempo real (Ctrl+K) y columna de alias/redirecciones/subdominios',
                            'Modal de alias y redirecciones — Al pulsar los badges se muestra un modal con el detalle de cada alias (dominio clicable) y redireccion (codigo 301/302, preservar ruta)',
                        ],
                        'en' => [
                            'Hosting list: total counter with active/suspended badges, real-time search (Ctrl+K) and alias/redirect/subdomain column',
                            'Alias and redirect modal — Clicking badges shows a modal with alias details (clickable domain) and redirects (301/302 code, preserve path)',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Botones de accion en listado de hostings — Rueda de configuracion + ojo para visitar el sitio en nueva pestana',
                        ],
                        'en' => [
                            'Hosting list action buttons — Settings gear + eye to visit site in new tab',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Dashboard: nota de MuseDock CMS sobre token Cloudflare con fondo blanco ilegible en tema oscuro — Cambiado a fondo oscuro semitransparente',
                        ],
                        'en' => [
                            'Dashboard: MuseDock CMS Cloudflare token note had white background unreadable in dark theme — Changed to semi-transparent dark background',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.22',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Editar credenciales de base de datos — Permite cambiar usuario y/o contrasena de bases de datos MySQL y PostgreSQL desde el panel, con generador de contrasenas y sincronizacion al cluster',
                        ],
                        'en' => [
                            'Edit database credentials — Change MySQL/PostgreSQL database user and/or password from the panel, with password generator and cluster sync',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Migracion WordPress: regex wp-config.php mejorado — Ahora soporta comillas simples, dobles y mixtas en define(). Verifica que el reemplazo se aplico correctamente',
                            'Migracion: verificacion de credenciales MySQL — Tras crear usuario/BD, verifica que las credenciales funcionan y reintenta ALTER USER si falla',
                            'Migracion: deteccion de errores MySQL — Se verifica la salida de todos los comandos MySQL (creacion de BD, usuario e importacion de dump) y se informa al usuario si hay errores',
                        ],
                        'en' => [
                            'WordPress migration: improved wp-config.php regex — Now supports single, double and mixed quotes in define(). Verifies replacement was applied correctly',
                            'Migration: MySQL credential verification — After creating user/DB, verifies credentials work and retries ALTER USER if they fail',
                            'Migration: MySQL error detection — All MySQL commands (DB creation, user creation, dump import) are checked for errors and reported to the user',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Migracion WordPress: nombre de BD no actualizado en wp-config.php — El regex no coincidía con formatos como define( \'DB_NAME\', \'valor\' ) con espacios. Corregido con regex flexible',
                            'Migracion WordPress: contrasena MySQL no aplicada — La creacion del usuario no verificaba el resultado ni la conectividad. Ahora se verifica y reintenta',
                        ],
                        'en' => [
                            'WordPress migration: DB name not updated in wp-config.php — Regex did not match formats like define( \'DB_NAME\', \'value\' ) with spaces. Fixed with flexible regex',
                            'WordPress migration: MySQL password not applied — User creation did not verify result or connectivity. Now verified and retried',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.20',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Eliminacion completa de cuentas — Modal de confirmacion con contrasena admin, opciones para eliminar archivos, bases de datos y correo. Aviso de propagacion al cluster',
                            'Promover subdominio a cuenta independiente — Convierte un subdominio en cuenta de hosting con su propio usuario Linux, FPM pool, vhost y ruta Caddy',
                        ],
                        'en' => [
                            'Full account deletion — Confirmation modal with admin password, options to delete files, databases and mail. Cluster propagation warning',
                            'Promote subdomain to independent account — Converts a subdomain into a hosting account with its own Linux user, FPM pool, vhost and Caddy route',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Eliminacion de cuenta sincronizada al cluster — Los nodos slave ahora reciben y ejecutan la eliminacion completa: archivos, BDs, correo, subdominios y rutas Caddy',
                            'Modal de eliminacion con opciones detalladas — Muestra contador de BDs, subdominios, cuentas de correo afectadas e indicador de propagacion al cluster',
                        ],
                        'en' => [
                            'Account deletion synced to cluster — Slave nodes now receive and execute full deletion: files, DBs, mail, subdomains and Caddy routes',
                            'Delete modal with detailed options — Shows DB count, subdomains, mail accounts affected and cluster propagation indicator',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Sincronizacion de eliminacion de hosting al slave — SystemService::deleteAccount() se llamaba con 1 parametro en vez de 3, causando error en el slave. Ahora se pasan username, domain y home_dir correctamente',
                            'Tabla hosting_subdomains no creada — La migracion se registro como ejecutada pero la tabla no existia. Corregido',
                        ],
                        'en' => [
                            'Hosting deletion sync to slave — SystemService::deleteAccount() was called with 1 parameter instead of 3, causing slave error. Now passes username, domain and home_dir correctly',
                            'hosting_subdomains table not created — Migration was recorded as executed but table did not exist. Fixed',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.19',
                'date' => '2026-03-27',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Sistema de subdominios — Crear y eliminar subdominios con carpeta propia (/vhosts/dominio.com/sub.dominio.com/) y ruta Caddy independiente, compartiendo usuario Linux y PHP-FPM de la cuenta padre',
                            'Adoptar cuenta como subdominio — Si existe una cuenta independiente (ej: api.dominio.com), se puede adoptar como subdominio de dominio.com: mueve archivos, elimina usuario/FPM independiente, reasigna BDs y crea ruta Caddy bajo el padre',
                            'Migracion SSH: deteccion de subdominios Plesk — Al probar conexion SSH, detecta automaticamente carpetas tipo subdominio en el vhost remoto, con deteccion de proyecto (Laravel/WordPress) y tamano, tanto con httpdocs como sin el',
                            'Migracion SSH: selector de subdominios y carpetas — Checkboxes para seleccionar subdominios a migrar (marcados por defecto) y carpetas adicionales del vhost. Cada subdominio se migra de forma completa e independiente (archivos + BD + .env/wp-config)',
                            'Sincronizacion cluster de subdominios — Operaciones add_subdomain, remove_subdomain y sync_subdomains para mantener subdominios sincronizados entre master y slave',
                        ],
                        'en' => [
                            'Subdomain system — Create and delete subdomains with own folder (/vhosts/domain.com/sub.domain.com/) and independent Caddy route, sharing parent account Linux user and PHP-FPM pool',
                            'Adopt account as subdomain — If an independent account exists (e.g., api.domain.com), it can be adopted as subdomain of domain.com: moves files, removes independent user/FPM, reassigns DBs and creates Caddy route under parent',
                            'SSH Migration: Plesk subdomain detection — SSH test auto-detects subdomain folders in remote vhost, with project detection (Laravel/WordPress) and size, with or without httpdocs',
                            'SSH Migration: subdomain and folder selector — Checkboxes to select subdomains to migrate (checked by default) and additional vhost folders. Each subdomain is fully migrated independently (files + DB + .env/wp-config)',
                            'Cluster subdomain sync — add_subdomain, remove_subdomain and sync_subdomains operations to keep subdomains in sync between master and slave',
                        ],
                    ],
                    'improved' => [
                        'es' => [],
                        'en' => [],
                    ],
                    'fixed' => [
                        'es' => [],
                        'en' => [],
                    ],
                ],
            ],
            [
                'version' => '1.0.18',
                'date' => '2026-03-27',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [],
                        'en' => [],
                    ],
                    'improved' => [
                        'es' => [],
                        'en' => [],
                    ],
                    'fixed' => [
                        'es' => [
                            'Sobreescritura de backups de BD en nodo remoto — Database::execute() no existe, cambiado a Database::update()',
                            'Transferencia de backups de BD (individual y masiva) — El token CSRF se enviaba como _token en vez de _csrf_token, causando rechazo 403 silencioso. Las transferencias ahora funcionan correctamente',
                            'Recarga de pagina tras actualizar panel — El polling verificaba /api/status que requiere sesion activa; si la sesion expira al reiniciar, nunca se recargaba. Ahora verifica la URL principal que responde con cualquier codigo HTTP',
                        ],
                        'en' => [
                            'DB backup overwrite on remote node — Database::execute() does not exist, changed to Database::update()',
                            'DB backup transfer (single and bulk) — CSRF token was sent as _token instead of _csrf_token, causing silent 403 rejection. Transfers now work correctly',
                            'Page reload after panel update — Polling checked /api/status which requires active session; if session expires on restart, panel returned 401 and never reloaded. Now checks main URL which responds with any HTTP code',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.17',
                'date' => '2026-03-26',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [],
                        'en' => [],
                    ],
                    'improved' => [
                        'es' => [
                            'Actualizacion del panel — El polling de progreso ahora se inicia inmediatamente via AJAX sin depender del reload de la pagina, solucionando el problema en slaves donde la pagina no se recargaba tras la actualizacion',
                            'Resultados de transferencia masiva — Muestra detalle de errores individuales y mensaje informativo cuando no hay transferencias',
                        ],
                        'en' => [
                            'Panel update — Progress polling now starts immediately via AJAX without depending on page reload, fixing the issue on slaves where the page did not reload after update',
                            'Bulk transfer results — Shows individual error details and informative message when no transfers occur',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Transferencia de backups de BD a nodos remotos fallaba silenciosamente — Faltaba el import de Database en ClusterApiController, causando error 500 en list-db-backups y receive-db-backup',
                        ],
                        'en' => [
                            'DB backup transfer to remote nodes was silently failing — Missing Database import in ClusterApiController caused error 500 on list-db-backups and receive-db-backup',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.16',
                'date' => '2026-03-26',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Transferencia de backups de BD a nodos remotos — Boton individual y transferencia masiva con seleccion multiple, deteccion de duplicados y opcion de sobreescribir',
                            'Seleccion masiva en backups de BD — Checkboxes, seleccionar todos, barra de acciones masivas (transferir/eliminar)',
                            'Eliminacion masiva de backups de BD via AJAX',
                        ],
                        'en' => [
                            'DB backup transfer to remote nodes — Individual button and bulk transfer with multi-select, duplicate detection, and overwrite option',
                            'Bulk selection for DB backups — Checkboxes, select all, bulk action bar (transfer/delete)',
                            'Bulk delete DB backups via AJAX',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Confirmacion modal al eliminar servidores failover y cuentas Cloudflare en configuracion cluster',
                            'Persistencia de tabs en cluster — Al guardar configuracion, la pagina vuelve al tab correspondiente (failover, nodos, archivos, configuracion, cola)',
                            'Verificacion de contraseña de admin al eliminar nodos del cluster',
                        ],
                        'en' => [
                            'Confirmation modal when deleting failover servers and Cloudflare accounts in cluster settings',
                            'Tab persistence in cluster — After saving, the page returns to the corresponding tab (failover, nodes, files, config, queue)',
                            'Admin password verification when removing cluster nodes',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Backups de BD MySQL fallaban — Se usaba el cliente mysql en vez de mysqldump para generar dumps',
                            'Redirect de tabs en cluster apuntaba a #tab-failover que no coincidia con el hash del JS (#failover)',
                        ],
                        'en' => [
                            'MySQL DB backups were failing — mysql client was used instead of mysqldump for generating dumps',
                            'Cluster tab redirect pointed to #tab-failover which did not match the JS tab hash (#failover)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.15',
                'date' => '2026-03-26',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Exclusiones integradas en rsync — Los directorios de herramientas AI/IDE (.claude/, .codex/, .cline/, .vscode-server/, .git/) se excluyen automaticamente de la sincronizacion periodica rsync y HTTPS, igual que en lsyncd',
                        ],
                        'en' => [
                            'Built-in rsync exclusions — AI/IDE tool directories (.claude/, .codex/, .cline/, .vscode-server/, .git/) are now automatically excluded from periodic rsync and HTTPS sync, matching lsyncd behavior',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Exclusiones lsyncd ampliadas — Añadidos .codex/ y .cline/ a las exclusiones por defecto de lsyncd',
                        ],
                        'en' => [
                            'Expanded lsyncd exclusions — Added .codex/ and .cline/ to lsyncd default exclusions',
                        ],
                    ],
                    'fixed' => [
                        'es' => [],
                        'en' => [],
                    ],
                ],
            ],
            [
                'version' => '1.0.13',
                'date' => '2026-03-26',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Vista de edicion en Slave — El slave puede acceder a /accounts/{id}/edit para ver los ajustes actuales (PHP, cuota, document root) pero con todos los formularios deshabilitados. Banner informativo y sin opciones de renombrar/contraseña',
                        ],
                        'en' => [
                            'Edit view on Slave — Slave can access /accounts/{id}/edit to view current settings (PHP, quota, document root) but with all forms disabled. Info banner and no rename/password options',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Recarga post-actualizacion — Mejorado el polling del actualizador: detecta restart del panel, muestra "Reiniciando panel...", reintenta reload hasta que el panel responda, y muestra confirmacion verde al completar',
                        ],
                        'en' => [
                            'Post-update reload — Improved updater polling: detects panel restart, shows "Restarting panel...", retries reload until panel responds, and shows green confirmation on completion',
                        ],
                    ],
                    'fixed' => [
                        'es' => [],
                        'en' => [],
                    ],
                ],
            ],
            [
                'version' => '1.0.12',
                'date' => '2026-03-26',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Modo solo lectura en Slave — La vista de hosting en servidores Slave oculta botones de edicion, eliminacion, alias/redirecciones y acciones. Muestra banner "Servidor Slave — Modo solo lectura"',
                            'Banner Slave en Settings — Todas las paginas de Settings muestran un aviso cuando el servidor es Slave indicando que los ajustes son de solo consulta',
                        ],
                        'en' => [
                            'Read-only mode on Slave — Hosting detail view on Slave servers hides edit, delete, alias/redirect and action buttons. Shows "Slave Server — Read-only mode" banner',
                            'Slave banner in Settings — All Settings pages show a notice when server is Slave indicating settings are read-only',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Cuota ilimitada en vista de hosting — Cuando disk_quota_mb es 0 (ilimitado), muestra el uso real con simbolo infinito en vez de barra de progreso vacia y "0 MB"',
                        ],
                        'en' => [
                            'Unlimited quota in hosting detail — When disk_quota_mb is 0 (unlimited), shows actual usage with infinity symbol instead of empty progress bar and "0 MB"',
                        ],
                    ],
                    'fixed' => [
                        'es' => [],
                        'en' => [],
                    ],
                ],
            ],
            [
                'version' => '1.0.11',
                'date' => '2026-03-26',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Auto-reconciliacion de bases de datos via heartbeat — El master envia un hash de las asociaciones de BD en cada heartbeat. Si el slave detecta diferencias, responde con db_hash_mismatch y el master encola automaticamente la sincronizacion completa de asociaciones de BD. No requiere accion manual',
                        ],
                        'en' => [
                            'Auto-reconciliation of databases via heartbeat — Master sends a hash of DB associations in each heartbeat. If slave detects differences, it responds with db_hash_mismatch and master auto-enqueues full DB association sync. No manual action required',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Texto de Sincronizacion Completa mejorado — Ahora incluye paso 3 (Bases de datos: dump + restore) y explicacion detallada de cuando se usa replicacion streaming vs copia periodica. Paso 1 tambien menciona la sincronizacion de alias, redirecciones y bases de datos',
                        ],
                        'en' => [
                            'Improved Full Sync description — Now includes step 3 (Databases: dump + restore) and detailed explanation of when streaming replication vs periodic copy is used. Step 1 also mentions sync of aliases, redirects and databases',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Fecha en formato espanol en /databases — La fecha de ultima sincronizacion de BD en el slave ahora se muestra en formato dd/mm/aaaa en vez de aaaa-mm-dd',
                            'Codigos ANSI en actualizador web — Eliminados caracteres de escape de color ([0;32m, etc.) en migrate.php y en la lectura del log. migrate.php ahora detecta si es terminal; UpdateService limpia ANSI como fallback',
                            'Recarga automatica tras actualizar — El polling del actualizador ahora reintenta hasta 10 veces consecutivas si el panel se reinicia, en vez de intentar recargar una sola vez y quedarse colgado',
                        ],
                        'en' => [
                            'Spanish date format on /databases — The last DB sync date on slave now shows in dd/mm/yyyy format instead of yyyy-mm-dd',
                            'ANSI codes in web updater — Removed color escape characters ([0;32m, etc.) in migrate.php and log reading. migrate.php now detects terminal; UpdateService strips ANSI as fallback',
                            'Auto-reload after update — Update poller now retries up to 10 consecutive failures if panel restarts, instead of trying to reload once and getting stuck',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.10',
                'date' => '2026-03-26',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Modal de instrucciones Cloudflare — Boton "Instrucciones" en Cuentas Cloudflare con guia paso a paso para crear API Token, explicacion de multiples cuentas y permisos minimos',
                            'Encriptacion de tokens Cloudflare — Los API tokens de Cloudflare ahora se almacenan encriptados con AES-256-CBC en la base de datos. Compatible con tokens legacy en texto plano',
                            'Sync de bases de datos al cluster — Crear, asociar o eliminar bases de datos en el master ahora sincroniza el registro al slave. El Sync Todo tambien incluye todas las bases de datos asociadas a cada hosting (prioridad 7, despues de hostings y aliases)',
                        ],
                        'en' => [
                            'Cloudflare instructions modal — "Instructions" button in Cloudflare Accounts with step-by-step guide to create API Token, multi-account explanation and minimum permissions',
                            'Cloudflare token encryption — Cloudflare API tokens are now stored encrypted with AES-256-CBC in the database. Backward compatible with legacy plain-text tokens',
                            'Database sync to cluster — Creating, associating or deleting databases on master now syncs the registration to slaves. Sync Todo also includes all databases associated to each hosting (priority 7, after hostings and aliases)',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Rendimiento en /accounts — El calculo de uso de disco ahora se hace en una sola llamada du -sm para todos los hostings en vez de una por hosting, reduciendo significativamente el tiempo de carga',
                            'Cuota ilimitada (0MB) — Cuando un hosting tiene cuota 0 (ilimitada) ahora muestra el uso real con simbolo de infinito en vez de una barra de progreso vacia',
                            'Aviso PHP FPM legible — El mensaje "No se encontro el archivo de pool FPM" ahora tiene texto gris claro sobre fondo sutil en vez de texto amarillo sobre fondo crema que era ilegible',
                            'Link a Cloudflare Accounts — El estado vacio del DNS Manager enlaza directamente al tab Failover (#failover) en vez de la raiz de cluster',
                        ],
                        'en' => [
                            'Performance on /accounts — Disk usage calculation now uses a single du -sm call for all hostings instead of one per hosting, significantly reducing page load time',
                            'Unlimited quota (0MB) — When a hosting has quota 0 (unlimited), now shows actual usage with infinity symbol instead of an empty progress bar',
                            'Readable PHP FPM warning — The "Pool FPM file not found" message now has light gray text on subtle background instead of yellow text on cream background which was unreadable',
                            'Link to Cloudflare Accounts — DNS Manager empty state now links directly to the Failover tab (#failover) instead of cluster root',
                        ],
                    ],
                    'fixed' => [
                        'es' => [],
                        'en' => [],
                    ],
                ],
            ],
            [
                'version' => '1.0.9',
                'date' => '2026-03-25',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Cloudflare DNS Manager — Nueva seccion en Settings para gestionar registros DNS de Cloudflare directamente desde el panel. Crear, editar, eliminar registros (A, AAAA, CNAME, MX, TXT, SRV) y activar/desactivar el proxy naranja (CDN/DDoS) con un click',
                            'Deteccion de Cloudflare Proxy en DNS — Los checks DNS ahora detectan si un dominio pasa por Cloudflare Proxy y muestran un badge naranja especifico en vez del warning generico amarillo. Aplica en /domains y en la vista de cada hosting',
                            'Domain Aliases & Redirects en /domains — Los alias y redirecciones ahora aparecen en una tabla dedicada en la pagina de dominios con tipo, DNS status, cuenta destino y fecha de creacion',
                        ],
                        'en' => [
                            'Cloudflare DNS Manager — New section in Settings to manage Cloudflare DNS records directly from the panel. Create, edit, delete records (A, AAAA, CNAME, MX, TXT, SRV) and toggle the orange proxy cloud (CDN/DDoS) with one click',
                            'Cloudflare Proxy detection in DNS — DNS checks now detect if a domain goes through Cloudflare Proxy and show a specific orange badge instead of the generic yellow warning. Applies in /domains and in each hosting detail view',
                            'Domain Aliases & Redirects on /domains — Aliases and redirects now appear in a dedicated table on the domains page with type, DNS status, target account and creation date',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'SSL status con Cloudflare — En la vista de hosting, si el dominio esta detras de Cloudflare Proxy se muestra icono naranja de SSL indicando que el certificado lo proporciona CF. Al desactivar el proxy, Caddy genera certificado automaticamente',
                            'Info box contextual — Nuevo mensaje informativo naranja cuando el dominio usa Cloudflare Proxy, explicando que SSL lo cubre CF y que Caddy genera cert al desactivar proxy',
                            'Leyenda DNS mejorada — La leyenda de la pagina /domains ahora incluye explicacion del badge Cloudflare Proxy y la diferencia entre Alias y Redirect',
                        ],
                        'en' => [
                            'SSL status with Cloudflare — In hosting detail view, if domain is behind Cloudflare Proxy, shows orange SSL icon indicating certificate is provided by CF. When proxy is disabled, Caddy auto-generates certificate',
                            'Contextual info box — New orange info message when domain uses Cloudflare Proxy, explaining that SSL is covered by CF and Caddy generates cert when proxy is disabled',
                            'Improved DNS legend — The /domains page legend now includes Cloudflare Proxy badge explanation and the difference between Alias and Redirect',
                        ],
                    ],
                    'fixed' => [
                        'es' => [],
                        'en' => [],
                    ],
                ],
            ],
            [
                'version' => '1.0.8',
                'date' => '2026-03-25',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Sync de renombrado de usuario al cluster — Renombrar un usuario en el master ahora se propaga automáticamente a los slaves (usuario Linux, PHP-FPM, Caddy, bases de datos)',
                            'Sync de contraseña al cluster — Cambiar la contraseña de un hosting en el master sincroniza el hash al slave (nunca envía texto plano)',
                            'Nodos sincronizados en Dashboard — Cuando no hay alertas, se muestra un banner informativo azul debajo de System Info con los nodos online y su último contacto. Link directo al tab Nodos del cluster',
                            'Limpiar fallidos en Cola — Nuevo botón "Limpiar Fallidos" en la pestaña Cola del cluster para eliminar elementos con status failed, además del existente para completados',
                        ],
                        'en' => [
                            'User rename sync to cluster — Renaming a user on master now propagates to slaves automatically (Linux user, PHP-FPM, Caddy, databases)',
                            'Password sync to cluster — Changing a hosting password on master syncs the hash to the slave (never sends plaintext)',
                            'Synced nodes on Dashboard — When no alerts, an informational blue banner below System Info shows online nodes and their last contact. Direct link to cluster Nodes tab',
                            'Clean failed items in Queue — New "Clean Failed" button in cluster Queue tab to delete items with failed status, in addition to the existing one for completed items',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'CSRF en peticiones AJAX — El middleware ahora detecta peticiones fetch/AJAX y devuelve JSON con error 401/403 en vez de redirigir a HTML, evitando el error "Unexpected token < is not valid JSON"',
                            'Token CSRF en Dashboard — Corregido bug: el fallback usaba $_SESSION[\'csrf_token\'] (sin underscore) en vez de $_SESSION[\'_csrf_token\']. Añadido input hidden CSRF al dashboard',
                            'Notas aclaratorias en Monitor — CPU threshold: indica que es la media de todos los cores. Network threshold: aclara que es Megabits/s con ejemplo (80 Mbps ≈ 10 MB/s)',
                            'Texto de Sync Todo mejorado — Explicación clara: la replicación es automática, Sync Todo re-provisiona hostings existentes (útil al añadir nodo nuevo). Link directo a pestaña Archivos',
                            'Texto de Sincronización Completa — Detalle de los 3 pasos (Hostings API, Archivos rsync SSH, SSL rsync SSH) con nota de idempotencia (seguro repetir)',
                            'Espaciado de botones en Nodos — Botones de acciones con gap-2 y flex-wrap para mejor legibilidad',
                            'Links internos entre pestañas — Los href="#archivos", "#nodos", etc. dentro del cluster ahora activan el tab correspondiente via Bootstrap',
                            'Redirección a tab Cola — Limpiar cola redirige a #cola en vez de la raíz del cluster',
                        ],
                        'en' => [
                            'CSRF on AJAX requests — Middleware now detects fetch/AJAX requests and returns JSON with 401/403 error instead of redirecting to HTML, fixing the "Unexpected token < is not valid JSON" error',
                            'CSRF token on Dashboard — Fixed bug: fallback used $_SESSION[\'csrf_token\'] (no underscore) instead of $_SESSION[\'_csrf_token\']. Added hidden CSRF input to dashboard',
                            'Clarification notes in Monitor — CPU threshold: indicates it\'s the average across all cores. Network threshold: clarifies it\'s Megabits/s with example (80 Mbps ≈ 10 MB/s)',
                            'Improved Sync Todo text — Clear explanation: replication is automatic, Sync Todo re-provisions existing hostings (useful when adding new node). Direct link to Files tab',
                            'Full Sync text improved — Detail of 3 steps (Hostings API, Files rsync SSH, SSL rsync SSH) with idempotency note (safe to repeat)',
                            'Button spacing in Nodes — Action buttons with gap-2 and flex-wrap for better readability',
                            'Internal tab links — href="#archivos", "#nodos", etc. inside cluster now activate the corresponding tab via Bootstrap',
                            'Redirect to Queue tab — Clean queue redirects to #cola instead of cluster root',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Botón Reactivar nodo en Dashboard — Devolvía "Unexpected token < is not valid JSON" porque el CSRF fallaba y el middleware redirigía a HTML. Corregido con detección AJAX + token CSRF correcto',
                        ],
                        'en' => [
                            'Reactivate node button on Dashboard — Returned "Unexpected token < is not valid JSON" because CSRF failed and middleware redirected to HTML. Fixed with AJAX detection + correct CSRF token',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.7',
                'date' => '2026-03-24',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Proteccion de restauracion con contrasena — Restaurar un backup ahora requiere contrasena de administrador, igual que eliminar',
                            'Deteccion de nodo en replicacion — Al restaurar un backup, el sistema detecta si la base de datos de hosting (PostgreSQL) esta en modo recovery (slave). Si es asi, bloquea la restauracion de bases de datos y solo permite restaurar archivos',
                            'Preflight de transferencia — Antes de transferir un backup a un nodo remoto, se valida capacidad de disco, espacio en /tmp y limites PHP (upload_max_filesize, post_max_size) del nodo destino',
                        ],
                        'en' => [
                            'Restore password protection — Restoring a backup now requires admin password confirmation, same as deleting',
                            'Replication node detection — When restoring a backup, the system detects if the hosting database (PostgreSQL) is in recovery mode (slave). If so, it blocks database restoration and only allows file restoration',
                            'Transfer preflight check — Before transferring a backup to a remote node, validates disk capacity, /tmp space and PHP limits (upload_max_filesize, post_max_size) on the target node',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Transferencia sin recompresion — El empaquetado para transferencia ahora usa tar sin gzip (tar cf en vez de tar czf), evitando recomprimir archivos ya comprimidos. Mas rapido y mismo resultado',
                            'Nombre descriptivo en descargas — Los archivos descargados ahora incluyen el nombre de la cuenta y fecha (ej: picaliascom_2026-03-24_files.tar.gz) en vez del generico files.tar.gz',
                            'Errores de transferencia mas claros — Corregido error "HTTP 422" generico: ahora muestra el mensaje real del nodo. Si el nodo remoto no soporta preflight (version antigua), la transferencia continua sin validacion previa en vez de abortar',
                            'Compatibilidad con paneles antiguos — callNodeDirect ahora busca tanto el campo error como message en respuestas de la API de cluster, evitando errores genericos "HTTP 422"',
                        ],
                        'en' => [
                            'Transfer without recompression — Transfer packaging now uses tar without gzip (tar cf instead of tar czf), avoiding recompressing already compressed files. Faster with same result',
                            'Descriptive download names — Downloaded files now include account name and date (e.g. picaliascom_2026-03-24_files.tar.gz) instead of generic files.tar.gz',
                            'Clearer transfer errors — Fixed generic "HTTP 422" error: now shows the actual node message. If remote node does not support preflight (old version), transfer continues without pre-validation instead of aborting',
                            'Backwards compatibility with older panels — callNodeDirect now checks both error and message fields in cluster API responses, avoiding generic "HTTP 422" errors',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.6',
                'date' => '2026-03-24',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Backups de bases de datos — Backup, restaurar y descargar bases de datos individuales o todas a la vez desde la pagina de Databases. Registro en tabla del panel + filesystem con reconciliacion automatica',
                            'Backups de hosting completos — Backup de cuentas de hosting con archivos (directorio completo o solo httpdocs/) y bases de datos. Worker en background con progreso en tiempo real via modal',
                            'Persistencia de progreso — Si el admin recarga la pagina durante un backup, el modal de progreso se recupera automaticamente leyendo el estado del servidor',
                            'Backups automaticos rotatorios — Cron configurable (diario/semanal, hora, alcance) que respalda TODAS las cuentas activas. Politica de retencion: N diarios + M semanales por cuenta',
                            'Exclusiones de backup — Lista configurable de directorios/archivos a excluir (node_modules, .git, *.log, etc.). Exclusiones por defecto + personalizadas',
                            'Backups remotos — Transferir backups locales a nodos remotos del cluster via API HTTPS (multipart upload). Listar, recuperar y eliminar backups en nodos remotos',
                            'Barra de progreso en transferencias — Worker en background con CURL progress callback que reporta porcentaje real de upload. El frontend hace polling y muestra progreso en tiempo real',
                            'Copia remota automatica — Opcion en auto-backups para enviar automaticamente cada backup al nodo remoto seleccionado tras completar el backup local',
                        ],
                        'en' => [
                            'Database backups — Backup, restore and download individual databases or all at once from the Databases page. Panel DB table + filesystem tracking with automatic reconciliation',
                            'Full hosting backups — Backup hosting accounts with files (full directory or httpdocs/ only) and databases. Background worker with real-time progress via modal',
                            'Progress persistence — If admin reloads the page during a backup, the progress modal automatically recovers by reading server state',
                            'Automatic rotary backups — Configurable cron (daily/weekly, time, scope) that backs up ALL active accounts. Retention policy: N daily + M weekly per account',
                            'Backup exclusions — Configurable list of directories/files to exclude (node_modules, .git, *.log, etc.). Default + custom exclusions',
                            'Remote backups — Transfer local backups to remote cluster nodes via HTTPS API (multipart upload). List, recover and delete backups on remote nodes',
                            'Transfer progress bar — Background worker with CURL progress callback reporting real upload percentage. Frontend polls and shows real-time progress',
                            'Automatic remote copy — Option in auto-backups to automatically send each backup to the selected remote node after completing the local backup',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Alertas dark-theme — Todas las alertas de exito, error y advertencia en modales usan estilos compatibles con el tema oscuro (fondo semitransparente + texto brillante) en vez de los alert-success/danger de Bootstrap',
                            'Icono de backup — Cambiado de bi-download a bi-archive para mejor representacion visual',
                            'Backup All incluye musedock_panel — El backup masivo de bases de datos ahora incluye la base del panel (puerto 5433), no solo las de hosting',
                            'Formato de fechas — Fechas en formato espanol (dd/mm/yyyy) en toda la UI de backups',
                        ],
                        'en' => [
                            'Dark-theme alerts — All success, error and warning alerts in modals now use dark-theme compatible styles (semi-transparent background + bright text) instead of Bootstrap alert-success/danger',
                            'Backup icon — Changed from bi-download to bi-archive for better visual representation',
                            'Backup All includes musedock_panel — Mass database backup now includes the panel database (port 5433), not just hosting databases',
                            'Date format — Spanish format dates (dd/mm/yyyy) across all backup UI',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.5',
                'date' => '2026-03-24',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Proxy Routes (caddy-l4) — Nuevo apartado en Settings para proxy permanente SNI. Permite enrutar dominios a servidores internos (NAT/VPN/IP privada) a través de un servidor con IP pública usando TCP passthrough. Los certificados SSL los gestiona el servidor destino',
                            'Proxy HTTP puerto 80 — caddy-l4 proxea también el puerto 80 (match por HTTP Host header), permitiendo que Let\'s Encrypt HTTP-01 challenge funcione en el servidor destino sin abrir puertos a internet',
                            'Test de conectividad TCP — Botón para probar la conexión al servidor destino (IP:puerto) antes de crear la ruta proxy, con tiempo de respuesta en ms',
                            'Preview config caddy-l4 — Visualización en tiempo real de las rutas proxy generadas en formato JSON',
                            'Licencia Free/Pro para Proxy Routes — Tier Free permite 1 ruta proxy, Pro ilimitadas. Integrado con LicenseService',
                            'Election / Cadena de sucesión (Fase 4) — Campo failover_priority por servidor. Cuando hay múltiples slaves activos, solo promueve el de mayor prioridad. Evita split-brain con múltiples promociones simultáneas',
                            'Rol REPLICA (pasivo) — Nuevo rol para réplicas que solo replican DB sin promover nunca. Se reconfiguran automáticamente cuando cambia el master',
                            'Reconfigure-replication broadcast — Cuando un slave promueve a master, notifica a todos los nodos (incluidos replicas pasivos) para reapuntar su replicación PostgreSQL/MySQL al nuevo master. Con enqueue como fallback si un nodo no responde',
                            'Interface self-check (ethernet failover) — Detección automática de caída de interfaz primaria (ONO) y switch a backup (Orange NAT) leyendo /sys/class/net/{iface}/operstate',
                            'Camino A y Camino B — Dos rutas de failover ante caída de interfaz: A) master accesible → notifica al master que cambie DNS; B) master inalcanzable → el slave cambia DNS autónomamente + activa caddy-l4',
                            'Reconciliación master-slave — Flag failover_dns_changed_locally evita que el master sobreescriba cambios DNS autónomos del slave. El master consulta query-local-state al reconectar',
                            'Autodetección de interfaces de red — UI con dropdown de interfaces detectadas, autocompletado de IP, y botón Test para verificar estado',
                            'Aviso de failover no configurado — Warning prominente cuando la pestaña Failover no tiene la configuración mínima (servidores, Cloudflare, etc.)',
                            'Remote Domain Sources — Los servidores exponen /api/domains (autenticado con Bearer token). Otros servidores descubren dominios remotos automáticamente para caddy-l4, con caché local y textarea manual como fallback',
                            'Test de remote sources — Botón para probar la conectividad a cada servidor remoto y verificar cuántos dominios expone',
                            'Detección e instalación de caddy-l4 — El panel detecta si caddy-l4 está instalado y ofrece botón de instalación desde la UI',
                            'LicenseService — Sistema de gating de features para modelo freemium. Free: 1 slave + 1 proxy route. Pro: multi-slave, election, chain failover, proxy routes ilimitadas. Actualmente todo desbloqueado (modo desarrollo)',
                        ],
                        'en' => [
                            'Proxy Routes (caddy-l4) — New Settings section for permanent SNI proxy. Routes domains to internal servers (NAT/VPN/private IP) through a server with public IP using TCP passthrough. SSL certificates are managed by the destination server',
                            'HTTP port 80 proxy — caddy-l4 also proxies port 80 (HTTP Host header match), allowing Let\'s Encrypt HTTP-01 challenge to work on the destination server without opening ports to the internet',
                            'TCP connectivity test — Button to test connection to destination server (IP:port) before creating the proxy route, showing response time in ms',
                            'caddy-l4 config preview — Real-time visualization of generated proxy routes in JSON format',
                            'Free/Pro licensing for Proxy Routes — Free tier allows 1 proxy route, Pro unlimited. Integrated with LicenseService',
                            'Election / Chain of succession (Phase 4) — failover_priority field per server. When multiple active slaves exist, only the highest-priority one promotes. Prevents split-brain with simultaneous promotions',
                            'REPLICA role (passive) — New role for replicas that only replicate DB without ever promoting. Auto-reconfigures when master changes',
                            'Reconfigure-replication broadcast — When a slave promotes to master, it notifies all nodes (including passive replicas) to repoint their PostgreSQL/MySQL replication to the new master. With enqueue fallback if a node is unreachable',
                            'Interface self-check (ethernet failover) — Automatic detection of primary interface (ONO) failure and switch to backup (Orange NAT) by reading /sys/class/net/{iface}/operstate',
                            'Path A and Path B — Two failover paths on interface failure: A) master reachable → notifies master to change DNS; B) master unreachable → slave changes DNS autonomously + activates caddy-l4',
                            'Master-slave reconciliation — failover_dns_changed_locally flag prevents master from overwriting autonomous DNS changes by slave. Master queries query-local-state on reconnect',
                            'Network interface autodetection — UI with detected interface dropdown, IP autocomplete, and Test button to verify status',
                            'Failover not configured warning — Prominent warning when Failover tab lacks minimum configuration (servers, Cloudflare, etc.)',
                            'Remote Domain Sources — Servers expose /api/domains (authenticated with Bearer token). Other servers auto-discover remote domains for caddy-l4, with local cache and manual textarea as fallback',
                            'Remote sources test — Button to test connectivity to each remote server and verify how many domains it exposes',
                            'caddy-l4 detection and installation — Panel detects if caddy-l4 is installed and offers one-click installation from the UI',
                            'LicenseService — Feature gating system for freemium model. Free: 1 slave + 1 proxy route. Pro: multi-slave, election, chain failover, unlimited proxy routes. Currently all unlocked (development mode)',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'caddy-l4 config unificada — generateCaddyL4Config() ahora genera rutas en 3 niveles de prioridad: 1) Proxy permanente (siempre activo), 2) Failover emergencia (solo en caída), 3) Fallback local',
                            'Puerto 80 en caddy-l4 — El bloque http_redirect ahora incluye rutas por HTTP Host para dominios proxy además del fallback a localhost:8080',
                            'Failover worker — Paso 0 de interface self-check antes de health checks. Election con shouldPromote() que recopila IPs caídas y solo promueve si es el candidato de mayor prioridad',
                            'Cluster worker — Reconciliación automática: cuando el master reconecta con un slave que estuvo offline, consulta su estado local para detectar cambios DNS autónomos',
                            'UI de servidores failover — Nueva columna de prioridad, rol replica en dropdown, explicación de roles y prioridades',
                            'Sección caddy-l4 mejorada — Detección automática de binario, warning cuando no está instalado, botón de instalación',
                            'Sección de dominios remotos — Tabla de servidores remotos con nombre/URL/token, botón probar conexión, dominios manuales como fallback',
                        ],
                        'en' => [
                            'Unified caddy-l4 config — generateCaddyL4Config() now generates routes in 3 priority levels: 1) Permanent proxy (always active), 2) Emergency failover (only on failure), 3) Local fallback',
                            'Port 80 in caddy-l4 — http_redirect block now includes HTTP Host routes for proxy domains in addition to localhost:8080 fallback',
                            'Failover worker — Step 0 interface self-check before health checks. Election with shouldPromote() that collects down IPs and only promotes if highest-priority candidate',
                            'Cluster worker — Automatic reconciliation: when master reconnects to a slave that was offline, queries its local state to detect autonomous DNS changes',
                            'Failover servers UI — New priority column, replica role in dropdown, role and priority explanation',
                            'Improved caddy-l4 section — Automatic binary detection, warning when not installed, installation button',
                            'Remote domains section — Remote server table with name/URL/token, test connection button, manual domains as fallback',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.4',
                'date' => '2026-03-24',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Endpoint /api/health — Health check profundo que verifica Caddy, PostgreSQL (5432+5433 con query real), MySQL (3306), disco y carga del sistema; devuelve HTTP 200 o 503 con severity (critical/warning/ok)',
                            'Failover Worker (cron cada minuto) — Health checks automáticos con contadores consecutivos: N fallos critical → DOWN, M checks OK → RECOVERED. Los warnings notifican pero NO disparan failover',
                            'Modo semiauto — Detecta caídas y notifica al admin sin ejecutar transiciones automáticas; el admin decide cuándo actuar',
                            'Modo auto — Detecta caídas, ejecuta transición DNS + auto-promote del slave a master cuando el master original cae',
                            'Failback inteligente con resync por pasos — Estados granulares: pending → syncing_db → syncing_files → syncing_certs → completed. Si un paso falla (failed:syncing_files), el failback se BLOQUEA hasta que el admin resuelva',
                            'Resync obligatorio en cualquier modo — El resync de datos se ejecuta automáticamente al detectar recuperación, independiente del modo (manual/semi/auto). El admin solo decide cuándo cambiar DNS',
                            'Monitorización MySQL — Nuevo check para MySQL (3306) con query real, severidad configurable por el admin',
                            'Umbrales de severidad configurables — Disco (% critical/warning), carga (multiplicador × cores), y severidad por servicio (Caddy, PG Hosting, PG Panel, MySQL) ajustables desde la UI',
                        ],
                        'en' => [
                            '/api/health endpoint — Deep health check verifying Caddy, PostgreSQL (5432+5433 with real query), MySQL (3306), disk and system load; returns HTTP 200 or 503 with severity (critical/warning/ok)',
                            'Failover Worker (cron every minute) — Automatic health checks with consecutive counters: N critical failures → DOWN, M OK checks → RECOVERED. Warnings notify but do NOT trigger failover',
                            'Semiauto mode — Detects failures and notifies admin without executing automatic transitions; admin decides when to act',
                            'Auto mode — Detects failures, executes DNS transition + auto-promote slave to master when original master goes down',
                            'Intelligent failback with step-by-step resync — Granular states: pending → syncing_db → syncing_files → syncing_certs → completed. If any step fails (failed:syncing_files), failback is BLOCKED until admin resolves',
                            'Mandatory resync in any mode — Data resync runs automatically on recovery detection, regardless of mode (manual/semi/auto). Admin only decides when to switch DNS',
                            'MySQL monitoring — New check for MySQL (3306) with real query, admin-configurable severity',
                            'Configurable severity thresholds — Disk (critical/warning %), load (multiplier × cores), and per-service severity (Caddy, PG Hosting, PG Panel, MySQL) adjustable from the UI',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Health checks con severity — Distingue entre critical (dispara failover), warning (notifica) e info (solo log). Cada servicio tiene su severidad configurable',
                            'Integración Failover ↔ Cluster — El failover ISP y el cluster master/slave ahora están conectados: failover cambia DNS + promote automático, failback hace resync + demote',
                            'UI de umbrales — Nueva sección en Settings > Cluster > Failover con controles para ajustar todos los umbrales de health checks y severidad por servicio',
                            'Cron en instalador — failover-worker.php se instala automáticamente en nuevas instalaciones, updates y repairs via install.sh',
                        ],
                        'en' => [
                            'Health checks with severity — Distinguishes between critical (triggers failover), warning (notifies) and info (log only). Each service has configurable severity',
                            'Failover ↔ Cluster integration — ISP failover and master/slave cluster are now connected: failover changes DNS + auto-promote, failback does resync + demote',
                            'Thresholds UI — New section in Settings > Cluster > Failover with controls to adjust all health check thresholds and per-service severity',
                            'Cron in installer — failover-worker.php is automatically installed on new installs, updates and repairs via install.sh',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.3',
                'date' => '2026-03-24',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Multi-ISP Failover (Fase 1) — Sistema completo de redundancia DNS entre múltiples servidores e ISPs con 4 estados: Normal, Degradado, Primarios Caídos y Emergencia',
                            'Servidores dinámicos — Tabla flexible para configurar cualquier número de servidores Primary, Failover y Backup con nombres personalizados, cualquier proveedor',
                            'Integración Cloudflare — Gestión multi-cuenta de tokens API con verificación de zonas; cambio automático de registros DNS A/CNAME durante failover',
                            'caddy-l4 — Generador de configuración para proxy SNI en modo emergencia; preview en tiempo real del JSON generado',
                            'Health checks — Comprobación de estado de todos los servidores con umbrales configurables (intervalo, timeout, caídas para DOWN, checks para UP)',
                            'Acciones manuales de failover — Failover parcial, primarios caídos, emergencia y failback con confirmación por contraseña',
                            'Widget de failover en Dashboard — Muestra estado actual y servidores con sus roles dinámicamente',
                            'Endpoint AJAX save-setting — Permite guardar ajustes individuales del cluster sin recargar (auto-failover toggle)',
                        ],
                        'en' => [
                            'Multi-ISP Failover (Phase 1) — Complete DNS redundancy system across multiple servers and ISPs with 4 states: Normal, Degraded, Primaries Down and Emergency',
                            'Dynamic servers — Flexible table to configure any number of Primary, Failover and Backup servers with custom names, any provider',
                            'Cloudflare integration — Multi-account API token management with zone verification; automatic DNS A/CNAME record switching during failover',
                            'caddy-l4 — Configuration generator for SNI proxy in emergency mode; real-time JSON config preview',
                            'Health checks — Server health monitoring with configurable thresholds (interval, timeout, failures for DOWN, checks for UP)',
                            'Manual failover actions — Partial failover, primaries down, emergency and failback with password confirmation',
                            'Dashboard failover widget — Shows current state and servers with their roles dynamically',
                            'AJAX save-setting endpoint — Allows saving individual cluster settings without reload (auto-failover toggle)',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Reorganización del tab Failover — Tres secciones claras: Operaciones (estado + acciones), Infraestructura (servidores + Cloudflare) y Ajustes (config + caddy-l4)',
                            'Promote/Demote movido al tab Estado — Junto al estado del cluster donde corresponde, con auto-failover toggle integrado',
                            'Banner explicativo en Failover — Diagrama visual del flujo de estados, explicación de roles (Primary/Failover/Backup), requisitos de IP pública y NAT',
                            'Eliminada card redundante de Alertas Slave — Notificaciones email/telegram ya se gestionan en Settings > Notifications',
                        ],
                        'en' => [
                            'Failover tab reorganization — Three clear sections: Operations (status + actions), Infrastructure (servers + Cloudflare) and Settings (config + caddy-l4)',
                            'Promote/Demote moved to Estado tab — Next to cluster status where it belongs, with integrated auto-failover toggle',
                            'Explanatory banner in Failover — Visual state flow diagram, role explanation (Primary/Failover/Backup), public IP and NAT requirements',
                            'Removed redundant Slave Alerts card — Email/telegram notifications already managed in Settings > Notifications',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.2',
                'date' => '2026-03-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Session key en standby de nodos — Corregido $_SESSION[admin_id] inexistente por $_SESSION[panel_user][id] que impedia poner nodos en standby (error de contraseña)',
                            'Badges de actualizacion persistentes — Los badges "nueva version disponible" ya no persisten tras actualizar; update.sh limpia los flags en BD y getCachedUpdateInfo compara version real vs remota',
                            'Codigos ANSI en actualizador web — Eliminados caracteres de escape de color ([0;36m, etc.) cuando update.sh se ejecuta desde el panel web en vez de terminal',
                            'git pull bloqueado por storage/.monitor_last.json — update.sh ahora hace git checkout de archivos locales modificados antes del pull para evitar conflictos',
                        ],
                        'en' => [
                            'Session key in node standby — Fixed non-existent $_SESSION[admin_id] to $_SESSION[panel_user][id] which prevented putting nodes in standby (password error)',
                            'Persistent update badges — "New version available" badges no longer persist after updating; update.sh clears DB flags and getCachedUpdateInfo compares actual vs remote version',
                            'ANSI codes in web updater — Removed color escape characters ([0;36m, etc.) when update.sh runs from panel web instead of terminal',
                            'git pull blocked by storage/.monitor_last.json — update.sh now checks out locally modified files before pull to avoid conflicts',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Dashboard diferencia nodo caido vs standby — Banner amarillo con icono de pausa para standby, rojo para caido realmente; muestra tiempo en standby, motivo y boton "Reactivar"',
                            'Alertas de slave con escalacion — El slave ya no spamea emails cada 5 min cuando el master no responde; escalacion progresiva: inmediata, luego 1h, 6h y diaria',
                            'Slave en standby no alerta — Si el slave esta en modo standby, omite la comprobacion de heartbeat del master',
                        ],
                        'en' => [
                            'Dashboard differentiates down vs standby nodes — Yellow banner with pause icon for standby, red for actually down; shows standby duration, reason and "Reactivate" button',
                            'Slave alerts with escalation — Slave no longer spams emails every 5 min when master is unresponsive; progressive escalation: immediate, then 1h, 6h and daily',
                            'Standby slave skips alerts — If slave is in standby mode, it skips master heartbeat checks entirely',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.1',
                'date' => '2026-03-21',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Optimizacion de lsyncd para reducir consumo de CPU — maxProcesses reducido de 4 a 2, delay aumentado de 5s a 15s para agrupar mas cambios por batch',
                            'Wrapper rsync-nice — Todos los rsync de lsyncd se ejecutan con nice -n 15 e ionice -c2 -n7 (prioridad baja de CPU e I/O) para evitar picos de CPU',
                            'Exclusiones por defecto en lsyncd — Se excluyen automaticamente .vscode-server, .claude, .git, node_modules, storage/logs, storage/framework/cache, sessions y views',
                            'Graficos de monitoring con picos reales en rangos largos — Los rangos 7d/30d/1y ahora usan max_val (picos reales) en vez de avg_val para preservar los valores maximos',
                            'Graficos de barras para rangos diarios — Los rangos 30d y 1y muestran barras finas en vez de lineas para no dar impresion de trafico constante cuando son picos puntuales',
                            'Lineas separadoras de dia en graficos 7d — Lineas verticales punteadas en medianoche para separar visualmente los dias',
                            'Linea de media en rangos 7d — Linea punteada adicional mostrando el valor promedio junto al valor pico para mejor contexto',
                            'Etiquetas de eje X adaptadas por rango — 1h-24h muestra hora:minuto, 7d muestra dia, 30d muestra dia+mes, 1y muestra mes+año',
                            'Snapshot de red en alertas NET_HIGH — Las alertas de red alta ahora incluyen conexiones TCP activas, procesos, dominios asociados e IPs via ss -tnp y DNS inverso',
                            'Comandos completos de procesos en alertas — ps aux ww en vez de ps aux para mostrar lineas de comando sin truncar en emails y detalles del panel',
                        ],
                        'en' => [
                            'lsyncd optimization to reduce CPU usage — maxProcesses reduced from 4 to 2, delay increased from 5s to 15s to batch more changes',
                            'rsync-nice wrapper — All lsyncd rsync operations run with nice -n 15 and ionice -c2 -n7 (low CPU and I/O priority) to prevent CPU spikes',
                            'Default lsyncd exclusions — Automatically excludes .vscode-server, .claude, .git, node_modules, storage/logs, storage/framework/cache, sessions and views',
                            'Monitoring charts with real peaks in long ranges — 7d/30d/1y ranges now use max_val (real peaks) instead of avg_val to preserve maximum values',
                            'Bar charts for daily ranges — 30d and 1y ranges show thin bars instead of lines to avoid implying constant traffic from point-in-time peaks',
                            'Day separator lines in 7d charts — Dotted vertical lines at midnight to visually separate days',
                            'Average line in 7d ranges — Additional dashed line showing average value alongside peak value for better context',
                            'X-axis labels adapted per range — 1h-24h shows hour:minute, 7d shows day, 30d shows day+month, 1y shows month+year',
                            'Network snapshot in NET_HIGH alerts — High network alerts now include active TCP connections, processes, associated domains and IPs via ss -tnp and reverse DNS',
                            'Full process commands in alerts — ps aux ww instead of ps aux to show untruncated command lines in emails and panel details',
                        ],
                    ],
                    'added' => [
                        'es' => [
                            'Regeneracion automatica de lsyncd via cluster-worker — Flag storage/lsyncd-regen.flag permite regenerar y recargar la configuracion de lsyncd sin acceso directo a root',
                        ],
                        'en' => [
                            'Automatic lsyncd regeneration via cluster-worker — Flag storage/lsyncd-regen.flag allows regenerating and reloading lsyncd config without direct root access',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.0',
                'date' => '2026-03-20',
                'badge' => 'primary',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Servidor de mail integrado — Instalacion completa de Postfix, Dovecot, OpenDKIM y Rspamd directamente en el servidor master o en nodos remotos del cluster',
                            'Setup de mail local — Opcion "Instalar en este servidor" que ejecuta los 10 pasos de configuracion localmente via nohup, sin necesidad de nodo remoto',
                            'Setup de mail en nodo remoto — Opcion "Instalar en nodo remoto" que envia la configuracion al nodo via API del cluster',
                            'Progreso de instalacion en tiempo real — Vista con los 10 pasos, barra de progreso, tiempo transcurrido, detalle de errores y cola de log en vivo (polling cada 3s)',
                            'Hostnames unicos por nodo de mail — Cada nodo de mail requiere un FQDN unico (ej. mail1.musedock.com), validado contra BD y settings, con indice unico parcial',
                            'Columna mail_hostname en tabla de nodos — Visible en Cluster > Nodes con indicador de advertencia si el servicio de mail esta activo pero sin hostname asignado',
                            'Seccion de mail en vista de hosting individual — Muestra buzones, aliases, cuota, uso con alerta al >85%, estado y enlace para crear cuentas',
                            'Boton "Activar correo" en hosting — Enlace directo a crear dominio de mail desde la vista del hosting individual',
                            'Estado deshabilitado de mail — Mensaje informativo cuando no hay servidor de mail configurado en el panel',
                            'Suspension de mail al suspender hosting — Checkbox en el dialogo de suspension para suspender simultaneamente el dominio de mail y todos sus buzones',
                            'Eliminacion de mail al eliminar hosting — Checkbox en el dialogo de eliminacion para borrar el dominio de mail asociado (con advertencia de buzones activos)',
                            'Reactivacion automatica de mail — Al reactivar un hosting, se reactivan automaticamente el dominio de mail y sus cuentas si estaban suspendidos',
                            'Metricas de disco en monitoring — Recoleccion de uso (%) y throughput I/O (lectura/escritura bytes/s) para cada disco fisico del sistema',
                            'Tarjetas de disco en dashboard — Stat cards con dispositivo, punto de montaje, usado/total/libre y barra de progreso con colores por nivel (verde/amarillo/rojo)',
                            'Graficos de disco — Grafico de uso porcentual y grafico de I/O (Read/Write) con selector de disco y rangos temporales (1h a 1y)',
                            'Alerta DISK_HIGH — Notificacion cuando un disco supera el umbral configurable (default 90%), con threshold ajustable en Alert Settings',
                            'Info de disco en todas las alertas — Cada alerta (CPU, RAM, GPU, NET, DISK) incluye el estado completo de todos los discos del sistema en los detalles',
                            'Modal de detalles de alerta — Click en cualquier alerta del panel abre un modal con hora, mensaje, valor y lista completa de procesos + discos al momento de la alerta',
                            'Comandos completos en alertas — Las notificaciones por email y los detalles en panel muestran la linea de comando completa de los procesos (sin truncar)',
                            'Next Steps en el instalador — El instalador muestra pasos siguientes al finalizar (configurar mail, cluster y replicacion desde el panel)',
                        ],
                        'en' => [
                            'Integrated mail server — Full installation of Postfix, Dovecot, OpenDKIM and Rspamd directly on master server or on remote cluster nodes',
                            'Local mail setup — "Install on this server" option that runs all 10 configuration steps locally via nohup, no remote node needed',
                            'Remote mail setup — "Install on remote node" option that sends configuration to the node via cluster API',
                            'Real-time installation progress — View with 10 steps, progress bar, elapsed time, error details and live log tail (polling every 3s)',
                            'Unique hostnames per mail node — Each mail node requires a unique FQDN (e.g. mail1.musedock.com), validated against DB and settings, with partial unique index',
                            'mail_hostname column in nodes table — Visible in Cluster > Nodes with warning indicator if mail service is active but no hostname assigned',
                            'Mail section in individual hosting view — Shows mailboxes, aliases, quota, usage with alert at >85%, status and link to create accounts',
                            '"Enable mail" button in hosting — Direct link to create mail domain from individual hosting view',
                            'Mail disabled state — Informational message when no mail server is configured in the panel',
                            'Mail suspension when suspending hosting — Checkbox in suspension dialog to simultaneously suspend mail domain and all mailboxes',
                            'Mail deletion when deleting hosting — Checkbox in deletion dialog to delete associated mail domain (with active mailbox warning)',
                            'Automatic mail reactivation — When reactivating a hosting, mail domain and accounts are automatically reactivated if they were suspended',
                            'Disk metrics in monitoring — Collection of usage (%) and I/O throughput (read/write bytes/s) for each physical disk on the system',
                            'Disk cards in dashboard — Stat cards with device, mount point, used/total/free and color-coded progress bar (green/yellow/red)',
                            'Disk charts — Usage percentage chart and I/O chart (Read/Write) with disk selector and time ranges (1h to 1y)',
                            'DISK_HIGH alert — Notification when a disk exceeds configurable threshold (default 90%), adjustable in Alert Settings',
                            'Disk info in all alerts — Every alert (CPU, RAM, GPU, NET, DISK) includes full disk status in details',
                            'Alert details modal — Click on any alert in the panel opens a modal with time, message, value and full process + disk list at time of alert',
                            'Full commands in alerts — Email notifications and panel details show complete process command lines (no truncation)',
                            'Next Steps in installer — Installer shows next steps after completion (configure mail, cluster and replication from panel)',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'getDnsRecords() devolviendo localhost como MX para dominios locales — Ahora usa la IP publica del servidor y el mail_local_hostname configurado',
                            'Mensajes de error en mail-setup-run.php mencionando "replica" en modo local — Mensajes adaptados para no mencionar replicas cuando se instala localmente',
                            'Operaciones de MailService con mail_node_id NULL — Todas las operaciones (crear/eliminar dominio, crear/actualizar/eliminar cuenta, rotar password) ahora funcionan correctamente en modo local sin intentar llamar a ClusterService con node_id null',
                        ],
                        'en' => [
                            'getDnsRecords() returning localhost as MX for local domains — Now uses server public IP and configured mail_local_hostname',
                            'Error messages in mail-setup-run.php mentioning "replica" in local mode — Messages adapted to not mention replicas when installing locally',
                            'MailService operations with NULL mail_node_id — All operations (create/delete domain, create/update/delete account, rotate password) now work correctly in local mode without trying to call ClusterService with null node_id',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.7.8',
                'date' => '2026-03-18',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Modo de sincronizacion lsyncd — Nueva opcion en Cluster > Archivos para elegir entre rsync periodico o lsyncd (tiempo real con inotify y delay de 5s)',
                            'Instalacion y gestion de lsyncd desde el panel — Botones para instalar, iniciar, detener, recargar y ver estado de lsyncd directamente desde la interfaz',
                            'Modo standby/mantenimiento por nodo — Boton para pausar toda sincronizacion, cola y alertas de un nodo especifico con password de admin y campo de motivo',
                            'Sincronizacion completa de /var/www/vhosts/ — Tanto rsync periodico como lsyncd sincronizan todo el directorio de vhosts, no solo los hostings registrados',
                            'Explorador visual de exclusiones — Modal con arbol de directorios y checkboxes para excluir rutas especificas del sync (ademas de patrones)',
                            'Actualizacion remota de disco — Calcula disk_used_mb en el slave via SSH tras cada sync y actualiza ambas bases de datos',
                            'Comprobacion independiente de streaming por motor — PostgreSQL y MySQL se verifican por separado para dumps, evitando que uno bloquee al otro',
                            'Indicador de motor gestionado por streaming — En la seccion de dumps, los motores con replicacion activa muestran enlace a la configuracion de streaming en vez del checkbox',
                            'Nota informativa de tareas independientes — Info box explicando que SSL, credenciales y dumps se ejecutan en cada intervalo independientemente del modo de archivos',
                        ],
                        'en' => [
                            'lsyncd sync mode — New option in Cluster > Files to choose between periodic rsync or lsyncd (real-time with inotify and 5s delay)',
                            'lsyncd install and management from panel — Buttons to install, start, stop, reload and view lsyncd status directly from the UI',
                            'Node standby/maintenance mode — Button to pause all sync, queue and alerts for a specific node with admin password and reason field',
                            'Full /var/www/vhosts/ sync — Both periodic rsync and lsyncd mirror the entire vhosts directory, not just registered hostings',
                            'Visual exclusion browser — Modal with directory tree and checkboxes to exclude specific paths from sync (in addition to patterns)',
                            'Remote disk usage update — Calculates disk_used_mb on slave via SSH after each sync and updates both databases',
                            'Independent streaming check per engine — PostgreSQL and MySQL are checked separately for dumps, preventing one from blocking the other',
                            'Engine managed by streaming indicator — In the dumps section, engines with active replication show a link to streaming config instead of the checkbox',
                            'Independent tasks info note — Info box explaining that SSL, credentials and dumps run on each interval regardless of file sync mode',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Sync manual independiente del modo — El boton de sincronizacion manual funciona siempre (rsync directo) independientemente de si lsyncd esta activo, respetando exclusiones',
                            'Worker de filesync consciente de standby — El worker omite nodos en standby pero permite acumular cola para cuando se reactiven',
                            'Worker de cluster silencia alertas en standby — No envia notificaciones de nodo caido para nodos marcados en mantenimiento',
                        ],
                        'en' => [
                            'Manual sync independent of mode — Manual sync button always works (direct rsync) regardless of whether lsyncd is active, respecting exclusions',
                            'Filesync worker standby-aware — Worker skips standby nodes but allows queue items to accumulate for when they are reactivated',
                            'Cluster worker silences standby alerts — Does not send offline notifications for nodes marked in maintenance',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Monitor de red mostraba valores planos — El archivo de contadores en /tmp/ no se actualizaba (owner incorrecto), causando que elapsed fuera de horas en vez de 30s y diluyendo todos los rates de red',
                            'Archivo de contadores movido a storage/ — Cambiado de /tmp/musedock_monitor_last.json a storage/.monitor_last.json para evitar problemas de permisos entre usuarios',
                            'Contadores se guardan antes del INSERT — file_put_contents movido antes de las operaciones de BD para garantizar datos frescos incluso si la BD falla',
                            'Database::execute() inexistente — Corregido a Database::query() en el worker de filesync',
                            'Sync de disco del slave incorrecto — El rsync solo sincronizaba /httpdocs en vez de todo el vhost root, causando discrepancias de disco',
                        ],
                        'en' => [
                            'Network monitor showed flat values — Counter file in /tmp/ was not updating (wrong owner), causing elapsed to be hours instead of 30s, diluting all network rates',
                            'Counter file moved to storage/ — Changed from /tmp/musedock_monitor_last.json to storage/.monitor_last.json to avoid permission issues between users',
                            'Counters saved before INSERT — file_put_contents moved before DB operations to guarantee fresh data even if DB fails',
                            'Non-existent Database::execute() — Fixed to Database::query() in filesync worker',
                            'Slave disk sync incorrect — rsync only synced /httpdocs instead of entire vhost root, causing disk discrepancies',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.7.7',
                'date' => '2026-03-18',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Top procesos en alertas — Las notificaciones de CPU/RAM/GPU incluyen los 5 procesos que mas consumen al dispararse la alerta',
                            'Importar hosting huerfano con cluster sync — Al importar un hosting existente se encola automaticamente la replicacion a nodos slave',
                            'Auto-deteccion de bases de datos al importar — Detecta automaticamente las bases de datos PostgreSQL (por owner) y MySQL (por prefijo) asociadas al hosting importado',
                            'Asociar base de datos externa — Boton "Asociar" en /databases para vincular bases de datos huerfanas a cuentas de hosting con auto-deteccion de owner',
                            'Boton "Probar conexion SSH" en migraciones — Permite testear la conexion SSH de forma independiente sin iniciar la migracion',
                            'Destino local editable en migraciones — Campo para especificar ruta de destino local con validacion de seguridad (debe estar dentro del home del usuario)',
                            'Cascada de descarga en migraciones — Intenta HTTPS dominio → HTTPS host SSH → HTTP dominio → HTTP host SSH → SCP directo como ultimo recurso',
                            'Cluster sync en edicion de hosting — Al editar document_root, PHP version, shell o quota en el master se sincroniza automaticamente a los slaves',
                            'Accion update_hosting_full en cluster — Nueva accion de sync que actualiza document_root (con creacion de directorio y ruta Caddy), PHP version, shell y quota en el slave',
                            'SSL cert sync robusto — Sincronizacion de certificados Caddy entre nodos sin depender de permisos del proceso PHP (rsync directo)',
                        ],
                        'en' => [
                            'Top processes in alerts — CPU/RAM/GPU alert notifications now include the top 5 consuming processes when the alert fires',
                            'Import orphan hosting with cluster sync — Importing an existing hosting automatically enqueues replication to slave nodes',
                            'Auto-detect databases on import — Automatically detects PostgreSQL databases (by owner) and MySQL databases (by username prefix) associated with the imported hosting',
                            'Associate external database — "Associate" button on /databases to link orphan databases to hosting accounts with owner auto-detection',
                            'Standalone SSH test button in migrations — Test SSH connection independently without starting the migration',
                            'Editable local destination in migrations — Field to specify local target path with security validation (must be within user home)',
                            'Download cascade in migrations — Tries HTTPS domain → HTTPS SSH host → HTTP domain → HTTP SSH host → direct SCP as last resort',
                            'Cluster sync on hosting edit — Editing document_root, PHP version, shell or quota on master automatically syncs to slaves',
                            'update_hosting_full cluster action — New sync action that updates document_root (with directory creation and Caddy route), PHP version, shell and quota on the slave',
                            'Robust SSL cert sync — Caddy certificate sync between nodes without depending on PHP process permissions (direct rsync)',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Timeouts en descarga de migraciones — wget con connect-timeout=30s, read-timeout=60s, deteccion de estancamiento (60s sin progreso) y timeout global de 10 minutos',
                            'Filesync sin exclusiones — Eliminadas todas las exclusiones de rsync para copia fiel entre master y slave',
                            'findCaddyCertDir tolerante a permisos — Detecta directorio de certificados Caddy incluso cuando el proceso PHP no puede leer el directorio (confia en la ruta por defecto si Caddy esta corriendo)',
                        ],
                        'en' => [
                            'Migration download timeouts — wget with connect-timeout=30s, read-timeout=60s, stall detection (60s no progress) and 10-minute global timeout',
                            'Filesync without exclusions — Removed all rsync exclusions for faithful copy between master and slave',
                            'findCaddyCertDir permission-tolerant — Detects Caddy certificate directory even when PHP process cannot read it (trusts default path if Caddy is running)',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'CSRF en verificacion de destino local — Corregido hang infinito al verificar destino local por falta de _csrf_token en la peticion fetch',
                            'Panel bloqueado por wget — El servidor PHP single-thread se bloqueaba con descargas wget estancadas. Ahora se fuerza terminacion con proc_terminate tras timeout',
                            'Descarga desde host incorrecto — Las migraciones descargaban el backup desde el dominio (detras de Cloudflare/Laravel = 404) en vez del host SSH directo',
                            'Modal de migracion reaparecia — El modal de migracion se reabria desde localStorage al recargar pagina. Limpieza de estado al completar/abortar',
                        ],
                        'en' => [
                            'CSRF on local destination check — Fixed infinite hang when verifying local destination due to missing _csrf_token in fetch request',
                            'Panel blocked by wget — PHP single-thread server was blocked by stalled wget downloads. Now forces termination with proc_terminate after timeout',
                            'Download from wrong host — Migrations downloaded backup from domain (behind Cloudflare/Laravel = 404) instead of direct SSH host',
                            'Migration modal reappeared — Migration modal reopened from localStorage on page reload. State cleanup on complete/abort',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.7.6',
                'date' => '2026-03-17',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Alertas escalonadas para nodos caidos — Intervalo progresivo: 1min, 5min, 15min, 30min, 45min, 1h, 2h, 4h, 8h, 12h en vez de alertar cada minuto',
                            'Silenciar alertas por nodo — Boton para silenciar alertas de un nodo especifico (mantenimiento programado). Se reactivan automaticamente al recuperarse el nodo',
                            'Notificacion de recuperacion — Cuando un nodo vuelve online se envia aviso con el tiempo total que estuvo caido',
                            'Banner de nodos caidos en Dashboard — Alerta roja prominente con tabla de nodos offline, tiempo caido y controles de silenciar/reactivar',
                            'Controles de alertas en Cluster > Nodos — Columna "Alertas" con estado (Activas/Silenciadas) y botones silenciar/reactivar por nodo',
                            'Modal "Ver Estado" instantaneo — El modal abre al momento con datos de la DB y verifica la conexion en vivo en paralelo con spinner',
                            'Endpoint ping individual — GET /settings/cluster/ping-node para verificar un solo nodo sin bloquear',
                            'Confirmacion con password para eliminar nodo — El modal de eliminar nodo ahora pide contraseña de admin y explica que solo desvincula (no borra datos remotos)',
                        ],
                        'en' => [
                            'Escalating alerts for offline nodes — Progressive interval: 1min, 5min, 15min, 30min, 45min, 1h, 2h, 4h, 8h, 12h instead of alerting every minute',
                            'Mute alerts per node — Button to silence alerts for a specific node (scheduled maintenance). Auto-reactivates when node recovers',
                            'Recovery notification — When a node comes back online, a notification is sent with total downtime',
                            'Offline nodes banner on Dashboard — Prominent red alert with table of offline nodes, downtime and mute/unmute controls',
                            'Alert controls in Cluster > Nodes — "Alerts" column with status (Active/Muted) and mute/unmute buttons per node',
                            'Instant "View Status" modal — Modal opens immediately with DB data and verifies live connection in parallel with spinner',
                            'Individual ping endpoint — GET /settings/cluster/ping-node to check a single node without blocking',
                            'Password confirmation to delete node — Delete node modal now requires admin password and explains it only unlinks (does not delete remote data)',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Cards de Dashboard con misma altura — Las stat-cards de CPU, RAM, Disco y Hosting Accounts ahora se alinean uniformemente',
                            'Cards de Monitoring con misma altura — Las stat-cards de Health, Alerts, Network, CPU, RAM y GPU ahora se alinean uniformemente',
                        ],
                        'en' => [
                            'Dashboard cards equal height — CPU, RAM, Disk and Hosting Accounts stat-cards now align uniformly',
                            'Monitoring cards equal height — Health, Alerts, Network, CPU, RAM and GPU stat-cards now align uniformly',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.7.5',
                'date' => '2026-03-17',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Alert Settings — Panel colapsable en Monitoring para configurar thresholds de CPU, RAM, red, GPU temp y GPU util. Valor 0 = alerta desactivada',
                            'Clear All Alerts — Boton para borrar todas las alertas del host actual con confirmacion',
                            'Database Timezone fix — El reinicio de PostgreSQL/MySQL al corregir timezone se hace en background para no romper la pagina',
                            'MySQL timezone real — Detecta offset real con TIMEDIFF cuando MySQL usa SYSTEM timezone',
                        ],
                        'en' => [
                            'Alert Settings — Collapsible panel in Monitoring to configure CPU, RAM, network, GPU temp and GPU util thresholds. Value 0 = alert disabled',
                            'Clear All Alerts — Button to delete all alerts for the current host with confirmation',
                            'Database Timezone fix — PostgreSQL/MySQL restart after timezone fix runs in background to avoid breaking the page',
                            'MySQL timezone real — Detects real offset with TIMEDIFF when MySQL uses SYSTEM timezone',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'CSRF en acknowledge — Corregido nombre de session key csrf_token → _csrf_token en el JS de monitoring',
                            'Threshold 0 desactiva alertas — Poner un threshold a 0 ahora desactiva esa alerta en vez de disparar siempre',
                        ],
                        'en' => [
                            'CSRF in acknowledge — Fixed session key name csrf_token → _csrf_token in monitoring JS',
                            'Threshold 0 disables alerts — Setting a threshold to 0 now disables that alert instead of always triggering',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.7.4',
                'date' => '2026-03-17',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Auto-deteccion de interfaces de red — Detecta automaticamente interfaces fisicas y WireGuard (eno1, eth0, enp0s3, wg0, etc.). Si las configuradas no existen, auto-detecta las reales',
                            'IP en tarjetas de red — Cada tarjeta de interfaz muestra su direccion IP (ej. 213.201.21.156/29, 10.10.70.156/24)',
                            'Nombre de GPU en graficas — Las graficas de GPU muestran el modelo (ej. RTX 3090 Ti) en el titulo y en la leyenda',
                            'GPU Health en System Health — Detecta GPUs NVIDIA, muestra estado de cada una (driver, VRAM, temp, util, power), alerta errores de hardware via dmesg/nvidia-smi, detecta GPUs caidas desde lspci',
                            'Database Timezone en System Health — Verifica si PostgreSQL y MySQL estan en UTC. Boton "Set UTC" para corregir automaticamente el timezone y reiniciar el servicio',
                        ],
                        'en' => [
                            'Auto-detect network interfaces — Detects physical and WireGuard interfaces (eno1, eth0, enp0s3, wg0, etc.). Falls back to auto-detect if configured ones don\'t exist',
                            'IP on network cards — Each interface card shows its IP address (e.g. 213.201.21.156/29, 10.10.70.156/24)',
                            'GPU name in charts — GPU charts show model name (e.g. RTX 3090 Ti) in headers and dataset legends',
                            'GPU Health in System Health — Detects NVIDIA GPUs, shows per-GPU status (driver, VRAM, temp, util, power), alerts hardware errors via dmesg/nvidia-smi, detects failed GPUs from lspci',
                            'Database Timezone in System Health — Checks if PostgreSQL and MySQL are set to UTC. "Set UTC" button to auto-fix timezone and restart the service',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'update.sh systemd — Corregido placeholder __PANEL_PORT__ por __PANEL_INTERNAL_PORT__ que causaba 502 Bad Gateway',
                            'update.sh auto-relaunch — Si update.sh cambia durante git pull, se re-ejecuta con la version nueva automaticamente',
                            'GPU multi-host — detectGpus() consulta la base de datos para hosts remotos en vez de nvidia-smi local',
                            'Update banner — Comprueba GitHub automaticamente si no hay cache o ha expirado',
                            'Interfaces de red en remoto — Servidores con interfaces distintas a eth0 (ej. eno1) funcionan sin configuracion manual',
                            'GPU duplicada en Health — Corregido GPU que aparecia dos veces cuando se detectaba por dmesg y por error PCI',
                        ],
                        'en' => [
                            'update.sh systemd — Fixed __PANEL_PORT__ placeholder to __PANEL_INTERNAL_PORT__ which caused 502 Bad Gateway',
                            'update.sh auto-relaunch — If update.sh changes during git pull, re-executes with new version automatically',
                            'GPU multi-host — detectGpus() queries database for remote hosts instead of local nvidia-smi',
                            'Update banner — Auto-checks GitHub if no cache exists or cache expired',
                            'Remote network interfaces — Servers with non-eth0 interfaces (e.g. eno1) work without manual config',
                            'Duplicate GPU in Health — Fixed GPU appearing twice when detected from both dmesg and PCI error',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.7.0',
                'date' => '2026-03-17',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Monitoring — Dashboard completo de monitorizacion con metricas de red, CPU y RAM en tiempo real',
                            'Graficas MRTG-style — Chart.js con RX/TX por interfaz (eth0, wg0), selectores de rango (1h/6h/24h/7d/30d/1y)',
                            'Historico 3 niveles — Datos raw (48h retencion), agregados por hora (90 dias), agregados diarios (ilimitado)',
                            'Health Score — Puntuacion 0-100 basada en CPU, RAM y trafico de red con penalizaciones ponderadas',
                            'Alertas — Alertas automaticas por CPU alta (>90%), RAM alta (>90%), trafico de red (>800Mbps) y GPU (temp >85C, util >95%)',
                            'Notificacion de alertas — Las alertas se envian por Email/Telegram via NotificationService con anti-spam (1 por tipo cada 5 min)',
                            'Acknowledge de alertas — Boton para marcar alertas como leidas, limpieza automatica de alertas acknowledged >30 dias',
                            'GPU Monitoring — Deteccion automatica de GPUs NVIDIA via nvidia-smi: utilizacion, memoria, temperatura y consumo',
                            'GPU Multi-GPU — Soporte para multiples GPUs con graficas independientes por GPU (util, memoria, temp, power)',
                            'Collector cron — Script bin/monitor-collector.php cada 30s con flock, delta calculation y limpieza automatica',
                            'Timezone en graficas — Las graficas muestran la hora de la zona configurada en Settings > Server (no la del navegador)',
                            'System Health — Nueva seccion en Settings con verificacion de crons, extensiones PHP, binarios y permisos de directorios',
                            'System Health Repair — Boton para reparar crons faltantes o vacios desde el panel',
                            'NTP status — Informacion de sincronizacion NTP en Settings > Server',
                            'PostgreSQL en UTC — La instancia del panel (5433) configurada en UTC para almacenamiento limpio de timestamps',
                            'Auto-Update — Sistema de actualizacion desde el panel: detecta nuevas versiones en GitHub, muestra changelog y actualiza con un click',
                            'Update banner — Banner en el header que notifica cuando hay una version nueva disponible',
                            'Settings > Updates — Pagina de actualizaciones con version actual/remota, boton de comprobar y actualizar, progreso en tiempo real',
                            'Update no-interactivo — Flag --auto en update.sh para ejecucion desde el panel sin prompts interactivos',
                        ],
                        'en' => [
                            'Monitoring — Full monitoring dashboard with real-time network, CPU and RAM metrics',
                            'MRTG-style charts — Chart.js with RX/TX per interface (eth0, wg0), range selectors (1h/6h/24h/7d/30d/1y)',
                            '3-tier history — Raw data (48h retention), hourly aggregates (90 days), daily aggregates (unlimited)',
                            'Health Score — 0-100 score based on CPU, RAM and network traffic with weighted penalties',
                            'Alerts — Automatic alerts for high CPU (>90%), high RAM (>90%), network traffic (>800Mbps) and GPU (temp >85C, util >95%)',
                            'Alert notifications — Alerts sent via Email/Telegram through NotificationService with anti-spam (1 per type per 5 min)',
                            'Alert acknowledge — Button to mark alerts as read, automatic cleanup of acknowledged alerts >30 days',
                            'GPU Monitoring — Automatic NVIDIA GPU detection via nvidia-smi: utilization, memory, temperature and power draw',
                            'GPU Multi-GPU — Support for multiple GPUs with independent charts per GPU (util, memory, temp, power)',
                            'Collector cron — bin/monitor-collector.php script every 30s with flock, delta calculation and automatic cleanup',
                            'Chart timezone — Charts display time in the timezone configured in Settings > Server (not browser timezone)',
                            'System Health — New Settings section with verification of crons, PHP extensions, binaries and directory permissions',
                            'System Health Repair — Button to repair missing or empty crons from the panel',
                            'NTP status — NTP synchronization info in Settings > Server',
                            'PostgreSQL on UTC — Panel instance (5433) configured in UTC for clean timestamp storage',
                            'Auto-Update — Panel update system: detects new versions on GitHub, shows changelog and updates with one click',
                            'Update banner — Header banner that notifies when a new version is available',
                            'Settings > Updates — Updates page with current/remote version, check and update buttons, real-time progress',
                            'Non-interactive update — --auto flag in update.sh for panel-triggered execution without interactive prompts',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Orden de bases de datos — La seccion PostgreSQL Panel (5433) ahora aparece primera en la pagina de Databases',
                            'musedock_panel primera — Dentro de PostgreSQL Panel, la BD musedock_panel aparece primera en la lista',
                            'Timezone en graficas — Corregido bug donde las graficas mostraban hora del navegador en vez de la zona del servidor',
                            'PostgreSQL timezone — Cambiado de Europe/Berlin a UTC para evitar ambiguedad en timestamps almacenados',
                            'certbot eliminado de Health — Caddy gestiona SSL internamente, certbot no es necesario',
                            'Monitor cron en instalador — El cron musedock-monitor se instala automaticamente en install.sh y update.sh',
                        ],
                        'en' => [
                            'Database ordering — PostgreSQL Panel (5433) section now appears first on the Databases page',
                            'musedock_panel first — Within PostgreSQL Panel, musedock_panel DB appears first in the list',
                            'Chart timezone — Fixed bug where charts showed browser timezone instead of server timezone',
                            'PostgreSQL timezone — Changed from Europe/Berlin to UTC to avoid timestamp ambiguity',
                            'certbot removed from Health — Caddy manages SSL internally, certbot is not needed',
                            'Monitor cron in installer — The musedock-monitor cron is automatically installed in install.sh and update.sh',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.6.0',
                'date' => '2026-03-17',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'Cluster tabs — La página de Cluster se reorganizó en 6 pestañas: Estado, Nodos, Archivos, Failover, Configuración, Cola',
                            'Sincronización Completa — Botón orquestador en pestaña Estado que ejecuta en secuencia: hostings (API) → archivos (rsync) → bases de datos (dump) → certificados SSL',
                            'Endpoint full-sync — POST /settings/cluster/full-sync lanza proceso en background con progreso en tiempo real via AJAX polling',
                            'DB dump sync (Nivel 1) — Sincronización simple de bases de datos entre master y slave usando pg_dump/mysqldump comprimidos con gzip. Se restauran automáticamente en el slave con DROP + CREATE + IMPORT',
                            'DB dump en sync manual — La sincronización manual de archivos ahora también incluye dump y restauración de bases de datos si está habilitado',
                            'DB dump periódico — El cron filesync-worker incluye dumps de BD cada intervalo si está habilitado. Se omite automáticamente si streaming replication (Nivel 2) está activo',
                            'isStreamingActive() — Método en ReplicationService que detecta si la replicación streaming de PostgreSQL o MySQL está activa',
                            'restore-db-dumps — Acción en la API del cluster para que el slave restaure los dumps recibidos. Crea usuarios de BD si no existen',
                            'Backup pre-replicación — Al convertir un servidor a slave de streaming replication, se crea backup automático de todas las bases de datos en /var/backups/musedock/pre-replication/ con timestamp',
                            'Modal convert-to-slave mejorado — Aviso en rojo explicando que se borrarán TODAS las bases de datos locales, lista las BD afectadas, checkbox de backup automático activado por defecto',
                            'Failover con select de nodos — El campo de IP manual para degradar a slave se reemplazó por un selector desplegable de nodos conectados',
                            'Failover con password — Promover a Master y Degradar a Slave requieren contraseña de administrador con validación AJAX y modales explicativos',
                            'System Users — Sección de solo lectura mostrando todos los usuarios del sistema Linux (UID, grupos, shell, home)',
                            'Hosting repair on re-sync — Si un hosting ya existe en el slave, se repara (UID, shell, grupos, password hash, caddy_route_id) en vez de saltarlo',
                            'SSL cert detection en slave — El panel detecta certificados SSL copiados del master via Caddy admin API y filesystem',
                            'Sync progress modal — Modal con barra de progreso, cronómetro y dominio actual durante la sincronización. Persiste tras recargar página',
                            'Auto-configurar replicación en slave — Botón "Convertir este nodo en Slave de X" con modal de advertencia, backup automático y verificación de contraseña',
                            'Nodo virtual en slave — Si el slave no tiene nodos de cluster registrados pero conoce la IP del master, muestra un nodo virtual para auto-configurar',
                        ],
                        'en' => [
                            'Cluster tabs — Cluster page reorganized into 6 tabs: Status, Nodes, Files, Failover, Config, Queue',
                            'Full Sync — Orchestrator button in Status tab that runs in sequence: hostings (API) → files (rsync) → databases (dump) → SSL certificates',
                            'Full-sync endpoint — POST /settings/cluster/full-sync launches background process with real-time AJAX progress polling',
                            'DB dump sync (Level 1) — Simple database sync between master and slave using pg_dump/mysqldump compressed with gzip. Auto-restored on slave with DROP + CREATE + IMPORT',
                            'DB dump in manual sync — Manual file sync now also includes database dump and restore if enabled',
                            'Periodic DB dump — The filesync-worker cron includes DB dumps each interval if enabled. Automatically skipped if streaming replication (Level 2) is active',
                            'isStreamingActive() — ReplicationService method that detects if PostgreSQL or MySQL streaming replication is active',
                            'restore-db-dumps — Cluster API action for the slave to restore received dumps. Creates DB users if they don\'t exist',
                            'Pre-replication backup — When converting a server to streaming slave, automatically backs up all databases to /var/backups/musedock/pre-replication/ with timestamp',
                            'Improved convert-to-slave modal — Red warning explaining ALL local databases will be deleted, lists affected DBs, auto-backup checkbox enabled by default',
                            'Failover with node selector — Manual IP input for demote-to-slave replaced by dropdown of connected nodes',
                            'Failover with password — Promote to Master and Demote to Slave require admin password with AJAX validation and detailed modals',
                            'System Users — Read-only section showing all Linux system users (UID, groups, shell, home)',
                            'Hosting repair on re-sync — If a hosting already exists on slave, repairs it (UID, shell, groups, password hash, caddy_route_id) instead of skipping',
                            'SSL cert detection on slave — Panel detects SSL certificates copied from master via Caddy admin API and filesystem',
                            'Sync progress modal — Modal with progress bar, timer and current domain during sync. Persists after page reload',
                            'Auto-configure replication on slave — "Convert this node to Slave of X" button with warning modal, auto-backup and password verification',
                            'Virtual node on slave — If slave has no cluster nodes but knows master IP, shows a virtual node for auto-configure',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Tildes en español — Corregidas todas las tildes faltantes en la página de Cluster (más de 50 correcciones)',
                            'Caddy Route N/A en slave — caddy_route_id ahora se incluye en el payload de sincronización de hostings',
                            'JSON sync modal — Corregido mismatch de campos entre backend (synced/failed) y frontend (ok_count/fail_count)',
                            'filesync-run.php bootstrap — Corregido error de archivo no encontrado usando bootstrap inline',
                            'rsync --delete en certs — Los certificados del slave ya no se borran al sincronizar',
                            'DROP DATABASE con conexiones activas — Añadido pg_terminate_backend + DROP DATABASE WITH (FORCE) con fallback para PG < 13',
                            'Session key en verify-admin-password — Corregido $_SESSION[admin_id] inexistente por $_SESSION[panel_user][id]',
                            'Auto-configure en slave sin nodos — El botón de auto-configurar ahora aparece en el slave usando nodo virtual del master',
                        ],
                        'en' => [
                            'Spanish tildes — Fixed all missing accents across the Cluster page (50+ corrections)',
                            'Caddy Route N/A on slave — caddy_route_id now included in hosting sync payload',
                            'JSON sync modal — Fixed field mismatch between backend (synced/failed) and frontend (ok_count/fail_count)',
                            'filesync-run.php bootstrap — Fixed file not found error using inline bootstrap',
                            'rsync --delete on certs — Slave certificates no longer deleted during sync',
                            'DROP DATABASE with active connections — Added pg_terminate_backend + DROP DATABASE WITH (FORCE) with PG < 13 fallback',
                            'Session key in verify-admin-password — Fixed non-existent $_SESSION[admin_id] to $_SESSION[panel_user][id]',
                            'Auto-configure on slave without nodes — Auto-configure button now appears on slave using virtual master node',
                        ],
                    ],
                ],
            ],
            [
                'version' => '0.5.3',
                'date' => '2026-03-16',
                'badge' => 'success',
                'changes' => [
                    'added' => [
                        'es' => [
                            'File Sync — Sincronización de archivos entre master y slaves via SSH (rsync) o HTTPS (API), con cron worker automático',
                            'File Sync SSL certs — Sincronización de certificados SSL de Caddy entre nodos con propiedad correcta (caddy:caddy)',
                            'File Sync ownership — rsync con --chown y HTTPS con owner_user para corregir UIDs entre servidores',
                            'File Sync UI — Botones funcionales en Cluster: generar clave SSH, instalar en nodo, test SSH, sincronizar ahora, verificar DB host',
                            'SSH info banner — Nota explicativa en la página de Cluster con el flujo de 3 pasos para configurar claves SSH',
                            'Firewall protocolos — Los protocolos ahora se muestran como texto (TCP, UDP, ICMP, ALL) en vez de números',
                            'Firewall descripción — Nueva columna "Descripción" en iptables mostrando estado (RELATED,ESTABLISHED, etc.)',
                            'Firewall protocolo ALL — Opción "Todos" en el selector de protocolo para reglas sin puerto específico',
                            'Cifrado Telegram — Token de Telegram cifrado con AES-256-CBC en panel_settings',
                            'Cifrado SMTP — Password SMTP cifrado con AES-256-CBC en panel_settings',
                            'Instalador Update — Nuevo modo "Actualizar" (opción 4) que aplica cambios incrementales sin reinstalar',
                            'Instalador filesync cron — El cron musedock-filesync se instala automáticamente con el instalador',
                            'SSL cert auto-fill — La ruta de certificados SSL se auto-rellena si se detecta Caddy',
                        ],
                        'en' => [
                            'File Sync — File synchronization between master and slaves via SSH (rsync) or HTTPS (API), with automatic cron worker',
                            'File Sync SSL certs — Caddy SSL certificate sync between nodes with correct ownership (caddy:caddy)',
                            'File Sync ownership — rsync with --chown and HTTPS with owner_user to fix UIDs between servers',
                            'File Sync UI — Working buttons in Cluster: generate SSH key, install on node, test SSH, sync now, verify DB host',
                            'SSH info banner — Explanatory note in Cluster page with 3-step flow for configuring SSH keys',
                            'Firewall protocols — Protocols now shown as text (TCP, UDP, ICMP, ALL) instead of numbers',
                            'Firewall description — New "Description" column in iptables showing state (RELATED,ESTABLISHED, etc.)',
                            'Firewall protocol ALL — "All" option in protocol selector for rules without specific port',
                            'Telegram encryption — Telegram token encrypted with AES-256-CBC in panel_settings',
                            'SMTP encryption — SMTP password encrypted with AES-256-CBC in panel_settings',
                            'Installer Update — New "Update" mode (option 4) that applies incremental changes without reinstalling',
                            'Installer filesync cron — The musedock-filesync cron is automatically installed with the installer',
                            'SSL cert auto-fill — SSL certificate path auto-fills if Caddy is detected',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'SMTP cifrado — Corregido tipo de cifrado (SSL→TLS/STARTTLS) y typo en dirección From',
                            'Firewall IPs — Las reglas muestran IPs numéricas en vez de hostnames (flag -n)',
                            'File Sync permisos — Los archivos sincronizados mantienen el propietario correcto en el slave',
                            'JS funciones faltantes — Añadidas 7 funciones JavaScript que faltaban en la UI de File Sync/Cluster',
                            'JS IDs inconsistentes — Corregidos IDs de HTML que no coincidían con los selectores de JavaScript',
                        ],
                        'en' => [
                            'SMTP encryption — Fixed encryption type (SSL→TLS/STARTTLS) and typo in From address',
                            'Firewall IPs — Rules show numeric IPs instead of hostnames (-n flag)',
                            'File Sync permissions — Synced files maintain correct owner on slave',
                            'Missing JS functions — Added 7 missing JavaScript functions in File Sync/Cluster UI',
                            'Inconsistent JS IDs — Fixed HTML IDs that didn\'t match JavaScript selectors',
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
