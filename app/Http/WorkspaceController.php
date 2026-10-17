<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\QuotaExceededException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Database\TeamRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;

final class WorkspaceController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly TeamAccessService $teamAccess,
        private readonly ProjectAccessService $projectAccess,
        private readonly TeamRepository $teams,
        private readonly ProjectRepository $projects,
    ) {
    }

    public function listTeams(): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        return Response::json(['teams' => $this->teams->listForUser($user['id'])]);
    }

    public function createTeam(Request $request): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $name = $request->body()['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 100) {
            return Response::json(['error' => 'Team name must contain 1 to 100 bytes.'], 422);
        }

        return Response::json(['team' => $this->teams->createForOwner($user['id'], trim($name))], 201);
    }

    /**
     * Update team settings after validating the request and current management role.
     */
    public function updateTeam(Request $request, array $params): Response
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

        $name = $request->body()['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 100) {
            return Response::json(['error' => 'Team name must contain 1 to 100 bytes.'], 422);
        }

        try {
            $this->teams->updateNameForManager($user['id'], $teamId, trim($name));
        } catch (DomainException) {
            return Response::json(['error' => 'Team not found or management permission denied.'], 404);
        }
        $team = $this->teams->findAccessibleByUser($teamId, $user['id']);
        return $team === null
            ? Response::json(['error' => 'Updated team could not be verified.'], 500)
            : Response::json(['team' => $team]);
    }

    public function showTeam(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $teamId = $this->positiveId($params['teamId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($teamId === null || !$this->teamAccess->canView($user['id'], $teamId)) {
            return Response::json(['error' => 'Team not found.'], 404);
        }

        $team = $this->teams->findAccessibleByUser($teamId, $user['id']);
        return $team === null
            ? Response::json(['error' => 'Team not found.'], 404)
            : Response::json(['team' => $team]);
    }

    public function listProjects(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $teamId = $this->positiveId($params['teamId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($teamId === null || !$this->teamAccess->canView($user['id'], $teamId)) {
            return Response::json(['error' => 'Team not found.'], 404);
        }

        return Response::json(['projects' => $this->projects->listAccessibleByUserInTeam($user['id'], $teamId)]);
    }

    public function createProject(Request $request, array $params): Response
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

        $body = $request->body();
        $name = $body['name'] ?? null;
        $description = $body['description'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 120) {
            return Response::json(['error' => 'Project name must contain 1 to 120 bytes.'], 422);
        }
        if ($description !== null && (!is_string($description) || strlen($description) > 5000)) {
            return Response::json(['error' => 'Project description must be at most 5000 bytes.'], 422);
        }

        try {
            $projectId = $this->projects->createForTeamManager($user['id'], $teamId, trim($name), $description);
        } catch (QuotaExceededException) {
            return Response::json(['error' => 'The team has reached its active project quota.'], 409);
        } catch (DomainException) {
            return Response::json(['error' => 'Team not found or management permission denied.'], 404);
        }

        $project = $this->projects->findAccessibleByUser($user['id'], $projectId);
        return $project === null
            ? Response::json(['error' => 'Project creation could not be verified.'], 500)
            : Response::json(['project' => $project], 201);
    }

    /**
     * Update project metadata; repository authorization re-checks the team role transactionally.
     */
    public function updateProject(Request $request, array $params): Response
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

        $body = $request->body();
        $name = $body['name'] ?? null;
        $description = $body['description'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 120) {
            return Response::json(['error' => 'Project name must contain 1 to 120 bytes.'], 422);
        }
        if ($description !== null && (!is_string($description) || strlen($description) > 5000)) {
            return Response::json(['error' => 'Project description must be at most 5000 bytes.'], 422);
        }

        try {
            $this->projects->updateForTeamManager(
                $user['id'],
                $projectId,
                trim($name),
                is_string($description) && trim($description) !== '' ? trim($description) : null,
            );
        } catch (DomainException) {
            return Response::json(['error' => 'Project not found or management permission denied.'], 404);
        }

        return Response::json([
            'updated' => true,
            'project_id' => $projectId,
            'name' => trim($name),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
        ]);
    }

    public function showProject(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project not found.'], 404);
        }

        // The repository repeats the approved-membership predicates in the data query to avoid relying on a prior check alone.
        $project = $this->projects->findAccessibleByUser($user['id'], $projectId);
        return $project === null
            ? Response::json(['error' => 'Project not found.'], 404)
            : Response::json(['project' => $project]);
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
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }
}
