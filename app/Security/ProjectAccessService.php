<?php

declare(strict_types=1);

namespace TeamCodeHub\Security;

use TeamCodeHub\Database\MembershipRepository;

final class ProjectAccessService
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    public function canView(int $userId, int $projectId): bool
    {
        return $this->accessLevel($userId, $projectId) !== null;
    }

    public function canEdit(int $userId, int $projectId): bool
    {
        return $this->accessLevel($userId, $projectId) === 'member';
    }

    public function accessLevel(int $userId, int $projectId): ?string
    {
        if ($userId < 1 || $projectId < 1) {
            return null;
        }
        return $this->memberships->approvedProjectAccessLevel($userId, $projectId);
    }
}
