# Changelog

Todas las versiones notables de MuseDock Panel se documentan aquí.

## [1.0.336] — 2026-10-06 — Relay de reserva con la IP propia de cada servidor

### Cambiado
- **Relay privado de reserva: cada servidor con su IP.** La reserva escuchaba en la IP flotante del master y solo arrancaba si el nodo de relevo se la "prestaba" al tomar el mando (en WireGuard los demás nodos siguen enviando esa IP al master caído). Ahora escucha en su propia IP de la VPN y en `127.0.0.1`, y arranca cuando el nodo manda, sin necesitar la flotante. Las reservas ya instaladas se pasan solas en el primer minuto tras actualizar (copia de `main.cf` y `master.cf`). Las aplicaciones deben enviar a `127.0.0.1:587` desde el mismo servidor.

## [1.0.335] — 2026-10-06 — Avisos por correo en HTML y crons de las webs en el slave

### Corregido
- **El antiguo master, al pasar a copia, seguía ejecutando los crons de sus webs** contra una base de solo lectura (picalias en mortadelo: un `schedule:run` por minuto, más de 50 000 errores desde el relevo). La copia de configuración solo apagaba lo que ella misma había copiado; las tareas que el slave ya tenía de antes, iguales que las del master, quedaban activas. Ahora, en el crontab de un **hosting del panel**, una tarea activa en el slave que también tiene el master se apaga y pasa al bloque copiado (copia previa en `/var/backups/musedock-mirror`); al promover se vuelve a encender. root, `/etc/cron.d` y las tareas propias de la máquina no se tocan (en servidores clonados son iguales en los dos y deben seguir).

### Cambiado
- **Los avisos por correo al administrador van también en HTML** (multipart, junto al texto de siempre): de qué servidor vienen, el asunto, el texto con sus párrafos y un pie con qué es y dónde se configura. Un correo de una sola línea en texto plano puntúa peor en los filtros de spam. Sin imágenes externas ni scripts.
- **Pruebas de aviso con texto completo** (botón de prueba, prueba de cada servidor SMTP y MCP `notify_configure test`): qué servidor, por dónde ha salido y qué avisos llegarán. Antes eran una línea ("Test - MuseDock Panel"), y la del MCP repetía el nombre del servidor en el asunto.

## [1.0.334] — 2026-10-06 — Copiar testigos a otro panel

### Añadido
- **`php bin/witness.php export-key <nombre>`**: da de alta en otro panel un testigo que este ya tiene, sin ver la clave. La clave solo sale por una tubería hacia `witness.php add` del otro panel; si la salida es la pantalla, se niega. Ejemplo: `php bin/witness.php export-key paquito | ssh root@otro "cd /opt/musedock-panel && php bin/witness.php add paquito <url> <huella>"`.

## [1.0.333] — 2026-10-06 — Certificados listos en el relevo y reparador de Caddy

### Añadido
- **Certificados de Caddy copiados al nodo de relevo.** Al tomar el mando, el relevo pone las webs del master, pero solo podía sacar el certificado cuando el DNS ya apuntaba a él: unos minutos de error de certificado tras cada relevo. Ahora el master copia a sus nodos los certificados de lo que sirve (Caddyfile y hostings) cada 6 h, solo si han cambiado, por el canal del cluster; Caddy los encuentra al momento y, cuando el master renueva, la copia siguiente trae el nuevo. En el nodo que recibe: solo si es slave, nunca cambia un certificado por otro que caduque antes, comprueba que la clave casa, guarda copia del anterior en `/var/backups/musedock-caddy-certs` y no recarga Caddy. A mano: `php bin/cluster-switch.php certs-sync` en el master.

### Corregido
- **La reposición de rutas de hostings (1.0.332) creaba en un slave la ruta de una web que el master sirve desde su Caddyfile** (muserelay.com en obelix). Esas webs quedan aparte en el slave hasta el relevo; ahora se saltan los dominios que aparecen en el Caddyfile del master (`/var/lib/musedock/Caddyfile.from-master`).
- **El reparador de Caddy fallaba al arrancar si nadie escuchaba en el puerto del panel** ("no se pudo preparar srv0/listeners"; Caddy: *cannot unmarshal array into ... tls_connection_policies*). Creaba el servidor del panel con `tls_connection_policies` = `[[]]` (una lista dentro de la lista) en vez de `[{}]`. Pasó en obelix al reiniciar con `--resume` sin el 8444. Corregido, y las políticas TLS del servidor del panel ya solo se ponen si faltan o no son válidas (antes se reescribían en cada pasada).
- **Si la API de Caddy no responde, la comprobación de rutas ya no bloquea al worker** (límite de 5 s; antes podía esperar 60 s en cada vuelta).

## [1.0.332] — 2026-10-06 — Webs de hostings que desaparecían de Caddy y envío local en el relay privado

### Añadido
- **Relay privado: envío también en `127.0.0.1:587`** (TLS obligatorio y usuario SMTP, igual que en la IP de la VPN). Una aplicación que vive en el mismo servidor que el relay puede enviar a `127.0.0.1:587` y funciona igual en el que manda y en su relevo, sin depender de la IP flotante. Se añade solo a los relays ya instalados (y a las reservas) en el primer minuto tras actualizar; copia de `master.cf` antes de tocarlo.

### Corregido
- **Hostings sin web ni certificado tras reiniciar o recargar Caddy.** Las rutas de los hostings solo viven en la memoria de Caddy: un arranque sin `--resume` o un `caddy reload` desde el Caddyfile las borraba y nadie las reponía (vocal9.com en asterisk, desde el 05-10; tampoco estaba en su relevo). Ahora el panel repone las rutas de hostings activos y sus redirecciones que falten: al arrancar Caddy, en cada actualización y cada 10 min desde el cluster-worker. Solo añade: no toca una ruta que existe ni un dominio que ya sirve otra ruta (p. ej. un bloque fijo del Caddyfile).
- **"Vaciar" el histórico del relay no vaciaba la tabla.** Solo vaciaba `mail.log`, pero la tabla se lee del histórico guardado en la base de datos, así que todo volvía a salir. Ahora la tabla empieza de cero desde que se vacía; lo anterior sigue guardado (estadísticas) y se ve con "ver también lo anterior". Botón renombrado a *Vaciar histórico*.
- **Tarjeta del cluster: "Correo — no lleva correo" en el relevo de un relay privado.** Con un master relay (sin buzones) ahora sale **Relay de correo**: "reserva lista (parada hasta que mande)" o "sin reserva".
- **Backups de bases de datos: "Cleanup" pasa a "Ordenar lista"** y pide confirmación explicando qué hace: quita de la lista los backups cuyo archivo ya no existe y añade los archivos que faltaban. Nunca borró ni borra archivos.
- **Caddy con `--resume` en todos los nodos.** `install.sh` ya lo ponía, pero los nodos montados de otra forma no lo tenían. `update.sh` lo añade (`caddy.service.d/zz-musedock-resume.conf`) partiendo del arranque actual; vale desde el próximo arranque, sin reiniciar Caddy.

## [1.0.331] — 2026-10-06 — Relay privado de reserva

### Añadido
- **Relay privado de reserva en el servidor de relevo.** Las aplicaciones envían a la IP flotante de la VPN, que en un relevo pasa al que toma el mando, pero ese servidor no tenía relay. Ahora se puede instalar en él una reserva con la misma configuración (nombre, IP flotante, red, dominio), **parada** mientras es copia:
  - `php bin/cluster-switch.php relay-standby <nodo> [--apply]` en el que manda (el instalador del relay tiene un modo reserva: configura todo y deja Postfix y OpenDKIM parados);
  - cada 5 min el que manda envía los dominios con su clave DKIM y los usuarios SMTP con su contraseña, por el canal del cluster y solo si cambian; los usuarios quitados se quitan también en la reserva;
  - cada minuto el panel arranca el relay si el nodo manda y tiene la IP flotante, y lo para si es copia o está apartado;
  - en una reserva parada, importar dominios o usuarios ya no arranca Postfix ni OpenDKIM.
  - Explicado en *Docs → Relay*.

## [1.0.330] — 2026-10-06 — Textos de los correos a clientes, ventana propia al traer cuentas de Cloudflare y tope de avisos

### Añadido
- **Textos de los correos a tus clientes del portal** (*Ajustes → Notificaciones*): asunto y texto de la invitación al portal y del cambio de contraseña, con `{nombre}` y `{empresa}`; párrafos separados por una línea en blanco. El saludo, el botón con el enlace y el aviso de caducidad se añaden solos. Vacío = el texto de por defecto (se ve en gris). Se copian a los nodos con el resto de avisos.
- "Marca en los correos a clientes" pasa a llamarse **Nombre de tu empresa**, con una explicación clara (solo afecta a los correos a tus clientes del portal, no a los avisos que te llegan a ti).

### Cambiado
- **"Traer de otro nodo"** (cuentas de Cloudflare) usa la ventana del panel en vez de la del navegador: consulta con indicador de espera, lista de cuentas y nodos, confirmación y resultado.

### Arreglado
- **Tope diario de correos de aviso vacío o 0** (p. ej. copiado a un nodo desde otro que no lo tenía guardado) = el de por defecto (25); antes se quedaba en 5.

## [1.0.329] — 2026-10-06 — Cuentas de Cloudflare entre nodos, caddy-l4 y botones de copiar

### Arreglado
- **El botón "Instalar caddy-l4" no hacía nada** (ni progreso): la petición no llevaba el token CSRF y el panel la rechazaba al momento. Ahora lo lleva, muestra los segundos que lleva compilando y, si falla, el motivo. Lo mismo pasaba con "Probar fuentes remotas" del relevo. El panel acepta ahora el token también en la cabecera `X-CSRF-Token` (peticiones con cuerpo JSON).
- **En una copia (slave), la pestaña Cluster → Configuración salía vacía**: el cierre de la pestaña Failover estaba dentro del bloque que solo se pinta en el principal, y Configuración quedaba dentro de Failover, oculta.
- **Una copia que pide la configuración del relevo al principal ya no pierde sus cuentas de Cloudflare** si el principal no tiene ninguna (al recibir el envío del principal ya era así; al pedirla, no).

### Añadido
- **Traer cuentas de Cloudflare de otro nodo** (*Cluster → Failover → Cuentas Cloudflare*, en el principal): las que tiene otro nodo y aquí faltan, por nombre. El token viaja por el canal autenticado del cluster (como cuando el principal las envía), nunca por pantalla. Primero dice cuáles traería y pide confirmación; las de aquí no se tocan.
- **Cloudflare DNS**: botón directo a *Cuentas y tokens de Cloudflare*.
- **Notificaciones: botones de copiar** junto al Chat ID de Telegram, los usuarios SMTP y las contraseñas SMTP (principal y secundario). Las contraseñas no están en la página: se piden al panel al pulsar (con sesión y token CSRF) y queda apuntado en el registro.

## [1.0.328] — 2026-10-06 — Token de Telegram oculto y guía de notificaciones

### Añadido
- **Token de Telegram oculto** en *Ajustes → Notificaciones*: sale con asteriscos, con botón para verlo y otro para copiarlo (para ponerlo en otro servidor). Antes se veía entero.
- **Docs → Notificaciones: correo y Telegram** (nueva): servidor principal y de reserva, remitente y marca, cómo crear el bot de Telegram para una persona o un grupo, y qué puede (y no puede) hacer otra persona con el bot. Enlace desde *Ajustes → Notificaciones*.

### Arreglado
- **Los botones de prueba de correo y Telegram usaban lo guardado**, no lo escrito: fallaban si aún no se había pulsado Guardar, y solo decían "revisa la configuración". Ahora prueban lo que hay en el formulario (si la contraseña o el token están vacíos, los guardados), el de correo prueba el principal y el secundario por separado, y los dos dicen el motivo real del fallo (p. ej. Telegram "chat not found": hay que abrir el bot y pulsar Iniciar).

## [1.0.326] — 2026-10-06 — Correos a clientes en HTML y envío SMTP con un nombre real

### Arreglado
- **El panel se presentaba al servidor de envío (p. ej. Sweego) como `musedock-panel`**, que no es un nombre válido y queda en las cabeceras del correo (`Received`) como señal de spam. Ahora usa un nombre real del servidor: su nombre de envío o DNS inverso, el nombre del panel o el de la máquina.

### Añadido
- **Correos a clientes en formato enriquecido** (invitación y cambio de contraseña del portal): HTML sencillo con la marca, saludo, un botón y el enlace de reserva, más la versión en texto (`multipart/alternative`), sin imágenes externas ni scripts. Cada correo lleva su propio `Message-ID`. El texto ya no parece un correo de "phishing" (explica qué es y que la contraseña actual sigue valiendo si no lo pidió).
- **Marca en los correos a clientes** (*Ajustes → Notificaciones*, `notify_brand`); vacía = el nombre del dominio del remitente. Se copia a los nodos con el resto de ajustes de avisos.
- **Servidor SMTP secundario en la pantalla** (*Ajustes → Notificaciones*, opcional): host, puerto, cifrado, usuario, contraseña y remitente propio. Solo se usa si el principal falla o rechaza el envío. Antes el panel ya sabía usarlo, pero solo se podía configurar por MCP.

## [1.0.325] — 2026-10-06 — Las invitaciones del portal salen por el SMTP del panel

### Arreglado
- **Las invitaciones y los "Reset password" del portal no llegaban** (ni a spam). Se enviaban con `mail()` de PHP por el Postfix local y desde `noreply@<nombre del servidor>`, sin SPF, DKIM ni DNS inverso que cuadrase: Gmail los descartaba. Ahora salen por el mismo SMTP que los avisos del panel (*Ajustes → Notificaciones*: principal y secundario de reserva) con su remitente, y cuentan en el tope diario de correos a clientes.

## [1.0.324] — 2026-10-06 — "Reset password" del cliente vuelve a funcionar

### Arreglado
- **"Reset password" e "Invitar al portal" en la ficha del cliente no hacían nada** (desde la 1.0.319): el token de seguridad se metía en el JavaScript con el escapado de atributos HTML (`&quot;`), el script de la página fallaba entero y el botón no respondía.

## [1.0.323] — 2026-10-05 — Monitor: avisos que se cierran solos

### Arreglado
- **Monitor: los avisos de métricas se cierran solos.** CPU, RAM, disco, red y GPU quedaban "Active" para siempre aunque el problema hubiera pasado o el disco se hubiera silenciado (miles de avisos viejos en un servidor). Ahora, cada 10 min, se dan por vistos los de un recurso (tipo + disco/GPU) que lleva 15 min sin dispararse; los vistos de más de 30 días se borran como antes.

### Añadido
- **Ocultar un tipo de aviso también del monitor** (*Ajustes → Avisos*, debajo de cada "Silenciar"): además de no enviar correo, ni se apunta en el monitor. Para todos los servidores o solo uno (`servidor:TIPO`, por MCP `alerts_configure` con `hide`/`unhide`). Silenciar sigue igual (quita el correo, el aviso se ve en el monitor).

## [1.0.322] — 2026-10-05 — Eliminar cliente desde una ventana de confirmación

### Cambiado
- **Eliminar cliente** ya no pide la contraseña en la propia ficha: el botón abre una ventana que dice qué cliente se elimina y pide ahí la contraseña del administrador.
- **Los cambios de clientes llegan a las copias al momento.** Al crear, editar, eliminar, vincular o bloquear un cliente, el principal avisa a sus copias y estas lo traen enseguida (antes, hasta 5 min).
- **Un cliente eliminado en el principal se elimina también en las copias** (antes solo quedaba inactivo). Si en la copia aún tuviera hostings o dominios de correo, se desactiva y se elimina en cuanto el principal los desvincule.
- **A quién pertenece cada dominio de correo ahora también viaja entre servidores** (antes solo el de los hostings): las copias reciben los cambios del principal y la recuperación de clientes de otro nodo trae también sus dominios de correo. Una copia ya no conserva enlaces de correo viejos que le impedían eliminar un cliente que el principal ya había quitado.

### Añadido
- **Nombre de envío y DNS inverso por servidor** (*Correo → pestaña anti-abuso*): comprueba las tres cosas que miran Gmail y compañía (la IP de salida tiene DNS inverso, ese nombre apunta de vuelta a la IP y Postfix se presenta con él) y dice qué falta. Campo **nombre de envío** (`smtp_helo_name`) propio de cada servidor (no se copia): se aplica en Postfix al guardar y el cluster-worker lo mantiene. **Vacío = automático:** usa el DNS inverso de la IP de salida si apunta de vuelta a ella (y lo sigue si cambia); si no se puede comprobar, no toca nada. Lo normal es el nombre de cada máquina, distinto en cada una: no depende de quién mande, porque cada servidor envía por su IP. Un nombre que se mueve en los relevos (como el del correo) no sirve como DNS inverso de dos IPs. El correo que se recibe (MX, IMAP, certificado) no cambia.

## [1.0.321] — 2026-10-05 — Eliminar clientes con contraseña y buscador al vincular

### Añadido
- **Eliminar un cliente** desde su ficha (en el servidor que manda). Solo si no tiene hostings ni dominios de correo vinculados (si los tiene, hay que desvincularlos antes) y con la **contraseña del administrador** como confirmación. Antes la acción existía pero no había botón, y no pedía contraseña ni miraba los dominios de correo.
- **Buscador al vincular** hostings y dominios de correo ya creados: se escribe parte del nombre y sale la lista filtrada (en vez de un desplegable largo).

## [1.0.320] — 2026-10-05 — Clientes: identificador estable entre servidores

### Arreglado
- **Cambiar el correo de un cliente en el principal hacía que volviera duplicado.** La copia de clientes entre nodos los reconocía por el correo: si el principal cambiaba el correo de un cliente recuperado de otro nodo antes de que ese nodo se enterase, la recuperación automática volvía a traer el "viejo" como si fuera otro. Ahora cada cliente tiene un **identificador estable** (`uid`, migración nueva; los existentes se rellenan solos) que viaja entre nodos y no cambia con el correo: la copia y la recuperación reconocen primero por ese identificador, y solo si no lo tienen, por correo. Además, un cliente que una copia recibió del principal ya nunca cuenta como "propio" de esa copia (aunque el principal lo renombre o lo quite), así que no se recupera de vuelta.

## [1.0.319] — 2026-10-05 — Vincular hostings y dominios de correo ya creados a un cliente

### Arreglado
- **"Reset password" / "Invitar al portal" en la ficha del cliente daba "Token CSRF inválido".** El botón monta su formulario con JavaScript y copiaba el token de otro formulario de la página, pero esa página no tenía ninguno. Ahora el token va siempre en la página (plantilla principal), lo que arregla también otras pantallas con botones parecidos.

### Añadido
- **Vincular a un cliente hostings y dominios de correo ya creados** (ficha del cliente, en el servidor que manda): desplegable con los que aún no tienen cliente y botón para desvincular (con confirmación). Solo cambia a quién pertenece: el hosting o el correo no se tocan. El cliente lo ve en su portal. Antes solo se podía asignar al crear el hosting.
- En la ficha del cliente, "New Account" pasa a llamarse **Nuevo hosting**, y en una copia no salen los botones de crear ni de vincular.
- **Bloquear o permitir el acceso de un cliente al portal** (ficha del cliente, en el servidor que manda), reversible: no borra su contraseña. Bloqueado, no puede entrar, su sesión abierta se cierra en un minuto y se anulan los enlaces de invitación o cambio de contraseña pendientes (no puede desbloquearse solo); "Reset password" queda rechazado mientras esté bloqueado. Al permitirlo, entra con su contraseña de siempre. Se ve como "Bloqueado" en *Customers* y en *Portal Clientes*, y llega a las copias.
- **Sesiones de PHP caducadas de los hostings.** Cada hosting guarda sus sesiones en `sessions/` y la limpieza de PHP de Debian/Ubuntu solo vacía la carpeta del sistema: se acumulaban sin fin (en un servidor, 1,5 millones de ficheros y 3,3 GB). Ahora cada servidor borra cada noche (04:00) **solo** los ficheros `sess_*` de esas carpetas que llevan más de 24 h sin usarse (ajuste `session_cleanup_hours`; `session_cleanup_enabled=0` lo desactiva). Botón **Sesiones caducadas** en *Hosting Accounts*: cuenta primero, por hosting, y pide confirmación.

## [1.0.318] — 2026-10-05 — update.sh: validación del Caddyfile con el entorno de Caddy

### Arreglado
- **`update.sh` decía "Generated Caddyfile failed validation" en los servidores que sacan certificados por DNS de Cloudflare.** La validación se hacía sin el entorno del servicio de Caddy (`EnvironmentFile`, donde está el token), así que `{env.CLOUDFLARE_API_TOKEN}` llegaba vacío y fallaba siempre ("API token '' appears invalid"); se restauraba el fichero anterior y la reparación del bloque del panel nunca se aplicaba. Ahora valida con ese entorno. Además, si el Caddyfile generado es igual al que había, no reinicia Caddy.
- **Nodos con el nombre de reserva del cambio de rol.** Al registrar el principal anterior se le pide su nombre de máquina; si en ese momento no contesta (en mitad del cambio), queda como "Antiguo master (IP)". Ahora, cuando ya contesta, se le pone su nombre (p. ej. "mortadelo (10.10.70.1)"). Solo cambia el nombre que se ve.

## [1.0.317] — 2026-10-05 — Acceso de clientes al portal solo desde el servidor que manda

### Arreglado
- **En una copia (slave) se podía "Invitar al portal", "Reset password" y revocar el acceso de un cliente**, aunque crear, editar y borrar clientes ya estaba bloqueado. Lo hecho en la copia se perdía (el master la vuelve a copiar) y el enlace del correo no valía (el portal lo sirve el master). Ahora se rechaza en la copia, los botones no salen y *Customers* explica que los clientes se gestionan en el servidor que manda y llegan solos.

## [1.0.316] — 2026-10-05 — Clientes que no se pierden en un cambio de rol, espejo 100 % y desglose de GB

### Arreglado
- **Los clientes creados en el principal anterior se perdían tras un cambio de rol.** Los clientes y a quién pertenece cada hosting se guardan en la base del panel, que es de cada servidor; se copiaban del principal a las copias, pero al cambiar el mando nadie los traía de vuelta. Ahora el principal recupera cada 30 min los clientes **nacidos** en otro nodo (no los que ese nodo recibió copiados) y enlaza sus hostings si aquí no tienen cliente. Solo añade: nunca cambia ni borra. Incluye su acceso al portal (la contraseña viaja cifrada como hash).
  - Botón **Traer clientes de otros servidores** en *Customers* (en el master): enseña primero qué se traería y pide confirmación.

### Añadido
- **Espejo 100 %** en *Cluster → Archivos → Exclusiones base*: botones para rellenar las listas con **Espejo 100 %** (sin exclusiones: copia también `.git`, `node_modules`, carpetas de IA/IDE, registros, cachés y sesiones), **Espejo de código** (todo menos lo temporal) o **Valores por defecto**. No se aplica hasta guardar.
- **Desglose de GB entre servidores** (*Hosting Accounts*, en el master): al pulsar **Estado réplica** se abre una ventana con lo que hay aquí, lo que la copia excluye, lo esperado en la copia, lo que tiene de verdad, y qué significan "Sobran" y "Faltan". Explicado también en *Docs → Sync de archivos*.
- **Portal de clientes: cuánto dura "Mantener la sesión iniciada"** (*Ajustes → Portal Clientes → Apariencia*, ajuste `portal_session_remember_days`: 1/7/30/60/90/180/365 días o sin caducidad; 30 por defecto). Se copia a las réplicas con el resto de ajustes del portal. Sin marcar la casilla, la sesión del cliente se cierra al cerrar el navegador o tras 30 min sin actividad.
- **Portal de clientes: favicon propio** (*Ajustes → Portal Clientes → Apariencia*): SVG, PNG o ICO de hasta 64 KB, validado por su contenido (un SVG con scripts, eventos, enlaces o entidades se rechaza), con vista previa y botón para volver al de por defecto. Se guarda como ajuste y llega solo a las réplicas (también cuando se vuelve al de por defecto). La página "Portal no activado" lo muestra cuando la sirve el portal.

## [1.0.315] — 2026-10-05 — "Renovar ahora" de la licencia del portal

### Arreglado
- **"Renovar ahora" decía "Cannot reach license server" aunque el servidor de licencias respondía.** Cualquier respuesta de error (licencia transferida, ligada a otro servidor, caducada) se mostraba como "no se puede contactar". Ahora se ve el motivo real, y si la licencia está pendiente tras una transferencia o ligada a otro servidor, "Renovar ahora" (y la renovación automática) la activa directamente en este servidor con su clave.

## [1.0.314] — 2026-10-05 — La licencia del portal sigue al servidor que manda

### Añadido
- **La licencia del portal sigue al servidor que manda, sin transferirla a mano.** El panel tiene un identificador del clúster (`portal_instance_id`, el mismo en todos los nodos: se copia con los ajustes del portal) y lo envía al activar y renovar. El servidor de licencias (0.2.4) acepta la renovación desde cualquier servidor del mismo clúster y le pasa la licencia. Además, el servidor que sirve el portal la renueva él mismo (cada 6 h como mucho, si le quedan menos de 23 días o está ligada a otro servidor): tras un relevo o cambio de rol, en menos de 30 min queda a su nombre. Solo hace falta transferirla una vez, para que la licencia aprenda el identificador del clúster.

## [1.0.313] — 2026-10-05 — Sesión que no se pierde al abrir un enlace, "Mantener la sesión iniciada" y licencia del portal

### Arreglado
- **Al abrir un enlace del panel desde un correo (p. ej. un aviso en Gmail) mandaba al login y cerraba la sesión.** La cookie de sesión era `SameSite=Strict`: el navegador no la envía cuando se llega desde otro sitio. Ahora es `Lax` (se envía al abrir enlaces; los envíos de formularios desde otros sitios siguen sin cookie y además llevan token CSRF).
- **Tras iniciar sesión se vuelve a la página que se pidió** (la del enlace del correo), no al inicio.
- **La página pública "Portal no activado" enseñaba la dirección y el puerto del panel** (`portal.dominio:8444/settings/portal`) a cualquiera que entrase al portal. Ahora dice solo "Portal temporalmente no disponible", sin enlaces al panel, en español o inglés según el navegador, y con `noindex`.
- **Pestañas del portal** (*Acceso Clientes* / *Apariencia*): la activa se veía como un botón blanco que "no hacía nada"; ahora se ve como pestaña seleccionada.

### Añadido
- **"Mantener la sesión iniciada"** en el login. Sin marcar: la sesión se cierra al cerrar el navegador o tras 2 h sin actividad (como hasta ahora). Marcada: dura lo que diga *Ajustes → Seguridad* (1 día, 7 o 30 días —por defecto—, 2, 3 o 6 meses, 1 año o sin caducidad) aunque se cierre el navegador. Vale también con MFA.
- **Licencia del portal desde el panel** (*Ajustes → Portal Clientes*, debajo del estado de la licencia): campo con la clave, **Activar en este servidor** (activa o reactiva la clave aquí sin reinstalar el portal; p. ej. tras transferirla desde el servidor de licencias por un cambio de rol) y **Renovar ahora**. Antes solo se podía poner la clave al instalar.
- **Aviso de licencia del portal** (tipo de aviso `license`): el servidor que sirve el portal avisa por correo cuando su licencia caduca en 14, 7, 3 y 1 días, al entrar en el periodo de gracia y al caducar (una vez por etapa), con qué hacer en cada caso (renovar, transferir y activar, o alargarla).

## [1.0.312] — 2026-10-05 — Vigilante de cambios del sistema

### Añadido
- **Vigilante de cambios del sistema (posible intruso).** En todos los servidores, cada 10 min, se compara con la foto anterior lo que hay en los sitios donde se instalan apps o se esconde un intruso, y se avisa **una vez** de cada novedad (la primera vez solo se toma la foto):
  - carpetas nuevas en `/opt`, `/srv`, `/var/www` y `/etc` que no instala ningún paquete;
  - carpetas de `/var/www/vhosts` que no son de ningún hosting del panel;
  - servicios de systemd (y sus `.d/*.conf`) y tareas cron nuevos o cambiados; programas nuevos o cambiados en `/usr/local/bin` y `/usr/local/sbin`;
  - claves SSH autorizadas de root, de `/home` y de los hostings añadidas o quitadas (dice cuál, por su comentario);
  - ejecutables en `/tmp`, `/var/tmp` y `/dev/shm`.
  - Al actualizar el panel se rehace la foto de servicios, cron y programas sin avisar. Tipo de aviso nuevo `system_changes` (no se calla en modo mantenimiento). Rutas que cambian a menudo: *Ajustes → Avisos → Cambios del sistema: ignorar* o MCP `alerts_configure` con `ignore_system_paths` (admite `servidor:patrón`). Explicado en *Docs → Avisos*. Es un cable trampa, no un antivirus: un intruso que ya es root puede desactivarlo.
- **Carpetas sin copia al servidor de relevo: también `/var/www`** (fuera de `vhosts`, que ya se copia entera). Una app en `/var/www/miapp` no se copiaba ni se avisaba de ella; ahora entra en el aviso, en `sync-add`/`sync-local` y en MCP `filesync_extra_paths`.

## [1.0.310] — 2026-10-05 — Portal de clientes en el 443

### Arreglado
- **El portal de clientes publicado en el puerto 443 daba error 502.** La ruta de Caddy apuntaba al "puerto público + 1" (444) en vez de al puerto en el que escucha de verdad el proceso del portal (8447, el `-S 127.0.0.1:NNNN` de su unidad). Al actualizar se corrige sola: el panel ve que la ruta apunta a otro puerto y la rehace (como mucho en 5 min, o al momento con "Guardar y aplicar").
- **Dirección del portal** (*Ajustes → Portal Clientes*): el puerto propone 443 por defecto, y una vez guardada los campos quedan bloqueados con un candado (botón **Editar**) para no cambiarlos sin querer.

### Añadido
- **Aviso de apps que no llegarían al servidor de relevo.** Una app instalada fuera de los hostings (p. ej. `/opt/miapp`) no se copia sola, y su base de datos puede estar en la del panel, que es de cada servidor. Pasó con el servidor de licencias: tras el cambio de rol el nuevo principal tenía el programa pero no la base. Ahora, cada 30 min, el master revisa las carpetas de primer nivel de `/opt` y `/srv` y avisa (una vez; vuelve a avisar solo si cambia la lista) de las que no se copian ni están marcadas como propias de la máquina. No cuentan el propio panel, lo que instala un paquete del sistema ni las carpetas vacías. Para cada una:
  - `php bin/cluster-switch.php sync-add <carpeta>` (enseña el plan; `--apply` la añade a la copia en espejo hacia el nodo de relevo);
  - `php bin/cluster-switch.php sync-local <carpeta>` si es solo de esa máquina (copias, herramientas): no se copia ni se avisa;
  - `sync-status` lo resume; por MCP, `filesync_extra_paths` devuelve `unsynced` y acepta `local_paths`.
  - No se copia `/opt` entero a propósito: la copia es en espejo y borraría en el otro servidor lo que solo tiene él. El panel tampoco: cada servidor lo actualiza con `bin/update.sh`.
  - Nota en *Cluster → Archivos* y sección nueva en *Docs → Sync de archivos* (qué necesita una app para sobrevivir a un relevo: ficheros en la copia, base en una base replicada, servicio en la copia de configuración).
## [1.0.309] — 2026-10-05 — Comprobación de certificados en bucle cada 30 min

### Arreglado
- **La comprobación de certificados en bucle se ejecutaba cada minuto** en vez de cada 30: solo guardaba la hora de la última revisión si encontraba fallos. Revisaba el registro de Caddy (journalctl) sesenta veces por hora sin necesidad.
- **Los avisos "Réplica con problemas" y "Nodo caído" saltaban al primer minuto y no decían por qué.** Un reinicio del principal de 2 minutos (una prueba de Proxmox) mandaba "Réplica con problemas", "Nodo caído" y "Nodo recuperado". Ahora:
  - **esperan** a que la caída dure (5 min por defecto, ajustable en Avisos);
  - **dan un diagnóstico** en llano: si el otro servidor responde por la VPN, por su panel, por su base de datos y por internet, y qué ven los testigos. Así se sabe si está apagado o reiniciándose, si falla la VPN o si el problema es de la propia réplica;
  - "Réplica recuperada" dice cuánto duró y que no hay que hacer nada;
  - "Nodo recuperado" solo llega si antes llegó "Nodo caído".
- **El relevo no distinguía "se cayó una máquina" de "se cayó todo el sitio".** Con el principal en una VM con HA (Proxmox), si se cae su servidor físico, otra máquina lo arranca en 2-4 min; con el margen de 5 min, si tardaba algo más la réplica tomaba el mando y quedaban dos principales. Nuevo en *Cluster → Failover*: "Comprobaciones del mismo sitio" (otras máquinas que no dependen del principal: otro servidor, el router, la otra línea). Si alguna responde, la réplica espera más (15 min por defecto, ajustable) y avisa de que espera; si no responde nada del sitio, actúa como siempre. Se copia a las réplicas con la configuración del relevo.
- **Docs → Failover:** nueva sección "Qué pasa según lo que caiga, y cuánto dura" (una máquina con alta disponibilidad, la línea, el sitio entero, el camino entre sitios) y avisos de caída actualizados (espera, diagnóstico, modo mantenimiento).
- **Valores propios de MuseDock en instalaciones de clientes:**
  - la cuenta de Let's Encrypt usaba `admin@musedock.com` si el panel no tenía correo (los certificados del cliente quedaban a nombre de MuseDock); ahora usa el correo del propio panel o ninguno;
  - CardDAV proponía `dav.musedock.com`; ahora `dav.<dominio del correo del panel>`;
  - los ejemplos en pantalla ya no usan nombres ni IPs de los servidores de MuseDock.
- **Modo mantenimiento** (Ajustes → Avisos o MCP `alerts_configure` con `maintenance_minutes`): durante un trabajo programado (reinicios, pruebas de relevo, mudanzas de VM) no se envían los avisos de "algo no responde" (se apuntan igual). Se copia a todos los nodos; máximo 12 h.
- **Ficheros secretos de los hostings legibles por otros hostings.** Todos los usuarios de hosting están en el grupo `www-data` y había `.env` y `wp-config.php` en 644, 664, 755 e incluso 777: cualquier hosting podía leer las claves de otro. Nuevo `php bin/secure-secrets.php` (sin `--apply` solo enseña qué cambiaría): los deja en 600 para su dueño, o en root más el grupo propio del hosting y 640 si están cerrados por "Blindar WordPress". Lo que tiene un dueño inesperado no se toca y se avisa. Nada se borra.
- **"Blindar WordPress" (strict) dejaba el `wp-config.php` con el grupo `www-data`**, legible por cualquier otro hosting, y daba la lectura al usuario por ACL, que no se copia a la réplica (tras un relevo la web podía no leer su configuración). Ahora usa el grupo propio del hosting y permisos normales. Al desbloquear, el `wp-config.php` queda en 600.
- **Aceptar un control de hardening valía para todos los servidores.** Ahora se puede aceptar solo en uno: `nitro:SSHD PermitRootLogin` (en Avisos, en "otros controles aceptados", o por MCP `alerts_configure` → `accept_hardening`). Sin prefijo sigue valiendo para todos.
- **El portal de clientes no respondía en ningún servidor tras el cambio de rol.** Su ruta de Caddy era un bloque escrito a mano en el Caddyfile, atado al nombre de una máquina, y la copia de configuración (con razón) solo pasa las webs del 443; nadie encendía ni apagaba el portal en un relevo, y los clientes (la base del panel es de cada nodo) no llegaban a la copia. Ahora:
  - **nombre público propio** del portal (*Ajustes → Portal Clientes → Dirección del portal*, ajuste `portal_hostname`, p. ej. `portal.<dominio>`), nunca el de una máquina; las invitaciones usan ese nombre;
  - **solo lo sirve el servidor que manda**: servicio `musedock-portal` y ruta de Caddy `portal-domain-route` en el puerto del portal (8446) encendidos en el principal, apagados en las copias y en un nodo apartado. Se aplica al promover, al degradar y al apartarse, y cada 5 min el `cluster-worker` lo repone (tras una recarga de Caddy o si una copia pasa a mandar por un relevo automático). El certificado sigue la misma política que el nombre del panel;
  - **las copias reciben del principal** (acción `export-portal-state`, cada 5 min) los clientes del portal, a quién pertenece cada hosting y los ajustes del portal. Se empareja por email y por dominio (los ids no coinciden entre nodos). No se borra nada: un cliente que desaparece del principal queda desactivado en la copia, y los clientes propios de la copia no se tocan;
  - la copia de configuración ya no copia la unidad `musedock-portal.service` (la gestiona cada nodo).
