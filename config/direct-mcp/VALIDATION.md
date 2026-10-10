# MCP directo activado: validación y conexión ChatGPT

Activación autorizada por el propietario y completada: 2026-10-10 19:24 CEST.

## Estado real

- DNS: `mcp.mortadelo.screenart.es` → `207.180.246.223`, A, TTL 300, Cloudflare **sin proxy**.
- MCP: `https://mcp.mortadelo.screenart.es/mcp`.
- Certificado válido emitido por Let's Encrypt; verificación TLS de curl: 0, sin ignorar certificados.
- Descubrimiento OAuth y metadatos del recurso: HTTP **200**, audiencia `/mcp`, PKCE S256, `client_secret_basic`, permiso `musedock:read`.
- Desde una IP fuera de los proveedores: MCP **403**, incluso con `X-Forwarded-For` o `CF-Connecting-IP` falsos.
- `/login`, `/settings/mcp`, `/api/cluster/action`, `/api/mcp` y archivos del panel: **404** en el nuevo subdominio.
- Configuración completa del listener 8444: SHA-256 `350c9dcf09e21a8813860437c75967cefb036a1c93cf42ecfbf67a635bf0487e`, idéntica a la anterior.
- Panel, Caddy y fail2ban activos. No se ha cambiado el firewall ni las credenciales anteriores.
- Redes comprobadas: OpenAI 283 rangos y Anthropic 1 rango de salida. Actualización automática por el worker; rangos caducados bloqueados.
- Cliente ChatGPT todavía sin registrar: se crean sus credenciales desde el panel privado. Ninguna cuenta está conectada aún.

## Pruebas tras activar

| Prueba aislada | Comprobaciones | Resultado |
| --- | ---: | --- |
| Registro directo / OAuth / redes | 56 | Correcto |
| HTTP directo / Caddy real aislado / API simulada | 62 | Correcto |
| OAuth e ingreso anteriores | 60 | Correcto |
| HTTP anterior y cliente oficial del túnel | 41 | Correcto |
| **Total** | **219** | **Todas correctas** |

Durante la activación se detectó que decodificar y recodificar la configuración Caddy con mapas PHP convertía matchers JSON vacíos de otras webs de `{}` a `[]`. Se corrigió conservando objetos JSON y comparando una representación canónica para evitar recargas innecesarias. Dos pruebas nuevas verifican específicamente esos casos, además de la prueba de cambios concurrentes con ETag. Caddy rechazó los intentos inválidos sin sustituir su configuración; la ruta corregida se ha aplicado y reconciliado correctamente.

## Pasos del usuario

1. Abre Ajustes → MCP en `https://mortadelo.screenart.es:8444/settings/mcp`.
2. En **Preparar la conexión con ChatGPT**, comprueba la devolución exacta que muestra ChatGPT y pulsa **Crear credenciales para ChatGPT**. El formulario propone el callback estable admitido con issuer identification; puede editarse si ChatGPT muestra otro.
3. Copia el ID y el secreto mostrados una sola vez a ChatGPT. Conexión HTTPS directa a `https://mcp.mortadelo.screenart.es/mcp`, OAuth, cliente definido por el usuario, `client_secret_basic`, ámbito `musedock:read`. Ámbitos base vacíos; sin OIDC ni túnel.
4. Completa el consentimiento de solo lectura en el panel privado con tu sesión/MFA.
5. Pide a ChatGPT: **«Consulta Mortadelo, lista los nodos y comprueba el estado de Filemon y Nitro»**.

Si visitas `/mcp` desde tu navegador y recibes 403, es el filtro de redes funcionando. Las rutas públicas de descubrimiento sí pueden abrirse desde el navegador. No se ha probado todavía el flujo de tu cuenta ChatGPT, porque su autorización requiere tu interacción; la puerta HTTPS y OAuth ya están listas.

Documentación de callback y autenticación: https://developers.openai.com/plugins/build/auth .

Antes de la activación: `VALIDATION-before-activation.md`. Copia previa de archivos: `storage/backups/direct-mcp-20261010T171002Z/manifest.json`. Rollback de acceso: `php bin/direct-mcp.php --disconnect`, o el botón de desconexión del panel; revoca los tokens y retira únicamente esta ruta Caddy.

### Mejora de ajustes MCP — 2026-10-10

- Modalidades separadas en pestañas: directa, túnel OpenAI y token/SSH.
- Credenciales nuevas con secreto oculto, mostrar/ocultar, copia por campo y retirada del DOM tras confirmación.
- Configuración HTTPS y alta de ChatGPT plegadas cuando ya existen clientes.
- Permiso de lectura por cliente: quitarlo desactiva su servidor OAuth, elimina ámbitos y revoca todos los consentimientos/tokens. Restaurarlo exige nuevo consentimiento. Clientes revocados no se pueden reactivar mediante este control. Escritura permanece deshabilitada.
- Validación: 65 pruebas del registro/OAuth/redes, 62 HTTP/Caddy aisladas y 60 OAuth/permisos anteriores (187 en total), sintaxis PHP y JavaScript correctas. Descubrimiento HTTPS público responde y el nuevo JavaScript se sirve íntegro desde el panel.
- No se han modificado los registros ni permisos de clientes de producción durante estas pruebas. No se ha realizado una comprobación visual con navegador autenticado.

### Escritura OAuth — versión 1.0.361 — 2026-10-10

- Nuevos ámbitos por cliente `musedock:write` y `musedock:dns`, con consentimiento específico y sin ampliar clientes existentes. El cliente ChatGPT real conserva únicamente `musedock:read`.
- Cola privada por cliente, máximo 20 solicitudes de 10 minutos. `apply=true` no ejecuta desde MCP. Ejecución únicamente tras aprobación autenticada y CSRF válido; revalidación de consentimiento, rol, ámbitos, límites generales y plan. Las solicitudes consumidas no se repiten ni se reintentan automáticamente tras fallos parciales.
- 43 pruebas aisladas de escritura/cola, 65 del registro y OAuth directo, 71 HTTP/Caddy, 60 OAuth previo y 41 HTTP/túnel: 280 comprobaciones. La escritura usa un ejecutor simulado; no se publicaron DNS ni se crearon buzones o bases reales durante las pruebas.
- Metadatos HTTPS públicos verificados: anuncian lectura, escritura y DNS. PHP y JavaScript sin errores de sintaxis. Configuración del panel actualizada a 1.0.361 y changelog correspondiente.
- Pendiente de prueba con cuenta real: seleccionar ámbitos de escritura en la ficha del cliente, volver a autorizar el conector y confirmar una operación de prueba desde el panel privado.
