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

- Front controller at \`public/index.php\`.
- Minimal request and response value objects.
- Explicit GET/POST route registration and named route parameters.
- Lazy module registry and module interface contract.
- \`GET /\` and a lightweight \`GET /health\` endpoint.
- Apache rewrite rules for pretty routes.
- Smoke tests and GitHub Actions PHP lint/test workflow.

Authentication, database persistence, migrations, team/project workflows, chat, Git synchronization, and admin capabilities are not implemented yet.

## Requirements

- PHP 8.2+ is the initial CI target.
- Apache with rewrite support, or equivalent web-server routing configuration.
- PDO MySQL will be required once database-backed features are introduced.

## Layout

\`\`\`text
public/          Web root and front controller
app/Core/        Request, response, router, module contracts/registry
app/bootstrap.php
modules/         Feature modules (to be introduced)
config/          Non-secret configuration templates
database/        Versioned migrations (to be introduced)
resources/       Templates and frontend assets (to be introduced)
storage/         Private runtime storage placeholder
tests/           Lightweight smoke tests
docs/            Architecture, security, routing, database, operations
\`\`\`

Configure the document root to \`public/\` where possible. Do not store secrets in the public directory. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
