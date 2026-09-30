<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Services\ClusterPairingService;

/**
 * POST /api/pair/request — endpoint PÚBLICO (sin token) por el que un servidor pide
 * unirse a este cluster. Solo existe mientras el master tiene abierta la ventana de
 * emparejamiento (fuera de ella responde 404), limita solicitudes por IP y no da
 * acceso a nada por sí mismo: unirse exige además que alguien con acceso de
 * escritura al master apruebe el código. Ver ClusterPairingService.
 */
class ClusterPairController
{
    public function request(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $raw = (string)file_get_contents('php://input', false, null, 0, 8192);
        $in = json_decode($raw, true);
        // Detrás de Caddy REMOTE_ADDR es 127.0.0.1: la IP real llega en X-Real-Ip /
        // X-Forwarded-For, que solo se aceptan si el cliente directo es local.
        $fromIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (in_array($fromIp, ['127.0.0.1', '::1'], true)) {
            foreach ([$_SERVER['HTTP_X_REAL_IP'] ?? '', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]] as $cand) {
                if (filter_var(trim($cand), FILTER_VALIDATE_IP)) {
                    $fromIp = trim($cand);
                    break;
                }
            }
        }
        [$code, $body] = ClusterPairingService::handleIncomingRequest(is_array($in) ? $in : [], $fromIp);
        http_response_code($code);
        echo json_encode($body);
    }
}
