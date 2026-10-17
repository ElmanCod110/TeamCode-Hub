<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_invitations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(254) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'member',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            invited_by BIGINT UNSIGNED NOT NULL,
            accepted_by BIGINT UNSIGNED NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_team_invitation_token (token_hash),
            KEY idx_team_invitations_team_status_expiry (team_id, status, expires_at),
            KEY idx_team_invitations_email (email),
            CONSTRAINT fk_team_invitations_team FOREIGN KEY (team_id) REFERENCES teams (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_team_invitations_inviter FOREIGN KEY (invited_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_team_invitations_acceptor FOREIGN KEY (accepted_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
