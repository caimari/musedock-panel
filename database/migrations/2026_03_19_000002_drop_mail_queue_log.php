<?php
/**
 * Drop mail_queue_log table — redundant.
 * Postfix logs deliveries in /var/log/mail.log (more reliable).
 * Cluster actions already tracked in cluster_queue.
 */
return function (PDO $pdo): void {
    $pdo->exec("DROP TABLE IF EXISTS mail_queue_log");
};
