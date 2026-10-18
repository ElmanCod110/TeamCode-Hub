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
    public function searchForSiteAdmin(int $actorId, string $query = '', string $status = 'all'): array
    {
        $access = $this->pdo->prepare(
            "SELECT 1 FROM user_system_roles
             WHERE user_id = :user_id AND role = 'site_admin' LIMIT 1"
        );
        $access->execute(['user_id' => $actorId]);
        if ($access->fetchColumn() === false) {
            throw new DomainException('Site administrator role required.');
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
                LIMIT 51";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
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
