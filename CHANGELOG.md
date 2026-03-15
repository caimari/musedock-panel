# Changelog

Todas las versiones notables de MuseDock Panel se documentan aqui.

## [0.4.0] — Pendiente

### Anadido
- **Replicacion** — Configurar PostgreSQL streaming replication y MySQL replication desde el panel, monitor en tiempo real con auto-refresh cada 5s, promover/degradar servidores (switchover), test de conexion AJAX, cifrado de passwords, backup automatico de archivos de configuracion

### Por hacer
- **Multi-idioma panel** — Soporte ES/EN en toda la interfaz del panel

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
