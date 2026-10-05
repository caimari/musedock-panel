<?php
/**
 * Identificador estable de cada cliente (uid): no cambia aunque se cambie su correo.
 * La copia de clientes entre nodos (PortalService) los reconocía por el correo, y un
 * cambio de correo en el principal hacía que el cliente "viejo" del otro nodo volviera
 * como si fuera otro. Los nuevos lo reciben solos (DEFAULT); los existentes se rellenan.
 * Solo añade; no toca nada existente.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS uid VARCHAR(32)");
    $pdo->exec("UPDATE customers SET uid = md5(random()::text || clock_timestamp()::text || id::text) WHERE uid IS NULL OR uid = ''");
    $pdo->exec("ALTER TABLE customers ALTER COLUMN uid SET DEFAULT md5(random()::text || clock_timestamp()::text)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS customers_uid_key ON customers(uid)");
};
