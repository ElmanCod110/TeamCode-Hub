<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\AdminAccountRepository;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\SecureSession;

final class AdminUserController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly UserRepository $users,
        private readonly AdminAccountRepository $accounts,
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
     * Enable or disable an account with an explicit reason and a durable audit event.
     * The repository repeats role and transition checks inside its transaction.
     */
    public function changeStatus(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }

        $user = $this->authentication->currentUser();
        $targetUserId = $this->positiveId($params['userId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($targetUserId === null) {
            return Response::json(['error' => 'Target account not found.'], 404);
        }

        $body = $request->body();
        $status = $body['status'] ?? null;
        $reason = $body['reason'] ?? null;
        if (!is_string($status) || !in_array($status, ['active', 'disabled'], true)) {
            return Response::json(['error' => 'Status must be active or disabled.'], 422);
        }
        if (!is_string($reason) || strlen(trim($reason)) < 5 || strlen(trim($reason)) > 500
            || preg_match('//u', trim($reason)) !== 1) {
            return Response::json(['error' => 'Provide an audit reason of 5 to 500 UTF-8 bytes.'], 422);
        }

        try {
            $result = $this->accounts->changeStatusForSiteAdmin(
                $user['id'],
                $targetUserId,
                $status,
                trim($reason),
            );
        } catch (OutOfBoundsException) {
            return Response::json(['error' => 'Target account not found.'], 404);
        } catch (DomainException $error) {
            $statusCode = $error->getMessage() === 'Site administrator role required.' ? 403 : 409;
            return Response::json(['error' => $error->getMessage()], $statusCode);
        }

        return Response::json($result);
    }

    /**
     * Show the bounded audit history for a single account to active site administrators.
     */
    public function audit(Request $request, array $params): Response
    {
        SecureSession::start();
        $user = $this->authentication->currentUser();
        $targetUserId = $this->positiveId($params['userId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($targetUserId === null) {
            return Response::json(['error' => 'Target account not found.'], 404);
        }

        try {
            $events = $this->accounts->listRecentAuditForSiteAdmin($user['id'], $targetUserId, 20);
        } catch (OutOfBoundsException) {
            return Response::json(['error' => 'Target account not found.'], 404);
        } catch (DomainException) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }

        return Response::json(['target_user_id' => $targetUserId, 'events' => $events]);
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
