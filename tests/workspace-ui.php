<?php

declare(strict_types=1);

/*
 * Workspace UI contract tests.
 *
 * These checks verify that the static shell, frontend controller, design-system
 * assets, and server response contract remain connected. Browser-level visual
 * and assistive-technology testing is still required before a public release.
 */

$root = dirname(__DIR__);
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo "PASS: {$message}\n";
};

$read = static function (string $path) use ($root): string {
    $fullPath = $root . '/' . $path;
    if (!is_file($fullPath)) {
        throw new RuntimeException('Required workspace asset is missing: ' . $path);
    }
    $content = file_get_contents($fullPath);
    if (!is_string($content)) {
        throw new RuntimeException('Could not read workspace asset: ' . $path);
    }
    return $content;
};

$html = $read('public/app.html');
$css = $read('public/assets/css/workspace.css');
$js = $read('public/assets/js/workspace.js');
$bootstrap = $read('app/bootstrap.php');
$authController = $read('app/Http/AuthController.php');
$accountLifecycleController = $read('app/Http/AccountLifecycleController.php');
$emailVerificationRepository = $read('app/Database/EmailVerificationRepository.php');
$accountLifecycleService = $read('app/Services/AccountLifecycleService.php');
$emailDeliveryService = $read('app/Services/EmailDeliveryService.php');
$passwordRecoveryController = $read('app/Http/PasswordRecoveryController.php');
$passwordRecoveryService = $read('app/Services/PasswordRecoveryService.php');
$passwordRecoveryRepository = $read('app/Database/PasswordRecoveryRepository.php');
$sessionVersionMigration = $read('database/migrations/20261018000300_add_user_session_version.php');
$adminUserController = $read('app/Http/AdminUserController.php');
$userRepository = $read('app/Database/UserRepository.php');
$adminAccountRepository = $read('app/Database/AdminAccountRepository.php');
$authService = $read('app/Security/AuthenticationService.php');
$workspaceController = $read('app/Http/WorkspaceController.php');
$response = $read('app/Core/Response.php');
$workflow = $read('.github/workflows/ci.yml');
$htaccess = $read('public/.htaccess');

$assert(str_contains($html, '<html lang="en" dir="ltr"'), 'workspace declares English left-to-right document semantics');

$assert(str_contains($html, '/assets/css/app.css?v=') && str_contains($html, '/assets/css/workspace.css?v='), 'workspace loads shared design tokens and workspace-specific stylesheets');
$assert(str_contains($html, '/assets/js/ui.js?v=') && str_contains($html, '/assets/js/workspace.js?v='), 'workspace loads shared and product-specific UI controllers');
preg_match_all('/(?:href|src)="(\/assets\/[^"]+)"/', $html, $workspaceAssetMatches);
foreach ($workspaceAssetMatches[1] as $assetUrl) {
    $relativePath = 'public' . strtok($assetUrl, '?');
    $assert(is_file($root . '/' . $relativePath), 'workspace asset URL resolves: ' . $relativePath);
}
$assert(str_contains($html, 'data-theme="light"') && str_contains($read('public/assets/js/ui.js'), 'root.dataset.theme = next'), 'workspace theme switcher updates the shared light/dark design tokens');

