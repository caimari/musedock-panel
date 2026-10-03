<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Services\IngressService;

/**
 * GET /api/ingress/domains — lista de dominios que sirve este servidor, para el proxy de
 * entrada alternativa (IngressService). Clave propia: "Authorization: Bearer <clave>"
 * (o "X-Api-Key"). No da acceso a nada más.
 */
class IngressController
{
    public function domains(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
        if (!IngressService::config()['enabled'] || !IngressService::checkKey($key)) {
            http_response_code(!IngressService::config()['enabled'] ? 404 : 401);
            echo json_encode(['ok' => false]);
            return;
        }
        echo json_encode(['ok' => true, 'server' => gethostname(), 'domains' => IngressService::domains()], JSON_UNESCAPED_SLASHES);
    }
}
