<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;

require dirname(__DIR__) . '/app/bootstrap.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo "PASS: {$message}\n";
};

$router = new Router();
$router->get('/projects/{projectId}', static function (Request $request, array $params): Response {
    return Response::text((string) $params['projectId']);
});

ob_start();
$router->dispatch(new Request('GET', '/projects/alpha'))->send();
$body = ob_get_clean();
$assert($body === 'alpha', 'named route parameter is dispatched');

ob_start();
$notFound = $router->dispatch(new Request('POST', '/projects/alpha'));
$notFound->send();
$body = ob_get_clean();
$assert($body === 'Not Found' && http_response_code() === 404, 'wrong HTTP method does not match route');

ob_start();
$encodedSlash = $router->dispatch(new Request('GET', '/projects/a%2Fb'));
$encodedSlash->send();
$body = ob_get_clean();
$assert($body === 'Not Found', 'encoded slash is rejected in a path parameter');

$registry = new ModuleRegistry();
$buildCount = 0;
$registry->register('example', static function () use (&$buildCount): object {
    $buildCount++;
    return new stdClass();
});
$assert($registry->has('example'), 'registered module is discoverable');
$first = $registry->get('example');
$second = $registry->get('example');
$assert($first === $second && $buildCount === 1, 'module factory is lazy and instantiated once');

$duplicateRejected = false;
try {
    $registry->register('example', static fn (): object => new stdClass());
} catch (LogicException) {
    $duplicateRejected = true;
}
$assert($duplicateRejected, 'duplicate module registration is rejected');

$unknownRejected = false;
try {
    $registry->get('missing');
} catch (LogicException) {
    $unknownRejected = true;
}
$assert($unknownRejected, 'unknown module lookup fails closed');

echo "\n{$passed} assertions passed.\n";
