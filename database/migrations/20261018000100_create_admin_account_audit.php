<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

/*
 * Keep an append-only audit trail for high-impact site-admin account status changes.
 * The log stores the reason and actor name at the time of the action for later review.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_account_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_id BIGINT UNSIGNED NOT NULL,
            actor_display_name VARCHAR(80) NOT NULL,
            target_user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            previous_status VARCHAR(20) NOT NULL,
            new_status VARCHAR(20) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_account_audit_target_created (target_user_id, created_at, id),
            KEY idx_admin_account_audit_actor_created (actor_id, created_at, id),
            CONSTRAINT fk_admin_account_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_admin_account_audit_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
