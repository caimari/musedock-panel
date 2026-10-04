<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\FailoverSafetyService;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\PgClusterService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

/**
 * Herramientas MCP de CLUSTER y FAILOVER.
 *
 * - failover_preflight (lectura): ¿está este servidor listo para un relevo? Rol,
 *   nodos y testigos, configuración de failover, cuentas de Cloudflare (sin tokens),
 *   estado de las réplicas de PostgreSQL y Redis, y una lista de problemas en llano.
 * - failover_configure (escritura, en el master): define primario + servidor de
 *   relevo con sus IPs públicas y el modo (manual/semiauto/auto), y lo propaga.
 * - replication_adopt (escritura): registra en el panel una réplica de PostgreSQL
 *   que ya funciona (montada a mano), SIN tocar datos, para que promover/degradar
 *   sepan con qué usuario y contra quién trabajar.
 * - cluster_node_services (escritura, en el master): qué hace cada nodo (web, mail).
 *
 * Mismas reglas que el resto de escrituras MCP: interruptor mcp_allow_write, solo
 * en el panel local, y sin `apply: true` solo devuelven el plan.
 */
final class McpClusterTools
{
    public static function definitions(): array
    {
        $apply = ['type' => 'boolean', 'description' => 'false (por defecto) = solo devuelve el plan. true = lo ejecuta. Muestra primero el plan al usuario y pide su confirmación.'];
        $nodeArg = ['node' => ['type' => 'string', 'description' => 'Opcional. Id o nombre de un nodo del cluster para ejecutarlo en él. Omitir = este servidor.']];
        $o = static fn(array $props, array $req = []) => array_filter([
            'type' => 'object', 'properties' => (object)$props, 'required' => $req ?: null, 'additionalProperties' => false,
        ], static fn($v) => $v !== null);

        return [
            'failover_preflight' => [
                'write' => false,
                'title' => 'Comprobar si el failover está listo',
                'description' => 'Revisa si ESTE servidor está preparado para un relevo (failover): rol en el cluster, nodos y testigos disponibles (anti split-brain), servidores de failover y modo, cuentas de Cloudflare con sus zonas (sin tokens), qué clusters de PostgreSQL y Redis se promoverían (simulación), scripts de relevo de /etc/musedock/hooks/{promote,demote}.d y si son válidos, salud de los servidores vigilados y una lista de problemas en lenguaje llano. Ejecútalo en el master y en el slave. Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'failover_configure' => [
                'write' => true, 'destructive' => true,
                'title' => 'Configurar el failover',
                'description' => 'En el MASTER: define este servidor como primario y un nodo del cluster como servidor de relevo, con sus IPs PÚBLICAS (las del DNS) y el modo: manual (solo avisa), semiauto (el slave promueve y cambia el DNS si el master cae; la vuelta es manual) o auto. Guarda y propaga la configuración a los slaves. Cada panel usa SUS cuentas de Cloudflare para el cambio de DNS. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'failover_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo (list_nodes) que tomará el relevo'],
                    'primary_ip' => ['type' => 'string', 'description' => 'IP pública de ESTE servidor (la de los registros A actuales)'],
                    'failover_ip' => ['type' => 'string', 'description' => 'IP pública del servidor de relevo'],
                    'mode' => ['type' => 'string', 'enum' => ['manual', 'semiauto', 'auto'], 'description' => 'Por defecto semiauto'],
                    'port' => ['type' => 'integer', 'description' => 'Puerto que se vigila (por defecto 443)'],
                    'replace' => ['type' => 'boolean', 'description' => 'Sustituir una configuración de servidores que ya exista'],
                    'apply' => $apply,
                ], ['failover_node', 'primary_ip', 'failover_ip']),
            ],
            'replication_adopt' => [
                'write' => true,
                'title' => 'Adoptar una réplica existente (PostgreSQL y MariaDB/MySQL)',
                'description' => 'Registra en el panel una réplica en streaming de PostgreSQL que YA funciona (montada a mano), sin tocar datos ni reiniciar nada. Detecta el rol (master si tiene réplicas conectadas, slave si está en recuperación), el usuario de réplica y el otro extremo. La contraseña del usuario de réplica se lee de ~postgres/.pgpass (slave) o de password_file (master), se guarda cifrada y nunca se devuelve. También registra la réplica de MariaDB/MySQL si la hay (SHOW SLAVE STATUS en el slave, hilos Binlog Dump en el master). Necesario para que promover/degradar funcionen. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'password_file' => ['type' => 'string', 'description' => 'Opcional. Fichero con la contraseña del usuario de réplica (p. ej. /root/pg-replicador.pass). Solo rutas bajo /root/ o /var/lib/postgresql/.'],
                    'apply' => $apply,
                ]),
            ],
            'cluster_pairing_open' => [
                'write' => true,
                'title' => 'Abrir emparejamiento (en el master)',
                'description' => 'PASO 1 para unir dos paneles sin pasar secretos por el chat. En el futuro MASTER: abre durante 30 minutos la recepción de solicitudes de emparejamiento. Después, en el futuro slave, cluster_pair_request. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o(['apply' => $apply]),
            ],
            'cluster_pair_request' => [
                'write' => true,
                'title' => 'Pedir unirse a un master (en el slave)',
                'description' => 'PASO 2. En el futuro SLAVE: envía al master una solicitud de emparejamiento con el token de cluster de este panel (viaja por TLS de panel a panel, nunca por el chat) y devuelve un CÓDIGO corto. Ese código se confirma en el master con cluster_pair_approve. El master debe tener abierta la ventana (cluster_pairing_open) y su 8444 debe admitir la IP de este servidor. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'master_url' => ['type' => 'string', 'description' => 'URL pública del panel master, p. ej. https://master.dominio.com:8444 (certificado válido)'],
                    'name' => ['type' => 'string', 'description' => 'Nombre con el que aparecerá este nodo (por defecto, el hostname)'],
                    'self_url' => ['type' => 'string', 'description' => 'URL por la que el master llegará a ESTE panel (por defecto la de su hostname de panel)'],
                    'services' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['web', 'mail']], 'description' => 'Por defecto ["web"]'],
                    'apply' => $apply,
                ], ['master_url']),
            ],
            'cluster_pair_pending' => [
                'write' => false,
                'title' => 'Solicitudes de emparejamiento pendientes',
                'description' => 'En el MASTER: muestra si la ventana de emparejamiento está abierta y las solicitudes recibidas (código, nombre, URL, IP de origen, servicios). No muestra tokens. Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'cluster_pair_approve' => [
                'write' => true, 'destructive' => true,
                'title' => 'Aprobar emparejamiento (en el master)',
                'description' => 'PASO 3. En el MASTER: aprueba la solicitud con ese código. Comprueba que llega a la API del slave con su token, lo registra como nodo y le envía el token del master; el slave queda con rol slave y este panel con rol master. Comprueba antes con el usuario que el código coincide con el que vio en el slave. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'code' => ['type' => 'string', 'description' => 'Código XXXX-XXXX que devolvió cluster_pair_request en el slave'],
                    'master_url' => ['type' => 'string', 'description' => 'URL de ESTE panel con el MISMO host que usó el slave en su master_url (por defecto la del hostname del panel)'],
                    'master_public_ip' => ['type' => 'string', 'description' => 'IP pública de este servidor (la que vigilará el failover del slave)'],
                    'apply' => $apply,
                ], ['code']),
            ],
            'cluster_queue' => [
                'write' => false,
                'title' => 'Cola de sincronización del cluster',
                'description' => 'En el MASTER: estado de la cola de operaciones hacia los nodos (pendientes, en curso, completadas, fallidas, canceladas) y las últimas operaciones con su nodo, acción, dominio, intentos y error. Sirve para ver por qué algo no llega a un slave. No muestra el contenido de las operaciones (puede llevar hashes). Solo lectura.',
                'inputSchema' => $o([
                    'status' => ['type' => 'string', 'enum' => ['all', 'pending', 'processing', 'completed', 'failed', 'cancelled'], 'description' => 'Filtrar por estado (por defecto all)'],
                    'limit' => ['type' => 'integer', 'description' => 'Máximo de operaciones a listar (por defecto 30, máx. 200)'],
                ]),
            ],
            'cluster_sync_hostings' => [
                'write' => true,
                'title' => 'Sincronizar hostings a un nodo',
                'description' => 'En el MASTER: lo mismo que el botón "Sincronizar Todo". Encola hacia el nodo el alta de cada hosting (el slave lo crea, o lo ADOPTA si ya tiene el usuario con el mismo UID y su carpeta), sus alias/redirecciones y sus bases de datos registradas. NUNCA borra nada en el nodo. Opcional: solo un dominio. Luego se sigue con cluster_queue. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'target_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo (list_nodes)'],
                    'domain' => ['type' => 'string', 'description' => 'Opcional: sincronizar solo este hosting'],
                    'apply' => $apply,
                ], ['target_node']),
            ],
            'hosting_php_settings' => [
                'write' => true,
                'title' => 'Límites de PHP de un hosting',
                'description' => 'Consulta o cambia los límites de PHP del pool de una cuenta de hosting (memory_limit, upload_max_filesize, post_max_size, max_execution_time, max_input_vars), lo mismo que Cuentas → Editar → PHP. Sin valores, solo muestra los actuales. Comprueba la configuración de PHP-FPM antes de recargar y, si falla, lo deja como estaba. Solo en el master o en un servidor independiente. Requiere "Permitir acciones que modifican" para aplicar.',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Dominio principal de la cuenta de hosting'],
                    'memory_limit' => ['type' => 'string', 'description' => 'p. ej. 256M'],
                    'upload_max_filesize' => ['type' => 'string', 'description' => 'p. ej. 50M'],
                    'post_max_size' => ['type' => 'string', 'description' => 'p. ej. 55M (algo más que upload_max_filesize)'],
                    'max_execution_time' => ['type' => 'string', 'description' => 'segundos, p. ej. 300'],
                    'max_input_vars' => ['type' => 'string', 'description' => 'p. ej. 3000'],
                    'apply' => $apply,
                ], ['domain']),
            ],
            'server_profile' => [
                'write' => false,
                'title' => 'Perfil de este servidor (léelo primero)',
                'description' => 'Solo lectura. Todo lo que hay que saber de este servidor antes de actuar: nombre, rol en el cluster, IPs, a qué nombre apuntan por defecto los dominios nuevos (dns_default_target, como CNAME; el proxy de Cloudflare es opcional), con quién hace el relevo (primario/relevo y nodos del cluster), cuentas de Cloudflare, correo, avisos, monitorización y qué permite el MCP aquí. Llámala al conectar por primera vez.',
                'inputSchema' => $o([]),
            ],
            'server_profile_set' => [
                'write' => true,
                'title' => 'Cambiar el destino por defecto de los dominios nuevos',
                'description' => 'Fija dns_default_target: el nombre al que apuntan por CNAME los dominios nuevos de este servidor (p. ej. srv1.ejemplo.com). No cambia ningún DNS existente. Primero sin apply.',
                'inputSchema' => $o(['dns_default_target' => ['type' => 'string'], 'apply' => $apply], ['dns_default_target']),
            ],
            'monitor_status' => [
                'write' => false,
                'title' => 'Estado de la monitorización',
                'description' => 'Solo lectura. Si la monitorización está activa y funcionando (última lectura), últimos valores (CPU, RAM, disco, red…), umbrales de aviso, avisos de las últimas 24 h, problemas que siguen abiertos, errores recientes del recolector y si los avisos por correo/Telegram salen.',
                'inputSchema' => $o([]),
            ],
            'monitor_configure' => [
                'write' => true,
                'title' => 'Activar o ajustar la monitorización',
                'description' => 'Activa o desactiva la monitorización y ajusta sus umbrales de aviso (CPU, RAM y disco en %, temperatura de GPU) y cada cuántas horas se repite un aviso que sigue. Primero sin apply.',
                'inputSchema' => $o([
                    'enabled' => ['type' => 'boolean'],
                    'cpu' => ['type' => 'integer', 'description' => '% de CPU que dispara aviso'],
                    'ram' => ['type' => 'integer'],
                    'disk' => ['type' => 'integer'],
                    'gpu_temp' => ['type' => 'integer', 'description' => '°C'],
                    'repeat_hours' => ['type' => 'integer', 'description' => 'Repetir un aviso que sigue cada N horas (por defecto 12)'],
                    'apply' => $apply,
                ]),
            ],
            'cloudflare_tokens' => [
                'write' => false,
                'title' => 'Qué tokens de Cloudflare usa este servidor y qué pueden hacer',
                'description' => 'Solo lectura. Para cada token que usa este servidor (cuentas de Cloudflare del panel y CLOUDFLARE_API_TOKEN de Caddy en /etc/default/caddy): estado y caducidad según Cloudflare, id corto, a cuántas zonas llega y si puede leer DNS y reglas de Email Routing (lo prueba con lecturas). Nunca muestra el token. Sirve para reconocer cada token en el panel de Cloudflare (donde todos se llaman igual) y saber cuáles están en uso antes de limpiar.',
                'inputSchema' => $o([]),
            ],
            'cloudflare_caddy_token_sync' => [
                'write' => true,
                'title' => 'Poner el token bueno en Caddy de este nodo y de los slaves',
                'description' => 'En el MASTER. Elige el token de Caddy (CLOUDFLARE_API_TOKEN, certificados DNS-01): el de la cuenta de Cloudflare del panel que contiene la zona del dominio del panel. Lo compara con el de Caddy aquí y en cada slave y SOLO donde sea distinto lo escribe y reinicia Caddy (antes comprueba con Cloudflare que está activo). force=true reescribe y reinicia en todos. Sin apply devuelve el plan (qué nodos cambiarían aquí; los slaves lo deciden ellos). Es lo mismo que pulsar Guardar en Cuentas Cloudflare. Nunca muestra el token. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'force' => ['type' => 'boolean', 'description' => 'Reescribir y reiniciar Caddy aunque ya tengan el token bueno'],
                    'apply' => $apply,
                ]),
            ],
            'cloudflare_email_routing' => [
                'write' => false,
                'title' => 'Enrutamiento de correo de Cloudflare (Email Routing)',
                'description' => 'Solo lectura. Por cada zona de las cuentas de Cloudflare del panel (o solo la de `domain`): si tiene Email Routing activado, sus reglas (dirección → reenvío a), el catch-all y a qué servidores apunta su MX. Dice además si el dominio ya existe como dominio de correo en este panel. Sirve para ver qué dominios reciben correo por Cloudflare antes de pasarlos al correo propio.',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Opcional: solo este dominio'],
                    'only_enabled' => ['type' => 'boolean', 'description' => 'Solo los que tienen Email Routing activado (por defecto true)'],
                ]),
            ],
            'dns_records' => [
                'write' => false,
                'title' => 'Registros DNS de un dominio (Cloudflare)',
                'description' => 'Solo lectura. Lista los registros DNS de la zona de un dominio en las cuentas de Cloudflare de este panel (nombre, tipo, contenido, proxy, TTL). Si la zona no aparece, refresca la lista de zonas de las cuentas (por si se acaba de registrar o añadir).',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string', 'description' => 'Dominio o subdominio (se busca su zona)'],
                    'type' => ['type' => 'string', 'description' => 'Opcional: filtrar por tipo (A, CNAME, TXT, MX...)'],
                ], ['domain']),
            ],
            'dns_record_set' => [
                'write' => true,
                'title' => 'Crear o modificar un registro DNS (Cloudflare)',
                'description' => 'Crea un registro DNS o modifica el que ya existe con ese nombre y tipo, en la zona del dominio en las cuentas de Cloudflare de este panel. Tipos: A, AAAA, CNAME, TXT, MX. NUNCA borra: si para hacerlo habría que borrar otro registro (p. ej. un A donde quieres un CNAME), se niega y lo explica. TXT y MX admiten varios valores: si ya existe ese valor no hace nada, y si no, lo AÑADE sin tocar los demás. Primero sin apply (plan), mostrarlo y pedir confirmación. Requiere "Permitir acciones que modifican" y "Permitir editar DNS en Cloudflare" en Ajustes → MCP.',
                'inputSchema' => $o([
                    'name' => ['type' => 'string', 'description' => 'Nombre completo del registro: midominio.com (raíz), www.midominio.com, sub.midominio.com'],
                    'type' => ['type' => 'string', 'enum' => ['A', 'AAAA', 'CNAME', 'TXT', 'MX']],
                    'content' => ['type' => 'string', 'description' => 'IP, nombre de destino (CNAME/MX) o texto (TXT)'],
                    'proxied' => ['type' => 'boolean', 'description' => 'Nube naranja (solo A, AAAA, CNAME). Por defecto: el valor actual o false'],
                    'ttl' => ['type' => 'integer', 'description' => '1 = automático (por defecto)'],
                    'priority' => ['type' => 'integer', 'description' => 'Prioridad (solo MX), por defecto 10'],
                    'apply' => $apply,
                ], ['name', 'type', 'content']),
            ],
            'notify_status' => [
                'write' => false,
                'title' => 'Avisos del panel: cómo y a quién',
                'description' => 'Solo lectura. Dice si este panel puede enviar avisos (correo y/o Telegram), por dónde (servidor SMTP, remitente, destinatario; nunca contraseñas ni tokens), si los avisos del monitor están activados y el último resultado de la vigilancia de réplicas. Con `node` se consulta otro nodo.',
                'inputSchema' => $o([]),
            ],
            'notify_configure' => [
                'write' => true,
                'title' => 'Configurar los avisos del panel',
                'description' => 'Configura el correo (SMTP) y/o Telegram por los que este panel envía avisos, y los activa. Los secretos NUNCA pasan por la conversación: la contraseña SMTP y el token de Telegram se leen de un fichero de este servidor (solo bajo /root/) que el usuario crea con `read -rs`; se guardan cifrados. Sin apply devuelve el plan; con test=true envía un aviso de prueba tras aplicar. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'email_to' => ['type' => 'string', 'description' => 'Destinatario de los avisos'],
                    'smtp_host' => ['type' => 'string'],
                    'smtp_port' => ['type' => 'integer', 'description' => '587 (STARTTLS) o 465 (SSL)'],
                    'smtp_encryption' => ['type' => 'string', 'enum' => ['tls', 'ssl', 'none']],
                    'smtp_user' => ['type' => 'string'],
                    'smtp_from' => ['type' => 'string', 'description' => 'Remitente (por defecto smtp_user)'],
                    'smtp_from_name' => ['type' => 'string'],
                    'smtp_pass_file' => ['type' => 'string', 'description' => 'Fichero bajo /root/ con la contraseña SMTP (una línea)'],
                    'smtp2_host' => ['type' => 'string', 'description' => 'SMTP SECUNDARIO: se usa si el principal falla o rechaza (p. ej. cupo diario agotado). Vacío = quitarlo'],
                    'smtp2_port' => ['type' => 'integer'],
                    'smtp2_encryption' => ['type' => 'string', 'enum' => ['tls', 'ssl', 'none']],
                    'smtp2_user' => ['type' => 'string'],
                    'smtp2_from' => ['type' => 'string', 'description' => 'Remitente para el secundario (si ese proveedor no acepta el del principal)'],
                    'smtp2_pass_file' => ['type' => 'string', 'description' => 'Fichero bajo /root/ con la contraseña del SMTP secundario'],
                    'telegram_token_file' => ['type' => 'string', 'description' => 'Fichero bajo /root/ con el token del bot de Telegram'],
                    'telegram_chat_id' => ['type' => 'string'],
                    'enable_email' => ['type' => 'boolean', 'description' => 'Activar (true) o desactivar (false) los avisos por correo con lo ya configurado'],
                    'enable_telegram' => ['type' => 'boolean', 'description' => 'Activar o desactivar los avisos por Telegram'],
                    'copy_to_nodes' => ['type' => 'boolean', 'description' => 'En el master: copiar esta configuración de avisos (secretos incluidos, por el canal autenticado del cluster) a todos sus nodos'],
                    'test' => ['type' => 'boolean', 'description' => 'Enviar un aviso de prueba tras aplicar'],
                    'apply' => $apply,
                ]),
            ],
            'page_check' => [
                'write' => false,
                'title' => 'Comprobar una página del panel (error real)',
                'description' => 'Solo lectura. Carga una página del panel (petición GET, como el navegador) como el primer administrador y devuelve el código HTTP y el error real si falla: excepción, error fatal o aviso de PHP, con fichero y línea. Para cuando una página da 500 y el registro de errores no dice nada. Con `node` se comprueba en otro nodo del cluster.',
                'inputSchema' => $o(['path' => ['type' => 'string', 'description' => 'Ruta del panel, p. ej. /domains o /settings/cluster']] + $nodeArg, ['path']),
            ],
            'panel_errors' => [
                'write' => false,
                'title' => 'Últimos errores de los registros del panel',
                'description' => 'Solo lectura. Las últimas líneas con error, excepción o fallo de los registros del panel (panel-error.log, panel.log, cluster-worker.log, failover-worker), con las cadenas largas que podrían ser tokens tapadas. Con `node` se mira en otro nodo.',
                'inputSchema' => $o(['lines' => ['type' => 'integer', 'description' => 'Cuántas líneas por registro (por defecto 30, máx. 200)']] + $nodeArg, []),
            ],
            'witnesses_status' => [
                'write' => false,
                'title' => 'Testigos externos y vigilante de entrada',
                'description' => 'Solo lectura. Los testigos externos ("solo ojos") registrados en ESTE panel y si responden ahora: por cada uno, sus comprobaciones (qué dirección mira, si llega, latencia media y pérdidas). También el vigilante de entrada de este panel (qué servidores vigila, su entrada normal y alternativa, en qué modo está cada uno y cuántos registros DNS tiene movidos) y las alertas de testigos sin responder. Nunca muestra claves. Para registrar o quitar testigos: Ajustes → Testigos o bin/witness.php. Con `node`, los de otro nodo.',
                'inputSchema' => $o($nodeArg),
            ],
            'failover_dns_plan' => [
                'write' => false,
                'title' => 'Qué DNS cambiaría un relevo',
                'description' => 'Solo lectura. Con las cuentas de Cloudflare de ESTE panel, lista por zona los registros A que hoy apuntan a la IP del servidor primario y que un relevo cambiaría, lo que se mueve con ellos por CNAME, y qué NO se movería: dominios de hostings y TODO lo que sirve Caddy aquí (también lo añadido por otras aplicaciones, p. ej. tenants del CMS). Ejecútalo en el MASTER para ver todo lo que sirve (las cuentas de Cloudflare son las mismas en el nodo de relevo). No cambia nada.',
                'inputSchema' => $o([]),
            ],
            'firewall_check_ip' => [
                'write' => false,
                'title' => '¿Qué puede hacer esta IP contra el servidor?',
                'description' => 'Solo lectura, en tiempo real. Simula una conexión nueva desde una IP concreta a cada puerto en escucha (o al indicado) con las reglas actuales de iptables, incluidas las listas ipset: a qué servicios llegaría y cuáles le bloquean (y qué regla). Dice también si es un origen de confianza del panel y si fail2ban la tiene bloqueada. Con `node` se consulta otro nodo.',
                'inputSchema' => $o([
                    'ip' => ['type' => 'string', 'description' => 'IPv4 a comprobar'],
                    'port' => ['type' => 'integer', 'description' => 'Opcional: solo este puerto'],
                ], ['ip']),
            ],
            'firewall_audit' => [
                'write' => false,
                'title' => 'Auditoría del firewall',
                'description' => 'Revisa si el servidor está realmente protegido. No mira reglas sueltas: simula la llegada de una conexión nueva a cada puerto en escucha (TCP y UDP) desde una IP cualquiera de internet y desde cada origen autorizado, siguiendo las cadenas de iptables. Dice por puerto si está abierto a todo internet, solo a ciertas IPs o cerrado, y avisa de: servicios sensibles expuestos (bases de datos, Redis, panel, APIs), IPv6 sin proteger, reglas que aceptan todo, orígenes con acceso a todos los puertos que no son de confianza, puertos de Docker (no pasan por INPUT) y ufw mezclado con iptables propio. Con `node` se audita otro nodo. Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'fail2ban_manage' => [
                'write' => true,
                'title' => 'fail2ban: ver bloqueos, desbloquear una IP y lista blanca',
                'description' => 'Sin `ip`: solo lectura, lista por jail las IPs bloqueadas ahora y la lista blanca (ignoreip). Con `ip`: plan para desbloquearla en todos los jails donde esté y, con whitelist=true, añadirla a la lista blanca para que no se vuelva a bloquear (p. ej. un servidor propio que se conecta a los buzones por IMAP). Nunca quita IPs de la lista blanca. Primero sin apply. Requiere "Permitir acciones que modifican" para aplicar.',
                'inputSchema' => $o([
                    'ip' => ['type' => 'string', 'description' => 'IPv4/IPv6 (o CIDR para whitelist)'],
                    'whitelist' => ['type' => 'boolean', 'description' => 'Añadirla además a la lista blanca de fail2ban'],
                    'apply' => $apply,
                ]),
            ],
            'firewall_trusted_sources' => [
                'write' => true,
                'title' => 'Orígenes de confianza del firewall',
                'description' => 'Consulta o cambia la lista manual de IPs/rangos de confianza que usa firewall_audit (además de los nodos del cluster, ALLOWED_IPS y la VPN, que ya cuentan). Sirve para que una IP revisada y autorizada deje de avisar. NO cambia el firewall. Sin `sources`, solo muestra la lista. Requiere "Permitir acciones que modifican" para cambiarla.',
                'inputSchema' => $o([
                    'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Lista completa de IPs o rangos CIDR (sustituye a la anterior)'],
                    'apply' => $apply,
                ]),
            ],
            'config_mirror' => [
                'write' => true,
                'title' => 'Copia de la configuración del sistema desde el master',
                'description' => 'En un SLAVE: copia del master lo que vive fuera de /var/www y hace falta para un relevo: programas de supervisor, tareas cron (crontabs y /etc/cron.d), webs fijas del Caddyfile y pools de PHP-FPM. Lo adapta al papel de reserva (programas con autostart=false, crons desactivados) y lo verifica antes de aplicar (ejecutable/carpeta/usuario, sintaxis de cron, caddy validate, php-fpm -t); lo que no pasa se omite y se avisa. Nunca borra: lo que el master ya no tiene se aparta con .removed-by-mirror. Caddy no se recarga (el Caddyfile se aplica al promover). Al promover, el panel enciende lo copiado; al degradar, lo apaga. Sin apply: muestra qué haría y el estado. enable=true/false activa o desactiva la copia automática cada 5 minutos. Requiere "Permitir acciones que modifican" para aplicar.',
                'inputSchema' => $o([
                    'enable' => ['type' => 'boolean', 'description' => 'Activar (true) o desactivar (false) la copia automática cada 5 min'],
                    'apply' => $apply,
                ]),
            ],
            'cluster_drift' => [
                'write' => false,
                'title' => 'Diferencias entre el master y sus nodos',
                'description' => 'En el MASTER: compara este servidor con cada nodo (o con uno) en lo que importa para que un relevo funcione: programas de supervisor, unidades systemd propias, tareas cron, webs del Caddyfile fuera del panel, versiones y extensiones de PHP, Node/npm/Composer, paquetes relevantes y nº de hostings. Devuelve lo que falta o es distinto en el nodo (hay que arreglarlo) y lo que solo existe en el nodo (informativo). Que en un slave los programas estén con autostart=false o los crons desactivados es normal y no cuenta. Sirve para cualquier pareja master/slave. Solo lectura.',
                'inputSchema' => $o([
                    'target_node' => ['type' => 'string', 'description' => 'Opcional: id o nombre de un nodo (por defecto, todos)'],
                ]),
            ],
            'filesync_status' => [
                'write' => false,
                'title' => 'Estado de la sincronización de ficheros',
                'description' => 'En el MASTER: si está activa la copia de ficheros de /var/www/vhosts a los nodos, en qué modo (lsyncd = casi al instante; periodic = cada N minutos), a qué nodos, qué se excluye, y el estado de lsyncd (en marcha, salud, problemas y últimas líneas de su log). Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'filesync_configure' => [
                'write' => true, 'destructive' => true,
                'title' => 'Activar la sincronización de ficheros',
                'description' => 'En el MASTER: activa la copia de /var/www/vhosts a los nodos web del cluster (no standby). Pasos: clave SSH de root del master (la crea si falta), la instala en cada nodo por la API del cluster, comprueba el SSH, instala lsyncd si hace falta, guarda la configuración y lo arranca. lsyncd copia cada cambio a los ~15 s y es un ESPEJO: al arrancar hace una pasada completa y borra en el nodo lo que no exista en el master (salvo exclusiones). Para un slave clon exacto, mirror_git y mirror_node_modules copian también .git y node_modules. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'mode' => ['type' => 'string', 'enum' => ['lsyncd', 'periodic'], 'description' => 'lsyncd (por defecto, casi al instante) o periodic (rsync cada interval_minutes)'],
                    'interval_minutes' => ['type' => 'integer', 'description' => 'Solo periodic (por defecto 10)'],
                    'mirror_git' => ['type' => 'boolean', 'description' => 'Copiar también las carpetas .git (por defecto true)'],
                    'mirror_node_modules' => ['type' => 'boolean', 'description' => 'Copiar también node_modules (por defecto true)'],
                    'apply' => $apply,
                ]),
            ],
            'filesync_extra_paths' => [
                'write' => true, 'destructive' => true,
                'title' => 'Carpetas extra en la copia de ficheros',
                'description' => 'En el MASTER: además de /var/www/vhosts, copiar con lsyncd carpetas de apps que viven fuera de los hostings (p. ej. /opt/miapp) SOLO a los nodos que se indiquen (normalmente el de relevo). Solo carpetas existentes bajo /opt, /srv o /home; nunca /opt/musedock-panel. Es ESPEJO: en el nodo destino se borra lo que no exista aquí dentro de esas carpetas. Sin argumentos muestra la configuración actual. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Lista completa de carpetas (sustituye a la anterior)'],
                    'target_nodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids o nombres de los nodos que las reciben'],
                    'apply' => $apply,
                ]),
            ],
            'cluster_node_services' => [
                'write' => true,
                'title' => 'Servicios de un nodo',
                'description' => 'En el MASTER: indica qué hace un nodo del cluster: web (hostings), mail (correo) o ambos. Afecta a qué se le sincroniza y a la elección de relevo. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'target_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo (list_nodes)'],
                    'services' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['web', 'mail']]],
                    'apply' => $apply,
                ], ['target_node', 'services']),
            ],
        ];
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    public static function run(string $name, array $args): array
    {
        return match ($name) {
            'failover_preflight'    => self::preflight(),
            'failover_configure'    => self::configure($args),
            'replication_adopt'     => self::adopt($args),
            'cluster_node_services' => self::nodeServices($args),
            'cluster_pairing_open'  => self::pairingOpen($args),
            'cluster_pair_request'  => self::pairRequest($args),
            'cluster_pair_pending'  => self::pairPending(),
            'cluster_pair_approve'  => self::pairApprove($args),
            'cluster_queue'         => self::queue($args),
            'cluster_sync_hostings' => self::syncHostings($args),
            'cluster_drift'         => self::drift($args),
            'hosting_php_settings'  => self::hostingPhp($args),
            'config_mirror'         => self::configMirror($args),
            'failover_dns_plan'     => self::dnsPlan(),
            'witnesses_status'      => self::witnessesStatus(),
            'page_check'            => self::pageCheck($args),
            'panel_errors'          => self::panelErrors($args),
            'server_profile'        => self::serverProfile(),
            'server_profile_set'    => self::serverProfileSet($args),
            'monitor_status'        => self::monitorStatus(),
            'monitor_configure'     => self::monitorConfigure($args),
            'cloudflare_email_routing' => self::cfEmailRouting($args),
            'cloudflare_tokens'     => self::cfTokens(),
            'cloudflare_caddy_token_sync' => self::cfCaddyTokenSync($args),
            'dns_records'           => self::dnsRecords($args),
            'dns_record_set'        => self::dnsRecordSet($args),
            'notify_status'         => self::notifyStatus(),
            'notify_configure'      => self::notifyConfigure($args),
            'firewall_audit'        => \MuseDockPanel\Services\FirewallAuditService::refreshStored(),
            'firewall_check_ip'     => \MuseDockPanel\Services\FirewallAuditService::checkSource((string)($args['ip'] ?? ''), isset($args['port']) ? (int)$args['port'] : null),
            'firewall_trusted_sources' => self::trustedSources($args),
            'fail2ban_manage' => self::fail2banManage($args),
            'filesync_status'       => self::filesyncStatus(),
            'filesync_configure'    => self::filesyncConfigure($args),
            'filesync_extra_paths'  => self::filesyncExtraPaths($args),
            default                 => throw new \InvalidArgumentException("Herramienta desconocida: {$name}"),
        };
    }

    // ── Perfil del servidor ──────────────────────────────────────────────

    /** Nombre al que apuntan por CNAME los dominios nuevos: el del panel, salvo que se fije otro. */
    public static function dnsDefaultTarget(): string
    {
        return strtolower(trim(Settings::get('dns_default_target', '') ?: Settings::get('panel_hostname', '') ?: (gethostname() ?: '')));
    }

    private static function serverProfile(): array
    {
        $ips = array_values(array_filter(preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) ?: []));
        $servers = array_map(static fn($s) => ['name' => $s['name'] ?? '', 'ip' => $s['ip'] ?? '', 'role' => $s['role'] ?? ''], FailoverService::getServers());
        $nodes = array_map(static fn($n) => ['id' => (int)$n['id'], 'name' => $n['name'], 'role' => $n['role'] ?? '', 'status' => $n['status'] ?? ''], ClusterService::getNodes());
        $cf = array_map(static fn($a) => ['name' => $a['name'] ?? '', 'zones' => count($a['zones'] ?? [])], CloudflareService::getConfiguredAccounts());
        $target = self::dnsDefaultTarget();
        return [
            'hostname' => gethostname(),
            'panel' => Settings::get('panel_hostname', '') . ':' . (Settings::get('panel_port', '8444') ?: '8444'),
            'cluster_role' => self::role(),
            'ips' => $ips,
            'new_domains' => [
                'dns_default_target' => $target,
                'how' => "Los dominios nuevos de este servidor apuntan por CNAME a {$target} (raíz y www). El proxy naranja de Cloudflare es OPCIONAL: pregúntalo (por defecto activado, como hace el CMS).",
                'why_cname' => "Así un relevo solo tiene que mover el registro A de {$target}; los dominios que apuntan a él se mueven solos y no hay que cambiarlos uno a uno.",
            ],
            'failover' => [
                'mode' => Settings::get('failover_mode', 'manual'),
                'servers' => $servers,
                'note' => 'El relevo cambia en Cloudflare los registros A de la IP del primario a la del de relevo (failover_dns_plan para ver qué movería).',
            ],
            'cluster_nodes' => $nodes,
            'cloudflare_accounts' => $cf,
            'mail' => ['hostname' => Settings::get('mail_hostname', ''), 'mode' => \MuseDockPanel\Services\MailService::getCurrentMailMode()],
            'alerts' => self::notifyStatus()['issues'] ?: 'avisos configurados',
            'monitoring_enabled' => Settings::get('monitor_enabled', '1') === '1',
            'mcp_permissions' => [
                'write' => Settings::get('mcp_allow_write', '0') === '1',
                'dns_edit' => Settings::get('mcp_allow_dns', '0') === '1',
                'never' => 'Ninguna herramienta borra datos ni registros DNS.',
            ],
            'start_here' => 'Para un dominio nuevo: dns_records (ver su zona) → dns_record_set (CNAME raíz y www a dns_default_target, proxy según el usuario) → comprobar la web. Para el estado general: monitor_status, notify_status, failover_preflight, domains_status.',
        ];
    }

    private static function serverProfileSet(array $args): array
    {
        $t = strtolower(trim(rtrim((string)($args['dns_default_target'] ?? ''), '.')));
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/', $t)) {
            throw new \InvalidArgumentException('Nombre no válido.');
        }
        $plan = ['dns_default_target' => ['antes' => self::dnsDefaultTarget(), 'después' => $t], 'note' => 'No cambia ningún DNS existente; solo el destino que se propone para los dominios nuevos.'];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan];
        }
        Settings::set('dns_default_target', $t);
        LogService::log('mcp.profile', 'dns_default_target', $t);
        return ['applied' => true, 'plan' => $plan];
    }

    // ── Monitorización ───────────────────────────────────────────────────

    private static function monitorStatus(): array
    {
        $host = gethostname() ?: 'localhost';
        $db = \MuseDockPanel\Database::class;
        $out = [
            'enabled' => Settings::get('monitor_enabled', '1') === '1',
            'thresholds' => [
                'cpu_%' => (float)Settings::get('monitor_alert_cpu', '90'),
                'ram_%' => (float)Settings::get('monitor_alert_ram', '90'),
                'disk_%' => (float)Settings::get('monitor_alert_disk', '90'),
                'gpu_temp_c' => (float)Settings::get('monitor_alert_gpu_temp', '85'),
                'repeat_hours' => (int)Settings::get('monitor_alert_repeat_hours', '12'),
            ],
        ];
        try {
            $last = $db::fetchOne("SELECT max(ts) AS ts FROM monitor_metrics WHERE host = :h", ['h' => $host]);
            $out['last_reading'] = $last['ts'] ?? null;
            $out['collector_working'] = !empty($last['ts']) && (time() - strtotime((string)$last['ts'])) < 300;
            $latest = [];
            foreach ($db::fetchAll("SELECT DISTINCT ON (metric) metric, value, ts FROM monitor_metrics WHERE host = :h AND ts > NOW() - INTERVAL '15 minutes' ORDER BY metric, ts DESC", ['h' => $host]) as $r) {
                $latest[$r['metric']] = round((float)$r['value'], 2);
            }
            ksort($latest);
            $out['latest'] = $latest;
            $out['alerts_24h'] = array_map(static fn($a) => ['at' => substr((string)$a['ts'], 0, 19), 'type' => $a['type'], 'message' => $a['message'],
                    'details' => $a['details'] !== null ? mb_substr((string)$a['details'], 0, 600) : null],
                $db::fetchAll("SELECT ts, type, message, details FROM monitor_alerts WHERE ts > NOW() - INTERVAL '24 hours' ORDER BY ts DESC LIMIT 20"));
        } catch (\Throwable $e) {
            $out['db_error'] = $e->getMessage();
        }
        $episodes = json_decode(Settings::get('monitor_alert_episodes', '{}'), true) ?: [];
        $out['open_problems'] = array_values(array_map(static fn($k, $e) => explode('|', $k, 2)[1] . ' (desde el aviso de ' . date('Y-m-d H:i', (int)($e['last_sent'] ?? 0)) . ')',
            array_keys(array_filter($episodes, static fn($e) => time() - (int)($e['last_seen'] ?? 0) < 600)),
            array_filter($episodes, static fn($e) => time() - (int)($e['last_seen'] ?? 0) < 600)));
        $log = PANEL_ROOT . '/storage/logs/monitor-collector.log';
        $errors = [];
        foreach (array_slice(@file($log, FILE_IGNORE_NEW_LINES) ?: [], -400) as $l) {
            if (preg_match('/error|fail|exception/i', $l)) {
                $errors[] = mb_substr($l, 0, 200);
            }
        }
        $out['collector_errors_recent'] = array_slice($errors, -10);
        $out['alert_delivery'] = self::notifyStatus()['issues'] ?: 'los avisos salen por los canales configurados (notify_status para el detalle)';
        if (!$out['enabled']) {
            $out['note'] = 'La monitorización está DESACTIVADA: no se recogen métricas ni se avisa de CPU/RAM/disco. monitor_configure con enabled=true para activarla.';
        }
        return $out;
    }

    private static function monitorConfigure(array $args): array
    {
        $map = ['cpu' => 'monitor_alert_cpu', 'ram' => 'monitor_alert_ram', 'disk' => 'monitor_alert_disk', 'gpu_temp' => 'monitor_alert_gpu_temp', 'repeat_hours' => 'monitor_alert_repeat_hours'];
        $set = [];
        if (array_key_exists('enabled', $args)) {
            $set['monitor_enabled'] = !empty($args['enabled']) ? '1' : '0';
        }
        foreach ($map as $a => $k) {
            if (isset($args[$a])) {
                $v = (int)$args[$a];
                if ($a !== 'repeat_hours' && $a !== 'gpu_temp' && ($v < 50 || $v > 100)) {
                    throw new \InvalidArgumentException("{$a}: pon un porcentaje entre 50 y 100.");
                }
                $set[$k] = (string)max(1, $v);
            }
        }
        if (!$set) {
            throw new \InvalidArgumentException('No hay nada que cambiar.');
        }
        $plan = [];
        foreach ($set as $k => $v) {
            $plan[$k] = ['antes' => Settings::get($k, ''), 'después' => $v];
        }
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan];
        }
        foreach ($set as $k => $v) {
            Settings::set($k, $v);
        }
        LogService::log('mcp.monitor', 'configure', json_encode($set));
        return ['applied' => true, 'plan' => $plan, 'note' => 'El recolector corre cada 30 s: en un minuto monitor_status mostrará lecturas nuevas.'];
    }

    // ── DNS en Cloudflare (crear y modificar; nunca borrar) ──────────────

    private static function dnsZoneFor(string $name): array
    {
        $name = strtolower(trim(rtrim($name, '.')));
        // El "_" se admite en las etiquetas: lo llevan selector._domainkey, _dmarc, _acme-challenge…
        if ($name === '' || !preg_match('/^(\*\.)?[a-z0-9_]([a-z0-9_.-]*[a-z0-9])?\.[a-z]{2,}$/', $name)) {
            throw new \InvalidArgumentException("Nombre de dominio no válido: {$name}");
        }
        $zone = CloudflareService::findZoneForDomain($name);
        if (!$zone && CloudflareService::refreshZones()) {
            $zone = CloudflareService::findZoneForDomain($name);
        }
        if (!$zone) {
            throw new \RuntimeException("La zona de {$name} no está en ninguna cuenta de Cloudflare de este panel (Cluster → Failover → Cuentas Cloudflare). Si se acaba de añadir, puede tardar en aparecer.");
        }
        return $zone;
    }

    private static function cfTokens(): array
    {
        $list = [];
        foreach (CloudflareService::getConfiguredAccounts() as $a) {
            $list[] = ['used_by' => 'panel: cuenta "' . ($a['name'] ?? '?') . '"', 'token' => (string)($a['token'] ?? '')];
        }
        foreach (@file('/etc/default/caddy', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*CLOUDFLARE_API_TOKEN\s*=\s*"?([^"\s]+)/', $l, $m)) {
                $list[] = ['used_by' => 'Caddy (/etc/default/caddy): certificados por DNS-01', 'token' => $m[1]];
            }
        }
        $out = [];
        $seen = [];
        foreach ($list as $t) {
            if ($t['token'] === '') {
                continue;
            }
            $h = hash('sha256', $t['token']);
            if (isset($seen[$h])) {
                $out[$seen[$h]]['used_by'] .= ' + ' . $t['used_by'];
                continue;
            }
            $v = CloudflareService::apiRequest($t['token'], 'GET', '/user/tokens/verify');
            $row = ['used_by' => $t['used_by']];
            if (empty($v['ok'])) {
                $row['status'] = 'NO VÁLIDO para Cloudflare: ' . ($v['error'] ?? '?');
            } else {
                $id = (string)($v['result']['id'] ?? '');
                $row['status'] = (string)($v['result']['status'] ?? '?');
                $row['id_short'] = $id !== '' ? substr($id, 0, 6) . '…' . substr($id, -4) : '?';
                $row['expires'] = $v['result']['expires_on'] ?? 'no caduca';
                $z = CloudflareService::listAllZones($t['token']);
                $zones = ($z['ok'] ?? false) ? ($z['result'] ?? []) : [];
                $row['zones'] = count($zones) . (count($zones) <= 4 ? ' (' . implode(', ', array_column($zones, 'name')) . ')' : '');
                $first = $zones[0]['id'] ?? null;
                if ($first) {
                    $row['can_read_dns'] = !empty(CloudflareService::listRecords($t['token'], (string)$first, ['per_page' => 1])['ok']);
                    $row['can_read_email_routing_rules'] = !empty(CloudflareService::apiRequest($t['token'], 'GET', "/zones/{$first}/email/routing/rules", ['per_page' => 1])['ok']);
                }
            }
            $seen[$h] = count($out);
            $out[] = $row;
        }
        $want = CloudflareService::caddyTokenFor(CloudflareService::accountsForTransfer());
        $have = CloudflareService::currentCaddyToken();
        $readable = is_readable('/etc/default/caddy');
        return [
            'tokens' => $out,
            'caddy_token' => !$readable ? 'no se puede comprobar: este proceso no puede leer /etc/default/caddy'
                : ($want === '' ? 'el panel no tiene cuentas con token'
                : ($have === $want ? 'Caddy tiene el token de la cuenta con la zona del panel (correcto)'
                : 'Caddy NO tiene el token de la cuenta con la zona del panel: arréglalo con cloudflare_caddy_token_sync (o Guardar en Cuentas Cloudflare del master)')),
            'note' => 'Para reconocerlos en Cloudflare (Mi perfil → Tokens de API, donde todos se llaman igual): por el número de zonas y los permisos. Un token que no aparezca aquí no lo usa ESTE servidor, pero puede usarlo otro (los nodos, el CMS MuseDock en sus ajustes de Cloudflare, otros proyectos): compruébalo antes de borrarlo.',
        ];
    }

    private static function cfCaddyTokenSync(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('Se usa en el MASTER: él elige el token y lo manda a los slaves.');
        }
        $token = CloudflareService::caddyTokenFor(CloudflareService::accountsForTransfer());
        if ($token === '') {
            throw new \RuntimeException('No hay cuentas de Cloudflare con token en este panel.');
        }
        $force = !empty($args['force']);
        $short = static fn (string $t): string => $t === '' ? '(ninguno)' : substr(hash('sha256', $t), 0, 8);
        $here = CloudflareService::currentCaddyToken();
        $plan = [
            'token_elegido' => 'huella ' . $short($token) . ' (cuenta con la zona de ' . Settings::get('panel_hostname', '?') . ')',
            'este_nodo' => ($here === $token && !$force) ? 'ya lo tiene, no se toca' : 'se escribirá y se reiniciará Caddy (huella actual ' . $short($here) . ')',
            'slaves' => 'cada uno compara y solo reinicia Caddy si es distinto' . ($force ? ' (forzado: todos)' : ''),
        ];
        if (empty($args['apply'])) {
            return ['plan' => $plan, 'apply' => false];
        }
        [$changed, $err] = CloudflareService::syncCaddyToken($token, $force);
        if ($changed) {
            Settings::set('failover_cf_token_propagated_at', date('Y-m-d H:i:s'));
        }
        $push = FailoverService::pushConfigToSlaves($force, $token);
        $nodes = [];
        foreach (($push['results'] ?? []) as $r) {
            $nodes[(string)($r['node'] ?? '?')] = empty($r['ok']) ? 'no responde: en cola'
                : (!empty($r['caddy_token_updated']) ? 'actualizado, Caddy se reinicia en 3 s'
                : (!empty($r['caddy_token_error']) ? 'ERROR: ' . $r['caddy_token_error'] : 'ya lo tenía'));
        }
        return [
            'este_nodo' => $changed ? 'actualizado, Caddy se reinicia en 3 s' : ($err ? 'ERROR: ' . $err : 'ya lo tenía'),
            'slaves' => $nodes,
        ];
    }

    private static function cfEmailRouting(array $args): array
    {
        $only = strtolower(trim((string)($args['domain'] ?? '')));
        $onlyEnabled = !array_key_exists('only_enabled', $args) || !empty($args['only_enabled']);
        $panelMail = [];
        try {
            foreach (\MuseDockPanel\Database::fetchAll("SELECT lower(domain) AS d FROM mail_domains") as $r) {
                $panelMail[$r['d']] = true;
            }
        } catch (\Throwable) {
        }
        $out = [];
        $errors = [];
        $checked = 0;
        foreach (CloudflareService::getConfiguredAccounts() as $acct) {
            $token = (string)($acct['token'] ?? '');
            foreach (($acct['zones'] ?? []) as $zone) {
                $name = strtolower((string)$zone['name']);
                if ($only !== '' && $name !== $only && !str_ends_with($only, '.' . $name)) {
                    continue;
                }
                $checked++;
                $set = CloudflareService::apiRequest($token, 'GET', "/zones/{$zone['id']}/email/routing");
                // El estado (/email/routing) pide un permiso que no tienen todos los
                // tokens; las reglas, otro. Sin el estado se siguen leyendo las reglas y
                // el MX, y se deduce si recibe por Cloudflare (MX route*.mx.cloudflare.net).
                $setOk = !empty($set['ok']);
                $rules = [];
                $catchAll = null;
                $rr = CloudflareService::apiRequest($token, 'GET', "/zones/{$zone['id']}/email/routing/rules", ['per_page' => 50]);
                if (empty($rr['ok'])) {
                    $errors[] = "{$name}: no se pudieron leer sus reglas: " . ($rr['error'] ?? 'sin acceso') . ' (¿el token no tiene permiso "Email Routing Rules: Read"?)';
                    continue;
                }
                foreach (($rr['ok'] ?? false) ? ($rr['result'] ?? []) : [] as $rule) {
                    $to = [];
                    foreach ((array)($rule['actions'] ?? []) as $a) {
                        $to[] = ($a['type'] ?? '') === 'forward' ? implode(', ', (array)($a['value'] ?? [])) : ($a['type'] ?? '?');
                    }
                    $from = [];
                    foreach ((array)($rule['matchers'] ?? []) as $m) {
                        $from[] = ($m['type'] ?? '') === 'all' ? '*' : (string)($m['value'] ?? '');
                    }
                    $row = ['from' => implode(', ', $from), 'to' => implode(' | ', $to), 'enabled' => !empty($rule['enabled'])];
                    if (in_array('*', $from, true)) {
                        $catchAll = $row;
                    } else {
                        $rules[] = $row;
                    }
                }
                $ca = CloudflareService::apiRequest($token, 'GET', "/zones/{$zone['id']}/email/routing/rules/catch_all");
                if (($ca['ok'] ?? false) && !empty($ca['result'])) {
                    $to = [];
                    foreach ((array)($ca['result']['actions'] ?? []) as $a) {
                        $to[] = ($a['type'] ?? '') === 'forward' ? implode(', ', (array)($a['value'] ?? [])) : ($a['type'] ?? '?');
                    }
                    $catchAll = ['from' => '*', 'to' => implode(' | ', $to), 'enabled' => !empty($ca['result']['enabled'])];
                }
                $mx = array_map(static fn($r) => ($r['priority'] ?? '') . ' ' . $r['content'],
                    (CloudflareService::listRecords($token, (string)$zone['id'], ['type' => 'MX', 'name' => $name])['result'] ?? []));
                $mxCf = (bool)array_filter($mx, static fn($m) => str_contains($m, '.mx.cloudflare.net'));
                $enabled = $setOk ? !empty($set['result']['enabled']) : ($mxCf || (bool)array_filter($rules, static fn($r) => $r['enabled']));
                if ($onlyEnabled && !$enabled) {
                    continue;
                }
                $out[] = [
                    'domain' => $name,
                    'account' => $acct['name'] ?? '',
                    'email_routing' => ($enabled ? 'activado' : 'desactivado') . ($setOk ? '' : ' (deducido por MX/reglas: el token no puede leer el estado)'),
                    'status' => $setOk ? ($set['result']['status'] ?? null) : null,
                    'mx_to_cloudflare' => $mxCf,
                    'rules' => $rules,
                    'catch_all' => $catchAll,
                    'mx' => $mx,
                    'in_panel_mail' => isset($panelMail[$name]),
                ];
            }
        }
        return ['zones_checked' => $checked, 'count' => count($out), 'domains' => $out, 'errors' => $errors,
            'note' => 'Pasar uno al correo del panel: mail_domain_create → alias que reenvíen igual que sus reglas (mail_alias_create) → mail_dns_publish (cambia el MX: avisa del conflicto con Email Routing y por defecto NO lo toca).'];
    }

    private static function dnsRecords(array $args): array
    {
        $domain = strtolower(trim((string)($args['domain'] ?? '')));
        $zone = self::dnsZoneFor($domain);
        $filters = !empty($args['type']) ? ['type' => strtoupper((string)$args['type'])] : [];
        $r = CloudflareService::listRecordsAll((string)$zone['token'], (string)$zone['zone_id'], $filters);
        if (empty($r['ok'])) {
            throw new \RuntimeException('Cloudflare no respondió: ' . ($r['error'] ?? 'error'));
        }
        $rows = array_map(static fn($x) => [
            'name' => $x['name'], 'type' => $x['type'], 'content' => $x['content'],
            'proxied' => (bool)($x['proxied'] ?? false), 'ttl' => (int)($x['ttl'] ?? 1),
        ] + (isset($x['priority']) ? ['priority' => (int)$x['priority']] : []), $r['result'] ?? []);
        usort($rows, static fn($a, $b) => [$a['name'], $a['type']] <=> [$b['name'], $b['type']]);
        return ['zone' => $zone['zone'], 'account' => $zone['account'], 'count' => count($rows), 'records' => $rows];
    }

    private static function dnsRecordSet(array $args): array
    {
        $name = strtolower(trim(rtrim((string)($args['name'] ?? ''), '.')));
        $type = strtoupper((string)($args['type'] ?? ''));
        $content = trim((string)($args['content'] ?? ''));
        if (!in_array($type, ['A', 'AAAA', 'CNAME', 'TXT', 'MX'], true)) {
            throw new \InvalidArgumentException('Tipo no admitido. Solo A, AAAA, CNAME, TXT o MX.');
        }
        if ($content === ''
            || ($type === 'A' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            || ($type === 'AAAA' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            || (in_array($type, ['CNAME', 'MX'], true) && !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}\.?$/i', $content))) {
            throw new \InvalidArgumentException("Contenido no válido para un registro {$type}: {$content}");
        }
        $zone = self::dnsZoneFor($name);
        [$token, $zoneId] = [(string)$zone['token'], (string)$zone['zone_id']];

        $same = CloudflareService::listRecordsAll($token, $zoneId, ['name' => $name, 'type' => $type]);
        $any = CloudflareService::listRecordsAll($token, $zoneId, ['name' => $name]);
        if (empty($same['ok']) || empty($any['ok'])) {
            throw new \RuntimeException('Cloudflare no respondió al consultar los registros.');
        }
        $same = $same['result'] ?? [];
        $others = array_values(array_filter($any['result'] ?? [], static fn($x) => $x['type'] !== $type && in_array($x['type'], ['A', 'AAAA', 'CNAME'], true)));
        // Un CNAME no puede convivir con otros registros del mismo nombre, y viceversa:
        // habría que borrar, y esta herramienta no borra.
        if (($type === 'CNAME' && $others) || (in_array($type, ['A', 'AAAA'], true) && array_filter($others, static fn($x) => $x['type'] === 'CNAME'))) {
            $list = implode(', ', array_map(static fn($x) => "{$x['type']} {$x['content']}", $others));
            throw new \RuntimeException("{$name} ya tiene {$list}: para poner un {$type} habría que borrar ese registro y esta herramienta no borra. Hazlo en Cloudflare o elige otro nombre.");
        }

        $proxiable = in_array($type, ['A', 'AAAA', 'CNAME'], true);
        $data = ['type' => $type, 'name' => $name, 'content' => rtrim($content, '.'), 'ttl' => max(1, (int)($args['ttl'] ?? 1))];
        if ($type === 'MX') {
            $data['priority'] = (int)($args['priority'] ?? 10);
        }
        $multi = in_array($type, ['TXT', 'MX'], true);
        $plan = ['zone' => $zone['zone'], 'account' => $zone['account']];
        $target = null;
        if ($multi) {
            foreach ($same as $x) {
                if (trim((string)$x['content'], '"') === trim($data['content'], '"')) {
                    return ['status' => 'ya_existe', 'record' => "{$type} {$name} → {$x['content']}", 'note' => 'No hace falta cambiar nada.'] + $plan;
                }
            }
            // Dos registros SPF (o DMARC) en el mismo nombre invalidan los dos: se edita el existente.
            foreach (['v=spf1' => 'mail_dns_publish con spf_add', 'v=dmarc1' => 'Cloudflare (editar el registro existente)'] as $pfx => $how) {
                if ($type === 'TXT' && str_starts_with(strtolower(trim($data['content'], '" ')), $pfx)
                    && array_filter($same, fn($x) => str_starts_with(strtolower(trim((string)$x['content'], '" ')), $pfx))) {
                    throw new \RuntimeException("{$name} ya tiene un registro {$pfx}: añadir otro invalidaría los dos. Para cambiarlo usa {$how}.");
                }
            }
            $plan['action'] = "AÑADIR {$type} {$name} → {$data['content']}" . (count($same) ? ' (se conservan los otros ' . count($same) . " {$type} de ese nombre)" : '');
        } elseif (count($same) > 1) {
            throw new \RuntimeException("{$name} tiene " . count($same) . " registros {$type}: no sé cuál modificar sin borrar. Hazlo en Cloudflare.");
        } elseif ($same) {
            $target = $same[0];
            $proxied = $proxiable ? (array_key_exists('proxied', $args) ? (bool)$args['proxied'] : (bool)($target['proxied'] ?? false)) : null;
            if ($target['content'] === $data['content'] && (!$proxiable || (bool)($target['proxied'] ?? false) === $proxied)) {
                return ['status' => 'ya_existe', 'record' => "{$type} {$name} → {$target['content']}" . ($proxiable && $proxied ? ' (proxy)' : ''), 'note' => 'No hace falta cambiar nada.'] + $plan;
            }
            $plan['action'] = "MODIFICAR {$type} {$name}: {$target['content']}" . ($proxiable && !empty($target['proxied']) ? ' (proxy)' : '')
                . " → {$data['content']}" . ($proxiable && $proxied ? ' (proxy)' : '');
        } else {
            $proxied = $proxiable ? !empty($args['proxied']) : null;
            $plan['action'] = "CREAR {$type} {$name} → {$data['content']}" . ($proxiable && $proxied ? ' (proxy)' : '');
        }
        if ($proxiable) {
            $data['proxied'] = $proxied;
            if ($proxied) {
                $data['ttl'] = 1;
            }
        }
        $dnsAllowed = Settings::get('mcp_allow_dns', '0') === '1';
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan + ['next' => $dnsAllowed
                ? 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'
                : 'Para aplicarlo hay que activar antes Ajustes → MCP → "Permitir editar DNS en Cloudflare".'];
        }
        if (!$dnsAllowed) {
            throw new \RuntimeException('Editar DNS por MCP está desactivado en este panel: actívalo en Ajustes → MCP → "Permitir editar DNS en Cloudflare".');
        }
        $r = $target
            ? CloudflareService::updateRecord($token, $zoneId, (string)$target['id'], $data)
            : CloudflareService::createRecord($token, $zoneId, $data);
        if (empty($r['ok'])) {
            throw new \RuntimeException('Cloudflare rechazó el cambio: ' . ($r['error'] ?? 'error'));
        }
        LogService::log('mcp.dns', $name, $plan['action'] . " (zona {$zone['zone']})");
        return ['status' => 'hecho'] + $plan;
    }

    // ── Avisos ───────────────────────────────────────────────────────────

    private static function notifyStatus(): array
    {
        $ns = \MuseDockPanel\Services\NotificationService::class;
        $health = json_decode(Settings::get('replication_health_state', '{}'), true);
        $emailOn = Settings::get('monitor_notify_email', '0') === '1';
        $tgOn = Settings::get('monitor_notify_telegram', '0') === '1';
        $emailReady = $ns::isEmailConfigured();
        $tgReady = Settings::get('notify_telegram_token', '') !== '' && Settings::get('notify_telegram_chat_id', '') !== '';
        $issues = [];
        if (!($emailOn && $emailReady) && !($tgOn && $tgReady)) {
            $issues[] = 'Este panel NO puede avisar de nada (ni correo ni Telegram activos y configurados).';
        }
        if ($emailOn && $emailReady && preg_match('/(^|\.)' . preg_quote((string)Settings::get('mail_hostname', '#'), '/') . '$/', (string)Settings::get('notify_smtp_host', ''))) {
            $issues[] = 'El correo de avisos sale por el servidor de correo del propio cluster: si este cae, el aviso tampoco sale. Conviene Telegram o un SMTP externo además.';
        }
        return [
            'email' => [
                'active' => $emailOn, 'configured' => $emailReady,
                'method' => Settings::get('notify_email_method', 'smtp'),
                'to' => $ns::getRecipientEmail(),
                'smtp_host' => Settings::get('notify_smtp_host', ''), 'smtp_port' => Settings::get('notify_smtp_port', ''),
                'smtp_user' => Settings::get('notify_smtp_user', ''), 'smtp_from' => Settings::get('notify_smtp_from', ''),
                'smtp_password_set' => Settings::get('notify_smtp_pass', '') !== '',
                'secondary' => Settings::get('notify_smtp2_host', '') === '' ? null : [
                    'smtp_host' => Settings::get('notify_smtp2_host', ''), 'smtp_port' => Settings::get('notify_smtp2_port', ''),
                    'smtp_user' => Settings::get('notify_smtp2_user', ''), 'smtp_from' => Settings::get('notify_smtp2_from', ''),
                    'smtp_password_set' => Settings::get('notify_smtp2_pass', '') !== '',
                ],
                'last_ok' => json_decode(Settings::get('notify_email_last_ok', 'null'), true),
                'last_error' => json_decode(Settings::get('notify_email_last_error', 'null'), true),
                'sent_today' => (static function () {
                    [$d, $n] = array_pad(explode('|', Settings::get('notify_email_daily_count', '')), 2, '0');
                    return $d === date('Y-m-d') ? (int)$n : 0;
                })(),
                'daily_cap' => (int)Settings::get('notify_email_daily_cap', '25'),
            ],
            'telegram' => ['active' => $tgOn, 'configured' => $tgReady, 'chat_id' => Settings::get('notify_telegram_chat_id', '')],
            'replication_health' => is_array($health) ? ($health['last'] ?? null) : null,
            'issues' => $issues,
        ];
    }

    private static function notifyConfigure(array $args): array
    {
        $readSecret = static function (string $path): string {
            $real = realpath($path) ?: '';
            if ($real === '' || !is_file($real) || !str_starts_with($real, '/root/')) {
                throw new \InvalidArgumentException("{$path}: tiene que ser un fichero existente bajo /root/.");
            }
            return trim((string)strtok((string)file_get_contents($real), "\n"));
        };
        $set = [];
        $plan = [];
        if (isset($args['email_to'])) {
            if (!filter_var($args['email_to'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('email_to no es un correo válido.');
            }
            $set['notify_email_to'] = (string)$args['email_to'];
        }
        foreach (['smtp_host' => 'notify_smtp_host', 'smtp_user' => 'notify_smtp_user', 'smtp_from' => 'notify_smtp_from', 'smtp_from_name' => 'notify_smtp_from_name'] as $a => $k) {
            if (isset($args[$a])) {
                $set[$k] = trim((string)$args[$a]);
            }
        }
        if (isset($args['smtp_port'])) {
            $set['notify_smtp_port'] = (string)(int)$args['smtp_port'];
        }
        foreach (['smtp2_host' => 'notify_smtp2_host', 'smtp2_user' => 'notify_smtp2_user', 'smtp2_from' => 'notify_smtp2_from', 'smtp2_encryption' => 'notify_smtp2_encryption'] as $a => $k) {
            if (isset($args[$a])) {
                $set[$k] = trim((string)$args[$a]);
            }
        }
        if (isset($args['smtp2_port'])) {
            $set['notify_smtp2_port'] = (string)(int)$args['smtp2_port'];
        }
        if (!empty($args['smtp2_pass_file'])) {
            $p2 = $readSecret((string)$args['smtp2_pass_file']);
            if ($p2 === '') {
                throw new \InvalidArgumentException('El fichero de la contraseña del SMTP secundario está vacío.');
            }
            $set['notify_smtp2_pass'] = ReplicationService::encryptPassword($p2);
        }
        if (isset($args['smtp_encryption'])) {
            $set['notify_smtp_encryption'] = (string)$args['smtp_encryption'];
        }
        $secretPlan = [];
        if (!empty($args['smtp_pass_file'])) {
            $p = $readSecret((string)$args['smtp_pass_file']);
            if ($p === '') {
                throw new \InvalidArgumentException('El fichero de la contraseña SMTP está vacío.');
            }
            $set['notify_smtp_pass'] = ReplicationService::encryptPassword($p);
            $secretPlan[] = 'contraseña SMTP: se guardará cifrada (leída de ' . $args['smtp_pass_file'] . ')';
        }
        if (!empty($args['telegram_token_file'])) {
            $t = $readSecret((string)$args['telegram_token_file']);
            if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $t)) {
                throw new \InvalidArgumentException('El fichero no contiene un token de bot de Telegram válido.');
            }
            $set['notify_telegram_token'] = ReplicationService::encryptPassword($t);
            $secretPlan[] = 'token de Telegram: se guardará cifrado (leído de ' . $args['telegram_token_file'] . ')';
        }
        if (isset($args['telegram_chat_id'])) {
            $set['notify_telegram_chat_id'] = trim((string)$args['telegram_chat_id']);
        }
        if (isset($set['notify_smtp_host'])) {
            $set['notify_email_method'] = 'smtp';
            $set['monitor_notify_email'] = '1';
            if (!isset($set['notify_smtp_from']) && isset($set['notify_smtp_user']) && Settings::get('notify_smtp_from', '') === '') {
                $set['notify_smtp_from'] = $set['notify_smtp_user'];
            }
        }
        if (isset($set['notify_telegram_token']) || isset($set['notify_telegram_chat_id'])) {
            $set['monitor_notify_telegram'] = '1';
        }
        if (array_key_exists('enable_email', $args)) {
            $set['monitor_notify_email'] = !empty($args['enable_email']) ? '1' : '0';
        }
        if (array_key_exists('enable_telegram', $args)) {
            $set['monitor_notify_telegram'] = !empty($args['enable_telegram']) ? '1' : '0';
        }
        $copy = !empty($args['copy_to_nodes']);
        $nodes = $copy ? ClusterService::getNodes() : [];
        if ($copy && self::role() !== 'master') {
            throw new \RuntimeException('copy_to_nodes se usa en el MASTER del cluster.');
        }
        if (!$set && !$copy) {
            throw new \InvalidArgumentException('No hay nada que configurar.');
        }
        foreach ($set as $k => $v) {
            $plan[$k] = in_array($k, ['notify_smtp_pass', 'notify_smtp2_pass', 'notify_telegram_token'], true) ? '(cifrado)' : $v;
        }
        if ($copy) {
            $plan['copy_to_nodes'] = array_map(static fn($n) => (string)$n['name'], $nodes);
        }
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'secrets' => $secretPlan,
                'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true (y test=true para enviar una prueba). Después el usuario puede borrar el fichero del secreto con shred -u.'];
        }
        foreach ($set as $k => $v) {
            Settings::set($k, $v);
        }
        $copied = [];
        foreach ($nodes as $n) {
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action',
                ['action' => 'set-notify-config', 'payload' => \MuseDockPanel\Services\NotificationService::exportConfig()]);
            $copied[(string)$n['name']] = !empty($r['ok']) && !empty($r['data']['ok'])
                ? 'copiada' . (!empty($r['data']['email_ready']) ? '' : ' (el nodo dice que el correo no queda listo)')
                : 'ERROR: ' . ($r['data']['error'] ?? $r['error'] ?? '?') . ' (¿panel del nodo anterior a 1.0.260?)';
        }
        LogService::log('mcp.notify', 'configure', 'Avisos configurados: ' . implode(', ', array_keys($plan)));
        if ($copied) {
            $plan['copy_result'] = $copied;
        }
        $test = null;
        if (!empty($args['test'])) {
            $host = gethostname();
            $test = [
                'email' => Settings::get('monitor_notify_email', '0') === '1' ? \MuseDockPanel\Services\NotificationService::sendEmail("[{$host}] Prueba de avisos", "Aviso de prueba del panel de {$host}.") : null,
                'telegram' => Settings::get('monitor_notify_telegram', '0') === '1' ? \MuseDockPanel\Services\NotificationService::sendTelegram("[{$host}] Prueba de avisos del panel") : null,
            ];
        }
        return ['applied' => true, 'plan' => $plan, 'test_sent' => $test, 'status' => self::notifyStatus()];
    }

    // ── Diagnóstico de páginas y registros del panel (solo lectura) ────────

    private static function pageCheck(array $args): array
    {
        $path = (string)($args['path'] ?? '');
        if (!preg_match('#^/[A-Za-z0-9/_.\-]*(\?[A-Za-z0-9_=&%.\-]*)?$#', $path) || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Ruta no válida (p. ej. /domains).');
        }
        $out = (string)shell_exec('timeout 90 php ' . escapeshellarg(PANEL_ROOT . '/bin/page-check.php') . ' ' . escapeshellarg($path) . ' 2>&1');
        $tail = substr($out, (int)strrpos($out, '== ' . explode('?', $path)[0]));
        preg_match('/HTTP (\d+), (\d+) bytes/', $tail, $m);
        $lines = array_values(array_filter(array_slice(explode("\n", trim($tail)), 1)));
        return ['path' => $path, 'http' => isset($m[1]) ? (int)$m[1] : null, 'bytes' => isset($m[2]) ? (int)$m[2] : null,
                'ok' => $lines === ['Sin errores de PHP.'], 'problems' => $lines === ['Sin errores de PHP.'] ? [] : $lines];
    }

    private static function panelErrors(array $args): array
    {
        $n = max(5, min(200, (int)($args['lines'] ?? 30)));
        $logs = [
            'panel-error.log' => PANEL_ROOT . '/storage/logs/panel-error.log',
            'panel.log' => PANEL_ROOT . '/storage/logs/panel.log',
            'cluster-worker.log' => PANEL_ROOT . '/storage/logs/cluster-worker.log',
            'failover-worker.log' => PANEL_ROOT . '/storage/logs/failover-worker.log',
        ];
        $out = [];
        foreach ($logs as $name => $f) {
            if (!is_file($f)) {
                continue;
            }
            $raw = (string)shell_exec('tail -n 4000 ' . escapeshellarg($f) . " | grep -iE 'error|exception|fatal|fall[oó]|warning' | tail -n {$n}");
            // Tapar lo que podría ser un token o una clave (cadenas largas sin espacios).
            $raw = preg_replace(['/\b[A-Za-z0-9_\-]{32,}\b/', '/(password|pass|token|secret|key)=\S+/i'], ['***', '$1=***'], $raw);
            $lines = array_values(array_filter(explode("\n", trim((string)$raw))));
            $out[$name] = ['lines' => $lines, 'modified' => date('Y-m-d H:i:s', (int)filemtime($f))];
        }
        return ['logs' => $out, 'note' => 'Solo líneas con error/excepción/fallo/aviso de las últimas 4000 de cada registro.'];
    }

    // ── Testigos externos y vigilante de entrada (solo lectura) ──────────

    private static function witnessesStatus(): array
    {
        $ws = \MuseDockPanel\Services\WitnessService::class;
        $list = $ws::all();
        $health = json_decode((string)\MuseDockPanel\Settings::get('witness_health', '{}'), true) ?: [];
        $out = [];
        foreach ($list as $w) {
            $a = $ws::query($w);
            $targets = [];
            foreach ((array)($a['targets'] ?? []) as $id => $t) {
                $targets[$id] = ['addr' => $t['addr'] ?? '', 'via' => $t['via'] ?? '', 'ok' => !empty($t['ok']),
                    'latency_avg_ms' => $t['latency_avg_ms'] ?? null, 'loss_pct' => (int)($t['loss_pct'] ?? 0), 'error' => $t['error'] ?? ''];
            }
            $h = $health[$w['name']] ?? [];
            $out[] = [
                'name' => $w['name'], 'url' => $w['url'],
                'fingerprint' => strtoupper(substr((string)$w['fingerprint'], 0, 16)) . '…',
                'responds' => !empty($a['ok']), 'error' => !empty($a['ok']) ? '' : ($a['error'] ?? ''),
                'agent_version' => $a['version'] ?? null,
                'down_since' => !empty($h['down_since']) ? date('Y-m-d H:i:s', (int)$h['down_since']) : null,
                'alerted' => !empty($h['alerted']),
                'targets' => $targets,
            ];
        }
        $iw = \MuseDockPanel\Services\IngressWatchService::status();
        return [
            'witnesses' => $out,
            'count' => count($out),
            'responding' => count(array_filter($out, static fn($x) => $x['responds'])),
            'note' => !$out ? 'Este panel no tiene testigos: los relevos se deciden solo con su propia vista (Ajustes → Testigos para crear uno).'
                : 'Un testigo que no responde no cuenta: decide el otro; si no responde ninguno, se decide con la vista de este panel.',
            'ingress_watch' => [
                'servers' => $iw['config']['servers'] ?? [],
                'thresholds' => array_intersect_key($iw['config'], array_flip(['fail_minutes', 'recover_minutes', 'max_latency_ms', 'max_loss_pct'])),
                'state' => $iw['state'] ?? [],
                'dns_records_moved' => $iw['moved'] ?? 0,
            ],
        ];
    }

    // ── Plan de DNS de un relevo (solo lectura) ──────────────────────────

    private static function dnsPlan(): array
    {
        $fo = \MuseDockPanel\Services\FailoverService::class;
        $cf = \MuseDockPanel\Services\CloudflareService::class;
        $pairs = [];
        foreach ($fo::getServers() as $srv) {
            if (($srv['role'] ?? '') !== 'primary' || empty($srv['enabled'] ?? true)) {
                continue;
            }
            $to = $fo::getServer((string)($srv['failover_to'] ?? ''));
            if ($to) {
                $pairs[] = ['from' => (string)$srv['ip'], 'from_name' => (string)$srv['name'], 'to' => (string)$to['ip'], 'to_name' => (string)$to['name']];
            }
        }
        if (!$pairs) {
            return ['ok' => false, 'error' => 'No hay servidor primario con servidor de relevo: failover_configure en el master.'];
        }
        return \MuseDockPanel\Services\DnsPlanService::plan($pairs) + [
            // Lo que un relevo ya movió desde ESTE panel y devolverá la vuelta (solo esto).
            'journal_moved' => $cf::journal($fo::DNS_JOURNAL),
            'journal_moved_backup' => $cf::journal($fo::DNS_JOURNAL_BACKUP),
        ];
    }

    // ── Utilidades ───────────────────────────────────────────────────────

    private static function sh(string $cmd, int $timeout = 5): string
    {
        return trim((string)shell_exec('timeout ' . (int)$timeout . ' sh -c ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    private static function psql(int $port, string $sql): string
    {
        return self::sh('runuser -u postgres -- psql -p ' . $port . ' -XAtc ' . escapeshellarg($sql));
    }

    private static function role(): string
    {
        return Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    }

    private static function localIps(): array
    {
        return array_values(array_filter(preg_split('/\s+/', self::sh('hostname -I')) ?: []));
    }

    private static function findNode(string $ref): array
    {
        $nodes = ClusterService::getNodes();
        foreach ($nodes as $n) {
            if ((string)$n['id'] === $ref || strcasecmp((string)$n['name'], $ref) === 0) {
                return $n;
            }
        }
        // Como el argumento `node` del resto de herramientas: parte del nombre ("Filemon"),
        // solo si coincide con un único nodo.
        if (!ctype_digit($ref) && trim($ref) !== '') {
            $partial = array_values(array_filter($nodes, static fn($n) => stripos((string)$n['name'], $ref) !== false));
            if (count($partial) === 1) {
                return $partial[0];
            }
            if (count($partial) > 1) {
                throw new \RuntimeException("'{$ref}' coincide con varios nodos: " . implode(', ', array_column($partial, 'name')) . '. Usa el id.');
            }
        }
        throw new \RuntimeException("Nodo '{$ref}' no encontrado. Usa list_nodes.");
    }

    /** Clusters de PostgreSQL de datos (excluye la BD del panel). */
    private static function dataClusters(): array
    {
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $all = PgClusterService::listClusters();
        return array_values(array_filter($all, static fn($c) => $c['cluster'] !== 'panel'
            && !(count($all) > 1 && (int)$c['port'] === $panelPort)));
    }

    private static function redisRole(): array
    {
        $pass = '';
        foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                $pass = trim($m[1], '"\'');
            }
        }
        if (self::sh('command -v redis-cli') === '') {
            return ['installed' => false];
        }
        $info = self::sh(($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'redis-cli --no-auth-warning INFO replication');
        $get = static fn(string $k) => preg_match('/^' . $k . ':(.*)$/m', $info, $m) ? trim($m[1]) : null;
        return ['installed' => true, 'role' => $get('role'), 'master_host' => $get('master_host'),
            'master_link_status' => $get('master_link_status'), 'connected_slaves' => $get('connected_slaves')];
    }

    // ── failover_preflight ───────────────────────────────────────────────

    private static function preflight(): array
    {
        $role = self::role();
        $issues = [];
        $myIps = self::localIps();
        $masterIp = Settings::get('cluster_master_ip', '');

        $nodes = [];
        foreach (ClusterService::getNodes() as $n) {
            $nodes[] = ['id' => (int)$n['id'], 'name' => $n['name'], 'host' => parse_url((string)$n['api_url'], PHP_URL_HOST),
                'role' => $n['role'], 'status' => $n['status'], 'services' => json_decode((string)$n['services'], true)];
        }
        // Testigos = nodos que no son este servidor ni el master (los consulta el
        // worker antes de auto-promover para no hacerlo por una partición de red).
        $witnesses = array_values(array_filter($nodes, static fn($n) => !in_array($n['host'], $myIps, true) && $n['host'] !== $masterIp
            && $n['role'] !== 'master'));

        $servers = FailoverService::getServers();
        $cfg = FailoverService::getConfig();
        $mode = $cfg['failover_mode'];

        $accounts = [];
        foreach (CloudflareService::getConfiguredAccounts() as $a) {
            $accounts[] = ['name' => $a['name'] ?? '', 'zones' => array_map(static fn($z) => $z['name'] ?? '', $a['zones'] ?? [])];
        }

        $pg = [];
        foreach (self::dataClusters() as $c) {
            $rec = self::psql((int)$c['port'], 'SELECT pg_is_in_recovery()');
            $pg[$c['key']] = [
                'port' => (int)$c['port'],
                'in_recovery' => $rec === 't',
                'upstream' => $rec === 't' ? (self::psql((int)$c['port'], "SELECT status||' '||coalesce(sender_host,'') FROM pg_stat_wal_receiver") ?: null) : null,
                'replicas' => $rec === 't' ? [] : array_values(array_filter(explode("\n", self::psql((int)$c['port'],
                    "SELECT coalesce(client_addr::text,'local')||' '||usename||' '||state FROM pg_stat_replication")))),
            ];
        }
        $promotePlan = [];
        try {
            $promotePlan = ['postgresql' => FailoverSafetyService::promoteAllPgClusters(true),
                'redis' => FailoverSafetyService::promoteRedis(true)];
        } catch (\Throwable $e) {
            $promotePlan = ['error' => $e->getMessage()];
        }
        $redis = self::redisRole();

        $health = [];
        try {
            foreach (FailoverService::checkAllEndpoints() as $h) {
                $health[] = ['name' => $h['name'] ?? '', 'role' => $h['role'] ?? '', 'ok' => !empty($h['ok'])];
            }
        } catch (\Throwable $e) {
            $health = ['error' => $e->getMessage()];
        }

        $hooks = ['promote' => \MuseDockPanel\Services\RoleHookService::list('promote'),
            'demote' => \MuseDockPanel\Services\RoleHookService::list('demote')];

        // ── Problemas en llano ──
        foreach ($hooks as $ev => $list) {
            foreach ($list as $h) {
                if (!$h['valid'] && empty($h['ignored'])) {
                    $issues[] = "Script de relevo {$ev}.d/{$h['file']} NO se ejecutaría: {$h['reason']}.";
                }
            }
        }
        if ($role === 'standalone') {
            $issues[] = 'Este panel es standalone: no está unido a ningún cluster.';
        }
        if (!FailoverService::isConfigured()) {
            $issues[] = 'Failover sin configurar (falta un servidor primario y uno de relevo con IPs públicas): failover_configure en el master.';
        }
        if ($mode === 'manual') {
            $issues[] = 'Modo manual: si el master cae, solo se avisa; nadie promueve ni cambia el DNS.';
        }
        if ($role === 'slave') {
            if (!$masterIp) {
                $issues[] = 'Este slave no conoce la IP del master (cluster_master_ip): no puede detectar su caída.';
            }
            if (!$accounts) {
                $issues[] = 'Este slave no tiene cuentas de Cloudflare: en un relevo no podría cambiar el DNS.';
            }
            if (!$witnesses && $mode !== 'manual') {
                $issues[] = 'Sin nodo testigo: el slave promovería con su sola visión del master (riesgo de split-brain si falla la red). Añade un tercer nodo como testigo o usa semiauto.';
            }
            $myServer = array_values(array_filter($servers, static fn($s) => in_array($s['ip'] ?? '', $myIps, true)));
            if ($servers && !$myServer) {
                $issues[] = 'Ninguna IP de este servidor figura en failover_servers: nunca ganaría la elección de relevo.';
            }
            foreach ($pg as $k => $p) {
                if (!$p['in_recovery']) {
                    $issues[] = "PostgreSQL {$k} NO está en réplica en este slave.";
                }
            }
            if (!empty($redis['installed']) && ($redis['role'] ?? '') === 'master') {
                $issues[] = 'Redis de este slave no replica del master (role:master).';
            }
            if (Settings::get('repl_pg_role', 'standalone') === 'standalone' && array_filter($pg, static fn($p) => $p['in_recovery'])) {
                $issues[] = 'La réplica de PostgreSQL funciona pero el panel no la tiene registrada: replication_adopt.';
            }
            if (Settings::get('cluster_config_mirror', '0') !== '1') {
                $issues[] = 'La copia de configuración del master (supervisor, cron, Caddyfile, pools PHP) no está activada: lo nuevo del master no llegaría. config_mirror con enable=true.';
            } else {
                $last = json_decode(Settings::get('cluster_config_mirror_last', 'null'), true);
                if (!empty($last['issues'])) {
                    $issues[] = 'La última copia de configuración del master tuvo avisos: ' . implode(' | ', array_slice($last['issues'], 0, 3));
                }
            }
        }
        if ($role === 'master') {
            if (!$nodes) {
                $issues[] = 'Master sin nodos registrados.';
            }
            foreach ($pg as $k => $p) {
                if (!$p['in_recovery'] && !$p['replicas']) {
                    $issues[] = "PostgreSQL {$k} no tiene ninguna réplica conectada.";
                }
            }
            if (Settings::get('repl_pg_role', 'standalone') === 'standalone' && array_filter($pg, static fn($p) => $p['replicas'])) {
                $issues[] = 'Hay réplicas de PostgreSQL conectadas pero el panel no lo tiene registrado: replication_adopt.';
            }
        }
        // Sin canal de avisos, un fallo de réplica o del master pasa en silencio.
        foreach (self::notifyStatus()['issues'] as $ni) {
            $issues[] = $ni;
        }
        try {
            foreach (\MuseDockPanel\Services\ReplicationHealthService::issues() as $ri) {
                $issues[] = $ri;
            }
        } catch (\Throwable) {
        }

        return [
            'ready' => !$issues,
            'issues' => $issues,
            'role' => $role,
            'local_ips' => $myIps,
            'cluster_master_ip' => $masterIp ?: null,
            'nodes' => $nodes,
            'witnesses' => array_map(static fn($w) => $w['name'], $witnesses),
            'failover' => [
                'mode' => $mode,
                'state' => FailoverService::getState(),
                'servers' => array_map(static fn($s) => [
                    'name' => $s['name'] ?? '', 'ip' => $s['ip'] ?? '', 'role' => $s['role'] ?? '',
                    'priority' => $s['failover_priority'] ?? null, 'enabled' => $s['enabled'] ?? true,
                ], $servers),
            ],
            'cloudflare_accounts' => $accounts,
            'postgresql' => $pg,
            'promote_simulation' => $promotePlan,
            'hooks' => $hooks + ['dir' => \MuseDockPanel\Services\RoleHookService::BASE],
            'redis' => $redis,
            'replication_registered' => [
                'repl_pg_role' => Settings::get('repl_pg_role', 'standalone'),
                'repl_pg_user' => Settings::get('repl_pg_user', '') ?: null,
                'password_stored' => Settings::get('repl_pg_password', '') !== '',
                'repl_remote_ip' => Settings::get('repl_remote_ip', '') ?: null,
            ],
            'health' => $health,
        ];
    }

    // ── failover_configure ───────────────────────────────────────────────

    private static function configure(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('failover_configure se ejecuta en el MASTER del cluster.');
        }
        $node = self::findNode(trim((string)($args['failover_node'] ?? '')));
        $pIp = trim((string)($args['primary_ip'] ?? ''));
        $fIp = trim((string)($args['failover_ip'] ?? ''));
        $pub = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($pIp, FILTER_VALIDATE_IP, $pub) || !filter_var($fIp, FILTER_VALIDATE_IP, $pub) || $pIp === $fIp) {
            throw new \InvalidArgumentException('primary_ip y failover_ip deben ser IPv4 PÚBLICAS y distintas (son las de los registros DNS).');
        }
        if (!in_array($pIp, self::localIps(), true)) {
            throw new \InvalidArgumentException("primary_ip {$pIp} no es una IP de este servidor (" . implode(', ', self::localIps()) . ').');
        }
        $mode = in_array($args['mode'] ?? '', ['manual', 'semiauto', 'auto'], true) ? $args['mode'] : 'semiauto';
        $port = (int)($args['port'] ?? 443) ?: 443;
        $current = FailoverService::getServers();

        $failId = 'fo-' . (int)$node['id'];
        $new = [
            ['id' => 'primary-local', 'name' => (string)gethostname(), 'ip' => $pIp, 'role' => FailoverService::ROLE_PRIMARY,
                'port' => $port, 'failover_to' => $failId, 'dyndns' => false, 'failover_priority' => 99, 'enabled' => true],
            ['id' => $failId, 'name' => (string)$node['name'], 'ip' => $fIp, 'role' => FailoverService::ROLE_FAILOVER,
                'port' => $port, 'failover_to' => '', 'dyndns' => false, 'failover_priority' => 1, 'enabled' => true],
        ];

        $warnings = [];
        if ($mode === 'auto') {
            $warnings[] = 'Modo auto: el slave promueve Y la vuelta también es automática. Recomendado semiauto (vuelta manual).';
        }
        $others = array_filter(ClusterService::getNodes(), static fn($n) => (int)$n['id'] !== (int)$node['id']);
        if ($mode !== 'manual' && !$others) {
            $warnings[] = 'No hay un tercer nodo que haga de testigo: el slave decidiría solo con lo que él ve.';
        }
        $warnings[] = "El cambio de DNS lo hace {$node['name']} con SUS cuentas de Cloudflare: comprueba con failover_preflight en él que tiene la cuenta de las zonas afectadas.";

        $plan = [
            'mode' => ['from' => Settings::get('failover_mode', 'manual'), 'to' => $mode],
            'servers_current' => $current,
            'servers_new' => $new,
            'propagate_to' => array_values(array_map(static fn($n) => $n['name'], ClusterService::getActiveNodes())),
            'warnings' => $warnings,
        ];
        if ($current && !empty(array_diff(array_column($current, 'ip'), [$pIp, $fIp])) && empty($args['replace'])) {
            return ['applied' => false, 'plan' => $plan,
                'blocked' => 'Ya hay servidores de failover distintos configurados. Revisa el plan y repite con replace=true para sustituirlos.'];
        }
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }

        FailoverService::saveServers($new);
        FailoverService::saveConfig(['failover_mode' => $mode]);
        $push = FailoverService::pushConfigToSlaves();
        LogService::log('mcp.failover', 'configure', "Failover: primario {$pIp} → relevo {$node['name']} {$fIp}, modo {$mode}");
        return ['applied' => true, 'plan' => $plan, 'push' => $push];
    }

    // ── replication_adopt ────────────────────────────────────────────────

    private static function adopt(array $args): array
    {
        $clusters = self::dataClusters();
        if (!$clusters) {
            throw new \RuntimeException('No hay clusters de PostgreSQL de datos en este servidor.');
        }
        $detected = [];
        foreach ($clusters as $c) {
            $port = (int)$c['port'];
            if (self::psql($port, 'SELECT pg_is_in_recovery()') === 't') {
                $up = self::psql($port, "SELECT coalesce(sender_host,'')||'|'||coalesce(sender_port::text,'')||'|'||status FROM pg_stat_wal_receiver");
                [$host, $sport, $st] = array_pad(explode('|', $up), 3, '');
                $conninfo = self::psql($port, 'SHOW primary_conninfo');
                $user = preg_match('/\buser=(\S+)/', $conninfo, $m) ? trim($m[1], "'") : '';
                $detected[] = ['cluster' => $c['key'], 'port' => $port, 'role' => 'slave', 'peer' => $host,
                    'peer_port' => (int)$sport, 'user' => $user, 'status' => $st];
            } else {
                $rows = array_filter(explode("\n", self::psql($port, "SELECT coalesce(client_addr::text,'')||'|'||usename||'|'||state FROM pg_stat_replication")));
                foreach ($rows as $r) {
                    [$addr, $user, $st] = array_pad(explode('|', $r), 3, '');
                    $detected[] = ['cluster' => $c['key'], 'port' => $port, 'role' => 'master', 'peer' => $addr,
                        'peer_port' => null, 'user' => $user, 'status' => $st];
                }
            }
        }
        $active = array_values(array_filter($detected, static fn($d) => in_array($d['status'], ['streaming', 'catchup'], true)));
        if (!$active) {
            throw new \RuntimeException('No se detecta ninguna réplica de PostgreSQL en marcha (ni como master ni como slave). Nada que adoptar.');
        }
        $roles = array_unique(array_column($active, 'role'));
        if (count($roles) > 1) {
            throw new \RuntimeException('Este servidor es master de un cluster y slave de otro: la adopción automática no cubre ese caso.');
        }
        $role = $roles[0];
        $users = array_unique(array_column($active, 'user'));
        $peers = array_unique(array_column($active, 'peer'));
        $user = count($users) === 1 ? $users[0] : '';

        // Contraseña del usuario de réplica (nunca se devuelve).
        $pass = '';
        $passSource = null;
        $pf = trim((string)($args['password_file'] ?? ''));
        if ($pf !== '') {
            $real = realpath($pf) ?: '';
            if ($real === '' || !is_file($real) || !(str_starts_with($real, '/root/') || str_starts_with($real, '/var/lib/postgresql/'))) {
                throw new \InvalidArgumentException('password_file debe ser un fichero existente bajo /root/ o /var/lib/postgresql/.');
            }
            $pass = trim((string)strtok((string)file_get_contents($real), "\n"));
            $passSource = $real;
        } elseif ($role === 'slave') {
            foreach (@file('/var/lib/postgresql/.pgpass', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                $f = explode(':', $l, 5);
                if (count($f) === 5 && in_array($f[0], [$peers[0] ?? '', '*'], true) && in_array($f[3], [$user, '*'], true)) {
                    $pass = $f[4];
                    $passSource = '/var/lib/postgresql/.pgpass';
                    break;
                }
            }
        }

        $settings = [
            'repl_role' => $role,
            'repl_pg_role' => $role,
            'repl_pg_user' => $user,
            'repl_configured_at' => date('Y-m-d H:i:s'),
        ];
        if ($role === 'slave' && count($peers) === 1) {
            $settings['repl_remote_ip'] = $peers[0];
            $settings['repl_pg_remote_ip'] = $peers[0];
        }
        // MariaDB/MySQL: mismas claves que el asistente de Replicación del panel.
        $mysql = null;
        try {
            $ss = ReplicationService::getMysqlSlaveStatus();
            if ($ss && ($ss['Slave_IO_Running'] ?? $ss['Replica_IO_Running'] ?? '') === 'Yes'
                && ($ss['Slave_SQL_Running'] ?? $ss['Replica_SQL_Running'] ?? '') === 'Yes') {
                $mysql = ['role' => 'slave', 'peer' => (string)($ss['Master_Host'] ?? $ss['Source_Host'] ?? ''),
                    'port' => (int)($ss['Master_Port'] ?? $ss['Source_Port'] ?? 3306), 'user' => (string)($ss['Master_User'] ?? $ss['Source_User'] ?? '')];
                $settings['repl_mysql_role'] = 'slave';
                $settings['repl_mysql_remote_ip'] = $mysql['peer'];
                $settings['repl_mysql_port'] = (string)$mysql['port'];
                $settings['repl_mysql_user'] = $mysql['user'];
            } else {
                $pdo = ReplicationService::getMysqlPdo();
                foreach ($pdo ? $pdo->query('SHOW PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC) : [] as $p) {
                    if (str_starts_with((string)($p['Command'] ?? ''), 'Binlog Dump')) {
                        $mysql = ['role' => 'master', 'peer' => preg_replace('/:\d+$/', '', (string)($p['Host'] ?? '')), 'user' => (string)($p['User'] ?? '')];
                        $settings['repl_mysql_role'] = 'master';
                        // Los necesitará este nodo si un día vuelve como slave (demoteToSlave).
                        $settings['repl_mysql_user'] = $mysql['user'];
                        $settings['repl_mysql_port'] = '3306';
                        break;
                    }
                }
            }
        } catch (\Throwable) {
        }
        if ($mysql !== null) {
            $detected[] = ['engine' => 'mariadb/mysql'] + $mysql;
        }
        $warnings = [];
        if ($mysql !== null && $mysql['role'] !== $role) {
            $warnings[] = "MariaDB/MySQL es {$mysql['role']} y PostgreSQL {$role}: revisa que sea lo esperado.";
        }
        if ($pass === '') {
            $warnings[] = $role === 'master'
                ? 'Sin contraseña del usuario de réplica: indica password_file. La necesitará este servidor si algún día tiene que volver como slave (pg_rewind).'
                : 'No se encontró la contraseña en ~postgres/.pgpass: indica password_file.';
        }
        if ($user === '') {
            $warnings[] = 'Varios usuarios de réplica distintos: no se registra ninguno.';
        }

        $plan = ['detected' => $detected, 'settings_to_write' => $settings,
            // "password_status" y no "password": redact() taparía este texto informativo.
            'password_status' => $pass !== '' ? "se guardará cifrada (leída de {$passSource})" : 'no disponible',
            'touches_data' => false, 'warnings' => $warnings];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        foreach ($settings as $k => $v) {
            Settings::set($k, (string)$v);
        }
        if ($pass !== '') {
            Settings::set('repl_pg_password', ReplicationService::encryptPassword($pass));
            // Mismo usuario y contraseña en MariaDB (como en el montaje habitual del panel).
            if (($settings['repl_mysql_user'] ?? '') === $user && $user !== '') {
                Settings::set('repl_mysql_pass', ReplicationService::encryptPassword($pass));
            }
        }
        try {
            \MuseDockPanel\Database::update('servers', ['role' => $role], 'is_local = true');
        } catch (\Throwable) {
        }
        LogService::log('mcp.replication', 'adopt', "Réplica PG adoptada: rol {$role}, usuario {$user}, otro extremo " . implode(',', $peers));
        return ['applied' => true, 'plan' => $plan];
    }

    // ── Emparejamiento ───────────────────────────────────────────────────

    private static function pairingOpen(array $args): array
    {
        $role = self::role();
        $nodes = ClusterService::getNodes();
        if ($role === 'slave') {
            throw new \RuntimeException('Este panel es slave: el emparejamiento se abre en el master.');
        }
        $plan = [
            'this_panel_becomes' => 'master (al aprobar la primera solicitud)',
            'window_minutes' => 30,
            'master_url_for_slave' => \MuseDockPanel\Services\ClusterPairingService::localPanelUrl() ?: '(panel_hostname sin configurar: usa la URL pública del 8444)',
            'current_nodes' => array_map(static fn($n) => $n['name'], $nodes),
            'note' => 'Mientras está abierta, /api/pair/request acepta solicitudes (limitadas por IP). Nadie se une sin cluster_pair_approve.',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        $r = \MuseDockPanel\Services\ClusterPairingService::openWindow();
        return ['applied' => true, 'open_until' => $r['open_until'], 'master_url' => $r['master_url'],
            'next' => 'En el futuro slave: cluster_pair_request con master_url=' . ($r['master_url'] ?: '<URL pública del 8444 de este panel>') . '.'];
    }

    private static function pairRequest(array $args): array
    {
        $masterUrl = rtrim(trim((string)($args['master_url'] ?? '')), '/');
        if (!filter_var($masterUrl, FILTER_VALIDATE_URL) || !str_starts_with($masterUrl, 'https://')) {
            throw new \InvalidArgumentException('master_url debe ser una URL https:// del panel master.');
        }
        if (self::role() === 'master' && ClusterService::getNodes()) {
            throw new \RuntimeException('Este panel ya es master con nodos: no puede unirse como slave.');
        }
        $name = trim((string)($args['name'] ?? '')) ?: (string)gethostname();
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('name: solo letras, números, punto, guion y guion bajo (máx. 64).');
        }
        $selfUrl = rtrim(trim((string)($args['self_url'] ?? '')), '/') ?: \MuseDockPanel\Services\ClusterPairingService::localPanelUrl();
        if (!filter_var($selfUrl, FILTER_VALIDATE_URL) || !str_starts_with($selfUrl, 'https://')) {
            throw new \InvalidArgumentException('No se conoce la URL de este panel (panel_hostname): indica self_url.');
        }
        $services = array_values(array_unique(array_intersect((array)($args['services'] ?? ['web']), ['web', 'mail']))) ?: ['web'];
        $plan = [
            'send_to' => $masterUrl . '/api/pair/request',
            'name' => $name,
            'self_url' => $selfUrl,
            'services' => $services,
            'sends' => 'nombre, URL, servicios y el token de cluster de este panel (por TLS, panel a panel; no se muestra aquí)',
            'after' => 'el master aprueba el código con cluster_pair_approve; entonces este panel pasa a rol slave',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        $r = \MuseDockPanel\Services\ClusterPairingService::requestPairing($masterUrl, $name, $selfUrl, $services);
        return ['applied' => true, 'code' => $r['code'], 'expires' => $r['expires'],
            'next' => "Enseña el código {$r['code']} al usuario y apruébalo en el master con cluster_pair_approve (comprobando que coincide)."];
    }

    private static function pairPending(): array
    {
        $until = \MuseDockPanel\Services\ClusterPairingService::windowOpenUntil();
        return [
            'window_open' => $until > time(),
            'open_until' => $until > time() ? date('Y-m-d H:i:s', $until) : null,
            'pending' => \MuseDockPanel\Services\ClusterPairingService::listPending(),
        ];
    }

    private static function pairApprove(array $args): array
    {
        if (self::role() === 'slave') {
            throw new \RuntimeException('Este panel es slave: las solicitudes se aprueban en el master.');
        }
        $code = strtoupper(trim((string)($args['code'] ?? '')));
        $p = \MuseDockPanel\Services\ClusterPairingService::findPending($code);
        if (!$p) {
            throw new \RuntimeException('No hay ninguna solicitud pendiente con ese código (o ha caducado). Mira cluster_pair_pending.');
        }
        $masterUrl = rtrim(trim((string)($args['master_url'] ?? '')), '/') ?: \MuseDockPanel\Services\ClusterPairingService::localPanelUrl();
        if (!filter_var($masterUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Indica master_url (la URL de este panel con el mismo host que usó el slave).');
        }
        $pubIp = trim((string)($args['master_public_ip'] ?? ''));
        if ($pubIp !== '' && !filter_var($pubIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException('master_public_ip debe ser una IP pública.');
        }
        $plan = [
            'request' => ['code' => $p['code'], 'name' => $p['name'], 'api_url' => $p['api_url'], 'from_ip' => $p['from_ip'], 'services' => $p['services']],
            'steps' => [
                "probar la API de {$p['api_url']} con el token que envió",
                "registrar {$p['name']} como nodo del cluster (servicios: " . implode(',', $p['services']) . ')',
                "enviarle el token de este panel; el slave comprueba el nonce y se registra como slave de {$masterUrl}",
                'este panel pasa a rol master (si no lo era)',
            ],
            'master_public_ip' => $pubIp ?: '(sin indicar: el slave no sabrá qué IP vigilar para el failover hasta configurarlo)',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan,
                'next' => 'Confirma con el usuario que el código coincide con el que vio en el slave y repite con apply=true.'];
        }
        $r = \MuseDockPanel\Services\ClusterPairingService::approve($code, $masterUrl, $pubIp);
        return ['applied' => true, 'node_id' => $r['node_id'], 'steps' => $r['steps']];
    }

    // ── Cola y sincronización ────────────────────────────────────────────

    private static function queue(array $args): array
    {
        $status = (string)($args['status'] ?? 'all');
        $limit = max(1, min(200, (int)($args['limit'] ?? 30)));
        $where = in_array($status, ['pending', 'processing', 'completed', 'failed', 'cancelled'], true) ? 'WHERE q.status = :st' : '';
        $params = $where ? ['st' => $status, 'lim' => $limit] : ['lim' => $limit];
        $rows = \MuseDockPanel\Database::fetchAll(
            "SELECT q.id, n.name AS node, q.action, q.payload->>'hosting_action' AS hosting_action,
                    coalesce(q.payload->'hosting_data'->>'domain', q.payload->'hosting_data'->>'main_domain') AS domain,
                    q.status, q.attempts, q.max_attempts, q.error_message, q.created_at, q.completed_at
             FROM cluster_queue q LEFT JOIN cluster_nodes n ON n.id = q.node_id
             {$where} ORDER BY q.created_at DESC, q.id DESC LIMIT :lim", $params);
        $counts = [];
        foreach (\MuseDockPanel\Database::fetchAll("SELECT status, COUNT(*) AS c FROM cluster_queue GROUP BY status") as $r) {
            $counts[$r['status']] = (int)$r['c'];
        }
        return ['counts' => $counts, 'items' => $rows];
    }

    private static function syncHostings(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('cluster_sync_hostings se ejecuta en el MASTER del cluster.');
        }
        $node = self::findNode(trim((string)($args['target_node'] ?? '')));
        $domain = trim((string)($args['domain'] ?? '')) ?: null;
        $accounts = \MuseDockPanel\Database::fetchAll("SELECT domain, username, system_uid FROM hosting_accounts WHERE status != 'deleted' ORDER BY id");
        if ($domain !== null) {
            $accounts = array_values(array_filter($accounts, static fn($a) => strcasecmp((string)$a['domain'], $domain) === 0));
            if (!$accounts) {
                throw new \InvalidArgumentException("No hay ningún hosting {$domain} en este master.");
            }
        }
        $plan = [
            'node' => $node['name'],
            'hostings' => array_map(static fn($a) => "{$a['domain']} (usuario {$a['username']}, uid {$a['system_uid']})", $accounts),
            'on_the_node' => 'crea cada hosting, o lo ADOPTA (solo registro en el panel) si ya existe el usuario con el mismo UID y su carpeta',
            'also' => 'alias/redirecciones y bases de datos registradas de cada hosting' . ($domain === null ? '; redirecciones sin hosting; las operaciones fallidas antiguas de este nodo se marcan canceladas' : ''),
            'deletes' => 'nada',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        $r = ClusterService::enqueueFullHostingSync((int)$node['id'], $domain);
        LogService::log('mcp.cluster', 'sync-hostings', "Encolados {$r['hostings']} hostings + {$r['redirects']} redirects al nodo {$node['name']}");
        return ['applied' => true, 'enqueued' => $r, 'next' => 'El cluster-worker los procesa cada minuto: sigue el resultado con cluster_queue.'];
    }

    // ── Firewall: orígenes de confianza ──────────────────────────────────

    private static function fail2banIgnore(): array
    {
        $c = is_file('/etc/fail2ban/jail.local') ? (string)file_get_contents('/etc/fail2ban/jail.local') : '';
        return preg_match('/^ignoreip\s*=\s*(.+)$/m', $c, $m) ? array_values(array_filter(preg_split('/[\s,]+/', trim($m[1])) ?: [])) : [];
    }

    private static function fail2banManage(array $args): array
    {
        if (trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')) === '') {
            throw new \RuntimeException('fail2ban no está instalado en este servidor.');
        }
        preg_match('/Jail list:\s*(.+)$/m', (string)shell_exec('fail2ban-client status 2>&1'), $jm);
        $jails = array_values(array_filter(array_map('trim', explode(',', $jm[1] ?? ''))));
        $banned = [];
        foreach ($jails as $j) {
            preg_match('/Banned IP list:\s*(.*)$/m', (string)shell_exec('fail2ban-client status ' . escapeshellarg($j) . ' 2>&1'), $bm);
            $banned[$j] = array_values(array_filter(preg_split('/\s+/', trim($bm[1] ?? '')) ?: []));
        }
        $ignore = self::fail2banIgnore();
        $ip = trim((string)($args['ip'] ?? ''));
        if ($ip === '') {
            return ['banned_now' => array_filter($banned), 'whitelist' => $ignore];
        }
        [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
        if (!filter_var($addr, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int)$bits > 128))) {
            throw new \InvalidArgumentException("No es una IP ni un rango CIDR válido: {$ip}");
        }
        $in = array_keys(array_filter($banned, fn($l) => in_array($addr, $l, true)));
        $wl = !empty($args['whitelist']) && !in_array($ip, $ignore, true);
        $plan = ['ip' => $ip, 'actions' => []];
        foreach ($in as $j) {
            $plan['actions'][] = "Desbloquear en el jail {$j}";
        }
        if ($wl) {
            $plan['actions'][] = 'Añadir a la lista blanca (ignoreip de /etc/fail2ban/jail.local) y recargar fail2ban';
        } elseif (!empty($args['whitelist'])) {
            $plan['actions'][] = 'Ya está en la lista blanca';
        }
        if (!$plan['actions'] || (!$in && !$wl)) {
            return ['status' => 'nada_que_hacer', 'note' => 'No está bloqueada' . (!empty($args['whitelist']) ? ' y ya está en la lista blanca.' : '.')] + $plan;
        }
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $done = [];
        foreach ($in as $j) {
            $o = trim((string)shell_exec('fail2ban-client set ' . escapeshellarg($j) . ' unbanip ' . escapeshellarg($addr) . ' 2>&1'));
            $done[] = "{$j}: " . ($o === '1' ? 'desbloqueada' : $o);
        }
        if ($wl) {
            $file = '/etc/fail2ban/jail.local';
            $c = is_file($file) ? (string)file_get_contents($file) : "[DEFAULT]\n";
            @copy($file, $file . '.bak.' . date('Ymd_His'));
            $line = 'ignoreip = ' . implode(' ', array_values(array_unique(array_merge(['127.0.0.1/8', '::1'], $ignore, [$ip]))));
            $c = preg_match('/^ignoreip\s*=.*$/m', $c) ? preg_replace('/^ignoreip\s*=.*$/m', $line, $c, 1)
                : (str_contains($c, '[DEFAULT]') ? preg_replace('/^\[DEFAULT\]\s*$/m', "[DEFAULT]\n{$line}", $c, 1) : "[DEFAULT]\n{$line}\n" . $c);
            file_put_contents($file, $c);
            $done[] = 'lista blanca: ' . trim((string)shell_exec('fail2ban-client reload 2>&1') ?: 'recargado');
        }
        LogService::log('mcp.fail2ban', $ip, implode('; ', $done));
        return ['status' => 'aplicado', 'done' => $done] + $plan;
    }

    private static function trustedSources(array $args): array
    {
        $manual = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', Settings::get('firewall_trusted_sources', '')) ?: [])));
        $info = ['manual' => $manual, 'effective' => \MuseDockPanel\Services\FirewallAuditService::trustedSources()];
        if (!array_key_exists('sources', $args)) {
            return $info;
        }
        $new = [];
        foreach ((array)$args['sources'] as $s) {
            $s = trim((string)$s);
            [$ip, $bits] = array_pad(explode('/', $s, 2), 2, null);
            if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int)$bits > 128))) {
                throw new \InvalidArgumentException("No es una IP ni un rango CIDR válido: {$s}");
            }
            $new[] = $s;
        }
        $new = array_values(array_unique($new));
        $plan = ['from' => $manual, 'to' => $new, 'note' => 'Solo cambia la lista que usa la auditoría para no avisar; el firewall no se toca.'];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        Settings::set('firewall_trusted_sources', implode(',', $new));
        \MuseDockPanel\Services\FirewallAuditService::refreshStored();
        return ['applied' => true, 'plan' => $plan];
    }

    // ── Copia de la configuración del sistema ────────────────────────────

    private static function configMirror(array $args): array
    {
        $svc = \MuseDockPanel\Services\ConfigMirrorService::class;
        $status = [
            'automatic_every_5_min' => \MuseDockPanel\Settings::get('cluster_config_mirror', '0') === '1',
            'last_run' => json_decode(\MuseDockPanel\Settings::get('cluster_config_mirror_last', 'null'), true),
        ];
        if (empty($args['apply'])) {
            $dry = $svc::run(false);
            return ['applied' => false, 'status' => $status, 'would_do' => $dry['actions'], 'issues' => $dry['issues'],
                'next' => 'Muestra al usuario lo que haría y, si lo confirma, repite con apply=true (y enable=true para dejarlo automático).'];
        }
        $r = $svc::run(true);
        if (array_key_exists('enable', $args)) {
            \MuseDockPanel\Settings::set('cluster_config_mirror', !empty($args['enable']) ? '1' : '0');
            $status['automatic_every_5_min'] = !empty($args['enable']);
        }
        LogService::log('mcp.mirror', 'run', 'Copia de configuración del master ejecutada por MCP');
        return ['applied' => true, 'status' => $status, 'done' => $r['actions'], 'issues' => $r['issues']];
    }

    // ── Límites de PHP de un hosting ─────────────────────────────────────

    private static function hostingPhp(array $args): array
    {
        $domain = strtolower(trim((string)($args['domain'] ?? '')));
        $acc = \MuseDockPanel\Database::fetchOne("SELECT * FROM hosting_accounts WHERE lower(domain) = :d AND status != 'deleted'", ['d' => $domain]);
        if (!$acc) {
            throw new \InvalidArgumentException("No hay ninguna cuenta de hosting con dominio {$domain} en este panel.");
        }
        $svc = \MuseDockPanel\Services\HostingPhpSettingsService::class;
        $current = $svc::current($acc);
        $wanted = array_intersect_key($args, $svc::ALLOWED);
        [$changes, $errors] = $svc::validate($wanted);
        $info = ['domain' => $acc['domain'], 'php_version' => $acc['php_version'], 'pool' => $svc::poolFile($acc), 'current' => $current];
        if (!is_file($svc::poolFile($acc))) {
            throw new \RuntimeException("La cuenta {$acc['domain']} no tiene pool propio de PHP-FPM (usuario {$acc['username']}, PHP {$acc['php_version']}): "
                . 'probablemente la sirve el pool general (www.conf) u otro servicio. Sus límites no se pueden cambiar desde aquí.');
        }
        if ($errors) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
        if (!$changes) {
            return $info + ['note' => 'Sin cambios pedidos: estos son los valores actuales.'];
        }
        if (self::role() === 'slave') {
            throw new \RuntimeException('Este servidor es slave: los límites de PHP se cambian en el master.');
        }
        $plan = $info + ['change' => array_map(static fn($k) => ['from' => $current[$k] ?? null, 'to' => $changes[$k]], array_combine(array_keys($changes), array_keys($changes))),
            'then' => 'se comprueba la configuración de PHP-FPM y se recarga (sin cortar las visitas); si falla, se deja como estaba'];
        if (!empty($changes['upload_max_filesize']) && empty($changes['post_max_size']) && !empty($current['post_max_size'])) {
            $plan['warning'] = 'post_max_size debería ser algo mayor que upload_max_filesize (ahora es ' . $current['post_max_size'] . ').';
        }
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        if (\MuseDockPanel\Settings::get('mcp_allow_write', '0') !== '1') {
            throw new \RuntimeException('Las acciones que modifican están desactivadas en este servidor.');
        }
        $r = $svc::apply($acc, $changes);
        if (!$r['ok']) {
            throw new \RuntimeException($r['error']);
        }
        return ['applied' => true, 'before' => $r['before'], 'after' => $r['after']];
    }

    // ── Diferencias master ↔ nodos ───────────────────────────────────────

    private static function drift(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('cluster_drift se ejecuta en el MASTER del cluster.');
        }
        $ref = trim((string)($args['target_node'] ?? ''));
        $nodes = $ref !== '' ? [self::findNode($ref)] : ClusterService::getNodes();
        $out = [];
        foreach ($nodes as $n) {
            try {
                $out[] = \MuseDockPanel\Services\ClusterDriftService::compareWithNode((int)$n['id']);
            } catch (\Throwable $e) {
                $out[] = ['node' => $n['name'], 'error' => $e->getMessage()];
            }
        }
        return ['all_in_sync' => !array_filter($out, static fn($r) => empty($r['in_sync'])), 'nodes' => $out];
    }

    // ── Sincronización de ficheros ───────────────────────────────────────

    /** Nodos a los que lsyncd copia (mismo criterio que FileSyncService::generateLsyncdConfig). */
    private static function filesyncTargets(): array
    {
        $out = [];
        foreach (ClusterService::getWebNodes() as $n) {
            if (empty($n['standby'])) {
                $out[] = ['id' => (int)$n['id'], 'name' => $n['name'],
                    'host' => \MuseDockPanel\Services\FileSyncService::extractHostFromUrl((string)$n['api_url'])];
            }
        }
        return $out;
    }

    private static function filesyncStatus(): array
    {
        $cfg = \MuseDockPanel\Services\FileSyncService::getConfig();
        $st = \MuseDockPanel\Services\FileSyncService::getLsyncdStatus();
        $log = array_slice(explode("\n", (string)($st['log_tail'] ?? $st['logTail'] ?? '')), -10);
        return [
            'enabled' => $cfg['enabled'],
            'mode' => $cfg['sync_mode'],
            'method' => $cfg['method'],
            'interval_minutes' => $cfg['interval_minutes'],
            'ssh_key_path' => $cfg['ssh_key_path'],
            'targets' => self::filesyncTargets(),
            'excludes' => array_values(array_unique(array_merge(
                \MuseDockPanel\Services\FileSyncService::getLsyncdDefaultExcludes(),
                \MuseDockPanel\Services\FileSyncService::parseExcludePatterns((string)$cfg['exclude_patterns'])))),
            'lsyncd' => [
                'installed' => $st['installed'] ?? false,
                'running' => $st['running'] ?? false,
                'enabled' => $st['enabled'] ?? false,
                'health' => $st['health'] ?? null,
                'issues' => $st['issues'] ?? [],
                'log_tail' => array_values(array_filter($log)),
            ],
        ];
    }

    private static function filesyncExtraPaths(array $args): array
    {
        $fs = \MuseDockPanel\Services\FileSyncService::class;
        $curPaths = $fs::extraPaths();
        $curNodes = array_map('intval', $fs::parseExcludePatterns(Settings::get('filesync_extra_nodes', '')));
        $nodeName = static function (int $id): string {
            $n = ClusterService::getNode($id);
            return $n ? (string)$n['name'] : "#{$id}";
        };
        $info = ['paths' => $curPaths, 'target_nodes' => array_map($nodeName, $curNodes)];
        if (!array_key_exists('paths', $args) && !array_key_exists('target_nodes', $args)) {
            return $info;
        }
        if (self::role() !== 'master') {
            throw new \RuntimeException('filesync_extra_paths se configura en el MASTER.');
        }
        $raw = implode("\n", (array)($args['paths'] ?? $curPaths));
        $paths = $fs::extraPaths($raw);
        $rejected = array_values(array_diff(array_map(static fn($p) => rtrim((string)$p, '/'), (array)($args['paths'] ?? [])), $paths));
        $nodes = array_key_exists('target_nodes', $args)
            ? array_map(static fn($r) => (int)self::findNode((string)$r)['id'], (array)$args['target_nodes'])
            : $curNodes;
        $sizes = [];
        foreach ($paths as $p) {
            $sizes[$p] = self::sh('du -sh ' . escapeshellarg($p) . " | cut -f1", 20) ?: '?';
        }
        $plan = [
            'paths' => $sizes,
            // No válidas: fuera de /opt, /srv o /home, inexistentes o el propio panel.
            'rejected' => $rejected,
            'target_nodes' => array_map($nodeName, $nodes),
            'mirror' => 'ESPEJO: en esos nodos, dentro de estas carpetas, se borra lo que no exista aquí. La primera pasada copia todo.',
            'then' => 'se regenera la configuración de lsyncd y se reinicia',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        Settings::set('filesync_extra_paths', implode("\n", $paths));
        Settings::set('filesync_extra_nodes', implode(',', $nodes));
        $r = $fs::reloadLsyncd();
        LogService::log('mcp.filesync', 'extra-paths', 'Carpetas extra: ' . implode(', ', $paths) . ' → nodos ' . implode(',', $nodes));
        return ['applied' => true, 'plan' => $plan, 'lsyncd_running' => !empty($r['ok'])];
    }

    private static function filesyncConfigure(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('filesync_configure se ejecuta en el MASTER del cluster.');
        }
        $targets = self::filesyncTargets();
        if (!$targets) {
            throw new \RuntimeException('No hay nodos web activos (no standby) a los que copiar ficheros.');
        }
        $mode = ($args['mode'] ?? 'lsyncd') === 'periodic' ? 'periodic' : 'lsyncd';
        $interval = max(1, (int)($args['interval_minutes'] ?? 10));
        $mirrorGit = !array_key_exists('mirror_git', $args) || !empty($args['mirror_git']);
        $mirrorNm = !array_key_exists('mirror_node_modules', $args) || !empty($args['mirror_node_modules']);
        $cfg = \MuseDockPanel\Services\FileSyncService::getConfig();
        $keyPath = (string)$cfg['ssh_key_path'];

        $drop = array_filter([$mirrorGit ? '.git' : null, $mirrorNm ? 'node_modules' : null]);
        $lsyncdEx = array_values(array_diff(\MuseDockPanel\Services\FileSyncService::getLsyncdDefaultExcludes(), $drop));
        $rsyncEx = array_values(array_diff(\MuseDockPanel\Services\FileSyncService::getRsyncDefaultExcludes(), $drop));
        $userEx = \MuseDockPanel\Services\FileSyncService::parseExcludePatterns((string)$cfg['exclude_patterns']);
        $userEx = array_values(array_diff($userEx, $drop));

        $plan = [
            'mode' => $mode === 'lsyncd' ? 'lsyncd: cada cambio se copia a los ~15 s' : "periodic: rsync cada {$interval} min",
            'source' => '/var/www/vhosts/ (todos los hostings de este master)',
            'targets' => array_map(static fn($t) => "{$t['name']} (root@{$t['host']}:/var/www/vhosts/)", $targets),
            'steps' => [
                "clave SSH de root de este servidor en {$keyPath} (se crea si no existe)",
                'se instala su clave pública en /root/.ssh/authorized_keys de cada nodo (por la API del cluster): este master tendrá SSH de root en ellos, como en cualquier cluster del panel',
                'se comprueba el SSH a cada nodo; si alguno falla, se para aquí',
                $mode === 'lsyncd' ? 'se instala lsyncd si falta, se genera /etc/lsyncd/lsyncd.conf.lua y se arranca' : 'el filesync-worker hará la copia periódica',
            ],
            'mirror' => 'ESPEJO: la primera pasada copia todo y BORRA en el nodo lo que no exista aquí (salvo exclusiones). Después, cada cambio o borrado se replica.',
            'excludes' => array_values(array_unique(array_merge($mode === 'lsyncd' ? $lsyncdEx : $rsyncEx, $userEx))),
            'mirror_git' => $mirrorGit,
            'mirror_node_modules' => $mirrorNm,
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }

        $steps = [];
        $key = \MuseDockPanel\Services\FileSyncService::generateSshKey($keyPath);
        if (empty($key['ok']) || empty($key['public_key'])) {
            throw new \RuntimeException('No se pudo preparar la clave SSH: ' . ($key['error'] ?? '?'));
        }
        $steps[] = !empty($key['exists']) ? "clave SSH existente ({$keyPath})" : "clave SSH creada ({$keyPath})";

        foreach ($targets as $t) {
            $r = ClusterService::callNode($t['id'], 'POST', 'api/cluster/action',
                ['action' => 'install-ssh-key', 'payload' => ['public_key' => $key['public_key']]]);
            if (empty($r['ok']) || empty($r['data']['ok'])) {
                throw new \RuntimeException("No se pudo instalar la clave en {$t['name']}: " . ($r['data']['error'] ?? $r['error'] ?? '?') . '. No se ha cambiado la configuración.');
            }
            $ssh = \MuseDockPanel\Services\FileSyncService::testSshConnection($t['host'], (int)$cfg['ssh_port'], $keyPath, 'root');
            if (empty($ssh['ok'])) {
                throw new \RuntimeException("La clave está instalada en {$t['name']}, pero el SSH a {$t['host']} falla: " . ($ssh['error'] ?? '?')
                    . '. Revisa que el puerto 22 del nodo admita este servidor (por la VPN). No se ha cambiado la configuración.');
            }
            $steps[] = "{$t['name']}: clave instalada y SSH comprobado";
        }

        if ($mode === 'lsyncd' && !\MuseDockPanel\Services\FileSyncService::isLsyncdInstalled()) {
            $inst = \MuseDockPanel\Services\FileSyncService::installLsyncd();
            if (empty($inst['ok'])) {
                throw new \RuntimeException('No se pudo instalar lsyncd.');
            }
            $steps[] = 'lsyncd instalado';
        }

        \MuseDockPanel\Services\FileSyncService::saveConfig([
            'filesync_enabled' => '1',
            'filesync_sync_mode' => $mode,
            'filesync_method' => 'ssh',
            'filesync_ssh_user' => 'root',
            'filesync_ssh_key_path' => $keyPath,
            'filesync_interval' => (string)$interval,
            'filesync_lsyncd_default_excludes' => implode("\n", $lsyncdEx),
            'filesync_rsync_default_excludes' => implode("\n", $rsyncEx),
            'filesync_exclude' => implode(',', $userEx),
        ]);
        $steps[] = 'configuración guardada';

        if ($mode === 'lsyncd') {
            $start = \MuseDockPanel\Services\FileSyncService::startLsyncd();
            $steps[] = !empty($start['ok']) ? 'lsyncd arrancado (hace ahora la primera pasada completa)' : 'lsyncd NO arrancó: ' . trim((string)($start['output'] ?? $start['error'] ?? ''));
        }
        LogService::log('mcp.filesync', 'configure', "Sincronización de ficheros {$mode} hacia " . implode(', ', array_column($targets, 'name')));
        return ['applied' => true, 'steps' => $steps, 'next' => 'Sigue la primera pasada con filesync_status.'];
    }

    // ── cluster_node_services ────────────────────────────────────────────

    private static function nodeServices(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('cluster_node_services se ejecuta en el MASTER del cluster.');
        }
        $node = self::findNode(trim((string)($args['target_node'] ?? '')));
        $services = array_values(array_unique(array_intersect((array)($args['services'] ?? []), ['web', 'mail'])));
        if (!$services) {
            throw new \InvalidArgumentException('services debe incluir web, mail o ambos.');
        }
        $plan = ['node' => $node['name'], 'from' => json_decode((string)$node['services'], true), 'to' => $services];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        ClusterService::updateNode((int)$node['id'], ['services' => json_encode($services)]);
        LogService::log('mcp.cluster', 'node-services', "Nodo {$node['name']}: servicios " . implode(',', $services));
        return ['applied' => true, 'plan' => $plan];
    }
}
