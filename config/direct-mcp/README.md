# MCP directo: preparación, pruebas y activación separadas

Esta integración está desactivada por defecto. Guardar configuración o crear clientes no crea DNS ni modifica Caddy. La activación pública requiere la confirmación explícita del propietario después de revisar las pruebas.

## Diseño

- HTTPS dedicado `https://mcp.mortadelo.screenart.es/mcp` en 443. DNS A, `proxied: false` (nube gris). No se cambia UFW, fail2ban ni el listener 8444.
- Caddy: Host exacto, únicamente `/mcp`, `/oauth/authorize`, `/oauth/token`, `/oauth/revoke` y los tres documentos de descubrimiento enumerados en `Gateway::PUBLIC_PATHS`. Resto 404, incluyendo `/`, `/login`, `/settings`, `/api/cluster/action` y MCP antiguo.
- MCP: matcher `remote_ip` con rangos de salida, no cabeceras aportadas por clientes. Caddy sobrescribe la IP y un secreto de ingreso privado; PHP exige loopback, Host exacto y ese secreto. Aun desde una red permitida hace falta OAuth.
- OAuth: cliente registrado manualmente, `client_secret_basic`, PKCE S256 obligatorio, audiencia `/mcp`, una devolución HTTPS exacta y autorización de un administrador activo con su sesión/MFA del panel. Cada cliente dispone de SQLite, secret hash, concesiones y revocación propios, fuera de las credenciales del túnel y el token Bearer antiguo. No hay DCR/CIMD/OIDC.
- La autorización y los metadatos son accesibles desde navegadores, independientemente de la red del proveedor. Los tokens y el MCP requieren la red del cliente correspondiente. La red de Claude no autoriza tokens de ChatGPT.
- HTTP MCP sin SSE: POST JSON-RPC y GET 405 una vez autenticado, como el endpoint actual.

## Rangos

OpenAI: https://openai.com/chatgpt-connectors.json (documentación: https://developers.openai.com/api/docs/guides/ip-addresses).

Anthropic: https://platform.claude.com/docs/en/api/ip-addresses.md ; se interpreta exclusivamente la sección **Outbound IP addresses**, excluyendo inbound y phased out. Si cambia el formato, la actualización falla cerrada.

Solo HTTPS verificado, sin redirecciones, tamaño limitado, formato/CIDR validado antes de sustituir la última lista válida. Actualización cada 24 horas por el cluster-worker; si falla reintenta como máximo una vez por hora, con conservación máxima de siete días. Al caducar, Caddy elimina esos rangos y PHP deniega el cliente. Otro proveedor requiere rangos manuales fiables o salida fija; no se autorizan IP por observar peticiones, User-Agent o errores. Los rangos solo se aplican a este subdominio, nunca al firewall del panel.

## Preparación y revisión

1. Ajustes → MCP: guardar orígenes públicos/privados. Añadir cada cliente con la URL de devolución exacta que muestra su asistente y copiar el secreto mostrado una sola vez. Elegir «tu propio cliente OAuth» en ChatGPT/Claude. Si el asistente necesita descubrimiento público para mostrar la devolución, se puede registrar el cliente después de la activación aprobada: sin clientes registrados el MCP responde un desafío OAuth, nunca proporciona herramientas ni datos.
2. Actualizar rangos desde el panel o `php bin/direct-mcp.php --refresh`.
3. Ejecutar `php tests/direct_mcp_test.php`, `php tests/direct_mcp_http_test.php`, `php tests/chatgpt_oauth_test.php` y `php tests/chatgpt_http_test.php`.
4. `php bin/direct-mcp.php --status` muestra estado sin secretos. `--export` genera la ruta Caddy **con el secreto de ingreso**: guardarla exclusivamente en un fichero privado, nunca adjuntarla ni pegarla en chats.
5. Comprobar en una instancia Caddy aislada que las rutas de administración y las cabeceras falsificadas están bloqueadas. No cargar la configuración de pruebas en el Caddy público.

## Después de la confirmación explícita

Ejecutar como operador del panel, con la IPv4 pública previamente verificada:

