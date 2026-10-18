<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;
use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\AdminAccountRepository;
use TeamCodeHub\Database\ChatRepository;
use TeamCodeHub\Database\DashboardRepository;
use TeamCodeHub\Database\GitHubActivityCacheRepository;
use TeamCodeHub\Database\GitHubRepositoryCacheRepository;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\EmailVerificationRepository;
use TeamCodeHub\Database\MembershipRepository;
use TeamCodeHub\Database\NotificationRepository;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Database\PasswordResetRepository;
use TeamCodeHub\Database\ProjectMembershipRepository;
use TeamCodeHub\Database\TeamRepository;
use TeamCodeHub\Database\TaskRepository;
use TeamCodeHub\Database\TaskCollaborationRepository;
use TeamCodeHub\Database\TeamInvitationRepository;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Http\AdminUserController;
use TeamCodeHub\Http\AuthController;
use TeamCodeHub\Http\AccountLifecycleController;
use TeamCodeHub\Http\PasswordRecoveryController;
use TeamCodeHub\Http\ChatController;
use TeamCodeHub\Http\ChatReportController;
use TeamCodeHub\Http\DashboardController;
use TeamCodeHub\Http\GitHubActivityController;
use TeamCodeHub\Http\ProjectGitHubController;
use TeamCodeHub\Http\WorkspaceController;
use TeamCodeHub\Http\TaskController;
use TeamCodeHub\Http\TaskCollaborationController;
use TeamCodeHub\Http\ProjectMembershipController;
use TeamCodeHub\Http\TeamInvitationController;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;
use TeamCodeHub\Services\GitHubActivityService;
use TeamCodeHub\Services\AccountLifecycleService;
use TeamCodeHub\Services\EmailDeliveryService;
use TeamCodeHub\Services\PasswordRecoveryService;
use TeamCodeHub\Services\GitHubRepositoryService;

