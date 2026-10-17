<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

use RuntimeException;

final class CsrfToken
{
    private const KEY = '_csrf_token';

    public static function get(): string
    {
        self::requireSession();
        $token = $_SESSION[self::KEY] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $token;
        }
        return $token;
    }

    public static function validate(?string $candidate): bool
    {
        self::requireSession();
        $expected = $_SESSION[self::KEY] ?? null;
        return is_string($candidate) && is_string($expected) && $candidate !== '' && hash_equals($expected, $candidate);
    }

    public static function rotate(): string
    {
        self::requireSession();
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::KEY];
    }

    private static function requireSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active session is required.');
        }
    }
}
