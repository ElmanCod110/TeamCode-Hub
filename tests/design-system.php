<?php

declare(strict_types=1);

/*
 * Design-system smoke tests.
 *
 * These checks do not replace browser-level visual or accessibility testing.
 * They catch common integration regressions before the stylesheet is deployed.
 */

$root = dirname(__DIR__);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $assertions++;
    echo "PASS: {$message}\n";
};

$read = static function (string $relativePath) use ($root): string {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException('Required design-system file is missing: ' . $relativePath);
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read design-system file: ' . $relativePath);
    }
    return $contents;
};

$entry = $read('public/assets/css/app.css');
$tokens = $read('public/assets/css/tokens.css');
$base = $read('public/assets/css/base.css');
$layout = $read('public/assets/css/layout.css');
$components = $read('public/assets/css/components.css');
$patterns = $read('public/assets/css/patterns.css');
$utilities = $read('public/assets/css/utilities.css');
$uiScript = $read('public/assets/js/ui.js');
$workspaceHtml = $read('public/app.html');
$workspaceCss = $read('public/assets/css/workspace.css');
$workspaceScript = $read('public/assets/js/workspace.js');

$expectedImports = [
    '@import url("./tokens.css?v=0.2.2-dev");',
    '@import url("./base.css?v=0.2.2-dev");',
    '@import url("./layout.css?v=0.2.2-dev");',
    '@import url("./components.css?v=0.2.2-dev");',
    '@import url("./patterns.css?v=0.2.2-dev");',
    '@import url("./utilities.css?v=0.2.2-dev");',
];
$lastPosition = -1;
foreach ($expectedImports as $import) {
    $position = strpos($entry, $import);
    $assert($position !== false && $position > $lastPosition, 'CSS entry imports layers in dependency order');
    $lastPosition = (int) $position;
}

$assert(str_contains($tokens, '--font-sans:') && str_contains($tokens, 'system-ui'), 'typography uses an installed system-font fallback stack');
$assert(!preg_match('/fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net/i', $entry . $tokens . $base . $layout . $components . $patterns . $utilities), 'design-system CSS does not require a hosted font service');
$assert(str_contains($tokens, 'data-theme="dark"'), 'an explicit dark theme token set is available');
$assert(str_contains($base, 'prefers-reduced-motion: reduce'), 'base styles respect reduced-motion preferences');
$assert(str_contains($base, ':focus-visible'), 'keyboard focus indicators are defined');
$assert(str_contains($layout, '@media (max-width: 720px)'), 'responsive layout rules are present');
$assert(str_contains($components, '.modal-overlay') && str_contains($components, '.toast-region'), 'overlay and notification components are included');
$assert(str_contains($patterns, '.dropzone') && str_contains($patterns, 'input[type="range"]'), 'file upload and range input patterns are available');
$assert(str_contains($patterns, '.acc details') && str_contains($patterns, '.pager button'), 'accordion and pagination patterns are available');
$assert(str_contains($patterns, '.steps') && str_contains($patterns, '.footer-grid'), 'workflow steps and responsive footer patterns are available');
$assert(str_contains($patterns, 'prefers-reduced-motion:reduce') && str_contains($patterns, 'forced-colors:active'), 'extended components respect motion and forced-colors preferences');
$assert(str_contains($patterns, '.grid.g2') && str_contains($patterns, '.avatar-wrap .status'), 'legacy layout aliases and avatar presence indicators are available');
$assert(str_contains($patterns, '.swatches') && str_contains($patterns, '.type-list') && str_contains($patterns, '.sec-head'), 'reference palette, typography, and section helpers are available');
$assert(str_contains($patterns, '.btn-xs') && str_contains($patterns, '.check .box') && str_contains($patterns, '.tabs.pill'), 'legacy button, form-state, and pill-tab aliases are available');
$assert(str_contains($uiScript, 'textContent = String(message'), 'toast content is inserted as text rather than interpreted HTML');
$assert(str_contains($uiScript, 'data-modal-open') && str_contains($uiScript, 'data-dropdown-toggle'), 'interaction controller includes modal and dropdown behavior');
$assert(str_contains($workspaceHtml, '<html lang="en" dir="ltr"'), 'product shell declares English left-to-right content');
$assert(str_contains($workspaceHtml, '/assets/css/app.css?v=0.2.2-dev') && str_contains($workspaceHtml, '/assets/css/workspace.css?v=0.2.8-dev'), 'product shell links shared and workspace stylesheets with refreshed cache keys');
$assert(str_contains($workspaceHtml, '/assets/js/ui.js?v=0.2.1-dev') && str_contains($workspaceHtml, '/assets/js/workspace.js?v=0.2.19-dev'), 'product shell links both external controllers with refreshed cache keys');
$assert(!preg_match('/\\sstyle\\s*=/i', $workspaceHtml) && !preg_match('/<style(?:\\s|>)/i', $workspaceHtml), 'product shell contains no inline styles');
$assert(!preg_match('/<script(?![^>]*\\bsrc=)[^>]*>/i', $workspaceHtml), 'product shell contains no inline JavaScript');
$assert(str_contains($workspaceCss, '.app-shell') && str_contains($workspaceCss, '.project-files-layout') && str_contains($workspaceCss, '.chat-messages'), 'product stylesheet covers actual workspace, source files, and chat views');
$assert(str_contains($workspaceScript, '/api/teams') && str_contains($workspaceScript, '/api/projects/') && str_contains($workspaceScript, 'credentials: "same-origin"'), 'workspace controller connects team/project APIs with same-origin requests');
$assert(str_contains($workspaceScript, '"X-Csrf-Token"') && str_contains($workspaceScript, 'function renderTeams'), 'workspace mutations retain CSRF protection and team rendering');

