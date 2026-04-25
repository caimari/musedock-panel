# Changelog

Todas las versiones notables de MuseDock Panel se documentan aquí.

## [1.0.121] — 2026-04-25

### Docs
- Nueva guia `/docs/mail/hostname` explicando dominio raiz vs `mail.dominio.com` como hostname de correo.
- La guia cubre Solo Envio, Relay Privado, Correo Completo, DNS A/TXT/MX, PTR/rDNS, Cloudflare `Solo DNS`, certificados TLS y pasos de cambio desde Infra.
- `/docs/mail-sections` y `/docs/mail/infra` enlazan la nueva guia para que aparezca en el mapa Mail y en busqueda.

## [1.0.120] — 2026-04-25

### Fixed
- SweetAlert global: cualquier llamada directa a `Swal.fire()` usa ahora el tema oscuro del panel por defecto.
- Modales: corregido contraste de texto en todos los modales, incluyendo confirmaciones de eliminar dominio relay, reparacion DKIM, loaders y resultados.
- Modales con fondo claro explicito reciben fallback de texto oscuro para evitar titulos grises/blancos ilegibles.

## [1.0.119] — 2026-04-25

### Fixed
- `/mail?tab=general`: corregido el icono vacio de la card `Dominios relay activos` usando un icono compatible con la version actual de Bootstrap Icons.
- SweetAlert: `SwalDark` queda expuesto globalmente para que los modales de Mail usen tema oscuro real y textos legibles.
- Modales: contraste reforzado para titulos y contenido en tema oscuro, con fallback legible si algun modal usa fondo claro.

## [1.0.118] — 2026-04-25

### Improved
- `/mail?tab=relay`: los botones de verificacion DNS muestran spinner y quedan deshabilitados mientras se ejecuta la comprobacion.
- Relay: el refresco global `Refrescar DNS + BD` y el refresco individual `Revisar DNS` tienen feedback visual inmediato para evitar dobles envios o dudas durante la espera.

## [1.0.117] — 2026-04-25

### New
- Relay: nuevo historico persistente en BD (`mail_relay_events`) para eventos Postfix `sent`, `deferred` y `bounced` parseados desde `mail.log`/`maillog`.

### Improved
- `/mail?tab=queue`: el historico reciente del relay ahora lee desde BD, no directamente desde el archivo de log.
- `Vaciar mail.log`: antes de truncar `mail.log`/`maillog`, el panel archiva los eventos detectados en BD y conserva el historico.
- Las metricas de General (`emails enviados`, diferidos y rebotes) usan el historico persistente en BD.

### Fixed
- Borrar o rotar `mail.log` ya no hace desaparecer el historico del relay mostrado por el panel.

## [1.0.116] — 2026-04-25

### Improved
- Sidebar: el enlace `Mail` apunta siempre a `/mail?tab=general` y `/mail` redirige a esa URL para dejar la pestaña General explicita.
- `/mail?tab=general`: se elimina el bloque duplicado de General y se consolidan las cards de resumen.
- `/mail?tab=general`: cards dinamicas por modo de correo:
  - Relay Privado: emails enviados, dominios relay activos, usuarios SMTP habilitados y cola actual.
  - Solo Envio: emails enviados, estado DKIM, backend local/remoto y diferidos/rebotes.
  - Correo Completo: dominios, buzones, aliases y storage mail.
  - SMTP Externo: proveedor SMTP, usuario SMTP, remitente y DNS remitente.
- Las metricas de envio se leen de forma acotada desde `mail.log`/`maillog` para reflejar actividad real reciente.

## [1.0.115] — 2026-04-25

### New
- `/mail?tab=deliverability`: nuevo canal de test `Relay autenticado (SASL/STARTTLS)` para probar credenciales creadas en `Usuarios SMTP del relay` contra host/puerto/usuario/password reales.

### Improved
- El test autenticado del relay usa el mismo flujo que un SaaS remoto: STARTTLS, AUTH LOGIN/SASL, `MAIL FROM` con el remitente elegido y mensaje `texto + HTML`.
- Health repair cron: el template de `musedock-backup` pasa a ejecutarse como root preparando `storage/backups` para `postgres:www-data`, evitando errores de redireccion por permisos del usuario `postgres`.

