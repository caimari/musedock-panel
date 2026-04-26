# MuseDock Panel

Panel de administracion de servidores y hosting en PHP, orientado a operacion real en produccion: seguridad, monitoreo, firewall, fail2ban, cluster y automatizacion.

MuseDock Panel y MuseDock CMS son productos distintos. El panel esta enfocado en administracion del host y servicios; el CMS en gestion de contenido multi-tenant.

## Estado actual del proyecto

El panel ha evolucionado de forma importante y ya incluye:

- Provisioning de hosting (usuarios Linux, vhosts, PHP-FPM, rutas Caddy)
- Monitor con health score, alertas, anti-spam configurable y analisis de ruido
- Firewall avanzado (auditoria, fixes, snapshots completos, export/import JSON, presets)
- Fail2Ban gestionable desde panel (jails, bans, whitelist, acciones rapidas)
- Seguridad avanzada (MFA TOTP, hardening audit, drift, exposicion de puertos, login anomaly)
- Operacion cluster (sync de archivos lsyncd, watchdog de degradacion, acciones de recuperacion)
- Actualizaciones web/shell con warm-up del monitor collector
- Documentacion interna visible en `/docs` con guias operativas

## Modulos principales

- Dashboard y Monitoring
- Hosting Accounts, Domains, Databases, Customers
- Mail
- System Users
- Activity Log y File Audit Log
- Backups
- Settings:
  - Server, PHP, SSL/TLS
  - Security, Fail2Ban, Firewall, WireGuard
  - Cron, Caddy, Logs
  - Cluster, Replication/Federation
  - Notifications, Updates, Services

## Requisitos recomendados

- Ubuntu 22.04+ o Debian 12+
- PHP 8.2/8.3
- PostgreSQL
- Caddy 2.x
- (Opcional) MySQL/MariaDB para apps de clientes

## Instalacion rapida

```bash
git clone https://github.com/caimari/musedock-panel.git /opt/musedock-panel
cd /opt/musedock-panel
bash install.sh
```

Si no eres root:

```bash
sudo bash /opt/musedock-panel/install.sh
```

## Actualizacion (shell) - copy paste

### Nodo unico

```bash
cd /opt/musedock-panel \
&& git pull --ff-only origin main \
&& bash /opt/musedock-panel/bin/update.sh --auto
```

Si no eres root:

```bash
cd /opt/musedock-panel \
&& git pull --ff-only origin main \
&& sudo bash /opt/musedock-panel/bin/update.sh --auto
```

### Varios nodos (master + slaves)

Ejemplo con dos nodos; cambia IPs/hosts por los tuyos:

```bash
for NODE in root@10.10.70.1 root@10.10.70.156; do
  echo "=== Updating $NODE ==="
  ssh -o BatchMode=yes "$NODE" "cd /opt/musedock-panel && git pull --ff-only origin main && bash /opt/musedock-panel/bin/update.sh --auto"
done
```

### Verificacion post-update

```bash
# version declarada en config
grep "'version'" /opt/musedock-panel/config/panel.php

# servicio panel
systemctl is-active musedock-panel

# warm-up/chequeo collector manual
php /opt/musedock-panel/bin/monitor-collector.php
```

## Actualizacion (web)

Tambien puedes actualizar desde `Settings > Updates`. El updater web y shell usan el mismo flujo base.

## Seguridad y buenas practicas

- Restringe acceso a puerto del panel por firewall (IPs de administracion)
- Manten MFA activa para admins y, cuando todos esten enrolados, habilita MFA obligatoria
- Activa notificaciones de eventos de seguridad y sistema
- Revisa auditoria de firewall y hardening periodicamente
- Usa snapshots/export del firewall antes de cambios grandes
- Manten todos los nodos del cluster en la misma version

## Documentacion interna del panel

Desde el propio panel en `/docs`:

- `/docs/security-operations`
- `/docs/firewall-operations`
- `/docs/sync-archivos-lsyncd`
- `/docs/profile-mfa`
- `/docs/install-recovery`
- `/docs/default-backups`

## Estructura tecnica (resumen)

- `app/` controladores, servicios, seguridad y logica de negocio
- `bin/` scripts operativos (install/update/collector/repair)
- `config/` configuracion base del panel
- `database/` schema y migraciones
- `public/` front-controller y rutas
- `resources/views/` vistas del panel
- `storage/` sesiones, cache y logs
- `docs/` documentos adicionales de operacion

## Licencia

El proyecto usa licencia **Source Available** (no MIT).

Revisa terminos completos en [LICENSE](LICENSE).
