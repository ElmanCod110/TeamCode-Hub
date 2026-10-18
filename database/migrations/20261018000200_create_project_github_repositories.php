<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

/*
 * Store project-to-repository links separately from public GitHub snapshots.
 * Cache rows are shared by repository name so several projects reuse one daily fetch.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // DDL can commit implicitly in MariaDB, so guard each ALTER for safe recovery after a partial migration.
        $columnCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $columnCheck->execute(['table_name' => 'projects', 'column_name' => 'github_repository']);
        if ((int) $columnCheck->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE projects ADD COLUMN github_repository VARCHAR(200) NULL AFTER description');
        }

        $indexCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
        );
        $indexCheck->execute(['table_name' => 'projects', 'index_name' => 'idx_projects_github_repository']);
        if ((int) $indexCheck->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE projects ADD KEY idx_projects_github_repository (github_repository)');
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS github_repository_cache (
            repository_full_name VARCHAR(200) NOT NULL,
            attempted_date DATE NULL,
            fetched_at DATETIME NULL,
            payload_json LONGTEXT NULL,
            last_error VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (repository_full_name),
            KEY idx_github_repository_cache_attempted (attempted_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS github_repository_refresh_budget (
            id TINYINT UNSIGNED NOT NULL,
            budget_date DATE NOT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT IGNORE INTO github_repository_refresh_budget (id, budget_date, attempts)
                    VALUES (1, '1970-01-01', 0)");
    }
};
