<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;

/**
 * Read-only site administration queries.
 *
 * Every public query verifies the site_admin role inside the data-access layer.
 * This repository does not grant site administrators implicit team/project access.
 */
final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function overview(int $actorId): array
    {
        $this->assertSiteAdmin($actorId);

        $statement = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM users) AS users_total,
                (SELECT COUNT(*) FROM users WHERE status = 'active') AS users_active,
                (SELECT COUNT(*) FROM users WHERE status = 'pending') AS users_pending,
                (SELECT COUNT(*) FROM teams WHERE status = 'active') AS teams_active,
                (SELECT COUNT(*) FROM projects WHERE status = 'active') AS projects_active,
                (SELECT COUNT(*) FROM tasks WHERE status <> 'done') AS tasks_open,
                (SELECT COUNT(*) FROM team_memberships WHERE status = 'pending') AS pending_team_memberships,
                (SELECT COUNT(*) FROM project_memberships WHERE status = 'pending') AS pending_project_memberships,
                (SELECT COUNT(*) FROM chat_reports WHERE reviewer_scope = 'site_admin' AND status IN ('pending', 'valid')) AS open_admin_reports"
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Site administration overview is unavailable.');
        }

        $counts = [];
        foreach ($row as $key => $value) {
            $counts[(string) $key] = (int) $value;
        }
        return $counts;
    }

    /**
     * List user records using keyset pagination to avoid large OFFSET scans.
     *
     * @return array{users:list<array<string,mixed>>,has_more:bool,next_cursor:?int}
     */
    public function listUsers(int $actorId, int $limit = 20, ?int $beforeId = null, string $search = ''): array
    {
        $this->assertSiteAdmin($actorId);
        $limit = max(1, min(50, $limit));
        $search = trim($search);

        $where = [];
        $parameters = [];
        if ($beforeId !== null) {
            $where[] = 'u.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }
        if ($search !== '') {
            $where[] = '(u.display_name LIKE :name_query OR u.email LIKE :email_query)';
            $needle = '%' . $search . '%';
            $parameters['name_query'] = $needle;
            $parameters['email_query'] = $needle;
        }

        $sql = "SELECT u.id, u.email, u.display_name, u.status, u.email_verified_at,
                       u.last_login_at, u.created_at,
                       EXISTS (
                           SELECT 1 FROM user_system_roles r
                           WHERE r.user_id = u.id AND r.role = 'site_admin'
                       ) AS is_site_admin
                FROM users u";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY u.id DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, $name === 'before_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $users = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'status' => (string) $row['status'],
                'is_email_verified' => $row['email_verified_at'] !== null,
                'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
                'created_at' => (string) $row['created_at'],
                'is_site_admin' => (bool) $row['is_site_admin'],
            ];
        }, $rows);

        return [
            'users' => $users,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $users !== [] ? $users[array_key_last($users)]['id'] : null,
        ];
    }

    private function assertSiteAdmin(int $actorId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM user_system_roles
             WHERE user_id = :user_id AND role = 'site_admin'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $actorId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Site administrator role required.');
        }
    }
}
