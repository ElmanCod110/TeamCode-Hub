<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\TaskRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;

final class TaskController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly ProjectAccessService $projectAccess,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function listTasks(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project not found.'], 404);
        }
        return Response::json(['tasks' => $this->tasks->listAccessibleByUser($user['id'], $projectId)]);
    }

    public function create(Request $request, array $params): Response
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
        $title = $body['title'] ?? null;
        $description = $body['description'] ?? null;
        $priority = $body['priority'] ?? 'normal';
        $assignedToRaw = $body['assigned_to'] ?? null;
        $dueRaw = $body['due_at'] ?? null;

        if (!is_string($title) || trim($title) === '' || strlen(trim($title)) > 160) {
            return Response::json(['error' => 'Task title must contain 1 to 160 bytes.'], 422);
        }
        if ($description !== null && (!is_string($description) || strlen($description) > 10000)) {
            return Response::json(['error' => 'Task description must be at most 10000 bytes.'], 422);
        }
        if (!is_string($priority) || !in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            return Response::json(['error' => 'Invalid task priority.'], 422);
        }

        $assignedTo = $assignedToRaw === null || $assignedToRaw === '' ? null : $this->positiveId($assignedToRaw);
        if ($assignedToRaw !== null && $assignedToRaw !== '' && $assignedTo === null) {
            return Response::json(['error' => 'assigned_to must be a positive user ID or null.'], 422);
        }

        $dueAt = null;
        if ($dueRaw !== null && $dueRaw !== '') {
            if (!is_string($dueRaw)) {
                return Response::json(['error' => 'due_at must use UTC YYYY-MM-DD HH:MM:SS format.'], 422);
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dueRaw, new DateTimeZone('UTC'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $parsed->format('Y-m-d H:i:s') !== $dueRaw) {
                return Response::json(['error' => 'due_at must use UTC YYYY-MM-DD HH:MM:SS format.'], 422);
            }
            $dueAt = $parsed->format('Y-m-d H:i:s');
        }

        try {
            $taskId = $this->tasks->create($user['id'], $projectId, trim($title), $description, $priority, $assignedTo, $dueAt);
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'Assignee')) {
                return Response::json(['error' => 'Assignee must have approved member-level project access.'], 422);
            }
            return Response::json(['error' => 'Project not found or editable membership required.'], 404);
        }

        return Response::json(['task_id' => $taskId, 'project_id' => $projectId, 'status' => 'todo'], 201);
    }

    public function updateStatus(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $taskId = $this->positiveId($params['taskId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $taskId === null) {
            return Response::json(['error' => 'Task not found.'], 404);
        }

        $status = $request->body()['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['todo', 'in_progress', 'blocked', 'done'], true)) {
            return Response::json(['error' => 'Status must be todo, in_progress, blocked, or done.'], 422);
        }

        try {
            $this->tasks->updateStatus($user['id'], $projectId, $taskId, $status);
        } catch (DomainException) {
            return Response::json(['error' => 'Task not found or editable membership required.'], 404);
        }
        return Response::json(['updated' => true, 'task_id' => $taskId, 'status' => $status]);
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
