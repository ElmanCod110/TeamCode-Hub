<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use Throwable;

final class ProjectFileRepository
{
    private const MAX_FILES_PER_PROJECT = 200;
    private const MAX_FILE_BYTES = 262144;
    private const MAX_PROJECT_BYTES = 10485760;
    private const HISTORY_LIMIT = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * List project files for approved members and viewers.
     *
     * @return array{can_edit:bool,limits:array{max_files:int,max_file_bytes:int,max_project_bytes:int},files:list<array<string,mixed>>}
     */
    public function listForUser(int $userId, int $projectId): array
    {
        $accessLevel = $this->accessLevelForUser($userId, $projectId);
        if ($accessLevel === null) {
            throw new DomainException('Approved project access required.');
        }

        $statement = $this->pdo->prepare(
            'SELECT f.id, f.path, f.version_number, OCTET_LENGTH(f.content) AS size_bytes,
                    f.created_at, f.updated_at, f.created_by, f.updated_by,
                    updater.display_name AS updated_by_name
             FROM project_files f
             INNER JOIN users updater ON updater.id = f.updated_by
             WHERE f.project_id = :project_id
             ORDER BY f.path ASC, f.id ASC
             LIMIT ' . self::MAX_FILES_PER_PROJECT
        );
        $statement->execute(['project_id' => $projectId]);
        $files = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'path' => (string) $row['path'],
            'version' => (int) $row['version_number'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'created_by' => (int) $row['created_by'],
            'updated_by' => (int) $row['updated_by'],
            'updated_by_name' => (string) $row['updated_by_name'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));

        return [
            'can_edit' => $accessLevel === 'member',
            'limits' => [
                'max_files' => self::MAX_FILES_PER_PROJECT,
                'max_file_bytes' => self::MAX_FILE_BYTES,
                'max_project_bytes' => self::MAX_PROJECT_BYTES,
            ],
            'files' => $files,
        ];
    }

