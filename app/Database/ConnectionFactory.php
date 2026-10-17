<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    public static function connect(DatabaseConfig $config): PDO
    {
        try {
            return new PDO($config->dsn(), $config->username, $config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed. Check private database configuration and server logs.', 0, $exception);
        }
    }
}
