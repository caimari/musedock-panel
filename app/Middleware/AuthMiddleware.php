<?php
namespace MuseDockPanel\Middleware;

use MuseDockPanel\Auth;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;

class AuthMiddleware
{
    private static array $publicPaths = ['/login', '/login/submit', '/login/mfa', '/login/mfa/verify', '/portal'];
    private static array $apiPrefixes = ['/api/'];

    public static function handle(): bool
    {
        $uri = strtok($_SERVER['REQUEST_URI'], '?');
        $uri = rtrim($uri, '/') ?: '/';

        // Allow static assets
        if (preg_match('/\.(css|js|png|jpg|svg|ico|woff2?)$/', $uri)) {
            return true;
        }

        // Allow public paths
        if (in_array($uri, self::$publicPaths)) {
            return true;
        }

        // Allow API routes (handled by ApiAuthMiddleware)
        foreach (self::$apiPrefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        // Check auth
        if (!Auth::check()) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            $wantJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
            $isFetch = !empty($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] !== 'navigate';
            if ($isAjax || $wantJson || $isFetch) {
                header('Content-Type: application/json', true, 401);
                echo json_encode(['ok' => false, 'error' => 'Sesión expirada. Recarga la página.']);
                exit;
            }
            Router::redirect('/login');
            return false;
        }

        // CSRF validation on all POST requests (except login)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!View::verifyCsrf()) {
                // If AJAX/fetch request, return JSON error instead of redirect
                $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                $wantJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
                $isFetch = !empty($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] !== 'navigate';
                if ($isAjax || $wantJson || $isFetch) {
                    header('Content-Type: application/json', true, 403);
                    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página e intenta de nuevo.']);
                    exit;
                }
                Flash::set('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
                $referer = $_SERVER['HTTP_REFERER'] ?? '/';
                // Only allow same-origin referer redirects
                $refererPath = parse_url($referer, PHP_URL_PATH) ?: '/';
                Router::redirect($refererPath);
                return false;
            }
        }

        return true;
    }
}
