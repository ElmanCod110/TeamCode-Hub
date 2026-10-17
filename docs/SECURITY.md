# Security Baseline

This document distinguishes implemented primitives from controls still required before production.

## Implemented primitives

- Session startup enables strict mode, cookie-only session IDs, HttpOnly cookies, SameSite=Lax, and Secure cookies when direct HTTPS is detected or `APP_HTTPS=true` is configured.
- Session IDs are rotated after successful authentication; logout clears session data and expires the session cookie.
- `GET /api/auth/csrf` creates or returns a cryptographically random session-bound token. Login, logout, and state-changing team/project routes require the token via `X-Csrf-Token` or the request body's `csrf_token` field.
- `AuthenticationService` checks password hashes, only accepts accounts whose status is `active`, rotates the session ID after successful authentication, and uses a generic failure response.
- Login attempts are throttled using database-backed per-IP and per-email-hash buckets. Defaults: 20 attempts per IP and 8 per email hash per 15 minutes.
- Forwarded client IP data is ignored unless `REMOTE_ADDR` matches an exact IP in `TRUSTED_PROXIES`.
- Team API routes require active team membership. Only active `owner` and `manager` roles can create projects.
- Project API routes require an approved project membership, an active team membership, and an active project. Viewer access is read-only.
- Project quota checks run inside the project-creation transaction while locking the team membership row.
- Permission checks are exact-match only; wildcard permissions are not supported.
- Initial site administrator creation is a CLI-only operation and refuses to create a second `site_admin` role.

## Required controls before production

- Add rate limiting and monitoring for invitations, account recovery, and other sensitive actions.
- Implement public registration, email verification, password reset, and account recovery.
- Enforce team-member quotas when invitation and membership workflows are introduced.
- Add authorization checks to every protected route, file download, chat history, and mutation. Future routes must not assume that membership in one project grants access to another.
- Keep real secrets outside the web root and never log passwords, session tokens, secret keys, or sensitive request bodies.
- Define retention and access restrictions for security logs and any personal data.
- Test the migration and query behavior against the actual supported MySQL/MariaDB versions and HTTPS/shared-hosting topology.

## Data and migrations

Use PDO prepared statements for values and context-appropriate output encoding. Validate upload type, size, names, storage location, and download authorization before adding upload features. Versioned migrations are run explicitly through a trusted CLI command; MySQL DDL may partially apply if interrupted, so inspect state before retrying.

Security controls require tests and review. No numerical security percentage is promised.
