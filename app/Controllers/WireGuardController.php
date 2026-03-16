<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\WireGuardService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\ReplicationService;

class WireGuardController
{
    public function index(): void
    {
        $installed = WireGuardService::isInstalled();
        $running   = $installed ? WireGuardService::isRunning() : false;
        $status    = null;
        $peers     = [];
        $interfaceIp = '';
        $config    = '';

        if ($installed && $running) {
            $status      = WireGuardService::getInterfaceStatus();
            $peers       = WireGuardService::getPeers();
            $interfaceIp = WireGuardService::getInterfaceIp();
            $config      = WireGuardService::getConfig();
        }

        View::render('settings/wireguard', [
            'layout'      => 'main',
            'pageTitle'   => 'WireGuard',
            'installed'   => $installed,
            'running'     => $running,
            'status'      => $status,
            'peers'       => $peers,
            'interfaceIp' => $interfaceIp,
            'config'      => $config,
        ]);
    }

    public function install(): void
    {
        View::verifyCsrf();

        $result = WireGuardService::install();
        if ($result['ok']) {
            LogService::log('wireguard.install', 'wg0', 'WireGuard instalado correctamente');
            Flash::set('success', 'WireGuard instalado correctamente');
        } else {
            Flash::set('error', 'Error al instalar WireGuard: ' . $result['output']);
        }

        header('Location: /settings/wireguard');
        exit;
    }

    public function addPeer(): void
    {
        View::verifyCsrf();

        $publicKey    = trim($_POST['public_key'] ?? '');
        $allowedIps   = trim($_POST['allowed_ips'] ?? '');
        $endpoint     = trim($_POST['endpoint'] ?? '');
        $presharedKey = trim($_POST['preshared_key'] ?? '');

        if ($publicKey === '' || $allowedIps === '') {
            Flash::set('error', 'La clave publica y las IPs permitidas son obligatorias');
            header('Location: /settings/wireguard');
            exit;
        }

        // Validate public key format (base64, 44 chars)
        if (!preg_match('/^[A-Za-z0-9+\/]{42,44}={0,2}$/', $publicKey)) {
            Flash::set('error', 'Formato de clave publica no valido');
            header('Location: /settings/wireguard');
            exit;
        }

        // Encrypt preshared key if provided
        $storedPsk = '';
        if ($presharedKey !== '') {
            $storedPsk = $presharedKey;
        }

        $result = WireGuardService::addPeer($publicKey, $allowedIps, $endpoint, $storedPsk);
        if ($result['ok']) {
            LogService::log('wireguard.peer.add', $publicKey, "AllowedIPs: {$allowedIps}");
            Flash::set('success', 'Peer agregado correctamente');
        } else {
            Flash::set('error', 'Error al agregar peer: ' . $result['output']);
        }

        header('Location: /settings/wireguard');
        exit;
    }

    public function removePeer(): void
    {
        View::verifyCsrf();

        $publicKey = trim($_POST['public_key'] ?? '');
        if ($publicKey === '') {
            Flash::set('error', 'Clave publica no proporcionada');
            header('Location: /settings/wireguard');
            exit;
        }

        $result = WireGuardService::removePeer($publicKey);
        if ($result['ok']) {
            LogService::log('wireguard.peer.remove', $publicKey, 'Peer eliminado');
            Flash::set('success', 'Peer eliminado correctamente');
        } else {
            Flash::set('error', 'Error al eliminar peer: ' . $result['output']);
        }

        header('Location: /settings/wireguard');
        exit;
    }

