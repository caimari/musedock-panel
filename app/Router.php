<?php
namespace MuseDockPanel;

class Router
{
    private static array $routes = [];
    private static array $middleware = [];

    public static function get(string $path, string $handler): void
    {
        self::$routes['GET'][$path] = $handler;
    }

    public static function post(string $path, string $handler): void
    {
        self::$routes['POST'][$path] = $handler;
    }

    public static function middleware(string $name): void
    {
        self::$middleware[] = $name;
    }

    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = strtok($_SERVER['REQUEST_URI'], '?');
        $uri = rtrim($uri, '/') ?: '/';

        // Try exact match first
        if (isset(self::$routes[$method][$uri])) {
            self::execute(self::$routes[$method][$uri]);
            return;
        }

        // Try pattern matching (e.g., /accounts/{id})
        foreach (self::$routes[$method] ?? [] as $pattern => $handler) {
            $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
            if (preg_match("#^{$regex}$#", $uri, $matches)) {
                // Extract named params
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $_REQUEST = array_merge($_REQUEST, $params);
                self::execute($handler, $params);
                return;
            }
        }

        // 404
        http_response_code(404);
        View::render('errors/404');
    }

    private static function execute(string $handler, array $params = []): void
    {
        // Run middleware
        foreach (self::$middleware as $mw) {
            $class = "MuseDockPanel\\Middleware\\{$mw}";
            if (class_exists($class) && method_exists($class, 'handle')) {
                $result = $class::handle();
                if ($result === false) {
                    return;
                }
            }
        }

        // Parse handler: "ControllerName@method"
        [$controllerName, $method] = explode('@', $handler);
        $class = "MuseDockPanel\\Controllers\\{$controllerName}";

        if (!class_exists($class)) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $controller = new $class();
        if (!method_exists($controller, $method)) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $controller->$method($params);
    }

    public static function redirect(string $path): void
    {
        // Prevent open redirect — only allow relative paths
        if (!str_starts_with($path, '/')) {
            $path = '/';
        }
        header("Location: {$path}");
        exit;
    }
}