- **Apoyo al portal de clientes (fase 4), sin cambiar nada de lo existente:**
  - `DatabaseService::changePassword()`: contraseña nueva generada para el usuario de una base (la devuelve una vez; mismo SQL que la solicitud de cambio por MCP);
  - `NotificationService::sendToAddress()`: correo a una dirección concreta (avisar al cliente de que su ticket tiene respuesta), con su propio tope diario (`notify_customer_email_daily_cap`, 50);
  - `View::renderFile()`: pantallas de un módulo dentro del diseño del panel (los tickets del portal en `/portal-admin/tickets`, con el login y el CSRF del panel). En *Ajustes → Portal Clientes* aparece el botón **Tickets de soporte** con los pendientes;
  - las copias reciben también los tickets de soporte del portal (mismos ids; cliente por email y hosting por dominio).
- **Las reglas de avisos no llegaban a las copias que cuelgan de otra copia** (Nitro, que sigue colgado de mortadelo tras el relevo). Ahora cada nodo que las recibe se las pasa a sus propias copias, en cascada y con un máximo de 3 saltos.

### Seguridad (auditoría)
- **CRÍTICO: páginas del panel accesibles sin iniciar sesión.** Cualquier ruta acabada en `.png`, `.css`, `.js`… (p. ej. `/mail/domains/1.png`) se saltaba el login. Ahora solo se saltan el login los ficheros que existen de verdad en `public/`.
- **Sesiones:** la cookie de sesión lleva `Secure` aunque el panel vaya detrás de Caddy; las sesiones caducan por inactividad (`SESSION_LIFETIME`); un admin desactivado o con otro rol lo nota en menos de 1 minuto.
- **Login:** límite de intentos también por usuario (fuerza bruta repartida entre IPs); el MFA se anula tras 5 códigos erróneos; el usuario se limpia antes de escribirlo en el log de acceso (se podían falsificar líneas que lee fail2ban).
- **WordPress / fail2ban:** el filtro `musedock-wordpress` solo cree `Cf-Connecting-Ip` si la conexión viene de Cloudflare (antes se podía banear a un tercero o librarse del ban con una cabecera falsa). **Hay que copiar el filtro a `/etc/fail2ban/filter.d/` y recargar la jaula** (lo hace `bin/update.sh`). `wp-harden ban` rechaza IPs locales, privadas y de Cloudflare. El análisis detecta imágenes e iconos con PHP dentro.
- **API de cluster/federación:** `backup_name` con `..` ya no puede borrar `storage/`; bloqueado el salto de ruta en `receive-files` y `restore-db-dumps` y validados los datos del manifest; el token de un peer federado ya no vale para la API del cluster ni mientras esté pendiente de aprobar; el handshake de un peer pendiente no instala clave SSH ni entrega el token; freno de 30 fallos de token / 10 min por IP; `panel_log` enmascara tokens y claves; las claves SSH deben ser de una sola línea; `tls_check public` rechaza IPs privadas.
- **Federación y hostings:** corregida una inyección de comandos con contraseñas de MySQL y un borrado de rutas en el rollback; se validan dominio, usuario, rutas, shell y versión de PHP al crear, importar o recibir hostings (también al generar el pool PHP-FPM).
- **Gestor de ficheros y portal de clientes:** la subida ya no sigue enlaces simbólicos fuera del hosting (en el panel y en el portal) y se corrigió la comprobación de prefijo en las descargas.
- **Migración:** las descargas por URL solo aceptan http/https hacia IP pública; los usuarios y hosts SSH que empiezan por `-` se rechazan; ficheros temporales con permisos 0600.
- **Clave SSH de federación:** la regex de shell que permitía `rsync; cualquier-comando` (root) se sustituye por `bin/federation-ssh-guard` (se copia a `/usr/local/bin` con `update.sh` e `install.sh`), que solo admite `rsync --server` bajo `/var/www/vhosts`, `psql`/`mysql` con usuario y base, y `echo OK`, sin shell. Si el guard falta, la clave no se instala. **Las claves ya instaladas hay que migrarlas a mano** (ver plan en el informe de auditoría).
- **Contraseñas fuera de la línea de comandos:** migraciones, copias de BD, réplica, correo, webmail/carddav y `chpasswd` pasan las contraseñas por variables de entorno, stdin o ficheros temporales 0600; ya no se ven con `ps`. Los instaladores de webmail y carddav no las escriben en su log.
- **Visor y vaciado de logs:** dentro de `/var/www/vhosts` solo se permiten ficheros de carpetas `logs`, y nunca `.env`, `wp-config*`, `.php`, `.pem`, `.key`, `.crt` ni `.sql`.
- **XSS:** unos 70 sitios más con datos dentro de `onclick`/`onsubmit` pasan a `View::js()` (20 vistas). Antes: nuevo `View::js()` y corregidos los botones con datos de cliente, copias, rutas proxy y panel inicial. Cabeceras: CSP mínima (`frame-ancestors`, `base-uri`, `object-src`) y Permissions-Policy.

## [1.0.308] — 2026-10-05 — Vigilancia de certificados de todas las webs

### Arreglado
- **El servidor de reserva salía "no sano" en la revisión del relevo** aunque estuviera perfecto. Al pasar a copia, el panel cierra a propósito su 80/443 (`closePublicPorts`) y los vuelve a abrir al tomar el mando, pero la comprobación miraba justo el 443. Ahora, para la reserva en estado normal, si su panel responde cuenta como viva y se anota que la web está cerrada a propósito. Para el principal no cambia nada: un 443 cerrado sigue siendo una caída.
- **Monitor sin tráfico web si `/var/log/caddy` o su registro eran de root** (creados por `update.sh` o `install.sh` antes que Caddy): Caddy no podía escribir. Ahora se dejan a nombre de `caddy`, también en la reparación automática cada 30 min.

### Añadido
- **Vigilancia de certificados (cada 6 h, en cada servidor).** Comprueba contra el propio servidor (da igual el proxy de Cloudflare) que cada web que sirve Caddy tiene un **certificado válido para su nombre** y que no está a **menos de 14 días de caducar sin renovarse**. Antes solo se detectaban los dominios que fallaban al pedir el certificado muchas veces seguidas.
  - Avisa por correo cuando cambia la lista de problemas, o una vez al día si alguno caduca en menos de 7 días.
  - El correo explica qué pasa según el proxy: en "Full (strict)", error 526; en "Full", funciona sin comprobar; con la nube gris, error en el navegador.
  - Los dominios sin DNS se separan y no avisan.
  - El aviso "Certificados" se puede silenciar en Avisos.
- **MCP `cert_status`** (lectura, con `node`): estado del certificado de todas las webs de un servidor, con sus problemas y las que antes caducan.

## [1.0.307] — 2026-10-04 — Avisos por servidor y desde cualquier nodo; arreglo del modo SSL de Cloudflare

### Arreglado
- **`cf_zone_ssl` y `cf_zone_ssl_set` (1.0.306) siempre decían "al token le falta el permiso"**: leían mal la respuesta de Cloudflare. Con `all=true` se consulta solo lo esencial (modo y "Automático") para no tardar minutos, y se listan aparte las zonas en "Automático".
- **`cf_zone_ssl_set` comprueba de verdad que strict no rompe nada:** prueba el certificado del destino real de **cada registro con proxy naranja** de la zona (no solo de este servidor), y con `all=true` cambia solo las zonas que pasan; las demás se listan con el motivo.
- **No se encontraba la zona de los dominios de dos niveles** (`aca.org.es`, `limpa.co.uk`): se tomaban las dos últimas partes (`org.es`, `co.uk`). Ahora la zona es el sufijo más largo que exista en las cuentas. Afectaba a las herramientas DNS del MCP, a las rutas nuevas y a las migraciones.
- **En una copia no se podían cambiar los avisos.** Ahora se puede desde cualquier nodo (página y MCP `alerts_configure`): se envía al master (acción `set-alert-policy-master`), que lo guarda y lo reparte a todos.

### Añadido
- **`dns_record_set` avisa al encender el proxy naranja** si la zona está en SSL "Flexible" (la web entraría en bucle) o en "Automático" (Cloudflare puede cambiarlo solo).
- **`cf_zone_ssl` con `all=true` consulta en paralelo:** de unos 4 minutos con 89 zonas, que bloqueaban el panel de un nodo de un solo proceso, a unos segundos.
- **Silenciar un aviso solo en un servidor** (p. ej. "Disco lleno" solo de `nitro`): tabla nueva en Ajustes → Avisos y `mute: ["nitro:DISK_HIGH"]` por MCP.

## [1.0.306] — 2026-10-04 — Modo SSL de Cloudflare desde el MCP

### Añadido
- **MCP `cf_zone_ssl`** (lectura): modo SSL/TLS de la zona de un dominio, o de todas con `all`. Indica si está en "Automático", "Always Use HTTPS", el TLS mínimo y las Configuration/Origin Rules que cambian el SSL o el puerto hacia el origen, con un veredicto ("flexible" = bucle con redirección en el origen).
- **MCP `cf_zone_ssl_set`:** pone una zona en "Full (strict)" (o "Full") y la saca de "Automático" para que Cloudflare no la cambie sola. Antes de strict comprueba que este servidor tiene un certificado válido para ese dominio (si no, error 526); se puede saltar con `force`. No toca el proxy naranja. Requiere "Permitir editar DNS en Cloudflare" y, en el token, "Zone Settings: Edit".
- **`cloudflare_tokens`** dice si cada token puede leer el modo SSL de las zonas.

## [1.0.305] — 2026-10-04 — Correos de aviso que se explican solos

### Añadido
- **Cada correo de aviso explica qué significa y qué hacer**, y cómo silenciarlo (con enlace a Ajustes → Avisos). Los del monitor llevan un asunto legible (p. ej. "Disco lleno (DISK_HIGH)").
- **Se pueden silenciar** también "Reinicio del servidor" y "Monitor sin medidas".

## [1.0.304] — 2026-10-04 — Tráfico web en el monitor de cualquier nodo; estadísticas por MCP; avisos de correo silenciables

### Arreglado
- **Monitor sin "Web Bandwidth" ni "Web Requests" en Filemon:** el registro de accesos de los hostings (`/var/log/caddy/hosting-access.log`) solo se configuraba al crear la ruta de un hosting. En un nodo que recibió las webs por sincronización o por un relevo, faltaba. Por eso tampoco había ancho de banda por hosting, ni el fail2ban de WordPress veía los ataques. Ahora el cluster-worker lo asegura cada 30 min en todos los nodos, para todos los hostings, subdominios y alias, y solo escribe en Caddy lo que falta.
- **"La copia de configuración del master tiene avisos" se repetía cada hora** mientras no cambiara nada (dos servicios propios de Filemon habrían mandado 24 correos al día). Ahora avisa solo cuando cambia la lista. Además se pueden **excluir de la copia** elementos propios de la máquina del master (p. ej. `systemd/wan-failover.service`): ni se copian ni avisan. Se hace con MCP `config_mirror` (`exclude`/`include`) o con `php bin/cluster-switch.php mirror-exclude|mirror-include <elemento>`, y el aviso es silenciable en Avisos.

### Añadido
- **MCP `monitor_stats`** (lectura, con `node`): estadísticas de un servidor en 1h, 6h, 24h, 7d o 30d. Para cada métrica (CPU, RAM, discos, red, GPU, tráfico web) da media, pico, p95 y último valor; además, el tráfico web por hosting en el periodo y en el mes.
- **Avisos:** también se pueden silenciar "Cola de correo pausada" y "Réplica con problemas" (este último, mejor no).
- **Ajustes → Avisos en una copia** explica que las reglas se cambian en el master y enlaza a su página. La copia pregunta al master su nombre de panel (`panel_hostname`, nuevo en `query-local-state`).

## [1.0.303] — 2026-10-04 — Análisis de WordPress: lo que se le escapaba en filmsinfest

### Arreglado
- **El análisis no veía 6 puertas traseras de filmsinfest** (las encontró Filemon a mano). Ahora busca:
  - código que ejecuta lo que llega en una **cookie**, también en temas inactivos;
  - **PHP disfrazado de idioma** en `wp-content/languages` (solo deben ser `*.l10n.php`);
  - **carpetas de `wp-content` que no son de WordPress** con PHP dentro (como `assets/`).

  Además lista los **temas sin usar**, porque sus PHP también se pueden abrir por URL.
- **Temas modificados:** enseña **qué cambia** (diff). A menudo es una personalización legítima (pie de página, un contador de visitas…) que se perdería al reinstalar, como en blogdot y haxel.

### Seguridad
- **Caddy:** en standard no se ejecuta PHP de `wp-content/languages`. En strict, los PHP de los temas no se abren directamente por URL (los carga WordPress). Las reglas se ponen al día solas en 30 min.

## [1.0.302] — 2026-10-04 — Avisos: menos ruido y control de lo que llega

### Arreglado
- **"Mail Node Degraded" mandaba un correo por cada corte de un minuto** (12 en un día desde mortadelo). Ahora:
  - solo lo vigila el master;
  - reintenta a los 3 s;
  - avisa si el fallo dura 5 minutos seguidos (ajustable) y otra vez al recuperarse.
- **El mismo aviso echaba la culpa a PostgreSQL** ("PostgreSQL local no responde") cuando en realidad no había podido preguntar a la API del nodo. Ahora dice que la API no respondió.
- **Tras cada actualización del panel se repetían avisos ya dados** (hardening, firewall, ficheros críticos…): `update.sh` vacía `storage/cache`, donde se guardaba su estado. Ahora el estado va en `storage/state`, y si falta, no se repite un aviso de hardening ya dado en los últimos 7 días.
- **Hardening:** solo avisa cuando falla un control nuevo; arreglar o aceptar un control ya no manda otro correo.
- **El análisis de WordPress no veía la reinfección de almatwins:** no revisaba los drop-ins ni los temas, ni detectaba código ofuscado. Ahora:
  - compara **cada tema** con su original de wordpress.org (misma versión) y marca el activo; los comerciales, a revisar a mano;
  - lista los **drop-ins y PHP sueltos** de `wp-content` (`db.php`, `advanced-cache.php`, `object-cache.php`…) con su origen, y reconoce los legítimos (WP Toolkit de Plesk, Autoptimize);
  - busca **marcas `SC_*_BEGIN`** y **líneas de más de 3000 caracteres**, sin contar los ficheros idénticos al original (Yoast, Jetpack…);
  - la ficha del hosting avisa también de PHP suelto en `wp-content` y de marcas de ofuscación.

### Añadido
- **Ajustes → Avisos** (y MCP `alerts_status` / `alerts_configure`). Se guarda en el master y se copia a los nodos (acción de cluster `set-alert-policy`, sin secretos). Permite:
  - silenciar tipos de aviso (solo quita el correo; siguen en el monitor);
  - dar por buenos controles de hardening;
  - poner umbral propio o silencio por disco y servidor (p. ej. `nitro` `/workspace`);
  - fijar los minutos de espera del aviso de correo.

  Los avisos del relevo no se pueden silenciar.
- **Docs → Avisos:** quién envía cada correo y cómo silenciarlo.

## [1.0.301] — 2026-10-04 — Blindar WordPress; copiar a Caddy las webs PHP del master

### Añadido
- **Blindar WordPress, por hosting** (*Hostings → el hosting → Blindar WordPress*, MCP y `bin/wp-harden.php`):
  - **standard**, por defecto en todos los WordPress, no limita al cliente: `xmlrpc.php` cerrado (abierto automáticamente si tiene Jetpack, o a mano), sin ejecutar PHP en `uploads`/`cache`/`upgrade`, y en 403 `wp-config.php`, `readme.html`, `*.sql`, las copias `.bak` y `?author=`.
  - **strict**, para webs propias: además el código queda de solo lectura para PHP (solo escribe en `uploads`, `cache` y similares), y un mu-plugin del panel impide instalar o editar plugins y temas desde el admin. Aunque roben la contraseña, no pueden escribir PHP. Para actualizar, se desbloquea 30 min, 1 h o 2 h y el panel lo vuelve a cerrar solo.
  - El nivel se cambia en el master y se copia a los nodos web. Cada 30 min, cada nodo pone al día sus reglas de Caddy.
- **Análisis de infección sin ejecutar el PHP del sitio** (`scan`, MCP `wordpress_scan`):
  - el núcleo y cada plugin, contra las sumas oficiales de wordpress.org (ficheros cambiados o de más, plugins inexistentes allí o de malware conocido);
  - trozos de puertas traseras en `wp-content`, PHP en uploads, zips subidos, mu-plugins y carpetas raras;
  - en la base de datos: administradores, opciones con scripts inyectados y entradas recientes.
  - Además, un aviso en la ficha del hosting si hay indicios.
- **Limpieza sin borrar nada** (MCP `wordpress_repair` y terminal): todo va a `/var/lib/musedock/wp-quarantine/<dominio>/<fecha>/`, con su lista. Permite:
  - mover a cuarentena;
  - reinstalar desde wordpress.org el núcleo, un plugin o un tema en su misma versión;
  - regenerar las claves de `wp-config.php` (cierra todas las sesiones).
- **MCP:** `wordpress_status` y `wordpress_scan` (lectura, con `node`); `wordpress_harden` y `wordpress_repair` (modifican; primero el plan).
- **Docs → Blindar WordPress.**

### Seguridad
- **Los baneos de fail2ban de WordPress no servían con el proxy de Cloudflare:** se baneaba en iptables la IP real del visitante, pero las conexiones llegan desde IPs de Cloudflare. Ahora la jaula `musedock-wordpress` banea también en Caddy, por la cabecera `Cf-Connecting-Ip` (acción `musedock-caddy-ban`; `update.sh` instala las acciones de fail2ban).

### Arreglado
- **Ficha de un hosting suspendido:** aviso de PHP por `$hasMailForDelete` sin definir.
- **`apply-master-caddyfile --apply` no podía meter webs con PHP** (p. ej. `*.musedock.com`): Caddy lo rechazaba con `cannot unmarshal array into Go value of type fileserver.MatchFile`. Al pasar la salida de `caddy adapt` por PHP, los objetos vacíos (`"file": {}` de `php_fastcgi`/`file_server`) se convertían en listas (`[]`). Ahora las rutas y las políticas de certificado se mandan a Caddy conservando los objetos.

## [1.0.300] — 2026-10-04 — MCP entre nodos: aviso, interruptor y registro

### Añadido
- **Interruptor "Permitir consultas reenviadas desde otros nodos"** en *Ajustes → MCP* (activado por defecto). Si se desactiva, el nodo solo responde a su propio token MCP: el MCP de otro panel del cluster ya no puede consultarlo con `node`.
- **Aviso en *Ajustes → MCP*** que explica el acceso reenviado: desde el MCP de un panel se leen los demás nodos de su cluster **sin el token de cada uno**, en los dos sentidos (master → copias y copia → master si lo tiene registrado). Lista los nodos a los que se llega.
- **Guía en Docs: "MCP entre nodos (acceso reenviado)"** (`/docs/mcp-nodes`): sentidos, límites y cómo cerrarlo.
- **Las consultas reenviadas quedan en el registro de actividad del nodo consultado** (`mcp.call`, con la herramienta y el nodo que preguntó; sin argumentos).

### Seguridad
- **`page_check` se niega a abrir rutas con pinta de acción** (logout, delete, restart, apply, toggle, promote…). Solo carga páginas de consulta.

## [1.0.299] — 2026-10-04 — Migraciones que no se ejecutaban; diagnóstico por MCP

### Arreglado
- **Las migraciones escritas como clase (`return new class { up() }`) no se ejecutaban nunca**, y aun así se apuntaban como hechas. Son 8; en los nodos cuyo esquema no venía de la plantilla faltaban tablas (en Nitro, `hosting_subdomains`: la página Dominios daba 500). Ahora:
  - el ejecutor llama a `up()`;
  - una migración de reparación **crea solo lo que falte** (`hosting_subdomains`, `hosting_bandwidth`, `hosting_subdomain_bandwidth`, `replication_users`, `replication_authorized_ips` y columnas de subdominios), con las definiciones de `schema.sql`.
  
  No se reejecutan aquellas 8: algunas renombran o quitan columnas o cambian ajustes de réplica, y se toca solo lo que no existe.
- **`page_check`, `panel_errors` y `witnesses_status` aceptan el argumento `node`** para ejecutarse en otro nodo del cluster.

### Añadido
- **MCP `page_check`** (solo lectura): carga una página del panel como administrador y devuelve el código HTTP y el error real (excepción, fatal o aviso, con fichero y línea). Con `node`, en otro nodo.
- **MCP `panel_errors`** (solo lectura): últimas líneas con error de los registros del panel (panel-error, panel, cluster-worker, failover-worker), con tokens y claves tapados.

## [1.0.298] — 2026-10-04 — Herramienta para ver el error real de una página

### Añadido
- **`php bin/page-check.php /ruta`**: carga una página del panel desde la terminal, como el primer administrador, y enseña el error real (excepción, error fatal o aviso de PHP, con fichero y línea) cuando la página da 500 y el registro de errores no dice nada. Solo hace un GET, como el navegador.

## [1.0.297] — 2026-10-04 — Instalaciones nuevas completas; avisos de entrada más claros

### Arreglado
- **El instalador no ejecutaba las migraciones**: hacía `php fichero.php`, pero las migraciones son funciones que hay que llamar, así que no pasaba nada. Ahora usa `bin/migrate.php`, que las ejecuta y las apunta.
- **La plantilla de instalación nueva (`schema.sql`) creaba `hosting_domain_aliases` sin `customer_id` ni `target_url`** (redirecciones sueltas), y la página Dominios podía dar 500. Plantilla corregida, más una migración que añade lo que falte en los nodos ya instalados. Es idempotente y no borra nada.
- **Entrada alternativa: "este Caddy no tiene el módulo de Cloudflare" salía como error** (`ok: false`). Ahora es un aviso: el certificado del nombre de comprobación se renueva igual por TLS-ALPN (el 443 llega al servidor tal cual por el proxy).

## [1.0.296] — 2026-10-04 — Testigos desde el panel; los modos del relevo, explicados; la copia nunca se queda con papeles viejos

### Arreglado
- **Un nodo que vuelve tras un relevo podía quedarse con la configuración vieja** ("soy el principal"), porque el envío de cuando se promovió el otro agotaba sus reintentos mientras estaba caído. Entonces no vigilaba al nuevo master ni le sustituía si caía. Ahora:
  - al ponerse como copia, **pide al nuevo master su configuración de relevo** (acción de cluster `push-failover-config`);
  - el master **la reenvía a sus copias cada 30 minutos**.
- **En *Cluster → Failover* y en la guía, una nota que resume la diferencia:** semi-auto y auto solo se diferencian en la vuelta planificada; tomar el mando es automático en los dos y en los dos sentidos.
- **La explicación de los modos en *Cluster → Failover* era incorrecta** (decía que semi-auto solo avisa). Ahora explica bien manual, semi-auto y auto, con un ejemplo plegable (el servidor A sin luz de 10:00 a 12:00) y enlace a la guía.

### Añadido
- **Ajustes → Testigos.** Crear y gestionar testigos externos desde el panel, de forma agnóstica:
  - **estado en vivo** de cada testigo registrado y de sus comprobaciones;
  - **generador del script de instalación**: propone qué mirar (cada servidor del relevo y, si este panel vigila la entrada de alguno, su línea normal y la alternativa) y quién puede preguntar (las IPs del relevo). Todo se puede editar. El script instala el agente, crea **en el testigo** la clave y el certificado, lo deja como servicio y configura el cortafuegos. No lleva secretos; al volver a ejecutarlo, actualiza y conserva la clave y la huella;
  - **registrar y quitar** testigos con la contraseña de administrador. Antes de guardar se comprueba que el testigo contesta con esa huella y esa clave.
- **MCP `witnesses_status`** (solo lectura): los testigos registrados en el panel y si responden ahora, con cada comprobación (dirección, si llega, latencia y pérdidas) y las alertas de testigos caídos, más el estado del vigilante de entrada (servidores vigilados, modo y registros movidos). Nunca muestra claves.
- **Docs → "Testigos externos"**: para qué sirven, qué son y qué no (sin datos, sin acceso, sin órdenes), cómo crearlos paso a paso, cómo personalizar las comprobaciones y qué pasa si fallan.
- **Aviso si un testigo externo no responde** durante 10 minutos, y otro cuando vuelve (cluster-worker, cada 5 min). Uno caído no afecta, porque decide el otro, pero conviene saberlo.
- **Docs → Failover: "Ejemplo: qué pasa en cada modo"**, con una tabla hora a hora (caída, toma del mando, vuelta como copia, recuperar el mando) y qué pasa si después cae el relevo.

## [1.0.295] — 2026-10-04 — Testigos que siguen una IP dinámica

### Añadido
- **Agente testigo v3: `resolve_host`**. Para entradas con IP dinámica (p. ej. una segunda línea con DynDNS), la comprobación resuelve ese nombre en cada pasada, se conecta a la IP que tenga en ese momento e informa de esa IP en `addr` (y del nombre en `via`). Sin fijar IPs que caducan.
- El vigilante de entrada reconoce la alternativa tanto así (IP igual a la que resuelve él) como con las comprobaciones sin IP fija.

## [1.0.294] — 2026-10-04 — Testigos en el panel y vigilante de entrada (ONO ↔ Orange)

### Añadido
- **Testigos externos en el panel** (`bin/witness.php add|list|test|remove`). Cada panel registra sus testigos con URL, **huella SHA-256 del certificado** (se fija: sin ella no conecta) y clave. La clave se escribe en la terminal sin verse, o por la entrada estándar; nunca como argumento. Antes de guardar se comprueba que el testigo contesta.
- **El relevo automático pregunta a los testigos externos** antes de tomar el mando. Si alguno llega al principal, no se promueve. Y si el principal **responde por su entrada alternativa** (otra línea), tampoco: lo que toca es cambiar la entrada, no el servidor.
- **Vigilante de entrada** (`bin/ingress-watch.php`, en el panel de fuera). Si la entrada normal de un servidor (su IP, p. ej. ONO) falla o va extremadamente lenta (umbrales configurables: 1.500 ms o 30 % de pérdidas por defecto) durante unos minutos, **según él y los testigos**, y la alternativa (p. ej. Orange) responde, mueve en Cloudflare los registros A a la IP alternativa, con diario. Sigue a la IP alternativa si cambia (línea dinámica) y lo devuelve cuando la normal lleva un rato estable. No mueve nombres de máquina ni destinos de MX (el proxy solo lleva 80/443). Si no hay nada que mover (el servidor es copia), no hace nada. Avisa al cambiar y al volver. También: `status`, `check` y `force-primary`.

### Cambiado
- **Agente testigo v2**: el saludo TLS se hace en el hilo de cada conexión y con tiempo límite (un cliente que abría la conexión sin hablar dejaba el agente sin responder a nadie); "IP privada" se comprueba con `ipaddress` (antes cualquier 172.x contaba como privada); el estado incluye qué dirección mira cada comprobación (`addr`, `name`) para que el panel sepa a qué servidor corresponde.

## [1.0.293] — 2026-10-04 — Agente testigo por HTTPS

### Cambiado
- **El agente testigo escucha por HTTPS** si su configuración trae `tls_cert` y `tls_key` (certificado propio; el panel fijará su huella SHA-256). El testigo debe consultarse por su **IP pública** desde fuera de la oficina: si se consulta por una VPN que pasa por la oficina, deja de responder justo cuando la oficina cae. Con IP pública sin TLS no arranca, para que la clave nunca vaya en claro.

## [1.0.292] — 2026-10-04 — Las webs del Caddyfile del master llegan de verdad al promover; agente testigo

### Arreglado
- **Al promover, las webs del Caddyfile del master no se ponían nunca** (en Filemon faltaban `*.musedock.com` y `license.musedock.com`). Eran tres fallos:
  1. la validación se hacía con `runuser -u caddy`, que **borra el entorno**: sin `CLOUDFLARE_API_TOKEN` fallaba siempre. Ahora el entorno se carga dentro de la orden, desde un fichero temporal que solo lee `caddy`, y el token no sale en `ps`;
  2. con Caddy arrancado con **`--resume`**, reiniciarlo ignora el Caddyfile. Ahora esas webs se **inyectan por la API** (`caddy adapt` → rutas `cfmirror-*` y sus políticas de certificado). Solo las del 443, y **solo las que este Caddy no sirve ya** (lo que sirve el panel u otra aplicación no se toca). Los comodines van al final;
  3. si fallaba, se daba por hecho y no se reintentaba. Ahora sigue pendiente, se avisa en el progreso de la promoción y por correo, y se puede poner a mano con `cluster-switch.php apply-master-caddyfile` (sin `--apply` solo enseña qué haría y si valida).

### Añadido
- **`bin/witness-agent.py`, agente testigo "solo ojos"** para un servidor ajeno al cluster. Es Python 3 sin dependencias, no tiene datos ni acceso a nada y no guarda registros. Mira cada pocos segundos una lista **fija** de comprobaciones de su configuración (https con SNI y certificado verificado, por una IP concreta si se quiere, o tcp) y responde con clave en `GET /v1/status`: si llega, latencia y pérdidas. Servirá para confirmar caídas reales antes de un relevo y para elegir la entrada (ONO u Orange).

## [1.0.291] — 2026-10-04 — Rutas que faltaban tras un cambio de rol

### Arreglado
- **Rutas que faltaban en Caddy tras un cambio de rol**: redirecciones sueltas que estaban en la base de datos pero no en Caddy (en Filemon faltaban `orientalartsresearchcenter.com` y `webmail.picalias.com`), y la ruta de CardDAV (`dav.musedock.com`). Ahora el cluster-worker las repone cada 5 minutos en cualquier nodo. Solo crea lo que falta, nunca borra.
- **Entrada alternativa: el mapa de dominios daba 403 "IP no permitida"** si el proxy no estaba en `ALLOWED_IPS`. Ahora `/api/ingress/domains` no pasa por esa lista, porque ya tiene su propia clave. El resto del panel sigue igual de protegido.
- **Entrada alternativa: el certificado del nombre de comprobación iba por HTTP/TLS-ALPN**, y solo sale si el proxy ya está abierto. En obelix falló 5 veces y Let's Encrypt bloqueó el nombre una hora. Ahora tiene su propia política con reto **DNS** (Cloudflare, el token del entorno de Caddy). Si a ese Caddy le falta el módulo o el token, `ingress.php status` lo dice.
- **Entrada alternativa:** la comprobación responde `ok-<nombre>` sacado del nombre de comprobación (`health-obelix…` → `ok-obelix`), no del hostname del sistema (en obelix es "155"). Se actualiza sola en el siguiente minuto.

## [1.0.290] — 2026-10-03 — Entrada alternativa por un proxy de SNI (segunda línea)

### Añadido
- **Entrada alternativa por un proxy de SNI con PROXY protocol v2** (por ejemplo, una segunda línea con IP dinámica). El proxy reenvía cada conexión, tal cual, a un puerto aparte de este servidor. Con `php bin/ingress.php enable --source=<IP del proxy> --health=<nombre> [--port=8443]`:
  - el servidor de Caddy de las webs escucha también en ese puerto, con las mismas rutas y certificados;
  - la cabecera PROXY (IP real del cliente) se lee **solo** de la IP del proxy; cualquier otra conexión, la del 443 incluida, se trata como siempre (`fallback_policy: skip`);
  - un nombre de comprobación responde `ok-<servidor>`;
  - el cortafuegos abre el puerto solo a la IP del proxy;
  - `GET /api/ingress/domains` (clave propia, `Authorization: Bearer` o `X-Api-Key`) da la lista de dominios que sirve este Caddy, para que el proxy sepa a quién mandar cada uno. La clave solo se ve en la terminal (`show-key`, `rotate-key`).

  El cluster-worker lo vuelve a poner si una recarga de Caddy lo quita. También: `status` y `disable`.

## [1.0.285] — 2026-10-03 — El relevo DNS deja de decir "caído" sin estarlo

### Arreglado
- **"Primarios caídos — Failover activo" aunque nadie estuviera caído.** Tras un relevo hecho a mano (`dns-failover`) o un cambio de rol, el relevo DNS se quedaba en estado de caída y con el antiguo master como principal. Ahora:
  - el cambio de rol deja los dos nodos en "Normal", con los papeles intercambiados y sin restos del relevo (diarios, marca de activación, resync pendiente);
  - `cluster-switch.php failover-normalize` lo arregla a mano en el master.
- **El relevo automático por caída no podía promover PostgreSQL.** Con el master caído, la réplica ya no recibe y el freno miraba "segundos desde la última transacción": siempre más de 5 s, así que se bloqueaba siempre. Ahora el cluster-worker apunta cada minuto que cada réplica está recibiendo (`/var/lib/musedock/pg-streaming-*`). Si el master no está, se promueve cuando la réplica ha aplicado todo lo recibido y recibía hace menos de 15 minutos. Una réplica desconectada desde hace horas sigue bloqueada.
- **El relevo automático por caída (semiauto/auto) no funcionaba y tenía riesgos.** Revisado entero:
  - **Nunca se promovía:** comparaba la IP del master del cluster (VPN) con las de las comprobaciones (públicas). Ahora identifica el principal caído y a sí mismo por la configuración del relevo.
  - **Movía el DNS antes de promoverse:** si el testigo paraba la promoción, las webs iban a una copia en solo lectura. Ahora primero se promueve y solo si lo consigue mueve el DNS. Después deja el relevo ordenado (él principal, el caído de relevo).
  - **El propio master podía mover el DNS** si no se alcanzaba a sí mismo por su IP pública (NAT en casa). Ahora solo actúa la réplica.
  - **Un testigo caído bloqueaba el relevo para siempre** (p. ej. en la misma línea que el master). Ahora solo cuentan los testigos que responden; si alguno ve al master vivo, no se promueve.
  - **La vuelta antigua se ha quitado**: un "resync" que siempre fallaba (y avisaba cada 15 min) y llevaba un `rsync --delete` hacia el antiguo master. Ahora el caído, al volver, se aparta solo y se reincorpora como copia, el que manda ordena los papeles, y que vuelva a mandar es un cambio de rol con el botón.
