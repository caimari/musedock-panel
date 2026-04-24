#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# MuseDock Panel — Mail Node Installer
# ═══════════════════════════════════════════════════════════════
#
# Installs and configures: Postfix, Dovecot, OpenDKIM, Rspamd
# on a node that will serve as a dedicated mail server.
#
# Prerequisites:
#   - Ubuntu 22.04/24.04 or Debian 12
#   - PostgreSQL replica already set up (via Panel Replication)
#   - Node already added to cluster_nodes with services: ["mail"]
#   - Root access
#
# Usage:
#   sudo bash install-mail-node.sh --db-host=localhost --db-name=musedock_panel \
#       --db-user=musedock_mail --db-pass=SECRET --mail-hostname=mail.example.com
#
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

# ── Colors ────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }
step() { echo -e "\n${BOLD}${CYAN}── $1 ──${NC}\n"; }

# ── Parse arguments ───────────────────────────────────────────
DB_HOST="localhost"
DB_NAME="musedock_panel"
DB_USER="musedock_mail"
DB_PASS=""
MAIL_HOSTNAME=""
VMAIL_UID=5000
VMAIL_GID=5000
MAIL_DIR="/var/mail/vhosts"
SSL_MODE="letsencrypt"  # letsencrypt | selfsigned | manual
PG_PORT="5433"          # Panel PostgreSQL port

for arg in "$@"; do
    case "$arg" in
        --db-host=*)       DB_HOST="${arg#*=}" ;;
        --db-name=*)       DB_NAME="${arg#*=}" ;;
        --db-user=*)       DB_USER="${arg#*=}" ;;
        --db-pass=*)       DB_PASS="${arg#*=}" ;;
        --mail-hostname=*) MAIL_HOSTNAME="${arg#*=}" ;;
        --ssl=*)           SSL_MODE="${arg#*=}" ;;
        --help|-h)
            echo "Usage: sudo bash $0 --db-pass=SECRET --mail-hostname=mail.example.com"
            echo ""
            echo "Options:"
            echo "  --db-host=HOST       PostgreSQL host (default: localhost)"
            echo "  --db-name=NAME       Database name (default: musedock_panel)"
            echo "  --db-user=USER       Database user (default: musedock_mail)"
            echo "  --db-pass=PASS       Database password (REQUIRED)"
            echo "  --mail-hostname=HOST Mail hostname for TLS cert (REQUIRED)"
            echo "  --ssl=MODE           letsencrypt|selfsigned|manual (default: letsencrypt)"
            exit 0
            ;;
        *) warn "Unknown argument: $arg" ;;
    esac
done

# ── Validate ──────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "This script must be run as root"
[[ -z "$DB_PASS" ]] && err "Database password is required (--db-pass=...)"
[[ -z "$MAIL_HOSTNAME" ]] && err "Mail hostname is required (--mail-hostname=...)"

# Detect OS
if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS_ID="$ID"
    OS_VERSION="$VERSION_ID"
else
    err "Cannot detect OS version"
fi

case "$OS_ID" in
    ubuntu|debian) ;;
    *) err "Unsupported OS: $OS_ID. Only Ubuntu/Debian supported." ;;
esac

echo ""
echo -e "${BOLD}MuseDock Mail Node Installer${NC}"
echo -e "Mail hostname: ${CYAN}${MAIL_HOSTNAME}${NC}"
echo -e "Database:      ${CYAN}${DB_USER}@${DB_HOST}/${DB_NAME}${NC}"
echo -e "SSL mode:      ${CYAN}${SSL_MODE}${NC}"
echo -e "OS:            ${CYAN}${OS_ID} ${OS_VERSION}${NC}"
echo ""
read -rp "Continue? [y/N] " confirm
[[ "$confirm" != "y" && "$confirm" != "Y" ]] && exit 0

# ══════════════════════════════════════════════════════════════
step "Step 1/7: System packages"
# ══════════════════════════════════════════════════════════════

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -yqq curl wget gnupg2 apt-transport-https ca-certificates lsb-release

# ══════════════════════════════════════════════════════════════
step "Step 2/7: Create vmail user"
# ══════════════════════════════════════════════════════════════

if id vmail &>/dev/null; then
    log "vmail user already exists"
else
    groupadd -g $VMAIL_GID vmail 2>/dev/null || true
    useradd -r -u $VMAIL_UID -g $VMAIL_GID -d "$MAIL_DIR" -s /usr/sbin/nologin vmail
    log "Created vmail user (uid=$VMAIL_UID)"
