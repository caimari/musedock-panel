<?php
namespace MuseDockPanel;

use MuseDockPanel\Security\ClientIp;

class Auth
{
    public static function findActiveUser(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        return Database::fetchOne(
            "SELECT * FROM panel_admins WHERE username = :username AND is_active = true",
            ['username' => $username]
        );
    }

    public static function verifyCredentials(string $username, string $password): ?array
    {
        $user = self::findActiveUser($username);
        if (!$user) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        return $user;
    }

    public static function loginUser(array $user): void
    {
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
    }

    public static function attempt(string $username, string $password): bool
    {
        $user = self::verifyCredentials($username, $password);
        if (!$user) {
            return false;
        }

        self::loginUser($user);
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
