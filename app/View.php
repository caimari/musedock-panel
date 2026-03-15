<?php
namespace MuseDockPanel;

class View
{
    private static string $viewsPath = __DIR__ . '/../resources/views';
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $view, array $data = []): void
    {
        $data = array_merge(self::$shared, $data);
        extract($data);

        $file = self::$viewsPath . '/' . str_replace('.', '/', $view) . '.php';

        if (!file_exists($file)) {
            http_response_code(500);
            echo "Error interno del servidor.";
            error_log("View not found: {$view} ({$file})");
            return;
        }

        ob_start();
        require $file;
        $__content = ob_get_clean();

        // If layout is set, wrap content
        if (isset($layout)) {
            $layoutFile = self::$viewsPath . '/layouts/' . $layout . '.php';
            if (file_exists($layoutFile)) {
                $content = $__content;
                require $layoutFile;
                return;
            }
        }

        echo $__content;
    }

    /**
     * Escape HTML
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Generate or retrieve CSRF token
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Return hidden input with CSRF token
     */
    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . self::csrfToken() . '">';
    }

    /**
     * Validate CSRF token from POST data
     */
    public static function verifyCsrf(): bool
    {
        $token = $_POST['_csrf_token'] ?? '';
        return !empty($token) && hash_equals($_SESSION['_csrf_token'] ?? '', $token);
    }
}
