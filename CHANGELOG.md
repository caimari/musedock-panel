# Changelog

Todas las versiones notables de MuseDock Panel se documentan aquí.

## [1.0.82] — 2026-04-24

### New
- Mail setup: selector de modo `Solo Envio (Satellite)`, `Correo Completo` y `SMTP Externo`, con explicaciones claras en la UI.
- Satellite mode: instalacion outbound-only con Postfix + OpenDKIM, sin Dovecot/Rspamd y sin abrir puertos de entrada.
- SMTP externo: guarda proveedor SMTP cifrado y genera `config/smtp-relay.json` para integraciones locales.
- `/mail`: nueva seccion de entregabilidad DNS con SPF, DKIM, DMARC, A, PTR/rDNS, blacklists y registros recomendados copiables.
- Endpoint local `GET /api/internal/smtp-config` para apps PHP/Laravel del mismo servidor, protegido por token y limitado a localhost.

### Improved
- Healthcheck de nodos mail: distingue `full`, `satellite` y `external`; Satellite/SMTP externo ya no se degradan por no tener Dovecot, DB de buzones ni puertos entrantes.
- Ejemplo `config/examples/laravel-mail-config.php` para consumir la configuracion SMTP desde apps Laravel locales.

## [1.0.81] — 2026-04-24

### New
- Mail node DB healthcheck: el worker comprueba PostgreSQL local, lectura real con `musedock_mail`, lag de replica, Maildir y PTR/rDNS en nodos con servicio `mail`.
- `/mail`: banners de alerta y columnas de salud DB/lag/PTR para detectar nodos de correo degradados aunque los puertos SMTP/IMAP sigan abiertos.
- Cola cluster: las acciones `mail_*` se pausan automaticamente cuando la DB local del nodo mail esta caida o el lag supera el umbral critico, y se reanudan al recuperar.

### Improved
- Acciones `mail_*` en `cluster_queue`: idempotency key para evitar duplicados pendientes por accion/nodo/dominio o mailbox.
- Documentado el procedimiento manual de failover PostgreSQL en `docs/FAILOVER.md`.

## [1.0.80] — 2026-04-24

### Improved
- `Settings → Cluster → Nodos`: nuevo boton de edicion rapida junto al nombre del nodo para cambiar la etiqueta visible.
- La edicion usa el endpoint existente `update-node` y solo modifica el nombre local; no toca URL, token, servicios ni configuracion remota del slave.

## [1.0.79] — 2026-04-24

### Improved
- `Settings → Cluster → Archivos`: las exclusiones base de sync ya son visibles y editables desde UI (`rsync/HTTPS` y `lsyncd`).
- `FileSyncService`: las exclusiones internas dejan de depender solo de constantes hardcodeadas; se cargan desde settings con defaults seguros como fallback.
- El calculo de `Esperado slave` usa las mismas exclusiones editables que el sync real, evitando diferencias entre lo que se sincroniza y lo que se compara.

## [1.0.78] — 2026-04-24

### Improved
- `/accounts`: el texto de la cabecera aclara que los datos de disco vienen de cache/BD y que no se ejecuta `du` en cada carga de pagina.
- `monitor-collector`: el calculo local de `disk_used_mb` pasa a ejecutarse cada 10 minutos para reducir carga.
- `filesync-worker`: refresco de disco remoto/esperado desacoplado del intervalo de sincronizacion; el slave real y el esperado se recalculan y persisten cada 10 minutos.

## [1.0.77] — 2026-04-24

### Improved
- `/accounts`: el resumen deja de mostrar numeros sueltos y pasa a mostrar metricas etiquetadas (`Hostings`, `Local`, `Slave real`, `Esperado slave`, `Estado replica`, `BW`).
- Estado de replica explicito: muestra `OK`, `Faltan X`, `Sobran X` o `Pendiente` segun la diferencia entre el tamano esperado en slave y el tamano real medido en slave.
- Cuando el calculo esperado aun no existe (antes del siguiente ciclo de `filesync-worker`), la UI muestra `Esperado slave: pendiente` en vez de ocultar la comparativa.

## [1.0.76] — 2026-04-24

