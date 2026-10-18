<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use Throwable;

/**
 * Persist password-reset token hashes and apply credential changes atomically.
 *
 * Raw bearer tokens never enter the database. A token is valid for 30 minutes,
 * can be consumed once, and is usable only by an active, email-verified account.
 */
final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Issue a reset token for an eligible account, returning the canonical email
     * only when a message may be sent. A generic HTTP response prevents enumeration.
     */
    public function issueForEmail(string $email, string $tokenHash): ?string
    {
        $email = strtolower(trim($email));
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            return null;
        }

        if (random_int(1, 100) === 1) {
            $this->pdo->exec(
                "DELETE FROM account_action_tokens
                 WHERE expires_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY
                    OR used_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY"
            );
        }

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                "SELECT id, email
                 FROM users
                 WHERE email = :email AND status = 'active' AND email_verified_at IS NOT NULL
                 LIMIT 1 FOR UPDATE"
            );
            $lookup->execute(['email' => $email]);
            $user = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) {
                $this->pdo->commit();
                return null;
            }

            $userId = (int) $user['id'];
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
                return null;
            }

            // Supersede previous links so only the newest outstanding reset can work.
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

            return (string) $user['email'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Change the password and consume the reset token in one transaction.
     * A successful reset invalidates every other outstanding reset link for the user.
     */
    public function resetPassword(string $tokenHash, string $passwordHash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1 || $passwordHash === '') {
            return false;
        }

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

            $userId = (int) $row['user_id'];
            $update = $this->pdo->prepare(
                "UPDATE users SET password_hash = :password_hash
                 WHERE id = :user_id AND status = 'active' AND email_verified_at IS NOT NULL"
            );
            $update->execute(['password_hash' => $passwordHash, 'user_id' => $userId]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $consume = $this->pdo->prepare(
                "UPDATE account_action_tokens SET used_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND purpose = 'password_reset' AND used_at IS NULL"
            );
            $consume->execute(['user_id' => $userId]);
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
