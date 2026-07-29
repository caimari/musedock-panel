#!/usr/bin/env php
<?php
/**
 * MuseDock — asynchronous Caddy DNS-module build.
 *
 * xcaddy builds take 2–5 min, but PHP-FPM kills any request at
 * request_terminate_timeout (120s). So the cluster action can't compile inline.
 * This CLI script does the actual build (reusing SystemService) and writes the
 * result to a status file; the node handler launches it detached (nohup) and
 * returns immediately, and the master polls the status.
 *
 * Usage: php bin/caddy-build-run.php <provider> <taskId>
 */
require_once __DIR__ . '/../app/bootstrap.php';

use MuseDockPanel\Services\SystemService;

$provider = strtolower(trim((string)($argv[1] ?? '')));
$taskId   = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($argv[2] ?? ''));
if ($provider === '' || $taskId === '') {
    fwrite(STDERR, "Usage: caddy-build-run.php <provider> <taskId>\n");
    exit(1);
}

$dir = '/var/lib/musedock';
@mkdir($dir, 0750, true);
$statusFile = "{$dir}/caddy-build-{$taskId}.json";

$write = static function (array $data) use ($statusFile) {
    @file_put_contents($statusFile, json_encode($data, JSON_UNESCAPED_SLASHES));
};

$write(['status' => 'building', 'provider' => $provider, 'started_at' => gmdate('c')]);

try {
    $res = SystemService::installCaddyDnsProvider($provider);
    $write([
        'status'      => !empty($res['ok']) ? 'done' : 'failed',
        'provider'    => $provider,
        'ok'          => !empty($res['ok']),
        'message'     => (string)($res['message'] ?? $res['error'] ?? ''),
        'output'      => (string)($res['output'] ?? ''),
        'finished_at' => gmdate('c'),
    ]);
} catch (\Throwable $e) {
    $write([
        'status'      => 'failed',
        'provider'    => $provider,
        'ok'          => false,
        'message'     => 'Excepción: ' . $e->getMessage(),
        'finished_at' => gmdate('c'),
    ]);
    exit(1);
}
