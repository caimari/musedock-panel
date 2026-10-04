<?php
/**
 * Deja hosting_domain_aliases completa en nodos instalados desde cero: la plantilla
 * (schema.sql) no traía customer_id ni target_url y el instalador no ejecutaba de verdad
 * las migraciones, así que la página Dominios podía fallar (500) al pedir esas columnas.
 * Idempotente: solo añade lo que falta; no borra nada.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE hosting_domain_aliases ALTER COLUMN hosting_account_id DROP NOT NULL");
    $pdo->exec("ALTER TABLE hosting_domain_aliases ADD COLUMN IF NOT EXISTS target_url VARCHAR(500) DEFAULT NULL");
    $has = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'hosting_domain_aliases' AND column_name = 'customer_id'")->fetchColumn();
    if (!$has) {
        $pdo->exec("ALTER TABLE hosting_domain_aliases ADD COLUMN customer_id INTEGER DEFAULT NULL REFERENCES customers(id) ON DELETE SET NULL");
    }
};