- **Vuelta automática al titular (modo auto).** El *titular* es el servidor al que se dio el mando con un cambio de rol planificado (o con `failover-normalize`); un relevo por caída no lo cambia. Si el titular cae y otro le sustituye, cuando el titular lleva `failover_return_stable_minutes` (15 por defecto) respondiendo bien y ya es copia al día, el sustituto le devuelve el mando con el mismo cambio de rol planificado del botón, con todas sus comprobaciones. En semiauto, en vez de hacerlo, avisa de que está listo.
- **Aviso en la terminal cuando el servidor es copia** (o está apartado): al abrir una terminal, en VS Code o por SSH, sale en amarillo "⚠ ESTE SERVIDOR ES COPIA de <master> (manda allí). Lo que edites aquí se sobrescribirá…". Solo avisa, no bloquea. Desaparece cuando el servidor manda. Lo mantiene el panel al promover, al pasar a copia y cada minuto desde el cluster-worker (`/etc/musedock/role-banner.*`, con un enganche en `/etc/profile.d` y otro en `/etc/bash.bashrc`, porque la terminal de VS Code no es de login). No sale en sesiones no interactivas: no molesta a rsync, lsyncd ni scp.
- **Ficha del hosting: la cabecera salía rota** (trozos de JavaScript a la vista) en los hostings con correo: el texto de "Suspender también el correo" iba con comillas dobles dentro del `onclick` del botón Suspend y cerraba el atributo. Ahora va en una función con el texto escapado.
- **MariaDB (ya en el código de la 1.0.284, sin anotar): la comprobación para seguir por GTID usa `gtid_current_pos` del nuevo master.** Cuenta lo escrito y lo recibido como réplica. Justo después de un sembrado, `gtid_binlog_pos` podía quedarse corto y se creía que faltaba algo, lo que acababa en copia completa.

## [1.0.284] — 2026-10-03 — Cambio de rol robusto: nunca a medias ni a ciegas

Lo aprendido en la prueba del 3-oct, en la que el cambio se paró a mitad y las webs se quedaron sin servidor.

### Arreglado
- **La promoción de PostgreSQL se bloqueaba con la réplica al día.** Medía "segundos desde la última transacción", y con el master apartado (solo lectura) suben sin parar. Ahora, si la réplica está conectada al master y le queda poco por aplicar (≤ 16 MB), se promueve. La medida en segundos solo cuenta si la réplica no está conectada (caída real del master).
- **Antes de promover al otro, se espera a que tenga todo.** Tras apartarse, el master compara la posición del WAL de cada PostgreSQL con lo aplicado por la réplica, hasta 2 minutos. Si no llega, se vuelve atrás sin tocar nada.
- **Si el otro se promueve a medias y el DNS no se ha movido, se vuelve atrás solo.** Se aparta al otro (sus bases en solo lectura) y este servidor vuelve a servir las webs. Antes se quedaba apartado, con las webs caídas. Si el DNS ya se movió, las webs están en el otro y se dice cómo terminar.
- **El aviso de un cambio parado no llegaba.** El nodo apartado tiene el correo parado. Ahora el aviso sale también a través del otro nodo (acción de cluster `notify-relay`), y el que se promueve avisa si su promoción falla.
- **El antiguo master se sembraba MariaDB entera por un aviso.** Al promoverse, el nuevo master pide a los nodos que repliquen de él (`reconfigure-replication`). El antiguo master lo atendía haciendo una copia completa de MariaDB dentro de la petición, y el panel quedaba bloqueado. Ahora:
  - ese aviso no se manda al antiguo master;
  - un nodo que es o era master, o que está apartado, no lo atiende;
  - PostgreSQL nunca se reconfigura por aviso;
  - MariaDB solo sigue por GTID (solo lo nuevo) y nunca hace una copia completa automática.
- **Monitorización: la agregación horaria fallaba cada 30 s en instalaciones nuevas** ("no existe la columna avg_val"): `database/schema.sql` creaba las tablas de resumen horario y diario con otras columnas que las que usa el recolector, y las gráficas de 7 días o más quedaban vacías. Migración que añade las columnas que faltan (sin borrar nada) y `schema.sql` corregido.
- **El paso a copia (demote) no vuelve a copiar MariaDB entera si ya replica del nuevo master.**
- **El antiguo master volvía "OK" sin replicar PostgreSQL.** El nuevo master borraba en unas horas el WAL desde el relevo, y el rebobinado del antiguo no podía alcanzar un estado consistente ("requested WAL segment … has already been removed"). Pero el demote lo daba por bueno solo con que arrancara. Ahora:
  - **al promover**, el nuevo master crea en cada PostgreSQL el slot del antiguo master (`<host>_<versión><clúster>`) con el WAL reservado desde ese momento, así la vuelta siempre puede ser un rebobinado (solo lo cambiado);
  - **el rebobinado comprueba hasta 90 s que de verdad replica** (`pg_stat_wal_receiver` en *streaming*); si no, lo dice y el demote pasa a la copia completa.
- **El rebobinado ya no hace una copia local de toda la carpeta de datos antes** (`*.pre-rewind.*`): tardaba minutos por clúster y llenaba el disco, y no aporta nada (los datos buenos están en el nuevo master; si el rebobinado falla, se copia de allí).
- **La copia completa de PostgreSQL ya no satura la línea**: `pg_basebackup` va con límite de velocidad (`repl_pg_basebackup_max_rate`, 20 MB/s por defecto; vacío = sin límite) y enseña su avance (MB y %) cada 15 s en la terminal y en el panel.

### Añadido también
- `cluster-switch.php pg-rebuild <ip-master> <clúster|all> [--max-rate=10M]`: copia completa de un clúster PostgreSQL desde el master, con avance. Aparta los datos actuales, no los borra.
- **El panel de rescate usa los mismos certificados que el Caddy principal.** Con uno nuevo, el navegador que había aceptado el anterior rechazaba en silencio las consultas del progreso.

### Añadido
- **Progreso del cambio de rol en el Dashboard de los dos nodos**: barra de progreso, último paso y todos los pasos. Sigue ahí aunque se recargue la página o se entre por el panel del otro nodo (el que recibe el mando enseña la versión completa del que lo pasa).

## [1.0.283] — 2026-10-03 — Sin "www." en los subdominios

### Arreglado
- **A todo dominio se le añadía "www." en Caddy, también a los subdominios** (`www.develop.ejemplo.org`, `www.webmail.cliente.com`): nombres sin DNS a los que Caddy intentaba sacar certificado y que salían en el plan DNS como "sin DNS". Ahora `www.` solo se añade a la raíz de su zona (`ejemplo.com`, `ejemplo.org.es`), y a un subdominio solo si su `www.` existe de verdad en el DNS. Vale para hostings, alias, redirecciones, la página de mantenimiento y el registro de accesos (`SystemService::hostsWithWww`).
- **Para las rutas que ya existían**: `bin/caddy-drop-www-subdomains.php` enseña qué `www.` sobran en las rutas del panel y con `--apply` los quita, cambiando solo la lista de nombres de cada ruta (no reconstruye nada). Con `--all-routes` aplica la misma regla a las rutas de otras aplicaciones (p. ej. un CMS).

## [1.0.282] — 2026-10-03 — El cambio de rol enseña qué dominios mueve, y lo cuenta por correo

### Añadido
- **Antes de pedir la contraseña, el cambio de rol enseña el plan DNS**: qué registros A cambian, qué dominios van con ellos por CNAME, qué nombres de máquina se quedan y qué dominios **no se pueden mover** porque su DNS no está en las cuentas de Cloudflare del panel (hostings y todo lo que sirve Caddy). En listas plegables; las comprobaciones también se pliegan si todo va bien.
- **Si el nombre del panel se va al nuevo master** (cuando es destino de los CNAME de las webs), lo avisa antes de empezar y al terminar recarga el panel por la IP de este servidor.
- **Correo del cambio de rol con el informe de DNS**: "<nodo> es el master (<IP>)", registros cambiados, los que no se pudieron cambiar, lo que va por CNAME, nombres de máquina y dominios que no se pudieron mover para cambiarlos a mano.
- **Docs → Failover: "Qué se mueve en el DNS, y por qué"**: las reglas (por IP, CNAME, nombres de máquina y su excepción), qué pasa con el nombre del panel, qué no se puede mover y que hoy el relevo de DNS es solo para Cloudflare.
- **MCP: redirecciones de dominio.** `domain_redirects` (lectura) lista las redirecciones sueltas y las de los hostings; `domain_redirect_create` crea una como en *Dominios → Redirect* (p. ej. `webmail.cliente.com` → `https://webmail.servidor.com`), con plan previo: comprueba que el dominio no sea ya un hosting, alias, redirección ni lo sirva Caddy, y dice si su DNS llega a este servidor (si no, el certificado no saldría). Se copia a los nodos web. No hay herramienta de borrado. El panel y el MCP usan el mismo código (`DomainAliasService::createStandaloneRedirect`).
- **Lista de nodos del Dashboard más compacta**: cada nodo es una fila (tipo, si puede tomar el mando y cuántas cosas guarda, p. ej. 6/6) que se despliega para ver el detalle en tarjetas.
- **Al terminar el cambio de rol**, la ventana ofrece *Recargar este panel* y *Abrir el panel del otro nodo* (por su IP pública). Si se pierde la conexión durante el cambio, enlaces para abrir este panel por IP y el del otro nodo.
- **Docs → Failover: "Cómo configurarlo, paso a paso"**: requisitos, cuentas de Cloudflare y token de Caddy, qué poner en cada campo de servidores, modo y tiempos, comprobar sin tocar nada (plan DNS, nombres de máquina que no se mueven), qué hace un relevo y cómo probarlo.

### Arreglado
- **Al apartarse (cambio de rol o master caducado), el nodo seguía aceptando correo.** Paraba las webs y la copia de ficheros, pero Postfix y Dovecot seguían recibiendo correo y atendiendo buzones: los remitentes con el DNS viejo en caché y los móviles conectados por IMAP/POP3 escribían en los dos nodos a la vez, y al juntarse los buzones la réplica renumeraba mensajes ("este correo ya no está en el buzón"). Ahora el nodo apartado para también el correo: los remitentes reintentan y entregan en el nuevo master, sin perder nada. Si se reinicia apartado, el correo no arranca. Vuelve a arrancar solo al reactivarse o al quedar como copia del nuevo master.

### Cambiado
- **El plan DNS lee cada zona una sola vez** (todos sus registros) en vez de una consulta por tipo e IP: con las zonas de mortadelo tarda unos 30 s (por MCP antes se pasaba del tiempo de espera). Es el mismo código para el MCP, el botón y el correo.
- La pestaña Failover dice que el firewall abre los puertos web **y los del correo** si lo hay (antes decía solo 80/443).

## [1.0.281] — 2026-10-03 — Qué copia guarda cada nodo, y "copia al día" real en el cambio de rol

### Añadido
- **El Dashboard del master dice qué guarda cada nodo**: ficheros de las webs, cada base de PostgreSQL, MariaDB, Redis y correo, y con eso qué tipo de nodo es: *réplica completa* (puede tomar el mando), *réplica a medias* o *solo copia de ficheros* (las webs sin sus bases de datos). Se consulta en segundo plano para no frenar la página.
- **Docs → "Cambio de rol y slave completo"**: qué es el cambio de rol (frente al failover por caída), los tipos de nodo, cómo preparar paso a paso un slave que pueda tomar el mando, qué hace el panel en cada paso y las órdenes de `cluster-switch.php`. Enlazada desde el Dashboard y desde la guía del cluster.

### Arreglado
- **La red de la VPN estaba escrita a fuego (10.10.70.x)** al buscar la IP de la VPN del servidor (réplicas y correo), en los orígenes de confianza del firewall y como valor por defecto de la red del relé de correo. Ahora se lee de la interfaz WireGuard del servidor, sea cual sea su red.
- **Textos con nombres de nuestra instalación**: los avisos y la ayuda de Replicación ponían "16/musemind" como instancia de ejemplo; ahora listan las instancias de PostgreSQL de cada servidor. `bin/g1g2-apply.php` tenía la IP de un slave concreto por defecto; ahora `--slave` es obligatorio.
- **El cambio de rol se bloqueaba con una réplica de PostgreSQL al día** ("retraso 72 s" en musemind): medía los segundos desde la última transacción aplicada, y una base sin escrituras durante un rato parecía retrasada. Ahora compara la posición del WAL del master con la que ha aplicado la réplica: al día si le quedan menos de 16 MB por aplicar. Muestra lo pendiente y, aparte, hace cuánto fue la última escritura.

## [1.0.280] — 2026-10-03 — Cambio de rol con un botón

### Añadido
- **Botón "Pasar el mando a…" / "Tomar el mando" en el Dashboard** (tarjeta Cluster), independiente del relevo por caída. Es un cambio de rol planificado con los dos servidores bien: el master pasa el mando al nodo que elijas en un desplegable, y desde un slave se le pide al master que se lo pase a él. El panel hace todo el proceso:
  1. comprobaciones previas: réplicas de PostgreSQL, MariaDB y Redis al día, `wal_log_hints`, `log_slave_updates`, IPs públicas para el DNS y ruta entre los nodos;
  2. pide la contraseña de administrador;
  3. el master se aparta;
  4. el elegido se promueve (comprobando que cada base acepta escrituras), mueve el DNS hacia su IP pública e invierte los papeles del relevo (él pasa a principal y el otro a relevo);
  5. el antiguo master se convierte en su copia en vivo, solo con lo cambiado.

  El avance se ve paso a paso en una ventana y al terminar llega un correo con el resultado. Si el elegido no llega a promoverse, el master se reactiva y todo queda como antes. Nada tiene nombres fijos: nodos, IPs y cuentas salen del cluster y del relevo. También desde la terminal: `cluster-switch.php switch-check <id>` y `switch-to <id>`.

### Arreglado
- **Los avisos por correo salían con el remitente del master** ("Mortadelo Master" también en los de nitro), porque la configuración de avisos se copia entre nodos. Ahora el remitente es el servidor que envía ("Nitro · MuseDock Panel"), y el asunto lo lleva delante (`[nitro] …`).
- **El botón "Pasar el mando a…" no abría nada**: su código se ejecutaba antes de que la página cargara las ventanas (SweetAlert). Ahora espera a que la página esté cargada.
- **Al promover un nodo no se abrían los puertos del correo** (25, 465, 587, 993, 143): tras un relevo el correo no entraba hasta abrirlos a mano. Ahora, si el nodo tiene correo instalado, se abren junto con 80/443.
- **Al promover se "apropiaba" de puertos que ya estaban abiertos**: `ufw allow` sobre una regla existente le ponía la etiqueta del relevo y al volver a slave se borraba, cerrando 80/443 que estaban abiertos de siempre. Ahora, si una regla propia del servidor ya abre el puerto a todos, no se toca; al volver a slave solo se cierra lo que abrió el panel.
- **El Dashboard avisaba de "desincronización" por envíos de la configuración de relevo que fallaron hace tiempo**, aunque después se hubiera enviado bien. La configuración se manda entera y cada envío sustituye al anterior, así que cuando uno llega bien a un nodo, los fallidos o pendientes anteriores a ese nodo se marcan como superados (cancelados).

## [1.0.278] — 2026-10-03 — El sembrado de MariaDB no choca con una réplica anterior

### Arreglado
- **El sembrado de una réplica MariaDB fallaba si ya había una réplica configurada**: al reiniciar MariaDB, la anterior arrancaba sola y el `CHANGE MASTER` del volcado daba "you have a running slave; run STOP SLAVE first". No se importaba nada. Ahora, antes de importar, se para y se olvida la réplica anterior (`STOP SLAVE` + `RESET SLAVE ALL`, que no toca datos).

## [1.0.277] — 2026-10-03 — La promoción de PostgreSQL ya no puede quedarse a medias

### Arreglado
- **Grave: al promover un nodo, una base PostgreSQL podía quedarse como réplica en solo lectura.** Para saber si era réplica se consultaba `pg_is_in_recovery()`, y si la consulta fallaba se daba por "ya es principal" y no se promovía. Tampoco se comprobaba el resultado. En la vuelta del relevo del 2026-10-03, la base principal de mortadelo se quedó así y las webs no podían guardar (inscripciones de festgate, formularios, admin del CMS) hasta promoverla a mano. Ahora `standby.signal` es la prueba definitiva, tras promover se comprueba hasta 30 s que acepta escrituras (y se quita el solo lectura del aislamiento), y si no queda promovida, el `promote` lo da como error.
- **La copia completa de PostgreSQL (cuando no se puede rebobinar) no aplicaba los arreglos de contraseña y slot**, que solo estaban en el rebobinado. Además, `postgresql.auto.conf` acumulaba conexiones y slots de copias anteriores (3 `primary_conninfo` y 2 slots en Filemon). Ahora los dos caminos crean antes su slot en el master (`pg_basebackup -S`) y dejan la configuración limpia con `normalizeStandbyConf`: una sola conexión con el `.pgpass` permanente, un solo slot propio y sin solo lectura heredado.
- **Cuando el rebobinado falla, se ve el motivo real** en pantalla y en el registro (`pg-rewind-failed`). Antes solo decía "no fue posible". El del 2026-10-03 era: "el origen no es PRIMARY", consecuencia del fallo de promoción.
- **MariaDB "solo lo nuevo" (GTID) necesita `log_slave_updates`** en el nuevo master: sin él, su binlog no tiene las transacciones del antiguo y no puede seguirle. Ahora se comprueba antes, y si falta lo dice y hace la copia completa. Las réplicas que configura el panel lo activan.
- **Si al usuario de réplica de MariaDB le faltan permisos para copiar los datos**, el error dice exactamente qué `GRANT` ejecutar en el master.
- **El `demote` deja el nodo como un slave normal:** devuelve su Caddyfile propio (guardado al promover en `/var/lib/musedock/Caddyfile.own`), quita la marca de apartado y el panel de rescate, y arranca Caddy. Antes se quedaba con el Caddyfile del master y Caddy parado.
- **El panel de rescate solo tenía certificado para las IPs**: entrando por nombre (MCP, otros nodos) el TLS fallaba ("handshake failed"). Ahora incluye también el nombre del panel y el del servidor.

## [1.0.276] — 2026-10-03 — Panel de rescate con la CA de siempre; adopt-peer comprueba SSH de verdad

### Arreglado
- **Con el panel de rescate, los otros nodos no podían usar la API del panel** ("authority and subject key identifier mismatch"): el rescate se creaba su propia autoridad de certificados. Ahora usa la misma del Caddy principal, en la que los nodos ya confían.
- **Todos los avisos (correo y Telegram) llevan en el asunto qué servidor los envía**: `[nombre-del-servidor] …`, con el nombre del panel o el hostname, sin nada fijo en el código. Con varios paneles avisando al mismo buzón no se sabía quién avisaba.
- **Se acabaron las ráfagas de avisos de CPU/RAM/GPU/red.** Solo avisa si la carga dura (por defecto 5 minutos seguidos, `monitor_alert_sustain_minutes`) y da el episodio por cerrado tras 1 hora sin repetirse (`monitor_alert_episode_gap_minutes`). Antes, una tarea programada que subía la CPU cada pocos minutos mandaba un correo por ráfaga: 22 en una mañana durante el relevo. Disco y temperatura avisan igual que antes.
- **El Dashboard de un slave o de un nodo apartado ya no muestra "N nodos sincronizados"**: era la lista de cuando fue master.
- **`adopt-peer` daba por buena la instalación de la clave SSH aunque la API hubiera fallado.** Ahora comprueba que SSH entra de verdad. Si no entra, no activa la copia de ficheros y lo dice.

## [1.0.275] — 2026-10-03 — El panel sigue accesible con el servidor apartado

### Añadido
- **Cambio de roles de MariaDB sin copia completa cuando se puede.** Al convertir un antiguo master en réplica (`demote`), si no tiene escrituras que el nuevo master no tenga (lo comprueba por GTID), le sigue con `MASTER_USE_GTID=current_pos`: solo llega lo nuevo, en segundos. Si no cuadra, o si la réplica no arranca, hace la copia completa como antes.
- **El antiguo master que vuelve se convierte solo en espejo, si es seguro.** Cuando detecta que otro nodo se promovió mientras no estaba, se aparta y, si ni PostgreSQL ni MariaDB tienen escrituras propias posteriores al relevo, pasa a ser réplica del nuevo master automáticamente. PostgreSQL lo comprueba comparando su WAL con el punto de bifurcación del timeline del nuevo master. Si no es seguro, se queda apartado y avisa con la orden para hacerlo a mano. Volver a ser principal nunca es automático. Se puede desactivar con `cluster_auto_rejoin = 0`.
- **`cluster-switch.php` muestra el avance en directo** (rebobinado de cada PostgreSQL, modo de MariaDB, Redis…). Antes la terminal se quedaba muda varios minutos.

- **Los ficheros también en espejo sea quien sea el principal.** Al promoverse, un nodo registra al antiguo master como su nodo si no lo tenía, le instala su clave SSH y arranca lsyncd hacia él con la misma configuración de copia que tenía el master: carpetas extra, exclusiones, certificados… Esa configuración la manda el master a los slaves (`filesync_snapshot`), o se pide al otro nodo con la acción `filesync-snapshot`. Ya no hace falta `rsync` a mano en la vuelta. A mano: `cluster-switch.php adopt-peer <ip>`.

### Arreglado (1.0.275)
- **Tras `demote` (pg_rewind), la réplica de PostgreSQL no conectaba con el nuevo master** ("fe_sendauth: no password supplied"). `--write-recovery-conf` dejaba `passfile=` apuntando al `.pgpass` temporal, que se borra al acabar. Ahora la contraseña de réplica queda en `/var/lib/postgresql/.pgpass` (0600, postgres) y `primary_conninfo` apunta ahí. Para réplicas ya afectadas: `bin/pg-replica-passfile.php` (root).
- **Tras `demote`, la réplica de PostgreSQL perdía el historial y había que copiarlo todo.** El nuevo master no guardaba su WAL para ella (no había slot) y además heredaba su `primary_slot_name`, que no existía. Ahora, antes de rebobinar, se crea en el nuevo master un slot físico con WAL reservado (`<nodo>_<versión><cluster>`, por el protocolo de réplica) y `primary_slot_name` apunta a él. El cambio de roles de PostgreSQL vuelve a ser solo lo cambiado.
- **`demote` avisaba a los demás nodos con la IP de este servidor en vez de la del nuevo master** (desde la 1.0.272). Solo se usa la IP propia cuando el nuevo master es este servidor (promote).
- **`demote` no convertía Redis en réplica:** el antiguo master se quedaba con Redis de principal. Ahora pasa a ser réplica del nuevo, con su contraseña, y lo deja persistido.
- **Tras `demote`, `promote` no reactivaba Caddy** si el nodo había estado apartado: quedaba la marca en disco y el Caddy principal bloqueado. Ahora `promote` siempre quita la marca y arranca Caddy. El aviso rojo también tiene en cuenta esa marca.
- **Aviso rojo en todo el panel cuando el servidor está apartado (fenced)**, con la fecha y el motivo. Antes el Dashboard decía "Master / Normal".
- **El aislamiento sobrevive a un reinicio.** Si un servidor apartado se reinicia (corte de red, proveedor…), su Caddy principal ya no arranca solo ni vuelve a servir webs con datos viejos, y el panel de rescate sí arranca. Funciona con una marca en disco (`/var/lib/musedock/fenced`), `ExecCondition` en Caddy y la unidad `musedock-fence-guard`.
- **Con el servidor apartado no se empuja nada a otros nodos.** Se bloquean "Sincronizar Todo" (también el botón del Dashboard) y la cola del cluster-worker: sus datos son viejos y pisarían los del principal actual.
- **Con el servidor apartado no se empuja nada a otros nodos.** Se bloquean "Sincronizar Todo" (también el botón del Dashboard) y la cola del cluster-worker: sus datos son viejos y pisarían los del principal actual.
- **Un master caducado que vuelve tras una caída real** (otro nodo se promovió mientras tanto) ahora también para Caddy y lsyncd, y deja el panel de rescate. Antes solo ponía las bases en solo lectura y seguía sirviendo webs.
- **Panel de rescate** (`bin/panel-rescue.php start|stop|status`). Al apartar un servidor (fence) se para Caddy entero, y con él el panel web; solo se podía entrar con un túnel SSH. Ahora arranca un Caddy mínimo y aparte que **solo** escucha en el puerto del panel y **solo** lleva al panel interno: no conoce ninguna web y no puede volver a servirlas. Usa un certificado propio, así que hay que entrar por IP (`https://IP:8444`) y aceptar el aviso del navegador. El fence lo arranca solo y el unfence lo para antes de arrancar Caddy.

## [1.0.274] — 2026-10-03 — Plan de DNS del relevo más rápido

### Arreglado
- **`failover_dns_plan` leía los CNAME de todas las zonas dos veces** (desde la 1.0.273) y por MCP se pasaba del tiempo límite. Ahora los lee una vez.

## [1.0.273] — 2026-10-03 — El relevo vuelve a mover las webs que apuntan al servidor por CNAME

### Arreglado
- **Grave (desde la 1.0.265): el relevo dejaba fuera todas las webs que apuntan al servidor por CNAME.** Por ejemplo `festgate.com → CNAME srv1.ejemplo.com`. La regla "los nombres de máquina no se mueven" también frenaba `srv1.ejemplo.com`, y con él todas esas webs. Ahora un nombre de máquina solo se queda quieto si ningún otro dominio apunta a él por CNAME. Si alguno apunta, hace de dirección de servicio y se mueve.

## [1.0.272] — 2026-10-03 — Cambio de roles planificado (switchover) seguro y desde la terminal

### Añadido
- **`bin/cluster-switch.php`** (root): cada paso de un cambio de roles desde la terminal, aunque Caddy esté parado y el panel web del nodo no responda. Órdenes: `status`, `push-config`, `dns-plan`, `fence`, `unfence`, `promote [--force]`, `demote <ip>`, `dns-failover` y `dns-failback`.

### Arreglado
- **Al apartar un nodo (fence), lsyncd seguía copiando en espejo al nuevo master** y habría borrado allí los ficheros subidos durante el relevo. Ahora se para, y vuelve a arrancar al reactivar el nodo si es master.
- **Al apartar un nodo, también se ponía en solo lectura la base de su propio panel**, y no podía ni guardar su rol al reconstruirse como slave. Ahora se deja con escritura: es propia de cada nodo y no se replica.
- **Tras promover un master, el aviso a los demás nodos les daba su IP pública** (la primera de `hostname -I`) y la réplica chocaba con el cortafuegos. Ahora se usa la IP por la que se llega a cada nodo (normalmente la VPN).
- **Algunas herramientas MCP (`cluster_drift`, `filesync_extra_paths`…) no encontraban un nodo por parte de su nombre** ("Filemon" en lugar de "Filemon (154)"), al contrario que el resto. Ahora lo aceptan si coincide con un único nodo.

## [1.0.271] — 2026-10-03 — Webmail del slave: carpetas de datos y sesiones

### Arreglado
- **El webmail del slave de relevo daba error 500.** Había dos causas:
  - Faltaban las carpetas de datos de Roundcube (`/var/lib/musedock-webmail/roundcube/{temp,logs}`): están fuera de `/opt` y lsyncd no las copia. `webmail-node-config.php` ahora las crea, escribibles por PHP-FPM. En el master corrige además la carpeta padre, a la que PHP-FPM no podía entrar.
  - En el slave, Redis es una réplica de solo lectura y Roundcube no podía guardar la sesión. Si Redis es réplica, `webmail-node-config.php` pone las sesiones en ficheros locales; tras un relevo siguen funcionando.

## [1.0.270] — 2026-10-03 — Arreglo al mover la base del webmail

### Arreglado
- **`webmail-move-db.php` fallaba al restaurar** ("could not open input file … Permission denied"). La copia de la base es de root y `pg_restore` se ejecuta como `postgres`, así que no podía leerla. Ahora la lee por la entrada estándar. Volver a ejecutarlo es seguro: reutiliza el usuario y la base vacía que se crearon en el intento fallido.

## [1.0.269] — 2026-10-03 — Webmail en espejo en el nodo de relevo

### Añadido
- **`mail-repair-local.php` dice cuándo cambia las tablas de OpenDKIM.** Antes respondía "ya estaba bien" aunque hubiera corregido el selector en `signing.table`/`key.table`.
- **Webmail en espejo en el nodo de relevo.** Si el master cae, `webmail.ejemplo.com` pasa al slave, que ahora puede servirlo con los mismos usuarios, contactos y ajustes:
  - `bin/webmail-move-db.php` (master, root): mueve la base de Roundcube del PostgreSQL del panel (puerto 5433, que no se replica) al principal (5432), que sí llega al slave. Copia con pg_dump/pg_restore, comprueba que el usuario de Roundcube entra y solo entonces cambia la configuración. La base antigua no se borra.
  - `bin/webmail-node-config.php` (cada nodo, root): lo que cambia de un nodo a otro (la base del panel para cambiar contraseñas y Redis con su contraseña) va en `/etc/musedock/webmail-local.inc.php`, que no se copia. `config.inc.php`, que sí se copia, lo incluye. Con `--enable-route --host=…` activa el webmail en el panel del slave y crea su ruta en Caddy.
  - Los ficheros (`/opt/musedock-webmail`) se copian al slave como carpeta extra de lsyncd.
  - El instalador ya deja el fichero propio del nodo.

## [1.0.268] — 2026-10-02 — Correo verificado al recibir, cambios del MCP con aprobación, y Caddy con los tokens nuevos de Cloudflare

### Arreglado
- **Los mensajes del panel ahora son toasts flotantes** (arriba a la derecha) en todo el panel, también en login, MFA y setup. Antes eran avisos dentro de la página: movían el contenido al aparecer y al irse, y los errores se cerraban a los 4 segundos sin tiempo para leerlos. Los de éxito e información se van solos a los 6 s (se pausan con el ratón encima). Los de error y aviso se quedan hasta cerrarlos y tienen botón **Copiar**. Desde JS: `musedockToast(texto, tipo)`.

### Añadido
- **Credenciales pendientes para todo lo que crea el MCP**, no solo buzones. La contraseña se genera en el servidor, nunca sale en la respuesta de la herramienta (ni en el historial del chat) y se ve una vez en Ajustes → MCP → Credenciales pendientes. La tabla muestra para qué es, de qué tipo y los datos de conexión (host, puerto, usuario, base), y caduca a los 7 días. Herramientas nuevas que lo usan:
  - **`database_create`**: base MySQL/MariaDB o PostgreSQL para un hosting, con su usuario. Usa el mismo código que /databases (nuevo `DatabaseService`).
  - **`password_change_request`**: el MCP **no cambia contraseñas**, solo deja una solicitud (buzón, usuario de base de datos de un hosting o acceso SFTP). Un administrador la confirma en **Ajustes → MCP → Cambios de contraseña por confirmar** escribiendo **su contraseña de administrador**. Entonces el panel genera la nueva, la aplica, la sincroniza a los slaves y la deja en Credenciales pendientes. Siempre prohibido: root, cuentas del sistema, la base de datos del panel y los administradores. Las solicitudes caducan a las 24 h.
  - **`mail_quota_request`**: el MCP prepara cambios de cuota de uno o varios buzones, o de todos los de un dominio (0 = sin límite), pero no los aplica. Aparecen en **Ajustes → MCP → Cambios por aprobar**, donde el administrador marca varios a la vez y los aprueba o rechaza tras un modal de confirmación. Como no hay secretos, no pide contraseña. Es una cola general (`McpChangeRequests`) preparada para más tipos de cambio.
  - El MCP **no tiene ninguna herramienta para borrar** buzones, bases de datos ni hostings.
- **Arreglado: los cambios de cuota de un buzón no llegaban a las réplicas de correo**, ni al fichero de Dovecot ni a su copia de la BD.
- **Arreglado: cambiar la contraseña de un buzón no llegaba a las réplicas de correo.** Filemon seguía con el hash anterior, y tras un relevo el buzón solo habría aceptado la contraseña antigua. Ahora el cambio se replica.
- **Correo recibido: cabecera `Authentication-Results` con `spf=`, `dkim=` y `dmarc=`.** rspamd ya comprobaba los tres para puntuar el spam, pero no los anotaba; solo aparecía el `dkim=` de OpenDKIM. Ahora rspamd escribe los tres en una sola cabecera firmada con el nombre del servidor de correo (`myhostname`), y quita las `Authentication-Results` que traiga el mensaje de fuera, para que nadie pueda colar una falsa. OpenDKIM pasa a modo `s` (solo firma lo que sale), porque la verificación la hace rspamd. Instalaciones nuevas: lo hace el instalador. Ya instaladas: `bin/mail-auth-results.php` (como root; `--dry-run` para ver el plan), con copia previa y vuelta atrás si rspamd no valida.
- **Caddy no podía cargar su configuración con los tokens nuevos de Cloudflare (`cfut_…`).** El módulo `caddy-dns/cloudflare` anterior a v0.2.4 los rechaza por su longitud ("API token appears invalid"). Caddy seguía en marcha con la configuración que ya tenía, pero cualquier recarga fallaba y no podía renovar certificados por DNS. Ahora:
  - `bin/caddy-build-run.php <proveedor> <tarea> --force` recompila Caddy aunque el módulo ya esté, y así lo actualiza a su última versión;
  - la validación del binario nuevo se hace con el entorno del servicio (antes siempre fallaba con un Caddyfile que usa el token);
  - el panel no pone en Caddy un token `cfut_`/`cfat_` si su módulo es antiguo: avisa de que hay que recompilar.
- **Arreglado: webmail daba error 500 ("NOAUTH Authentication required")** desde que Redis empezó a exigir contraseña al replicarse al slave. Roundcube guardaba sus sesiones en Redis sin contraseña. Ahora el instalador la incluye, y `bin/webmail-redis-auth.php` (como root) la añade a un webmail ya instalado, con copia previa.
- **Arreglado: `dns_record_set` rechazaba los nombres con guion bajo**, como `selector1._domainkey`, `_dmarc` o `_acme-challenge`. Así no se podían publicar los registros DKIM de un proveedor de envío como Sweego.
- **Lista blanca de fail2ban** en mortadelo con la VPN y los servidores propios. Se gestiona con el MCP `fail2ban_manage`.
- **Arreglado: crear una base PostgreSQL desde /databases.** El SQL usaba comillas de shell (`'nombre'`, un texto) en lugar de identificadores (`"nombre"`), y PostgreSQL lo rechazaba.
- **Toasts**: solo muestran mensajes de aviso. Antes podían enseñar datos internos de una página, como las credenciales recién creadas de una base de datos.
- **El relevo ya no mueve los nombres de máquina.** Antes cambiaba todos los registros A que apuntaban a la IP del primario, incluido el propio nombre del servidor (p. ej. `srv1.ejemplo.com`), que pasaba a apuntar al de relevo. Ahora se dejan quietos los nombres cuya primera etiqueta es el hostname de un servidor del cluster. Se deducen solos, sin nombres fijos: el hostname, `panel_hostname` y los nombres de los nodos. El master los manda al slave, que es quien hace el relevo. Se pueden añadir más en el ajuste `failover_dns_exclude`. `failover_dns_plan` los muestra en `machine_names_kept`.
- **Textos del MCP sin nombres de una instalación concreta**: los ejemplos usan `ejemplo.com`. El panel es genérico para cualquier servidor.
- **MCP `fail2ban_manage`**: sin `ip`, lista las IPs bloqueadas por jail y la lista blanca. Con `ip`, plan para desbloquearla y, con `whitelist=true`, añadirla a la lista blanca (edita solo la línea `ignoreip`, con copia previa). Nunca quita IPs de la lista blanca.
- **Selector DKIM por dominio** (MCP `mail_dkim_selector` y `bin/mail-dkim-selector.php <selector> dominio…`): cuando otro remitente (Sweego, la web…) ya publica su clave en `default._domainkey`, el panel firma con otro selector (p. ej. `musedock`) con la misma clave y conviven. Arreglado de paso: OpenDKIM solo añadía la línea de un dominio si no estaba, así que un cambio de selector no llegaba; ahora sustituye exactamente las líneas de ese dominio.
- **`mail_dns_publish` con `spf_add`**: añade términos (p. ej. `include:spf.sweego.io`) al SPF existente, editando ese único registro. **`dns_record_set` ya no crea un segundo registro SPF o DMARC** en el mismo nombre (dos invalidan los dos): se niega y explica cómo editarlo.
- **`mail_dns_publish` limpia el SPF que dejó el Email Routing de Cloudflare.** Cuando el MX ya no va a Cloudflare, propone quitar solo el término `include:_spf.mx.cloudflare.net`. Lo hace editando el registro, sin borrarlo, y el resto del SPF se mantiene.
- **MCP `mail_replication_status`** (solo lectura, también con `node`): dice si el contenido de los buzones se replica con el otro nodo (Dovecot dsync) y, buzón a buzón, la última sincronización correcta, los fallos y los buzones que el replicador aún no tiene. En el master compara además los dominios, buzones y alias con cada réplica de correo y dice qué le falta (así se vio que Filemon no tenía muserelay.com).
- **MCP `mail_resync_node`** (master, plan/apply): reenvía a un nodo réplica todos los dominios, buzones y alias, igual que el botón Sincronizar de Mail → Infraestructura. Nunca borra en el nodo. Con `copy_mail=true` fuerza además la copia completa de los buzones (dsync).
- **`mail_domain_verify` ya no da el SPF por malo cuando autoriza a este servidor junto con otros remitentes.** Es el mismo criterio que usa `mail_dns_publish`, que amplía el SPF en vez de sustituirlo. Antes exigía el texto exacto. Avisa si sobra el `include` del Email Routing de Cloudflare.
- **`bin/mail-repair-local.php dominio…`** (como root): rehace en el servidor los ficheros de dominios de correo a partir de la BD (carpeta, clave DKIM y tablas de OpenDKIM, Maildir y cuota de cada buzón), sin tocar la BD ni el DNS. Se puede repetir sin riesgo.
- **El MCP por stdio se niega a aplicar acciones que modifican si no corre como root.** Sin root, la BD se escribía pero los ficheros del sistema no (DKIM, Maildir…), y el alta quedaba a medias.
- **MCP `cloudflare_tokens`** dice si Caddy tiene el token de la cuenta con la zona del panel (o que no puede comprobarlo, si el proceso no puede leer `/etc/default/caddy`).
- **MCP `cloudflare_email_routing`** ya no se salta las zonas cuyo token no puede leer el *estado* de Email Routing (otro permiso): lee igualmente las reglas y el MX, y deduce si recibe por Cloudflare (`mx_to_cloudflare`). Antes, con el token rotado de la cuenta principal, solo aparecían 2 de 10 dominios.

