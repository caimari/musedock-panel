<?php
/**
 * Add shell column to hosting_accounts
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE hosting_accounts ADD COLUMN IF NOT EXISTS shell VARCHAR(50) NOT NULL DEFAULT '/usr/sbin/nologin'");
};
