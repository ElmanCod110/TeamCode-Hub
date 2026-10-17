<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;
use TeamCodeHub\Core\QuotaExceededException;
use Throwable;

final class TeamInvitationRepository
{
    private readonly NotificationRepository $notifications;

    public function __construct(private readonly PDO $pdo)
    {
        $this->notifications = new NotificationRepository($pdo);
    }

    /** @return array{id:int,team_id:int,email:string,token:string,expires_at:string} */
    public function createForManager(int $actorId, int $teamId, string $email): array
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->pdo->beginTransaction();
        try {
            $access = $this->pdo->prepare(
                "SELECT tm.role, t.member_limit
                 FROM team_memberships tm INNER JOIN teams t ON t.id = tm.team_id
                 WHERE tm.user_id = :user_id AND tm.team_id = :team_id
                   AND tm.status = 'active' AND t.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $access->execute(['user_id' => $actorId, 'team_id' => $teamId]);
            $accessRow = $access->fetch(PDO::FETCH_ASSOC);
            if (!is_array($accessRow) || !in_array($accessRow['role'], ['owner', 'manager'], true)) {
                throw new DomainException('Active team manager role required.');
            }

            $member = $this->pdo->prepare(
                'SELECT tm.status FROM team_memberships tm INNER JOIN users u ON u.id = tm.user_id WHERE tm.team_id = :team_id AND u.email = :email LIMIT 1 FOR UPDATE'
            );
            $member->execute(['team_id' => $teamId, 'email' => $email]);
            $memberStatus = $member->fetchColumn();
            if ($memberStatus === 'active') {
                throw new DomainException('Email is already a member.');
            }
            if ($memberStatus === 'suspended') {
                throw new DomainException('Email belongs to a suspended member.');
            }

            $existing = $this->pdo->prepare(
                "SELECT id FROM team_invitations
                 WHERE team_id = :team_id AND email = :email AND status = 'pending'
                   AND expires_at > CURRENT_TIMESTAMP LIMIT 1"
            );
            $existing->execute(['team_id' => $teamId, 'email' => $email]);
            if ($existing->fetchColumn() !== false) {
                throw new DomainException('A pending invitation already exists for this email.');
            }

            $usage = $this->pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM team_memberships WHERE team_id = :team_active_id AND status = 'active') +
                    (SELECT COUNT(*) FROM team_invitations WHERE team_id = :team_invite_id AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP)"
            );
            $usage->execute(['team_active_id' => $teamId, 'team_invite_id' => $teamId]);
            if ((int) $usage->fetchColumn() >= (int) $accessRow['member_limit']) {
                throw new QuotaExceededException('Team member quota reached.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO team_invitations (team_id, email, token_hash, role, status, invited_by, expires_at)
                 VALUES (:team_id, :email, :token_hash, 'member', 'pending', :invited_by, CURRENT_TIMESTAMP + INTERVAL 7 DAY)"
            );
            $insert->execute([
                'team_id' => $teamId,
                'email' => $email,
                'token_hash' => $tokenHash,
                'invited_by' => $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $expiry = $this->pdo->prepare('SELECT expires_at FROM team_invitations WHERE id = :id');
            $expiry->execute(['id' => $id]);
            $expiresAt = (string) $expiry->fetchColumn();
            $this->pdo->commit();

            return ['id' => $id, 'team_id' => $teamId, 'email' => $email, 'token' => $token, 'expires_at' => $expiresAt];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function accept(int $userId, string $accountEmail, string $token): int
    {
        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                "SELECT id, team_id, email, status, invited_by
                 FROM team_invitations
                 WHERE token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP
                 LIMIT 1 FOR UPDATE"
            );
            $lookup->execute(['token_hash' => hash('sha256', $token)]);
            $invitation = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($invitation) || $invitation['status'] !== 'pending') {
                throw new DomainException('Invitation is invalid, expired, or already used.');
            }
            if (!hash_equals(strtolower((string) $invitation['email']), strtolower(trim($accountEmail)))) {
                throw new DomainException('Invitation email does not match the signed-in account.');
            }

            $team = $this->pdo->prepare("SELECT id FROM teams WHERE id = :team_id AND status = 'active' LIMIT 1 FOR UPDATE");
            $team->execute(['team_id' => $invitation['team_id']]);
            if ($team->fetchColumn() === false) {
                throw new DomainException('Team is no longer active.');
            }

            $membership = $this->pdo->prepare(
                'SELECT id, status FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id LIMIT 1 FOR UPDATE'
            );
            $membership->execute(['team_id' => $invitation['team_id'], 'user_id' => $userId]);
            $existingMembership = $membership->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingMembership) && $existingMembership['status'] === 'suspended') {
                throw new DomainException('Suspended membership cannot be reactivated by an invitation.');
            }