## [1.0.265] — 2026-10-02 — El token de Caddy se sincroniza solo desde el master

### Arreglado
- **Guardar las cuentas de Cloudflare en el master no dejaba bien el token de Caddy en todos los nodos.** Solo se tocaba si se marcaba la casilla, se usaba siempre "la primera cuenta" (con varias cuentas, como Muse Layer, podía ser la equivocada) y un nodo que no respondía o con panel antiguo salía como "sincronizado". Ahora:
  - el token de Caddy es el de **la cuenta que contiene la zona del dominio del panel** (p. ej. musedock.com), lo elija el master y lo manda a los slaves;
  - **al guardar, siempre**: cada nodo (master y slaves) compara su `CLOUDFLARE_API_TOKEN` con ese y **solo si es distinto** lo escribe y reinicia Caddy; antes comprueba con Cloudflare que el token está activo (nunca cambia uno que funciona por uno que no);
  - la casilla pasa a ser **"Forzar token en Caddy"** (reescribir y reiniciar aunque ya esté bien);
  - el mensaje dice nodo a nodo: actualizado, ya lo tenía, error (con el motivo), en cola o panel antiguo sin actualizar.
- **El token no llegaba a los slaves, y Filemon daba "unexpected eof" en bucle.** El slave reiniciaba Caddy en mitad de la petición del master (el panel se sirve a través de Caddy). La petición se cortaba, se quedaba en la cola y cada reintento volvía a reiniciar Caddy. Además, los nodos antiguos (Nitro) no tenían `/usr/local/bin/update-caddy-token.sh`. Ahora, si el panel corre como root, escribe él mismo `/etc/default/caddy` (copia previa, permisos 600; el token no aparece en ninguna línea de comandos) y deja el reinicio de Caddy **programado a 3 s** con `systemd-run`, después de responder. El ayudante solo se usa si el panel no es root.
- **Tras guardar con reinicio de Caddy, la página volvía a la pestaña Estado** en vez de a Failover. Ahora vuelve a Failover.
- **Los mensajes de error se cerraban solos a los 4 segundos**, sin tiempo para leerlos ni copiarlos. Ahora (en todo el panel) los errores y avisos no se cierran solos y además salen en un **modal** con el texto seleccionable y un botón **Copiar**. Los mensajes de éxito siguen cerrándose solos.

### Añadido
- **MCP `cloudflare_caddy_token_sync`** (en el master, plan/apply): lo mismo que Guardar, con el resultado por nodo.
- **MCP `cloudflare_tokens`** dice si Caddy tiene el token de la cuenta con la zona del panel.

## [1.0.263] — 2026-10-02 — Tokens de Cloudflare: los nodos ya no los reciben estropeados, y el navegador no cuela contraseñas

### Arreglado (importante)
- **Los nodos slave guardaban los tokens de Cloudflare estropeados.** Cada panel cifra los tokens con su propia clave (derivada de su `DB_PASS`). Al enviar la configuración de relevo, el master solo mandaba el token descifrado si se marcaba "Actualizar token de Caddy"; sin esa casilla, o cuando era el slave quien pedía la configuración, mandaba el **texto cifrado**. El slave lo guardaba como si fuera el token, y Cloudflare lo rechazaba ("Invalid request headers"; visto en Filemon). Así, el slave no habría podido cambiar los DNS en un relevo. Ahora:
  - el master **siempre** envía los tokens descifrados (por el canal autenticado del cluster; enmascarados en el registro);
  - el slave los cifra con **su** clave;
  - si al slave le llega algo que no tiene forma de token (por ejemplo, de un master con versión antigua), **conserva el que tenía** en vez de estropearlo, y lo deja anotado.

- **Al guardar las cuentas de Cloudflare, el navegador podía rellenar un token con una contraseña guardada.** El campo era de tipo contraseña y vacío (desde la 1.0.262 la página ya no envía los tokens), así que Chrome lo rellenaba solo y se guardaba como token. Pasó con la cuenta Muse Layer LLC en mortadelo. Ahora el campo es de texto con el contenido oculto, sin autocompletado. Además, al guardar, **un token nuevo que Cloudflare no acepta no sustituye al anterior**: se conserva el que había y se avisa.

## [1.0.262] — 2026-10-02 — Correo y hosting del mismo dominio se reconocen, y /domains carga al momento

### Añadido
- **Al crear un dominio de correo, el panel detecta si ese dominio ya es una web del panel.** Lo comprueba como dominio principal de un hosting, como dominio extra, como alias y como redirección. Correo y hosting van unidos por el nombre del dominio: el hosting no se toca, y el correo aparece también en la ficha del hosting. Ahora se avisa en los dos sitios:
  - **Formulario** (Mail → Dominios → Nuevo): mientras escribes el dominio, aviso con enlace al hosting y su cliente propuesto (si no has elegido otro).
  - **MCP `mail_domain_create`**: el plan lo dice antes de aplicar.
  Sin cliente elegido, el dominio de correo **hereda el cliente del hosting** (antes quedaba vacío).
- **Mail → Dominios**: etiqueta **"Hosting"** con enlace a la ficha cuando el dominio también es una web.
- **/domains**: etiqueta **"Correo"** junto a los dominios que tienen correo en el panel, con enlace a su dominio de correo.
- **MCP `caddy_domains`** (solo lectura): todos los dominios que sirve Caddy y **de dónde vienen**:
  - `panel_hosting`: hostings del panel, con su dominio, www, dominio extra, subdominio, alias y redirecciones;
  - `panel_system`: dominio del panel, webmail, CardDAV y certificado del correo;
  - `caddyfile`: escritos en `/etc/caddy/Caddyfile`;
  - `external`: añadidos por la API de Caddy desde fuera del panel, por ejemplo los tenants del CMS MuseDock, que se reconocen por su @id `route_*`.
  Recorre también las subrutas y **avisa de rutas con el mismo @id repetidas**. En mortadelo: 161 dominios (68 de hostings, 4 propios del panel, 2 del Caddyfile y 87 externos) y la ruta del certificado del correo repetida 10 veces, restos de antes de la 1.0.254.
- **MCP `domains_status`** (solo lectura): el estado de **todos** los dominios del servidor en un sitio. Incluye hostings, los que sirve Caddy por otras aplicaciones (como el CMS), el Caddyfile y el correo. De cada uno da:
  - **registro** por RDAP, consultado directamente al registro de su extensión según la lista oficial de IANA: activo, caducado, en redención o libre, con fecha de caducidad y registrador;
  - **DNS**: apunta aquí, a otro sitio o no resuelve;
  - **dónde se usa**.
  Avisa de dominios libres o en redención que siguen en uso, de los que caducan en menos de 30 días y de los registrados sin DNS. El registro se guarda 24 h (`refresh=true` para forzar), con un máximo de 40 consultas por llamada. Las extensiones sin RDAP (como `.es`) se marcan como "desconocido", nunca como "libre".
- **Editar DNS de Cloudflare por MCP, sin poder borrar nunca.**
  - `dns_records` (solo lectura): lista los registros de la zona de un dominio. Si la zona no aparece, refresca las zonas de las cuentas, por si se acaba de registrar.
  - `dns_record_set`: crea un registro A, AAAA, CNAME, TXT o MX, o modifica el que ya existe con ese nombre y tipo.
  - **No hay ninguna herramienta para borrar.** Si un cambio exigiera borrar otro registro (por ejemplo, poner un CNAME donde hay un A), se niega y lo explica.
  - TXT y MX se **añaden** sin tocar los demás valores; si ese valor ya existe, no hace nada.
  - Primero devuelve el plan. Para aplicarlo hacen falta **dos interruptores**: "Permitir acciones que modifican" y el nuevo **"Permitir editar DNS en Cloudflare"** (Ajustes → MCP, apagado por defecto). El plan se puede ver sin ellos. Cada cambio queda en el registro del panel.
- **MCP `server_profile`**: el "manual de bienvenida" de cada servidor. Da rol, IPs, con quién hace el relevo, nodos, cuentas de Cloudflare, correo, avisos, monitorización, permisos del MCP y **a qué nombre apuntan los dominios nuevos** (`dns_default_target`, por defecto el dominio del panel; en mortadelo, `mortadelo.musedock.com`). Explica que se usa CNAME de la raíz y de www, con el proxy de Cloudflare como opción, y por qué: un relevo solo mueve el registro A de ese nombre. `server_profile_set` cambia ese destino sin tocar ningún DNS existente.
- **Instrucciones del MCP para un asistente que se conecta por primera vez**: lo primero es llamar a `server_profile`. Incluyen ya el destino por defecto de los dominios nuevos y qué herramientas usar para ver el estado general.
- **MCP `monitor_status`** (solo lectura): si la monitorización está activa y leyendo, sus últimos valores (CPU, RAM, disco, red), umbrales, avisos de las últimas 24 h con su detalle, problemas abiertos, errores del recolector y si los avisos salen. **`monitor_configure`**: activarla o desactivarla y ajustar umbrales y la repetición de avisos.
- **MCP `mail_domain_alias`: dominio de correo sinónimo de otro.** Todo lo que llega a X@alias se entrega en X@destino, con los mismos buzones y alias y conservando el nombre de usuario. Da de alta el dominio alias si no existe (con su DKIM y el cliente del destino) y crea su catch-all `@alias` → `@destino`: Postfix reescribe `@otrodominio` conservando el usuario (virtual(5)). Se niega si el dominio alias ya tiene buzones propios, porque el catch-all les quitaría el correo. No toca el DNS. Primero devuelve el plan.
- **MCP `cloudflare_tokens`** (solo lectura): qué tokens de Cloudflare usa este servidor (cuentas del panel y el `CLOUDFLARE_API_TOKEN` de Caddy) y qué pueden hacer. Da su estado y caducidad según Cloudflare, un id corto, a cuántas zonas llega y si puede leer DNS y reglas de Email Routing. Nunca muestra el token. Sirve para reconocerlos en el panel de Cloudflare, donde todos se llaman igual, antes de editar o limpiar.
- **MCP `cloudflare_email_routing`** (solo lectura): por cada zona de las cuentas de Cloudflare del panel, si tiene el enrutamiento de correo de Cloudflare (Email Routing) activado, sus reglas (dirección → reenvío), el catch-all, a qué apunta su MX y si ya existe como dominio de correo en el panel. Si el token no tiene permiso para leer las reglas, lo dice en vez de mostrar una lista vacía. Pensado para ver qué dominios reciben correo por Cloudflare antes de pasarlos al correo propio.
- **MCP `firewall_check_ip`** (solo lectura, en tiempo real): qué puede hacer una IP concreta contra el servidor. Simula una conexión nueva desde ella a cada puerto en escucha con las reglas actuales (incluidas las listas ipset) y dice a qué servicios llegaría, cuáles le bloquean y qué regla lo hace, si es un origen de confianza del panel y si fail2ban la tiene bloqueada.
- **Suspender un hosting puede quitarlo también de Caddy.** Nueva casilla en el diálogo de Suspend, pensada para un dominio caducado o sin uso: sin página de mantenimiento y sin pedir certificados. No se borra nada; al reactivar se restaura la ruta. Sin marcarla, se mantiene como antes, con la página de mantenimiento.
- **`failover_dns_plan` revisa todo lo que sirve Caddy**, no solo los hostings. Así ve también los tenants del CMS y lo que añadan otras aplicaciones. Separa lo que de verdad se quedaría atrás (`caddy_domains_not_moved`) de lo que no tiene DNS (`caddy_domains_without_dns`: no hay nada que mover). Ejecutado en el master, que es donde Caddy lo sirve todo.

### Mejorado
- **/domains ya no comprueba el DNS de todos los dominios cada vez que se abre.** Antes lanzaba una comprobación por dominio (unas 37), una detrás de otra, y cada una hacía además `curl ifconfig.me` para averiguar la IP del propio servidor: decenas de peticiones a internet por visita. Ahora:
  - la página carga al momento con el **último estado guardado** y su antigüedad ("hace 2 h");
  - un **icono de recargar por dominio** y un botón **"Comprobar DNS"** para todos;
  - al abrirla solo se comprueban los dominios que nunca se han comprobado;
  - la IP propia sale de las interfaces del servidor y de la IP pública guardada, sin peticiones externas;
  - la detección de "CF Proxy" usa los rangos de IP reales de Cloudflare, en vez de "empieza por 104.".

### Arreglado (importante)
- **Los botones de relevo del panel (Failover, Failback, Emergencia en Cluster → Failover) no hacían nada.** Su JavaScript buscaba el campo de seguridad con un nombre que no existe (`_token`, cuando el panel usa `_csrf_token`), daba un error y no llegaba a enviar la petición. Lo mismo le pasaba al botón de verificar el token de una cuenta de Cloudflare. Corregidos los dos.

- **El aviso "Exposición pública inesperada" saltaba con puertos que el cortafuegos tiene cerrados.** Solo miraba en qué dirección escucha cada servicio, así que avisaba de MariaDB y Redis de la réplica (cerrados salvo para el otro nodo por la VPN) y de los puertos del correo en un servidor de correo. Ahora solo cuenta los puertos que la auditoría real del cortafuegos ve **abiertos a todo internet**, y en un servidor de correo da por buenos sus puertos (25, 110, 143, 465, 587, 993, 995 y 4190).
- **La simulación del cortafuegos no entendía las listas de IPs (ipset) cuando se le daba una IP concreta**: las tomaba por coincidentes. Ahora pregunta a ipset si esa IP está en la lista.

- **En un nodo slave, la pestaña Failover no enseñaba ninguna cuenta de Cloudflare**, y no se sabía si las tenía ni dónde se cambiaban. Ahora muestra, en solo lectura y sin tokens, las cuentas copiadas del master con sus zonas, y explica que se cambian en el master ("Actualizar token de Caddy" actualiza también el Caddy del nodo).

### Seguridad
- **La página "Cuentas Cloudflare" ya no envía los tokens al navegador.**
- **El panel habla con la API de Cloudflare siempre por IPv4.** Si un token tiene filtro de IPs ("Client IP Address Filtering"), normalmente lleva solo las IPv4 de los servidores; por IPv6 Cloudflare rechazaría el token. Antes iban en el código de la página (en un campo de contraseña, pero legibles con "ver código fuente"). Ahora el campo sale vacío con "guardado — déjalo vacío para conservarlo": vacío conserva el token guardado y uno nuevo lo sustituye. El botón de verificar usa el token guardado si el campo está vacío.

## [1.0.261] — 2026-10-02 — Fin del bombardeo de avisos por disco lleno, y `node` por id

### Añadido
- **SMTP secundario para los avisos** (`notify_smtp2_*`). Si el principal falla o rechaza (por ejemplo, por cupo diario agotado), el aviso se envía por el secundario, con su propio remitente si lo tiene. Se configura con `notify_configure` (`smtp2_host`, `smtp2_port`, `smtp2_user`, `smtp2_from`, `smtp2_encryption`, `smtp2_pass_file`; la contraseña se lee de un fichero, nunca del chat) y se copia a los nodos con `copy_to_nodes`. `notify_status` muestra el secundario, el último envío correcto, el último error y los correos enviados hoy frente al tope.

### Arreglado (importante)
- **El aviso de disco lleno se enviaba cada 5 minutos para siempre.** La pausa entre avisos del monitor tiene un máximo de 1 h (por defecto 5 min), así que un estado que no se arregla solo, como un disco al 96 %, mandaba un correo cada 5 min. Al activar los avisos en Nitro se agotó en una noche el cupo diario de Sweego (100 correos para todos los paneles). Ahora **todos** los avisos del monitor (disco, CPU, RAM, GPU) van **por episodio**: avisan al empezar el problema, se repiten **como mucho cada 12 h** mientras sigue (`monitor_alert_repeat_hours`), y si se arregla (más de 10 min sin dispararse) y vuelve, es un episodio nuevo y avisa otra vez. La cuenta es por recurso: cada disco, punto de montaje o GPU va aparte.
- **Tope diario de correos de aviso por panel** (`notify_email_daily_cap`, 25 por defecto): un aviso repetido ya no puede agotar el cupo del proveedor y dejar sin correo el aviso importante. El último correo del día lo dice, y lo demás queda en el registro del panel.
- **Si el proveedor SMTP rechazaba un aviso, se perdía sin rastro.** No se comprobaba la respuesta a `MAIL FROM` ni a `RCPT TO`, ni se guardaba el motivo. Ahora cada paso de la conversación SMTP se comprueba con su código, y el error exacto del servidor queda en `notify_email_last_error` (visible en `notify_status`) y en el registro del panel. Comprobado con Sweego al agotarse el cupo: responde `552 Usage over quota` al enviar el mensaje. También se codifica el asunto en UTF-8 (las tildes llegaban rotas), se normalizan los saltos de línea a CRLF y se protegen las líneas que empiezan por punto.
- **Los avisos copiados del master llegaban con el nombre de remitente del master** (los de Nitro salían como "Mortadelo Master"). Ese nombre ya no se copia: cada nodo usa "MuseDock <su hostname>".

### Arreglado
- **El argumento `node` de las herramientas MCP podía ejecutar la consulta en el nodo equivocado.** Se buscaba por coincidencia parcial del nombre antes que por id, así que `node: "1"` cogía "Filemon (154)" porque su nombre contiene un 1. Ahora tienen prioridad el id y el nombre exactos. La coincidencia parcial solo se usa si no hay ninguna exacta, nunca con un número, y si encaja con varios nodos se pide el id.

## [1.0.260] — 2026-10-01 — Avisos cuando se rompe una réplica, y avisos configurables por MCP

### Añadido
- **Vigilancia de réplicas con aviso** (`ReplicationHealthService`, cada 5 min en `cluster-worker`, en cualquier rol). Hasta ahora una réplica rota pasaba en silencio hasta el día del relevo. Avisa por los canales del panel de:
  - un slot de PostgreSQL **perdido** (se superó `max_slot_wal_keep_size`: hay que recopiar la réplica), a punto de perderse, o más de 10 min sin réplica conectada (no cuentan los slots que nunca se han usado);
  - una réplica de PostgreSQL que no recibe del master;
  - la réplica de MariaDB/MySQL parada (con su error) o más de 15 min por detrás;
  - Redis réplica con el enlace caído.
  Avisa una vez por problema, lo repite cada 6 h si sigue y avisa cuando se arregla.
- **MCP `notify_status`** (solo lectura): por dónde avisa el panel (correo y/o Telegram, servidor, remitente y destinatario, nunca secretos), si los avisos están activados y el último resultado de la vigilancia de réplicas.
- **MCP `notify_configure`**: configura y activa el correo (SMTP) y/o Telegram. La contraseña y el token se leen de un fichero bajo `/root/`, nunca del chat, y se guardan cifrados. `enable_email` y `enable_telegram` activan lo ya configurado. Con `copy_to_nodes`, el master copia su configuración de avisos a todos sus nodos por el canal autenticado del cluster (acción `set-notify-config`): cada nodo vuelve a cifrar los secretos con su propia clave, y en el log se enmascaran. `test=true` envía una prueba.
- `failover_preflight` avisa si el panel no puede avisar de nada y de los problemas de réplica que haya en ese momento.

## [1.0.259] — 2026-10-01 — Cambio de DNS en un relevo: completo, y la vuelta solo devuelve lo que se movió

### Arreglado (importante)
- **La vuelta del relevo (failback) cambiaba a la IP del primario TODOS los registros que apuntaban a la IP del servidor de relevo.** Eso incluía los que siempre han sido suyos (p. ej. `filemon.musedock.com`) y cualquier otro servicio en esa IP. Ahora el relevo anota en un diario (`failover_dns_journal`, y `failover_dns_journal_backup` para la IP de reserva) cada registro que cambia: zona, id, IP de origen y de destino. La vuelta **solo devuelve lo anotado**, y solo si sigue apuntando donde lo dejó el relevo: si alguien lo cambió después a mano, no se toca. Sin diario no se devuelve nada a ciegas, y se avisa. Se aplica también al cambio por caída de la interfaz (dual WAN) y a su vuelta.
- **El cambio de DNS solo procesaba los primeros 100 registros A de cada zona.** Ahora recorre todas las páginas (`CloudflareService::listRecordsAll`).
- **Los registros que van por el proxy de Cloudflare recibían un TTL fijo**, que Cloudflare no admite para esos registros (solo "automático"). Ahora se les envía TTL automático, y la bajada de TTL previa al relevo se los salta.

- **Reconstruir un antiguo master como slave copiaba también las cuentas de MariaDB del otro nodo.** El volcado con `--all-databases` incluía la base `mysql`, así que al importarlo se pisaban `root` y `debian-sys-maint` locales. El panel de ese nodo perdía el acceso a su propio MariaDB, porque `debian.cnf` ya no coincidía. Ahora solo se copian las bases de datos de las apps (las de sistema nunca). Las cuentas de las apps ya existen en los dos nodos, y las nuevas llegan por la propia réplica.
- **`replication_adopt` en el master no guardaba el usuario ni la contraseña de replicación de MariaDB.** Al reconstruir ese nodo como slave (`demoteToSlave`), habría intentado conectar con el usuario por defecto. Ahora se guardan `repl_mysql_user` y `repl_mysql_port`, y la contraseña cifrada si es la misma que la de PostgreSQL.

### Añadido
- **MCP `failover_dns_plan`** (solo lectura): con las cuentas de Cloudflare del panel que hará el relevo, lista por zona los registros A que cambiarían, los dominios que se mueven con ellos por CNAME, los dominios de hostings que **no** se moverían (y por qué) y el diario de lo ya movido. En mortadelo → Filemon: 9 registros A, 177 nombres por CNAME, y solo `elbookdeamanda.com` fuera, porque su DNS no está en Cloudflare.

### Arreglado
- **La copia de certificados del master al nodo podía sustituir un certificado más nuevo por uno más viejo.** Pasaba cuando el nodo había renovado por su cuenta un dominio que también tiene el master (por ejemplo `mail.…`): rsync sobrescribía el fichero solo porque era distinto. Ahora se copia con `--update`. Los certificados propios del nodo (su hostname, sus IPs) nunca se han tocado, porque la copia no usa `--delete`.

## [1.0.258] — 2026-10-01 — lsyncd reutiliza la conexión SSH con cada nodo

### Mejorado
- **lsyncd abría una conexión SSH nueva por cada tanda de cambios y carpeta**, cada 15 s. En Filemon eso eran unos 13 logins de root por minuto: 327 en 25 minutos, cada uno con su sesión de systemd y su línea en `auth.log`. Ahora el `rsh` de lsyncd usa `ControlMaster=auto` con `ControlPersist=600` (socket en `/run/musedock-lsyncd-%C`): una sola conexión por nodo, que se reutiliza. Se aplica al regenerar la configuración de lsyncd (Archivos → guardar, `filesync_configure` o `filesync_extra_paths`).

## [1.0.257] — 2026-10-01 — replication_adopt también registra MariaDB, y cluster_drift entiende las webs guardadas para el relevo

### Añadido
- **`replication_adopt` registra también la réplica de MariaDB/MySQL** con las mismas claves que el asistente de Replicación del panel. En el slave, `repl_mysql_role=slave`, la IP, el puerto y el usuario se leen de `SHOW SLAVE STATUS`; la contraseña se guarda cifrada si es la misma que la de PostgreSQL. En el master, `repl_mysql_role=master` si hay hilos `Binlog Dump`. Avisa si MariaDB y PostgreSQL tienen roles distintos.

### Arreglado
- **`cluster_drift` decía "falta la web X en el nodo"** para las webs del master que `config_mirror` guarda aparte desde la 1.0.253 (`/var/lib/musedock/Caddyfile.from-master`). El inventario del nodo ahora informa de esas webs (`caddyfile_staged_sites`), y `cluster_drift` las cuenta como presentes, con una nota de que se ponen al promover.

## [1.0.256] — 2026-10-01 — Copiar certificados a un nodo ya no le borra las rutas de Caddy

### Arreglado (importante)
- **Cada vez que el master copiaba los certificados a un nodo (unos 20 min), le quitaba a Caddy las rutas puestas por la API.** Tras copiarlos ejecutaba por SSH `systemctl reload caddy`, que recarga desde el Caddyfile. En los nodos que no arrancan Caddy con `--resume` se perdían la ruta del panel por su nombre, la del certificado del correo y las de los hostings. Visto en Filemon: el panel dejó de responder por `filemon.musedock.com:8444` (error TLS) y Caddy dejó de escuchar en el 443.
  - Ahora vuelve a cargar **la configuración que ya está en marcha**: `GET /config/` y después `POST /load` con `Cache-Control: must-revalidate`, para que Caddy no la ignore por ser idéntica. Caddy relee los certificados de su almacén y no pierde ninguna ruta.

## [1.0.255] — 2026-10-01 — No se envían volcados de BBDD a un nodo que ya es réplica

### Arreglado (importante)
- **El master seguía enviando y restaurando volcados de las bases de datos en un slave que ya replicaba de él.** La comprobación (`isStreamingActive()`) solo miraba el rol del propio servidor, y en el master siempre respondía "no hay réplica". Con "Volcados de BBDD" activado en Archivos, cada pocos minutos se restauraban volcados encima de la réplica. En MariaDB eso se hace como root, lo que rompe la replicación. Visto en mortadelo → Filemon, justo antes de convertir Filemon en réplica.
  - Nuevo `ReplicationService::nodeReplicatesFromHere($node)`: desde el master, mira si hay conexiones de replicación que vienen de las IPs del nodo. En PostgreSQL usa `pg_stat_replication` de cada instancia; en MariaDB, los hilos `Binlog Dump` de `SHOW PROCESSLIST`.
  - Es **persistente**: en cuanto lo detecta, lo guarda en `filesync_node_replica_{id}`, para que un corte momentáneo de la réplica no vuelva a activar los volcados. También se puede marcar a mano antes de montar la réplica.
  - Se aplica en los tres sitios que envían volcados: `filesync-worker` (el periódico), la sincronización completa y `syncAllToNode`. Los demás nodos (p. ej. Nitro) siguen recibiéndolos como antes.

## [1.0.254] — 2026-10-01 — La ruta del certificado de correo sobrevive a los reinicios de Caddy

### Arreglado
- **Tras reiniciar Caddy, el hostname de correo (`mail.…`) desaparecía de Caddy y su certificado dejaba de renovarse.** La ruta se añade por la API de Caddy, así que se pierde al reiniciar Caddy sin `--resume`, y el reparador de arranque no la reponía. Además, `ensureMailCertViaCaddy` no comprobaba si la ruta ya existía: en una segunda pasada Caddy rechazaba el POST con "duplicate ID" y `repair-mail-cert-sync` fallaba. Visto en Filemon con la actualización: `update.sh` repara el certificado de correo y después reinicia Caddy.
  - Nuevo `MailService::ensureMailCertRoute()`, idempotente: consulta `/id/mail-cert-…` antes de añadirla y, si Caddy la rechaza, devuelve el error real.
  - `cli/repair-caddy-routes.php`, que se ejecuta en cada arranque de Caddy, la repone en los nodos de correo, igual que la del webmail.

## [1.0.253] — 2026-10-01 — config_mirror ya no toca el Caddyfile en uso del slave

### Arreglado (importante)
- **`config_mirror` escribía las webs del master en el `/etc/caddy/Caddyfile` en uso del slave.** Se aplicaban "al promover", pero cualquier reinicio de Caddy (reboot, `bin/update.sh`, un `systemctl restart`) ya cargaba ese fichero. En Filemon, esas webs usan `{env.CLOUDFLARE_API_TOKEN}`, que allí no existe: con un reinicio, Caddy no habría arrancado y habrían caído el panel, el correo y las webs del slave. En obelix, `update.sh` validó el fichero y reinició Caddy con las webs de asterisk.
  - Ahora las webs del master se guardan validadas **aparte**, en `/var/lib/musedock/Caddyfile.from-master` (0600). El Caddyfile en uso del slave no se toca.
  - **Al promover**, se combinan las opciones globales y el bloque del panel del nodo con esas webs. Se valida con el entorno **real** de Caddy, sin valores de relleno. Solo si pasa, se escribe y se reinicia Caddy. Si no pasa, Caddy sigue con su configuración y se avisa de qué falta: mejor sin esas webs que sin Caddy.
  - **Corrección automática** en los slaves con copias anteriores: en la siguiente pasada de `config_mirror`, el Caddyfile en uso vuelve a ser el propio del slave. Se usan las opciones globales y el bloque del panel actuales, y las webs propias de antes de la primera copia (la copia de seguridad más antigua en `/var/backups/musedock-mirror/`). Antes de escribirlo se valida y se guarda copia de seguridad. No se reinicia Caddy.
  - Si falta una variable `{env.…}`, el aviso explica que en un relevo esas webs **no** se pondrían hasta que esté.

## [1.0.252] — 2026-10-01 — cluster_drift compara el PHP por defecto

### Añadido
- **`cluster_drift` avisa si el PHP por defecto del nodo es distinto del master.** Por ejemplo, mortadelo usa 8.3 y Filemon 8.4. Los crons y scripts que llaman a `php` a secas correrían con otra versión tras un relevo. El aviso incluye el comando para igualarlo (`update-alternatives --set php …`).

### Arreglado
- **`cluster_drift` decía "el nodo no tiene Composer" cuando sí estaba instalado pero no arrancaba.** Pasa, por ejemplo, con el Composer 2.2.6 de Ubuntu cuando el PHP por defecto es 8.4. Ahora distingue entre "no está instalado" e "instalado pero `composer --version` falla", y dice qué PHP por defecto tiene el nodo.
- **El aviso de `config_mirror` sobre el token de Cloudflare indicaba un menú equivocado.** El sitio correcto es Cluster → Failover → Cuentas Cloudflare. Ahora además avisa de que ese botón usa la **primera** cuenta y reinicia Caddy en todos los nodos. Como alternativa, propone copiar `/etc/default/caddy` del master.

## [1.0.250] — 2026-10-01 — config_mirror valida el Caddyfile con el entorno real de Caddy

### Arreglado
- **`config_mirror` rechazaba el Caddyfile del master con "API token '' appears invalid".** `caddy validate` se lanzaba sin el entorno del servicio caddy, así que `{env.CLOUDFLARE_API_TOKEN}` llegaba vacío aunque el nodo tuviera el token en `/etc/default/caddy`. Ahora la validación carga las variables que systemd da a Caddy (`EnvironmentFile=` y `Environment=`). Se pasan por el entorno del proceso, no por la línea de órdenes, para que el token no aparezca en `ps`.
- Si el Caddyfile usa una variable `{env.…}` que el Caddy del slave no tiene, se valida el resto con un valor de relleno y se avisa: tras un relevo, esas webs no podrían renovar certificados. Para `CLOUDFLARE_API_TOKEN`, el aviso indica cómo propagarlo desde el master.

## [1.0.249] — 2026-10-01 — config_mirror copia el Caddyfile aunque las webs escriban logs en su carpeta

### Arreglado
- **`config_mirror` no podía copiar el Caddyfile cuando una web escribe su log en la carpeta `logs/` del hosting.** En el slave, esa carpeta la crea el panel a nombre del hosting, y lsyncd no la copia. Caddy no puede escribir en ella y `caddy validate` falla con "permission denied" (visto en Filemon con musedock.com). Ahora, para la validación, esos logs se apuntan a `/tmp`. Al aplicar, se da permiso de escritura a `caddy` con ACL sobre esa carpeta, sin cambiar el dueño. Si la carpeta no existe, se crea a nombre de `caddy`. Solo se toca `/var/www/vhosts/<hosting>/logs/` y `/var/log/caddy/`.

## [1.0.248] — 2026-09-30 — "Igualar módulos" de Caddy funciona en un Ubuntu 22.04 recién instalado

### Arreglado
- **La compilación de módulos DNS de Caddy fallaba con "No se pudo instalar/encontrar xcaddy"** en nodos con Ubuntu 22.04 (visto en Filemon). El Go que trae apt es el 1.18, demasiado viejo para instalar xcaddy y para compilar Caddy 2.10 o posterior. Ahora, si Go falta o es anterior a 1.21, el panel instala el Go oficial de go.dev en `/usr/local/go`. El Go de apt no se toca. La compilación usa `GOTOOLCHAIN=auto`, así que Go descarga solo la versión que pida Caddy. Si `go install xcaddy` falla, se descarga el binario publicado de xcaddy en GitHub. Las variables `HOME`/`GOPATH` se fijan explícitamente, porque la compilación corre en segundo plano y fuera del entorno del servicio.

## [1.0.247] — 2026-09-30 — config_mirror no copia un pool de PHP que chocaría con otro del slave

### Arreglado
- **`config_mirror` podía dejar PHP-FPM sin arrancar en el slave.** Si el slave ya tenía un pool para el mismo hosting con **otro nombre de fichero** (el panel del slave crea el suyo al sincronizar el hosting; por ejemplo `musedock.conf` en el master y otro nombre en Filemon), copiar el del master dejaba dos pools con el mismo `[nombre]` o el mismo socket. `php-fpm -t` no lo detecta, pero PHP-FPM no puede arrancar. Ahora ese pool **no se copia**: se avisa de con qué fichero choca, para decidir cuál debe quedar. Probado con los pools reales de mortadelo.

## [1.0.246] — 2026-09-30 — config_mirror en slaves unidos por el método antiguo

### Arreglado
- **`config_mirror` no funcionaba en un slave unido por el método antiguo** («No hay ningún nodo master registrado», visto en Filemon). En esos clusters solo el master tiene registrado al slave; el slave conoce a su master únicamente por la IP de la que le llegan los latidos. Ahora, si no hay master registrado, el slave llama a esa IP (VPN) con su **propio token de cluster**, que el master ya acepta porque lo tiene guardado como el token de ese nodo. No hace falta volver a emparejar.

## [1.0.245] — 2026-09-30 — Apps fuera de /var/www: carpetas extra en la copia de ficheros y servicios systemd en config_mirror

