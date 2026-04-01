<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;

class AuthController
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            Router::redirect('/');
            return;
        }
        View::render('auth/login');
    }

    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            Flash::set('error', 'Usuario y contraseña son obligatorios.');
            Router::redirect('/login');
            return;
        }

        if (Auth::attempt($username, $password)) {
            $ip = self::getClientIp();
            self::writeAuthLog($ip, $username, true);
            Flash::set('success', 'Bienvenido al panel.');
            Router::redirect('/');
        } else {
            $ip = self::getClientIp();
            self::writeAuthLog($ip, $username, false);
            Flash::set('error', 'Credenciales incorrectas.');
            Router::redirect('/login');
        }
    }

    private static function getClientIp(): string
    {
        // Behind Caddy reverse proxy — use X-Forwarded-For
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = $ips[0]; // leftmost = original client
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private static function writeAuthLog(string $ip, string $username, bool $success): void
    {
        $status = $success ? 'OK' : 'FAIL';
        $line = date('Y-m-d H:i:s') . " {$status} login from {$ip} user {$username}\n";
        @file_put_contents('/var/log/musedock-panel-auth.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function logout(): void
    {
        Auth::logout();
        Router::redirect('/login');
    }
}
