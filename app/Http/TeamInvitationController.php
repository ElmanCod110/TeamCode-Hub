<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\QuotaExceededException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\TeamInvitationRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\ClientIpResolver;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;
use TeamCodeHub\Services\EmailDeliveryService;

final class TeamInvitationController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly TeamAccessService $teamAccess,
        private readonly TeamInvitationRepository $invitations,
        private readonly EmailDeliveryService $emailDelivery,
        private readonly LoginRateLimiter $rateLimiter,
    ) {
    }

    public function listPending(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $teamId = $this->positiveId($params['teamId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($teamId === null || !$this->teamAccess->canManage($user['id'], $teamId)) {
            return Response::json(['error' => 'Team not found or management permission denied.'], 404);
        }
        return Response::json(['invitations' => $this->invitations->listPendingForManager($user['id'], $teamId)]);
    }

    public function create(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $teamId = $this->positiveId($params['teamId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($teamId === null || !$this->teamAccess->canManage($user['id'], $teamId)) {
            return Response::json(['error' => 'Team not found or management permission denied.'], 404);
        }

        $email = $request->body()['email'] ?? null;
        if (!is_string($email) || strlen(trim($email)) > 254 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            return Response::json(['error' => 'A valid email address is required.'], 422);
        }

        $normalizedEmail = strtolower(trim($email));
        $rateLimitIdentity = 'team-' . $teamId . ':' . $normalizedEmail;
        if (!$this->rateLimiter->allowsForPurpose('team-invite-create', $rateLimitIdentity, $this->clientIp($request))) {
            return Response::json(['error' => 'Too many invitation attempts. Try again later.'], 429);
        }

        try {
            $invitation = $this->invitations->createForManager($user['id'], $teamId, $normalizedEmail);
        } catch (QuotaExceededException) {
            return Response::json(['error' => 'The team has reached its member quota.'], 409);
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'already exists') || str_contains($error->getMessage(), 'already a member') || str_contains($error->getMessage(), 'suspended')) {
                return Response::json(['error' => 'An active member or pending invitation already exists for this email.'], 409);
            }
            return Response::json(['error' => 'Team not found or management permission denied.'], 404);
        }

        $emailSent = $this->emailDelivery->sendTeamInvitationEmail(
            $invitation['email'],
            $invitation['token'],
            $invitation['team_name'],
        );
        if ($emailSent) {
            // Once mail delivery succeeds, do not also expose the bearer token to the browser.
            unset($invitation['token'], $invitation['team_name']);
        } else {
            // Keep the one-time manual fallback only when configured email delivery is unavailable.
            unset($invitation['team_name']);
        }

        return Response::json([
            'invitation' => $invitation,
            'email_sent' => $emailSent,
            'message' => $emailSent
                ? 'Invitation email sent. The recipient must use the invited email address.'
                : 'Email delivery failed. Copy the one-time token and share it through a trusted channel.',
        ], 201);
    }

    public function accept(Request $request): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $token = $request->body()['token'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return Response::json(['error' => 'A valid invitation token is required.'], 422);
        }

        try {
            $teamId = $this->invitations->accept($user['id'], $user['email'], $token);
        } catch (DomainException) {
            return Response::json(['error' => 'Invitation is invalid, expired, already used, or does not match this account.'], 422);
        }
        return Response::json(['accepted' => true, 'team_id' => $teamId]);
    }

    public function revoke(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $teamId = $this->positiveId($params['teamId'] ?? null);
        $invitationId = $this->positiveId($params['invitationId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($teamId === null || $invitationId === null || !$this->teamAccess->canManage($user['id'], $teamId)) {
            return Response::json(['error' => 'Team or invitation not found.'], 404);
        }

        try {
            $revoked = $this->invitations->revokeByManager($user['id'], $teamId, $invitationId);
        } catch (DomainException) {
            return Response::json(['error' => 'Team or invitation not found.'], 404);
        }
        return $revoked
            ? Response::json(['revoked' => true])
            : Response::json(['error' => 'Pending invitation not found.'], 404);
    }

    private function currentUser(): ?array
    {
        SecureSession::start();
        return $this->authentication->currentUser();
    }

    private function validCsrf(Request $request): bool
    {
        $token = $request->header('X-Csrf-Token');
        $body = $request->body();
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

    private function positiveId(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }
}