### Añadido
- **Carpetas extra en la copia de ficheros (`filesync_extra_paths`, MCP, en el master).** Además de `/var/www/vhosts`, lsyncd copia carpetas de apps que viven fuera de los hostings (por ejemplo `/opt/musemind-trading`, `/opt/musedock-portal`, `/opt/musedock-license` en mortadelo).
  - Solo van a los **nodos elegidos** (normalmente el de relevo), porque la copia es en espejo y otro nodo podría tener sus propias cosas en esas rutas.
  - Solo se admiten carpetas existentes bajo `/opt`, `/srv` o `/home`, y **nunca** `/opt/musedock-panel` (cada nodo tiene su panel).
  - El plan muestra el tamaño de cada carpeta y las rechazadas.
- **`config_mirror` copia también los servicios systemd propios del master** (`/etc/systemd/system/*.service` que no sean del panel ni de snap).
  - En el slave quedan **parados y deshabilitados**.
  - Se verifica que existan el ejecutable de `ExecStart`, la carpeta de trabajo y el usuario; si aún no están (por ejemplo, porque la copia de `/opt` no ha llegado), se omiten y se reintenta cada 5 minutos.
  - **Al promover** se habilitan y arrancan los que estaban habilitados en el master; al degradar o aislar se paran.
  - No se toca una unidad propia del slave con el mismo nombre, y lo que el master deja de tener se aparta (`.removed-by-mirror`).

## [1.0.244] — 2026-09-30 — Botón «Entendido» en el aviso del firewall

### Añadido
- **El aviso del firewall del dashboard se puede cerrar con «Entendido»** cuando solo hay avisos (amarillo). Se da por visto el conjunto actual de avisos: si luego aparece un problema distinto, el aviso vuelve. Los **críticos (rojo) no se pueden cerrar**.

### Mejorado
- El aviso de reglas de iptables repetidas ya no da por hecho que las carguen ufw y netfilter-persistent: en Filemon no está netfilter-persistent y la repetición venía de haber añadido dos veces la misma regla a ufw.

## [1.0.243] — 2026-09-30 — Panel inaccesible con 421 en servidores donde el 443 y el 8444 comparten servidor de Caddy

### Arreglado
- **El panel respondía 421 en Filemon** (y en cualquier servidor donde el 443 y el puerto del panel comparten el mismo servidor de Caddy). Lo introdujo la 1.0.232: la ruta del dominio del panel en el 443 (`panel-domain-https-route`) solo miraba el dominio y, si estaba delante de la ruta del panel, **atrapaba también las peticiones al 8444** y respondía 421.
  - Ahora solo se aplica a lo que llega **de verdad por el 443**: comprueba el puerto local de la conexión (`{http.request.local.port} == 443`), no el que dice la cabecera `Host`. Da igual el orden de las rutas.
  - **`https://dominio-del-panel/` sin puerto daba 421 en vez de redirigir al panel** (el puerto de la cabecera llega vacío). Ahora un puerto vacío cuenta como 443 y redirige (308).
  - Probado contra un Caddy real con el 443 y el 8444 en el mismo servidor y la ruta del 443 delante: 443 sin puerto → 308; 443 con `:8444` (HTTP/3 de Chrome) → 421; 8444 → panel. El reparador sustituye solo las rutas de las versiones anteriores.
- **El dashboard mostraba en todos los nodos el aviso «ufw está ACTIVO junto a reglas iptables propias».** No es un problema en sí, porque la auditoría ya simula el resultado final de las reglas. Ahora solo es un aviso si hay **reglas duplicadas** (ufw y netfilter-persistent cargando lo mismo al arrancar); si no, es una nota informativa que no sale en el dashboard.
- **Aviso del firewall en el dashboard:** la fecha de revisión y el texto de ayuda eran grises sobre amarillo y apenas se leían; ahora el texto es oscuro (o blanco en el aviso rojo).

## [1.0.242] — 2026-09-30 — Ajustes tras probar en asterisk y obelix

### Mejorado
- **`config_mirror` enseña qué cambiaría del Caddyfile** antes de aplicar: líneas que se añaden y que se quitan en las webs (máximo 30 de cada), con los hashes de `basic_auth` y los tokens largos ocultos. En obelix la simulación marcaba el Caddyfile como «actualizado» sin poder ver por qué.
- **`firewall_audit`**: el puerto de WireGuard del kernel (que no tiene proceso de usuario) aparece como «WireGuard» en vez de «(desconocido)».

### Arreglado
- **`hosting_php_settings` devolvía valores vacíos** para cuentas sin pool propio (muserelay.com usa el pool general `www.conf`). Ahora lo dice claramente en lugar de mostrar un pool inexistente.

## [1.0.241] — 2026-09-30 — El slave copia la configuración del sistema del master + límites de PHP por MCP

### Añadido
- **Copia de la configuración del sistema del master a un slave (`config_mirror`, MCP, en el slave).** Genérica, para cualquier pareja master/slave. Lo que ya se replicaba (BD, Redis, ficheros de `/var/www/vhosts`, hostings) se completa con lo que vive fuera de esas carpetas y hace falta para un relevo:
  - **Supervisor:** los programas se copian con `autostart=false`, recordando su valor original. Se verifica que existan el ejecutable, la carpeta y el usuario.
  - **Cron:**
    - las tareas de los crontabs van a un **bloque propio** (`# >>> musedock-mirror`), desactivadas con `#MUSEDOCK-OFF#` y **sin duplicar** lo que el slave ya tenga (activo o desactivado); lo demás del crontab del slave no se toca;
    - los ficheros de `/etc/cron.d` se copian como `<nombre>.musedock-off`, que cron ignora;
    - se valida la sintaxis.
  - **Caddyfile:** se toman las webs del master y se conservan las opciones globales y el bloque del panel del slave. Se valida con `caddy validate` y **no se recarga Caddy**, porque un reload desde el Caddyfile quitaría las rutas del panel: se aplica al promover.
  - **Pools de PHP-FPM:** solo de usuarios que existan en el slave. Se comprueban con `php-fpm -t` y, si fallan, vuelven a como estaban.

  Lo que no pasa la verificación se omite y avisa (como mucho una notificación por hora). **Nunca borra**: lo que el master ya no tiene se aparta como `.removed-by-mirror`, y solo toca lo que copió él. Hay copias de seguridad en `/var/backups/musedock-mirror/`. Se ejecuta cada 5 minutos en el cluster-worker si está activada (`enable=true`); sin `apply`, muestra lo que haría. Nueva acción del cluster `export-system-config` en el master.
- **Al promover, el panel enciende lo copiado:**
  - supervisor, con su `autostart` original, y arranca los programas;
  - las tareas cron;
  - Caddy se reinicia si su Caddyfile cambió, y el reparador repone las rutas del panel.

  Al degradar o al aislarse como master caducado, lo apaga. Siempre antes de los scripts `promote.d` y `demote.d`.
- **`hosting_php_settings`** (MCP): consulta o cambia `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time` y `max_input_vars` del pool de una cuenta, lo mismo que Cuentas → Editar → PHP. Comprueba PHP-FPM (`php-fpm -t`) antes de recargar y, si falla, lo deja como estaba (la web también, ahora con el mismo código).
- `failover_preflight` avisa en un slave si la copia de configuración no está activada o si la última tuvo avisos.
- **Auditoría del firewall (`firewall_audit`, MCP) y aviso en el dashboard.** No mira reglas sueltas (un `ACCEPT all` puede ser solo de `lo`/`wg0` o un agujero): **simula** la llegada de una conexión nueva a cada puerto en escucha (TCP y UDP), desde una IP cualquiera de internet y desde cada origen autorizado, siguiendo las cadenas propias de iptables. Indica por puerto: abierto a todo internet, solo desde ciertas IPs, o cerrado. Avisa de:
  - servicios sensibles expuestos (PostgreSQL, MySQL, Redis, panel, API de Caddy o Docker…);
  - **IPv6 sin proteger**;
  - reglas que aceptan todo;
  - orígenes con acceso a todos los puertos que no son de confianza;
  - puertos de **Docker** (no pasan por INPUT, solo por DOCKER-USER);
  - **ufw** activo mezclado con iptables propio.

  Las reglas con condiciones que no se simulan (`limit`, `recent`…) cuentan como abiertas, lo que va del lado seguro. Son de confianza los nodos del cluster, los servidores de failover, `ALLOWED_IPS`, la VPN y la lista manual (**`firewall_trusted_sources`**, MCP, que solo cambia la lista y nunca el firewall). El cluster-worker la repite cada 15 minutos, y el **dashboard** muestra un aviso rojo (crítico) o amarillo (avisos) con los principales problemas. Con `node` se audita otro nodo.

### Arreglado
- **Los límites de PHP de algunas cuentas no se podían ver ni cambiar desde el panel.** El panel daba por hecho que el pool se llama `{usuario}.conf`, pero hay cuentas cuyo pool tiene otro nombre: por ejemplo, musedock.com usa `musedock.conf`. La pantalla de edición mostraba valores por defecto (2M) en vez de los reales (10M), y guardar daba «No se encontró el archivo de pool FPM». Ahora se busca el pool cuyo `user =` es el de la cuenta.
- **`cluster_drift` daba una falsa diferencia de hostings** («master 2, nodo 3» entre asterisk y obelix). En el master contaba los hostings de la BD; en el nodo, los dominios del panel **incluidos los alias** (vocal9.es). Ahora compara el mismo indicador en los dos lados (hostings + alias).

## [1.0.240] — 2026-09-30 — Vigilante de diferencias entre el master y sus nodos

### Añadido
- **`cluster_drift`** (MCP, lectura, en el master): compara este servidor con cada nodo (o con uno) en lo que importa para que un relevo funcione:
  - programas de supervisor (comando, usuario, carpeta, procesos);
  - unidades systemd propias;
  - tareas cron (crontabs y `/etc/cron.d`);
  - webs del Caddyfile fuera del panel;
  - versiones y extensiones de PHP;
  - versión mayor de Node, Composer y paquetes relevantes (`php8.x-*`, nodejs, redis, postgresql, supervisor, imagemagick, ffmpeg, chromium…);
  - número de hostings del panel.

  Devuelve en llano **lo que falta o es distinto en el nodo** (hay que arreglarlo), **lo que solo existe en el nodo** (informativo) y **notas** (por ejemplo, paquetes npm globales, que suelen ser herramientas de administración). Es **genérico, para cualquier pareja master/slave**. Que en un slave los programas estén con `autostart=false` o los crons desactivados es lo normal y no cuenta como diferencia.
- Nueva acción del cluster **`clone-inventory`** (autenticada, solo lectura): el master pide el inventario completo del nodo por la API del cluster, sin depender de que el nodo tenga el MCP activado y sin el límite de 60 KB de la salida MCP.

### Mejorado
- El inventario reconoce las tareas cron **desactivadas a propósito** en un slave (líneas `#MUSEDOCK-OFF#` y ficheros `.disabled` de `/etc/cron.d`) y las marca con `disabled: true` en vez de ignorarlas.

## [1.0.238] — 2026-09-30 — MCP: sincronización de ficheros entre nodos

### Añadido
- **`filesync_configure`** (MCP, escritura, en el master): activa la copia de `/var/www/vhosts` a los nodos web del cluster, lo mismo que desde Ajustes → Cluster → Ficheros. Pasos:
  1. clave SSH de root del master (se crea si falta);
  2. se instala en cada nodo por la API del cluster;
  3. **se comprueba el SSH antes de cambiar nada**;
  4. se instala lsyncd si hace falta;
  5. se guarda la configuración y se arranca.

  Detalles:
  - en modo `lsyncd`, cada cambio llega al nodo a los ~15 s; también hay modo `periodic`;
  - es un **espejo**: la primera pasada borra en el nodo lo que no exista en el master, salvo exclusiones, y el plan lo avisa;
  - las opciones `mirror_git` y `mirror_node_modules` (activadas por defecto) copian también `.git` y `node_modules`, que el panel excluye por defecto, para que un slave sea un clon exacto de apps como muserelay;
  - los logs, las sesiones y las cachés siguen excluidos.
- **`filesync_status`** (MCP, lectura): si la copia está activa, el modo, los nodos destino, las exclusiones efectivas y el estado de lsyncd (salud, problemas y últimas líneas de su log).
- Las instrucciones del MCP explican el orden: emparejar → `cluster_sync_hostings` → `filesync_configure`, con la norma de que ninguna herramienta borra datos por decisión propia.

## [1.0.237] — 2026-09-30 — Sincronizar hostings: el slave ya no crea rutas de Caddy que el master no tiene

### Arreglado
- **Al sincronizar, el slave podía crear rutas de Caddy para dominios que el master NO sirve** (visto con vocal9.com/vocal9.es en obelix). El panel de asterisk conservaba el identificador de una ruta antigua (`caddy_route_id`) de un hosting con el pool de PHP desactivado. La adopción de la 1.0.235 se fiaba de ese dato, y además la sincronización de dominios alias reconstruía la ruta del hosting en cualquier caso (`rebuildCaddyRouteWithAliases`).
  - Ahora el master envía si **de verdad** sirve el dominio en ese momento (`caddy_served`, comprobado en la configuración viva de Caddy).
  - El slave solo crea o reconstruye rutas si el master lo sirve, y solo si en el slave no lo sirve ya algo que no es del panel (por ejemplo, un bloque fijo del Caddyfile de un slave clonado, como muserelay en obelix); si la ruta es del panel, sí se reconstruye.
  - Los alias se registran igual en la BD del slave.
  - Con un master de versión anterior se mantiene el comportamiento antiguo.

## [1.0.236] — 2026-09-30 — MCP: sincronizar hostings y ver la cola del cluster

### Añadido
- **`cluster_sync_hostings`** (MCP, escritura, en el master): lo mismo que el botón «Sincronizar Todo» de un nodo.
  - Encola el alta de cada hosting (el slave lo crea o, desde la 1.0.235, lo **adopta** si ya tiene el usuario con el mismo UID y su carpeta), junto con sus alias/redirecciones y sus bases de datos registradas.
  - Admite limitarlo a **un solo dominio**.
  - **Nunca borra nada en el nodo.** Siguiendo la norma del MCP, las herramientas pueden crear, actualizar y sincronizar, pero no borrar.
- **`cluster_queue`** (MCP, lectura): estado de la cola de operaciones hacia los nodos (pendientes, completadas, fallidas, canceladas) y las últimas operaciones con su nodo, acción, dominio, intentos y error. Para ver por qué algo no llega a un slave. No muestra el contenido de las operaciones.

### Cambiado
- La lógica de «Sincronizar Todo» pasa a `ClusterService::enqueueFullHostingSync()`, que usan el botón y el MCP: el mismo código por los dos caminos.

## [1.0.235] — 2026-09-30 — Failover seguro: el master que vuelve se aísla, margen de 5 min y el slave adopta los hostings

### Añadido
- **Un master que vuelve tras un relevo se aísla solo (anti split-brain).** Caso: el proveedor reinicia el master o este se cae más de lo que tolera el failover, el slave se promueve y, al volver, el antiguo master arranca **creyéndose master**. Los visitantes con el DNS viejo en caché escribirían en él y el resto en el nuevo master: dos masters y datos que divergen.
  - Ahora el antiguo master pregunta a los nodos del cluster. Si alguno es master y **se promovió después que él**, se aísla:
    - sus bases de datos de clientes pasan a **solo lectura** (la del panel no);
    - se ejecutan los scripts de relevo `demote.d` (parar la app, soltar la IP flotante...);
    - te avisa.
  - **El panel sigue accesible**, a diferencia de `fenceSelf`, que para Caddy entero. El rol no se cambia solo: devolverlo como slave es un paso aparte (`demoteToSlave`), que además quita el aislamiento.
  - Se comprueba **al arrancar el servidor, antes que Caddy y supervisor** (servicio `musedock-stale-master-check`, que instala `update.sh`, con prefijo `-` y límite de 90 s: nunca bloquea el arranque), y después **cada minuto** en el cluster-worker.
  - Si no llega a ningún nodo, no decide nada y lo reintenta.
  - Los nodos informan de su fecha de promoción (`cluster_promoted_at`) en `query-local-state`.
- **El slave adopta los hostings que ya tiene en vez de crearlos.** Si al sincronizar un hosting el slave ya tiene el usuario del sistema **con el mismo UID** y su carpeta (un slave clonado con rsync, como obelix), solo lo registra en su panel:
  - no crea usuario ni pool de PHP;
  - no cambia el dueño de los ficheros;
  - no toca Caddy si el dominio ya se sirve (solo añade la ruta si el master la tiene y en el slave nadie sirve el dominio).

  Así «Sincronizar Todo» es seguro en un slave clonado, y el panel del slave muestra sus hostings.

### Cambiado
- **Margen del failover por defecto: 5 comprobaciones fallidas seguidas (~5 minutos), antes 3.** Un reinicio normal del proveedor (1–3 min) ya no provoca un relevo. Si en un servidor se había guardado otro valor, se respeta.

### Arreglado
- **La reconciliación del cluster-worker nunca veía el estado del slave.** Al reconectar un slave, leía `$stateResp['state']`, cuando `callNode` devuelve el cuerpo en `data`. No detectaba que un slave hubiera cambiado el DNS por su cuenta mientras el master estaba caído.

### Nota para los scripts de relevo (`demote.d`)
- Al arrancar, la comprobación de master caducado se ejecuta **antes** que supervisor. Un script `demote.d` que pare la app debe también impedir que supervisor la vuelva a arrancar (por ejemplo, `autostart=false` o parar supervisor), no solo `supervisorctl stop`.

## [1.0.234] — 2026-09-30 — Cluster: paneles que se bloqueaban entre sí, IP del master y cola de fallidas

Visto al emparejar asterisk (master) y obelix (slave): «nodo caído», panel muy lento y 48 operaciones fallidas en pocos minutos.

### Arreglado
- **Dos paneles de un cluster se bloqueaban entre sí y se marcaban como caídos.** El panel atendía las peticiones de una en una (`php -S` con un solo proceso). Cuando el dashboard del master consultaba al slave justo mientras el slave le enviaba su latido, **cada uno esperaba al otro** hasta el tiempo límite: consultas de 25 s, latidos perdidos y «nodo caído». Ahora el servicio arranca con `PHP_CLI_SERVER_WORKERS=4` y atiende 4 peticiones a la vez. Probado: con una petición lenta en marcha, otra rápida pasa de esperar 3,7 s a 0,01 s. `update.sh` regenera el servicio en cada actualización.
- **El latido sobrescribía la «IP del master» con la IP equivocada.** Todo panel que recibía un latido guardaba la IP de quien lo enviaba como `cluster_master_ip`. Pasaban dos cosas:
  - el master, al recibir los latidos de su slave, apuntaba al slave como «su master»;
  - en el slave, la IP pública del master que había guardado el emparejamiento se cambiaba por la de la VPN. El failover compara esa IP con las **IPs públicas** de `failover_servers`, así que nunca habría detectado la caída.

  Ahora un master no guarda nada al recibir latidos, y una IP pública ya configurada no se cambia por una privada. Además, cuando el master envía la configuración de failover, el slave toma como IP del master la del servidor **primario** de esa configuración. La IP de la VPN desde la que llegan los latidos se guarda aparte (`cluster_master_heartbeat_ip`). Esa es la que usan la réplica de correo y la base de datos de correo del slave, que van por la VPN. En los clusters existentes, que solo tenían la IP de la VPN, no cambia nada.
- **La cola del cluster se llenaba de operaciones fallidas cada minuto.** Si el slave no tenía los mismos hostings que el master (por ejemplo, clonado por debajo del panel), cada latido detectaba la diferencia y encolaba una operación por hosting, que fallaba con «Hosting X not found on slave». Ahora ese reenvío se hace como mucho una vez cada 30 minutos por nodo.
- `replication_adopt` mostraba `[REDACTED]` en el plan en lugar de decir de dónde se lee la contraseña: el filtro de secretos tapaba el campo por llamarse `password`.

## [1.0.233] — 2026-09-30 — MCP: las acciones con `apply=true` no se ejecutaban

### Arreglado
- **Ninguna herramienta MCP de escritura se ejecutaba con `apply=true`**: respondían «Nodo '' no encontrado». La etiqueta de auditoría (`local`) recibía «, APPLY» y después se usaba esa misma etiqueta para decidir si la llamada era local. Al no ser ya exactamente `local`, se intentaba reenviar a un nodo vacío. Afectaba a todas las herramientas de escritura, también a las de correo de la 1.0.224, que nunca se habían llegado a aplicar en real. No se aplicaba nada: fallaba antes. Ahora la decisión local/remoto va aparte de la etiqueta de auditoría. Visto al emparejar asterisk y obelix por MCP.

## [1.0.232] — 2026-09-30 — Panel en blanco con Chrome en servidores con webs en el 443

### Arreglado
- **El actualizador generaba un Caddyfile inválido y volvía a poner el anterior**: «subject does not qualify for certificate: '}'» en Filemon y «unrecognized directive: www.muserelay.com» en obelix. Lo introdujo la 1.0.231: al añadir `servers :8444 { protocols h1 h2 }` dentro de las opciones globales, estas pasaron a tener llaves anidadas, y el extractor de `update.sh` daba por terminado ese bloque en la **primera** `}` suelta. La `}` real quedaba colgando. Además, un bloque del panel con las etiquetas repartidas en varias líneas no se cerraba nunca. La validación como usuario `caddy` de la 1.0.231 impidió que se aplicara, así que no se rompió nada.
  - El extractor se ha reescrito contando llaves de verdad en los dos bloques. Tiene en cuenta etiquetas en varias líneas, comentarios con llaves y cualquier etiqueta `:PUERTO` del nivel superior.
  - Tampoco arrastra ya las líneas en blanco del principio (antes el Caddyfile crecía una línea en cada actualización).
  - Probado con cuatro Caddyfiles (el de Filemon, el de obelix con muserelay, el formato antiguo con comentarios y un sitio `:8444`) y con el real de mortadelo: todos válidos según `caddy adapt`, sin perder ningún sitio, y dos pasadas seguidas dan exactamente el mismo fichero.
- **El panel salía en blanco en Chrome en cuanto el servidor tenía webs en el 443** (visto en obelix al clonar muserelay). Las webs del 443 anuncian HTTP/3 (`alt-svc`), y Chrome reutilizaba esa conexión QUIC del 443 para pedir el panel del `:8444`. La petición llegaba al servidor del 443 diciendo «puerto 8444»; la ruta del dominio del panel solo contemplaba el puerto 443, así que no había nada que la atendiera y Caddy respondía vacío.
- Ahora esa ruta (`panel-domain-https-route`) responde **421 Misdirected Request** a lo que llega al 443 con otro puerto. Ese es el mecanismo estándar de HTTP para decirle al navegador «esta conexión no es la correcta», y Chrome reintenta por una conexión nueva al 8444. Lo que llega con puerto 443 sigue redirigiéndose (308) al panel.
- **No se sirve el panel por el 443**, a propósito: el 8444 está limitado por el firewall a IPs concretas, y el 443 está abierto a todo internet.
- El reparador de Caddy sustituye automáticamente la ruta antigua por la nueva. Probado contra un Caddy real.

## [1.0.231] — 2026-09-30 — Unir dos paneles desde el MCP, sin secretos en el chat

### Añadido
- **Emparejamiento de paneles por MCP**, como vincular un dispositivo: ningún token pasa por el chat ni por el usuario.
  1. En el master, `cluster_pairing_open` abre durante **30 minutos** la recepción de solicitudes.
  2. En el futuro slave, `cluster_pair_request` envía al master, **por TLS y de panel a panel**, su token de cluster, y devuelve un **código** `XXXX-XXXX`.
  3. En el master, `cluster_pair_approve` con ese código:
     - comprueba que llega a la API del slave con su token;
     - lo registra como nodo;
     - le envía el token del master junto con un nonce que solo conocen los dos.

     El slave comprueba el nonce, y además que le responde el mismo master al que se lo pidió; entonces se registra como slave. `cluster_pair_pending` muestra las solicitudes, sin tokens.
- **Protecciones del endpoint público `POST /api/pair/request`**, el único sin token:
  - responde **404** mientras la ventana está cerrada;
  - admite como mucho 5 solicitudes cada 10 minutos por IP (la real, no la de Caddy) y 5 pendientes;
  - sigue sujeto a `ALLOWED_IPS`;
  - **recibir una solicitud no da acceso a nada**: unirse exige aprobarla en el master con acceso de escritura.
- El token del master y el nonce nunca se escriben en `panel_log`, que se replica.

### Arreglado
- **Panel en blanco en el navegador (visto en obelix).** El navegador entra al 8444 por HTTP/3 (QUIC). Tras cambios de configuración de Caddy en caliente, el oyente QUIC podía quedarse con rutas viejas y devolver respuestas vacías, mientras HTTP/1.1 y HTTP/2 funcionaban. Ahora el Caddyfile generado declara `servers :8444 { protocols h1 h2 }`: el puerto del panel ya no ofrece HTTP/3 y el navegador usa HTTP/2. Las webs del 443 no cambian.
- **`update.sh` rechazaba un Caddyfile correcto y volvía a poner el anterior** («no PEM block found», visto en Filemon). Validaba con `caddy validate` como **root**, que usa la CA interna de root (`/root/.local/share/caddy/pki`), y en Filemon esa CA estaba dañada: ficheros con su tamaño normal pero llenos de ceros. El Caddy real funciona como el usuario `caddy`, con su propio almacén. Ahora la validación se hace como el usuario `caddy` y con su almacén, igual que el servicio; si ese usuario no existe, sigue validando como root.

## [1.0.230] — 2026-09-30 — NOVEDAD: el MCP ya gestiona cluster y failover (+ 3 fallos críticos del relevo corregidos)

**Primer paso hacia montar un slave completo desde Claude, ChatGPT o VS Code.** El MCP deja de ser solo de consulta en la parte de cluster: ya puede diagnosticar un relevo, registrar una réplica existente, configurar el failover y asignar servicios a los nodos. Todo con el mismo protocolo que el correo: primero el plan, y solo se aplica con tu confirmación y con «Permitir acciones que modifican» activado.

### Añadido: herramientas MCP de cluster y failover (`app/Mcp/McpClusterTools.php`)
- **`failover_preflight`** (lectura) dice en lenguaje llano **qué falta para que un relevo funcione**. Revisa:
  - rol del servidor, nodos y testigos disponibles (anti split-brain);
  - servidores de failover y modo;
  - cuentas de Cloudflare con sus zonas (sin tokens);
  - estado de las réplicas de PostgreSQL y de Redis;
  - **simulación** de qué se promovería;
  - salud de los servidores vigilados.

  Se ejecuta en el master **y** en el slave.
- **`replication_adopt`** registra en el panel una réplica de PostgreSQL que **ya funciona**, montada a mano, **sin tocar datos ni reiniciar nada**:
  - detecta el rol, el usuario de réplica y el otro extremo;
  - guarda la contraseña **cifrada**, leída de `~postgres/.pgpass` o de un fichero bajo `/root/`, sin devolverla nunca;
  - sin esto, promover y degradar no saben con qué credenciales trabajar.
- **`failover_configure`** (en el master) define este servidor como primario y un nodo como servidor de relevo, con sus **IPs públicas**, y el modo `manual`/`semiauto`/`auto`. Luego lo propaga a los slaves. Antes de aplicar:
  - valida que las IPs sean públicas y que la primaria sea de este servidor;
  - avisa si no hay testigo;
  - pide `replace=true` para sustituir una configuración existente.
- **`cluster_node_services`** (en el master) indica si un nodo es web, mail o ambos.

### Añadido: Redis en el relevo
- **Al promover un slave, Redis también se promueve.** Si Redis era réplica del master, pasa a principal (`REPLICAOF NO ONE`) y se **persiste** con `CONFIG REWRITE`: si no, el siguiente reinicio lo volvería a hacer réplica del master caído. La contraseña se lee de `redis.conf` y va por el entorno, nunca en la línea de comandos.

### Añadido: scripts de relevo (hooks)
- **El panel ejecuta los scripts que root deje en `/etc/musedock/hooks/promote.d/` al promover y en `demote.d/` al degradar.** Sirven para lo que el panel no gestiona: mover una IP flotante, arrancar programas de supervisor, activar crons o republicar en Caddy los dominios de una app. Funcionan como `run-parts` y `cron.d`:
  - solo se ejecutan ficheros de **root**, ejecutables y **sin permiso de escritura para grupo ni otros**, en directorios que cumplan lo mismo;
  - se ejecutan en orden alfabético, con un **límite de 120 s** cada uno;
  - reciben `MUSEDOCK_EVENT` y, según el caso, `MUSEDOCK_OLD_MASTER_IP` o `MUSEDOCK_NEW_MASTER_IP`;
  - al promover van **después** de promover PostgreSQL y Redis, para que las apps arranquen ya con escritura;
  - un fallo se informa, pero no deshace el relevo;
  - su salida no se escribe en `panel_log`, que se replica: allí solo va el código de salida.
- `failover_preflight` lista los scripts y avisa de los que **no** se ejecutarían, y por qué.

### Arreglado (fallos que habrían hecho fallar un relevo real)
- **Un slave con varias IPs podía no promoverse nunca.** La elección comparaba solo la **primera** IP de `hostname -I` con las de `failover_servers`. En un servidor con IP pública y de red local en la misma interfaz (obelix), si salía primero la local, perdía siempre la elección. Ahora se compara con todas las IPs del servidor.
- **Configurar el failover en el master podía borrar la cuenta de Cloudflare del slave.** Al propagar la configuración, el master enviaba su lista de cuentas de Cloudflare y el slave la **sustituía**. Si el master no tenía ninguna (asterisk), el slave se quedaba sin token y, en un relevo, no habría podido cambiar el DNS. Ahora una lista vacía del master no borra las cuentas propias del slave.
- **Degradar un servidor a slave intentaba reconstruir también la base de datos del panel.**
  - **PostgreSQL:** `demoteToSlave` recorría todos los clusters de PostgreSQL, incluido el del panel. Entre asterisk (16) y obelix (14) solo lo evitaba una comprobación de versión; con la misma versión, habría borrado la base del panel para copiar la del otro nodo. Ahora el cluster del panel se omite siempre.
  - **MySQL:** se intentaba reconstruir aunque nunca hubiera estado en réplica. Ahora se omite si no forma parte de la réplica.

### Inventario MCP
- **Un PostgreSQL en réplica salía sin detalles en `clone_inventory`.** Su estado es `online,recovery` y el detector solo inspeccionaba los clusters `online`.
- En cada cluster se listan los **slots de réplica**. En una réplica se muestra además a quién sigue (estado, host, puerto y slot) y el **retraso de aplicación** en segundos.

### Arreglado
- **`clone_inventory` no mostraba la versión de Composer.** Ejecutado como root, Composer se para a preguntar «Continue as root?» y la consulta volvía vacía. Ahora se lanza con `COMPOSER_ALLOW_SUPERUSER=1` y sin entrada estándar.

## [1.0.228] — 2026-09-29 — Correcciones del inventario MCP tras probarlo en asterisk

### Arreglado
- **La vista completa (`clone_inventory` sin `section`) se truncaba a 60 KB** y se perdían `network` y `caddy`. Ahora, en esa vista, la lista de paquetes apt y las extensiones de PHP salen resumidas en un recuento; completas con `section=runtime`. Los workers idénticos (Horizon, Octane...) se agrupan en una sola fila con `count` y `pids`.
- **El puerto de Octane salía con el programa de supervisor vacío.** El servidor swoole que abre el puerto no hereda el entorno de supervisor. Ahora se sube por los procesos padre hasta encontrarlo; el campo `via_parent_pid` indica de qué proceso salió el dato.
- **El checklist decía «10 variables» del `.env` pero solo nombraba 8.** Ahora las nombra todas.

## [1.0.227] — 2026-09-29 — MCP: inventario completo para clonar un servidor en un slave exacto

### Mejorado
- **`clone_inventory` rehecho** (nueva clase `app/Mcp/McpInventory.php`) para el futuro asistente «Añadir slave». Nuevo argumento `section` (`sites`, `processes`, `services`, `apps`, `databases`, `cron`, `runtime`, `network`, `caddy` o `all`): así se puede pedir una sola parte y comparar dos servidores sección a sección.
- **Quién arranca cada proceso**: supervisor (por `SUPERVISOR_PROCESS_NAME`), PM2, unidad systemd o cron (por el cgroup), o **«manual»** si vive en una sesión SSH, lo que significa que no sobrevive a un reinicio. Aplica a los puertos en escucha y a los procesos de aplicación (Node, PHP fuera de FPM como artisan/Octane/Reverb, Python).
- **Servicios**: programas de supervisor (comando, directorio, usuario, `numprocs`, solo los *nombres* de sus variables de entorno) y su estado; drop-ins de `/etc/systemd/system/*.d`; servicios en marcha y fallidos. Las unidades `snap.*` ya no cuentan como propias.
- **Apps propias**: se descubren por el Caddyfile, los procesos, supervisor, systemd y cron. De cada una se muestra:
  - el tipo (Laravel, Node, PHP o Python);
  - el estado de git (remoto sin credenciales, rama, commit y cambios sin commit);
  - lo que **no está en git** y no llegaría con un `git clone` (`vendor`, `public/build`, `storage/app`...);
  - composer y `package.json`;
  - del `.env`, **solo los nombres** de las variables que apuntan a este servidor (IPs propias, localhost, hostname o rutas locales), nunca sus valores.
- **Bases de datos**:
  - PostgreSQL con versión principal, direcciones de `listen_addresses` **configuradas que no están escuchando** (el caso de asterisk, cuando PostgreSQL arranca antes que WireGuard), reglas remotas de `pg_hba`, réplicas y ajustes WAL.
  - MySQL/MariaDB a través de `debian.cnf`.
  - Redis con rol, réplicas, bind, si tiene contraseña (sí/no), persistencia y claves por BD.
- **Crons**: el contenido de `/etc/cron.d` y de los crontabs de usuario, con los secretos enmascarados (`-p`, `--password`, `PGPASSWORD=`, credenciales en URL...). También se detecta el scheduler de Laravel.
- **Runtime**: extensiones de PHP por versión, Node, npm y paquetes globales, Composer, versión de Caddy, fuentes apt y **paquetes apt instalados a mano**. Se leen directamente de `dpkg`/`extended_states`, porque `apt-mark` y `npm ls` tardaban 1 s cada uno y el panel es monohilo.
- **Red**: IPs, WireGuard (endpoints, allowed-ips y antigüedad del handshake, sin claves), firewall (política de INPUT y puertos abiertos a cualquiera) y **ficheros de `/etc` que citan IPs de este servidor**.
- **Caddy**: opciones globales, almacén y lista de certificados, y módulos no estándar (p. ej. `dns.providers.cloudflare`, que obliga a usar el mismo binario en el slave).
- **Checklist en lenguaje llano** con lo que el asistente tendría que resolver: procesos arrancados a mano, puertos en todas las interfaces (y si el firewall los deja abiertos), servicios fallidos, unidades y programas de supervisor que hay que copiar, sitios del Caddyfile fuera del panel, `.env` que dependen del servidor, cambios sin commit, `listen` de PostgreSQL sin aplicar, Redis sin réplica, crons.

### Arreglado
- MySQL aparecía sin bases de datos, porque no se usaba `debian.cnf`.
- El puerto del panel (8445) se atribuía al proceso `ss`: el hijo que lanza el panel hereda sus sockets. Ahora se busca el dueño real.
- Los comodines IPv4 e IPv6 del mismo puerto salían duplicados.

## [1.0.226] — 2026-09-29 — Hotfix de 1.0.225: el reparador de Caddy fallaba al arrancar

