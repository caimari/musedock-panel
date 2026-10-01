<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Mcp\McpInventory;

/**
 * Vigilante de diferencias master ↔ slave (genérico, para cualquier pareja).
 *
 * Compara el inventario de ESTE servidor (el master) con el de un nodo, en lo que
 * importa para que un relevo funcione: programas de supervisor, unidades systemd
 * propias, tareas cron, webs del Caddyfile fuera del panel, versiones y extensiones
 * de PHP, Node/npm/Composer, paquetes relevantes y nº de hostings del panel.
 *
 * - "missing_or_different": falta en el nodo o es distinto → hay que arreglarlo.
 * - "only_on_node": existe solo en el nodo → informativo (puede ser legítimo).
 * En un slave es normal que los programas estén con autostart=false y los crons
 * desactivados (#MUSEDOCK-OFF#): eso NO cuenta como diferencia.
 * Solo lectura: no cambia nada en ningún servidor.
 */
final class ClusterDriftService
{
    /** Paquetes que suelen aportar runtimes/servicios que una app necesita. */
    // Sin "php-*" sin versión (metapaquetes: las extensiones ya se comparan por
    // versión) ni "python3-*" (base del sistema): solo generaban ruido.
    private const RELEVANT_PKG_RE = '/^(php\d+\.\d+-|nodejs$|redis|postgresql-\d+$|mysql-server|mariadb-server|supervisor$|caddy$|composer$|imagemagick|ghostscript$|ffmpeg$|libvips|chromium|wkhtmltopdf$|poppler-utils$|tesseract-ocr$|golang|openjdk|ruby$|memcached$|rabbitmq-server$|elasticsearch$|meilisearch$)/';

    public static function fetchNodeInventory(int $nodeId): array
    {
        $r = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', ['action' => 'clone-inventory', 'payload' => ['section' => 'all']]);
        $inv = $r['data']['inventory'] ?? null;
        if (empty($r['ok']) || !is_array($inv)) {
            throw new \RuntimeException('El nodo no devolvió su inventario: ' . ($r['data']['error'] ?? $r['error'] ?? '?')
                . ' (¿versión del panel anterior a 1.0.240?)');
        }
        return $inv;
    }

    public static function compareWithNode(int $nodeId): array
    {
        $node = ClusterService::getNode($nodeId);
        if (!$node) {
            throw new \RuntimeException("Nodo {$nodeId} no encontrado.");
        }
        $local = McpInventory::build('all', false);
        $remote = self::fetchNodeInventory($nodeId);
        $r = self::compare($local, $remote);
        // Mismo indicador en los dos lados (el del inventario: dominios del panel,
        // incluidos los alias). Antes se comparaba el nº de hostings de la BD del
        // master con ese indicador del nodo, que cuenta también los alias.
        $masterHostings = (int)($local['sites']['panel_hostings'] ?? -1);
        $nodeHostings = (int)($remote['sites']['panel_hostings'] ?? -1);
        if ($masterHostings >= 0 && $nodeHostings >= 0 && $nodeHostings !== $masterHostings) {
            $r['missing_or_different'][] = "Dominios del panel (hostings + alias): master {$masterHostings}, nodo {$nodeHostings} (usa cluster_sync_hostings).";
        }
        $r['node'] = $node['name'];
        $r['in_sync'] = $r['missing_or_different'] === [];
        return $r;
    }