fi

mkdir -p "$MAIL_DIR"
chown vmail:vmail "$MAIL_DIR"
chmod 700 "$MAIL_DIR"

# Trash directory for deleted mailboxes
mkdir -p /var/mail/trash
chown vmail:vmail /var/mail/trash

# ══════════════════════════════════════════════════════════════
step "Step 3/7: Install Postfix + Dovecot + OpenDKIM"
# ══════════════════════════════════════════════════════════════

# Pre-configure Postfix to avoid interactive prompts
debconf-set-selections <<< "postfix postfix/mailname string $MAIL_HOSTNAME"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"

apt-get install -yqq \
    postfix postfix-pgsql \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-pgsql dovecot-sieve dovecot-managesieved \
    opendkim opendkim-tools \
    certbot

log "Packages installed"

# ══════════════════════════════════════════════════════════════
step "Step 4/7: Install Rspamd"
# ══════════════════════════════════════════════════════════════

if ! command -v rspamd &>/dev/null; then
    # Add Rspamd repository
    curl -fsSL https://rspamd.com/apt-stable/gpg.key | gpg --dearmor -o /etc/apt/trusted.gpg.d/rspamd.gpg
    echo "deb http://rspamd.com/apt-stable/ $(lsb_release -cs) main" > /etc/apt/sources.list.d/rspamd.list
    apt-get update -qq
    apt-get install -yqq rspamd
    log "Rspamd installed"
else
    log "Rspamd already installed"
fi

# ══════════════════════════════════════════════════════════════
step "Step 5/7: Configure Postfix"
# ══════════════════════════════════════════════════════════════

POSTFIX_DIR="/etc/postfix"

# PostgreSQL lookup: virtual domains
cat > "$POSTFIX_DIR/pgsql-virtual-domains.cf" <<PGEOF
hosts = $DB_HOST:$PG_PORT
dbname = $DB_NAME
user = $DB_USER
password = $DB_PASS
query = SELECT domain FROM mail_domains WHERE domain = '%s' AND status = 'active'
PGEOF

# PostgreSQL lookup: virtual mailboxes
cat > "$POSTFIX_DIR/pgsql-virtual-mailboxes.cf" <<PGEOF
hosts = $DB_HOST:$PG_PORT
dbname = $DB_NAME
user = $DB_USER
password = $DB_PASS
query = SELECT CONCAT(md.domain, '/', ma.local_part, '/Maildir/') FROM mail_accounts ma JOIN mail_domains md ON md.id = ma.mail_domain_id WHERE ma.email = '%s' AND ma.status = 'active'
PGEOF

# PostgreSQL lookup: virtual aliases
cat > "$POSTFIX_DIR/pgsql-virtual-aliases.cf" <<PGEOF
hosts = $DB_HOST:$PG_PORT
dbname = $DB_NAME
user = $DB_USER
password = $DB_PASS
query = SELECT destination FROM mail_aliases WHERE (source = '%s' OR (is_catchall = true AND source = CONCAT('@', split_part('%s', '@', 2)))) AND is_active = true LIMIT 1
PGEOF

# Secure the lookup files (contain passwords)
chmod 640 "$POSTFIX_DIR"/pgsql-*.cf
chown root:postfix "$POSTFIX_DIR"/pgsql-*.cf

# Main.cf
postconf -e "myhostname = $MAIL_HOSTNAME"
postconf -e "mydomain = $(echo "$MAIL_HOSTNAME" | cut -d. -f2-)"
postconf -e "myorigin = \$mydomain"
postconf -e "mydestination = localhost"
postconf -e "mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128"

# Virtual transport via Dovecot LMTP
postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
postconf -e "virtual_mailbox_base = $MAIL_DIR"
postconf -e "virtual_mailbox_domains = pgsql:$POSTFIX_DIR/pgsql-virtual-domains.cf"
postconf -e "virtual_mailbox_maps = pgsql:$POSTFIX_DIR/pgsql-virtual-mailboxes.cf"
postconf -e "virtual_alias_maps = pgsql:$POSTFIX_DIR/pgsql-virtual-aliases.cf"
postconf -e "virtual_uid_maps = static:$VMAIL_UID"
postconf -e "virtual_gid_maps = static:$VMAIL_GID"

