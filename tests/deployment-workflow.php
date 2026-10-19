<?php

declare(strict_types=1);

/*
 * Deployment workflow contract tests. These checks protect the manual production
 * gate and prevent unsafe defaults; they do not replace a staging deployment.
 */

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/deploy.yml';
$workflow = is_file($workflowPath) ? file_get_contents($workflowPath) : false;

if (!is_string($workflow)) {
    throw new RuntimeException('FAIL: deployment workflow exists and is readable');
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo "PASS: {$message}\n";
};

$assert(str_contains($workflow, 'workflow_dispatch:'), 'production deployment is manual-only');
$assert(str_contains($workflow, "inputs.confirm_production == 'DEPLOY'"), 'deployment requires an explicit production confirmation');
$assert(str_contains($workflow, "github.ref == 'refs/heads/main'"), 'production deployment only accepts the main branch');
$assert(str_contains($workflow, 'environment: production'), 'deployment uses the protected production environment');
$assert(str_contains($workflow, 'DEPLOY_KNOWN_HOSTS') && str_contains($workflow, 'StrictHostKeyChecking=yes'), 'SSH verifies a pinned host key');
$assert(str_contains($workflow, 'test -d') && str_contains($workflow, 'test -w'), 'deployment checks that the destination exists and is writable');
$assert(str_contains($workflow, 'DEPLOY_PATH must be an absolute path') && str_contains($workflow, 'DEPLOY_PATH is too broad'), 'deployment rejects unsafe destination paths');
$assert(str_contains($workflow, 'rsync -az --checksum'), 'deployment uses incremental rsync transfer');
$assert(!preg_match('/rsync[^\n]*--delete/', $workflow), 'deployment never deletes remote files by default');
$assert(str_contains($workflow, 'if: always()') && str_contains($workflow, 'rm -f'), 'temporary SSH credentials are removed even after a failed deployment');
$assert(!str_contains($workflow, 'bin/migrate.php') && !str_contains($workflow, 'bootstrap-admin.php'), 'deployment never runs database migrations or administrator bootstrap automatically');

echo "{$checks} deployment workflow assertions passed.\n";