- **`Undefined constant "MuseDockPanel\Services\apps"` en `SystemService.php:2269`** (visto en obelix). En tres líneas de `ensureCaddyHttpServerReady()` se habían perdido las comillas de las rutas (`apps/http/servers/srv0` y `…/srv0/listen`) al generar el código de 1.0.225. `php -l` no lo detectaba, porque sin comillas es PHP sintácticamente válido (constantes y divisiones), así que el fallo solo aparecía al ejecutarse. Con el prefijo `-` de 1.0.225 Caddy ya no se caía, pero el reparador no completaba y `srv0` no quedaba escuchando en `:443`. Corregido y comprobado **ejecutando** la función contra un Caddy real, no solo con `php -l`. Además, un escaneo por *tokens* de todos los ficheros tocados en 1.0.224–1.0.225 confirma que no queda ninguna otra cadena sin comillas: sobre la 1.0.225 publicada detecta exactamente estas tres líneas.
- **El reparador se ejecutaba como usuario `caddy`**: `ExecStartPost` hereda `User=caddy` del servicio, así que no podía leer `/opt/musedock-panel/.env` (root, 600) y arrancaba sin credenciales de la BD ni ajustes (aviso `Permission denied` en `Env.php`). El drop-in pasa a `ExecStartPost=-+…`: el `+` ejecuta solo esa orden como root y el `-` sigue impidiendo que un fallo del reparador tumbe Caddy.

## [1.0.225] — 2026-09-29 — CRÍTICO: el panel borraba configuración de Caddy con PATCH (causa real del incidente de agosto)

**Actualiza todos los nodos.** Con 1.0.224, obelix se quedó sin Caddy al arrancar: el reparador borró todas las rutas de `srv0`, abortó, y systemd mató Caddy.

### Causa real: `PATCH` en Caddy SUSTITUYE, no fusiona

Comprobado contra una instancia real de Caddy:

| Llamada del panel | Efecto real |
|---|---|
| `PATCH srv0 {"listen":[…]}` | `srv0` se queda **sin rutas** (todas las webs fuera) |
| `PATCH servers {"srv_panel":{…}}` | **se borran todos los demás servers** |
| `PATCH tls/automation {"policies":[…]}` | se pierde el resto de ajustes TLS |
| `PATCH apps {"tls":{…}}` | se borran **todas las apps** |
| `GET` de una ruta inexistente | responde `200` con cuerpo `null`, no `404` |

- **Corrige lo que creíamos en agosto.** El incidente del 6 de agosto no fue un «null transitorio» tras parchear el `listen`: **el propio `PATCH srv0 {listen}` borraba las rutas**. El comentario del código lo decía («routes vacío tras parchear srv0») sin entenderlo. La protección añadida entonces (no vaciar si había rutas antes) evitó el borrado silencioso, pero dejó el reparador abortando sin revertir, y con `ExecStartPost` systemd tumbaba Caddy.
- **Solo se disparaba en nodos donde el `listen` de `srv0` no incluía ya `:443`** (obelix). En mortadelo y asterisk no se llegaba a ejecutar.
- Las comprobaciones de «si es 404, créalo» nunca se cumplían, porque Caddy no devuelve 404.

### Arreglado

- **Escritura segura en la API de Caddy** (`SystemService`): nuevos `caddySetLeaf()`, `caddyCreatePath()` y `caddyPathExists()`. Se escribe **solo la hoja exacta**; si no existe, se crea con `POST` colgándola del antepasado existente más profundo, sin tocar a sus hermanas; y si ya tiene el mismo valor, no se escribe. **Se eliminan los 10 `PATCH` sobre objetos contenedores**: el `listen` de `srv0`, la cadena de creación de `srv0` (servers/http/apps), la creación y normalización del server del panel, y las políticas TLS (ahora solo `tls/automation/policies`).
- **El reparador ya no puede dejar el servidor peor ni tumbar Caddy**:
  - si falla la preparación de `srv0` después de haber tocado Caddy y se perdieron hosts, **revierte a la foto inicial** antes de salir (antes salía con `exit(1)` sin revertir);
  - el drop-in de systemd usa `ExecStartPost=-…`: un fallo del reparador ya no marca el arranque de Caddy como fallido.
- Probado contra un Caddy real: el `listen` cambia sin perder rutas, crear el server del panel conserva los demás, las políticas TLS no pierden otros ajustes, y si falta la app TLS se crea sin tocar `http`.

## [1.0.224] — 2026-09-29 — Servidor MCP (fase 1), Mail general 29× más rápida, fixes de correo

### Añadido: servidor MCP (Model Context Protocol), fase 1 — solo lectura

Permite que un asistente de IA (Claude Code, VS Code, ChatGPT…) consulte el panel con herramientas tipadas en vez de comandos a mano. **Apagado por defecto** en cada nodo.

- **Dos transportes**: HTTP en `/api/mcp` (token Bearer; respuestas JSON sin streaming, porque el panel corre en `php -S` monohilo) y **stdio por SSH** (`bin/mcp-stdio.php`, autenticado con la clave SSH, no expone nada nuevo).
- **11 herramientas de solo lectura**: `panel_info`, `list_nodes`, `node_status`, `services_status`, `failover_status`, `hosting_accounts`, `mail_domains`, `mail_domain`, `tls_check` (handshake SNI real: sujeto, SANs, días restantes), `caddy_hosts` y **`clone_inventory`** (todo lo que habría que clonar para un slave exacto, marcando lo que el panel **no** gestiona: sitios del Caddyfile fuera del panel, unidades systemd propias, Redis, procesos Node/PM2, Docker, bases de datos, crons, puertos). Todas aceptan `node` para ejecutarse en otro nodo del cluster vía la API del cluster (acción `mcp-call`); el nodo destino debe tener también su MCP activado.
- **Seguridad**: desactivado → `404`; token obligatorio guardado solo como SHA-256 y mostrado una vez; tokens erróneos escritos en `/var/log/musedock-panel-auth.log` (el jail fail2ban `musedock-panel` los banea: 5 fallos → 1 h); rate limit por IP; rechazo de peticiones con `Origin` (anti DNS-rebinding, exigido por la especificación MCP); `ALLOWED_IPS` del `.env` se aplica antes. Todas las salidas pasan por un filtro de secretos (contraseñas, hashes, claves, tokens) y cada llamada queda en el log de actividad (sin argumentos sensibles: `panel_log` se replica a todos los nodos).
- **Ajustes → MCP**: activar/desactivar, generar/regenerar/revocar token, y la configuración lista para copiar para VS Code (`mcp.json`, por SSH o HTTP) y Claude Code.
- Protocolo MCP 2025-06-18 (compatible con 2025-03-26 y 2024-11-05), JSON-RPC 2.0 sin dependencias.

### Arreglado: cuentas de Cloudflare con más de 50 zonas (afectaba al failover DNS)

- **El panel solo veía las 50 primeras zonas de cada cuenta de Cloudflare.** `listZones()` devuelve una sola página de la API (50 zonas), y se usaba como si fuera la lista completa en cuatro sitios: `refreshZones()` (lista guardada que usan el **failover DNS** y el publicador de DNS de correo), las dos rutas de alta/verificación de cuentas en `FailoverController` y la página **Ajustes → Cloudflare DNS**. La cuenta «Screen Art FIlms» tiene **83 zonas**: **33 dominios** (entre ellos screenart.es y screenartfilms.es) eran invisibles para el panel, así que **en un failover no se habrían repuntado al slave**. Nuevo `CloudflareService::listAllZones()` que recorre todas las páginas (tope 2.000 zonas; si falla a mitad devuelve lo leído y lo avisa), usado en los cuatro sitios. Aplicado ya en mortadelo: 50 → 83 zonas, tokens intactos (el guardado no re-cifra tokens ya cifrados). **`bin/update.sh` refresca la lista automáticamente en cada actualización** (solo si el nodo tiene cuentas de Cloudflare; muestra `Cloudflare zones refreshed (antes->después)`), así que los slaves quedan corregidos al actualizar sin pasos manuales. En el próximo arranque/actualización, las políticas TLS por cuenta incluirán también esas 33 zonas (comportamiento correcto: DNS-01 con el token de su cuenta).

### Añadido: MCP — paquete de correo (acciones que modifican, con candado)

«Créame un dominio solo de correo, configúralo en Cloudflare y crea las cuentas» con una frase. Cinco herramientas nuevas (`app/Mcp/McpMailTools.php`), que **reutilizan el código del panel**, de modo que el DKIM, la carpeta del buzón y la réplica a los nodos de correo ocurren igual que desde la web:

- `mail_domain_create`: alta del dominio con su DKIM.
- `mail_dns_publish`: publica en Cloudflare MX, SPF, DKIM y DMARC. Es nuevo: el panel no tenía publicador de DNS de correo.
- `mail_mailbox_create` y `mail_alias_create`: buzones, alias y catch-all.
- `mail_domain_verify` (solo lectura): comprueba el DNS desde 1.1.1.1 y 8.8.8.8, compara la **clave DKIM completa** con la del panel y ejecuta `opendkim-testkey`.

Candados:
- **Interruptor aparte** «Permitir acciones que modifican» (`mcp_allow_write`), apagado por defecto.
- **Plan antes de actuar**: sin `apply: true` solo devuelven el plan, y se anuncian como no-solo-lectura, así que el cliente MCP pide permiso en cada llamada.
- **Nunca se reenvían a otros nodos** (ni por el argumento `node` ni por la acción de cluster `mcp-call`) ni se ejecutan en un slave.
- **Contraseñas**: si no se indica una, se genera y se guarda **cifrada** en *Ajustes → MCP → Credenciales pendientes* (caducan a los 7 días). Nunca vuelve al chat.

DNS con cabeza:
- **SPF: sumar, nunca sustituir.** Si el SPF existente ya autoriza a este servidor se respeta tal cual; si no, se añade `mx ip4:…` antes del `all` sin quitar a nadie. Así no se retira el permiso a otros remitentes, como asterisk o sweego en muserelay.com.
- **MX: nunca dos proveedores.** Si hay MX de otro proveedor (incluido el Enrutamiento de correo de Cloudflare, que detecta y explica), no añade el propio salvo con `replace_conflicts`. Aun así, solo lo añade si todos los MX ajenos se pudieron borrar.
- Probado en solo lectura contra los datos reales: musedock.com y muserelay.com, todo `ok`; screenart.es, verificación completa `✓` y `key OK`.

### Correo: rendimiento, fixes y mejoras de interfaz

- **Mail → general cargaba en ~3,3 s; ahora en ~0,15 s.** Medido como root: `getDeliveryLogStats()` se llevaba **3,13 s** (el 95 %) porque en **cada visita** releía las últimas 20.000 líneas de `mail.log` (decenas de MB) y las volcaba a `mail_relay_events`, antes de contar enviados/diferidos/rebotes. Ahora la página **solo lee de BD** y la importación pasa a un cron en segundo plano (`bin/mail-log-ingest.php`, cada minuto, `nice -n 10`, con `flock`), que además es **incremental**: recuerda hasta qué byte leyó (`storage/cache/mail-log-ingest.json`, local al nodo) y solo procesa lo nuevo; ante rotación del log, primera ejecución o hueco > 20 MB vuelve a las últimas 20.000 líneas. Lee con 256 KB de solape para no perder el remitente de colas partidas; las líneas repetidas se descartan por `ON CONFLICT (line_hash)`. La página del log de relay deja también de importar al cargar. `update.sh` instala el cron (`/etc/cron.d/musedock-mail-log`) en todos los nodos.

- **Fix: editar un buzón daba error 500 y no guardaba nada** (ni la contraseña). Al guardar con el autorespondedor desmarcado se enviaba `false` a la columna booleana `autoresponder_enabled`; PDO manda `false` como cadena vacía `""` y PostgreSQL la rechaza (*invalid input syntax for type boolean*). Corregido **en el origen** (`Database::query`): `false` se envía como `"0"`, simétrico con `true` → `"1"`, válido tanto en columnas boolean como integer. Arregla de paso el mismo fallo latente en `can_send` de la política de envío y en cualquier otra escritura con un booleano `false`.
- **Editar buzón: doble campo de contraseña** («Nueva» + «Repetir»), cada uno con **botón de ojo** para mostrar/ocultar. Validación en vivo (mínimo 8 caracteres y que coincidan) que bloquea el envío, y comprobación también en el servidor (`hash_equals`) para no fijar una contraseña con una errata.

- **Confirmación con modal al borrar** dominio, buzón o alias (modal oscuro del panel, botón rojo «Eliminar», el foco por defecto en «Cancelar»). Antes, **borrar un alias no pedía confirmación** (un clic y se borraba) y dominio/buzón usaban el `confirm()` nativo. El modal dice exactamente qué se borra y sus consecuencias; si es el **catch-all**, avisa de que los correos a direcciones inexistentes dejarán de llegar. Si SweetAlert no cargase, cae al `confirm()` nativo: nunca se borra sin preguntar. El texto del modal va doble-escapado (sin riesgo de XSS con nombres de alias o buzones).

- **Mail → dominio**: la tarjeta **DNS Records** pasa de una columna estrecha a la derecha a **ancho completo debajo** de la ficha del dominio. Los nombres ya no se parten letra a letra («scree / nart. / es»): van en una línea y en monoespaciado. El valor ocupa el ancho restante y muestra hasta 160 caracteres antes de recortar; el botón de copiar sigue copiando el valor **completo**.

## [1.0.223] — 2026-09-29 — Fix: el regenerador de Caddyfile de update.sh producía un fichero inválido

- **`bin/update.sh` regeneraba un Caddyfile inválido y restauraba el anterior** (aviso «Generated Caddyfile failed validation… server block without any key is global configuration, and if used, it must be first»). Causa: el `awk` que extrae los sitios existentes detectaba el bloque global de opciones con `NR<=5`, pero el Caddyfile tiene una cabecera de comentarios que empuja el `{` global a la línea 6 → no lo eliminaba → lo re-pegaba **después** del bloque del panel como un bloque sin etiqueta y no-primero → fallaba la validación. Ahora el bloque global se detecta como **el primer bloque `{` sin etiqueta, sea cual sea la línea** (`past_global`), robusto ante cabeceras de comentarios. Impacto previo bajo (Caddy conservaba su config al restaurar), pero la regeneración del bloque TLS del panel quedaba inutilizada. Verificado: el Caddyfile generado ahora **adapta a JSON sin errores**.

## [1.0.222] — 2026-09-28 — Reparador de Caddy (TLS end-to-end + solo arranque), OPcache JIT off, y cert de correo

### Reparador de Caddy: verificación TLS end-to-end, idempotencia de políticas y solo en arranque

Segundo incidente del hook de reparación: el 14-sep, tras el update a 1.0.220, el TLS de `muserelay.com` dejó de servir certificado **dos veces** (el vigilante recargó desde disco en ~6s, impacto nulo, pero la causa seguía). No era «srv0 sin rutas» como en agosto, sino **«no peer certificate»**: la ruta existía pero el handshake TLS no devolvía cert. Causa: `patchTlsPolicies()` hacía **`DELETE` de TODAS las políticas TLS + rebuild desde cero** en cada ejecución (incluida cada recarga vía `ExecReload`); con inputs transitoriamente incompletos (proveedor DNS/token CF no detectado ese instante) el rebuild dejaba un host sin política aplicable.

- **Idempotencia + reemplazo atómico de políticas TLS**: `patchTlsPolicies()` ahora **no toca nada si las políticas actuales ya equivalen a las nuevas**, y cuando cambian usa un único PATCH atómico en vez de `DELETE` + rebuild (se elimina la ventana en la que no existía ninguna política y el handshake fallaba).
- **Verificación TLS end-to-end con revert** (`cli/repair-caddy-routes.php`): el reparador captura, con un handshake SNI local, **qué hosts sirven certificado ANTES**; al terminar comprueba que **cada uno siga sirviéndolo**. Si algún host que servía cert deja de hacerlo (o se pierde de la config), **revierte** al snapshot previo (`POST /load`), avisa y sale con error. Ya no basta con que la ruta exista: tiene que **resolver certificado de verdad**. Complementa el guard de pérdida de rutas de agosto.
- **El reparador ya solo corre en ARRANQUE, no en cada recarga**: se quitó `ExecReload` del drop-in `zz-musedock-panel-repair.conf` (queda solo `ExecStartPost`). Que el panel se ejecutara tras CADA `reload` de Caddy le daba autoridad permanente sobre la config de apps de terceros que conviven en el server. El `caddy reload` nativo (desde disco) sigue intacto; y al actualizar el panel, `update.sh` ejecuta el reparador directamente.
- **Log de constancia**: cada ejecución del reparador queda registrada en `LogService` (`caddy.repair` → `ok`/`reverted`/`revert_failed`) con hosts antes/después y certs verificados, para poder auditar desde el panel qué tocó y por qué.

### OPcache JIT desactivado en PHP-FPM (fix 502 intermitente de WordPress)

Los sitios **WordPress** en php8.3 devolvían **502** de forma intermitente: el worker de PHP-FPM **segfaulteaba al instante** (sin dejar log), mientras las apps que no son WP seguían a 200. Causa: el **JIT de OPcache** (`opcache.jit=1255`), que compila a código máquina y tiene segfaults conocidos con bases dinámicas grandes como WordPress; un auto-update de WP disparó un miscompile persistente hasta recargar FPM.

- **`bin/update.sh` desactiva el JIT en todos los PHP-FPM instalados** (drop-in propio `99-musedock-opcache.ini`: `opcache.jit=disable` + `opcache.jit_buffer_size=0`) y reinicia el FPM afectado. Idempotente. OPcache normal sigue activo (rendimiento intacto); el JIT no aporta en cargas web (I/O-bound) y era la única fuente del crash. Así ningún nodo nuevo ni pool futuro vuelve a nacer con el JIT activo.

### Renovación del certificado del correo: propagación robusta a Postfix/Dovecot

El servidor de correo no tiene certbot propio: reutiliza el wildcard `*.musedock.com` que **Caddy renueva solo**, copiándolo a `/etc/mail-certs/`. Había una incoherencia de rutas que podía dejar el correo apuntando a un cert desincronizado tras una renovación.

### Arreglado

- **Propagación del cert renovado a Postfix/Dovecot**: `MailService::ensureMailCertViaCaddy()` ahora escribe siempre el par **estable** `/etc/mail-certs/mail.crt` + `mail.key` como canónico (el que consumen tanto los nodos legacy como los actuales) y mantiene las copias `{hostname}.crt/.key` por compatibilidad. El script de sincronización que instala (`/usr/local/bin/musedock-mail-cert-sync.sh`, cron `17 */6 * * *`) refresca **ambos** pares en cada renovación de Caddy y recarga Postfix+Dovecot. Antes, config y copia podían apuntar a rutas distintas y el correo se quedaba con el cert viejo.

### Añadido

- **`cli/repair-mail-cert-sync.php`**: repara la propagación en nodos de correo con Caddy — obtiene el cert vigente, apunta `smtpd_tls_cert_file`/`key_file` de Postfix y reescribe `/etc/dovecot/conf.d/10-ssl.conf` a la ruta estable, y recarga. Se **salta** los nodos sin Caddy (gestionados por certbot), que no se tocan.
- **Hook en `bin/update.sh`**: tras cada actualización del panel se ejecuta ese repair (best-effort), de modo que los nodos de correo que venían de versiones antiguas quedan corregidos automáticamente al actualizar.

## [1.0.219] — 2026-08-18 — Red de seguridad del reparador de Caddy (incidente web caída 9 días)

Tras un incidente en un servidor con panel + app Laravel compartiendo Caddy: el hook de reparación (`repair-caddy-routes.php`, ejecutado por systemd tras cada arranque/recarga de Caddy) dejó la config **activa** reducida al host del panel, tirando la web principal (y con ella los webhooks de Meta) durante **9 días** de forma invisible.

### Causa raíz probable: TOCTOU en `ensureCaddyHttpServerReady()` que vacía srv0

- **El bug**: la función parchea el `listen` de `srv0` (`PATCH`) y **acto seguido lee `srv0/routes`**. Ese `PATCH` puede hacer que la lectura inmediata devuelva `null`/vacío de forma **transitoria** (el propio comentario del código lo reconocía: «routes endpoint returns null/empty after srv0 listen patching»). El código interpretaba ese vacío como «la clave de rutas no existe» y hacía `PUT []`, **borrando todas las rutas reales**; después se re-añadía solo la ruta del panel → `srv0` con un único host, el del panel. Es un TOCTOU clásico: comprobar «está vacío» y usar «puedo vaciarlo sin perder nada» no son atómicos. Reproduce exactamente el estado final del incidente **sin** necesidad de ningún `PUT` con payload del panel.
- **El fix** (3 recomendaciones del reporte): (1) **releer con reintentos** — hasta 3 intentos separados 0,5 s; si las rutas reaparecen, era un `null` transitorio y no se toca nada; (2) **testigo previo** — se cuenta cuántas rutas tenía `srv0` **antes** del `PATCH`; si había N>0 y ahora se lee vacío tras los reintentos, es una **lectura perdida, no un estado vacío** → se **aborta** (no se vacía); (3) **nunca vaciar un server compartido** — `srv0` (webs de terceros) solo se inicializa a `[]` cuando se confirma genuinamente vacío (no había rutas antes **y** persiste vacío). El `PUT []` sobre `srv_panel_admin` (server aislado del panel) sigue siendo seguro por diseño.
- **Corrección de la auditoría previa**: la conclusión «el código actual está limpio, no hay que tocar nada más» era correcta para el patrón buscado (no hay `PUT` mayorista con payload parcial), pero **se le escapó el camino del vaciado**: bastaba con vaciar y re-añadir solo la ruta del panel. El atributo de líneas 1111/1130/1147 del reporte original (que apuntaban a config de logging, no a rutas) sí era incorrecto y la auditoría lo corrigió bien.

### Guard anti-pérdida de hosts en `repair-caddy-routes.php` (peticiones #2 y #4 del reporte)

- **Verificación antes/después + reversión automática**: el reparador ahora **fotografía la config completa y el conjunto de hosts servidos ANTES** de tocar nada (unión de `match.host` de todos los servers, no solo srv0). Al terminar, **recaptura**; si **desapareció cualquier host** que estaba antes, **revierte** a la instantánea previa vía `POST /load` (recarga interna de Caddy, sin re-disparar el hook → sin recursión) y sale con código ≠ 0. Cubre también el caso #4 (quedarse como único host en `:443`): es un subcaso de «perdió hosts». Validado en producción (165 hosts detectados correctamente en srv0-srv3).
- **Alerta (el núcleo del incidente: nadie se enteró en 9 días)**: al detectar la pérdida, registra en `LogService` (`caddy.repair` → `reverted`/`revert_failed`) y envía notificación por `NotificationService`, tanto si la reversión funcionó como si no (en cuyo caso indica ejecutar `systemctl reload caddy`).

### Estado de las otras peticiones

- **#1 (no usar PUT sobre objetos compartidos)**: cubierto en las escrituras de rutas (ruta del panel con `@id` propio: `deleteRouteById` + `POST`-append; `listen` con `PATCH`; prepend con `PUT /routes/0` que **inserta**, no reemplaza). El único `PUT` mayorista peligroso restante era el `PUT []` del camino de vaciado — corregido arriba con el fix anti-TOCTOU.
- **#3 (ExecReload del drop-in)**: la red de seguridad anterior **neutraliza el riesgo** (cualquier recarga que perdiera hosts se revierte). **Decisión: se mantiene `ExecReload`** — con el guard, una recarga de un tercero ya no puede romper otros sitios, y el hook sigue siendo necesario para que el panel reponga sus rutas runtime (dominio del panel, webmail) tras cada `systemctl reload caddy`.

## [1.0.211 – 1.0.217] — 2026-07-29 — Failover de BBDD de clientes: auditoría, endurecimiento e incidente Proxmox

Sesión centrada en el failover de bases de datos de clientes (PostgreSQL + MariaDB) con modelo de **un solo escritor**: auditoría de seguridad del módulo, endurecimiento, un incidente real de corrupción de disco en un nodo Proxmox resuelto **sin pérdida de datos de clientes** (mortadelo, el master, tenía todo; los nodos locales son esclavos), y el arreglo de varios bugs de replicación que llevaban meses ocultos.

### Bugs de replicación de redirecciones (destapados en producción)

- **Fallo real corregido**: las redirecciones **standalone** (dominio → URL, sin cuenta de hosting; `hosting_account_id = NULL`) **nunca llegaban a los nodos slave**. Al crearlas no se encolaba nada, y «Sincronizar Todo» solo recorría `hosting_accounts` (las standalone no están ahí). Las redirecciones *attached* a un hosting sí se replicaban; solo las standalone quedaban solo en el master. Ahora: (a) crear/borrar una standalone la propaga a los nodos web (`sync_standalone_redirect`/`remove_standalone_redirect`, con handler en el slave que crea/borra la ruta Caddy + upsert del registro), y (b) «Sincronizar Todo» las incluye. **Fix de arrastre**: faltaba `use MuseDockPanel\Settings;` en `DomainController` (habría dado fatal al replicar).
- **«Sincronizar Todo» limpia los items muertos**: al re-provisionar un nodo, los items de la cola `failed` con reintentos agotados se marcan `cancelled` (ya no reflejan la realidad), de modo que el banner de drift del dashboard se aclara tras una sincronización correcta en vez de mostrar fallos antiguos para siempre.
- **La «Sincronización Completa» (fullsync) también se completó**: ese botón (distinto de «Sincronizar Todo») solo encolaba `create_hosting` — **no sincronizaba ni los aliases/redirects attached ni los standalone**. Ahora `bin/fullsync-run.php` incluye ambos (aliases attached vía `sync_domain_aliases`, standalone vía `sync_standalone_redirect`) y la limpieza de items muertos, igual que «Sincronizar Todo». Los dos caminos quedan equivalentes en cuanto a hostings + redirecciones.

### Compilación de módulos Caddy: asíncrona (FPM mata a 120 s) + modales bonitos

- **«Igualar módulos» daba «Operation timed out after 30000 ms»**: la llamada al nodo tenía timeout de 30 s, pero xcaddy compila en 2–5 min. Peor: `request_terminate_timeout=120` en PHP-FPM mataba la petición **en ambos nodos** aunque se subiera el timeout. Rediseñado a **asíncrono**: el nodo lanza el build detached (`setsid nohup bin/caddy-build-run.php`) y responde al instante con un `task_id`; el master **hace polling** (`caddy-sync-status` → `caddy-install-status`) sin bloquear ninguna petición. El build sobrevive al límite del FPM.
- **Modales bonitos**: la tarjeta de módulos Caddy usaba `alert()`/`confirm()` nativos del navegador. Ahora usa **SweetAlert** (como el resto del panel): plan de compilación con confirmación, spinner «Compilando…» mientras xcaddy trabaja, y resultado final con icono.

### Modos de failover afinados: semiauto ejecuta el forward pero NUNCA el failback

- **semiauto** pasa de «solo notificar» a **ejecutar el forward automáticamente** cuando cae el master (repunta DNS al slave de mayor prioridad + lo promociona, con el guard de quórum), para que los sitios sigan online sin intervención. **El failback sigue siendo MANUAL** en semiauto: el sistema **nunca** revierte solo un slave promovido de vuelta a slave cuando el master regresa — evita el flapping en recuperaciones inestables (como la del incidente Proxmox); el admin confirma el «Revertir Failover» cuando decide. **auto** sigue haciendo ambos (forward + failback) automáticamente; **manual** no ejecuta acciones (pero los emails de nodo/master caído se envían igual, vía `cluster-worker`, en todos los modos).

### Fix falsa alarma «Master caído» cuando quien cayó fue el propio slave

- El worker del slave enviaba «ALERTA: Master caído» basándose solo en la **antigüedad del último heartbeat recibido**. Pero un heartbeat viejo puede significar «el master cayó» **o** «yo (el slave) estuve offline y no pude recibirlo». Cuando los nodos locales se reiniciaron por un corte de luz (failover de Proxmox), al recuperarse veían el heartbeat viejo y mandaban un email de «Master caído» **aunque el master (en la nube) nunca se cayó** — el guard existente (panel local caído) no lo cazaba porque el slave ya se había recuperado. Ahora, antes de alertar, el slave **sondea activamente al master** (TCP al puerto del panel + ping); si responde, **suprime la falsa alarma**. También se suprime si el slave arrancó hace menos que el timeout (reinicio propio reciente).

### Fix CSRF: «Igualar módulos» (Caddy) y setup de réplica de correo fallaban con «Token CSRF inválido»

- Dos bugs en el JS de `Settings → Cluster`: (1) el token se enviaba como `csrf_token` (sin underscore) cuando `View::verifyCsrf()` lee `_csrf_token`; (2) el valor se leía de `input[name="csrf_token"]`, que **no existe** (el input real es `_csrf_token`), así que iba vacío. Afectaba a «Igualar módulos» (Caddy DNS) y al setup de replicación de correo. Corregidos ambos → las acciones ya validan el CSRF.

### UX: falso «Error» en la Sincronización Completa con hostings grandes + robustez rsync

- **Falso «Error durante la sincronización»**: el endpoint `sync-progress` marca como zombi cualquier sync cuyo fichero de progreso lleve >180 s sin actualizarse. Pero durante el rsync de **un hosting grande** (p. ej. 2.3 GB → ~8 min) el progreso solo se actualizaba *entre* hostings, así que a los 3 min se declaraba «Error» aunque el rsync estaba transfiriendo correctamente. Ahora `rsyncHosting` acepta un **heartbeat** (vía `proc_open`) que refresca el fichero de progreso cada ~10 s durante la transferencia, y `fullsync-run.php` lo usa. El sync ya no da falsos errores en hostings grandes.
- **Robustez rsync**: se añadió `--timeout=300` — si una transferencia se estanca de verdad (caída de red), rsync aborta en 5 min en vez de colgarse indefinidamente y el bucle continúa con el resto.

### Elección inteligente del nodo de failover + documentación del panel

- **Desempate inteligente de la elección cuando las prioridades no están diferenciadas**: `FailoverService::getFailoverServersByPriority()` ordena los servidores de failover por: (1) **prioridad configurada** (forzada, `1` = máxima) si la pusiste; (2) a igualdad (o si no pusiste números), el **nodo más completo** (web + mail gana a solo-web, vía `nodeCompletenessMap()`); (3) a igualdad, el **menor ID** (determinista, no aleatorio). Así, si configuraste la lista pero olvidaste los números de prioridad, la elección sigue siendo sensata en vez de arbitraria. Lo consume `shouldPromote()`, así que aplica en `semiauto` y `auto`. **Alcance real (importante):** esto solo decide *QUIÉN* promociona; el *repunte de DNS* (a qué IP apuntar los dominios) necesita la **IP pública** de cada nodo y el mapeo de zonas Cloudflare, datos que **solo** viven en `failover_servers`. Los nodos del cluster únicamente conocen su IP WireGuard privada (`10.10.70.x`), inservible como destino DNS público. Por eso **la lista `failover_servers` con IPs públicas es imprescindible**: el `failover-worker` se salta la actuación automática si está vacía. El fallback derivado de los nodos es una última red para la *elección*, no un sustituto de configurar el failover de tráfico.
- **Emails de caída independientes del modo (confirmado)**: los avisos de «Nodo caído» (master → slave, `cluster-worker` Step 3) y «Master caído» (slave → master, Step 3b, con supresión de falsa alarma) **se envían en cualquier modo, incluido `manual`, caiga quien caiga**. El `failover_mode` solo gobierna si el sistema *ejecuta* el failover (`failover-worker`), no si *avisa* (`cluster-worker`).
- **Guía de clientes de correo ampliada** (`/docs/mail/ports`, «Puertos y clientes»): añadida la **regla de oro** que causa el fallo más común — el servidor IMAP/SMTP es **siempre** el hostname del panel (`mail.musedock.com`, wildcard TLS) aunque el buzón sea de otro dominio; usar `mail.<tu-dominio>` da error de certificado / «servidor bloqueado» aunque usuario y contraseña sean correctos. Añadidos también el aviso de **fail2ban** (5 fallos = 1 h de bloqueo, afecta a IMAP y envío a la vez), que **no hay POP3** (solo IMAP) y que el **cifrado TLS es obligatorio**. La tarjeta de esta guía se añadió al mapa índice de Mail (faltaba).
- **Nueva guía en Docs**: `/docs/failover-modes` («Failover: modos, prioridades e IDs») explica en claro las dos capas (datos vs. tráfico), la tabla de los tres modos con el símil del «interruptor de dos momentos» (manual = solo emails; semiauto = cambia IPs al caer pero failback manual; auto = todo), cómo Cloudflare repunta los registros A (TTL 60 s), y las reglas de elección de nodo (prioridad → completitud → ID). Registrada en `DocsController`, indexada por el buscador de docs y enlazada desde la guía base de cluster.

### Fix bug latente suspend/activate en slaves + fecha del banner en español

- **`suspend_hosting`/`activate_hosting` fallaban en el slave** (bug latente desde marzo): el handler llamaba a `SystemService::suspendAccount($username)` con **1 argumento** (necesita al menos `username`+`fpmSocket`) y leía `$result['success']` sobre un método que devuelve `void` → siempre «Too few arguments» y siempre reportaba fallo. Ahora resuelve el socket/parámetros desde el registro local del nodo y maneja el retorno `void` con try/catch. (Este era el origen de los 7 items muertos de Nitro.)
- **Banner de drift en formato español**: la fecha se muestra como `dd/mm/aaaa HH:MM` con «hace X días» en vez de `2026-03-28 08:54`.

### Aviso de desincronización de nodos en el dashboard

- **El drift de replicación ya no es silencioso**: la replicación del panel es por eventos + «Sincronizar Todo» manual, sin auto-reconciliación. Si un nodo estuvo caído o algo se creó antes de darlo de alta, los items de la cola **agotan reintentos y quedan muertos sin avisar** (se detectaron 170 items muertos, algunos desde marzo, que nadie había visto). Ahora el dashboard muestra un **banner de aviso** cuando hay operaciones de sync fallidas por nodo (cuántas, desde cuándo, ejemplo del error), con enlace directo a «Sincronizar Todo». Nuevo `ClusterService::getSyncDriftSummary()` (solo se evalúa en el master, read-only).

### Botón "Activar como Master" legacy bloqueado + flujo por-clúster en la UI

- **Incidente resuelto**: el botón «Activar como Master» de Settings→Replicación ejecutaba el legacy `activatePgMaster`, que resolvía el clúster **del panel** (5433) en vez del elegido, ponía `listen_addresses='*'` (abre a todas las interfaces) y reiniciaba el clúster del panel → **HTTP 500** (mató la conexión del panel). El clúster de clientes (5432) NO se tocó y el firewall ya limitaba 5433 a la IP de Filemon, así que no hubo exposición real. `activatePgMaster` queda **bloqueado** (devuelve error explicativo).
- **Nuevo flujo master POR-CLÚSTER en la UI** (`setupMasterCluster` → `setupPgMasterForCluster`, ya auditado como seguro): selector de clúster real + IPs de slave (validadas contra `isKnownNodeIp`), **simulación (dry-run)** que no cambia nada, y confirmación literal. Limita `listen_addresses` a loopback+WireGuard (nunca `*`), abre `pg_hba` solo para los slaves indicados, activa `wal_log_hints=on` y reinicia SOLO ese clúster. Crea/reutiliza el usuario de replicación y muestra las credenciales para configurar el slave.

### Endurecimiento de la replicación de BBDD de clientes (auditoría de seguridad)

Tras una auditoría del módulo de replicación (nunca activado en producción), se corrigieron 5 problemas críticos antes de poder activarlo con datos de clientes reales. El camino PostgreSQL ya estaba bien construido; MariaDB era una carcasa que corrompía datos.

### Fixed (críticos)

