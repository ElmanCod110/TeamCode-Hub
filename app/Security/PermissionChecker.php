<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

final class PermissionChecker
{
    /** @param list<string> $grantedPermissions */
    public static function allows(array $grantedPermissions, string $requiredPermission): bool
    {
        if ($requiredPermission === '' || str_contains($requiredPermission, '*')) {
            return false;
        }
        return in_array($requiredPermission, $grantedPermissions, true);
    }
}
