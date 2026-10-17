<?php

declare(strict_types=1);

namespace TeamCodeHub\\Core;

final class Request
{
    /** @param array<string, mixed> $query */
    /** @param array<string, mixed> $body */
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $path = '/' . trim($rawPath, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_scalar($value)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = $_POST;
        if ($body === [] && str_contains(strtolower($headers['Content-Type'] ?? ''), 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        return new self($method, $path, $_GET, $body, $headers);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
