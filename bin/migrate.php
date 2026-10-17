<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\MigrationRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $runner = new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
    $versions = $runner->migrate();

    if ($versions === []) {
        fwrite(STDOUT, "Database schema is already up to date.\n");
        exit(0);
    }
    foreach ($versions as $version) {
        fwrite(STDOUT, 'Applied migration: ' . $version . "\n");
    }
    exit(0);
} catch (Throwable $error) {
    error_log('[TeamCode Hub] Migration failed: ' . $error::class . ': ' . $error->getMessage());
    fwrite(STDERR, "Migration failed. Check server logs and database configuration.\n");
    exit(1);
}
