# Operations and Deployment

## Before deployment

- Configure the document root to the repository's `public/` directory where possible.
- Configure database environment variables through the host or a private configuration mechanism outside the web root.
- Create a dedicated database user with only the permissions the application requires.
- Enable HTTPS and configure session cookie security accordingly. If TLS terminates at a trusted reverse proxy, set `APP_HTTPS=true` only when the deployment topology guarantees HTTPS to the browser.
- Disable displayed PHP errors in production and enable restricted server-side error logs.
- Confirm private storage, configuration, backup, and log paths cannot be downloaded over HTTP.
- Back up the database and files before applying schema changes.

## Database migrations

After configuring `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`, run `php bin/migrate.php` from a trusted CLI environment. Never run migrations automatically in response to normal web requests or arbitrary SQL errors. The runner records completed versions and uses a MySQL advisory lock to avoid concurrent runs.

MySQL DDL can implicitly commit. If a migration is interrupted part-way through, inspect the database before retrying. Migrations must be designed for safe recovery. Current migrations create identity, team/project membership, authentication rate-limit, and system-role tables. This schema alone does not enforce application-level authorization.

## Initial administrator (CLI only)

Run migrations first. Then, from a trusted SSH/cPanel terminal, set the bootstrap variables without placing the password in shell history:

```bash
read -r -p 'Admin email: ' TCH_ADMIN_EMAIL
read -r -p 'Display name: ' TCH_ADMIN_NAME
read -r -s -p 'Admin password: ' TCH_ADMIN_PASSWORD; printf '\n'
export TCH_ADMIN_EMAIL TCH_ADMIN_NAME TCH_ADMIN_PASSWORD
php bin/bootstrap-admin.php
unset TCH_ADMIN_EMAIL TCH_ADMIN_NAME TCH_ADMIN_PASSWORD
```

The command is CLI-only, refuses to run if a `site_admin` role already exists, creates an active verified account and grants the first `site_admin` role in one database transaction. It refuses to overwrite an existing email. Use a strong password of at least 12 bytes; when Argon2id is unavailable, the current default bcrypt-compatible path limits the password to 72 bytes to avoid silent truncation. Do not expose this command through a web route.

## Initial health endpoint

`GET /health` checks only that the minimal request/router bootstrap responds. It does not test database connectivity or prove that product features are healthy.

## Chat retention cron

After applying all migrations, schedule `php /absolute/path/to/TeamCode-Hub/bin/cleanup-chat.php` through the hosting provider's cron interface at least once per day. Run it from a trusted CLI environment with the database environment variables configured. It deletes only chat rows whose expiry has passed; unresolved reported messages have no expiry and remain held. Resolved/rejected reports resume a 24-hour retention period before cleanup can remove the message and its context snapshot.

## Shared-hosting constraints

Do not require a long-running worker or WebSocket server for the baseline. Use bounded AJAX polling and incremental cursors where real-time updates are introduced. Keep logs and caches bounded and define retention policies for personal/security data.
