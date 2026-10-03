<?php
/**
 * Las instalaciones nuevas (database/schema.sql) creaban monitor_metrics_hourly y
 * monitor_metrics_daily con una sola columna `value`, pero el recolector escribe
 * avg_val/p95_val/max_val/min_val/samples: la agregación horaria fallaba cada 30 s
 * ("no existe la columna avg_val", visto en Filemon el 2026-10-03) y las gráficas de
 * 7 días o más quedaban vacías. Añade lo que falte; no borra nada.
 */
return function (PDO $pdo): void {
    foreach (['monitor_metrics_hourly', 'monitor_metrics_daily'] as $t) {
        foreach (['avg_val DOUBLE PRECISION', 'p95_val DOUBLE PRECISION', 'max_val DOUBLE PRECISION', 'min_val DOUBLE PRECISION', 'samples INT'] as $col) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS {$col}");
        }
        // La columna antigua, si existe, ya no se rellena: que no impida insertar.
        $has = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = '{$t}' AND column_name = 'value'")->fetchColumn();
        if ($has) {
            $pdo->exec("ALTER TABLE {$t} ALTER COLUMN value DROP NOT NULL");
        }
    }
};
