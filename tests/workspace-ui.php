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
$authService = $read('app/Security/AuthenticationService.php');
$response = $read('app/Core/Response.php');
$workflow = $read('.github/workflows/ci.yml');
$htaccess = $read('public/.htaccess');

$assert(str_contains($html, '<html lang="en" dir="ltr"'), 'workspace declares English left-to-right document semantics');

$assert(str_contains($html, '/assets/css/app.css?v=0.2.0-dev') && str_contains($html, '/assets/css/workspace.css?v=0.2.0-dev'), 'workspace loads shared design tokens and workspace-specific stylesheets');
$assert(str_contains($html, '/assets/js/ui.js?v=0.2.0-dev') && str_contains($html, '/assets/js/workspace.js?v=0.2.0-dev'), 'workspace loads the shared UI controller and product controller');
preg_match_all('/(?:href|src)="(\/assets\/[^"]+)"/', $html, $workspaceAssetMatches);
foreach ($workspaceAssetMatches[1] as $assetUrl) {
    $relativePath = 'public' . strtok($assetUrl, '?');
    $assert(is_file($root . '/' . $relativePath), 'workspace asset URL resolves: ' . $relativePath);
}
$assert(str_contains($html, 'data-theme="light"') && str_contains($read('public/assets/js/ui.js'), 'root.dataset.theme = next'), 'workspace theme switcher updates the shared light/dark design tokens');

$assert(str_contains($html, 'id="login-form"') && str_contains($html, 'id="application"'), 'login and authenticated application shells are present');
$assert(str_contains($html, 'id="nav-moderation-item"') && str_contains($html, 'data-page="moderation"'), 'moderation navigation exists as a permission-gated workspace view');
$assert(str_contains($html, 'data-page="github"') && str_contains($js, '/api/github/activity'), 'GitHub activity page is connected to its authenticated endpoint');
$assert(str_contains($css, '.github-feed') && str_contains($css, '.github-summary'), 'GitHub activity page has dedicated responsive presentation styles');
$assert(str_contains($html, 'id="workspace-main"') && str_contains($html, 'class="skip-link"'), 'workspace provides a skip link and main landmark');
$assert(str_contains($js, 'editTeamModal') && str_contains($js, '"/edit"'), 'workspace settings dialogs call the team and project edit endpoints');
$assert(str_contains($bootstrap, "/api/teams/{teamId}/edit") && str_contains($bootstrap, "/api/projects/{projectId}/edit"), 'team and project settings routes are registered');
$assert(str_contains($js, 'if (team.role === "owner")') && str_contains($js, 'canManageTeam(project.team_id)'), 'team rename is owner-only while project settings support team managers');
$assert(str_contains($js, 'actionButton("Edit team", "edit-team"') && str_contains($js, 'actionButton("Edit project", "edit-project"'), 'team and project detail headers expose the settings actions');
$assert(str_contains($js, 'async function editProjectModal') && str_contains($js, 'Update project details without changing its members'), 'project settings dialog explains the non-destructive metadata-only operation');
$assert(str_contains($bootstrap, "->updateTeam(\$request, \$params)") && str_contains($bootstrap, "->updateProject(\$request, \$params)"), 'settings routes reuse the existing authorized update handlers');
$assert(str_contains($read('app/Http/WorkspaceController.php'), 'updateNameForOwner') && str_contains($read('app/Http/WorkspaceController.php'), 'updateMetadataForTeamManager'), 'settings endpoints rely on transactional repository authorization');

$assert(str_contains($js, 'data-action="edit-team"') === false && str_contains($js, 'edit-project'), 'workspace controller wires team and project settings actions');
$assert(str_contains($html, '/assets/css/workspace.css?v=0.2.0-dev'), 'product stylesheet is loaded as an external versioned asset');
$assert(str_contains($html, '/assets/js/workspace.js?v=0.2.0-dev'), 'product controller is loaded as an external versioned asset');
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
$assert(str_contains($authService, 'password_verify($currentPassword') && str_contains($authService, 'strlen($newPassword) < 12'), 'password changes verify the current credential and enforce a 12-character minimum');

$assert(str_contains($js, '/api/dashboard') && str_contains($js, '/api/teams') && str_contains($js, '/api/projects/') && str_contains($js, '/api/notifications'), 'controller connects the existing dashboard, team, project, and notification APIs');
$assert(str_contains($js, '/api/admin/reports') && str_contains($js, '/api/reports/') && str_contains($js, 'renderReportCard'), 'moderation UI connects scoped report queues and review actions');
$assert(str_contains($js, '/api/team-invitations/accept') && str_contains($js, 'revoke-invitation') && str_contains($js, 'renderPendingInvitations'), 'team UI connects invitation acceptance, listing, and revocation');
$assert(str_contains($js, '/comments') && str_contains($js, '/subtasks') && str_contains($js, 'openTaskDetails'), 'task details UI connects comment threads, checklists, and editing');
$assert(str_contains($js, '/details') && str_contains($js, '/edit'), 'task details UI calls the read and edit endpoints');
$assert(str_contains($css, '.task-details-modal') && str_contains($css, '.task-checklist') && str_contains($css, '.task-comment-row'), 'task collaboration views have dedicated responsive styles');
$assert(str_contains($bootstrap, "'/public/app.html'") && str_contains($bootstrap, "get('/login'"), 'front controller serves the workspace shell at root and login paths');
$assert(str_contains($response, 'public static function html') && str_contains($response, 'Content-Security-Policy'), 'HTML responses include a restrictive same-origin content security policy');
$assert(str_contains($htaccess, 'Header always set Content-Security-Policy') && str_contains($htaccess, "frame-ancestors 'none'"), 'directly served static assets receive the same-origin content policy');
$assert(str_contains($workflow, 'node --check public/assets/js/workspace.js') && str_contains($workflow, 'php tests/workspace-ui.php'), 'continuous integration validates workspace JavaScript and UI contracts');

echo "\n{$checks} workspace UI assertions passed.\n";
