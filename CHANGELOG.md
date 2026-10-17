# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.1.0-dev] - Unreleased

- Establish the PHP front controller, request/response, router, and lazy module registry.
- Add validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migrations.
- Add identity, team/project membership, authentication rate-limit, system-role, and team invitation schema.
- Add CSRF-protected JSON authentication endpoints, login throttling, and CLI-only administrator bootstrap.
- Add team/project APIs with approved membership checks, manager-only project creation, and project quota enforcement.
- Add expiring team invitations with hashed tokens, member quota reservations, acceptance, listing, and revocation.
- Add project member assignment, pending approval, viewer/member access levels, and revocation.
- Add exact scoped permissions, trusted-proxy-aware client IP resolution, and automated PHP lint/smoke tests.

This is an in-progress development baseline, not a production-ready release.
