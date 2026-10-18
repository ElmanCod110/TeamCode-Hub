<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Security\ClientIpResolver;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Services\PasswordResetService;

/** Public, rate-limited endpoints for requesting and completing a password reset. */
final class PasswordResetController
{
    private const GENERIC_RESPONSE = 'If an active verified account matches that address, password reset instructions will be sent.';

    public function __construct(
        private readonly PasswordResetService $passwordReset,
        private readonly LoginRateLimiter $rateLimiter,
    ) {
    }

    /** Always return the same response for existing and unknown email addresses. */
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

        $this->passwordReset->requestReset($email);
        return Response::json(['accepted' => true, 'message' => self::GENERIC_RESPONSE], 202);
    }

    /** Validate the reset form and consume a valid one-time token atomically. */
    public function completeReset(Request $request): Response
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
            return Response::json(['error' => 'This reset link is invalid, expired, or already used.'], 400);
        }
        if (!is_string($password) || !is_string($confirmation)) {
            return Response::json(['error' => 'A new password and confirmation are required.'], 422);
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            return Response::json(['error' => 'Password must contain 12 to 72 bytes.'], 422);
        }
        if (!hash_equals($password, $confirmation)) {
            return Response::json(['error' => 'Password confirmation does not match.'], 422);
        }
        if (!$this->rateLimiter->allowsForPurpose('password-reset-complete', $token, $this->clientIp($request))) {
            return Response::json(['error' => 'Too many attempts. Request a new reset link later.'], 429);
        }

        if (!$this->passwordReset->completeReset($token, $password)) {
            return Response::json(['error' => 'This reset link is invalid, expired, or already used.'], 400);
        }

        return Response::json(['reset' => true, 'message' => 'Your password has been updated. You can now sign in.']);
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
