<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;
use TeamCodeHub\Services\GitHubRepositoryService;

final class ProjectGitHubController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly ProjectRepository $projects,
        private readonly ProjectAccessService $projectAccess,
        private readonly TeamAccessService $teamAccess,
        private readonly GitHubRepositoryService $github,
    ) {
    }

    /** Return the shared cached public repository snapshot to approved project members. */
    public function show(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project not found.'], 404);
        }

        $project = $this->projects->findAccessibleByUser($user['id'], $projectId);
        if ($project === null) {
            return Response::json(['error' => 'Project not found.'], 404);
        }

        $canManage = $this->teamAccess->canManage($user['id'], (int) $project['team_id']);
        $repository = $project['github_repository'] ?? null;
        if (!is_string($repository) || $repository === '') {
            return Response::json(['linked' => false, 'can_manage' => $canManage, 'repository' => null]);
        }

        return Response::json([
            'linked' => true,
            'can_manage' => $canManage,
            'repository' => $this->github->repository($repository),
        ]);
    }

    /** Link or unlink a repository; only active team owners/managers may change this setting. */
    public function updateLink(Request $request, array $params): Response
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
            return Response::json(['error' => 'Project not found or management permission denied.'], 404);
        }

        $project = $this->projects->findAccessibleByUser($user['id'], $projectId);
        if ($project === null || !$this->teamAccess->canManage($user['id'], (int) $project['team_id'])) {
            return Response::json(['error' => 'Project not found or management permission denied.'], 404);
        }

        $body = $request->body();
        if (!array_key_exists('repository_url', $body)) {
            return Response::json(['error' => 'Provide repository_url as a public GitHub URL or null to unlink.'], 422);
        }
        $reference = $body['repository_url'];
        if ($reference !== null && !is_string($reference)) {
            return Response::json(['error' => 'Repository URL must be a string or null.'], 422);
        }
        $repository = null;
        if (is_string($reference) && trim($reference) !== '') {
            $repository = GitHubRepositoryService::parseRepositoryReference($reference);
            if ($repository === null) {
                return Response::json([
                    'error' => 'Use a direct HTTPS URL to a public repository on github.com, such as https://github.com/owner/repository.',
                ], 422);
            }
        }

        try {
            $this->projects->updateGitHubRepositoryForTeamManager($user['id'], $projectId, $repository);
        } catch (DomainException) {
            return Response::json(['error' => 'Project not found or management permission denied.'], 404);
        }

        $updated = $this->projects->findAccessibleByUser($user['id'], $projectId);
        return $updated === null
            ? Response::json(['error' => 'Project update could not be verified.'], 500)
            : Response::json([
                'updated' => true,
                'linked' => is_string($repository) && $repository !== '',
                'project' => $updated,
            ]);
    }

    private function currentUser(): ?array
    {
        SecureSession::start();
        return $this->authentication->currentUser();
    }

    private function validCsrf(Request $request): bool
    {
        $header = $request->header('X-Csrf-Token');
        $body = $request->body();
        $token = is_string($header) && $header !== '' ? $header : ($body['csrf_token'] ?? null);
        return is_string($token) && CsrfToken::validate($token);
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }
}