### Notes
- La password del relay no se muestra desde la BD por seguridad; para probar credenciales hay que introducir la password generada al crear el usuario SMTP.

## [1.0.114] — 2026-04-25

### Improved
- `Test de envio`: añade cabeceras `List-ID` y `List-Unsubscribe` para que herramientas como Mail-Tester no penalicen el mensaje por faltar baja de lista en pruebas tipo campana.
- `/mail?tab=deliverability`: texto de ayuda aclarado; Mail-Tester llama "autenticado" a SPF/DKIM/DMARC, mientras que SMTP AUTH requiere usuario/password y no sustituye la firma DKIM.

### Notes
- SMTP autenticado valida credenciales de conexion, pero la firma DKIM depende de OpenDKIM/Postfix (`smtpd_milters`/`non_smtpd_milters`) y de que el dominio remitente tenga clave/selector correcto.

## [1.0.113] — 2026-04-25

### Improved
- `/mail?tab=deliverability`: persistencia del ultimo resultado DNS (incluyendo `A hostname`, `PTR/rDNS` y `blacklists`) para que la vista no vuelva a `N/D` al recargar si ya se comprobo.
- `/mail?tab=deliverability`: validacion de PTR mas robusta, aceptando coincidencia por alias/fcRDNS cuando apunta a la misma IP del hostname esperado.
- `Test de envio`: ahora genera mensaje `multipart/alternative` real (`text/plain + text/html`) para mejorar validacion externa.
- `Test de envio`: nuevo selector de canal (`Auto`, `Local`, `SMTP autenticado`); en `Auto` usa SMTP autenticado en modo externo y flujo local en modos con Postfix local.

### Fixed
- Falsos positivos de `PTR/rDNS = Revisar` cuando el PTR era valido pero no coincidia de forma literal con el hostname configurado.
- En entregabilidad diferida, A/PTR/blacklists ya no se pierden tras recarga cuando no se ejecuta un nuevo check.

## [1.0.112] — 2026-04-25

### Improved
- `/mail?tab=deliverability`: bloque de test externo de reputacion con enlace directo a `https://mail-tester.com/`, recomendaciones de validacion (`SPF/DKIM/DMARC PASS`, `rDNS OK`) y UI alineada para envio de test.
- `/mail?tab=deliverability`: formulario de `Test de envio` ampliado con selector de origen de remitente (`Recomendado`, `mail_from_address`, `Email admin`) y envelope sender forzado para pruebas SPF/DMARC mas realistas.
- `/mail?tab=deliverability`: aviso contextual cuando `non_smtpd_milters` no incluye OpenDKIM, para detectar rapidamente por que un test local puede salir sin firma DKIM.
- `/mail` (General): nueva card de mantenimiento `Normalizar DKIM` visible tambien cuando el sistema esta estable, para reaplicar socket/permisos/milters de OpenDKIM sin esperar a estado de fallo.
- Reparador local de mail: ahora normaliza `smtpd_milters` y `non_smtpd_milters` asegurando OpenDKIM sin eliminar otros milters ya existentes.

### Security
- `/mail/repair-local`: bloqueo defensivo si no se detecta instalacion local de mail (`repair_available=false`), evitando ejecutar reparaciones en nodos sin huella local.
- Modal de reparacion: texto explicito de seguridad indicando que no se sobrescriben dominios, cuentas, buzones, aliases, cola ni DNS.

### Docs
- `/docs/mail/deliverability`: ampliada con caso anonimo de arquitectura hibrida multi-proveedor, tabla de referencia tipo Cloudflare y notas de coexistencia SPF/DKIM/DMARC/MX/PTR.
- `/docs/mail/deliverability`: nuevas recomendaciones operativas de cuentas `dmarc@`, `postgresql@`, `root@` y flujo de test de reputacion.
- `/docs/mail/relay`: documentacion nueva sobre SaaS autenticado por Relay Privado (cuando se cumplen DKIM/SPF/DMARC) y aplicacion al portal de clientes/apps.
- `/docs/mail/webmail`: seccion anadida sobre autenticacion de usuarios webmail contra backend IMAP/SMTP configurado.

