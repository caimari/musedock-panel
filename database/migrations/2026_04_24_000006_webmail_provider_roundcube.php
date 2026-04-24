<?php
/**
 * Webmail provider settings. Roundcube is the first supported provider.
 */
return function (PDO $pdo): void {
    foreach ([
        'mail_webmail_enabled' => '0',
        'mail_webmail_provider' => 'roundcube',
        'mail_webmail_host' => '',
        'mail_webmail_url' => '',
        'mail_webmail_imap_host' => '',
        'mail_webmail_smtp_host' => '',
        'mail_webmail_doc_root' => '',
        'mail_webmail_install_task_id' => '',
        'mail_webmail_install_status' => '',
        'mail_webmail_installed_at' => '',
    ] as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (:k, :v) ON CONFLICT (key) DO NOTHING");
        $stmt->execute(['k' => $key, 'v' => $value]);
    }
};
