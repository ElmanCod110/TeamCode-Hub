<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body VARCHAR(2000) NOT NULL,
            edited_at DATETIME NULL,
            deleted_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_project_messages_project_expiry_id (project_id, expires_at, id),
            KEY idx_project_messages_author_created (user_id, created_at),
            CONSTRAINT fk_project_messages_project FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_project_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            reported_user_id BIGINT UNSIGNED NOT NULL,
            reporter_user_id BIGINT UNSIGNED NOT NULL,
            report_day DATE NOT NULL,
            reason VARCHAR(500) NOT NULL,
            context_snapshot JSON NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewer_scope VARCHAR(20) NOT NULL DEFAULT 'team_manager',
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            valid_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_chat_report_person_reporter_day (reported_user_id, reporter_user_id, report_day),
            KEY idx_chat_reports_project_status (project_id, status, created_at),
            KEY idx_chat_reports_target_valid (reported_user_id, valid_at, status),
            KEY idx_chat_reports_review_queue (reviewer_scope, status, created_at),
            CONSTRAINT fk_chat_reports_project FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_chat_reports_message FOREIGN KEY (message_id) REFERENCES project_messages (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_chat_reports_target FOREIGN KEY (reported_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_chat_reports_reporter FOREIGN KEY (reporter_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_chat_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