```
php bin/direct-mcp.php --activate --approved 207.180.246.223
```

El comando revisa que el registro existente sea A, a la IP indicada y sin proxy; si no existe crea solo ese A. No cambia registros existentes ni añade AAAA/CNAME. Actualiza rangos y permite el descubrimiento desde las redes oficiales aunque todavía no haya clientes registrados, habilita los clientes preparados y aplica únicamente la ruta dedicada mediante la API de Caddy con ETag/If-Match. Si Caddy falla revoca el acceso. Un DNS recién creado puede permanecer, pero el endpoint OAuth/MCP se queda desactivado.

Verificar certificado público, descubrimiento, flujo real OAuth, `panel_info` y `list_nodes` desde cada asistente, y después consultar Mortadelo, Filemon y Nitro. Las pruebas locales no certifican una conexión real con una cuenta ChatGPT/Claude.

El cluster-worker repone solo esta ruta explícitamente activada después de recargas de Caddy. Nunca activa una configuración desactivada. La API persiste en autosave; el Caddyfile base puede sobrescribir las rutas al reiniciar y el worker las restaura. En ese intervalo el servicio MCP puede estar inaccesible, pero no se publica el panel. Conservar el hostname dedicado sin reutilizarlo para hosting genérico.

Revocar un cliente o todos desde el panel. Rollback total: `php bin/direct-mcp.php --disconnect` revoca todos los tokens y retira únicamente la ruta dedicada. El DNS propio puede eliminarse aparte; no modifica las demás webs.

## Permisos futuros

Los clientes declaran sus ámbitos independientemente. El permiso inicial es `musedock:read`. Desde la versión 1.0.361 pueden habilitarse `musedock:write` y `musedock:dns` por cliente; `WriteMcp::TOOLS` mantiene una lista cerrada de operaciones. Los tokens de lectura no heredan escritura aunque el cliente o el servidor la permitan. Una operación futura de CMS exige un ámbito específico, consentimiento nuevo, política de producto, comprobación de permiso en ejecución y un flujo de confirmación de cada escritura. No basta con añadir una herramienta ni habilitar la opción global del MCP tradicional. No se implementan ni habilitan escrituras de CMS en esta entrega.


## Escritura con aprobación privada (1.0.361)

1. En Ajustes → MCP → Directa, selecciona los permisos del cliente y guarda. Escritura requiere lectura; DNS requiere escritura. No se amplían los clientes existentes automáticamente.
2. Configura en el asistente los ámbitos indicados en la ficha del cliente (separados por espacios; en ChatGPT pueden introducirse uno por línea) y vuelve a autorizar. El consentimiento muestra los ámbitos solicitados. Los permisos generales del servidor para escritura y DNS también deben estar habilitados en la pestaña avanzada.
3. `apply=false` devuelve un plan. `apply=true` solo crea una solicitud local de diez minutos: no ejecuta la operación ni admite contraseñas o destinos `node`.
4. Un administrador autenticado y con CSRF válido confirma o rechaza el plan en la pestaña directa. Se valida el consentimiento vigente, el rol del usuario que lo concedió, los límites generales, los ámbitos y un plan recalculado. Si el plan cambia, hay que solicitar uno nuevo.
5. La solicitud se consume antes de ejecutar para impedir replays. Un fallo puede ser parcial; no se reintenta automáticamente. El registro de actividad conserva solicitud, aprobación, rechazo y resultado.

Herramientas: `mail_domain_create`, `mail_mailbox_create`, `mail_alias_create`, `mail_domain_alias`, `mail_dkim_selector`, `database_create`, `domain_redirect_create`, `mail_dns_publish`, `dns_record_set`. Las dos últimas exigen además `musedock:dns`. No se incluyen herramientas de clúster, firewall, borrado o CMS. El túnel OpenAI conserva solo lectura.

Las solicitudes se guardan en el SQLite privado de cada cliente; se limita la cola a 20 y la revocación de ámbitos/cliente/consentimiento las invalida. Los campos de contraseña no se anuncian ni se aceptan; las credenciales generadas se consultan en el panel.
