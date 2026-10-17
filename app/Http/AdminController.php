<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\AdminRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\SecureSession;

/**
 * Site-wide, read-only administration endpoints.
 *
 * This controller intentionally does not grant a site administrator access to
 * ordinary team or project resources.
 */
final class AdminController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly AdminRepository $admin,
    ) {
    }

    public function overview(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        try {
            return Response::json(['overview' => $this->admin->overview($user['id'])]);
        } catch (DomainException) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }
    }

    public function listUsers(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $query = $request->query();
        $rawLimit = $query['limit'] ?? 20;
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 50) {
            return Response::json(['error' => 'Limit must be an integer between 1 and 50.'], 422);
        }

        $beforeId = null;
        if (isset($query['before_id'])) {
            $beforeId = filter_var($query['before_id'], FILTER_VALIDATE_INT);
            if ($beforeId === false || $beforeId < 1) {
                return Response::json(['error' => 'Cursor must be a positive integer.'], 422);
            }
        }

        $search = $query['q'] ?? '';
        if (!is_string($search) || strlen($search) > 100) {
            return Response::json(['error' => 'Search must be at most 100 bytes.'], 422);
        }

        try {
            return Response::json($this->admin->listUsers($user['id'], $limit, $beforeId, trim($search)));
        } catch (DomainException) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }
    }

    /** @return array{id:int,email:string,status:string,display_name:string,roles:list<string>}|null */
    private function currentUser(): ?array
    {
        SecureSession::start();
        return $this->authentication->currentUser();
    }
}
