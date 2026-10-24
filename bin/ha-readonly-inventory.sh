#!/bin/bash
# Solo lectura. Ejecutar en cada nodo. No lee .env, tokens, mensajes ni credenciales.
set -u
export LC_ALL=C
printf '\n=== Host / reloj / recursos ===\n'
date -u
hostname
uptime
lscpu | sed -n '1,18p'
free -h
df -hT / /var/lib/postgresql /var/www/vhosts 2>/dev/null
lsblk -o NAME,TYPE,SIZE,ROTA,MOUNTPOINTS
printf '\n=== Versiones ===\n'
php -v 2>/dev/null | head -2
php -m 2>/dev/null
node --version 2>/dev/null
redis-server --version 2>/dev/null
caddy version 2>/dev/null
pg_lsclusters 2>/dev/null
printf '\n=== Servicios (no se modifica ninguno) ===\n'
for unit in caddy redis-server supervisor musedock-panel musedock-boot-guard musedock-stale-master-check lsyncd; do
    systemctl show "$unit" -p Id -p LoadState -p ActiveState -p UnitFileState -p Type -p Result -p ExecMainStatus -p Before -p After --no-pager 2>/dev/null
done
printf '\n=== PostgreSQL: estado, lag y extensiones instaladas ===\n'
if command -v pg_lsclusters >/dev/null; then
    while read -r ver cluster port status rest; do
        printf '\nCluster %s/%s puerto %s\n' "$ver" "$cluster" "$port"
        runuser -u postgres -- psql -X -p "$port" -d postgres -P pager=off <<'SQL'
BEGIN READ ONLY;
SELECT version(), pg_is_in_recovery();
SELECT CASE WHEN pg_is_in_recovery() THEN pg_last_wal_receive_lsn() END AS receive_lsn,
       CASE WHEN pg_is_in_recovery() THEN pg_last_wal_replay_lsn() END AS replay_lsn,
       CASE WHEN pg_is_in_recovery() THEN now()-pg_last_xact_replay_timestamp() END AS last_replay_age;
SELECT status, received_tli, latest_end_lsn, latest_end_time FROM pg_stat_wal_receiver;
SELECT application_name, state, sync_state, sent_lsn, write_lsn, flush_lsn, replay_lsn,
       pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS replay_gap_bytes
FROM pg_stat_replication WHERE NOT pg_is_in_recovery();
SELECT name, default_version, installed_version FROM pg_available_extensions
WHERE name IN ('vector','pg_trgm','btree_gist');
SHOW wal_log_hints;
SHOW synchronous_standby_names;
COMMIT;
SQL
    done < <(pg_lsclusters --no-header)
fi
printf '\n=== Redis (si permite acceso local sin credenciales) ===\n'
if command -v redis-cli >/dev/null; then
    redis-cli INFO replication 2>/dev/null
    redis-cli INFO persistence 2>/dev/null
    redis-cli INFO memory 2>/dev/null | sed -n '/^used_memory_human:/p;/^maxmemory:/p;/^maxmemory_policy:/p'
fi
printf '\n=== Reloj sincronizado ===\n'
timedatectl show -p NTPSynchronized -p Timezone 2>/dev/null
