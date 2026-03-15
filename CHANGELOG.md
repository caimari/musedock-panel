# Changelog

Todas las versiones notables de MuseDock Panel se documentan aqui.

## [0.3.0] — Pendiente

### Por hacer
- **Backups** — Backup/restore por cuenta (archivos + BD)
- **PHP por cuenta** — memory_limit, upload_max, etc. por hosting account (pool FPM individual)
- **Database management** — Crear/borrar BD MySQL/PostgreSQL por cuenta desde el panel
- **Fail2Ban** — Ver bans activos, desbloquear IPs, configurar jails
- **Logs browser** — Ver logs de Caddy/FPM por cuenta desde el panel
- **Multi-idioma panel** — El instalador ya es ES/EN, el panel necesita soporte i18n

---

## [0.2.0] — 2026-03-15

### Anadido
- **Settings > Servidor** — Info del servidor (IP, hostname, OS, uptime), zona horaria configurable, dominio del panel opcional, selector HTTP/HTTPS
- **Settings > PHP** — Configuracion global de php.ini por version (memory_limit, upload_max, post_max, max_execution_time, display_errors), lista de extensiones, reinicio FPM automatico
- **Settings > SSL/TLS** — Certificados activos de Caddy, politicas TLS, info Let's Encrypt
- **Settings > Seguridad** — IPs permitidas (edita .env en vivo), sesiones activas, info cookies
- **Tabs compartidos** — Navegacion unificada entre todas las secciones de Settings
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
