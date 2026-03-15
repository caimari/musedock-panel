<?php
/**
 * Migration: Add cluster settings to panel_settings
 */
return function (PDO $pdo): void {
    // Generate a random local token
    $localToken = bin2hex(openssl_random_pseudo_bytes(32));

    // Encrypt it using the same method as ReplicationService
    $key = hash('sha256', ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: 'musedock-default-key'), true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($localToken, 'aes-256-cbc', $key, 0, $iv);
    $encryptedToken = base64_encode($iv . '::' . $encrypted);

    $defaults = [
        'cluster_local_token'        => $encryptedToken,
        'cluster_heartbeat_interval' => '30',
        'cluster_unreachable_timeout'=> '300',
        'cluster_smtp_host'          => '',
        'cluster_smtp_port'          => '587',
        'cluster_smtp_user'          => '',
        'cluster_smtp_pass'          => '',
        'cluster_smtp_from'          => '',
        'cluster_smtp_to'            => '',
        'cluster_telegram_token'     => '',
        'cluster_telegram_chat_id'   => '',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO panel_settings (key, value, updated_at) VALUES (:key, :value, NOW()) ON CONFLICT (key) DO NOTHING'
    );

    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
};
