<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        // A monotonically increasing version invalidates sessions created with older credentials.
        $pdo->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER email_verified_at");
    }
};
