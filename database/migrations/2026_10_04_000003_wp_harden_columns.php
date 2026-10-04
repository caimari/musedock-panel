<?php
/**
 * Blindar WordPress (WordPressHardenService), por hosting:
 *   wp_harden        off | standard | strict   (standard por defecto: no limita al cliente)
 *   wp_allow_xmlrpc  NULL = automático (solo si tiene Jetpack), true/false = forzado
 *   wp_unlock_until  en strict: hasta cuándo está desbloqueado el código para actualizar
 * Solo añade columnas que falten; no toca nada existente.
 */
return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE hosting_accounts ADD COLUMN IF NOT EXISTS wp_harden VARCHAR(10) NOT NULL DEFAULT 'standard'");
    $pdo->exec("ALTER TABLE hosting_accounts ADD COLUMN IF NOT EXISTS wp_allow_xmlrpc BOOLEAN");
    $pdo->exec("ALTER TABLE hosting_accounts ADD COLUMN IF NOT EXISTS wp_unlock_until TIMESTAMP");
};
