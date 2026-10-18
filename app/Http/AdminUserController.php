<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
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

    /**
     * Change an account between active and disabled states.
     * Pending accounts and system-role assignments are deliberately out of scope.
     */
    public function updateStatus(Request $request, array $params): Response
    {
        SecureSession::start();
        $token = $request->header('X-Csrf-Token');
        $body = $request->body();
        if ($token === null && isset($body['csrf_token']) && is_string($body['csrf_token'])) {
            $token = $body['csrf_token'];
        }
        if (!CsrfToken::validate($token)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if (!in_array('site_admin', $user['roles'] ?? [], true)) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }

        $userIdInput = $params['userId'] ?? null;
        $userId = is_string($userIdInput) && preg_match('/^[1-9][0-9]{0,17}$/', $userIdInput) === 1
            ? filter_var($userIdInput, FILTER_VALIDATE_INT)
            : false;
        if (!is_int($userId) || $userId < 1) {
            return Response::json(['error' => 'Target account was not found.'], 404);
        }

        $status = $body['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['active', 'disabled'], true)) {
            return Response::json(['error' => 'Status must be active or disabled.'], 422);
        }

        try {
            $result = $this->users->setStatusForSiteAdmin($user['id'], $userId, $status);
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'administrator role is required')) {
                return Response::json(['error' => 'Site administrator role required.'], 403);
            }
            if (str_contains($error->getMessage(), 'not found')) {
                return Response::json(['error' => 'Target account was not found.'], 404);
            }
            return Response::json(['error' => $error->getMessage()], 409);
        }

        return Response::json(['updated' => true, 'user' => $result]);
    }

}
