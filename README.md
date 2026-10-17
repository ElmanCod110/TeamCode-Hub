# TeamCode Hub

**Version:** 0.1.0-dev  
**Status:** Foundation in progress

TeamCode Hub is a modular PHP collaboration platform for software teams, targeting shared hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This repository is being bootstrapped. Planned capabilities are not considered implemented until code and tests exist.

## Principles
- Server-side authorization for every protected route and resource; deny by default.
- Modular monolith with explicit contracts and lazy registration.
- Shared-hosting-friendly PHP and MySQL/MariaDB baseline.
- Separate routing, services, persistence, templates, CSS, and JavaScript.
- Private configuration, logs, and runtime storage outside the web root where possible.
- Small, testable commits and continuous backlog maintenance rather than rigid phases.

## Planned capabilities
Authentication and security events; scoped system/team/project roles; team and project membership; tasks and dashboards; lightweight AJAX chat and abuse reporting; cached Git integration; admin operations; migrations and health checks.

These are planned capabilities, not claims of current functionality.

## Target layout
```text
public/      Web root and front controller
app/         Bootstrap, routing, services, contracts
modules/     Feature modules
config/      Non-secret configuration templates
database/    Versioned migrations
resources/   Templates and frontend source assets
storage/     Private logs, cache, uploads
 tests/      Automated tests
docs/        Architecture and operations
```

## Development
See CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, and docs/ for design and workflow.

## License
No license has been selected. Until a license is added, do not assume reuse or redistribution permission.
