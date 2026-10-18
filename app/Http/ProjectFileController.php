<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use DomainException;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\ProjectFileRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;

final class ProjectFileController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly ProjectAccessService $projectAccess,
        private readonly ProjectFileRepository $files,
    ) {
    }

    /** List the bounded source-file directory for approved project members and viewers. */
    public function index(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        $projectId = $this->positiveId($params['projectId'] ?? null);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project not found.'], 404);
        }

        try {
            return Response::json($this->files->listForUser($user['id'], $projectId));
        } catch (DomainException) {
            return Response::json(['error' => 'Project not found.'], 404);
        }
    }

    /** Create a new UTF-8 text source file for approved member-level users. */
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
        $path = $body['path'] ?? null;
        $content = $body['content'] ?? '';
        if (!is_string($path) || !is_string($content)) {
            return Response::json(['error' => 'File path and text content are required.'], 422);
        }

        try {
            $fileId = $this->files->create($user['id'], $projectId, $path, $content);
            return Response::json([
                'created' => true,
                'file' => $this->files->findForUser($user['id'], $projectId, $fileId),
            ], 201);
        } catch (DomainException $error) {
            return self::domainError($error);
        }
    }

    /** Read a source file; approved viewers can inspect content but cannot mutate it. */
    public function show(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        [$projectId, $fileId] = $this->resourceIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $fileId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project file not found.'], 404);
        }

        try {
            return Response::json(['file' => $this->files->findForUser($user['id'], $projectId, $fileId)]);
        } catch (DomainException) {
            return Response::json(['error' => 'Project file not found.'], 404);
        }
    }

    /** Save a new revision with optimistic concurrency protection. */
    public function update(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        [$projectId, $fileId] = $this->resourceIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $fileId === null) {
            return Response::json(['error' => 'Project file not found.'], 404);
        }

        $body = $request->body();
        $content = $body['content'] ?? null;
        $expectedVersion = $this->positiveId($body['expected_version'] ?? null);
        if (!is_string($content) || $expectedVersion === null) {
            return Response::json(['error' => 'Text content and a positive expected_version are required.'], 422);
        }

        try {
            return Response::json($this->files->update($user['id'], $projectId, $fileId, $expectedVersion, $content));
        } catch (DomainException $error) {
            return self::domainError($error);
        }
    }

    /** Return at most 20 revision metadata records, not the historical source contents. */
    public function history(Request $request, array $params): Response
    {
        $user = $this->currentUser();
        [$projectId, $fileId] = $this->resourceIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $fileId === null || !$this->projectAccess->canView($user['id'], $projectId)) {
            return Response::json(['error' => 'Project file not found.'], 404);
        }

        try {
            return Response::json([
                'file_id' => $fileId,
                'versions' => $this->files->historyForUser($user['id'], $projectId, $fileId),
            ]);
        } catch (DomainException) {
            return Response::json(['error' => 'Project file not found.'], 404);
        }
    }

    /** Restore a retained revision as a new version without overwriting revision history. */
    public function restore(Request $request, array $params): Response
    {
        SecureSession::start();
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid or expired request token.'], 419);
        }
        $user = $this->authentication->currentUser();
        [$projectId, $fileId] = $this->resourceIds($params);
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if ($projectId === null || $fileId === null) {
            return Response::json(['error' => 'Project file not found.'], 404);
        }

        $body = $request->body();
        $version = $this->positiveId($body['version'] ?? null);
        $expectedVersion = $this->positiveId($body['expected_version'] ?? null);
        if ($version === null || $expectedVersion === null) {
            return Response::json(['error' => 'A positive version and expected_version are required.'], 422);
        }

        try {
            return Response::json($this->files->restore($user['id'], $projectId, $fileId, $version, $expectedVersion));
        } catch (DomainException $error) {
            return self::domainError($error);
        }
    }

    /** @return array{?int,?int} */
    private function resourceIds(array $params): array
    {
        return [
            $this->positiveId($params['projectId'] ?? null),
            $this->positiveId($params['fileId'] ?? null),
        ];
    }

    private static function domainError(DomainException $error): Response
    {
        $message = $error->getMessage();
        if (str_contains($message, 'version conflict')) {
            return Response::json(['error' => $message], 409);
        }
        if (str_contains($message, 'already exists') || str_contains($message, 'quota reached')) {
            return Response::json(['error' => $message], 409);
        }
        if (str_contains($message, 'File path') || str_contains($message, 'reserved')
            || str_contains($message, 'Source file') || str_contains($message, 'cannot exceed')
            || str_contains($message, 'positive integer')) {
            return Response::json(['error' => $message], 422);
        }
        return Response::json(['error' => 'Project file not found or member-level edit access is required.'], 404);
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
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }
}
