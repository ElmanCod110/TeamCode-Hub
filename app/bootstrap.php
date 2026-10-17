<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;
use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\MembershipRepository;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Database\TeamRepository;
use TeamCodeHub\Database\TeamInvitationRepository;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Http\AuthController;
use TeamCodeHub\Http\WorkspaceController;
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

    $router->get('/', static fn (Request $request, array $params): Response => Response::json([
        'name' => 'TeamCode Hub',
        'version' => '0.1.0-dev',
        'status' => 'foundation',
        'message' => 'Core, authentication, team, and project APIs are available; additional product features remain in development.',
    ]));

    $router->get('/health', static fn (Request $request, array $params): Response => Response::json([
        'status' => 'ok',
        'checks' => ['router' => 'ok', 'module_registry' => 'ok'],
    ]));

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

    $router->get('/api/teams/{teamId}/invitations', static fn (Request $request, array $params): Response =>
        $modules->get('team-invitation.controller')->list($request, $params)
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