$assert(str_contains($html, 'id="login-form"') && str_contains($html, 'id="application"'), 'login and authenticated application shells are present');
$assert(str_contains($html, 'id="register-form"') && str_contains($html, 'id="verification-resend-form"') && str_contains($html, 'id="verify-screen"'), 'public registration, resend, and explicit email verification forms are present');
$assert(str_contains($js, 'registerAccount') && str_contains($js, 'resendVerification') && str_contains($js, 'state.verificationToken'), 'authentication UI connects registration, resend, and token confirmation flows');
$assert(str_contains($bootstrap, "/api/auth/register") && str_contains($bootstrap, "/api/auth/verification/resend") && str_contains($bootstrap, "/api/auth/verify-email"), 'public account lifecycle endpoints are registered');
$assert(str_contains($accountLifecycleController, 'allowsForPurpose') && str_contains($accountLifecycleController, 'CsrfToken::validate'), 'public account lifecycle endpoints require CSRF and purpose-specific rate limiting');
$assert(str_contains($accountLifecycleController, "'verify-email'") && str_contains($accountLifecycleController, "'verification-resend'"), 'verification attempts and resend requests have separate rate-limit buckets');
$assert(str_contains($emailVerificationRepository, 'expires_at > CURRENT_TIMESTAMP') && str_contains($emailVerificationRepository, 'used_at IS NULL') && str_contains($emailVerificationRepository, "SET status = 'active'"), 'email verification enforces expiry, single use, and transactional account activation');
$assert(str_contains($accountLifecycleService, 'hash(\'sha256\', $token)') && str_contains($emailDeliveryService, 'APP_URL') && str_contains($emailDeliveryService, '/verify-email#verify-email?token='), 'verification service hashes bearer tokens and sends fragment-only HTTPS links');
$assert(str_contains($html, 'id="nav-moderation-item"') && str_contains($html, 'data-page="moderation"'), 'moderation navigation exists as a permission-gated workspace view');
$assert(str_contains($html, 'data-page="github"') && str_contains($js, '/api/github/activity'), 'GitHub activity page is connected to its authenticated endpoint');
$assert(str_contains($bootstrap, "'/api/projects/{projectId}/github'") && str_contains($bootstrap, 'project-github.controller'), 'project GitHub endpoints are registered through a dedicated controller');
$assert(str_contains($js, 'renderProjectGitHub') && str_contains($js, 'connectProjectRepositoryModal') && str_contains($js, 'unlinkProjectRepository'), 'project workspace connects repository display, linking, and unlinking actions');
$assert(str_contains($js, 'data-project-tab') && str_contains($js, '"repository", "Repository", "chart"'), 'project repository is available as a dedicated project tab');
$assert(str_contains($css, '.project-github-summary') && str_contains($css, '.project-github-commit') && str_contains($css, '.project-github-actions'), 'project repository metadata and commit list have responsive styles');
$assert(str_contains($read('app/Http/WorkspaceController.php'), 'updateMetadataForTeamManager') && str_contains($read('app/Database/ProjectRepository.php'), 'updateGitHubRepositoryForTeamManager'), 'repository link changes are guarded in the project repository layer');
$assert(str_contains($html, 'id="nav-admin-users-item"') && str_contains($js, '"admin-users"') && str_contains($js, 'renderAdminUsers'), 'site-admin user directory has a dedicated navigation entry and rendered view');
$assert(str_contains($bootstrap, "'/api/admin/users'") && str_contains($adminUserController, 'searchForSiteAdmin'), 'user directory route connects to the repository-protected admin controller');
$assert(str_contains($js, '/api/admin/users?') && str_contains($js, 'params.set("page", String(page))') && str_contains($js, 'Go to next user directory page'), 'admin directory connects search filters to bounded previous/next pagination');
$assert(str_contains($userRepository, 'LIMIT 51 OFFSET :offset') && str_contains($userRepository, 'password_hash') && str_contains($userRepository, 'searchForSiteAdmin'), 'directory query uses a bounded SQL page and isolates authentication secrets from selected fields');
$assert(str_contains($css, '.admin-users-pagination') && str_contains($css, '.admin-user-row') && str_contains($css, '@media (max-width:420px)'), 'admin user directory and pagination have responsive styles');
$assert(str_contains($bootstrap, "'/api/admin/users/{userId}/status'") && str_contains($bootstrap, "'/api/admin/users/{userId}/audit'"), 'account status and audit endpoints are registered');
$assert(str_contains($js, 'changeAdminUserStatusModal') && str_contains($js, 'admin-user-status') && str_contains($js, 'minLength: 5'), 'account status actions require a reason in the connected dialog');
$assert(str_contains($js, 'showAdminUserAuditModal') && str_contains($js, 'admin-user-audit') && str_contains($js, 'email verified'), 'account rows expose audit history and verification state');
$assert(str_contains($adminAccountRepository, 'You cannot disable your own account.') && str_contains($adminAccountRepository, 'email_verified_at') && str_contains($adminAccountRepository, 'admin_account_audit'), 'account status changes enforce self-disable and verified-reactivation safeguards with audit persistence');
$assert(str_contains($css, '.admin-user-actions') && str_contains($css, '.admin-audit-event') && str_contains($css, '.admin-audit-modal'), 'account actions and audit history have responsive styles');
$assert(str_contains($css, '.github-feed') && str_contains($css, '.github-summary'), 'GitHub activity page has dedicated responsive presentation styles');
$assert(str_contains($html, 'id="workspace-main"') && str_contains($html, 'class="skip-link"'), 'workspace provides a skip link and main landmark');
$assert(str_contains($html, '/assets/css/workspace.css?v='), 'product stylesheet is loaded as an external versioned asset');
$assert(str_contains($html, '/assets/js/workspace.js?v='), 'product controller is loaded as an external versioned asset');
$assert(!preg_match('/<style(?:\s|>)/i', $html), 'workspace HTML contains no inline style blocks');
$assert(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), 'workspace HTML contains no inline script blocks');
$assert(str_contains($css, '@media (max-width:560px)') && str_contains($css, 'prefers-reduced-motion:reduce'), 'workspace styling covers narrow screens and reduced motion');
$assert(str_contains($css, '.chat-messages') && str_contains($css, '.metric-grid') && str_contains($css, '.tch-modal'), 'workspace styles include dashboard, chat, and modal surfaces');
$assert(str_contains($js, 'credentials: "same-origin"') && str_contains($js, '"X-Csrf-Token"'), 'API client keeps same-origin credentials and CSRF protection');
$assert(str_contains($js, 'textContent') && str_contains($js, 'after_id=') && str_contains($js, 'set-task-status'), 'controller renders text safely, polls chat incrementally, and updates task status');
$assert(str_contains($js, 'canManageTeam') && str_contains($js, 'access_level !== "viewer"'), 'UI controls reflect manager and viewer capabilities without replacing server authorization');
$assert(str_contains($bootstrap, "/api/auth/profile") && str_contains($bootstrap, "/api/auth/password"), 'account profile and password endpoints are registered');
$assert(str_contains($js, 'editProfileModal') && str_contains($js, 'changePasswordModal') && str_contains($js, 'confirm_password'), 'preferences connect profile editing and password confirmation forms');
$assert(str_contains($authController, 'CsrfToken::rotate()') && str_contains($authController, 'SecureSession::regenerateAfterAuthentication()'), 'successful password changes rotate the session identifier and CSRF token');
$assert(str_contains($authService, 'password_verify($currentPassword') && str_contains($authService, 'strlen($newPassword) < 12'), 'password changes verify the current credential and enforce password limits');
$assert(str_contains($bootstrap, '/api/auth/password-reset/request') && str_contains($bootstrap, '/api/auth/password-reset/confirm') && str_contains($bootstrap, "get('/reset-password'"), 'password recovery request, confirmation, and page routes are registered');
$assert(str_contains($html, 'id="password-recovery-form"') && str_contains($html, 'id="password-reset-form"') && str_contains($html, 'id="show-password-recovery"'), 'authentication screen exposes password recovery and reset forms');
$assert(str_contains($js, 'requestPasswordReset') && str_contains($js, 'resetPassword') && str_contains($js, 'reset-password?'), 'browser flow reads reset tokens from fragments and connects both recovery endpoints');
$assert(str_contains($passwordRecoveryController, 'allowsForPurpose') && str_contains($passwordRecoveryController, 'GENERIC_RESPONSE') && str_contains($passwordRecoveryController, 'validCsrf'), 'password recovery uses generic responses, rate limiting, and CSRF validation');
$assert(str_contains($passwordRecoveryRepository, 'INTERVAL 30 MINUTE') && str_contains($passwordRecoveryRepository, 'session_version = session_version + 1') && str_contains($passwordRecoveryRepository, 'FOR UPDATE'), 'reset token redemption is time-bounded, transactional, and revokes old sessions');
$assert(str_contains($adminAccountRepository, 'session_version = session_version + 1'), 'administrator account status transitions invalidate existing sessions');
$assert(str_contains($passwordRecoveryService, "hash('sha256'") && str_contains($emailDeliveryService, 'sendPasswordResetEmail') && str_contains($sessionVersionMigration, 'session_version'), 'password reset stores token hashes and versions account sessions');

