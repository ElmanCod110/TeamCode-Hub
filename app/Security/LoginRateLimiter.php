<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

use PDO;

final class LoginRateLimiter
{
    private const WINDOW_MINUTES = 15;
    private const IP_LIMIT = 20;
    private const EMAIL_LIMIT = 8;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allows(string $email, string $clientIp): bool
    {
        $emailKey = hash('sha256', 'email:' . strtolower(trim($email)));
        $ipKey = hash('sha256', 'ip:' . $clientIp);

        // Keep buckets bounded and periodically remove stale identifiers.
        if (random_int(1, 100) === 1) {
            $this->pdo->exec('DELETE FROM auth_rate_limits WHERE window_started_at < CURRENT_TIMESTAMP - INTERVAL 1 DAY');
        }

        $ipAttempts = $this->consume($ipKey, self::IP_LIMIT + 1);
        $emailAttempts = $this->consume($emailKey, self::EMAIL_LIMIT + 1);

        return $ipAttempts <= self::IP_LIMIT && $emailAttempts <= self::EMAIL_LIMIT;
    }

    /**
     * Apply the same bounded IP/email limits to another public account action
     * without consuming the login-specific buckets.
     */
    public function allowsForPurpose(string $purpose, string $email, string $clientIp): bool
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $purpose) !== 1) {
            return false;
        }

        $emailKey = hash('sha256', $purpose . ':email:' . strtolower(trim($email)));
        $ipKey = hash('sha256', $purpose . ':ip:' . $clientIp);
        if (random_int(1, 100) === 1) {
            $this->pdo->exec('DELETE FROM auth_rate_limits WHERE window_started_at < CURRENT_TIMESTAMP - INTERVAL 1 DAY');
        }

        $ipAttempts = $this->consume($ipKey, self::IP_LIMIT + 1);
        $emailAttempts = $this->consume($emailKey, self::EMAIL_LIMIT + 1);
        return $ipAttempts <= self::IP_LIMIT && $emailAttempts <= self::EMAIL_LIMIT;
    }

    private function consume(string $bucketKey, int $cap): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_rate_limits (bucket_key, window_started_at, attempts)
             VALUES (:bucket_key, CURRENT_TIMESTAMP, 1)
             ON DUPLICATE KEY UPDATE
               attempts = IF(window_started_at < CURRENT_TIMESTAMP - INTERVAL 15 MINUTE, 1, LEAST(attempts + 1, :cap)),
               window_started_at = IF(window_started_at < CURRENT_TIMESTAMP - INTERVAL 15 MINUTE, CURRENT_TIMESTAMP, window_started_at)'
        );
        $statement->execute(['bucket_key' => $bucketKey, 'cap' => $cap]);

        $read = $this->pdo->prepare('SELECT attempts FROM auth_rate_limits WHERE bucket_key = :bucket_key LIMIT 1');
        $read->execute(['bucket_key' => $bucketKey]);
        return (int) $read->fetchColumn();
    }
}
