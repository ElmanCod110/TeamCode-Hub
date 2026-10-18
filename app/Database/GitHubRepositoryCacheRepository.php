<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use RuntimeException;
use Throwable;

final class GitHubRepositoryCacheRepository
{
    private const DAILY_REFRESH_BUDGET = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Claim one daily refresh for a public repository under a shared UTC-day budget.
     * The global budget row is locked first so concurrent refreshes cannot exceed the cap.
     *
     * @return array{claimed:bool,rate_limited:bool,snapshot:array{repository:string,attempted_date:?string,fetched_at:?string,data:?array<string,mixed>,last_error:?string}}
     */
    public function claimDailyAttempt(string $repository, string $utcDate): array
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO github_repository_cache (repository_full_name) VALUES (:repository)'
            );
            $insert->execute(['repository' => $repository]);

            $budgetQuery = $this->pdo->query(
                'SELECT utc_date, attempts FROM github_repository_refresh_budget WHERE id = 1 LIMIT 1 FOR UPDATE'
            );
            $budget = $budgetQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($budget)) {
                throw new RuntimeException('GitHub repository refresh budget has not been initialized.');
            }
            $budgetDate = (string) $budget['utc_date'];
            $attempts = (int) $budget['attempts'];
            if ($budgetDate !== $utcDate) {
                $reset = $this->pdo->prepare(
                    'UPDATE github_repository_refresh_budget SET utc_date = :utc_date, attempts = 0 WHERE id = 1'
                );
                $reset->execute(['utc_date' => $utcDate]);
                $attempts = 0;
            }

            $select = $this->pdo->prepare(
                'SELECT repository_full_name, attempted_date, fetched_at, payload_json, last_error
                 FROM github_repository_cache WHERE repository_full_name = :repository LIMIT 1 FOR UPDATE'
            );
            $select->execute(['repository' => $repository]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('GitHub repository cache row could not be locked.');
            }

            $attemptedDate = $row['attempted_date'] === null ? null : (string) $row['attempted_date'];
            if ($attemptedDate === $utcDate) {
                $this->pdo->commit();
                return ['claimed' => false, 'rate_limited' => false, 'snapshot' => self::normalize($row)];
            }

            if ($attempts >= self::DAILY_REFRESH_BUDGET) {
                $this->pdo->commit();
                return ['claimed' => false, 'rate_limited' => true, 'snapshot' => self::normalize($row)];
            }

            $mark = $this->pdo->prepare(
                'UPDATE github_repository_cache
                 SET attempted_date = :utc_date, last_error = NULL
                 WHERE repository_full_name = :repository'
            );
            $mark->execute(['utc_date' => $utcDate, 'repository' => $repository]);

            $increment = $this->pdo->prepare(
                'UPDATE github_repository_refresh_budget
                 SET utc_date = :utc_date, attempts = :attempts WHERE id = 1'
            );
            $increment->execute(['utc_date' => $utcDate, 'attempts' => $attempts + 1]);

            $row['attempted_date'] = $utcDate;
            $row['last_error'] = null;
            $this->pdo->commit();
            return ['claimed' => true, 'rate_limited' => false, 'snapshot' => self::normalize($row)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $data */
    public function storeSuccess(string $repository, string $fetchedAt, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'UPDATE github_repository_cache
             SET fetched_at = :fetched_at, payload_json = :payload_json, last_error = NULL
             WHERE repository_full_name = :repository'
        );
        $statement->execute([
            'fetched_at' => $fetchedAt,
            'payload_json' => $payload,
            'repository' => $repository,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('GitHub repository snapshot could not be stored.');
        }
    }

    public function storeFailure(string $repository, string $safeMessage): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE github_repository_cache SET last_error = :last_error WHERE repository_full_name = :repository'
        );
        $statement->execute([
            'last_error' => substr($safeMessage, 0, 160),
            'repository' => $repository,
        ]);
    }

    /**
     * @return array{repository:string,attempted_date:?string,fetched_at:?string,data:?array<string,mixed>,last_error:?string}
     */
    public function snapshot(string $repository): array
    {
        $statement = $this->pdo->prepare(
            'SELECT repository_full_name, attempted_date, fetched_at, payload_json, last_error
             FROM github_repository_cache WHERE repository_full_name = :repository LIMIT 1'
        );
        $statement->execute(['repository' => $repository]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('GitHub repository cache row does not exist.');
        }
        return self::normalize($row);
    }

    /** @param array<string,mixed> $row
     *  @return array{repository:string,attempted_date:?string,fetched_at:?string,data:?array<string,mixed>,last_error:?string}
     */
    private static function normalize(array $row): array
    {
        $data = null;
        if (is_string($row['payload_json'] ?? null) && $row['payload_json'] !== '') {
            try {
                $decoded = json_decode($row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                $data = is_array($decoded) ? $decoded : null;
            } catch (\JsonException) {
                $data = null;
            }
        }

        return [
            'repository' => (string) $row['repository_full_name'],
            'attempted_date' => $row['attempted_date'] === null ? null : (string) $row['attempted_date'],
            'fetched_at' => $row['fetched_at'] === null ? null : (string) $row['fetched_at'],
            'data' => $data,
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
        ];
    }
}
