<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\ChatRepository;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Database\DashboardRepository;
use TeamCodeHub\Database\NotificationRepository;
use TeamCodeHub\Database\DatabaseConfig;
use TeamCodeHub\Database\MembershipRepository;
use TeamCodeHub\Database\MigrationRunner;
use TeamCodeHub\Database\ProjectMembershipRepository;
use TeamCodeHub\Database\ProjectRepository;
use TeamCodeHub\Database\TaskRepository;
use TeamCodeHub\Database\TeamInvitationRepository;
use TeamCodeHub\Database\TeamRepository;
use TeamCodeHub\Database\UserRepository;
use TeamCodeHub\Security\AuthenticationService;
use TeamCodeHub\Security\LoginRateLimiter;
use TeamCodeHub\Security\ProjectAccessService;
use TeamCodeHub\Security\SecureSession;
use TeamCodeHub\Security\TeamAccessService;
use TeamCodeHub\Core\QuotaExceededException;

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
    $assert(count($applied) >= 7, 'all versioned migrations are applied to MariaDB');
    $assert($runner->migrate() === [], 'migration runner is idempotent on a migrated schema');

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

    $teams = new TeamRepository($pdo);
    $team = $teams->createForOwner($adminId, 'Integration Team');
    $teamId = $team['id'];
    $assert($teams->findAccessibleByUser($teamId, $adminId) !== null, 'team creator receives active owner membership');
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
    $projectMemberships = new ProjectMembershipRepository($pdo);

    $projectMemberships->assignPending($adminId, $projectId, $memberId, 'member');
    $assert(!$projectAccess->canView($memberId, $projectId), 'pending project membership grants no access');
    $projectMemberships->approve($adminId, $projectId, $memberId);
    $assert($projectAccess->canView($memberId, $projectId) && $projectAccess->canEdit($memberId, $projectId), 'approved member can view and edit project');
    $memberNotices = $notifications->listRecentForUser($memberId);
    $assert(count(array_filter($memberNotices, static fn (array $item): bool => $item['type'] === 'project_access_approved')) === 1, 'project access approval creates a notification');

    $projectMemberships->assignPending($adminId, $projectId, $viewerId, 'viewer');
    $projectMemberships->approve($adminId, $projectId, $viewerId);
    $assert($projectAccess->canView($viewerId, $projectId), 'approved viewer can read project');
    $assert(!$projectAccess->canEdit($viewerId, $projectId), 'viewer cannot edit project');
    $assert(!$projectAccess->canView($outsiderId, $projectId), 'outsider cannot read project');

    $managerMembers = $projectMemberships->listForManager($adminId, $projectId);
    $assert(count($managerMembers) === 3, 'project manager can list approved project memberships');

    // A second active manager enables the multi-reporter escalation path.
    $pdo->prepare("INSERT INTO team_memberships (team_id, user_id, role, status) VALUES (:team_id, :user_id, 'manager', 'active')")
        ->execute(['team_id' => $teamId, 'user_id' => $manager2Id]);
    $pdo->prepare("INSERT INTO project_memberships (project_id, user_id, access_level, status, approved_by, approved_at)
        VALUES (:project_id, :user_id, 'member', 'approved', :approved_by, CURRENT_TIMESTAMP)")
        ->execute(['project_id' => $projectId, 'user_id' => $manager2Id, 'approved_by' => $adminId]);

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
    $auth->logout();
    $assert(session_status() !== PHP_SESSION_ACTIVE, 'authentication logout destroys the session');

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
