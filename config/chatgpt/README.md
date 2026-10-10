# ChatGPT en MuseDock Panel: OAuth + túnel privado

Primera versión: solo consulta servidores. MuseDock CMS es otro producto y queda fuera.
El MCP Bearer y stdio existentes mantienen su autenticación y permisos. ChatGPT tiene
un endpoint independiente `/api/mcp/chatgpt`, accesible solo desde loopback sin cabeceras
de proxy y con tokens OAuth. No publicar este endpoint en Caddy ni abrir 8444.

## Requisitos

- PHP con PDO SQLite, curl y PostgreSQL; panel servido detrás de Caddy y en loopback.
- Cuenta ChatGPT capaz de añadir MCP personalizados y organización Platform con
  permisos Tunnels Read + Manage para crear el túnel y Read + Use para ejecutarlo.
  Una suscripción Pro no demuestra por sí sola esos permisos. Verificar acceso y
  condiciones de Platform en la cuenta antes de generar credenciales.
- Dominio dedicado para OAuth con DNS y certificado público válido. El puerto 443
  ya usado por los sitios servirá solo las rutas de `oauth.Caddyfile.example`.
- El navegador del usuario debe poder entrar al panel desde su red/IP autorizada
  para aprobar OAuth. Usa el login y MFA existentes, sin publicar el login del panel.

## Instalación

1. Crear el túnel en Platform y asociarlo a la cuenta/workspace de ChatGPT.
2. Obtener `tunnel-client` del repositorio oficial `openai/tunnel-client`, con origen
   e integridad verificados. Usar una release comprobada o compilar un commit
   revisado con dependencias verificadas. No ejecutar descargas a ciegas. El instalador
   exige el SHA256 esperado de un binario previamente verificado (un hash calculado
   de una descarga sin verificar su procedencia no acredita su origen).
3. Como root: `bash bin/install-chatgpt-tunnel.sh /ruta/tunnel-client SHA256_VERIFICADO`.
   Crea el usuario dedicado y servicio endurecido. No inicia el servicio ni toca firewall.
4. Preparar `oauth.Caddyfile.example`: sustituir host OAuth y puerto interno del panel.
   Validar el Caddyfile completo y recargar Caddy después de revisar el bloque. No
   cambiar las reglas del panel ni publicar rutas distintas. Comprobar que `/login`,
   `/settings/mcp`, `/api/mcp` y `/api/mcp/chatgpt` dan 404 en el dominio OAuth.
5. Ajustes → MCP → Conexión con ChatGPT: guardar dominio OAuth, URL privada del panel,
   callback exacto mostrado en ChatGPT, tunnel_id y clave runtime. El recurso inicial
   puede ser el identificador HTTPS lógico `https://DOMINIO_OAUTH/api/mcp/chatgpt`
   para arrancar la detección (no publica ese endpoint).
   El recurso es un identificador HTTPS estable del MCP (no la URL local HTTP). Debe
   coincidir exactamente con el `resource` solicitado por ChatGPT tras la discovery
   del túnel. En la pantalla avanzada de ChatGPT, copiar el campo Recurso detectado;
   desconectar en el panel, actualizar ese valor y volver a conectar antes de autorizar.
   Este ajuste inicial es necesario porque el servicio reescribe el identificador local.
   Revisar esa correspondencia durante el canario; no aceptar recursos
   arbitrarios ni desactivar la validación de audiencia para resolver un error.
6. El perfil generado usa HTTP **solo en loopback** al puerto interno del panel,
   dentro del mismo host. No elude un certificado HTTPS. La comunicación externa
   del túnel usa HTTPS con validación TLS normal. La API key va en archivo 0640
   exclusivo del grupo del túnel, fuera de argumentos y repositorio.
7. Ejecutar `tunnel-client doctor --profile-file RUTA/storage/chatgpt/profile.yaml`
   con la identidad del servicio. Comprobar discovery, recurso y TLS del dominio OAuth.
8. Activar el MCP existente y el interruptor Conectar con ChatGPT. Este interruptor
   solo afecta al nuevo acceso, nunca habilita escritura ni cambia el token antiguo.
9. En ChatGPT elegir Túnel + OAuth + cliente definido por el usuario, copiar ID,
   secreto mostrado una vez, callback exacto, `client_secret_basic` y `musedock:read`.
   DCR/CIMD/OIDC no se anuncian en esta versión. Autorizar en el panel.

## Seguridad y ciclo de vida

OAuth usa código de un solo uso (120 segundos), PKCE S256 obligatorio, estado,
redirect exacto y recurso exacto. Access tokens opacos: 1 hora. Refresh tokens
rotatorios: hasta 7 días desde la autorización; reutilizarlos revoca toda la familia.
Solo hashes de códigos, access/refresh tokens y secreto cliente se guardan en SQLite
privado fuera de public. La clave runtime debe estar disponible al cliente local.
Los permisos de administrador se revalidan en la base de datos al intercambiar o
usar tokens; desactivar o degradar al usuario corta su acceso. No se replica esta
configuración con `panel_settings`, ni sus credenciales a Filemon/Nitro.

La lista explícita de herramientas de ChatGPT no incorpora automáticamente herramientas
futuras. Escritura, DNS, cambios de contraseña y operaciones administrativas quedan
bloqueados aunque el MCP tradicional permita escribir. La lectura del cluster usa
su API existente y respeta los interruptores de consultas reenviadas del destino.
Se conservan auditoría y fail2ban. El servicio no registra tráfico bruto ni secretos;
la interfaz muestra readiness, comprobación y última llamada autenticada. Una sonda
ready no acredita que la cuenta Pro haya completado el flujo OAuth.

## Validación y prueba real

`php tests/chatgpt_oauth_test.php` y `php tests/chatgpt_http_test.php` usan estado y
servidor aislados sin la base de datos de producción. Para incluir el transporte
oficial: `MUSEDOCK_TEST_TUNNEL_CLIENT=/ruta/binario-verificado php tests/chatgpt_http_test.php`.
Levanta el modo `dev proxy` oficial y un OAuth HTTPS local con CA temporal; comprueba
discovery, reescritura del recurso, forwarding del Bearer y bloqueo de escritura.
No prueba permisos de Platform ni sustituye la conexión con la cuenta real.
Revisar además configuración
systemd y Caddy antes de instalarlas. Desde la cuenta real, consultar `panel_info`,
`list_nodes` y `node_status` con cada ID real de Mortadelo, Filemon y Nitro. No inventar
IDs ni habilitar consultas en nodos sin revisión. Intentar una herramienta de escritura:
debe fallar incluso con escritura tradicional activada. Probar desconexión, token
revocado, restart, pérdida del túnel y recuperación sin cambios al firewall.

## Rollback

Desconectar en Ajustes → MCP: primero desactiva acceso y revoca todos los grants,
después detiene el servicio. Aunque systemctl falle, los tokens quedan inutilizados.
Retirar exclusivamente el bloque OAuth añadido a Caddy y validar/recargar. Detener y
eliminar el servicio dedicado si se desinstala; no cambiar firewall ni servicios del
panel. Al conectar, el panel habilita systemd para que el túnel vuelva tras reinicio;
al desconectar, lo deshabilita además de detenerlo. Si el servicio falla, el MCP
sigue privado y no se modifica el firewall. Mantener una copia del cambio de código para revertir solo esos archivos.

Fuentes: https://developers.openai.com/plugins/build/auth y
https://developers.openai.com/api/docs/guides/secure-mcp-tunnels.
