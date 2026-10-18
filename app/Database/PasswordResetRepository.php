<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use Throwable;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Issue a one-time password-reset token only for an active, verified account.
     * Locking the user row serializes requests and a cooldown limits outbound email.
     */
    public function issueToken(int $userId, string $tokenHash): bool
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
            $user = $this->pdo->prepare(
                "SELECT id FROM users
                 WHERE id = :user_id AND status = 'active' AND email_verified_at IS NOT NULL
                 LIMIT 1 FOR UPDATE"
            );
            $user->execute(['user_id' => $userId]);
            if ($user->fetchColumn() === false) {
                $this->pdo->commit();
                return false;
            }

            $recent = $this->pdo->prepare(
                "SELECT id FROM account_action_tokens
                 WHERE user_id = :user_id AND purpose = 'password_reset'
                   AND used_at IS NULL
                   AND created_at >= CURRENT_TIMESTAMP - INTERVAL 60 SECOND
                 LIMIT 1 FOR UPDATE"
            );
            $recent->execute(['user_id' => $userId]);
            if ($recent->fetchColumn() !== false) {
                $this->pdo->commit();
                return false;
            }

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
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Consume the reset token and replace the credential in one transaction.
     * Incrementing auth_version revokes all sessions created with the old password.
     */
    public function resetPasswordWithToken(string $tokenHash, string $passwordHash): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                "SELECT t.id AS token_id, u.id AS user_id
                 FROM account_action_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.purpose = 'password_reset' AND t.token_hash = :token_hash
                   AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
                   AND u.status = 'active' AND u.email_verified_at IS NOT NULL
                 LIMIT 1 FOR UPDATE"
            );
            $lookup->execute(['token_hash' => $tokenHash]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return false;
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET password_hash = :password_hash, auth_version = auth_version + 1
                 WHERE id = :user_id AND status = 'active' AND email_verified_at IS NOT NULL"
            );
            $update->execute([
                'password_hash' => $passwordHash,
                'user_id' => (int) $row['user_id'],
            ]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            // Invalidate every outstanding reset link for this account, including the one just used.
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
