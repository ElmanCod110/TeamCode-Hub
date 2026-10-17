<?php

declare(strict_types=1);

use TeamCodeHub\Core\ModuleRegistry;
use TeamCodeHub\Core\Request;
use TeamCodeHub\Core\Response;
use TeamCodeHub\Core\Router;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Security\PermissionChecker;
use TeamCodeHub\Security\CsrfToken;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\ClientIpResolver;

require dirname(__DIR__) . '/app/bootstrap.php';

// Keep output buffered so session startup can be exercised in CLI tests.
ob_start();

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
$assert($body === 'Not Found' && $notFound->statusCode() === 404, 'wrong HTTP method returns a 404 response');

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

$db = new DatabaseConfig('localhost', 3306, 'teamcode_hub', 'app_user', 'test-secret');
$assert(
    $db->dsn() === 'mysql:host=localhost;port=3306;dbname=teamcode_hub;charset=utf8mb4',
    'database DSN uses MySQL and utf8mb4 without embedding credentials'
);

$unsafeDatabaseRejected = false;
try {
    new DatabaseConfig('localhost', 3306, 'db;DROP_TABLE', 'app_user', 'secret');
} catch (InvalidArgumentException) {
    $unsafeDatabaseRejected = true;
}
$assert($unsafeDatabaseRejected, 'unsafe database identifier is rejected');

$assert(ClientIpResolver::resolve('198.51.100.20', '203.0.113.9', ['10.0.0.2']) === '198.51.100.20', 'forwarded IP is ignored for an untrusted peer');
$assert(ClientIpResolver::resolve('10.0.0.2', '198.51.100.99, 203.0.113.10', ['10.0.0.2']) === '203.0.113.10', 'trusted proxy chain ignores spoofed left-side entries');

$grants = ['team:42:members:invite', 'project:8:read'];
$assert(PermissionChecker::allows($grants, 'team:42:members:invite'), 'exact scoped permission is granted');
$assert(!PermissionChecker::allows($grants, 'team:43:members:invite'), 'permission does not cross team scope');
$assert(!PermissionChecker::allows($grants, 'team:*:members:invite'), 'wildcard permissions are rejected');

SecureSession::start();
$csrf = CsrfToken::get();
$assert(strlen($csrf) === 64, 'CSRF token uses a 256-bit hexadecimal token');
$assert(CsrfToken::validate($csrf), 'valid CSRF token is accepted');
$assert(!CsrfToken::validate('wrong-token'), 'invalid CSRF token is rejected');
$rotated = CsrfToken::rotate();
$assert($rotated !== $csrf && CsrfToken::validate($rotated), 'CSRF rotation invalidates the previous token');
$assert(!CsrfToken::validate($csrf), 'previous CSRF token is rejected after rotation');
$assert(session_status() === PHP_SESSION_ACTIVE, 'secure session starts successfully');
SecureSession::destroy();
$assert(session_status() !== PHP_SESSION_ACTIVE, 'logout destroys the active session');

echo "\n{$passed} assertions passed.\n";
$output = ob_get_clean();
echo $output;
