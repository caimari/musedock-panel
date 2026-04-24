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
                'version' => '1.0.81',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Mail node DB healthcheck: `cluster-worker` valida PostgreSQL local, lectura real con `musedock_mail`, lag de replica, Maildir y PTR/rDNS en nodos con servicio `mail`',
                            '`/mail`: banners y columnas de salud DB/lag/PTR para detectar nodos de correo degradados aunque SMTP/IMAP sigan escuchando',
                            'Cola cluster: acciones `mail_*` pausadas automaticamente cuando la DB local del nodo mail cae o el lag supera el umbral critico',
                        ],
                        'en' => [
                            'Mail node DB healthcheck: `cluster-worker` validates local PostgreSQL, real `musedock_mail` reads, replica lag, Maildir and PTR/rDNS on nodes with the `mail` service',
                            '`/mail`: DB/lag/PTR health banners and columns detect degraded mail nodes even when SMTP/IMAP ports are still open',
                            'Cluster queue: `mail_*` actions are automatically paused when the mail node local DB is down or lag exceeds the critical threshold',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Acciones `mail_*` con idempotency key para evitar duplicados pendientes por nodo y destino',
                            'Documentado procedimiento manual de failover PostgreSQL en `docs/FAILOVER.md`',
                        ],
                        'en' => [
                            '`mail_*` actions now use idempotency keys to avoid duplicate pending node/target operations',
                            'Manual PostgreSQL failover procedure documented in `docs/FAILOVER.md`',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.80',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            '`Settings → Cluster → Nodos`: boton de edicion rapida junto al nombre para cambiar la etiqueta visible del nodo',
                            'La edicion reutiliza `update-node` y solo cambia el nombre local; no toca URL, token, servicios ni configuracion remota del slave',
                        ],
                        'en' => [
                            '`Settings → Cluster → Nodes`: quick edit button next to the node name to rename the visible node label',
                            'The edit action reuses `update-node` and only changes the local name; URL, token, services and remote slave configuration are untouched',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.79',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            '`Settings → Cluster → Archivos`: exclusiones base visibles y editables desde UI para `rsync/HTTPS` y `lsyncd`',
                            '`FileSyncService`: las exclusiones internas se cargan desde settings con defaults seguros como fallback, en vez de depender solo de constantes hardcodeadas',
                            'El calculo de `Esperado slave` usa las mismas exclusiones editables que el sync real',
                        ],
                        'en' => [
                            '`Settings → Cluster → Files`: base exclusions are now visible and editable from the UI for `rsync/HTTPS` and `lsyncd`',
                            '`FileSyncService`: internal exclusions are loaded from settings with safe defaults as fallback instead of relying only on hardcoded constants',
                            '`Expected slave` calculation now uses the same editable exclusions as the real sync path',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.78',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            '`/accounts`: la cabecera aclara que el disco se lee de cache/BD y no ejecuta `du` en cada carga de pagina',
                            '`monitor-collector`: el calculo local de `disk_used_mb` se limita a un ciclo de 10 minutos para evitar carga innecesaria',
                            '`filesync-worker`: el disco real del slave y el esperado en master se recalculan y persisten cada 10 minutos, desacoplados del intervalo de sync de archivos',
                        ],
                        'en' => [
                            '`/accounts`: header now states that disk values are read from DB/cache and no `du` runs on page load',
                            '`monitor-collector`: local `disk_used_mb` calculation is throttled to a 10-minute cycle to avoid unnecessary load',
                            '`filesync-worker`: real slave disk and expected master replica values are recalculated and persisted every 10 minutes, independently from the file sync interval',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.77',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            '`/accounts`: resumen con metricas etiquetadas (`Hostings`, `Local`, `Slave real`, `Esperado slave`, `Estado replica`, `BW`) en vez de numeros sueltos',
                            'Estado de replica explicito: muestra `OK`, `Faltan X`, `Sobran X` o `Pendiente` segun diferencia entre esperado en slave y real medido en slave',
                            'Si todavia no existe calculo esperado, la UI muestra `Esperado slave: pendiente` hasta el siguiente ciclo de `filesync-worker`',
                        ],
                        'en' => [
                            '`/accounts`: summary now uses labeled metrics (`Hostings`, `Local`, `Slave real`, `Expected slave`, `Replica status`, `BW`) instead of loose numbers',
                            'Explicit replica status: shows `OK`, `Missing X`, `Extra X` or `Pending` based on expected-vs-real slave size',
                            'When the expected calculation is not available yet, UI shows `Expected slave: pending` until the next `filesync-worker` cycle',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.76',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            '`/accounts` header UX: acciones arriba y resumen debajo para evitar cabecera rota, botones desproporcionados y saltos de layout',
                            '`/accounts` (solo Master): comparativa por slave con `local` (master bruto), `real slave` (du remoto) y `estimado` (master con mismas exclusiones de sync)',
                            'Nuevo gap `estimado vs real` por slave para distinguir desviaciones reales de sincronizacion frente a diferencias esperadas por exclusiones',
                        ],
                        'en' => [
                            '`/accounts` header UX: actions moved above and summary below to avoid broken header layout and oversized buttons',
                            '`/accounts` (Master only): per-slave comparison with `local` (master raw), `real slave` (remote du) and `estimated` (master with same sync exclusions)',
                            'New `estimated vs real` gap per slave to distinguish actual sync drift from expected exclusion-driven differences',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'filesync-worker ahora persiste por nodo `master_total_mb`, `master_replicable_mb` y `remote_total_mb` para que `/accounts` use datos consistentes y auditables',
                        ],
                        'en' => [
                            'filesync-worker now persists per-node `master_total_mb`, `master_replicable_mb` and `remote_total_mb` so `/accounts` can render consistent, auditable values',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.75',
                'date' => '2026-04-24',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            '`/accounts` (solo Master): nuevo indicador de disco replicado por slave (`cloud-arrow-down`) para comparar facilmente local vs replica',
                            'Contexto en UI: aclarado que `disk_used_mb` local viene de cache en BD (~5 min por monitor-collector) y que la replica se refresca por ciclo de filesync-worker',
                        ],
                        'en' => [
                            '`/accounts` (Master only): new replicated-disk indicator per slave (`cloud-arrow-down`) to clearly compare local vs replica usage',
                            'UI context: clarified that local `disk_used_mb` is DB-cached (~5 min via monitor-collector) and replica totals refresh on filesync-worker cycles',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'filesync-worker ahora persiste total remoto por slave + timestamp en `panel_settings` (`filesync_remote_total_mb_node_{id}`) para alimentar la vista sin calculos ad-hoc',
                        ],
                        'en' => [
                            'filesync-worker now persists per-slave remote totals + timestamp in `panel_settings` (`filesync_remote_total_mb_node_{id}`) so UI can read stable values without ad-hoc calculations',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.74',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Cluster legacy-safe queue: `ClusterService` detecta en runtime si existe `cluster_nodes.standby`; si falta en un nodo legacy, omite el filtro `n.standby` para evitar `SQLSTATE[42703]` en el worker',
                            'Compatibilidad en nodos mixtos: `getActiveNodes()` usa fallback sin standby cuando el esquema todavia no tiene esa columna, evitando caidas durante ventanas de update',
                        ],
                        'en' => [
                            'Legacy-safe cluster queue: `ClusterService` now checks at runtime whether `cluster_nodes.standby` exists; when missing on legacy nodes, it skips the `n.standby` filter to avoid worker `SQLSTATE[42703]` errors',
                            'Mixed-node compatibility: `getActiveNodes()` now falls back to non-standby queries when schema lag exists, preventing update-window worker failures',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.73',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Cluster schema backfill: columnas `cluster_nodes.standby`, `standby_since` y `standby_reason` añadidas en nodos legacy para evitar errores de `cluster-worker` tras update',
                        ],
                        'en' => [
                            'Cluster schema backfill: `cluster_nodes.standby`, `standby_since` and `standby_reason` columns added on legacy nodes to prevent post-update `cluster-worker` errors',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.72',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Caddy mixed-mode hardening: el auto-repair del panel deja de inyectar `:8444` en `srv0`; ahora usa servidor dedicado `srv_panel_admin` cuando corresponde',
                            'Update/cluster safe-guard: si `PANEL_PORT` lo sirve un server externo del Caddyfile (ej. `srv1`), se omite la mutacion de rutas/politicas runtime del panel para no romper dominios productivos',
                            'Panel route targeting: `panel-fallback-route` y `panel-domain-route` se aplican solo en servidores gestionados por el panel (`srv0` legacy o `srv_panel_admin`), evitando pisar configuraciones ajenas',
                        ],
                        'en' => [
                            'Caddy mixed-mode hardening: panel auto-repair no longer injects `:8444` into `srv0`; it now uses dedicated `srv_panel_admin` where applicable',
                            'Update/cluster safe-guard: when `PANEL_PORT` is owned by an external Caddyfile server (e.g. `srv1`), runtime panel route/policy mutations are skipped to avoid breaking production domains',
                            'Panel route targeting: `panel-fallback-route` and `panel-domain-route` are applied only on panel-managed servers (`srv0` legacy or `srv_panel_admin`), preventing foreign config clobbering',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Nuevo `.env` opcional `CADDY_PANEL_SERVER_NAME` para personalizar el server runtime dedicado del panel',
                        ],
                        'en' => [
                            'New optional `.env` key `CADDY_PANEL_SERVER_NAME` to customize the panel dedicated runtime server name',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.71',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Cloudflare secrets-at-rest: `failover_cf_accounts` se normaliza siempre cifrado al guardar (aunque llegue token en claro desde flujo legacy)',
                            'Auto-migracion silenciosa: al leer cuentas Cloudflare, si detecta token legacy en claro, lo recifra automaticamente en `panel_settings` sin romper compatibilidad',
                        ],
                        'en' => [
                            'Cloudflare secrets-at-rest: `failover_cf_accounts` is now always normalized encrypted when saved (even if legacy plain token input arrives)',
                            'Silent auto-migration: while reading Cloudflare accounts, any detected legacy plain token is automatically re-encrypted in `panel_settings` with backward compatibility',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.70',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Firewall UI: botones Activar/Desactivar tambien disponibles para iptables (ademas de UFW), con deteccion de estado en pantalla',
                            'Seguridad firewall: activar/desactivar iptables requiere confirmacion con contrasena admin igual que UFW',
                        ],
                        'en' => [
                            'Firewall UI: Enable/Disable buttons now available for iptables too (in addition to UFW), with on-screen state detection',
                            'Firewall security: enabling/disabling iptables now requires admin-password confirmation, same as UFW',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Controlador firewall: acciones de activar/desactivar ya no estan hardcodeadas a UFW; ahora enrutan segun tipo detectado (`ufw` o `iptables`)',
                        ],
                        'en' => [
                            'Firewall controller: enable/disable actions are no longer hardcoded to UFW; now route by detected type (`ufw` or `iptables`)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.69',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Firewall (UFW): activar/desactivar ahora requiere confirmacion con contrasena de administrador en modal, para evitar cambios accidentales o sin autorizacion',
                            'TLS panel (Settings > Servidor): nueva formula operativa visible para renovacion HTTP-01 con ventana corta de firewall (abrir 80/443, reparar Caddy, verificar y cerrar)',
                        ],
                        'en' => [
                            'Firewall (UFW): enable/disable now requires admin-password confirmation in modal, preventing accidental or unauthorized changes',
                            'Panel TLS (Settings > Server): new visible operational recipe for HTTP-01 renewal with a short firewall window (open 80/443, repair Caddy, verify, then close)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.68',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Sync Cloudflare master→slave: corregido payload para enviar `cf_accounts` en formato raw cifrado (no decriptado), restaurando la propagacion fiable de token a `/etc/default/caddy` en slaves',
                            'Seguridad en slave: al recibir `cf_accounts`, los tokens se normalizan a cifrados antes de guardar en `panel_settings` (compatibilidad con payloads legacy en texto plano)',
                            'Compatibilidad update token: `sync-failover-config` acepta token legacy en claro para ejecutar `update-caddy-token.sh` y luego persiste cifrado en BD',
                        ],
                        'en' => [
                            'Cloudflare master→slave sync: fixed payload to send `cf_accounts` as raw encrypted data (not decrypted), restoring reliable token propagation to `/etc/default/caddy` on slaves',
                            'Slave security: incoming `cf_accounts` tokens are normalized to encrypted-at-rest before saving into `panel_settings` (compatibility with legacy plain-text payloads)',
                            'Token-update compatibility: `sync-failover-config` now accepts legacy plain tokens for `update-caddy-token.sh` execution and then persists encrypted in DB',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.67',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Dashboard SSL warning: texto aclarado para indicar que la falta de `CLOUDFLARE_API_TOKEN` afecta especificamente a flujos DNS-01 (dominios proxied y panel DNS-01), evitando confundirlo con todos los certificados',
                            'Dashboard SSL warning (slave): mensaje actualizado para explicar que la propagacion desde master requiere guardar cuentas Cloudflare con «Actualizar token de Caddy» y que no debe hacerse manual en cada slave si sync fue correcto',
                            'Dashboard UX: añadido boton visible «No mostrar mas» en el aviso de token Cloudflare (ademas del cierre rapido)',
                        ],
                        'en' => [
                            'Dashboard SSL warning: clarified copy to indicate missing `CLOUDFLARE_API_TOKEN` specifically affects DNS-01 flows (proxied domains and panel DNS-01), avoiding confusion with all certificates',
                            'Dashboard SSL warning (slave): updated message clarifies propagation from master requires saving Cloudflare accounts with “Update Caddy token”, so per-slave manual edits are not needed when sync succeeds',
                            'Dashboard UX: added explicit “Do not show again” button on Cloudflare token warning (in addition to quick close)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.66',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'UX TLS (Settings > Servidor): ayuda contextual ampliada para distinguir uso de Email ACME, fallback por IP y escenario HSTS `includeSubDomains`',
                            'Guia operativa en UI: recomendaciones explicitas para acceso admin por IP (`:8444`) y uso de DNS-01 cuando se quiere certificado publico sin abrir puertos',
                        ],
                        'en' => [
                            'TLS UX (Settings > Server): expanded contextual guidance for ACME email usage, IP fallback, and `includeSubDomains` HSTS scenarios',
                            'Operational UI guide: explicit recommendations for admin IP access (`:8444`) and DNS-01 when public certs are required without opening ports',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.65',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Settings TLS panel: en modo `self_signed` el email ACME deja de persistirse para evitar arrastrar valores legacy al volver a abrir la pantalla',
                        ],
                        'en' => [
                            'Panel TLS settings: in `self_signed` mode ACME email is no longer persisted, avoiding legacy carry-over values in the UI',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.64',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Settings > Server > Email ACME: eliminado fallback visual hardcoded `admin@musedock.com`; ahora muestra placeholder neutral `webmaster@domain.com` cuando no hay valor guardado',
                            'Guardado TLS del panel: el email ACME solo es obligatorio en modos `http01`/`dns01`; en `self_signed` puede quedar vacio sin forzar un valor por defecto',
                        ],
                        'en' => [
                            'Settings > Server > ACME email: removed hardcoded visual fallback `admin@musedock.com`; now uses neutral placeholder `webmaster@domain.com` when unset',
                            'Panel TLS save flow: ACME email is now required only for `http01`/`dns01`; `self_signed` can remain empty without forced defaults',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.63',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'TLS panel por IP: nueva policy dedicada con certificado interno para `server_ip`/IPs del nodo, evitando bloqueo de acceso admin por IP cuando ACME falla',
                            'Hardening TLS admin: el fallback por IP ya no depende del catch-all ACME de hostings, evitando mezclar disponibilidad del panel con certificados publicos de clientes',
                        ],
                        'en' => [
                            'Panel IP TLS: new dedicated internal-certificate policy for `server_ip`/node IPs, preventing admin IP lockout when ACME fails',
                            'Admin TLS hardening: IP fallback no longer depends on hosting catch-all ACME policy, keeping panel availability separated from customer public certs',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.62',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'HSTS del panel: desactivado por defecto en el puerto admin para evitar bloqueos persistentes de navegador tras cambios de certificado',
                            'TLS admin anti-lockout: al cambiar entre cert publico/interno ya no se queda el acceso atrapado por cache HSTS en Chrome',
                        ],
                        'en' => [
                            'Panel HSTS: disabled by default on admin port to avoid persistent browser lockouts after certificate mode changes',
                            'Admin TLS anti-lockout: switching between public/internal cert modes no longer traps access behind stale Chrome HSTS cache',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Nueva variable `PANEL_HSTS_ENABLED` en instalador/update/.env.example para habilitar HSTS solo cuando se quiera explicitamente',
                        ],
                        'en' => [
                            'New `PANEL_HSTS_ENABLED` variable in installer/update/.env.example so HSTS is opt-in only',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.61',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'TLS del panel (Settings > Servidor): nuevos 3 modos para el puerto admin (self-signed, HTTP-01/TLS-ALPN-01 y DNS-01 con proveedor DNS configurable)',
                            'DNS-01 multi-proveedor para el panel: soporte por nombre de modulo Caddy (`dns.providers.<proveedor>`) + configuracion JSON del provider (no solo Cloudflare)',
                            'Setup first-run: bloque informativo de seguridad/TLS para explicar diferencias entre self-signed, HTTP-01 y DNS-01 antes de crear el admin',
                        ],
                        'en' => [
                            'Panel TLS (Settings > Server): new 3-mode admin-port TLS model (self-signed, HTTP-01/TLS-ALPN-01 and DNS-01 with configurable DNS provider)',
                            'Multi-provider DNS-01 for panel TLS: support via Caddy module name (`dns.providers.<provider>`) + provider JSON config (not Cloudflare-only)',
                            'First-run setup: security/TLS guidance block explaining self-signed vs HTTP-01 vs DNS-01 before admin creation',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Caddy panel route: ahora usa siempre el `PANEL_PORT` real (ya no hardcoded) y mantiene coherencia entre URL mostrada, fallback y reglas',
                            'Politicas TLS del panel: se gestionan de forma dedicada por hostname del panel para no mezclar decision de cert admin con TLS general de hostings',
                            'Installer (shell): resumen final ampliado con instrucciones claras de los 3 modelos de certificacion del panel admin y requisitos de cada uno',
                        ],
                        'en' => [
                            'Caddy panel route: now always uses real `PANEL_PORT` (no hardcoded value), keeping URL/fallback/rules consistent',
                            'Panel TLS policies: now managed per panel hostname so admin cert strategy is separated from general hosting TLS policies',
                            'Installer (shell): expanded final output with clear instructions for the 3 admin-panel certificate models and their requirements',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Panel TLS anti-lockout: en modos ACME (`http01` y `dns01`) se añade fallback interno para evitar `ERR_SSL_PROTOCOL_ERROR` cuando ACME falla',
                            'Settings server save: validaciones de DNS-01 (dominio requerido, proveedor valido, JSON valido, email ACME valido)',
                            'Limpieza TLS del panel: al quitar dominio del panel, se elimina tambien la policy TLS dedicada para evitar estado residual',
                            'URL de panel en UI: para hostname configurado muestra acceso correcto por puerto admin (`https://dominio:8444`), evitando confusion con 443',
                        ],
                        'en' => [
                            'Panel TLS anti-lockout: ACME modes (`http01` and `dns01`) now include internal fallback to avoid `ERR_SSL_PROTOCOL_ERROR` when ACME fails',
                            'Server settings save: DNS-01 validation added (hostname required, provider format, valid JSON, valid ACME email)',
                            'Panel TLS cleanup: when panel hostname is removed, dedicated panel TLS policy is removed too (no stale policy leftovers)',
                            'Panel URL in UI: hostname access now explicitly uses admin port (`https://domain:8444`), avoiding confusing 443 assumptions',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.60',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'CLI de reparacion Caddy: nuevo comando `php cli/repair-caddy-routes.php` para validar y reparar listeners/politicas/rutas del panel',
                        ],
                        'en' => [
                            'Caddy repair CLI: new `php cli/repair-caddy-routes.php` command to validate and repair panel listeners/policies/routes',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Caddy self-heal reforzado: normalizacion de `srv0` ante estados mixtos (`listen`/`routes` nulos o incompletos) y compatibilidad con despliegues sin modulo DNS Cloudflare',
                            'Updater endurecido: `bin/update.sh` corta la actualizacion cuando falla una migracion y evita continuar en estado parcialmente aplicado',
                            'Cluster heartbeat mas robusto: se reduce el falso positivo de "Master caido" cuando el problema real es el listener local del panel en el slave',
                            'Mantenimiento de cron mas estable: normalizacion de variables de entorno y ejecucion escalonada para tareas de verificacion Caddy/plugins',
                        ],
                        'en' => [
                            'Hardened Caddy self-heal: `srv0` normalization for mixed states (`listen`/`routes` null or incomplete) and compatibility with deployments without Cloudflare DNS module',
                            'Hardened updater: `bin/update.sh` now stops the update when a migration fails, preventing partially-applied states',
                            'More robust cluster heartbeat: reduces false "Master down" alerts when the actual issue is the slave local panel listener',
                            'More stable cron maintenance: normalized env variables and staggered execution for Caddy/plugins verification tasks',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Migraciones: corregido fallo en nodos legacy donde faltaba `hosting_subdomains` al aplicar `2026_04_03_000004_add_hosting_type_to_subdomains`',
                            'Schema de ancho de banda: migracion de reparacion para crear/ajustar tablas faltantes y eliminar error 500 en `/accounts` por `hosting_bandwidth` inexistente',
                            'Caddy API: corregidos flujos que provocaban estados invalidos (409 en `listen`, rutas perdidas o `routes` nulo) tras updates/reloads',
                            'Panel domain route: re-creacion y sincronizacion de ruta HTTPS del dominio del panel con fallback operativo del puerto administrativo',
                        ],
                        'en' => [
                            'Migrations: fixed legacy-node failure when `hosting_subdomains` was missing while applying `2026_04_03_000004_add_hosting_type_to_subdomains`',
                            'Bandwidth schema: repair migration now creates/fixes missing tables and removes `/accounts` 500 errors caused by missing `hosting_bandwidth`',
                            'Caddy API: fixed flows that produced invalid states (409 on `listen`, lost routes or null `routes`) after updates/reloads',
                            'Panel domain route: restored and synchronized panel HTTPS domain route while keeping administrative-port fallback operational',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.59',
                'date' => '2026-04-23',
                'badge' => 'primary',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Monitoring (7d/30d/1y): graficas de administracion ajustadas para lectura operativa — linea principal en AVG y linea secundaria en P95 (en lugar de mostrar solo picos)',
                            'Monitoring tooltips: en rangos agregados muestran avg/p95/peak para evitar interpretaciones alarmistas por picos puntuales',
                            'Monitoring data model: agregados horarios y diarios ahora incluyen p95_val para soportar visualizacion y analisis de percentiles',
                            'Alerts en /monitor: paginacion real por API (page/per_page), contador total y navegacion Prev/Next',
                            'Alerts en /monitor: selector de tamanyo por pagina (20/50/100) con refresco inmediato',
                            'UI /monitor: afinado visual de bordes en controles de paginacion y selector para integracion con tema dark',
                        ],
                        'en' => [
                            'Monitoring (7d/30d/1y): admin charts tuned for operational reading — main line uses AVG and secondary line uses P95 (instead of showing only peaks)',
                            'Monitoring tooltips: aggregated ranges now show avg/p95/peak to avoid alarmist interpretation from isolated spikes',
                            'Monitoring data model: hourly and daily aggregates now include p95_val to support percentile visualization and analysis',
                            'Alerts in /monitor: real API pagination (page/per_page), total counter and Prev/Next navigation',
                            'Alerts in /monitor: page-size selector (20/50/100) with immediate refresh',
                            'UI /monitor: visual refinement of borders in pagination controls and selector for better dark theme integration',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Login: placeholder de usuario cambiado de \"admin\" a \"Nombre de usuario\" y placeholder de contrasenya a \"Password\" para evitar confusiones en primer acceso',
                            'Alerts /monitor: ya no se recortan en frontend a 20 fijos; ahora se respetan paginas completas desde backend',
                        ],
                        'en' => [
                            'Login: username placeholder changed from \"admin\" to \"Nombre de usuario\" and password placeholder to \"Password\" to reduce first-login confusion',
                            'Alerts /monitor: no longer hard-truncated to 20 on frontend; full backend pages are now respected',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.58',
                'date' => '2026-04-22',
                'badge' => 'success',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Cluster TLS: validacion interna endurecida con soporte de CA/pinning para conexiones entre nodos',
                            'Health checks de cluster: mejor diagnostico de heartbeat caido con mensajes mas claros en panel y logs',
                            'Monitoring: optimizada carga inicial de /monitor para reducir latencia con historicos amplios',
                            'Release ops: flujo de versionado simplificado para publicar version/tag en Git con un solo script',
                        ],
                        'en' => [
                            'Cluster TLS: internal validation hardened with CA/pinning support for node-to-node connections',
                            'Cluster health checks: improved diagnostics for missing heartbeat with clearer panel and log messages',
                            'Monitoring: optimized initial /monitor load to reduce latency on large historical datasets',
                            'Release ops: simplified versioning flow to publish version/tag to Git with a single script',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'File operations: eliminado uso residual de eval en rutas sensibles para reducir superficie de riesgo',
                            'Cluster sync: corregidos falsos positivos de "Master caido" causados por validacion TLS inconsistente entre nodos',
                            'Login UX: textos de acceso ajustados para evitar confusion en instalaciones nuevas',
                        ],
                        'en' => [
                            'File operations: removed residual eval usage in sensitive paths to reduce risk surface',
                            'Cluster sync: fixed false "Master down" positives caused by inconsistent TLS validation between nodes',
                            'Login UX: access text adjusted to reduce confusion in fresh installations',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.57',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Federation: migrar subdominio entre masters — Boton en la pagina del subdominio para migrar archivos y BD a otro master federado usando SSH keys (sin credenciales manuales)',
                            'Databases: usuario personalizado al crear BD — Campo opcional para definir nombre de usuario propio en vez del auto-generado',
                        ],
                        'en' => [
                            'Federation: migrate subdomain between masters — Button on subdomain page to migrate files and DB to another federated master using SSH keys (no manual credentials)',
                            'Databases: custom username on DB creation — Optional field to define custom username instead of auto-generated',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Health: paquetes PHP permitidos — Las extensiones PHP (php8.3-sqlite3, php8.3-bcmath, etc.) ahora se pueden instalar desde el panel sin error "paquete no permitido"',
                            'Health: binarios nuevos permitidos — nodejs, npm, composer, sshpass, tar, unzip ahora en la lista de paquetes instalables',
                        ],
                        'en' => [
                            'Health: PHP packages allowed — PHP extensions (php8.3-sqlite3, php8.3-bcmath, etc.) can now be installed from panel without "package not allowed" error',
                            'Health: new binaries allowed — nodejs, npm, composer, sshpass, tar, unzip now in installable packages list',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.56',
                'date' => '2026-04-03',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Hosting Type: 3 modos (PHP/SPA/Static) — Configura como Caddy sirve cada hosting: PHP (index.php), SPA (index.html para React/Vue/Angular) o Static (file_server puro)',
                            'Hosting Type: auto-deteccion — El panel analiza el document root y sugiere el tipo correcto (detecta React, Laravel, WordPress, HTML estatico)',
                            'Hosting Type: por subdominio — Cada subdominio puede tener su propio tipo independiente del dominio principal (ej: factubase.com=SPA, api.factubase.com=PHP)',
                            'Hosting Type: modal de confirmacion — Cambiar el tipo requiere confirmacion con descripcion del cambio',
                            'Migracion: Opcion 4 Subdominio Individual — Migra archivos + BD de un subdominio especifico via SSH en un solo paso',
                            'Migracion subdomain: auto-deteccion BD — Lee .env del servidor remoto, detecta credenciales MySQL/PostgreSQL, crea BD local y actualiza .env',
                            'Migracion subdomain: selector de scope — Elige migrar archivos + BD, solo archivos o solo BD',
                            'Health: PHP extensions — Añadidos sqlite3, zip, gd, xml, bcmath. Boton de instalacion para extensiones faltantes',
                            'Health: build tools — Añadidos node, npm, composer, sshpass, tar, unzip con botones de instalacion',
                        ],
                        'en' => [
                            'Hosting Type: 3 modes (PHP/SPA/Static) — Configure how Caddy serves each hosting: PHP (index.php), SPA (index.html for React/Vue/Angular) or Static (pure file_server)',
                            'Hosting Type: auto-detection — Panel analyzes document root and suggests correct type (detects React, Laravel, WordPress, static HTML)',
                            'Hosting Type: per subdomain — Each subdomain can have its own type independent of main domain (e.g. factubase.com=SPA, api.factubase.com=PHP)',
                            'Hosting Type: confirmation modal — Changing type requires confirmation with change description',
                            'Migration: Option 4 Individual Subdomain — Migrate files + DB of a specific subdomain via SSH in one step',
                            'Migration subdomain: auto-detect DB — Reads .env from remote server, detects MySQL/PostgreSQL credentials, creates local DB and updates .env',
                            'Migration subdomain: scope selector — Choose to migrate files + DB, files only or DB only',
                            'Health: PHP extensions — Added sqlite3, zip, gd, xml, bcmath. Install button for missing extensions',
                            'Health: build tools — Added node, npm, composer, sshpass, tar, unzip with install buttons',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Federation: hosting_type en migracion — El tipo de hosting (PHP/SPA/Static) se transfiere al destino durante la migracion federation',
                            'Federation: subdominios con tipo — Los subdominios migrados conservan su hosting_type individual y se crean con la ruta Caddy correcta',
                            'SubdomainService: auto-deteccion al crear — Al crear un subdominio, el tipo se auto-detecta basandose en el contenido del document root',
                            'SystemService: buildCaddySubroutes — Refactorizado para generar rutas Caddy segun el tipo (eliminado codigo duplicado entre addCaddyRoute y rebuildCaddyRouteWithAliases)',
                        ],
                        'en' => [
                            'Federation: hosting_type in migration — Hosting type (PHP/SPA/Static) is transferred to destination during federation migration',
                            'Federation: subdomains with type — Migrated subdomains preserve their individual hosting_type and are created with correct Caddy route',
                            'SubdomainService: auto-detect on creation — When creating a subdomain, type is auto-detected based on document root contents',
                            'SystemService: buildCaddySubroutes — Refactored to generate Caddy routes based on type (removed duplicate code between addCaddyRoute and rebuildCaddyRouteWithAliases)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.55',
                'date' => '2026-04-03',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [
                            'PostgreSQL SSL: activar/desactivar desde Settings > Seguridad — Genera certificado auto-firmado, modifica postgresql.conf, reinicia PG. Opcion de actualizar DB_SSLMODE en todos los .env',
                            'Databases: card interactiva en cuenta — Pulsar sobre una BD abre modal con info de conexion (host, puerto, tipo) y formulario para cambiar usuario/password con verificacion de admin',
                            'Databases: crear BD desde cuenta — Boton "Nueva BD" con selector MySQL/PostgreSQL, nombre con prefijo automatico, password auto-generada',
                            'Migration DB: modal de credenciales — Despues de migrar BD, modal SweetAlert que no se cierra hasta pulsar "Ya la he copiado". Muestra DB name, usuario, tipo y password con boton copiar',
                            'Migration DB: selector de subdominio — Dropdown para elegir de que subdominio migrar la BD. Auto-rellena la ruta remota y actualiza el .env del subdominio local correcto',
                            'Dashboard: card Hosting Accounts clickable — La card del dashboard enlaza directamente a /accounts',
                        ],
                        'en' => [
                            'PostgreSQL SSL: enable/disable from Settings > Security — Generates self-signed cert, modifies postgresql.conf, restarts PG. Option to update DB_SSLMODE in all .env files',
                            'Databases: interactive card in account — Click a DB opens modal with connection info (host, port, type) and form to change user/password with admin verification',
                            'Databases: create DB from account — "New DB" button with MySQL/PostgreSQL selector, auto-prefixed name, auto-generated password',
                            'Migration DB: credentials modal — After DB migration, SweetAlert modal that stays until "I already copied it". Shows DB name, user, type and password with copy button',
                            'Migration DB: subdomain selector — Dropdown to choose which subdomain DB to migrate. Auto-fills remote path and updates the correct local subdomain .env',
                            'Dashboard: clickable Hosting Accounts card — Dashboard card links directly to /accounts',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'PostgreSQL ownership: REASSIGN OWNED tras migracion — Las tablas importadas con pg_dump ahora se reasignan al usuario de la app (antes quedaban como owner postgres)',
                            'PostgreSQL credenciales: comillas correctas — ALTER USER usa comillas dobles para identificadores y simples para passwords (antes usaba escapeshellarg que generaba SQL invalido)',
                            'Federation: columna hosting_account_id — Corregidas queries a hosting_domain_aliases que usaban account_id inexistente (causaba error 500 en federation-migrate)',
                            'Migration DB: redireccion post-error — Los errores al editar credenciales redirigen a la pagina de origen (/accounts/X) en vez de /databases',
                            'Migration DB: .env del subdominio — Ahora actualiza el .env correcto del subdominio seleccionado, no el del dominio principal',
                            'Migration DB: DB_SSLMODE — Para PostgreSQL migrado, cambia DB_SSLMODE=require a prefer (conexion local no necesita SSL obligatorio)',
                            'Backups create: dark theme completo — Switches, inputs time, input-group-text e iconos con colores del dark theme (antes se veian negros)',
                            'Migration Step 3: titulo PostgreSQL — Card dice "MySQL / PostgreSQL" en vez de solo "MySQL". Texto de proceso actualizado',
                            'Migration Step 3: memoria de collapse — La card desplegada se mantiene abierta al recargar pagina (localStorage)',
                            'Migration Step 3: overlay durante migracion — Spinner a pantalla completa con mensaje mientras migra BD (antes no habia feedback visual)',
                        ],
                        'en' => [
                            'PostgreSQL ownership: REASSIGN OWNED after migration — Tables imported with pg_dump now reassigned to app user (previously owned by postgres)',
                            'PostgreSQL credentials: correct quoting — ALTER USER uses double quotes for identifiers and single quotes for passwords (previously used escapeshellarg generating invalid SQL)',
                            'Federation: hosting_account_id column — Fixed queries to hosting_domain_aliases using non-existent account_id (caused 500 error in federation-migrate)',
                            'Migration DB: post-error redirect — Credential edit errors redirect to origin page (/accounts/X) instead of /databases',
                            'Migration DB: subdomain .env — Now updates the correct subdomain .env, not the main domain one',
                            'Migration DB: DB_SSLMODE — For migrated PostgreSQL, changes DB_SSLMODE=require to prefer (local connection does not need mandatory SSL)',
                            'Backups create: full dark theme — Switches, time inputs, input-group-text and icons with dark theme colors (previously appeared black)',
                            'Migration Step 3: PostgreSQL title — Card says "MySQL / PostgreSQL" instead of just "MySQL". Process text updated',
                            'Migration Step 3: collapse memory — Expanded card stays open on page reload (localStorage)',
                            'Migration Step 3: overlay during migration — Full-screen spinner with message while migrating DB (previously no visual feedback)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.54',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Federation Backups: peers como almacenamiento remoto — Los federation peers aparecen como destinos en transferir backup, backups remotos y auto-backup remoto',
                            'Federation Backups: selector SSH / HTTP — Al transferir a un peer, elige entre SSH (rsync, rapido) o HTTP upload (para servidores con SSH limitado como Contabo)',
                            'Federation Backups: API de backups remotos — Endpoints para listar, recibir, descargar y eliminar backups en peers federados',
                            'Federation Backups: recepcion HTTP — Nuevo endpoint receive-upload para recibir backups via multipart POST cuando SSH no esta disponible',
                            'Federation Migration: selector de subdominios — Checkboxes para elegir que subdominios y aliases incluir en la migracion/clonacion',
                            'Federation Migration: Caddy routes para subdominios — El paso FINALIZE ahora crea rutas Caddy individuales para cada subdominio y alias migrado',
                        ],
                        'en' => [
                            'Federation Backups: peers as remote storage — Federation peers appear as destinations in backup transfer, remote backups and auto-backup remote',
                            'Federation Backups: SSH / HTTP selector — When transferring to a peer, choose between SSH (rsync, fast) or HTTP upload (for servers with limited SSH like Contabo)',
                            'Federation Backups: remote backup API — Endpoints to list, receive, download and delete backups on federated peers',
                            'Federation Backups: HTTP receive — New receive-upload endpoint to receive backups via multipart POST when SSH is unavailable',
                            'Federation Migration: subdomain selector — Checkboxes to choose which subdomains and aliases to include in migration/cloning',
                            'Federation Migration: Caddy routes for subdomains — FINALIZE step now creates individual Caddy routes for each migrated subdomain and alias',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Backups UI: selectores agrupados — Los selectores de nodo remoto agrupan Cluster Nodes y Federation Peers con etiquetas visuales',
                            'Auto-backup: destino federation — La configuracion de auto-backup remoto ahora permite seleccionar federation peers ademas de cluster nodes',
                        ],
                        'en' => [
                            'Backups UI: grouped selectors — Remote node selectors group Cluster Nodes and Federation Peers with visual labels',
                            'Auto-backup: federation destination — Auto-backup remote configuration now allows selecting federation peers in addition to cluster nodes',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.53',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Federation Clone: Actualizar clon — Sincronizacion incremental (archivos y/o BD) sin borrar datos extra en destino. Selector de scope: solo archivos, solo BD, o ambos',
                            'Federation Clone: Re-clonar — Elimina completamente el hosting en destino y recrea desde cero. Limpieza verificada antes del nuevo clon',
                            'Federation Clone: Promover clon a produccion — Convierte un clon en el hosting activo con cambio de DNS (auto/manual), grace period y verificacion obligatoria',
                            'Federation Clone: UI de gestion — Card "Clones en otros servidores" en la pagina de cuenta con botones Actualizar, Re-clonar, Promover',
                            'Federation Clone: modal de confirmacion con contrasenya — Cada accion requiere contrasenya del admin, con descripcion detallada de lo que va a pasar',
                            'Federation Clone: warning de clon antiguo — Badge de antiguedad (>24h) en la lista de clones. Warning destacado en modal de Promover si el clon esta desactualizado',
                            'Federation Clone: sync obligatorio en promote antiguo — Si el clon tiene >24h, el checkbox "Sincronizar antes de promover" se fuerza activado (no se puede desmarcar)',
                            'Federation Clone: warning de incompatibilidad — Al seleccionar "Solo archivos" o "Solo BD" muestra aviso de posibles problemas de compatibilidad codigo/esquema',
                        ],
                        'en' => [
                            'Federation Clone: Update clone — Incremental sync (files and/or DB) without deleting extra data on destination. Scope selector: files only, DB only, or both',
                            'Federation Clone: Force re-clone — Completely deletes hosting on destination and recreates from scratch. Verified cleanup before new clone',
                            'Federation Clone: Promote clone to production — Converts a clone into the active hosting with DNS change (auto/manual), grace period and mandatory verification',
                            'Federation Clone: Management UI — "Clones on other servers" card on account page with Update, Re-clone, Promote buttons',
                            'Federation Clone: Confirmation modal with password — Each action requires admin password, with detailed description of what will happen',
                            'Federation Clone: Stale clone warning — Age badge (>24h) on clone list. Highlighted warning in Promote modal if clone is outdated',
                            'Federation Clone: Mandatory sync on stale promote — If clone is >24h old, "Sync before promoting" checkbox is forced on (cannot be unchecked)',
                            'Federation Clone: Incompatibility warning — When selecting "Files only" or "DB only" shows warning about potential code/schema compatibility issues',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Federation: partial unique index — Previene race condition en migraciones duplicadas a nivel de BD',
                            'Federation: cleanup worker respeta mode=clone — Nunca limpia archivos de origen en clones (regla explicita, no accidental)',
                            'Federation: promote siempre verifica — VERIFY obligatorio antes de DNS switch, incluso sin sync previo',
                            'Federation: paginas maintenance/read-only con dark theme — Diseyo responsive, mensajes claros, consistente con el panel',
                            'Federation: FPM drain configurable (30s) — Constante ajustable para esperar a conexiones en vuelo antes de freeze',
                            'Federation: metricas por paso — Duracion, bytes transferidos, velocidad rsync. Resumen al completar migracion',
                            'Federation: alert dark theme — Warning "No hay peers" con estilo consistente (sin fondo crema Bootstrap)',
                        ],
                        'en' => [
                            'Federation: partial unique index — Prevents race condition on duplicate migrations at DB level',
                            'Federation: cleanup worker respects mode=clone — Never cleans origin files for clones (explicit rule, not accidental)',
                            'Federation: promote always verifies — VERIFY mandatory before DNS switch, even without prior sync',
                            'Federation: maintenance/read-only pages with dark theme — Responsive design, clear messages, consistent with panel',
                            'Federation: configurable FPM drain (30s) — Adjustable constant for waiting on in-flight connections before freeze',
                            'Federation: per-step metrics — Duration, bytes transferred, rsync speed. Summary on migration completion',
                            'Federation: dark theme alert — "No peers" warning with consistent styling (no Bootstrap cream background)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.52',
                'date' => '2026-04-03',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Federation: sistema de migracion Master-to-Master — Migra hostings completos entre servidores federados con maquina de estados de 11 pasos, retry automatico, pause/resume y rollback completo',
                            'Federation: gestion de peers — Settings > Federation para agregar, testar y gestionar paneles remotos. Handshake bidireccional automatico con intercambio de SSH keys',
                            'Federation: codigo de emparejamiento — Genera un codigo temporal en un panel, pegalo en otro y se conectan automaticamente. Sin copiar tokens ni configurar SSH manualmente',
                            'Federation: modo clon — Copia un hosting a otro servidor sin mover DNS ni limpiar origen. Ideal para staging o pre-migracion',
                            'Federation: modo dry-run — Valida conflictos (dominio, UID, disco) sin ejecutar la migracion real',
                            'Federation: selector DNS automatico/manual — Elige entre cambio via Cloudflare API o manual. Si Cloudflare falla, no bloquea la migracion',
                            'Federation: read-only real durante grace period — FPM parado, Caddy bloquea PHP y POST/PUT/DELETE. Solo sirve archivos estaticos. Zero escrituras garantizadas',
                            'Federation: drain de conexiones FPM — Antes de congelar, espera hasta 30s a que las conexiones activas terminen. Despues mata FPM',
                            'Federation: sync de slaves automatico — Al completar la migracion, el destino encola sync hacia sus slaves (obelix/filemon) exactamente como un hosting nuevo',
                            'Federation: pause-sync hard-stop — Pausa la replicacion hacia slaves durante la migracion: flag + exclusion rsync + reload lsyncd. Auto-expira en 2h',
                            'Federation: worker de migraciones — Cron cada minuto: resume migraciones huerfanas tras reinicio, libera locks muertos, completa grace periods, monitoriza DNS manual',
                            'Federation: cleanup worker — Cron horario: elimina archivos del origen 48h despues de migracion completada',
                            'Federation: metricas por paso — Duracion, bytes transferidos, velocidad rsync. Resumen total al completar (tiempo, MB, step timings)',
                            'Federation: verificacion multi-capa — HTTP 200 via HTTPS/SNI, PHP funciona, cada BD responde SELECT 1, ownership correcto, assets estaticos presentes',
                            'Federation: paginas maintenance/read-only con estilo — Dark theme, responsive, mensajes claros en vez de HTML plano',
                            'Federation: aprobacion manual de peers — Handshakes remotos crean peers en estado pending_approval. El admin debe aprobar antes de usar',
                        ],
                        'en' => [
                            'Federation: Master-to-Master migration system — Migrate complete hostings between federated servers with 11-step state machine, auto retry, pause/resume and full rollback',
                            'Federation: peer management — Settings > Federation to add, test and manage remote panels. Automatic bidirectional handshake with SSH key exchange',
                            'Federation: pairing code — Generate a temporary code on one panel, paste it on another and they auto-connect. No manual token copying or SSH configuration',
                            'Federation: clone mode — Copy a hosting to another server without moving DNS or cleaning origin. Ideal for staging or pre-migration testing',
                            'Federation: dry-run mode — Validate conflicts (domain, UID, disk) without executing the actual migration',
                            'Federation: automatic/manual DNS selector — Choose between Cloudflare API or manual DNS change. If Cloudflare fails, migration continues',
                            'Federation: real read-only during grace period — FPM stopped, Caddy blocks PHP and POST/PUT/DELETE. Only serves static files. Zero writes guaranteed',
                            'Federation: FPM connection draining — Before freezing, waits up to 30s for active connections to finish. Then kills FPM',
                            'Federation: automatic slave sync — On migration completion, destination enqueues sync to its slaves (obelix/filemon) just like a new hosting',
                            'Federation: hard-stop pause-sync — Pauses slave replication during migration: flag + rsync exclusion + lsyncd reload. Auto-expires in 2h',
                            'Federation: migration worker — Cron every minute: resumes orphaned migrations after restart, releases stale locks, completes grace periods, monitors manual DNS',
                            'Federation: cleanup worker — Hourly cron: deletes origin files 48h after completed migration',
                            'Federation: per-step metrics — Duration, bytes transferred, rsync speed. Total summary on completion (time, MB, step timings)',
                            'Federation: multi-layer verification — HTTP 200 via HTTPS/SNI, PHP works, each DB responds SELECT 1, correct ownership, static assets present',
                            'Federation: styled maintenance/read-only pages — Dark theme, responsive, clear messages instead of plain HTML',
                            'Federation: manual peer approval — Remote handshakes create peers in pending_approval state. Admin must approve before use',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'API Auth: soporte federation — ApiAuthMiddleware ahora valida tokens de federation_peers ademas de cluster_nodes',
                            'Health: cron federation — musedock-federation aparece en System Health como cron requerido con boton de reparacion',
                            'Race condition: unique index — Partial unique index impide crear dos migraciones activas para el mismo hosting (proteccion a nivel de BD)',
                        ],
                        'en' => [
                            'API Auth: federation support — ApiAuthMiddleware now validates federation_peers tokens in addition to cluster_nodes',
                            'Health: federation cron — musedock-federation appears in System Health as required cron with repair button',
                            'Race condition: unique index — Partial unique index prevents creating two active migrations for the same hosting (DB-level protection)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.51',
                'date' => '2026-04-03',
                'badge' => 'warning',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Firewall: reglas iptables manuales visibles — Muestra reglas añadidas fuera de UFW que antes eran invisibles. Indica que se procesan antes que UFW y pueden anularlas',
                            'Firewall: auditoria de puertos sensibles — Detecta SSH, panel (8444), portal (8446), MySQL, PostgreSQL y Redis abiertos a todo internet con alertas de severidad',
                        ],
                        'en' => [
                            'Firewall: manual iptables rules visible — Shows rules added outside UFW that were previously invisible. Indicates they are processed before UFW and can override it',
                            'Firewall: sensitive port audit — Detects SSH, panel (8444), portal (8446), MySQL, PostgreSQL and Redis open to the internet with severity alerts',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.50',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Fail2Ban: boton "Configurar Jails" — Cuando no hay jails activos, un boton configura automaticamente los 3 jails (panel, portal, WordPress), crea logs, configura Caddy y reinicia fail2ban',
                        ],
                        'en' => [
                            'Fail2Ban: "Setup Jails" button — When no jails are active, a button auto-configures all 3 jails (panel, portal, WordPress), creates logs, configures Caddy and restarts fail2ban',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.49',
                'date' => '2026-04-03',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Fail2Ban instalable desde web — Boton "Instalar Fail2Ban" en Settings con confirmacion, spinner de progreso, instalacion de paquete + configs + jails automaticos',
                            'Health: instalar binarios faltantes — Boton de descarga junto a cada binario "Not found" que instala el paquete via apt con spinner AJAX',
                        ],
                        'en' => [
                            'Fail2Ban installable from web — "Install Fail2Ban" button in Settings with confirmation, progress spinner, auto package + configs + jails setup',
                            'Health: install missing binaries — Download button next to each "Not found" binary that installs the package via apt with AJAX spinner',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'WireGuard: spinner de progreso — El boton "Instalar WireGuard" muestra spinner "Instalando..." mientras se instala',
                            'Health: spinner en reparaciones — Botones de reparar cron, fix timezone y reparar BD ahora muestran spinner durante la operacion',
                        ],
                        'en' => [
                            'WireGuard: progress spinner — "Install WireGuard" button shows spinner while installing',
                            'Health: spinner on repairs — Repair cron, fix timezone and repair DB buttons now show spinner during operation',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.48',
                'date' => '2026-04-03',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Boton "Reparar BD" en Settings → Health — Re-ejecuta schema.sql y migraciones pendientes. Crea tablas faltantes sin tocar datos existentes. Soluciona errores 500 por tablas inexistentes',
                        ],
                        'en' => [
                            '"Repair DB" button in Settings → Health — Re-runs schema.sql and pending migrations. Creates missing tables without touching existing data. Fixes 500 errors from missing tables',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'schema.sql ahora contiene TODAS las tablas del sistema — 15 tablas añadidas que solo existian en migraciones (hosting_web_stats, mail_*, monitor_*, replication_*, proxy_routes, database_backups, file_audit_logs, hosting_domain_aliases). Instalaciones nuevas nunca tendran tablas faltantes',
                            'Monitor: notificaciones email/telegram desactivadas por defecto — Evita errores en instalaciones nuevas sin SMTP/Telegram configurado',
                        ],
                        'en' => [
                            'schema.sql now contains ALL system tables — 15 tables added that only existed in migrations. Fresh installs will never have missing tables',
                            'Monitor: email/telegram notifications disabled by default — Prevents errors on fresh installs without SMTP/Telegram configured',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.47',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Hostname del servidor en el sidebar — Muestra el nombre del servidor debajo del logo y encima de la version, util para distinguir master de slave',
                            'Boton cerrar aviso de Cloudflare — El aviso de token SSL se puede cerrar desde el dashboard. El estado se guarda en panel_settings y no vuelve a aparecer',
                        ],
                        'en' => [
                            'Server hostname in sidebar — Shows server name below logo and above version, useful to distinguish master from slave',
                            'Dismiss Cloudflare warning button — SSL token warning can be closed from dashboard. State saved in panel_settings and does not reappear',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Version del panel ya no esta hardcoded — PANEL_VERSION se lee de config/panel.php en vez de estar duplicada en index.php',
                        ],
                        'en' => [
                            'Panel version no longer hardcoded — PANEL_VERSION reads from config/panel.php instead of being duplicated in index.php',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.46',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'schema.sql incluye todas las tablas — hosting_subdomains, hosting_bandwidth (con columna ts), hosting_bandwidth_hourly y hosting_subdomain_bandwidth ahora se crean en instalaciones frescas sin depender de migraciones',
                            'Instalaciones en servidores nuevos no fallan en /accounts — Todas las tablas referenciadas por BandwidthService existen desde el schema inicial',
                        ],
                        'en' => [
                            'schema.sql includes all tables — hosting_subdomains, hosting_bandwidth (with ts column), hosting_bandwidth_hourly and hosting_subdomain_bandwidth now created on fresh installs without depending on migrations',
                            'Fresh server installs no longer fail on /accounts — All tables referenced by BandwidthService exist from the initial schema',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Pagina de Import 10x mas rapida — El calculo de disco usa timeout de 3 segundos en vez de du sin limite. Directorios grandes no bloquean la carga',
                        ],
                        'en' => [
                            'Import page 10x faster — Disk calculation uses 3-second timeout instead of unlimited du. Large directories no longer block page load',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.45',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Error "relation servers does not exist" en /setup — La tabla servers se creaba despues de hosting_accounts que la referencia. Reordenado schema.sql para crear servers antes',
                            'Deteccion de firewall mejorada — iptables con policy DROP ahora se detecta correctamente aunque UFW este instalado pero inactivo. Ya no dice "sin firewall" cuando hay reglas iptables activas',
                        ],
                        'en' => [
                            'Error "relation servers does not exist" on /setup — servers table was created after hosting_accounts which references it. Reordered schema.sql to create servers first',
                            'Improved firewall detection — iptables with DROP policy now correctly detected even when UFW is installed but inactive. No longer says "no firewall" when active iptables rules exist',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Portal removido de la instalacion principal — El portal de clientes es un modulo separado de pago, ya no se ofrece durante la instalacion del panel',
                        ],
                        'en' => [
                            'Portal removed from main installer — Customer portal is a separate paid module, no longer offered during panel installation',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.44',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Deteccion de "admin off" en Caddyfile — El instalador detecta y reemplaza automaticamente "admin off" por "admin localhost:2019" para que la API funcione',
                            'Limpieza de autosave.json corrupto — Si el autosave tiene admin:disabled (de un Caddy pre-existente con admin off), se elimina antes de arrancar Caddy con --resume',
                            'Dominios del Caddyfile excluidos de migracion — Si Caddy ya tiene un dominio configurado en el Caddyfile, no se ofrece migrarlo desde Nginx/Apache (evita duplicados innecesarios)',
                        ],
                        'en' => [
                            'Detect "admin off" in Caddyfile — Installer auto-detects and replaces "admin off" with "admin localhost:2019" so the API works',
                            'Stale autosave.json cleanup — If autosave has admin:disabled (from pre-existing Caddy with admin off), it is deleted before starting Caddy with --resume',
                            'Caddyfile domains excluded from migration — If Caddy already has a domain in the Caddyfile, it is not offered for migration from Nginx/Apache (avoids unnecessary duplicates)',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Todas las llamadas curl al API de Caddy usan 127.0.0.1 en vez de localhost — Evita fallos en servidores con IPv6 deshabilitado donde localhost resuelve a ::1',
                            'Verificacion de API con reintentos — Si Caddy tarda en arrancar, el instalador reintenta 2 veces antes de continuar',
                            'Deduplicacion completa en migracion Nginx/Apache — Tres niveles: recoleccion, JSON y Caddy API',
                        ],
                        'en' => [
                            'All Caddy API curl calls use 127.0.0.1 instead of localhost — Prevents failures on servers with IPv6 disabled where localhost resolves to ::1',
                            'API verification with retries — If Caddy takes time to start, installer retries twice before continuing',
                            'Full deduplication in Nginx/Apache migration — Three levels: collection, JSON and Caddy API',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.42',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Firewall interactivo en el instalador — Al finalizar la instalacion, pregunta si configurar restriccion de IP para el puerto del panel. Detecta automaticamente UFW activo, UFW inactivo, iptables o sin firewall, e instala UFW si no hay ninguno',
                            'Doble capa de seguridad — Aplica reglas de firewall (UFW o iptables) Y actualiza ALLOWED_IPS en .env simultaneamente',
                            'Deteccion temprana de Caddy — El instalador ahora informa si Caddy ya esta corriendo en 80/443 antes de detectar Nginx/Apache, explicando por que no estan en esos puertos',
                        ],
                        'en' => [
                            'Interactive firewall in installer — After installation, prompts to configure IP restriction for the panel port. Auto-detects active UFW, inactive UFW, iptables, or no firewall, and installs UFW if none found',
                            'Double security layer — Applies firewall rules (UFW or iptables) AND updates ALLOWED_IPS in .env simultaneously',
                            'Early Caddy detection — Installer now reports if Caddy is already running on 80/443 before detecting Nginx/Apache, explaining why they are not on those ports',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Mensajes de Nginx/Apache mejorados — Cuando no escuchan en 80/443 porque Caddy ya los ocupa, el instalador lo explica en vez de decir solo "sin conflicto"',
                        ],
                        'en' => [
                            'Improved Nginx/Apache messages — When not listening on 80/443 because Caddy owns them, installer explains why instead of just saying "no conflict"',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.41',
                'date' => '2026-04-03',
                'badge' => 'warning',
                'changes' => [
                    'improved' => [
                        'es' => [
                            'Crons escalonados — Todos los crons del panel, CMS y servicios arrancan en segundos/minutos distintos para evitar picos de CPU al 100% (thundering herd). Aplicado automaticamente via update.sh',
                            'Monitor CPU real — El sample de CPU captura la realidad del sistema sin sleep aleatorio ni auto-medicion. Picos reales registrados tal como son',
                            'update.sh automatiza escalonamiento — Al actualizar, los crons se reescriben con offsets: cluster +5s, failover +10s, filesync +15s, bandwidth +20s, backup :02, CMS crons +3/+8 min',
                            'du cada 5 min — El calculo de disco solo corre cada 5 minutos en vez de cada 30 segundos, reduciendo picos de CPU',
                        ],
                        'en' => [
                            'Staggered crons — All panel, CMS and service crons start at different seconds/minutes to avoid 100% CPU spikes (thundering herd). Auto-applied via update.sh',
                            'Real CPU monitoring — CPU sample captures true system state without random sleep or self-measurement inflation',
                            'update.sh automates staggering — On update, crons auto-rewritten with offsets: cluster +5s, failover +10s, filesync +15s, bandwidth +20s, backup :02, CMS crons +3/+8 min',
                            'du every 5 min — Disk usage calculation runs every 5 min instead of 30s, reducing CPU peaks',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.40',
                'date' => '2026-04-03',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Migracion automatica de Nginx/Apache a Caddy — El instalador parsea sites-enabled, extrae dominio, document root, PHP y usuario, y crea las rutas Caddy automaticamente',
                            'Migracion de Apache — Mismo flujo que Nginx: parsea VirtualHosts, extrae ServerName, DocumentRoot y socket FPM',
                            'Import crea ruta Caddy automaticamente — Al importar un hosting sin ruta Caddy, se crea via API sin intervencion manual',
                            'Descubrimiento de sitios desde rutas Caddy — /accounts/import ahora detecta sitios migrados que estan fuera de /var/www/vhosts/ escaneando las rutas activas de Caddy',
                        ],
                        'en' => [
                            'Automatic Nginx/Apache to Caddy migration — Installer parses sites-enabled, extracts domain, document root, PHP and user, and creates Caddy routes automatically',
                            'Apache migration — Same flow as Nginx: parses VirtualHosts, extracts ServerName, DocumentRoot and FPM socket',
                            'Import auto-creates Caddy route — When importing a hosting without a Caddy route, it is created via API without manual intervention',
                            'Site discovery from Caddy routes — /accounts/import now detects migrated sites outside /var/www/vhosts/ by scanning active Caddy routes',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Import preserva rutas originales — Respeta el document root original del sitio (httpdocs, public, html, o cualquier ruta personalizada) sin mover archivos',
                            'UI de import muestra origen — Badge "desde Caddy" para sitios descubiertos via rutas Caddy vs directorio vhosts',
                        ],
                        'en' => [
                            'Import preserves original paths — Respects the original document root (httpdocs, public, html, or any custom path) without moving files',
                            'Import UI shows source — "from Caddy" badge for sites discovered via Caddy routes vs vhosts directory',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.39',
                'date' => '2026-04-03',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Web Stats por hosting — Pagina de estadisticas tipo AWStats con top paginas, IPs, paises, referrers, navegadores, bots, codigos HTTP y metodos',
                            'Visitantes unicos por dia — Grafica de barras con selector Hoy/7d/30d/1 ano',
                            'Navegadores y Bots separados — Tablas independientes con porcentaje y clasificacion automatica (Googlebot, AhrefsBot, Chrome, Safari...)',
                            'Referrers con link real — Click en el referrer abre la URL original. Filtrado automatico de self-referrals y panel (detecta hostname del servidor dinamicamente)',
                            'Bandwidth IN (uploads) — bytes_read del log de Caddy ahora se registra como bytes_in. Muestra IN + OUT en Account Details y graficas',
                            'IP real del visitante — Usa Cf-Connecting-Ip (Cloudflare proxy), X-Forwarded-For, o remote_ip (directo). Compatible con dominios con y sin proxy',
                            'Boton Stats en listado de hostings — Acceso directo a las estadisticas desde /accounts',
                            'Fail2Ban: boton Desactivar por jail — Permite desactivar temporalmente la proteccion WordPress u otros jails',
                            'Fail2Ban: modal de IPs baneadas — Click en el numero de IPs baneadas abre modal con opciones de desbanear o añadir a whitelist',
                            'Fail2Ban: info de configuracion visible — Cada jail muestra max intentos, ventana y duracion del ban',
                        ],
                        'en' => [
                            'Web Stats per hosting — AWStats-like page with top pages, IPs, countries, referrers, browsers, bots, HTTP codes and methods',
                            'Unique visitors per day — Bar chart with Today/7d/30d/1y selector',
                            'Browsers and Bots separated — Independent tables with percentage and auto-classification',
                            'Referrers with real link — Click opens original URL. Auto-filters self-referrals and panel (detects server hostname dynamically)',
                            'Bandwidth IN (uploads) — bytes_read from Caddy log now recorded as bytes_in. Shows IN + OUT in Account Details and charts',
                            'Real visitor IP — Uses Cf-Connecting-Ip (Cloudflare proxy), X-Forwarded-For, or remote_ip (direct)',
                            'Stats button in hosting list — Direct access to stats from /accounts',
                            'Fail2Ban: disable button per jail — Temporarily disable WordPress protection or other jails',
                            'Fail2Ban: banned IPs modal — Click banned count opens modal with unban and whitelist options',
                            'Fail2Ban: config info visible — Each jail shows max retries, find time and ban duration',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Fail2Ban WordPress: nombres amigables — Muestra "WordPress Sites" en vez del nombre tecnico del jail',
                        ],
                        'en' => [
                            'Fail2Ban WordPress: friendly names — Shows "WordPress Sites" instead of technical jail name',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Fail2Ban WordPress: IP de Cloudflare — El filtro ahora usa Cf-Connecting-Ip (IP real) en vez de client_ip (IP de Cloudflare). Antes baneaba IPs de Cloudflare bloqueando a todos los visitantes del edge node',
                            'CPU collector: auto-medicion — El sample de CPU ahora se toma antes de cualquier trabajo del collector para no inflarse a si mismo. Picos de CPU en graficas ahora reflejan el uso real del sistema',
                            'du cada 5 min en vez de 30s — Reduce drasticamente los picos de CPU del collector',
                        ],
                        'en' => [
                            'Fail2Ban WordPress: Cloudflare IP — Filter now uses Cf-Connecting-Ip (real IP) instead of client_ip (Cloudflare IP). Previously banned Cloudflare IPs blocking all visitors',
                            'CPU collector: self-measurement — CPU sample now taken before any collector work to avoid inflating itself',
                            'du every 5 min instead of 30s — Drastically reduces collector CPU peaks',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.38',
                'date' => '2026-04-02',
                'badge' => 'success',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Ancho de banda por hosting — Parseo automatico de logs Caddy cada 10 min, acumulado por cuenta y dia en base de datos. Los datos persisten aunque se borren/roten los logs',
                            'Ancho de banda por subdominio — Trafico individual por subdominio (api.factubase.com, portal.factubase.com, etc.) visible en el acordeon del listado',
                            'Columna BW en listado de hostings — Consumo del mes actual de cada hosting',
                            'Grafica de ancho de banda en Account Details — Chart.js con barras de trafico + linea de requests, selector 30d / 12m / Anual',
                            'Bandwidth en Account Details — Fila con bytes y requests del mes junto a Disk Usage',
                            'Totales globales en listado — Suma total de disco y ancho de banda de todos los hostings en la barra superior',
                            'Columnas ordenables — Click en Domain, Customer, User, PHP, Disk, BW, Status, Created para ordenar ascendente/descendente. Los subdominios se mueven con su dominio padre',
                            'Dashboard cards en tiempo real — CPU, RAM y Disco se actualizan cada 3s via /monitor/api/realtime',
                            'Dashboard modal de disco — Click en la card de Disk abre detalle con filesystem, inodes, top 10 directorios',
                        ],
                        'en' => [
                            'Bandwidth per hosting — Automatic Caddy log parsing every 10 min, aggregated per account per day in database. Data persists even if logs are rotated/deleted',
                            'Bandwidth per subdomain — Individual traffic per subdomain visible in the accordion listing',
                            'BW column in hosting list — Current month consumption per hosting',
                            'Bandwidth chart in Account Details — Chart.js with traffic bars + request line, 30d / 12m / Yearly selector',
                            'Bandwidth in Account Details — Row with bytes and requests next to Disk Usage',
                            'Global totals in listing — Total disk and bandwidth sum across all hostings in the top bar',
                            'Sortable columns — Click Domain, Customer, User, PHP, Disk, BW, Status, Created to sort asc/desc. Subdomain rows follow their parent',
                            'Dashboard real-time cards — CPU, RAM and Disk update every 3s via /monitor/api/realtime',
                            'Dashboard disk modal — Click Disk card for detail with filesystem, inodes, top 10 directories',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'du-throttled — Wrapper que limita du -sm al 50% de un core via SIGSTOP/SIGCONT (nunca supera ~50% CPU)',
                        ],
                        'en' => [
                            'du-throttled — Wrapper that limits du -sm to 50% of one core via SIGSTOP/SIGCONT (never exceeds ~50% CPU)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.37',
                'date' => '2026-04-02',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Subdominios — Pagina de edicion individual con Document Root y ajustes PHP independientes (hereda del dominio principal por defecto, personalizable con .user.ini)',
                            'Subdominios — Boton suspender/activar con pagina de mantenimiento Caddy. Solo se puede eliminar un subdominio estando suspendido',
                            'Subdominios — Acordeon en el listado de Hosting Accounts: clic en el badge de subdominios despliega las filas inline',
                            'Cloudflare DNS — Seleccion masiva con checkboxes: eliminar, activar/desactivar proxy y edicion masiva (cambiar tipo, contenido, TTL, proxy) en multiples registros',
                            'Cloudflare DNS — Modal de confirmacion al hacer toggle de proxy (nube naranja) tanto en la pagina DNS como en la vista de cuenta',
                            'Cloudflare DNS — Editar y crear registros ahora en modal SweetAlert en vez de formulario inline',
                            'Monitor — Cards de CPU y RAM ahora abren el modal de procesos (igual que en Dashboard)',
                            'Monitor — Cards de red ahora abren modal con detalle: velocidad real-time, IPs, MTU, totales desde boot, errores/drops',
                            'Monitor — Cards de disco ahora abren modal con detalle: uso, filesystem, inodes, top 10 directorios mas grandes',
                            'Monitor — Todas las cards (CPU, RAM, red) se actualizan en tiempo real cada 3s con datos de /proc/stat y /proc/meminfo',
                        ],
                        'en' => [
                            'Subdomains — Individual edit page with independent Document Root and PHP settings (inherits from parent by default, customizable via .user.ini)',
                            'Subdomains — Suspend/activate button with Caddy maintenance page. Deletion only allowed when suspended',
                            'Subdomains — Accordion in Hosting Accounts list: click subdomain badge to expand inline rows',
                            'Cloudflare DNS — Bulk selection with checkboxes: delete, toggle proxy and bulk edit (change type, content, TTL, proxy) on multiple records',
                            'Cloudflare DNS — Confirmation modal when toggling proxy (orange cloud) in both DNS page and account view',
                            'Cloudflare DNS — Edit and create records now in SweetAlert modal instead of inline form',
                            'Monitor — CPU and RAM cards now open process modal (same as Dashboard)',
                            'Monitor — Network cards now open detail modal: real-time speed, IPs, MTU, totals since boot, errors/drops',
                            'Monitor — Disk cards now open detail modal: usage, filesystem, inodes, top 10 largest directories',
                            'Monitor — All cards (CPU, RAM, network) update in real-time every 3s from /proc/stat and /proc/meminfo',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'CPU real — Dashboard, Monitor y collector ahora miden CPU real desde /proc/stat (sample 500ms) en vez de aproximar con load average',
                            'RAM real — Se usa MemTotal - MemAvailable de /proc/meminfo (uso real de apps) en vez del campo "used" de free que incluye cache del kernel',
                            'du -sm con nice/ionice — El calculo de disco de todos los hostings ahora corre con prioridad minima (nice -n 19, ionice idle) para no afectar al rendimiento',
                            'Listado de cuentas — Icono de abrir sitio junto al nombre del dominio, eliminado boton duplicado en acciones',
                            'Tabla de subdominios — Document root con text-overflow ellipsis y table-layout fixed para que no desborde la card',
                            'Deteccion de estado WireGuard — Las interfaces virtuales (wg0) ahora muestran "up" correctamente en vez de "unknown"',
                        ],
                        'en' => [
                            'Real CPU — Dashboard, Monitor and collector now measure real CPU from /proc/stat (500ms sample) instead of approximating with load average',
                            'Real RAM — Uses MemTotal - MemAvailable from /proc/meminfo (real app usage) instead of "used" from free which includes kernel cache',
                            'du -sm with nice/ionice — Disk usage calculation for all hostings now runs at minimum priority (nice -n 19, ionice idle) to avoid performance impact',
                            'Account list — Open site icon next to domain name, removed duplicate button in actions column',
                            'Subdomains table — Document root with text-overflow ellipsis and table-layout fixed to prevent card overflow',
                            'WireGuard state detection — Virtual interfaces (wg0) now correctly show "up" instead of "unknown"',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Caddy route ID collision — Los subdominios ya no colisionan con la cuenta principal. IDs ahora basados en dominio (hosting-{domain}) en vez de usuario (hosting-{username}). Migradas todas las rutas existentes',
                            'Cloudflare zona duplicada — MuseDock CMS ya no crea zonas en Cuenta 2 si el dominio ya existe en Cuenta 1',
                            'Editar registro DNS — El boton de editar (lapiz) ya no falla con registros que contienen comillas (TXT, DKIM)',
                        ],
                        'en' => [
                            'Caddy route ID collision — Subdomains no longer collide with parent account. IDs now domain-based (hosting-{domain}) instead of user-based (hosting-{username}). All existing routes migrated',
                            'Cloudflare duplicate zone — MuseDock CMS no longer creates zones in Account 2 if domain already exists in Account 1',
                            'Edit DNS record — Edit button (pencil) no longer fails on records containing quotes (TXT, DKIM)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.36',
                'date' => '2026-04-01',
                'badge' => 'danger',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Fail2Ban integrado — Proteccion contra fuerza bruta para panel admin, portal de clientes y WordPress, gestionado desde el panel sin plugins',
                            'Jail musedock-panel — Banea IPs tras 5 intentos fallidos de login al panel admin en 10 min (ban 1h)',
                            'Jail musedock-portal — Banea IPs tras 10 intentos fallidos de login al portal en 10 min (ban 30min)',
                            'Jail musedock-wordpress — Banea IPs tras 10 POSTs a wp-login.php o xmlrpc.php en 5 min (ban 1h). Automatico para todos los hostings sin plugin en WordPress',
                            'Auth logging — Intentos de login del panel y portal se escriben a /var/log/ con IP real del cliente (X-Forwarded-For tras Caddy)',
                            'Caddy access logging — Los hostings se registran automaticamente en el logger de Caddy al crear o reparar rutas',
                            'Banear IP manualmente — Nuevo boton en Settings > Fail2Ban para banear una IP en cualquier jail',
                            'Whitelist (ignoreip) — Gestion de IPs que nunca se banean, con soporte para CIDR. Boton rapido en cada IP baneada',
                            'Configs distribuidos via git — Filtros, jails y logrotate en config/fail2ban/, se instalan automaticamente con install.sh y update.sh',
                        ],
                        'en' => [
                            'Integrated Fail2Ban — Brute force protection for admin panel, customer portal and WordPress, managed from panel without plugins',
                            'musedock-panel jail — Bans IPs after 5 failed panel login attempts in 10 min (1h ban)',
                            'musedock-portal jail — Bans IPs after 10 failed portal login attempts in 10 min (30min ban)',
                            'musedock-wordpress jail — Bans IPs after 10 POSTs to wp-login.php or xmlrpc.php in 5 min (1h ban). Automatic for all hostings, no WordPress plugin needed',
                            'Auth logging — Panel and portal login attempts written to /var/log/ with real client IP (X-Forwarded-For behind Caddy)',
                            'Caddy access logging — Hostings auto-registered in Caddy logger when creating or repairing routes',
                            'Manual IP ban — New button in Settings > Fail2Ban to ban an IP in any jail',
                            'Whitelist (ignoreip) — Manage IPs that never get banned, with CIDR support. Quick button on each banned IP',
                            'Configs distributed via git — Filters, jails and logrotate in config/fail2ban/, auto-installed by install.sh and update.sh',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Portal IP siempre 127.0.0.1 — RateLimiter y auth log ahora usan la IP real del cliente en vez de la IP de Caddy',
                        ],
                        'en' => [
                            'Portal IP always 127.0.0.1 — RateLimiter and auth log now use real client IP instead of Caddy proxy IP',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.35',
                'date' => '2026-03-31',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Soporte WebTV CMS en migrador — Detecta config/Config.inc.php automaticamente, extrae credenciales BD y actualiza la config tras importar',
                            'DNS checks lazy via AJAX en /domains — La pagina carga instantaneamente, los checks DNS se hacen en background con spinners progresivos',
                        ],
                        'en' => [
                            'WebTV CMS support in migrator — Detects config/Config.inc.php automatically, extracts DB credentials and updates config after import',
                            'Lazy DNS checks via AJAX on /domains — Page loads instantly, DNS checks run in background with progressive spinners',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Pagina /accounts carga instantanea — Disco leido de BD (cacheado) en vez de du en tiempo real. Actualizado por monitor-collector cada minuto',
                            'IP del servidor cacheada en Settings — No llama a ifconfig.me en cada carga de /domains',
                        ],
                        'en' => [
                            'Instant /accounts page load — Disk read from DB (cached) instead of real-time du. Updated by monitor-collector every minute',
                            'Server IP cached in Settings — No longer calls ifconfig.me on every /domains page load',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.34',
                'date' => '2026-03-31',
                'badge' => 'primary',
                'changes' => [
                    'fixed' => [
                        'es' => [
                            'Corregida suspension de hostings — Al suspender, la ruta de mantenimiento se duplicaba con la ruta PHP original, permitiendo que el sitio siguiera accesible. Ahora usa PUT por ID que reemplaza la ruta directamente sin duplicados',
                        ],
                        'en' => [
                            'Fixed hosting suspension — When suspending, the maintenance route was duplicated with the original PHP route, allowing the site to remain accessible. Now uses PUT by ID which replaces the route directly without duplicates',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.33',
                'date' => '2026-03-31',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'request_terminate_timeout en todos los pools FPM — Mata scripts PHP que tarden mas de 120s, evitando que loops infinitos saturen la CPU. Aplicado a todos los pools existentes y por defecto en nuevos hostings',
                        ],
                        'en' => [
                            'request_terminate_timeout on all FPM pools — Kills PHP scripts running longer than 120s, preventing infinite loops from saturating CPU. Applied to all existing pools and by default on new hostings',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Corregido suspendAccount/activateAccount con PHP multi-version — Ahora recibe la version PHP del hosting en vez de usar el default global. Busca el pool en todas las versiones instaladas si no lo encuentra',
                        ],
                        'en' => [
                            'Fixed suspendAccount/activateAccount with multi-PHP — Now receives the hosting PHP version instead of using the global default. Searches all installed PHP versions if pool not found',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.32',
                'date' => '2026-03-31',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Modo vhost root en migracion — Poner la ruta del vhost (sin httpdocs) copia todo el directorio incluyendo .git y carpetas hermanas. Detecta automaticamente el proyecto dentro de httpdocs/',
                            'Checkbox "Copiar absolutamente todo" — Sin exclusiones de ningún tipo, oculta el selector de carpetas',
                            'Funcion setupPostgresDb() centralizada — Crea usuario, BD, importa dump, corrige ownership de todas las tablas/secuencias, aplica GRANT ALL y verifica conexion. Usada por Opcion 2, Opcion 3 y Resume',
                            'Listado completo de carpetas remotas — Ahora lista carpetas ocultas (.git, .claude, etc.) y carpetas de sistema (logs, tmp) en el selector de carpetas',
                        ],
                        'en' => [
                            'Vhost root mode in migration — Entering the vhost path (without httpdocs) copies the entire directory including .git and sibling folders. Automatically detects the project inside httpdocs/',
                            'Copy absolutely everything checkbox — No exclusions of any kind, hides the folder selector',
                            'Centralized setupPostgresDb() function — Creates user, DB, imports dump, fixes ownership of all tables/sequences, applies GRANT ALL and verifies connection. Used by Option 2, Option 3 and Resume',
                            'Complete remote folder listing — Now lists hidden folders (.git, .claude, etc.) and system folders (logs, tmp) in the folder selector',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'SCP como metodo de descarga prioritario — Mas fiable para archivos grandes. HTTP como fallback',
                            'Destino local se auto-ajusta — Si la ruta remota es vhost root, el destino local cambia a home_dir automaticamente',
                            'Ruta remota pre-rellenada — El campo de ruta remota en Opcion 3 viene con el valor correcto (sin /public)',
                            'Exclusion automatica de .claude, .vscode-server, .codex, .cline, .copilot en el tar (salvo con "copiar todo")',
                            'PostgreSQL: ownership y permisos corregidos automaticamente tras importar — Elimina errores de "permission denied" y "role does not exist"',
                        ],
                        'en' => [
                            'SCP as priority download method — More reliable for large files. HTTP as fallback',
                            'Local target auto-adjusts — If remote path is vhost root, local target changes to home_dir automatically',
                            'Remote path pre-filled — The remote path field in Option 3 comes with the correct value (without /public)',
                            'Automatic exclusion of .claude, .vscode-server, .codex, .cline, .copilot in tar (unless "copy everything")',
                            'PostgreSQL: ownership and permissions automatically fixed after import — Eliminates "permission denied" and "role does not exist" errors',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Corregida deteccion DB_CONNECTION en Laravel para Opcion 3 — La Opcion 3 tenia logica inline duplicada que no leia el driver. Ahora reutiliza parseLaravelEnv()',
                            'Corregido resume usando dump equivocado — Ahora usa el dump mas reciente en vez del primero alfabeticamente',
                            'Corregido .env no actualizado tras Opcion 3 — Las credenciales locales se escriben correctamente con el puerto adecuado (5432 para pgsql, 3306 para mysql)',
                        ],
                        'en' => [
                            'Fixed DB_CONNECTION detection in Laravel for Option 3 — Option 3 had duplicated inline logic that did not read the driver. Now reuses parseLaravelEnv()',
                            'Fixed resume using wrong dump file — Now uses the most recent dump instead of the first alphabetically',
                            'Fixed .env not updated after Option 3 — Local credentials are correctly written with the appropriate port (5432 for pgsql, 3306 for mysql)',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.30',
                'date' => '2026-03-30',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Portal de Clientes (Fase 0-3) — Nuevo portal independiente en puerto 8446 para que los clientes gestionen sus hostings, archivos y perfil',
                            'File Manager — Navegador de archivos con subida, descarga, editor de codigo, crear carpetas y eliminar. Ejecuta operaciones como el usuario del hosting via wrapper seguro',
                            'Sistema de invitaciones por email — El admin invita al cliente con un link seguro para que cree su propia contraseña. Token SHA-256 con caducidad 48h',
                            'Sistema de templates — Temas Light y Dark intercambiables desde Settings, con color de sidebar personalizable (10 presets + color picker)',
                            'Settings > Portal Clientes — Tabs de Acceso Clientes y Apariencia, gestion de acceso con invitar/reset/revocar, estado del portal y licencia',
                            'Perfil del cliente — Editar nombre, email, empresa, telefono y cambiar contraseña desde el portal',
                            'Dashboard con acordeon estilo Plesk — Cada hosting se despliega inline con tabs Info, Dominios, BD y Archivos',
                            'Vista detalle de hosting — Cabecera reutilizable con tabs Dashboard, Dominios, Archivos, y pagina completa',
                            'Router group() — Soporte para registrar rutas externas con prefijo y middleware independiente (para el portal)',
                            'LicenseService con JWT — Constantes de features del portal, verificacion JWT skeleton, portal stub para instalaciones sin licencia',
                            'Interfaces PortalProviderInterface y FileManagerInterface — Contratos para el modulo portal externo',
                            'Auto-heal TLS en cluster-worker — Regenera politicas TLS automaticamente cada minuto si detecta que faltan o estan incorrectas',
                        ],
                        'en' => [
                            'Customer Portal (Phase 0-3) — New independent portal on port 8446 for customers to manage their hostings, files and profile',
                            'File Manager — File browser with upload, download, code editor, create folders and delete. Runs operations as hosting user via secure wrapper',
                            'Email invitation system — Admin invites customer with secure link to create their own password. SHA-256 token with 48h expiry',
                            'Template system — Light and Dark themes switchable from Settings, with customizable sidebar color (10 presets + color picker)',
                            'Settings > Portal Clients — Customer Access and Appearance tabs, access management with invite/reset/revoke, portal status and license',
                            'Customer profile — Edit name, email, company, phone and change password from the portal',
                            'Plesk-style accordion dashboard — Each hosting expands inline with Info, Domains, DB and Files tabs',
                            'Hosting detail view — Reusable header with Dashboard, Domains, Files tabs, and full page view',
                            'Router group() — Support for external route registration with prefix and independent middleware (for portal)',
                            'LicenseService with JWT — Portal feature constants, JWT verification skeleton, portal stub for unlicensed installs',
                            'PortalProviderInterface and FileManagerInterface — Contracts for external portal module',
                            'TLS auto-heal in cluster-worker — Automatically regenerates TLS policies every minute if missing or incorrect',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Politicas TLS idempotentes — ensureTlsCatchAllPolicy() ahora genera el estado correcto desde cero, sin depender de lo que haya en Caddy',
                            'patchTlsPolicies() usa DELETE + PATCH — Reemplaza completamente las politicas en vez de mezclar con las del Caddyfile',
                            'max_execution_time del panel subido a 3600s — Migraciones de sitios grandes ya no mueren por timeout',
                            'Exclusiones lsyncd ampliadas — .copilot, .cache, .glide_cache, glide_cache, proxy_cache excluidos de la replicacion',
                            'CSP del portal con unsafe-inline para scripts — Necesario para onclick y scripts inline en el file manager',
                        ],
                        'en' => [
                            'Idempotent TLS policies — ensureTlsCatchAllPolicy() now builds correct state from scratch regardless of current Caddy state',
                            'patchTlsPolicies() uses DELETE + PATCH — Fully replaces policies instead of merging with Caddyfile ones',
                            'Panel max_execution_time raised to 3600s — Large site migrations no longer die from timeout',
                            'Extended lsyncd exclusions — .copilot, .cache, .glide_cache, glide_cache, proxy_cache excluded from replication',
                            'Portal CSP with unsafe-inline for scripts — Required for onclick and inline scripts in file manager',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Corregida deteccion PostgreSQL en Laravel — parseLaravelEnv() no leia DB_CONNECTION, causando que proyectos pgsql usaran mysqldump. Ahora detecta el driver correctamente',
                            'Corregido caddy reload borrando politicas TLS — El cluster-worker ahora detecta y regenera politicas perdidas automaticamente',
                        ],
                        'en' => [
                            'Fixed PostgreSQL detection in Laravel — parseLaravelEnv() was not reading DB_CONNECTION, causing pgsql projects to use mysqldump. Now detects driver correctly',
                            'Fixed caddy reload wiping TLS policies — Cluster-worker now detects and regenerates lost policies automatically',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.29',
                'date' => '2026-03-29',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Standalone Redirects — Crear redirecciones de dominio sin necesidad de cuenta de hosting, asignables a cliente, desde la pagina de Dominios',
                            'Auto document root public/ — La migracion detecta automaticamente proyectos Laravel/MuseDock con public/index.php y cambia el document root a public/',
                            'Verificacion de integridad en migraciones — Compara tamano de descarga con el remoto, test de tar.gz antes de extraer, reintento SCP automatico si descarga HTTP incompleta',
                            'Botones Cancelar/Reintentar en migraciones — Cuando una migracion se queda colgada, aparecen botones para cancelar, reintentar todo o continuar solo la importacion de BD pendiente',
                            'Endpoint Resume BD — Si la migracion murio durante la importacion, se puede retomar solo la BD sin volver a descargar archivos',
                        ],
                        'en' => [
                            'Standalone Redirects — Create domain redirects without a hosting account, assignable to customers, from the Domains page',
                            'Auto document root public/ — Migration automatically detects Laravel/MuseDock projects with public/index.php and changes document root to public/',
                            'Integrity verification in migrations — Compares download size with remote, tar.gz test before extraction, automatic SCP retry if HTTP download incomplete',
                            'Cancel/Retry buttons in migrations — When a migration stalls, buttons appear to cancel, retry all, or continue only the pending DB import',
                            'Resume DB endpoint — If migration died during import, DB can be resumed without re-downloading files',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Timeout de migracion aumentado a 1 hora — El panel PHP ya no mata migraciones de sitios grandes (antes: 10 min)',
                            'Timeout de tar remoto aumentado a 15 min — Sitios grandes ya no generan backups truncados (antes: 30 seg)',
                            'Exclusiones lsyncd mejoradas — Cachees de imagenes (.glide_cache, proxy_cache) y herramientas de desarrollo (.copilot, .cache) excluidas de la replicacion',
                            'Pagina de Dominios separada — Aliases/redirects de hosting y standalone redirects en secciones diferentes para mayor claridad',
                            'Campos SSH siempre visibles en migracion BD standalone — Eliminada casilla innecesaria, SSH es el metodo por defecto',
                            'Confirmacion con password de admin para eliminar redirects standalone',
                        ],
                        'en' => [
                            'Migration timeout increased to 1 hour — Panel PHP no longer kills large site migrations (was: 10 min)',
                            'Remote tar timeout increased to 15 min — Large sites no longer generate truncated backups (was: 30 sec)',
                            'Improved lsyncd exclusions — Image caches (.glide_cache, proxy_cache) and dev tools (.copilot, .cache) excluded from replication',
                            'Domains page separated — Hosting aliases/redirects and standalone redirects in different sections for clarity',
                            'SSH fields always visible in standalone DB migration — Removed unnecessary checkbox, SSH is the default method',
                            'Admin password confirmation required to delete standalone redirects',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Corregido tar truncado en migraciones — El timeout de 30s del SSH causaba que el tar se cortara en sitios grandes, resultando en archivos faltantes',
                            'Corregida descarga incompleta sin deteccion — Ahora se verifica el tamano y la integridad del tar antes de extraer',
                        ],
                        'en' => [
                            'Fixed truncated tar in migrations — The 30s SSH timeout caused tar to be cut off on large sites, resulting in missing files',
                            'Fixed undetected incomplete downloads — Size and tar integrity are now verified before extraction',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.28',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Politicas TLS multi-cuenta Cloudflare — Caddy genera certificados SSL usando el token correcto segun la cuenta CF donde este cada dominio, soportando multiples cuentas CF simultaneas',
                            'Refresco automatico de zonas Cloudflare — Al crear un hosting, alias o redirect con un dominio no conocido, el panel consulta la API de CF y actualiza la lista de zonas automaticamente',
                            'Subdominios en pagina de dominios ��� La vista /domains ahora muestra los subdominios debajo de cada dominio principal con badge y estado DNS',
                            'Full Manager con dominio preseleccionado — Al hacer clic en "Full Manager" desde la ficha de un hosting, la pagina de Cloudflare DNS abre con la zona del dominio ya seleccionada',
                        ],
                        'en' => [
                            'Multi-account Cloudflare TLS policies — Caddy generates SSL certificates using the correct token for each domain based on which CF account it belongs to, supporting multiple CF accounts simultaneously',
                            'Automatic Cloudflare zone refresh — When creating a hosting, alias or redirect with an unknown domain, the panel queries the CF API and updates the zone list automatically',
                            'Subdomains in domains page — The /domains view now shows subdomains below each main domain with badge and DNS status',
                            'Full Manager with preselected domain — Clicking "Full Manager" from a hosting account page opens Cloudflare DNS with the domain zone already selected',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Corregido error SSL 525 en dominios de cuentas CF secundarias ��� Los certificados fallaban porque Caddy usaba un unico token para todos los dominios; ahora cada cuenta CF tiene su propia politica TLS',
                            'Corregido nombre duplicado de subdominios en /domains — Se mostraba sub.dominio.com.dominio.com en vez de sub.dominio.com',
                        ],
                        'en' => [
                            'Fixed SSL 525 error for domains in secondary CF accounts — Certificates failed because Caddy used a single token for all domains; now each CF account has its own TLS policy',
                            'Fixed duplicated subdomain name in /domains — Was showing sub.domain.com.domain.com instead of sub.domain.com',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.27',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Deteccion automatica de proyectos en migracion — El migrador ahora detecta automaticamente MuseDock CMS, Laravel, WordPress y Zend/SocialEngine sin necesidad de seleccionar manualmente el tipo',
                            'Soporte Zend/SocialEngine — Detecta application/settings/database.php y extrae credenciales (host, username, password, dbname) automaticamente',
                            'Soporte MuseDock CMS — Detecta archivo muse + .env con credenciales DB_NAME, DB_USER, DB_PASS y DB_DRIVER',
                            'Soporte PostgreSQL en migracion — Cuando el proyecto usa DB_DRIVER=pgsql, se usa pg_dump/psql en lugar de mysqldump/mysql',
                            'Modo Auto en migracion de BD standalone — Nuevo boton "Auto" que detecta el tipo de proyecto sin seleccion manual',
                        ],
                        'en' => [
                            'Automatic project detection in migration — The migrator now automatically detects MuseDock CMS, Laravel, WordPress and Zend/SocialEngine without manual selection',
                            'Zend/SocialEngine support — Detects application/settings/database.php and extracts credentials (host, username, password, dbname) automatically',
                            'MuseDock CMS support — Detects muse file + .env with DB_NAME, DB_USER, DB_PASS and DB_DRIVER credentials',
                            'PostgreSQL support in migration — When the project uses DB_DRIVER=pgsql, pg_dump/psql are used instead of mysqldump/mysql',
                            'Auto mode in standalone DB migration — New "Auto" button that detects the project type without manual selection',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Deteccion de proyectos en subdominios — Ahora detecta MuseDock, Laravel, WordPress y Zend en subdominios migrados',
                            'Menos conexiones SSH — La deteccion de proyecto se hace en una sola llamada SSH en vez de multiples',
                        ],
                        'en' => [
                            'Project detection in subdomains — Now detects MuseDock, Laravel, WordPress and Zend in migrated subdomains',
                            'Fewer SSH connections — Project detection now uses a single SSH call instead of multiple',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Corregido error PREG_OFFSET_CAPTURE en WP-Cron — La constante PHP estaba mal referenciada dentro del namespace, causando error 500 al desactivar WP-Cron',
                            'Corregida falsa deteccion Laravel — Archivos .env sin DB_DATABASE (como los de SocialEngine) ya no se detectan como Laravel, se continua con la cascada de deteccion',
                            'Excluido dominio principal de lista de subdominios — El propio dominio de la cuenta ya no aparece como subdominio a migrar',
                            'Corregido tar exit code 1 en subdominios — Archivos que cambian durante compresion se tratan como warning, no como error fatal',
                            'Corregida re-migracion de subdominios — Si un subdominio ya existe de un intento anterior, se reutiliza en vez de fallar',
                        ],
                        'en' => [
                            'Fixed false Laravel detection — .env files without DB_DATABASE (like SocialEngine ones) are no longer detected as Laravel, detection cascade continues',
                            'Excluded main domain from subdomain list — The account own domain no longer appears as a subdomain to migrate',
                            'Fixed tar exit code 1 for subdomains — Files changed during compression are treated as warning, not fatal error',
                            'Fixed subdomain re-migration — If a subdomain already exists from a previous attempt, it is reused instead of failing',
                        ],
                    ],
                ],
            ],
            [
                'version' => '1.0.25',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Toggles Email/Telegram en alertas — En Monitor > Alert Settings se puede activar o desactivar independientemente el envio de alertas por email y por Telegram',
                            'Gestion de WP-Cron — Deteccion automatica de WordPress en cada cuenta. Card con estado de WP-Cron (activo/desactivado) y boton para activar/desactivar',
                            'Desactivar WP-Cron masivo — Boton en el listado de hostings para desactivar WP-Cron en todos los WordPress activos de una vez',
                        ],
                        'en' => [
                            'Email/Telegram toggles for alerts — In Monitor > Alert Settings you can independently enable or disable alert notifications via email and Telegram',
                            'WP-Cron management — Automatic WordPress detection per account. Card showing WP-Cron status (active/disabled) with toggle button',
                            'Bulk disable WP-Cron — Button in hosting list to disable WP-Cron in all active WordPress accounts at once',
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
                'version' => '1.0.24',
                'date' => '2026-03-28',
                'badge' => 'primary',
                'changes' => [
                    'new' => [
                        'es' => [
                            'Propagacion de token Cloudflare a slaves — Al marcar "Actualizar token de Caddy" en el master, el token se propaga automaticamente a /etc/default/caddy de todos los slaves via sync de cluster',
                        ],
                        'en' => [
                            'Cloudflare token propagation to slaves — When checking "Update Caddy token" on master, the token is automatically propagated to /etc/default/caddy on all slaves via cluster sync',
                        ],
                    ],
                    'improved' => [
                        'es' => [
                            'Dashboard SSL: aviso mejorado con contexto segun rol — Slave muestra instrucciones para configurar desde el master; Master/standalone muestra link directo a Settings; todos muestran nota sobre importancia para slaves',
                        ],
                        'en' => [
                            'Dashboard SSL: improved warning with role-specific context — Slave shows instructions to configure from master; Master/standalone shows direct link to Settings; all show note about importance for slaves',
                        ],
                    ],
                    'fixed' => [
                        'es' => [
                            'Slaves sin token Cloudflare — Los slaves no tenian forma de obtener el token de Caddy ya que /etc/default/caddy no se replica por BD ni por lsyncd. Ahora se propaga desde el master al sincronizar cuentas Cloudflare',
                            'Dashboard: notas informativas con fondo blanco ilegible en tema oscuro — Cambiado a fondo oscuro semitransparente consistente',
                        ],
                        'en' => [
                            'Slaves without Cloudflare token — Slaves had no way to get the Caddy token since /etc/default/caddy is not replicated via DB or lsyncd. Now propagated from master when syncing Cloudflare accounts',
                            'Dashboard: informational notes with white background unreadable in dark theme — Changed to consistent semi-transparent dark background',
                        ],
                    ],
                ],
            ],
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
