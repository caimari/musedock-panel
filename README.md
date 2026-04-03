# MuseDock Panel

Panel de administracion de hosting ligero, construido con PHP puro. Sin frameworks, sin Composer, sin npm — menos de 700 KB.

Gestiona cuentas de hosting Linux con aprovisionamiento automatico de usuarios del sistema, pools PHP-FPM, rutas del servidor web Caddy y certificados SSL.

> **Nota:** MuseDock Panel es un **panel de administracion de sistemas** — no tiene relacion con MuseDock CMS (el sistema de gestion de contenidos multi-tenant). Sin embargo, ambos productos estan diseñados para convivir en el mismo servidor: MuseDock Panel puede ver y gestionar las rutas de Caddy creadas por los tenants de MuseDock CMS, y ambos pueden funcionar juntos sin conflictos.

## Caracteristicas

- **Cuentas de Hosting** — Crear, suspender, activar y eliminar cuentas de hosting Linux
- **Integracion con Caddy** — Creacion automatica de rutas, SSL/TLS via Let's Encrypt, paginas de mantenimiento
- **Gestion de PHP-FPM** — Pools FPM por cuenta con versiones de PHP configurables (7.4–8.4)
- **Herramienta de Migracion** — Importar sitios desde servidores remotos via SSH/rsync con migracion de base de datos
- **Gestion de Clientes** — Vincular cuentas de hosting a clientes
- **Gestion de Dominios** — Verificacion DNS, alias de dominio
- **Control de Servicios** — Iniciar/detener/reiniciar servicios del sistema (Caddy, PHP-FPM, MySQL, PostgreSQL)
- **Gestor de Cron** — Ver, crear, editar y eliminar tareas cron para cualquier usuario del sistema
- **Registro de Actividad** — Historial completo de todas las acciones del administrador
- **Tema Oscuro** — Interfaz moderna y responsive con Bootstrap 5

## Requisitos

| Software | Version |
|----------|---------|
| Linux | Ubuntu 22.04+ / Debian 12+ |
| PHP | 8.0+ (recomendado 8.3) |
| PostgreSQL | 12+ |
| Caddy | 2.x |
| MySQL/MariaDB | 8.0+ / 10.6+ (para bases de datos de clientes) |

**Extensiones PHP:** pdo_pgsql, curl, mbstring, xml, zip, gd, intl, bcmath, mysql

## Instalacion

### Automatica (recomendada)

```bash
git clone https://github.com/caimari/musedock-panel.git /opt/musedock-panel
cd /opt/musedock-panel
sudo bash install.sh   # requiere root — el instalador lo verifica
```

El instalador:

1. Detecta el sistema operativo e instala las dependencias (PHP 8.3, PostgreSQL, Caddy, MySQL)
2. Crea la base de datos PostgreSQL y ejecuta el esquema
3. Genera el archivo de configuracion `.env`
4. Configura Caddy con la API de administracion y persistencia de rutas
5. Crea e inicia un servicio systemd en el puerto 8444
6. Muestra la URL del panel

Despues de la instalacion, abre `https://IP-DEL-SERVIDOR:8444` en tu navegador. Un asistente de configuracion (similar a WordPress) te guiara para crear la cuenta de administrador.

### Instalacion en servidor con Nginx/Apache existente

Si el servidor ya tiene Nginx o Apache con sitios en produccion, el instalador gestiona la migracion automaticamente:

1. **Detecta** Nginx/Apache corriendo en puertos 80/443
2. **Parsea** los configs de `sites-enabled` — extrae dominio, document root, version PHP y usuario
3. **Pregunta** si quieres migrar los sitios detectados a Caddy
4. **Para** Nginx/Apache (con backup completo de la config) y arranca Caddy
5. **Crea las rutas Caddy** equivalentes — los sitios siguen funcionando sin downtime

Despues de la instalacion, importa los sitios migrados al panel desde **Panel > Hosting Accounts > Importar**. El import detecta tanto los vhosts en `/var/www/vhosts/` como los sitios migrados en cualquier otro directorio (via las rutas activas de Caddy), y crea automaticamente la ruta Caddy si no existe.

> **Nota:** El instalador respeta el document root original de cada sitio (ya sea `/var/www/html`, `/home/user/public_html`, o cualquier otra ruta). No mueve archivos ni modifica la estructura de directorios existente.

### Manual

