# Changelog

Notable changes to TeamCode Hub are recorded here.

## [0.2.0-dev] - Unreleased

### Workspace interface

- Add an English application shell with an accessible sign-in form and authenticated workspace navigation.
- Serve the product shell at `/` and `/login` through the PHP front controller.
- Add responsive sidebar, mobile navigation, connection state, notifications indicator, and light/dark/system theme preference.
- Connect overview metrics and recent activity to the dashboard, team, task, and notification APIs.
- Add team listing, team creation, team project views, and one-time invitation creation for authorized team owners/managers.
- Add project directory, project details, task/member/chat tabs, project-member assignment and moderation controls.
- Add task creation, status changes, search/filter controls, and conversion of local date input to UTC API values.
- Add project chat send/edit/delete/report controls with eight-second incremental polling and periodic bounded-window reconciliation for existing-message edits/deletions.
- Add user-scoped notification read actions and safe, text-only toast feedback.
- Add a same-origin Content Security Policy to HTML responses and keep product CSS/JavaScript in external versioned files.
- Add workspace UI contract tests and CI syntax checks for the new controller.

### Design system

- Add centralized neutral and semantic design tokens, optional explicit dark-theme tokens, and an installed-system-font stack.
- Add separate base, responsive layout, component, utility, and design-system entry stylesheets.
- Add reusable accessible controls, forms, badges, cards, alerts, dashboard metrics, tables, tabs, dropdowns, dialogs, toasts, progress indicators, and empty states.
- Add dependency-free UI interactions for dropdowns, keyboard-navigable tabs, modal dialogs, mobile sidebars, theme switching, progress semantics, and text-safe notifications.
- Add an English component showcase with no inline styles or inline JavaScript and no external font downloads.
- Add frontend asset smoke tests and JavaScript syntax checks to CI.

### Existing backend

- Preserve session hardening, CSRF validation, login throttling, and trusted-proxy-aware IP resolution.
- Preserve team/project access checks, quota enforcement, invitations, task workflows, notifications, chat retention, and report escalation.

## [0.1.0-dev] - Foundation baseline

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
