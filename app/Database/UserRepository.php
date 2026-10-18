<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use DomainException;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,email:string,password_hash:string,status:string,display_name:string}|null */
    public function findForAuthentication(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, password_hash, status, display_name FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'status' => (string) $row['status'],
            'display_name' => (string) $row['display_name'],
        ];
    }

    /** @return array{id:int,email:string,status:string,display_name:string}|null */
    public function findPublicById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, status, display_name FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'display_name' => (string) $row['display_name'],
        ];
    }


    /**
     * Update the public display name for an active account.
     * The database constraint remains the final guard against invalid account state.
     */
    public function updateDisplayName(int $userId, string $displayName): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE users SET display_name = :display_name
             WHERE id = :id AND status = 'active'"
        );
        $statement->execute(['display_name' => $displayName, 'id' => $userId]);
        if ($statement->rowCount() > 0) {
            return true;
        }

        // MySQL may report zero changed rows when the requested name is already current.
        $exists = $this->pdo->prepare("SELECT 1 FROM users WHERE id = :id AND status = 'active' LIMIT 1");
        $exists->execute(['id' => $userId]);
        return $exists->fetchColumn() !== false;
    }

    /** @return array{id:int,email:string,password_hash:string,status:string,display_name:string}|null */
    public function findAuthenticationById(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, password_hash, status, display_name FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'status' => (string) $row['status'],
            'display_name' => (string) $row['display_name'],
        ];
    }

    /**
     * Replace a password only if its previous hash is unchanged since verification.
     * This compare-and-swap prevents overwriting a concurrent password update.
     */
    public function replacePasswordHash(int $userId, string $expectedHash, string $newHash): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE users SET password_hash = :new_hash
             WHERE id = :id AND status = 'active' AND password_hash = :expected_hash"
        );
        $statement->execute([
            'new_hash' => $newHash,
            'id' => $userId,
            'expected_hash' => $expectedHash,
        ]);
        return $statement->rowCount() > 0;
    }


    /**
     * Search a bounded user directory for site administrators only.
     * Password hashes and other authentication secrets are never selected.
     *
     * @return array{users:list<array<string, mixed>>,has_more:bool}
     */
    public function searchForSiteAdmin(int $actorId, string $query = '', string $status = 'all', int $page = 1): array
    {
        $access = $this->pdo->prepare(
            "SELECT 1 FROM user_system_roles
             WHERE user_id = :user_id AND role = 'site_admin' LIMIT 1"
        );
        $access->execute(['user_id' => $actorId]);
        if ($access->fetchColumn() === false) {
            throw new DomainException('Site administrator role required.');
        }

        if ($page < 1 || $page > 10000) {
            throw new DomainException('Directory page is outside the supported range.');
        }

        $conditions = [];
        $parameters = [];
        if ($status !== 'all') {
            $conditions[] = 'u.status = :status';
            $parameters['status'] = $status;
        }
        if ($query !== '') {
            $escaped = strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $conditions[] = "(u.email LIKE :email_term ESCAPE '!' OR u.display_name LIKE :name_term ESCAPE '!' OR CAST(u.id AS CHAR) = :exact_id)";
            $parameters['email_term'] = '%' . $escaped . '%';
            $parameters['name_term'] = '%' . $escaped . '%';
            $parameters['exact_id'] = ctype_digit($query) ? $query : '-1';
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT u.id, u.email, u.display_name, u.status, u.created_at, u.last_login_at,
                       EXISTS (
                           SELECT 1 FROM user_system_roles sr
                           WHERE sr.user_id = u.id AND sr.role = 'site_admin'
                       ) AS is_site_admin
                FROM users u {$where}
                ORDER BY u.created_at DESC, u.id DESC
                LIMIT 51 OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':offset', ($page - 1) * 50, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > 50;
        if ($hasMore) {
            array_pop($rows);
        }

        return [
            'users' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
                'is_site_admin' => (bool) $row['is_site_admin'],
            ], $rows),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Change an existing active/disabled account status from the site-admin directory.
     *
     * The active administrator rows are locked in a stable order before any account
     * status is changed. This prevents concurrent administrators from disabling one
     * another after each independently observed an active administrator.
     *
     * @return array{id:int,status:string,is_site_admin:bool}
     */
    public function setStatusForSiteAdmin(int $actorId, int $targetUserId, string $status): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new DomainException('Only active and disabled account states can be selected.');
        }

        $this->pdo->beginTransaction();
        try {
            $adminStatement = $this->pdo->query(
                "SELECT u.id, u.status
                 FROM user_system_roles r
                 INNER JOIN users u ON u.id = r.user_id
                 WHERE r.role = 'site_admin'
                 ORDER BY u.id ASC
                 FOR UPDATE"
            );
            $adminRows = $adminStatement->fetchAll(PDO::FETCH_ASSOC);
            $activeAdminIds = [];
            $actorIsActiveAdmin = false;
            foreach ($adminRows as $adminRow) {
                $adminId = (int) $adminRow['id'];
                if ($adminRow['status'] === 'active') {
                    $activeAdminIds[] = $adminId;
                }
                if ($adminId === $actorId && $adminRow['status'] === 'active') {
                    $actorIsActiveAdmin = true;
                }
            }
            if (!$actorIsActiveAdmin) {
                throw new DomainException('An active site administrator role is required.');
            }
            if ($actorId === $targetUserId && $status !== 'active') {
                throw new DomainException('Administrators cannot disable their own account.');
            }

            $targetStatement = $this->pdo->prepare(
                "SELECT id, status,
                        EXISTS (SELECT 1 FROM user_system_roles WHERE user_id = users.id AND role = 'site_admin') AS is_site_admin
                 FROM users
                 WHERE id = :user_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $targetStatement->execute(['user_id' => $targetUserId]);
            $target = $targetStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($target)) {
                throw new DomainException('Target account was not found.');
            }
            if (!in_array($target['status'], ['active', 'disabled'], true)) {
                throw new DomainException('Pending accounts cannot be activated or disabled through this endpoint.');
            }

            $isSiteAdmin = (bool) $target['is_site_admin'];
            if ($isSiteAdmin && $target['status'] === 'active' && $status === 'disabled'
                && count($activeAdminIds) <= 1) {
                throw new DomainException('At least one active site administrator must remain.');
            }

            if ($target['status'] !== $status) {
                $update = $this->pdo->prepare('UPDATE users SET status = :status WHERE id = :user_id');
                $update->execute(['status' => $status, 'user_id' => $targetUserId]);
            }

            $this->pdo->commit();
            return ['id' => $targetUserId, 'status' => $status, 'is_site_admin' => $isSiteAdmin];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<string> */
    public function systemRoles(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT role FROM user_system_roles WHERE user_id = :user_id ORDER BY role ASC');
        $statement->execute(['user_id' => $userId]);
        $roles = $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($roles, static fn ($role): bool => is_string($role)));
    }

    public function updateLastLogin(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
