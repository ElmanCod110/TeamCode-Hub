<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use Throwable;

final class ProjectMembershipRepository
{
    private readonly NotificationRepository $notifications;

    public function __construct(private readonly PDO $pdo)
    {
        $this->notifications = new NotificationRepository($pdo);
    }

    /** @return list<array<string, mixed>> */
    public function listForManager(int $actorId, int $projectId): array
    {
        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForManager($actorId, $projectId);
            $statement = $this->pdo->prepare(
                'SELECT pm.user_id, u.email, u.display_name, pm.access_level, pm.status, pm.approved_by, pm.approved_at, pm.created_at
                 FROM project_memberships pm
                 INNER JOIN users u ON u.id = pm.user_id
                 WHERE pm.project_id = :project_id
                 ORDER BY pm.status ASC, u.display_name ASC, pm.user_id ASC'
            );
            $statement->execute(['project_id' => $projectId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $this->pdo->commit();
            return array_map(static function (array $row) use ($project): array {
                $row['user_id'] = (int) $row['user_id'];
                $row['approved_by'] = $row['approved_by'] === null ? null : (int) $row['approved_by'];
                $row['is_project_creator'] = (int) $row['user_id'] === $project['created_by'];
                return $row;
            }, $rows);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Search active members of the project's team who can be assigned to the project.
     *
     * @return list<array{user_id:int,display_name:string,email:string,team_role:string,existing_status:?string}>
     */
    public function searchAssignableTeamMembers(int $actorId, int $projectId, string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 80 || preg_match('//u', $query) !== 1) {
            throw new DomainException('Search query must contain 1 to 80 valid UTF-8 bytes.');
        }

        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForManager($actorId, $projectId);
            $statement = $this->pdo->prepare(
                "SELECT u.id AS user_id, u.display_name, u.email, tm.role AS team_role,
                        pm.status AS existing_status
                 FROM team_memberships tm
                 INNER JOIN users u ON u.id = tm.user_id AND u.status = 'active'
                 LEFT JOIN project_memberships pm
                   ON pm.project_id = :project_id AND pm.user_id = tm.user_id
                 WHERE tm.team_id = :team_id AND tm.status = 'active'
                   AND (pm.status IS NULL OR pm.status <> 'approved')
                   AND (u.display_name LIKE :name_query OR u.email LIKE :email_query
                        OR CAST(u.id AS CHAR) = :exact_id)
                 ORDER BY
                   CASE WHEN CAST(u.id AS CHAR) = :sort_id THEN 0 ELSE 1 END,
                   u.display_name ASC, u.id ASC
                 LIMIT 12"
            );
            $term = '%' . $query . '%';
            $statement->execute([
                'project_id' => $projectId,
                'team_id' => $project['team_id'],
                'name_query' => $term,
                'email_query' => $term,
                'exact_id' => $query,
                'sort_id' => $query,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $this->pdo->commit();
            return array_map(static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'display_name' => (string) $row['display_name'],
                'email' => (string) $row['email'],
                'team_role' => (string) $row['team_role'],
                'existing_status' => $row['existing_status'] === null ? null : (string) $row['existing_status'],
            ], $rows);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Search approved member-level project users who are eligible for task assignment.
     *
     * @return list<array{user_id:int,display_name:string,email:string,access_level:string}>
     */
    public function searchTaskAssignees(int $actorId, int $projectId, string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 80 || preg_match('//u', $query) !== 1) {
            throw new DomainException('Search query must contain 1 to 80 valid UTF-8 bytes.');
        }

        $this->pdo->beginTransaction();
        try {
            $access = $this->pdo->prepare(
                "SELECT p.team_id
                 FROM projects p
                 INNER JOIN project_memberships actor_pm
                   ON actor_pm.project_id = p.id AND actor_pm.user_id = :actor_id AND actor_pm.status = 'approved'
                 INNER JOIN team_memberships actor_tm
                   ON actor_tm.team_id = p.team_id AND actor_tm.user_id = actor_pm.user_id AND actor_tm.status = 'active'
                 WHERE p.id = :project_id AND p.status = 'active'
                 LIMIT 1"
            );
            $access->execute(['actor_id' => $actorId, 'project_id' => $projectId]);
            $teamId = $access->fetchColumn();
            if ($teamId === false) {
                throw new DomainException('Approved project membership required.');
            }

            $statement = $this->pdo->prepare(
                "SELECT u.id AS user_id, u.display_name, u.email, pm.access_level
                 FROM project_memberships pm
                 INNER JOIN team_memberships tm
                   ON tm.team_id = :team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
                 INNER JOIN users u ON u.id = pm.user_id AND u.status = 'active'
                 WHERE pm.project_id = :project_id AND pm.status = 'approved' AND pm.access_level = 'member'
                   AND (u.display_name LIKE :name_query OR u.email LIKE :email_query
                        OR CAST(u.id AS CHAR) = :exact_id)
                 ORDER BY CASE WHEN CAST(u.id AS CHAR) = :sort_id THEN 0 ELSE 1 END,
                          u.display_name ASC, u.id ASC
                 LIMIT 12"
            );
            $term = '%' . $query . '%';
            $statement->execute([
                'team_id' => (int) $teamId,
                'project_id' => $projectId,
                'name_query' => $term,
                'email_query' => $term,
                'exact_id' => $query,
                'sort_id' => $query,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $this->pdo->commit();
            return array_map(static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'display_name' => (string) $row['display_name'],
                'email' => (string) $row['email'],
                'access_level' => (string) $row['access_level'],
            ], $rows);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function assignPending(int $actorId, int $projectId, int $targetUserId, string $accessLevel): int
    {
        if (!in_array($accessLevel, ['viewer', 'member'], true)) {
            throw new DomainException('Invalid project access level.');
        }

        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForManager($actorId, $projectId);
            $teamMember = $this->pdo->prepare(
                "SELECT id FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id AND status = 'active' LIMIT 1 FOR UPDATE"
            );
            $teamMember->execute(['team_id' => $project['team_id'], 'user_id' => $targetUserId]);
            if ($teamMember->fetchColumn() === false) {
                throw new DomainException('Target user is not an active member of this team.');
            }

            $existing = $this->pdo->prepare(
                'SELECT id, status FROM project_memberships WHERE project_id = :project_id AND user_id = :user_id LIMIT 1 FOR UPDATE'
            );
            $existing->execute(['project_id' => $projectId, 'user_id' => $targetUserId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['status'] === 'approved') {
                throw new DomainException('Project membership is already approved.');
            }

            if (is_array($row)) {
                $update = $this->pdo->prepare(
                    "UPDATE project_memberships SET access_level = :access_level, status = 'pending', approved_by = NULL, approved_at = NULL
                     WHERE id = :id"
                );
                $update->execute(['access_level' => $accessLevel, 'id' => $row['id']]);
                $membershipId = (int) $row['id'];
            } else {
                $insert = $this->pdo->prepare(
                    "INSERT INTO project_memberships (project_id, user_id, access_level, status)
                     VALUES (:project_id, :user_id, :access_level, 'pending')"
                );
                $insert->execute(['project_id' => $projectId, 'user_id' => $targetUserId, 'access_level' => $accessLevel]);
                $membershipId = (int) $this->pdo->lastInsertId();
            }

            if ($targetUserId !== $actorId) {
                $this->notifications->create(
                    $targetUserId,
                    'project_access_pending',
                    'Project access awaiting approval',
                    'A team manager assigned you to a project. Access is pending approval.',
                    ['project_id' => $projectId, 'access_level' => $accessLevel],
                );
            }
            $this->pdo->commit();
            return $membershipId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function approve(int $actorId, int $projectId, int $targetUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForManager($actorId, $projectId);
            $teamMember = $this->pdo->prepare(
                "SELECT id FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id AND status = 'active' LIMIT 1 FOR UPDATE"
            );
            $teamMember->execute(['team_id' => $project['team_id'], 'user_id' => $targetUserId]);
            if ($teamMember->fetchColumn() === false) {
                throw new DomainException('Target user is not an active team member.');
            }

            $update = $this->pdo->prepare(
                "UPDATE project_memberships SET status = 'approved', approved_by = :actor_id, approved_at = CURRENT_TIMESTAMP
                 WHERE project_id = :project_id AND user_id = :user_id AND status = 'pending'"
            );
            $update->execute(['actor_id' => $actorId, 'project_id' => $projectId, 'user_id' => $targetUserId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Pending project membership not found.');
            }
            $level = $this->pdo->prepare('SELECT access_level FROM project_memberships WHERE project_id = :project_id AND user_id = :user_id');
            $level->execute(['project_id' => $projectId, 'user_id' => $targetUserId]);
            $this->notifications->create(
                $targetUserId,
                'project_access_approved',
                'Project access approved',
                'Your project access has been approved.',
                ['project_id' => $projectId, 'access_level' => (string) $level->fetchColumn()],
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Change an approved member's read/write access without changing membership status.
     * The project creator must retain member-level access to avoid locking out the owner.
     */
    public function changeAccessLevel(int $actorId, int $projectId, int $targetUserId, string $accessLevel): bool
    {
        if (!in_array($accessLevel, ['viewer', 'member'], true)) {
            throw new DomainException('Invalid project access level.');
        }

        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForManager($actorId, $projectId);
            if ($targetUserId === $project['created_by'] && $accessLevel === 'viewer') {
                throw new DomainException('Project creator must retain member-level access.');
            }

            $teamMember = $this->pdo->prepare(
                "SELECT id FROM team_memberships
                 WHERE team_id = :team_id AND user_id = :user_id AND status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $teamMember->execute(['team_id' => $project['team_id'], 'user_id' => $targetUserId]);
            if ($teamMember->fetchColumn() === false) {
                throw new DomainException('Target user is not an active team member.');
            }

            $membership = $this->pdo->prepare(
                'SELECT access_level FROM project_memberships
                 WHERE project_id = :project_id AND user_id = :user_id AND status = \'approved\'
                 LIMIT 1 FOR UPDATE'
            );
            $membership->execute(['project_id' => $projectId, 'user_id' => $targetUserId]);
            $currentLevel = $membership->fetchColumn();
            if (!is_string($currentLevel)) {
                throw new DomainException('Approved project membership not found.');
            }
            if ($currentLevel === $accessLevel) {
                $this->pdo->commit();
                return false;
            }

            $update = $this->pdo->prepare(
                'UPDATE project_memberships SET access_level = :access_level
                 WHERE project_id = :project_id AND user_id = :user_id AND status = \'approved\''
            );
            $update->execute([
                'access_level' => $accessLevel,
                'project_id' => $projectId,
                'user_id' => $targetUserId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Approved project membership could not be updated.');
            }

            if ($targetUserId !== $actorId) {
                $this->notifications->create(
                    $targetUserId,
                    'project_access_level_changed',
                    'Project access updated',
                    $accessLevel === 'viewer'
                        ? 'Your project access is now read-only.'
                        : 'You now have member-level edit access to the project.',
                    ['project_id' => $projectId, 'access_level' => $accessLevel],
                );
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function revoke(int $actorId, int $projectId, int $targetUserId): void
    {
        $this->pdo->beginTransaction();
        try {
            $project = $this->lockProjectForManager($actorId, $projectId);
            if ($targetUserId === $project['created_by']) {
                throw new DomainException('Project creator membership cannot be revoked through this endpoint.');
            }

            $update = $this->pdo->prepare(
                "UPDATE project_memberships SET status = 'revoked'
                 WHERE project_id = :project_id AND user_id = :user_id AND status IN ('pending', 'approved')"
            );
            $update->execute(['project_id' => $projectId, 'user_id' => $targetUserId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Project membership not found.');
            }
            $this->notifications->create(
                $targetUserId,
                'project_access_revoked',
                'Project access revoked',
                'Your project access has been revoked.',
                ['project_id' => $projectId],
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{team_id:int,created_by:int} */
    private function lockProjectForManager(int $actorId, int $projectId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.team_id, p.created_by
             FROM projects p
             INNER JOIN teams t ON t.id = p.team_id AND t.status = 'active'
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = :actor_id
             WHERE p.id = :project_id AND p.status = 'active'
               AND tm.status = 'active' AND tm.role IN ('owner', 'manager')
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['actor_id' => $actorId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Active team manager role required.');
        }
        return ['team_id' => (int) $row['team_id'], 'created_by' => (int) $row['created_by']];
    }
}
