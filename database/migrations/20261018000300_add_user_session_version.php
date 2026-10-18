<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // Increment this value whenever credentials change to revoke older sessions.
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1
             AFTER password_hash"
        );
    }
};
