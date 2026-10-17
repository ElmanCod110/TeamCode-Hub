<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_rate_limits (
            bucket_key CHAR(64) NOT NULL,
            window_started_at DATETIME NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket_key),
            KEY idx_auth_rate_limits_window (window_started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
