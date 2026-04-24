<?php
/**
 * Laravel mail.php example for apps running on the same server as MuseDock Panel.
 *
 * Set MUSEDOCK_INTERNAL_TOKEN in the app .env with the token shown in
 * MuseDock Panel > Mail > Integracion apps.
 */

$token = env('MUSEDOCK_INTERNAL_TOKEN', '');
$config = [];

if ($token !== '') {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 3,
            'header' => 'Authorization: Bearer ' . $token,
        ],
    ]);
    $json = @file_get_contents('http://localhost:8444/api/internal/smtp-config', false, $ctx);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $config = $decoded;
    }
}

return [
    'default' => 'smtp',

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 25,
            'encryption' => $config['encryption'] ?? null,
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
    ],

    'from' => [
        'address' => $config['from_address'] ?? env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name' => $config['from_name'] ?? env('MAIL_FROM_NAME', 'MuseDock'),
    ],
];
