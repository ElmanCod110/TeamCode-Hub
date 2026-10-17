<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use Throwable;

final class TaskRepository
{
    private readonly NotificationRepository $notifications;

    public function __construct(private readonly PDO $pdo)
    {
        $this->notifications = new NotificationRepository($pdo);
    }

    /** @return list<array<string, mixed>> */
    public function listAccessibleByUser(int $userId, int $projectId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.id, t.project_id, t.title, t.description, t.status, t.priority, t.created_by, t.assigned_to,
                    t.due_at, t.completed_at, t.created_at, t.updated_at,
                    creator.display_name AS created_by_name, assignee.display_name AS assigned_to_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id AND p.status = 'active'
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id AND pm.status = 'approved'
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
             INNER JOIN users creator ON creator.id = t.created_by
             LEFT JOIN users assignee ON assignee.id = t.assigned_to
             WHERE t.project_id = :project_id
             ORDER BY CASE t.status WHEN 'in_progress' THEN 0 WHEN 'todo' THEN 1 WHEN 'blocked' THEN 2 ELSE 3 END,
                      CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                      t.updated_at DESC, t.id DESC"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        return array_map(self::normalize(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function create(int $userId, int $projectId, string $title, ?string $description, string $priority, ?int $assignedTo, ?string $dueAt): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockProjectAccess($userId, $projectId, true);
            if ($assignedTo !== null) {
                $assignee = $this->pdo->prepare(
                    "SELECT pm.user_id
                     FROM project_memberships pm
                     INNER JOIN projects p ON p.id = pm.project_id
                     INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
                     INNER JOIN users u ON u.id = pm.user_id AND u.status = 'active'
                     WHERE pm.project_id = :project_id AND pm.user_id = :user_id
                       AND pm.status = 'approved' AND pm.access_level = 'member' AND tm.status = 'active'
                     LIMIT 1 FOR UPDATE"
                );
                $assignee->execute(['project_id' => $projectId, 'user_id' => $assignedTo]);
                if ($assignee->fetchColumn() === false) {
                    throw new DomainException('Assignee must have approved member-level project access.');
                }
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO tasks (project_id, title, description, status, priority, created_by, assigned_to, due_at)
                 VALUES (:project_id, :title, :description, 'todo', :priority, :created_by, :assigned_to, :due_at)"
            );
            $insert->execute([
                'project_id' => $projectId,
                'title' => $title,
                'description' => $description,
                'priority' => $priority,
                'created_by' => $userId,
                'assigned_to' => $assignedTo,
                'due_at' => $dueAt,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if ($assignedTo !== null && $assignedTo !== $userId) {
                $this->notifications->create(
                    $assignedTo,
                    'task_assigned',
                    'New task assigned',
                    $title,
                    ['project_id' => $projectId, 'task_id' => $id],
                );
            }
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function updateStatus(int $userId, int $projectId, int $taskId, string $status): void
    {
        if (!in_array($status, ['todo', 'in_progress', 'blocked', 'done'], true)) {
            throw new DomainException('Invalid task status.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->lockProjectAccess($userId, $projectId, true);
            $task = $this->pdo->prepare('SELECT id, created_by, title FROM tasks WHERE id = :task_id AND project_id = :project_id LIMIT 1 FOR UPDATE');
            $task->execute(['task_id' => $taskId, 'project_id' => $projectId]);
            $taskRow = $task->fetch(PDO::FETCH_ASSOC);
            if (!is_array($taskRow)) {
                throw new DomainException('Task not found.');
            }

            $update = $this->pdo->prepare(
                "UPDATE tasks SET status = :status,
                    completed_at = CASE WHEN :completed_status = 'done' THEN CURRENT_TIMESTAMP ELSE NULL END
                 WHERE id = :task_id AND project_id = :project_id"
            );
            $update->execute([
                'status' => $status,
                'completed_status' => $status,
                'task_id' => $taskId,
                'project_id' => $projectId,
            ]);
            if ($status === 'done' && (int) $taskRow['created_by'] !== $userId) {
                $this->notifications->create(
                    (int) $taskRow['created_by'],
                    'task_completed',
                    'Task completed',
                    (string) $taskRow['title'],
                    ['project_id' => $projectId, 'task_id' => $taskId],
                );
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{team_id:int,access_level:string} */
    private function lockProjectAccess(int $userId, int $projectId, bool $mustEdit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.team_id, pm.access_level
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE p.id = :project_id AND p.status = 'active'
               AND pm.status = 'approved' AND tm.status = 'active'
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($mustEdit && $row['access_level'] !== 'member')) {
            throw new DomainException('Approved member-level project access required.');
        }
        return ['team_id' => (int) $row['team_id'], 'access_level' => (string) $row['access_level']];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'project_id', 'created_by', 'assigned_to'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
