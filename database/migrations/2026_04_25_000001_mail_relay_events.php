<?php
/**
 * Persist parsed Postfix relay delivery events so mail.log cleanup/rotation does
 * not erase the panel history.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_relay_events (
        id BIGSERIAL PRIMARY KEY,
        line_hash CHAR(64) NOT NULL UNIQUE,
        event_at TIMESTAMP NOT NULL,
        log_timestamp VARCHAR(32) NOT NULL DEFAULT '',
        domain VARCHAR(255) NOT NULL DEFAULT '',
        sender VARCHAR(320) NOT NULL DEFAULT '',
        recipient VARCHAR(320) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL,
        relay VARCHAR(255) NOT NULL DEFAULT '',
        dsn VARCHAR(64) NOT NULL DEFAULT '',
        detail TEXT NOT NULL DEFAULT '',
        raw_line TEXT NOT NULL,
        source_file VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_relay_events_event_at ON mail_relay_events(event_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_relay_events_status ON mail_relay_events(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_relay_events_domain ON mail_relay_events(domain)");
};
