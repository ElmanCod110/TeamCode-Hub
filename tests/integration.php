<?php

declare(strict_types=1);

use TeamCodeHub\Database\ConnectionFactory;
use TeamCodeHub\Database\AdminAccountRepository;
use TeamCodeHub\Database\ChatRepository;
use TeamCodeHub\Database\ChatReportRepository;
use TeamCodeHub\Database\DashboardRepository;
use TeamCodeHub\Database\EmailVerificationRepository;
use TeamCodeHub\Database\PasswordResetRepository;
use TeamCodeHub\Services\EmailDeliveryService;
use TeamCodeHub\Database\GitHubActivityCacheRepository;
use TeamCodeHub\Database\GitHubRepositoryCacheRepository;
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
use TeamCodeHub\Services\GitHubRepositoryService;

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
    $assert(count($applied) >= 13, 'all versioned migrations are applied to MariaDB');
    $assert($runner->migrate() === [], 'migration runner is idempotent on a migrated schema');

    // Exercise email verification directly without invoking the server's real mail transport.
    $verificationUserId = (new UserRepository($pdo))->createPendingAccount(
        'email-verification@example.test',
        password_hash('A-Strong-Verification-Password-48', PASSWORD_DEFAULT),
        'Verification Fixture'
    );
    $assert(is_int($verificationUserId) && $verificationUserId > 0, 'registration creates a pending account before email verification');
    SecureSession::start();
    $pendingAuthentication = new AuthenticationService(new UserRepository($pdo));
    $assert(!$pendingAuthentication->authenticate('email-verification@example.test', 'A-Strong-Verification-Password-48'), 'pending accounts cannot sign in before email verification');
    $duplicateVerificationUser = (new UserRepository($pdo))->createPendingAccount(
        'email-verification@example.test',
        password_hash('A-Strong-Verification-Password-49', PASSWORD_DEFAULT),
        'Duplicate Fixture'
    );
    $assert($duplicateVerificationUser === null, 'duplicate registration emails do not create a second account');

    $verificationRepository = new EmailVerificationRepository($pdo);
    $verificationToken = bin2hex(random_bytes(32));
    $verificationHash = hash('sha256', $verificationToken);
    $assert($verificationRepository->issueToken($verificationUserId, $verificationHash), 'pending account receives a hashed verification token');
    $storedVerificationHash = $pdo->prepare("SELECT token_hash FROM account_action_tokens WHERE user_id = :id AND purpose = 'email_verification' LIMIT 1");
    $storedVerificationHash->execute(['id' => $verificationUserId]);
    $assert($storedVerificationHash->fetchColumn() === $verificationHash && $verificationHash !== $verificationToken, 'database stores only the SHA-256 token hash');
    $secondVerificationHash = hash('sha256', bin2hex(random_bytes(32)));
    $assert(!$verificationRepository->issueToken($verificationUserId, $secondVerificationHash), 'verification resend cooldown blocks repeated token issuance');
    $assert($verificationRepository->verifyToken($verificationHash), 'valid one-time token activates the pending account');
    $verifiedAccount = $pdo->prepare('SELECT status, email_verified_at FROM users WHERE id = :id');
    $verifiedAccount->execute(['id' => $verificationUserId]);
    $verifiedRow = $verifiedAccount->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($verifiedRow) && $verifiedRow['status'] === 'active' && $verifiedRow['email_verified_at'] !== null, 'verification commits active status and email timestamp together');
    $assert(!$verificationRepository->verifyToken($verificationHash), 'email verification tokens cannot be replayed');
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $verificationUserId]);

    $expiredUserId = (new UserRepository($pdo))->createPendingAccount(
        'expired-verification@example.test',
        password_hash('A-Strong-Expired-Password-50', PASSWORD_DEFAULT),
        'Expired Verification Fixture'
    );
    if (!is_int($expiredUserId) || $expiredUserId < 1) {
        throw new RuntimeException('Expired verification fixture could not be created.');
    }
    $expiredToken = bin2hex(random_bytes(32));
    $expiredHash = hash('sha256', $expiredToken);
    $assert($verificationRepository->issueToken($expiredUserId, $expiredHash), 'second pending account can receive a separate token');
    $pdo->prepare("UPDATE account_action_tokens SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) WHERE token_hash = :hash")
        ->execute(['hash' => $expiredHash]);
    $assert(!$verificationRepository->verifyToken($expiredHash), 'expired email verification tokens cannot activate accounts');
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $expiredUserId]);

    // A non-HTTPS base URL must be rejected before PHP's mail transport is invoked.
    $previousAppUrl = getenv('APP_URL');
    $previousMailFrom = getenv('MAIL_FROM_ADDRESS');
    putenv('APP_URL=http://example.test');
    putenv('MAIL_FROM_ADDRESS=notifications@example.test');
    $mailDelivery = new EmailDeliveryService();
    $assert(!$mailDelivery->sendVerificationEmail('recipient@example.test', bin2hex(random_bytes(32))), 'verification email delivery rejects non-HTTPS application URLs');
    $assert(!$mailDelivery->sendPasswordResetEmail('recipient@example.test', bin2hex(random_bytes(32))), 'password reset email delivery rejects non-HTTPS application URLs');
    $previousAppUrl === false ? putenv('APP_URL') : putenv('APP_URL=' . $previousAppUrl);
    $previousMailFrom === false ? putenv('MAIL_FROM_ADDRESS') : putenv('MAIL_FROM_ADDRESS=' . $previousMailFrom);

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
    // Project repository integration uses an injected transport; tests never call GitHub.
    $repositoryCache = new GitHubRepositoryCacheRepository($pdo);
    $repositoryFetchCount = 0;
    $repositoryFetcher = static function (string $repository) use (&$repositoryFetchCount): array {
        $repositoryFetchCount++;
        return [
            'description' => 'Fixture repository',
            'stars' => 12,
            'forks' => 3,
            'open_issues' => 4,
            'default_branch' => 'main',
            'language' => 'PHP',
            'pushed_at' => '2026-10-17T12:00:00Z',
            'commits' => [[
                'sha' => 'abcdef1234567',
                'message' => 'Integration fixture commit',
                'author' => 'Fixture Author',
                'committed_at' => '2026-10-17T12:00:00Z',
            ]],
        ];
    };
    $repositoryService = new GitHubRepositoryService($repositoryCache, $repositoryFetcher);
    $repositoryDate = gmdate('Y-m-d');
    $repositoryFirst = $repositoryService->repository('integration-fixture/repository', $repositoryDate);
    $repositorySecond = $repositoryService->repository('integration-fixture/repository', $repositoryDate);
    $assert($repositoryFetchCount === 1, 'project repository cache permits one refresh per repository per UTC day');
    $assert($repositoryFirst['available'] && $repositoryFirst['data']['stars'] === 12
        && $repositoryFirst['data']['commits'][0]['message'] === 'Integration fixture commit', 'public repository metadata and recent commits are normalized into the cache');
    $assert($repositorySecond['data'] === $repositoryFirst['data'], 'subsequent project repository reads reuse the cached snapshot');

    $repositoryFailureService = new GitHubRepositoryService($repositoryCache, static function (string $repository): array {
        throw new RuntimeException('Simulated public GitHub API outage.');
    });
    $nextRepositoryDate = gmdate('Y-m-d', strtotime($repositoryDate . ' +1 day UTC'));
    $staleRepository = $repositoryFailureService->repository('integration-fixture/repository', $nextRepositoryDate);
    $assert($staleRepository['stale'] && $staleRepository['data']['stars'] === 12, 'failed repository refresh preserves the last good public snapshot');
    $assert(GitHubRepositoryService::parseRepositoryReference('https://github.com/Example-Org/sample-repo.git/') === 'example-org/sample-repo', 'repository URL parser canonicalizes a public GitHub URL');
    $assert(GitHubRepositoryService::parseRepositoryReference('https://github.com.evil.test/Example-Org/sample-repo') === null
        && GitHubRepositoryService::parseRepositoryReference('http://github.com/Example-Org/sample-repo') === null
        && GitHubRepositoryService::parseRepositoryReference('https://github.com/Example-Org/../settings') === null,
        'repository URL parser rejects alternate hosts, insecure schemes, and traversal paths');

    // The shared daily budget is global so an attacker cannot exhaust the unauthenticated API quota through many projects.
    $budgetClaimsSucceeded = true;
    for ($budgetIndex = 1; $budgetIndex <= 19; $budgetIndex++) {
        $budgetClaim = $repositoryCache->claimDailyAttempt('budget-fixture/repository-' . $budgetIndex, $nextRepositoryDate);
        $budgetClaimsSucceeded = $budgetClaimsSucceeded && $budgetClaim['claimed'];
    }
    $budgetOverflow = $repositoryCache->claimDailyAttempt('budget-fixture/overflow', $nextRepositoryDate);
    $assert($budgetClaimsSucceeded && $budgetOverflow['rate_limited'] && !$budgetOverflow['claimed'], 'public repository refresh budget caps outbound refreshes at 20 repositories per UTC day');


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

    // Exercise password recovery without invoking the server's real mail transport.
    $resetUserId = $insertUser('password-reset@example.test', 'Password Reset Fixture', 'Old-Password-For-Reset-41');
    $passwordReset = new PasswordResetRepository($pdo);
    $resetToken = bin2hex(random_bytes(32));
    $resetHash = hash('sha256', $resetToken);
    $issuedResetEmail = $passwordReset->issueForEmail('password-reset@example.test', $resetHash);
    $assert($issuedResetEmail === 'password-reset@example.test', 'active verified account can receive a password reset token');
    $storedReset = $pdo->prepare("SELECT token_hash, expires_at FROM account_action_tokens WHERE user_id = :user_id AND purpose = 'password_reset' LIMIT 1");
    $storedReset->execute(['user_id' => $resetUserId]);
    $storedResetRow = $storedReset->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($storedResetRow) && $storedResetRow['token_hash'] === $resetHash && $resetHash !== $resetToken, 'password reset persists only the token hash');
    $resetExpirySeconds = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, expires_at) FROM account_action_tokens WHERE token_hash = :hash");
    $resetExpirySeconds->execute(['hash' => $resetHash]);
    $resetTtl = (int) $resetExpirySeconds->fetchColumn();
    $assert($resetTtl > 1700 && $resetTtl <= 1800, 'password reset tokens expire after 30 minutes');
    $assert($passwordReset->issueForEmail('password-reset@example.test', hash('sha256', bin2hex(random_bytes(32)))) === null, 'password reset issuance enforces a per-account cooldown');
    $assert($passwordReset->issueForEmail('unknown-reset@example.test', hash('sha256', bin2hex(random_bytes(32)))) === null, 'unknown accounts do not receive password reset tokens');
    $versionBeforeReset = $pdo->prepare('SELECT auth_version FROM users WHERE id = :id');
    $versionBeforeReset->execute(['id' => $resetUserId]);
    $initialAuthVersion = (int) $versionBeforeReset->fetchColumn();
    $newResetPassword = 'New-Unique-Password-For-Reset-52';
    $newResetHash = password_hash($newResetPassword, PASSWORD_DEFAULT);
    $assert(is_string($newResetHash) && $passwordReset->resetPassword($resetHash, $newResetHash), 'valid password reset token changes the password transactionally');
    $versionAfterReset = $pdo->prepare('SELECT auth_version FROM users WHERE id = :id');
    $versionAfterReset->execute(['id' => $resetUserId]);
    $assert((int) $versionAfterReset->fetchColumn() === $initialAuthVersion + 1, 'password reset increments the account authentication version to revoke older sessions');
    $assert(!$passwordReset->resetPassword($resetHash, password_hash('Another-Reset-Password-53', PASSWORD_DEFAULT)), 'password reset token cannot be replayed');
    $resetAuthentication = new AuthenticationService(new UserRepository($pdo));
    $assert($resetAuthentication->authenticate('password-reset@example.test', $newResetPassword), 'new password works after a successful reset');
    $assert(!$resetAuthentication->authenticate('password-reset@example.test', 'Old-Password-For-Reset-41'), 'old password stops working after a successful reset');
    $_SESSION['user_id'] = $resetUserId;
    $_SESSION['auth_version'] = $initialAuthVersion;
    $assert($resetAuthentication->currentUser() === null, 'session with a pre-reset authentication version is rejected');
    SecureSession::start();
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $resetUserId]);

    $pdo->prepare("INSERT INTO user_system_roles (user_id, role) VALUES (:user_id, 'site_admin')")->execute(['user_id' => $adminId]);

    $userDirectory = new UserRepository($pdo);
    $adminSearch = $userDirectory->searchForSiteAdmin($adminId, 'admin@example.test', 'active');
    $assert(count($adminSearch['users']) === 1 && $adminSearch['users'][0]['id'] === $adminId, 'site admin can search the directory by email');
    $assert(!array_key_exists('password_hash', $adminSearch['users'][0]), 'admin user directory never exposes password hashes');
    $assert($adminSearch['users'][0]['email_verified'] === true && !array_key_exists('email_verified_at', $adminSearch['users'][0]), 'admin directory exposes only a boolean email-verification state');
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
        && $statusAudit[1]['action'] === 'account_disabled'
        && $statusAudit[0]['reason'] === 'Integration reactivation test', 'status changes create ordered audit records with reasons');
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
    $projects->updateGitHubRepositoryForTeamManager($adminId, $projectId, 'example-org/sample-repo');
    $linkedProject = $projects->findAccessibleByUser($adminId, $projectId);
    $assert(is_array($linkedProject) && $linkedProject['github_repository'] === 'example-org/sample-repo', 'team owner can link a public GitHub repository to a project');
    $unauthorizedRepositoryLinkRejected = false;
    try {
        $projects->updateGitHubRepositoryForTeamManager($outsiderId, $projectId, 'example-org/unauthorized-repo');
    } catch (DomainException) {
        $unauthorizedRepositoryLinkRejected = true;
    }
    $assert($unauthorizedRepositoryLinkRejected, 'unrelated users cannot change a project repository link');
    $projects->updateGitHubRepositoryForTeamManager($adminId, $projectId, null);
    $unlinkedProject = $projects->findAccessibleByUser($adminId, $projectId);
    $assert(is_array($unlinkedProject) && $unlinkedProject['github_repository'] === null, 'team owner can unlink a project repository without deleting project history');
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
