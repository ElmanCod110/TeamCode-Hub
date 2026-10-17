# Development Guide

## Local prerequisites

- PHP 8.2+ for the initial target.
- PDO and PDO MySQL extensions for database-backed features and integration tests.
- A disposable MySQL/MariaDB database for migration and workflow integration tests.
- Apache rewrite support for local pretty-route testing, if using Apache.

## Current state

The repository includes core routing/module primitives, database configuration and migration tooling, authentication/session services, team/project membership APIs, invitations, and a basic task workflow. This is still a development foundation; production authentication setup and several product features remain incomplete.

## Validation

Run syntax checks on changed PHP files with `php -l path/to/file.php`. Run the fast smoke suite with `php tests/run.php`.

For database integration testing, configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to point at a disposable test database, then run `php tests/integration.php`. The integration suite applies versioned migrations and exercises invitations, access approval, project/task authorization, quotas, login throttling, and authentication. Do not point this test at production data; it inserts and modifies rows.

GitHub Actions runs PHP linting and the smoke/integration suites on PHP 8.2, 8.3, and 8.4 against MariaDB 10.11. Do not report a check as passing unless it actually ran successfully.

## Deployment direction

Configure the web server document root to `public/`. Keep secrets and private storage outside that directory where possible. Configure environment variables through the host; this repository does not include a `.env` parser. Disable displayed PHP errors in production and use restricted error logs.
