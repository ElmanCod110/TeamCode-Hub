<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use RuntimeException;

final class GitHubActivityCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Atomically claim the one allowed outbound fetch for a username and UTC day.
     *
     * @return array{claimed:bool,snapshot:array<string,mixed>}
     */
    public function claimDailyAttempt(string $username, string $utcDate): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT username, attempted_date, fetched_at, payload_json, last_error
                 FROM github_activity_cache WHERE id = 1 LIMIT 1 FOR UPDATE'
            );
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('GitHub activity cache has not been initialized. Apply database migrations first.');
            }

            $attemptedDate = $row['attempted_date'] === null ? null : (string) $row['attempted_date'];
            if ((string) $row['username'] === $username && $attemptedDate === $utcDate) {
                $this->pdo->commit();
                return ['claimed' => false, 'snapshot' => self::normalize($row)];
            }

            // Clear a prior account's data if the configured public username changed.
            $update = $this->pdo->prepare(
                'UPDATE github_activity_cache
                 SET username = :username, attempted_date = :attempted_date,
                     fetched_at = NULL, payload_json = NULL, last_error = NULL
                 WHERE id = 1'
            );
            $update->execute(['username' => $username, 'attempted_date' => $utcDate]);
            $this->pdo->commit();

            return [
                'claimed' => true,
                'snapshot' => [
                    'username' => $username,
                    'attempted_date' => $utcDate,
                    'fetched_at' => null,
                    'events' => [],
                    'last_error' => null,
                ],
            ];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<array<string,mixed>> $events */
    public function storeSuccess(string $username, string $fetchedAt, array $events): void
    {
        $payload = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'UPDATE github_activity_cache
             SET fetched_at = :fetched_at, payload_json = :payload_json, last_error = NULL
             WHERE id = 1 AND username = :username'
        );
        $statement->execute([
            'fetched_at' => $fetchedAt,
            'payload_json' => $payload,
            'username' => $username,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('GitHub activity cache could not store the fetched snapshot.');
        }
    }

    public function storeFailure(string $username, string $safeMessage): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE github_activity_cache SET last_error = :last_error WHERE id = 1 AND username = :username'
        );
        $statement->execute([
            'last_error' => substr($safeMessage, 0, 160),
            'username' => $username,
        ]);
    }

    /** @return array{username:string,attempted_date:?string,fetched_at:?string,events:list<array<string,mixed>>,last_error:?string} */
    public function snapshot(): array
    {
        $statement = $this->pdo->query(
            'SELECT username, attempted_date, fetched_at, payload_json, last_error
             FROM github_activity_cache WHERE id = 1 LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('GitHub activity cache has not been initialized. Apply database migrations first.');
        }
        return self::normalize($row);
    }

    /** @param array<string,mixed> $row
     *  @return array{username:string,attempted_date:?string,fetched_at:?string,events:list<array<string,mixed>>,last_error:?string}
     */
    private static function normalize(array $row): array
    {
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        return [
            'username' => (string) ($row['username'] ?? ''),
            'attempted_date' => $row['attempted_date'] === null ? null : (string) $row['attempted_date'],
            'fetched_at' => $row['fetched_at'] === null ? null : (string) $row['fetched_at'],
            'events' => is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [],
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
        ];
    }
}
