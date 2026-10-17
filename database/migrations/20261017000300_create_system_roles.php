<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_system_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) NOT NULL,
            assigned_by BIGINT UNSIGNED NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_system_role (user_id, role),
            KEY idx_user_system_roles_role (role),
            CONSTRAINT fk_user_system_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_user_system_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
