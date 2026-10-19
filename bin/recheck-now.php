#!/usr/bin/env php
<?php
/**
 * Fuerza que el cron del clúster repita YA (en su próxima vuelta, en ≤1 min) las dos revisiones que alimentan los avisos
 * del dashboard: la preparación para tomar el mando (cada 30 min) y la auditoría del firewall (cada 15 min).
 * Útil tras actualizar el panel, para que un aviso ya corregido desaparezca sin esperar.
 */
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__));
require_once PANEL_ROOT . '/app/bootstrap.php';

\MuseDockPanel\Settings::set('ha_readiness_run_at', '0');
\MuseDockPanel\Settings::set('firewall_audit_run_at', '0');
echo "Listo: en la próxima vuelta del cron del clúster (≤ 1 minuto) se repiten la revisión de preparación y la auditoría del firewall.\n";
