<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Env;
use MuseDockPanel\Settings;

class WebmailService
{
    public static function providers(): array
    {
        return [
            'roundcube' => [
                'label' => 'Roundcube',
                'status' => 'supported',
                'description' => 'Webmail IMAP/SMTP estable y habitual en hosting compartido.',
            ],
            'snappymail' => [
                'label' => 'SnappyMail',
                'status' => 'planned',
                'description' => 'Cliente ligero y rapido. Preparado como proveedor futuro.',
            ],
            'sogo' => [
                'label' => 'SOGo',
                'status' => 'planned',
                'description' => 'Groupware con correo, calendarios y contactos. Proveedor futuro.',
            ],
        ];
    }

    public static function config(): array
    {
        $provider = Settings::get('mail_webmail_provider', 'roundcube') ?: 'roundcube';
        if (!isset(self::providers()[$provider])) $provider = 'roundcube';
        $host = Settings::get('mail_webmail_host', '');
        $url = Settings::get('mail_webmail_url', '');
        if ($url === '' && $host !== '') $url = 'https://' . $host;

        return [
            'enabled' => Settings::get('mail_webmail_enabled', '0') === '1',
            'provider' => $provider,
            'provider_label' => self::providers()[$provider]['label'],
            'provider_status' => self::providers()[$provider]['status'],
            'host' => $host,
            'url' => $url,
            'imap_host' => Settings::get('mail_webmail_imap_host', ''),
            'smtp_host' => Settings::get('mail_webmail_smtp_host', ''),
            'doc_root' => Settings::get('mail_webmail_doc_root', ''),
            'aliases' => self::aliases(),
            'sieve_enabled' => Settings::get('mail_webmail_sieve_enabled', '0') === '1',
            'task_id' => Settings::get('mail_webmail_install_task_id', ''),
            'install_status' => Settings::get('mail_webmail_install_status', ''),
            'installed_at' => Settings::get('mail_webmail_installed_at', ''),
        ];
    }

    public static function defaultHost(): string
    {
        $mailHost = Settings::get('mail_local_hostname', '') ?: Settings::get('mail_setup_hostname', '') ?: Settings::get('mail_outbound_hostname', '');
        if ($mailHost !== '' && str_contains($mailHost, '.')) {
            $parts = explode('.', $mailHost);
            $root = implode('.', array_slice($parts, -2));
            return 'webmail.' . $root;
        }
        $panelHost = Settings::get('panel_hostname', '');
        if ($panelHost !== '' && str_contains($panelHost, '.')) {
            $parts = explode('.', $panelHost);
            $root = implode('.', array_slice($parts, -2));
            return 'webmail.' . $root;
        }
        return '';
    }

    public static function defaultMailHost(): string
    {
        return Settings::get('mail_local_hostname', '')
            ?: Settings::get('mail_setup_hostname', '')
            ?: Settings::get('mail_outbound_hostname', '')
            ?: '127.0.0.1';
    }

    public static function saveConfig(string $provider, string $host, string $imapHost, string $smtpHost): array
    {
        $provider = strtolower(trim($provider));
        if (!isset(self::providers()[$provider])) {
            return ['ok' => false, 'error' => 'Proveedor webmail no valido'];
        }
        $host = strtolower(trim($host));
        $imapHost = trim($imapHost) ?: self::defaultMailHost();
        $smtpHost = trim($smtpHost) ?: $imapHost;
        if (!self::isValidHostname($host)) {
            return ['ok' => false, 'error' => 'Hostname webmail no valido'];
        }
        if (self::providers()[$provider]['status'] !== 'supported') {
            return ['ok' => false, 'error' => 'Proveedor aun no soportado: ' . self::providers()[$provider]['label']];
        }

        Settings::set('mail_webmail_provider', $provider);
        Settings::set('mail_webmail_host', $host);
        Settings::set('mail_webmail_url', 'https://' . $host);
        Settings::set('mail_webmail_imap_host', $imapHost);
        Settings::set('mail_webmail_smtp_host', $smtpHost);
        return ['ok' => true];
    }

    public static function aliases(): array
    {
        $raw = Settings::get('mail_webmail_aliases', '[]') ?: '[]';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        $aliases = [];
        foreach ($decoded as $host) {
            $host = strtolower(trim((string)$host));
            if ($host !== '' && self::isValidHostname($host)) {
                $aliases[] = $host;
            }
        }
        return array_values(array_unique($aliases));
    }

    public static function addAlias(string $host): array
    {
        $host = strtolower(trim($host));
        if (!self::isValidHostname($host)) {
            return ['ok' => false, 'error' => 'Hostname webmail no valido'];
        }
        $cfg = self::config();
        if ($host === ($cfg['host'] ?? '')) {
            return ['ok' => false, 'error' => 'Ese hostname ya es el principal'];
        }
        $aliases = self::aliases();
        if (!in_array($host, $aliases, true)) {
            $aliases[] = $host;
            Settings::set('mail_webmail_aliases', json_encode(array_values($aliases), JSON_UNESCAPED_SLASHES));
        }
        $repair = self::repairConfiguredRoute();
        return ($repair['ok'] ?? false) ? ['ok' => true] : $repair;
    }

