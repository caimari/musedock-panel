#!/bin/bash
# ============================================================
# MuseDock Panel — Automated Installer
# Supported: Ubuntu 22.04/24.04, Debian 12
# Run as root: sudo bash install.sh
# ============================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'
INSTALL_LOG="/tmp/musedock-panel-install.log"
: > "$INSTALL_LOG" 2>/dev/null || true
INSTALL_START_TS=$(date +%s)
STEP_START_TS=$INSTALL_START_TS
STEP_TITLE=""
STEP_TIMER_PID=""

format_duration() {
    local seconds="${1:-0}"
    local minutes=$((seconds / 60))
    local rest=$((seconds % 60))
    if [ "$minutes" -gt 0 ]; then
        printf "%dm%02ds" "$minutes" "$rest"
    else
        printf "%ds" "$rest"
    fi
}

elapsed_step() {
    local now
    now=$(date +%s)
    format_duration $((now - STEP_START_TS))
}

elapsed_total() {
    local now
    now=$(date +%s)
    format_duration $((now - INSTALL_START_TS))
}

stop_step_timer() {
    if [ -n "${STEP_TIMER_PID:-}" ]; then
        kill "$STEP_TIMER_PID" 2>/dev/null || true
        wait "$STEP_TIMER_PID" 2>/dev/null || true
        STEP_TIMER_PID=""
    fi
}

start_step_timer() {
    local title="$1"
    (
        while true; do
            sleep 30 || exit 0
            now=""
            elapsed=""
            total=""
            now=$(date +%s)
            elapsed=$(format_duration $((now - STEP_START_TS)))
            total=$(format_duration $((now - INSTALL_START_TS)))
            echo -e "  ${CYAN}…${NC} ${title} sigue en curso (${elapsed}; total ${total})"
        done
    ) &
    STEP_TIMER_PID=$!
}

header() {
    stop_step_timer
    STEP_TITLE="$1"
    STEP_START_TS=$(date +%s)
    echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}"
    echo -e "${CYAN}Inicio: $(date '+%Y-%m-%d %H:%M:%S') · total $(elapsed_total)${NC}\n"
    start_step_timer "$1"
}
prompt_read() {
    local prompt="$1"
    local var_name="$2"
    local resume_timer=false
    if [ -n "${STEP_TIMER_PID:-}" ]; then
        resume_timer=true
        stop_step_timer
    fi
    read -rp "$prompt" "$var_name"
    local rc=$?
    if [ "$resume_timer" = true ] && [ -n "${STEP_TITLE:-}" ]; then
        start_step_timer "$STEP_TITLE"
    fi
    return "$rc"
}

prompt_read_secret() {
    local prompt="$1"
    local var_name="$2"
    local resume_timer=false
    if [ -n "${STEP_TIMER_PID:-}" ]; then
        resume_timer=true
        stop_step_timer
    fi
    read -rsp "$prompt" "$var_name"
    local rc=$?
    if [ "$resume_timer" = true ] && [ -n "${STEP_TITLE:-}" ]; then
        start_step_timer "$STEP_TITLE"
    fi
    return "$rc"
}

install_caddy_backup_cron() {
    if [ ! -x "${PANEL_DIR}/bin/backup-caddy-config.sh" ]; then
        return 0
    fi

    mkdir -p "${PANEL_DIR}/storage/backups/caddy" "${PANEL_DIR}/storage/logs" 2>/dev/null || true
    chown root:root "${PANEL_DIR}/storage/backups/caddy" 2>/dev/null || true
    chmod 0750 "${PANEL_DIR}/storage/backups/caddy" 2>/dev/null || true
    cat > /etc/cron.d/musedock-caddy-backup << CRONEOF
# MuseDock Panel — Daily Caddy config backup (15d rotation + last-known-good)
17 3 * * * root ${PANEL_DIR}/bin/backup-caddy-config.sh >> ${PANEL_DIR}/storage/logs/caddy-backup.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-caddy-backup
}

run_caddy_config_snapshot() {
    if [ -x "${PANEL_DIR}/bin/backup-caddy-config.sh" ]; then
        "${PANEL_DIR}/bin/backup-caddy-config.sh" >> "${PANEL_DIR}/storage/logs/caddy-backup.log" 2>&1 || true
    fi
}

install_caddy_runtime_repair_override() {
    if ! command -v systemctl >/dev/null 2>&1 || ! systemctl list-unit-files caddy.service >/dev/null 2>&1; then
        return 0
    fi
    if [ ! -f "${PANEL_DIR}/cli/repair-caddy-routes.php" ]; then
        return 0
    fi

    mkdir -p /etc/systemd/system/caddy.service.d
    cat > /etc/systemd/system/caddy.service.d/zz-musedock-panel-repair.conf << OVERRIDEEOF
[Service]
ExecStartPost=/bin/sleep 5
ExecStartPost=/usr/bin/php ${PANEL_DIR}/cli/repair-caddy-routes.php
ExecReload=/bin/sleep 5
ExecReload=/usr/bin/php ${PANEL_DIR}/cli/repair-caddy-routes.php
OVERRIDEEOF
    chmod 644 /etc/systemd/system/caddy.service.d/zz-musedock-panel-repair.conf
    systemctl daemon-reload 2>/dev/null || true
}

ok()     { echo -e "  ${GREEN}✓${NC} [$(elapsed_step)] $1"; }
warn()   { echo -e "  ${YELLOW}!${NC} [$(elapsed_step)] $1"; }
fail()   { stop_step_timer; echo -e "  ${RED}✗${NC} [$(elapsed_step)] $1"; exit 1; }
ask()    { prompt_read "  $1: " "$2"; }

on_unhandled_error() {
    local rc=$?
    local line="${1:-?}"
    stop_step_timer
    echo ""
    echo -e "  ${RED}✗ Instalador detenido en linea ${line} (exit ${rc}).${NC}"
    if [ -s "$INSTALL_LOG" ]; then
        echo -e "  ${YELLOW}Ultimas lineas de ${INSTALL_LOG}:${NC}"
        tail -n 40 "$INSTALL_LOG" | sed 's/^/    /'
    fi
    echo -e "  ${YELLOW}Reintenta con: bash -x install.sh${NC}"
}

read_env_value() {
    local key="$1"
    grep -E "^${key}=" "${PANEL_DIR}/.env" 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

quick_pg_port_ready() {
    local port="${1:-5433}"
    if command -v pg_isready >/dev/null 2>&1; then
        pg_isready -h 127.0.0.1 -p "$port" -t 1 >/dev/null 2>&1 && return 0
    fi
    ss -tln 2>/dev/null | grep -qE "127\\.0\\.0\\.1:${port}\\b|\\*:${port}\\b|0\\.0\\.0\\.0:${port}\\b|\\[::\\]:${port}\\b"
}

wait_quick_pg_port_ready() {
    local port="${1:-5433}"
    local max_wait="${2:-5}"
    local i
    for i in $(seq 1 "$max_wait"); do
        if quick_pg_port_ready "$port"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

preflight_existing_panel_services() {
    [ -f "${PANEL_DIR}/.env" ] || return 0
    command -v pg_lsclusters >/dev/null 2>&1 || return 0
    command -v pg_ctlcluster >/dev/null 2>&1 || return 0

    local env_db_port pg_line pg_ver pg_cluster pg_port pg_status
    env_db_port=$(read_env_value DB_PORT)
    env_db_port=${env_db_port:-5433}

    if [ "$env_db_port" != "5433" ]; then
        return 0
    fi

    pg_line=$(pg_lsclusters -h 2>/dev/null | awk '$2=="panel" {print $1" "$2" "$3" "$4; exit}')
    if [ -z "$pg_line" ]; then
        return 0
    fi

    read -r pg_ver pg_cluster pg_port pg_status <<< "$pg_line"
    if [ "$pg_port" != "5433" ]; then
        warn "Preflight: cluster PostgreSQL 'panel' existe en puerto ${pg_port}, pero .env usa 5433"
        return 0
    fi

    if quick_pg_port_ready 5433; then
        ok "Preflight: PostgreSQL panel responde en 5433"
        return 0
    fi

    warn "Preflight: PostgreSQL panel no responde en 5433; intentando recuperar cluster ${pg_ver}/panel"
    if [ "$pg_status" = "online" ]; then
        timeout 20 pg_ctlcluster "$pg_ver" panel restart >/dev/null 2>&1 || true
    else
        timeout 20 pg_ctlcluster "$pg_ver" panel start >/dev/null 2>&1 || true
    fi

    if wait_quick_pg_port_ready 5433 5; then
        ok "Preflight: PostgreSQL panel recuperado en 5433"
    else
        warn "Preflight: PostgreSQL panel sigue sin responder; el paso PostgreSQL mostrara diagnostico completo"
    fi
}

panel_looks_operational() {
    [ -f "${PANEL_DIR}/.env" ] || return 1

    local env_panel_port env_internal_port internal_code https_code db_host db_port db_name db_user db_pass
    env_panel_port=$(read_env_value PANEL_PORT)
    env_panel_port=${env_panel_port:-8444}
    env_internal_port=$(read_env_value PANEL_INTERNAL_PORT)
    env_internal_port=${env_internal_port:-$((env_panel_port + 1))}

    systemctl is-active --quiet musedock-panel 2>/dev/null || return 1
    systemctl is-active --quiet caddy 2>/dev/null || return 1

    if ! ss -tln 2>/dev/null | grep -qE "127\\.0\\.0\\.1:${env_internal_port}\\b"; then
        return 1
    fi
    if ! ss -tln 2>/dev/null | grep -qE "\\*:${env_panel_port}\\b|0\\.0\\.0\\.0:${env_panel_port}\\b|\\[::\\]:${env_panel_port}\\b"; then
        return 1
    fi

    internal_code=$(curl -sk -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 3 "http://127.0.0.1:${env_internal_port}/" 2>/dev/null || echo "000")
    internal_code=$(echo "$internal_code" | tr -d '[:space:]')
    https_code=$(curl -sk -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 3 "https://127.0.0.1:${env_panel_port}/" 2>/dev/null || echo "000")
    https_code=$(echo "$https_code" | tr -d '[:space:]')
    case "$internal_code:$https_code" in
        2*:2*|2*:3*|2*:403|3*:2*|3*:3*|3*:403|403:2*|403:3*|403:403) ;;
        *) return 1 ;;
    esac

    db_host=$(read_env_value DB_HOST)
    db_port=$(read_env_value DB_PORT)
    db_name=$(read_env_value DB_NAME)
    db_user=$(read_env_value DB_USER)
    db_pass=$(read_env_value DB_PASS)
    db_host=${db_host:-127.0.0.1}
    db_port=${db_port:-5433}
    db_name=${db_name:-musedock_panel}
    db_user=${db_user:-musedock_panel}
    if command -v psql >/dev/null 2>&1; then
        PGPASSWORD="$db_pass" PGCONNECT_TIMEOUT=3 psql -h "$db_host" -p "$db_port" -U "$db_user" -d "$db_name" -tAc "SELECT 1" >/dev/null 2>&1 || return 1
    fi

    return 0
}
trap 'on_unhandled_error $LINENO' ERR
trap 'stop_step_timer' EXIT

install_ondrej_php_repo_manual() {
    local codename key_url keyring source_file
    codename="$(lsb_release -sc)"
    key_url="https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6"
    keyring="/usr/share/keyrings/ondrej-php.gpg"
    source_file="/etc/apt/sources.list.d/ondrej-php.list"

    warn "add-apt-repository fallo o tardo demasiado; usando fallback directo ppa.launchpadcontent.net"

    if ! curl -fsSL --connect-timeout 15 --max-time 45 "$key_url" | gpg --dearmor > "$keyring" 2>> "$PHP_INSTALL_LOG"; then
        return 1
    fi
    chmod 0644 "$keyring" 2>/dev/null || true
    echo "deb [signed-by=${keyring}] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${codename} main" > "$source_file"
    return 0
}

# ============================================================
# Language selection (before anything else)
# ============================================================
LANG_CODE="en"

# Must be root (check before language prompt)
if [ "$EUID" -ne 0 ]; then
    echo -e "  \033[0;31m✗ This installer must be run as root (sudo bash install.sh)\033[0m"
    exit 1
fi

# Resolve PANEL_DIR early (needed for .env check)
PANEL_DIR="$(cd "$(dirname "$0")" && pwd)"

echo ""
echo "  Select language / Selecciona idioma:"
echo ""
echo "    1) English"
echo "    2) Español"
echo ""
prompt_read "  Choose / Elige [1/2] (default: 2): " LANG_CHOICE
LANG_CHOICE=${LANG_CHOICE:-2}
case "$LANG_CHOICE" in
    1) LANG_CODE="en" ;;
    *) LANG_CODE="es" ;;
esac

