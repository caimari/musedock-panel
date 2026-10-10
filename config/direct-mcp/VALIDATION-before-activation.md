# MCP directo: resultado y activación pendiente

Validación: 2026-10-10 19:14 CEST. Servidor: Mortadelo. Configuración preparada en Ajustes → MCP, desactivada.

## Resultado de las pruebas

| Prueba aislada | Comprobaciones | Resultado |
| --- | ---: | --- |
| `tests/direct_mcp_test.php` | 56 | Correcto |
| `tests/direct_mcp_http_test.php` | 60 | Correcto |
| `tests/chatgpt_oauth_test.php` | 60 | Correcto |
| `tests/chatgpt_http_test.php`, con el cliente oficial del túnel | 41 | Correcto |
| **Total** | **217** | **Todas correctas** |

Las pruebas usan SQLite temporal, base de datos simulada, sesiones separadas y listeners exclusivamente en loopback. La instancia Caddy de pruebas tiene su API administrativa y persistencia desactivadas. La actualización de rutas se prueba contra una API simulada; ninguna prueba publica DNS ni cambia el Caddy real.

Se han comprobado: callback exacto y consentimiento privado; PKCE; aislamiento de secretos y tokens; audiencia; bloqueo de escritura y ámbitos de escritura; separación de redes por cliente; códigos de un uso; refresh rotatorio y revocación por replay; administrador activo; CSRF; revocación individual y total; expiración de rangos; cabeceras falsificadas; bloqueo de administración, archivos y rutas extra; límites de cuerpo 16 KiB/1 MiB; conservación de rutas ajenas ante cambios concurrentes mediante ETag; rollback de la ruta propia; ausencia de secretos en logs y vistas; regresión del MCP previo y del túnel oficial.

Sintaxis PHP verificada en los 19 archivos nuevos o modificados de implementación/pruebas. `git diff --check` sin errores. Se han conservado las rutas y la documentación de la funcionalidad DNS que se estaba modificando en paralelo.

## Comprobaciones del servidor real, sin activación

- Cloudflare: zona `screenart.es`, cuenta configurada disponible. `mortadelo.screenart.es` es A a `207.180.246.223`, sin proxy naranja.
- `mcp.mortadelo.screenart.es`: no existe registro DNS. No se ha creado ni modificado ningún registro.
- Configuración local: `https://mcp.mortadelo.screenart.es`, autorización privada en `https://mortadelo.screenart.es:8444`, clientes todavía sin registrar y acceso desactivado.
- Fuentes oficiales verificadas: OpenAI **283** rangos; Anthropic **1** rango de salida. Se guardan localmente para la preparación, sin aplicar reglas públicas.
- Caddy real: ruta `musedock-direct-mcp` ausente. No se ha cargado la configuración nueva.
- Listener 8444 y su configuración completos: hash antes/después `350c9dcf09e21a8813860437c75967cefb036a1c93cf42ecfbf67a635bf0487e`, sin cambios.
- Panel, Caddy y fail2ban activos. No se han ejecutado cambios de firewall ni alterado `.env`, credenciales antiguas o configuración del túnel.
- Smoke HTTP local: MCP y metadatos desactivados devuelven 404; ajustes y consentimiento privados exigen sesión (302 al login).
- Estado OAuth local protegido: directorio 0700 y registro SQLite 0600, excluidos de Git y de los ajustes replicados.
- Copia previa de archivos: `storage/backups/direct-mcp-20261010T171002Z/manifest.json`. Cambios concurrentes del alta DNS preservados tras la instalación.

## Acción que requiere confirmación del propietario

Crear únicamente el A DNS **sin proxy** `mcp.mortadelo.screenart.es → 207.180.246.223` y cargar en Caddy la ruta HTTPS dedicada. El comando de activación exige la marca explícita `--approved`; sin ella se ha verificado que se detiene antes de cualquier operación DNS/Caddy.

Caddy admite únicamente `/mcp` desde los rangos permitidos y las rutas OAuth/metadatos necesarias para autorizar desde un navegador. El resto responde 404. Cada acceso a herramientas necesita además el token OAuth del cliente correcto, sus permisos y un administrador activo. No hay herramientas de escritura. No cambia el firewall ni el puerto 8444.

Tras la confirmación: comprobar certificado público y descubrimiento; registrar los clientes con el callback que muestra cada asistente; autorizar desde el panel; consultar Mortadelo y verificar Filemon y Nitro desde la cuenta real. **No se ha probado todavía una conexión real con una cuenta ChatGPT o Claude**, porque requiere habilitar primero ese acceso público y completar el consentimiento del usuario.
