<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const LOCK_NAME = 'teamcode_hub_schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> Applied migration versions, in order. */
    public function migrate(): array
    {
        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            throw new RuntimeException('Migration directory is missing or unreadable.');
        }

        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lock->execute(['lock_name' => self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock.');
        }

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(190) NOT NULL PRIMARY KEY,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $appliedStatement = $this->pdo->query('SELECT version FROM schema_migrations');
            $applied = array_fill_keys($appliedStatement->fetchAll(PDO::FETCH_COLUMN), true);
            $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_STRING);
            $completed = [];

            foreach ($files as $file) {
                $version = basename($file, '.php');
                if (preg_match('/^[0-9]{14}_[a-z0-9_]+$/', $version) !== 1) {
                    throw new RuntimeException('Invalid migration filename: ' . basename($file));
                }
                if (isset($applied[$version])) {
                    continue;
                }

                $migration = require $file;
                if (!$migration instanceof MigrationInterface) {
                    throw new RuntimeException('Migration must return a MigrationInterface instance: ' . basename($file));
                }

                // MySQL DDL may implicitly commit. Record the version only after up() succeeds.
                $migration->up($this->pdo);
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
                $insert->execute(['version' => $version]);
                $completed[] = $version;
            }

            return $completed;
        } finally {
            try {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => self::LOCK_NAME]);
            } catch (Throwable) {
                // Preserve the original failure; closing the connection also releases the lock.
            }
        }
    }
}
