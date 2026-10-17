<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Database\ChatRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;

final class ChatController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly ProjectAccessService $projectAccess,
        private readonly ChatRepository $chat,
        private readonly ChatReportRepository $reports,
    ) {
    }

    public function listMessages(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project not found.'], 404);
        }

        $rawCursor = $request->query()['after_id'] ?? 0;
        $cursor = filter_var($rawCursor, FILTER_VALIDATE_INT);
        if (!is_int($cursor) || $cursor < 0) {
            return Response::json(['error' => 'after_id must be a non-negative integer.'], 422);
        }
        $messages = $this->chat->listMessages($user['id'], $projectId, $cursor);
        $nextCursor = $messages === [] ? $cursor : (int) $messages[count($messages) - 1]['id'];
        return Response::json(['messages' => $messages, 'after_id' => $nextCursor, 'limit' => 50]);
    }

    public function send(Request $request, array $params): Response
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

        $body = $request->body()['body'] ?? null;
        if (!is_string($body) || trim($body) === '' || strlen(trim($body)) > 2000 || preg_match('//u', trim($body)) !== 1) {
            return Response::json(['error' => 'Message must contain 1 to 2000 bytes.'], 422);
        }
        try {
            $messageId = $this->chat->createMessage($user['id'], $projectId, trim($body));
        } catch (DomainException) {
            return Response::json(['error' => 'Project not found or editable membership required.'], 404);
        }
        return Response::json(['message_id' => $messageId], 201);
    }

    public function edit(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $messageId = $this->positiveId($params['messageId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $messageId === null) {
            return Response::json(['error' => 'Message not found.'], 404);
        }

        $body = $request->body()['body'] ?? null;
        if (!is_string($body) || trim($body) === '' || strlen(trim($body)) > 2000 || preg_match('//u', trim($body)) !== 1) {
            return Response::json(['error' => 'Message must contain 1 to 2000 bytes.'], 422);
        }
        try {
            $this->chat->editMessage($user['id'], $projectId, $messageId, trim($body));
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'unresolved report')) {
                return Response::json(['error' => 'A message with an unresolved report cannot be edited.'], 409);
            }
            return Response::json(['error' => 'Message not found, not owned by this user, or outside the edit window.'], 404);
        }
        return Response::json(['edited' => true, 'message_id' => $messageId]);
    }

    public function delete(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $messageId = $this->positiveId($params['messageId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $messageId === null) {
            return Response::json(['error' => 'Message not found.'], 404);
        }
        try {
            $this->chat->deleteMessage($user['id'], $projectId, $messageId);
        } catch (DomainException) {
            return Response::json(['error' => 'Message not found or not owned by this user.'], 404);
        }
        return Response::json(['deleted' => true, 'message_id' => $messageId]);
    }

    public function report(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        $messageId = $this->positiveId($params['messageId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $messageId === null) {
            return Response::json(['error' => 'Message not found.'], 404);
        }

        $reason = $request->body()['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || strlen(trim($reason)) > 500 || preg_match('//u', trim($reason)) !== 1) {
            return Response::json(['error' => 'Report reason must contain 1 to 500 bytes.'], 422);
        }
        try {
            $report = $this->reports->create($user['id'], $projectId, $messageId, trim($reason));
        } catch (DomainException $error) {
            if (str_contains($error->getMessage(), 'already submitted')) {
                return Response::json(['error' => 'You have already reported this person today.'], 409);
            }
            if (str_contains($error->getMessage(), 'own messages')) {
                return Response::json(['error' => 'You cannot report your own message.'], 422);
            }
            return Response::json(['error' => 'Message not found or report access denied.'], 404);
        }
        return Response::json(['report' => $report, 'notice' => 'A report is an allegation and will be reviewed; it is not proof of wrongdoing.'], 201);
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