    public static function deleteAlias(string $host): array
    {
        $host = strtolower(trim($host));
        $aliases = array_values(array_filter(self::aliases(), static fn($h) => $h !== $host));
        Settings::set('mail_webmail_aliases', json_encode($aliases, JSON_UNESCAPED_SLASHES));

        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = rtrim($config['caddy']['api_url'], '/');
        $routeId = self::routeIdForHost($host);
        if ($routeId !== '') {
            $del = curl_init("{$caddyApi}/id/{$routeId}");
            curl_setopt_array($del, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($del);
            curl_close($del);
        }
        return ['ok' => true];
    }

    public static function startInstall(string $provider, string $host, string $imapHost, string $smtpHost): array
    {
        $provider = strtolower(trim($provider));
        $saved = self::saveConfig($provider, $host, $imapHost, $smtpHost);
        if (!($saved['ok'] ?? false)) return $saved;
        if ($provider !== 'roundcube') return ['ok' => false, 'error' => 'Solo Roundcube esta soportado ahora'];

        $taskId = 'webmail-' . $provider . '-' . time() . '-' . bin2hex(random_bytes(4));
        $payload = [
            'provider' => $provider,
            'host' => $host,
            'imap_host' => $imapHost ?: self::defaultMailHost(),
            'smtp_host' => $smtpHost ?: ($imapHost ?: self::defaultMailHost()),
            'php_version' => Env::get('FPM_PHP_VERSION', '8.3'),
        ];
        Settings::set('mail_webmail_install_task_id', $taskId);
        Settings::set('mail_webmail_install_status', 'running');

        $encoded = base64_encode(json_encode($payload));
        $cmd = sprintf(
            'cd %s && nohup php bin/webmail-setup-run.php %s %s > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT),
            escapeshellarg($taskId),
            escapeshellarg($encoded)
        );
        shell_exec($cmd);
        return ['ok' => true, 'task_id' => $taskId];
    }

    public static function installStatus(?string $taskId = null): array
    {
        $taskId = $taskId ?: Settings::get('mail_webmail_install_task_id', '');
        if ($taskId === '') return ['status' => 'idle'];
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $file = PANEL_ROOT . '/storage/webmail-setup-' . $safe . '.json';
        if (!is_file($file)) return ['status' => Settings::get('mail_webmail_install_status', 'running') ?: 'running', 'task_id' => $safe];
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data + ['task_id' => $safe] : ['status' => 'unknown', 'task_id' => $safe];
    }

    public static function ensureRoundcubeCaddyRoute(string $host, string $docRoot, string $phpVersion): array
    {
        $host = strtolower(trim($host));
        if ($host === '' || !is_dir($docRoot)) return ['ok' => false, 'error' => 'Host o document root no valido'];
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = rtrim($config['caddy']['api_url'], '/');
        if (!SystemService::ensureCaddyHttpServerReady($caddyApi)) {
            return ['ok' => false, 'error' => 'Caddy API no disponible'];
        }

        $routeId = self::routeIdForHost($host);
        // A PHP-FPM socket is a Unix SOCKET, not a regular file — is_file() returns
        // false for it, so use file_exists()/filetype() which recognise sockets.
        // Otherwise the socket "isn't found" even though it exists, and the webmail
        // route never gets published (page shows "Dominio no configurado").
        $sockOk = static fn(string $p): bool => @file_exists($p) && @filetype($p) === 'socket';
        $candidates = [
            "/run/php/php{$phpVersion}-fpm.sock",
            '/run/php/php-fpm.sock',
            "/run/php/php{$phpVersion}-fpm-www.sock",
        ];
        // Fallback: any php*-fpm.sock in /run/php (pick the highest version).
        foreach (glob('/run/php/php*-fpm.sock') ?: [] as $g) { $candidates[] = $g; }
        $socket = '';
        foreach ($candidates as $c) { if ($sockOk($c)) { $socket = $c; break; } }
        if ($socket === '') {
            return ['ok' => false, 'error' => 'No se encontro socket PHP-FPM para publicar Roundcube'];
        }

        // Roundcube 1.7 layout: docroot is public_html/ (only index.php + static.php),
        // and assets (skins/, program/, plugins/) live one level up, served THROUGH
        // static.php. The Caddy route therefore:
        //  1. site root = public_html;
        //  2. block sensitive paths (403);
        //  3. asset paths (/skins /program /plugins) → rewrite to static.php/<path> → PHP;
        //  4. real files in public_html (favicon etc.) → file_server;
        //  5. everything else → index.php over FastCGI.
        $fastcgi = [
            'handler' => 'reverse_proxy',
            'transport' => ['protocol' => 'fastcgi', 'root' => $docRoot, 'split_path' => ['.php']],
            'upstreams' => [['dial' => 'unix/' . $socket]],
        ];
        $route = [
            '@id' => $routeId,
            'match' => [['host' => [$host]]],
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [
                    ['handle' => [['handler' => 'vars', 'root' => $docRoot]]],
                    [
                        'match' => [['path' => ['/config/*', '/logs/*', '/temp/*', '/SQL/*', '/vendor/*', '/bin/*', '/installer/*', '/composer.*', '/.git/*']]],
                        'handle' => [['handler' => 'static_response', 'status_code' => 403]],
                    ],
                    // 3. Assets: Roundcube itself builds URLs as static.php/skins/...
                    //    so requests arrive as /static.php/<asset>. static.php must run
                    //    as PHP with the asset path in PATH_INFO — split on "static.php"
                    //    so PATH_INFO becomes /skins/... . (Also handle bare /skins,
                    //    /program, /plugins by rewriting them through static.php.)
                    [
                        'match' => [['path' => ['/static.php', '/static.php/*']]],
                        'handle' => [[
                            'handler' => 'reverse_proxy',
                            'transport' => ['protocol' => 'fastcgi', 'root' => $docRoot, 'split_path' => ['static.php']],
                            'upstreams' => [['dial' => 'unix/' . $socket]],
                        ]],
                    ],
                    [
                        'match' => [['path' => ['/program/*', '/skins/*', '/plugins/*']]],
                        'handle' => [
                            ['handler' => 'rewrite', 'uri' => '/static.php{http.request.uri.path}'],
                            [
                                'handler' => 'reverse_proxy',
                                'transport' => ['protocol' => 'fastcgi', 'root' => $docRoot, 'split_path' => ['static.php']],
                                'upstreams' => [['dial' => 'unix/' . $socket]],
                            ],
                        ],
                    ],
                    // 4. Real files inside public_html (favicon, robots) → file_server.
                    [
                        'match' => [['file' => ['try_files' => ['{http.request.uri.path}']]]],
                        'handle' => [['handler' => 'file_server', 'root' => $docRoot]],
                    ],
                    // 5. Everything else → index.php over FastCGI.
                    [
                        'handle' => [
                            ['handler' => 'rewrite', 'uri' => '/index.php{http.request.uri}'],
                            $fastcgi,
                        ],
                    ],
                ],
            ]],
            'terminal' => true,
        ];

