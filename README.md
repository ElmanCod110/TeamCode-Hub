# TeamCode Hub

**Version:** 0.2.0-dev  
**Status:** Foundation in progress

TeamCode Hub is a modular, security-focused collaboration platform for software teams, targeting conventional PHP hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This is an early development foundation, not a production-ready application. Planned capabilities are not considered implemented until code and tests exist.

## Current foundation

- Public front controller, request/response objects, pretty-route dispatcher, and lazy module registry.
- Validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migration runner.
- Identity, team/project membership, login-rate-limit, system-role, invitation, and task schema.
- Hardened sessions, CSRF-protected authentication API, login throttling, and trusted-proxy-aware IP resolution.
- CLI-only one-time administrator bootstrap.
- Team/project APIs with active/approved membership checks and transactional project quota enforcement.
- Seven-day team invitations with hashed tokens, acceptance, and revocation.
- Project member assignment with pending-by-default status, manager approval, viewer/member access levels, and revocation.
- Task listing for approved project users, member-only task creation/status changes, priorities, assignees, and UTC due dates.
- Personalized dashboard API with active teams, approved projects, due-today/overdue tasks, and unread notification counts.
- Text-only project chat with a 50-message window, incremental cursor, message edit/delete, 24-hour expiry, and report-context preservation.
- Report review queues for team managers/site admins, one-report-per-person-per-reporter-per-day enforcement, and escalation after two distinct valid reports within 24 hours.
- User-scoped notification inbox and read-state endpoint; workflow events create notifications transactionally.
- Modular design-system assets for tokens, base styles, layout, components, utilities, and accessible UI interactions.\n- English component showcase at `/design-system.html`, with no external font downloads or runtime UI dependencies.\n- Frontend asset smoke checks and JavaScript syntax checks in CI, alongside PHP 8.2–8.4 and MariaDB integration tests.

## Setup

1. Configure database environment variables using the host's environment settings or a private configuration mechanism. There is no `.env` parser.
2. Run `php bin/migrate.php` from a trusted CLI shell.
3. Create the first administrator using the CLI-only procedure in [docs/OPERATIONS.md](docs/OPERATIONS.md).
4. Serve the project from `public/` and require HTTPS. Configure `APP_HTTPS=true` only when browser-facing HTTPS is guaranteed by the deployment.

## API

Authentication: `GET /api/auth/csrf`, `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`.

Teams: team CRUD foundation and invitation lifecycle routes. Projects: project listing/creation, membership assignment/approval/revocation. Dashboard: `GET /api/dashboard`, `GET /api/notifications`, `POST /api/notifications/{notificationId}/read`.

Tasks: `GET|POST /api/projects/{projectId}/tasks` and `POST /api/projects/{projectId}/tasks/{taskId}/status`.

State-changing routes require a valid CSRF token. Invitation tokens are returned once and are not emailed by the current baseline. See [docs/API.md](docs/API.md) for request bodies, status codes, and access rules.

## Not implemented yet

Public registration, email verification, password recovery, automatic invitation email, team editing/deletion, project editing/deletion, task editing/assignment after creation, comments, subtasks, chat UI/polling client, Git synchronization, and admin UI remain future work. Every future protected route must explicitly enforce authorization; service classes do not secure routes automatically.

## Requirements and layout

- PHP 8.2+ is the initial CI target.
- Apache rewrite support or equivalent web-server routing configuration.
- PDO MySQL for database-backed features.
- Configure the document root to `public/` where possible; keep secrets and private runtime data outside it.

```text
public/          Web root, front controller, design-system preview, CSS and JS assets
app/Core/        Request, response, router, module registry
app/Database/    PDO connection, repositories, migrations
app/Security/    Session, CSRF, authentication, access, rate limiting
app/Http/        HTTP controllers
bin/             Trusted CLI commands
database/        Versioned migrations
tests/           Lightweight smoke tests
docs/            Architecture, security, API, database, operations
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