```bash
# 1. Clonar
git clone https://github.com/caimari/musedock-panel.git /opt/musedock-panel

# 2. Crear directorios
mkdir -p /opt/musedock-panel/storage/{sessions,logs,cache}
mkdir -p /var/www/vhosts

# 3. Instalar dependencias
apt install -y php8.3 php8.3-{pgsql,curl,mbstring,xml,zip,gd,intl,bcmath,mysql} \
               postgresql caddy mysql-server

# 4. Configurar entorno
cp /opt/musedock-panel/.env.example /opt/musedock-panel/.env
nano /opt/musedock-panel/.env
chmod 600 /opt/musedock-panel/.env

# 5. Crear base de datos PostgreSQL
sudo -u postgres createuser musedock_panel
sudo -u postgres createdb -O musedock_panel musedock_panel
sudo -u postgres psql -c "ALTER USER musedock_panel PASSWORD 'tu-password-segura';"
psql -U musedock_panel -d musedock_panel -f /opt/musedock-panel/database/schema.sql

# 6. Iniciar el panel
php -S 0.0.0.0:8444 -t /opt/musedock-panel/public /opt/musedock-panel/public/router.php
```

Para produccion, usa el servicio systemd en lugar de `php -S`:

```bash
# Copiar y configurar el archivo de servicio
sed -e "s|__PANEL_DIR__|/opt/musedock-panel|g" \
    -e "s|__PANEL_PORT__|8444|g" \
    /opt/musedock-panel/bin/musedock-panel.service > /etc/systemd/system/musedock-panel.service

systemctl daemon-reload
systemctl enable --now musedock-panel
```

En la primera visita, el asistente web te guiara para crear la cuenta de administrador.

## Seguridad

> **El panel se ejecuta como root** porque gestiona usuarios del sistema, pools FPM y servicios.
> Protege el puerto del panel con un firewall — solo las IPs de administradores de confianza deberian tener acceso.

### Firewall (ejemplo con UFW)

```bash
# Permitir solo tu IP
ufw allow from TU_IP to any port 8444

# Bloquear el resto
ufw deny 8444
```

### Restriccion por IP

Tambien puedes restringir el acceso en `.env`:

```env
ALLOWED_IPS=1.2.3.4,5.6.7.8
```

### Caracteristicas de seguridad

- Proteccion CSRF en todos los formularios POST
- Sesiones seguras (cookies Secure, HttpOnly, SameSite=Strict)
- Prevencion de fijacion de sesion (regeneracion en login)
- Prevencion de inyeccion de comandos (escapeshellarg + validacion de entrada)
- Prevencion de inyeccion SQL (PDO con sentencias preparadas)
- Cabeceras de seguridad (HSTS, X-Frame-Options, X-Content-Type-Options)
- Hash de contraseñas con bcrypt
- Prevencion de redireccion abierta

## Arquitectura

```
/opt/musedock-panel/
├── app/
│   ├── Controllers/       # Controladores de peticiones
│   ├── Middleware/         # Autenticacion + validacion CSRF
│   ├── Services/          # SystemService (Linux/Caddy/FPM)
│   ├── Auth.php           # Autenticacion
│   ├── Database.php       # Wrapper PDO
│   ├── Env.php            # Parser de .env
│   ├── Router.php         # Enrutamiento de URLs
│   └── View.php           # Motor de plantillas + CSRF
├── bin/
│   ├── migrate.php              # Ejecutor de migraciones
│   ├── update.sh                # Actualizador con un comando
│   ├── uninstall.sh             # Desinstalador seguro
│   ├── musedock-panel.service   # Unidad systemd
│   └── musedock-panel.sh        # Script de inicio/parada
├── config/
│   └── panel.php          # Configuracion (lee de .env)
├── database/
│   ├── schema.sql         # Esquema completo de la base de datos
│   └── migrations/        # Migraciones incrementales
├── public/
│   ├── index.php          # Punto de entrada
│   └── router.php         # Router para servidor PHP integrado
├── resources/views/       # Plantillas PHP
├── storage/               # Sesiones, logs, cache
├── .env.example           # Plantilla de entorno
├── install.sh             # Instalador automatizado
└── README.md
```

## Configuracion

Toda la configuracion esta en `.env`. Consulta `.env.example` para ver todas las opciones disponibles.

