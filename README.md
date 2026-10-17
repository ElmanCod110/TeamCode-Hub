# TeamCode Hub

**Version:** 0.1.0-dev  
**Status:** Foundation in progress

TeamCode Hub is a modular, security-focused collaboration platform for software teams, targeting conventional PHP hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This is an early foundation, not a production-ready application. Planned capabilities are not considered implemented until code and tests exist.

## Current foundation

- Public front controller, request/response objects, and pretty-route dispatcher.
- Lazy module registry and module interface contract.
- Lightweight `GET /` and `GET /health` endpoints.
- Validated MySQL/MariaDB configuration, PDO connection factory, and explicit CLI migration runner.
- Initial users, teams, team memberships, projects, project memberships, login-rate-limit, and system-role schema.
- Hardened sessions, CSRF tokens, prepared repositories, and session-backed authentication.
- JSON authentication endpoints: `GET /api/auth/csrf`, `POST /api/auth/login`, `GET /api/auth/me`, and `POST /api/auth/logout`.
- Database-backed login throttling and trusted-proxy-aware client IP resolution.
- CLI-only one-time administrator bootstrap.
- Team/project access services that require active/approved membership.
- Smoke tests and GitHub Actions checks on PHP 8.2, 8.3, and 8.4.

## Setup

1. Configure the database environment variables using the host's environment settings or a private configuration mechanism. There is no `.env` file parser.
2. Run `php bin/migrate.php` from a trusted CLI shell.
3. Create the first administrator using the CLI-only procedure in [docs/OPERATIONS.md](docs/OPERATIONS.md).
4. Serve the project from `public/` and require HTTPS. Configure `APP_HTTPS=true` only when browser-facing HTTPS is guaranteed by the deployment.

## Authentication API

1. Call `GET /api/auth/csrf` and retain the session cookie and returned `csrf_token`.
2. Send `POST /api/auth/login` with JSON `{ "email": "...", "password": "..." }` and the token in `X-Csrf-Token` or `csrf_token`.
3. Only accounts with `status = active` can authenticate.
4. After login, fetch a fresh CSRF token before state-changing requests because the token is rotated on successful authentication.
5. Call `GET /api/auth/me` to inspect the current session. Call `POST /api/auth/logout` with a valid CSRF token to log out.

## Not implemented yet

Public registration, email verification, password recovery, admin web UI, global authorization middleware, team/project CRUD routes, tasks, chat, Git synchronization, and the admin dashboard remain future work. Access services must be called by every protected route; merely having service classes does not secure a route automatically.

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
docs/            Architecture, security, routing, database, operations
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
