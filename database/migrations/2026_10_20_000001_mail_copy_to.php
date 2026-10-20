<?php
/**
 * «Enviar copia a…» por buzón: una dirección a la que Postfix manda una copia de lo que llega al buzón
 * (recipient_bcc_maps), conservando el original. Solo añade una columna; vacía = sin copia.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS copy_to VARCHAR(255) NOT NULL DEFAULT ''");
};
