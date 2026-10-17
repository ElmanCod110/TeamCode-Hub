<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\SecureSession;

final class ChatReportController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly ChatReportRepository $reports,
    ) {
    }

    public function teamQueue(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $teamId = $this->positiveId($params['teamId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($teamId === null) {
            return Response::json(['error' => 'Team not found.'], 404);
        }
        try {
            return Response::json(['reports' => $this->reports->listForTeamManager($user['id'], $teamId)]);
        } catch (DomainException) {
            return Response::json(['error' => 'Team not found or management permission denied.'], 404);
        }
    }

    public function adminQueue(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        try {
            return Response::json(['reports' => $this->reports->listForSiteAdmin($user['id'])]);
        } catch (DomainException) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }
    }

    public function review(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $reportId = $this->positiveId($params['reportId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($reportId === null) {
            return Response::json(['error' => 'Report not found.'], 404);
        }
        $action = $request->body()['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['valid', 'rejected', 'resolved'], true)) {
            return Response::json(['error' => 'Action must be valid, rejected, or resolved.'], 422);
        }
        try {
            $result = $this->reports->review($user['id'], $reportId, $action);
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'themselves') || str_contains($error->getMessage(), 'involved in a report')) {
                return Response::json(['error' => 'You cannot review a report against yourself.'], 403);
            }
            if (str_contains($error->getMessage(), 'Site administrator role')) {
                return Response::json(['error' => 'Site administrator role required.'], 403);
            }
            return Response::json(['error' => 'Report not found, already processed, or review permission denied.'], 404);
        }
        return Response::json(['report_id' => $reportId] + $result);
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
