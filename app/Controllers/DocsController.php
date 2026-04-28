<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;

class DocsController
{
    private const DOCS_VIEW_BASE = '/opt/musedock-panel/resources/views/help/';
    private const SHORTCUTS_SETTING_KEY = 'docs_special_shortcut_slugs';

    private function settingsIconBySlug(string $slug): string
    {
        $map = [
            'server' => 'bi-server',
            'php' => 'bi-filetype-php',
            'ssl-tls' => 'bi-shield-lock',
            'security' => 'bi-lock',
            'fail2ban' => 'bi-shield-exclamation',
            'cron' => 'bi-clock-history',
            'caddy' => 'bi-globe',
            'logs' => 'bi-terminal',
            'replication' => 'bi-arrow-repeat',
            'firewall' => 'bi-shield-fill',
            'wireguard' => 'bi-hdd-network',
            'notifications' => 'bi-bell',
            'cluster' => 'bi-diagram-3',
            'proxy-routes' => 'bi-diagram-2',
            'cloudflare-dns' => 'bi-cloud-fill',
            'system-health' => 'bi-heart-pulse',
            'updates' => 'bi-cloud-arrow-down',
            'services' => 'bi-hdd-rack',
            'portal-clientes' => 'bi-people',
            'federation' => 'bi-arrow-left-right',
        ];

        return $map[$slug] ?? 'bi-gear-wide-connected';
    }

    private function dedupeTopics(array $topics): array
    {
        $deduped = [];
        foreach ($topics as $topic) {
            $url = (string)($topic['url'] ?? '');
            if ($url === '' || isset($deduped[$url])) {
                continue;
            }
            $deduped[$url] = $topic;
        }
        return array_values($deduped);
    }

    private function childTopics(array $guides): array
    {
        $specialShortcutSlugs = $this->specialShortcutSlugs($guides);
        $topics = [];
        foreach ($guides as $slug => $guide) {
            $topics[] = [
                'title' => 'Settings: ' . (string)($guide['title'] ?? $slug),
                'description' => (string)($guide['summary'] ?? ''),
                'url' => '/docs/settings/' . $slug,
                'panel_url' => (string)($guide['panel_url'] ?? ''),
                'category' => 'Settings / Hijo',
                'icon' => $this->settingsIconBySlug($slug),
                'keywords' => mb_strtolower(trim(
                    $slug . ' ' .
                    $this->flattenSearchValue($guide['quick_steps'] ?? []) . ' ' .
                    $this->flattenSearchValue($guide['checklist'] ?? []) . ' ' .
                    $this->flattenSearchValue($guide['pitfalls'] ?? [])
                )),
                'special_shortcut' => in_array($slug, $specialShortcutSlugs, true),
            ];
        }
        return $this->dedupeTopics($topics);
    }

    private function mailGuides(): array
    {
        return [
            'general' => [
                'title' => 'General',
                'summary' => 'Resumen operativo del modulo de correo, estado global y acciones base.',
                'panel_url' => '/mail?tab=general',
                'icon' => 'bi-envelope',
                'keywords' => 'mail general estado setup instalacion modo correo',
                'view' => 'mail-general',
            ],
            'domains' => [
                'title' => 'Dominios',
                'summary' => 'Dominios autorizados y estado de activacion en modo relay/completo.',
                'panel_url' => '/mail?tab=domains',
                'icon' => 'bi-globe',
                'keywords' => 'mail dominios autorizados relay completo dkim smtp',
                'view' => 'mail-domains',
            ],
            'webmail' => [
                'title' => 'Webmail',
                'summary' => 'Que configura Webmail, que queda bloqueado, y que revisar en DNS/IMAP/SMTP.',
                'panel_url' => '/mail?tab=webmail#webmail',
                'icon' => 'bi-envelope',
                'keywords' => 'mail webmail roundcube imap smtp caddy sieve managesieve candado bloqueo configuracion dns verificacion',
                'view' => 'mail-webmail',
            ],
            'relay' => [
                'title' => 'Relay',
                'summary' => 'Flujo relay privado: dominios remitentes, usuarios SMTP y estado por dominio.',
                'panel_url' => '/mail?tab=relay',
                'icon' => 'bi-diagram-3',
                'keywords' => 'mail relay privado wireguard dominio remitente usuarios smtp pending active',
                'view' => 'mail-relay',
            ],
            'queue' => [
                'title' => 'Cola',
                'summary' => 'Cola de Postfix, reintentos, borrado controlado e historico reciente de relay.',
                'panel_url' => '/mail?tab=queue',
                'icon' => 'bi-inboxes',
                'keywords' => 'mail queue cola postfix deferred reintento borrar historico relay log',
                'view' => 'mail-queue',
            ],
            'migration' => [
                'title' => 'Migracion',
                'summary' => 'Pasos para mover configuracion y operativa de correo entre modos/nodos.',
                'panel_url' => '/mail?tab=migration',
                'icon' => 'bi-arrow-left-right',
                'keywords' => 'mail migracion mover modo correo nodo transferencia',
                'view' => 'mail-migration',
            ],
            'infra' => [
                'title' => 'Infra',
                'summary' => 'Instalacion o actualizacion del servidor mail y parametros estructurales.',
                'panel_url' => '/mail?tab=infra&setup=1',
                'icon' => 'bi-hdd-network',
                'keywords' => 'mail infra instalar actualizar servidor modo correo postfix dovecot opendkim',
                'view' => 'mail-infra',
            ],
            'deliverability' => [
                'title' => 'Entregabilidad',
                'summary' => 'Checks DNS en tiempo real: SPF, DKIM, DMARC, A hostname, PTR/rDNS y blacklist.',
                'panel_url' => '/mail?tab=deliverability',
                'icon' => 'bi-clipboard',
                'keywords' => 'mail entregabilidad spf dkim dmarc ptr rdns blacklist dns check',
                'view' => 'mail-deliverability',
            ],
            'hostname' => [
                'title' => 'Hostname de correo',
                'summary' => 'Cuando usar dominio raiz o subdominio mail para SMTP/IMAP, PTR, MX, certificados y Cloudflare.',
                'panel_url' => '/mail?tab=infra&setup=1',
                'icon' => 'bi-signpost-2',
                'keywords' => 'mail hostname dominio raiz subdominio mail.example.com example.com ptr rdns mx cloudflare proxy solo dns certificado postfix imap smtp',
                'view' => 'mail-hostname',
            ],
        ];
    }

    private function mailChildTopics(): array
    {
        $topics = [];
        foreach ($this->mailGuides() as $slug => $guide) {
            $topics[] = [
                'title' => 'Mail: ' . (string)($guide['title'] ?? $slug),
                'description' => (string)($guide['summary'] ?? ''),
                'url' => '/docs/mail/' . $slug,
                'panel_url' => (string)($guide['panel_url'] ?? ''),
                'category' => 'Mail / Hijo',
                'icon' => (string)($guide['icon'] ?? 'bi-envelope'),
                'keywords' => mb_strtolower((string)($guide['keywords'] ?? 'mail')),
            ];
        }

        return $this->dedupeTopics($topics);
    }

