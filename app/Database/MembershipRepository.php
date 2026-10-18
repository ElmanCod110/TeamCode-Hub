<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;

final class MembershipRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function activeTeamRole(int $userId, int $teamId): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT tm.role
             FROM team_memberships tm
             INNER JOIN teams active_team ON active_team.id = tm.team_id AND active_team.status = 'active'
             WHERE tm.user_id = :user_id AND tm.team_id = :team_id AND tm.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId, 'team_id' => $teamId]);
        $role = $statement->fetchColumn();

        return is_string($role) ? $role : null;
    }

    public function approvedProjectAccessLevel(int $userId, int $projectId): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT pm.access_level
             FROM project_memberships pm
             INNER JOIN projects p ON p.id = pm.project_id
             INNER JOIN teams active_team ON active_team.id = p.team_id AND active_team.status = 'active'
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE pm.user_id = :user_id
               AND pm.project_id = :project_id
               AND pm.status = 'approved'
               AND tm.status = 'active'
               AND p.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $level = $statement->fetchColumn();
        if (!is_string($level) || !in_array($level, ['viewer', 'member'], true)) {
            return null;
        }

        return $level;
    }
}
