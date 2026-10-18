<?php

declare(strict_types=1);

use TeamCodeHub\Database\MigrationInterface;

/*
 * Store project source files outside the public web root, with a bounded revision history.
 * File contents are data only; this feature never evaluates or executes them.
 */
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            path VARCHAR(240) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            version_number INT UNSIGNED NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_files_project_path (project_id, path),
            KEY idx_project_files_project_updated (project_id, updated_at, id),
            CONSTRAINT fk_project_files_project FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_project_files_creator FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_project_files_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS project_file_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            action VARCHAR(16) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            editor_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_file_versions_file_version (file_id, version_number),
            KEY idx_project_file_versions_project_file_created (project_id, file_id, created_at, id),
            CONSTRAINT fk_project_file_versions_file FOREIGN KEY (file_id) REFERENCES project_files (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_project_file_versions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_project_file_versions_editor FOREIGN KEY (editor_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
