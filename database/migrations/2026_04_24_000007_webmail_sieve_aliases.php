<?php
/**
 * Webmail commercial details: aliases and Sieve feature flags.
 */
return function (PDO $pdo): void {
    foreach ([
        'mail_webmail_aliases' => '[]',
        'mail_webmail_sieve_enabled' => '0',
    ] as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (:k, :v) ON CONFLICT (key) DO NOTHING");
        $stmt->execute(['k' => $key, 'v' => $value]);
    }
};