## [1.0.111] — 2026-04-25

### Improved
- `/mail?tab=deliverability`: tras pulsar `Comprobar DNS ahora` en modo relay, la siguiente carga muestra tambien checks en caliente de `A hostname`, `PTR/rDNS` y `blacklists` (no solo estado diferido de BD).
- `/mail?tab=deliverability`: "Registros recomendados" pasa a tabla orientada a Cloudflare con columnas `Tipo`, `Nombre (Host)`, `Contenido (Value)`, `Prioridad`, `Proxy`, `TTL` y `Donde`.
- `/mail?tab=deliverability`: normalizacion del campo `Host` para zona raiz (`@`) y subdominios relativos, facilitando copia directa en Cloudflare.

### Docs
- `/docs/mail/deliverability`: anadidas notas operativas para coexistencia con otros proveedores/relays (DKIM por selectores, SPF unico combinado, DMARC unico y manejo de MX/PTR).

## [1.0.110] — 2026-04-25

### New
- Docs Mail: nueva guia padre `/docs/mail-sections` y guias hijas por seccion en `/docs/mail/{slug}` (`general`, `domains`, `webmail`, `relay`, `queue`, `migration`, `infra`, `deliverability`), con vista 404 dedicada para slugs no registrados.

### Improved
- `/docs`: la home incorpora Mail como guia padre y la busqueda indexa tambien metadatos/contenido de las nuevas guias hijas de Mail.
- `/mail?tab=deliverability`: la comprobacion DNS pasa a modo on-demand; al entrar en `/mail` ya no se lanzan checks automaticamente y se ejecutan solo al pulsar `Comprobar DNS ahora`.
- `/mail?tab=deliverability` en modo relay: el boton `Comprobar DNS ahora` ejecuta chequeo DNS y sincroniza estado en BD (`active/pending`) en una sola accion.
- Deliverability rows: cuando no se ha lanzado check on-demand, la vista muestra estado diferido usando ultimo estado guardado en BD (`spf_verified`, `dkim_verified`, `dmarc_verified`) en vez de forzar resoluciones DNS en cada carga.

### Fixed
- `/docs`: icono roto en card padre de Mail corregido usando icono compatible (`bi-envelope-fill`).

## [1.0.109] — 2026-04-25

### Improved
- `/mail` (tabs Relay, Queue, Webmail, Migracion e Infra): se reemplazan confirmaciones nativas (`confirm/alert/prompt`) por modales SweetAlert2 para una UX consistente.
- `/mail?tab=relay` y `/mail?tab=deliverability`: accion unificada `Refrescar DNS + BD` (sin botones redundantes por fila) con feedback mas claro de dominios pendientes.
- `/mail?tab=infra&setup=1`: cuando ya hay configuracion, el estado inicial aparece como `Configurado` y el CTA pasa a `Actualizar ...` segun el modo en lugar de `Instalar ...`.
- `/mail?tab=infra&setup=1`: nuevos avisos de coherencia entre hostname de mail, DNS (A/MX/PTR) y parametros de Webmail.

### Fixed
- Relay deliverability: la validacion DKIM ahora usa el selector real del dominio (no solo `default` fijo), evitando falsos `pending` cuando el selector cambia.
- DNS checks de entregabilidad: se refuerzan TXT/A/PTR combinando `dns_get_record` con consultas `dig` (resolver local + 1.1.1.1 + 8.8.8.8) para reducir resultados inconsistentes por cache/resolver local.
- Acciones delicadas de Relay (`borrar dominio`, `borrar usuario`, `borrar cola`, `borrar mensaje`, `borrar historico`) ahora requieren password admin tambien en backend, no solo en frontend.

## [1.0.108] — 2026-04-25

