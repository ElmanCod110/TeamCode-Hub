# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.1.0-dev] - Unreleased

- Establish the PHP front controller, request/response, router, and lazy module registry.
- Add validated MySQL/MariaDB configuration, PDO connection factory, and explicit CLI migrations.
- Add initial identity, team, project membership, and authentication rate-limit schema.
- Add prepared user and membership repositories and session-backed authentication.
- Add JSON CSRF, login, current-session, and logout endpoints.
- Add database-backed login throttling and trusted-proxy-aware client IP resolution.
- Add team/project access services, exact scoped permission checks, and automated session/CSRF/IP-resolution tests.
- Run automated linting and smoke tests across PHP 8.2, 8.3, and 8.4.

This is an in-progress development baseline, not a production-ready release.
