<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use Throwable;

final class EmailVerificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Persist a one-time verification token hash for a pending, unverified account.
     * A user-row lock serializes resend attempts and a short cooldown prevents email floods.
     */
    public function issueToken(int $userId, string $tokenHash): bool
    {
        // Periodically prune old tokens so the support table remains bounded.
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
                 WHERE id = :user_id AND status = 'pending' AND email_verified_at IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            $user->execute(['user_id' => $userId]);
            if ($user->fetchColumn() === false) {
                $this->pdo->commit();
                return false;
            }

            $recent = $this->pdo->prepare(
                "SELECT id FROM account_action_tokens
                 WHERE user_id = :user_id AND purpose = 'email_verification'
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
                 WHERE user_id = :user_id AND purpose = 'email_verification' AND used_at IS NULL"
            );
            $revoke->execute(['user_id' => $userId]);

            $insert = $this->pdo->prepare(
                "INSERT INTO account_action_tokens (user_id, purpose, token_hash, expires_at)
                 VALUES (:user_id, 'email_verification', :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))"
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
     * Consume a valid email token and activate only its pending, unverified account.
     * The token and user transition commit together, so a token cannot be replayed.
     */
    public function verifyToken(string $tokenHash): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                "SELECT t.id AS token_id, u.id AS user_id, u.status, u.email_verified_at
                 FROM account_action_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.purpose = 'email_verification' AND t.token_hash = :token_hash
                   AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
                 LIMIT 1 FOR UPDATE"
            );
            $lookup->execute(['token_hash' => $tokenHash]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['status'] !== 'pending' || $row['email_verified_at'] !== null) {
                $this->pdo->commit();
                return false;
            }

            $activate = $this->pdo->prepare(
                "UPDATE users
                 SET status = 'active', email_verified_at = CURRENT_TIMESTAMP
                 WHERE id = :user_id AND status = 'pending' AND email_verified_at IS NULL"
            );
            $activate->execute(['user_id' => (int) $row['user_id']]);
            if ($activate->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $consume = $this->pdo->prepare(
                'UPDATE account_action_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id AND used_at IS NULL'
            );
            $consume->execute(['id' => (int) $row['token_id']]);
            if ($consume->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

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
