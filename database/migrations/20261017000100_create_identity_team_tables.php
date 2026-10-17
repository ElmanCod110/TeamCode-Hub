<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            email_verified_at DATETIME NULL,
            last_login_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email),
            KEY idx_users_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS teams (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            member_limit INT UNSIGNED NOT NULL DEFAULT 10,
            project_limit INT UNSIGNED NOT NULL DEFAULT 10,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_teams_slug (slug),
            KEY idx_teams_created_by (created_by),
            CONSTRAINT fk_teams_creator FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS team_memberships (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'member',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            invited_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_team_membership (team_id, user_id),
            KEY idx_team_memberships_user_status (user_id, status),
            CONSTRAINT fk_team_memberships_team FOREIGN KEY (team_id) REFERENCES teams (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_team_memberships_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_team_memberships_inviter FOREIGN KEY (invited_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_projects_team_slug (team_id, slug),
            KEY idx_projects_team_status (team_id, status),
            CONSTRAINT fk_projects_team FOREIGN KEY (team_id) REFERENCES teams (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_projects_creator FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS project_memberships (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            access_level VARCHAR(20) NOT NULL DEFAULT 'viewer',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_membership (project_id, user_id),
            KEY idx_project_memberships_user_status (user_id, status),
            CONSTRAINT fk_project_memberships_project FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_project_memberships_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_project_memberships_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
