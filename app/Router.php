<?php
namespace MuseDockPanel;

class Router
{
    private static array $routes = [];
    private static array $middleware = [];
    private static string $groupPrefix = '';
    private static array $groupMiddleware = [];

    public static function get(string $path, string $handler): void
    {
        $fullPath = self::$groupPrefix . $path;
        self::$routes['GET'][$fullPath] = [
            'handler' => $handler,
            'middleware' => self::$groupMiddleware ?: null,
        ];
    }

    public static function post(string $path, string $handler): void
    {
        $fullPath = self::$groupPrefix . $path;
        self::$routes['POST'][$fullPath] = [
            'handler' => $handler,
            'middleware' => self::$groupMiddleware ?: null,
        ];
    }

    /**
     * Register a group of routes with a shared prefix and optional middleware.
     * Routes inside the group use the group's middleware INSTEAD of global middleware.
     *
     * @param string   $prefix     URL prefix (e.g., '/portal')
     * @param callable $callback   Function that calls Router::get()/post() to register routes
     * @param array    $options    ['middleware' => ['MiddlewareName', ...]]
     */
    public static function group(string $prefix, callable $callback, array $options = []): void
    {
        $previousPrefix = self::$groupPrefix;
        $previousMiddleware = self::$groupMiddleware;

        self::$groupPrefix = $previousPrefix . $prefix;
        self::$groupMiddleware = $options['middleware'] ?? [];

        $callback();

        self::$groupPrefix = $previousPrefix;
        self::$groupMiddleware = $previousMiddleware;
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
            $route = self::$routes[$method][$uri];
            self::execute($route, []);
            return;
        }

        // Try pattern matching (e.g., /accounts/{id})
        foreach (self::$routes[$method] ?? [] as $pattern => $route) {
            $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
            if (preg_match("#^{$regex}$#", $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $_REQUEST = array_merge($_REQUEST, $params);
                self::execute($route, $params);
                return;
            }
        }

        // 404
        http_response_code(404);
        View::render('errors/404');
    }

    private static function execute(array $route, array $params = []): void
    {
        $handler = $route['handler'];
        $routeMiddleware = $route['middleware'];

        // Use route-level middleware if defined, otherwise fall back to global
        $middlewareList = $routeMiddleware !== null ? $routeMiddleware : self::$middleware;

        foreach ($middlewareList as $mw) {
            // Support both short names and fully-qualified class names
            $class = str_contains($mw, '\\') ? $mw : "MuseDockPanel\\Middleware\\{$mw}";
            if (class_exists($class) && method_exists($class, 'handle')) {
                $result = $class::handle();
                if ($result === false) {
                    return;
                }
            }
        }

        // Parse handler: "ControllerName@method" or "Namespace\ControllerName@method"
        [$controllerName, $method] = explode('@', $handler);
        $class = str_contains($controllerName, '\\') ? $controllerName : "MuseDockPanel\\Controllers\\{$controllerName}";

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