$assert(str_contains($tokens, '--text-muted: var(--g500);'), 'muted text uses a readable neutral on light surfaces');
$assert(str_contains($tokens, '--text-muted: #a1a1aa;'), 'muted text remains legible against dark surfaces');

$assert(str_contains($tokens, '--text-primary: var(--text);'), 'workspace primary text alias resolves to the active theme text token');
$assert(str_contains($tokens, '--surface-hover: var(--g100);') && str_contains($tokens, '--surface-subtle: var(--surface-muted);'), 'workspace hover and selected surfaces resolve from the active neutral palette');
$assert(str_contains($tokens, '--surface-code: var(--surface-raised);') && str_contains($tokens, '--radius-md: var(--r-lg);'), 'source editor surface and legacy radius aliases are defined centrally');

/* Verify every stylesheet imported by the entry point exists on disk. */
preg_match_all('/@import\s+url\("([^"]+)"\)/', $entry, $importMatches);
foreach ($importMatches[1] as $importUrl) {
    $relativePath = 'public/assets/css/' . strtok($importUrl, '?');
    $assert(is_file($root . '/' . $relativePath), 'entry stylesheet import resolves: ' . $relativePath);
}

/* Verify the product shell references existing local CSS and JavaScript assets. */
preg_match_all('/(?:href|src)="(\/assets\/[^"]+)"/', $workspaceHtml, $assetMatches);
foreach ($assetMatches[1] as $assetUrl) {
    $relativePath = 'public' . strtok($assetUrl, '?');
    $assert(is_file($root . '/' . $relativePath), 'product asset URL resolves: ' . $relativePath);
}

$assert(str_contains($workspaceHtml, 'id="page-content"') && str_contains($workspaceHtml, 'id="application"'), 'workspace shell contains the actual app mount points');
$assert(str_contains($workspaceScript, 'async function boot()') && str_contains($workspaceScript, 'window.TCHWorkspace'), 'workspace controller initializes and exposes its guarded controller');

echo "\n{$assertions} design-system assertions passed.\n";
