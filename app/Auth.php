<?php
namespace MuseDockPanel;

use MuseDockPanel\Security\ClientIp;

class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $user = Database::fetchOne(
            "SELECT * FROM panel_admins WHERE username = :username AND is_active = true",
            ['username' => $username]
        );

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Update last login
        Database::update('panel_admins', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => ClientIp::resolve(),
        ], 'id = :id', ['id' => $user['id']]);

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['panel_user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['panel_user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['panel_user'] ?? null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            Router::redirect('/login');
        }
    }
}
