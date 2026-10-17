# TeamCode Hub

**Version:** 0.1.0-dev  
**Status:** Foundation in progress

TeamCode Hub is a modular, security-focused collaboration platform for software teams, targeting conventional PHP hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This is an early foundation, not a production-ready application. Planned capabilities are not considered implemented until code and tests exist.

## Current foundation

- Public front controller, request/response objects, pretty-route dispatcher, and lazy module registry.
- Validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migration runner.
- Identity, team/project membership, login-rate-limit, and system-role schema.
- Hardened sessions, CSRF tokens, authentication API, login throttling, and trusted-proxy-aware IP resolution.
- CLI-only one-time administrator bootstrap.
- Team API for listing/creating teams and reading a team when the user is an active member.
- Project API for listing approved projects, creating projects as an active team owner/manager, and reading projects only after membership approval.
- Project quota enforcement during creation and read-only viewer access.
- Automated linting and smoke tests on PHP 8.2, 8.3, and 8.4.

## Setup

1. Configure database environment variables using the host's environment settings or a private configuration mechanism. There is no `.env` parser.
2. Run `php bin/migrate.php` from a trusted CLI shell.
3. Create the first administrator using the CLI-only procedure in [docs/OPERATIONS.md](docs/OPERATIONS.md).
4. Serve the project from `public/` and require HTTPS. Configure `APP_HTTPS=true` only when browser-facing HTTPS is guaranteed by the deployment.

## API

Authentication routes: `GET /api/auth/csrf`, `POST /api/auth/login`, `GET /api/auth/me`, and `POST /api/auth/logout`.

Workspace routes: `GET|POST /api/teams`, `GET /api/teams/{teamId}`, `GET|POST /api/teams/{teamId}/projects`, and `GET /api/projects/{projectId}`. State-changing routes require a valid CSRF token. See [docs/API.md](docs/API.md) for request bodies, status codes, and access rules.

## Not implemented yet

Public registration, email verification, password recovery, invitations, project membership approval UI, team-member quota enforcement, project editing/deletion, tasks, chat, Git synchronization, and the admin dashboard remain future work. Every future protected route must explicitly enforce authorization; service classes do not secure routes automatically.

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
app/Http/        HTTP controllers
bin/             Trusted CLI commands
database/        Versioned migrations
tests/           Lightweight smoke tests
docs/            Architecture, security, API, database, operations
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
