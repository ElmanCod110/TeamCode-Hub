<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use TeamCodeHub\Database\PasswordResetRepository;
use TeamCodeHub\Database\UserRepository;

final class PasswordRecoveryService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $passwordResets,
        private readonly EmailDeliveryService $emailDelivery,
    ) {
    }

    /**
     * Send a reset link only to an active, email-verified account.
     * The controller always returns a generic response to prevent account enumeration.
     */
    public function requestReset(string $email): void
    {
        $user = $this->users->findActiveVerifiedByEmail($email);
        if ($user === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        if (!$this->passwordResets->issueToken($user['id'], hash('sha256', $token))) {
            return;
        }
        if (!$this->emailDelivery->sendPasswordResetEmail($user['email'], $token)) {
            // Avoid writing account addresses or bearer tokens to logs.
            error_log('TeamCode Hub: password reset email delivery failed; check APP_URL, MAIL_FROM_ADDRESS, and PHP mail transport.');
        }
    }

    /** Replace the password only when a valid one-time token is still available. */
    public function resetPassword(string $token, string $newPassword): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || strlen($newPassword) < 12 || strlen($newPassword) > 72) {
            return false;
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            return false;
        }
        return $this->passwordResets->resetPasswordWithToken(hash('sha256', $token), $passwordHash);
    }
}