| Variable | Por defecto | Descripcion |
|----------|-------------|-------------|
| `PANEL_PORT` | `8444` | Puerto de la interfaz web del panel |
| `DB_HOST` | `127.0.0.1` | Host de PostgreSQL |
| `DB_NAME` | `musedock_panel` | Nombre de la base de datos PostgreSQL |
| `DB_USER` | `musedock_panel` | Usuario de PostgreSQL |
| `DB_PASS` | — | Contraseña de PostgreSQL |
| `CADDY_API_URL` | `http://localhost:2019` | Endpoint de la API de administracion de Caddy |
| `FPM_PHP_VERSION` | `8.3` | Version de PHP por defecto para nuevas cuentas |
| `VHOSTS_DIR` | `/var/www/vhosts` | Directorio base para las cuentas de hosting |
| `ALLOWED_IPS` | — | IPs de administradores permitidas (separadas por coma) |

## Actualizacion

Un solo comando para actualizar cualquier instalacion a la ultima version:

```bash
sudo bash /opt/musedock-panel/bin/update.sh
```

Esto:

1. Descarga el codigo mas reciente desde GitHub (`git pull`)
2. Ejecuta las migraciones de base de datos pendientes automaticamente
3. Limpia la cache
4. Reinicia el servicio del panel

**Se puede ejecutar en cualquier momento** — nunca toca `.env`, `storage/` ni los datos existentes. Las migraciones usan `IF NOT EXISTS` y transacciones, asi que son idempotentes.

### Comandos de migracion

```bash
# Ver estado de todas las migraciones
php bin/migrate.php --status

# Listar migraciones pendientes
php bin/migrate.php --pending

# Ejecutar migraciones pendientes manualmente
php bin/migrate.php
```

Las migraciones tambien se ejecutan automaticamente en cada peticion al panel, asi que en la mayoria de los casos no necesitas ejecutarlas manualmente.

## Desinstalacion

Un solo comando para desinstalar el panel de forma segura:

```bash
sudo bash /opt/musedock-panel/bin/uninstall.sh
```

El desinstalador es interactivo y **no borra nada sin preguntar**. Paso a paso:

1. **Comprueba hosting activo** — Si hay cuentas de hosting con sitios en produccion, avisa cuantas hay, lista los dominios y pregunta si continuar. Los sitios seguiran funcionando (Caddy y PHP-FPM no se tocan), pero ya no podras gestionarlos desde el panel.
2. **Para el servicio** — Detiene y deshabilita `musedock-panel` de systemd.
3. **Limpia Caddy** — Pregunta si quieres eliminar las rutas API del panel y/o el override `--resume`. Por defecto no toca nada.
4. **Base de datos** — Pregunta si quieres conservar o eliminar la base de datos `musedock_panel`. Eliminar requiere escribir `DELETE` como confirmacion.
5. **Elimina configuracion** — Borra `.env` (con backup automatico), limpia sesiones y cache.
6. **Limpia sistema** — Elimina entradas de sudoers y logrotate si existen.

### Que se conserva siempre

- Caddy, PostgreSQL, MySQL, PHP (servicios compartidos)
- Sitios de clientes en `/var/www/vhosts/`
- Logs del panel en `storage/logs/`
- Snapshots de instalacion en `install-backup/`
- Codigo fuente del panel (para poder reinstalar)

### Reinstalar despues de desinstalar

```bash
sudo bash /opt/musedock-panel/install.sh
```

Si conservaste la base de datos, el panel recuperara toda la configuracion anterior.

## Gestion del servicio

```bash
# Estado
systemctl status musedock-panel

# Reiniciar
systemctl restart musedock-panel

# Logs
journalctl -u musedock-panel -f
tail -f /opt/musedock-panel/storage/logs/panel.log
```

## Hoja de ruta

- [x] Modo cluster (replicacion master/slave)
- [x] Migracion automatica desde Nginx/Apache
- [ ] Sistema de backups
- [ ] API para integraciones externas
- [ ] Soporte multi-idioma
- [ ] Autenticacion de dos factores

## Licencia

**Source Available — Uso no comercial**

MuseDock Panel es gratuito para uso personal y educativo. El uso comercial (venta de hosting, ofrecerlo como SaaS, integrarlo con productos de pago) requiere una licencia comercial separada.

Las funcionalidades avanzadas como el modo cluster estan disponibles bajo licencia Pro.

Consulta [LICENSE](LICENSE) para los terminos completos. Para licencias comerciales: info@musedock.com
