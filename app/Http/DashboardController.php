<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\DashboardRepository;
use TeamCodeHub\Database\NotificationRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\SecureSession;

final class DashboardController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly DashboardRepository $dashboard,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function overview(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $data = $this->dashboard->overview($user['id']);
        $unread = $this->notifications->unreadCount($user['id']);
        $data['recent_notifications'] = $this->notifications->listRecentForUser($user['id'], 8);
        $data['counts'] = [
            'teams' => count($data['teams']),
            'projects' => count($data['projects']),
            'tasks_due_today' => count($data['tasks_due_today']),
            'overdue_tasks' => count($data['overdue_tasks']),
            'unread_notifications' => $unread,
        ];

        return Response::json(['dashboard' => $data]);
    }

    public function listNotifications(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $query = $request->query();
        $rawLimit = $query['limit'] ?? 20;
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);
        $limit = is_int($limit) ? max(1, min(50, $limit)) : 20;

        return Response::json([
            'notifications' => $this->notifications->listRecentForUser($user['id'], $limit),
            'unread_count' => $this->notifications->unreadCount($user['id']),
        ]);
    }

    public function markRead(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        $notificationId = $this->positiveId($params['notificationId'] ?? null);
        if ($notificationId === null || !$this->notifications->markRead($user['id'], $notificationId)) {
            return Response::json(['error' => 'Notification not found.'], 404);
        }
        return Response::json(['read' => true, 'unread_count' => $this->notifications->unreadCount($user['id'])]);
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
