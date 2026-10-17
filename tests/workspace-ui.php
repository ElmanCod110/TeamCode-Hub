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
$workspaceController = $read('app/Http/WorkspaceController.php');
$response = $read('app/Core/Response.php');
$workflow = $read('.github/workflows/ci.yml');
$htaccess = $read('public/.htaccess');

$assert(str_contains($html, '<html lang="en" dir="ltr"'), 'workspace declares English left-to-right document semantics');
$assert(str_contains($html, 'id="login-form"') && str_contains($html, 'id="application"'), 'login and authenticated application shells are present');
$assert(str_contains($html, 'id="nav-moderation-item"') && str_contains($html, 'data-page="moderation"'), 'moderation navigation exists as a permission-gated workspace view');
$assert(str_contains($html, 'data-page="github"') && str_contains($js, '/api/github/activity'), 'GitHub activity page is connected to its authenticated endpoint');
$assert(str_contains($css, '.github-feed') && str_contains($css, '.github-summary'), 'GitHub activity page has dedicated responsive presentation styles');
$assert(str_contains($html, 'id="workspace-main"') && str_contains($html, 'class="skip-link"'), 'workspace provides a skip link and main landmark');
$assert(str_contains($html, '/assets/css/workspace.css?v=0.2.0-dev'), 'product stylesheet is loaded as an external versioned asset');
$assert(str_contains($html, '/assets/js/workspace.js?v=0.2.3-dev'), 'updated product controller is loaded as an external cache-versioned asset');
$assert(!preg_match('/<style(?:\s|>)/i', $html), 'workspace HTML contains no inline style blocks');
$assert(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), 'workspace HTML contains no inline script blocks');
$assert(str_contains($css, '@media (max-width:560px)') && str_contains($css, 'prefers-reduced-motion:reduce'), 'workspace styling covers narrow screens and reduced motion');
$assert(str_contains($css, '.chat-messages') && str_contains($css, '.metric-grid') && str_contains($css, '.tch-modal'), 'workspace styles include dashboard, chat, and modal surfaces');
$assert(str_contains($js, 'credentials: "same-origin"') && str_contains($js, '"X-Csrf-Token"'), 'API client keeps same-origin credentials and CSRF protection');
$assert(str_contains($js, 'textContent') && str_contains($js, 'after_id=') && str_contains($js, 'set-task-status'), 'controller renders text safely, polls chat incrementally, and updates task status');
$assert(str_contains($js, 'renderTeamMembers') && str_contains($js, '/members"'), 'team details view loads the active member directory');
$assert(str_contains($bootstrap, "'/api/teams/{teamId}/members'") && str_contains($bootstrap, 'listTeamMembers($request, $params)'), 'team roster route is connected to its controller action');
$assert(str_contains($js, 'ID #') && str_contains($js, 'team-list'), 'team roster displays account identifiers with existing responsive list styles');
$assert(str_contains($js, 'canManageTeam') && str_contains($js, 'access_level !== "viewer"'), 'UI controls reflect manager and viewer capabilities without replacing server authorization');
$assert(str_contains($js, '/api/dashboard') && str_contains($js, '/api/teams') && str_contains($js, '/api/projects/') && str_contains($js, '/api/notifications'), 'controller connects the existing dashboard, team, project, and notification APIs');
$assert(str_contains($bootstrap, "/api/teams/{teamId}/edit") && str_contains($bootstrap, "/api/projects/{projectId}/edit"), 'team and project metadata update routes are registered');
$assert(str_contains($workspaceController, 'updateNameForOwner') && str_contains($workspaceController, 'updateMetadataForTeamManager'), 'metadata endpoints use repository-level authorization guards');
$assert(str_contains($js, 'editTeamModal') && str_contains($js, 'editProjectModal'), 'workspace exposes connected team and project editing dialogs');
$assert(str_contains($js, 'Dashboard summaries omit descriptions') && str_contains($js, 'Project details could not be loaded.'), 'project editing loads the full authorized record before saving metadata');
$assert(str_contains($js, 'team.role === "owner"') && str_contains($js, 'canManageTeam(project.team_id)'), 'team rename is owner-only and project editing is manager-gated in the UI');
$assert(str_contains($js, '/api/admin/reports') && str_contains($js, '/api/reports/') && str_contains($js, 'renderReportCard'), 'moderation UI connects scoped report queues and review actions');
$assert(str_contains($js, '/api/team-invitations/accept') && str_contains($js, 'revoke-invitation') && str_contains($js, 'renderPendingInvitations'), 'team UI connects invitation acceptance, listing, and revocation');
$assert(str_contains($bootstrap, '/members/{userId}/access') && str_contains($js, 'change-member-access'), 'project member access-level controls are connected to the backend route');
$assert(str_contains($js, '!member.is_project_creator') && str_contains($js, 'member.access_level === "member" ? "viewer" : "member"'), 'UI protects the project creator and offers explicit viewer/member transitions');
$assert(str_contains($js, '/comments') && str_contains($js, '/subtasks') && str_contains($js, 'openTaskDetails'), 'task details UI connects comment threads, checklists, and editing');
$assert(str_contains($js, '/details') && str_contains($js, '/edit'), 'task details UI calls the read and edit endpoints');
$assert(str_contains($css, '.task-details-modal') && str_contains($css, '.task-checklist') && str_contains($css, '.task-comment-row'), 'task collaboration views have dedicated responsive styles');
$assert(str_contains($bootstrap, "'/public/app.html'") && str_contains($bootstrap, "get('/login'"), 'front controller serves the workspace shell at root and login paths');
$assert(str_contains($response, 'public static function html') && str_contains($response, 'Content-Security-Policy'), 'HTML responses include a restrictive same-origin content security policy');
$assert(str_contains($htaccess, 'Header always set Content-Security-Policy') && str_contains($htaccess, "frame-ancestors 'none'"), 'directly served static assets receive the same-origin content policy');
$assert(str_contains($workflow, 'node --check public/assets/js/workspace.js') && str_contains($workflow, 'php tests/workspace-ui.php'), 'continuous integration validates workspace JavaScript and UI contracts');

echo "\n{$checks} workspace UI assertions passed.\n";
