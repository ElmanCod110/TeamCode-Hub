<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;

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

    $router->get('/', static fn (Request $request, array $params): Response => Response::json([
        'name' => 'TeamCode Hub',
        'version' => '0.1.0-dev',
        'status' => 'foundation',
        'message' => 'Application foundation is running. Product features are not yet available.',
    ]));

    $router->get('/health', static fn (Request $request, array $params): Response => Response::json([
        'status' => 'ok',
        'checks' => ['router' => 'ok', 'module_registry' => 'ok'],
    ]));

    $response = $router->dispatch(Request::fromGlobals());
    $response->send();
};
