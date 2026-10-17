<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Response;
use TeamCodeHub\Database\AdminRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\SecureSession;

/**
 * Expose read-only system metrics to explicitly assigned site administrators.
 * Workspace ownership and project membership never grant global administrative access.
 */
final class AdminController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly AdminRepository $admin,
    ) {
    }

    public function overview(): Response
    {
        SecureSession::start();
        $user = $this->authentication->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        if (!in_array('site_admin', $user['roles'] ?? [], true)) {
            return Response::json(['error' => 'Site administrator role required.'], 403);
        }

        return Response::json(['overview' => $this->admin->overview()]);
    }
}
