# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.1.0-dev] - Unreleased

- Establish the PHP front controller, request/response, router, and lazy module registry.
- Add validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migrations.
- Add identity, team/project membership, authentication rate-limit, and system-role schema.
- Add CSRF-protected JSON authentication endpoints, login throttling, and CLI-only administrator bootstrap.
- Add team listing/creation and membership-protected team reads.
- Add approved-project listing/reads, manager-only project creation, and transactional active-project quota checks.
- Add exact scoped permissions, trusted-proxy-aware client IP resolution, and automated PHP lint/smoke tests.

This is an in-progress development baseline, not a production-ready release.