# TLS (placeholder — certs generated in Step 6)
postconf -e "smtpd_use_tls = yes"
postconf -e "smtpd_tls_auth_only = yes"
postconf -e "smtpd_tls_security_level = may"
postconf -e "smtpd_tls_protocols = !SSLv2,!SSLv3,!TLSv1,!TLSv1.1"
postconf -e "smtp_tls_security_level = may"
postconf -e "smtp_tls_protocols = !SSLv2,!SSLv3,!TLSv1,!TLSv1.1"

# SASL via Dovecot
postconf -e "smtpd_sasl_type = dovecot"
postconf -e "smtpd_sasl_path = private/auth"
postconf -e "smtpd_sasl_auth_enable = yes"

# Restrictions
postconf -e "smtpd_recipient_restrictions = permit_sasl_authenticated,permit_mynetworks,reject_unauth_destination"

# Limits
postconf -e "message_size_limit = 26214400"
postconf -e "mailbox_size_limit = 0"

# DKIM milter
postconf -e "milter_protocol = 6"
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = unix:opendkim/opendkim.sock"
postconf -e "non_smtpd_milters = unix:opendkim/opendkim.sock"

# Rspamd milter (after DKIM)
postconf -e "smtpd_milters = unix:opendkim/opendkim.sock,inet:localhost:11332"
postconf -e "non_smtpd_milters = unix:opendkim/opendkim.sock,inet:localhost:11332"

# Enable submission (port 587) in master.cf
if ! grep -q "^submission" /etc/postfix/master.cf; then
    cat >> /etc/postfix/master.cf <<'MCEOF'

submission inet n       -       y       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
  -o milter_macro_daemon_name=ORIGINATING
MCEOF
    log "Submission (587) enabled"
fi

log "Postfix configured"

# ══════════════════════════════════════════════════════════════
step "Step 6/7: Configure Dovecot"
# ══════════════════════════════════════════════════════════════

DOVECOT_DIR="/etc/dovecot"

# SQL authentication config
cat > "$DOVECOT_DIR/dovecot-sql.conf" <<DOVEEOF
driver = pgsql
connect = host=$DB_HOST port=$PG_PORT dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = BLF-CRYPT

password_query = SELECT email AS user, password_hash AS password \\
  FROM mail_accounts \\
  WHERE email = '%u' AND status = 'active'

user_query = SELECT \\
    email AS user, \\
    $VMAIL_UID AS uid, \\
    $VMAIL_GID AS gid, \\
    home_dir AS home, \\
    CONCAT('*:bytes=', quota_mb * 1048576) AS quota_rule \\
  FROM mail_accounts \\
  WHERE email = '%u' AND status = 'active'

iterate_query = SELECT email AS user FROM mail_accounts WHERE status = 'active'
DOVEEOF

chmod 640 "$DOVECOT_DIR/dovecot-sql.conf"
chown root:dovecot "$DOVECOT_DIR/dovecot-sql.conf"

# Main Dovecot config
cat > "$DOVECOT_DIR/conf.d/10-musedock.conf" <<'DOVECONF'
# MuseDock mail node configuration

# Auth
auth_mechanisms = plain login
protocols = imap lmtp sieve

passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf
}

userdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf
}

# Mail location
mail_location = maildir:~/Maildir
mail_uid = 5000
mail_gid = 5000
mail_privileged_group = vmail
first_valid_uid = 5000

# Quota + Sieve
mail_plugins = $mail_plugins quota sieve
protocol imap {
  mail_plugins = $mail_plugins imap_quota
}
protocol lmtp {
  mail_plugins = $mail_plugins sieve
}

plugin {
  quota = maildir:User quota
  quota_grace = 10%%
  quota_status_success = DUNNO
  quota_status_nouser = DUNNO
  quota_status_overquota = "552 5.2.2 Mailbox is full"
  sieve = file:~/sieve;active=~/.dovecot.sieve
  sieve_default = /etc/dovecot/sieve/default.sieve
  sieve_global_extensions = +vacation +copy +include
}

# LMTP for Postfix delivery
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

# Auth socket for Postfix SASL
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
  unix_listener auth-userdb {
    mode = 0660
    user = vmail
    group = vmail
  }
}

# ManageSieve for Roundcube filters/vacation
service managesieve-login {
  inet_listener sieve {
    port = 4190
  }
}

# SSL (paths set by certbot/manual below)
ssl = required
DOVECONF

