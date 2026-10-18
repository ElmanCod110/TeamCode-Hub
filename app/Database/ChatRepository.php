<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use Throwable;

final class ChatRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listMessages(int $userId, int $projectId, int $afterId = 0): array
    {
        $base = "SELECT m.id, m.project_id, m.user_id, u.display_name, m.body, m.edited_at, m.deleted_at, m.created_at
                 FROM project_messages m
                 INNER JOIN projects p ON p.id = m.project_id AND p.status = 'active'
                  INNER JOIN teams active_team ON active_team.id = p.team_id AND active_team.status = 'active'
                 INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id AND pm.status = 'approved'
                 INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
                 INNER JOIN users u ON u.id = m.user_id
                 WHERE m.project_id = :project_id
                   AND (m.expires_at IS NULL OR m.expires_at > CURRENT_TIMESTAMP)";

        if ($afterId > 0) {
            $statement = $this->pdo->prepare($base . ' AND m.id > :after_id ORDER BY m.id ASC LIMIT 50');
            $statement->execute(['user_id' => $userId, 'project_id' => $projectId, 'after_id' => $afterId]);
            return array_map(self::normalize(...), $statement->fetchAll(PDO::FETCH_ASSOC));
        }

        $statement = $this->pdo->prepare($base . ' ORDER BY m.id DESC LIMIT 50');
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $messages = array_map(self::normalize(...), $statement->fetchAll(PDO::FETCH_ASSOC));
        return array_reverse($messages);
    }

    public function createMessage(int $userId, int $projectId, string $body): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockProjectAccess($userId, $projectId);
            $statement = $this->pdo->prepare(
                'INSERT INTO project_messages (project_id, user_id, body, expires_at)
                 VALUES (:project_id, :user_id, :body, CURRENT_TIMESTAMP + INTERVAL 1 DAY)'
            );
            $statement->execute(['project_id' => $projectId, 'user_id' => $userId, 'body' => $body]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function editMessage(int $userId, int $projectId, int $messageId, string $body): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockProjectAccess($userId, $projectId);
            $message = $this->pdo->prepare(
                "SELECT id FROM project_messages
                 WHERE id = :message_id AND project_id = :project_id AND user_id = :user_id
                   AND deleted_at IS NULL AND created_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE
                   AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 LIMIT 1 FOR UPDATE"
            );
            $message->execute(['message_id' => $messageId, 'project_id' => $projectId, 'user_id' => $userId]);
            if ($message->fetchColumn() === false) {
                throw new DomainException('Message cannot be edited.');
            }

            $reported = $this->pdo->prepare(
                "SELECT COUNT(*) FROM chat_reports WHERE message_id = :message_id AND status IN ('pending', 'valid')"
            );
            $reported->execute(['message_id' => $messageId]);
            if ((int) $reported->fetchColumn() > 0) {
                throw new DomainException('A message with an unresolved report cannot be edited.');
            }

            $update = $this->pdo->prepare(
                'UPDATE project_messages SET body = :body, edited_at = CURRENT_TIMESTAMP WHERE id = :message_id AND project_id = :project_id'
            );
            $update->execute(['body' => $body, 'message_id' => $messageId, 'project_id' => $projectId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function deleteMessage(int $userId, int $projectId, int $messageId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockProjectAccess($userId, $projectId);
            $message = $this->pdo->prepare(
                "SELECT id FROM project_messages
                 WHERE id = :message_id AND project_id = :project_id AND user_id = :user_id
                   AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 LIMIT 1 FOR UPDATE"
            );
            $message->execute(['message_id' => $messageId, 'project_id' => $projectId, 'user_id' => $userId]);
            if ($message->fetchColumn() === false) {
                throw new DomainException('Message cannot be deleted.');
            }

            // Keep the message row and report snapshots intact; only replace its visible content.
            $update = $this->pdo->prepare(
                "UPDATE project_messages SET body = '[message deleted]', deleted_at = CURRENT_TIMESTAMP, edited_at = NULL
                 WHERE id = :message_id AND project_id = :project_id"
            );
            $update->execute(['message_id' => $messageId, 'project_id' => $projectId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function lockProjectAccess(int $userId, int $projectId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id
             FROM projects p
              INNER JOIN teams active_team ON active_team.id = p.team_id AND active_team.status = 'active'
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

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        $row['user_id'] = (int) $row['user_id'];
        return $row;
    }
}
