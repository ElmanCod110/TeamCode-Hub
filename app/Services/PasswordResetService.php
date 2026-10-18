<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use RuntimeException;
use TeamCodeHub\Database\PasswordResetRepository;

/** Coordinate one-time password-reset tokens and their email delivery. */
final class PasswordResetService
{
    public function __construct(
        private readonly PasswordResetRepository $tokens,
        private readonly EmailDeliveryService $emailDelivery,
    ) {
    }

    /**
     * Request a reset without revealing whether the account exists.
     * The raw token exists only in memory and in the outbound email.
     */
    public function requestReset(string $email): void
    {
        $token = bin2hex(random_bytes(32));
        $recipient = $this->tokens->issueForEmail(strtolower(trim($email)), hash('sha256', $token));
        if ($recipient === null) {
            return;
        }

        if (!$this->emailDelivery->sendPasswordResetEmail($recipient, $token)) {
            // Never log recipient addresses or reset tokens.
            error_log('TeamCode Hub: password reset email delivery failed; check MAIL_FROM_ADDRESS, APP_URL, and PHP mail transport.');
        }
    }

    /** Replace the password only when the one-time bearer token is still valid. */
    public function completeReset(string $token, string $newPassword): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Could not secure the new account credential.');
        }

        return $this->tokens->resetPassword(hash('sha256', $token), $passwordHash);
    }
}