- **C1 — El slave MariaDB no copiaba los datos**: `setupMysqlSlave` sólo hacía `CHANGE MASTER` sobre el datadir existente, así que las BBDD de clientes preexistentes nunca se replicaban y el slave divergía en silencio. Además usaba `MASTER_AUTO_POSITION=1` (sintaxis de Oracle MySQL) que en MariaDB 10.6 no funciona. Ahora **siembra los datos** desde el master (`seedMysqlSlaveFromMaster`: `mysqldump --single-transaction --master-data=2 --gtid --all-databases` → import local) ANTES de arrancar la replicación, detecta el vendor real (`detectDbVendor`) y usa `MASTER_USE_GTID=slave_pos` en MariaDB. Si la siembra falla, **no arranca la replicación** (evita el slave incompleto).
- **C2 — Degradar un ex-master MariaDB corrompía datos**: como no hay `pg_rewind` en MySQL, un viejo master con escrituras divergentes aplicaba el binlog del nuevo encima → corrupción silenciosa. `demoteToSlave` ahora fuerza `setupMysqlSlave(..., seed=true)` = **reconstrucción completa** desde el nuevo master, descartando los datos divergentes (equivalente a la ruta rewind/basebackup de PG).
- **C4 — Se promocionaba un standby retrasado sin avisar**: `promotePgSlaveForCluster` promovía sin mirar el lag → se perdían las transacciones no recibidas. Ahora comprueba el lag (máx 5 s por defecto) y **bloquea** la promoción de un standby retrasado, con override explícito y registrado (`$maxLagSeconds = null`) para desastres reales.
- **C5 — `promote`/`demote` en `/api/cluster/action` reconstruían BBDD apuntando a una IP arbitraria**: un token filtrado podía wipear un nodo. Ahora se valida que `new_master_ip` sea un **nodo registrado** (`ClusterService::isKnownNodeIp`) y que el llamante sea un nodo del clúster reconocido.
- **C3 — Auto-promote sin quórum = split-brain**: en una partición de red, un slave veía el master «caído» y se auto-promovía mientras el viejo master seguía sirviendo escrituras → dos masters. Ahora, antes de auto-promocionar, consulta a **nodos testigo** (`probe-host`): si un testigo alcanza el master, **aborta** (partición, no muerte); si ninguno confirma, **aplaza** y notifica. El modo por defecto sigue siendo `manual`.

### Fixed (segunda pasada — revisión adversarial de los propios fixes)

- **La siembra MariaDB arrancaba la replicación en la posición equivocada**: se usaba `--master-data=2` (que escribe la coordenada COMENTADA, sin ejecutarla) → el slave replicaba desde una posición GTID vieja, duplicando o saltando transacciones. Corregido a **`--master-data=1`/`--source-data=1`**, que ejecuta el `CHANGE MASTER` en el import y fija la coordenada exacta; el `CHANGE MASTER` posterior con `MASTER_USE_GTID=slave_pos` la preserva.
- **El import podía quedar bloqueado por `read_only`** y no reasentaba read-only después. Ahora el import corre con `SET GLOBAL read_only=0; SET SESSION sql_log_bin=0` y **se restaura `read_only=1`** al terminar.
- **Fuga de datos de clientes**: el dump (`--all-databases`, incluye la tabla `mysql` con hashes) se dejaba en `/var/lib` sin borrar. Ahora vive en un dir temporal 0700 y **se borra siempre** (`try/finally`), en éxito y en error. Password del defaults-file con escape correcto de `\` y `"`.
- **SSRF en `probe-host`**: aceptaba IP y puerto arbitrarios → escáner de puertos vía token de nodo. Ahora solo sondea **nodos registrados** en el **puerto del panel/443**.
- **`isKnownNodeIp` con falsos positivos**: rechaza explícitamente `0.0.0.0`/loopback aunque aparezcan en el metadata de un nodo, y valida que los valores del metadata sean IPs reales.

### Notes

- `mysqldump`/`mariadb-dump` disponibles en el host; no se requiere mariabackup para la siembra lógica. Tests: `tests/replication_safety_test.php` (52 checks incluyendo redirects, drift banner y suspend/activate). Suite de replicación/failover completa: >190 checks OK.
- **Limitación conocida** (documentada, no bloqueante para modo manual): el import lógico se hace sobre el datadir vivo; un fallo a mitad puede dejarlo parcial → el error lo indica y el nodo debe reconstruirse por completo. Un import atómico requeriría snapshot de filesystem (fuera de alcance).
- **Pendiente antes de activar auto en producción**: testigo/quórum externo real (STONITH), clave de cifrado dedicada para credenciales de replicación (hoy derivada de `DB_PASS`), y política de purga de los datadirs apartados (`.old.*`/`.pre-rewind.*`). Recomendado: ensayo en staging del ciclo completo con datos de mentira.

## [1.0.208 – 1.0.210] — 2026-07-23 — Sincronización manual de nodos y fix crítico de replicación de aliases

### Fixed

- **Solo se replicaba UN alias por dominio (bug crítico de la cola)**: `ClusterService::buildQueueIdempotencyKey` construía la clave de idempotencia con `email ?? domain ?? …`, pero el payload de un alias no lleva `email` — caía en `domain`, así que **todos los aliases del mismo dominio generaban la MISMA clave** y solo el primero del lote se encolaba; el resto se descartaba como «duplicado pendiente». Efecto: por mucho que se reinstalara o resincronizara la réplica, el slave se quedaba con un único alias (aquí: `calamar@` de 4). Ahora la clave incluye `source`, de modo que cada alias se encola por separado. Afecta a `mail_upsert_alias` y `mail_delete_alias`.
- **Nodo mostrado como «DB Mail: pending / Domains: 0» pese a replicar bien**: el CA del nodo auto-bootstrapeado (`storage/tls/node-<ip>-root-ca.pem`) lo escribe quien ejecuta el bootstrap — normalmente **root** (worker/cron) — quedando `root:root 0640`. El **panel web** (usuario PHP-FPM) no podía leerlo, así que toda verificación TLS contra ese nodo fallaba con «unable to get local issuer certificate» y el health check lo pintaba como pendiente/0, aunque la cola (ejecutada por root) sí replicaba correctamente. Ahora `storeNodeCaFile` **alinea propietario/grupo** con los del directorio del panel, y un CA auto-gestionado ilegible dispara un **re-bootstrap** en vez de caer en silencio al bundle del sistema.

### Added

- **Botón «Sincronizar» por nodo (correo)** en Mail → Infra: reenvía **dominios + buzones + aliases** al slave reutilizando `MailService::resyncMailToNode` (upsert idempotente, **nunca borra** en el destino). Repara desajustes de replicación **sin reinstalar** la réplica. Modal con confirmación y recuento de lo encolado (`POST /mail/resync-node`).
- **Botón «Sincronizar contactos ahora» (CardDAV/CalDAV)**: empuja un snapshot completo al nodo elegido al margen del cron de cada minuto (`POST /mail/carddav/resync-node`).

## [1.0.203 – 1.0.207] — 2026-07-22 — Servicio integral: Contactos y calendarios (CardDAV/CalDAV) con failover

Servicio **integral** de correo: además de mensajes, ahora hay **contactos y calendarios** compartidos, con failover y sincronización con el webmail y el móvil (iPhone/Android), usando la **misma contraseña del buzón**. Servido en `dav.<dominio>` por Baïkal (SabreDAV) sobre PostgreSQL.

### Added (CardDAV/CalDAV)

- **Servidor Baïkal (SabreDAV) sobre PostgreSQL**: instalador idempotente `bin/carddav-setup-run.php` que descarga Baïkal 0.10.1, crea la BBDD `baikal` en el clúster 5433, carga el schema PgSQL (+ los índices UNIQUE que el schema no trae, necesarios para el auto-aprovisionamiento race-safe), y genera `baikal.yaml` con el formato exacto que Baïkal espera.
- **Auth contra el buzón por IMAP (una sola fuente de verdad)**: backend `resources/carddav/IMAPBasicAuth.php` que valida cada petición DAV abriendo IMAP contra Dovecot local. No se duplican ni convierten hashes de contraseña. En el primer login se **auto-aprovisiona** el principal + libreta + calendario del usuario (idempotente). Cada usuario accede **solo a su** principal (`principals/<email>`).
- **Plugin en el webmail con SSO**: `roundcube/carddav` con preset fijo (`%u`/`%p` de la sesión) → el usuario ve los mismos contactos en el webmail sin volver a autenticarse. El instalador de webmail añade `carddav` a los plugins si el servicio está instalado.
- **Ruta Caddy `dav.<dominio>`** (`CardDavService::ensureCaddyRoute`): insertada en índice 0, bloquea `Core/Specific/config`, redirige la raíz + `/.well-known/carddav|caldav` a `/dav.php/` para el autodescubrimiento del móvil. El cert lo emite la policy catch-all DNS-01 existente.
- **Failover por rol (última promoción gana)**: como los contactos los crea el usuario directamente en Baïkal (el panel no los ve), la réplica es un **push periódico** (cron `carddav-sync-worker.php`, cada minuto): el nodo que es master empuja un snapshot completo de las tablas DAV al otro nodo, que hace un **reemplazo autoritativo** en su BBDD local. Al hacer failover se **invierte la dirección** automáticamente; `promoteToMaster` empuja al instante. Acción de cluster `carddav_apply_snapshot`.
- **Réplica en el slave orquestada desde el master** (botón «Preparar réplica» en el tab Infra, con modal de progreso, como el correo): el master ordena al slave instalar Baïkal con las **mismas credenciales** (`carddav_setup_replica` → `CardDavService::nodeSetupReplica`, que lanza el mismo instalador) y le envía el primer snapshot. Sin esto el slave rechazaría los pushes (no tendría la BBDD `baikal`), y el failover no funcionaría.
- **Modales de progreso** para instalar CardDAV (tab Webmail) y preparar la réplica (tab Infra), con barra y polling del estado real (`/mail/carddav/status`, `/mail/carddav/replica-status`).
- **Privacidad**: el snapshot (PII de contactos) está en las acciones "bulk" que **no** se vuelcan al `panel_log` replicado; credenciales DAV (`db_pass`, `enc_key`) en `SECRET_KEYS`.
- Guía en el panel (`/docs/mail/contacts`) con estado real, pasos para iPhone/Android y explicación del failover. Tests `tests/carddav_test.php` (56 checks).

### Fixed (CardDAV, sobre la marcha)

- **Permisos de `baikal.yaml`**: Baïkal (usuario del pool FPM) debe poder escribir su config; el instalador ahora detecta el usuario real del pool y da escritura a `config/` y `Specific/` (antes quedaba `www-data` solo-lectura → «config/baikal.yaml is not writable»).
- **Routing `dav.php`**: la reescritura manual `/dav.php{uri}` hacía a SabreDAV calcular mal el base («Requested uri (/) is out of base uri») → ahora se sirve `dav.php`/`card.php`/`cal.php` directo y se redirige la raíz a `/dav.php/`.
- **Auth IMAP: STARTTLS→SSL**: PHP daba «SSL negotiation failed» con `/imap/tls` (143 STARTTLS); el backend usa ahora `993/imap/ssl` (con fallback a 143 plano), todo loopback. Además **caché de 30 s** de la validación (hash de user+pass, nunca la contraseña) para que las ráfagas de peticiones del cliente DAV no re-autentiquen cada vez.
- **HTML del tab Webmail**: un `<div>` sin cerrar en la tarjeta CardDAV anidaba los tabs siguientes (Dominios/Infra salían vacíos en el navegador); corregido el balance.
- **CSRF en los modales** + **contraste**: los avisos usan `alert-info` (texto legible sobre el tema) en vez de `alert-secondary`.

## [1.0.201 – 1.0.202] — 2026-07-22 — Correo del slave con copia LOCAL (failover real)

- **Correo del slave con copia LOCAL (failover real)**: antes el Postfix/Dovecot del slave leía las cuentas del 5433 del **master** por WireGuard — lo que fallaba si el master caía (el slave se quedaba sin poder autenticar). Ahora cada dominio/buzón/alias se **replica a la BBDD local de cada nodo de mail** (por la cola, como los hostings): al crear/borrar en el master se propaga a todos los nodos réplica, que hacen upsert en su propio `mail_domains`/`mail_accounts`. El slave lee de `127.0.0.1` (rol `musedock_mail` local) → **sirve correo aunque el master esté caído**. Al instalar la réplica se hace un **sync inicial** de dominios+buzones+aliases existentes. Nuevos: `MailService::replicateMailOp/upsertLocalMailDomain/upsertLocalMailAccount/nodeUpsertAlias/resyncMailToNode/markNodeAsMail/ensureLocalMailRole`.
- **Retorno tras failover (simple)**: cuando un slave se promueve a master, re-sincroniza su estado de correo a los demás nodos; al reintegrarse el viejo master, recibe los buzones creados durante la caída. Modelo «última promoción gana» (sin merge bidireccional en caliente).
- **Propagación de políticas anti-abuso al slave**: los toggles de fail2ban/rate-limit/whitelist se envían a los nodos de mail (`mail_apply_policy`, `mail_set_rate`) para que la protección sea idéntica en todos.
- Fixes de la revisión adversarial: el hash de contraseña de buzón y la clave DKIM privada ya **no se registran** en `panel_log` (que se replica); `getMailReplicaNodes` excluye el nodo local para no auto-encolar por loopback; no se crea una cuenta réplica con hash vacío. Health check del nodo: parseo correcto de `host:port` (`127.0.0.1:5433`) para no dar «could not translate host name». Tests `tests/mail_db_replication_test.php` (28 checks).

## [1.0.199 – 1.0.200] — 2026-07-22 — Webmail Roundcube: correcciones, rendimiento y failover

Webmail Roundcube funcionando (`webmail.<dominio>`) sirviendo todos los dominios. Estreno con múltiples correcciones, todas en el instalador para que los nodos nuevos nazcan bien.

### Fixed (instalación del webmail)

- **Socket PHP-FPM no detectado**: `is_file()` devuelve `false` para un socket Unix; ahora se usa `file_exists()` + `filetype()==='socket'`. Sin esto la ruta de Caddy no se publicaba y salía «Dominio no configurado».
- **Ruta Caddy tapada por el wildcard**: la ruta del webmail se añadía al final y el `*.<dominio>` la interceptaba; ahora se inserta al principio (índice 0).
- **Assets sin estilos**: Roundcube 1.7 exige docroot `public_html` y sirve `skins/program/plugins` vía `static.php`; la ruta ahora enruta `/static.php/*` con `split_path`.
- **PostgreSQL en vez de SQLite**: el webmail usa una BBDD `roundcube` en el clúster panel (replicable para HA), no un fichero SQLite local.
- **Caché rota por permisos** (crítico): el schema se cargaba como `postgres`, dejando las tablas con ese dueño; el rol `roundcube` no podía escribir en la caché y re-descargaba todo en cada carga. Ahora el rol pasa a ser **dueño** de las tablas + GRANT.
- **Autenticación caía por Sieve** (Dovecot): `sieve` estaba en el `mail_plugins` global, lo que rompía imap/auth con `undefined symbol`; ahora solo en `protocol lmtp`/`lda`.

### Performance (webmail)

- **Conexión local por `127.0.0.1`**: cuando el correo corre en la misma máquina, Roundcube conecta a `tls://127.0.0.1` (IMAP/SMTP/ManageSieve) en vez de al hostname público. Evita el rodeo por la IP pública y el timeout de IPv6 de `localhost` — la causa principal de la lentitud percibida frente a Plesk. Cert no verificado solo en estas conexiones de loopback (seguro, no salen de la máquina).
- **Caché + Redis**: `messages_cache`/`imap_cache` en la BBDD y sesiones en Redis; leer correo cacheado es casi instantáneo.

### Added

- El instalador de webmail detecta si el correo es local para elegir la conexión óptima (`webmail_mail_is_local`).

- **Correo del slave con copia LOCAL (failover real)**: antes el Postfix/Dovecot del slave leía las cuentas del 5433 del **master** por WireGuard — lo que fallaba si el master caía (el slave se quedaba sin poder autenticar). Ahora cada dominio/buzón se **replica a la BBDD local de cada nodo de mail** (por la cola, como los hostings): al crear/borrar un buzón en el master se propaga a todos los nodos réplica, que hacen upsert en su propio `mail_domains`/`mail_accounts`. El slave lee de `127.0.0.1` (rol `musedock_mail` local) → **sirve correo aunque el master esté caído**. Al instalar la réplica se hace un **sync inicial** de los buzones existentes. Nuevos: `MailService::replicateMailOp/upsertLocalMailDomain/upsertLocalMailAccount/resyncMailToNode/markNodeAsMail/ensureLocalMailRole`.
- **Retorno tras failover (simple)**: cuando un slave se promueve a master, re-sincroniza su estado de correo a los demás nodos; al reintegrarse el viejo master, recibe los buzones creados durante la caída. Modelo «última promoción gana» (sin merge bidireccional en caliente, que sería multi-master).
- Fixes de la revisión adversarial: el hash de contraseña de buzón y la clave DKIM privada ya **no se registran** en `panel_log` (que se replica); `getMailReplicaNodes` excluye el nodo local para no auto-encolar por loopback; no se crea una cuenta réplica con hash vacío.

### Notes

- El **webmail en nodos slave** (para failover) se pospone: la BBDD `roundcube` viviría en el clúster panel (5433), que en un slave con réplica en streaming es de solo lectura → `CREATE DATABASE` fallaría. Se retomará junto con la decisión de la réplica del 5433 (BBDD `roundcube` en un clúster escribible independiente). Nota: CardDAV sí lo resolvió más tarde porque su BBDD `baikal` se replica por la cola lógica (push periódico), no por streaming — el patrón que aquí faltaba.

## [1.0.194 – 1.0.198] — 2026-07-22 — Correo HA master ↔ slave (backup-replica por dsync)

Base del servicio de correo en alta disponibilidad sobre la que se construyeron el webmail, el failover local y CardDAV.

### Added (correo HA)

- **Backup-replica de correo orquestada desde el master**: botón «Preparar réplica» en Mail → Infra que instala Postfix/Dovecot/Rspamd en un nodo slave como copia viva, abre el `pg_hba` del master para `musedock_mail` desde la IP WireGuard del slave, comparte el secreto de replicación y configura **dsync** en ambos lados (`MailService::prepareMailReplicaOnNode`, `nodeSetupBackupReplica`, `finalizeBackupReplica`). Los mensajes se replican por Dovecot dsync sobre WireGuard.
- **Reconciliación «última promoción gana»**: al promover un slave a master (`ClusterService::promoteToMaster`), re-sincroniza el estado de correo a los demás nodos; documentado como modelo simple sin merge multi-master.
- **Guía «Correo HA (master + slave)»** en el panel (`/docs/mail/ha`), dinámica con hostname/IP reales, más las guías de puertos y seguridad anti-abuso (`mail-ports`, `mail-security`).
- Consolidación de los fixes del instalador de correo en `bin/mail-setup-run.php` (Sieve solo en `protocol lmtp`/`lda`, para no romper la auth IMAP con `undefined symbol`).

## [1.0.193] — 2026-07-21 — Alta disponibilidad y consistencia de nodos

Trabajo de base para el **failover master↔slave** y para que los nodos nuevos nazcan consistentes entre sí.

### Added

- **`pg_rewind` para el reingreso del antiguo master** (`ReplicationService::rewindPgClusterFrom`): cuando un master caído vuelve, se reincorpora como slave absorbiendo **solo lo que cambió** durante la caída, en vez de recopiar el clúster entero con `pg_basebackup`. Con guards de seguridad (verifica que el origen es PRIMARY, prerrequisito `wal_log_hints`/checksums leído incluso con el clúster parado vía `pg_controldata`), copia de seguridad previa con `--reflink=auto` y rollback en cada rama de fallo. Integrado en `planRebuildAsSlave`, que ahora elige `pg_rewind` o `pg_basebackup` por clúster. 28 tests.
- **Banda de UID dedicada para hostings** (`SystemService::HOSTING_UID_MIN`=20000): los usuarios de sistema de los hostings se asignan a partir de 20000, fuera del rango del SO/admin (1000-9999). Así un UID asignado en el master queda libre en los slaves y la sincronización reproduce **el mismo UID en todos los nodos**, manteniendo la propiedad de los ficheros coherente tras un failover. 10 tests.

### Fixed

- **Deriva silenciosa de UID entre nodos**: al sincronizar un hosting a un slave, si el UID del master ya estaba ocupado, el código asignaba **otro UID distinto sin avisar** (origen de los UID divergentes observados en Filemon: `musedock.com` 1000→1020, etc.). Ahora el conflicto se registra (`system.uid_conflict`, marcado como DIVERGENTE) en lugar de ocultarse, para que la reconciliación pueda verlo. Los hostings ya existentes no se tocan (siguen funcionando porque rsync remapea la propiedad por **nombre** con `--chown`, no por número).

- **Correo en modo réplica de respaldo (failover), orquestado desde el master**: desde el panel del **master** (Mail → Infra) se puede instalar el correo en un nodo slave como copia viva. El master orquesta todo por el canal cluster autenticado (TLS + token): abre su `pg_hba` para que el slave lea las cuentas por WireGuard (conexión normal de `musedock_mail`, complementa la apertura de G1), comparte el secreto de replicación **dsync** y configura **ambos** lados, y ordena al slave instalar los servicios leyendo la BBDD del master. Si el master cae, el slave ya tiene los buzones y puede servir el correo. El panel del **slave** muestra el estado (servicios / dsync / listo) e indica que la instalación se lanza desde el master. Nuevos: `MailService::prepareMailReplicaOnNode()` + `nodeSetupBackupReplica()` + `slaveMailReplicaStatus()`, acción de cluster `mail_setup_backup_replica`, endpoint `/settings/cluster/prepare-mail-replica`.

### Fixed (revisión adversarial del flujo de correo-réplica)

- **Clave de cifrado por-nodo**: un slave no podía descifrar la contraseña de correo del master (`mail_db_password_enc` se cifra con la clave local de cada nodo). Ahora el master pasa la contraseña **en claro por el canal seguro del cluster**, no vía settings replicados.
- **`pg_hba` del master**: la apertura de G1 solo añadía una línea `replication`, que no cubre una conexión normal de `musedock_mail`. Se añade una línea `host <db> musedock_mail <slaveWG>/32 scram-sha-256` al preparar la réplica.
- **dsync unilateral**: el drop-in de Dovecot no hacía `!include` del fichero de secreto (así que `doveadm_password` nunca se aplicaba) y cada lado generaba un secreto distinto. Ahora el drop-in incluye el fichero de secreto y el master comparte **el mismo** secreto a ambos extremos.
- **Fuga de credenciales en el log replicado** (2ª revisión): la acción de cluster registraba el payload completo en `panel_log` (tabla replicada a todos los nodos), incluida la contraseña de correo en claro. Se redactan ahora los campos sensibles (`master_db_pass`, `db_pass`, `dsync_secret`, tokens, etc.) antes de loguear.
- **Sin sincronización inicial de buzones** (2ª revisión): la réplica solo copiaba el correo entregado *después* del setup. Se añade una fase 2 (`finalizeBackupReplica`) que hace `doveadm sync -A` inicial para traer los buzones ya existentes del master.
- **Carrera instalación/dsync** (2ª revisión): la config de dsync se intentaba mientras la instalación de Dovecot aún corría en segundo plano. Ahora la finalización (dsync + sync inicial) se **encola** y el worker la reintenta hasta que Dovecot está listo, de forma idempotente.
- **Puerto dsync (12345)**: se abre explícitamente en el firewall, scoped al `/32` del partner por WireGuard, en vez de depender solo de la confianza implícita de la interfaz.

### Notes

- El panel replica la configuración de hostings/correo entre nodos **de forma lógica** (por API/cola `cluster_queue`), no por replicación física de PostgreSQL. Una auditoría confirmó que Filemon tiene los 25 hostings, 16 BBDD MySQL + 4 PostgreSQL y 165 certificados de Caddy — la réplica lógica funciona.
- `wal_log_hints=on` + `listen_addresses` en la WireGuard ya aplicados al clúster panel (5433) en producción, habilitando `pg_rewind` y el acceso de réplica.

## [1.0.192] — 2026-07-18

Nuevo módulo **anti-abuso de envío de correo**: controles combinables para que un buzón comprometido no pueda usarse para enviar spam masivo.

### Added

- **Políticas de envío combinables** (`Mail → Anti-spam`), todas activables por separado:
  - **Modo de envío por buzón** (heredado de un valor por defecto del dominio): `normal` (envía desde clientes y webmail), `solo webmail` (solo desde el webmail del panel; se rechaza el SMTP externo, bloqueando una contraseña robada usada por un bot) o `solo lectura` (no envía; solo recibe y lee, ideal para buzones tipo `info@`). Se aplica **en vivo** vía un lookup `smtpd_sender_login_maps` de Postfix + `reject_authenticated_sender_login_mismatch` en submission/smtps — el mismo mecanismo instantáneo y sin recarga que ya usa `status='active'`. El webmail sigue enviando porque inyecta localmente (`permit_mynetworks`).
  - **Límite de tasa de envío** por buzón (X correos/hora) vía el módulo `ratelimit` de Rspamd.
  - **Lista blanca de dominios**: interruptor maestro que exige que el dominio tenga el envío permitido.
  - **Protección de fuerza bruta (fail2ban)**: jails para autenticación fallida SMTP/IMAP (filtros `postfix-sasl` y `dovecot`), que banean IPs que reintentan.
  - Interruptor **puede enviar** por buzón (corte duro).
- Selector de política en el **editor de cada buzón** y valores por defecto en el **dominio** (los buzones nuevos los heredan).

### Notes

- Todos los interruptores nacen **desactivados**: nada cambia en el correo hasta que el operador los activa. El módulo genera la configuración de Postfix/Rspamd/fail2ban de forma idempotente y recarga solo el servicio afectado.
- Los cambios de modo/`can_send` por buzón son instantáneos (columna en BBDD que Postfix consulta en vivo); el límite de tasa y fail2ban se aplican regenerando su config.

## [1.0.191] — 2026-07-18

Primera instalación de **Correo Completo por el panel**: se pulieron todos los fallos que salieron al estrenarla, más un monitor de certificados y un badge de estado.

### Fixed

- **Rol de BBDD del correo:** la instalación fallaba con `permission denied to create role` porque el panel intentaba crear el usuario `musedock_mail` con su propio usuario de BBDD (sin `CREATEROLE`, a propósito). Ahora el rol se crea como superusuario `postgres` vía `sudo -u postgres`, idempotente.
- **Certificado vs Caddy (puerto 80):** el instalador usaba `certbot --standalone`, que exige el puerto 80 — pero Caddy ya lo tiene en un master, así que fallaba (`Could not bind TCP port 80`) y caía a un certificado auto-firmado (los clientes avisaban al conectar). Ahora, si Caddy está activo, **es Caddy quien emite el certificado** (HTTP-01/DNS-01, reutilizando la política TLS del panel y con renovación automática); `certbot` queda solo para un nodo de correo dedicado sin Caddy.
- **Certificado comodín:** Caddy suele emitir un comodín `*.dominio.com` por DNS-01, así que no existe un fichero `mail.dominio.com.crt`. El instalador lo buscaba por nombre y no lo encontraba, cayendo a auto-firmado aunque ya hubiera un certificado válido. Ahora se localiza por **SAN** (cubre el host o su comodín), y un cron re-sincroniza el certificado con el correo tras cada renovación.
- **Espera de emisión:** el DNS-01 espera la propagación del registro TXT en Cloudflare, que suele tardar más de 90s; el instalador se rendía antes de tiempo. Ampliada la espera a 150s.
- **Puertos 587/465:** `submission` se añadía como texto crudo a `master.cf` y a veces quedaba sin escuchar. Ahora se configuran `submission` (587) y `smtps` (465) con `postconf -M/-P` (método soportado e idempotente), y ambos arrancan de forma fiable.
- **No saltar el certificado auto-firmado:** al reinstalar, el paso SSL se saltaba si ya existía cualquier certificado, dejando el auto-firmado sin actualizar. Ahora detecta el auto-firmado y reintenta el real.

### Added

- **Monitor de certificados en bucle de fallo:** un dominio muerto (DNS movido o caducado) que reintenta su certificado sin parar agota el cupo de Let's Encrypt (5 fallos/hora) y puede **bloquear los certificados de todos los demás dominios y del correo** (le pasó a `gregorioevans.com`, con 1.375 fallos/hora bloqueando `mail.musedock.com`). Ahora el worker revisa cada ~30 min los fallos ACME de Caddy y **avisa al administrador por email** cuando un dominio supera 20 fallos, indicando si está agotando el cupo, si el dominio ya no resuelve, y la acción recomendada. Anti-spam: re-avisa como mucho cada 12h. Solo informa; nunca da de baja un dominio por su cuenta.
- **Badge de estado del certificado** en `Mail → Servidor de Mail Local`: verde (certificado válido), ámbar (auto-firmado o caduca en ≤10 días), rojo (caducado o inexistente), con el emisor y la fecha de caducidad en el tooltip. Así un certificado roto se ve de un vistazo, no solo en los logs.

### Notes

- En un servidor con Caddy, el certificado del correo lo gestiona **Caddy** (mismo mecanismo que las webs), con renovación automática. No hay que usar `certbot` ni abrir el puerto 80 para el correo.

## [1.0.189] — 2026-07-17

Alta disponibilidad de correo: los buzones ahora se pueden replicar de verdad entre dos nodos, no solo la configuración.

### Added

- **Replicación real de correo (Dovecot dsync).** Hasta ahora un slave recibía la *configuración* de correo (cuentas, contraseñas, cuotas, dominios, DKIM) porque vive en la base de datos replicada, pero **NO los mensajes**: `lsyncd` solo copia `/var/www/vhosts`, no `/var/mail/vhosts`. En un failover del nodo de correo el usuario podía autenticarse pero ver el **buzón vacío**. Nuevo `MailReplicationService` que configura la **replicación nativa de Dovecot** (`replicator` + `dsync`) entre dos nodos de correo sobre WireGuard.
  - Se usa **dsync a propósito, nunca rsync**: rsync sobre un Maildir vivo corrompe buzones cuando hay entregas concurrentes; dsync entiende la semántica de Maildir/IMAP (UIDs, flags, expunges) y fusiona en ambos sentidos con seguridad.
  - `setupPair` orquesta ambos extremos desde el master (cada nodo apunta al otro), comparte un secreto `doveadm` y lanza la sincronización inicial. Los correos existentes se copian; a partir de ahí, cada cambio se replica al instante.
  - El puerto de replicación se limita a la red WireGuard; el correo entre nodos nunca sale de la red privada.
  - Con backup del `.conf` antes de escribir y **rollback automático** si Dovecot no arranca con la nueva config (el correo nunca se queda caído por un error de replicación).
- **Failover de correo.** Al promover un nodo a master, los dominios de correo que apuntaban al nodo caído (`mail_node_id`) se **reasignan automáticamente al nodo superviviente**, para que el correo nuevo se entregue localmente y los buzones —ya replicados por dsync— estén presentes.

### Notes

- Es apto para el escenario de **dos servidores** (p. ej. mortadelo ↔ Filemon): activa el correo en el principal y replica con el secundario para tener respaldo real de los mensajes.
- Los nodos deben tener el servidor de correo (Dovecot) instalado antes de activar la replicación.

## [1.0.188] — 2026-07-17

Corrige una caída total de Caddy y tres fallos silenciosos que impedían que el token de Cloudflare llegara a los slaves.

### Security

- **Caída total (postmortem):** guardar el token de Cloudflare reinicia Caddy, y ese reinicio activó una mina latente: `install.sh` generaba el override de systemd con `ExecStartPost` **sin el prefijo `-`**. Cuando `repair-caddy-routes.php` salía con código ≠ 0 (la API admin de Caddy aún no estaba lista), **systemd mataba Caddy con SIGKILL** — todos los dominios caídos y bucle de reinicio. El token era correcto; el fallo era del panel.
- El instalador genera ahora el override con `-` en todos los hooks: un script de reparación que falle **no puede volver a tumbar Caddy**.
- `repair-caddy-routes.php` espera hasta 30s a que la API admin responda (antes se rendía al primer intento) y sale con **código 0** cuando no puede reparar. Una BBDD caída o una API no lista **ya no pueden tirar el servidor web**.

### Fixed

- **El token de Cloudflare nunca llegaba a los slaves.** Dos causas, ambas invisibles:
  - **Cifrado imposible de abrir:** los tokens se cifran con `sha256(DB_PASS)` y **cada nodo tiene su propio `DB_PASS`**, así que un slave **nunca** podía descifrar un token cifrado por el master. Aun así se enviaba el texto cifrado (el comentario del código afirmaba justo lo contrario). Ahora, solo cuando se pide propagar, el master **descifra con su clave** y envía el token por el canal **TLS mutuo** del clúster a nodos autenticados; el slave lo **re-cifra con su propia clave** al guardarlo, así que nunca queda en claro en reposo.
  - **Helper inexistente:** `/usr/local/bin/update-caddy-token.sh` se había puesto **a mano** en el master y **el instalador nunca lo creaba**, de modo que **ningún nodo recién instalado podía aplicar el token** — el `file_exists()` del handler simplemente se saltaba, sin log ni aviso. El instalador lo genera ahora (escritura atómica, backup con fecha de `/etc/default/caddy`, reinicio **verificando que Caddy vuelve**) junto con su regla de sudoers.
- **Éxito falso:** el panel decía *«Token propagado a Caddy (master y slaves)»* **sin comprobar los slaves**. Ahora cada nodo informa del motivo concreto del fallo, el master lo registra y la interfaz indica **qué nodo falló y por qué**; solo confirma el éxito global cuando el token se aplicó de verdad en todos.
- **La web se quedaba parada ~10s en cada recarga de Caddy:** el `ExecReload` encadenaba `sleep 5` + el script de reparación. Ahora la reparación corre **desacoplada** (`systemd-run --no-block`) y `systemctl reload caddy` vuelve al instante.
- **`ERR_CONNECTION_CLOSED` al propagar el token:** el reinicio de Caddy mata la conexión del propio panel. El formulario envía ahora los datos fuera de banda, muestra un aviso de espera, **sondea hasta que el panel responde** y recarga solo entonces (mismo patrón que la recarga post-update de la v1.0.185).

### Added

- **Paridad del binario de Caddy entre master y slaves.** Los módulos DNS (`dns.providers.cloudflare`, `route53`…) van **compilados dentro del binario**: no se pueden añadir por la API de Caddy y no viajan ni por la base de datos replicada ni por lsyncd. Un slave instalado desde el paquete apt entra en el clúster con aspecto sano pero **no puede emitir certificados DNS-01 en un failover**, lo que obligaba a copiar el binario del master a mano.
  - Nuevo `CaddyBinaryService`: versión, hash SHA-256 y módulos DNS de cada nodo, con comparación contra el master. Severidad **crítica** si al nodo le faltan módulos que el master sí tiene, **aviso** si difiere la versión o el build, **OK** si el hash coincide.
  - Nueva tarjeta en `Settings → Cluster → Nodos`: semáforo por nodo y botón **«Igualar módulos»** que hace **dry-run primero**, muestra el plan, pide confirmación (avisando de que reiniciará Caddy en ese nodo) y solo entonces compila los módulos que faltan mediante `xcaddy`. Se carga solo al abrir la pestaña, ya que consulta a todos los nodos por red.
  - Nuevas acciones de clúster `caddy-info` (solo lectura) y `caddy-install-dns-module`.
  - `install.sh` avisa tras instalar el Caddy estándar de apt de que **no trae módulos DNS compilados**, indicando dónde instalarlos.

### Changed

- `Failover → Cuentas Cloudflare`: se aclara que **«Propagar token a Caddy» es una acción puntual, no un ajuste** — reinicia Caddy en el master y en todos los slaves, por eso no se queda marcada. En su lugar se registra y muestra la **fecha de la última propagación**.

### Notes

