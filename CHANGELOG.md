# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.1.0-dev] - Unreleased

- Establish the PHP front controller, request/response, router, and lazy module registry.
- Add validated MySQL/MariaDB configuration, PDO connection factory, and explicit CLI migrations.
- Add identity, team/project membership, authentication rate-limit, and system-role schema.
- Add prepared repositories, session-backed authentication, CSRF-protected JSON auth endpoints, and login throttling.
- Add team/project access services, exact scoped permission checks, and trusted-proxy-aware client IP resolution.
- Add CLI-only one-time site administrator bootstrap and expose assigned system roles in the current-user response.
- Add automated linting and smoke tests across PHP 8.2, 8.3, and 8.4.

This is an in-progress development baseline, not a production-ready release.
