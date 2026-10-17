# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.1.0-dev] - Unreleased

- Establish the PHP front controller, request/response, router, and lazy module registry.
- Add validated MySQL/MariaDB configuration and a PDO connection factory.
- Add an explicit CLI migration runner with a migration registry and advisory lock.
- Add initial identity, team, and project membership schema.
- Add prepared user and membership repositories.
- Add session hardening, session-bound CSRF tokens, authentication service, and exact scoped permission checks.
- Add team and project access services that enforce active and approved memberships.
- Add automated linting and smoke tests across PHP 8.2, 8.3, and 8.4.
- Document architecture, security, routing, database, operations, and deployment assumptions.

This is an in-progress development baseline, not a production-ready release.