mkdir -p /etc/dovecot/sieve
echo 'require ["fileinto"];' > /etc/dovecot/sieve/default.sieve
sievec /etc/dovecot/sieve/default.sieve 2>/dev/null || true

log "Dovecot configured"

# ══════════════════════════════════════════════════════════════
step "Step 7/7: SSL/TLS Certificates"
# ══════════════════════════════════════════════════════════════

CERT_DIR="/etc/letsencrypt/live/$MAIL_HOSTNAME"

case "$SSL_MODE" in
    letsencrypt)
        log "Requesting Let's Encrypt certificate for $MAIL_HOSTNAME..."
        # Stop services briefly for standalone mode
        systemctl stop postfix 2>/dev/null || true

        certbot certonly --standalone -d "$MAIL_HOSTNAME" --agree-tos --non-interactive \
            --register-unsafely-without-email --preferred-challenges http || {
            warn "Let's Encrypt failed. Falling back to self-signed cert."
            SSL_MODE="selfsigned"
        }

        systemctl start postfix 2>/dev/null || true

        if [[ "$SSL_MODE" == "letsencrypt" ]]; then
            # Auto-renew hook to reload services
            cat > /etc/letsencrypt/renewal-hooks/post/mail-services.sh <<'RENEWEOF'
#!/bin/bash
systemctl reload postfix dovecot 2>/dev/null || true
RENEWEOF
            chmod +x /etc/letsencrypt/renewal-hooks/post/mail-services.sh
            log "Let's Encrypt certificate obtained + auto-renewal configured"
        fi
        ;;

    selfsigned)
        log "Generating self-signed certificate..."
        mkdir -p "$CERT_DIR"
        openssl req -new -x509 -days 3650 -nodes \
            -out "$CERT_DIR/fullchain.pem" \
            -keyout "$CERT_DIR/privkey.pem" \
            -subj "/CN=$MAIL_HOSTNAME"
        log "Self-signed cert created (NOT recommended for production)"
        ;;

    manual)
        warn "Manual SSL mode selected."
        warn "Place your cert at: $CERT_DIR/fullchain.pem"
        warn "Place your key at: $CERT_DIR/privkey.pem"
        warn "Then restart: systemctl restart postfix dovecot"
        mkdir -p "$CERT_DIR"
        # Create placeholder to avoid startup errors
        if [[ ! -f "$CERT_DIR/fullchain.pem" ]]; then
            openssl req -new -x509 -days 30 -nodes \
                -out "$CERT_DIR/fullchain.pem" \
                -keyout "$CERT_DIR/privkey.pem" \
                -subj "/CN=$MAIL_HOSTNAME"
            warn "Temporary self-signed cert created as placeholder"
        fi
        ;;
esac

# Apply cert paths to Postfix and Dovecot
postconf -e "smtpd_tls_cert_file = $CERT_DIR/fullchain.pem"
postconf -e "smtpd_tls_key_file = $CERT_DIR/privkey.pem"

# Dovecot SSL
cat > "$DOVECOT_DIR/conf.d/10-ssl.conf" <<SSLCONF
ssl = required
ssl_cert = <$CERT_DIR/fullchain.pem
ssl_key = <$CERT_DIR/privkey.pem
ssl_min_protocol = TLSv1.2
ssl_prefer_server_ciphers = yes
SSLCONF

log "SSL configured"

# ══════════════════════════════════════════════════════════════
step "Configuring OpenDKIM"
# ══════════════════════════════════════════════════════════════

mkdir -p /etc/opendkim/keys
chown -R opendkim:opendkim /etc/opendkim

# OpenDKIM config
cat > /etc/opendkim.conf <<'DKIMCONF'
Syslog          yes
SyslogSuccess   yes
LogWhy          yes
Mode            sv
Canonicalization relaxed/simple
Domain          *
SubDomains      no
AutoRestart     yes
AutoRestartRate 10/1M
Background      yes

KeyTable        /etc/opendkim/key.table
SigningTable    refile:/etc/opendkim/signing.table
InternalHosts   /etc/opendkim/trusted.hosts

Socket          local:/var/spool/postfix/opendkim/opendkim.sock
PidFile         /run/opendkim/opendkim.pid
UMask           007
UserID          opendkim
DKIMCONF

# Create required files
touch /etc/opendkim/key.table /etc/opendkim/signing.table
echo -e "127.0.0.1\nlocalhost\n$MAIL_HOSTNAME" > /etc/opendkim/trusted.hosts