    public function updatePeer(): void
    {
        View::verifyCsrf();

        $publicKey  = trim($_POST['public_key'] ?? '');
        $allowedIps = trim($_POST['allowed_ips'] ?? '');
        $endpoint   = trim($_POST['endpoint'] ?? '');

        if ($publicKey === '' || $allowedIps === '') {
            Flash::set('error', 'Clave publica y IPs permitidas son obligatorias');
            header('Location: /settings/wireguard');
            exit;
        }

        $result = WireGuardService::updatePeer($publicKey, $allowedIps, $endpoint);
        if ($result['ok']) {
            LogService::log('wireguard.peer.update', $publicKey, "AllowedIPs: {$allowedIps}");
            Flash::set('success', 'Peer actualizado correctamente');
        } else {
            Flash::set('error', 'Error al actualizar peer: ' . $result['output']);
        }

        header('Location: /settings/wireguard');
        exit;
    }

    public function generateKeys(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $keyPair = WireGuardService::generateKeyPair();
        echo json_encode([
            'ok'      => $keyPair['private'] !== '' && $keyPair['public'] !== '',
            'private' => $keyPair['private'],
            'public'  => $keyPair['public'],
        ]);
        exit;
    }

    public function generateConfig(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $serverPublicKey = trim($_POST['server_public_key'] ?? '');
        $serverEndpoint  = trim($_POST['server_endpoint'] ?? '');
        $serverPort      = (int)($_POST['server_port'] ?? 51820);
        $peerPrivateKey  = trim($_POST['peer_private_key'] ?? '');
        $peerAddress     = trim($_POST['peer_address'] ?? '');
        $allowedIps      = trim($_POST['allowed_ips'] ?? '10.10.70.0/24');
        $presharedKey    = trim($_POST['preshared_key'] ?? '');

        if ($serverPublicKey === '' || $serverEndpoint === '' || $peerPrivateKey === '' || $peerAddress === '') {
            echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios']);
            exit;
        }

        $config = WireGuardService::generatePeerConfig(
            $serverPublicKey, $serverEndpoint, $serverPort,
            $peerPrivateKey, $peerAddress, $allowedIps, $presharedKey
        );

        echo json_encode(['ok' => true, 'config' => $config]);
        exit;
    }

    public function pingPeer(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $ip = trim($_POST['ip'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'IP no valida']);
            exit;
        }

        $latency = WireGuardService::pingPeer($ip);
        echo json_encode([
            'ok'      => $latency !== null,
            'latency' => $latency,
            'display' => $latency !== null ? round($latency, 2) . ' ms' : 'Sin respuesta',
        ]);
        exit;
    }

    public function restart(): void
    {
        View::verifyCsrf();

        $result = WireGuardService::restartInterface();
        if ($result['ok']) {
            LogService::log('wireguard.restart', 'wg0', 'Interfaz reiniciada');
            Flash::set('success', 'Interfaz WireGuard reiniciada correctamente');
        } else {
            Flash::set('error', 'Error al reiniciar interfaz: ' . $result['output']);
        }

        header('Location: /settings/wireguard');
        exit;
    }

    public function start(): void
    {
        View::verifyCsrf();

        $output = trim((string)shell_exec('systemctl start wg-quick@wg0 2>&1'));
        $running = WireGuardService::isRunning();

        if ($running) {
            LogService::log('wireguard.start', 'wg0', 'Interfaz iniciada');
            Flash::set('success', 'Interfaz WireGuard iniciada correctamente');
        } else {
            Flash::set('error', 'Error al iniciar interfaz: ' . $output);
        }

        header('Location: /settings/wireguard');
        exit;
    }

    public function status(): void
    {
        header('Content-Type: application/json');

        $installed = WireGuardService::isInstalled();
        $running   = $installed ? WireGuardService::isRunning() : false;

        $data = [
            'installed'    => $installed,
            'running'      => $running,
            'interface_ip' => '',
            'status'       => null,
            'peers'        => [],
            'timestamp'    => date('Y-m-d H:i:s'),
        ];

        if ($running) {
            $data['status']       = WireGuardService::getInterfaceStatus();
            $data['peers']        = WireGuardService::getPeers();
            $data['interface_ip'] = WireGuardService::getInterfaceIp();
        }

        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}
