# Security Baseline

This document distinguishes implemented primitives from controls still required before production.

## Implemented primitives

- Session startup enables strict mode, cookie-only session IDs, HttpOnly cookies, SameSite=Lax, and Secure cookies when direct HTTPS is detected or `APP_HTTPS=true` is configured.
- Session IDs are rotated after successful authentication; logout clears session data and expires the session cookie.
- Login, logout, team/project creation, invitation operations, project membership mutations, task mutations, and notification read-state changes require a session-bound CSRF token.
- Authentication accepts only active accounts and uses generic failure responses. Login attempts are throttled with database-backed per-IP and per-email-hash buckets (20/IP and 8/email per 15 minutes by default).
- Forwarded client IP data is ignored unless `REMOTE_ADDR` matches an exact IP in `TRUSTED_PROXIES`.
- Team reads require active membership. Only active `owner` and `manager` roles can create projects or manage invitations/project memberships.
- Project reads require approved project membership plus active team membership. Viewers have read-only access; pending or revoked memberships are denied.
- Project assignments start as `pending`; an active team owner/manager must approve access.
- Task reads require approved project access. Creating tasks and changing task status require approved `member` access; viewer access is read-only. Assignees must have approved member-level access to the same project.
- Dashboard task queries repeat the project/team membership predicates and expose only the user's own authorized team/project/task data.
- Notifications are scoped by user ID in list, count, and mark-read queries. Relevant workflow notifications are inserted inside the same transaction as the change they describe.
- Active project quota checks run inside a transaction while checking a manager role under a row lock.
- Team invitations use 256-bit random tokens, store only SHA-256 token hashes, expire after seven days, and can be revoked. Invitation creation reserves member capacity.
- Initial site administrator creation is CLI-only and refuses to create a second `site_admin` role.

## Required controls before production

- Add rate limiting and monitoring for invitations, project membership changes, task mutations, account recovery, and other sensitive actions.
- Implement public registration, email verification, password reset, and secure invitation delivery by email.
- Add audit history and retention policy for notifications and security events.
- Ensure every future file, chat, Git, and admin route checks authorization at the data-access boundary.
- Keep real secrets outside the web root and never log passwords, session tokens, secret keys, or raw invitation tokens.
- Define retention and access restrictions for security logs and personal data.
- Test migrations and SQL behavior against supported MySQL/MariaDB versions and the production HTTPS/shared-hosting topology.

## Data and migrations

Use PDO prepared statements for values and context-appropriate output encoding. Validate upload type, size, names, storage location, and download authorization before adding upload features. Versioned migrations are run explicitly through a trusted CLI command; MySQL DDL may partially apply if interrupted, so inspect state before retrying.

Security controls require tests and review. No numerical security percentage is promised.