    private function bugGuides(): array
    {
        return [
            'err-ssl-protocol-error' => [
                'title' => 'ERR_SSL_PROTOCOL_ERROR en panel por IP/dominio',
                'summary' => 'Como diagnosticar cuando Chrome no muestra "Avanzado" y el puerto HTTPS responde mal.',
                'panel_url' => '/settings/server',
                'icon' => 'bi-bug',
                'keywords' => 'bug err_ssl_protocol_error ssl protocol error wrong version number tls caddy ip dominio 8444 certificado autofirmado avanzado chrome curl reverse proxy',
                'view' => 'bug-err-ssl-protocol-error',
            ],
            'caddy-recovery' => [
                'title' => 'Restaurar Caddy/web tras reinstalacion accidental',
                'summary' => 'Como recuperar una web cuando el Caddyfile queda solo con el bloque del panel, restaurar backups y corregir permisos.',
                'panel_url' => '/settings/caddy',
                'icon' => 'bi-arrow-counterclockwise',
                'keywords' => 'bug caddy recovery recuperar restaurar backup caddyfile reinstalacion accidental panel only 8444 web caida permission denied root root chmod 0644 muserelay asterisk mabelle',
                'view' => 'bug-caddy-recovery',
            ],
            'caddy-backups' => [
                'title' => 'Backups de Caddy y reconstruccion sin backup',
                'summary' => 'Politica de snapshots diarios, last-known-good y reconstruccion manual de dominios si no hay backup.',
                'panel_url' => '/settings/caddy',
                'icon' => 'bi-shield-check',
                'keywords' => 'bug caddy backups caddyfile rotacion 15 dias last-known-good reconstruccion sin backup vhosts php-fpm reverse proxy certificados restaurar',
                'view' => 'bug-caddy-backups',
            ],
        ];
    }

    private function bugChildTopics(): array
    {
        $topics = [];
        foreach ($this->bugGuides() as $slug => $guide) {
            $topics[] = [
                'title' => 'Bug: ' . (string)($guide['title'] ?? $slug),
                'description' => (string)($guide['summary'] ?? ''),
                'url' => '/docs/bugs/' . $slug,
                'panel_url' => (string)($guide['panel_url'] ?? ''),
                'category' => 'Bugs / Articulo',
                'icon' => (string)($guide['icon'] ?? 'bi-bug'),
                'keywords' => mb_strtolower((string)($guide['keywords'] ?? 'bug incidencia')),
            ];
        }

        return $this->dedupeTopics($topics);
    }

    private function parentTopics(array $guides): array
    {
        return $this->dedupeTopics([
            [
                'title' => 'Settings: mapa de secciones',
                'description' => 'Mapa base. Desde aqui entras a todas las guias hijas de Settings.',
                'url' => '/docs/settings-sections',
                'category' => 'Guia padre',
                'icon' => 'bi-sliders2',
                'keywords' => 'settings padre hijos secciones mapa',
            ],
            [
                'title' => 'Mail: mapa de secciones',
                'description' => 'Mapa base. Desde aqui entras a las guias hijas de Mail.',
                'url' => '/docs/mail-sections',
                'category' => 'Guia padre',
                'icon' => 'bi-envelope-fill',
                'keywords' => 'mail padre hijos secciones mapa relay infra entregabilidad webmail',
            ],
            [
                'title' => 'Bugs: incidencias y diagnostico',
                'description' => 'Articulos tecnicos de bugs reales: sintomas, causa raiz, diagnostico y correccion.',
                'url' => '/docs/bugs-sections',
                'category' => 'Guia padre',
                'icon' => 'bi-bug-fill',
                'keywords' => 'bugs incidencias diagnostico troubleshooting errores ssl caddy update instalador',
            ],
        ]);
    }

