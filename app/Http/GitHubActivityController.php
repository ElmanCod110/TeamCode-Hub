<?php

declare(strict_types=1);

namespace TeamCodeHub\Http;

use TeamCodeHub\Core\Response;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Services\GitHubActivityService;

final class GitHubActivityController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly GitHubActivityService $activity,
    ) {
    }

    public function index(): Response
    {
        SecureSession::start();
        if ($this->authentication->currentUser() === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        return Response::json(['activity' => $this->activity->activity()]);
    }
}
