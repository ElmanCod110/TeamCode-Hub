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


    /**
     * Update the authenticated account's display name.
     * Email addresses and system roles are intentionally not editable here.
     */
    public function updateProfile(Request $request): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $name = $request->body()['display_name'] ?? null;
        if (!is_string($name) || preg_match('//u', trim($name)) !== 1
            || trim($name) === '' || strlen(trim($name)) > 80) {
            return Response::json(['error' => 'Display name must contain 1 to 80 bytes.'], 422);
        }

        if (!$this->authentication->updateDisplayName($user['id'], trim($name))) {
            return Response::json(['error' => 'Profile could not be updated. Please try again.'], 409);
        }

        return Response::json(['updated' => true, 'user' => $this->authentication->currentUser()]);
    }

    /**
     * Change the account password after checking the current password.
     * Successful changes rotate the session ID and CSRF token.
     */
    public function changePassword(Request $request): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $body = $request->body();
        $currentPassword = $body['current_password'] ?? null;
        $newPassword = $body['new_password'] ?? null;
        $confirmation = $body['confirm_password'] ?? null;
        if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmation)
            || strlen($currentPassword) > 4096 || strlen($newPassword) > 4096
            || strlen($confirmation) > 4096) {
            return Response::json(['error' => 'All password fields are required.'], 422);
        }
        if (strlen($newPassword) < 12) {
            return Response::json(['error' => 'The new password must be at least 12 characters long.'], 422);
        }
        if (!hash_equals($newPassword, $confirmation)) {
            return Response::json(['error' => 'The new password and confirmation do not match.'], 422);
        }
        if (!$this->authentication->changePassword($user['id'], $currentPassword, $newPassword)) {
            return Response::json(['error' => 'The current password is incorrect or the account could not be updated.'], 403);
        }

        SecureSession::regenerateAfterAuthentication();
        CsrfToken::rotate();
        return Response::json(['updated' => true, 'csrf_token' => CsrfToken::get()]);
    }

    /** Validate the CSRF token supplied with a state-changing request. */
    private function validCsrf(Request $request): bool
    {
        $csrf = $request->header('X-Csrf-Token');
        if ($csrf === null && isset($request->body()['csrf_token']) && is_string($request->body()['csrf_token'])) {
            $csrf = $request->body()['csrf_token'];
        }
        return CsrfToken::validate($csrf);
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