    /** Compara dos inventarios de McpInventory::build('all'). */
    public static function compare(array $m, array $s): array
    {
        $diff = [];
        $extra = [];
        $notes = [];
        $checked = [];

        // ── Supervisor ──
        $sup = static function (array $inv): array {
            $o = [];
            foreach (($inv['services']['supervisor']['programs'] ?? []) as $p) {
                $o[$p['program']] = implode(' | ', [trim((string)$p['command']), (string)($p['user'] ?? ''), (string)($p['directory'] ?? ''), 'numprocs=' . (int)($p['numprocs'] ?? 1)]);
            }
            return $o;
        };
        [$ms, $ss] = [$sup($m), $sup($s)];
        foreach ($ms as $name => $sig) {
            if (!isset($ss[$name])) {
                $diff[] = "Supervisor: falta el programa '{$name}' en el nodo.";
            } elseif ($ss[$name] !== $sig) {
                $diff[] = "Supervisor: el programa '{$name}' es distinto (comando/usuario/carpeta/procesos).";
            }
        }
        foreach (array_diff_key($ss, $ms) as $name => $_) {
            $extra[] = "Supervisor: el programa '{$name}' solo existe en el nodo.";
        }
        $checked[] = 'supervisor: ' . count($ms) . ' programas';

        // ── Unidades systemd propias ──
        $units = static fn(array $inv) => array_column($inv['services']['custom_units'] ?? [], 'exec', 'unit');
        [$mu, $su] = [$units($m), $units($s)];
        foreach ($mu as $u => $exec) {
            if (!array_key_exists($u, $su)) {
                $diff[] = "systemd: falta la unidad propia '{$u}' en el nodo.";
            } elseif ((string)$su[$u] !== (string)$exec) {
                $diff[] = "systemd: la unidad '{$u}' ejecuta algo distinto.";
            }
        }
        foreach (array_diff_key($su, $mu) as $u => $_) {
            $extra[] = "systemd: la unidad '{$u}' solo existe en el nodo.";
        }
        $checked[] = 'systemd: ' . count($mu) . ' unidades propias';

        // ── Cron (desactivado en el slave = presente) ──
        $cron = static function (array $inv): array {
            $o = [];
            foreach (($inv['cron']['entries'] ?? []) as $e) {
                $src = (string)$e['source'];
                if (!str_starts_with($src, 'crontab:')) {
                    $src = 'cron.d/' . preg_replace('/\.(disabled|musedock-off)$/', '', basename($src));
                }
                $o[$src . ' → ' . preg_replace('/\s+/', ' ', trim((string)$e['line']))] = true;
            }
            return $o;
        };
        [$mc, $sc] = [$cron($m), $cron($s)];
        foreach (array_diff_key($mc, $sc) as $k => $_) {
            $diff[] = "Cron: falta en el nodo: {$k}";
        }
        foreach (array_diff_key($sc, $mc) as $k => $_) {
            $extra[] = "Cron: solo en el nodo: {$k}";
        }
        $checked[] = 'cron: ' . count($mc) . ' tareas';

        // ── Webs del Caddyfile fuera del panel ──
        $sites = static function (array $inv): array {
            $o = [];
            foreach (($inv['sites']['caddyfile_sites'] ?? []) as $site) {
                if (($site['kind'] ?? '') === 'panel') {
                    continue;
                }
                foreach (($site['labels'] ?? []) as $l) {
                    $o[strtolower((string)$l)] = true;
                }
            }
            return $o;
        };
        [$mw, $sw] = [$sites($m), $sites($s)];
        foreach (array_diff_key($mw, $sw) as $l => $_) {
            $diff[] = "Caddyfile: falta la web '{$l}' en el nodo.";
        }
        foreach (array_diff_key($sw, $mw) as $l => $_) {
            $extra[] = "Caddyfile: la web '{$l}' solo existe en el nodo.";
        }
        $checked[] = 'Caddyfile: ' . count($mw) . ' webs';

        // ── PHP ──
        $mp = $m['runtime']['php'] ?? [];
        $sp = $s['runtime']['php'] ?? [];
        foreach ($mp as $ver => $info) {
            if (!isset($sp[$ver])) {
                $diff[] = "PHP: falta la versión {$ver} en el nodo.";
                continue;
            }
            $missing = array_diff((array)($info['extensions'] ?? []), (array)($sp[$ver]['extensions'] ?? []));
            if ($missing) {
                $diff[] = "PHP {$ver}: faltan extensiones en el nodo: " . implode(', ', $missing) . '.';
            }
        }
        $checked[] = 'PHP: ' . implode(', ', array_keys($mp));

        // ── Node / npm global / Composer ──
        $mn = (string)($m['runtime']['node'] ?? '');
        $sn = (string)($s['runtime']['node'] ?? '');
        if ($mn !== '' && $sn === '') {
            $diff[] = "Node: el master tiene {$mn} y el nodo no tiene Node.";
        } elseif ($mn !== '' && preg_replace('/^v(\d+).*/', '$1', $mn) !== preg_replace('/^v(\d+).*/', '$1', $sn)) {
            $diff[] = "Node: versión mayor distinta (master {$mn}, nodo {$sn}).";
        }
        // Los paquetes npm globales suelen ser herramientas de quien administra (no de la
        // app): se informan como nota, no como diferencia que bloquee.
        $npm = array_diff((array)($m['runtime']['npm_global'] ?? []), (array)($s['runtime']['npm_global'] ?? []));
        if ($npm) {
            $notes[] = 'npm global (herramientas, no suele afectar a las apps): faltan en el nodo: ' . implode(', ', $npm) . '.';
        }
        // `php` a secas (crons, scripts, Composer) usa la versión por defecto del
        // sistema: si difiere, tras un relevo los mismos crons corren con otro PHP.
        $mcli = (string)($m['runtime']['php_default_cli'] ?? '');
        $scli = (string)($s['runtime']['php_default_cli'] ?? '');
        if ($mcli !== '' && $scli !== '' && $mcli !== $scli) {
            $diff[] = "PHP por defecto distinto (master {$mcli}, nodo {$scli}): los crons y scripts que llaman a `php` correrían con otra versión tras un relevo. "
                . "En el nodo: update-alternatives --set php /usr/bin/php{$mcli}";
        }
        if (!empty($m['runtime']['composer']) && empty($s['runtime']['composer'])) {
            $diff[] = in_array('composer', (array)($s['runtime']['apt_manual'] ?? []), true)
                ? "Composer: está instalado en el nodo pero `composer --version` falla (PHP por defecto del nodo: " . ($scli ?: '?') . ')'
                : 'Composer: el nodo no lo tiene.';
        }
        $checked[] = 'Node/npm/Composer';

        // ── Paquetes relevantes ──
        $pk = static fn(array $inv) => array_values(array_filter((array)($inv['runtime']['apt_manual'] ?? []),
            static fn($p) => is_string($p) && preg_match(self::RELEVANT_PKG_RE, $p)));
        $missingPkgs = array_values(array_diff($pk($m), $pk($s)));
        if ($missingPkgs) {
            $diff[] = 'Paquetes: faltan en el nodo: ' . implode(', ', array_slice($missingPkgs, 0, 40)) . (count($missingPkgs) > 40 ? '…' : '') . '.';
        }
        $checked[] = 'paquetes relevantes (php, node, redis, postgresql, supervisor, imagemagick, ffmpeg…)';

        return ['missing_or_different' => $diff, 'only_on_node' => $extra, 'notes' => $notes, 'checked' => $checked];
    }
}
