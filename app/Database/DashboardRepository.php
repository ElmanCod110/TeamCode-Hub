<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{teams:list<array<string,mixed>>,projects:list<array<string,mixed>>,tasks_due_today:list<array<string,mixed>>,overdue_tasks:list<array<string,mixed>>} */
    public function overview(int $userId): array
    {
        $teams = $this->pdo->prepare(
            "SELECT t.id, t.name, t.slug, tm.role
             FROM teams t INNER JOIN team_memberships tm ON tm.team_id = t.id
             WHERE tm.user_id = :user_id AND tm.status = 'active' AND t.status = 'active'
             ORDER BY t.name ASC, t.id ASC"
        );
        $teams->execute(['user_id' => $userId]);

        $projects = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.name, p.slug, p.status, pm.access_level, p.updated_at
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id AND pm.status = 'approved'
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
             WHERE p.status = 'active'
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $projects->execute(['user_id' => $userId]);

        $dayStart = gmdate('Y-m-d 00:00:00');
        $dayEnd = gmdate('Y-m-d 00:00:00', strtotime('+1 day UTC'));
        $now = gmdate('Y-m-d H:i:s');
        $dueToday = $this->taskQuery(
            "t.due_at >= :day_start AND t.due_at < :day_end",
            ['day_start' => $dayStart, 'day_end' => $dayEnd],
            $userId,
        );
        $overdue = $this->taskQuery(
            't.due_at < :day_start',
            ['day_start' => $dayStart],
            $userId,
        );

        return [
            'teams' => array_map(self::normalize(...), $teams->fetchAll(PDO::FETCH_ASSOC)),
            'projects' => array_map(self::normalize(...), $projects->fetchAll(PDO::FETCH_ASSOC)),
            'tasks_due_today' => $dueToday,
            'overdue_tasks' => $overdue,
        ];
    }

    /** @param array<string, string> $dateParameters
     *  @return list<array<string, mixed>>
     */
    private function taskQuery(string $dateCondition, array $dateParameters, int $userId): array
    {
        $sql = "SELECT t.id, t.project_id, p.name AS project_name, t.title, t.status, t.priority, t.assigned_to, t.due_at
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id AND p.status = 'active'
                INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :member_user_id AND pm.status = 'approved'
                INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
                WHERE (t.assigned_to = :assigned_user_id OR t.created_by = :created_user_id)
                  AND t.status <> 'done' AND t.due_at IS NOT NULL AND {$dateCondition}
                ORDER BY t.due_at ASC, t.id ASC";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_merge([
            'member_user_id' => $userId,
            'assigned_user_id' => $userId,
            'created_user_id' => $userId,
        ], $dateParameters));
        return array_map(self::normalize(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'team_id', 'project_id', 'assigned_to'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