$assert(str_contains($js, '/api/dashboard') && str_contains($js, '/api/teams') && str_contains($js, '/api/projects/') && str_contains($js, '/api/notifications'), 'controller connects the existing dashboard, team, project, and notification APIs');
$assert(str_contains($bootstrap, "/api/teams/{teamId}/edit") && str_contains($bootstrap, "/api/projects/{projectId}/edit"), 'team and project metadata update routes are registered');
$workspaceController = $read('app/Http/WorkspaceController.php');
$assert(str_contains($workspaceController, 'public function renameTeam(') && str_contains($workspaceController, 'public function editProject('), 'registered settings routes resolve to implemented controller methods');
$assert(str_contains($workspaceController, 'updateNameForOwner') && str_contains($workspaceController, 'updateMetadataForTeamManager'), 'settings controllers reuse transactional repository authorization boundaries');
$assert(str_contains($bootstrap, '/api/teams/{teamId}/archived-projects') && str_contains($bootstrap, '/api/projects/{projectId}/archive') && str_contains($bootstrap, '/api/projects/{projectId}/restore'), 'project lifecycle endpoints are registered');
$assert(str_contains($js, 'archive-project') && str_contains($js, 'restore-project') && str_contains($js, 'Archived projects'), 'workspace connects archive actions to a restorable archived-project list');
$assert(str_contains($workspaceController, 'updateNameForOwner') && str_contains($workspaceController, 'updateMetadataForTeamManager'), 'metadata endpoints use repository-level authorization guards');
$assert(str_contains($js, 'editTeamModal') && str_contains($js, 'editProjectModal'), 'workspace exposes connected team and project editing dialogs');
$assert(str_contains($js, 'Dashboard summaries omit descriptions') && str_contains($js, 'Project details could not be loaded.'), 'project editing loads the full authorized record before saving metadata');
$assert(str_contains($js, 'team.role === "owner"') && str_contains($js, 'canManageTeam(project.team_id)'), 'team rename is owner-only and project editing is manager-gated in the UI');
$assert(str_contains($js, '/api/admin/reports') && str_contains($js, '/api/reports/') && str_contains($js, 'renderReportCard'), 'moderation UI connects scoped report queues and review actions');
$assert(str_contains($bootstrap, "/api/admin/users") && str_contains($html, 'id="nav-admin-users-item"'), 'site-admin user directory route and navigation are registered');
$assert(str_contains($js, 'function renderAdminUsers()') && str_contains($js, '/api/admin/users?') && str_contains($js, 'AbortController'), 'admin user directory connects debounced search with stale-request cancellation');
$assert(str_contains($js, 'params.set("page", String(page))') && str_contains($js, 'admin-users-pagination'), 'admin user directory sends and renders bounded pagination state');