### New
- `/mail?tab=infra&setup=1`: el instalador de mail ahora precarga la configuracion actual (modo, destino local/remoto, hostname, relay/WireGuard/SMTP) y muestra un resumen de "modo actual configurado".
- `/mail?tab=relay` y `/mail?tab=deliverability`: nuevo refresco masivo de dominios relay para sincronizar estado DNS en BD (`active/pending`) desde un solo boton.

### Improved
- `/mail?tab=webmail`: la configuracion queda plegable cuando ya esta configurada, mostrando resumen actual de proveedor/host/IMAP/SMTP.
- `/mail?tab=webmail`: edicion protegida por candado con bloqueo por defecto; los parametros IMAP/SMTP gestionados por el modo de correo quedan bloqueados en duro con aviso para evitar romper la configuracion.
- `/mail?tab=webmail`: los inputs solo se autocompletan con defaults cuando hay backend de mail capaz de proveerlos; si no, quedan vacios (sin placeholders forzados).
- `/mail?tab=webmail`: reorganizacion visual para dejar cada boton de accion debajo de su bloque funcional (instalacion, hostnames extra, sieve).
- `/mail?tab=queue`: todas las acciones destructivas y de mantenimiento de cola/historico usan confirmaciones SweetAlert2 en lugar de `confirm()` nativo.

### Fixed
- `/mail`: al refrescar un dominio relay se conserva la pestaña de origen (`relay` o `deliverability`) y no se fuerza volver siempre a `relay`.

## [1.0.107] — 2026-04-25

### New
- Docs Settings: nueva estructura de guias padre/hijas con rutas dedicadas (`/docs/settings-sections`, `/docs/settings/{slug}`), incluyendo guias base de Cluster, Federation y replica espejo PostgreSQL master/slave.
- `/docs/settings/{slug}`: boton real de estrella para anadir/quitar una guia en "Accesos directos especiales", guardado de forma persistente en configuracion del panel.
- `/mail?tab=queue`: nuevo boton para vaciar el historico del relay (`mail.log`/`maillog`) con confirmacion previa.

### Improved
- `/docs`: la busqueda ahora indexa tambien contenido interno de las paginas, no solo titulos/descripciones.
- `/docs`: home reorganizada para mostrar guias padre, accesos directos especiales y guias especiales, con iconos mas consistentes visualmente.
- `/mail?tab=queue`: selector de paginado del historico relay (`25/100/200/500/1000`) y conservacion del estado de pagina/tamano tras acciones de cola.
- Header del panel: hora del sistema en tiempo real con dia de la semana y segundos, sincronizada con zona horaria del servidor.
- Header del panel: reloj y boton de update/version alineados juntos a la derecha.
- `/docs/mail-modes`: anadido boton "Volver a Docs" junto al acceso de vuelta al instalador.

### Fixed
- `/mail?tab=queue`: acciones de cola (reintentar/borrar) ya no resetean contexto del historico; mantienen paginacion seleccionada.

## [1.0.106] — 2026-04-25

### New
- `/docs`: nueva home de documentacion interna con indice de temas y busqueda simple; los enlaces globales de Docs ahora apuntan a esta home.

## [1.0.105] — 2026-04-25

### Improved
- Docs Mail: enlace global en el footer lateral y acceso directo desde Settings para abrir `/docs/mail-modes` sin entrar primero al instalador de Mail.

## [1.0.104] — 2026-04-25

### New
- `/mail?tab=queue`: nueva pestaña Cola para Relay Privado con cola real de Postfix, historico paginado y acciones para reintentar, borrar `deferred`, borrar toda la cola o borrar un mensaje concreto por Queue ID.

### Fixed
- UI dark: los textos de ayuda de formularios (`form-text`) y bloques de Mail quedan forzados a colores claros para evitar texto negro sobre tarjetas oscuras.

## [1.0.103] — 2026-04-25

### Improved
- `/mail?tab=relay`: los campos para crear usuarios SMTP ahora tienen labels y ayuda clara: usuario, descripcion, limite por hora, dominios remitentes permitidos y relacion con `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_FROM_ADDRESS`.

## [1.0.102] — 2026-04-25

