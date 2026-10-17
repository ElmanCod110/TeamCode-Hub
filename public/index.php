<?php

declare(strict_types=1);

$bootstrap = dirname(__DIR__) . '/app/bootstrap.php';

if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Application bootstrap is unavailable.';
    exit;
}

try {
    $run = require $bootstrap;
    if (!is_callable($run)) {
        throw new RuntimeException('Bootstrap did not return an application callable.');
    }
    $run();
} catch (Throwable $error) {
    error_log(sprintf('[TeamCode Hub] Unhandled exception: %s in %s:%d', $error::class, $error->getFile(), $error->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo 'Internal Server Error';
}
