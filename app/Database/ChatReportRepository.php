<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use PDOException;
use Throwable;

final class ChatReportRepository
{
    private readonly NotificationRepository $notifications;

    public function __construct(private readonly PDO $pdo)
    {
        $this->notifications = new NotificationRepository($pdo);
    }

    /** @return array{id:int,project_id:int,message_id:int,status:string,reviewer_scope:string,created_at:string} */
    public function create(int $reporterId, int $projectId, int $messageId, string $reason): array
    {
        $this->pdo->beginTransaction();
        try {
            $messageQuery = $this->pdo->prepare(
                "SELECT m.id, m.project_id, m.user_id, m.body, m.created_at, m.deleted_at, p.team_id
                 FROM project_messages m
                 INNER JOIN projects p ON p.id = m.project_id AND p.status = 'active'
                 INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :reporter_id AND pm.status = 'approved'
                 INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id AND tm.status = 'active'
                 WHERE m.id = :message_id AND m.project_id = :project_id AND m.deleted_at IS NULL
                   AND (m.expires_at IS NULL OR m.expires_at > CURRENT_TIMESTAMP)
                 LIMIT 1 FOR UPDATE"
            );
            $messageQuery->execute(['reporter_id' => $reporterId, 'message_id' => $messageId, 'project_id' => $projectId]);
            $message = $messageQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($message)) {
                throw new DomainException('Message not found or report access denied.');
            }
            $reportedUserId = (int) $message['user_id'];
            if ($reportedUserId === $reporterId) {
                throw new DomainException('Users cannot report their own messages.');
            }

            $snapshot = $this->contextSnapshot($projectId, $messageId, $message);
            $reportDay = gmdate('Y-m-d');

            $targetIsManager = $this->countTeamManagers((int) $message['team_id'], $reportedUserId) > 0;
            $otherManagers = $this->countOtherTeamManagers((int) $message['team_id'], $reportedUserId, $reporterId);
            $scope = (!$targetIsManager && $otherManagers > 0) ? 'team_manager' : 'site_admin';

            $insert = $this->pdo->prepare(
                "INSERT INTO chat_reports
                    (project_id, message_id, reported_user_id, reporter_user_id, report_day, reason, context_snapshot, status, reviewer_scope)
                 VALUES
                    (:project_id, :message_id, :reported_user_id, :reporter_user_id, :report_day, :reason, :context_snapshot, 'pending', :reviewer_scope)"
            );
            $insert->execute([
                'project_id' => $projectId,
                'message_id' => $messageId,
                'reported_user_id' => $reportedUserId,
                'reporter_user_id' => $reporterId,
                'report_day' => $reportDay,
                'reason' => $reason,
                'context_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'reviewer_scope' => $scope,
            ]);
            $reportId = (int) $this->pdo->lastInsertId();

            $hold = $this->pdo->prepare('UPDATE project_messages SET expires_at = NULL WHERE id = :message_id');
            $hold->execute(['message_id' => $messageId]);

            $recipients = $scope === 'team_manager'
                ? $this->teamManagerIds((int) $message['team_id'], $reportedUserId, $reporterId)
                : $this->siteAdminIds();

            if ($scope === 'team_manager' && $recipients === []) {
                $scope = 'site_admin';
                $this->pdo->prepare("UPDATE chat_reports SET reviewer_scope = 'site_admin' WHERE id = :id")
                    ->execute(['id' => $reportId]);
                $recipients = $this->siteAdminIds();
            }

            foreach ($recipients as $recipientId) {
                $this->notifications->create(
                    $recipientId,
                    'chat_report_received',
                    'Chat report requires review',
                    'A report has been submitted and requires review.',
                    ['report_id' => $reportId, 'project_id' => $projectId, 'team_id' => (int) $message['team_id']],
                );
            }

            $created = $this->pdo->prepare('SELECT created_at FROM chat_reports WHERE id = :id');
            $created->execute(['id' => $reportId]);
            $createdAt = (string) $created->fetchColumn();
            $this->pdo->commit();

            return [
                'id' => $reportId,
                'project_id' => $projectId,
                'message_id' => $messageId,
                'status' => 'pending',
                'reviewer_scope' => $scope,
                'created_at' => $createdAt,
            ];
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error->getCode() === '23000') {
                throw new DomainException('A unique report against this person was already submitted today.', 0, $error);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listForTeamManager(int $actorId, int $teamId): array
    {
        $access = $this->pdo->prepare(
            "SELECT 1 FROM team_memberships
             WHERE user_id = :user_id AND team_id = :team_id AND status = 'active'
               AND role IN ('owner', 'manager') LIMIT 1"
        );
        $access->execute(['user_id' => $actorId, 'team_id' => $teamId]);
        if ($access->fetchColumn() === false) {
            throw new DomainException('Active team manager role required.');
        }

        $statement = $this->pdo->prepare(
            "SELECT r.id, r.project_id, r.message_id, r.reported_user_id, r.reporter_user_id,
                    r.reason, r.context_snapshot, r.status, r.reviewer_scope, r.reviewed_by,
                    r.reviewed_at, r.valid_at, r.created_at, p.team_id,
                    target.display_name AS reported_name, reporter.display_name AS reporter_name
             FROM chat_reports r
             INNER JOIN projects p ON p.id = r.project_id
             INNER JOIN users target ON target.id = r.reported_user_id
             INNER JOIN users reporter ON reporter.id = r.reporter_user_id
             WHERE p.team_id = :team_id AND r.reviewer_scope = 'team_manager'
               AND r.status IN ('pending', 'valid')
             ORDER BY r.created_at ASC, r.id ASC LIMIT 50"
        );
        $statement->execute(['team_id' => $teamId]);
        return array_map(self::normalizeReport(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function listForSiteAdmin(int $actorId): array
    {
        if (!$this->isSiteAdmin($actorId)) {
            throw new DomainException('Site administrator role required.');
        }

        $statement = $this->pdo->prepare(
            "SELECT r.id, r.project_id, r.message_id, r.reported_user_id, r.reporter_user_id,
                    r.reason, r.context_snapshot, r.status, r.reviewer_scope, r.reviewed_by,
                    r.reviewed_at, r.valid_at, r.created_at, p.team_id,
                    target.display_name AS reported_name, reporter.display_name AS reporter_name
             FROM chat_reports r
             INNER JOIN projects p ON p.id = r.project_id
             INNER JOIN users target ON target.id = r.reported_user_id
             INNER JOIN users reporter ON reporter.id = r.reporter_user_id
             WHERE r.reviewer_scope = 'site_admin' AND r.status IN ('pending', 'valid')
             ORDER BY r.created_at ASC, r.id ASC LIMIT 50"
        );
        $statement->execute();
        return array_map(self::normalizeReport(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{status:string,reviewer_scope:string,escalated:bool} */
    public function review(int $actorId, int $reportId, string $action): array
    {
        if (!in_array($action, ['valid', 'rejected', 'resolved'], true)) {
            throw new DomainException('Invalid report review action.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT r.id, r.project_id, r.message_id, r.reported_user_id, r.reporter_user_id,
                        r.status, r.reviewer_scope, p.team_id
                 FROM chat_reports r
                 INNER JOIN projects p ON p.id = r.project_id
                 WHERE r.id = :id LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['id' => $reportId]);
            $report = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($report)) {
                throw new DomainException('Report not found.');
            }
            $reportedUserId = (int) $report['reported_user_id'];
            $involvement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM chat_reports WHERE reported_user_id = :reported_user_id AND reporter_user_id = :reviewer_id AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 DAY'
            );
            $involvement->execute(['reported_user_id' => $reportedUserId, 'reviewer_id' => $actorId]);
            if ($reportedUserId === $actorId || (int) $involvement->fetchColumn() > 0) {
                throw new DomainException('A person involved in a report cannot review that case.');
            }

            if ($report['reviewer_scope'] === 'team_manager') {
                $access = $this->pdo->prepare(
                    "SELECT 1 FROM team_memberships
                     WHERE user_id = :user_id AND team_id = :team_id AND status = 'active'
                       AND role IN ('owner', 'manager') LIMIT 1 FOR UPDATE"
                );
                $access->execute(['user_id' => $actorId, 'team_id' => $report['team_id']]);
                if ($access->fetchColumn() === false) {
                    throw new DomainException('Active team manager role required.');
                }
            } elseif ($report['reviewer_scope'] === 'site_admin') {
                if (!$this->isSiteAdmin($actorId)) {
                    throw new DomainException('Site administrator role required.');
                }
            } else {
                throw new DomainException('Unknown report review scope.');
            }

            if ($action === 'valid' || $action === 'rejected') {
                if ($report['status'] !== 'pending') {
                    throw new DomainException('Only pending reports can be validated or rejected.');
                }
                $validAt = $action === 'valid' ? ', valid_at = CURRENT_TIMESTAMP' : '';
                $update = $this->pdo->prepare(
                    "UPDATE chat_reports SET status = :status, reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP{$validAt}
                     WHERE id = :id"
                );
                $update->execute(['status' => $action, 'reviewed_by' => $actorId, 'id' => $reportId]);
            } else {
                if ($report['status'] !== 'valid') {
                    throw new DomainException('Only valid reports can be resolved.');
                }
                $update = $this->pdo->prepare(
                    "UPDATE chat_reports SET status = 'resolved', reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $update->execute(['reviewed_by' => $actorId, 'id' => $reportId]);
            }

            $escalated = false;
            if ($action === 'valid') {
                $validCount = $this->pdo->prepare(
                    "SELECT COUNT(DISTINCT reporter_user_id)
                     FROM chat_reports
                     WHERE reported_user_id = :user_id
                       AND valid_at >= CURRENT_TIMESTAMP - INTERVAL 1 DAY
                       AND status IN ('valid', 'resolved')"
                );
                $validCount->execute(['user_id' => $reportedUserId]);
                if ((int) $validCount->fetchColumn() >= 2) {
                    $alreadyEscalated = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM chat_reports WHERE reported_user_id = :user_id
                         AND reviewer_scope = 'site_admin' AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 DAY
                         AND status IN ('pending', 'valid')"
                    );
                    $alreadyEscalated->execute(['user_id' => $reportedUserId]);
                    $notifyAdmins = (int) $alreadyEscalated->fetchColumn() === 0;
                    $queue = $this->pdo->prepare(
                        "UPDATE chat_reports SET reviewer_scope = 'site_admin'
                         WHERE reported_user_id = :user_id
                           AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 DAY
                           AND status IN ('pending', 'valid')"
                    );
                    $queue->execute(['user_id' => $reportedUserId]);
                    $adminIds = $notifyAdmins ? $this->siteAdminIds() : [];
                    foreach ($adminIds as $adminId) {
                        $this->notifications->create(
                            $adminId,
                            'chat_report_escalated',
                            'Multiple valid chat reports require review',
                            'At least two distinct valid reports were recorded against a user within 24 hours.',
                            ['reported_user_id' => $reportedUserId, 'project_id' => (int) $report['project_id']],
                        );
                    }
                    $escalated = true;
                }
            }

            if ($action === 'rejected' || $action === 'resolved') {
                $unresolved = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM chat_reports WHERE message_id = :message_id AND status IN ('pending', 'valid')"
                );
                $unresolved->execute(['message_id' => $report['message_id']]);
                if ((int) $unresolved->fetchColumn() === 0) {
                    $release = $this->pdo->prepare(
                        'UPDATE project_messages SET expires_at = CURRENT_TIMESTAMP + INTERVAL 1 DAY WHERE id = :message_id AND expires_at IS NULL'
                    );
                    $release->execute(['message_id' => $report['message_id']]);
                }
            }

            $newStatus = $action === 'resolved' ? 'resolved' : $action;
            $scopeQuery = $this->pdo->prepare('SELECT reviewer_scope FROM chat_reports WHERE id = :id');
            $scopeQuery->execute(['id' => $reportId]);
            $scope = (string) $scopeQuery->fetchColumn();
            $this->pdo->commit();

            return ['status' => $newStatus, 'reviewer_scope' => $scope, 'escalated' => $escalated];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $message
     *  @return list<array<string, mixed>>
     */
    private function contextSnapshot(int $projectId, int $messageId, array $message): array
    {
        $previous = $this->pdo->prepare(
            "SELECT m.id, m.user_id, u.display_name, m.body, m.created_at, m.deleted_at
             FROM project_messages m INNER JOIN users u ON u.id = m.user_id
             WHERE m.project_id = :project_id AND m.id < :message_id
               AND (m.expires_at IS NULL OR m.expires_at > CURRENT_TIMESTAMP)
             ORDER BY m.id DESC LIMIT 1"
        );
        $previous->execute(['project_id' => $projectId, 'message_id' => $messageId]);
        $before = $previous->fetch(PDO::FETCH_ASSOC);

        $author = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :id');
        $author->execute(['id' => $message['user_id']]);
        $current = [
            'id' => (int) $message['id'],
            'user_id' => (int) $message['user_id'],
            'display_name' => (string) $author->fetchColumn(),
            'body' => (string) $message['body'],
            'created_at' => (string) $message['created_at'],
            'deleted_at' => $message['deleted_at'],
        ];

        $next = $this->pdo->prepare(
            "SELECT m.id, m.user_id, u.display_name, m.body, m.created_at, m.deleted_at
             FROM project_messages m INNER JOIN users u ON u.id = m.user_id
             WHERE m.project_id = :project_id AND m.id > :message_id
               AND (m.expires_at IS NULL OR m.expires_at > CURRENT_TIMESTAMP)
             ORDER BY m.id ASC LIMIT 1"
        );
        $next->execute(['project_id' => $projectId, 'message_id' => $messageId]);
        $after = $next->fetch(PDO::FETCH_ASSOC);

        $snapshot = [];
        if (is_array($before)) {
            $before['id'] = (int) $before['id'];
            $before['user_id'] = (int) $before['user_id'];
            $snapshot[] = $before;
        }
        $snapshot[] = $current;
        if (is_array($after)) {
            $after['id'] = (int) $after['id'];
            $after['user_id'] = (int) $after['user_id'];
            $snapshot[] = $after;
        }
        return $snapshot;
    }

    private function countTeamManagers(int $teamId, int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM team_memberships
             WHERE team_id = :team_id AND user_id = :user_id AND status = 'active'
               AND role IN ('owner', 'manager')"
        );
        $statement->execute(['team_id' => $teamId, 'user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    private function countOtherTeamManagers(int $teamId, int $reportedUserId, int $reporterId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM team_memberships
             WHERE team_id = :team_id AND status = 'active'
               AND role IN ('owner', 'manager') AND user_id <> :reported_user_id AND user_id <> :reporter_id"
        );
        $statement->execute(['team_id' => $teamId, 'reported_user_id' => $reportedUserId, 'reporter_id' => $reporterId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<int> */
    private function teamManagerIds(int $teamId, int $reportedUserId, int $reporterId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT user_id FROM team_memberships
             WHERE team_id = :team_id AND status = 'active'
               AND role IN ('owner', 'manager') AND user_id <> :reported_user_id AND user_id <> :reporter_id"
        );
        $statement->execute(['team_id' => $teamId, 'reported_user_id' => $reportedUserId, 'reporter_id' => $reporterId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function siteAdminIds(): array
    {
        $statement = $this->pdo->query("SELECT user_id FROM user_system_roles WHERE role = 'site_admin'");
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function isSiteAdmin(int $userId): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM user_system_roles WHERE user_id = :user_id AND role = 'site_admin' LIMIT 1");
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function normalizeReport(array $row): array
    {
        foreach (['id', 'project_id', 'message_id', 'reported_user_id', 'reporter_user_id', 'team_id', 'reviewed_by'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        $snapshot = json_decode((string) $row['context_snapshot'], true);
        $row['context'] = is_array($snapshot) ? $snapshot : [];
        unset($row['context_snapshot']);
        return $row;
    }
}