### Improved
- `/accounts` header UX: acciones arriba y resumen debajo para evitar cabecera rota, botones desproporcionados y saltos de layout.
- `/accounts` (solo Master): comparativa de disco por slave con tres referencias claras:
  - `local` (master bruto),
  - `real slave` (medido por `du` remoto),
  - `estimado` (calculado en master con mismas exclusiones activas de sync).
- Indicador de gap `estimado vs real` por slave para detectar desviaciones reales de sincronizacion y no confundirlas con exclusiones esperadas.

### Fixed
- `filesync-worker` ahora persiste por nodo los totales `master_total_mb`, `master_replicable_mb` y `remote_total_mb` para alimentar `/accounts` con datos consistentes y auditables.

## [1.0.75] — 2026-04-24

### Improved
- `/accounts` (solo Master): ahora muestra totales de disco replicado por nodo slave (`cloud-arrow-down`) para comparar local vs replica sin confusión.
- Contexto de refresco en UI: se aclara que `disk_used_mb` local viene de cache BD (~5 min por `monitor-collector`) y que el total replicado se refresca en ciclos de `filesync-worker`.

### Fixed
- `filesync-worker`: persiste en `panel_settings` el total remoto por slave (`filesync_remote_total_mb_node_{id}`) y timestamp para que la vista no dependa de cálculos ad-hoc.

## [1.0.74] — 2026-04-23

### Fixed
- Cluster legacy-safe queue: `ClusterService` ahora detecta en runtime si existe `cluster_nodes.standby`; si falta en un nodo legacy, omite el filtro `n.standby` y evita el error `SQLSTATE[42703] column n.standby does not exist`.
- Compatibilidad de lectura en nodos mixtos: `getActiveNodes()` cae a `SELECT * FROM cluster_nodes` cuando el esquema aún no tiene standby, evitando ruptura del worker durante ventanas de actualización.

## [1.0.73] — 2026-04-23

### Fixed
- Cluster schema backfill: añade columnas `cluster_nodes.standby`, `standby_since` y `standby_reason` en nodos legacy actualizados para evitar errores `column n.standby does not exist` en `cluster-worker`.

## [1.0.72] — 2026-04-23

### Fixed
- Caddy mixed-mode hardening: el auto-repair del panel ya no inyecta `:8444` en `srv0`; usa servidor dedicado `srv_panel_admin` cuando aplica.
- Guard anti-clobber en nodos mixtos: si `PANEL_PORT` lo sirve un server externo del Caddyfile (ej. `srv1`), se omite la mutación runtime de rutas/políticas del panel.
- Panel routes target fix: `panel-fallback-route` y `panel-domain-route` se escriben solo en servidores gestionados por el panel (`srv0` legacy o `srv_panel_admin`).

### Improved
- Nueva variable opcional `.env`: `CADDY_PANEL_SERVER_NAME` para personalizar el server runtime dedicado del panel.

## [1.0.53] — 2026-04-22

### Security
- TLS interno endurecido para cluster/federation/backup/failover: eliminación de `CURLOPT_SSL_VERIFYPEER=false` y validación estricta (`VERIFYPEER=true`, `VERIFYHOST=2`) con soporte de CA/pinning (`tls_ca_file`, `tls_pin`) por nodo/peer.
- Cluster TLS auto-bootstrap: si un nodo privado falla por CA desconocida, el panel intenta autoconfigurar `tls_ca_file` de forma automática (vía export firmado en nodos nuevos o fallback TOFU de cadena TLS en nodos legacy) para evitar cortes operativos post-hardening.
- Bootstrap TLS de cluster endurecido: se elimina envío de token sobre cURL sin verificación; el flujo firmado usa CA semilla TOFU y validación TLS activa.
- Verificación local de dominio en federation API ajustada a `CURLOPT_RESOLVE` + TLS estricto (sin bypass de certificado a `127.0.0.1`).
- `musedock-fileop`: parser JSON migrado a esquema sin `eval` (KEY + base64), manteniendo filtros de metacaracteres y contención robusta de rutas.
- Backups: validación estricta de `backup_name/backup_id` (regex allowlist) en restore/delete/transfer/fetch remoto.
- Backups: verificación de ruta reforzada (`base` exacto o `base/*`) para evitar bypass por prefijo en checks con `realpath`.
- Transferencia a peers: opciones SSH endurecidas con validación de puerto/ruta de clave y builder centralizado.
- Workers de backup (`backup-worker.php`, `backup-transfer-worker.php`): sanitización de argumentos CLI críticos (`backup_name`, `transfer_method`, `scope`).
- Restore backup: normalización de versión PHP antes de reiniciar `phpX.Y-fpm`.

