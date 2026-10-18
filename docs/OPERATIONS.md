# Operations and Deployment

## Before deployment

- Configure the document root to the repository's `public/` directory where possible.
- Configure database environment variables through the host or a private configuration mechanism outside the web root.
- Create a dedicated database user with only the permissions the application requires.
- Enable HTTPS and configure session cookie security accordingly. If TLS terminates at a trusted reverse proxy, set `APP_HTTPS=true` only when the deployment topology guarantees HTTPS to the browser.
- Disable displayed PHP errors in production and enable restricted server-side error logs.
- Confirm private storage, configuration, backup, and log paths cannot be downloaded over HTTP.
- Back up the database and files before applying schema changes.

## Registration and email verification

Public registration is enabled after the account-token migration has been applied. Configure these environment variables outside the web root:

- `APP_URL` — the canonical HTTPS origin of the workspace, for example `https://hub.example.com`.
- `MAIL_FROM_ADDRESS` — a valid sender mailbox controlled by your domain.
- Ensure PHP's `mail()` transport is configured and monitored by the hosting provider. Configure SPF, DKIM, and DMARC for reliable delivery.

The application deliberately returns a generic response for both existing and unknown email addresses. New accounts stay pending until the recipient confirms a one-time link that expires after 24 hours. Resend requests have IP/email rate limits and a per-account cooldown. The verification token is placed in the URL fragment, not the HTTP request line, and the browser requires an explicit confirmation action. If mail delivery is unavailable, the account remains pending; server logs contain a generic configuration warning, not the recipient address or token. Test delivery and verification end-to-end before enabling public traffic.

Password recovery uses the same HTTPS origin and sender configuration. Reset requests also return a generic response, use purpose-specific IP/email and token-attempt rate limits, and enforce a per-account cooldown. Reset tokens expire after 30 minutes, are stored only as SHA-256 hashes, and are invalidated after use. Password reset and password changes increment `users.session_version`; older sessions are rejected on their next authenticated request. Test both email flows end-to-end before enabling public traffic.

Team invitations use the same `APP_URL`, `MAIL_FROM_ADDRESS`, and PHP mail transport. Each invitation link expires after seven days and is bound to the invited email address. If delivery fails, the API exposes the raw token once as a trusted-channel fallback; successful delivery omits the token from the response. Test delivery to both existing and newly registered, verified accounts.

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

## GitHub public activity cache

Set `GITHUB_USERNAME` to the public GitHub username whose public events should appear in the workspace. If unset, the current default is `ElmanCod110`. No GitHub token is required or stored; only the public events endpoint is queried. Apply migrations with `php bin/migrate.php` before using the integration.

The first authenticated request on a UTC day claims the single refresh attempt under a row lock, then makes one HTTPS request to GitHub. Other visits on that day serve the database snapshot without another outbound request. Failed refreshes do not trigger repeated retries and the last successful snapshot is preserved. The `/api/github/activity` endpoint is authenticated and reports whether data is stale. This is a read-only public activity feed, not a private repository integration.

## Shared-hosting constraints

Do not require a long-running worker or WebSocket server for the baseline. Use bounded AJAX polling and incremental cursors where real-time updates are introduced. Keep logs and caches bounded and define retention policies for personal/security data.
