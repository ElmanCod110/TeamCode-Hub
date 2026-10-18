<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\AdminAccountRepository;
use TeamCodeHub\Database\ChatRepository;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Database\DashboardRepository;
use TeamCodeHub\Database\GitHubActivityCacheRepository;
use TeamCodeHub\Database\NotificationRepository;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\MembershipRepository;
use TeamCodeHub\Database\MigrationRunner;
use TeamCodeHub\Database\ProjectMembershipRepository;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Database\TaskRepository;
use TeamCodeHub\Database\TaskCollaborationRepository;
use TeamCodeHub\Database\TeamInvitationRepository;
use TeamCodeHub\Database\TeamRepository;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;
use TeamCodeHub\Core\QuotaExceededException;
use TeamCodeHub\Services\GitHubActivityService;

require dirname(__DIR__) . '/app/bootstrap.php';

ob_start();
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo "PASS: {$message}\n";
};

$pdo = null;

try {
    $config = DatabaseConfig::fromEnvironment();
    $pdo = ConnectionFactory::connect($config);
    $runner = new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
    $applied = $runner->migrate();
    $assert(count($applied) >= 10, 'all versioned migrations are applied to MariaDB');
    $assert($runner->migrate() === [], 'migration runner is idempotent on a migrated schema');

    // Exercise the daily GitHub cache through an injected transport; tests never call GitHub.
    $githubCache = new GitHubActivityCacheRepository($pdo);
    $githubFetchCount = 0;
    $githubFetcher = static function (string $username) use (&$githubFetchCount): array {
        $githubFetchCount++;
        return [[
            'id' => 'fixture-event-1',
            'type' => 'PushEvent',
            'repo' => ['name' => 'fixture/project'],
            'actor' => ['login' => $username],
            'created_at' => '2026-10-17T12:00:00Z',
            'payload' => ['size' => 1, 'commits' => [['message' => 'Integration fixture commit']]],
        ]];
    };
    $githubDate = gmdate('Y-m-d');
    $githubService = new GitHubActivityService('integration-fixture', $githubCache, $githubFetcher);
    $githubFirst = $githubService->activity($githubDate);
    $githubSecond = $githubService->activity($githubDate);
    $assert($githubFetchCount === 1, 'GitHub activity cache claims at most one refresh per username per UTC day');
    $assert($githubFirst['available'] && !$githubFirst['stale'] && count($githubFirst['events']) === 1, 'GitHub public activity is normalized and cached after a successful refresh');
    $assert($githubSecond['events'][0]['summary'] === 'Pushed 1 commit: Integration fixture commit', 'cached GitHub activity is served without another transport request');

    $failureFetchCount = 0;
    $failureFetcher = static function (string $username) use (&$failureFetchCount): array {
        $failureFetchCount++;
        throw new RuntimeException('Simulated GitHub transport outage.');
    };
    $nextUtcDay = gmdate('Y-m-d', strtotime($githubDate . ' +1 day UTC'));
    $failureService = new GitHubActivityService('integration-fixture', $githubCache, $failureFetcher);
    $staleResult = $failureService->activity($nextUtcDay);
    $failureAgain = $failureService->activity($nextUtcDay);
    $assert($failureFetchCount === 1, 'failed GitHub refresh is not retried repeatedly on the same UTC day');
    $assert($staleResult['stale'] && count($staleResult['events']) === 1 && $failureAgain['events'] === $staleResult['events'], 'failed daily refresh preserves and serves the last good public snapshot');

    $insertUser = static function (string $email, string $name, string $password) use ($pdo): int {
        $statement = $pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, status, email_verified_at)
             VALUES (:email, :password_hash, :display_name, 'active', CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $name,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $adminId = $insertUser('admin@example.test', 'Integration Admin', 'A-Strong-Test-Password-42');
    $memberId = $insertUser('member@example.test', 'Integration Member', 'A-Strong-Test-Password-43');
    $viewerId = $insertUser('viewer@example.test', 'Integration Viewer', 'A-Strong-Test-Password-44');
    $outsiderId = $insertUser('outsider@example.test', 'Integration Outsider', 'A-Strong-Test-Password-45');
    $manager2Id = $insertUser('manager2@example.test', 'Integration Manager Two', 'A-Strong-Test-Password-46');
    $pdo->prepare("INSERT INTO user_system_roles (user_id, role) VALUES (:user_id, 'site_admin')")->execute(['user_id' => $adminId]);

    $userDirectory = new UserRepository($pdo);
    $adminSearch = $userDirectory->searchForSiteAdmin($adminId, 'admin@example.test', 'active');
    $assert(count($adminSearch['users']) === 1 && $adminSearch['users'][0]['id'] === $adminId, 'site admin can search the directory by email');
    $assert(!array_key_exists('password_hash', $adminSearch['users'][0]), 'admin user directory never exposes password hashes');
    $exactIdSearch = $userDirectory->searchForSiteAdmin($adminId, (string) $adminId, 'all');
    $assert(count($exactIdSearch['users']) === 1 && $exactIdSearch['users'][0]['email'] === 'admin@example.test', 'admin user directory supports exact user ID lookup');
    $literalWildcardSearch = $userDirectory->searchForSiteAdmin($adminId, '%', 'all');
    $assert($literalWildcardSearch['users'] === [], 'user search treats SQL wildcard characters as literal input');
    $nonAdminDirectoryDenied = false;
    try {
        $userDirectory->searchForSiteAdmin($outsiderId);
    } catch (DomainException) {
        $nonAdminDirectoryDenied = true;
    }
    $assert($nonAdminDirectoryDenied, 'non-admin accounts cannot query the site-wide user directory');

    // Seed enough pending records to exercise bounded pagination without creating memberships.
    $adminCredential = $userDirectory->findAuthenticationById($adminId);
    if (!is_array($adminCredential)) {
        throw new RuntimeException('Integration administrator credential fixture is missing.');
    }
    $directoryFixture = $pdo->prepare(
        "INSERT INTO users (email, password_hash, display_name, status)
         VALUES (:email, :password_hash, :display_name, 'pending')"
    );
    for ($directoryIndex = 1; $directoryIndex <= 51; $directoryIndex++) {
        $directoryFixture->execute([
            'email' => 'directory-fixture-' . $directoryIndex . '@example.test',
            'password_hash' => $adminCredential['password_hash'],
            'display_name' => 'Directory Fixture ' . $directoryIndex,
        ]);
    }
    $directoryPageOne = $userDirectory->searchForSiteAdmin($adminId, '', 'all', 1);
    $directoryPageTwo = $userDirectory->searchForSiteAdmin($adminId, '', 'all', 2);
    $pageOneIds = array_column($directoryPageOne['users'], 'id');
    $pageTwoIds = array_column($directoryPageTwo['users'], 'id');
    $assert(count($pageOneIds) === 50 && $directoryPageOne['has_more'], 'admin user directory limits each page to 50 and reports additional matches');
    $assert(count($pageTwoIds) === 6 && !$directoryPageTwo['has_more'], 'admin user directory returns the remaining records on the next page');
    $assert(array_intersect($pageOneIds, $pageTwoIds) === [], 'admin user directory pages do not repeat user records');
    $pendingDirectory = $userDirectory->searchForSiteAdmin($adminId, '', 'pending', 1);
    $assert(count($pendingDirectory['users']) === 50 && $pendingDirectory['has_more']
        && count(array_filter($pendingDirectory['users'], static fn (array $row): bool => $row['status'] === 'pending')) === 50,
        'admin user directory filters pending accounts before applying the page limit');
    $invalidDirectoryPageRejected = false;
    try {
        $userDirectory->searchForSiteAdmin($adminId, '', 'all', 10001);
    } catch (DomainException) {
        $invalidDirectoryPageRejected = true;
    }
    $assert($invalidDirectoryPageRejected, 'admin user directory rejects unsupported page offsets');

    // Account status changes require an active site administrator and a durable reason.
    $accountManager = new AdminAccountRepository($pdo);
    $statusTestId = $insertUser('status-test@example.test', 'Status Test Account', 'A-Strong-Test-Password-47');
    $pdo->prepare("INSERT INTO user_system_roles (user_id, role) VALUES (:user_id, 'site_admin')")
        ->execute(['user_id' => $statusTestId]);
    $disabledStatus = $accountManager->changeStatusForSiteAdmin($adminId, $statusTestId, 'disabled', 'Integration disable test');
    $assert($disabledStatus['changed'] && $disabledStatus['user']['status'] === 'disabled', 'site administrator can disable another account');
    $assert($disabledStatus['user']['email_verified'], 'account status response exposes only a boolean email-verification state');

    SecureSession::start();
    $statusAuthentication = new AuthenticationService($userDirectory);
    $assert(!$statusAuthentication->authenticate('status-test@example.test', 'A-Strong-Test-Password-47'), 'disabled accounts cannot authenticate');

    $reactivatedStatus = $accountManager->changeStatusForSiteAdmin($adminId, $statusTestId, 'active', 'Integration reactivation test');
    $assert($reactivatedStatus['changed'] && $reactivatedStatus['user']['status'] === 'active', 'verified disabled account can be reactivated');
    $unchangedStatus = $accountManager->changeStatusForSiteAdmin($adminId, $statusTestId, 'active', 'Integration unchanged status test');
    $assert(!$unchangedStatus['changed'], 'repeating the current account status is an idempotent no-op');
    $statusAudit = $accountManager->listRecentAuditForSiteAdmin($adminId, $statusTestId);
    $assert(count($statusAudit) === 2 && $statusAudit[0]['action'] === 'account_reactivated'
        && $statusAudit[1]['action'] === 'account_disabled', 'status changes create ordered audit records with action history');
    $auditPermissionRejected = false;
    try {
        $accountManager->listRecentAuditForSiteAdmin($outsiderId, $statusTestId);
    } catch (DomainException) {
        $auditPermissionRejected = true;
    }
    $assert($auditPermissionRejected, 'non-admin accounts cannot read administrator account audit history');

    $selfDisableRejected = false;
    try {
        $accountManager->changeStatusForSiteAdmin($adminId, $adminId, 'disabled', 'Attempt self disable');
    } catch (DomainException) {
        $selfDisableRejected = true;
    }
    $assert($selfDisableRejected, 'site administrators cannot disable their own account');
    $nonAdminStatusChangeRejected = false;
    try {
        $accountManager->changeStatusForSiteAdmin($outsiderId, $statusTestId, 'disabled', 'Attempt unauthorized disable');
    } catch (DomainException) {
        $nonAdminStatusChangeRejected = true;
    }
    $assert($nonAdminStatusChangeRejected, 'non-admin accounts cannot change account status');

    $unverifiedPending = $pdo->prepare("SELECT id FROM users WHERE email = 'directory-fixture-1@example.test' LIMIT 1");
    $unverifiedPending->execute();
    $unverifiedPendingId = (int) $unverifiedPending->fetchColumn();
    $accountManager->changeStatusForSiteAdmin($adminId, $unverifiedPendingId, 'disabled', 'Disable unverified fixture');
    $unverifiedReactivationRejected = false;
    try {
        $accountManager->changeStatusForSiteAdmin($adminId, $unverifiedPendingId, 'active', 'Attempt unverified reactivation');
    } catch (DomainException) {
        $unverifiedReactivationRejected = true;
    }
    $assert($unverifiedReactivationRejected, 'unverified accounts cannot be reactivated by the status endpoint');



    $directoryFixture = $pdo->prepare(
        "INSERT INTO users (email, password_hash, display_name, status, email_verified_at)
         VALUES (:email, 'directory-test-only-hash', :display_name, 'active', CURRENT_TIMESTAMP)"
    );
    for ($directoryIndex = 1; $directoryIndex <= 52; $directoryIndex++) {
        $directoryFixture->execute([
            'email' => 'directory-pagination-' . $directoryIndex . '@example.test',
            'display_name' => 'Directory Pagination ' . $directoryIndex,
        ]);
    }
    $firstDirectoryPage = $userDirectory->searchForSiteAdmin($adminId, 'directory-pagination', 'active', 1);
    $secondDirectoryPage = $userDirectory->searchForSiteAdmin($adminId, 'directory-pagination', 'active', 2);
    $assert(count($firstDirectoryPage['users']) === 50 && $firstDirectoryPage['has_more'], 'admin user directory caps each page at 50 and signals additional results');
    $assert(count($secondDirectoryPage['users']) === 2 && !$secondDirectoryPage['has_more'], 'admin user directory returns the remaining results on the next page');

    $teams = new TeamRepository($pdo);
    $team = $teams->createForOwner($adminId, 'Integration Team');
    $teamId = $team['id'];
    $assert($teams->findAccessibleByUser($teamId, $adminId) !== null, 'team creator receives active owner membership');
    $originalTeamSlug = $team['slug'];
    $renamedTeam = $teams->updateNameForOwner($adminId, $teamId, 'Integration Team Updated');
    $assert($renamedTeam['name'] === 'Integration Team Updated', 'team owner can update team display name');
    $assert($renamedTeam['slug'] === $originalTeamSlug, 'renaming a team preserves its stable slug');
    $outsiderTeamRenameRejected = false;
    try {
        $teams->updateNameForOwner($outsiderId, $teamId, 'Unauthorized Team Name');
    } catch (DomainException) {
        $outsiderTeamRenameRejected = true;
    }
    $assert($outsiderTeamRenameRejected, 'unrelated user cannot rename a team');
    $assert($teams->listForUser($outsiderId) === [], 'unrelated user cannot discover team in their list');

    $invitations = new TeamInvitationRepository($pdo);
    $notifications = new NotificationRepository($pdo);
    $memberInvitation = $invitations->createForManager($adminId, $teamId, 'member@example.test');
    $assert(strlen($memberInvitation['token']) === 64, 'team invitation token has 256 bits of entropy');
    $storedHash = $pdo->prepare('SELECT token_hash FROM team_invitations WHERE id = :id');
    $storedHash->execute(['id' => $memberInvitation['id']]);
    $assert(hash_equals((string) $storedHash->fetchColumn(), hash('sha256', $memberInvitation['token'])), 'only the invitation token hash is stored');

    $wrongEmailRejected = false;
    try {
        $invitations->accept($viewerId, 'viewer@example.test', $memberInvitation['token']);
    } catch (DomainException) {
        $wrongEmailRejected = true;
    }
    $assert($wrongEmailRejected, 'invitation cannot be accepted by a different email address');
    $assert($invitations->accept($memberId, 'member@example.test', $memberInvitation['token']) === $teamId, 'matching account can accept invitation');
    $adminNotices = $notifications->listRecentForUser($adminId);
    $assert(count(array_filter($adminNotices, static fn (array $item): bool => $item['type'] === 'team_invitation_accepted')) === 1, 'invitation acceptance notifies the inviter');

    $reuseRejected = false;
    try {
        $invitations->accept($memberId, 'member@example.test', $memberInvitation['token']);
    } catch (DomainException) {
        $reuseRejected = true;
    }
    $assert($reuseRejected, 'accepted invitation token cannot be reused');

    $viewerInvitation = $invitations->createForManager($adminId, $teamId, 'viewer@example.test');
    $invitations->accept($viewerId, 'viewer@example.test', $viewerInvitation['token']);
    $pendingInvitation = $invitations->createForManager($adminId, $teamId, 'outsider@example.test');
    $listedInvitations = $invitations->listPendingForManager($adminId, $teamId);
    $assert(count($listedInvitations) === 1 && !array_key_exists('token', $listedInvitations[0]), 'manager invitation listing never returns raw tokens');
    $assert($invitations->revokeByManager($adminId, $teamId, $pendingInvitation['id']), 'manager can revoke a pending invitation');

    $memberships = new MembershipRepository($pdo);
    $teamAccess = new TeamAccessService($memberships);
    $projectAccess = new ProjectAccessService($memberships);
    $assert($teamAccess->canManage($adminId, $teamId), 'active owner can manage their team');
    $assert(!$teamAccess->canView($outsiderId, $teamId), 'unapproved outsider cannot view a team');

    $projects = new ProjectRepository($pdo);
    $projectId = $projects->createForTeamManager($adminId, $teamId, 'Integration Project', 'Testing project access');
    $projects->updateMetadataForTeamManager($adminId, $projectId, 'Integration Project Updated', 'Updated project description');
    $updatedProject = $projects->findAccessibleByUser($adminId, $projectId);
    $assert(is_array($updatedProject) && $updatedProject['name'] === 'Integration Project Updated' && $updatedProject['description'] === 'Updated project description', 'team owner can update project name and description');
    $projectMemberships = new ProjectMembershipRepository($pdo);

    $projectMemberships->assignPending($adminId, $projectId, $memberId, 'member');
    $assert(!$projectAccess->canView($memberId, $projectId), 'pending project membership grants no access');
    $projectMemberships->approve($adminId, $projectId, $memberId);
    $assert($projectAccess->canView($memberId, $projectId) && $projectAccess->canEdit($memberId, $projectId), 'approved member can view and edit project');
    $memberProjectEditRejected = false;
    try {
        $projects->updateMetadataForTeamManager($memberId, $projectId, 'Unauthorized Project Name', null);
    } catch (DomainException) {
        $memberProjectEditRejected = true;
    }
    $assert($memberProjectEditRejected, 'ordinary project member cannot update project metadata');
    $memberNotices = $notifications->listRecentForUser($memberId);
    $assert(count(array_filter($memberNotices, static fn (array $item): bool => $item['type'] === 'project_access_approved')) === 1, 'project access approval creates a notification');

    $projectMemberships->assignPending($adminId, $projectId, $viewerId, 'viewer');
    $projectMemberships->approve($adminId, $projectId, $viewerId);
    $assert($projectAccess->canView($viewerId, $projectId), 'approved viewer can read project');
    $assert(!$projectAccess->canEdit($viewerId, $projectId), 'viewer cannot edit project');
    $assert(!$projectAccess->canView($outsiderId, $projectId), 'outsider cannot read project');

    $managerMembers = $projectMemberships->listForManager($adminId, $projectId);
    $assert(count($managerMembers) === 3, 'project manager can list approved project memberships');

    $candidateId = $insertUser('candidate@example.test', 'Directory Candidate', 'A-Strong-Test-Password-47');
    $pdo->prepare("INSERT INTO team_memberships (team_id, user_id, role, status) VALUES (:team_id, :user_id, 'member', 'active')")
        ->execute(['team_id' => $teamId, 'user_id' => $candidateId]);
    $candidateSearch = $projectMemberships->searchAssignableTeamMembers($adminId, $projectId, 'Directory Candidate');
    $assert(count($candidateSearch) === 1 && $candidateSearch[0]['user_id'] === $candidateId, 'manager can search active team members eligible for project assignment');
    $candidateById = $projectMemberships->searchAssignableTeamMembers($adminId, $projectId, (string) $candidateId);
    $assert(count($candidateById) === 1 && $candidateById[0]['user_id'] === $candidateId, 'member search supports exact user ID lookup');
    $approvedMemberSearch = $projectMemberships->searchAssignableTeamMembers($adminId, $projectId, 'admin@example.test');
    $assert($approvedMemberSearch === [], 'member search excludes users who already have approved project access');
    $projectMemberships->assignPending($adminId, $projectId, $candidateId, 'member');
    $pendingCandidateSearch = $projectMemberships->searchAssignableTeamMembers($adminId, $projectId, 'candidate@example.test');
    $assert(count($pendingCandidateSearch) === 1 && $pendingCandidateSearch[0]['existing_status'] === 'pending', 'member search returns pending assignments with their current status');
    $unauthorizedDirectorySearchRejected = false;
    try {
        $projectMemberships->searchAssignableTeamMembers($outsiderId, $projectId, 'candidate');
    } catch (DomainException) {
        $unauthorizedDirectorySearchRejected = true;
    }
    $assert($unauthorizedDirectorySearchRejected, 'unrelated users cannot search a project team directory');

    // A second active manager enables the multi-reporter escalation path.
    $pdo->prepare("INSERT INTO team_memberships (team_id, user_id, role, status) VALUES (:team_id, :user_id, 'manager', 'active')")
        ->execute(['team_id' => $teamId, 'user_id' => $manager2Id]);
    $pdo->prepare("INSERT INTO project_memberships (project_id, user_id, access_level, status, approved_by, approved_at)
        VALUES (:project_id, :user_id, 'member', 'approved', :approved_by, CURRENT_TIMESTAMP)")
        ->execute(['project_id' => $projectId, 'user_id' => $manager2Id, 'approved_by' => $adminId]);
    $managerTeamRenameRejected = false;
    try {
        $teams->updateNameForOwner($manager2Id, $teamId, 'Manager Cannot Rename Team');
    } catch (DomainException) {
        $managerTeamRenameRejected = true;
    }
    $assert($managerTeamRenameRejected, 'team manager cannot rename the team reserved for its owner');
    $projects->updateMetadataForTeamManager($manager2Id, $projectId, 'Manager Updated Project', 'Manager edited the project description');
    $managerUpdatedProject = $projects->findAccessibleByUser($manager2Id, $projectId);
    $assert(is_array($managerUpdatedProject) && $managerUpdatedProject['name'] === 'Manager Updated Project', 'approved team manager can edit project metadata');
    $demotedToViewer = $projectMemberships->changeAccessLevel($manager2Id, $projectId, $memberId, 'viewer');
    $assert($demotedToViewer, 'team manager can change an approved member to read-only viewer access');
    $assert(!$projectAccess->canEdit($memberId, $projectId), 'viewer access immediately removes task and chat write capability');
    $promotedToMember = $projectMemberships->changeAccessLevel($manager2Id, $projectId, $memberId, 'member');
    $assert($promotedToMember && $projectAccess->canEdit($memberId, $projectId), 'team manager can restore approved member-level edit access');

    $memberAssigneeSearch = $projectMemberships->searchTaskAssignees($memberId, $projectId, 'member@example.test');
    $assert(count($memberAssigneeSearch) === 1 && $memberAssigneeSearch[0]['user_id'] === $memberId, 'approved project members can search eligible task assignees');
    $viewerAssigneeSearch = $projectMemberships->searchTaskAssignees($memberId, $projectId, 'viewer@example.test');
    $assert($viewerAssigneeSearch === [], 'task assignee search excludes approved read-only viewers');
    $pendingAssigneeSearch = $projectMemberships->searchTaskAssignees($memberId, $projectId, 'candidate@example.test');
    $assert($pendingAssigneeSearch === [], 'task assignee search excludes pending project memberships');
    $unauthorizedAssigneeSearchRejected = false;
    try {
        $projectMemberships->searchTaskAssignees($outsiderId, $projectId, 'member');
    } catch (DomainException) {
        $unauthorizedAssigneeSearchRejected = true;
    }
    $assert($unauthorizedAssigneeSearchRejected, 'unapproved users cannot enumerate task assignees');
    $viewerAssigneeDirectoryRejected = false;
    try {
        $projectMemberships->searchTaskAssignees($viewerId, $projectId, 'member');
    } catch (DomainException) {
        $viewerAssigneeDirectoryRejected = true;
    }
    $assert($viewerAssigneeDirectoryRejected, 'read-only viewers cannot enumerate the task assignee directory');
    $creatorDowngradeRejected = false;
    try {
        $projectMemberships->changeAccessLevel($manager2Id, $projectId, $adminId, 'viewer');
    } catch (DomainException) {
        $creatorDowngradeRejected = true;
    }
    $assert($creatorDowngradeRejected, 'project creator cannot be demoted to viewer access');
    $unauthorizedAccessChangeRejected = false;
    try {
        $projectMemberships->changeAccessLevel($memberId, $projectId, $viewerId, 'member');
    } catch (DomainException) {
        $unauthorizedAccessChangeRejected = true;
    }
    $assert($unauthorizedAccessChangeRejected, 'ordinary project member cannot change another member access level');
    $memberAccessNotices = array_filter(
        $notifications->listRecentForUser($memberId),
        static fn (array $item): bool => $item['type'] === 'project_access_level_changed',
    );
    $assert(count($memberAccessNotices) === 2, 'access-level changes notify the affected member');

    $chat = new ChatRepository($pdo);
    $reports = new ChatReportRepository($pdo);
    $contextBeforeId = $chat->createMessage($memberId, $projectId, 'Context before reported message');
    $reportedMessageId = $chat->createMessage($memberId, $projectId, 'Potentially harmful message');
    $contextAfterId = $chat->createMessage($memberId, $projectId, 'Context after reported message');
    $chatMessages = $chat->listMessages($viewerId, $projectId);
    $assert(count($chatMessages) === 3 && $chatMessages[0]['id'] === $contextBeforeId && $chatMessages[2]['id'] === $contextAfterId, 'chat returns a bounded ordered message window to an approved viewer');

    $chat->editMessage($memberId, $projectId, $contextBeforeId, 'Edited context before reported message');
    $editedMessage = $pdo->prepare('SELECT edited_at FROM project_messages WHERE id = :id');
    $editedMessage->execute(['id' => $contextBeforeId]);
    $assert($editedMessage->fetchColumn() !== null, 'message author can edit a recent message');

    $viewerCannotSend = false;
    try {
        $chat->createMessage($viewerId, $projectId, 'Viewer should not send');
    } catch (DomainException) {
        $viewerCannotSend = true;
    }
    $assert($viewerCannotSend, 'viewer cannot send chat messages');

    $reportOne = $reports->create($viewerId, $projectId, $reportedMessageId, 'Potential harassment');
    $reportTwo = $reports->create($manager2Id, $projectId, $reportedMessageId, 'Repeated harassment');
    $assert($reportOne['reviewer_scope'] === 'team_manager' && $reportTwo['reviewer_scope'] === 'team_manager', 'initial reports are routed to team managers when no conflict exists');

    $duplicatePersonReportRejected = false;
    try {
        $reports->create($viewerId, $projectId, $contextAfterId, 'Second report against the same person');
    } catch (DomainException) {
        $duplicatePersonReportRejected = true;
    }
    $assert($duplicatePersonReportRejected, 'a reporter cannot submit duplicate reports against the same person on the same day');

    $managerQueue = $reports->listForTeamManager($adminId, $teamId);
    $snapshotReport = array_values(array_filter($managerQueue, static fn (array $report): bool => $report['id'] === $reportOne['id']))[0] ?? null;
    $assert(is_array($snapshotReport) && count($snapshotReport['context']) === 3 && $snapshotReport['context'][1]['body'] === 'Potentially harmful message', 'report stores adjacent chat context and original message content');

    $editReportedMessageRejected = false;
    try {
        $chat->editMessage($memberId, $projectId, $reportedMessageId, 'Try to change reported content');
    } catch (DomainException) {
        $editReportedMessageRejected = true;
    }
    $assert($editReportedMessageRejected, 'message with an unresolved report cannot be edited');

    $chat->deleteMessage($memberId, $projectId, $reportedMessageId);
    $deletedMessage = $pdo->prepare('SELECT body, expires_at FROM project_messages WHERE id = :id');
    $deletedMessage->execute(['id' => $reportedMessageId]);
    $deletedState = $deletedMessage->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($deletedState) && $deletedState['body'] === '[message deleted]' && $deletedState['expires_at'] === null, 'deleting reported chat content preserves a held tombstone');

    $firstReview = $reports->review($adminId, $reportOne['id'], 'valid');
    $assert($firstReview['status'] === 'valid' && !$firstReview['escalated'], 'one valid unique report remains with the team manager');
    $secondReview = $reports->review($adminId, $reportTwo['id'], 'valid');
    $assert($secondReview['escalated'] && $secondReview['reviewer_scope'] === 'site_admin', 'two distinct valid reports in 24 hours escalate to site admin');

    $adminReportQueue = $reports->listForSiteAdmin($adminId);
    $queuedIds = array_column($adminReportQueue, 'id');
    $assert(in_array($reportOne['id'], $queuedIds, true) && in_array($reportTwo['id'], $queuedIds, true), 'escalated valid reports appear in site admin queue');

    $reports->review($adminId, $reportOne['id'], 'resolved');
    $reports->review($adminId, $reportTwo['id'], 'resolved');
    $deletedMessage->execute(['id' => $reportedMessageId]);
    $resolvedMessage = $deletedMessage->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($resolvedMessage) && $resolvedMessage['expires_at'] !== null, 'reported message retention resumes only after all related cases are resolved');

    $expiryCheck = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, expires_at) FROM project_messages WHERE id = :id');
    $expiryCheck->execute(['id' => $contextBeforeId]);
    $normalMessageTtl = (int) $expiryCheck->fetchColumn();
    $assert($normalMessageTtl > 86000 && $normalMessageTtl <= 86400, 'normal chat messages receive a 24-hour expiry');

    for ($i = 0; $i < 55; $i++) {
        $chat->createMessage($memberId, $projectId, 'Window message ' . $i);
    }
    $window = $chat->listMessages($viewerId, $projectId);
    $windowIds = array_column($window, 'id');
    $assert(count($window) === 50 && $windowIds === array_values($windowIds) && $windowIds[0] < $windowIds[49], 'chat initial load is capped at 50 messages in chronological order');
    $cursor = (int) $window[count($window) - 1]['id'];
    $chat->createMessage($memberId, $projectId, 'Incremental cursor message');
    $incremental = $chat->listMessages($viewerId, $projectId, $cursor);
    $assert(count($incremental) === 1 && $incremental[0]['body'] === 'Incremental cursor message', 'chat cursor fetch returns only newer messages');

    $adminNotices = $notifications->listRecentForUser($adminId);
    $assert(count(array_filter($adminNotices, static fn (array $item): bool => $item['type'] === 'chat_report_escalated')) === 1, 'valid report escalation notifies site admin');


    $tasks = new TaskRepository($pdo);
    $taskId = $tasks->create(
        $adminId,
        $projectId,
        'Validate integration workflow',
        'Task created in integration suite',
        'high',
        $memberId,
        '2026-10-20 12:00:00'
    );
    $memberNotices = $notifications->listRecentForUser($memberId);
    $assert(count(array_filter($memberNotices, static fn (array $item): bool => $item['type'] === 'task_assigned')) === 1, 'task assignment notifies the assignee');
    $viewerTasks = $tasks->listAccessibleByUser($viewerId, $projectId);
    $assert(count($viewerTasks) === 1 && $viewerTasks[0]['id'] === $taskId, 'approved viewer can read project tasks');

    $viewerCannotCreateTask = false;
    try {
        $tasks->create($viewerId, $projectId, 'Should be denied', null, 'normal', null, null);
    } catch (DomainException) {
        $viewerCannotCreateTask = true;
    }
    $assert($viewerCannotCreateTask, 'viewer cannot create tasks');

    $tasks->updateStatus($memberId, $projectId, $taskId, 'done');
    $taskRow = $pdo->prepare('SELECT status, completed_at FROM tasks WHERE id = :id');
    $taskRow->execute(['id' => $taskId]);
    $taskState = $taskRow->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($taskState) && $taskState['status'] === 'done' && $taskState['completed_at'] !== null, 'marking a task done sets completion timestamp');

    $tasks->updateDetails($adminId, $projectId, $taskId, 'Validate task collaboration', 'Updated task description', 'urgent', $memberId, '2026-10-21 12:00:00');
    $updatedTask = $pdo->prepare('SELECT title, description, priority, assigned_to, due_at FROM tasks WHERE id = :id');
    $updatedTask->execute(['id' => $taskId]);
    $updatedTaskRow = $updatedTask->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($updatedTaskRow) && $updatedTaskRow['title'] === 'Validate task collaboration' && $updatedTaskRow['priority'] === 'urgent', 'task details can be updated transactionally');
    $assert(is_array($updatedTaskRow) && (int) $updatedTaskRow['assigned_to'] === $memberId && $updatedTaskRow['due_at'] === '2026-10-21 12:00:00', 'task edits can update assignee and UTC due date');

    $taskCollaboration = new TaskCollaborationRepository($pdo);
    $commentId = $taskCollaboration->createComment($memberId, $projectId, $taskId, 'Initial task discussion note');
    $taskCollaboration->editComment($memberId, $projectId, $taskId, $commentId, 'Edited task discussion note');
    $viewerTaskDetails = $taskCollaboration->details($viewerId, $projectId, $taskId);
    $assert(count($viewerTaskDetails['comments']) === 1 && $viewerTaskDetails['comments'][0]['body'] === 'Edited task discussion note', 'approved viewers can read task comments while member edits remain visible');
    $assert($viewerTaskDetails['task']['access_level'] === 'viewer', 'task detail response includes the viewer access boundary');

    $viewerCannotComment = false;
    try {
        $taskCollaboration->createComment($viewerId, $projectId, $taskId, 'Viewer mutation should be rejected');
    } catch (DomainException) {
        $viewerCannotComment = true;
    }
    $assert($viewerCannotComment, 'viewer cannot create task comments');

    $commentNotifications = $notifications->listRecentForUser($adminId);
    $assert(count(array_filter($commentNotifications, static fn (array $item): bool => $item['type'] === 'task_comment')) === 1, 'task comment notifies the task creator');

    $subtaskId = $taskCollaboration->createSubtask($memberId, $projectId, $taskId, 'Add checklist coverage');
    $subtaskDone = $taskCollaboration->toggleSubtask($adminId, $projectId, $taskId, $subtaskId);
    $taskDetails = $taskCollaboration->details($memberId, $projectId, $taskId);
    $assert($subtaskDone && count($taskDetails['subtasks']) === 1 && $taskDetails['subtasks'][0]['is_done'], 'task checklist items can be created and toggled by approved members');

    $viewerCannotToggle = false;
    try {
        $taskCollaboration->toggleSubtask($viewerId, $projectId, $taskId, $subtaskId);
    } catch (DomainException) {
        $viewerCannotToggle = true;
    }
    $assert($viewerCannotToggle, 'viewer cannot mutate task checklist items');

    $taskCollaboration->deleteComment($memberId, $projectId, $taskId, $commentId);
    $deletedCommentDetails = $taskCollaboration->details($memberId, $projectId, $taskId);
    $assert($deletedCommentDetails['comments'][0]['deleted_at'] !== null && $deletedCommentDetails['comments'][0]['body'] === '', 'task comment deletion preserves a visible thread marker without exposing the old body');
    $taskCollaboration->deleteSubtask($adminId, $projectId, $taskId, $subtaskId);
    $assert($taskCollaboration->details($adminId, $projectId, $taskId)['subtasks'] === [], 'task creator can remove a checklist item created by another member');
    $adminNotices = $notifications->listRecentForUser($adminId);
    $assert(count(array_filter($adminNotices, static fn (array $item): bool => $item['type'] === 'task_completed')) === 1, 'task completion notifies its creator');

    $tasks->create($adminId, $projectId, 'Due today', null, 'normal', $memberId, gmdate('Y-m-d') . ' 23:59:00');
    $tasks->create($adminId, $projectId, 'Overdue task', null, 'urgent', $memberId, gmdate('Y-m-d', strtotime('-1 day UTC')) . ' 12:00:00');
    $dashboard = (new DashboardRepository($pdo))->overview($memberId);
    $assert(count($dashboard['tasks_due_today']) === 1, 'dashboard separates tasks due today');
    $assert(count($dashboard['overdue_tasks']) === 1, 'dashboard identifies overdue tasks without including today’s tasks');

    $beforeUnread = $notifications->unreadCount($memberId);
    $recent = $notifications->listRecentForUser($memberId, 1);
    $assert($beforeUnread > 0 && count($recent) === 1, 'notification inbox returns recent items and unread count');
    $assert(!$notifications->markRead($outsiderId, $recent[0]['id']), 'users cannot mark another user’s notification as read');
    $assert($notifications->markRead($memberId, $recent[0]['id']), 'notification owner can mark an item as read');
    $assert($notifications->unreadCount($memberId) === $beforeUnread - 1, 'marking a notification read updates unread count');

    $projectQuotaReached = false;
    $pdo->prepare('UPDATE teams SET project_limit = 1 WHERE id = :id')->execute(['id' => $teamId]);
    try {
        $projects->createForTeamManager($adminId, $teamId, 'Over quota project', null);
    } catch (QuotaExceededException) {
        $projectQuotaReached = true;
    }
    $assert($projectQuotaReached, 'active project quota is enforced inside repository transaction');

    $memberQuotaReached = false;
    $pdo->prepare('UPDATE teams SET member_limit = 3 WHERE id = :id')->execute(['id' => $teamId]);
    try {
        $invitations->createForManager($adminId, $teamId, 'another@example.test');
    } catch (QuotaExceededException) {
        $memberQuotaReached = true;
    }
    $assert($memberQuotaReached, 'active memberships consume team member quota');

    $limiter = new LoginRateLimiter($pdo);
    $emailAttemptsAllowed = true;
    for ($i = 0; $i < 8; $i++) {
        $emailAttemptsAllowed = $emailAttemptsAllowed && $limiter->allows('limit@example.test', '198.51.100.10');
    }
    $assert($emailAttemptsAllowed, 'login limiter allows attempts within per-email threshold');
    $assert(!$limiter->allows('limit@example.test', '198.51.100.10'), 'login limiter blocks attempts beyond per-email threshold');

    $ipAttemptsAllowed = true;
    for ($i = 0; $i < 20; $i++) {
        $ipAttemptsAllowed = $ipAttemptsAllowed && $limiter->allows('ip-' . $i . '@example.test', '198.51.100.77');
    }
    $assert($ipAttemptsAllowed, 'login limiter allows attempts within per-IP threshold');
    $assert(!$limiter->allows('ip-over@example.test', '198.51.100.77'), 'login limiter blocks attempts beyond per-IP threshold');

    SecureSession::start();
    $auth = new AuthenticationService(new UserRepository($pdo));
    $assert($auth->authenticate('admin@example.test', 'A-Strong-Test-Password-42'), 'active user can authenticate with correct password');
    $current = $auth->currentUser();
    $assert(is_array($current) && $current['id'] === $adminId, 'authenticated session resolves current user');
    $assert(!$auth->authenticate('admin@example.test', 'incorrect-password'), 'incorrect password is rejected');

    $assert($auth->updateDisplayName($adminId, 'Integration Admin Updated'), 'active user can update their display name');
    $assert($auth->currentUser()['display_name'] === 'Integration Admin Updated', 'updated display name is reflected in the authenticated user record');
    $assert($auth->updateDisplayName($adminId, 'Integration Admin Updated'), 'saving an unchanged display name remains successful');
    $assert(!$auth->changePassword($adminId, 'incorrect-password', 'A-New-Strong-Password-52'), 'password change rejects an incorrect current password');
    $assert(!$auth->changePassword($adminId, 'A-Strong-Test-Password-42', str_repeat('x', 73)), 'password change rejects values beyond the supported byte limit');
    $assert($auth->changePassword($adminId, 'A-Strong-Test-Password-42', 'A-New-Strong-Password-52'), 'password change accepts a verified current password and strong replacement');
    $assert(!$auth->changePassword($adminId, 'A-Strong-Test-Password-42', 'Another-New-Strong-Password-53'), 'a replaced password cannot be reused as the current credential');
    $newCredential = (new UserRepository($pdo))->findAuthenticationById($adminId);
    $assert(is_array($newCredential) && password_verify('A-New-Strong-Password-52', $newCredential['password_hash']), 'only the new password hash is persisted after a password change');
    $auth->logout();
    $assert(session_status() !== PHP_SESSION_ACTIVE, 'authentication logout destroys the session');

    $unauthorizedArchiveRejected = false;
    try {
        $projects->archiveForTeamManager($outsiderId, $projectId);
    } catch (DomainException) {
        $unauthorizedArchiveRejected = true;
    }
    $assert($unauthorizedArchiveRejected, 'unrelated user cannot archive a project');

    $projects->archiveForTeamManager($adminId, $projectId);
    $assert($projects->findAccessibleByUser($adminId, $projectId) === null, 'archived projects leave active project access queries');
    $archivedProjects = $projects->listArchivedForManager($adminId, $teamId);
    $assert(count(array_filter($archivedProjects, static fn (array $project): bool => $project['id'] === $projectId)) === 1, 'team manager can find archived projects for restoration');
    $preservedTaskCount = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE project_id = :project_id');
    $preservedTaskCount->execute(['project_id' => $projectId]);
    $assert((int) $preservedTaskCount->fetchColumn() > 0, 'archiving preserves project task records');

    $replacementProjectId = $projects->createForTeamManager($adminId, $teamId, 'Quota Replacement Project', null);
    $restoreQuotaBlocked = false;
    try {
        $projects->restoreForTeamManager($adminId, $projectId);
    } catch (QuotaExceededException) {
        $restoreQuotaBlocked = true;
    }
    $assert($restoreQuotaBlocked, 'restoring an archived project respects the active project quota');
    $projects->archiveForTeamManager($adminId, $replacementProjectId);
    $projects->restoreForTeamManager($adminId, $projectId);
    $restoredProject = $projects->findAccessibleByUser($adminId, $projectId);
    $assert(is_array($restoredProject) && $restoredProject['status'] === 'active', 'team owner can restore an archived project after quota capacity is available');

    echo "\n{$passed} integration assertions passed.\n";
    $output = ob_get_clean();
    echo $output;
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, 'Integration test failed: ' . $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