### Improved
- Cluster UI: nueva visibilidad del estado TLS por nodo (pin/CA/auto, vencimiento y detalle), en tabla y modal de estado.
- `cluster-worker`: alertas proactivas de TLS (warning/crítico por expiración de CA) con throttling y alerta de recuperación cuando vuelve a estado normal.
- Monitoring: carga inicial de `/monitor` optimizada (charts secundarios diferidos), refresco de cards más ligero y menos polling redundante.
- Monitoring: `api/realtime` acelerada (sample 250ms + micro-cache 2s) para reducir latencia percibida y carga cuando hay varias vistas abiertas.

## [1.0.52] — 2026-04-22

### Security
- Login admin (`/login/submit`) ahora valida CSRF en backend (antes el token no se comprobaba en ese endpoint).
- Rate limit de login admin: 20 intentos/minuto por IP para mitigar fuerza bruta.
- Resolución de IP de cliente centralizada y segura (`X-Forwarded-For` solo si el request llega desde proxy local).
- Endurecimiento de ejecución de comandos en migración/federación:
  - `pkill` ahora usa patrón escapado en `FederationMigrationService` (evita inyección por username).
  - `systemctl reload phpX.Y-fpm` ahora valida versión PHP antes de componer comando.
  - opciones SSH (`-i key`) ahora pasan por `escapeshellarg` en todos los flujos de sync DB.
  - migración de BD de subdominios endurecida con sanitización estricta de `db_name/db_user` y escape en import MySQL.

## [1.0.51] — 2026-04-03

### New
- Firewall: reglas iptables manuales (fuera de UFW) ahora visibles en el panel
- Firewall: auditoria de puertos sensibles (SSH, panel, portal, MySQL, PostgreSQL, Redis) abiertos a internet

## [1.0.50] — 2026-04-03

### New
- Fail2Ban: boton "Configurar Jails" cuando no hay jails activos (auto-config de panel, portal, WordPress)

## [1.0.49] — 2026-04-03

### New
- Fail2Ban instalable desde web con spinner de progreso y auto-config de jails
- Health: instalar binarios faltantes via apt con boton AJAX y spinner

### Improved
- WireGuard: spinner en boton de instalar
- Health: spinner en todos los botones de reparacion (cron, timezone, BD)

## [1.0.41] — 2026-04-03

### Improved
- Crons escalonados: todos los crons arrancan en segundos/minutos distintos (thundering herd fix)
- Monitor CPU real: sample sin sleep aleatorio ni auto-medicion
- update.sh automatiza escalonamiento de crons en cada actualizacion
- du cada 5 min en vez de 30s

## [1.0.40] — 2026-04-03

### New
- Migracion automatica Nginx/Apache a Caddy
- Import crea ruta Caddy automaticamente
- Descubrimiento de sitios desde rutas Caddy

## [1.0.39] — 2026-04-03

### New
- Web Stats per hosting — AWStats-like page (top pages, IPs, countries, referrers, browsers/bots, HTTP codes)
- Bandwidth IN (uploads) via bytes_read + real visitor IP (Cf-Connecting-Ip / X-Forwarded-For / remote_ip)
- Fail2Ban: disable button per jail, banned IPs modal with unban/whitelist, config info visible

### Fixed
- Fail2Ban WordPress filter now uses Cf-Connecting-Ip instead of client_ip (was banning Cloudflare IPs)
- CPU collector self-measurement: sample taken before any work
- du runs every 5 min instead of 30s

## [1.0.38] — 2026-04-02

