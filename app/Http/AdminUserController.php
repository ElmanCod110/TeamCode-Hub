<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\SecureSession;

final class AdminUserController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Return a bounded, searchable user directory to site administrators.
     * The repository independently enforces the same role boundary.
     */
    public function index(Request $request): Response
    {
        SecureSession::start();
        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $query = $request->query()['q'] ?? '';
        $status = $request->query()['status'] ?? 'all';
        $pageInput = $request->query()['page'] ?? '1';
        if (!is_string($query) || strlen($query) > 100 || preg_match('//u', $query) !== 1) {
            return Response::json(['error' => 'Search text must be valid UTF-8 and at most 100 bytes.'], 422);
        }
        if (!is_string($status) || !in_array($status, ['all', 'active', 'pending', 'disabled'], true)) {
            return Response::json(['error' => 'Status must be all, active, pending, or disabled.'], 422);
        }
        if ((!is_string($pageInput) && !is_int($pageInput)) || !ctype_digit((string) $pageInput)
            || (int) $pageInput < 1 || (int) $pageInput > 10000) {
            return Response::json(['error' => 'Page must be an integer between 1 and 10000.'], 422);
        }
        $page = (int) $pageInput;

        try {
            $result = $this->users->searchForSiteAdmin($user['id'], trim($query), $status, $page);
        } catch (DomainException) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }

        return Response::json($result + [
            'query' => trim($query),
            'status' => $status,
            'limit' => 50,
            'page' => $page,
        ]);
    }
}
