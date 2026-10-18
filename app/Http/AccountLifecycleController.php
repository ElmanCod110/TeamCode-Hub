<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Security\ClientIpResolver;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Services\AccountLifecycleService;

final class AccountLifecycleController
{
    private const GENERIC_RESPONSE = 'If the request can be processed, a verification email will be sent. Check your inbox and spam folder.';

    public function __construct(
        private readonly AccountLifecycleService $accounts,
        private readonly LoginRateLimiter $rateLimiter,
    ) {
    }

    /** Create a pending account without revealing whether an email is already registered. */
    public function register(Request $request): Response
    {
        SecureSession::start();
        $body = $request->body();
        if (!$this->validCsrf($request, $body)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $email = $body['email'] ?? null;
        $displayName = $body['display_name'] ?? null;
        $password = $body['password'] ?? null;
        $confirmation = $body['confirm_password'] ?? null;
        if (!is_string($email) || !is_string($displayName) || !is_string($password) || !is_string($confirmation)) {
            return Response::json(['error' => 'Email, display name, password, and confirmation are required.'], 422);
        }

        $email = strtolower(trim($email));
        $displayName = trim($displayName);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Response::json(['error' => 'Enter a valid email address.'], 422);
        }
        if ($displayName === '' || strlen($displayName) > 80 || preg_match('//u', $displayName) !== 1) {
            return Response::json(['error' => 'Display name must contain 1 to 80 UTF-8 bytes.'], 422);
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            return Response::json(['error' => 'Password must contain 12 to 72 bytes.'], 422);
        }
        if (!hash_equals($password, $confirmation)) {
            return Response::json(['error' => 'Password confirmation does not match.'], 422);
        }

        $ip = $this->clientIp($request);
        if (!$this->rateLimiter->allowsForPurpose('register', $email, $ip)) {
            return Response::json(['error' => 'Too many requests. Try again later.'], 429);
        }

        $this->accounts->register($email, $displayName, $password);
        return Response::json(['accepted' => true, 'message' => self::GENERIC_RESPONSE], 202);
    }

    /** Resend links without disclosing whether the address has a pending account. */
    public function resendVerification(Request $request): Response
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
        if (!$this->rateLimiter->allowsForPurpose('verification-resend', $email, $this->clientIp($request))) {
            return Response::json(['error' => 'Too many requests. Try again later.'], 429);
        }

        $this->accounts->resendVerification($email);
        return Response::json(['accepted' => true, 'message' => self::GENERIC_RESPONSE], 202);
    }

    /** Complete verification only after the user confirms the email link in the browser. */
    public function verifyEmail(Request $request): Response
    {
        SecureSession::start();
        $body = $request->body();
        if (!$this->validCsrf($request, $body)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $token = $body['token'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return Response::json(['error' => 'This verification link is invalid, expired, or already used.'], 400);
        }
        if (!$this->accounts->verifyEmail($token)) {
            return Response::json(['error' => 'This verification link is invalid, expired, or already used.'], 400);
        }

        return Response::json(['verified' => true, 'message' => 'Email verified. You can now sign in.']);
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
