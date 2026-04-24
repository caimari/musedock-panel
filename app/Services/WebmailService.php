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
        if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
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

        $routeId = 'webmail-' . preg_replace('/[^a-z0-9]/', '', $host);
        $socket = "/run/php/php{$phpVersion}-fpm.sock";
        if (!is_file($socket)) $socket = '/run/php/php-fpm.sock';
        if (!is_file($socket)) $socket = "/run/php/php{$phpVersion}-fpm-www.sock";
        if (!is_file($socket)) {
            return ['ok' => false, 'error' => 'No se encontro socket PHP-FPM para publicar Roundcube'];
        }

        $route = [
            '@id' => $routeId,
            'match' => [['host' => [$host]]],
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [
                    ['handle' => [['handler' => 'vars', 'root' => $docRoot]]],
                    [
                        'match' => [['path' => ['/config/*', '/logs/*', '/temp/*', '/SQL/*', '/vendor/*', '/installer/*', '/composer.*', '/.git/*']]],
                        'handle' => [['handler' => 'static_response', 'status_code' => 403]],
                    ],
                    [
                        'match' => [['file' => ['try_files' => ['{http.request.uri.path}', '{http.request.uri.path}/index.php', '/index.php']]]],
                        'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']],
                    ],
                    [
                        'match' => [['path' => ['*.php']]],
                        'handle' => [[
                            'handler' => 'reverse_proxy',
                            'transport' => ['protocol' => 'fastcgi', 'root' => $docRoot, 'split_path' => ['.php']],
                            'upstreams' => [['dial' => 'unix/' . $socket]],
                        ]],
                    ],
                    ['handle' => [['handler' => 'file_server', 'root' => $docRoot, 'hide' => ['.git', '.env', 'config', 'logs', 'temp', 'SQL', 'vendor', 'composer.json', 'composer.lock']]]],
                ],
            ]],
            'terminal' => true,
        ];

        $del = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($del, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($del);
        curl_close($del);

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
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
        return self::ensureRoundcubeCaddyRoute((string)$cfg['host'], $docRoot, $phpVersion);
    }
}
