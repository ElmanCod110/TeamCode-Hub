<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\TaskCollaborationRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\SecureSession;

final class TaskCollaborationController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly TaskCollaborationRepository $collaboration,
    ) {
    }

    public function details(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null) {
            return Response::json(['error' => 'Task not found.'], 404);
        }

        try {
            return Response::json($this->collaboration->details($user['id'], $ids['project_id'], $ids['task_id']));
        } catch (DomainException) {
            return Response::json(['error' => 'Task not found or approved project access required.'], 404);
        }
    }

    public function createComment(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null) {
            return Response::json(['error' => 'Task not found.'], 404);
        }

        $body = $request->body()['body'] ?? null;
        if (!is_string($body) || trim($body) === '' || strlen(trim($body)) > 4000) {
            return Response::json(['error' => 'Comment text must contain 1 to 4000 bytes.'], 422);
        }

        try {
            $commentId = $this->collaboration->createComment($user['id'], $ids['project_id'], $ids['task_id'], trim($body));
            return Response::json(['created' => true, 'comment_id' => $commentId, 'task_id' => $ids['task_id']], 201);
        } catch (DomainException) {
            return Response::json(['error' => 'Task not found or editable member access required.'], 404);
        }
    }

    public function editComment(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        $commentId = $this->positiveId($params['commentId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null || $commentId === null) {
            return Response::json(['error' => 'Comment not found.'], 404);
        }
        $body = $request->body()['body'] ?? null;
        if (!is_string($body) || trim($body) === '' || strlen(trim($body)) > 4000) {
            return Response::json(['error' => 'Comment text must contain 1 to 4000 bytes.'], 422);
        }

        try {
            $this->collaboration->editComment($user['id'], $ids['project_id'], $ids['task_id'], $commentId, trim($body));
            return Response::json(['updated' => true, 'comment_id' => $commentId]);
        } catch (DomainException) {
            return Response::json(['error' => 'Comment not found, edit window expired, or editable member access required.'], 404);
        }
    }

    public function deleteComment(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        $commentId = $this->positiveId($params['commentId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null || $commentId === null) {
            return Response::json(['error' => 'Comment not found.'], 404);
        }

        try {
            $this->collaboration->deleteComment($user['id'], $ids['project_id'], $ids['task_id'], $commentId);
            return Response::json(['deleted' => true, 'comment_id' => $commentId]);
        } catch (DomainException) {
            return Response::json(['error' => 'Comment not found or editable member access required.'], 404);
        }
    }

    public function createSubtask(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null) {
            return Response::json(['error' => 'Task not found.'], 404);
        }
        $title = $request->body()['title'] ?? null;
        if (!is_string($title) || trim($title) === '' || strlen(trim($title)) > 160) {
            return Response::json(['error' => 'Checklist title must contain 1 to 160 bytes.'], 422);
        }

        try {
            $subtaskId = $this->collaboration->createSubtask($user['id'], $ids['project_id'], $ids['task_id'], trim($title));
            return Response::json(['created' => true, 'subtask_id' => $subtaskId, 'task_id' => $ids['task_id']], 201);
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), '50 checklist')) {
                return Response::json(['error' => $error->getMessage()], 409);
            }
            return Response::json(['error' => 'Task not found or editable member access required.'], 404);
        }
    }

    public function toggleSubtask(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        $subtaskId = $this->positiveId($params['subtaskId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null || $subtaskId === null) {
            return Response::json(['error' => 'Checklist item not found.'], 404);
        }

        try {
            $done = $this->collaboration->toggleSubtask($user['id'], $ids['project_id'], $ids['task_id'], $subtaskId);
            return Response::json(['updated' => true, 'subtask_id' => $subtaskId, 'is_done' => $done]);
        } catch (DomainException) {
            return Response::json(['error' => 'Checklist item not found or editable member access required.'], 404);
        }
    }

    public function deleteSubtask(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->currentUser();
        $ids = $this->routeIds($params);
        $subtaskId = $this->positiveId($params['subtaskId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($ids === null || $subtaskId === null) {
            return Response::json(['error' => 'Checklist item not found.'], 404);
        }

        try {
            $this->collaboration->deleteSubtask($user['id'], $ids['project_id'], $ids['task_id'], $subtaskId);
            return Response::json(['deleted' => true, 'subtask_id' => $subtaskId]);
        } catch (DomainException) {
            return Response::json(['error' => 'Checklist item not found or deletion is restricted to its creator or task creator.'], 404);
        }
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

    /** @return array{project_id:int,task_id:int}|null */
    private function routeIds(array $params): ?array
    {
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $taskId = $this->positiveId($params['taskId'] ?? null);
        if ($projectId === null || $taskId === null) {
            return null;
        }
        return ['project_id' => $projectId, 'task_id' => $taskId];
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
