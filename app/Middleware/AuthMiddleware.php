<?php
namespace MuseDockPanel\Middleware;

use MuseDockPanel\Auth;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;

class AuthMiddleware
{
    private static array $publicPaths = ['/login', '/login/submit'];
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
            Router::redirect('/login');
            return false;
        }

        // CSRF validation on all POST requests (except login)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!View::verifyCsrf()) {
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
