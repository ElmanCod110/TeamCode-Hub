<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

final class ClientIpResolver
{
    /** @param list<string> $trustedProxies Exact IP addresses of trusted reverse proxies. */
    public static function resolve(string $remoteAddress, ?string $forwardedFor, array $trustedProxies = []): string
    {
        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        if (!in_array($remoteAddress, $trustedProxies, true) || $forwardedFor === null || trim($forwardedFor) === '') {
            return $remoteAddress;
        }

        $chain = [];
        foreach (explode(',', $forwardedFor) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $chain[] = $candidate;
            }
        }
        $chain[] = $remoteAddress;

        for ($index = count($chain) - 1; $index >= 0; $index--) {
            $candidate = $chain[$index];
            if (in_array($candidate, $trustedProxies, true)) {
                continue;
            }
            return $candidate;
        }

        return $remoteAddress;
    }

    /** @return list<string> */
    public static function configuredTrustedProxies(): array
    {
        $value = getenv('TRUSTED_PROXIES');
        if ($value === false || trim($value) === '') {
            return [];
        }

        $trusted = [];
        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $trusted[] = $candidate;
            }
        }
        return array_values(array_unique($trusted));
    }
}