### Improved
- `/mail?tab=relay`: añade instrucciones visibles para editar/cambiar despues el relay, incluyendo hostname, IP WireGuard, dominio remitente, DNS y refresco de SPF/DKIM/DMARC.

## [1.0.101] — 2026-04-25

### Fixed
- Relay Privado: al instalar o reparar el modo relay, limpia `relayhost`, `transport_maps` y mapas SMTP salientes antiguos para evitar que Postfix siga intentando entregar por proveedores previos como `smtp.*`.
- Reparador mail: en modo relay tambien elimina transportes y credenciales SMTP salientes obsoletas antes de reiniciar Postfix.

## [1.0.100] — 2026-04-25

### Improved
- `/mail?tab=relay`: los ultimos envios ahora muestran el detalle real de Postfix (`dsn`, `relay` y motivo entre parentesis) para entender por que un envio queda `deferred` o `bounced`.

## [1.0.99] — 2026-04-25

### Improved
- `/mail?tab=relay`: añade una guia visible de activacion de dominios relay con los pasos autorizar dominio, publicar DNS en Entregabilidad y crear usuario SMTP.
- Relay: explica que `pending` significa DNS incompleto y enlaza directamente a `Entregabilidad` para copiar SPF/DKIM/DMARC/A/PTR.
- Relay: muestra el DNS base esperado del relay (`A`, `PTR/rDNS` y endpoint WireGuard STARTTLS).

## [1.0.98] — 2026-04-25

### Fixed
- Relay domains: guarda `spf_verified`, `dkim_verified` y `dmarc_verified` como booleanos PostgreSQL explicitos (`t/f`) para evitar `invalid input syntax for type boolean: ""`.
- Relay SMTP: al crear usuarios SASL, refuerza permisos de `/etc/sasldb2` para que Postfix pueda leer la base de autenticacion y reinicia Postfix.

## [1.0.97] — 2026-04-25

### Fixed
- Relay SMTP: los usuarios SASL ahora se crean con el realm del dominio remitente (`mail_outbound_domain`/`mydomain`) en vez del hostname del relay, evitando `454 Temporary authentication failure`.

## [1.0.96] — 2026-04-25

### Fixed
- `/mail`: en modo Relay Privado, `Mail Domains` deja de aparecer fuera de su pestaña y ya no se ofrece como flujo principal para crear buzones.
- Relay: crear dominio o usuario SMTP ya no puede terminar en 500 sin contexto; las excepciones se capturan y se muestran como error legible.