# ============================================================
# Translation system
# ============================================================
t() {
    local key="$1"; shift
    local text=""
    case "$LANG_CODE" in
        es)
            case "$key" in
                must_be_root) text="Este instalador debe ejecutarse como root (sudo bash install.sh)" ;;
                cannot_detect_os) text="No se detecta el sistema operativo. Solo Ubuntu 22.04+ y Debian 12+ soportados." ;;
                os_not_supported) text="$1 $2 no soportado. Minimo: $3" ;;
                os_unknown) text="OS '$1' no soportado. Solo Ubuntu 22.04+ y Debian 12+ soportados." ;;
                existing_detected) text="Se ha detectado una instalacion existente." ;;
                existing_found) text="Encontrado: $1" ;;
                existing_options) text="Opciones:" ;;
                existing_opt_reinstall) text="Reinstalar (no borra la base de datos)" ;;
                existing_opt_verify) text="Solo verificar — ejecuta el health check sin tocar nada" ;;
                existing_opt_update) text="Actualizar — aplica cambios nuevos sin reinstalar (crons, BD, permisos)" ;;
                existing_opt_cancel) text="Cancelar" ;;
                existing_choose) text="Elige [1/2/3/4/5] (por defecto: 4): " ;;
                install_cancelled) text="Instalacion cancelada. Instalacion existente preservada." ;;
                reinstall_mode) text="Modo reinstalacion — se hara backup del .env existente" ;;
                update_mode) text="Modo actualizacion — aplicando cambios sin reinstalar..." ;;
                update_dirs) text="Directorios verificados" ;;
                update_schema) text="Esquema de BD actualizado" ;;
                update_migrations) text="Migraciones ejecutadas" ;;
                update_crons) text="Crons del sistema actualizados" ;;
                update_perms) text="Permisos corregidos" ;;
                update_service) text="Servicio reiniciado" ;;
                update_env_new_keys) text="Nuevas claves añadidas al .env" ;;
                update_complete) text="Actualizacion completada!" ;;
                update_no_new_keys) text=".env ya tiene todas las claves" ;;
                existing_opt_repair) text="Reparar — diagnostica y corrige problemas del panel" ;;
                repair_mode) text="Modo reparacion — diagnosticando y reparando..." ;;
                repair_complete) text="Reparacion completada!" ;;
                repair_check_php) text="Verificando sintaxis PHP" ;;
                repair_php_ok) text="Todos los archivos PHP sin errores" ;;
                repair_php_error) text="Error de sintaxis en: $1" ;;
                repair_check_service) text="Verificando servicio del panel" ;;
                repair_service_not_found) text="Servicio musedock-panel no encontrado — reinstalando" ;;
                repair_service_dead) text="Servicio muerto — reiniciando" ;;
                repair_service_ok) text="Servicio activo" ;;
                repair_check_db) text="Verificando conexion a base de datos" ;;
                repair_db_ok) text="Base de datos accesible" ;;
                repair_db_fail) text="No se puede conectar a la base de datos" ;;
                repair_check_caddy) text="Verificando Caddy" ;;
                repair_caddy_ok) text="Caddy activo y respondiendo" ;;
                repair_caddy_dead) text="Caddy no esta corriendo — reiniciando" ;;
                repair_check_perms) text="Verificando permisos" ;;
                repair_perms_fixed) text="Permisos corregidos" ;;
                repair_check_dirs) text="Verificando directorios" ;;
                repair_dirs_ok) text="Directorios verificados" ;;
                repair_check_env) text="Verificando .env" ;;
                repair_env_ok) text=".env presente y legible" ;;
                repair_env_missing) text=".env no encontrado — no se puede reparar sin el" ;;
                repair_check_port) text="Verificando puerto del panel" ;;
                repair_port_ok) text="Panel respondiendo en puerto $1" ;;
                repair_port_fail) text="Panel NO responde en puerto $1" ;;
                repair_check_crons) text="Verificando crons" ;;
                repair_crons_ok) text="Crons del sistema presentes" ;;
                repair_crons_missing) text="Crons faltantes — reinstalando" ;;
                repair_fixed) text="Reparado" ;;
                repair_summary_ok) text="Sin problemas detectados" ;;
                repair_summary_fixed) text="$1 problema(s) reparado(s)" ;;
                repair_summary_fail) text="$1 problema(s) no reparables — revisa manualmente" ;;
                verify_mode) text="Modo verificacion — comprobando estado del sistema..." ;;
                checking_conflicts) text="Comprobando servicios en conflicto" ;;
                plesk_warning) text="AVISO: Plesk detectado en este servidor!" ;;
                plesk_version) text="Version de Plesk: $1" ;;
                plesk_desc) text="Plesk gestiona su propio servidor web (nginx/Apache), bases de datos y PHP. Instalar MuseDock Panel junto a Plesk puede causar conflictos de puertos e interferencia de servicios." ;;
                plesk_opt_abort) text="Abortar instalacion (recomendado)" ;;
                plesk_opt_continue) text="Continuar igualmente (solo usuarios avanzados — debes resolver conflictos manualmente)" ;;
                plesk_aborted) text="Instalacion abortada. Plesk y MuseDock Panel no son compatibles en el mismo servidor." ;;
                plesk_continuing) text="Continuando con Plesk presente — resolucion manual de conflictos requerida" ;;
                nginx_detected) text="Nginx detectado: $1" ;;
                nginx_plesk_managed) text="Este nginx esta gestionado por Plesk — NO lo desactives sin Plesk" ;;
                listening_ports) text="Escuchando en puertos: $1" ;;
                caddy_port_conflict) text="Caddy necesita puertos 80/443 — esto causara conflicto." ;;
                no_port_conflict) text="$1 esta instalado pero NO escucha en puertos 80/443" ;;
                no_conflict_caddy) text="Sin conflicto con Caddy." ;;
                opt_stop_disable) text="Parar y desactivar $1 permanentemente (recomendado — libera puertos para Caddy)" ;;
                opt_keep_running) text="Mantener $1 corriendo (debes reconfigurar puertos manualmente)" ;;
                opt_abort) text="Abortar instalacion" ;;
                config_backed_up) text="Config de $1 guardada en $2" ;;
                stopped_disabled) text="$1 parado y desactivado (no arrancara en reboot)" ;;
                to_reenable) text="Para reactivar: systemctl enable --now $1" ;;
                keep_running_warn) text="$1 sigue corriendo — DEBES moverlo de puertos 80/443 antes de que Caddy arranque" ;;
                caddy_fail_bind) text="Si no, Caddy no podra bindear y los sitios no funcionaran" ;;
                plesk_managed_warn) text="$1 esta gestionado por Plesk — no se puede desactivar automaticamente" ;;
                plesk_resolve_manual) text="Debes resolver el conflicto de puertos manualmente o quitar Plesk primero" ;;
                continue_anyway) text="Continuar igualmente? [s/N] " ;;
                installation_aborted) text="Instalacion abortada." ;;
                apache_detected) text="Apache detectado: $1" ;;
                apache_plesk_managed) text="Este Apache esta gestionado por Plesk — NO lo desactives sin Plesk" ;;
                no_conflicts) text="No se detectaron servicios en conflicto (no nginx, no Apache, no Plesk)" ;;
                caddy_already_running) text="Caddy ya esta corriendo en puertos 80/443" ;;
                caddy_already_desc) text="Este servidor ya tiene Caddy sirviendo sitios. El panel se integrara con el Caddy existente." ;;
                caddy_has_routes) text="Caddy tiene $1 ruta(s) activa(s)" ;;
                nginx_not_on_ports_caddy) text="Nginx no escucha en 80/443 porque Caddy ya los ocupa" ;;
                apache_not_on_ports_caddy) text="Apache no escucha en 80/443 porque Caddy ya los ocupa" ;;
                migrate_header) text="Migrando sitios de $1 a Caddy" ;;
                migrate_parsing) text="Parseando $1..." ;;
                migrate_found) text="Encontrado: $1 → $2" ;;
                migrate_skipped) text="Omitido (sin server_name): $1" ;;
                migrate_skipped_caddy) text="Omitido $1 — ya configurado en Caddyfile" ;;
                migrate_none) text="No se encontraron sitios para migrar en $1" ;;
                migrate_ask) text="Migrar $1 sitio(s) de $2 a Caddy? [S/n] " ;;
                migrate_saved) text="Sitios migrados guardados en $1 (se aplicaran tras instalar Caddy)" ;;
                migrate_skip_user) text="Migracion omitida por el usuario" ;;
                migrate_applying) text="Aplicando $1 sitio(s) migrado(s) a Caddy..." ;;
                migrate_route_ok) text="Ruta Caddy creada: $1" ;;
                migrate_route_fail) text="Fallo al crear ruta Caddy para $1 (HTTP $2)" ;;
                migrate_complete) text="Migracion completada: $1/$2 sitios migrados a Caddy" ;;
                configuration) text="Configuracion" ;;
                panel_port_prompt) text="Puerto del panel (por defecto: 8444)" ;;
                php_version) text="Version de PHP" ;;
                php_available) text="Disponibles: 8.1, 8.2, 8.3, 8.4" ;;
                php_version_prompt) text="Version de PHP (por defecto: 8.3)" ;;
                php_invalid) text="Version de PHP invalida. Usando 8.3" ;;
                summary) text="Resumen:" ;;
                summary_port) text="Puerto panel:  $1" ;;
                summary_php) text="Version PHP:   $1" ;;
                summary_dir) text="Directorio:    $1" ;;
                summary_admin) text="Admin setup:   via asistente web en primer acceso" ;;
                proceed_install) text="Proceder con la instalacion? [S/n] " ;;
                snapshot_header) text="Creando snapshot pre-instalacion" ;;
                snapshot_services) text="Servicios activos guardados" ;;
                snapshot_ports) text="Puertos escuchando guardados" ;;
                snapshot_caddyfile) text="Caddyfile guardado" ;;
                snapshot_autosave) text="Caddy autosave.json guardado" ;;
                snapshot_nginx) text="Config de nginx guardada" ;;
                snapshot_apache) text="Config de Apache guardada" ;;
                snapshot_pghba) text="pg_hba.conf guardado" ;;
                snapshot_env) text=".env existente guardado" ;;
                snapshot_packages) text="Lista de paquetes guardada" ;;
                snapshot_saved) text="Snapshot guardado en $1" ;;
                step_packages) text="Paso 1/7 — Instalando paquetes del sistema" ;;
                pkg_updated) text="Listas de paquetes actualizadas" ;;
                pkg_installed) text="Paquetes esenciales instalados" ;;
                step_php) text="Paso 2/7 — Instalando PHP $1" ;;
                php_repo_added) text="Repositorio PHP añadido" ;;
                php_repo_exists) text="Repositorio PHP ya configurado" ;;
                php_installed) text="PHP $1 + extensiones instaladas" ;;
                php_fpm_started) text="PHP-FPM $1 iniciado" ;;
                step_pgsql) text="Paso 3/7 — Instalando PostgreSQL" ;;
                pgsql_installed) text="PostgreSQL instalado" ;;
                pgsql_exists) text="PostgreSQL ya instalado" ;;
                pgsql_running) text="PostgreSQL en ejecucion" ;;
                pgsql_reusing) text="Reutilizando credenciales de base de datos existentes" ;;
                pgsql_no_pass) text="No se pudo leer la contraseña de BD existente — generando nueva" ;;
                pgsql_peer_fail) text="La autenticacion peer de PostgreSQL no esta disponible." ;;
                pgsql_peer_desc) text="Esto puede ocurrir si pg_hba.conf usa md5/scram-sha-256 para conexiones locales, o si el usuario del sistema 'postgres' no tiene acceso directo." ;;
                pgsql_empty_pass) text="Conectado a PostgreSQL con contraseña por defecto (vacia)" ;;
                pgsql_enter_pass) text="Introduce la contraseña del superusuario PostgreSQL (postgres):" ;;
                pgsql_pass_verified) text="Contraseña de PostgreSQL verificada" ;;
                pgsql_cannot_connect) text="No se puede conectar a PostgreSQL. Revisa la contraseña y la configuracion de pg_hba.conf." ;;
                pgsql_hba_updated) text="pg_hba.conf actualizado para acceso del usuario del panel" ;;
                pgsql_db_created) text="Base de datos '$1' creada (usuario: $2)" ;;
                step_caddy) text="Paso 4/7 — Instalando y configurando Caddy" ;;
                caddy_exists) text="Caddy ya instalado" ;;
                caddy_config_detected) text="Configuracion de Caddy existente detectada:" ;;
                caddy_api_routes) text="$1 rutas API activas" ;;
                caddy_caddyfile_domains) text="Caddyfile tiene bloques de dominio ($1 entradas)" ;;
                caddy_autosave_found) text="autosave.json encontrado (rutas persistidas por API)" ;;
                caddy_may_be_cms) text="Puede ser MuseDock CMS u otra aplicacion." ;;
                caddy_opt_integrate) text="Integrar — usar Caddy existente, preservar TODA la config (recomendado)" ;;
                caddy_opt_reconfigure) text="Reconfigurar — sobreescribir Caddyfile (ATENCION: puede romper sitios existentes)" ;;
                caddy_integrated) text="Integrado con Caddy existente (toda la config preservada)" ;;
                caddy_admin_added) text="API admin añadida al Caddyfile existente (backup creado)" ;;
                caddy_resume_added) text="Flag --resume añadido (las rutas persisten entre reinicios)" ;;
                caddy_resume_exists) text="Flag --resume ya configurado" ;;
                caddy_reconfiguring) text="Reconfigurando Caddy — creando backups" ;;
                caddy_backed_up) text="Caddyfile guardado en $1" ;;
                caddy_autosave_backed) text="autosave.json guardado en $1" ;;
                caddy_reconfigured) text="Caddyfile reconfigurado" ;;
                caddy_restarted) text="Caddy reiniciado con nueva configuracion" ;;
                caddy_configured) text="Caddyfile configurado con API admin" ;;
                caddy_admin_exists) text="Caddyfile ya tiene API admin habilitada" ;;
                caddy_running_resume) text="Caddy en ejecucion con --resume" ;;
                caddy_installed) text="Caddy instalado" ;;
                caddy_running_persist) text="Caddy en ejecucion con --resume (rutas persisten entre reinicios)" ;;
                caddy_api_ok) text="API de Caddy accesible en localhost:2019" ;;
                caddy_api_wait) text="API de Caddy no responde aun (HTTP $1) — puede necesitar un momento" ;;
                step_mysql) text="Paso 5/7 — Instalando MySQL" ;;
                mysql_installed) text="MySQL/MariaDB instalado" ;;
                mysql_exists) text="MySQL/MariaDB ya instalado" ;;
                mysql_running) text="MySQL en ejecucion" ;;
                mysql_socket_ok) text="MySQL root usa autenticacion por socket (no necesita contraseña)" ;;
                mysql_socket_access) text="MySQL root accesible via autenticacion por socket" ;;
                mysql_pass_recovered) text="Contraseña de MySQL root recuperada del .env existente" ;;
                mysql_needs_pass) text="El acceso root a MySQL requiere contraseña." ;;
                mysql_needs_pass_desc) text="El panel necesita acceso root a MySQL para crear bases de datos de clientes." ;;
                mysql_opt_enter) text="Introducir la contraseña de root de MySQL ahora" ;;
                mysql_opt_skip) text="Saltar — puedes configurarlo despues en .env (MYSQL_ROOT_PASS)" ;;
                mysql_attempt) text="Contraseña root MySQL (intento $1/$2): " ;;
                mysql_pass_verified) text="Contraseña de MySQL root verificada" ;;
                mysql_invalid_pass) text="Contraseña incorrecta" ;;
                mysql_auth_failed) text="No se pudo autenticar en MySQL — puedes poner MYSQL_ROOT_PASS en .env despues" ;;
                mysql_skipped) text="Contraseña de MySQL root omitida — pon MYSQL_ROOT_PASS en .env para crear BDs de clientes" ;;
                step_panel) text="Paso 6/7 — Configurando MuseDock Panel" ;;
                dirs_created) text="Directorios creados" ;;
                env_backed_up) text=".env existente guardado" ;;
                env_created) text=".env creado (permisos: 600 — solo root)" ;;
                schema_applied) text="Esquema de base de datos aplicado (tablas existentes preservadas)" ;;
                permissions_set) text="Permisos establecidos" ;;
                step_service) text="Paso 7/7 — Configurando servicio systemd" ;;
                service_started) text="Servicio MuseDock Panel iniciado en puerto $1" ;;
                health_header) text="Health Check" ;;
                health_panel_running) text="Servicio panel: en ejecucion" ;;
                health_panel_down) text="Servicio panel: NO esta en ejecucion" ;;
                health_panel_fix) text="Fix: systemctl start musedock-panel" ;;
                health_panel_logs) text="Logs: journalctl -u musedock-panel --no-pager -n 20" ;;
                health_http_ok) text="Panel HTTP: respondiendo (HTTP $1) en puerto $2" ;;
                health_http_ip) text="Panel HTTP: respondiendo (HTTP 403 — restriccion IP activa) en puerto $1" ;;
                health_http_fail) text="Panel HTTP: NO responde (HTTP $1) en puerto $2" ;;
                health_http_fix) text="Fix: Comprueba que el servicio esta corriendo y el puerto no esta bloqueado" ;;
                health_pgsql_ok) text="PostgreSQL: conectado a $1 como $2" ;;
                health_pgsql_fail) text="PostgreSQL: no se puede conectar a $1 como $2" ;;
                health_pgsql_fix) text="Fix: Verifica que PostgreSQL esta corriendo y las credenciales son correctas" ;;
                health_mysql_ok) text="MySQL: conectado via autenticacion por $1" ;;
                health_mysql_fail) text="MySQL: fallo de autenticacion por $1" ;;
                health_mysql_skip) text="MySQL: omitido (autenticacion no configurada — pon MYSQL_ROOT_PASS en .env)" ;;
                health_caddy_ok) text="API Caddy: respondiendo en localhost:2019" ;;
                health_caddy_fail) text="API Caddy: NO responde (HTTP $1)" ;;
                health_caddy_fix) text="Fix: Verifica que Caddy esta corriendo" ;;
                health_caddy_ports) text="Puertos Caddy: escuchando en 80/443" ;;
                health_caddy_no_ports) text="Caddy: no escucha en 80/443 — los sitios pueden no ser accesibles" ;;
                health_fpm_ok) text="PHP-FPM $1: en ejecucion" ;;
                health_fpm_fail) text="PHP-FPM $1: NO esta en ejecucion" ;;
                health_all_ok) text="Todos los checks pasaron! ($1 errores)" ;;
                health_errors) text="Health check completado con $1 error(es)" ;;
                health_review) text="Revisa los errores arriba y corrigelos antes de usar el panel." ;;
                install_complete) text="Instalacion completada!" ;;
                next_step) text=">>> SIGUIENTE PASO: Crea tu cuenta de administrador <<<" ;;
                open_browser) text="Abre tu navegador y ve a:" ;;
                setup_wizard) text="El asistente de configuracion te guiara para crear el usuario y contraseña de admin." ;;
                save_credentials) text="GUARDA ESTAS CREDENCIALES (se muestran una sola vez):" ;;
                mysql_auth_label) text="MySQL auth: " ;;
                mysql_pass_stored) text="MySQL pass: (guardada en .env)" ;;
                mysql_pass_socket) text="MySQL pass: (no necesaria — auth por socket)" ;;
                mysql_pass_notset) text="MySQL pass: NO CONFIGURADA — edita .env despues" ;;
                stored_in_env) text="Tambien guardadas en .env (solo root)." ;;
                detection_summary) text="RESUMEN DE DETECCION DE SERVICIOS:" ;;
                plesk_detected_sum) text="Plesk: detectado (resolucion manual de conflictos)" ;;
                nginx_detected_sum) text="Nginx: detectado — $1" ;;
                apache_detected_sum) text="Apache: detectado — $1" ;;
                security_header) text="SEGURIDAD — IMPORTANTE:" ;;
                security_firewall) text="Protege el puerto del panel con un firewall." ;;
                security_root) text="El panel se ejecuta como root y tiene acceso total al sistema." ;;
                security_trusted) text="Solo IPs de administradores de confianza deben alcanzar el puerto $1." ;;
                security_ufw) text="# Ejemplo UFW — permitir solo tu IP:" ;;
                security_restrict) text="Restringe IPs en .env (capa adicional):" ;;
                security_admin_only) text="El puerto del panel es solo para administradores." ;;
                security_no_public) text="Nunca lo expongas a internet sin un firewall." ;;
                security_client_sites) text="Los sitios de clientes se sirven por Caddy en puertos 80/443." ;;
                service_mgmt) text="Gestion del servicio:" ;;
                snapshot_label) text="Snapshot pre-instalacion:" ;;
                snapshot_desc) text="(servicios, puertos, configs guardados antes de la instalacion)" ;;
                uninstall_label) text="Desinstalar:" ;;
                enjoy) text="Disfruta MuseDock Panel!" ;;
                next_steps_header) text="SIGUIENTES PASOS (desde el panel):" ;;
                next_steps_mail) text="Mail: Mail → Setup (local o nodo remoto)" ;;
                next_steps_cluster) text="Cluster: Settings → Cluster (anadir nodos)" ;;
                next_steps_replication) text="Replicacion: Settings → Replication (master/slave)" ;;
                next_steps_hint) text="Todo se configura desde el panel web — no necesitas volver a SSH." ;;
                firewall_header) text="ACCESO AL PANEL:" ;;
                firewall_ufw_active) text="UFW esta activo en este servidor." ;;
                firewall_iptables_active) text="iptables esta activo en este servidor (sin UFW)." ;;
                firewall_port_open) text="El puerto $1 esta ABIERTO — puedes acceder al panel." ;;
                firewall_port_closed) text="El puerto $1 esta BLOQUEADO por el firewall." ;;
                firewall_port_open_ip) text="El puerto $1 esta accesible desde IPs autorizadas (reglas ACCEPT all)." ;;
                firewall_open_cmd) text="Para abrir el acceso desde tu IP:" ;;
                firewall_no_detected) text="No se detecto firewall activo (UFW/iptables)." ;;
                firewall_consider) text="Considera activar un firewall para proteger el puerto $1." ;;
                firewall_access_url) text="Accede al panel en:" ;;
                firewall_make_sure) text="Asegurate de que el puerto $1 esta abierto para tu IP de administracion." ;;
                fw_setup_header) text="Proteccion del panel" ;;
                fw_setup_desc) text="El panel se ejecuta como root. Es CRITICO restringir el acceso al puerto $1." ;;
                fw_setup_ask) text="Configurar restriccion de IP ahora? (muy recomendado) [S/n] " ;;
                fw_setup_ip_prompt) text="IP o rango permitido (ej: 85.60.10.5 o 192.168.1.0/24)" ;;
                fw_setup_ip_another) text="Añadir otra IP? [s/N] " ;;
                fw_setup_ip_invalid) text="IP/rango no valido: $1" ;;
                fw_setup_applying_ufw) text="Aplicando reglas UFW..." ;;
                fw_setup_applying_ipt) text="Aplicando reglas iptables..." ;;
                fw_setup_installing_ufw) text="Instalando y activando UFW..." ;;
                fw_setup_ufw_rule) text="UFW: permitido $1 → puerto $2" ;;
                fw_setup_ufw_deny) text="UFW: denegado acceso general al puerto $1" ;;
                fw_setup_ipt_rule) text="iptables: permitido $1 → puerto $2" ;;
                fw_setup_ipt_deny) text="iptables: denegado acceso general al puerto $1" ;;
                fw_setup_ipt_saved) text="iptables: reglas guardadas (persistentes)" ;;
                fw_setup_env_ips) text="ALLOWED_IPS actualizado en .env: $1" ;;
                fw_setup_done) text="Panel protegido — solo accesible desde: $1" ;;
                fw_setup_skip) text="Omitido — RECUERDA proteger el puerto $1 cuanto antes!" ;;
                fw_setup_skip_warn) text="Sin proteccion, cualquiera puede acceder al panel como root." ;;
                fw_setup_detect_ufw) text="UFW detectado y activo" ;;
                fw_setup_detect_ufw_inactive) text="UFW instalado pero inactivo" ;;
                fw_setup_detect_ipt) text="iptables detectado (sin UFW)" ;;
                fw_setup_detect_ipt_open) text="iptables instalado, pero sin politica restrictiva activa" ;;
                fw_setup_detect_none) text="Sin firewall activo detectado" ;;
                fw_setup_selected) text="Modo firewall seleccionado: $1" ;;
                caddy_choose) text="Elige [1/2] (por defecto: 1): " ;;
                caddy_proxy_ok) text="Caddy HTTPS reverse proxy → https://0.0.0.0:$1 → 127.0.0.1:$2" ;;
                caddy_tls_internal) text="TLS interno (certificado autofirmado para acceso por IP)" ;;
                caddy_proxy_fail) text="Caddy/TLS no responde aun (HTTP $1); el panel queda disponible internamente en 127.0.0.1" ;;
                caddy_proxy_fallback) text="Panel accesible en http://IP:$1 (sin HTTPS)" ;;
                caddy_api_unavailable) text="API Caddy no disponible — panel en http://0.0.0.0:$1 (sin HTTPS)" ;;
                mysql_choose) text="Elige [1/2] (por defecto: 1): " ;;
                verify_complete) text="Verificacion completada" ;;
                verify_all_ok) text="Todo correcto! El panel esta funcionando correctamente." ;;
                verify_has_errors) text="Se encontraron $1 problema(s). Revisa arriba." ;;
                pgsql_detect_version) text="Detectando version de PostgreSQL..." ;;
                pgsql_version_found) text="PostgreSQL version $1 detectada" ;;
                pgsql_dual_header) text="Configurando doble cluster PostgreSQL..." ;;
                pgsql_cluster_exists) text="Cluster '$1' ya existe en puerto $2" ;;
                pgsql_cluster_created) text="Cluster '$1' creado en puerto $2" ;;
                pgsql_migrating) text="Migrando BD del panel de 5432 a 5433..." ;;
                pgsql_migration_backup) text="Backup de seguridad: $1" ;;
                pgsql_migration_done) text="BD del panel migrada exitosamente a puerto 5433" ;;
                pgsql_already_5433) text="El panel ya usa puerto 5433 — no hay que migrar" ;;
                pgsql_5432_free) text="Puerto 5432 disponible para proyectos y replica" ;;
                pgsql_panel_cluster) text="Cluster del panel: $1/$2 en puerto $3" ;;
                *) text="[$key]" ;;
            esac
            ;;
        *)
            case "$key" in
                must_be_root) text="This installer must be run as root (sudo bash install.sh)" ;;
                cannot_detect_os) text="Cannot detect OS. Only Ubuntu 22.04+ and Debian 12+ are supported." ;;
                os_not_supported) text="$1 $2 not supported. Minimum: $3" ;;
                os_unknown) text="OS '$1' not supported. Only Ubuntu 22.04+ and Debian 12+ are supported." ;;
                existing_detected) text="An existing installation has been detected." ;;
                existing_found) text="Found: $1" ;;
                existing_options) text="Options:" ;;
                existing_opt_reinstall) text="Reinstall (will NOT delete the database)" ;;
                existing_opt_verify) text="Verify only — run health check without changing anything" ;;
                existing_opt_update) text="Update — apply new changes without reinstalling (crons, DB, permissions)" ;;
                existing_opt_cancel) text="Cancel" ;;
                existing_choose) text="Choose [1/2/3/4/5] (default: 4): " ;;
                install_cancelled) text="Installation cancelled. Existing installation preserved." ;;
                reinstall_mode) text="Reinstall mode — existing .env will be backed up" ;;
                update_mode) text="Update mode — applying changes without reinstalling..." ;;
                update_dirs) text="Directories verified" ;;
                update_schema) text="Database schema updated" ;;
                update_migrations) text="Migrations executed" ;;
                update_crons) text="System crons updated" ;;
                update_perms) text="Permissions fixed" ;;
                update_service) text="Service restarted" ;;
                update_env_new_keys) text="New keys added to .env" ;;
                update_complete) text="Update completed!" ;;
                update_no_new_keys) text=".env already has all keys" ;;
                existing_opt_repair) text="Repair — diagnose and fix panel issues" ;;
                repair_mode) text="Repair mode — diagnosing and fixing..." ;;
                repair_complete) text="Repair completed!" ;;
                repair_check_php) text="Checking PHP syntax" ;;
                repair_php_ok) text="All PHP files have no errors" ;;
                repair_php_error) text="Syntax error in: $1" ;;
                repair_check_service) text="Checking panel service" ;;
                repair_service_not_found) text="musedock-panel service not found — reinstalling" ;;
                repair_service_dead) text="Service dead — restarting" ;;
                repair_service_ok) text="Service active" ;;
                repair_check_db) text="Checking database connection" ;;
                repair_db_ok) text="Database accessible" ;;
                repair_db_fail) text="Cannot connect to database" ;;
                repair_check_caddy) text="Checking Caddy" ;;
                repair_caddy_ok) text="Caddy active and responding" ;;
                repair_caddy_dead) text="Caddy not running — restarting" ;;
                repair_check_perms) text="Checking permissions" ;;
                repair_perms_fixed) text="Permissions fixed" ;;
                repair_check_dirs) text="Checking directories" ;;
                repair_dirs_ok) text="Directories verified" ;;
                repair_check_env) text="Checking .env" ;;
                repair_env_ok) text=".env present and readable" ;;
                repair_env_missing) text=".env not found — cannot repair without it" ;;
                repair_check_port) text="Checking panel port" ;;
                repair_port_ok) text="Panel responding on port $1" ;;
                repair_port_fail) text="Panel NOT responding on port $1" ;;
                repair_check_crons) text="Checking crons" ;;
                repair_crons_ok) text="System crons present" ;;
                repair_crons_missing) text="Missing crons — reinstalling" ;;
                repair_fixed) text="Fixed" ;;
                repair_summary_ok) text="No issues detected" ;;
                repair_summary_fixed) text="$1 issue(s) fixed" ;;
                repair_summary_fail) text="$1 issue(s) not fixable — check manually" ;;
                verify_mode) text="Verify mode — checking system status..." ;;
                checking_conflicts) text="Checking for conflicting services" ;;
                plesk_warning) text="WARNING: Plesk detected on this server!" ;;
                plesk_version) text="Plesk version: $1" ;;
                plesk_desc) text="Plesk manages its own web server (nginx/Apache), databases, and PHP. Installing MuseDock Panel alongside Plesk may cause port conflicts and service interference." ;;
                plesk_opt_abort) text="Abort installation (recommended)" ;;
                plesk_opt_continue) text="Continue anyway (advanced users only — you must resolve conflicts manually)" ;;
                plesk_aborted) text="Installation aborted. Plesk and MuseDock Panel are not compatible on the same server." ;;
                plesk_continuing) text="Continuing with Plesk present — manual conflict resolution required" ;;
                nginx_detected) text="Nginx detected: $1" ;;
                nginx_plesk_managed) text="This nginx is managed by Plesk — do NOT disable it without Plesk" ;;
                listening_ports) text="Listening on ports: $1" ;;
                caddy_port_conflict) text="Caddy needs ports 80/443 — this will cause a conflict." ;;
                no_port_conflict) text="$1 is installed but NOT listening on ports 80/443" ;;
                no_conflict_caddy) text="No conflict with Caddy." ;;
                opt_stop_disable) text="Stop & disable $1 permanently (recommended — frees ports for Caddy)" ;;
                opt_keep_running) text="Keep $1 running (you must reconfigure ports manually)" ;;
                opt_abort) text="Abort installation" ;;
                config_backed_up) text="$1 config backed up to $2" ;;
                stopped_disabled) text="$1 stopped and disabled (will not start on reboot)" ;;
                to_reenable) text="To re-enable: systemctl enable --now $1" ;;
                keep_running_warn) text="$1 kept running — you MUST move it off ports 80/443 before Caddy starts" ;;
                caddy_fail_bind) text="Otherwise Caddy will fail to bind and websites will not work" ;;
                plesk_managed_warn) text="$1 is managed by Plesk — cannot disable automatically" ;;
                plesk_resolve_manual) text="You must resolve the port conflict manually or remove Plesk first" ;;
                continue_anyway) text="Continue anyway? [y/N] " ;;
                installation_aborted) text="Installation aborted." ;;
                apache_detected) text="Apache detected: $1" ;;
                apache_plesk_managed) text="This Apache is managed by Plesk — do NOT disable it without Plesk" ;;
                no_conflicts) text="No conflicting services detected (no nginx, no Apache, no Plesk)" ;;
                caddy_already_running) text="Caddy is already running on ports 80/443" ;;
                caddy_already_desc) text="This server already has Caddy serving sites. The panel will integrate with the existing Caddy." ;;
                caddy_has_routes) text="Caddy has $1 active route(s)" ;;
                nginx_not_on_ports_caddy) text="Nginx is not on 80/443 because Caddy already owns those ports" ;;
                apache_not_on_ports_caddy) text="Apache is not on 80/443 because Caddy already owns those ports" ;;
                migrate_header) text="Migrating $1 sites to Caddy" ;;
                migrate_parsing) text="Parsing $1..." ;;
                migrate_found) text="Found: $1 → $2" ;;
                migrate_skipped) text="Skipped (no server_name): $1" ;;
                migrate_skipped_caddy) text="Skipped $1 — already configured in Caddyfile" ;;
                migrate_none) text="No sites found to migrate in $1" ;;
                migrate_ask) text="Migrate $1 site(s) from $2 to Caddy? [Y/n] " ;;
                migrate_saved) text="Migrated sites saved to $1 (will be applied after Caddy install)" ;;
                migrate_skip_user) text="Migration skipped by user" ;;
                migrate_applying) text="Applying $1 migrated site(s) to Caddy..." ;;
                migrate_route_ok) text="Caddy route created: $1" ;;
                migrate_route_fail) text="Failed to create Caddy route for $1 (HTTP $2)" ;;
                migrate_complete) text="Migration complete: $1/$2 sites migrated to Caddy" ;;
                configuration) text="Configuration" ;;
                panel_port_prompt) text="Panel port (default: 8444)" ;;
                php_version) text="PHP Version" ;;
                php_available) text="Available: 8.1, 8.2, 8.3, 8.4" ;;
                php_version_prompt) text="PHP version (default: 8.3)" ;;
                php_invalid) text="Invalid PHP version. Using 8.3" ;;
                summary) text="Summary:" ;;
                summary_port) text="Panel port:   $1" ;;
                summary_php) text="PHP version:  $1" ;;
                summary_dir) text="Install dir:  $1" ;;
                summary_admin) text="Admin setup:  via web wizard on first access" ;;
                proceed_install) text="Proceed with installation? [Y/n] " ;;
                snapshot_header) text="Creating pre-installation snapshot" ;;
                snapshot_services) text="Running services saved" ;;
                snapshot_ports) text="Listening ports saved" ;;
                snapshot_caddyfile) text="Caddyfile backed up" ;;
                snapshot_autosave) text="Caddy autosave.json backed up" ;;
                snapshot_nginx) text="Nginx config backed up" ;;
                snapshot_apache) text="Apache config backed up" ;;
                snapshot_pghba) text="pg_hba.conf backed up" ;;
                snapshot_env) text="Existing .env backed up" ;;
                snapshot_packages) text="Package list saved" ;;
                snapshot_saved) text="Snapshot saved to $1" ;;
                step_packages) text="Step 1/7 — Installing system packages" ;;
                pkg_updated) text="Package lists updated" ;;
                pkg_installed) text="Essential packages installed" ;;
                step_php) text="Step 2/7 — Installing PHP $1" ;;
                php_repo_added) text="PHP repository added" ;;
                php_repo_exists) text="PHP repository already configured" ;;
                php_installed) text="PHP $1 + extensions installed" ;;
                php_fpm_started) text="PHP-FPM $1 started" ;;
                step_pgsql) text="Step 3/7 — Installing PostgreSQL" ;;
                pgsql_installed) text="PostgreSQL installed" ;;
                pgsql_exists) text="PostgreSQL already installed" ;;
                pgsql_running) text="PostgreSQL running" ;;
                pgsql_reusing) text="Reusing existing database credentials" ;;
                pgsql_no_pass) text="Could not read existing DB password — generating new one" ;;
                pgsql_peer_fail) text="PostgreSQL peer authentication is not available." ;;
                pgsql_peer_desc) text="This may happen if pg_hba.conf uses md5/scram-sha-256 for local connections, or if the 'postgres' system user is not configured for direct access." ;;
                pgsql_empty_pass) text="Connected to PostgreSQL with default (empty) password" ;;
                pgsql_enter_pass) text="Enter the PostgreSQL superuser (postgres) password:" ;;
                pgsql_pass_verified) text="PostgreSQL password verified" ;;
                pgsql_cannot_connect) text="Cannot connect to PostgreSQL. Check the password and pg_hba.conf settings." ;;
                pgsql_hba_updated) text="pg_hba.conf updated for panel user access" ;;
                pgsql_db_created) text="Database '$1' created (user: $2)" ;;
                step_caddy) text="Step 4/7 — Installing & configuring Caddy" ;;
                caddy_exists) text="Caddy already installed" ;;
                caddy_config_detected) text="Existing Caddy configuration detected:" ;;
                caddy_api_routes) text="$1 active API routes" ;;
                caddy_caddyfile_domains) text="Caddyfile has domain blocks ($1 entries)" ;;
                caddy_autosave_found) text="autosave.json found (API-persisted routes)" ;;
                caddy_may_be_cms) text="This may be MuseDock CMS or another application." ;;
                caddy_opt_integrate) text="Integrate — use existing Caddy, preserve ALL config (recommended)" ;;
                caddy_opt_reconfigure) text="Reconfigure — overwrite Caddyfile (WARNING: may break existing sites)" ;;
                caddy_integrated) text="Integrating with existing Caddy (all existing config preserved)" ;;
                caddy_admin_added) text="Added admin API to existing Caddyfile (backup created)" ;;
                caddy_resume_added) text="Added --resume flag (routes will persist across restarts)" ;;
                caddy_resume_exists) text="--resume flag already configured" ;;
                caddy_reconfiguring) text="Reconfiguring Caddy — creating backups" ;;
                caddy_backed_up) text="Caddyfile backed up to $1" ;;
                caddy_autosave_backed) text="autosave.json backed up to $1" ;;
                caddy_reconfigured) text="Caddyfile reconfigured" ;;
                caddy_restarted) text="Caddy restarted with new configuration" ;;
                caddy_configured) text="Caddyfile configured with admin API" ;;
                caddy_admin_exists) text="Caddyfile already has admin API enabled" ;;
                caddy_running_resume) text="Caddy running with --resume" ;;
                caddy_installed) text="Caddy installed" ;;
                caddy_running_persist) text="Caddy running with --resume (routes persist across restarts)" ;;
                caddy_api_ok) text="Caddy API accessible on localhost:2019" ;;
                caddy_api_wait) text="Caddy API not responding yet (HTTP $1) — may need a moment to start" ;;
                step_mysql) text="Step 5/7 — Installing MySQL" ;;
                mysql_installed) text="MySQL/MariaDB installed" ;;
                mysql_exists) text="MySQL/MariaDB already installed" ;;
                mysql_running) text="MySQL running" ;;
                mysql_socket_ok) text="MySQL root uses socket authentication (no password needed)" ;;
                mysql_socket_access) text="MySQL root access via socket authentication" ;;
                mysql_pass_recovered) text="MySQL root password recovered from existing .env" ;;
                mysql_needs_pass) text="MySQL root access requires a password." ;;
                mysql_needs_pass_desc) text="The panel needs MySQL root access to create client databases." ;;
                mysql_opt_enter) text="Enter the MySQL root password now" ;;
                mysql_opt_skip) text="Skip — you can configure this later in .env (MYSQL_ROOT_PASS)" ;;
                mysql_attempt) text="MySQL root password (attempt $1/$2): " ;;
                mysql_pass_verified) text="MySQL root password verified" ;;
                mysql_invalid_pass) text="Invalid password" ;;
                mysql_auth_failed) text="Could not authenticate to MySQL — you can set MYSQL_ROOT_PASS in .env later" ;;
                mysql_skipped) text="MySQL root password skipped — set MYSQL_ROOT_PASS in .env to enable client DB creation" ;;
                step_panel) text="Step 6/7 — Setting up MuseDock Panel" ;;
                dirs_created) text="Directories created" ;;
                env_backed_up) text="Existing .env backed up" ;;
                env_created) text=".env created (permissions: 600 — root only)" ;;
                schema_applied) text="Database schema applied (existing tables preserved)" ;;
                permissions_set) text="Permissions set" ;;
                step_service) text="Step 7/7 — Configuring systemd service" ;;
                service_started) text="MuseDock Panel service started on port $1" ;;
                health_header) text="Health Check" ;;
                health_panel_running) text="Panel service: running" ;;
                health_panel_down) text="Panel service: NOT running" ;;
                health_panel_fix) text="Fix: systemctl start musedock-panel" ;;
                health_panel_logs) text="Logs: journalctl -u musedock-panel --no-pager -n 20" ;;
                health_http_ok) text="Panel HTTP: responding (HTTP $1) on port $2" ;;
                health_http_ip) text="Panel HTTP: responding (HTTP 403 — IP restriction active) on port $1" ;;
                health_http_fail) text="Panel HTTP: NOT responding (HTTP $1) on port $2" ;;
                health_http_fix) text="Fix: Check if the service is running and the port is not blocked" ;;
                health_pgsql_ok) text="PostgreSQL: connected to $1 as $2" ;;
                health_pgsql_fail) text="PostgreSQL: cannot connect to $1 as $2" ;;
                health_pgsql_fix) text="Fix: Check PostgreSQL is running and credentials are correct" ;;
                health_mysql_ok) text="MySQL: connected via $1 authentication" ;;
                health_mysql_fail) text="MySQL: $1 auth failed" ;;
                health_mysql_skip) text="MySQL: skipped (authentication not configured — set MYSQL_ROOT_PASS in .env)" ;;
                health_caddy_ok) text="Caddy API: responding on localhost:2019" ;;
                health_caddy_fail) text="Caddy API: NOT responding (HTTP $1)" ;;
                health_caddy_fix) text="Fix: Check Caddy is running" ;;
                health_caddy_ports) text="Caddy ports: listening on 80/443" ;;
                health_caddy_no_ports) text="Caddy: not listening on 80/443 — sites may not be reachable" ;;
                health_fpm_ok) text="PHP-FPM $1: running" ;;
                health_fpm_fail) text="PHP-FPM $1: NOT running" ;;
                health_all_ok) text="All health checks passed! ($1 errors)" ;;
                health_errors) text="Health check completed with $1 error(s)" ;;
                health_review) text="Review the errors above and fix them before using the panel." ;;
                install_complete) text="Installation completed!" ;;
                next_step) text=">>> NEXT STEP: Create your admin account <<<" ;;
                open_browser) text="Open your browser and go to:" ;;
                setup_wizard) text="The setup wizard will guide you through creating the admin username and password." ;;
                save_credentials) text="SAVE THESE CREDENTIALS (shown only once):" ;;
                mysql_auth_label) text="MySQL auth: " ;;
                mysql_pass_stored) text="MySQL pass: (stored in .env)" ;;
                mysql_pass_socket) text="MySQL pass: (not needed — socket auth)" ;;
                mysql_pass_notset) text="MySQL pass: NOT SET — edit .env later" ;;
                stored_in_env) text="These are also stored in .env (root only)." ;;
                detection_summary) text="SERVICE DETECTION SUMMARY:" ;;
                plesk_detected_sum) text="Plesk: detected (manual conflict resolution)" ;;
                nginx_detected_sum) text="Nginx: detected — $1" ;;
                apache_detected_sum) text="Apache: detected — $1" ;;
                security_header) text="SECURITY — IMPORTANT:" ;;
                security_firewall) text="Protect the panel port with a firewall." ;;
                security_root) text="The panel runs as root and has full system access." ;;
                security_trusted) text="Only trusted administrator IPs should reach port $1." ;;
                security_ufw) text="# UFW example — allow only your IP:" ;;
                caddy_choose) text="Choose [1/2] (default: 1): " ;;
                caddy_proxy_ok) text="Caddy HTTPS reverse proxy → https://0.0.0.0:$1 → 127.0.0.1:$2" ;;
                caddy_tls_internal) text="TLS internal (self-signed certificate for IP access)" ;;
                caddy_proxy_fail) text="Caddy/TLS is not responding yet (HTTP $1); the panel remains available internally on 127.0.0.1" ;;
                caddy_proxy_fallback) text="Panel accessible via http://IP:$1 (no HTTPS)" ;;
                caddy_api_unavailable) text="Caddy API not available — panel running on http://0.0.0.0:$1 (no HTTPS)" ;;
                mysql_choose) text="Choose [1/2] (default: 1): " ;;
                security_restrict) text="Restrict IPs in .env (additional layer):" ;;
                security_admin_only) text="The panel port is for administrators only." ;;
                security_no_public) text="Never expose it to the public internet without a firewall." ;;
                security_client_sites) text="Hosting client sites are served by Caddy on ports 80/443." ;;
                service_mgmt) text="Service management:" ;;
                snapshot_label) text="Pre-install snapshot:" ;;
                snapshot_desc) text="(services, ports, configs saved before installation)" ;;
                uninstall_label) text="Uninstall:" ;;
                enjoy) text="Enjoy MuseDock Panel!" ;;
                next_steps_header) text="NEXT STEPS (from the panel):" ;;
                next_steps_mail) text="Mail: Mail → Setup (local or remote node)" ;;
                next_steps_cluster) text="Cluster: Settings → Cluster (add nodes)" ;;
                next_steps_replication) text="Replication: Settings → Replication (master/slave)" ;;
                next_steps_hint) text="Everything is configured from the web panel — no need to come back to SSH." ;;
                firewall_header) text="PANEL ACCESS:" ;;
                firewall_ufw_active) text="UFW is active on this server." ;;
                firewall_iptables_active) text="iptables is active on this server (no UFW)." ;;
                firewall_port_open) text="Port $1 is OPEN — you can access the panel." ;;
                firewall_port_closed) text="Port $1 is BLOCKED by the firewall." ;;
                firewall_port_open_ip) text="Port $1 is accessible from authorized IPs (ACCEPT all rules)." ;;
                firewall_open_cmd) text="To open access from your IP:" ;;
                firewall_no_detected) text="No active firewall detected (UFW/iptables)." ;;
                firewall_consider) text="Consider enabling a firewall to protect port $1." ;;
                firewall_access_url) text="Access the panel at:" ;;
                firewall_make_sure) text="Make sure port $1 is open for your admin IP." ;;
                fw_setup_header) text="Panel protection" ;;
                fw_setup_desc) text="The panel runs as root. It is CRITICAL to restrict access to port $1." ;;
                fw_setup_ask) text="Configure IP restriction now? (highly recommended) [Y/n] " ;;
                fw_setup_ip_prompt) text="Allowed IP or range (e.g. 85.60.10.5 or 192.168.1.0/24)" ;;
                fw_setup_ip_another) text="Add another IP? [y/N] " ;;
                fw_setup_ip_invalid) text="Invalid IP/range: $1" ;;
                fw_setup_applying_ufw) text="Applying UFW rules..." ;;
                fw_setup_applying_ipt) text="Applying iptables rules..." ;;
                fw_setup_installing_ufw) text="Installing and activating UFW..." ;;
                fw_setup_ufw_rule) text="UFW: allowed $1 → port $2" ;;
                fw_setup_ufw_deny) text="UFW: denied general access to port $1" ;;
                fw_setup_ipt_rule) text="iptables: allowed $1 → port $2" ;;
                fw_setup_ipt_deny) text="iptables: denied general access to port $1" ;;
                fw_setup_ipt_saved) text="iptables: rules saved (persistent)" ;;
                fw_setup_env_ips) text="ALLOWED_IPS updated in .env: $1" ;;
                fw_setup_done) text="Panel protected — only accessible from: $1" ;;
                fw_setup_skip) text="Skipped — REMEMBER to protect port $1 ASAP!" ;;
                fw_setup_skip_warn) text="Without protection, anyone can access the panel as root." ;;
                fw_setup_detect_ufw) text="UFW detected and active" ;;
                fw_setup_detect_ufw_inactive) text="UFW installed but inactive" ;;
                fw_setup_detect_ipt) text="iptables detected (no UFW)" ;;
                fw_setup_detect_ipt_open) text="iptables installed, but no restrictive policy is active" ;;
                fw_setup_detect_none) text="No active firewall detected" ;;
                fw_setup_selected) text="Selected firewall mode: $1" ;;
                verify_complete) text="Verification complete" ;;
                verify_all_ok) text="Everything OK! The panel is running correctly." ;;
                verify_has_errors) text="Found $1 issue(s). Review above." ;;
                pgsql_detect_version) text="Detecting PostgreSQL version..." ;;
                pgsql_version_found) text="PostgreSQL version $1 detected" ;;
                pgsql_dual_header) text="Configuring dual PostgreSQL cluster..." ;;
                pgsql_cluster_exists) text="Cluster '$1' already exists on port $2" ;;
                pgsql_cluster_created) text="Cluster '$1' created on port $2" ;;
                pgsql_migrating) text="Migrating panel DB from 5432 to 5433..." ;;
                pgsql_migration_backup) text="Safety backup: $1" ;;
                pgsql_migration_done) text="Panel DB successfully migrated to port 5433" ;;
                pgsql_already_5433) text="Panel already uses port 5433 — no migration needed" ;;
                pgsql_5432_free) text="Port 5432 available for projects and replication" ;;
                pgsql_panel_cluster) text="Panel cluster: $1/$2 on port $3" ;;
                *) text="[$key]" ;;
            esac
            ;;
    esac
    echo "$text"
}

