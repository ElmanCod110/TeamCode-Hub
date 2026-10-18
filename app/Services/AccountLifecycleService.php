<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use RuntimeException;
use TeamCodeHub\Database\EmailVerificationRepository;
use TeamCodeHub\Database\UserRepository;

final class AccountLifecycleService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailVerificationRepository $verificationTokens,
        private readonly EmailDeliveryService $emailDelivery,
    ) {
    }

    /**
     * Create a pending account and dispatch a one-time verification link.
     * Duplicate email addresses intentionally produce the same public response.
     */
    public function register(string $email, string $displayName, string $password): void
    {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Could not secure the account credential.');
        }

        $userId = $this->users->createPendingAccount($email, $passwordHash, $displayName);
        if ($userId === null) {
            return;
        }

        $this->sendVerification($userId, $email);
    }

    /**
     * Resend a verification message only for a pending, unverified account.
     * Callers must return a generic response regardless of whether an account exists.
     */
    public function resendVerification(string $email): void
    {
        $user = $this->users->findPendingVerificationByEmail($email);
        if ($user === null) {
            return;
        }
        $this->sendVerification($user['id'], $user['email']);
    }

    /** Consume a valid 256-bit token once and activate its pending account. */
    public function verifyEmail(string $token): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }
        return $this->verificationTokens->verifyToken(hash('sha256', $token));
    }

    private function sendVerification(int $userId, string $email): void
    {
        $token = bin2hex(random_bytes(32));
        if (!$this->verificationTokens->issueToken($userId, hash('sha256', $token))) {
            return;
        }
        if (!$this->emailDelivery->sendVerificationEmail($email, $token)) {
            // Do not log addresses or tokens. Operators can inspect mail transport configuration.
            error_log('TeamCode Hub: verification email delivery failed; check MAIL_FROM_ADDRESS, APP_BASE_URL, and PHP mail transport.');
        }
    }
}