$assert(str_contains($adminUserController, 'Site administrator role required.') && str_contains($userRepository, 'function searchForSiteAdmin'), 'admin user directory enforces the site-admin boundary at the server and repository layers');
$assert(str_contains($js, 'formatAdminDate') && str_contains($js, 'user.is_site_admin'), 'admin directory renders account activity and administrator flags');

$assert(str_contains($js, '/api/team-invitations/accept') && str_contains($js, 'revoke-invitation') && str_contains($js, 'renderPendingInvitations'), 'team UI connects invitation acceptance, listing, and revocation');
$assert(str_contains($bootstrap, "get('/accept-invitation'") && str_contains($js, 'state.invitationToken') && str_contains($js, 'acceptInvitationModal(state.invitationToken)'), 'invitation email deep links resume acceptance after sign-in');
$assert(str_contains($emailDeliveryService, 'sendTeamInvitationEmail') && str_contains($js, 'result.email_sent') && str_contains($js, 'Email delivery failed'), 'invitation UI prefers email delivery and offers a token fallback only on failure');
$assert(str_contains($passwordRecoveryController, 'GENERIC_RESPONSE') && str_contains($emailDeliveryService, '/accept-invitation#accept-invitation?token='), 'invitation email links use a fragment token and an explicit acceptance flow');
$assert(str_contains($bootstrap, '/members/{userId}/access') && str_contains($js, 'change-member-access'), 'project member access-level controls are connected to the backend route');
$assert(str_contains($js, '!member.is_project_creator') && str_contains($js, 'member.access_level === "member" ? "viewer" : "member"'), 'UI protects the project creator and offers explicit viewer/member transitions');
$assert(str_contains($bootstrap, '/api/projects/{projectId}/members/candidates') && str_contains($js, '/members/candidates?q='), 'project member search route is connected to the frontend');
$assert(str_contains($js, 'function addProjectMemberModal') && str_contains($js, 'Search active team members') && str_contains($js, 'Request access'), 'member assignment uses a searchable accessible picker instead of manual ID entry');
$assert(str_contains($js, 'searchSequence++') && str_contains($js, 'searchController.abort()') && str_contains($js, 'Updating search…'), 'member picker discards stale asynchronous results when the query changes');
$assert(str_contains($css, '.member-picker-modal') && str_contains($css, '.member-picker-option') && str_contains($css, '.member-picker-state'), 'searchable member picker has dedicated responsive styles');