- Los nodos instalados **antes** de esta versión (p. ej. Nitro, Filemon) no tienen `update-caddy-token.sh`: hay que copiarlo una vez o reinstalar el panel en ellos. A partir de esta versión, **todo nodo nuevo nace con él**.

## [1.0.186] — 2026-07-14

Reescritura del módulo de replicación para soportar **múltiples clústeres PostgreSQL** y separar correctamente **MariaDB** de **MySQL**.

### Security

- **CRÍTICO — Replicación PostgreSQL:** `Convertir en Slave` podía **destruir datos** en hosts con varios clústeres. El código derivaba la versión del *cliente* `psql` (16) y el primer clúster listado (`main`), construyendo la ruta inexistente `/etc/postgresql/16/main`; después ejecutaba `systemctl stop postgresql` (deteniendo **los 3 clústeres**: `14/main`, `14/panel` y `16/musemind`) y `rm -rf` sobre el directorio de datos resuelto por el puerto por defecto (5432), **borrando `14/main` en caliente**.
- `setupPgSlave()` legacy queda **bloqueado**: todas las rutas que lo invocaban (botón manual, `auto-configure`, failover de clúster) devuelven un error seguro en lugar de borrar datos.
- `promotePgSlave()` **promovía el clúster equivocado** (derivaba `16/main` del cliente `psql` y caía al datadir del puerto 5432 = `14/main`). Bloqueado y sustituido por `promotePgSlaveForCluster()` con clúster explícito.
- Eliminadas **7 llamadas** a `systemctl restart/reload postgresql` (paraguas) en los métodos legacy de master/sync/logical: ahora solo se reinicia el clúster del panel vía `pg_ctlcluster`, nunca los tres.
- Endurecimiento de red: `listen_addresses` se limita a loopback + WireGuard (nunca `*`) y las entradas de `pg_hba.conf` se acotan a la IP `/32` de cada slave en WireGuard.
- Las contraseñas de replicación ya no aparecen en la línea de comandos: se usa un fichero `PGPASSFILE` con permisos `0600`.

### Added

- **`PgClusterService`**: identidad explícita de cada clúster PostgreSQL desde `pg_lsclusters` (versión, clúster, puerto, `data_dir`, config, `hba`, unit systemd). Un clúster inexistente devuelve `NULL` en vez de un fallback peligroso.
- **`setupPgSlaveForCluster()`**: procedimiento slave **seguro** — actúa sobre **un solo clúster** (`pg_ctlcluster`), transmite a un directorio **temporal**, valida `PG_VERSION` y `standby.signal`, **aparta** el directorio anterior (`mv`, nunca lo borra) y hace **rollback automático** si el arranque falla. Verifica que la versión mayor de master y slave coincide.
- **`setupPgMasterForCluster()`**: configura un clúster como master con slots físicos y `application_name` únicos por slave+clúster, recargando solo ese clúster.
- **Tabla `replication_pg_instances`**: un slave puede replicar **varios clústeres** a la vez (`14/main`, `14/panel`, `16/musemind`). Migración aditiva e idempotente; `replication_slaves` no se toca y los nodos existentes no se modifican.
- **Preflight + dry-run**: informe previo con clúster, puerto, directorio, tamaño de datos, espacio libre, conectividad WireGuard, compatibilidad de versión, comandos exactos, ficheros y servicios afectados, riesgos y tiempo estimado.
- **Confirmación literal** que incluye slave, clúster y puerto (`SLAVE:filemon CLUSTER:14/main PORT:5432`), para que un texto copiado no pueda apuntar a la instancia equivocada.
- **Matriz de replicación** en la UI: `Slave | Motor | Instancia | Puerto | Rol | Estado | Lag | Slot | Último error`, con refresco automático.
- Suite de tests (`tests/replication_test.php`): **38 comprobaciones** sobre 12 escenarios, todas de solo lectura.

### Fixed

- **MariaDB y MySQL mezclados:** el código detectaba MariaDB pero le aplicaba opciones exclusivas de Oracle MySQL (`gtid_mode`, `enforce-gtid-consistency`, `MASTER_AUTO_POSITION`) que **impiden arrancar MariaDB 10.6**. Ahora se detecta el motor real vía `SELECT VERSION()` y se usa la sintaxis correcta de cada uno: MariaDB (`gtid_strict_mode`, `MASTER_USE_GTID=slave_pos`) y MySQL 8 (`gtid_mode`, `SOURCE_AUTO_POSITION`). Ya no se consulta `@@gtid_mode` en MariaDB (no existe allí).
- **Aviso crítico de incompatibilidad** cuando master y slave son de familias distintas (p. ej. MariaDB 10.6 → MySQL 8): se impide configurar replicación binaria y se recomienda igualar el motor o sincronizar por dumps. Nunca se sustituye el motor automáticamente si existen bases de datos.
- **Dumps omitidos indebidamente:** `isStreamingActive()` colapsaba PostgreSQL en un único booleano, de modo que un solo clúster replicando (p. ej. `14/main`) **suprimía los dumps de los demás** (`14/panel`, `16/musemind`). La decisión es ahora **por instancia**, y los dumps lógicos **nunca** se eliminan por tener streaming activo.
- **Monitorización ciega:** el estado de replicación se leía solo desde la BD del panel (puerto 5433), sin ver 5432 ni 5434. Ahora cada clúster se consulta por **su propio puerto** y reporta rol, streaming, lag y slot de forma independiente.
- Las operaciones destructivas comprueban el **código de salida** real de `mv`/`rm` en lugar de reportar éxito siempre; si el rollback falla, se avisa explícitamente indicando dónde están los datos intactos.
- La contraseña del rol de replicación se escapa como **literal SQL** (comillas duplicadas) en lugar de `escapeshellarg`, que corrompía contraseñas con comilla simple.

### Notes

- La replicación nativa **no se activa** con esta versión: los endpoints existen pero deben usarse explícitamente. El sistema de dumps y `filesync` sigue siendo el mecanismo activo.
- `getPgConfigDir()` / `getPgDataDir()` ahora resuelven el clúster del panel (según `DB_PORT` de `.env`) en lugar de mezclar la versión del cliente con el primer clúster.

## [1.0.185] — 2026-04-29

### Fixed
- `Settings > Updates`: la recarga automatica post-update ahora valida que la respuesta HTML del panel sea real y estable (no vacia/transitoria) antes de hacer `location.replace`, evitando la pantalla en blanco puntual tras reinicio del servicio.

## [1.0.184] — 2026-04-28

### Changed
- Licencia del panel actualizada a **Source Available (Provider Use)**: se permite uso comercial como operador de hosting para tus propios clientes.
- Se mantiene restriccion de no revender/sublicenciar el software del panel ni ofrecerlo como white-label SaaS del propio panel.
- `MuseDock Portal` y add-ons comerciales siguen con licencia separada de pago.

### Improved
- README actualizado con resumen claro de derechos y limites de la nueva licencia.

## [1.0.183] — 2026-04-28

### Improved
- Docs: el hijo `Settings > DNS` ahora explica el flujo completo de `/settings/dns`: estado, proveedor, instalacion de modulo Caddy, credenciales JSON, activacion DNS-01, verificacion y rollback.
- Docs: el mapa de Settings usa icono propio para DNS y diferencia claramente `Settings > DNS` de `Settings > Cloudflare DNS`.

## [1.0.182] — 2026-04-28

### Added
- `Settings > DNS`: nueva seccion clara para configurar el proveedor DNS-01 del panel, instalar modulos Caddy y guardar credenciales del proveedor.
- Redireccion desde la URL legacy `/musedock/plugins/caddy-domain-manager/dns-accounts` hacia `/settings/dns` para evitar 404.
- Docs: `Settings > DNS` queda documentado y enlazado desde la guia TLS/DNS-01 del panel.

### Improved
- Las credenciales JSON nuevas para DNS-01 del panel se guardan cifradas, manteniendo compatibilidad con configuraciones antiguas en claro.
- Modales de confirmacion e inputs JSON de Caddy, Cron y Fail2Ban ajustados al tema oscuro para que password y textos sean legibles.

## [1.0.181] — 2026-04-27

### Added
- Documentacion: nueva guia especial `TLS del panel: DNS-01, proxy naranja y puertos cerrados`, visible desde `/docs`, con ejemplos genericos para dominio/subdominio, certificados del panel, ACME HTTP-01/TLS-ALPN-01, DNS-01, proxy CDN y proveedores DNS.
- `Settings > Server` y documentacion de Firewall enlazan la guia para explicar que ocurre cuando 80/443 estan cerrados, cuando se usa asistencia ACME temporal y cuando conviene DNS-01.

## [1.0.180] — 2026-04-27

### Added
- `Settings > Server`: instalador de modulos DNS para Caddy. Compila con `xcaddy`, preserva modulos no estandar existentes, guarda backup del binario, reinicia Caddy y hace rollback si el servicio no queda activo.
- `Settings > Server`: el flujo DNS-01 del panel puede instalar soporte para proveedores del catalogo MuseDock como Cloudflare, DigitalOcean, Route53, Hetzner, OVH, Vultr, Linode, Porkbun, Namecheap, Gandi, PowerDNS y RFC2136.

## [1.0.179] — 2026-04-27

### Improved
- `Settings > Server`: DNS-01 del TLS del panel ahora detecta proveedores instalados desde `caddy list-modules` y muestra un selector multi-proveedor real.
- `Settings > Server`: al guardar DNS-01 se valida que Caddy tenga cargado `dns.providers.<proveedor>` antes de aplicar la policy ACME.

## [1.0.178] — 2026-04-27

### Fixed
- `Settings > Server`: corrige el modal de asistencia ACME para que el boton `Abrir 80/443 y emitir certificado` no envie el formulario sin pedir password.
- `Settings > Server`: al guardar con HTTP-01/TLS-ALPN-01 y 80/443 cerrados, el aviso explica que se puede usar el bloque `Firewall y Let's Encrypt` o abrir temporalmente los puertos desde el propio modal.

## [1.0.177] — 2026-04-27

### Improved
- Updater: cada ejecucion queda auditada en `storage/logs/update-audit.log`, `panel_log` y `panel_settings`; si falla, se envia notificacion de evento con run id, version y paso del fallo.
- `Settings > Updates`: muestra el ultimo estado auditado del updater, incluyendo error y run id cuando existan.

## [1.0.176] — 2026-04-27

### Improved
- `Settings > Server`: la asistencia ACME ahora tiene boton explicito para abrir temporalmente 80/443 y emitir certificado, ademas del modal al guardar.

## [1.0.175] — 2026-04-27

### Improved
- `Settings > Server`: al guardar un dominio publico con Let's Encrypt HTTP-01/TLS-ALPN-01, el panel detecta si el firewall no tiene 80/443 abiertos a Internet y puede abrirlos temporalmente durante 30 minutos con confirmacion y password admin.

## [1.0.174] — 2026-04-27

### Fixed
- Panel public TLS: las policies ACME de dominios publicos del panel ya no incluyen fallback `internal`. El certificado interno queda limitado a acceso por IP/localhost para evitar que Chrome/HSTS reciba `ERR_CERT_AUTHORITY_INVALID` cuando ACME todavia no ha emitido.

## [1.0.173] — 2026-04-27

### Improved
- `Settings > Server`: el boton Guardar muestra spinner y queda deshabilitado mientras se guardan ajustes y se aplica Caddy/TLS.

## [1.0.172] — 2026-04-27

### Fixed
- Panel domain routes: `panel-domain-route` (`:8444`) y `panel-domain-https-route` (`:443`) ya no se detectan entre si como conflicto para el mismo hostname.

## [1.0.171] — 2026-04-27

### Fixed
- Cluster alerts: las URLs del panel ya no usan `PANEL_DOMAIN` ni el hostname de la maquina como dominio supuesto. Ahora usan `panel_hostname` solo si fue configurado en `Settings > Server`; si no, caen a `server_ip`/IP detectada.

## [1.0.170] — 2026-04-27

### Fixed
- Panel domain ACME: al configurar un dominio publico del panel en `:8444`, Caddy crea tambien `panel-domain-https-route` en `:443` para ese hostname, con redirect 308 hacia `:8444`. Esto da a Caddy un anclaje estandar para TLS-ALPN/ACME y evita quedarse sirviendo certificado interno.
- La ruta `:443` solo se crea si no existe ya otra ruta para ese hostname, para no pisar hostings manuales.

## [1.0.169] — 2026-04-27

### Fixed
- `bin/update.sh`: corrige el orden de reparación de Caddy. Primero regenera/reinicia el bloque persistente de IP del panel y después ejecuta `repair-caddy-routes.php`, evitando que el reinicio de Caddy borre la ruta runtime del dominio recién creada.

## [1.0.168] — 2026-04-27

### Fixed
- Panel domain TLS: los hostnames publicos del panel ya no quedan con policy `internal/self_signed`; se fuerza Let’s Encrypt HTTP-01/TLS-ALPN-01 para evitar bloqueos HSTS como `ERR_CERT_AUTHORITY_INVALID`.
- `repair-caddy-routes.php` corrige policies antiguas `internal` para dominios publicos del panel aunque la BD arrastre `panel_tls_mode=self_signed` desde el instalador.
- Al detectar una ruta del dominio ya existente, el reparador vuelve a calentar TLS para disparar la obtencion/seleccion del certificado publico.
- `Settings > Server` muestra Let’s Encrypt como modo recomendado cuando hay dominio del panel configurado y rellena un email ACME razonable si existe email de admin/notificaciones.

## [1.0.167] — 2026-04-27

### Fixed
- Panel IP fallback: `repair-caddy-routes.php` y el worker ahora reponen `panel-fallback-route` en el servidor Caddy que realmente escucha `PANEL_PORT`, incluido `srv1` generado desde Caddyfile.
- El acceso directo por IP a `https://IP:8444/` queda preservado para slaves sin subdominio configurado, usando TLS interno/autofirmado y proxy al panel interno.
- La ruta fallback queda limitada a IPs locales/detectadas y `localhost`, evitando convertir `:8444` en un catch-all innecesario para cualquier hostname.

## [1.0.166] — 2026-04-27

### Fixed
- Panel domain TLS: `Settings > Server` ahora puede crear la ruta `panel-domain-route` en el servidor Caddy que realmente escucha `PANEL_PORT` aunque sea `srv1` generado desde Caddyfile, siempre que proxyee al panel interno.
- La UI ya no muestra `Acceso recomendado` si Caddy omitio la ruta; solo lo muestra cuando la ruta queda aplicada de verdad.
- `repair-caddy-routes.php` y `cluster-worker.php` reponen la ruta del dominio del panel tras reload/reinicio de Caddy sin tocar rutas manuales existentes.
- `install.sh` y `bin/update.sh` instalan un hook systemd para ejecutar el reparador tras `caddy start/reload`, preservando configuraciones runtime/autosave.

## [1.0.165] — 2026-04-26

### Added
- `Settings > Cron`: nuevo bloque `Exportar / Importar configuracion (JSON)` con autenticacion por password admin.
- `Settings > Caddy`: nuevo bloque `Exportar / Importar configuracion (JSON)` para backup/restauracion completa desde API.
- `Settings > Fail2Ban`: nuevo bloque `Exportar / Importar configuracion (JSON)` para whitelist + configuracion musedock.
- Nuevos endpoints:
  - `POST /settings/crons/export`
  - `POST /settings/crons/import`
  - `POST /settings/caddy/export`
  - `POST /settings/caddy/import`
  - `POST /settings/fail2ban/export`
  - `POST /settings/fail2ban/import`

### Improved
- Imports protegidos con confirmacion y password admin en UI (SweetAlert) para evitar cambios accidentales.
- `/docs/settings/{fail2ban|cron|caddy}` actualizado con recomendaciones operativas de export/import y rollback.
- README actualizado para reflejar export/import JSON en Fail2Ban, Cron y Caddy.

## [1.0.164] — 2026-04-26

### Improved
- README modernizado y alineado con el estado real del panel (monitoring, firewall, fail2ban, seguridad/MFA, cluster, docs internas).
- README: nueva seccion de actualizacion shell con bloques copy/paste para nodo unico y para varios nodos (master/slaves).
- README: anadida verificacion post-update y referencia clara a `Settings > Updates` para update desde web.
- README: limpieza de contenido antiguo para evitar desalineacion con funcionalidades actuales.

## [1.0.163] — 2026-04-26

### Added
- Docs: nueva guia especial `/docs/profile-mfa` con configuración MFA paso a paso (móvil/PC), uso diario, recuperación y procedimiento de emergencia por base de datos.
- Profile: nuevo botón `Guia` en la tarjeta `Autenticacion MFA (TOTP)` con acceso directo a `/docs/profile-mfa`.

### Improved
- Login: campo de contraseña con botón ojo para mostrar/ocultar password en `/login`.

### Fixed
- Monitor collector (`FIREWALL_CHANGED`): ya no dispara alerta externa cuando el único cambio en firewall corresponde a bans dinámicos por IP de Fail2Ban.

## [1.0.158] — 2026-04-26

### Added
- Docs: nueva guia especial `/docs/default-backups` explicando backups por defecto del sistema: BD del panel, Caddy, `last-known-good`, snapshots de instalacion, retenciones, restauracion y limites.

## [1.0.157] — 2026-04-26

### Added
- Nuevo `bin/backup-caddy-config.sh`: guarda snapshots de `/etc/caddy/Caddyfile`, conserva 15 dias por defecto y mantiene una copia `last-known-good` validada con `caddy validate`.
- `install.sh` y `bin/update.sh`: instalan `/etc/cron.d/musedock-caddy-backup` para backup diario de Caddy y crean snapshot inmediato.
- Docs/Bugs: nuevo articulo `Backups de Caddy y reconstruccion sin backup`, con politica de retencion, restauracion y reconstruccion manual desde `/var/www/vhosts`.

### Improved
- `install.sh`: crea snapshot de Caddy antes de entrar en el paso de configuracion Caddy.
- `bin/repair-panel-tls.sh`: ejecuta un snapshot de Caddy antes de reescribir el Caddyfile.

## [1.0.156] — 2026-04-26

### Added
- Docs/Bugs: nueva guia `Restaurar Caddy/web tras reinstalacion accidental`, con diagnostico, restauracion desde backups, permisos Caddyfile y reconstruccion si no hubiese backup.

## [1.0.155] — 2026-04-26

### Fixed
- `bin/repair-panel-tls.sh`: fuerza `/etc/caddy/Caddyfile` a `root:root 0644` tras escribir o restaurar, corrigiendo `open /etc/caddy/Caddyfile: permission denied` cuando Caddy corre como usuario `caddy`.
- `install.sh`: normaliza permisos del Caddyfile generado o restaurado para que `systemd` pueda arrancar Caddy.

## [1.0.154] — 2026-04-26

### Fixed
- `bin/repair-panel-tls.sh`: si Caddy valida pero no arranca, restaura automaticamente el Caddyfile anterior y muestra `systemctl/journalctl` para no dejar el panel sin listener.
- `install.sh`: si el Caddyfile generado valida pero el servicio no reinicia, intenta restaurar el ultimo backup de Caddyfile.

## [1.0.153] — 2026-04-26

### Fixed
- `install.sh`: `Reconfigurar Caddy` tambien exige confirmacion exacta `RECONFIGURAR CADDY` cuando detecta dominios/rutas existentes, evitando sobrescrituras accidentales de sitios.
- `install.sh`: el health check PostgreSQL usa el `DB_HOST` real del panel, incluido `/var/run/postgresql`, en vez de forzar siempre `127.0.0.1`.

## [1.0.152] — 2026-04-26

### Fixed
- `bin/repair-panel-tls.sh`: valida Caddyfiles temporales con `--adapter caddyfile`, evitando el error `config is not valid JSON`.
- `install.sh`: el modo `Reinstalar` queda protegido si el panel existente parece operativo; exige escribir `REINSTALAR` para evitar reinstalaciones accidentales.
- `install.sh`: corregida URL corrupta del health check en modo `Solo verificar`.

## [1.0.151] — 2026-04-26

### Fixed
- Runtime DB: `Database::connect()` usa `connect_timeout` configurable (`DB_CONNECT_TIMEOUT`, 5s por defecto) para que una conexion PostgreSQL colgada no bloquee todo el panel.
- Runtime DB: si `DB_HOST=127.0.0.1/localhost` falla y existe socket local PostgreSQL, el panel intenta `/var/run/postgresql` antes de romper la request.

## [1.0.150] — 2026-04-26

### Fixed
- `install.sh`: el health check ya no aborta por `curl` timeout cuando Caddy/HTTPS `8444` no responde; valida primero el panel interno `127.0.0.1:PANEL_INTERNAL_PORT`.
- `install.sh`: textos de Caddy actualizados para no decir que cae a PHP directo en `8444`; el panel queda interno y requiere reparar Caddy/TLS para acceso publico.

## [1.0.149] — 2026-04-26

### Fixed
- `install.sh`: si `psql` por TCP a `127.0.0.1:5433` hace timeout pero PostgreSQL escucha, el instalador prueba socket Unix `/var/run/postgresql` y guarda `DB_HOST=/var/run/postgresql` si funciona.
- `install.sh`: en reinstalaciones ya migradas a `5433`, vuelve a normalizar `pg_hba.conf` con reglas `local` y `host` especificas para el usuario del panel antes de aplicar `schema.sql`.

## [1.0.148] — 2026-04-25

### Fixed
- `install.sh`: los checks de PostgreSQL `panel/5433` ya no dependen exclusivamente de `pg_isready`; si `ss` confirma que el puerto local escucha, la reinstalacion continua y `psql` valida credenciales en el paso siguiente.
- `bin/update.sh`: mismo fallback para updates, evitando falsos negativos cuando PostgreSQL esta online pero `pg_isready` falla por configuracion local de sockets/stats/localhost.

## [1.0.147] — 2026-04-25

### Fixed
- `/settings/updates`: tras un update con `updated=1`, la pagina usa cache local y no depende de una consulta remota inmediata a GitHub, evitando pantalla en blanco si DNS/red esta lenta tras el reinicio.
- `/settings/updates`: la vista y el endpoint JSON capturan errores temporales de estado/check y muestran salida controlada en vez de romper la pagina.

## [1.0.146] — 2026-04-25

### Improved
- `install.sh`: al elegir `Reinstalar`, ejecuta un preflight inmediato de servicios existentes; si PostgreSQL `panel` en `5433` no responde, intenta arrancar/reiniciar el cluster antes de iniciar pasos pesados.
- `install.sh`: el paso PostgreSQL reinicia el cluster `panel` cuando figura `online` pero no acepta conexiones, y acota `pg_ctlcluster`/`pg_createcluster` con timeout para evitar bloqueos largos.

## [1.0.145] — 2026-04-25

### Fixed
- TLS admin/cluster: el bloque Caddy del panel ahora incluye todas las IPv4 locales del nodo, incluyendo IP privada/WireGuard `10.x.x.x`, evitando `tlsv1 alert internal error` cuando el cluster conecta a `https://10.x.x.x:8444`.
- `install.sh`, `bin/update.sh` y `bin/repair-panel-tls.sh`: generan site labels Caddy multi-IP para IP publica, IPs privadas, `127.0.0.1` y `localhost`.

## [1.0.144] — 2026-04-25

### Fixed
- `cluster-worker.php`: los slaves ya no envian alerta de `Master caido` si su propio panel local falla HTTPS en `https://127.0.0.1:PANEL_PORT`; esto evita falsos positivos cuando `8444` esta abierto pero degradado a HTTP plano.

## [1.0.143] — 2026-04-25

### Added
- Nuevo `bin/repair-panel-tls.sh`: reparador SSH independiente para recuperar `https://IP:8444` cuando aparece `ERR_SSL_PROTOCOL_ERROR`, sin depender de la BD ni del panel web.

### Improved
- El reparador TLS del panel reconstruye el bloque Caddy `https://IP:PANEL_PORT` con `tls internal`, preserva bloques no-panel, elimina override `--resume`, valida Caddy y verifica HTTPS local.

## [1.0.142] — 2026-04-25

### Improved
- `install.sh`: preflight rapido de PostgreSQL `panel` en `5433` con `pg_isready -t 1`, evitando esperas largas si el cluster ya esta operativo.
- `install.sh`: al reinstalar, si el cluster `panel` esta parado se intenta arrancar y se espera maximo 5s; si no responde, se muestran comandos de diagnostico en vez de bloquearse en `psql`.
- `install.sh`: las conexiones criticas a PostgreSQL usan `PGCONNECT_TIMEOUT=5` y el arranque global de PostgreSQL queda acotado a 30s.

## [1.0.141] — 2026-04-25

### Improved
- `bin/update.sh`: antes de migrar comprueba si PostgreSQL del panel responde y, en instalaciones locales, intenta arrancar el cluster `panel` si esta parado.
- `bin/update.sh`: si la BD sigue inaccesible, muestra diagnostico operativo (`pg_lsclusters`, `systemctl`, logs PostgreSQL) y recomienda reinstalar solo cuando la instalacion quedo parcial.

## [1.0.140] — 2026-04-25

### Improved
- `install.sh`: la cabecera del instalador usa la version real del panel desde `config/panel.php` en vez de `v0.1.0`.
- `install.sh`: el temporizador de pasos se pausa mientras espera respuestas interactivas, evitando latidos falsos durante prompts.
- `install.sh`: los checks de rutas de Caddy usan timeout corto para no bloquear varios minutos si la API admin local esta lenta o no responde.

## [1.0.139] — 2026-04-25

### Fixed
- `install.sh`: instalaciones parciales ya no dejan desincronizado el password de PostgreSQL; si el rol `musedock_panel` ya existe, se actualiza con el `DB_PASS` actual.
- `install.sh`: si la BD `musedock_panel` ya existe, se normaliza el owner antes de aplicar esquema.
- `install.sh`: la aplicacion de `database/schema.sql` deja log en `/tmp/musedock-panel-install-schema.log` y muestra el error real de `psql` en vez de morir silenciosamente.

## [1.0.138] — 2026-04-25

### Improved
- `install.sh`: temporizador visible por paso; cada `OK`/warning muestra tiempo transcurrido del paso.
- `install.sh`: pasos largos imprimen un latido cada 30 segundos con tiempo del paso y tiempo total para evitar sensacion de bloqueo durante APT/PHP/PostgreSQL.

## [1.0.137] — 2026-04-25

### Fixed
- `install.sh`: si `add-apt-repository ppa:ondrej/php` se queda colgado o falla por timeout de Launchpad, aplica fallback directo con keyring y `https://ppa.launchpadcontent.net/ondrej/php/ubuntu`.
- `install.sh`: `add-apt-repository` queda limitado con `timeout 90` para no bloquear indefinidamente instalaciones virgenes.

## [1.0.136] — 2026-04-25

### Fixed
- `install.sh`: el paso PHP ya no muere silenciosamente en `add-apt-repository`, `apt-get update`, `apt-get install` o `php-fpm`; muestra las ultimas lineas del log real.
- `install.sh`: nuevo log temporal `/tmp/musedock-panel-install-php.log` para diagnosticar fallos de repositorio PHP/PPA/Sury en servidores virgenes.
- `install.sh`: trap global para errores no controlados, mostrando linea y ultimas lineas de `/tmp/musedock-panel-install.log`.

## [1.0.135] — 2026-04-25

### Improved
- Setup inicial `/setup`: textos auxiliares y notas de firewall/TLS pasan a colores claros sobre fondo azul oscuro para evitar gris sobre azul poco legible.
- Setup inicial: codigos como `ALLOWED_IPS`, puertos y ayudas de IP/CIDR quedan con contraste alto.

## [1.0.134] — 2026-04-25

### Docs
- Nueva seccion padre `/docs/bugs-sections` para articulos de incidencias reales: sintomas, diagnostico, causa raiz, fix y prevencion.
- Nuevo articulo `/docs/bugs/err-ssl-protocol-error` documentando el bug de `ERR_SSL_PROTOCOL_ERROR` por IP/dominio en `8444`, Caddy/PHP, `wrong version number`, runtime API y Caddyfile.
- `/docs`: nueva card padre "Bugs: incidencias y diagnostico"; la busqueda indexa tambien los articulos de Bugs.

## [1.0.133] — 2026-04-25

### Fixed
- `bin/update.sh` e `install.sh`: corregida la extraccion del bloque Caddy del panel para eliminar el bloque `:PANEL_PORT` completo, incluyendo cierres anidados de `reverse_proxy`.
- Evita que quede una llave `}` suelta en `/etc/caddy/Caddyfile`, que provocaba `subject does not qualify for certificate: '}'` y dejaba `8444` sin TLS funcional.

## [1.0.132] — 2026-04-25

### Fixed
- Caddy TLS por IP: retirado `default_sni` para compatibilidad con builds de Caddy que no lo validan.
- `bin/update.sh`: la reparacion TLS del panel se ejecuta al final, despues de cualquier reparacion runtime por API, y elimina `--resume` para arrancar desde Caddyfile.
- `cluster-worker.php` y `repair-caddy-routes.php`: si `PANEL_PORT` ya esta gestionado por Caddyfile con `tls internal`, no mutan el runtime del panel por API y evitan degradar `8444` a HTTP plano.

## [1.0.131] — 2026-04-25

### Fixed
- TLS por IP en instalaciones nuevas: Caddy declara explicitamente `https://IP:PANEL_PORT`, `https://127.0.0.1:PANEL_PORT`, `https://localhost:PANEL_PORT` y `default_sni`, evitando `tlsv1 alert internal error`.
- `bin/update.sh`: repara automaticamente el bloque TLS del panel en Caddy al actualizar, preservando otros bloques no-panel del Caddyfile.

### Notes
- El puerto publico sigue siendo Caddy/TLS (`8444`) y el PHP interno queda solo en `127.0.0.1:8445`.

## [1.0.130] — 2026-04-25

### Fixed
- `install.sh`: el puerto publico del panel (`8444`) queda reservado para Caddy/TLS; ya no cae a PHP HTTP directo en `0.0.0.0:8444` si Caddy falla.
- `install.sh`: Caddyfile del panel usa `:PANEL_PORT` con `tls internal`, valido para acceso por IP/host con certificado interno.
- Health check: ya no considera correcto HTTP plano en `PANEL_PORT`; detecta y avisa del caso que provoca `ERR_SSL_PROTOCOL_ERROR`.

### Notes
- `ERR_SSL_PROTOCOL_ERROR` no es un aviso de certificado. Significa que el navegador intenta HTTPS pero el puerto responde HTTP plano, por eso no aparece la opcion normal de "avanzado".

## [1.0.129] — 2026-04-25

### Fixed
- SweetAlert global: corregida recursion infinita en `window.Swal.fire` que provocaba `Maximum call stack size exceeded` y bloqueaba botones como `/settings/updates`.

### Docs
- `/docs/install-recovery`: comandos de primera instalacion separados para usuario `root` y usuario con `sudo`, evitando el bloque confuso con `sudo -i`.
- `/docs/install-recovery`: nota de diagnostico si `install.sh` no muestra salida, usando `sudo bash -x install.sh`.

## [1.0.128] — 2026-04-25

### Docs
- Nueva guia especial `/docs/install-recovery` con primera instalacion desde GitHub, carpeta correcta (`/opt/musedock-panel`), comandos de instalacion y primer acceso web.
- Documentadas las opciones principales de `install.sh`: puerto, PHP, PostgreSQL interno, MySQL opcional, firewall, IP/CIDR permitido y Fail2Ban.
- Documentado como actualizar desde shell con `bin/update.sh --auto`.
- Documentada recuperacion PostgreSQL/.env: diferencia entre superusuario `postgres`, usuario DB `musedock_panel`, `DB_PASS` y admin web del panel.

## [1.0.127] — 2026-04-25

### Fixed
- `install.sh`: en servidores virgenes/minimos ya no depende de `sudo` para ejecutar comandos como usuario `postgres`; usa `runuser -u postgres -- ...`.
- Instalador PostgreSQL: evita que un servidor limpio sin `sudo` parezca necesitar credenciales manuales del superusuario `postgres`.

### Notes
- El usuario de base de datos del panel (`musedock_panel`) y su password los crea el instalador shell y los escribe en `.env`; el setup web solo crea el primer admin del panel.

## [1.0.126] — 2026-04-25

### Improved
- `install.sh`: deteccion de firewall mas segura y explicita: distingue UFW activo, UFW instalado pero inactivo, iptables restrictivo, iptables instalado sin politica restrictiva y ausencia de firewall.
- `install.sh`: si iptables ya esta activo/restrictivo, se respeta iptables aunque UFW este instalado pero inactivo; no se activa UFW por sorpresa.
- `install.sh`: la restriccion del puerto del panel permite introducir IP o rango CIDR para abrir `PANEL_PORT`/`8444` solo a fuentes de confianza y guardar `ALLOWED_IPS`.
- `install.sh`: Fail2Ban ya no se toca automaticamente sin confirmacion; si esta instalado pregunta si sincronizar jails MuseDock, y si no esta instalado pregunta si instalarlo.

### Fixed
- Instalador: opcion real para saltar firewall/Fail2Ban sin instalar ni modificar servicios existentes.

## [1.0.125] — 2026-04-25

### Fixed
- Cron `musedock-backup`: corregido el backup horario de la BD del panel cuando `storage/backups` no existe o no es escribible por `postgres`.
- `update.sh`: ahora normaliza `/etc/cron.d/musedock-backup`, crea `storage/backups` como `postgres:www-data` con modo `0770` y evita el error `cannot create ... Permission denied`.
- `install.sh`: todas las rutas de instalacion/reparacion escriben el cron seguro, ejecutando `pg_dump` como `postgres` pero dejando la creacion/redireccion del archivo bajo `root`.

### Notes
- El backup lo lanza cron, no el proceso web del panel. Aunque el panel corra como root, la linea antigua fallaba porque el shell intentaba crear el `.sql.gz` como usuario `postgres`.

## [1.0.124] — 2026-04-25

### Fixed
- `/settings/updates`: el boton `Actualizar` ya no oculta errores del backend; si no puede arrancar el updater muestra el mensaje real en modal y en la salida de progreso.
- `/settings/updates`: si la peticion se corta por reinicio del panel, la UI sigue haciendo polling y recarga automaticamente cuando el panel vuelve.

### Improved
- `/settings/updates`: spinners y bloqueo visual en `Comprobar ahora` y `Actualizar` para evitar dobles clicks y dejar claro que la pagina esta trabajando.

## [1.0.123] — 2026-04-25

### Improved
- `/mail?tab=queue`: historico relay mas compacto, con detalle truncado para evitar scroll horizontal y boton de ojo para abrir modal con evento completo.
- Historico relay: el parser correlaciona lineas de Postfix por queue id para rellenar `from` y dominio cuando la linea `status=sent/deferred/bounced` solo trae `to`.
- Al reingestar `mail.log`, eventos ya guardados en BD actualizan campos vacios (`from`, dominio, relay, dsn, detalle) si el log permite completarlos.

### Docs
- `/docs/mail/relay`: nueva seccion Laravel/SaaS con `MAIL_LOCAL_URL`, DSN SMTP interno, `verify_peer=0`, mailer `local` y `failover` local + proveedor backup.
- `/docs/mail/queue`: documentado como se alimenta el historico desde `mail.log`/`maillog`, correlacion por queue id y modal de detalle raw.

## [1.0.122] — 2026-04-25

### New
- `/mail?tab=relay`: edicion de usuarios SMTP del relay desde la tabla, con descripcion, limite/hora, dominios remitentes permitidos y cambio de password.
- Relay SMTP: opcion de mantener password, generar una nueva o definir una manual; si cambia, se actualiza el usuario SASL real en `sasldb2` y se guarda cifrada para migraciones.

### Improved
- `/mail?tab=relay`: spinner al crear usuario SMTP y al guardar/borrar usuarios.
- Edicion y borrado de usuarios SMTP requieren modal de confirmacion y password admin antes de tocar credenciales sensibles.

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