### Improved
- `/mail?tab=relay`: añade instrucciones claras para Laravel/SaaS con `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y STARTTLS.
- `/mail/domains/create`: bloquea la creacion de dominios de buzones cuando el modo actual no es Correo Completo y redirige al flujo correcto del relay.

## [1.0.95] — 2026-04-25

### Fixed
- OpenDKIM relay/satellite: corrige el timeout causado por `/run/opendkim` creado como `root:root` mientras OpenDKIM intenta crear el socket como usuario `opendkim`.
- Reparador mail: el override systemd ahora ejecuta OpenDKIM como servicio `simple` bajo `opendkim:opendkim`, con `RuntimeDirectory` propio y `ExecStart` en foreground.
- Reparador mail: elimina `UserID` de `/etc/opendkim.conf` en modo reparacion y anade `postfix` al grupo `opendkim` para poder usar el socket Unix.

## [1.0.94] — 2026-04-25

### Fixed
- `/mail`: el reparador local ya no usa el modal nativo del navegador ni una redireccion muda; ahora ejecuta por AJAX y muestra el resultado real.
- Reparador mail: errores internos, respuestas no JSON y fallos de systemd/apt se muestran en pantalla con detalle.

### Improved
- `/mail`: SweetAlert2 muestra confirmacion, spinner y fases de reparacion mientras se corrige OpenDKIM/Postfix.

## [1.0.93] — 2026-04-24

### New
- `/mail`: reparador de instalacion local incompleta para casos donde Postfix/OpenDKIM quedaron a medias durante el setup.

### Fixed
- Reparador mail: recrea `/run/opendkim`, tmpfiles, override systemd, socket local y permisos de OpenDKIM, reinicia OpenDKIM/Postfix y marca el mail local como configurado solo si ambos quedan activos.
- `/mail`: detecta restos de instalacion o IP WireGuard no asignada y muestra una accion clara de reparacion en General/Infra.

### Improved
- Instalador mail: tarjetas y modal sin fondos suaves de colores; ahora usan paneles oscuros sobrios con borde de seleccion.

## [1.0.92] — 2026-04-24

### Fixed
- Relay/Satellite mail setup: prepara `/run/opendkim`, tmpfiles y override systemd antes de reiniciar OpenDKIM para evitar timeouts del servicio.
- Relay/Satellite mail setup: normaliza `UserID opendkim:opendkim` y `/etc/default/opendkim` con el socket esperado.
- Tema oscuro: alertas `danger`, `warning`, `success` e `info` usan fondos oscuros y texto legible.

### Improved
- Instalador mail: las tarjetas de modo tienen descripcion mas clara y legible sobre fondo oscuro.

## [1.0.91] — 2026-04-24

### Fixed
- Instalador mail local: corregido el endpoint de progreso para importar `MailService`, evitando errores 500 que la UI mostraba como "Error de conexion, reintentando...".
- Instalador mail local: Relay Privado valida que la IP WireGuard indicada este asignada realmente al servidor antes de lanzar Postfix.
- Tema oscuro: inputs con autofill de Chrome mantienen fondo oscuro y texto blanco.

### Improved
- Instalador mail: las respuestas no JSON o errores del endpoint de progreso se muestran en pantalla con detalle en vez de quedar en reintentos silenciosos.

## [1.0.90] — 2026-04-24

### Improved
- `/docs/mail-modes` y modal de instalacion mail: ejemplos neutralizados con dominios genericos, sin hosts privados del entorno.
- Setup inicial: nueva seccion de firewall que detecta firewall activo y permite abrir SSH/puerto panel solo para una IP o rango de confianza.
- Setup inicial: si no hay firewall activo, ofrece preparar UFW con `deny incoming`, `allow outgoing`, SSH y puerto panel restringidos antes de activarlo.
- Login: ahora muestra mensajes `success` y `warning` del setup, no solo errores.

## [1.0.89] — 2026-04-24

### New
- `/docs/mail-modes`: primera pagina de documentacion interna para explicar los modos Satellite, Relay Privado, Correo Completo y SMTP Externo.

### Improved
- `/mail?tab=general`: instalador de mail con modal de ayuda para elegir modo, ejemplos de uso y diferencias claras entre SaaS local, relay WireGuard y buzones completos.
- `/mail?tab=general`: textos ampliados para hostname, DNS, PTR/rDNS, Let's Encrypt, WireGuard, credenciales SMTP y confirmacion de admin.
- `/mail?tab=general`: recomendacion dinamica segun el modo seleccionado, incluyendo ejemplos genericos de envio por VPN y convivencia gradual con proveedores SMTP externos.
- `/settings/updates`: el check remoto resuelve primero el SHA real de `origin/main` y lee GitHub raw por commit para evitar cache stale de `main`.

## [1.0.88] — 2026-04-24

### Improved
- `/mail`: reorganizacion en tabs persistentes (`General`, `Dominios`, `Webmail`, `Migracion`, `Infra`, `Entregabilidad`) para reducir la densidad de la pagina y mantener el tab activo al recargar.
- `/mail`: estado real del servicio de correo visible en `General`, diferenciando servidor instalado, no instalado, slave gestionado desde master, SMTP externo y estados con alertas.
- `/mail/domains/create`: formulario adaptado al tema oscuro y con bloqueo visual si no hay backend de correo disponible.

### Fixed
- `/settings/updates`: las actualizaciones lanzadas desde la web ahora se ejecutan fuera del cgroup del panel usando `systemd-run`, evitando que el reinicio del servicio mate el updater antes de limpiar el estado.
- `/settings/updates`: recuperacion robusta de updates atascados; si la version local ya alcanzo la remota y no hay unidad de update activa, se limpia `update_in_progress`.
- `/mail/domains/create`: bloqueo backend para impedir crear dominios de mail cuando no existe servidor local configurado ni nodo remoto online.

## [1.0.87] — 2026-04-24

### New
- Roundcube: configuracion automatica de plugins `password` y `managesieve` para cambio de password, filtros, vacaciones/autoresponder y reenvios desde webmail.
- Mail full setup: Dovecot instala y activa `Sieve/ManageSieve` en nuevas instalaciones de correo completo.
- `/mail`: boton para activar `Sieve/ManageSieve` en instalaciones existentes, localmente o encolado a nodos mail remotos.
- `/mail`: hostnames webmail adicionales para publicar el mismo Roundcube como `webmail.cliente.com`.
- Admin mailbox edit: autoresponder conectado a Sieve en el nodo de correo.

### Improved
- Roundcube queda preparado para multi-dominio webmail sin instalar varias copias del cliente.
- `repair-caddy-routes.php` reinyecta tambien los hostnames webmail adicionales configurados.

## [1.0.86] — 2026-04-24

### New
- `/mail`: proveedor webmail configurable con Roundcube como primer proveedor soportado y SnappyMail/SOGo reservados para futuras versiones.
- Nuevo instalador bajo demanda `bin/webmail-setup-run.php` para descargar Roundcube, crear su configuracion IMAP/SMTP y publicar el hostname en Caddy.
- Settings persistentes `mail_webmail_*` para separar proveedor, hostname webmail, servidor IMAP y servidor SMTP.

### Improved
- `/mail`: nueva tarjeta Webmail con fases de implantacion, estado de instalacion y enlace directo al webmail publicado.
- La instalacion de webmail no se ejecuta durante `update.sh`; requiere accion explicita del admin y password del panel.
- `repair-caddy-routes.php`: repara tambien la ruta webmail instalada para recuperarla tras reinicios o reloads de Caddy.

## [1.0.85] — 2026-04-24

### New
- Relay privado: los nuevos usuarios SMTP guardan la contraseña cifrada y recuperable en BD para permitir futuras migraciones sin regenerar credenciales.
- `/mail`: nuevo migrador de correo con preflight seguro para `satellite`, `relay` y `full`.
- `/mail`: migracion operativa de `relay privado` a otro nodo, importando dominios DKIM y usuarios SASL recuperables.

### Improved
- `/mail`: la tabla de usuarios relay indica si la credencial es recuperable (`cifrada`) o legacy, para saber si se puede migrar sin reset.
- Migrador full mail: queda bloqueado en preflight con aviso explicito hasta implementar rsync/corte controlado de Maildirs.

## [1.0.84] — 2026-04-24

### Improved
- `/settings/updates`: el polling web detecta fin de update, cambio de version y reinicio del panel con cache-busting, recargando la pagina automaticamente al terminar.
- `/mail?setup=1`: limpieza de placeholders/autofill en SMTP externo, relay WireGuard y passwords para evitar valores pegados por el navegador.
- Relay privado: la IP publica del relay pasa a ser opcional; si se deja vacia, el instalador detecta la IPv4 publica del nodo y la guarda para SPF/PTR/blacklists.
- SMTP externo: `From name` deja de tener valor hardcodeado por defecto; queda vacio salvo que el admin lo defina.

## [1.0.83] — 2026-04-24

### New
- Mail setup: cuarto modo `Relay Privado (WireGuard)` para montar un relay SMTP propio accesible solo por VPN.
- Relay privado: Postfix + OpenDKIM multi-dominio + SASL, sin Dovecot/Rspamd ni recepcion publica de correo.
- `/mail`: gestion de dominios autorizados del relay con DKIM independiente, verificacion SPF/DKIM/DMARC y usuarios SMTP SASL.
- Satellite mode: failover opcional de relay privado a SMTP externo mediante transport map y healthcheck local.

### Improved
- Entregabilidad: puntuacion SPF/DKIM/DMARC/PTR/blacklists por dominio y soporte para dominios del relay privado.
- Setup full mail: preseed de Postfix compatible con shells sin here-string.

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
