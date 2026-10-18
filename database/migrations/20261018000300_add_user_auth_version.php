<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // Make this migration recoverable if MySQL committed the ALTER before the runner recorded it.
        $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_version'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($column)) {
            $pdo->exec(
                'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash'
            );
        }
    }
};
