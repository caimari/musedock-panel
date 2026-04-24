# MuseDock Cluster Failover Manual

This document intentionally describes a manual procedure. MuseDock Panel must not auto-promote PostgreSQL nodes without operator confirmation because split-brain can corrupt customer data.

## PostgreSQL Slave Promotion

Use this only after confirming the current master is really down and not just partitioned from your network.

1. Confirm the master is unreachable from at least two independent paths.
2. On the selected slave, promote PostgreSQL:

   ```bash
   sudo -u postgres pg_promote
   ```

3. On the promoted node, change the panel role/replication mode to master in the panel configuration/settings.
4. Restart the panel service and workers on the promoted node.
5. Update DNS/firewall/routes for the panel if needed.
6. When the old master comes back:
   - Do not start the panel.
   - Stop PostgreSQL.
   - Rebuild its data directory from the new master.
   - Configure it as a replica of the new master.
   - Start PostgreSQL and wait until replication catches up.
   - Only then start the panel as a slave.

## Mail Nodes

Mail nodes depend on their local PostgreSQL replica. Postfix and Dovecot query local SQL tables to resolve domains, mailboxes and aliases.

The panel healthcheck must treat a mail node as degraded/down if:

- local PostgreSQL is not reachable,
- user `musedock_mail` cannot read `mail_domains`,
- PostgreSQL replay lag exceeds the configured threshold,
- `/var/mail/vhosts` is missing or has wrong ownership.

When the healthcheck pauses `mail_*` queue actions, do not manually replay them until the DB health is green again.