        foreach (array_unique([$routeId, 'webmail-' . preg_replace('/[^a-z0-9]/', '', $host)]) as $deleteId) {
            $del = curl_init("{$caddyApi}/id/{$deleteId}");
            curl_setopt_array($del, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($del);
            curl_close($del);
        }

        // Insert the webmail route at INDEX 0 (PUT .../routes/0), not append (POST
        // .../routes). Caddy evaluates routes in order and the first host match wins;
        // a generic wildcard route ('*.musedock.com', the panel's "domain not
        // configured" fallback) sits early in the list and would otherwise capture
        // webmail.<domain> before the specific route at the end. Prepending makes the
        // exact-host webmail route win.
        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes/0");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($route),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => "Caddy route failed HTTP {$code}: {$response}"];
        }
        return ['ok' => true, 'route_id' => $routeId];
    }

    public static function repairConfiguredRoute(): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || ($cfg['provider'] ?? '') !== 'roundcube' || empty($cfg['host'])) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'webmail_not_enabled'];
        }

        $docRoot = (string)($cfg['doc_root'] ?? '');
        if ($docRoot === '' || !is_dir($docRoot)) {
            $current = '/opt/musedock-webmail/roundcube/current';
            if (is_file($current . '/public_html/index.php')) {
                $docRoot = $current . '/public_html';
            } elseif (is_file($current . '/index.php')) {
                $docRoot = $current;
            }
        }
        if ($docRoot === '' || !is_dir($docRoot)) {
            return ['ok' => false, 'error' => 'Roundcube doc_root no encontrado'];
        }

        $phpVersion = Env::get('FPM_PHP_VERSION', '8.3');
        $hosts = array_values(array_unique(array_filter(array_merge([(string)$cfg['host']], self::aliases()))));
        $errors = [];
        $applied = [];
        foreach ($hosts as $host) {
            $result = self::ensureRoundcubeCaddyRoute($host, $docRoot, $phpVersion);
            if ($result['ok'] ?? false) {
                $applied[] = $host;
            } else {
                $errors[] = $host . ': ' . ($result['error'] ?? 'error desconocido');
            }
        }
        if ($errors) {
            return ['ok' => false, 'error' => implode('; ', $errors), 'applied' => $applied];
        }
        return ['ok' => true, 'applied' => $applied];
    }

    private static function isValidHostname(string $host): bool
    {
        return $host !== '' && (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host);
    }

    private static function routeIdForHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') return '';
        return 'webmail-' . substr(sha1($host), 0, 12) . '-' . preg_replace('/[^a-z0-9]/', '', $host);
    }
}
