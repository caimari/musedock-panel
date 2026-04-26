<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\MfaService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

class ProfileController
{
    public function index(): void
    {
        $user = Auth::user();
        $admin = Database::fetchOne(
            "SELECT id, username, email, role, last_login_at, last_login_ip, created_at, mfa_enabled, mfa_secret
             FROM panel_admins WHERE id = :id",
            ['id' => $user['id']]
        );

        $activeAdmins = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN mfa_enabled = true AND mfa_secret IS NOT NULL AND mfa_secret != '' THEN 1 ELSE 0 END) AS enrolled
             FROM panel_admins
             WHERE is_active = true"
        );
        $mfaSetupSecret = (string)($_SESSION['mfa_setup_secret'] ?? '');
        $issuer = 'MuseDock Panel';
        $otpAuthUri = $mfaSetupSecret !== ''
            ? MfaService::buildOtpAuthUri($issuer, (string)($admin['username'] ?? 'admin'), $mfaSetupSecret)
            : '';

        View::render('profile/index', [
            'pageTitle' => 'Mi Perfil',
            'layout' => 'main',
            'admin' => $admin,
            'mfaSetupSecret' => $mfaSetupSecret,
            'mfaOtpAuthUri' => $otpAuthUri,
            'mfaIssuer' => $issuer,
            'mfaRequiredGlobal' => Settings::get('security_mfa_required', '0') === '1',
            'mfaActiveAdmins' => (int)($activeAdmins['total'] ?? 0),
            'mfaEnrolledAdmins' => (int)($activeAdmins['enrolled'] ?? 0),
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

    public function mfaStart(): void
    {
        $user = Auth::user();
        if (!$user) {
            Flash::set('error', 'Sesion no valida.');
            Router::redirect('/login');
            return;
        }

        $_SESSION['mfa_setup_secret'] = MfaService::generateSecret(32);
        LogService::log('profile.mfa.start', $user['username'], 'Inicio de configuracion MFA');
        Flash::set('success', 'Secret MFA generado. Escanealo en tu app y confirma con un codigo.');
        Router::redirect('/profile');
    }

    public function mfaEnable(): void
    {
        $user = Auth::user();
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $code = trim((string)($_POST['mfa_code'] ?? ''));
        $secret = (string)($_SESSION['mfa_setup_secret'] ?? '');

        if ($secret === '') {
            Flash::set('error', 'No hay configuracion MFA pendiente. Pulsa "Generar/rotar secret" primero.');
            Router::redirect('/profile');
            return;
        }

        $admin = Database::fetchOne(
            "SELECT password_hash FROM panel_admins WHERE id = :id",
            ['id' => $user['id']]
        );
        if (!$admin || !password_verify($currentPassword, (string)$admin['password_hash'])) {
            Flash::set('error', 'Contrasena actual incorrecta.');
            Router::redirect('/profile');
            return;
        }

        if (!MfaService::verifyCode($secret, $code, 1)) {
            Flash::set('error', 'Codigo MFA invalido.');
            Router::redirect('/profile');
            return;
        }

        Database::update('panel_admins', [
            'mfa_enabled' => true,
            'mfa_secret' => ReplicationService::encryptPassword($secret),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        unset($_SESSION['mfa_setup_secret']);
        LogService::log('profile.mfa.enable', $user['username'], 'MFA activado');
        Flash::set('success', 'MFA activado correctamente.');
        Router::redirect('/profile');
    }

    public function mfaDisable(): void
    {
        $user = Auth::user();
        if (Settings::get('security_mfa_required', '0') === '1') {
            Flash::set('error', 'MFA obligatorio esta activo globalmente. Desactivalo primero en Settings > Security.');
            Router::redirect('/profile');
            return;
        }

        $currentPassword = (string)($_POST['current_password_disable'] ?? '');
        $code = trim((string)($_POST['mfa_code_disable'] ?? ''));

        $admin = Database::fetchOne(
            "SELECT password_hash, mfa_secret, (CASE WHEN mfa_enabled THEN 1 ELSE 0 END) AS mfa_enabled_int
             FROM panel_admins WHERE id = :id",
            ['id' => $user['id']]
        );
        if (!$admin || (int)($admin['mfa_enabled_int'] ?? 0) !== 1) {
            Flash::set('warning', 'MFA ya estaba desactivado.');
            Router::redirect('/profile');
            return;
        }
        if (!password_verify($currentPassword, (string)$admin['password_hash'])) {
            Flash::set('error', 'Contrasena actual incorrecta.');
            Router::redirect('/profile');
            return;
        }

        $secretEnc = (string)($admin['mfa_secret'] ?? '');
        $secret = $secretEnc !== '' ? ReplicationService::decryptPassword($secretEnc) : '';
        if ($secret === '' || !MfaService::verifyCode($secret, $code, 1)) {
            Flash::set('error', 'Codigo MFA invalido.');
            Router::redirect('/profile');
            return;
        }

        Database::update('panel_admins', [
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        unset($_SESSION['mfa_setup_secret']);
        LogService::log('profile.mfa.disable', $user['username'], 'MFA desactivado');
        Flash::set('success', 'MFA desactivado.');
        Router::redirect('/profile');
    }
}
