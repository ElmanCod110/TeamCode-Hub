<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;
use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\ChatRepository;
use TeamCodeHub\Database\DashboardRepository;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\MembershipRepository;
use TeamCodeHub\Database\NotificationRepository;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Database\ProjectMembershipRepository;
use TeamCodeHub\Database\TeamRepository;
use TeamCodeHub\Database\TaskRepository;
use TeamCodeHub\Database\TeamInvitationRepository;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Http\AuthController;
use TeamCodeHub\Http\ChatController;
use TeamCodeHub\Http\ChatReportController;
use TeamCodeHub\Http\DashboardController;
use TeamCodeHub\Http\WorkspaceController;
use TeamCodeHub\Http\TaskController;
use TeamCodeHub\Http\ProjectMembershipController;
use TeamCodeHub\Http\TeamInvitationController;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;

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
    $router->post('/api/reports/{reportId}/review', static fn (Request $request, array $params): Response =>
        $modules->get('chat-report.controller')->review($request, $params)
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
    $router->post('/api/auth/login', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->login($request)
    );
    $router->get('/api/auth/me', static fn (Request $request, array $params): Response =>
        $modules->get('auth.controller')->me()
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
    $router->get('/api/teams/{teamId}', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->showTeam($request, $params)
    );
    $router->get('/api/teams/{teamId}/projects', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->listProjects($request, $params)
    );
    $router->post('/api/teams/{teamId}/projects', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->createProject($request, $params)
    );
    $router->get('/api/projects/{projectId}', static fn (Request $request, array $params): Response =>
        $modules->get('workspace.controller')->showProject($request, $params)
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

    $router->get('/api/projects/{projectId}/members', static fn (Request $request, array $params): Response =>
        $modules->get('project-membership.controller')->list($request, $params)
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
