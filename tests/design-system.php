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
$utilities = $read('public/assets/css/utilities.css');
$uiScript = $read('public/assets/js/ui.js');
$demoScript = $read('public/assets/js/design-system-demo.js');
$demoPage = $read('public/design-system.html');
$demoCss = $read('public/assets/css/design-system-demo.css');

$expectedImports = [
    '@import url("./tokens.css?v=0.2.0-dev");',
    '@import url("./base.css?v=0.2.0-dev");',
    '@import url("./layout.css?v=0.2.0-dev");',
    '@import url("./components.css?v=0.2.0-dev");',
    '@import url("./utilities.css?v=0.2.0-dev");',
];
$lastPosition = -1;
foreach ($expectedImports as $import) {
    $position = strpos($entry, $import);
    $assert($position !== false && $position > $lastPosition, 'CSS entry imports layers in dependency order');
    $lastPosition = (int) $position;
}

$assert(str_contains($tokens, '--font-sans:') && str_contains($tokens, 'system-ui'), 'typography uses an installed system-font fallback stack');
$assert(!preg_match('/fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net/i', $entry . $tokens . $base . $layout . $components . $utilities . $demoCss), 'design-system CSS does not require a hosted font service');
$assert(str_contains($tokens, 'data-theme="dark"'), 'an explicit dark theme token set is available');
$assert(str_contains($base, 'prefers-reduced-motion: reduce'), 'base styles respect reduced-motion preferences');
$assert(str_contains($base, ':focus-visible'), 'keyboard focus indicators are defined');
$assert(str_contains($layout, '@media (max-width: 720px)'), 'responsive layout rules are present');
$assert(str_contains($components, '.modal-overlay') && str_contains($components, '.toast-region'), 'overlay and notification components are included');
$assert(str_contains($uiScript, 'textContent = String(message'), 'toast content is inserted as text rather than interpreted HTML');
$assert(str_contains($uiScript, 'data-modal-open') && str_contains($uiScript, 'data-dropdown-toggle'), 'interaction controller includes modal and dropdown behavior');
$assert(str_contains($demoPage, '<html lang="en" dir="ltr"'), 'showcase document declares English left-to-right content');
$assert(str_contains($demoPage, '/assets/css/app.css') && str_contains($demoPage, '/assets/js/ui.js'), 'showcase loads shared assets by external URLs');
$assert(!preg_match('/\sstyle\s*=/i', $demoPage), 'showcase HTML contains no inline style attributes');
$assert(!preg_match('/<script\s*>/i', $demoPage), 'showcase HTML contains no inline JavaScript blocks');
$assert(str_contains($demoPage, '/assets/css/design-system-demo.css') && str_contains($demoPage, '/assets/js/design-system-demo.js'), 'showcase-specific behavior and styles are external assets');
$assert(str_contains($demoScript, 'window.TCHUI.notify'), 'showcase buttons use the shared notification API');

/* Verify every stylesheet imported by the entry point exists on disk. */
preg_match_all('/@import\s+url\("([^"]+)"\)/', $entry, $importMatches);
foreach ($importMatches[1] as $importUrl) {
    $relativePath = 'public/assets/css/' . strtok($importUrl, '?');
    $assert(is_file($root . '/' . $relativePath), 'entry stylesheet import resolves: ' . $relativePath);
}

/* Verify the showcase references existing local CSS and JavaScript assets. */
preg_match_all('/(?:href|src)="(\/assets\/[^"]+)"/', $demoPage, $assetMatches);
foreach ($assetMatches[1] as $assetUrl) {
    $relativePath = 'public' . strtok($assetUrl, '?');
    $assert(is_file($root . '/' . $relativePath), 'showcase asset URL resolves: ' . $relativePath);
}


echo "\n{$assertions} design-system assertions passed.\n";
