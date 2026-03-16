# Changelog

Todas las versiones notables de MuseDock Panel se documentan aqui.

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

### Por hacer
- **Multi-idioma panel** — Soporte ES/EN en toda la interfaz del panel

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
