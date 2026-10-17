<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use TeamCodeHub\Core\QuotaExceededException;
use PDO;
use Throwable;

final class ProjectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listAccessibleByUserInTeam(int $userId, int $teamId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.name, p.slug, p.description, p.status, p.created_by, p.created_at, p.updated_at, pm.access_level
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE p.team_id = :team_id AND p.status = 'active'
               AND pm.status = 'approved' AND tm.status = 'active'
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $statement->execute(['user_id' => $userId, 'team_id' => $teamId]);
        return array_map(self::normalize(...), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findAccessibleByUser(int $userId, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.team_id, p.name, p.slug, p.description, p.status, p.created_by, p.created_at, p.updated_at, pm.access_level
             FROM projects p
             INNER JOIN project_memberships pm ON pm.project_id = p.id AND pm.user_id = :user_id
             INNER JOIN team_memberships tm ON tm.team_id = p.team_id AND tm.user_id = pm.user_id
             WHERE p.id = :project_id AND p.status = 'active'
               AND pm.status = 'approved' AND tm.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $row = $statement->fetch();
        return is_array($row) ? self::normalize($row) : null;
    }

    public function createForTeamManager(int $userId, int $teamId, string $name, ?string $description): int
    {
        $role = $this->pdo->prepare(
            "SELECT tm.role, t.project_limit
             FROM team_memberships tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.team_id = :team_id AND tm.user_id = :user_id
               AND tm.status = 'active' AND t.status = 'active'
             LIMIT 1 FOR UPDATE"
        );

        $base = strtolower($name);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        if ($slug === '') {
            $slug = 'project';
        }
        $slug = substr($slug, 0, 95) . '-' . bin2hex(random_bytes(3));

        $this->pdo->beginTransaction();
        try {
            $role->execute(['team_id' => $teamId, 'user_id' => $userId]);
            $roleRow = $role->fetch(PDO::FETCH_ASSOC);
            if (!is_array($roleRow) || !in_array($roleRow['role'], ['owner', 'manager'], true)) {
                throw new DomainException('Active team manager role required.');
            }

            $count = $this->pdo->prepare("SELECT COUNT(*) FROM projects WHERE team_id = :team_id AND status = 'active'");
            $count->execute(['team_id' => $teamId]);
            if ((int) $count->fetchColumn() >= (int) $roleRow['project_limit']) {
                throw new QuotaExceededException('Team project quota reached.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO projects (team_id, name, slug, description, status, created_by)
                 VALUES (:team_id, :name, :slug, :description, 'active', :created_by)"
            );
            $insert->execute([
                'team_id' => $teamId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'created_by' => $userId,
            ]);
            $projectId = (int) $this->pdo->lastInsertId();

            $membership = $this->pdo->prepare(
                "INSERT INTO project_memberships (project_id, user_id, access_level, status, approved_by, approved_at)
                 VALUES (:project_id, :user_id, 'member', 'approved', :approved_by, CURRENT_TIMESTAMP)"
            );
            $membership->execute(['project_id' => $projectId, 'user_id' => $userId, 'approved_by' => $userId]);
            $this->pdo->commit();
            return $projectId;
        } catch (Throwable $error) {
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
        foreach (['id', 'team_id', 'created_by'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }
}