spl_autoload_register(static function (string $class): void {
    $prefix = 'TeamCodeHub\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

return static function (): void {
    $router = new Router();
    $modules = new ModuleRegistry();

    // Database-backed components are constructed only when their routes need them.
    $modules->register('auth.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        return new AuthController(
            new AuthenticationService(new UserRepository($pdo)),
            new LoginRateLimiter($pdo),
        );
    });

    $modules->register('account-lifecycle.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $users = new UserRepository($pdo);
        return new AccountLifecycleController(
            new AccountLifecycleService($users, new EmailVerificationRepository($pdo), new EmailDeliveryService()),
            new LoginRateLimiter($pdo),
        );
    });

    $modules->register('password-recovery.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $users = new UserRepository($pdo);
        return new PasswordRecoveryController(
            new PasswordRecoveryService($users, new PasswordResetRepository($pdo), new EmailDeliveryService()),
            new LoginRateLimiter($pdo),
        );
    });

    $modules->register('team-invitation.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $users = new UserRepository($pdo);
        $memberships = new MembershipRepository($pdo);
        return new TeamInvitationController(
            new AuthenticationService($users),
            new TeamAccessService($memberships),
            new TeamInvitationRepository($pdo),
        );
    });

    $modules->register('project-membership.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        return new ProjectMembershipController(
            new AuthenticationService(new UserRepository($pdo)),
            new ProjectMembershipRepository($pdo),
        );
    });

    $modules->register('chat.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $memberships = new MembershipRepository($pdo);
        return new ChatController(
            new AuthenticationService(new UserRepository($pdo)),
            new ProjectAccessService($memberships),
            new ChatRepository($pdo),
            new ChatReportRepository($pdo),
        );
    });

    $modules->register('chat-report.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        return new ChatReportController(
            new AuthenticationService(new UserRepository($pdo)),
            new ChatReportRepository($pdo),
        );
    });

    $modules->register('admin-user.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $users = new UserRepository($pdo);
        return new AdminUserController(new AuthenticationService($users), $users, new AdminAccountRepository($pdo));
    });

    $modules->register('project-github.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $memberships = new MembershipRepository($pdo);
        return new ProjectGitHubController(
            new AuthenticationService(new UserRepository($pdo)),
            new ProjectRepository($pdo),
            new ProjectAccessService($memberships),
            new TeamAccessService($memberships),
            new GitHubRepositoryService(new GitHubRepositoryCacheRepository($pdo)),
        );
    });

    $modules->register('github-activity.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $configuredUsername = getenv('GITHUB_USERNAME');
        $username = is_string($configuredUsername) && trim($configuredUsername) !== ''
            ? trim($configuredUsername)
            : 'ElmanCod110';
        return new GitHubActivityController(
            new AuthenticationService(new UserRepository($pdo)),
            new GitHubActivityService($username, new GitHubActivityCacheRepository($pdo)),
        );
    });

    $modules->register('dashboard.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        return new DashboardController(
            new AuthenticationService(new UserRepository($pdo)),
            new DashboardRepository($pdo),
            new NotificationRepository($pdo),
        );
    });

    $modules->register('task.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $memberships = new MembershipRepository($pdo);
        return new TaskController(
            new AuthenticationService(new UserRepository($pdo)),
            new ProjectAccessService($memberships),
            new TaskRepository($pdo),
        );
    });

    $modules->register('task-collaboration.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        return new TaskCollaborationController(
            new AuthenticationService(new UserRepository($pdo)),
            new TaskCollaborationRepository($pdo),
        );
    });

    $modules->register('workspace.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $users = new UserRepository($pdo);
        $memberships = new MembershipRepository($pdo);

        return new WorkspaceController(
            new AuthenticationService($users),
            new TeamAccessService($memberships),
            new ProjectAccessService($memberships),
            new TeamRepository($pdo),
            new ProjectRepository($pdo),
        );
    });

    // Serve the product shell through the front controller so pretty URLs share one entry point.
    $appPage = static function (): Response {
        $path = dirname(__DIR__) . '/public/app.html';
        $html = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($html)) {
            return Response::text('Workspace application is unavailable.', 500);
        }
        return Response::html($html);
    };

    $router->get('/', static fn (Request $request, array $params): Response => $appPage());
    $router->get('/login', static fn (Request $request, array $params): Response => $appPage());
    $router->get('/verify-email', static fn (Request $request, array $params): Response => $appPage());
    $router->get('/reset-password', static fn (Request $request, array $params): Response => $appPage());

    $router->get('/health', static fn (Request $request, array $params): Response => Response::json([
        'status' => 'ok',
        'checks' => ['router' => 'ok', 'module_registry' => 'ok'],
    ]));

    $router->get('/api/projects/{projectId}/chat', static fn (Request $request, array $params): Response =>
        $modules->get('chat.controller')->listMessages($request, $params)
    );
    $router->post('/api/projects/{projectId}/chat', static fn (Request $request, array $params): Response =>
        $modules->get('chat.controller')->send($request, $params)
    );
    $router->post('/api/projects/{projectId}/chat/{messageId}/edit', static fn (Request $request, array $params): Response =>
        $modules->get('chat.controller')->edit($request, $params)
    );
    $router->post('/api/projects/{projectId}/chat/{messageId}/delete', static fn (Request $request, array $params): Response =>
        $modules->get('chat.controller')->delete($request, $params)
    );
    $router->post('/api/projects/{projectId}/chat/{messageId}/report', static fn (Request $request, array $params): Response =>
        $modules->get('chat.controller')->report($request, $params)
    );
    $router->get('/api/teams/{teamId}/reports', static fn (Request $request, array $params): Response =>
        $modules->get('chat-report.controller')->teamQueue($request, $params)
    );
    $router->get('/api/admin/reports', static fn (Request $request, array $params): Response =>
        $modules->get('chat-report.controller')->adminQueue($request)
    );
    $router->get('/api/admin/users', static fn (Request $request, array $params): Response =>
        $modules->get('admin-user.controller')->index($request)
    );
    $router->post('/api/admin/users/{userId}/status', static fn (Request $request, array $params): Response =>
        $modules->get('admin-user.controller')->changeStatus($request, $params)
    );
    $router->get('/api/admin/users/{userId}/audit', static fn (Request $request, array $params): Response =>
        $modules->get('admin-user.controller')->audit($request, $params)
    );
    $router->post('/api/reports/{reportId}/review', static fn (Request $request, array $params): Response =>
        $modules->get('chat-report.controller')->review($request, $params)
    );

    $router->get('/api/github/activity', static fn (Request $request, array $params): Response =>
        $modules->get('github-activity.controller')->index()
    );

    $router->get('/api/dashboard', static fn (Request $request, array $params): Response =>
        $modules->get('dashboard.controller')->overview($request)
    );
    $router->get('/api/notifications', static fn (Request $request, array $params): Response =>
        $modules->get('dashboard.controller')->listNotifications($request)
    );
    $router->post('/api/notifications/{notificationId}/read', static fn (Request $request, array $params): Response =>
        $modules->get('dashboard.controller')->markRead($request, $params)
    );

    $router->get('/api/auth/csrf', static function (Request $request, array $params): Response {
        SecureSession::start();
        return Response::json(['csrf_token' => CsrfToken::get()]);
    });
    $router->post('/api/auth/register', static fn (Request $request, array $params): Response =>
        $modules->get('account-lifecycle.controller')->register($request)
    );
    $router->post('/api/auth/verification/resend', static fn (Request $request, array $params): Response =>
        $modules->get('account-lifecycle.controller')->resendVerification($request)
    );
    $router->post('/api/auth/verify-email', static fn (Request $request, array $params): Response =>
        $modules->get('account-lifecycle.controller')->verifyEmail($request)
    );
    $router->post('/api/auth/password-reset/request', static fn (Request $request, array $params): Response =>
        $modules->get('password-recovery.controller')->requestReset($request)
    );
    $router->post('/api/auth/password-reset/confirm', static fn (Request $request, array $params): Response =>
        $modules->get('password-recovery.controller')->confirmReset($request)
    );
    $router->post('/api/auth/login', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->login($request)
    );
    $router->get('/api/auth/me', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->me()
    );
    $router->post('/api/auth/profile', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->updateProfile($request)
    );
    $router->post('/api/auth/password', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->changePassword($request)
    );
    $router->post('/api/auth/logout', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->logout($request)
    );

    $router->get('/api/teams', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->listTeams()
    );
    $router->post('/api/teams', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->createTeam($request)
    );
    $router->post('/api/teams/{teamId}/edit', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->updateTeam($request, $params)
    );
    $router->get('/api/teams/{teamId}', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->showTeam($request, $params)
    );
    $router->get('/api/teams/{teamId}/projects', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->listProjects($request, $params)
    );
    $router->get('/api/teams/{teamId}/archived-projects', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->listArchivedProjects($request, $params)
    );
    $router->post('/api/teams/{teamId}/projects', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->createProject($request, $params)
    );
    $router->get('/api/projects/{projectId}', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->showProject($request, $params)
    );
    $router->get('/api/projects/{projectId}/github', static fn (Request $request, array $params): Response =>
        $modules->get('project-github.controller')->show($request, $params)
    );
    $router->post('/api/projects/{projectId}/github', static fn (Request $request, array $params): Response =>
        $modules->get('project-github.controller')->updateLink($request, $params)
    );
    $router->post('/api/projects/{projectId}/archive', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->archiveProject($request, $params)
    );
    $router->post('/api/projects/{projectId}/restore', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->restoreProject($request, $params)
    );
    $router->post('/api/projects/{projectId}/edit', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->updateProject($request, $params)
    );

    $router->get('/api/projects/{projectId}/tasks', static fn (Request $request, array $params): Response =>
        $modules->get('task.controller')->listTasks($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks', static fn (Request $request, array $params): Response =>
        $modules->get('task.controller')->create($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/status', static fn (Request $request, array $params): Response =>
        $modules->get('task.controller')->updateStatus($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/edit', static fn (Request $request, array $params): Response =>
        $modules->get('task.controller')->edit($request, $params)
    );
    $router->get('/api/projects/{projectId}/tasks/{taskId}/details', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->details($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/comments', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->createComment($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/comments/{commentId}/edit', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->editComment($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/comments/{commentId}/delete', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->deleteComment($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/subtasks', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->createSubtask($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/subtasks/{subtaskId}/toggle', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->toggleSubtask($request, $params)
    );
    $router->post('/api/projects/{projectId}/tasks/{taskId}/subtasks/{subtaskId}/delete', static fn (Request $request, array $params): Response =>
        $modules->get('task-collaboration.controller')->deleteSubtask($request, $params)
    );

    $router->get('/api/projects/{projectId}/members', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->list($request, $params)
    );
    $router->get('/api/projects/{projectId}/members/candidates', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->candidates($request, $params)
    );
    $router->get('/api/projects/{projectId}/assignees', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->assignees($request, $params)
    );
    $router->post('/api/projects/{projectId}/members', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->assign($request, $params)
    );
    $router->post('/api/projects/{projectId}/members/{userId}/approve', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->approve($request, $params)
    );
    $router->post('/api/projects/{projectId}/members/{userId}/revoke', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->revoke($request, $params)
    );
    $router->post('/api/projects/{projectId}/members/{userId}/access', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->changeAccessLevel($request, $params)
    );

    $router->get('/api/teams/{teamId}/invitations', static fn (Request $request, array $params): Response =>
        $modules->get('team-invitation.controller')->listPending($request, $params)
    );
    $router->post('/api/teams/{teamId}/invitations', static fn (Request $request, array $params): Response =>
        $modules->get('team-invitation.controller')->create($request, $params)
    );
    $router->add('DELETE', '/api/teams/{teamId}/invitations/{invitationId}', static fn (Request $request, array $params): Response =>
        $modules->get('team-invitation.controller')->revoke($request, $params)
    );
    $router->post('/api/team-invitations/accept', static fn (Request $request, array $params): Response =>
        $modules->get('team-invitation.controller')->accept($request)
    );

    $response = $router->dispatch(Request::fromGlobals());
    $response->send();
};
