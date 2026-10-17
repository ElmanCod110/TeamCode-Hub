<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use Throwable;

final class TaskCollaborationRepository
{
    private readonly NotificationRepository $notifications;

    public function __construct(private readonly PDO $pdo)
    {
        $this->notifications = new NotificationRepository($pdo);
    }

    /** @return array{task:array<string,mixed>,comments:list<array<string,mixed>>,subtasks:list<array<string,mixed>>} */
    public function details(int $userId, int $projectId, int $taskId): array
    {
        $task = $this->taskAccess($userId, $projectId, $taskId, false);
        $comments = $this->pdo->prepare(
            "SELECT c.id, c.task_id, c.user_id,
                    CASE WHEN c.deleted_at IS NULL THEN c.body ELSE '' END AS body,
                    c.edited_at, c.deleted_at, c.created_at, c.updated_at,
                    u.display_name AS author_name, u.email AS author_email
             FROM task_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.task_id = :task_id
             ORDER BY c.created_at ASC, c.id ASC
             LIMIT 100"
        );
        $comments->execute(['task_id' => $taskId]);

        $subtasks = $this->pdo->prepare(
            'SELECT id, task_id, title, is_done, created_by, created_at, updated_at
             FROM task_subtasks
             WHERE task_id = :task_id
             ORDER BY created_at ASC, id ASC
             LIMIT 50'
        );
        $subtasks->execute(['task_id' => $taskId]);

        return [
            'task' => $task,
            'comments' => array_map(self::normalizeComment(...), $comments->fetchAll(PDO::FETCH_ASSOC)),
            'subtasks' => array_map(self::normalizeSubtask(...), $subtasks->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    public function createComment(int $userId, int $projectId, int $taskId, string $body): int
    {
        $this->pdo->beginTransaction();
        try {
            $task = $this->taskAccess($userId, $projectId, $taskId, true, true);
            $insert = $this->pdo->prepare(
                'INSERT INTO task_comments (task_id, user_id, body) VALUES (:task_id, :user_id, :body)'
            );
            $insert->execute(['task_id' => $taskId, 'user_id' => $userId, 'body' => $body]);
            $commentId = (int) $this->pdo->lastInsertId();

            $recipients = array_unique(array_filter([
                (int) $task['created_by'],
                $task['assigned_to'] === null ? null : (int) $task['assigned_to'],
            ], static fn (?int $id): bool => $id !== null && $id > 0 && $id !== $userId));
            foreach ($recipients as $recipientId) {
                $this->notifications->create(
                    $recipientId,
                    'task_comment',
                    'New task comment',
                    (string) $task['title'],
                    ['project_id' => $projectId, 'task_id' => $taskId, 'comment_id' => $commentId],
                );
            }

            $this->pdo->commit();
            return $commentId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function editComment(int $userId, int $projectId, int $taskId, int $commentId, string $body): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->taskAccess($userId, $projectId, $taskId, true, true);
            $comment = $this->pdo->prepare(
                'SELECT id FROM task_comments
                 WHERE id = :comment_id AND task_id = :task_id AND user_id = :user_id
                   AND deleted_at IS NULL
                   AND created_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE
                 LIMIT 1 FOR UPDATE'
            );
            $comment->execute(['comment_id' => $commentId, 'task_id' => $taskId, 'user_id' => $userId]);
            if ($comment->fetchColumn() === false) {
                throw new DomainException('Comment not found, outside the edit window, or not editable by this account.');
            }

            $update = $this->pdo->prepare(
                'UPDATE task_comments SET body = :body, edited_at = CURRENT_TIMESTAMP
                 WHERE id = :comment_id AND task_id = :task_id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $update->execute(['body' => $body, 'comment_id' => $commentId, 'task_id' => $taskId, 'user_id' => $userId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function deleteComment(int $userId, int $projectId, int $taskId, int $commentId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->taskAccess($userId, $projectId, $taskId, true, true);
            $delete = $this->pdo->prepare(
                'UPDATE task_comments SET deleted_at = CURRENT_TIMESTAMP
                 WHERE id = :comment_id AND task_id = :task_id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $delete->execute(['comment_id' => $commentId, 'task_id' => $taskId, 'user_id' => $userId]);
            if ($delete->rowCount() !== 1) {
                throw new DomainException('Comment not found or not editable by this account.');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function createSubtask(int $userId, int $projectId, int $taskId, string $title): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->taskAccess($userId, $projectId, $taskId, true, true);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM task_subtasks WHERE task_id = :task_id');
            $count->execute(['task_id' => $taskId]);
            if ((int) $count->fetchColumn() >= 50) {
                throw new DomainException('A task can contain at most 50 checklist items.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO task_subtasks (task_id, title, created_by) VALUES (:task_id, :title, :created_by)'
            );
            $insert->execute(['task_id' => $taskId, 'title' => $title, 'created_by' => $userId]);
            $subtaskId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $subtaskId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function toggleSubtask(int $userId, int $projectId, int $taskId, int $subtaskId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->taskAccess($userId, $projectId, $taskId, true, true);
            $subtask = $this->pdo->prepare(
                'SELECT is_done FROM task_subtasks WHERE id = :subtask_id AND task_id = :task_id LIMIT 1 FOR UPDATE'
            );
            $subtask->execute(['subtask_id' => $subtaskId, 'task_id' => $taskId]);
            $current = $subtask->fetchColumn();
            if ($current === false) {
                throw new DomainException('Checklist item not found.');
            }
            $next = (int) $current === 1 ? 0 : 1;
            $update = $this->pdo->prepare(
                'UPDATE task_subtasks SET is_done = :is_done WHERE id = :subtask_id AND task_id = :task_id'
            );
            $update->execute(['is_done' => $next, 'subtask_id' => $subtaskId, 'task_id' => $taskId]);
            $this->pdo->commit();
            return $next === 1;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function deleteSubtask(int $userId, int $projectId, int $taskId, int $subtaskId): void
    {
        $this->pdo->beginTransaction();
        try {
            $task = $this->taskAccess($userId, $projectId, $taskId, true, true);
            $subtask = $this->pdo->prepare(
                'SELECT created_by FROM task_subtasks WHERE id = :subtask_id AND task_id = :task_id LIMIT 1 FOR UPDATE'
            );
            $subtask->execute(['subtask_id' => $subtaskId, 'task_id' => $taskId]);
            $creatorId = $subtask->fetchColumn();
            if ($creatorId === false || ((int) $creatorId !== $userId && (int) $task['created_by'] !== $userId)) {
                throw new DomainException('Checklist item not found or not deletable by this account.');
            }
            $delete = $this->pdo->prepare('DELETE FROM task_subtasks WHERE id = :subtask_id AND task_id = :task_id');
            $delete->execute(['subtask_id' => $subtaskId, 'task_id' => $taskId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Resolve the task only through active team membership and approved project access.
     * Mutations lock the task row so comment/checklist limits remain consistent.
     *
     * @return array<string,mixed>
     */
    private function taskAccess(int $userId, int $projectId, int $taskId, bool $mustEdit, bool $forUpdate = false): array
    {
        $sql = "SELECT t.id, t.project_id, t.title, t.description, t.status, t.priority,
                       t.created_by, t.assigned_to, t.due_at, t.completed_at, t.created_at, t.updated_at,
                       pm.access_level, creator.display_name AS created_by_name,
                       assignee.display_name AS assigned_to_name
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id AND p.status = 'active'
                INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id AND pm.status = 'approved'
                INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
                INNER JOIN users creator ON creator.id = t.created_by
                LEFT JOIN users assignee ON assignee.id = t.assigned_to
                WHERE p.id = :project_id AND t.id = :task_id";
        if ($forUpdate) {
            $sql .= ' LIMIT 1 FOR UPDATE';
        } else {
            $sql .= ' LIMIT 1';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId, 'task_id' => $taskId]);
        $task = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($task) || ($mustEdit && $task['access_level'] !== 'member')) {
            throw new DomainException('Task not found or editable member access required.');
        }
        foreach (['id', 'project_id', 'created_by', 'assigned_to'] as $key) {
            if (isset($task[$key])) {
                $task[$key] = (int) $task[$key];
            }
        }
        return $task;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalizeComment(array $row): array
    {
        foreach (['id', 'task_id', 'user_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalizeSubtask(array $row): array
    {
        foreach (['id', 'task_id', 'created_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['is_done'] = (bool) $row['is_done'];
        return $row;
    }
}
