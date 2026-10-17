# TeamCode Hub

**Version:** 0.1.0-dev  
**Status:** Foundation in progress

TeamCode Hub is a modular, security-focused collaboration platform for software teams, targeting conventional PHP hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This is an early development foundation, not a production-ready application. Planned capabilities are not considered implemented until code and tests exist.

## Current foundation

- Public front controller, request/response objects, pretty-route dispatcher, and lazy module registry.
- Validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migration runner.
- Identity, team/project membership, login-rate-limit, system-role, and invitation schema.
- Hardened sessions, CSRF-protected authentication API, login throttling, and trusted-proxy-aware IP resolution.
- CLI-only one-time administrator bootstrap.
- Team/project APIs with active/approved membership checks and transactional project quota enforcement.
- Seven-day team invitations with hashed tokens, manual trusted-channel delivery, acceptance, and revocation.
- Project member assignment with pending-by-default status, manager approval, viewer/member access levels, and revocation.
- Automated linting and smoke tests on PHP 8.2, 8.3, and 8.4.

## Setup

1. Configure database environment variables using the host's environment settings or a private configuration mechanism. There is no `.env` parser.
2. Run `php bin/migrate.php` from a trusted CLI shell.
3. Create the first administrator using the CLI-only procedure in [docs/OPERATIONS.md](docs/OPERATIONS.md).
4. Serve the project from `public/` and require HTTPS. Configure `APP_HTTPS=true` only when browser-facing HTTPS is guaranteed by the deployment.

## API

Authentication: `GET /api/auth/csrf`, `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`.

Teams: `GET|POST /api/teams`, `GET /api/teams/{teamId}`, team invitations via `GET|POST /api/teams/{teamId}/invitations`, `DELETE /api/teams/{teamId}/invitations/{invitationId}`, and `POST /api/team-invitations/accept`.

Projects: `GET|POST /api/teams/{teamId}/projects`, `GET /api/projects/{projectId}`, and project membership routes under `/api/projects/{projectId}/members` for listing, assigning, approving, and revoking members.

State-changing routes require a valid CSRF token. Invitation tokens are returned once and are not emailed by the current baseline. See [docs/API.md](docs/API.md) for request bodies, status codes, and access rules.

## Not implemented yet

Public registration, email verification, password recovery, automatic invitation email, team member management UI, team editing/deletion, project editing/deletion, tasks, chat, Git synchronization, and the admin dashboard remain future work. Every future protected route must explicitly enforce authorization; service classes do not secure routes automatically.

## Requirements and layout

- PHP 8.2+ is the initial CI target.
- Apache rewrite support or equivalent web-server routing configuration.
- PDO MySQL for database-backed features.
- Configure the document root to `public/` where possible; keep secrets and private runtime data outside it.

```text
public/          Web root and front controller
app/Core/        Request, response, router, module registry
app/Database/    PDO connection, repositories, migrations
app/Security/    Session, CSRF, authentication, access, rate limiting
app/Http/        HTTP controllersin/             Trusted CLI commands
database/        Versioned migrations
tests/           Lightweight smoke tests
docs/            Architecture, security, API, database, operations
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
