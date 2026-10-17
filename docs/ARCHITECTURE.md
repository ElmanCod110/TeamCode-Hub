# Architecture

## Runtime model

The initial target is a modular monolith: one PHP application process with explicit internal boundaries. Modules running in the same PHP process are **not** isolated security sandboxes. If a future feature needs a stronger isolation boundary, it must be moved to a separately restricted process or service rather than relying on module naming.

The platform targets PHP on shared hosting (cPanel/DirectAdmin) with MySQL/MariaDB and no mandatory long-running worker. A conventional front controller and Apache rewrite rules provide pretty URLs from the beginning.

## Responsibilities

- **Public entry point:** receives HTTP requests and delegates to bootstrap.
- **Core:** request/response objects, router, module registry, error handling, and shared contracts.
- **Security:** authentication, session hardening, CSRF, authorization policies, audit events, and rate limiting.
- **Persistence:** PDO connection management, repositories, versioned migrations, and transaction boundaries.
- **Feature modules:** teams, projects, tasks, chat/reporting, Git integration, admin, and future media/voice features.
- **Presentation:** PHP templates, CSS, and JavaScript kept separate by responsibility.
- **Private runtime storage:** logs, caches, uploads, and temporary artifacts; never expose these paths directly.

These are logical boundaries. They do not require every engine to become a separate service.

## Dependency direction

The front controller depends on bootstrap. Feature modules depend on documented core contracts, not on arbitrary implementation details of other modules. Database access should flow through persistence services/repositories. A central registry may instantiate modules lazily, but registration must be explicit and duplicate module identifiers must be rejected.

## Authorization

Every protected route and resource must check the current principal's effective permissions at the server. System, team, and project scopes are distinct. Project membership or viewer status must not implicitly grant access to unrelated projects. Unapproved users must be denied project routes, APIs, files, and chat even if they know a URL.

## Events and incremental updates

Internal events may decouple modules. On shared hosting, polling should be bounded and incremental: request changes after a cursor/ID, avoid duplicate concurrent polls, slow or pause polling when a tab is hidden, and retrieve only the changed resource. WebSockets are optional, not a baseline requirement.

## Configuration and deployment

Keep secrets outside the web root where possible. Commit only non-secret examples. The document root should point to `public/`. If the host cannot support that layout, deployment instructions must explicitly deny access to private directories and verify the denial. Production setup must not rely on the first anonymous visitor becoming an administrator.

## Evolution

Keep a continuously maintained backlog and deliver reviewable changes. There are no rigid project phases. A capability is considered implemented only when code, tests, and relevant documentation support that claim.
