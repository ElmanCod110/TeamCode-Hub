<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\ClientIpResolver;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\SecureSession;

final class AuthController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly LoginRateLimiter $rateLimiter,
    ) {
    }

    public function login(Request $request): Response
    {
        SecureSession::start();
        $body = $request->body();
        $csrf = $request->header('X-Csrf-Token');
        if ($csrf === null && isset($body['csrf_token']) && is_string($body['csrf_token'])) {
            $csrf = $body['csrf_token'];
        }
        if (!CsrfToken::validate($csrf)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($email) || !is_string($password) || strlen($email) > 254 || strlen($password) > 4096) {
            return Response::json(['error' => 'Email and password are required.'], 422);
        }

        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ip = ClientIpResolver::resolve(
            $remote,
            $request->header('X-Forwarded-For'),
            ClientIpResolver::configuredTrustedProxies(),
        );
        if (!$this->rateLimiter->allows($email, $ip)) {
            return Response::json(['error' => 'Too many login attempts. Try again later.'], 429);
        }

        if (!$this->authentication->authenticate($email, $password)) {
            return Response::json(['error' => 'Invalid email or password.'], 401);
        }

        return Response::json(['authenticated' => true]);
    }

    public function me(): Response
    {
        SecureSession::start();
        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['authenticated' => false], 401);
        }

        return Response::json(['authenticated' => true, 'user' => $user]);
    }

    public function logout(Request $request): Response
    {
        SecureSession::start();
        $csrf = $request->header('X-Csrf-Token');
        if ($csrf === null && isset($request->body()['csrf_token']) && is_string($request->body()['csrf_token'])) {
            $csrf = $request->body()['csrf_token'];
        }
        if (!CsrfToken::validate($csrf)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $this->authentication->logout();
        return Response::json(['authenticated' => false]);
    }
}
