<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use InvalidArgumentException;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {
        if ($host === '' || preg_match('/^[a-zA-Z0-9._:-]+$/', $host) !== 1) {
            throw new InvalidArgumentException('DB_HOST contains unsupported characters.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('DB_PORT must be between 1 and 65535.');
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $database) !== 1) {
            throw new InvalidArgumentException('DB_DATABASE must contain only letters, numbers, and underscores.');
        }
        if ($username === '') {
            throw new InvalidArgumentException('DB_USERNAME cannot be empty.');
        }
    }

    public static function fromEnvironment(): self
    {
        $host = self::required('DB_HOST');
        $database = self::required('DB_DATABASE');
        $username = self::required('DB_USERNAME');
        $password = getenv('DB_PASSWORD');
        $port = getenv('DB_PORT');

        if ($port === false || $port === '') {
            $port = '3306';
        }
        if (filter_var($port, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('DB_PORT must be an integer.');
        }

        return new self($host, (int) $port, $database, $username, $password === false ? '' : $password);
    }

    private static function required(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new InvalidArgumentException($name . ' is required.');
        }
        return trim($value);
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
    }
}
