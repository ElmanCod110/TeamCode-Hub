<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Security\ClientIpResolver;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Services\PasswordRecoveryService;

final class PasswordRecoveryController
{
    private const GENERIC_RESPONSE = 'If an active, verified account matches this address, a password reset email will be sent.';

    public function __construct(
        private readonly PasswordRecoveryService $passwordRecovery,
        private readonly LoginRateLimiter $rateLimiter,
    ) {
    }

    /** Request a reset link without revealing whether the address belongs to an account. */
    public function requestReset(Request $request): Response
    {
        SecureSession::start();
        $body = $request->body();
        if (!$this->validCsrf($request, $body)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $email = $body['email'] ?? null;
        if (!is_string($email) || strlen($email) > 254 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            return Response::json(['error' => 'Enter a valid email address.'], 422);
        }
        $email = strtolower(trim($email));
        if (!$this->rateLimiter->allowsForPurpose('password-reset-request', $email, $this->clientIp($request))) {
            return Response::json(['error' => 'Too many requests. Try again later.'], 429);
        }

        $this->passwordRecovery->requestReset($email);
        return Response::json(['accepted' => true, 'message' => self::GENERIC_RESPONSE], 202);
    }

    /** Complete a reset after validating the token and the new password pair. */
    public function confirmReset(Request $request): Response
    {
        SecureSession::start();
        $body = $request->body();
        if (!$this->validCsrf($request, $body)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $token = $body['token'] ?? null;
        $password = $body['password'] ?? null;
        $confirmation = $body['confirm_password'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return Response::json(['error' => 'This password reset link is invalid, expired, or already used.'], 400);
        }
        if (!is_string($password) || !is_string($confirmation)
            || strlen($password) < 12 || strlen($password) > 72) {
            return Response::json(['error' => 'Password must contain 12 to 72 bytes.'], 422);
        }
        if (!hash_equals($password, $confirmation)) {
            return Response::json(['error' => 'Password confirmation does not match.'], 422);
        }
        if (!$this->rateLimiter->allowsForPurpose('password-reset-confirm', $token, $this->clientIp($request))) {
            return Response::json(['error' => 'Too many reset attempts. Try again later.'], 429);
        }
        if (!$this->passwordRecovery->resetPassword($token, $password)) {
            return Response::json(['error' => 'This password reset link is invalid, expired, or already used.'], 400);
        }

        return Response::json(['reset' => true, 'message' => 'Password reset successfully. Sign in with your new password.']);
    }

    /** @param array<string, mixed> $body */
    private function validCsrf(Request $request, array $body): bool
    {
        $token = $request->header('X-Csrf-Token');
        if ($token === null && isset($body['csrf_token']) && is_string($body['csrf_token'])) {
            $token = $body['csrf_token'];
        }
        return CsrfToken::validate($token);
    }

    private function clientIp(Request $request): string
    {
        return ClientIpResolver::resolve(
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            $request->header('X-Forwarded-For'),
            ClientIpResolver::configuredTrustedProxies(),
        );
    }
}
