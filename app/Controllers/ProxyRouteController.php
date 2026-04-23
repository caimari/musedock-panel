<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\ProxyRouteService;

class ProxyRouteController
{
    /**
     * GET /settings/proxy-routes — list all proxy routes
     */
    public function index(): void
    {
        $routes = ProxyRouteService::getAll();
        $canAdd = ProxyRouteService::canAddRoute();

        View::render('settings.proxy-routes', [
            'layout'    => 'main',
            'pageTitle' => 'Proxy Routes',
            'routes'    => $routes,
            'canAdd'    => $canAdd,
        ]);
    }

    /**
     * POST /settings/proxy-routes/save — create or update a route
     */
    public function save(): void
    {
        $id     = (int)($_POST['id'] ?? 0);
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $ip     = trim($_POST['target_ip'] ?? '');
        $port   = (int)($_POST['target_port'] ?? 443) ?: 443;
        $name   = trim($_POST['name'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $enabled = isset($_POST['enabled']);

        if (!$domain || !$ip) {
            Flash::set('error', 'Dominio y IP destino son obligatorios');
            Router::redirect('/settings/proxy-routes');
            return;
        }

        // Validate domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $domain)) {
            Flash::set('error', 'Formato de dominio inválido');
            Router::redirect('/settings/proxy-routes');
            return;
        }

        // Validate IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP destino inválida');
            Router::redirect('/settings/proxy-routes');
            return;
        }

        // Check for duplicate domain (different route)
        $existing = ProxyRouteService::getByDomain($domain);
        if ($existing && (int)$existing['id'] !== $id) {
            Flash::set('error', "El dominio {$domain} ya tiene una ruta proxy configurada");
            Router::redirect('/settings/proxy-routes');
            return;
        }

        $data = [
            'name'        => $name,
            'domain'      => $domain,
            'target_ip'   => $ip,
            'target_port' => $port,
            'enabled'     => $enabled,
            'notes'       => $notes,
        ];

        if ($id) {
            // Update
            $route = ProxyRouteService::getById($id);
            if (!$route) {
                Flash::set('error', 'Ruta no encontrada');
                Router::redirect('/settings/proxy-routes');
                return;
            }
            ProxyRouteService::update($id, $data);
            Flash::set('success', "Ruta proxy actualizada: {$domain}");
        } else {
            // Create — check license
            if (!ProxyRouteService::canAddRoute()) {
                Flash::set('error', 'No se pudo crear la ruta proxy.');
                Router::redirect('/settings/proxy-routes');
                return;
            }
            ProxyRouteService::create($data);
            Flash::set('success', "Ruta proxy creada: {$domain}");
        }

        // Apply caddy-l4 config if binary exists
        ProxyRouteService::applyCaddyL4Config();

        Router::redirect('/settings/proxy-routes');
    }

    /**
     * POST /settings/proxy-routes/delete — delete a route
     */
    public function delete(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $route = ProxyRouteService::getById($id);
            ProxyRouteService::delete($id);
            if ($route) {
                Flash::set('success', "Ruta proxy eliminada: {$route['domain']}");
            }
            ProxyRouteService::applyCaddyL4Config();
        }
        Router::redirect('/settings/proxy-routes');
    }

    /**
     * POST /settings/proxy-routes/toggle — enable/disable a route
     */
    public function toggle(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $route = ProxyRouteService::getById($id);
            if ($route) {
                ProxyRouteService::update($id, array_merge($route, [
                    'enabled' => !$route['enabled'],
                ]));
                $state = $route['enabled'] ? 'desactivada' : 'activada';
                Flash::set('success', "Ruta {$route['domain']} {$state}");
                ProxyRouteService::applyCaddyL4Config();
            }
        }
        Router::redirect('/settings/proxy-routes');
    }

    /**
     * POST /settings/proxy-routes/test — test target connectivity (AJAX)
     */
    public function test(): void
    {
        header('Content-Type: application/json');
        $ip   = trim($_POST['ip'] ?? '');
        $port = (int)($_POST['port'] ?? 443) ?: 443;

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'IP inválida']);
            return;
        }

        $result = ProxyRouteService::testTarget($ip, $port);
        echo json_encode($result);
    }

    /**
     * GET /settings/proxy-routes/preview — preview caddy-l4 config (AJAX)
     */
    public function preview(): void
    {
        header('Content-Type: application/json');
        $proxyRoutes = ProxyRouteService::getCaddyL4Routes();
        echo json_encode([
            'ok'     => true,
            'routes' => $proxyRoutes,
            'count'  => count($proxyRoutes),
        ]);
    }
}
