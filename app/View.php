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
     * Como render(), pero con una vista de fuera del panel (ruta absoluta), para los
     * módulos que añaden pantallas al panel (p. ej. el portal de clientes: tickets).
     * Usa el layout y los datos compartidos del panel. La ruta la pone el código, nunca
     * el usuario.
     */
    public static function renderFile(string $file, array $data = []): void
    {
        if (!is_file($file) || !str_ends_with($file, '.php')) {
            http_response_code(500);
            echo "Error interno del servidor.";
            error_log("View file not found: {$file}");
            return;
        }
        $data = array_merge(self::$shared, $data);
        extract($data);
        ob_start();
        require $file;
        $__content = ob_get_clean();
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
     * Valor para usar como argumento JS dentro de un atributo HTML (onclick="f(<?= View::js($x) ?>)").
     * Devuelve un literal JS entre comillas, ya escapado también para HTML (sin ' ni " ni < sin escapar).
     */
    public static function js(mixed $value): string
    {
        return htmlspecialchars(
            json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ENT_QUOTES, 'UTF-8'
        );
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
        // En el formulario o, para peticiones con cuerpo JSON, en la cabecera X-CSRF-Token.
        $token = (string)($_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return $token !== '' && hash_equals((string)($_SESSION['_csrf_token'] ?? ''), $token);
    }
}
