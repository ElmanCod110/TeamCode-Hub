<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;
use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Http\AuthController;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\ClientIpResolver;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\SecureSession;

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

    // Database-backed components are constructed only when an auth endpoint needs them.
    $modules->register('auth.controller', static function (): object {
        $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        return new AuthController(
            new AuthenticationService(new UserRepository($pdo)),
            new LoginRateLimiter($pdo),
        );
    });

    $router->get('/', static fn (Request $request, array $params): Response => Response::json([
        'name' => 'TeamCode Hub',
        'version' => '0.1.0-dev',
        'status' => 'foundation',
        'message' => 'Core and authentication endpoints are available; product features remain in development.',
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

    $response = $router->dispatch(Request::fromGlobals());
    $response->send();
};
