# Operations and Deployment

## Before deployment

- Configure the document root to the repository's `public/` directory where possible.
- Configure environment variables through the host or a private configuration file outside the web root.
- Create a dedicated database user with only the permissions the application requires.
- Enable HTTPS and configure session cookie security accordingly. If TLS terminates at a trusted reverse proxy, set `APP_HTTPS=true` only when the deployment topology guarantees HTTPS to the browser.
- Disable displayed PHP errors in production and enable restricted server-side error logs.
- Confirm private storage, configuration, backup, and log paths cannot be downloaded over HTTP.
- Back up the database and files before applying schema changes.

## Explicit migration command

After configuring `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`, run `php bin/migrate.php` from a trusted CLI environment. Never run migrations automatically in response to normal web requests or arbitrary SQL errors. The runner records completed versions and uses a MySQL advisory lock to avoid concurrent runs.

MySQL DDL can implicitly commit. If a migration is interrupted part-way through, inspect the database before retrying; migrations must be designed for safe recovery. The first migration creates the initial users, teams, team memberships, projects, and project memberships tables. This schema alone does not implement authentication or enforce application-level authorization.

## Initial health endpoint

`GET /health` checks only that the minimal request/router bootstrap responds. It does not test database connectivity or prove that product features are healthy.

## Administrator bootstrap

Do not create an administrator based on the first visitor or an unauthenticated request. An eventual install flow must be explicit, one-time, and protected by a secret outside the public document root. Disable the installer after initialization.

## Shared-hosting constraints

Do not require a long-running worker or WebSocket server for the baseline. Use bounded AJAX polling and incremental cursors where real-time updates are introduced. Keep logs and caches bounded and define retention policies for personal/security data.
