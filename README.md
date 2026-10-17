# TeamCode Hub

**Version:** 0.2.0-dev  
**Status:** Active development

TeamCode Hub is a modular, security-focused collaboration platform for software teams, designed for conventional PHP hosting with cPanel/DirectAdmin and MySQL/MariaDB.

> This is an active development baseline, not a production-ready release. The implemented UI is connected to the existing APIs, but several product areas remain planned.

## Product interface

- English workspace shell at `/` and `/login`, with an accessible sign-in form.
- Responsive application navigation, mobile sidebar, light/dark/system theme preference, loading states, and safe toast notifications.
- Overview dashboard connected to personalized teams, projects, due-today tasks, overdue tasks, and recent notifications.
- Team list and detail views, owner-only team renaming, project creation and metadata editing for authorized team managers, a manager-only active member directory, and one-time team invitation creation for owners/managers.
- Project directory and project detail views with overview, task, member-access, and chat tabs.
- Task creation, editing/reassignment, status changes, priorities, UTC due-date conversion, threaded comments, checklist items, and task filtering/search.
- Project member assignment, pending membership approval, member/viewer access changes, and access revocation where the signed-in user has management permission.
- Text-only project chat with incremental polling, periodic bounded-window reconciliation for edits/deletions, own-message editing/deletion, and message reporting.
- Notification inbox with user-scoped read-state changes.
- Personal preferences for light/dark/system theme; an expanded token-driven design system with responsive forms, feedback, navigation, upload controls, pagination, accordions, workflow steps, and reusable content patterns. No external fonts or UI framework are required.

## Security and backend foundation

- Public front controller, request/response objects, pretty-route dispatcher, and lazy module registry.
- Validated MySQL/MariaDB configuration, PDO repositories, and explicit CLI migration runner.
- Hardened sessions, CSRF-protected authentication, login throttling, and trusted-proxy-aware IP resolution.
- CLI-only one-time administrator bootstrap.
- Team/project APIs with active/approved membership checks and transactional quota enforcement.
- Seven-day team invitations with hashed tokens, acceptance, and revocation.
- Member/viewer access separation; pending or revoked project members cannot access protected project resources.
- Task listing, member-only creation/edit/status changes, priorities, approved-member assignment, UTC due dates, comments, and checklists.
- Personalized dashboard API and user-scoped notifications.
- Project chat with a 50-message response window, incremental ID cursor, edit/delete, 24-hour expiry, and report-context preservation.
- Report review queues for team managers/site admins, duplicate-report controls, and escalation after two distinct valid reports within 24 hours.
- Same-origin Content Security Policy for HTML application responses, external CSS/JS, and versioned frontend assets.

## Run locally or deploy

1. Configure database environment variables using the host's environment settings or a private configuration mechanism. There is no `.env` parser. Set `GITHUB_USERNAME` to the public account whose events should appear in the workspace; it defaults to `ElmanCod110`.
2. Run `php bin/migrate.php` from a trusted CLI shell.
3. Create the first administrator using the CLI-only procedure in [docs/OPERATIONS.md](docs/OPERATIONS.md).
4. Configure the web server document root to `public/` and enable HTTPS. Set `APP_HTTPS=true` only when browser-facing HTTPS is guaranteed by the deployment.
5. Open `/`. The application shows the sign-in form when there is no authenticated session. Accounts must already exist and be active.

The project uses plain PHP, browser-native JavaScript, CSS, and PDO. It does not require a Node build step or a remote font download for production.

## API and frontend contracts

Authentication: `GET /api/auth/csrf`, `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`.

Teams and invitations: `GET|POST /api/teams`, `GET /api/teams/{teamId}`, `GET /api/teams/{teamId}/projects`, invitation creation/listing/acceptance/revocation.

Projects and members: project list/detail/create, member assignment/approval/revocation, task list/create/edit/status, task comments/checklists, and project chat/report workflows.

Dashboard: `GET /api/dashboard`, `GET /api/notifications`, and `POST /api/notifications/{notificationId}/read`.

See [docs/API.md](docs/API.md) for request bodies, status codes, retention, and authorization boundaries. See [docs/WORKSPACE-UI.md](docs/WORKSPACE-UI.md) for UI-to-API mappings and browser behavior.

## Validation

CI checks PHP syntax and smoke tests, design-system and workspace UI contracts, JavaScript syntax, and MariaDB integration tests on PHP 8.2, 8.3, and 8.4.

Run the lightweight local checks with:

```sh
php tests/run.php
php tests/design-system.php
php tests/workspace-ui.php
node --check public/assets/js/ui.js
node --check public/assets/js/design-system-demo.js
node --check public/assets/js/workspace.js
```

## Remaining product work

Public registration, email verification, password recovery, automatic invitation email delivery, team/project deletion, Git synchronization, richer administrative pages, and automated browser-level visual/accessibility tests are not implemented yet. There is no automatic admin bypass of team/project access checks. Every protected route must continue to enforce authorization on the server.

## Repository layout

```text
public/          Web root, front controller, English workspace shell, CSS and JS assets
app/Core/        Request, response, router, module registry
app/Database/    PDO connection, repositories, migrations
app/Security/    Session, CSRF, authentication, access, rate limiting
app/Http/        HTTP controllers
bin/             Trusted CLI commands
database/        Versioned migrations
tests/           Smoke, UI contract, and MariaDB integration tests
docs/            Architecture, security, API, UI, database, and operations
```

See [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until a license is added, do not assume that this repository grants permission to reuse, redistribute, or relicense its contents.