# Socket directory inside Postfix chroot
mkdir -p /var/spool/postfix/opendkim
chown opendkim:postfix /var/spool/postfix/opendkim
chmod 750 /var/spool/postfix/opendkim

# Add postfix to opendkim group
usermod -aG opendkim postfix

log "OpenDKIM configured"

# ══════════════════════════════════════════════════════════════
step "Configuring Rspamd"
# ══════════════════════════════════════════════════════════════

# Basic Rspamd override — reject high-score spam
mkdir -p /etc/rspamd/local.d
cat > /etc/rspamd/local.d/actions.conf <<'RSPCONF'
reject = 15;
add_header = 6;
greylist = 4;
RSPCONF

# Enable milter mode for Postfix
cat > /etc/rspamd/local.d/worker-proxy.inc <<'RSPCONF'
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
RSPCONF

log "Rspamd configured"

# ══════════════════════════════════════════════════════════════
step "Creating PostgreSQL read-only user"
# ══════════════════════════════════════════════════════════════

# If DB is local, create the mail lookup user
if [[ "$DB_HOST" == "localhost" || "$DB_HOST" == "127.0.0.1" ]]; then
    # Detect PostgreSQL port (panel uses 5433)
    PG_PORT=5433
    if ! pg_isready -h localhost -p 5433 -q 2>/dev/null; then
        PG_PORT=5432
    fi

    sudo -u postgres psql -p "$PG_PORT" -d "$DB_NAME" <<SQLEOF 2>/dev/null || true
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$DB_USER') THEN
        CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASS';
    END IF;
END \$\$;
GRANT CONNECT ON DATABASE $DB_NAME TO $DB_USER;
GRANT USAGE ON SCHEMA public TO $DB_USER;
GRANT SELECT ON mail_domains, mail_accounts, mail_aliases TO $DB_USER;
SQLEOF
    log "PostgreSQL user '$DB_USER' created with SELECT on mail tables"
else
    warn "Remote DB ($DB_HOST) — ensure user '$DB_USER' has SELECT on mail_domains, mail_accounts, mail_aliases"
fi

# ══════════════════════════════════════════════════════════════
step "Testing database connectivity"
# ══════════════════════════════════════════════════════════════

if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "SELECT COUNT(*) FROM mail_domains;" &>/dev/null; then
    log "Database connection OK"
else
    warn "Cannot connect to database. Verify credentials and that the replica is running."
    warn "Services will start but mail delivery will fail until DB is accessible."
fi

# ══════════════════════════════════════════════════════════════
step "Starting services"
# ══════════════════════════════════════════════════════════════

systemctl enable --now postfix dovecot opendkim rspamd
systemctl restart postfix dovecot opendkim rspamd

log "All services started"

# ══════════════════════════════════════════════════════════════
step "Verifying"
# ══════════════════════════════════════════════════════════════

echo ""
CHECKS=0
for svc in postfix dovecot opendkim rspamd; do
    if systemctl is-active --quiet "$svc"; then
        log "$svc: running"
    else
        warn "$svc: NOT running"
        CHECKS=$((CHECKS + 1))
    fi
done

echo ""
for port in 25 587 993; do
    if ss -tlnp | grep -q ":${port} "; then
        log "Port $port: listening"
    else
        warn "Port $port: NOT listening"
        CHECKS=$((CHECKS + 1))
    fi
done

echo ""
if [[ $CHECKS -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}Mail node installation complete!${NC}"
else
    echo -e "${YELLOW}${BOLD}Installation complete with $CHECKS warning(s). Check above.${NC}"
fi

echo ""
echo -e "${BOLD}Next steps:${NC}"
echo "  1. In MuseDock Panel, add this node to Cluster with services: [\"mail\"]"
echo "  2. Configure DNS for each mail domain:"
echo "     - MX record pointing to $MAIL_HOSTNAME"
echo "     - SPF TXT record: v=spf1 ip4:$(hostname -I | awk '{print $1}') ~all"
echo "     - DKIM and DMARC records (generated by panel when creating mail domains)"
echo "  3. Ensure firewall allows ports: 25, 587, 993"
echo "  4. Test: send an email to a mailbox created in the panel"
echo ""
echo -e "${BOLD}Firewall (if using UFW):${NC}"
echo "  ufw allow 25/tcp    # SMTP"
echo "  ufw allow 587/tcp   # Submission"
echo "  ufw allow 993/tcp   # IMAPS"
echo ""
