<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use OutOfBoundsException;
use PDO;
use Throwable;

final class AdminAccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Change account status within a transaction and record the reason for every real change.
     *
     * The actor and target rows are locked in ID order to reduce deadlocks when two
     * administrators act concurrently. Authorization is checked again inside this boundary.
     *
     * @return array{changed:bool,user:array{id:int,email:string,display_name:string,status:string,email_verified:bool}}
     */
    public function changeStatusForSiteAdmin(
        int $actorId,
        int $targetUserId,
        string $newStatus,
        string $reason,
    ): array {
        $reason = trim($reason);
        if (!in_array($newStatus, ['active', 'disabled'], true)) {
            throw new DomainException('Only active or disabled status transitions are supported.');
        }
        if (strlen($reason) < 5 || strlen($reason) > 500 || preg_match('//u', $reason) !== 1) {
            throw new DomainException('An audit reason of 5 to 500 UTF-8 bytes is required.');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT id, email, display_name, status, email_verified_at
                 FROM users
                 WHERE id IN (:actor_id, :target_id)
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $lock->execute(['actor_id' => $actorId, 'target_id' => $targetUserId]);
            $lockedUsers = [];
            foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $lockedUsers[(int) $row['id']] = $row;
            }

            $actor = $lockedUsers[$actorId] ?? null;
            if (!is_array($actor) || $actor['status'] !== 'active') {
                throw new DomainException('Site administrator role required.');
            }

            $role = $this->pdo->prepare(
                "SELECT 1 FROM user_system_roles
                 WHERE user_id = :user_id AND role = 'site_admin'
                 LIMIT 1 FOR UPDATE"
            );
            $role->execute(['user_id' => $actorId]);
            if ($role->fetchColumn() === false) {
                throw new DomainException('Site administrator role required.');
            }

            $target = $lockedUsers[$targetUserId] ?? null;
            if (!is_array($target)) {
                throw new OutOfBoundsException('Target account not found.');
            }
            if ($actorId === $targetUserId && $newStatus === 'disabled') {
                throw new DomainException('You cannot disable your own account.');
            }

            $previousStatus = (string) $target['status'];
            if ($previousStatus === $newStatus) {
                $this->pdo->commit();
                return ['changed' => false, 'user' => self::publicAccount($target)];
            }

            if ($newStatus === 'active'
                && ($previousStatus !== 'disabled' || $target['email_verified_at'] === null)) {
                throw new DomainException('Only previously disabled, email-verified accounts can be reactivated.');
            }
            if ($newStatus === 'disabled' && !in_array($previousStatus, ['active', 'pending'], true)) {
                throw new DomainException('This account cannot be disabled from its current state.');
            }

            if ($newStatus === 'disabled' && $previousStatus === 'active') {
                $targetAdmin = $this->pdo->prepare(
                    "SELECT 1 FROM user_system_roles WHERE user_id = :user_id AND role = 'site_admin' LIMIT 1"
                );
                $targetAdmin->execute(['user_id' => $targetUserId]);
                if ($targetAdmin->fetchColumn() !== false) {
                    $activeAdmins = $this->pdo->query(
                        "SELECT COUNT(*)
                         FROM users u
                         INNER JOIN user_system_roles sr ON sr.user_id = u.id AND sr.role = 'site_admin'
                         WHERE u.status = 'active'"
                    );
                    if ((int) $activeAdmins->fetchColumn() <= 1) {
                        throw new DomainException('At least one active site administrator must remain.');
                    }
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE users SET status = :status WHERE id = :user_id'
            );
            $update->execute(['status' => $newStatus, 'user_id' => $targetUserId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Account status could not be updated.');
            }

            $action = $newStatus === 'disabled' ? 'account_disabled' : 'account_reactivated';
            $audit = $this->pdo->prepare(
                'INSERT INTO admin_account_audit
                    (actor_id, actor_display_name, target_user_id, action, previous_status, new_status, reason)
                 VALUES (:actor_id, :actor_name, :target_id, :action, :previous_status, :new_status, :reason)'
            );
            $audit->execute([
                'actor_id' => $actorId,
                'actor_name' => (string) $actor['display_name'],
                'target_id' => $targetUserId,
                'action' => $action,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
            ]);

            $target['status'] = $newStatus;
            $this->pdo->commit();
            return ['changed' => true, 'user' => self::publicAccount($target)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Return recent status changes for a target account to active site administrators only.
     *
     * @return list<array{id:int,actor_id:int,actor_name:string,target_user_id:int,action:string,previous_status:string,new_status:string,reason:string,created_at:string}>
     */
    public function listRecentAuditForSiteAdmin(int $actorId, int $targetUserId, int $limit = 20): array
    {
        $this->assertActiveSiteAdmin($actorId);
        $target = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $target->execute(['id' => $targetUserId]);
        if ($target->fetchColumn() === false) {
            throw new OutOfBoundsException('Target account not found.');
        }

        $limit = max(1, min(50, $limit));
        $statement = $this->pdo->prepare(
            'SELECT id, actor_id, actor_display_name, target_user_id, action,
                    previous_status, new_status, reason, created_at
             FROM admin_account_audit
             WHERE target_user_id = :target_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':target_id', $targetUserId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'actor_id' => (int) $row['actor_id'],
            'actor_name' => (string) $row['actor_display_name'],
            'target_user_id' => (int) $row['target_user_id'],
            'action' => (string) $row['action'],
            'previous_status' => (string) $row['previous_status'],
            'new_status' => (string) $row['new_status'],
            'reason' => (string) $row['reason'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function assertActiveSiteAdmin(int $userId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM users u
             INNER JOIN user_system_roles sr ON sr.user_id = u.id AND sr.role = 'site_admin'
             WHERE u.id = :user_id AND u.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Site administrator role required.');
        }
    }

    /** @param array<string, mixed> $row
     *  @return array{id:int,email:string,display_name:string,status:string,email_verified:bool}
     */
    private static function publicAccount(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'email_verified' => $row['email_verified_at'] !== null,
        ];
    }
}
