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
    private const GENERIC_RESPONSE = 'If an active account matches that email address, a password reset link will be sent shortly.';

    public function __construct(
        private readonly PasswordRecoveryService $recovery,
        private readonly LoginRateLimiter $rateLimiter,
    ) {
    }

    /** Request a reset link without revealing whether an account exists. */
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
        if (!$this->rateLimiter->allowsForPurpose('password-reset', $email, $this->clientIp($request))) {
            return Response::json(['error' => 'Too many requests. Try again later.'], 429);
        }

        $this->recovery->requestReset($email);
        return Response::json(['accepted' => true, 'message' => self::GENERIC_RESPONSE], 202);
    }

    /** Consume a one-time reset link and replace the password. */
    public function resetPassword(Request $request): Response
    {
        SecureSession::start();
        $body = $request->body();
        if (!$this->validCsrf($request, $body)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $token = $body['token'] ?? null;
        $password = $body['new_password'] ?? null;
        $confirmation = $body['confirm_password'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || !is_string($password) || !is_string($confirmation)
            || strlen($password) > 4096 || strlen($confirmation) > 4096) {
            return Response::json(['error' => 'This reset link is invalid, expired, or already used.'], 400);
        }

        if (!$this->rateLimiter->allowsForPurpose('password-reset-token', hash('sha256', $token), $this->clientIp($request))) {
            return Response::json(['error' => 'Too many attempts. Request a new password reset link later.'], 429);
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            return Response::json(['error' => 'The new password must be between 12 and 72 bytes long.'], 422);
        }
        if (!hash_equals($password, $confirmation)) {
            return Response::json(['error' => 'The new password and confirmation do not match.'], 422);
        }
        if (!$this->recovery->resetPassword($token, $password)) {
            return Response::json(['error' => 'This reset link is invalid, expired, or already used.'], 400);
        }

        // The reset transaction increments session_version, invalidating existing sessions.
        SecureSession::destroy();
        return Response::json(['reset' => true, 'message' => 'Password updated. You can now sign in with your new password.']);
    }

    /** Validate the CSRF token supplied with a state-changing request. */
    private function validCsrf(Request $request, array $body): bool
    {
        $token = $request->header('X-Csrf-Token');
        if ($token === null && isset($body['csrf_token']) && is_string($body['csrf_token'])) {
            $token = $body['csrf_token'];
        }
        return CsrfToken::validate($token);
    }

    /** Resolve client addresses only through explicitly trusted proxy configuration. */
    private function clientIp(Request $request): string
    {
        return ClientIpResolver::resolve(
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            $request->header('X-Forwarded-For'),
            ClientIpResolver::configuredTrustedProxies(),
        );
    }
}
