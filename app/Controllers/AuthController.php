<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Flash;
use MuseDockPanel\RateLimiter;
use MuseDockPanel\Router;
use MuseDockPanel\Security\ClientIp;
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
        $ip = ClientIp::resolve() ?: 'unknown';

        if (!View::verifyCsrf()) {
            Flash::set('error', 'Sesion expirada. Intenta de nuevo.');
            Router::redirect('/login');
            return;
        }

        if (!RateLimiter::check($ip, 'panel-login', 20)) {
            self::writeAuthLog($ip, $username !== '' ? $username : '-', false);
            Flash::set('error', 'Demasiados intentos. Espera un minuto.');
            Router::redirect('/login');
            return;
        }

        if (empty($username) || empty($password)) {
            Flash::set('error', 'Usuario y contraseña son obligatorios.');
            Router::redirect('/login');
            return;
        }

        if (Auth::attempt($username, $password)) {
            self::writeAuthLog($ip, $username, true);
            Flash::set('success', 'Bienvenido al panel.');
            Router::redirect('/');
        } else {
            self::writeAuthLog($ip, $username, false);
            Flash::set('error', 'Credenciales incorrectas.');
            Router::redirect('/login');
        }
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