### New
- Ancho de banda por hosting — Parseo de logs Caddy cada 10 min, acumulado por cuenta/dia en DB
- Ancho de banda por subdominio — Trafico individual visible en acordeon del listado
- Columna BW en listado + grafica Chart.js en Account Details (30d/12m/Anual)
- Totales globales (disco + BW) en barra superior del listado
- Columnas ordenables (click en headers) con subdominios que siguen a su padre
- Dashboard cards CPU/RAM/Disk en tiempo real (3s) + modal de disco

### Improved
- du-throttled — Limita du al 50% de un core via SIGSTOP/SIGCONT

## [1.0.37] — 2026-04-02

### New
- Subdominios — Pagina de edicion individual con Document Root y ajustes PHP independientes
- Subdominios — Suspender/activar con pagina de mantenimiento Caddy. Eliminar solo cuando esta suspendido
- Subdominios — Acordeon en el listado de Hosting Accounts
- Cloudflare DNS — Seleccion masiva (checkboxes): eliminar, toggle proxy, edicion masiva
- Cloudflare DNS — Modal de confirmacion al toggle proxy
- Cloudflare DNS — Crear/editar registros en modal SweetAlert
- Monitor — Cards CPU/RAM abren modal de procesos
- Monitor — Cards de red abren modal con detalle (velocidad RT, IPs, MTU, errores)
- Monitor — Cards de disco abren modal con detalle (filesystem, inodes, top directorios)
- Monitor — Cards actualizadas en tiempo real cada 3s

### Improved
- CPU real desde /proc/stat en vez de load average (dashboard, monitor, collector)
- RAM real con MemAvailable en vez de "used" de free
- du -sm con nice -n 19 ionice -c3 para no afectar rendimiento
- Deteccion de estado WireGuard (up en vez de unknown)
- Tabla de subdominios con text-overflow ellipsis

### Fixed
- Caddy route ID collision — IDs basados en dominio en vez de username. Subdominios ya no colisionan
- Cloudflare zona duplicada — CMS ya no crea zonas en Cuenta 2 si existe en Cuenta 1
- Boton editar DNS fallaba con registros con comillas (TXT, DKIM)

## [1.0.36] — 2026-04-01

### Anadido
- **Fail2Ban integrado** — Proteccion contra fuerza bruta para panel admin, portal de clientes y WordPress, todo gestionado desde el panel sin plugins
- **Jail musedock-panel** — Banea IPs tras 5 intentos fallidos de login al panel admin en 10 minutos (ban 1h, puerto 8444)
- **Jail musedock-portal** — Banea IPs tras 10 intentos fallidos de login al portal de clientes en 10 minutos (ban 30min, puerto 8446)
- **Jail musedock-wordpress** — Banea IPs tras 10 POSTs a wp-login.php o xmlrpc.php en 5 minutos (ban 1h, puertos 80/443). Automatico para todos los hostings, sin necesidad de plugin en WordPress
- **Auth logging** — Los intentos de login (exitosos y fallidos) del panel y portal se escriben a /var/log/musedock-panel-auth.log y /var/log/musedock-portal-auth.log con IP real del cliente
- **IP real tras Caddy** — Nuevo metodo `getClientIp()` en ambos AuthController que extrae la IP real de X-Forwarded-For en vez de REMOTE_ADDR (siempre 127.0.0.1 tras reverse proxy)
- **Caddy access logging para hostings** — Nuevo metodo `SystemService::ensureHostingAccessLog()` que registra dominios en el logger de Caddy via API. Se ejecuta automaticamente al crear o reparar rutas de hosting
- **Banear IP manualmente** — Nuevo boton en Settings > Fail2Ban para banear una IP en cualquier jail
- **Whitelist (ignoreip)** — Gestion de IPs que nunca se banean desde el panel, con soporte para IPs individuales y rangos CIDR. Escribe en /etc/fail2ban/jail.local
- **Boton Whitelist en IPs baneadas** — Cada IP baneada tiene boton para desbanear y anadir a whitelist en un click
- **Configs en el repo** — Filtros, jails y logrotate en config/fail2ban/ para distribucion automatica via git
- **Instalador (install.sh)** — Nuevo Step 7c que instala filtros, jails, log files y logrotate de Fail2Ban. En modo update tambien sincroniza configs
- **Updater (update.sh)** — Sincroniza automaticamente configs de Fail2Ban si hay cambios, solo recarga si es necesario
- **repair-caddy-routes.php** — Ahora registra dominios reparados para Caddy access logging (Fail2Ban WordPress)

