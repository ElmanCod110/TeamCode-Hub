<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

/*
 * Store a single public GitHub activity snapshot and the daily fetch attempt.
 * The singleton row serializes attempts so concurrent page loads do not fan out
 * into multiple outbound requests to GitHub on the same UTC day.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS github_activity_cache (
            id TINYINT UNSIGNED NOT NULL,
            username VARCHAR(39) NOT NULL DEFAULT '',
            attempted_date DATE NULL,
            fetched_at DATETIME NULL,
            payload_json LONGTEXT NULL,
            last_error VARCHAR(160) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT INTO github_activity_cache (id, username)
                    VALUES (1, '')
                    ON DUPLICATE KEY UPDATE id = VALUES(id)");
    }
};
