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
            $this->lockProjectForManager($actorId, $projectId);
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
            return array_map(static function (array $row): array {
                $row['user_id'] = (int) $row['user_id'];
                $row['approved_by'] = $row['approved_by'] === null ? null : (int) $row['approved_by'];
                return $row;
            }, $rows);
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