### Corregido
- **Portal IP siempre 127.0.0.1** — El RateLimiter del portal ahora usa la IP real del cliente en vez de la IP de Caddy

---

## [0.6.0] — 2026-03-17

### Añadido
- **Cluster tabs** — La página de Cluster se reorganizó en 6 pestañas: Estado, Nodos, Archivos, Failover, Configuración, Cola. Cada pestaña incluye descripción explicativa y dependencias
- **Sincronización Completa** — Botón orquestador en pestaña Estado que ejecuta en secuencia: hostings (API) → archivos (rsync) → bases de datos (dump) → certificados SSL. Detecta automáticamente qué está configurado y avisa si falta SSH
- **Endpoint full-sync** — `POST /settings/cluster/full-sync` lanza proceso en background (`fullsync-run.php`) con progreso en tiempo real via AJAX polling
- **DB dump sync (Nivel 1)** — Sincronización simple de bases de datos entre master y slave usando `pg_dump`/`mysqldump` comprimidos con gzip. Se restauran automáticamente en el slave con `DROP + CREATE + IMPORT`. Configurable en pestaña Archivos
- **DB dump en sync manual** — La sincronización manual de archivos ahora también incluye dump y restauración de bases de datos si está habilitado
- **DB dump periódico** — El cron `filesync-worker` ahora incluye dumps de BD cada intervalo si está habilitado. Se omite automáticamente si streaming replication (Nivel 2) está activo
- **isStreamingActive()** — Nuevo método en ReplicationService que detecta si la replicación streaming de PostgreSQL o MySQL está activa, consultando `pg_stat_wal_receiver` y `SHOW REPLICA STATUS`
- **restore-db-dumps** — Nueva acción en la API del cluster para que el slave restaure los dumps recibidos. Crea usuarios de BD si no existen (`CREATE ROLE IF NOT EXISTS` / `CREATE USER IF NOT EXISTS`)
- **Backup pre-replicación** — Al convertir un servidor a slave de streaming replication, se crea automáticamente un backup de todas las bases de datos en `/var/backups/musedock/pre-replication/` con timestamp. Checkbox en el modal para activar/desactivar
- **Modal convert-to-slave mejorado** — El modal ahora muestra aviso en rojo explicando que se borrarán TODAS las bases de datos locales, lista las BD afectadas, y tiene checkbox de backup automático (activado por defecto)
- **Failover con select de nodos** — El campo de IP manual para degradar a slave se reemplazó por un selector desplegable de nodos conectados con nombre e IP
- **Failover con password** — Tanto "Promover a Master" como "Degradar a Slave" ahora requieren contraseña de administrador con validación AJAX antes de ejecutar. Modales detallados explicando las implicaciones de cada operación
- **System Users** — Nueva sección de solo lectura mostrando todos los usuarios del sistema Linux (UID, grupos, shell, home). Root visible pero no editable
- **Hosting repair on re-sync** — Si un hosting ya existe en el slave, se repara (UID, shell, grupos, password hash, caddy_route_id) en vez de saltarlo
- **SSL cert detection en slave** — El panel ahora detecta certificados SSL copiados del master via Caddy admin API (`localhost:2019`) y filesystem, mostrando candado azul si el cert existe aunque el DNS no apunte al servidor
- **Sync progress modal** — Modal con barra de progreso, cronómetro y dominio actual durante la sincronización. Persiste tras recargar página con sessionStorage
- **Auto-configurar replicación en slave** — Botón "Convertir este nodo en Slave de X" con modal de advertencia, backup automático y verificación de contraseña
- **Nodo virtual en slave** — Si el slave no tiene nodos de cluster registrados pero conoce la IP del master, muestra un nodo virtual para auto-configurar

