<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use Throwable;

final class PasswordRecoveryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Issue a hashed, short-lived token for an active account.
     * The generic public response is handled by the service/controller even when no account matches.
     *
     * @return array{id:int,email:string}|null
     */
    public function issueTokenForEmail(string $email, string $tokenHash): ?array
    {
        if (random_int(1, 100) === 1) {
            $this->pdo->exec(
                "DELETE FROM account_action_tokens
                 WHERE expires_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY
                    OR used_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY"
            );
        }

        $this->pdo->beginTransaction();
        try {
            $userQuery = $this->pdo->prepare(
                "SELECT id, email FROM users
                 WHERE email = :email AND status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $userQuery->execute(['email' => strtolower(trim($email))]);
            $user = $userQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) {
                $this->pdo->commit();
                return null;
            }

            $userId = (int) $user['id'];
            $recent = $this->pdo->prepare(
                "SELECT id FROM account_action_tokens
                 WHERE user_id = :user_id AND purpose = 'password_reset'
                   AND created_at >= CURRENT_TIMESTAMP - INTERVAL 60 SECOND
                 LIMIT 1 FOR UPDATE"
            );
            $recent->execute(['user_id' => $userId]);
            if ($recent->fetchColumn() !== false) {
                $this->pdo->commit();
                return null;
            }

            // Only the newest outstanding reset link remains usable.
            $revoke = $this->pdo->prepare(
                "UPDATE account_action_tokens SET used_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND purpose = 'password_reset' AND used_at IS NULL"
            );
            $revoke->execute(['user_id' => $userId]);

            $insert = $this->pdo->prepare(
                "INSERT INTO account_action_tokens (user_id, purpose, token_hash, expires_at)
                 VALUES (:user_id, 'password_reset', :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))"
            );
            $insert->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
            $this->pdo->commit();

            return ['id' => $userId, 'email' => (string) $user['email']];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Replace the credential, revoke every active reset link, and increment the session
     * version in one transaction. Existing authenticated sessions become invalid on next use.
     */
    public function resetPassword(string $tokenHash, string $newPasswordHash): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                "SELECT t.id AS token_id, u.id AS user_id, u.status
                 FROM account_action_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.purpose = 'password_reset' AND t.token_hash = :token_hash
                   AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
                 LIMIT 1 FOR UPDATE"
            );
            $lookup->execute(['token_hash' => $tokenHash]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['status'] !== 'active') {
                $this->pdo->commit();
                return false;
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET password_hash = :password_hash, session_version = session_version + 1
                 WHERE id = :user_id AND status = 'active'"
            );
            $update->execute([
                'password_hash' => $newPasswordHash,
                'user_id' => (int) $row['user_id'],
            ]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $consume = $this->pdo->prepare(
                "UPDATE account_action_tokens SET used_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND purpose = 'password_reset' AND used_at IS NULL"
            );
            $consume->execute(['user_id' => (int) $row['user_id']]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
