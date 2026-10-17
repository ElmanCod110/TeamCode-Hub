# Security Baseline

This document distinguishes implemented primitives from controls still required before production.

## Implemented primitives

- Session startup enables strict mode, cookie-only session IDs, HttpOnly cookies, SameSite=Lax, and Secure cookies when direct HTTPS is detected or `APP_HTTPS=true` is configured.
- Session IDs can be rotated after authentication; logout clears session data and expires the session cookie.
- `CsrfToken` generates cryptographically random session-bound tokens and validates them with `hash_equals`. State-changing routes must validate the token before performing work.
- `AuthenticationService` checks password hashes, only accepts accounts whose status is `active`, rotates the session ID after successful authentication, and uses a generic boolean failure result.
- `ProjectAccessService` allows access only when a project membership is approved, the user's team membership is active, and the project is active. Viewers can view but cannot edit.
- `TeamAccessService` requires active membership; only active `owner` and `manager` roles can manage a team.
- Permission checks are exact-match only; wildcard permissions are not supported.

## Required controls before exposing endpoints

- Add rate limiting and monitoring for authentication attempts, invitation workflows, and other sensitive actions.
- Validate and normalize email addresses during account creation; verify password policy and account recovery flows.
- Add authorization checks to every protected route, API, file download, chat history, and mutation. A service existing in the codebase does not mean routes already use it.
- Validate CSRF tokens on all browser-based state-changing routes; do not rely on SameSite cookies alone.
- Keep real secrets outside the web root and never log passwords, session tokens, secret keys, or sensitive request bodies.
- Trust forwarded client-IP headers only from explicitly configured trusted proxies.
- Define retention and access restrictions for IP/security logs.

## Data and migrations

Use PDO prepared statements for values and context-appropriate output encoding. Validate upload type, size, names, storage location, and download authorization before adding upload features. Versioned migrations are run explicitly through a trusted CLI command; MySQL DDL may partially apply if interrupted, so inspect state before retrying.

Security controls require tests and review. No numerical security percentage is promised.