    /** @return array<string,mixed> */
    public function findForUser(int $userId, int $projectId, int $fileId): array
    {
        if ($this->accessLevelForUser($userId, $projectId) === null) {
            throw new DomainException('Approved project access required.');
        }

        $statement = $this->pdo->prepare(
            'SELECT f.id, f.project_id, f.path, f.content, f.version_number,
                    OCTET_LENGTH(f.content) AS size_bytes, f.created_by, f.updated_by,
                    f.created_at, f.updated_at, creator.display_name AS created_by_name,
                    updater.display_name AS updated_by_name
             FROM project_files f
             INNER JOIN users creator ON creator.id = f.created_by
             INNER JOIN users updater ON updater.id = f.updated_by
             WHERE f.id = :file_id AND f.project_id = :project_id
             LIMIT 1'
        );
        $statement->execute(['file_id' => $fileId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Project file not found.');
        }

        return self::normalizeFile($row);
    }

    /** Create a source file and its first immutable version snapshot. */
    public function create(int $userId, int $projectId, string $path, string $content): int
    {
        $path = self::validatePath($path);
        self::validateContent($content);

        $this->pdo->beginTransaction();
        try {
            $this->lockEditableProject($userId, $projectId);
            $quota = $this->quotaState($projectId);
            if ($quota['file_count'] >= self::MAX_FILES_PER_PROJECT) {
                throw new DomainException('Project file-count quota reached.');
            }
            if ($quota['total_bytes'] + strlen($content) > self::MAX_PROJECT_BYTES) {
                throw new DomainException('Project storage quota reached.');
            }

            $exists = $this->pdo->prepare(
                'SELECT 1 FROM project_files WHERE project_id = :project_id AND path = :path LIMIT 1'
            );
            $exists->execute(['project_id' => $projectId, 'path' => $path]);
            if ($exists->fetchColumn() !== false) {
                throw new DomainException('A file already exists at this path.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO project_files (project_id, path, content, version_number, created_by, updated_by)
                 VALUES (:project_id, :path, :content, 1, :created_by, :updated_by)'
            );
            $insert->execute([
                'project_id' => $projectId,
                'path' => $path,
                'content' => $content,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();
            $this->insertVersion($fileId, $projectId, 1, 'created', $content, $userId);
            $this->pdo->commit();
            return $fileId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Save a new version only when the client's expected version still matches. */
    public function update(int $userId, int $projectId, int $fileId, int $expectedVersion, string $content): array
    {
        self::validateContent($content);
        if ($expectedVersion < 1) {
            throw new DomainException('Expected file version must be a positive integer.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->lockEditableProject($userId, $projectId);
            $file = $this->lockFile($projectId, $fileId);
            if ((int) $file['version_number'] !== $expectedVersion) {
                throw new DomainException('File version conflict. Reload the current version before saving.');
            }

            if ((string) $file['content'] === $content) {
                $this->pdo->commit();
                return ['changed' => false, 'file' => self::normalizeFile($file)];
            }

            $quota = $this->quotaState($projectId);
            $newTotal = $quota['total_bytes'] - strlen((string) $file['content']) + strlen($content);
            if ($newTotal > self::MAX_PROJECT_BYTES) {
                throw new DomainException('Project storage quota reached.');
            }

            $nextVersion = $expectedVersion + 1;
            $update = $this->pdo->prepare(
                'UPDATE project_files
                 SET content = :content, version_number = :next_version, updated_by = :updated_by
                 WHERE id = :file_id AND project_id = :project_id AND version_number = :expected_version'
            );
            $update->execute([
                'content' => $content,
                'next_version' => $nextVersion,
                'updated_by' => $userId,
                'file_id' => $fileId,
                'project_id' => $projectId,
                'expected_version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('File version conflict. Reload the current version before saving.');
            }
            $this->insertVersion($fileId, $projectId, $nextVersion, 'updated', $content, $userId);
            $this->pruneHistory($fileId, $nextVersion);
            $this->pdo->commit();

            $file['content'] = $content;
            $file['version_number'] = $nextVersion;
            $file['updated_by'] = $userId;
            $file['updated_at'] = gmdate('Y-m-d H:i:s');
            return ['changed' => true, 'file' => self::normalizeFile($file)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Return recent revision metadata without shipping old source text in a list response. */
    public function historyForUser(int $userId, int $projectId, int $fileId): array
    {
        if ($this->accessLevelForUser($userId, $projectId) === null) {
            throw new DomainException('Approved project access required.');
        }
        $file = $this->pdo->prepare(
            'SELECT 1 FROM project_files WHERE id = :file_id AND project_id = :project_id LIMIT 1'
        );
        $file->execute(['file_id' => $fileId, 'project_id' => $projectId]);
        if ($file->fetchColumn() === false) {
            throw new DomainException('Project file not found.');
        }

        $statement = $this->pdo->prepare(
            'SELECT v.version_number, v.action, OCTET_LENGTH(v.content) AS size_bytes,
                    v.created_at, v.editor_id, u.display_name AS editor_name
             FROM project_file_versions v
             INNER JOIN users u ON u.id = v.editor_id
             WHERE v.project_id = :project_id AND v.file_id = :file_id
             ORDER BY v.version_number DESC
             LIMIT ' . self::HISTORY_LIMIT
        );
        $statement->execute(['project_id' => $projectId, 'file_id' => $fileId]);
        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version_number'],
            'action' => (string) $row['action'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'editor_id' => (int) $row['editor_id'],
            'editor_name' => (string) $row['editor_name'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Restore an earlier snapshot as a new version; history is never rewritten in place. */
    public function restore(int $userId, int $projectId, int $fileId, int $versionToRestore, int $expectedVersion): array
    {
        if ($versionToRestore < 1 || $expectedVersion < 1) {
            throw new DomainException('File version must be a positive integer.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->lockEditableProject($userId, $projectId);
            $file = $this->lockFile($projectId, $fileId);
            if ((int) $file['version_number'] !== $expectedVersion) {
                throw new DomainException('File version conflict. Reload the current version before restoring.');
            }

            $snapshot = $this->pdo->prepare(
                'SELECT content FROM project_file_versions
                 WHERE file_id = :file_id AND project_id = :project_id AND version_number = :version
                 LIMIT 1'
            );
            $snapshot->execute([
                'file_id' => $fileId,
                'project_id' => $projectId,
                'version' => $versionToRestore,
            ]);
            $content = $snapshot->fetchColumn();
            if (!is_string($content)) {
                throw new DomainException('Requested file revision is no longer available.');
            }

            $quota = $this->quotaState($projectId);
            if ($quota['total_bytes'] - strlen((string) $file['content']) + strlen($content) > self::MAX_PROJECT_BYTES) {
                throw new DomainException('Project storage quota reached.');
            }
            if ((string) $file['content'] === $content) {
                $this->pdo->commit();
                return ['changed' => false, 'file' => self::normalizeFile($file)];
            }

            $nextVersion = $expectedVersion + 1;
            $update = $this->pdo->prepare(
                'UPDATE project_files SET content = :content, version_number = :next_version, updated_by = :updated_by
                 WHERE id = :file_id AND project_id = :project_id AND version_number = :expected_version'
            );
            $update->execute([
                'content' => $content,
                'next_version' => $nextVersion,
                'updated_by' => $userId,
                'file_id' => $fileId,
                'project_id' => $projectId,
                'expected_version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('File version conflict. Reload the current version before restoring.');
            }
            $this->insertVersion($fileId, $projectId, $nextVersion, 'restored', $content, $userId);
            $this->pruneHistory($fileId, $nextVersion);
            $this->pdo->commit();

            $file['content'] = $content;
            $file['version_number'] = $nextVersion;
            $file['updated_by'] = $userId;
            $file['updated_at'] = gmdate('Y-m-d H:i:s');
            return ['changed' => true, 'file' => self::normalizeFile($file)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function validatePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 240 || preg_match('//u', $path) !== 1
            || preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path) !== 1) {
            throw new DomainException('File path must be a relative UTF-8 path of at most 240 bytes using letters, digits, dots, hyphens, underscores, and forward slashes.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || $segment === '.git'
                || preg_match('/^\.env(?:\..*)?$/i', $segment) === 1 && strtolower($segment) !== '.env.example') {
                throw new DomainException('This file path is reserved and cannot be stored in the project workspace.');
            }
        }

        return $path;
    }

    private static function validateContent(string $content): void
    {
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new DomainException('A source file cannot exceed 256 KiB.');
        }
        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new DomainException('Source file content must be valid UTF-8 text without NUL bytes.');
        }
    }

    private function accessLevelForUser(int $userId, int $projectId): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT pm.access_level
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id AND pm.status = 'approved'
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
             WHERE p.id = :project_id AND p.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $access = $statement->fetchColumn();
        return is_string($access) ? $access : null;
    }

    private function lockEditableProject(int $userId, int $projectId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE p.id = :project_id AND p.status = 'active'
               AND pm.status = 'approved' AND pm.access_level = 'member' AND tm.status = 'active'
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Approved member-level project access required.');
        }
    }

    /** @return array{file_count:int,total_bytes:int} */
    private function quotaState(int $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS file_count, COALESCE(SUM(OCTET_LENGTH(content)), 0) AS total_bytes
             FROM project_files WHERE project_id = :project_id'
        );
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['file_count' => (int) ($row['file_count'] ?? 0), 'total_bytes' => (int) ($row['total_bytes'] ?? 0)];
    }

    private function lockFile(int $projectId, int $fileId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, project_id, path, content, version_number, created_by, updated_by, created_at, updated_at
             FROM project_files WHERE id = :file_id AND project_id = :project_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['file_id' => $fileId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Project file not found.');
        }
        return $row;
    }

    private function insertVersion(int $fileId, int $projectId, int $version, string $action, string $content, int $editorId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO project_file_versions (file_id, project_id, version_number, action, content, editor_id)
             VALUES (:file_id, :project_id, :version_number, :action, :content, :editor_id)'
        );
        $statement->execute([
            'file_id' => $fileId,
            'project_id' => $projectId,
            'version_number' => $version,
            'action' => $action,
            'content' => $content,
            'editor_id' => $editorId,
        ]);
    }

    private function pruneHistory(int $fileId, int $currentVersion): void
    {
        $minimumVersion = max(1, $currentVersion - self::HISTORY_LIMIT + 1);
        $statement = $this->pdo->prepare(
            'DELETE FROM project_file_versions WHERE file_id = :file_id AND version_number < :minimum_version'
        );
        $statement->execute(['file_id' => $fileId, 'minimum_version' => $minimumVersion]);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private static function normalizeFile(array $row): array
    {
        foreach (['id', 'project_id', 'version_number', 'created_by', 'updated_by'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (isset($row['size_bytes'])) {
            $row['size_bytes'] = (int) $row['size_bytes'];
        }
        $row['version'] = (int) ($row['version_number'] ?? 1);
        unset($row['version_number']);
        return $row;
    }
}