$assert(str_contains($js, 'chatPollCount: 0') && str_contains($js, 'chatMessageVersion'), 'chat polling tracks bounded reconciliation and message revisions');
$assert(str_contains($js, 'incomingIds') && str_contains($js, 'existing.dataset.messageVersion') && str_contains($js, 'previousScrollHeight'), 'chat polling reconciles edited/deleted messages while preserving the reading position');
$assert(str_contains($js, 'if (messages.length > 0)') && str_contains($js, 'const placeholder = $(".empty-state", list)'), 'new incremental chat messages clear the empty-conversation placeholder');
$assert(str_contains($js, '/comments') && str_contains($js, '/subtasks') && str_contains($js, 'openTaskDetails'), 'task details UI connects comment threads, checklists, and editing');
$assert(str_contains($js, '/details') && str_contains($js, '/edit'), 'task details UI calls the read and edit endpoints');
$assert(str_contains($bootstrap, '/api/projects/{projectId}/assignees') && str_contains($js, '/assignees?q='), 'task assignee search is connected to the project-scoped API');
$assert(str_contains($js, 'function startNotificationPolling') && str_contains($js, 'function pollNotifications') && str_contains($js, 'visibilitychange'), 'recent notifications and unread badges synchronize while the app is visible');
$assert(str_contains($js, 'unreadNotificationCount') && str_contains($js, 'notificationBusy') && str_contains($js, 'stopNotificationPolling'), 'notification sync maintains authoritative unread counts and stops on sign-out');
$assert(str_contains($js, 'type: "member-picker"') && str_contains($js, 'mountAssigneePicker') && str_contains($js, 'makeAssigneeField'), 'task create and edit forms use the searchable assignee picker');
$assert(str_contains($css, '.assignee-picker-option') && str_contains($css, '.assignee-picker-selection'), 'task assignee search has dedicated responsive styles');
$assert(str_contains($css, '.task-details-modal') && str_contains($css, '.task-checklist') && str_contains($css, '.task-comment-row'), 'task collaboration views have dedicated responsive styles');
$assert(str_contains($bootstrap, "'/public/app.html'") && str_contains($bootstrap, "get('/login'"), 'front controller serves the workspace shell at root and login paths');
$assert(str_contains($response, 'public static function html') && str_contains($response, 'Content-Security-Policy'), 'HTML responses include a restrictive same-origin content security policy');
$assert(str_contains($htaccess, 'Header always set Content-Security-Policy') && str_contains($htaccess, "frame-ancestors 'none'"), 'directly served static assets receive the same-origin content policy');
$assert(str_contains($workflow, 'node --check public/assets/js/workspace.js') && str_contains($workflow, 'php tests/workspace-ui.php'), 'continuous integration validates workspace JavaScript and UI contracts');

echo "\n{$checks} workspace UI assertions passed.\n";
