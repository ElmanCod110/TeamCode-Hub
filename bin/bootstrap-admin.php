<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\DatabaseConfig;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim((string) getenv('TCH_ADMIN_EMAIL')));
$name = trim((string) getenv('TCH_ADMIN_NAME'));
$password = (string) getenv('TCH_ADMIN_PASSWORD');

try {
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        throw new RuntimeException('TCH_ADMIN_EMAIL must be a valid email address.');
    }
    if ($name === '' || strlen($name) > 80) {
        throw new RuntimeException('TCH_ADMIN_NAME must contain 1 to 80 bytes.');
    }

    $algorithms = password_algos();
    $useArgon2id = in_array('argon2id', $algorithms, true);
    $maximumPasswordBytes = $useArgon2id ? 4096 : 72;
    if (strlen($password) < 12 || strlen($password) > $maximumPasswordBytes) {
        throw new RuntimeException('TCH_ADMIN_PASSWORD must be at least 12 bytes and no more than ' . $maximumPasswordBytes . ' bytes for the selected password algorithm.');
    }

    $hash = password_hash($password, $useArgon2id ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash the administrator password.');
    }

    $pdo = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    $pdo->beginTransaction();

    $existingAdmin = $pdo->query("SELECT 1 FROM user_system_roles WHERE role = 'site_admin' LIMIT 1")->fetchColumn();
    if ($existingAdmin !== false) {
        throw new RuntimeException('A site administrator already exists. Bootstrap is one-time and has been refused.');
    }

    $lookup = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $lookup->execute(['email' => $email]);
    if ($lookup->fetchColumn() !== false) {
        throw new RuntimeException('That email already exists. No account was changed; use a separately reviewed administrative process.');
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO users (email, password_hash, display_name, status, email_verified_at)
         VALUES (:email, :password_hash, :display_name, 'active', CURRENT_TIMESTAMP)"
    );
    $insertUser->execute(['email' => $email, 'password_hash' => $hash, 'display_name' => $name]);
    $userId = (int) $pdo->lastInsertId();

    $grant = $pdo->prepare("INSERT INTO user_system_roles (user_id, role, assigned_by) VALUES (:user_id, 'site_admin', NULL)");
    $grant->execute(['user_id' => $userId]);

    $pdo->commit();
    fwrite(STDOUT, 'Created the initial site administrator (user ID ' . $userId . ').' . "\n");
    fwrite(STDOUT, "Unset TCH_ADMIN_EMAIL, TCH_ADMIN_NAME, and TCH_ADMIN_PASSWORD from your shell after this command.\n");
    exit(0);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[TeamCode Hub] Admin bootstrap failed: ' . $error::class . ': ' . $error->getMessage());
    fwrite(STDERR, 'Administrator bootstrap failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $password = '';
    putenv('TCH_ADMIN_PASSWORD');
    putenv('TCH_ADMIN_EMAIL');
    putenv('TCH_ADMIN_NAME');
}