    private function defaultSpecialShortcutSlugs(array $guides): array
    {
        $slugs = [];
        foreach ($guides as $slug => $guide) {
            if (!empty($guide['special_shortcut'])) {
                $slugs[] = (string)$slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    private function configuredSpecialShortcutSlugs(): ?array
    {
        $raw = trim((string)Settings::get(self::SHORTCUTS_SETTING_KEY, ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $slugs = [];
        foreach ($decoded as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $slug = trim((string)$value);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    private function specialShortcutSlugs(array $guides): array
    {
        $configured = $this->configuredSpecialShortcutSlugs();
        $validGuideSlugs = array_keys($guides);
        $validMap = array_fill_keys($validGuideSlugs, true);

        if ($configured === null) {
            $configured = $this->defaultSpecialShortcutSlugs($guides);
        }

        $filtered = [];
        foreach ($configured as $slug) {
            if (isset($validMap[$slug])) {
                $filtered[] = $slug;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function saveSpecialShortcutSlugs(array $slugs): void
    {
        $payload = json_encode(array_values(array_unique($slugs)), JSON_UNESCAPED_SLASHES);
        Settings::set(self::SHORTCUTS_SETTING_KEY, is_string($payload) ? $payload : '[]');
    }

    private function specialShortcutTopics(array $childTopics): array
    {
        return array_values(array_filter($childTopics, static function (array $topic): bool {
            return !empty($topic['special_shortcut']);
        }));
    }

    private function specialTopics(): array
    {
        return $this->dedupeTopics([
            [
                'title' => 'TLS del panel: DNS-01, proxy naranja y puertos cerrados',
                'description' => 'Como publicar el panel en un dominio/subdominio con certificado publico usando HTTP-01 o DNS-01, incluso con 80/443 cerrados o proxy DNS.',
                'url' => '/docs/panel-tls-dns01',
                'category' => 'Guia especial',
                'icon' => 'bi-shield-lock-fill',
                'keywords' => 'panel tls dns-01 http-01 tls-alpn lets encrypt certificado dominio subdominio proxy naranja cloudflare caddy dns providers xcaddy firewall 80 443 cerrado acme',
            ],
            [
                'title' => 'Seguridad operativa: hardening, drift, exposicion, lockdown y MFA',
                'description' => 'Guia integral de seguridad del panel: que dispara FIREWALL_CHANGED, como validar cambios reales y donde gestionar cada control.',
                'url' => '/docs/security-operations',
                'category' => 'Guia especial',
                'icon' => 'bi-shield-lock',
                'keywords' => 'security hardening drift firewall changed fingerprint hash lockdown mfa login anomaly fail2ban exposicion puertos collector',
            ],
            [
                'title' => 'Firewall completo: snapshots, export/import y verificacion',
                'description' => 'Guia operativa del firewall del panel con snapshots completos, backup JSON e importacion segura por nodos.',
                'url' => '/docs/firewall-operations',
                'category' => 'Guia especial',
                'icon' => 'bi-shield-fill-check',
                'keywords' => 'firewall snapshots export import json restore iptables ufw reglas presets backup recovery',
            ],
            [
                'title' => 'Sync de archivos (lsyncd)',
                'description' => 'Diagnostico rapido de Sync degradado, cola lsyncd, SSH entre nodos y autocorreccion desde Cluster > Archivos.',
                'url' => '/docs/sync-archivos-lsyncd',
                'category' => 'Guia especial',
                'icon' => 'bi-files',
                'keywords' => 'filesync lsyncd sync degradado cluster archivos ssh queue rsync autocorregir',
            ],
            [
                'title' => 'Modos de correo',
                'description' => 'Como elegir entre Satellite, Relay Privado, Correo Completo y SMTP Externo.',
                'url' => '/docs/mail-modes',
                'category' => 'Guia especial',
                'icon' => 'bi-envelope-fill',
                'keywords' => 'mail correo smtp satellite relay wireguard postfix dkim roundcube sieve externo',
            ],
            [
                'title' => 'Replica espejo PostgreSQL (Master/Slave)',
                'description' => 'Guia especial paso a paso para montar espejo, riesgos, IPs recomendadas y ficheros root.',
                'url' => '/docs/postgresql-mirror-master-slave',
                'category' => 'Guia especial',
                'icon' => 'bi-database',
                'keywords' => 'postgresql master slave replication espejo streaming wal hot standby pg_hba postgresql.conf root ip privada wireguard panel 5433 no replica',
            ],
            [
                'title' => 'Instalacion y recuperacion',
                'description' => 'Primera instalacion desde GitHub, opciones del instalador, actualizacion por shell y recuperacion de PostgreSQL/.env.',
                'url' => '/docs/install-recovery',
                'category' => 'Guia especial',
                'icon' => 'bi-tools',
                'keywords' => 'instalacion instalar github git clone update actualizar shell recuperacion rotura postgres postgresql runuser env db_pass db_user musedock_panel setup admin firewall fail2ban',
            ],
            [
                'title' => 'Backups por defecto',
                'description' => 'Que guarda el sistema automaticamente: BD del panel, Caddy, snapshots de instalacion, retenciones y limites.',
                'url' => '/docs/default-backups',
                'category' => 'Guia especial',
                'icon' => 'bi-shield-check',
                'keywords' => 'backups defecto backup panel base datos caddy caddyfile last-known-good retencion 48 horas 15 dias install-backup snapshot restore restaurar vhosts correo bases clientes',
            ],
            [
                'title' => 'Perfil de usuario: MFA (Authenticator/TOTP)',
                'description' => 'Como activar MFA paso a paso, apps compatibles (movil/PC), buenas practicas y recuperacion si se pierde el dispositivo.',
                'url' => '/docs/profile-mfa',
                'category' => 'Guia especial',
                'icon' => 'bi-phone-vibrate',
                'keywords' => 'profile perfil mfa authenticator totp qr secret otpauth 2fa recovery recuperar perdida movil backup root sql panel_admins security_mfa_required',
            ],
        ]);
    }

    private function flattenSearchValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = $this->flattenSearchValue($item);
            }
            return trim(implode(' ', array_filter($parts, static fn(string $part): bool => $part !== '')));
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return '';
    }

    private function extractViewText(string $viewPath): string
    {
        if (!is_file($viewPath)) {
            return '';
        }

        $raw = (string)file_get_contents($viewPath);
        if ($raw === '') {
            return '';
        }

        $withoutPhp = preg_replace('/<\\?(?:php|=)?[\\s\\S]*?\\?>/i', ' ', $raw) ?? $raw;
        $plain = html_entity_decode(strip_tags($withoutPhp), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\\s+/u', ' ', $plain) ?? $plain;

        return trim($plain);
    }

    private function topicContentByUrl(string $url, array $guides): string
    {
        if ($url === '/docs/settings-sections') {
            return $this->flattenSearchValue($guides);
        }

        if (str_starts_with($url, '/docs/settings/')) {
            $slug = trim(substr($url, strlen('/docs/settings/')));
            if ($slug !== '' && isset($guides[$slug])) {
                return $this->flattenSearchValue($guides[$slug]);
            }
        }

        if ($url === '/docs/mail-modes') {
            return $this->extractViewText(self::DOCS_VIEW_BASE . 'mail-modes.php');
        }

        if ($url === '/docs/install-recovery') {
            return $this->extractViewText(self::DOCS_VIEW_BASE . 'install-recovery.php');
        }

        if ($url === '/docs/default-backups') {
            return $this->extractViewText(self::DOCS_VIEW_BASE . 'default-backups.php');
        }

        if ($url === '/docs/mail-sections') {
            return $this->extractViewText(self::DOCS_VIEW_BASE . 'mail-sections.php');
        }

        if ($url === '/docs/bugs-sections') {
            return $this->extractViewText(self::DOCS_VIEW_BASE . 'bugs-sections.php');
        }

        if (str_starts_with($url, '/docs/mail/')) {
            $slug = trim(substr($url, strlen('/docs/mail/')));
            if ($slug !== '' && !str_contains($slug, '/')) {
                $mailGuides = $this->mailGuides();
                $view = (string)($mailGuides[$slug]['view'] ?? '');
                if ($view !== '') {
                    return $this->extractViewText(self::DOCS_VIEW_BASE . $view . '.php');
                }
            }
        }

        if (str_starts_with($url, '/docs/bugs/')) {
            $slug = trim(substr($url, strlen('/docs/bugs/')));
            if ($slug !== '' && !str_contains($slug, '/')) {
                $bugGuides = $this->bugGuides();
                $view = (string)($bugGuides[$slug]['view'] ?? '');
                if ($view !== '') {
                    return $this->extractViewText(self::DOCS_VIEW_BASE . $view . '.php');
                }
            }
        }

        if (!str_starts_with($url, '/docs/')) {
            return '';
        }

        $slug = trim(substr($url, strlen('/docs/')), '/');
        if ($slug === '' || str_contains($slug, '/')) {
            return '';
        }

        return $this->extractViewText(self::DOCS_VIEW_BASE . $slug . '.php');
    }

    private function buildSearchExcerpt(string $text, string $query): string
    {
        $query = trim($query);
        if ($query === '' || $text === '') {
            return '';
        }

        $normalized = preg_replace('/\\s+/u', ' ', $text) ?? $text;
        $normalized = trim($normalized);
        if ($normalized === '') {
            return '';
        }

        $matchPos = mb_stripos($normalized, $query);
        if ($matchPos === false) {
            return '';
        }

        $contextBefore = 90;
        $contextLength = 220;
        $start = max(0, $matchPos - $contextBefore);
        $excerpt = mb_substr($normalized, $start, $contextLength);
        $needsPrefix = $start > 0;
        $needsSuffix = ($start + mb_strlen($excerpt)) < mb_strlen($normalized);

        return ($needsPrefix ? '... ' : '') . trim($excerpt) . ($needsSuffix ? ' ...' : '');
    }

    private function settingGuides(): array
    {
        return [
            'server' => [
                'title' => 'Servidor',
                'panel_url' => '/settings/server',
                'summary' => 'Identidad del nodo, hostname, TLS del panel y parametros globales del host.',
                'what_is' => 'Centraliza ajustes base del nodo donde corre el panel: identidad, timezone, URL/TLS del panel y estado operativo general.',
                'quick_steps' => [
                    'Verificar hostname, timezone y URL de acceso real del panel.',
                    'Ajustar TLS del panel (self-signed/http01/dns01) segun tu modelo de red.',
                    'Si usas DNS-01, revisar /settings/dns y la guia especial /docs/panel-tls-dns01 antes de guardar.',
                    'Guardar cambios y comprobar acceso HTTPS por dominio e IP fallback.',
                    'Gestionar avisos de reinicio/paradas desde Settings > Notifications.',
                ],
                'checklist' => [
                    'Hostname correcto y coherente con DNS/reverse.',
                    'URL del panel accesible por ruta principal y fallback.',
                    'Cambios aplicados sin errores en logs.',
                    'Sin impacto en servicios dependientes (Caddy/PHP/cron).',
                ],
                'pitfalls' => [
                    'Cambiar identidad sin revisar DNS puede romper validaciones externas.',
                    'Usar HTTP-01 con firewall cerrado impide emision/renovacion de certificados.',
                    'Elegir DNS-01 sin instalar el modulo Caddy del proveedor en Settings > DNS impide crear la policy ACME.',
                    'Pensar que los avisos de reinicio se gestionan aqui (ahora van en Notificaciones).',
                ],
            ],
            'php' => [
                'title' => 'PHP',
                'panel_url' => '/settings/php',
                'summary' => 'Versiones PHP, limites y runtime para hostings.',
                'what_is' => 'Gestiona versiones y parametros de PHP-FPM para compatibilidad, rendimiento y seguridad de las aplicaciones.',
                'quick_steps' => [
                    'Confirmar versiones instaladas y version por defecto.',
                    'Ajustar limites (memoria, upload, timeout) segun carga real.',
                    'Probar una cuenta de referencia tras cambios.',
                ],
                'checklist' => [
                    'Version compatible con apps criticas.',
                    'FPM responde sin errores.',
                    'Sin warnings recurrentes en logs PHP.',
                ],
                'pitfalls' => [
                    'Subir version mayor sin test previo puede romper codigo legacy.',
                    'Limites demasiado bajos degradan jobs o importaciones.',
                ],
            ],
            'ssl-tls' => [
                'title' => 'SSL/TLS',
                'panel_url' => '/settings/ssl',
                'summary' => 'Certificados, HTTPS y estado de cifrado de dominios.',
                'what_is' => 'Controla el estado de certificados y politicas TLS para que dominios sirvan HTTPS valido.',
                'quick_steps' => [
                    'Revisar estado de certificados activos y caducidad.',
                    'Regenerar/reemitir cuando haya fallos de validacion.',
                    'Confirmar acceso HTTPS real desde navegador o curl.',
                ],
                'checklist' => [
                    'Certificados vigentes.',
                    'Sin errores de handshake.',
                    'Redireccion HTTP->HTTPS operativa si aplica.',
                ],
                'pitfalls' => [
                    'DNS incorrecto bloquea renovaciones automaticas.',
                    'Cambios TLS agresivos pueden excluir clientes antiguos.',
                ],
            ],
            'security' => [
                'title' => 'Seguridad',
                'panel_url' => '/settings/security',
                'summary' => 'Hardening del host, MFA admin y controles de acceso del panel.',
                'what_is' => 'Agrupa baseline de hardening del host, politica MFA de administradores y restricciones de acceso del panel.',
                'quick_steps' => [
                    'Revisar auditoria de hardening (sshd/fail2ban/sysctl/permisos SSH) y su score.',
                    'Aplicar fix 1 clic cuando haya controles fuera de baseline.',
                    'Definir puertos publicos esperados para alerta de exposicion.',
                    'Activar MFA obligatoria solo cuando todos los admins esten enrolados.',
                ],
                'checklist' => [
                    'Acceso admin protegido con MFA cuando corresponda.',
                    'Hardening base del host en estado OK.',
                    'Puertos esperados definidos para deteccion de deriva operativa.',
                    'Sin bloqueos accidentales de operacion legitima.',
                    'Eventos sensibles auditables en logs.',
                ],
                'pitfalls' => [
                    'Activar MFA global sin enrolar a todos los admins bloquea operaciones.',
                    'Aplicar hardening sin validar acceso SSH por clave puede cortar acceso remoto.',
                    'Cambiar varias politicas a la vez dificulta aislar incidencias.',
                ],
            ],
            'fail2ban' => [
                'title' => 'Fail2Ban',
                'panel_url' => '/settings/fail2ban',
                'summary' => 'Bloqueo automatico de IPs por abuso o intentos fallidos.',
                'what_is' => 'Orquesta jails y umbrales para mitigar fuerza bruta y trafico hostil.',
                'quick_steps' => [
                    'Verificar jails activos y servicios protegidos.',
                    'Exportar configuracion JSON antes de cambios grandes.',
                    'Ajustar bantime/findtime/maxretry segun perfil de trafico.',
                    'Comprobar bans recientes y falsos positivos.',
                    'Importar JSON en append para migraciones; usar replace solo con validacion previa.',
                ],
                'checklist' => [
                    'Jails criticos habilitados.',
                    'Sin falso positivo masivo.',
                    'Integracion correcta con firewall.',
                    'Whitelist y archivos musedock exportados para rollback.',
                ],
                'pitfalls' => [
                    'Umbrales bajos pueden banear usuarios legitimos.',
                    'No excluir IPs operativas puede cortar mantenimiento.',
                    'Importar en replace sin backup puede sobrescribir ajustes locales necesarios.',
                ],
            ],
            'cron' => [
                'title' => 'Cron',
                'panel_url' => '/settings/crons',
                'summary' => 'Tareas programadas de sistema y workers.',
                'what_is' => 'Permite revisar y mantener jobs periodicos que sostienen sincronizacion, salud y mantenimiento.',
                'quick_steps' => [
                    'Comprobar que cron del sistema esta activo.',
                    'Verificar entradas requeridas del panel.',
                    'Exportar JSON de cron antes de reordenar frecuencias o limpiar tareas.',
                    'Reparar jobs faltantes y revisar logs de ejecucion.',
                    'Importar en append primero; replace solo cuando quieras clonar estado exacto.',
                ],
                'checklist' => [
                    'Crons requeridos presentes.',
                    'Sin errores repetitivos por permisos o rutas.',
                    'Frecuencias coherentes con carga del servidor.',
                    'Copia JSON disponible para rollback rapido.',
                ],
                'pitfalls' => [
                    'Desactivar un cron critico rompe tareas silenciosamente.',
                    'Ejecuciones superpuestas pueden saturar CPU/IO.',
                    'Replace en import elimina tareas no incluidas en el archivo.',
                ],
            ],
            'caddy' => [
                'title' => 'Caddy',
                'panel_url' => '/settings/caddy',
                'summary' => 'Estado del web server/reverse proxy Caddy.',
                'what_is' => 'Gestiona estado operativo y configuracion efectiva de Caddy para rutas web, TLS y proxy.',
                'quick_steps' => [
                    'Comprobar servicio y estado de configuracion.',
                    'Exportar JSON completo antes de cambios de rutas/TLS.',
                    'Aplicar cambios y validar reload correcto.',
                    'Probar dominios representativos tras ajustes.',
                ],
                'checklist' => [
                    'Caddy activo y estable.',
                    'Sin errores de parseo en config.',
                    'Rutas y certificados funcionando.',
                    'Ultimo export JSON disponible para recovery inmediato.',
                ],
                'pitfalls' => [
                    'Cambios no validados pueden dejar sitios inaccesibles.',
                    'No revisar logs de Caddy oculta errores de routing.',
                    'Importar config completa sin validar origen puede romper todas las rutas.',
                ],
                'advanced_steps' => [
                    'Exportar o guardar una copia de la configuracion actual antes de tocar rutas sensibles.',
                    'Aplicar cambios por lotes pequenos (dominio por dominio o bloque por bloque).',
                    'Validar parseo y recarga limpia antes de dar por aplicado.',
                    'Comprobar certificados y rutas proxy en dominios criticos.',
                    'Monitorizar logs durante unos minutos tras el cambio.',
                ],
                'verify_commands' => [
                    'sudo systemctl status caddy --no-pager',
                    'sudo journalctl -u caddy -n 100 --no-pager',
                    'curl -kI https://127.0.0.1',
                    'sudo caddy validate --config /etc/caddy/Caddyfile',
                ],
                'rollback_steps' => [
                    'Restaurar la ultima configuracion Caddy funcional.',
                    'Recargar servicio y confirmar estado activo.',
                    'Verificar respuesta HTTPS en dominios criticos.',
                    'Reaplicar cambios de forma incremental para aislar el fallo.',
                ],
            ],
            'logs' => [
                'title' => 'Logs',
                'panel_url' => '/settings/logs',
                'summary' => 'Visor de logs para diagnostico rapido.',
                'what_is' => 'Punto central para inspeccionar eventos y errores de servicios gestionados por el panel.',
                'quick_steps' => [
                    'Filtrar por servicio y ventana temporal.',
                    'Detectar errores repetitivos o picos de warnings.',
                    'Corregir causa raiz antes de limpiar.',
                ],
                'checklist' => [
                    'Se identifica causa tecnica concreta.',
                    'Se valida solucion con nuevos logs limpios.',
                    'Se documentan patrones recurrentes.',
                ],
                'pitfalls' => [
                    'Limpiar logs sin analizar elimina contexto de incidentes.',
                    'Reaccionar a warnings aislados puede generar sobrecambios.',
                ],
            ],
            'replication' => [
                'title' => 'Replicacion',
                'panel_url' => '/settings/replication',
                'summary' => 'Estado y configuracion de replicacion de bases de datos.',
                'what_is' => 'Administra topologia y salud de replicacion para PostgreSQL/MySQL segun modo configurado.',
                'quick_steps' => [
                    'Confirmar rol local (master/slave) por motor.',
                    'Verificar lag y estado de streaming.',
                    'Corregir credenciales, red o slots antes de reprobar.',
                ],
                'checklist' => [
                    'Replica conectada y al dia.',
                    'Sin errores persistentes de autenticacion.',
                    'Failover/restore planificado y probado.',
                ],
                'pitfalls' => [
                    'Mezclar modos manuales y auto sin criterio causa desalineacion.',
                    'Ignorar lag alto puede provocar failover con datos atrasados.',
                ],
                'advanced_steps' => [
                    'Validar prerequisitos de red, credenciales y puertos antes de activar replicacion.',
                    'Configurar replicacion motor por motor (PostgreSQL y MySQL) con pruebas separadas.',
                    'Medir lag en carga real y ajustar parametros antes de pasar a productivo.',
                    'Probar escenario de corte y recuperacion para confirmar procedimiento de failover.',
                    'Documentar rol y estado final en cada nodo para operacion diaria.',
                ],
                'verify_commands' => [
                    'sudo systemctl status postgresql --no-pager',
                    'sudo systemctl status mysql --no-pager',
                    'psql -c \"select now();\"',
                    'mysql -e \"select now();\"',
                ],
                'rollback_steps' => [
                    'Pausar cambios y dejar un solo master estable si hay divergencia.',
                    'Detener replica defectuosa y resembrar desde backup consistente.',
                    'Restaurar configuracion previa de replicacion validada.',
                    'Reactivar replicacion tras verificar consistencia de datos.',
                ],
            ],
            'firewall' => [
                'title' => 'Firewall',
                'panel_url' => '/settings/firewall',
                'summary' => 'Reglas de red, auditoria, snapshots, import/export y lockdown temporal.',
                'what_is' => 'Controla la superficie expuesta del servidor, permite correcciones rapidas y mantiene backups operativos del estado real del firewall.',
                'quick_steps' => [
                    'Guardar snapshot completo antes de tocar reglas.',
                    'Aplicar cambios minimos y validar acceso SSH/panel tras cada bloque.',
                    'Revisar la Auditoria de Seguridad y ejecutar fix directo cuando aplique.',
                    'Usar lockdown temporal de emergencia para cortar ataque activo y dejar auto-expiracion.',
                    'Exportar JSON al cerrar cambios para poder clonar estado en otros nodos.',
                ],
                'checklist' => [
                    'Puertos criticos abiertos solo donde toca.',
                    'Sin reglas globales tipo ACCEPT all sin condicion.',
                    'Panel y SSH accesibles tras cambios.',
                    'Servicios internos no expuestos innecesariamente.',
                    'IPv6 protegida (reglas o bloqueo por defecto) si esta activa.',
                ],
                'pitfalls' => [
                    'Cerrar SSH/panel sin regla de rescate puede dejar servidor inaccesible.',
                    'Importar en modo replace sin snapshot previo complica recuperacion.',
                    'Olvidar IPv6 deja una via abierta aunque IPv4 este bien cerrada.',
                ],
                'advanced_steps' => [
                    'Tomar baseline: reglas actuales, politica por defecto y puertos escuchando realmente.',
                    'Crear/confirmar camino de rescate para SSH y panel antes de cambios destructivos.',
                    'Aplicar cambios por capas (entrada, servicios, endurecimiento) con verificacion en cada paso.',
                    'Si hay incidente, activar lockdown temporal 10-15 min y resolver antes de su expiracion.',
                    'Guardar snapshot completo al inicio y otro al final para rollback rapido.',
                    'Mantener vigilancia de cambios externos en Settings > Notifications para detectar cambios por shell/manual.',
                    'Si gestionas multiples nodos, exportar JSON e importar en append primero; replace solo tras validacion.',
                ],
                'verify_commands' => [
                    'iptables -L -n --line-numbers',
                    'iptables -S | grep "^-P"',
                    'iptables -S | grep -E "ACCEPT.*0.0.0.0/0.*0.0.0.0/0"',
                    'iptables -L INPUT -n | grep dpt:22',
                    'ip6tables -S',
                    'ss -tuln',
                    'fail2ban-client status',
                    'curl -kI https://127.0.0.1:8444',
                ],
                'rollback_steps' => [
                    'Aplicar el ultimo snapshot completo valido desde la seccion de snapshots.',
                    'Usar el boton de emergencia para abrir acceso de gestion desde tu IP si quedaste bloqueado.',
                    'Reabrir temporalmente puertos de gestion y volver a baseline seguro.',
                    'Exportar estado recuperado y documentar la regla o import conflictivo antes de reintentar.',
                ],
            ],
            'wireguard' => [
                'title' => 'WireGuard',
                'panel_url' => '/settings/wireguard',
                'summary' => 'Tuneles privados entre nodos.',
                'what_is' => 'Configura red privada cifrada entre servidores para trafico de gestion, cluster y federation.',
                'quick_steps' => [
                    'Generar/confirmar claves y peers.',
                    'Validar handshakes y rutas entre nodos.',
                    'Usar IP privada WG en servicios inter-nodo.',
                ],
                'checklist' => [
                    'Peers conectados y con handshake reciente.',
                    'Ping entre IPs privadas operativo.',
                    'Servicios internos usando red privada.',
                ],
                'pitfalls' => [
                    'AllowedIPs mal definido rompe routing.',
                    'MTU inadecuada puede degradar transferencia.',
                ],
                'advanced_steps' => [
                    'Definir topologia (hub/spoke o mesh) y rangos privados sin solapamientos.',
                    'Crear peers con claves nuevas y AllowedIPs minimos por nodo.',
                    'Levantar tunel en una ventana controlada y validar handshake bidireccional.',
                    'Probar conectividad IP privada entre paneles y luego mover trafico inter-nodo a WG.',
                    'Actualizar Cluster/Federation para usar IP privada del tunel en API URL/SSH host.',
                ],
                'verify_commands' => [
                    'sudo wg show',
                    'ip a show wg0',
                    'ip route',
                    'ping -c 3 10.10.70.1',
                ],
                'rollback_steps' => [
                    'Desactivar peer o interfaz WG recien aplicada si corta conectividad.',
                    'Restaurar configuracion WG anterior desde backup local.',
                    'Volver temporalmente a IP publica en Cluster/Federation hasta estabilizar tunel.',
                    'Reaplicar cambios de forma incremental peer por peer.',
                ],
            ],
            'notifications' => [
                'title' => 'Notificaciones',
                'panel_url' => '/settings/notifications',
                'summary' => 'Canales de envio y eventos de seguridad/operacion con anti-spam.',
                'what_is' => 'Define como y a donde se notifican alertas del panel (SMTP/PHP mail/Telegram) y activa eventos de seguridad: firewall externo, reboot, gap, hardening, config drift, exposicion y login anomalo.',
                'quick_steps' => [
                    'Configurar canal de envio (SMTP o PHP mail) y destinatario efectivo.',
                    'Configurar Telegram si quieres segundo canal de respaldo.',
                    'Activar eventos de sistema: FIREWALL_CHANGED, SERVER_REBOOT y MONITOR_GAP.',
                    'Activar eventos de seguridad: SECURITY_HARDENING, CONFIG_DRIFT, PORT_EXPOSURE y LOGIN_ANOMALY.',
                    'Ejecutar pruebas de envio y validar recepcion real.',
                ],
                'checklist' => [
                    'Al menos un canal de envio operativo y probado.',
                    'Destinatarios actualizados (email/telegram).',
                    'Eventos del sistema activados segun politica del nodo.',
                    'Eventos de seguridad activados con cooldown apropiado (sin ruido excesivo).',
                    'Umbral de MONITOR_GAP acorde al cron real (sin ruido innecesario).',
                ],
                'pitfalls' => [
                    'Dejar eventos activos sin canal configurado genera falsa sensacion de cobertura.',
                    'Umbral de gap demasiado bajo dispara alertas ruidosas.',
                    'Monitor collector inactivo impide detectar eventos de firewall/reboot/gap.',
                ],
                'advanced_steps' => [
                    'Si usas PHP mail(), valida que exista sendmail/postfix; si no, usa SMTP.',
                    'Definir destinatario explicito para separar alertas tecnicas del email de perfil.',
                    'Usar Telegram como canal secundario para incidentes de correo.',
                    'Verificar que el cron de monitor collector esta activo en el nodo.',
                    'Tras guardar cambios, forzar una ejecucion del collector para inicializar estados de vigilancia.',
                ],
                'verify_commands' => [
                    'cd /opt/musedock-panel && php bin/monitor-collector.php',
                    'ls -l /opt/musedock-panel/storage/cache/*watch-state.json',
                    'ls -l /opt/musedock-panel/storage/cache/security-hardening-watch-state.json /opt/musedock-panel/storage/cache/config-drift-watch-state.json /opt/musedock-panel/storage/cache/public-exposure-watch-state.json',
                    'tail -n 60 /opt/musedock-panel/storage/logs/monitor-collector.log',
                    'cat /etc/cron.d/musedock-monitor',
                    'systemctl status cron --no-pager',
                ],
                'rollback_steps' => [
                    'Desactivar temporalmente el evento ruidoso mientras ajustas umbral/canal.',
                    'Volver a la ultima configuracion SMTP/Telegram conocida que enviaba correctamente.',
                    'Reactivar eventos de forma gradual y validar cada canal con prueba real.',
                    'Si el collector falla, reparar cron/ejecucion antes de volver a activar avisos.',
                ],
            ],
            'proxy-routes' => [
                'title' => 'Proxy Routes',
                'panel_url' => '/settings/proxy-routes',
                'summary' => 'Rutas proxy para exponer servicios internos por dominio.',
                'what_is' => 'Publica servicios internos detras de dominios manejados por el panel mediante reglas proxy.',
                'quick_steps' => [
                    'Definir dominio origen y destino interno.',
                    'Guardar ruta y verificar resolucion/puerto.',
                    'Probar respuesta HTTP/HTTPS extremo a extremo.',
                ],
                'checklist' => [
                    'Destino interno accesible desde el host del panel.',
                    'TLS correcto en el dominio publicado.',
                    'Sin loops de proxy o redireccion.',
                ],
                'pitfalls' => [
                    'Apuntar a destino inexistente genera 502/504.',
                    'Conflictos de hostnames con otras rutas provocan comportamiento ambiguo.',
                ],
            ],
            'dns' => [
                'title' => 'DNS',
                'panel_url' => '/settings/dns',
                'summary' => 'Proveedor DNS-01 del panel, modulos Caddy y credenciales API para certificados sin abrir 80/443.',
                'what_is' => 'Centraliza el proveedor DNS que Caddy usara para validar certificados del dominio del panel mediante DNS-01. Es independiente del gestor completo de registros Cloudflare.',
                'quick_steps' => [
                    'Elegir proveedor DNS del catalogo.',
                    'Instalar el modulo Caddy si aparece como no instalado.',
                    'Pegar el JSON de credenciales API del proveedor.',
                    'Marcar activar DNS-01 si quieres que el panel use ese metodo al guardar.',
                    'Verificar despues el certificado del dominio del panel.',
                ],
                'checklist' => [
                    'El dominio del panel esta configurado en Settings > Servidor.',
                    'El email ACME es valido.',
                    'Caddy lista dns.providers.<proveedor>.',
                    'Las credenciales API tienen permisos DNS suficientes para crear TXT _acme-challenge.',
                ],
                'pitfalls' => [
                    'DNS-01 valida por DNS publico, no por el puerto 8444.',
                    'Un proxy/CDN delante no sustituye al token API del proveedor DNS real.',
                    'Compilar un modulo Caddy reinicia Caddy; el panel crea backup y rollback automatico.',
                ],
            ],
            'cloudflare-dns' => [
                'title' => 'Cloudflare DNS',
                'panel_url' => '/settings/cloudflare-dns',
                'summary' => 'Gestion DNS por API para zonas Cloudflare.',
                'what_is' => 'Permite administrar registros DNS por API. El token debe crearse en la cuenta de Cloudflare que posee o administra las zonas que quieres gestionar.',
                'quick_steps' => [
                    'Crear token en Cloudflare: My Profile > API Tokens > Create Token > Create Custom Token.',
                    'Permisos minimos recomendados: Zone:DNS Edit y Zone:Zone Read.',
                    'Scope del token: limitar a zonas concretas (recomendado) o All zones si hace falta.',
                    'Pegar token en Settings > Cloudflare DNS del panel y guardar.',
                    'Verificar zonas visibles y seleccion correcta.',
                    'Aplicar cambios DNS y confirmar propagacion.',
                ],
                'checklist' => [
                    'Token valido y no expirado.',
                    'Token creado en la cuenta correcta de Cloudflare.',
                    'Registros sincronizados con estado real.',
                    'Sin cambios destructivos en zonas equivocadas.',
                ],
                'pitfalls' => [
                    'Permisos insuficientes bloquean operaciones silenciosamente.',
                    'Token creado en otra cuenta Cloudflare no vera tus zonas.',
                    'Editar zona incorrecta produce caidas de resolucion.',
                ],
                'advanced_steps' => [
                    'Usar un token dedicado al panel, no el Global API Key.',
                    'Separar tokens por entorno (produccion/staging) para reducir impacto.',
                    'Restringir por zonas y rotar token periodicamente.',
                    'Comprobar que las zonas esperadas aparecen en el selector del panel.',
                    'Probar cambio controlado en un registro no critico antes de operar en produccion.',
                ],
                'verify_commands' => [
                    'curl -s -H \"Authorization: Bearer CF_TOKEN\" -H \"Content-Type: application/json\" https://api.cloudflare.com/client/v4/zones',
                    'curl -s -H \"Authorization: Bearer CF_TOKEN\" -H \"Content-Type: application/json\" \"https://api.cloudflare.com/client/v4/zones/ZONE_ID/dns_records?type=A&name=example.com\"',
                ],
                'rollback_steps' => [
                    'Revertir registros DNS afectados al ultimo valor conocido bueno.',
                    'Desactivar o rotar inmediatamente token comprometido o mal configurado.',
                    'Volver temporalmente a cambios manuales en Cloudflare hasta estabilizar integracion.',
                    'Recrear token con permisos/scope correctos y revalidar zonas en el panel.',
                ],
            ],
            'system-health' => [
                'title' => 'System Health',
                'panel_url' => '/settings/health',
                'summary' => 'Metricas y checks de salud del sistema.',
                'what_is' => 'Panel de observabilidad basica para detectar degradacion de recursos y servicios requeridos.',
                'quick_steps' => [
                    'Revisar estado global y checks en warning/error.',
                    'Corregir primero fallos de servicios criticos.',
                    'Volver a ejecutar checks para confirmar recuperacion.',
                ],
                'checklist' => [
                    'Checks obligatorios en verde.',
                    'Sin degradacion sostenida de disco/RAM/CPU.',
                    'Crons requeridos reportando actividad.',
                ],
                'pitfalls' => [
                    'Ignorar warnings prolongados suele terminar en incidente.',
                    'Corregir sintomas sin causa raiz repite fallos.',
                ],
            ],
            'updates' => [
                'title' => 'Updates',
                'panel_url' => '/settings/updates',
                'summary' => 'Actualizacion del panel y componentes asociados.',
                'what_is' => 'Gestiona ciclo de actualizacion para mantener seguridad, fixes y nuevas funciones.',
                'quick_steps' => [
                    'Revisar version actual vs disponible.',
                    'Tomar backup/snapshot antes de actualizar.',
                    'Actualizar desde web o shell y validar rutas criticas del panel.',
                ],
                'checklist' => [
                    'Version esperada aplicada.',
                    'Sin regresiones funcionales clave.',
                    'Rollback plan definido antes de cambios mayores.',
                ],
                'pitfalls' => [
                    'Actualizar sin ventana controlada complica rollback.',
                    'Omitir validacion post-update deja errores ocultos.',
                ],
                'advanced_steps' => [
                    'Revisar changelog y dependencias que puedan afectar produccion.',
                    'Tomar snapshot/backup antes de actualizar y confirmar punto de retorno.',
                    'Ejecutar update en ventana controlada y monitorizar servicios durante el proceso.',
                    'Verificar rutas criticas: login panel, hostings, mail, cluster/federation y backups.',
                    'Confirmar crons requeridos y health checks en verde al finalizar.',
                ],
                'verify_commands' => [
                    'cd /opt/musedock-panel && git pull --ff-only origin main',
                    'bash /opt/musedock-panel/bin/update.sh --auto',
                    'cd /opt/musedock-panel && git rev-parse --short HEAD',
                    'sudo systemctl status caddy --no-pager',
                    'sudo systemctl status php8.2-fpm --no-pager',
                    'php -v',
                ],
                'rollback_steps' => [
                    'Detener cambios operativos y volver a snapshot/backup previo si hay regresion critica.',
                    'Restaurar version anterior del panel y reiniciar servicios afectados.',
                    'Validar endpoints criticos antes de reabrir operacion normal.',
                    'Analizar causa raiz de la regresion antes del siguiente intento.',
                ],
            ],
            'services' => [
                'title' => 'Servicios',
                'panel_url' => '/settings/services',
                'summary' => 'Control de estado y arranque de servicios del sistema.',
                'what_is' => 'Permite arrancar, parar o reiniciar servicios operativos gestionados desde el panel.',
                'quick_steps' => [
                    'Identificar servicio afectado y su dependencia.',
                    'Aplicar accion (restart/start/stop) con criterio.',
                    'Confirmar estado final y estabilidad en logs.',
                ],
                'checklist' => [
                    'Servicios criticos en running.',
                    'Reinicios sin bucles ni caidas recurrentes.',
                    'Dependencias aguas abajo estables.',
                ],
                'pitfalls' => [
                    'Reinicios en cadena sin diagnostico pueden agravar incidente.',
                    'Parar servicios base impacta multiples modulos del panel.',
                ],
                'advanced_steps' => [
                    'Identificar dependencia entre servicios antes de reiniciar (web, db, mail, workers).',
                    'Aplicar reinicios en orden controlado y uno por uno cuando haya incidente.',
                    'Tras cada accion, revisar logs y estado para confirmar recuperacion real.',
                    'Evitar restart masivo sin diagnostico en horas de carga.',
                    'Cerrar incidencia con causa raiz y accion preventiva documentada.',
                ],
                'verify_commands' => [
                    'sudo systemctl --type=service --state=running',
                    'sudo systemctl status caddy --no-pager',
                    'sudo systemctl status postgresql --no-pager',
                    'sudo systemctl status mysql --no-pager',
                ],
                'rollback_steps' => [
                    'Revertir el ultimo restart/stop sobre el servicio afectado.',
                    'Levantar servicios base en orden: base de datos, runtime, web/proxy.',
                    'Confirmar disponibilidad funcional antes de continuar con mas cambios.',
                    'Escalar a mantenimiento planificado si la inestabilidad persiste.',
                ],
            ],
            'portal-clientes' => [
                'title' => 'Portal Clientes',
                'panel_url' => '/settings/portal',
                'summary' => 'Configuracion del portal orientado a clientes finales.',
                'what_is' => 'Define el comportamiento funcional y visual del area de clientes conectada al panel.',
                'quick_steps' => [
                    'Revisar parametros de acceso y experiencia del cliente.',
                    'Ajustar branding/flujo segun politica operativa.',
                    'Validar login y acciones basicas con cuenta de prueba.',
                ],
                'checklist' => [
                    'Acceso cliente operativo.',
                    'Permisos y limites correctos.',
                    'Mensajeria y enlaces coherentes con entorno.',
                ],
                'pitfalls' => [
                    'Cambios de portal sin pruebas pueden afectar soporte.',
                    'Permisos mal definidos exponen funciones no deseadas.',
                ],
            ],
        ];
    }

    public function index(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        $guides = $this->settingGuides();
        $parentTopics = $this->parentTopics($guides);
        $childTopics = $this->childTopics($guides);
        $mailChildTopics = $this->mailChildTopics();
        $bugChildTopics = $this->bugChildTopics();
        $specialShortcutTopics = $this->specialShortcutTopics($childTopics);
        $specialTopics = $this->specialTopics();
        $topics = [];

        if ($query !== '') {
            $topics = $this->dedupeTopics(array_merge($parentTopics, $childTopics, $mailChildTopics, $bugChildTopics, $specialTopics));
            $needle = mb_strtolower($query);
            $matched = [];

            foreach ($topics as $topic) {
                $url = (string)($topic['url'] ?? '');
                $content = $this->topicContentByUrl($url, $guides);
                $haystack = mb_strtolower(implode(' ', [
                    (string)($topic['title'] ?? ''),
                    (string)($topic['description'] ?? ''),
                    (string)($topic['category'] ?? ''),
                    (string)($topic['keywords'] ?? ''),
                    $content,
                ]));

                if (!str_contains($haystack, $needle)) {
                    continue;
                }

                $topic['search_excerpt'] = $this->buildSearchExcerpt($content, $query);
                $matched[] = $topic;
            }

            $topics = $matched;
        }

        View::render('help/index', [
            'layout' => 'main',
            'pageTitle' => 'Docs',
            'topics' => $topics,
            'parentTopics' => $parentTopics,
            'childTopics' => $childTopics,
            'mailChildTopics' => $mailChildTopics,
            'bugChildTopics' => $bugChildTopics,
            'specialShortcutTopics' => $specialShortcutTopics,
            'specialTopics' => $specialTopics,
            'query' => $query,
        ]);
    }

    public function mailModes(): void
    {
        View::render('help/mail-modes', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Mail Modes',
        ]);
    }

    public function mailSections(): void
    {
        View::render('help/mail-sections', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Mail Sections',
        ]);
    }

    public function mailGuide(array $params): void
    {
        $slug = trim((string)($params['slug'] ?? ''));
        $mailGuides = $this->mailGuides();
        $guide = $mailGuides[$slug] ?? null;

        if (!$guide) {
            http_response_code(404);
            View::render('help/mail-guide-not-found', [
                'layout' => 'main',
                'pageTitle' => 'Docs - Mail Guide Not Found',
                'slug' => $slug,
            ]);
            return;
        }

        $view = 'help/' . (string)$guide['view'];
        View::render($view, [
            'layout' => 'main',
            'pageTitle' => 'Docs - Mail - ' . (string)($guide['title'] ?? 'Guide'),
        ]);
    }

    public function bugSections(): void
    {
        View::render('help/bugs-sections', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Bugs',
            'bugGuides' => $this->bugGuides(),
        ]);
    }

    public function bugGuide(array $params): void
    {
        $slug = trim((string)($params['slug'] ?? ''));
        $bugGuides = $this->bugGuides();
        $guide = $bugGuides[$slug] ?? null;

        if (!$guide) {
            http_response_code(404);
            View::render('help/bug-guide-not-found', [
                'layout' => 'main',
                'pageTitle' => 'Docs - Bug Not Found',
                'slug' => $slug,
            ]);
            return;
        }

        $view = 'help/' . (string)$guide['view'];
        View::render($view, [
            'layout' => 'main',
            'pageTitle' => 'Docs - Bug - ' . (string)($guide['title'] ?? 'Guide'),
        ]);
    }

    public function settingsSections(): void
    {
        View::render('help/settings-sections', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Settings Sections',
        ]);
    }

    public function settingsGuide(array $params): void
    {
        $slug = trim((string)($params['slug'] ?? ''));
        $guides = $this->settingGuides();
        $guide = $guides[$slug] ?? null;

        if (!$guide) {
            http_response_code(404);
            View::render('help/settings-guide-not-found', [
                'layout' => 'main',
                'pageTitle' => 'Docs - Guide Not Found',
                'slug' => $slug,
            ]);
            return;
        }

        $guide['special_shortcut'] = in_array($slug, $this->specialShortcutSlugs($guides), true);

        View::render('help/settings-guide', [
            'layout' => 'main',
            'pageTitle' => 'Docs - ' . ($guide['title'] ?? 'Settings Guide'),
            'guide' => $guide,
            'slug' => $slug,
        ]);
    }

    public function settingsGuideShortcutToggle(array $params): void
    {
        $slug = trim((string)($params['slug'] ?? ''));
        $guides = $this->settingGuides();

        if (!isset($guides[$slug])) {
            Flash::set('error', 'Guia no encontrada.');
            Router::redirect('/docs/settings-sections');
            return;
        }

        if (!View::verifyCsrf()) {
            Flash::set('error', 'Token CSRF invalido. Recarga la pagina e intentalo de nuevo.');
            Router::redirect('/docs/settings/' . $slug);
            return;
        }

        $current = $this->specialShortcutSlugs($guides);
        $isStarred = in_array($slug, $current, true);

        if ($isStarred) {
            $next = array_values(array_filter($current, static fn(string $item): bool => $item !== $slug));
            $message = 'Guia quitada de accesos directos especiales.';
        } else {
            $next = $current;
            $next[] = $slug;
            $next = array_values(array_unique($next));
            $message = 'Guia anadida a accesos directos especiales.';
        }

        try {
            $this->saveSpecialShortcutSlugs($next);
            Flash::set('success', $message);
        } catch (\Throwable $e) {
            Flash::set('error', 'No se pudo guardar el estado de favorito: ' . $e->getMessage());
        }

        Router::redirect('/docs/settings/' . $slug);
    }

    public function clusterBasics(): void
    {
        View::render('help/cluster-basics', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Cluster Basics',
        ]);
    }

    public function federationBasics(): void
    {
        View::render('help/federation-basics', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Federation Basics',
        ]);
    }

    public function postgresqlMirrorMasterSlave(): void
    {
        View::render('help/postgresql-mirror-master-slave', [
            'layout' => 'main',
            'pageTitle' => 'Docs - PostgreSQL Mirror Master/Slave',
        ]);
    }

    public function installRecovery(): void
    {
        View::render('help/install-recovery', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Instalacion y Recuperacion',
        ]);
    }

    public function defaultBackups(): void
    {
        View::render('help/default-backups', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Backups por defecto',
        ]);
    }

    public function firewallOperations(): void
    {
        View::render('help/firewall-operations', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Firewall Operations',
        ]);
    }

    public function panelTlsDns01(): void
    {
        View::render('help/panel-tls-dns01', [
            'layout' => 'main',
            'pageTitle' => 'Docs - TLS del panel y DNS-01',
        ]);
    }

    public function securityOperations(): void
    {
        View::render('help/security-operations', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Seguridad operativa',
        ]);
    }

    public function syncArchivosLsyncd(): void
    {
        View::render('help/sync-archivos-lsyncd', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Sync de archivos (lsyncd)',
        ]);
    }

    public function profileMfa(): void
    {
        View::render('help/profile-mfa', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Perfil MFA',
        ]);
    }
}