### Corregido
- **Tildes en español** — Corregidas todas las tildes faltantes en la página de Cluster (más de 50 correcciones en HTML y JavaScript)
- **Caddy Route N/A en slave** — `caddy_route_id` ahora se incluye en el payload de sincronización de hostings
- **JSON sync modal** — Corregido mismatch de campos entre backend (`synced`/`failed`) y frontend (`ok_count`/`fail_count`)
- **filesync-run.php bootstrap** — Corregido error de archivo no encontrado usando bootstrap inline como `cluster-worker.php`
- **rsync --delete en certs** — Los certificados del slave ya no se borran al sincronizar (opción `no_delete`)
- **DROP DATABASE con conexiones activas** — Añadido `pg_terminate_backend` + `DROP DATABASE WITH (FORCE)` con fallback para PG < 13
- **Session key en verify-admin-password** — Corregido `$_SESSION['admin_id']` inexistente por `$_SESSION['panel_user']['id']` en verificación de contraseña del cluster
- **Auto-configure en slave sin nodos** — El botón de auto-configurar ahora aparece en el slave usando nodo virtual del master

---

## [0.5.3] — 2026-03-16

### Anadido
- **File Sync** — Sincronizacion de archivos entre master y slaves via SSH (rsync) o HTTPS (API), con cron worker automatico (`musedock-filesync`)
- **File Sync SSL certs** — Sincronizacion de certificados SSL de Caddy entre nodos con propiedad correcta (`caddy:caddy`)
- **File Sync ownership** — rsync con `--chown` y HTTPS con `owner_user` para corregir UIDs entre servidores
- **File Sync UI** — Botones funcionales en Cluster: generar clave SSH, instalar en nodo, test SSH, sincronizar ahora, verificar DB host
- **SSH info banner** — Nota explicativa en la pagina de Cluster con el flujo de 3 pasos para configurar claves SSH
- **Firewall protocolos** — Los protocolos ahora se muestran como texto (TCP, UDP, ICMP, ALL) en vez de numeros (6, 17, 1, 0)
- **Firewall descripcion** — Nueva columna "Descripcion" en iptables mostrando estado (RELATED,ESTABLISHED, etc.)
- **Firewall protocolo ALL** — Opcion "Todos" en el selector de protocolo para reglas sin puerto especifico
- **Cifrado Telegram** — Token de Telegram cifrado con AES-256-CBC en panel_settings
- **Cifrado SMTP** — Password SMTP cifrado con AES-256-CBC en panel_settings
- **Instalador Update** — Nuevo modo "Actualizar" (opcion 4) que aplica cambios incrementales sin reinstalar (crons, migraciones, permisos, .env)
- **Instalador filesync cron** — El cron `musedock-filesync` se instala automaticamente con el instalador
- **SSL cert auto-fill** — La ruta de certificados SSL se auto-rellena si se detecta Caddy (antes solo placeholder)

### Corregido
- **SMTP cifrado** — Corregido tipo de cifrado (SSL→TLS/STARTTLS) y typo en direccion From
- **Firewall IPs** — Las reglas muestran IPs numericas en vez de hostnames (flag `-n`)
- **File Sync permisos** — Los archivos sincronizados mantienen el propietario correcto en el slave
- **JS funciones faltantes** — Añadidas 7 funciones JavaScript que faltaban en la UI de File Sync/Cluster
- **JS IDs inconsistentes** — Corregidos IDs de HTML que no coincidian con los selectores de JavaScript

---

## [0.5.2] — 2026-03-16

### Anadido
- **Notificaciones** — Nueva pestaña Settings > Notificaciones con Email (SMTP/PHP mail) y Telegram unificados
- **Email SMTP avanzado** — Selector de cifrado STARTTLS/SSL/Ninguno, test de envio inline con AJAX
- **Email destinatario inteligente** — Por defecto usa el email del perfil del admin, con override manual opcional
- **Firewall editable** — Las reglas del firewall ahora se pueden editar (antes solo eliminar), modal de edicion
- **Firewall interfaces de red** — Muestra todas las interfaces del servidor con IP real (ya no 127.0.0.1)
- **Firewall direccion** — Columna IN/OUT visible en el listado de reglas
- **Databases multi-instancia** — Vista muestra PostgreSQL Hosting (5432, replicable), PostgreSQL Panel (5433) y MySQL agrupados
- **Databases reales** — Lista todas las bases de datos reales del sistema, no solo las gestionadas por el panel
- **Activity Log filtros** — Busqueda por texto, filtro por accion y por admin, paginacion de 50 registros
- **Activity Log limpieza** — Boton para limpiar logs antiguos (7/30/90/180 dias) y vaciar todo con verificacion de password
- **Visor de logs limpieza** — Boton para vaciar archivos de log individuales con confirmacion SweetAlert
- **Caddy access logs** — Los logs de acceso de Caddy por dominio ahora aparecen en el visor de logs

