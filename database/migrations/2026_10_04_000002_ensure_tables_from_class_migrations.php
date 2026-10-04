<?php
/**
 * Las migraciones escritas como clase (return new class { up() }) NO se ejecutaban: el
 * ejecutor solo llamaba a funciones y las apuntaba como hechas igualmente. En los nodos
 * cuyo esquema no venía de schema.sql faltan sus tablas (en Nitro: hosting_subdomains →
 * la página Dominios daba 500). Aquí solo se CREA lo que falte, con las mismas
 * definiciones que schema.sql. No se vuelven a ejecutar aquellas migraciones (algunas
 * renombran o quitan columnas y cambian ajustes de réplica): nada existente se toca.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosting_subdomains (
        id SERIAL PRIMARY KEY,
        account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
        subdomain VARCHAR(255) NOT NULL,
        document_root VARCHAR(500),
        php_version VARCHAR(10) DEFAULT '8.3',
        caddy_route_id VARCHAR(255),
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        php_overrides JSONB,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("ALTER TABLE hosting_subdomains ADD COLUMN IF NOT EXISTS php_version VARCHAR(10) DEFAULT '8.3'");
    $pdo->exec("ALTER TABLE hosting_subdomains ADD COLUMN IF NOT EXISTS php_overrides JSONB");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_hosting_subdomains_subdomain ON hosting_subdomains(subdomain)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hosting_subdomains_account ON hosting_subdomains(account_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hosting_bandwidth (
        id SERIAL PRIMARY KEY,
        account_id INTEGER NOT NULL REFERENCES hosting_accounts(id) ON DELETE CASCADE,
        ts TIMESTAMP NOT NULL,
        bytes_out BIGINT NOT NULL DEFAULT 0,
        bytes_in BIGINT NOT NULL DEFAULT 0,
        requests INTEGER NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosting_subdomain_bandwidth (
        id SERIAL PRIMARY KEY,
        subdomain_id INTEGER NOT NULL REFERENCES hosting_subdomains(id) ON DELETE CASCADE,
        ts TIMESTAMP NOT NULL,
        bytes_out BIGINT NOT NULL DEFAULT 0,
        bytes_in BIGINT NOT NULL DEFAULT 0,
        requests INTEGER NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    // Índices por ts solo si la tabla ya tiene esa columna (las antiguas usaban "date").
    $hasTs = static fn(string $t) => (bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = '{$t}' AND column_name = 'ts'")->fetchColumn();
    if ($hasTs('hosting_bandwidth')) {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_bandwidth_account_ts ON hosting_bandwidth(account_id, ts)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bandwidth_date ON hosting_bandwidth(ts)");
    }
    if ($hasTs('hosting_subdomain_bandwidth')) {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_bw_sub_ts ON hosting_subdomain_bandwidth(subdomain_id, ts)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS replication_users (
        id SERIAL PRIMARY KEY,
        engine VARCHAR(10) NOT NULL DEFAULT 'pg',
        username VARCHAR(100) NOT NULL,
        password_encrypted TEXT NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS replication_authorized_ips (
        id SERIAL PRIMARY KEY,
        engine VARCHAR(10) NOT NULL DEFAULT 'pg',
        ip_address VARCHAR(45) NOT NULL,
        label VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
};
