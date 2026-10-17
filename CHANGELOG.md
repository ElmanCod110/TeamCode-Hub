# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.1.0-dev] - Unreleased

- Establish the PHP front controller, request/response, router, and lazy module registry.
- Add validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migrations.
- Add identity, team/project membership, authentication rate-limit, system-role, invitation, and task schema.
- Add CSRF-protected JSON authentication endpoints, login throttling, and CLI-only administrator bootstrap.
- Add team/project APIs with approved membership checks, quota enforcement, invitations, and project membership approval.
- Add task listing, member-only creation/status changes, priority, approved member assignment, and UTC due-date support.
- Add exact scoped permissions, trusted-proxy-aware client IP resolution, and automated PHP lint/smoke tests.
- Add a MariaDB 10.11 integration suite for migrations, authentication, invitations, quotas, project access, and tasks.
- Add a personalized dashboard API, user-scoped notifications, and transactional notification events for invitation, project-access, and task workflows.
- Add text-only project chat with bounded retrieval, incremental cursors, message retention, report snapshots, manager/admin review queues, and valid-report escalation.

This is an in-progress development baseline, not a production-ready release.
