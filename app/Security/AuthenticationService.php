<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

use RuntimeException;
use TeamCodeHub\Database\UserRepository;

final class AuthenticationService
{
    // A fixed valid hash reduces the timing difference for unknown email addresses.
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(private readonly UserRepository $users)
    {
    }

    /** Returns true only for an active account with a valid password. */
    public function authenticate(string $email, string $password): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Start the secure session before authenticating.');
        }

        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > 4096) {
            password_verify('invalid-input', self::DUMMY_HASH);
            return false;
        }

        $user = $this->users->findForAuthentication($email);
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $passwordValid = password_verify($password, $hash);

        if (!$passwordValid || $user === null || $user['status'] !== 'active') {
            return false;
        }

        $this->users->updateLastLogin($user['id']);
        SecureSession::regenerateAfterAuthentication();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['auth_version'] = (int) $user['auth_version'];
        $_SESSION['authenticated_at'] = time();
        CsrfToken::rotate();

        return true;
    }


    /** Update the signed-in user's display name without exposing password fields. */
    public function updateDisplayName(int $userId, string $displayName): bool
    {
        return $this->users->updateDisplayName($userId, $displayName);
    }

    /**
     * Change the current account password after verifying the existing credential.
     * A compare-and-swap update prevents racing password changes from overwriting one another.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        if ($currentPassword === '' || strlen($currentPassword) > 4096
            || strlen($newPassword) < 12 || strlen($newPassword) > 72) {
            return false;
        }

        $user = $this->users->findAuthenticationById($userId);
        if ($user === null || $user['status'] !== 'active'
            || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash)) {
            return false;
        }

        if (!$this->users->replacePasswordHash($userId, $user['password_hash'], $newHash)) {
            return false;
        }

        // Password changes invalidate other sessions while keeping this authenticated session alive.
        $updated = $this->users->findAuthenticationById($userId);
        if ($updated === null) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['user_id'] ?? null) === $userId) {
            $_SESSION['auth_version'] = (int) $updated['auth_version'];
        }
        return true;
    }

    /** @return array{id:int,email:string,status:string,display_name:string,roles:list<string>}|null */
    public function currentUser(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            return null;
        }

        $user = $this->users->findSessionUserById($id);
        $sessionVersion = $_SESSION['auth_version'] ?? null;
        if ($user === null || $user['status'] !== 'active'
            || !is_int($sessionVersion) || $sessionVersion !== $user['auth_version']) {
            SecureSession::destroy();
            return null;
        }

        // Never expose the internal credential version in the /api/auth/me response.
        unset($user['auth_version']);
        $user['roles'] = $this->users->systemRoles($id);
        return $user;
    }

    public function logout(): void
    {
        SecureSession::destroy();
    }
}
