<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\ProjectMembershipRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\SecureSession;

final class ProjectMembershipController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly ProjectMembershipRepository $memberships,
    ) {
    }

    public function list(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null) {
            return Response::json(['error' => 'Project not found.'], 404);
        }
        try {
            return Response::json(['members' => $this->memberships->listForManager($user['id'], $projectId)]);
        } catch (DomainException) {
            return Response::json(['error' => 'Project not found or management permission denied.'], 404);
        }
    }

    public function assign(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null) {
            return Response::json(['error' => 'Project not found.'], 404);
        }

        $body = $request->body();
        $targetUserId = $this->positiveId($body['user_id'] ?? null);
        $accessLevel = $body['access_level'] ?? null;
        if ($targetUserId === null || !is_string($accessLevel) || !in_array($accessLevel, ['viewer', 'member'], true)) {
            return Response::json(['error' => 'user_id and access_level (viewer or member) are required.'], 422);
        }

        try {
            $id = $this->memberships->assignPending($user['id'], $projectId, $targetUserId, $accessLevel);
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'already approved')) {
                return Response::json(['error' => 'This user already has approved project access.'], 409);
            }
            return Response::json(['error' => 'Project, target team membership, or management permission is invalid.'], 404);
        }
        return Response::json(['membership_id' => $id, 'user_id' => $targetUserId, 'access_level' => $accessLevel, 'status' => 'pending'], 201);
    }

    public function approve(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $targetUserId = $this->positiveId($params['userId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $targetUserId === null) {
            return Response::json(['error' => 'Project membership not found.'], 404);
        }

        try {
            $this->memberships->approve($user['id'], $projectId, $targetUserId);
        } catch (DomainException) {
            return Response::json(['error' => 'Project membership is not pending or management permission is denied.'], 404);
        }
        return Response::json(['approved' => true, 'project_id' => $projectId, 'user_id' => $targetUserId]);
    }

    public function revoke(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $targetUserId = $this->positiveId($params['userId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $targetUserId === null) {
            return Response::json(['error' => 'Project membership not found.'], 404);
        }

        try {
            $this->memberships->revoke($user['id'], $projectId, $targetUserId);
        } catch (DomainException) {
            return Response::json(['error' => 'Project membership not found or cannot be revoked.'], 404);
        }
        return Response::json(['revoked' => true, 'project_id' => $projectId, 'user_id' => $targetUserId]);
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

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }
}
