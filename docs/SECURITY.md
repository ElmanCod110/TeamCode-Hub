# Security Baseline

This document distinguishes implemented primitives from controls still required before production.

## Implemented primitives

- Session startup enables strict mode, cookie-only session IDs, HttpOnly cookies, SameSite=Lax, and Secure cookies when direct HTTPS is detected or `APP_HTTPS=true` is configured.
- Session IDs are rotated after successful authentication; logout clears session data and expires the session cookie.
- Login, logout, team/project creation, invitation creation/revocation/acceptance require a session-bound CSRF token.
- Authentication accepts only active accounts and uses generic failure responses. Login attempts are throttled with database-backed per-IP and per-email-hash buckets (20/IP and 8/email per 15 minutes by default).
- Forwarded client IP data is ignored unless `REMOTE_ADDR` matches an exact IP in `TRUSTED_PROXIES`.
- Team reads require active membership. Only active `owner` and `manager` roles can create projects or manage invitations.
- Project reads require approved project membership plus active team membership. Viewers have read-only access.
- Active project quota checks run inside a transaction while checking a manager role under a row lock.
- Team invitations use 256-bit random tokens, store only SHA-256 token hashes, expire after seven days, and can be revoked. Only the `member` role can be invited. Acceptance requires an authenticated account whose email matches the invitation.
- Invitation creation reserves capacity so active members plus unexpired pending invitations cannot exceed the team's member limit.
- Initial site administrator creation is CLI-only and refuses to create a second `site_admin` role.

## Required controls before production

- Add rate limiting and monitoring for invitations, account recovery, and other sensitive actions.
- Implement public registration, email verification, password reset, and secure invitation delivery by email.
- Implement project membership assignment/approval workflows and team-member quota enforcement for every membership-changing path.
- Add authorization checks to every protected route, file download, chat history, and mutation. Future routes must not assume that membership in one project grants access to another.
- Keep real secrets outside the web root and never log passwords, session tokens, secret keys, or raw invitation tokens.
- Define retention and access restrictions for security logs and personal data.
- Test migration and query behavior against supported MySQL/MariaDB versions and the production HTTPS/shared-hosting topology.

## Data and migrations

Use PDO prepared statements for values and context-appropriate output encoding. Validate upload type, size, names, storage location, and download authorization before adding upload features. Versioned migrations are run explicitly through a trusted CLI command; MySQL DDL may partially apply if interrupted, so inspect state before retrying.

Security controls require tests and review. No numerical security percentage is promised.
