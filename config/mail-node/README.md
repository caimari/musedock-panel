# Mail Node Configuration Templates

These are reference configurations for setting up a MuseDock Mail Node.
Copy them to the appropriate locations on the mail node server.

## Architecture

```
Master Panel (PostgreSQL) ──replication──> Mail Node (PostgreSQL replica)
                                              │
                                     ┌────────┼────────┐
                                     │        │        │
                                  Postfix  Dovecot  OpenDKIM
                                     │        │
                                     └──SQL lookup──> PostgreSQL (local replica)
```

## Setup Steps

1. Install packages: `apt install postfix dovecot-imapd dovecot-pop3d dovecot-pgsql opendkim rspamd`
2. Create vmail user: `useradd -r -u 5000 -g 5000 -d /var/mail/vhosts -s /usr/sbin/nologin vmail`
3. Set up PostgreSQL replication from master (use MuseDock Panel's Replication feature)
4. Copy these config files to their locations
5. Update DB connection credentials in each file
6. Restart services

## Files

- `postfix-pgsql-virtual-domains.cf` → `/etc/postfix/pgsql-virtual-domains.cf`
- `postfix-pgsql-virtual-mailboxes.cf` → `/etc/postfix/pgsql-virtual-mailboxes.cf`
- `postfix-pgsql-virtual-aliases.cf` → `/etc/postfix/pgsql-virtual-aliases.cf`
- `dovecot-sql.conf` → `/etc/dovecot/dovecot-sql.conf`
- `postfix-main.cf.example` → Reference for `/etc/postfix/main.cf`
