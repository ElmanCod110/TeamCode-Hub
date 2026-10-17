<?php

declare(strict_types=1);

namespace TeamCodeHub\Core;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'text/plain; charset=utf-8'],
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return new self('{"error":"Response encoding failed"}', 500, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return new self($encoded, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function statusCode(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }
}
