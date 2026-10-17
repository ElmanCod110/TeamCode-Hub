# Development Guide

## Local prerequisites

- PHP 8.2+ for the initial target (the supported range will be verified in CI).
- PDO and PDO MySQL for database-backed features.
- Apache rewrite support for the included shared-hosting route configuration.
- Git and a PHP syntax checker.

## Current state

The repository contains the initial routing/module-registry primitives and architecture documents. Database-backed product features, authentication, team/project workflows, and chat are not yet implemented.

## Validation

Run PHP lint on changed PHP files with `php -l path/to/file.php`. As tests and static analysis are introduced, run the documented test and analysis commands before opening a pull request. Never report a check as passing unless it was actually executed.

## Deployment direction

Configure the web server document root to `public/`. Put secrets and private storage outside that directory when possible. Configure environment variables through the host's supported mechanism. Disable public error display in production and use restricted error logs.