# ============================================================
# Pre-flight checks
# ============================================================

# 2. Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_ID="$ID"
    OS_VERSION="$VERSION_ID"
else
    fail "$(t cannot_detect_os)"
fi

case "$OS_ID" in
    ubuntu)
        if [[ "$OS_VERSION" < "22.04" ]]; then
            fail "$(t os_not_supported Ubuntu "$OS_VERSION" 22.04)"
        fi
        ;;
    debian)
        if [[ "$OS_VERSION" < "12" ]]; then
            fail "$(t os_not_supported Debian "$OS_VERSION" 12)"
        fi
        ;;
    *)
        fail "$(t os_unknown "$OS_ID")"
        ;;
esac

echo ""
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════╗"
echo "  ║         MuseDock Panel Installer         ║"
INSTALLER_VERSION=$(sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" "${PANEL_DIR}/config/panel.php" | head -1)
INSTALLER_VERSION=${INSTALLER_VERSION:-unknown}
printf "  ║            v%-10s — %-4s        ║\n" "$INSTALLER_VERSION" "$(date +%Y)"
echo "  ╚══════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  OS:          ${BOLD}${OS_ID} ${OS_VERSION}${NC}"
echo -e "  Install dir: ${BOLD}${PANEL_DIR}${NC}"
echo ""

# 3. Check for existing installation
REINSTALL=false
VERIFY_ONLY=false
UPDATE_ONLY=false
REPAIR_MODE=false
if [ -f "${PANEL_DIR}/.env" ]; then
    echo -e "  ${YELLOW}${BOLD}$(t existing_detected)${NC}"
    echo -e "  ${YELLOW}$(t existing_found "${PANEL_DIR}/.env")${NC}"
    echo ""
    echo "  $(t existing_options)"
    echo "    1) $(t existing_opt_reinstall)"
    echo "    2) $(t existing_opt_verify)"
    echo "    3) $(t existing_opt_cancel)"
    echo "    4) $(t existing_opt_update)"
    echo "    5) $(t existing_opt_repair)"
    echo ""
    prompt_read "  $(t existing_choose)" EXISTING_CHOICE
    EXISTING_CHOICE=${EXISTING_CHOICE:-4}

    case "$EXISTING_CHOICE" in
        1)
            if panel_looks_operational; then
                echo ""
                echo -e "  ${RED}${BOLD}PELIGRO: este panel parece operativo.${NC}"
                echo -e "  ${YELLOW}Reinstalar puede reescribir servicio systemd, Caddyfile, crons, .env y rutas del panel.${NC}"
                echo -e "  ${YELLOW}No borra bases de datos ni vhosts, pero no es la opcion correcta para actualizar un servidor sano.${NC}"
                echo -e "  ${CYAN}Usa la opcion 4 para actualizar o la opcion 5 para reparar.${NC}"
                echo ""
                prompt_read "  Para forzar reinstalacion escribe exactamente REINSTALAR: " REINSTALL_CONFIRM
                if [ "$REINSTALL_CONFIRM" != "REINSTALAR" ]; then
                    echo -e "  ${GREEN}Cancelado. No se ha tocado la instalacion existente.${NC}"
                    exit 0
                fi
            fi
            REINSTALL=true
            echo ""
            ok "$(t reinstall_mode)"
            preflight_existing_panel_services
            ;;
        2)
            VERIFY_ONLY=true
            echo ""
            ok "$(t verify_mode)"
            # Read existing .env for health check
            PANEL_PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8444")
            DB_PORT=$(grep -E '^DB_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "5432")
            DB_NAME=$(grep -E '^DB_NAME=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_USER=$(grep -E '^DB_USER=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PHP_VER=$(grep -E '^FPM_PHP_VERSION=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8.3")
            MYSQL_AUTH_METHOD=$(grep -E '^MYSQL_AUTH_METHOD=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "unknown")
            MYSQL_ROOT_PASS=$(grep -E '^MYSQL_ROOT_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PANEL_ROLE=$(grep -E '^PANEL_ROLE=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "standalone")
            ;;
        4)
            UPDATE_ONLY=true
            echo ""
            ok "$(t update_mode)"
            # Read existing .env
            PANEL_PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8444")
            DB_PORT=$(grep -E '^DB_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "5432")
            DB_NAME=$(grep -E '^DB_NAME=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_USER=$(grep -E '^DB_USER=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PHP_VER=$(grep -E '^FPM_PHP_VERSION=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8.3")
            MYSQL_AUTH_METHOD=$(grep -E '^MYSQL_AUTH_METHOD=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "unknown")
            MYSQL_ROOT_PASS=$(grep -E '^MYSQL_ROOT_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PANEL_ROLE=$(grep -E '^PANEL_ROLE=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "standalone")
            ;;
        5)
            REPAIR_MODE=true
            echo ""
            ok "$(t repair_mode)"
            PANEL_PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8444")
            DB_PORT=$(grep -E '^DB_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "5432")
            DB_NAME=$(grep -E '^DB_NAME=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_USER=$(grep -E '^DB_USER=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PHP_VER=$(grep -E '^FPM_PHP_VERSION=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8.3")
            PANEL_ROLE=$(grep -E '^PANEL_ROLE=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "standalone")
            ;;
        3)
            echo ""
            echo -e "  $(t install_cancelled)"
            exit 0
            ;;
        *)
            # Default = update (option 4)
            UPDATE_ONLY=true
            echo ""
            ok "$(t update_mode)"
            PANEL_PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8444")
            DB_PORT=$(grep -E '^DB_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "5432")
            DB_NAME=$(grep -E '^DB_NAME=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_USER=$(grep -E '^DB_USER=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "musedock_panel")
            DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PHP_VER=$(grep -E '^FPM_PHP_VERSION=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "8.3")
            MYSQL_AUTH_METHOD=$(grep -E '^MYSQL_AUTH_METHOD=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "unknown")
            MYSQL_ROOT_PASS=$(grep -E '^MYSQL_ROOT_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "")
            PANEL_ROLE=$(grep -E '^PANEL_ROLE=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'' || echo "standalone")
            ;;
    esac
fi

# ============================================================
# Detect conflicting services (nginx, Apache, Plesk)
# ============================================================
if [ "$REPAIR_MODE" = true ]; then
    # ============================================================
    # Repair mode — diagnose and fix common issues
    # ============================================================
    set +e

    PANEL_INTERNAL_PORT=$((PANEL_PORT + 1))
    REPAIR_FIXED=0
    REPAIR_FAILED=0

    header "$(t repair_mode)"

    # --- 1. Check .env ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_env)${NC}"
    if [ -f "${PANEL_DIR}/.env" ]; then
        ok "$(t repair_env_ok)"
    else
        fail "$(t repair_env_missing)"
    fi

    # --- 2. Check directories ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_dirs)${NC}"
    for dir in storage/sessions storage/logs storage/cache storage/backups; do
        if [ ! -d "${PANEL_DIR}/${dir}" ]; then
            mkdir -p "${PANEL_DIR}/${dir}"
            warn "  ${dir} — $(t repair_fixed)"
            REPAIR_FIXED=$((REPAIR_FIXED + 1))
        fi
    done
    install -d -o postgres -g www-data -m 0770 "${PANEL_DIR}/storage/backups"
    if [ ! -d /var/www/vhosts ]; then
        mkdir -p /var/www/vhosts
        warn "  /var/www/vhosts — $(t repair_fixed)"
        REPAIR_FIXED=$((REPAIR_FIXED + 1))
    fi
    ok "$(t repair_dirs_ok)"

    # --- 3. Check PHP syntax of all panel files ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_php)${NC}"
    PHP_ERRORS=0
    while IFS= read -r phpfile; do
        ERR=$(php -l "$phpfile" 2>&1)
        if [ $? -ne 0 ]; then
            echo -e "  ${RED}✗ $(t repair_php_error "$phpfile")${NC}"
            echo -e "    ${RED}${ERR}${NC}"
            PHP_ERRORS=$((PHP_ERRORS + 1))
            REPAIR_FAILED=$((REPAIR_FAILED + 1))
        fi
    done < <(find "${PANEL_DIR}/app" "${PANEL_DIR}/config" "${PANEL_DIR}/public" -name "*.php" 2>/dev/null)
    if [ "$PHP_ERRORS" -eq 0 ]; then
        ok "$(t repair_php_ok)"
    else
        echo -e "  ${RED}  $PHP_ERRORS archivo(s) con errores de sintaxis${NC}"
    fi

    # --- 4. Check database connection ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_db)${NC}"
    DB_WORKING_PORT=""

    # 4a. Check if PostgreSQL service is running
    PG_SERVICE_ACTIVE=$(timeout 3 systemctl is-active postgresql 2>/dev/null || echo "inactive")
    if [ "$PG_SERVICE_ACTIVE" != "active" ]; then
        warn "PostgreSQL no esta corriendo — intentando iniciar..."
        systemctl start postgresql 2>/dev/null
        sleep 3
        PG_SERVICE_ACTIVE=$(timeout 3 systemctl is-active postgresql 2>/dev/null || echo "inactive")
        if [ "$PG_SERVICE_ACTIVE" = "active" ]; then
            ok "PostgreSQL iniciado — $(t repair_fixed)"
            REPAIR_FIXED=$((REPAIR_FIXED + 1))
        else
            echo -e "  ${RED}✗ No se pudo iniciar PostgreSQL${NC}"
            echo -e "    ${YELLOW}Revisa: systemctl status postgresql${NC}"
        fi
    else
        ok "PostgreSQL servicio activo"
    fi

    # 4b. Detect actual listening ports
    PG_PORTS=$(ss -tlnp 2>/dev/null | grep postgres | grep -oP ':\K\d+' | sort -u)
    if [ -n "$PG_PORTS" ]; then
        echo -e "    PostgreSQL escucha en puertos: $(echo $PG_PORTS | tr '\n' ' ')"
    fi

    # 4c. Try to connect — configured port first, then detected ports, then defaults
    ALL_PORTS=$(echo -e "${DB_PORT}\n${PG_PORTS}\n5433\n5432" | sort -u)
    LAST_DB_ERROR=""
    for TRY_PORT in $ALL_PORTS; do
        [ -z "$TRY_PORT" ] && continue
        DB_CHECK=$(PGPASSWORD="${DB_PASS}" timeout 5 psql -U "${DB_USER}" -h 127.0.0.1 -p "${TRY_PORT}" -d "${DB_NAME}" -tAc "SELECT 1;" 2>&1)
        if [ "$DB_CHECK" = "1" ]; then
            DB_WORKING_PORT="$TRY_PORT"
            ok "$(t repair_db_ok) (puerto ${TRY_PORT})"
            break
        else
            LAST_DB_ERROR="$DB_CHECK"
        fi
    done

    if [ -z "$DB_WORKING_PORT" ]; then
        echo -e "  ${RED}✗ $(t repair_db_fail)${NC}"
        echo -e "    ${RED}Error: ${LAST_DB_ERROR}${NC}"

        # 4d. Diagnose: check pg_hba.conf for md5/scram auth on localhost
        PG_VERSION=$(ls /etc/postgresql/ 2>/dev/null | sort -V | tail -1)
        if [ -n "$PG_VERSION" ]; then
            PG_HBA="/etc/postgresql/${PG_VERSION}/main/pg_hba.conf"
            if [ -f "$PG_HBA" ]; then
                HBA_LOCAL=$(grep -v '^#' "$PG_HBA" | grep -E '127\.0\.0\.1|localhost' | head -3)
                if [ -n "$HBA_LOCAL" ]; then
                    echo -e "    ${YELLOW}pg_hba.conf (localhost):${NC}"
                    echo "$HBA_LOCAL" | while read -r hbaline; do
                        echo -e "    ${YELLOW}  $hbaline${NC}"
                    done
                fi
                # Check if panel user has access
                if ! grep -v '^#' "$PG_HBA" | grep -qE "${DB_USER}|all.*all.*127"; then
                    echo -e "    ${YELLOW}El usuario '${DB_USER}' puede no tener acceso en pg_hba.conf${NC}"
                    echo -e "    ${YELLOW}Añadiendo regla de acceso...${NC}"
                    # Add access rule before the first line
                    sed -i "1i host    ${DB_NAME}    ${DB_USER}    127.0.0.1/32    md5" "$PG_HBA"
                    # Reload PostgreSQL
                    systemctl reload postgresql 2>/dev/null
                    sleep 2
                    # Retry connection
                    for TRY_PORT in $ALL_PORTS; do
                        [ -z "$TRY_PORT" ] && continue
                        DB_CHECK=$(PGPASSWORD="${DB_PASS}" timeout 5 psql -U "${DB_USER}" -h 127.0.0.1 -p "${TRY_PORT}" -d "${DB_NAME}" -tAc "SELECT 1;" 2>&1)
                        if [ "$DB_CHECK" = "1" ]; then
                            DB_WORKING_PORT="$TRY_PORT"
                            ok "$(t repair_db_ok) (puerto ${TRY_PORT}) — pg_hba.conf $(t repair_fixed)"
                            REPAIR_FIXED=$((REPAIR_FIXED + 1))
                            break
                        fi
                    done
                fi
            fi
        fi

        if [ -z "$DB_WORKING_PORT" ]; then
            echo -e "    ${YELLOW}Credenciales en .env: usuario=${DB_USER}, bd=${DB_NAME}, puerto=${DB_PORT}${NC}"
            REPAIR_FAILED=$((REPAIR_FAILED + 1))
        fi
    fi

    # --- 4e. Check firewall doesn't block localhost ---
    echo ""
    echo -e "  ${CYAN}Verificando firewall (acceso localhost)${NC}"
    FW_ISSUES=0

    # Check iptables
    if command -v iptables &>/dev/null; then
        IPT_POLICY=$(iptables -L INPUT -n 2>/dev/null | head -1 | grep -oP 'policy \K\w+' || echo "ACCEPT")
        if [ "$IPT_POLICY" = "DROP" ]; then
            # Check if loopback (lo) is accepted
            LO_ACCEPT=$(iptables -L INPUT -n -v 2>/dev/null | grep -E 'ACCEPT.*lo\b' | head -1)
            if [ -z "$LO_ACCEPT" ]; then
                warn "iptables: politica DROP sin regla para loopback (lo)"
                echo -e "    ${YELLOW}Añadiendo: iptables -I INPUT 1 -i lo -j ACCEPT${NC}"
                iptables -I INPUT 1 -i lo -j ACCEPT 2>/dev/null
                ok "$(t repair_fixed) — loopback permitido"
                REPAIR_FIXED=$((REPAIR_FIXED + 1))
                FW_ISSUES=$((FW_ISSUES + 1))
            else
                ok "iptables: loopback (lo) permitido"
            fi

            # Check if panel port 8444 is accessible (from anywhere or specific IPs)
            PANEL_RULE=$(iptables -L INPUT -n 2>/dev/null | grep -E "ACCEPT.*(dpt:${PANEL_PORT}|dpt:${PANEL_INTERNAL_PORT})" | head -1)
            PANEL_ALL=$(iptables -L INPUT -n 2>/dev/null | grep -E "ACCEPT.*0\.0\.0\.0/0.*0\.0\.0\.0/0" | grep -v ctstate | head -1)
            if [ -z "$PANEL_RULE" ] && [ -z "$PANEL_ALL" ]; then
                # Check if any IP-specific rule could cover it
                PANEL_COVERED=$(iptables -L INPUT -n 2>/dev/null | grep "ACCEPT" | grep -v ctstate | grep -v "dpt:" | head -1)
                if [ -z "$PANEL_COVERED" ]; then
                    warn "iptables: puerto ${PANEL_PORT} del panel podria estar bloqueado"
                    echo -e "    ${YELLOW}Las IPs que necesiten acceso al panel deben tener reglas ACCEPT${NC}"
                    FW_ISSUES=$((FW_ISSUES + 1))
                fi
            fi

            # Check critical ports: PostgreSQL
            for CHECK_PORT in 5432 5433; do
                PG_LISTEN=$(ss -tlnp 2>/dev/null | grep ":${CHECK_PORT}\b" | head -1)
                if [ -n "$PG_LISTEN" ]; then
                    # PG listens on this port — check it's not blocked for localhost via lo
                    # If loopback is allowed (which we ensured above), localhost is fine
                    ok "Puerto ${CHECK_PORT} (PostgreSQL) — accesible via loopback"
                fi
            done
        else
            ok "iptables: politica ${IPT_POLICY} — sin restricciones"
        fi
    fi

    # Check UFW
    UFW_STATUS=$(timeout 3 ufw status 2>/dev/null | head -1 || echo "")
    if echo "$UFW_STATUS" | grep -qi "active"; then
        ok "UFW: activo"
        # Check if panel port is allowed
        UFW_PANEL=$(ufw status 2>/dev/null | grep -E "${PANEL_PORT}" | head -1)
        if [ -z "$UFW_PANEL" ]; then
            warn "UFW: puerto ${PANEL_PORT} del panel no tiene regla explicita"
            FW_ISSUES=$((FW_ISSUES + 1))
        fi
    fi

    if [ "$FW_ISSUES" -eq 0 ]; then
        ok "Firewall: sin problemas detectados"
    fi

    # --- 4f. Retry DB connection if firewall was fixed ---
    if [ -z "$DB_WORKING_PORT" ] && [ "$FW_ISSUES" -gt 0 ]; then
        echo ""
        echo -e "  ${CYAN}Reintentando conexion a base de datos (firewall corregido)${NC}"
        for TRY_PORT in ${ALL_PORTS}; do
            [ -z "$TRY_PORT" ] && continue
            DB_CHECK=$(PGPASSWORD="${DB_PASS}" timeout 5 psql -U "${DB_USER}" -h 127.0.0.1 -p "${TRY_PORT}" -d "${DB_NAME}" -tAc "SELECT 1;" 2>&1)
            if [ "$DB_CHECK" = "1" ]; then
                DB_WORKING_PORT="$TRY_PORT"
                ok "$(t repair_db_ok) (puerto ${TRY_PORT}) — $(t repair_fixed)"
                REPAIR_FIXED=$((REPAIR_FIXED + 1))
                # Reduce failed count since we fixed this
                REPAIR_FAILED=$((REPAIR_FAILED > 0 ? REPAIR_FAILED - 1 : 0))
                break
            fi
        done
        if [ -z "$DB_WORKING_PORT" ]; then
            warn "Sigue sin conectar a la BD — revisa credenciales en .env"
        fi
    fi

    # --- 5. Run schema + migrations (safe, only if DB is accessible) ---
    if [ -n "$DB_WORKING_PORT" ]; then
        echo ""
        echo -e "  ${CYAN}$(t update_schema)${NC}"
        PGPASSWORD="${DB_PASS}" timeout 15 psql -U "${DB_USER}" -h 127.0.0.1 -p "${DB_WORKING_PORT}" -d "${DB_NAME}" -f "${PANEL_DIR}/database/schema.sql" > /dev/null 2>&1
        ok "$(t update_schema)"

        # Migrations
        MIGRATION_DIR="${PANEL_DIR}/database/migrations"
        if [ -d "$MIGRATION_DIR" ]; then
            MIGRATION_COUNT=0
            EXISTING_MIGRATIONS=$(PGPASSWORD="${DB_PASS}" timeout 5 psql -U "${DB_USER}" -h 127.0.0.1 -p "${DB_WORKING_PORT}" -d "${DB_NAME}" -tAc "SELECT migration FROM panel_migrations;" 2>/dev/null || echo "")
            for mig_file in "$MIGRATION_DIR"/*.php; do
                [ -f "$mig_file" ] || continue
                mig_name=$(basename "$mig_file")
                if echo "$EXISTING_MIGRATIONS" | grep -q "$mig_name" 2>/dev/null; then
                    continue
                fi
                php "$mig_file" 2>/dev/null && MIGRATION_COUNT=$((MIGRATION_COUNT + 1)) && ok "  + $mig_name"
            done
            ok "$(t update_migrations) ($MIGRATION_COUNT pendientes)"
        fi
    else
        warn "Saltando schema/migraciones — base de datos no accesible"
    fi

    # --- 6. Check Caddy ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_caddy)${NC}"
    CADDY_ACTIVE=$(timeout 5 systemctl is-active caddy 2>/dev/null)
    if [ "$CADDY_ACTIVE" = "active" ]; then
        CADDY_API=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 http://127.0.0.1:2019/config/ 2>/dev/null)
        if [ "$CADDY_API" = "200" ]; then
            ok "$(t repair_caddy_ok)"
        else
            warn "Caddy activo pero API no responde (puerto 2019)"
        fi
    else
        warn "$(t repair_caddy_dead)"
        timeout 10 systemctl start caddy 2>/dev/null
        sleep 2
        CADDY_ACTIVE2=$(timeout 5 systemctl is-active caddy 2>/dev/null)
        if [ "$CADDY_ACTIVE2" = "active" ]; then
            ok "$(t repair_fixed)"
            REPAIR_FIXED=$((REPAIR_FIXED + 1))
        else
            echo -e "  ${RED}✗ No se pudo iniciar Caddy${NC}"
            REPAIR_FAILED=$((REPAIR_FAILED + 1))
        fi
    fi

    # --- 7. Check permissions ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_perms)${NC}"
    chmod 600 "${PANEL_DIR}/.env" 2>/dev/null
    chmod -R 750 "${PANEL_DIR}/storage" 2>/dev/null
    ok "$(t repair_perms_fixed)"

    # --- 8. Check crons ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_crons)${NC}"
    CRONS_FIXED=0
    for cronfile in musedock-cluster musedock-backup musedock-caddy-backup musedock-filesync musedock-monitor musedock-failover; do
        if [ ! -f "/etc/cron.d/${cronfile}" ]; then
            warn "  /etc/cron.d/${cronfile} — faltante"
            CRONS_FIXED=$((CRONS_FIXED + 1))
        fi
    done
    if [ "$CRONS_FIXED" -gt 0 ]; then
        warn "$(t repair_crons_missing)"
        # Reinstall crons
        cat > /etc/cron.d/musedock-cluster << CRONEOF
# MuseDock Panel — Cluster worker (queue, heartbeat, alerts)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/cluster-worker.php >> ${PANEL_DIR}/storage/logs/cluster-worker.log 2>&1
CRONEOF
        chmod 644 /etc/cron.d/musedock-cluster

        cat > /etc/cron.d/musedock-backup << CRONEOF
# MuseDock Panel — Hourly panel DB backup
0 * * * * root install -d -o postgres -g www-data -m 0770 ${PANEL_DIR}/storage/backups && runuser -u postgres -- pg_dump -p 5433 musedock_panel | gzip > ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chown postgres:www-data ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chmod 0640 ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz
# Cleanup backups older than 48 hours
5 * * * * root find ${PANEL_DIR}/storage/backups/ -name "panel-*.sql.gz" -mmin +2880 -delete 2>/dev/null
CRONEOF
        chmod 644 /etc/cron.d/musedock-backup

        install_caddy_backup_cron
        run_caddy_config_snapshot

        cat > /etc/cron.d/musedock-filesync << CRONEOF
# MuseDock Panel — File sync worker (master -> slave file replication)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/filesync-worker.php >> ${PANEL_DIR}/storage/logs/filesync-worker.log 2>&1
CRONEOF
        chmod 644 /etc/cron.d/musedock-filesync

        cat > /etc/cron.d/musedock-monitor << CRONEOF
# MuseDock Panel — Network/system monitoring collector (every 30s)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
* * * * * root sleep 30 && /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
CRONEOF
        chmod 644 /etc/cron.d/musedock-monitor

        cat > /etc/cron.d/musedock-failover << CRONEOF
# MuseDock Panel — Failover worker (health checks, auto-failover, resync)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/failover-worker.php >> ${PANEL_DIR}/storage/logs/failover-worker.log 2>&1
CRONEOF
        chmod 644 /etc/cron.d/musedock-failover

        systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
        ok "$(t repair_fixed)"
        REPAIR_FIXED=$((REPAIR_FIXED + $CRONS_FIXED))
    else
        ok "$(t repair_crons_ok)"
    fi

    # --- 9. Check/reinstall systemd service ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_service)${NC}"
    if [ ! -f /etc/systemd/system/musedock-panel.service ]; then
        warn "$(t repair_service_not_found)"
        if [ -f "${PANEL_DIR}/bin/musedock-panel.service" ]; then
            sed -e "s|__PANEL_DIR__|${PANEL_DIR}|g" \
                -e "s|__PANEL_PORT__|${PANEL_PORT}|g" \
                -e "s|__PANEL_INTERNAL_PORT__|${PANEL_INTERNAL_PORT}|g" \
                "${PANEL_DIR}/bin/musedock-panel.service" > /etc/systemd/system/musedock-panel.service
            systemctl daemon-reload
            systemctl enable musedock-panel 2>/dev/null
            ok "$(t repair_fixed)"
            REPAIR_FIXED=$((REPAIR_FIXED + 1))
        else
            echo -e "  ${RED}✗ Plantilla de servicio no encontrada en bin/${NC}"
            REPAIR_FAILED=$((REPAIR_FAILED + 1))
        fi
    fi

    # Check if service is running
    if systemctl is-active --quiet musedock-panel 2>/dev/null; then
        ok "$(t repair_service_ok)"
    else
        warn "$(t repair_service_dead)"
        systemctl restart musedock-panel 2>/dev/null
        sleep 2
        if systemctl is-active --quiet musedock-panel 2>/dev/null; then
            ok "$(t repair_fixed)"
            REPAIR_FIXED=$((REPAIR_FIXED + 1))
        else
            echo -e "  ${RED}✗ No se pudo reiniciar el servicio${NC}"
            # Try to show the error
            echo -e "  ${RED}  $(systemctl status musedock-panel 2>&1 | grep -i 'error\|fail\|exit' | head -3)${NC}"
            REPAIR_FAILED=$((REPAIR_FAILED + 1))
        fi
    fi

    # --- 10. Check panel port responds ---
    echo ""
    echo -e "  ${CYAN}$(t repair_check_port)${NC}"
    sleep 1
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "http://127.0.0.1:${PANEL_INTERNAL_PORT}/" 2>/dev/null)
    if [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ]; then
        ok "$(t repair_port_ok "${PANEL_PORT}") (HTTP ${HTTP_CODE})"
    else
        echo -e "  ${RED}✗ $(t repair_port_fail "${PANEL_PORT}") (HTTP ${HTTP_CODE})${NC}"
        # Try to diagnose
        if ! ss -tlnp 2>/dev/null | grep -q ":${PANEL_INTERNAL_PORT}\b"; then
            echo -e "  ${RED}  El puerto ${PANEL_INTERNAL_PORT} no esta en escucha${NC}"
            echo -e "  ${YELLOW}  Intentando reiniciar el servicio...${NC}"
            systemctl restart musedock-panel 2>/dev/null
            sleep 3
            HTTP_CODE2=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "http://127.0.0.1:${PANEL_INTERNAL_PORT}/" 2>/dev/null)
            if [ "$HTTP_CODE2" = "302" ] || [ "$HTTP_CODE2" = "200" ]; then
                ok "$(t repair_port_ok "${PANEL_PORT}") — $(t repair_fixed)"
                REPAIR_FIXED=$((REPAIR_FIXED + 1))
            else
                echo -e "  ${RED}✗ Sigue sin responder despues del reinicio${NC}"
                echo -e "  ${YELLOW}  Revisa: journalctl -u musedock-panel -n 20${NC}"
                REPAIR_FAILED=$((REPAIR_FAILED + 1))
            fi
        else
            echo -e "  ${YELLOW}  Puerto en escucha pero no responde — posible error PHP${NC}"
            REPAIR_FAILED=$((REPAIR_FAILED + 1))
        fi
    fi

    # --- 11. Check HTTPS via Caddy ---
    echo ""
    echo -e "  ${CYAN}Verificando acceso HTTPS${NC}"
    HTTPS_CODE=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null)
    if [ "$HTTPS_CODE" = "302" ] || [ "$HTTPS_CODE" = "200" ]; then
        ok "HTTPS respondiendo en puerto ${PANEL_PORT} (HTTP ${HTTPS_CODE})"
    else
        echo -e "  ${RED}✗ HTTPS no responde en puerto ${PANEL_PORT} (HTTP ${HTTPS_CODE})${NC}"
        echo -e "  ${YELLOW}  Revisa la configuracion de Caddy: caddy reverse-proxy al puerto ${PANEL_INTERNAL_PORT}${NC}"
        REPAIR_FAILED=$((REPAIR_FAILED + 1))
    fi

    # --- Summary ---
    echo ""
    echo -e "  ${CYAN}${BOLD}════════════════════════════════════════${NC}"
    if [ "$REPAIR_FAILED" -eq 0 ] && [ "$REPAIR_FIXED" -eq 0 ]; then
        echo -e "  ${GREEN}${BOLD}$(t repair_summary_ok)${NC}"
    else
        if [ "$REPAIR_FIXED" -gt 0 ]; then
            echo -e "  ${GREEN}${BOLD}$(t repair_summary_fixed "$REPAIR_FIXED")${NC}"
        fi
        if [ "$REPAIR_FAILED" -gt 0 ]; then
            echo -e "  ${RED}${BOLD}$(t repair_summary_fail "$REPAIR_FAILED")${NC}"
        fi
    fi
    echo -e "  ${CYAN}${BOLD}════════════════════════════════════════${NC}"
    echo ""
    exit 0

elif [ "$VERIFY_ONLY" = true ]; then
    # ============================================================
    # Verify mode — comprehensive system diagnosis
    # Disable set -e so checks don't kill the script
    # ============================================================
    set +e

    # Detect PG version
    PG_VER=$(pg_lsclusters -h 2>/dev/null | head -1 | awk '{print $1}')
    [ -z "$PG_VER" ] && PG_VER="16"

    header "$(t health_header)"

    HEALTH_ERRORS=0
    HEALTH_WARNINGS=0

    # --- 1. Panel service ---
    if systemctl is-active --quiet musedock-panel 2>/dev/null; then
        ok "$(t health_panel_running)"
    else
        echo -e "  ${RED}✗ $(t health_panel_down)${NC}"
        echo -e "    ${YELLOW}$(t health_panel_fix)${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi

    # --- 2. Panel HTTP ---
    sleep 1
    PANEL_HTTP="000"
    for TEST_URL in \
        "http://127.0.0.1:$((PANEL_PORT + 1))/" \
        "https://127.0.0.1:${PANEL_PORT}/" \
    ; do
        PANEL_HTTP=$(curl -sk -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 3 "$TEST_URL" 2>/dev/null || echo "000")
        PANEL_HTTP=$(echo "$PANEL_HTTP" | tr -d '[:space:]')
        [ -n "$PANEL_HTTP" ] && [ "$PANEL_HTTP" != "000" ] && break
        PANEL_HTTP="000"
    done
    PANEL_PUBLIC_PLAIN_HTTP=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 3 "http://127.0.0.1:/" 2>/dev/null || echo "000")
    PANEL_PUBLIC_PLAIN_HTTP=$(echo "$PANEL_PUBLIC_PLAIN_HTTP" | tr -d '[:space:]')
    if [ "$PANEL_PUBLIC_PLAIN_HTTP" = "200" ] || [ "$PANEL_PUBLIC_PLAIN_HTTP" = "302" ] || [ "$PANEL_PUBLIC_PLAIN_HTTP" = "301" ]; then
        echo -e "  ${RED}✗ El puerto publico ${PANEL_PORT} responde por HTTP plano. Esto causa ERR_SSL_PROTOCOL_ERROR en https://IP:${PANEL_PORT}.${NC}"
        echo -e "    ${YELLOW}Fix: musedock-panel debe escuchar en 127.0.0.1:$((PANEL_PORT + 1)) y Caddy debe servir TLS en ${PANEL_PORT}.${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi
    if [ "$PANEL_HTTP" = "200" ] || [ "$PANEL_HTTP" = "302" ] || [ "$PANEL_HTTP" = "301" ]; then
        ok "$(t health_http_ok "$PANEL_HTTP" "$PANEL_PORT")"
    elif [ "$PANEL_HTTP" = "403" ]; then
        ok "$(t health_http_ip "$PANEL_PORT")"
    else
        echo -e "  ${RED}✗ $(t health_http_fail "$PANEL_HTTP" "$PANEL_PORT")${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi

    # --- 3. PostgreSQL clusters ---
    echo ""
    echo -e "  ${BOLD}PostgreSQL Clusters:${NC}"
    PG_CLUSTERS=$(pg_lsclusters -h 2>/dev/null || true)
    if [ -n "$PG_CLUSTERS" ]; then
        while IFS= read -r line; do
            CL_VER=$(echo "$line" | awk '{print $1}')
            CL_NAME=$(echo "$line" | awk '{print $2}')
            CL_PORT=$(echo "$line" | awk '{print $3}')
            CL_STATUS=$(echo "$line" | awk '{print $4}')
            if [ "$CL_STATUS" = "online" ]; then
                ok "Cluster ${CL_VER}/${CL_NAME} — puerto ${CL_PORT} (online)"
            else
                echo -e "  ${RED}✗ Cluster ${CL_VER}/${CL_NAME} — puerto ${CL_PORT} (${CL_STATUS})${NC}"
                HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
            fi
        done <<< "$PG_CLUSTERS"
    else
        echo -e "  ${RED}✗ No se encontraron clusters PostgreSQL${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi

    # --- 4. Panel DB connection (with current DB_PORT) ---
    echo ""
    echo -e "  ${BOLD}Panel DB (puerto ${DB_PORT}):${NC}"
    if PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h 127.0.0.1 -p "${DB_PORT}" -d "${DB_NAME}" -c "SELECT 1;" > /dev/null 2>&1; then
        ok "Conexion OK — ${DB_NAME}@${DB_USER} en puerto ${DB_PORT}"
        TABLE_COUNT=$(PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h 127.0.0.1 -p "${DB_PORT}" -d "${DB_NAME}" -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null || echo "?")
        ok "Tablas en BD: ${TABLE_COUNT}"
    else
        echo -e "  ${RED}✗ No se puede conectar a ${DB_NAME} en puerto ${DB_PORT}${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi

    # --- 5. Migration check: panel on 5432 needs migration to 5433 ---
    echo ""
    echo -e "  ${BOLD}Migracion PostgreSQL:${NC}"
    PANEL_CLUSTER_EXISTS=$(pg_lsclusters -h 2>/dev/null | awk '$2=="panel"' | wc -l)

    if [ "$DB_PORT" = "5433" ] && [ "$PANEL_CLUSTER_EXISTS" -gt 0 ]; then
        ok "Panel ya usa cluster dedicado en puerto 5433 — no necesita migracion"
    elif [ "$DB_PORT" = "5433" ] && [ "$PANEL_CLUSTER_EXISTS" -eq 0 ]; then
        echo -e "  ${RED}✗ .env dice DB_PORT=5433 pero no existe cluster 'panel'${NC}"
        echo -e "    ${YELLOW}Ejecuta: sudo pg_createcluster ${PG_VER} panel --port 5433 --start${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    elif [ "$DB_PORT" = "5432" ]; then
        echo -e "  ${YELLOW}⚠ Panel usa puerto 5432 (cluster de produccion)${NC}"
        echo -e "  ${YELLOW}  Se recomienda migrar a cluster dedicado en puerto 5433${NC}"
        echo -e "  ${YELLOW}  Para migrar, ejecuta: sudo bash install.sh (opcion 1 — Reinstalar)${NC}"
        HEALTH_WARNINGS=$((HEALTH_WARNINGS + 1))
        if [ "$PANEL_CLUSTER_EXISTS" -gt 0 ]; then
            echo -e "  ${CYAN}  Cluster 'panel' (5433) ya existe — solo falta migrar datos${NC}"
        else
            echo -e "  ${CYAN}  Cluster 'panel' (5433) no existe — se creara al reinstalar${NC}"
        fi
    fi

    # --- 6. MySQL ---
    echo ""
    echo -e "  ${BOLD}MySQL:${NC}"
    if [ "$MYSQL_AUTH_METHOD" = "socket" ]; then
        if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
            ok "$(t health_mysql_ok socket)"
        else
            echo -e "  ${RED}✗ $(t health_mysql_fail socket)${NC}"
            HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
        fi
    elif [ "$MYSQL_AUTH_METHOD" = "password" ]; then
        if mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
            ok "$(t health_mysql_ok password)"
        else
            echo -e "  ${RED}✗ $(t health_mysql_fail password)${NC}"
            HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
        fi
    else
        if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
            ok "MySQL: OK (socket)"
        elif [ -n "$MYSQL_ROOT_PASS" ] && mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
            ok "MySQL: OK (password)"
        else
            warn "MySQL: no se pudo verificar (auth method: ${MYSQL_AUTH_METHOD})"
        fi
    fi

    # --- 7. Caddy ---
    echo ""
    echo -e "  ${BOLD}Caddy:${NC}"
    CADDY_HC=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://127.0.0.1:2019/config/ 2>/dev/null || echo "000")
    if [ "$CADDY_HC" = "200" ]; then
        ok "$(t health_caddy_ok)"
    else
        echo -e "  ${RED}✗ $(t health_caddy_fail "$CADDY_HC")${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi

    if systemctl is-active --quiet caddy 2>/dev/null; then
        CADDY_ON_80=$(ss -tlnp 2>/dev/null | grep ':80\b.*caddy' | wc -l)
        CADDY_ON_443=$(ss -tlnp 2>/dev/null | grep ':443\b.*caddy' | wc -l)
        if [ "$CADDY_ON_80" -gt 0 ] || [ "$CADDY_ON_443" -gt 0 ]; then
            ok "$(t health_caddy_ports)"
        fi
    fi

    # --- 8. PHP-FPM ---
    echo ""
    echo -e "  ${BOLD}PHP-FPM:${NC}"
    if systemctl is-active --quiet "php${PHP_VER}-fpm" 2>/dev/null; then
        ok "$(t health_fpm_ok "$PHP_VER")"
    else
        echo -e "  ${RED}✗ $(t health_fpm_fail "$PHP_VER")${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi

    # --- 9. Cron jobs ---
    echo ""
    echo -e "  ${BOLD}Cron Jobs:${NC}"
    if [ -f /etc/cron.d/musedock-cluster ]; then
        ok "Cluster worker: instalado (/etc/cron.d/musedock-cluster)"
    else
        echo -e "  ${YELLOW}⚠ Cluster worker no instalado${NC}"
        echo -e "    ${YELLOW}Se instalara al reinstalar o ejecuta manualmente:${NC}"
        echo -e "    ${CYAN}echo '* * * * * root /usr/bin/php ${PANEL_DIR}/bin/cluster-worker.php >> ${PANEL_DIR}/storage/logs/cluster-worker.log 2>&1' > /etc/cron.d/musedock-cluster${NC}"
        HEALTH_WARNINGS=$((HEALTH_WARNINGS + 1))
    fi
    if [ -f /etc/cron.d/musedock-backup ]; then
        ok "Panel DB backup: instalado (/etc/cron.d/musedock-backup)"
    else
        echo -e "  ${YELLOW}⚠ Backup periodico del panel no instalado${NC}"
        HEALTH_WARNINGS=$((HEALTH_WARNINGS + 1))
    fi
    if [ -f /etc/cron.d/musedock-filesync ]; then
        ok "File sync worker: instalado (/etc/cron.d/musedock-filesync)"
    else
        echo -e "  ${YELLOW}⚠ File sync worker no instalado${NC}"
        echo -e "    ${YELLOW}Se instalara al reinstalar o ejecuta manualmente:${NC}"
        echo -e "    ${CYAN}echo '* * * * * root /usr/bin/php ${PANEL_DIR}/bin/filesync-worker.php >> ${PANEL_DIR}/storage/logs/filesync-worker.log 2>&1' > /etc/cron.d/musedock-filesync${NC}"
        HEALTH_WARNINGS=$((HEALTH_WARNINGS + 1))
    fi
    if [ -f /etc/cron.d/musedock-failover ]; then
        ok "Failover worker: instalado (/etc/cron.d/musedock-failover)"
    else
        echo -e "  ${YELLOW}⚠ Failover worker no instalado${NC}"
        echo -e "    ${YELLOW}Se instalara al reinstalar o ejecuta manualmente:${NC}"
        echo -e "    ${CYAN}echo '* * * * * root /usr/bin/php ${PANEL_DIR}/bin/failover-worker.php >> ${PANEL_DIR}/storage/logs/failover-worker.log 2>&1' > /etc/cron.d/musedock-failover${NC}"
        HEALTH_WARNINGS=$((HEALTH_WARNINGS + 1))
    fi

    # --- 10. Panel role & .env ---
    echo ""
    echo -e "  ${BOLD}Configuracion:${NC}"
    ok "PANEL_ROLE: ${PANEL_ROLE:-standalone}"
    ok "DB_PORT: ${DB_PORT}"
    ok "PANEL_PORT: ${PANEL_PORT}"

    # --- Summary ---
    echo ""
    if [ "$HEALTH_ERRORS" -eq 0 ] && [ "$HEALTH_WARNINGS" -eq 0 ]; then
        echo -e "  ${GREEN}${BOLD}$(t verify_all_ok)${NC}"
    elif [ "$HEALTH_ERRORS" -eq 0 ]; then
        echo -e "  ${YELLOW}${BOLD}OK con ${HEALTH_WARNINGS} advertencia(s). Revisa arriba.${NC}"
    else
        echo -e "  ${RED}${BOLD}$(t verify_has_errors "$HEALTH_ERRORS")${NC}"
    fi
    echo ""
    exit 0

elif [ "$UPDATE_ONLY" = true ]; then
    # ============================================================
    # Update mode — apply new changes without reinstalling
    # Safe: does NOT touch .env values, does NOT reinstall packages,
    # does NOT reconfigure PostgreSQL/MySQL/Caddy/PHP
    # ============================================================
    set +e

    PANEL_INTERNAL_PORT=$((PANEL_PORT + 1))

    header "$(t update_mode)"

    # --- 1. Ensure directories exist ---
    mkdir -p "${PANEL_DIR}/storage/sessions"
    mkdir -p "${PANEL_DIR}/storage/logs"
    mkdir -p "${PANEL_DIR}/storage/cache"
    install -d -o postgres -g www-data -m 0770 "${PANEL_DIR}/storage/backups"
    mkdir -p /var/www/vhosts
    ok "$(t update_dirs)"

    # --- 2. Add new .env keys if missing (never overwrites existing values) ---
    ENV_KEYS_ADDED=0
    add_env_key() {
        local key="$1" default_value="$2"
        if ! grep -qE "^${key}=" "${PANEL_DIR}/.env" 2>/dev/null; then
            echo "" >> "${PANEL_DIR}/.env"
            echo "${key}=${default_value}" >> "${PANEL_DIR}/.env"
            ok "  + ${key}=${default_value}"
            ENV_KEYS_ADDED=$((ENV_KEYS_ADDED + 1))
        fi
    }

    # Keys added in various versions — add new ones here as the panel evolves
    add_env_key "PANEL_INTERNAL_PORT" "$PANEL_INTERNAL_PORT"
    add_env_key "PANEL_ROLE" "standalone"
    add_env_key "VHOSTS_DIR" "/var/www/vhosts"
    add_env_key "CADDY_API_URL" "http://localhost:2019"
    add_env_key "FPM_SOCKET_DIR" "/run/php"
    add_env_key "SESSION_LIFETIME" "7200"
    add_env_key "ALLOWED_IPS" ""
    add_env_key "PANEL_HSTS_ENABLED" "false"

    if [ "$ENV_KEYS_ADDED" -gt 0 ]; then
        ok "$(t update_env_new_keys) ($ENV_KEYS_ADDED)"
    else
        ok "$(t update_no_new_keys)"
    fi

    # --- 3. Run database schema (safe — IF NOT EXISTS) ---
    # Try configured port, then 5433, then 5432
    UPDATE_DB_PORT=""
    for TRY_PORT in "${DB_PORT}" 5433 5432; do
        DB_TEST=$(PGPASSWORD="${DB_PASS}" timeout 5 psql -U "${DB_USER}" -h 127.0.0.1 -p "${TRY_PORT}" -d "${DB_NAME}" -tAc "SELECT 1;" 2>&1)
        if [ "$DB_TEST" = "1" ]; then
            UPDATE_DB_PORT="$TRY_PORT"
            break
        fi
    done
    if [ -n "$UPDATE_DB_PORT" ]; then
        PGPASSWORD="${DB_PASS}" timeout 15 psql -U "${DB_USER}" -h 127.0.0.1 -p "${UPDATE_DB_PORT}" -d "${DB_NAME}" -f "${PANEL_DIR}/database/schema.sql" > /dev/null 2>&1
        ok "$(t update_schema) (puerto ${UPDATE_DB_PORT})"
    else
        warn "$(t update_schema) — no se pudo conectar a la BD (probados: ${DB_PORT}, 5433, 5432)"
    fi

    # --- 4. Run pending migrations ---
    MIGRATION_DIR="${PANEL_DIR}/database/migrations"
    if [ -d "$MIGRATION_DIR" ] && [ -n "$UPDATE_DB_PORT" ]; then
        MIGRATION_COUNT=0
        # Check which migrations have already been run
        EXISTING_MIGRATIONS=$(PGPASSWORD="${DB_PASS}" timeout 5 psql -U "${DB_USER}" -h 127.0.0.1 -p "${UPDATE_DB_PORT}" -d "${DB_NAME}" -tAc "SELECT migration FROM panel_migrations;" 2>/dev/null || echo "")

        for mig_file in "$MIGRATION_DIR"/*.php; do
            [ -f "$mig_file" ] || continue
            mig_name=$(basename "$mig_file")
            if echo "$EXISTING_MIGRATIONS" | grep -q "$mig_name" 2>/dev/null; then
                continue  # Already executed
            fi
            # Run migration via PHP
            php "$mig_file" 2>/dev/null && MIGRATION_COUNT=$((MIGRATION_COUNT + 1)) && ok "  + $mig_name"
        done

        if [ "$MIGRATION_COUNT" -gt 0 ]; then
            ok "$(t update_migrations) ($MIGRATION_COUNT)"
        else
            ok "$(t update_migrations) (0 pendientes)"
        fi
    fi

    # --- 5. Install/update cron jobs ---
    # Cluster worker
    cat > /etc/cron.d/musedock-cluster << CRONEOF
# MuseDock Panel — Cluster worker (queue, heartbeat, alerts)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/cluster-worker.php >> ${PANEL_DIR}/storage/logs/cluster-worker.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-cluster

    # Panel DB backup
    cat > /etc/cron.d/musedock-backup << CRONEOF
# MuseDock Panel — Hourly panel DB backup
0 * * * * root install -d -o postgres -g www-data -m 0770 ${PANEL_DIR}/storage/backups && runuser -u postgres -- pg_dump -p 5433 musedock_panel | gzip > ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chown postgres:www-data ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chmod 0640 ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz
# Cleanup backups older than 48 hours
5 * * * * root find ${PANEL_DIR}/storage/backups/ -name "panel-*.sql.gz" -mmin +2880 -delete 2>/dev/null
CRONEOF
    chmod 644 /etc/cron.d/musedock-backup

    install_caddy_backup_cron
    run_caddy_config_snapshot

    # File sync worker
    cat > /etc/cron.d/musedock-filesync << CRONEOF
# MuseDock Panel — File sync worker (master -> slave file replication)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/filesync-worker.php >> ${PANEL_DIR}/storage/logs/filesync-worker.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-filesync

    # Monitor collector (network, CPU, RAM — every 30s)
    cat > /etc/cron.d/musedock-monitor << CRONEOF
# MuseDock Panel — Network/system monitoring collector (every 30s)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
* * * * * root sleep 30 && /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
CRONEOF
    chmod 644 /etc/cron.d/musedock-monitor

    # Failover worker (health checks, auto-failover, resync)
    cat > /etc/cron.d/musedock-failover << CRONEOF
# MuseDock Panel — Failover worker (health checks, auto-failover, resync)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/failover-worker.php >> ${PANEL_DIR}/storage/logs/failover-worker.log 2>&1
CRONEOF
    chmod 644 /etc/cron.d/musedock-failover

    systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
    ok "$(t update_crons)"

    # --- 5b. Sync Fail2Ban configs ---
    if command -v fail2ban-client >/dev/null 2>&1 && [ -d "${PANEL_DIR}/config/fail2ban" ]; then
        touch /var/log/musedock-panel-auth.log /var/log/musedock-portal-auth.log 2>/dev/null
        chmod 644 /var/log/musedock-panel-auth.log /var/log/musedock-portal-auth.log 2>/dev/null
        mkdir -p /var/log/caddy 2>/dev/null
        touch /var/log/caddy/hosting-access.log 2>/dev/null
        chmod 644 /var/log/caddy/hosting-access.log 2>/dev/null

        for f in "${PANEL_DIR}"/config/fail2ban/filter.d/*.conf; do
            [ -f "$f" ] && cp "$f" /etc/fail2ban/filter.d/
        done
        cp "${PANEL_DIR}/config/fail2ban/musedock.conf" /etc/fail2ban/jail.d/musedock.conf 2>/dev/null
        [ -f "${PANEL_DIR}/config/fail2ban/logrotate-musedock-auth" ] && cp "${PANEL_DIR}/config/fail2ban/logrotate-musedock-auth" /etc/logrotate.d/musedock-auth 2>/dev/null

        if systemctl is-active --quiet fail2ban 2>/dev/null; then
            fail2ban-client reload >/dev/null 2>&1
        fi
        ok "Fail2Ban configs sincronizados"
    fi

    # --- 6. Fix permissions ---
    chmod 600 "${PANEL_DIR}/.env"
    chmod -R 750 "${PANEL_DIR}/storage"
    ok "$(t update_perms)"

    # --- 7. Update systemd service file and restart ---
    if [ -f "${PANEL_DIR}/bin/musedock-panel.service" ]; then
        sed -e "s|__PANEL_DIR__|${PANEL_DIR}|g" \
            -e "s|__PANEL_PORT__|${PANEL_PORT}|g" \
            -e "s|__PANEL_INTERNAL_PORT__|${PANEL_INTERNAL_PORT}|g" \
            "${PANEL_DIR}/bin/musedock-panel.service" > /etc/systemd/system/musedock-panel.service
        systemctl daemon-reload
    fi
    systemctl restart musedock-panel 2>/dev/null
    ok "$(t update_service)"

    echo ""
    echo -e "  ${GREEN}${BOLD}$(t update_complete)${NC}"
    echo ""

    # Fall through to health check below

else
# Begin install flow
header "$(t checking_conflicts)"

PLESK_DETECTED=false
NGINX_DETECTED=false
APACHE_DETECTED=false
NGINX_ON_HTTP=false
APACHE_ON_HTTP=false
CADDY_PREINSTALLED=false

# --- Early Caddy detection (before nginx/apache, to explain port ownership) ---
if command -v caddy &> /dev/null && systemctl is-active --quiet caddy 2>/dev/null; then
    CADDY_ON_80=$(ss -tlnp 2>/dev/null | grep ':80\b.*caddy' | wc -l)
    CADDY_ON_443=$(ss -tlnp 2>/dev/null | grep ':443\b.*caddy' | wc -l)
    if [ "$CADDY_ON_80" -gt 0 ] || [ "$CADDY_ON_443" -gt 0 ]; then
        CADDY_PREINSTALLED=true
        echo ""
        echo -e "  ${GREEN}${BOLD}$(t caddy_already_running)${NC}"
        echo -e "  ${GREEN}  $(t caddy_already_desc)${NC}"
        # Count active routes
        CADDY_EARLY_ROUTES=$(curl -s --max-time 3 http://127.0.0.1:2019/config/apps/http/servers/srv0/routes 2>/dev/null | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
        if [ "$CADDY_EARLY_ROUTES" -gt 0 ] 2>/dev/null; then
            echo -e "  ${GREEN}  $(t caddy_has_routes "$CADDY_EARLY_ROUTES")${NC}"
        fi
        echo ""
    fi
fi

# --- Plesk detection ---
if [ -d /usr/local/psa ] || command -v psa &> /dev/null || [ -f /etc/init.d/psa ]; then
    PLESK_DETECTED=true
    PLESK_VERSION=$(plesk version 2>/dev/null | head -1 || echo "unknown version")
    echo ""
    echo -e "  ${RED}${BOLD}╔══════════════════════════════════════════════════╗${NC}"
    echo -e "  ${RED}${BOLD}║  $(t plesk_warning)${NC}"
    echo -e "  ${RED}${BOLD}╚══════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "  ${YELLOW}$(t plesk_version "$PLESK_VERSION")${NC}"
    echo -e "  ${YELLOW}$(t plesk_desc)${NC}"
    echo ""
    echo "  $(t existing_options)"
    echo "    1) $(t plesk_opt_abort)"
    echo "    2) $(t plesk_opt_continue)"
    echo ""
    prompt_read "  Choose [1/2] (default: 1): " PLESK_CHOICE
    PLESK_CHOICE=${PLESK_CHOICE:-1}

    if [ "$PLESK_CHOICE" = "1" ]; then
        echo ""
        echo -e "  $(t plesk_aborted)"
        exit 0
    else
        warn "$(t plesk_continuing)"
    fi
fi

# --- Nginx detection ---
if command -v nginx &> /dev/null; then
    NGINX_DETECTED=true
    NGINX_VERSION=$(nginx -v 2>&1 | head -1 || echo "unknown")

    # Check if nginx is listening on 80 or 443
    NGINX_PORTS=""
    if ss -tlnp 2>/dev/null | grep -q 'nginx.*:80\b'; then
        NGINX_ON_HTTP=true
        NGINX_PORTS="80"
    fi
    if ss -tlnp 2>/dev/null | grep -q 'nginx.*:443\b'; then
        NGINX_ON_HTTP=true
        NGINX_PORTS="${NGINX_PORTS:+$NGINX_PORTS, }443"
    fi

    # Check if nginx is managed by Plesk
    NGINX_IS_PLESK=false
    if [ "$PLESK_DETECTED" = true ]; then
        if nginx -V 2>&1 | grep -qi plesk 2>/dev/null || [ -f /etc/nginx/plesk.conf.d/server.conf ]; then
            NGINX_IS_PLESK=true
        fi
    fi

    echo ""
    echo -e "  ${YELLOW}${BOLD}$(t nginx_detected "$NGINX_VERSION")${NC}"
    if [ "$NGINX_IS_PLESK" = true ]; then
        echo -e "  ${YELLOW}  $(t nginx_plesk_managed)${NC}"
    fi
    if [ -n "$NGINX_PORTS" ]; then
        echo -e "  ${YELLOW}  $(t listening_ports "$NGINX_PORTS")${NC}"
        echo -e "  ${YELLOW}  $(t caddy_port_conflict)${NC}"
    elif [ "$CADDY_PREINSTALLED" = true ]; then
        echo -e "  ${GREEN}  $(t nginx_not_on_ports_caddy)${NC}"
    else
        echo -e "  ${GREEN}  $(t no_port_conflict Nginx)${NC}"
        echo -e "  ${GREEN}  $(t no_conflict_caddy)${NC}"
    fi

    if [ "$NGINX_ON_HTTP" = true ] && [ "$NGINX_IS_PLESK" = false ]; then
        echo ""
        echo "  $(t existing_options)"
        echo "    1) $(t opt_stop_disable nginx)"
        echo "    2) $(t opt_keep_running nginx)"
        echo "    3) $(t opt_abort)"
        echo ""
        prompt_read "  Choose [1/2/3] (default: 1): " NGINX_CHOICE
        NGINX_CHOICE=${NGINX_CHOICE:-1}

        case "$NGINX_CHOICE" in
            1)
                # Backup nginx config before disabling
                BACKUP_TS=$(date +%Y%m%d%H%M%S)
                if [ -d /etc/nginx ]; then
                    tar czf "/etc/nginx.backup.${BACKUP_TS}.tar.gz" /etc/nginx 2>/dev/null || true
                    ok "$(t config_backed_up Nginx "/etc/nginx.backup.${BACKUP_TS}.tar.gz")"
                fi
                systemctl stop nginx 2>/dev/null || true
                systemctl disable nginx 2>/dev/null || true
                ok "$(t stopped_disabled Nginx)"
                echo -e "  ${CYAN}  $(t to_reenable nginx)${NC}"
                echo -e "  ${CYAN}  Config backup: /etc/nginx.backup.${BACKUP_TS}.tar.gz${NC}"
                ;;
            2)
                warn "$(t keep_running_warn Nginx)"
                warn "$(t caddy_fail_bind)"
                ;;
            3)
                echo ""
                echo -e "  $(t installation_aborted)"
                exit 0
                ;;
        esac
    elif [ "$NGINX_ON_HTTP" = true ] && [ "$NGINX_IS_PLESK" = true ]; then
        warn "$(t plesk_managed_warn Nginx)"
        warn "$(t plesk_resolve_manual)"
        prompt_read "  $(t continue_anyway)" NGINX_PLESK_CONTINUE
        if [[ ! "$NGINX_PLESK_CONTINUE" =~ ^[YySs]$ ]]; then
            echo "  $(t installation_aborted)"
            exit 0
        fi
    fi
fi

# --- Apache detection ---
if command -v apache2 &> /dev/null || command -v httpd &> /dev/null; then
    APACHE_DETECTED=true
    APACHE_VERSION=$(apache2 -v 2>/dev/null | head -1 || httpd -v 2>/dev/null | head -1 || echo "unknown")
    APACHE_SVC="apache2"
    command -v apache2 &> /dev/null || APACHE_SVC="httpd"

    # Check if Apache is listening on 80 or 443
    APACHE_PORTS=""
    if ss -tlnp 2>/dev/null | grep -qE '(apache2|httpd).*:80\b'; then
        APACHE_ON_HTTP=true
        APACHE_PORTS="80"
    fi
    if ss -tlnp 2>/dev/null | grep -qE '(apache2|httpd).*:443\b'; then
        APACHE_ON_HTTP=true
        APACHE_PORTS="${APACHE_PORTS:+$APACHE_PORTS, }443"
    fi

    # Check if Apache is managed by Plesk
    APACHE_IS_PLESK=false
    if [ "$PLESK_DETECTED" = true ]; then
        if [ -f /etc/apache2/plesk.conf.d/roundcube.conf ] || [ -d /usr/local/psa/admin/htdocs ]; then
            APACHE_IS_PLESK=true
        fi
    fi

    echo ""
    echo -e "  ${YELLOW}${BOLD}$(t apache_detected "$APACHE_VERSION")${NC}"
    if [ "$APACHE_IS_PLESK" = true ]; then
        echo -e "  ${YELLOW}  $(t apache_plesk_managed)${NC}"
    fi
    if [ -n "$APACHE_PORTS" ]; then
        echo -e "  ${YELLOW}  $(t listening_ports "$APACHE_PORTS")${NC}"
        echo -e "  ${YELLOW}  $(t caddy_port_conflict)${NC}"
    elif [ "$CADDY_PREINSTALLED" = true ]; then
        echo -e "  ${GREEN}  $(t apache_not_on_ports_caddy)${NC}"
    else
        echo -e "  ${GREEN}  $(t no_port_conflict Apache)${NC}"
        echo -e "  ${GREEN}  $(t no_conflict_caddy)${NC}"
    fi

    if [ "$APACHE_ON_HTTP" = true ] && [ "$APACHE_IS_PLESK" = false ]; then
        echo ""
        echo "  $(t existing_options)"
        echo "    1) $(t opt_stop_disable Apache)"
        echo "    2) $(t opt_keep_running Apache)"
        echo "    3) $(t opt_abort)"
        echo ""
        prompt_read "  Choose [1/2/3] (default: 1): " APACHE_CHOICE
        APACHE_CHOICE=${APACHE_CHOICE:-1}

        case "$APACHE_CHOICE" in
            1)
                # Backup Apache config before disabling
                BACKUP_TS=$(date +%Y%m%d%H%M%S)
                APACHE_CONF_DIR="/etc/${APACHE_SVC}"
                if [ -d "$APACHE_CONF_DIR" ]; then
                    tar czf "${APACHE_CONF_DIR}.backup.${BACKUP_TS}.tar.gz" "$APACHE_CONF_DIR" 2>/dev/null || true
                    ok "$(t config_backed_up Apache "${APACHE_CONF_DIR}.backup.${BACKUP_TS}.tar.gz")"
                fi
                systemctl stop "$APACHE_SVC" 2>/dev/null || true
                systemctl disable "$APACHE_SVC" 2>/dev/null || true
                ok "$(t stopped_disabled Apache)"
                echo -e "  ${CYAN}  $(t to_reenable "$APACHE_SVC")${NC}"
                echo -e "  ${CYAN}  Config backup: ${APACHE_CONF_DIR}.backup.${BACKUP_TS}.tar.gz${NC}"
                ;;
            2)
                warn "$(t keep_running_warn Apache)"
                warn "$(t caddy_fail_bind)"
                ;;
            3)
                echo ""
                echo -e "  $(t installation_aborted)"
                exit 0
                ;;
        esac
    elif [ "$APACHE_ON_HTTP" = true ] && [ "$APACHE_IS_PLESK" = true ]; then
        warn "$(t plesk_managed_warn Apache)"
        warn "$(t plesk_resolve_manual)"
        prompt_read "  $(t continue_anyway)" APACHE_PLESK_CONTINUE
        if [[ ! "$APACHE_PLESK_CONTINUE" =~ ^[YySs]$ ]]; then
            echo "  $(t installation_aborted)"
            exit 0
        fi
    fi
fi

# ============================================================
# Migrate sites from Nginx/Apache to Caddy (deferred — saved to file, applied after Caddy is up)
# ============================================================
MIGRATE_SITES_FILE="/tmp/musedock-migrate-sites.json"
rm -f "$MIGRATE_SITES_FILE"

# Extract domains already configured in Caddyfile (to skip from migration)
CADDYFILE_EXISTING_DOMAINS=""
if [ -f /etc/caddy/Caddyfile ]; then
    CADDYFILE_EXISTING_DOMAINS=$(grep -oE '^[a-zA-Z0-9][a-zA-Z0-9._-]*\.[a-zA-Z]{2,}\s*\{' /etc/caddy/Caddyfile 2>/dev/null | sed 's/\s*{//' | tr '\n' ' ')
fi

migrate_nginx_sites() {
    local SITES_DIR="/etc/nginx/sites-enabled"
    [ -d "$SITES_DIR" ] || SITES_DIR="/etc/nginx/conf.d"
    [ -d "$SITES_DIR" ] || return

    header "$(t migrate_header Nginx)"

    local SITES_FOUND=0
    local SITES_JSON="["
    local SEEN_DOMAINS=""
    local SEEN_FILES=""

    for conf in "$SITES_DIR"/*; do
        [ -f "$conf" ] || continue
        # Resolve symlinks to avoid processing the same file twice
        local REAL_CONF
        REAL_CONF=$(readlink -f "$conf" 2>/dev/null || echo "$conf")
        case "$SEEN_FILES" in *"|${REAL_CONF}|"*) continue ;; esac
        SEEN_FILES="${SEEN_FILES}|${REAL_CONF}|"

        # Skip default/catch-all configs
        case "$(basename "$conf")" in
            default|default.conf) continue ;;
        esac

        ok "$(t migrate_parsing "$(basename "$conf")")"

        # Extract server_name (first non-localhost, non-_ value)
        local SERVER_NAME=""
        SERVER_NAME=$(grep -E '^\s*server_name\s+' "$conf" 2>/dev/null | head -1 | sed 's/.*server_name\s\+//; s/\s*;.*//' | tr -s ' ' '\n' | grep -vE '^(_|localhost|127\.|$)' | head -1)

        if [ -z "$SERVER_NAME" ]; then
            warn "$(t migrate_skipped "$(basename "$conf")")"
            continue
        fi

        # Skip duplicate domains (multiple configs for same domain)
        case "$SEEN_DOMAINS" in *"|${SERVER_NAME}|"*) continue ;; esac
        SEEN_DOMAINS="${SEEN_DOMAINS}|${SERVER_NAME}|"

        # Skip domains already configured in Caddyfile
        case "$CADDYFILE_EXISTING_DOMAINS" in *"${SERVER_NAME}"*)
            ok "$(t migrate_skipped_caddy "$SERVER_NAME")"
            continue ;;
        esac

        # Extract root directive
        local DOC_ROOT=""
        DOC_ROOT=$(grep -E '^\s*root\s+' "$conf" 2>/dev/null | head -1 | sed 's/.*root\s\+//; s/\s*;.*//')

        if [ -z "$DOC_ROOT" ]; then
            DOC_ROOT="/var/www/vhosts/${SERVER_NAME}/httpdocs"
        fi

        # Detect PHP version from fastcgi_pass socket path
        local PHP_VER="8.3"
        local FPM_SOCK=""
        FPM_SOCK=$(grep -oE 'unix:/run/php/php[0-9.]+-fpm[^;]*\.sock' "$conf" 2>/dev/null | head -1)
        if [ -z "$FPM_SOCK" ]; then
            FPM_SOCK=$(grep -oE 'unix:/var/run/php/php[0-9.]+-fpm[^;]*\.sock' "$conf" 2>/dev/null | head -1)
        fi
        if [ -n "$FPM_SOCK" ]; then
            PHP_VER=$(echo "$FPM_SOCK" | grep -oE 'php[0-9.]+' | sed 's/php//')
        fi

        # Detect username from socket name (php8.3-fpm-USERNAME.sock) or from doc root owner
        local SITE_USER=""
        if [ -n "$FPM_SOCK" ]; then
            SITE_USER=$(echo "$FPM_SOCK" | grep -oE 'fpm-[^.]+' | sed 's/fpm-//')
        fi
        if [ -z "$SITE_USER" ] && [ -d "$DOC_ROOT" ]; then
            SITE_USER=$(stat -c '%U' "$DOC_ROOT" 2>/dev/null)
        fi
        [ -z "$SITE_USER" ] && SITE_USER="www-data"

        ok "$(t migrate_found "$SERVER_NAME" "$DOC_ROOT")"

        # Append to JSON array
        [ "$SITES_FOUND" -gt 0 ] && SITES_JSON="${SITES_JSON},"
        SITES_JSON="${SITES_JSON}{\"domain\":\"${SERVER_NAME}\",\"root\":\"${DOC_ROOT}\",\"user\":\"${SITE_USER}\",\"php\":\"${PHP_VER}\"}"
        SITES_FOUND=$((SITES_FOUND + 1))
    done

    SITES_JSON="${SITES_JSON}]"

    if [ "$SITES_FOUND" -eq 0 ]; then
        warn "$(t migrate_none Nginx)"
        return
    fi

    echo ""
    prompt_read "  $(t migrate_ask "$SITES_FOUND" Nginx)" MIGRATE_CONFIRM
    MIGRATE_CONFIRM=${MIGRATE_CONFIRM:-s}

    if [[ "$MIGRATE_CONFIRM" =~ ^[YySs]$ ]] || [ -z "$MIGRATE_CONFIRM" ]; then
        echo "$SITES_JSON" > "$MIGRATE_SITES_FILE"
        ok "$(t migrate_saved "$MIGRATE_SITES_FILE")"
    else
        warn "$(t migrate_skip_user)"
    fi
}

migrate_apache_sites() {
    local SITES_DIR="/etc/apache2/sites-enabled"
    [ -d "$SITES_DIR" ] || SITES_DIR="/etc/httpd/conf.d"
    [ -d "$SITES_DIR" ] || return

    header "$(t migrate_header Apache)"

    # Collect domains already migrated from nginx to skip them
    local EXISTING_DOMAINS=""
    local SITES_FOUND=0
    local SITES_JSON="["
    local SEEN_FILES=""
    local NEW_APACHE_FOUND=0

    if [ -f "$MIGRATE_SITES_FILE" ]; then
        local EXISTING
        EXISTING=$(cat "$MIGRATE_SITES_FILE")
        SITES_JSON="${EXISTING%]}"
        SITES_FOUND=$(echo "$EXISTING" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
        EXISTING_DOMAINS=$(echo "$EXISTING" | python3 -c "import sys,json; print(' '.join(s['domain'] for s in json.load(sys.stdin)))" 2>/dev/null || echo "")
    fi

    for conf in "$SITES_DIR"/*; do
        [ -f "$conf" ] || continue
        # Resolve symlinks to avoid processing same file twice
        local REAL_CONF
        REAL_CONF=$(readlink -f "$conf" 2>/dev/null || echo "$conf")
        case "$SEEN_FILES" in *"|${REAL_CONF}|"*) continue ;; esac
        SEEN_FILES="${SEEN_FILES}|${REAL_CONF}|"

        # Skip default configs and SSL duplicates (Apache -le-ssl.conf = same vhost with SSL)
        case "$(basename "$conf")" in
            000-default*|default*) continue ;;
            *-le-ssl.conf) continue ;;
        esac

        ok "$(t migrate_parsing "$(basename "$conf")")"

        local SERVER_NAME=""
        SERVER_NAME=$(grep -iE '^\s*ServerName\s+' "$conf" 2>/dev/null | head -1 | sed 's/.*ServerName\s\+//i; s/\s*$//' | tr -d ' ')

        if [ -z "$SERVER_NAME" ]; then
            warn "$(t migrate_skipped "$(basename "$conf")")"
            continue
        fi

        # Skip if domain already found from nginx or already seen in this loop
        case "$EXISTING_DOMAINS" in *"${SERVER_NAME}"*) continue ;; esac

        # Skip domains already configured in Caddyfile
        case "$CADDYFILE_EXISTING_DOMAINS" in *"${SERVER_NAME}"*)
            ok "$(t migrate_skipped_caddy "$SERVER_NAME")"
            continue ;;
        esac

        local DOC_ROOT=""
        DOC_ROOT=$(grep -iE '^\s*DocumentRoot\s+' "$conf" 2>/dev/null | head -1 | sed 's/.*DocumentRoot\s\+//i; s/\s*$//' | tr -d '"')

        [ -z "$DOC_ROOT" ] && DOC_ROOT="/var/www/vhosts/${SERVER_NAME}/httpdocs"

        # Detect PHP from FPM proxy
        local PHP_VER="8.3"
        local FPM_SOCK=""
        FPM_SOCK=$(grep -oE 'unix:/run/php/php[0-9.]+-fpm[^|]*\.sock' "$conf" 2>/dev/null | head -1)
        if [ -n "$FPM_SOCK" ]; then
            PHP_VER=$(echo "$FPM_SOCK" | grep -oE 'php[0-9.]+' | sed 's/php//')
        fi

        local SITE_USER=""
        if [ -d "$DOC_ROOT" ]; then
            SITE_USER=$(stat -c '%U' "$DOC_ROOT" 2>/dev/null)
        fi
        [ -z "$SITE_USER" ] && SITE_USER="www-data"

        ok "$(t migrate_found "$SERVER_NAME" "$DOC_ROOT")"

        [ "$SITES_FOUND" -gt 0 ] && SITES_JSON="${SITES_JSON},"
        SITES_JSON="${SITES_JSON}{\"domain\":\"${SERVER_NAME}\",\"root\":\"${DOC_ROOT}\",\"user\":\"${SITE_USER}\",\"php\":\"${PHP_VER}\"}"
        SITES_FOUND=$((SITES_FOUND + 1))
        NEW_APACHE_FOUND=$((NEW_APACHE_FOUND + 1))
        EXISTING_DOMAINS="${EXISTING_DOMAINS} ${SERVER_NAME}"
    done

    SITES_JSON="${SITES_JSON}]"

    if [ "$NEW_APACHE_FOUND" -eq 0 ]; then
        warn "$(t migrate_none Apache)"
        return
    fi

    echo ""
    prompt_read "  $(t migrate_ask "$NEW_APACHE_FOUND" Apache)" MIGRATE_CONFIRM
    MIGRATE_CONFIRM=${MIGRATE_CONFIRM:-s}

    if [[ "$MIGRATE_CONFIRM" =~ ^[YySs]$ ]] || [ -z "$MIGRATE_CONFIRM" ]; then
        echo "$SITES_JSON" > "$MIGRATE_SITES_FILE"
        ok "$(t migrate_saved "$MIGRATE_SITES_FILE")"
    else
        warn "$(t migrate_skip_user)"
    fi
}

# Run migration discovery if nginx/apache were detected and user chose to stop them
if [ "$NGINX_DETECTED" = true ]; then
    migrate_nginx_sites
fi
if [ "$APACHE_DETECTED" = true ]; then
    migrate_apache_sites
fi

# Summary of detections
if [ "$NGINX_DETECTED" = false ] && [ "$APACHE_DETECTED" = false ] && [ "$PLESK_DETECTED" = false ]; then
    ok "$(t no_conflicts)"
fi

# ============================================================
# Configuration prompts
# ============================================================
header "$(t configuration)"

PANEL_PORT=8444

# Panel port
ask "$(t panel_port_prompt)" USER_PORT
PANEL_PORT=${USER_PORT:-8444}

# PHP version
echo ""
echo -e "  ${BOLD}$(t php_version)${NC}"
echo "  $(t php_available)"
ask "$(t php_version_prompt)" PHP_VER
PHP_VER=${PHP_VER:-8.3}

# Validate PHP version
case "$PHP_VER" in
    8.1|8.2|8.3|8.4) ;;
    *) warn "$(t php_invalid)"; PHP_VER="8.3" ;;
esac

echo ""
echo -e "  ${BOLD}$(t summary)${NC}"
echo "  - $(t summary_port "$PANEL_PORT")"
echo "  - $(t summary_php "$PHP_VER")"
echo "  - $(t summary_dir "$PANEL_DIR")"
echo -e "  - ${CYAN}$(t summary_admin)${NC}"
echo ""

prompt_read "  $(t proceed_install)" CONFIRM
CONFIRM=${CONFIRM:-Y}
if [[ ! "$CONFIRM" =~ ^[YySs]$ ]]; then
    echo "  $(t install_cancelled)"
    exit 0
fi

# ============================================================
# Pre-installation snapshot
# ============================================================
header "$(t snapshot_header)"

SNAPSHOT_DIR="${PANEL_DIR}/install-backup"
SNAPSHOT_TS=$(date +%Y%m%d%H%M%S)
mkdir -p "${SNAPSHOT_DIR}/${SNAPSHOT_TS}"

# Running services
systemctl list-units --type=service --state=running --no-pager --no-legend \
    > "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/services-running.txt" 2>/dev/null
ok "$(t snapshot_services)"

# Listening ports
ss -tlnp > "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/ports-listening.txt" 2>/dev/null
ok "$(t snapshot_ports)"

# Caddy config
if [ -f /etc/caddy/Caddyfile ]; then
    cp /etc/caddy/Caddyfile "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/Caddyfile.bak"
    ok "$(t snapshot_caddyfile)"
fi
# Caddy autosave.json
SNAP_CADDY_HOME=$(caddy environ 2>/dev/null | grep 'caddy.AppConfigDir=' | cut -d= -f2 || echo "")
if [ -z "$SNAP_CADDY_HOME" ]; then
    for d in /var/lib/caddy/.config/caddy /root/.config/caddy /home/caddy/.config/caddy; do
        [ -f "${d}/autosave.json" ] && SNAP_CADDY_HOME="$d" && break
    done
fi
if [ -n "$SNAP_CADDY_HOME" ] && [ -f "${SNAP_CADDY_HOME}/autosave.json" ]; then
    cp "${SNAP_CADDY_HOME}/autosave.json" "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/autosave.json.bak"
    ok "$(t snapshot_autosave)"
fi

# Nginx config
if [ -d /etc/nginx ]; then
    tar czf "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/nginx-config.tar.gz" /etc/nginx 2>/dev/null && \
        ok "$(t snapshot_nginx)" || true
fi

# Apache config
for apache_dir in /etc/apache2 /etc/httpd; do
    if [ -d "$apache_dir" ]; then
        tar czf "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/apache-config.tar.gz" "$apache_dir" 2>/dev/null && \
            ok "$(t snapshot_apache)" || true
        break
    fi
done

# PostgreSQL pg_hba.conf
PG_HBA_SNAP=$(find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1)
if [ -n "$PG_HBA_SNAP" ] && [ -f "$PG_HBA_SNAP" ]; then
    cp "$PG_HBA_SNAP" "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/pg_hba.conf.bak"
    ok "$(t snapshot_pghba)"
fi

# Existing .env
if [ -f "${PANEL_DIR}/.env" ]; then
    cp "${PANEL_DIR}/.env" "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/env.bak"
    ok "$(t snapshot_env)"
fi

# Installed packages relevant to us
dpkg -l | grep -E '(caddy|nginx|apache2|postgresql|mysql|mariadb|php)' \
    > "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/packages-installed.txt" 2>/dev/null || true
ok "$(t snapshot_packages)"

# Symlink latest snapshot for easy access
ln -sfn "${SNAPSHOT_DIR}/${SNAPSHOT_TS}" "${SNAPSHOT_DIR}/latest"
ok "$(t snapshot_saved "${SNAPSHOT_DIR}/${SNAPSHOT_TS}/")"

# ============================================================
# Generate database password
# ============================================================
DB_PASS=$(openssl rand -hex 16)
DB_NAME="musedock_panel"
DB_USER="musedock_panel"

# ============================================================
# Step 1: System packages
# ============================================================
header "$(t step_packages)"

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
ok "$(t pkg_updated)"

apt-get install -y -qq curl wget gnupg2 lsb-release apt-transport-https ca-certificates \
    software-properties-common unzip git acl > /dev/null 2>&1
ok "$(t pkg_installed)"

# ============================================================
# Step 2: PHP
# ============================================================
header "$(t step_php "$PHP_VER")"
PHP_INSTALL_LOG="/tmp/musedock-panel-install-php.log"
: > "$PHP_INSTALL_LOG" 2>/dev/null || true

# Add Ondrej PPA for PHP (check multiple possible filenames)
PHP_REPO_EXISTS=false
for f in /etc/apt/sources.list.d/ondrej-*.list /etc/apt/sources.list.d/php.list /etc/apt/sources.list.d/ondrej-*.sources; do
    if [ -f "$f" ] 2>/dev/null; then
        PHP_REPO_EXISTS=true
        break
    fi
done

if [ "$PHP_REPO_EXISTS" = false ]; then
    if [ "$OS_ID" = "ubuntu" ]; then
        if ! timeout 90 add-apt-repository -y ppa:ondrej/php >> "$PHP_INSTALL_LOG" 2>&1; then
            if ! install_ondrej_php_repo_manual; then
                tail -n 60 "$PHP_INSTALL_LOG" | sed 's/^/    /'
                fail "No se pudo añadir el repositorio PHP ondrej ni por add-apt-repository ni por fallback directo. Revisa red/DNS/Launchpad."
            fi
        fi
    else
        if ! curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb >> "$PHP_INSTALL_LOG" 2>&1; then
            tail -n 40 "$PHP_INSTALL_LOG" | sed 's/^/    /'
            fail "No se pudo descargar la keyring de packages.sury.org para PHP."
        fi
        if ! dpkg -i /tmp/debsuryorg-archive-keyring.deb >> "$PHP_INSTALL_LOG" 2>&1; then
            tail -n 40 "$PHP_INSTALL_LOG" | sed 's/^/    /'
            fail "No se pudo instalar la keyring de packages.sury.org para PHP."
        fi
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        rm -f /tmp/debsuryorg-archive-keyring.deb
    fi
    if ! apt-get update -qq >> "$PHP_INSTALL_LOG" 2>&1; then
        tail -n 40 "$PHP_INSTALL_LOG" | sed 's/^/    /'
        fail "No se pudo actualizar APT despues de añadir el repositorio PHP."
    fi
    ok "$(t php_repo_added)"
else
    ok "$(t php_repo_exists)"
fi

PHP_PACKAGES=(
    "php${PHP_VER}-cli"
    "php${PHP_VER}-fpm"
    "php${PHP_VER}-pgsql"
    "php${PHP_VER}-curl"
    "php${PHP_VER}-mbstring"
    "php${PHP_VER}-xml"
    "php${PHP_VER}-zip"
    "php${PHP_VER}-gd"
    "php${PHP_VER}-intl"
    "php${PHP_VER}-bcmath"
    "php${PHP_VER}-mysql"
)
if ! apt-get install -y -qq "${PHP_PACKAGES[@]}" >> "$PHP_INSTALL_LOG" 2>&1; then
    tail -n 60 "$PHP_INSTALL_LOG" | sed 's/^/    /'
    fail "No se pudo instalar PHP ${PHP_VER}. Revisa ${PHP_INSTALL_LOG}. Si el repo no ofrece PHP ${PHP_VER}, relanza el instalador y elige PHP 8.3."
fi
ok "$(t php_installed "$PHP_VER")"

if ! systemctl enable php${PHP_VER}-fpm >> "$PHP_INSTALL_LOG" 2>&1; then
    tail -n 40 "$PHP_INSTALL_LOG" | sed 's/^/    /'
    fail "No se pudo habilitar php${PHP_VER}-fpm."
fi
if ! systemctl start php${PHP_VER}-fpm >> "$PHP_INSTALL_LOG" 2>&1; then
    tail -n 40 "$PHP_INSTALL_LOG" | sed 's/^/    /'
    fail "No se pudo arrancar php${PHP_VER}-fpm."
fi
ok "$(t php_fpm_started "$PHP_VER")"

# ============================================================
# Step 3: PostgreSQL (dual cluster: main on 5432, panel on 5433)
# ============================================================
header "$(t step_pgsql)"

if ! command -v psql &> /dev/null; then
    apt-get install -y -qq postgresql postgresql-client > /dev/null 2>&1
    ok "$(t pgsql_installed)"
else
    ok "$(t pgsql_exists)"
fi

systemctl enable postgresql > /dev/null 2>&1
if ! systemctl is-active --quiet postgresql 2>/dev/null; then
    timeout 30 systemctl start postgresql || fail "PostgreSQL no arranco en 30s. Revisa: journalctl -u postgresql --no-pager -n 80"
fi
ok "$(t pgsql_running)"

# Detect PostgreSQL major version
ok "$(t pgsql_detect_version)"
PG_VER=$(pg_lsclusters -h 2>/dev/null | head -1 | awk '{print $1}')
if [ -z "$PG_VER" ]; then
    fail "Could not detect PostgreSQL version from pg_lsclusters"
fi
ok "$(t pgsql_version_found "$PG_VER")"

# Show current clusters
header "$(t pgsql_dual_header)"

pg_port_ready() {
    local PORT="${1:-5433}"
    if command -v pg_isready >/dev/null 2>&1; then
        pg_isready -h 127.0.0.1 -p "$PORT" -t 1 >/dev/null 2>&1 && return 0
    fi
    ss -tln 2>/dev/null | grep -qE "127\\.0\\.0\\.1:${PORT}\\b|\\*:${PORT}\\b|0\\.0\\.0\\.0:${PORT}\\b|\\[::\\]:${PORT}\\b"
}

wait_pg_port_ready() {
    local PORT="${1:-5433}"
    local MAX_WAIT="${2:-5}"
    local i
    for i in $(seq 1 "$MAX_WAIT"); do
        if pg_port_ready "$PORT"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

fail_panel_pg_unavailable() {
    local PORT="${1:-5433}"
    warn "PostgreSQL cluster 'panel' no responde en 127.0.0.1:${PORT}"
    echo "    Diagnostico rapido:"
    echo "    sudo pg_lsclusters"
    echo "    sudo systemctl status postgresql@${PG_VER}-panel --no-pager"
    echo "    sudo tail -n 80 /var/log/postgresql/postgresql-${PG_VER}-panel.log"
    fail "No se puede continuar hasta que PostgreSQL del panel responda en ${PORT}."
}

# Function to ensure the panel cluster exists on port 5433
ensure_panel_cluster() {
    if pg_lsclusters -h 2>/dev/null | awk '{print $2}' | grep -qw "panel"; then
        local PANEL_PORT_ACTUAL
        PANEL_PORT_ACTUAL=$(pg_lsclusters -h 2>/dev/null | awk '$2=="panel" {print $3}')
        ok "$(t pgsql_cluster_exists "panel" "$PANEL_PORT_ACTUAL")"
        if [ "$PANEL_PORT_ACTUAL" != "5433" ]; then
            fail "El cluster PostgreSQL 'panel' existe en puerto ${PANEL_PORT_ACTUAL}, pero MuseDock espera 5433. Corrige el cluster antes de reinstalar."
        fi
        if pg_port_ready 5433; then
            return 0
        fi
        # Ensure it is running
        local PANEL_STATUS
        PANEL_STATUS=$(pg_lsclusters -h 2>/dev/null | awk '$2=="panel" {print $4}')
        if [ "$PANEL_STATUS" = "online" ]; then
            warn "PostgreSQL panel figura online pero no responde; reiniciando cluster ${PG_VER}/panel"
            timeout 20 pg_ctlcluster "$PG_VER" panel restart 2>/dev/null || true
        else
            warn "PostgreSQL panel esta ${PANEL_STATUS:-unknown}; arrancando cluster ${PG_VER}/panel"
            timeout 20 pg_ctlcluster "$PG_VER" panel start 2>/dev/null || true
        fi
        wait_pg_port_ready 5433 5 || fail_panel_pg_unavailable 5433
    else
        timeout 60 pg_createcluster "$PG_VER" panel --port 5433 --start 2>/dev/null
        wait_pg_port_ready 5433 5 || fail_panel_pg_unavailable 5433
        ok "$(t pgsql_cluster_created "panel" "5433")"
    fi
}

# Function to add pg_hba.conf entry for the panel cluster
update_panel_pg_hba() {
    local PG_HBA="/etc/postgresql/${PG_VER}/panel/pg_hba.conf"
    if [ -f "$PG_HBA" ]; then
        if ! grep -Eq "^[[:space:]]*local[[:space:]]+${DB_NAME}[[:space:]]+${DB_USER}[[:space:]]+md5" "$PG_HBA" 2>/dev/null; then
            cp "$PG_HBA" "${PG_HBA}.bak.$(date +%Y%m%d%H%M%S)"
            sed -i "1ilocal    ${DB_NAME}    ${DB_USER}    md5" "$PG_HBA" 2>/dev/null || \
                echo "local    ${DB_NAME}    ${DB_USER}    md5" >> "$PG_HBA"
            pg_ctlcluster "$PG_VER" panel reload 2>/dev/null || true
            ok "pg_hba.conf actualizado para socket local del panel"
        fi
        if ! grep -Eq "^[[:space:]]*host[[:space:]]+${DB_NAME}[[:space:]]+${DB_USER}[[:space:]]+127\.0\.0\.1/32[[:space:]]+md5" "$PG_HBA" 2>/dev/null; then
            cp "$PG_HBA" "${PG_HBA}.bak.$(date +%Y%m%d%H%M%S)"
            sed -i "/^# IPv4 local connections/a host    ${DB_NAME}    ${DB_USER}    127.0.0.1/32    md5" "$PG_HBA" 2>/dev/null || \
                echo "host    ${DB_NAME}    ${DB_USER}    127.0.0.1/32    md5" >> "$PG_HBA"
            # Reload only the panel cluster — never restart port 5432
            pg_ctlcluster "$PG_VER" panel reload 2>/dev/null || true
            ok "$(t pgsql_hba_updated)"
        fi
    fi
}

choose_panel_db_host() {
    PANEL_DB_HOST="127.0.0.1"

    if PGCONNECT_TIMEOUT=5 PGPASSWORD="${DB_PASS}" psql -v ON_ERROR_STOP=1 -U "${DB_USER}" -h 127.0.0.1 -p 5433 -d "${DB_NAME}" -c "SELECT 1;" > /dev/null 2>&1; then
        ok "PostgreSQL panel accesible por TCP 127.0.0.1:5433"
        return 0
    fi

    if PGCONNECT_TIMEOUT=5 PGPASSWORD="${DB_PASS}" psql -v ON_ERROR_STOP=1 -U "${DB_USER}" -h /var/run/postgresql -p 5433 -d "${DB_NAME}" -c "SELECT 1;" > /dev/null 2>&1; then
        PANEL_DB_HOST="/var/run/postgresql"
        warn "PostgreSQL TCP 127.0.0.1:5433 no responde; usando socket Unix ${PANEL_DB_HOST}"
        return 0
    fi

    return 1
}

# Try peer auth first as the postgres system user, fallback to password auth.
# Use runuser instead of sudo so a minimal fresh server does not need sudo installed.
PG_AUTH_METHOD="peer"
PG_PASS_ENV=""

if ! runuser -u postgres -- env PGCONNECT_TIMEOUT=5 psql -c "SELECT 1;" > /dev/null 2>&1; then
    PG_AUTH_METHOD="password"
    echo ""
    echo -e "  ${YELLOW}${BOLD}$(t pgsql_peer_fail)${NC}"
    echo -e "  ${YELLOW}$(t pgsql_peer_desc)${NC}"
    echo ""

    if PGCONNECT_TIMEOUT=5 PGPASSWORD="" psql -U postgres -h 127.0.0.1 -c "SELECT 1;" > /dev/null 2>&1; then
        ok "$(t pgsql_empty_pass)"
        PG_PASS_ENV=""
    else
        echo -e "  ${BOLD}$(t pgsql_enter_pass)${NC}"
        prompt_read_secret "  Password: " PG_SUPERUSER_PASS
        echo ""

        if PGCONNECT_TIMEOUT=5 PGPASSWORD="$PG_SUPERUSER_PASS" psql -U postgres -h 127.0.0.1 -c "SELECT 1;" > /dev/null 2>&1; then
            ok "$(t pgsql_pass_verified)"
            PG_PASS_ENV="$PG_SUPERUSER_PASS"
        else
            fail "$(t pgsql_cannot_connect)"
        fi
    fi
fi

# Function to run psql commands with the right auth method (on a given port)
pg_exec() {
    local SQL="$1"
    local PORT="${2:-5433}"
    if [ "$PG_AUTH_METHOD" = "peer" ]; then
        runuser -u postgres -- env PGCONNECT_TIMEOUT=5 psql -p "$PORT" -c "$SQL" 2>/dev/null
    else
        PGCONNECT_TIMEOUT=5 PGPASSWORD="$PG_PASS_ENV" psql -U postgres -h 127.0.0.1 -p "$PORT" -c "$SQL" 2>/dev/null
    fi
}

pg_exec_quiet() {
    local PORT="${1:-5433}"
    if [ "$PG_AUTH_METHOD" = "peer" ]; then
        runuser -u postgres -- env PGCONNECT_TIMEOUT=5 psql -p "$PORT" -lqt 2>/dev/null
    else
        PGCONNECT_TIMEOUT=5 PGPASSWORD="$PG_PASS_ENV" psql -U postgres -h 127.0.0.1 -p "$PORT" -lqt 2>/dev/null
    fi
}

if [ "$REINSTALL" = true ]; then
    # Read existing DB credentials from .env
    EXISTING_DB_PASS=$(grep -E '^DB_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    EXISTING_DB_USER=$(grep -E '^DB_USER=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    EXISTING_DB_NAME=$(grep -E '^DB_NAME=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
    EXISTING_DB_PORT=$(grep -E '^DB_PORT=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')

    if [ -n "$EXISTING_DB_PASS" ]; then
        DB_PASS="$EXISTING_DB_PASS"
        ok "$(t pgsql_reusing)"
    else
        warn "$(t pgsql_no_pass)"
    fi
    [ -n "$EXISTING_DB_USER" ] && DB_USER="$EXISTING_DB_USER"
    [ -n "$EXISTING_DB_NAME" ] && DB_NAME="$EXISTING_DB_NAME"

    if [ "$EXISTING_DB_PORT" = "5433" ]; then
        # Scenario C — already migrated to 5433
        ok "$(t pgsql_already_5433)"
        ensure_panel_cluster
        update_panel_pg_hba
        ok "$(t pgsql_panel_cluster "$PG_VER" "panel" "5433")"
    else
        # Scenario B — needs migration from 5432 to 5433
        ok "$(t pgsql_migrating)"
        ensure_panel_cluster
        update_panel_pg_hba

        # Safety backup from old port
        MIGRATION_BACKUP="/tmp/backup-panel-pre-migration-$(date +%Y%m%d_%H%M%S).sql"
        runuser -u postgres -- pg_dump -p 5432 "${DB_NAME}" > "$MIGRATION_BACKUP" 2>/dev/null || true
        ok "$(t pgsql_migration_backup "$MIGRATION_BACKUP")"

        # Create role in new cluster if not exists
        if pg_exec "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" 5433 | grep -q 1; then
            pg_exec "ALTER USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" 5433 > /dev/null 2>&1
        else
            pg_exec "CREATE ROLE ${DB_USER} WITH LOGIN PASSWORD '${DB_PASS}';" 5433 > /dev/null 2>&1
        fi

        # Create database in new cluster if not exists
        pg_exec_quiet 5433 | cut -d \| -f 1 | grep -qw "${DB_NAME}" || \
            pg_exec "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};" 5433 > /dev/null 2>&1

        pg_exec "ALTER DATABASE ${DB_NAME} OWNER TO ${DB_USER};" 5433 > /dev/null 2>&1 || true
        pg_exec "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};" 5433 > /dev/null 2>&1

        # Copy data from 5432 to 5433
        runuser -u postgres -- pg_dump -p 5432 "${DB_NAME}" 2>/dev/null | runuser -u postgres -- psql -p 5433 "${DB_NAME}" > /dev/null 2>&1 || true

        ok "$(t pgsql_migration_done)"
        ok "$(t pgsql_5432_free)"
        ok "$(t pgsql_panel_cluster "$PG_VER" "panel" "5433")"
    fi
else
    # Scenario A — fresh install
    ensure_panel_cluster
    update_panel_pg_hba

    # Create user if not exists (on port 5433 — panel cluster)
    if pg_exec "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" 5433 | grep -q 1; then
        pg_exec "ALTER USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" 5433 > /dev/null 2>&1
    else
        pg_exec "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" 5433 > /dev/null 2>&1
    fi

    # Create database if not exists (on port 5433 — panel cluster)
    pg_exec_quiet 5433 | cut -d \| -f 1 | grep -qw "${DB_NAME}" || \
        pg_exec "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};" 5433 > /dev/null 2>&1

    pg_exec "ALTER DATABASE ${DB_NAME} OWNER TO ${DB_USER};" 5433 > /dev/null 2>&1 || true
    pg_exec "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};" 5433 > /dev/null 2>&1

    ok "$(t pgsql_db_created "$DB_NAME" "$DB_USER")"
    ok "$(t pgsql_5432_free)"
    ok "$(t pgsql_panel_cluster "$PG_VER" "panel" "5433")"
fi

PANEL_DB_HOST="127.0.0.1"
if ! choose_panel_db_host; then
    warn "No se pudo conectar a la BD del panel ni por TCP ni por socket Unix"
    echo "    Prueba manual TCP:    PGPASSWORD='***' psql -U ${DB_USER} -h 127.0.0.1 -p 5433 -d ${DB_NAME} -c 'SELECT 1;'"
    echo "    Prueba manual socket: PGPASSWORD='***' psql -U ${DB_USER} -h /var/run/postgresql -p 5433 -d ${DB_NAME} -c 'SELECT 1;'"
    fail "PostgreSQL del panel no acepta conexion autenticada. Revisa pg_hba.conf y credenciales."
fi

# ============================================================
# Step 4: Caddy
# ============================================================
header "$(t step_caddy)"

CADDY_FILE="/etc/caddy/Caddyfile"
CADDY_WAS_RUNNING=false
CADDY_EXISTING=false
run_caddy_config_snapshot

# Detect existing Caddy
if command -v caddy &> /dev/null; then
    CADDY_EXISTING=true
    ok "$(t caddy_exists)"

    # Check if Caddy is running
    if systemctl is-active --quiet caddy 2>/dev/null; then
        CADDY_WAS_RUNNING=true
    fi

    # Check for existing configuration (API routes + Caddyfile domains + autosave.json)
    CADDY_ROUTES=0
    CADDY_HAS_CADDYFILE_DOMAINS=false
    CADDY_HAS_AUTOSAVE=false

    if [ "$CADDY_WAS_RUNNING" = true ]; then
        CADDY_ROUTES=$(curl -s --max-time 3 http://127.0.0.1:2019/config/apps/http/servers/srv0/routes 2>/dev/null | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
    fi

    # Check if Caddyfile has domain blocks (not just global options)
    if [ -f "$CADDY_FILE" ]; then
        CADDYFILE_DOMAINS=$(grep -cE '^\s*[a-zA-Z0-9].*\{' "$CADDY_FILE" 2>/dev/null || echo "0")
        if [ "$CADDYFILE_DOMAINS" -gt 0 ] 2>/dev/null; then
            CADDY_HAS_CADDYFILE_DOMAINS=true
        fi
    fi

    # Check for autosave.json (API-created routes persisted to disk)
    CADDY_HOME=$(caddy environ 2>/dev/null | grep 'caddy.AppConfigDir=' | cut -d= -f2 || echo "")
    if [ -z "$CADDY_HOME" ]; then
        # Fallback: check common locations
        for autosave_dir in /var/lib/caddy/.config/caddy /root/.config/caddy /home/caddy/.config/caddy; do
            if [ -f "${autosave_dir}/autosave.json" ]; then
                CADDY_HOME="$autosave_dir"
                break
            fi
        done
    fi
    if [ -n "$CADDY_HOME" ] && [ -f "${CADDY_HOME}/autosave.json" ]; then
        CADDY_HAS_AUTOSAVE=true
        AUTOSAVE_SIZE=$(stat -c%s "${CADDY_HOME}/autosave.json" 2>/dev/null || echo "0")
        # autosave.json with meaningful content (>50 bytes = has real config)
        if [ "$AUTOSAVE_SIZE" -lt 50 ] 2>/dev/null; then
            CADDY_HAS_AUTOSAVE=false
        fi
    fi

    CADDY_HAS_EXISTING_CONFIG=false
    if [ "$CADDY_ROUTES" -gt 0 ] 2>/dev/null || [ "$CADDY_HAS_CADDYFILE_DOMAINS" = true ] || [ "$CADDY_HAS_AUTOSAVE" = true ]; then
        CADDY_HAS_EXISTING_CONFIG=true
    fi

    if [ "$CADDY_HAS_EXISTING_CONFIG" = true ]; then
        echo ""
        echo -e "  ${YELLOW}${BOLD}$(t caddy_config_detected)${NC}"
        [ "$CADDY_ROUTES" -gt 0 ] 2>/dev/null && echo -e "  ${YELLOW}  - $(t caddy_api_routes "$CADDY_ROUTES")${NC}"
        [ "$CADDY_HAS_CADDYFILE_DOMAINS" = true ] && echo -e "  ${YELLOW}  - $(t caddy_caddyfile_domains "$CADDYFILE_DOMAINS")${NC}"
        [ "$CADDY_HAS_AUTOSAVE" = true ] && echo -e "  ${YELLOW}  - $(t caddy_autosave_found)${NC}"
        echo -e "  ${YELLOW}$(t caddy_may_be_cms)${NC}"
        echo ""
        echo "  $(t existing_options)"
        echo "    1) $(t caddy_opt_integrate)"
        echo "    2) $(t caddy_opt_reconfigure)"
        echo ""
        prompt_read "  $(t caddy_choose)" CADDY_CHOICE
        CADDY_CHOICE=${CADDY_CHOICE:-1}
        [ "$CADDY_CHOICE" = "2" ] || CADDY_CHOICE="1"

        if [ "$CADDY_CHOICE" = "1" ]; then
            ok "$(t caddy_integrated)"
            # Ensure Caddyfile has admin API enabled
            cp "$CADDY_FILE" "${CADDY_FILE}.bak.$(date +%Y%m%d%H%M%S)" 2>/dev/null || true
            if grep -qE '^\s*admin\s+off' "$CADDY_FILE" 2>/dev/null; then
                # Replace "admin off" with "admin localhost:2019"
                sed -i 's/^\(\s*\)admin\s\+off/\1admin localhost:2019/' "$CADDY_FILE"
                ok "$(t caddy_admin_added) (admin off → localhost:2019)"
            elif grep -qE '^\s*admin\s+localhost:2019' "$CADDY_FILE" 2>/dev/null; then
                ok "$(t caddy_admin_exists)"
            elif ! grep -qE '^\s*admin\s' "$CADDY_FILE" 2>/dev/null; then
                # No admin directive at all — prepend global block
                TEMP_CADDY=$(mktemp)
                cat > "$TEMP_CADDY" << 'ADMINEOF'
{
    admin localhost:2019
}

ADMINEOF
                cat "$CADDY_FILE" >> "$TEMP_CADDY"
                mv "$TEMP_CADDY" "$CADDY_FILE"
                ok "$(t caddy_admin_added)"
            fi

            # Fix stale autosave.json that may have admin:disabled
            for autosave_path in /var/lib/caddy/.config/caddy/autosave.json /root/.config/caddy/autosave.json /home/caddy/.config/caddy/autosave.json; do
                if [ -f "$autosave_path" ]; then
                    if python3 -c "import json; d=json.load(open('$autosave_path')); exit(0 if d.get('admin',{}).get('disabled') else 1)" 2>/dev/null; then
                        rm -f "$autosave_path"
                        ok "Removed stale autosave.json with admin:disabled ($autosave_path)"
                    fi
                fi
            done
            # Ensure --resume is enabled
            mkdir -p /etc/systemd/system/caddy.service.d
            if [ ! -f /etc/systemd/system/caddy.service.d/override-resume.conf ]; then
                cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF
                systemctl daemon-reload
                ok "$(t caddy_resume_added)"
            else
                ok "$(t caddy_resume_exists)"
            fi
        else
            echo ""
            echo -e "  ${RED}${BOLD}PELIGRO: vas a sobrescribir la configuracion de Caddy.${NC}"
            echo -e "  ${YELLOW}Se han detectado dominios/rutas/autosave existentes. Si solo quieres instalar o actualizar el panel, usa Integrar.${NC}"
            echo -e "  ${YELLOW}Reconfigurar puede dejar sitios en 80/443 fuera de servicio hasta restaurar el backup.${NC}"
            echo ""
            prompt_read "  Para forzar reconfiguracion de Caddy escribe exactamente RECONFIGURAR CADDY: " CADDY_RECONFIG_CONFIRM
            if [ "$CADDY_RECONFIG_CONFIRM" != "RECONFIGURAR CADDY" ]; then
                ok "$(t caddy_integrated)"
                CADDY_CHOICE="1"
            fi
        fi

        if [ "$CADDY_CHOICE" != "1" ]; then
            # Reconfigure Caddy — backup EVERYTHING first
            warn "$(t caddy_reconfiguring)"
            BACKUP_TS=$(date +%Y%m%d%H%M%S)
            cp "$CADDY_FILE" "${CADDY_FILE}.bak.${BACKUP_TS}" 2>/dev/null || true
            ok "$(t caddy_backed_up "${CADDY_FILE}.bak.${BACKUP_TS}")"
            # Also backup autosave.json if it exists
            if [ "$CADDY_HAS_AUTOSAVE" = true ] && [ -n "$CADDY_HOME" ]; then
                cp "${CADDY_HOME}/autosave.json" "${CADDY_HOME}/autosave.json.bak.${BACKUP_TS}" 2>/dev/null || true
                ok "$(t caddy_autosave_backed "${CADDY_HOME}/autosave.json.bak.${BACKUP_TS}")"
            fi

            cat > "$CADDY_FILE" << 'CADDYEOF'
{
    admin localhost:2019
    auto_https disable_redirects
}
CADDYEOF
            ok "$(t caddy_reconfigured)"

            mkdir -p /etc/systemd/system/caddy.service.d
            cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF
            systemctl daemon-reload
            systemctl restart caddy
            ok "$(t caddy_restarted)"
        fi
    else
        # Caddy exists but no config at all — safe to configure
        if ! grep -q "admin" "$CADDY_FILE" 2>/dev/null; then
            cat > "$CADDY_FILE" << 'CADDYEOF'
{
    admin localhost:2019
    auto_https disable_redirects
}
CADDYEOF
            ok "$(t caddy_configured)"
        else
            ok "$(t caddy_admin_exists)"
        fi

        mkdir -p /etc/systemd/system/caddy.service.d
        if [ ! -f /etc/systemd/system/caddy.service.d/override-resume.conf ]; then
            cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF
            systemctl daemon-reload
        fi
        systemctl enable caddy > /dev/null 2>&1
        systemctl restart caddy
        ok "$(t caddy_running_resume)"
    fi
else
    # Fresh Caddy install
    apt-get install -y -qq debian-keyring debian-archive-keyring > /dev/null 2>&1
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' 2>/dev/null | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>/dev/null
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' 2>/dev/null | tee /etc/apt/sources.list.d/caddy-stable.list > /dev/null 2>&1
    apt-get update -qq
    apt-get install -y -qq caddy > /dev/null 2>&1
    ok "$(t caddy_installed)"

    cat > "$CADDY_FILE" << 'CADDYEOF'
{
    admin localhost:2019
    auto_https disable_redirects
}
CADDYEOF
    ok "$(t caddy_configured)"

    mkdir -p /etc/systemd/system/caddy.service.d
    cat > /etc/systemd/system/caddy.service.d/override-resume.conf << 'SVCEOF'
[Service]
ExecStart=
ExecStart=/usr/bin/caddy run --environ --resume --config /etc/caddy/Caddyfile
SVCEOF

    systemctl daemon-reload
    systemctl enable caddy > /dev/null 2>&1
    systemctl restart caddy
    ok "$(t caddy_running_persist)"
fi

# Verify Caddy API is accessible (try 127.0.0.1 first, then localhost — some systems have IPv6 issues)
sleep 2
CADDY_API_OK=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 http://127.0.0.1:2019/config/ 2>/dev/null || echo "000")
if [ "$CADDY_API_OK" != "200" ]; then
    CADDY_API_OK=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 http://127.0.0.1:2019/config/ 2>/dev/null || echo "000")
fi
# Retry once after 3 more seconds if not ready
if [ "$CADDY_API_OK" != "200" ]; then
    sleep 3
    CADDY_API_OK=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 http://127.0.0.1:2019/config/ 2>/dev/null || echo "000")
fi
if [ "$CADDY_API_OK" = "200" ]; then
    ok "$(t caddy_api_ok)"
else
    warn "$(t caddy_api_wait "$CADDY_API_OK")"
fi
install_caddy_runtime_repair_override

# ============================================================
# Apply deferred nginx/apache → Caddy site migrations
# ============================================================
MIGRATE_SITES_FILE="/tmp/musedock-migrate-sites.json"
if [ -f "$MIGRATE_SITES_FILE" ] && [ "$CADDY_API_OK" = "200" ]; then

    # Deduplicate the migration file (keep first occurrence of each domain)
    python3 -c "
import json
sites = json.load(open('$MIGRATE_SITES_FILE'))
seen = set()
unique = []
for s in sites:
    d = s['domain']
    if d not in seen:
        seen.add(d)
        unique.append(s)
json.dump(unique, open('$MIGRATE_SITES_FILE', 'w'))
" 2>/dev/null

    MIGRATE_COUNT=$(python3 -c "import json; print(len(json.load(open('$MIGRATE_SITES_FILE'))))" 2>/dev/null || echo "0")
    if [ "$MIGRATE_COUNT" -gt 0 ] 2>/dev/null; then
        header "$(t migrate_applying "$MIGRATE_COUNT")"
        MIGRATE_OK=0
        MIGRATE_SKIPPED=0

        # Ensure srv0 server exists with a routes array
        SRV0_CHECK=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 http://127.0.0.1:2019/config/apps/http/servers/srv0 2>/dev/null)
        if [ "$SRV0_CHECK" != "200" ]; then
            curl -s --max-time 3 -X POST http://127.0.0.1:2019/config/apps/http/servers/srv0 \
                -H "Content-Type: application/json" \
                -d '{"listen":[":443",":80"],"routes":[]}' > /dev/null 2>&1
        fi

        # Fetch existing routes to detect duplicates
        EXISTING_ROUTES_JSON=$(curl -s --max-time 3 http://127.0.0.1:2019/config/apps/http/servers/srv0/routes 2>/dev/null || echo "[]")

        python3 -c "
import json, sys
sites = json.load(open('$MIGRATE_SITES_FILE'))
for s in sites:
    print(json.dumps(s))
" 2>/dev/null | while IFS= read -r site_json; do
            DOMAIN=$(echo "$site_json" | python3 -c "import sys,json; print(json.load(sys.stdin)['domain'])")
            DOC_ROOT=$(echo "$site_json" | python3 -c "import sys,json; print(json.load(sys.stdin)['root'])")
            SITE_USER=$(echo "$site_json" | python3 -c "import sys,json; print(json.load(sys.stdin)['user'])")
            PHP_VER=$(echo "$site_json" | python3 -c "import sys,json; print(json.load(sys.stdin)['php'])")

            ROUTE_ID="hosting-$(echo "$DOMAIN" | tr -dc 'a-zA-Z0-9' | tr '[:upper:]' '[:lower:]')"
            SOCKET_PATH="/run/php/php${PHP_VER}-fpm-${SITE_USER}.sock"

            # Check if a route for this domain already exists in Caddy
            ALREADY_EXISTS=$(echo "$EXISTING_ROUTES_JSON" | python3 -c "
import sys, json
routes = json.load(sys.stdin)
domain = '${DOMAIN}'
for r in routes:
    for m in r.get('match', []):
        if domain in m.get('host', []):
            print('yes')
            sys.exit(0)
print('no')
" 2>/dev/null)

            if [ "$ALREADY_EXISTS" = "yes" ]; then
                # Route exists — delete it first so we replace with clean config (fixes duplicates from bad installs)
                # Find and delete by @id or by matching domain
                curl -s --max-time 3 -X DELETE "http://127.0.0.1:2019/id/${ROUTE_ID}" > /dev/null 2>&1
                # Also try to delete any route that matches this domain but has a different @id
                python3 -c "
import json, sys, urllib.request
routes = json.loads('$(echo "$EXISTING_ROUTES_JSON" | sed "s/'/\\\\'/g")')
for i, r in enumerate(routes):
    for m in r.get('match', []):
        if '${DOMAIN}' in m.get('host', []):
            rid = r.get('@id', '')
            if rid and rid != '${ROUTE_ID}':
                try:
                    req = urllib.request.Request('http://127.0.0.1:2019/id/' + rid, method='DELETE')
                    urllib.request.urlopen(req, timeout=3)
                except: pass
" 2>/dev/null
            fi

            # Build Caddy route JSON
            ROUTE_JSON=$(python3 -c "
import json
route = {
    '@id': '${ROUTE_ID}',
    'match': [{'host': ['${DOMAIN}', 'www.${DOMAIN}']}],
    'handle': [{
        'handler': 'subroute',
        'routes': [
            {'handle': [{'handler': 'vars', 'root': '${DOC_ROOT}'}]},
            {
                'match': [{'path': ['*.jpg','*.jpeg','*.png','*.gif','*.webp','*.svg','*.ico','*.css','*.js','*.woff','*.woff2']}],
                'handle': [{'handler': 'headers', 'response': {'set': {'Cache-Control': ['public, max-age=2592000']}}}]
            },
            {
                'match': [{'file': {'try_files': ['{http.request.uri.path}', '{http.request.uri.path}/index.php', '/index.php']}}],
                'handle': [{'handler': 'rewrite', 'uri': '{http.matchers.file.relative}'}]
            },
            {
                'match': [{'path': ['*.php']}],
                'handle': [{'handler': 'reverse_proxy', 'transport': {'protocol': 'fastcgi', 'root': '${DOC_ROOT}', 'split_path': ['.php']}, 'upstreams': [{'dial': 'unix/${SOCKET_PATH}'}]}]
            },
            {'handle': [{'handler': 'file_server', 'root': '${DOC_ROOT}', 'hide': ['.git', '.env', '.htaccess']}]}
        ]
    }],
    'terminal': True
}
print(json.dumps(route))
")

            HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 -X POST \
                "http://127.0.0.1:2019/config/apps/http/servers/srv0/routes" \
                -H "Content-Type: application/json" \
                -d "$ROUTE_JSON" 2>/dev/null)

            if [ "$HTTP_CODE" -ge 200 ] && [ "$HTTP_CODE" -lt 300 ] 2>/dev/null; then
                ok "$(t migrate_route_ok "$DOMAIN")"
                MIGRATE_OK=$((MIGRATE_OK + 1))
            else
                warn "$(t migrate_route_fail "$DOMAIN" "$HTTP_CODE")"
            fi
        done

        ok "$(t migrate_complete "$MIGRATE_OK" "$MIGRATE_COUNT")"
        rm -f "$MIGRATE_SITES_FILE"
    fi
fi

# ============================================================
# Step 5: MySQL (for hosting client databases)
# ============================================================
header "$(t step_mysql)"

MYSQL_FRESH_INSTALL=false
if ! command -v mysql &> /dev/null; then
    apt-get install -y -qq mysql-server > /dev/null 2>&1 || \
    apt-get install -y -qq mariadb-server > /dev/null 2>&1
    MYSQL_FRESH_INSTALL=true
    ok "$(t mysql_installed)"
else
    ok "$(t mysql_exists)"
fi

systemctl enable mysql > /dev/null 2>&1 || systemctl enable mariadb > /dev/null 2>&1
systemctl start mysql > /dev/null 2>&1 || systemctl start mariadb > /dev/null 2>&1
ok "$(t mysql_running)"

# Determine MySQL root access method and store credentials for client DB management
MYSQL_ROOT_PASS=""
MYSQL_AUTH_METHOD="unknown"

if [ "$MYSQL_FRESH_INSTALL" = true ]; then
    # Fresh install — root uses unix_socket/auth_socket (no password needed from root)
    if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
        MYSQL_AUTH_METHOD="socket"
        ok "$(t mysql_socket_ok)"
    fi
else
    # Existing install — test access methods
    if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
        # Socket auth works from root
        MYSQL_AUTH_METHOD="socket"
        ok "$(t mysql_socket_access)"
    elif [ "$REINSTALL" = true ]; then
        # Try reading from existing .env
        EXISTING_MYSQL_PASS=$(grep -E '^MYSQL_ROOT_PASS=' "${PANEL_DIR}/.env" 2>/dev/null | cut -d= -f2 | tr -d ' "'"'"'')
        if [ -n "$EXISTING_MYSQL_PASS" ]; then
            if mysql -u root -p"${EXISTING_MYSQL_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
                MYSQL_ROOT_PASS="$EXISTING_MYSQL_PASS"
                MYSQL_AUTH_METHOD="password"
                ok "$(t mysql_pass_recovered)"
            fi
        fi
    fi

    if [ "$MYSQL_AUTH_METHOD" = "unknown" ]; then
        echo ""
        echo -e "  ${YELLOW}${BOLD}$(t mysql_needs_pass)${NC}"
        echo -e "  ${YELLOW}$(t mysql_needs_pass_desc)${NC}"
        echo ""
        echo "  $(t existing_options)"
        echo "    1) $(t mysql_opt_enter)"
        echo "    2) $(t mysql_opt_skip)"
        echo ""
        prompt_read "  $(t mysql_choose)" MYSQL_PASS_CHOICE
        MYSQL_PASS_CHOICE=${MYSQL_PASS_CHOICE:-1}

        if [ "$MYSQL_PASS_CHOICE" = "1" ]; then
            MAX_ATTEMPTS=3
            for attempt in $(seq 1 $MAX_ATTEMPTS); do
                prompt_read_secret "  $(t mysql_attempt "$attempt" "$MAX_ATTEMPTS")" MYSQL_INPUT_PASS
                echo ""
                if mysql -u root -p"${MYSQL_INPUT_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
                    MYSQL_ROOT_PASS="$MYSQL_INPUT_PASS"
                    MYSQL_AUTH_METHOD="password"
                    ok "$(t mysql_pass_verified)"
                    break
                else
                    warn "$(t mysql_invalid_pass)"
                fi
            done
            if [ "$MYSQL_AUTH_METHOD" = "unknown" ]; then
                warn "$(t mysql_auth_failed)"
                MYSQL_AUTH_METHOD="unconfigured"
            fi
        else
            warn "$(t mysql_skipped)"
            MYSQL_AUTH_METHOD="unconfigured"
        fi
    fi
fi

# ============================================================
# Step 6: Panel setup
# ============================================================
header "$(t step_panel)"

# Create directories
mkdir -p "${PANEL_DIR}/storage/sessions"
mkdir -p "${PANEL_DIR}/storage/logs"
mkdir -p "${PANEL_DIR}/storage/cache"
mkdir -p /var/www/vhosts
ok "$(t dirs_created)"

# Backup existing .env if reinstalling
if [ "$REINSTALL" = true ] && [ -f "${PANEL_DIR}/.env" ]; then
    cp "${PANEL_DIR}/.env" "${PANEL_DIR}/.env.bak.$(date +%Y%m%d%H%M%S)"
    ok "$(t env_backed_up)"
fi

# Generate .env
cat > "${PANEL_DIR}/.env" << ENVEOF
# MuseDock Panel — Generated $(date '+%Y-%m-%d %H:%M:%S')
PANEL_NAME="MuseDock Panel"
PANEL_PORT=${PANEL_PORT}
PANEL_DEBUG=false
PANEL_HSTS_ENABLED=false

DB_HOST=${PANEL_DB_HOST}
DB_PORT=5433
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

SESSION_LIFETIME=7200

CADDY_API_URL=http://localhost:2019

FPM_PHP_VERSION=${PHP_VER}
FPM_SOCKET_DIR=/run/php

VHOSTS_DIR=/var/www/vhosts

ALLOWED_IPS=

# MySQL — for client database management
MYSQL_AUTH_METHOD=${MYSQL_AUTH_METHOD}
MYSQL_ROOT_PASS=${MYSQL_ROOT_PASS}

# Internal port for PHP (Caddy proxies PANEL_PORT → PANEL_INTERNAL_PORT)
PANEL_INTERNAL_PORT=$((PANEL_PORT + 1))

# Cluster role (standalone/master/slave)
PANEL_ROLE=standalone
ENVEOF

chmod 600 "${PANEL_DIR}/.env"
DB_PORT=5433  # Keep in bash scope for health check
ok "$(t env_created)"

# Run database schema (safe — uses IF NOT EXISTS)
SCHEMA_INSTALL_LOG="/tmp/musedock-panel-install-schema.log"
if ! PGCONNECT_TIMEOUT=5 PGPASSWORD="${DB_PASS}" psql -v ON_ERROR_STOP=1 -U "${DB_USER}" -h "${PANEL_DB_HOST}" -p 5433 -d "${DB_NAME}" -f "${PANEL_DIR}/database/schema.sql" > "$SCHEMA_INSTALL_LOG" 2>&1; then
    tail -n 80 "$SCHEMA_INSTALL_LOG" | sed 's/^/    /'
    fail "No se pudo aplicar database/schema.sql. Revisa PostgreSQL, pg_hba.conf y credenciales en ${PANEL_DIR}/.env."
fi
ok "$(t schema_applied)"

# Set permissions
chmod -R 750 "${PANEL_DIR}/storage"
ok "$(t permissions_set)"

# ============================================================
# Step 7: Systemd service + Caddy HTTPS reverse proxy
# ============================================================
header "$(t step_service)"

# PHP listens internally on PANEL_PORT+1, Caddy handles HTTPS on PANEL_PORT
PANEL_INTERNAL_PORT=$((PANEL_PORT + 1))

# Generate service file from template
sed -e "s|__PANEL_DIR__|${PANEL_DIR}|g" \
    -e "s|__PANEL_PORT__|${PANEL_PORT}|g" \
    -e "s|__PANEL_INTERNAL_PORT__|${PANEL_INTERNAL_PORT}|g" \
    "${PANEL_DIR}/bin/musedock-panel.service" > /etc/systemd/system/musedock-panel.service

systemctl daemon-reload
systemctl enable musedock-panel > /dev/null 2>&1
systemctl restart musedock-panel
ok "$(t service_started "$PANEL_INTERNAL_PORT") (internal)"

# Configure Caddy as HTTPS reverse proxy for the panel
# This gives us: https://IP:PANEL_PORT with internal cert (tls internal).
# IP access needs an explicit site address/default SNI; a bare :PANEL_PORT block
# can fail TLS certificate selection and surface as ERR_SSL_PROTOCOL_ERROR.
CADDY_API="http://localhost:2019"
SERVER_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -1)
ALL_PANEL_IPS=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u | tr '\n' ' ')
[ -z "$SERVER_IP" ] && SERVER_IP=$(echo "$ALL_PANEL_IPS" | awk '{print $1}')
[ -z "$SERVER_IP" ] && SERVER_IP="127.0.0.1"
PANEL_SITE_LABELS="https://${SERVER_IP}:${PANEL_PORT}"
for ip in $ALL_PANEL_IPS 127.0.0.1; do
    [ -n "$ip" ] || continue
    case ",$PANEL_SITE_LABELS," in
        *,"https://${ip}:${PANEL_PORT}",*) ;;
        *) PANEL_SITE_LABELS="${PANEL_SITE_LABELS}, https://${ip}:${PANEL_PORT}" ;;
    esac
done
PANEL_SITE_LABELS="${PANEL_SITE_LABELS}, https://localhost:${PANEL_PORT}"

# Wait for Caddy API
for i in 1 2 3 4 5; do
    CADDY_API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 2 "${CADDY_API}/config/" 2>/dev/null || echo "000")
    [ "$CADDY_API_STATUS" = "200" ] && break
    sleep 1
done

# Write Caddyfile with panel reverse proxy (more reliable than API for TLS internal)
# Preserve existing site blocks if Caddyfile has them
CADDY_FILE="/etc/caddy/Caddyfile"
CADDY_PANEL_BLOCK="
${PANEL_SITE_LABELS} {
    tls internal
    reverse_proxy 127.0.0.1:${PANEL_INTERNAL_PORT} {
        header_up X-Forwarded-Proto https
        header_up X-Real-Ip {remote_host}
    }
}
"

# Check if Caddyfile already has a panel block
if grep -q ":${PANEL_PORT}" "$CADDY_FILE" 2>/dev/null; then
    # Remove old panel block and rewrite
    ok "Actualizando bloque del panel en Caddyfile"
fi

# Build new Caddyfile: global options + panel block + existing site blocks
EXISTING_SITES=""
if [ -f "$CADDY_FILE" ]; then
    # Extract site blocks that are NOT the panel and NOT global options
    EXISTING_SITES=$(awk '
        /^{$/ && NR<=3 { in_global=1; next }
        in_global && /^}$/ { in_global=0; next }
        in_global { next }
        /^https?:\/\/:'"${PANEL_PORT}"'/ || /^https?:\/\/[^ ]*:'"${PANEL_PORT}"'/ || /^:'"${PANEL_PORT}"'/ {
            in_panel=1
            line=$0
            opens=gsub(/\{/, "{", line)
            line=$0
            closes=gsub(/\}/, "}", line)
            depth=opens-closes
            if(depth<=0) depth=1
            next
        }
        in_panel {
            line=$0
            opens=gsub(/\{/, "{", line)
            line=$0
            closes=gsub(/\}/, "}", line)
            depth += opens-closes
            if(depth<=0) in_panel=0
            next
        }
        { print }
    ' "$CADDY_FILE" 2>/dev/null)
fi

# Backup existing Caddyfile
cp "$CADDY_FILE" "${CADDY_FILE}.bak.$(date +%Y%m%d%H%M%S)" 2>/dev/null || true

cat > "$CADDY_FILE" << CADDYEOF
{
    auto_https disable_redirects
    admin localhost:2019
}

${PANEL_SITE_LABELS} {
    tls internal
    reverse_proxy 127.0.0.1:${PANEL_INTERNAL_PORT} {
        header_up X-Forwarded-Proto https
        header_up X-Real-Ip {remote_host}
    }
}
CADDYEOF

# Append existing non-panel site blocks if any
if [ -n "$(echo "$EXISTING_SITES" | tr -d '[:space:]')" ]; then
    echo "" >> "$CADDY_FILE"
    echo "$EXISTING_SITES" >> "$CADDY_FILE"
fi
chown root:root "$CADDY_FILE" 2>/dev/null || true
chmod 0644 "$CADDY_FILE" 2>/dev/null || true

# Remove --resume override (use Caddyfile directly)
rm -f /etc/systemd/system/caddy.service.d/override-resume.conf
systemctl daemon-reload

# Install libnss3-tools for cert management (silent)
apt install -y libnss3-tools > /dev/null 2>&1 || true

# Install Caddy root cert to system trust store (so TLS internal works)
CADDY_ROOT_CERT="/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt"
if [ -f "$CADDY_ROOT_CERT" ]; then
    cp "$CADDY_ROOT_CERT" /usr/local/share/ca-certificates/caddy-root.crt
    update-ca-certificates > /dev/null 2>&1 || true
fi

# Validate and reload Caddy
if caddy validate --adapter caddyfile --config "$CADDY_FILE" > /dev/null 2>&1; then
    if ! systemctl restart caddy; then
        warn "Caddy no pudo reiniciar con el Caddyfile generado; restaurando backup si existe"
        LATEST_CADDY_BACKUP=$(ls -1t "${CADDY_FILE}".bak.* 2>/dev/null | head -1 || true)
        if [ -n "$LATEST_CADDY_BACKUP" ]; then
            cp "$LATEST_CADDY_BACKUP" "$CADDY_FILE"
            chown root:root "$CADDY_FILE" 2>/dev/null || true
            chmod 0644 "$CADDY_FILE" 2>/dev/null || true
            systemctl restart caddy >/dev/null 2>&1 || true
            warn "Caddyfile restaurado desde ${LATEST_CADDY_BACKUP}"
        fi
    fi
    sleep 2

    # Verify HTTPS works
    HTTPS_CHECK=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || echo "000")
    if [ "$HTTPS_CHECK" = "200" ] || [ "$HTTPS_CHECK" = "302" ] || [ "$HTTPS_CHECK" = "301" ]; then
        ok "$(t caddy_proxy_ok "$PANEL_PORT" "$PANEL_INTERNAL_PORT")"
        ok "$(t caddy_tls_internal)"
    else
        # Caddy may need a moment to generate certs on first run
        sleep 3
        HTTPS_CHECK2=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 "https://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || echo "000")
        if [ "$HTTPS_CHECK2" = "200" ] || [ "$HTTPS_CHECK2" = "302" ] || [ "$HTTPS_CHECK2" = "301" ]; then
            ok "$(t caddy_proxy_ok "$PANEL_PORT" "$PANEL_INTERNAL_PORT")"
            ok "$(t caddy_tls_internal)"
        else
            warn "$(t caddy_proxy_fail "$HTTPS_CHECK2")"
            warn "HTTPS en puerto ${PANEL_PORT} puede necesitar reiniciar Caddy manualmente"
        fi
    fi
else
    warn "Caddyfile invalido — NO se expone PHP directo en ${PANEL_PORT} para evitar ERR_SSL_PROTOCOL_ERROR"
    warn "El panel queda escuchando solo en 127.0.0.1:${PANEL_INTERNAL_PORT}. Corrige Caddy y reinicia."
fi

# ============================================================
# Step 7b: Cron jobs (cluster worker + panel DB backup)
# ============================================================
header "Configurando cron jobs del panel..."

# Create backups directory
install -d -o postgres -g www-data -m 0770 "${PANEL_DIR}/storage/backups"

# Cluster worker — processes sync queue, heartbeats, alerts (every minute)
CRON_WORKER="/etc/cron.d/musedock-cluster"
cat > "$CRON_WORKER" << CRONEOF
# MuseDock Panel — Cluster worker (queue, heartbeat, alerts)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/cluster-worker.php >> ${PANEL_DIR}/storage/logs/cluster-worker.log 2>&1
CRONEOF
chmod 644 "$CRON_WORKER"
ok "Cron: cluster-worker.php (cada minuto)"

# Panel DB backup — hourly pg_dump to storage/backups (keeps last 48h)
CRON_BACKUP="/etc/cron.d/musedock-backup"
cat > "$CRON_BACKUP" << CRONEOF
# MuseDock Panel — Hourly panel DB backup
0 * * * * root install -d -o postgres -g www-data -m 0770 ${PANEL_DIR}/storage/backups && runuser -u postgres -- pg_dump -p 5433 musedock_panel | gzip > ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chown postgres:www-data ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz && chmod 0640 ${PANEL_DIR}/storage/backups/panel-\$(date +\%Y\%m\%d_\%H).sql.gz
# Cleanup backups older than 48 hours
5 * * * * root find ${PANEL_DIR}/storage/backups/ -name "panel-*.sql.gz" -mmin +2880 -delete 2>/dev/null
CRONEOF
chmod 644 "$CRON_BACKUP"
ok "Cron: backup panel DB cada hora (retiene 48h)"

install_caddy_backup_cron
run_caddy_config_snapshot
ok "Cron: backup Caddy diario (15 dias + last-known-good)"

# File sync worker — syncs hosting files from master to slave nodes (every minute, respects interval setting)
CRON_FILESYNC="/etc/cron.d/musedock-filesync"
cat > "$CRON_FILESYNC" << CRONEOF
# MuseDock Panel — File sync worker (master → slave file replication)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/filesync-worker.php >> ${PANEL_DIR}/storage/logs/filesync-worker.log 2>&1
CRONEOF
chmod 644 "$CRON_FILESYNC"
ok "Cron: filesync-worker.php (cada minuto, respeta intervalo configurado)"

# Monitor collector — reads network/CPU/RAM stats every 30 seconds
CRON_MONITOR="/etc/cron.d/musedock-monitor"
cat > "$CRON_MONITOR" << CRONEOF
# MuseDock Panel — Network/system monitoring collector (every 30s)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
* * * * * root sleep 30 && /usr/bin/php ${PANEL_DIR}/bin/monitor-collector.php
CRONEOF
chmod 644 "$CRON_MONITOR"
ok "Cron: monitor-collector.php (cada 30 segundos)"

# Failover worker — health checks, auto-failover, resync (every minute)
CRON_FAILOVER="/etc/cron.d/musedock-failover"
cat > "$CRON_FAILOVER" << CRONEOF
# MuseDock Panel — Failover worker (health checks, auto-failover, resync)
* * * * * root /usr/bin/php ${PANEL_DIR}/bin/failover-worker.php >> ${PANEL_DIR}/storage/logs/failover-worker.log 2>&1
CRONEOF
chmod 644 "$CRON_FAILOVER"
ok "Cron: failover-worker.php (cada minuto)"

# Reload cron daemon
systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null || true
ok "Cron jobs instalados"

# ============================================================
# Step 7c: Fail2Ban — Brute force protection
# ============================================================
header "Configurando Fail2Ban (proteccion contra fuerza bruta)..."

F2B_CONFIGURE=false
if command -v fail2ban-client >/dev/null 2>&1; then
    ok "Fail2Ban detectado"
    prompt_read "  Fail2Ban ya esta instalado. Instalar/sincronizar jails MuseDock? [S/n] " F2B_CONFIRM
    F2B_CONFIRM=${F2B_CONFIRM:-s}
    if [[ "$F2B_CONFIRM" =~ ^[YySs]$ ]]; then
        F2B_CONFIGURE=true
    else
        warn "Fail2Ban existente preservado sin cambios por eleccion del usuario"
    fi
else
    warn "Fail2Ban no instalado."
    prompt_read "  Instalar Fail2Ban y configurar jails MuseDock ahora? [S/n] " F2B_CONFIRM
    F2B_CONFIRM=${F2B_CONFIRM:-s}
    if [[ "$F2B_CONFIRM" =~ ^[YySs]$ ]]; then
        apt-get install -y -qq fail2ban > /dev/null 2>&1 || true
        if command -v fail2ban-client >/dev/null 2>&1; then
            ok "Fail2Ban instalado"
            F2B_CONFIGURE=true
        else
            warn "No se pudo instalar Fail2Ban. Podras instalarlo despues con: apt install fail2ban -y"
        fi
    else
        warn "Fail2Ban omitido por eleccion del usuario"
        warn "Podras instalarlo despues con: apt install fail2ban -y && bash ${PANEL_DIR}/bin/update.sh --auto"
    fi
fi

if [ "$F2B_CONFIGURE" = true ]; then
    # Create log files
    touch /var/log/musedock-panel-auth.log /var/log/musedock-portal-auth.log
    chmod 644 /var/log/musedock-panel-auth.log /var/log/musedock-portal-auth.log
    mkdir -p /var/log/caddy
    touch /var/log/caddy/hosting-access.log
    chmod 644 /var/log/caddy/hosting-access.log
    ok "Log files creados"

    # Install filters
    cp "${PANEL_DIR}/config/fail2ban/filter.d/musedock-panel.conf" /etc/fail2ban/filter.d/
    cp "${PANEL_DIR}/config/fail2ban/filter.d/musedock-portal.conf" /etc/fail2ban/filter.d/
    cp "${PANEL_DIR}/config/fail2ban/filter.d/musedock-wordpress.conf" /etc/fail2ban/filter.d/
    ok "Filtros Fail2Ban instalados"

    # Install jails
    cp "${PANEL_DIR}/config/fail2ban/musedock.conf" /etc/fail2ban/jail.d/musedock.conf
    ok "Jails Fail2Ban instalados (musedock-panel, musedock-portal, musedock-wordpress)"

    # Install logrotate
    cp "${PANEL_DIR}/config/fail2ban/logrotate-musedock-auth" /etc/logrotate.d/musedock-auth
    ok "Logrotate configurado"

    # Reload fail2ban
    if systemctl is-active --quiet fail2ban 2>/dev/null; then
        fail2ban-client reload >/dev/null 2>&1
        ok "Fail2Ban recargado"
    else
        systemctl enable --now fail2ban >/dev/null 2>&1 || true
        ok "Fail2Ban iniciado"
    fi
fi

# ============================================================
# Post-installation health check
# ============================================================
fi # end of "if VERIFY_ONLY=false" block that wraps the install flow

# Disable set -e for health checks (checks may fail without killing the script)
set +e

header "$(t health_header)"

HEALTH_ERRORS=0

# 1. Panel service running?
if systemctl is-active --quiet musedock-panel 2>/dev/null; then
    ok "$(t health_panel_running)"
else
    echo -e "  ${RED}✗ $(t health_panel_down)${NC}"
    echo -e "    ${YELLOW}$(t health_panel_fix)${NC}"
    echo -e "    ${YELLOW}$(t health_panel_logs)${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 2. Panel HTTP/HTTPS responding?
sleep 2
# Valid layout: HTTPS on public PANEL_PORT via Caddy, HTTP only on internal PANEL_PORT+1.
PANEL_HTTP="000"
for TEST_URL in \
    "http://127.0.0.1:$((PANEL_PORT + 1))/" \
    "https://127.0.0.1:${PANEL_PORT}/" \
; do
    PANEL_HTTP=$(curl -sk -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 3 "$TEST_URL" 2>/dev/null || echo "000")
    PANEL_HTTP=$(echo "$PANEL_HTTP" | tr -d '[:space:]')
    [ -n "$PANEL_HTTP" ] && [ "$PANEL_HTTP" != "000" ] && break
    PANEL_HTTP="000"
done
PANEL_PUBLIC_PLAIN_HTTP=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 1 --max-time 3 "http://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || echo "000")
PANEL_PUBLIC_PLAIN_HTTP=$(echo "$PANEL_PUBLIC_PLAIN_HTTP" | tr -d '[:space:]')
if [ "$PANEL_PUBLIC_PLAIN_HTTP" = "200" ] || [ "$PANEL_PUBLIC_PLAIN_HTTP" = "302" ] || [ "$PANEL_PUBLIC_PLAIN_HTTP" = "301" ]; then
    echo -e "  ${RED}✗ El puerto publico ${PANEL_PORT} responde por HTTP plano. Esto causa ERR_SSL_PROTOCOL_ERROR en https://IP:${PANEL_PORT}.${NC}"
    echo -e "    ${YELLOW}Fix: musedock-panel debe escuchar en 127.0.0.1:$((PANEL_PORT + 1)) y Caddy debe servir TLS en ${PANEL_PORT}.${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi
if [ "$PANEL_HTTP" = "200" ] || [ "$PANEL_HTTP" = "302" ] || [ "$PANEL_HTTP" = "301" ]; then
    ok "$(t health_http_ok "$PANEL_HTTP" "$PANEL_PORT")"
elif [ "$PANEL_HTTP" = "403" ]; then
    ok "$(t health_http_ip "$PANEL_PORT")"
else
    echo -e "  ${RED}✗ $(t health_http_fail "$PANEL_HTTP" "$PANEL_PORT")${NC}"
    echo -e "    ${YELLOW}$(t health_http_fix)${NC}"
    echo -e "    ${YELLOW}  systemctl status musedock-panel${NC}"
    echo -e "    ${YELLOW}  ss -tlnp | grep ${PANEL_PORT}${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 3. PostgreSQL connection?
HEALTH_DB_HOST="${PANEL_DB_HOST:-$(grep -E '^DB_HOST=' "${PANEL_DIR}/.env" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'\''')}"
HEALTH_DB_HOST="${HEALTH_DB_HOST:-127.0.0.1}"
if PGCONNECT_TIMEOUT=5 PGPASSWORD="${DB_PASS}" psql -U "${DB_USER}" -h "${HEALTH_DB_HOST}" -p "${DB_PORT:-5433}" -d "${DB_NAME}" -c "SELECT 1;" > /dev/null 2>&1; then
    ok "$(t health_pgsql_ok "$DB_NAME" "$DB_USER")"
else
    echo -e "  ${RED}✗ $(t health_pgsql_fail "$DB_NAME" "$DB_USER")${NC}"
    echo -e "    ${YELLOW}$(t health_pgsql_fix)${NC}"
    echo -e "    ${YELLOW}  DB_HOST=${HEALTH_DB_HOST} DB_PORT=${DB_PORT:-5433}${NC}"
    echo -e "    ${YELLOW}  systemctl status postgresql${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 4. MySQL accessible?
if [ "$MYSQL_AUTH_METHOD" = "socket" ]; then
    if mysql -u root -e "SELECT 1;" > /dev/null 2>&1; then
        ok "$(t health_mysql_ok socket)"
    else
        echo -e "  ${RED}✗ $(t health_mysql_fail socket)${NC}"
        echo -e "    ${YELLOW}Fix: systemctl status mysql${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi
elif [ "$MYSQL_AUTH_METHOD" = "password" ]; then
    if mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1;" > /dev/null 2>&1; then
        ok "$(t health_mysql_ok password)"
    else
        echo -e "  ${RED}✗ $(t health_mysql_fail password)${NC}"
        echo -e "    ${YELLOW}Fix: Check MYSQL_ROOT_PASS in .env${NC}"
        HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
    fi
else
    warn "$(t health_mysql_skip)"
fi

# 5. Caddy API?
CADDY_HC=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://127.0.0.1:2019/config/ 2>/dev/null || echo "000")
if [ "$CADDY_HC" = "200" ]; then
    ok "$(t health_caddy_ok)"
else
    echo -e "  ${RED}✗ $(t health_caddy_fail "$CADDY_HC")${NC}"
    echo -e "    ${YELLOW}$(t health_caddy_fix)${NC}"
    echo -e "    ${YELLOW}  systemctl status caddy${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# 6. Caddy ports 80/443
if systemctl is-active --quiet caddy 2>/dev/null; then
    CADDY_ON_80=$(ss -tlnp 2>/dev/null | grep ':80\b.*caddy' | wc -l)
    CADDY_ON_443=$(ss -tlnp 2>/dev/null | grep ':443\b.*caddy' | wc -l)
    if [ "$CADDY_ON_80" -gt 0 ] || [ "$CADDY_ON_443" -gt 0 ]; then
        ok "$(t health_caddy_ports)"
    else
        if [ "${CADDY_HAS_CADDYFILE_DOMAINS:-false}" = true ] || [ "${CADDY_HAS_AUTOSAVE:-false}" = true ]; then
            warn "$(t health_caddy_no_ports)"
        fi
    fi
fi

# 7. PHP-FPM running?
if systemctl is-active --quiet "php${PHP_VER}-fpm" 2>/dev/null; then
    ok "$(t health_fpm_ok "$PHP_VER")"
else
    echo -e "  ${RED}✗ $(t health_fpm_fail "$PHP_VER")${NC}"
    echo -e "    ${YELLOW}Fix: systemctl start php${PHP_VER}-fpm${NC}"
    HEALTH_ERRORS=$((HEALTH_ERRORS + 1))
fi

# Health check summary
echo ""
if [ "$HEALTH_ERRORS" -eq 0 ]; then
    echo -e "  ${GREEN}${BOLD}$(t health_all_ok "$HEALTH_ERRORS")${NC}"
else
    echo -e "  ${RED}${BOLD}$(t health_errors "$HEALTH_ERRORS")${NC}"
    echo -e "  ${YELLOW}$(t health_review)${NC}"
fi

# If verify-only mode, show summary and exit
if [ "$VERIFY_ONLY" = true ]; then
    echo ""
    header "$(t verify_complete)"
    if [ "$HEALTH_ERRORS" -eq 0 ]; then
        echo -e "  ${GREEN}${BOLD}$(t verify_all_ok)${NC}"
    else
        echo -e "  ${RED}${BOLD}$(t verify_has_errors "$HEALTH_ERRORS")${NC}"
    fi
    echo ""
    exit 0
fi

if [ "$UPDATE_ONLY" = true ]; then
    echo ""
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo -e "${GREEN}${BOLD}"
    echo "  ╔══════════════════════════════════════════════════╗"
    echo "  ║         $(t update_complete)                    ║"
    echo "  ╚══════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    echo -e "  ${BOLD}Panel:${NC} https://${SERVER_IP}:${PANEL_PORT}/"
    echo -e "  ${BOLD}Estado:${NC} systemctl status musedock-panel"
    echo ""
    if [ "$HEALTH_ERRORS" -gt 0 ]; then
        echo -e "  ${RED}${BOLD}$(t health_errors "$HEALTH_ERRORS")${NC}"
        echo -e "  ${YELLOW}$(t health_review)${NC}"
    else
        echo -e "  ${GREEN}${BOLD}$(t health_all_ok "$HEALTH_ERRORS")${NC}"
    fi
    echo ""
    exit 0
fi

# ============================================================
# Interactive firewall / IP restriction setup
# ============================================================
header "$(t fw_setup_header)"
echo ""
echo -e "  ${RED}${BOLD}$(t fw_setup_desc "$PANEL_PORT")${NC}"
echo ""

# Detect what firewall is available without changing it.
FW_ENGINE="none"
FW_UFW_INSTALLED=false
FW_UFW_ACTIVE=false
FW_IPTABLES_INSTALLED=false
FW_IPTABLES_RESTRICTIVE=false

if command -v ufw &> /dev/null; then
    FW_UFW_INSTALLED=true
    if ufw status 2>/dev/null | grep -q "Status: active"; then
        FW_UFW_ACTIVE=true
    fi
fi

if command -v iptables &> /dev/null; then
    FW_IPTABLES_INSTALLED=true
    if iptables -L INPUT -n 2>/dev/null | head -1 | grep -qE "policy (DROP|REJECT)"; then
        FW_IPTABLES_RESTRICTIVE=true
    fi
fi

if [ "$FW_UFW_ACTIVE" = true ]; then
    FW_ENGINE="ufw"
    ok "$(t fw_setup_detect_ufw)"
elif [ "$FW_IPTABLES_RESTRICTIVE" = true ]; then
    FW_ENGINE="iptables"
    ok "$(t fw_setup_detect_ipt)"
elif [ "$FW_UFW_INSTALLED" = true ]; then
    FW_ENGINE="ufw_inactive"
    ok "$(t fw_setup_detect_ufw_inactive)"
elif [ "$FW_IPTABLES_INSTALLED" = true ]; then
    FW_ENGINE="iptables_open"
    ok "$(t fw_setup_detect_ipt_open)"
else
    FW_ENGINE="install_ufw"
    ok "$(t fw_setup_detect_none)"
fi

case "$FW_ENGINE" in
    ufw) FW_MODE_LABEL=$([ "$LANG_CODE" = "es" ] && echo "UFW existente activo" || echo "existing active UFW") ;;
    iptables) FW_MODE_LABEL=$([ "$LANG_CODE" = "es" ] && echo "iptables existente activo" || echo "existing active iptables") ;;
    ufw_inactive) FW_MODE_LABEL=$([ "$LANG_CODE" = "es" ] && echo "activar UFW existente si confirmas" || echo "activate existing UFW only if confirmed") ;;
    iptables_open) FW_MODE_LABEL=$([ "$LANG_CODE" = "es" ] && echo "usar iptables existente si confirmas" || echo "use existing iptables only if confirmed") ;;
    install_ufw) FW_MODE_LABEL=$([ "$LANG_CODE" = "es" ] && echo "instalar UFW solo si confirmas" || echo "install UFW only if confirmed") ;;
esac
ok "$(t fw_setup_selected "$FW_MODE_LABEL")"

echo ""
prompt_read "  $(t fw_setup_ask)" FW_SETUP_CONFIRM
FW_SETUP_CONFIRM=${FW_SETUP_CONFIRM:-s}

if [[ "$FW_SETUP_CONFIRM" =~ ^[YySs]$ ]] || [ -z "$FW_SETUP_CONFIRM" ]; then
    # Collect IPs
    FW_ALLOWED_IPS=()
    while true; do
        echo ""
        prompt_read "  $(t fw_setup_ip_prompt): " FW_IP
        FW_IP=$(echo "$FW_IP" | tr -d ' ')

        # Validate IP or CIDR
        if echo "$FW_IP" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}(/[0-9]{1,2})?$'; then
            FW_ALLOWED_IPS+=("$FW_IP")
        else
            warn "$(t fw_setup_ip_invalid "$FW_IP")"
            continue
        fi

        echo ""
        prompt_read "  $(t fw_setup_ip_another)" FW_MORE
        if [[ ! "$FW_MORE" =~ ^[YySs]$ ]]; then
            break
        fi
    done

    if [ ${#FW_ALLOWED_IPS[@]} -gt 0 ]; then
        # --- Apply firewall rules ---
        case "$FW_ENGINE" in
            ufw)
                echo ""
                ok "$(t fw_setup_applying_ufw)"
                # Remove any existing broad allow on PANEL_PORT
                ufw delete allow "${PANEL_PORT}/tcp" 2>/dev/null || true
                ufw delete allow "$PANEL_PORT" 2>/dev/null || true
                for ip in "${FW_ALLOWED_IPS[@]}"; do
                    ufw allow from "$ip" to any port "$PANEL_PORT" proto tcp > /dev/null 2>&1
                    ok "$(t fw_setup_ufw_rule "$ip" "$PANEL_PORT")"
                done
                # Deny general access to panel port
                ufw deny "$PANEL_PORT"/tcp > /dev/null 2>&1
                ok "$(t fw_setup_ufw_deny "$PANEL_PORT")"
                # Ensure SSH is allowed and UFW is active
                ufw allow 22/tcp > /dev/null 2>&1
                ufw allow 80/tcp > /dev/null 2>&1
                ufw allow 443/tcp > /dev/null 2>&1
                ufw --force enable > /dev/null 2>&1
                ;;
            ufw_inactive|install_ufw)
                echo ""
                if ! command -v ufw &> /dev/null; then
                    ok "$(t fw_setup_installing_ufw)"
                    apt-get install -y -qq ufw > /dev/null 2>&1
                fi
                ok "$(t fw_setup_applying_ufw)"
                # Default policy: allow outgoing, deny incoming (except what we allow)
                ufw default deny incoming > /dev/null 2>&1
                ufw default allow outgoing > /dev/null 2>&1
                # Essential ports
                ufw allow 22/tcp > /dev/null 2>&1
                ufw allow 80/tcp > /dev/null 2>&1
                ufw allow 443/tcp > /dev/null 2>&1
                # Panel port: only from allowed IPs
                for ip in "${FW_ALLOWED_IPS[@]}"; do
                    ufw allow from "$ip" to any port "$PANEL_PORT" proto tcp > /dev/null 2>&1
                    ok "$(t fw_setup_ufw_rule "$ip" "$PANEL_PORT")"
                done
                ufw deny "$PANEL_PORT"/tcp > /dev/null 2>&1
                ok "$(t fw_setup_ufw_deny "$PANEL_PORT")"
                ufw --force enable > /dev/null 2>&1
                ;;
            iptables|iptables_open)
                echo ""
                ok "$(t fw_setup_applying_ipt)"
                # Remove existing rules for PANEL_PORT
                while iptables -D INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null; do :; done
                while iptables -D INPUT -p tcp --dport "$PANEL_PORT" -j DROP 2>/dev/null; do :; done
                # Allow from each IP
                for ip in "${FW_ALLOWED_IPS[@]}"; do
                    iptables -A INPUT -p tcp -s "$ip" --dport "$PANEL_PORT" -j ACCEPT
                    ok "$(t fw_setup_ipt_rule "$ip" "$PANEL_PORT")"
                done
                # Drop everyone else
                iptables -A INPUT -p tcp --dport "$PANEL_PORT" -j DROP
                ok "$(t fw_setup_ipt_deny "$PANEL_PORT")"
                # Save rules if iptables-persistent is available
                if command -v netfilter-persistent &> /dev/null; then
                    netfilter-persistent save > /dev/null 2>&1
                    ok "$(t fw_setup_ipt_saved)"
                elif command -v iptables-save &> /dev/null; then
                    iptables-save > /etc/iptables/rules.v4 2>/dev/null || \
                    iptables-save > /etc/iptables.rules 2>/dev/null || true
                    ok "$(t fw_setup_ipt_saved)"
                fi
                ;;
        esac

        # --- Update ALLOWED_IPS in .env ---
        ENV_FILE="${PANEL_DIR}/.env"
        IP_LIST=$(IFS=','; echo "${FW_ALLOWED_IPS[*]}")
        if grep -q "^ALLOWED_IPS=" "$ENV_FILE" 2>/dev/null; then
            sed -i "s|^ALLOWED_IPS=.*|ALLOWED_IPS=${IP_LIST}|" "$ENV_FILE"
        else
            echo "ALLOWED_IPS=${IP_LIST}" >> "$ENV_FILE"
        fi
        ok "$(t fw_setup_env_ips "$IP_LIST")"

        echo ""
        echo -e "  ${GREEN}${BOLD}$(t fw_setup_done "$IP_LIST")${NC}"
    fi
else
    echo ""
    echo -e "  ${RED}${BOLD}$(t fw_setup_skip "$PANEL_PORT")${NC}"
    echo -e "  ${RED}$(t fw_setup_skip_warn)${NC}"
fi

# ============================================================
# Done!
# ============================================================
SERVER_IP=$(hostname -I | awk '{print $1}')

# Detect firewall status
FIREWALL_TYPE="none"
FIREWALL_PORT_STATUS="unknown"

if command -v ufw &> /dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
    FIREWALL_TYPE="ufw"
    if ufw status 2>/dev/null | grep -qE "${PANEL_PORT}.*(ALLOW)"; then
        FIREWALL_PORT_STATUS="open"
    else
        FIREWALL_PORT_STATUS="closed"
    fi
elif iptables -L INPUT -n 2>/dev/null | head -1 | grep -qE "policy (DROP|REJECT)" 2>/dev/null; then
    FIREWALL_TYPE="iptables"
    # Check if port is explicitly allowed OR if there are broad ACCEPT rules (ACCEPT all -- source)
    if iptables -L INPUT -n 2>/dev/null | grep -qE "ACCEPT.*dpt:${PANEL_PORT}"; then
        FIREWALL_PORT_STATUS="open"
    elif iptables -L INPUT -n 2>/dev/null | grep -qE "ACCEPT\s+all\s+--\s+[0-9]"; then
        # There are IP-based ACCEPT ALL rules — port is likely accessible from those IPs
        FIREWALL_PORT_STATUS="open_by_ip"
    elif iptables -L INPUT -n 2>/dev/null | head -1 | grep -q "DROP"; then
        FIREWALL_PORT_STATUS="closed"
    else
        # Default policy is ACCEPT or there are broad rules
        FIREWALL_PORT_STATUS="open"
    fi
fi

echo ""
echo -e "${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║         $(t install_complete)                  ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo -e "${NC}"

echo ""
echo -e "  ┌──────────────────────────────────────────────┐"
echo -e "  │  ${BOLD}$(t save_credentials)${NC}"
echo -e "  │                                              │"
echo -e "  │  Database:   ${BOLD}${DB_NAME}${NC}"
echo -e "  │  DB User:    ${BOLD}${DB_USER}${NC}"
echo -e "  │  DB Password: ${BOLD}${DB_PASS}${NC}"
echo -e "  │  .env file:  ${BOLD}${PANEL_DIR}/.env${NC}"
echo -e "  │                                              │"
echo -e "  │  $(t mysql_auth_label)${BOLD}${MYSQL_AUTH_METHOD}${NC}"
if [ "$MYSQL_AUTH_METHOD" = "password" ]; then
echo -e "  │  $(t mysql_pass_stored)"
elif [ "$MYSQL_AUTH_METHOD" = "socket" ]; then
echo -e "  │  $(t mysql_pass_socket)"
elif [ "$MYSQL_AUTH_METHOD" = "unconfigured" ]; then
echo -e "  │  ${YELLOW}$(t mysql_pass_notset)${NC}"
fi
echo -e "  │                                              │"
echo -e "  │  $(t stored_in_env)"
echo -e "  └──────────────────────────────────────────────┘"
echo ""

# Detection summary
if [ "${NGINX_DETECTED:-false}" = true ] || [ "${APACHE_DETECTED:-false}" = true ] || [ "${PLESK_DETECTED:-false}" = true ]; then
    echo -e "  ${YELLOW}${BOLD}$(t detection_summary)${NC}"
    [ "${PLESK_DETECTED:-false}" = true ] && echo -e "  ${YELLOW}  $(t plesk_detected_sum)${NC}"
    [ "${NGINX_DETECTED:-false}" = true ] && echo -e "  ${YELLOW}  $(t nginx_detected_sum "$(systemctl is-active nginx 2>/dev/null || echo 'unknown')")${NC}"
    [ "${APACHE_DETECTED:-false}" = true ] && echo -e "  ${YELLOW}  $(t apache_detected_sum "$(systemctl is-active ${APACHE_SVC:-apache2} 2>/dev/null || echo 'unknown')")${NC}"
    echo ""
fi

echo -e "  ${YELLOW}${BOLD}$(t security_header)${NC}"
echo ""
echo -e "  ${YELLOW}1.${NC} ${BOLD}$(t security_firewall)${NC}"
echo -e "     $(t security_root)"
echo -e "     $(t security_trusted "$PANEL_PORT")"
echo ""
echo -e "  ${YELLOW}2.${NC} ${BOLD}$(t security_restrict)${NC}"
echo -e "     ${CYAN}ALLOWED_IPS=1.2.3.4,5.6.7.8${NC}"
echo ""
echo -e "  ${YELLOW}3.${NC} ${BOLD}$(t security_admin_only)${NC}"
echo -e "     $(t security_no_public)"
echo -e "     $(t security_client_sites)"
echo ""
echo -e "  ${YELLOW}${BOLD}TLS del panel admin (${PANEL_PORT})${NC}"
if [ "$LANG_CODE" = "es" ]; then
    echo -e "     - Modo por defecto: certificado interno/autofirmado (no depende de Internet)."
    echo -e "     - Opcion 1: Let's Encrypt HTTP-01/TLS-ALPN-01 (requiere 80/443 abiertos para emitir/renovar; fallback interno automatico si falla)."
    echo -e "     - Opcion 2: Let's Encrypt DNS-01 (sin abrir 80/443 para certificar; requiere proveedor DNS con API; fallback interno automatico)."
    echo -e "     - Se configura desde: Settings -> Servidor (TLS del panel)."
else
    echo -e "     - Default mode: internal/self-signed certificate (no Internet dependency)."
    echo -e "     - Option 1: Let's Encrypt HTTP-01/TLS-ALPN-01 (requires public 80/443 for issue/renew; automatic internal fallback on failure)."
    echo -e "     - Option 2: Let's Encrypt DNS-01 (no need to open 80/443 for cert issuance; requires DNS API provider; automatic internal fallback)."
    echo -e "     - Configure it from: Settings -> Server (Panel TLS mode)."
fi
echo ""
echo -e "  ${BOLD}$(t service_mgmt)${NC}"
echo -e "     systemctl status musedock-panel"
echo -e "     systemctl restart musedock-panel"
echo -e "     journalctl -u musedock-panel -f"
echo ""
echo -e "  ${BOLD}$(t snapshot_label)${NC}"
echo -e "     ${SNAPSHOT_DIR}/${SNAPSHOT_TS}/"
echo -e "     $(t snapshot_desc)"
echo ""
echo -e "  ${BOLD}$(t uninstall_label)${NC}"
echo -e "     sudo bash ${PANEL_DIR}/bin/uninstall.sh"
echo ""

# ============================================================
# Final prominent access panel — ALWAYS at the very end
# ============================================================
echo ""
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════════╗"
echo "  ║                                                          ║"
echo "  ║   $(t firewall_header)                                   ║"
echo "  ║                                                          ║"
echo "  ╚══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Firewall status
if [ "$FIREWALL_TYPE" = "ufw" ]; then
    echo -e "  ${YELLOW}$(t firewall_ufw_active)${NC}"
    if [ "$FIREWALL_PORT_STATUS" = "open" ]; then
        echo -e "  ${GREEN}✓ $(t firewall_port_open "$PANEL_PORT")${NC}"
    else
        echo -e "  ${RED}${BOLD}✗ $(t firewall_port_closed "$PANEL_PORT")${NC}"
        echo ""
        echo -e "  ${BOLD}$(t firewall_open_cmd)${NC}"
        echo -e "  ${CYAN}  ufw allow from TU_IP to any port ${PANEL_PORT}${NC}"
    fi
elif [ "$FIREWALL_TYPE" = "iptables" ]; then
    echo -e "  ${YELLOW}$(t firewall_iptables_active)${NC}"
    if [ "$FIREWALL_PORT_STATUS" = "open" ] || [ "$FIREWALL_PORT_STATUS" = "open_by_ip" ]; then
        if [ "$FIREWALL_PORT_STATUS" = "open_by_ip" ]; then
            echo -e "  ${GREEN}✓ $(t firewall_port_open_ip "$PANEL_PORT")${NC}"
        else
            echo -e "  ${GREEN}✓ $(t firewall_port_open "$PANEL_PORT")${NC}"
        fi
    else
        echo -e "  ${RED}${BOLD}✗ $(t firewall_port_closed "$PANEL_PORT")${NC}"
        echo ""
        echo -e "  ${BOLD}$(t firewall_open_cmd)${NC}"
        echo -e "  ${CYAN}  iptables -A INPUT -p tcp --dport ${PANEL_PORT} -s TU_IP -j ACCEPT${NC}"
    fi
else
    echo -e "  ${YELLOW}$(t firewall_no_detected)${NC}"
    echo -e "  ${YELLOW}$(t firewall_consider "$PANEL_PORT")${NC}"
fi

echo ""
echo -e "  $(t setup_wizard)"
echo ""
echo -e "  ${GREEN}${BOLD}┌──────────────────────────────────────────────────────────┐${NC}"
echo -e "  ${GREEN}${BOLD}│                                                          │${NC}"
echo -e "  ${GREEN}${BOLD}│   $(t firewall_access_url)                               │${NC}"
echo -e "  ${GREEN}${BOLD}│                                                          │${NC}"
echo -e "  ${GREEN}${BOLD}│   >>> https://${SERVER_IP}:${PANEL_PORT}/setup              │${NC}"
echo -e "  ${GREEN}${BOLD}│                                                          │${NC}"
echo -e "  ${GREEN}${BOLD}│   $(t firewall_make_sure "$PANEL_PORT")  │${NC}"
echo -e "  ${GREEN}${BOLD}│                                                          │${NC}"
echo -e "  ${GREEN}${BOLD}└──────────────────────────────────────────────────────────┘${NC}"
echo ""
echo -e "  ${CYAN}${BOLD}$(t next_steps_header)${NC}"
echo ""
echo -e "  ${CYAN}📧${NC}  $(t next_steps_mail)"
echo -e "  ${CYAN}🖧${NC}  $(t next_steps_cluster)"
echo -e "  ${CYAN}🔄${NC}  $(t next_steps_replication)"
echo ""
echo -e "  ${YELLOW}$(t next_steps_hint)${NC}"
echo ""

echo -e "  ${GREEN}${BOLD}$(t enjoy)${NC}"
echo ""
