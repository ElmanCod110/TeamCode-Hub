<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use RuntimeException;
use TeamCodeHub\Database\PasswordRecoveryRepository;

final class PasswordRecoveryService
{
    public function __construct(
        private readonly PasswordRecoveryRepository $tokens,
        private readonly EmailDeliveryService $emailDelivery,
    ) {
    }

    /**
     * Send a reset link only when an active account can receive one.
     * The public endpoint always returns the same message to prevent account enumeration.
     */
    public function requestReset(string $email): void
    {
        $token = bin2hex(random_bytes(32));
        $issued = $this->tokens->issueTokenForEmail(strtolower(trim($email)), hash('sha256', $token));
        if ($issued === null) {
            return;
        }

        if (!$this->emailDelivery->sendPasswordResetEmail($issued['email'], $token)) {
            // Never log an email address or a bearer token.
            error_log('TeamCode Hub: password reset email delivery failed; check MAIL_FROM_ADDRESS, APP_URL, and PHP mail transport.');
        }
    }

    /** Reset an active account password using a valid, single-use 256-bit bearer token. */
    public function resetPassword(string $token, string $newPassword): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || strlen($newPassword) < 12 || strlen($newPassword) > 72) {
            return false;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Could not secure the new account credential.');
        }

        return $this->tokens->resetPassword(hash('sha256', $token), $passwordHash);
    }
}
