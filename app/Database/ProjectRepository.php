<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use TeamCodeHub\Core\QuotaExceededException;
use PDO;
use Throwable;

final class ProjectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listAccessibleByUserInTeam(int $userId, int $teamId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.name, p.slug, p.description, p.github_repository, p.status, p.created_by, p.created_at, p.updated_at, pm.access_level
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE p.team_id = :team_id AND p.status = 'active'
               AND pm.status = 'approved' AND tm.status = 'active'
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $statement->execute(['user_id' => $userId, 'team_id' => $teamId]);
        return array_map(self::normalize(...), $statement->fetchAll());
    }

    /**
     * Return archived project metadata for team owners/managers only.
     * Archived records remain visible here so a manager can restore them without losing history.
     *
     * @return list<array<string, mixed>>
     */
    public function listArchivedForManager(int $userId, int $teamId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.name, p.slug, p.description, p.github_repository, p.status, p.created_by, p.created_at, p.updated_at
             FROM projects p
             INNER JOIN teams t ON t.id = p.team_id AND t.status = 'active'
             INNER JOIN team_memberships tm ON tm.team_id = t.id AND tm.user_id = :user_id AND tm.status = 'active'
             WHERE p.team_id = :team_id AND p.status = 'archived'
               AND tm.role IN ('owner', 'manager')
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT 100"
        );
        $statement->execute(['user_id' => $userId, 'team_id' => $teamId]);
        return array_map(self::normalize(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function findAccessibleByUser(int $userId, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.name, p.slug, p.description, p.github_repository, p.status, p.created_by, p.created_at, p.updated_at, pm.access_level
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE p.id = :project_id AND p.status = 'active'
               AND pm.status = 'approved' AND tm.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $row = $statement->fetch();
        return is_array($row) ? self::normalize($row) : null;
    }

    public function createForTeamManager(int $userId, int $teamId, string $name, ?string $description): int
    {
        $role = $this->pdo->prepare(
            "SELECT tm.role, t.project_limit
             FROM team_memberships tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.team_id = :team_id AND tm.user_id = :user_id
               AND tm.status = 'active' AND t.status = 'active'
             LIMIT 1 FOR UPDATE"
        );

        $base = strtolower($name);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        if ($slug === '') {
            $slug = 'project';
        }
        $slug = substr($slug, 0, 95) . '-' . bin2hex(random_bytes(3));

        $this->pdo->beginTransaction();
        try {
            $role->execute(['team_id' => $teamId, 'user_id' => $userId]);
            $roleRow = $role->fetch(PDO::FETCH_ASSOC);
            if (!is_array($roleRow) || !in_array($roleRow['role'], ['owner', 'manager'], true)) {
                throw new DomainException('Active team manager role required.');
            }

            $count = $this->pdo->prepare("SELECT COUNT(*) FROM projects WHERE team_id = :team_id AND status = 'active'");
            $count->execute(['team_id' => $teamId]);
            if ((int) $count->fetchColumn() >= (int) $roleRow['project_limit']) {
                throw new QuotaExceededException('Team project quota reached.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO projects (team_id, name, slug, description, status, created_by)
                 VALUES (:team_id, :name, :slug, :description, 'active', :created_by)"
            );
            $insert->execute([
                'team_id' => $teamId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'created_by' => $userId,
            ]);
            $projectId = (int) $this->pdo->lastInsertId();

            $membership = $this->pdo->prepare(
                "INSERT INTO project_memberships (project_id, user_id, access_level, status, approved_by, approved_at)
                 VALUES (:project_id, :user_id, 'member', 'approved', :approved_by, CURRENT_TIMESTAMP)"
            );
            $membership->execute(['project_id' => $projectId, 'user_id' => $userId, 'approved_by' => $userId]);
            $this->pdo->commit();
            return $projectId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Archive a project without deleting its tasks, memberships, comments, or chat history.
     * The operation is reversible by a team owner/manager.
     */
    public function archiveForTeamManager(int $userId, int $projectId): void
    {
        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForLifecycle($userId, $projectId);
            if ($project['status'] !== 'active') {
                throw new DomainException('Only active projects can be archived.');
            }
            $update = $this->pdo->prepare("UPDATE projects SET status = 'archived' WHERE id = :project_id AND status = 'active'");
            $update->execute(['project_id' => $projectId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Active project not found.');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Restore an archived project only when the active project quota still allows it.
     * Locking the team row serializes restores against project creation.
     */
    public function restoreForTeamManager(int $userId, int $projectId): void
    {
        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForLifecycle($userId, $projectId);
            if ($project['status'] !== 'archived') {
                throw new DomainException('Only archived projects can be restored.');
            }

            $count = $this->pdo->prepare(
                "SELECT COUNT(*) FROM projects WHERE team_id = :team_id AND status = 'active'"
            );
            $count->execute(['team_id' => $project['team_id']]);
            if ((int) $count->fetchColumn() >= $project['project_limit']) {
                throw new QuotaExceededException('Team active project quota reached.');
            }

            $update = $this->pdo->prepare("UPDATE projects SET status = 'active' WHERE id = :project_id AND status = 'archived'");
            $update->execute(['project_id' => $projectId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Archived project not found.');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{team_id:int,project_limit:int,status:string} */
    private function lockProjectForLifecycle(int $userId, int $projectId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.status, t.project_limit, tm.role
             FROM projects p
             INNER JOIN teams t ON t.id = p.team_id AND t.status = 'active'
             INNER JOIN team_memberships tm ON tm.team_id = t.id AND tm.user_id = :user_id AND tm.status = 'active'
             WHERE p.id = :project_id AND tm.role IN ('owner', 'manager')
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Active team owner/manager role required.');
        }
        return [
            'team_id' => (int) $row['team_id'],
            'project_limit' => (int) $row['project_limit'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Update project metadata only for a team owner/manager with approved project access.
     * Keep the role and membership checks inside the transaction as a data-boundary guard.
     */
    public function updateMetadataForTeamManager(int $userId, int $projectId, string $name, ?string $description): void
    {
        $this->pdo->beginTransaction();
        try {
            $access = $this->pdo->prepare(
                "SELECT p.id, p.team_id, tm.role
                 FROM projects p
                 INNER JOIN teams t ON t.id = p.team_id AND t.status = 'active'
                 INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = :team_user_id AND tm.status = 'active'
                 INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :project_user_id AND pm.status = 'approved'
                 WHERE p.id = :project_id AND p.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $access->execute(['team_user_id' => $userId, 'project_user_id' => $userId, 'project_id' => $projectId]);
            $row = $access->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !in_array($row['role'], ['owner', 'manager'], true)) {
                throw new DomainException('Team manager role and approved project access required.');
            }

            $update = $this->pdo->prepare(
                "UPDATE projects SET name = :name, description = :description
                 WHERE id = :project_id AND status = 'active'"
            );
            $update->execute([
                'name' => $name,
                'description' => $description,
                'project_id' => $projectId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }


    /**
     * Link or unlink a public GitHub repository only for an active team owner/manager.
     * The role and approved project membership are rechecked while the project row is locked.
     */
    public function updateGitHubRepositoryForTeamManager(int $userId, int $projectId, ?string $repository): void
    {
        if ($repository !== null
            && preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?/[A-Za-z0-9_.-]{1,100}$~', $repository) !== 1) {
            throw new DomainException('GitHub repository reference is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $access = $this->pdo->prepare(
                "SELECT p.id, tm.role
                 FROM projects p
                 INNER JOIN teams t ON t.id = p.team_id AND t.status = 'active'
                 INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = :team_user_id AND tm.status = 'active'
                 INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :project_user_id AND pm.status = 'approved'
                 WHERE p.id = :project_id AND p.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $access->execute([
                'team_user_id' => $userId,
                'project_user_id' => $userId,
                'project_id' => $projectId,
            ]);
            $row = $access->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !in_array($row['role'], ['owner', 'manager'], true)) {
                throw new DomainException('Team manager role and approved project access required.');
            }

            $update = $this->pdo->prepare(
                'UPDATE projects SET github_repository = :repository WHERE id = :project_id AND status = \'active\''
            );
            $update->execute(['repository' => $repository, 'project_id' => $projectId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'team_id', 'created_by'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
