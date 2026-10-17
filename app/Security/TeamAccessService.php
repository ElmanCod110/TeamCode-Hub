<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

use TeamCodeHub\Database\MembershipRepository;

final class TeamAccessService
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    public function canView(int $userId, int $teamId): bool
    {
        return $userId > 0 && $teamId > 0 && $this->memberships->activeTeamRole($userId, $teamId) !== null;
    }

    public function canManage(int $userId, int $teamId): bool
    {
        if ($userId < 1 || $teamId < 1) {
            return false;
        }
        return in_array($this->memberships->activeTeamRole($userId, $teamId), ['owner', 'manager'], true);
    }
}
