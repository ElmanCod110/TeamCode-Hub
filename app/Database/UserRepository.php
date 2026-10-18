<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

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
        return $statement->rowCount() > 0;
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