            if (is_array($existingMembership)) {
                if ($existingMembership['status'] !== 'active') {
                    $activate = $this->pdo->prepare(
                        "UPDATE team_memberships SET role = 'member', status = 'active', invited_by = :invited_by
                         WHERE id = :id"
                    );
                    $activate->execute(['invited_by' => $invitation['invited_by'], 'id' => $existingMembership['id']]);
                }
            } else {
                $insert = $this->pdo->prepare(
                    "INSERT INTO team_memberships (team_id, user_id, role, status, invited_by)
                     VALUES (:team_id, :user_id, 'member', 'active', :invited_by)"
                );
                $insert->execute([
                    'team_id' => $invitation['team_id'],
                    'user_id' => $userId,
                    'invited_by' => $invitation['invited_by'],
                ]);
            }

            $accept = $this->pdo->prepare(
                "UPDATE team_invitations SET status = 'accepted', accepted_by = :user_id, accepted_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $accept->execute(['user_id' => $userId, 'id' => $invitation['id']]);
            $this->notifications->create(
                (int) $invitation['invited_by'],
                'team_invitation_accepted',
                'Team invitation accepted',
                'A pending team invitation was accepted.',
                ['team_id' => (int) $invitation['team_id'], 'user_id' => $userId],
            );
            $this->pdo->commit();
            return (int) $invitation['team_id'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function revokeByManager(int $actorId, int $teamId, int $invitationId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $access = $this->pdo->prepare(
                "SELECT tm.role FROM team_memberships tm INNER JOIN teams t ON t.id = tm.team_id AND t.status = 'active' WHERE tm.user_id = :user_id AND tm.team_id = :team_id AND tm.status = 'active' LIMIT 1 FOR UPDATE"
            );
            $access->execute(['user_id' => $actorId, 'team_id' => $teamId]);
            if (!in_array($access->fetchColumn(), ['owner', 'manager'], true)) {
                throw new DomainException('Active team manager role required.');
            }

            $revoke = $this->pdo->prepare(
                "UPDATE team_invitations SET status = 'revoked'
                 WHERE id = :id AND team_id = :team_id AND status = 'pending'"
            );
            $revoke->execute(['id' => $invitationId, 'team_id' => $teamId]);
            $changed = $revoke->rowCount() === 1;
            $this->pdo->commit();
            return $changed;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listPendingForManager(int $actorId, int $teamId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT ti.id, ti.email, ti.role, ti.expires_at, ti.created_at
             FROM team_invitations ti
             INNER JOIN team_memberships tm ON tm.team_id = ti.team_id AND tm.user_id = :user_id AND tm.status = 'active'
             INNER JOIN teams t ON t.id = ti.team_id AND t.status = 'active'
             WHERE ti.team_id = :team_id AND ti.status = 'pending' AND ti.expires_at > CURRENT_TIMESTAMP
               AND tm.role IN ('owner', 'manager')
             ORDER BY ti.created_at DESC, ti.id DESC"
        );
        $statement->execute(['user_id' => $actorId, 'team_id' => $teamId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            return $row;
        }, $rows);
    }
}
