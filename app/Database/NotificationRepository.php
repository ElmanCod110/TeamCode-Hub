<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Caller owns transaction boundaries when this method is used during another workflow. */
    public function create(int $userId, string $type, string $title, ?string $body, array $data = []): int
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, data_json) VALUES (:user_id, :type, :title, :body, :data_json)'
        );
        $statement->execute([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data_json' => $encoded,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function listRecentForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->pdo->prepare(
            'SELECT id, type, title, body, data_json, read_at, created_at
             FROM notifications WHERE user_id = :user_id ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $decoded = json_decode((string) ($row['data_json'] ?? ''), true);
            $row['data'] = is_array($decoded) ? $decoded : [];
            unset($row['data_json']);
            $row['is_read'] = $row['read_at'] !== null;
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unreadCount(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $userId]);
        if ($statement->rowCount() === 1) {
            return true;
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
        $exists->execute(['id' => $notificationId, 'user_id' => $userId]);
        return $exists->fetchColumn() !== false;
    }
}
