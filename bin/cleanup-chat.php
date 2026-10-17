<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\DatabaseConfig;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $deleted = 0;
    do {
        $statement = $pdo->prepare(
            'DELETE FROM project_messages
             WHERE expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP
             ORDER BY id ASC LIMIT 5000'
        );
        $statement->execute();
        $batch = $statement->rowCount();
        $deleted += $batch;
    } while ($batch === 5000);

    fwrite(STDOUT, 'Expired chat messages removed: ' . $deleted . "\n");
    exit(0);
} catch (Throwable $error) {
    error_log('[TeamCode Hub] Chat cleanup failed: ' . $error::class . ': ' . $error->getMessage());
    fwrite(STDERR, "Chat cleanup failed. Check the restricted server error log.\n");
    exit(1);
}
