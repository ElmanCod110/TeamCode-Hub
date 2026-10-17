# TeamCode Hub

**Version:** 0.1.0-dev  
**Status:** Foundation in progress

TeamCode Hub is a modular, security-focused collaboration platform for software teams, targeting conventional PHP hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This is an early foundation, not a production-ready application. Planned capabilities are not considered implemented until code and tests exist.

## Principles

- Secure-by-default request handling and server-side authorization.
- A modular monolith with explicit module contracts and lazy registration.
- PHP, HTML templates, CSS, and JavaScript separated by responsibility.
- MySQL/MariaDB support for shared hosting; no required long-running worker.
- Pretty routes from the beginning.
- Small, reviewable changes, automated checks, and clear documentation.
- No claim of process isolation between PHP modules running in the same application process.

## Current foundation

- Public front controller, request/response objects, and pretty-route dispatcher.
- Lazy module registry and module interface contract.
- Lightweight `GET /` and `GET /health` endpoints.
- Validated MySQL/MariaDB configuration and PDO connection factory.
- Explicit CLI migration runner with version registry and advisory lock.
- Initial users, teams, team memberships, projects, and project memberships schema.
- Hardened session defaults, session-bound CSRF token helper, and exact scoped permission checks.
- Prepared user and membership repositories.
- Authentication service that accepts only active accounts and rotates session IDs on successful login.
- Team/project access services that require active/approved membership.
- Smoke tests and GitHub Actions checks on PHP 8.2, 8.3, and 8.4.

HTTP login/registration routes, account recovery, rate limiting, application-wide authorization wiring, repositories for all product features, chat, Git synchronization, and admin capabilities are not implemented yet.

## Requirements

- PHP 8.2+ is the initial CI target.
- Apache with rewrite support, or equivalent web-server routing configuration.
- PDO MySQL for database-backed features.

## Layout

```text
public/          Web root and front controller
app/Core/        Request, response, router, module contracts/registry
app/Database/   PDO connection, repositories, and migration primitives
app/Security/   Session, authentication, CSRF, and access services
app/bootstrap.php
bin/             Trusted CLI commands
database/        Versioned migrations
modules/         Feature modules (to be introduced)
resources/       Templates and frontend assets (to be introduced)
storage/         Private runtime storage placeholder
tests/           Lightweight smoke tests
docs/            Architecture, security, routing, database, operations
```

Configure the document root to `public/` where possible. Keep secrets and private runtime data outside the public directory. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## Database setup

Copy the variable names in `.env.example` into the hosting provider's environment configuration or a private configuration mechanism. The project does not currently include a `.env` file parser. Run `php bin/migrate.php` from a trusted CLI shell after verifying the target database and taking an appropriate backup.

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
