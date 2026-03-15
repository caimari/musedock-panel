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
            Flash::set('success', 'Bienvenido al panel.');
            Router::redirect('/');
        } else {
            Flash::set('error', 'Credenciales incorrectas.');
            Router::redirect('/login');
        }
    }

    public function logout(): void
    {
        Auth::logout();
        Router::redirect('/login');
    }
}
