<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

class ProfileController
{
    public function index(): void
    {
        $user = Auth::user();
        $admin = Database::fetchOne(
            "SELECT id, username, email, role, last_login_at, last_login_ip, created_at FROM panel_admins WHERE id = :id",
            ['id' => $user['id']]
        );

        View::render('profile/index', [
            'pageTitle' => 'Mi Perfil',
            'layout' => 'main',
            'admin' => $admin,
        ]);
    }

    public function updateUsername(): void
    {
        $user = Auth::user();
        $newUsername = trim($_POST['username'] ?? '');

        if (empty($newUsername)) {
            Flash::set('error', 'El nombre de usuario no puede estar vacio.');
            Router::redirect('/profile');
            return;
        }

        // Validate format
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,49}$/', $newUsername)) {
            Flash::set('error', 'Usuario invalido. Solo letras, numeros, guiones y puntos (3-50 chars).');
            Router::redirect('/profile');
            return;
        }

        // Check if already taken (by another user)
        if ($newUsername !== $user['username']) {
            $existing = Database::fetchOne(
                "SELECT id FROM panel_admins WHERE username = :username AND id != :id",
                ['username' => $newUsername, 'id' => $user['id']]
            );
            if ($existing) {
                Flash::set('error', 'Ese nombre de usuario ya esta en uso.');
                Router::redirect('/profile');
                return;
            }
        }

        Database::update('panel_admins', [
            'username' => $newUsername,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        // Update session
        $_SESSION['panel_user']['username'] = $newUsername;

        LogService::log('profile.username', $user['username'], "Username changed: {$user['username']} -> {$newUsername}");
        Flash::set('success', 'Nombre de usuario actualizado.');
        Router::redirect('/profile');
    }

    public function updateEmail(): void
    {
        $user = Auth::user();
        $email = trim($_POST['email'] ?? '');

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email no valido.');
            Router::redirect('/profile');
            return;
        }

        Database::update('panel_admins', [
            'email' => $email ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        LogService::log('profile.email', $user['username'], "Email updated");
        Flash::set('success', 'Email actualizado.');
        Router::redirect('/profile');
    }

    public function updatePassword(): void
    {
        $user = Auth::user();
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Verify current password
        $admin = Database::fetchOne(
            "SELECT password_hash FROM panel_admins WHERE id = :id",
            ['id' => $user['id']]
        );

        if (!password_verify($currentPassword, $admin['password_hash'])) {
            Flash::set('error', 'La contrasena actual es incorrecta.');
            Router::redirect('/profile');
            return;
        }

        if (strlen($newPassword) < 8) {
            Flash::set('error', 'La nueva contrasena debe tener al menos 8 caracteres.');
            Router::redirect('/profile');
            return;
        }

        if ($newPassword !== $confirmPassword) {
            Flash::set('error', 'Las contrasenas no coinciden.');
            Router::redirect('/profile');
            return;
        }

        Database::update('panel_admins', [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        LogService::log('profile.password', $user['username'], "Password changed");
        Flash::set('success', 'Contrasena actualizada correctamente.');
        Router::redirect('/profile');
    }
}