### Corregido
- **Firewall reglas DENY** — Las reglas DENY/REJECT ahora aparecen correctamente en el listado (regex corregido)
- **Firewall borde blanco** — Eliminado borde blanco de la tabla de interfaces de red
- **Email remitente** — El From por defecto ahora usa el email del admin (antes usaba panel@hostname)
- **Email destinatario** — Corregido "No hay email configurado" (query filtraba role=admin en vez de superadmin)
- **PHP mail() error claro** — Muestra mensaje especifico cuando sendmail/postfix no esta instalado
- **Session key password** — Corregido `$_SESSION['admin_id']` inexistente por `$_SESSION['panel_user']['id']` en verificacion de password (Vaciar todo logs y Eliminar BD)
- **Icono busqueda invisible** — Añadido color blanco al icono de lupa en Activity Log
- **Notificaciones migradas** — Config SMTP/Telegram movida de Cluster a nueva pestaña Notificaciones con migracion automatica

---

## [0.5.0] — 2026-03-16

### Anadido
- **Cluster multi-servidor** — Arquitectura master/slave entre paneles, API bidireccional con autenticacion por token Bearer
- **Cluster API** — Endpoints `/api/cluster/status`, `/api/cluster/heartbeat`, `/api/cluster/action` para comunicacion entre nodos
- **Sincronizacion de hostings** — Cola de sincronizacion (cluster_queue) para propagar creacion/eliminacion/suspension de cuentas entre nodos
- **Heartbeat y monitoreo** — Polling automatico de nodos, deteccion de nodos caidos, alertas por email (SMTP) y Telegram
- **Failover** — Promover slave a master y degradar master a slave desde el panel, actualiza PANEL_ROLE en .env
- **Worker cron** — `bin/cluster-worker.php` para procesar cola, heartbeats, alertas y limpieza automatica
- **ApiAuthMiddleware** — Autenticacion por token para rutas /api/*, separada de la autenticacion por sesion
- **Instalador dual PostgreSQL** — Deteccion automatica de cluster existente, 3 escenarios (instalacion limpia en 5433, migracion de 5432 a 5433, ya migrado)
- **PANEL_ROLE en .env** — Rol del servidor (standalone/master/slave) almacenado en .env para evitar sobreescritura durante sincronizacion

---

## [0.4.0] — 2026-03-15

### Anadido
- **Replicacion avanzada** — Multiples slaves por master, IPs dual (primaria + fallback WireGuard), modo sincrono/asincrono por slave, replicacion logica PostgreSQL (seleccionar BDs), GTID MySQL, monitor multi-slave en tiempo real
- **WireGuard VPN** — Instalar, configurar interfaz wg0, CRUD de peers, generar claves, generar config remota, ping/latencia, aplicar sin reiniciar (wg syncconf)
- **Firewall** — Auto-deteccion UFW/iptables, ver/añadir/eliminar reglas, enable/disable UFW, guardar iptables, boton de emergencia "permitir mi IP", sugerencias automaticas para replicacion y hosting

---

## [0.3.0] — 2026-03-15

### Anadido
- **Backups** — Backup/restore por cuenta (archivos + BD MySQL/PostgreSQL), descarga directa, eliminacion con confirmacion de password
- **Fail2Ban** — Ver jails activos, IPs baneadas, desbloquear IPs desde el panel
- **Visor de logs** — Logs de Caddy, FPM, cuentas y sistema con navegacion por archivo
- **Base de datos PostgreSQL** — Crear/eliminar BD PostgreSQL por cuenta desde el panel
- **Base de datos protegida** — La BD del sistema (musedock_panel) se muestra como protegida y no se puede borrar
- **Confirmacion con password** — Borrar BD y backups requiere password del admin
- **Changelog** — Pagina de versiones dentro del panel con toggle ES/EN, version clickeable en sidebar

---

## [0.2.0] — 2026-03-15

### Anadido
- **Settings > Servidor** — Info del servidor (IP, hostname, OS, uptime), zona horaria configurable, dominio del panel opcional, selector HTTP/HTTPS
- **Settings > PHP** — Configuracion global de php.ini por version (memory_limit, upload_max, post_max, max_execution_time, display_errors), lista de extensiones, reinicio FPM automatico
- **Settings > SSL/TLS** — Certificados activos de Caddy, politicas TLS, info Let's Encrypt
- **Settings > Seguridad** — IPs permitidas (edita .env en vivo), sesiones activas, info cookies
- **Tabs compartidos** — Navegacion unificada entre todas las secciones de Settings
- **PHP por cuenta** — memory_limit, upload_max, post_max, max_execution_time por hosting account via pool FPM
- **Gestion de bases de datos MySQL** — Crear/eliminar BD MySQL por cuenta, usuarios con prefijo
- **Tabla `panel_settings`** — Almacen clave-valor en BD para configuracion del panel
- **Tabla `servers`** — Preparacion para clustering (localhost por defecto)
- **Campo `server_id`** en `hosting_accounts` — Preparacion para multi-servidor
- **Instalador HTTPS** — Caddy como reverse proxy con certificado autofirmado (tls internal)
- **Instalador i18n** — Seleccion de idioma ES/EN al inicio, todos los textos traducidos
- **Instalador firewall** — Deteccion de UFW/iptables, estado del puerto, reglas ACCEPT all por IP
- **Instalador verify mode** — Opcion "solo verificar" que ejecuta health check sin tocar nada
- **Health check mejorado** — Prueba HTTPS, HTTP interno y HTTP directo en secuencia
- **Deteccion de conflictos** — nginx, Apache, Plesk con opciones interactivas
- **Snapshot pre-instalacion** — Backup de servicios, puertos, configs antes de instalar
- **Desinstalador** — `bin/uninstall.sh` con verificacion de hosting activo y confirmaciones paso a paso

### Corregido
- **Login HTTP** — Cookie `Secure` solo se activa en HTTPS (antes bloqueaba login en HTTP)
- **HSTS condicional** — Header solo en conexiones HTTPS
- **Favicon** — Eliminado punto verde, solo muestra la M
- **Health check** — `curl -w` limpieza de output, fallback multi-URL
- **Firewall iptables** — Deteccion correcta de `policy DROP` y reglas `ACCEPT all` por IP
- **Textos en ingles** — Todos los mensajes del instalador usan `t()` para i18n

---

## [0.1.0] — 2026-03-14

### Anadido
- **Dashboard** — CPU, RAM, disco, hosting accounts, system info, actividad reciente
- **Hosting Accounts** — Crear, suspender, activar, eliminar cuentas con usuario Linux, pool FPM y ruta Caddy
- **Dominios** — Gestion de dominios por cuenta, verificacion DNS, alias
- **Clientes** — Vincular cuentas de hosting a clientes
- **Settings > Servicios** — Iniciar/detener/reiniciar Caddy, MySQL, PostgreSQL, PHP-FPM, Redis, Supervisor
- **Settings > Cron** — CRUD de tareas cron por usuario
- **Settings > Caddy** — Visor de rutas API, politicas TLS, config raw JSON
- **Activity Log** — Historial de todas las acciones del admin
- **Perfil** — Cambiar usuario, email, contraseña
- **Setup wizard** — Asistente de primera configuracion (como WordPress)
- **Tema oscuro** — Interfaz moderna con Bootstrap 5
- **Seguridad** — CSRF, sesiones seguras, prevencion de inyeccion, headers de seguridad
- **Instalador automatizado** — `install.sh` con deteccion de OS, instalacion de dependencias, PostgreSQL, Caddy, MySQL
- **Servicio systemd** — `musedock-panel.service` con restart automatico
