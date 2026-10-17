<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
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
    $assert(count($applied) >= 5, 'all versioned migrations are applied to MariaDB');
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

    $teams = new TeamRepository($pdo);
    $team = $teams->createForOwner($adminId, 'Integration Team');
    $teamId = $team['id'];
    $assert($teams->findAccessibleByUser($teamId, $adminId) !== null, 'team creator receives active owner membership');
    $assert($teams->listForUser($outsiderId) === [], 'unrelated user cannot discover team in their list');

    $invitations = new TeamInvitationRepository($pdo);
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

    $projectMemberships->assignPending($adminId, $projectId, $viewerId, 'viewer');
    $projectMemberships->approve($adminId, $projectId, $viewerId);
    $assert($projectAccess->canView($viewerId, $projectId), 'approved viewer can read project');
    $assert(!$projectAccess->canEdit($viewerId, $projectId), 'viewer cannot edit project');
    $assert(!$projectAccess->canView($outsiderId, $projectId), 'outsider cannot read project');

    $managerMembers = $projectMemberships->listForManager($adminId, $projectId);
    $assert(count($managerMembers) === 3, 'project manager can list approved project memberships');

    $tasks = new TaskRepository($pdo);
    $taskId = $tasks->create(
        $memberId,
        $projectId,
        'Validate integration workflow',
        'Task created in integration suite',
        'high',
        $memberId,
        '2026-10-20 12:00:00'
    );
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
