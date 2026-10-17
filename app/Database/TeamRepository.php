<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use Throwable;

final class TeamRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.id, t.name, t.slug, t.status, t.member_limit, t.project_limit, tm.role, tm.status AS membership_status
             FROM teams t
             INNER JOIN team_memberships tm ON tm.team_id = t.id
             WHERE tm.user_id = :user_id AND tm.status = 'active' AND t.status = 'active'
             ORDER BY t.name ASC, t.id ASC"
        );
        $statement->execute(['user_id' => $userId]);
        return array_map(self::normalize(...), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findAccessibleByUser(int $teamId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.id, t.name, t.slug, t.status, t.member_limit, t.project_limit, t.created_by, tm.role
             FROM teams t
             INNER JOIN team_memberships tm ON tm.team_id = t.id
             WHERE t.id = :team_id AND tm.user_id = :user_id
               AND tm.status = 'active' AND t.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['team_id' => $teamId, 'user_id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? self::normalize($row) : null;
    }

    /** @return array<string, mixed> */
    public function createForOwner(int $userId, string $name): array
    {
        $base = strtolower($name);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        if ($slug === '') {
            $slug = 'team';
        }
        $slug = substr($slug, 0, 80) . '-' . bin2hex(random_bytes(3));

        $this->pdo->beginTransaction();
        try {
            $team = $this->pdo->prepare(
                "INSERT INTO teams (name, slug, created_by, status) VALUES (:name, :slug, :created_by, 'active')"
            );
            $team->execute(['name' => $name, 'slug' => $slug, 'created_by' => $userId]);
            $teamId = (int) $this->pdo->lastInsertId();

            $membership = $this->pdo->prepare(
                "INSERT INTO team_memberships (team_id, user_id, role, status) VALUES (:team_id, :user_id, 'owner', 'active')"
            );
            $membership->execute(['team_id' => $teamId, 'user_id' => $userId]);
            $this->pdo->commit();

            return [
                'id' => $teamId,
                'name' => $name,
                'slug' => $slug,
                'status' => 'active',
                'member_limit' => 10,
                'project_limit' => 10,
                'role' => 'owner',
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Update a team's display name without changing its stable slug.
     * Only an active owner or manager may change team settings.
     */
    public function updateNameForManager(int $userId, int $teamId, string $name): void
    {
        $this->pdo->beginTransaction();
        try {
            $permission = $this->pdo->prepare(
                "SELECT tm.role
                 FROM team_memberships tm
                 INNER JOIN teams t ON t.id = tm.team_id
                 WHERE tm.team_id = :team_id AND tm.user_id = :user_id
                   AND tm.status = 'active' AND t.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $permission->execute(['team_id' => $teamId, 'user_id' => $userId]);
            $role = $permission->fetchColumn();
            if (!in_array($role, ['owner', 'manager'], true)) {
                throw new \DomainException('Active team management permission required.');
            }

            $update = $this->pdo->prepare(
                "UPDATE teams SET name = :name, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :team_id AND status = 'active'"
            );
            $update->execute(['name' => $name, 'team_id' => $teamId]);
            if ($update->rowCount() < 0) {
                throw new \RuntimeException('Team settings could not be saved.');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'member_limit', 'project_limit', 'created_by'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
