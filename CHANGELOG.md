# Changelog

## Unreleased

- Add a project Files tab with a searchable private source-file directory, lightweight text editor, save/reload controls, and revision-history restore.
- Add optimistic version checks, a 200-file project limit, a 256 KiB file limit, a 10 MiB current-source quota, and a 20 MiB revision-history quota with 20 retained versions per file.
- Keep project file reads available to approved viewers while restricting creation, editing, and restoration to approved member-level access.

- Add generic password-reset requests and a responsive reset form connected to the application shell.
- Store only SHA-256 token hashes, enforce a 30-minute expiry and 60-second resend cooldown, rate-limit public requests, and consume reset tokens transactionally.
- Increment account session versions after credential changes so password reset invalidates existing sessions.
- Add MariaDB integration and workspace contract checks for token expiry, replay prevention, password replacement, and session invalidation.


- Add public account registration that creates pending users and avoids email-existence disclosure.
- Add hashed, single-use 24-hour email verification tokens, transactional activation, resend cooldowns, and purpose-specific rate limits.
- Add HTTPS-only verification email delivery configuration and an explicit browser confirmation step that keeps tokens out of HTTP request logs.
- Add MariaDB integration and UI contract coverage for duplicate registration, verification, expiry/use semantics, and replay prevention.

- Implement the already-registered team rename and project metadata routes in `WorkspaceController`.
- Reuse the existing transactional repository checks: team renaming is owner-only; project settings require an active team owner/manager with approved project access.
- Extend workspace UI contract tests to fail when a registered settings route has no controller handler.

- Add authenticated display-name editing without exposing email or system-role mutation.
- Add password changes requiring the current password, matching confirmation, and a 12–72 byte limit.
- Persist password updates with a compare-and-swap hash check to avoid overwriting concurrent changes.
- Rotate the current session identifier and CSRF token after successful password changes.
- Connect account dialogs to Preferences and add integration/UI contract coverage.

Notable changes to TeamCode Hub are recorded here.


- Align password-reset redemption lock order with token issuance by locking the account row before the token row, reducing avoidable concurrent-reset deadlocks.
- Add a site-admin-only user directory with search by name, email, or exact user ID, status filters, and bounded pagination.
- Add administrator-only account disable/reactivate actions with required reasons, transactional audit records, self-disable prevention, and email-verification safeguards.
- Add a bounded account audit-history endpoint and responsive history dialog.
- Return at most 50 public account records and never select or serialize password hashes.
- Enforce the administrator role in both controller and repository layers; cancel stale browser search requests.

## [0.2.0-dev] - Unreleased

### Project repository integration

- Add project-scoped public GitHub repository linking for team owners/managers and read-only summaries for approved project members.
- Cache repository metadata and five recent commits once per repository per UTC day, preserving the last good snapshot after failures.
- Add a shared 20-repository daily refresh budget, strict HTTPS GitHub URL validation, and no-token public API transport.

### GitHub integration

- Add a public GitHub events reader with strict username validation and HTTPS-only transport.
- Add a singleton MariaDB cache that atomically claims one refresh attempt per configured username per UTC day.
- Preserve the last successful snapshot on network or API failure and avoid retry storms on repeated visits.
- Add an authenticated GitHub activity endpoint, overview preview, profile/repository links, and a dedicated activity view.
- Add deterministic integration tests for daily request limits, event normalization, and stale-cache fallback.

### Project lifecycle

- Add reversible project archival that removes a project from active access/list queries without deleting tasks, memberships, comments, or chat history.
- Add a manager-only archived-project list and restore controls in the team workspace.
- Enforce the active project quota transactionally during restoration and return a conflict when capacity is full.
- Add integration coverage for authorization, historical data preservation, archived-list visibility, and quota-blocked restoration.

### Searchable task assignees

- Add a project-scoped endpoint that returns only active, approved member-level users eligible for task assignment.
- Replace numeric assignee IDs with a debounced searchable picker when creating or editing tasks.
- Clear selected assignees when the query or selected project changes; cancel stale requests and preserve the option to leave tasks unassigned.
- Add responsive picker styles and integration tests for viewer, pending-member, and unauthorized-user restrictions.

### Searchable project membership

- Add a manager-authorized endpoint that searches only active members of the selected project’s team and excludes users with approved project access.
- Replace manual user-ID entry with an accessible, debounced member picker that searches by name, email, or exact ID and shows pending-request state.
- Preserve server-side membership checks, use prepared queries, bound results to 12 candidates, and add MariaDB and UI contract tests.
- Add responsive selection/results states and cache-bust the updated workspace assets.

### Project access management

- Add a CSRF-protected access-level endpoint for approved project memberships.
- Let active team owners/managers switch eligible members between read-only viewer and writable member access without recreating memberships.
- Prevent demoting the project creator, reject unauthorized actors at the repository boundary, and notify the affected member when access changes.
- Add UI controls with confirmation, explicit current access badges, and automated MariaDB/UI contract coverage.

### Workspace management

- Add CSRF-protected team renaming restricted to the active team owner; preserve the team's stable slug and all existing relationships.
- Add CSRF-protected project name/description updates restricted to active team owners/managers with approved project membership.
- Add connected edit dialogs in the English workspace, refresh the relevant records after save, and retain task, membership, and chat history.
- Add MariaDB integration coverage for authorized edits and rejected owner/manager/member permission boundaries.

### Live notification synchronization

- Refresh recent notifications and authoritative unread counts every 30 seconds while the authenticated workspace is visible.
- Trigger a refresh when the user returns to the tab and stop polling when the authentication screen is shown.
- Update the open notification inbox only when its visible data changes, avoiding unnecessary re-renders and scroll disruption.
- Keep notification polling failures isolated from the rest of the workspace and add UI contract checks.

### Project chat synchronization

- Fix the background reconciliation counter so the bounded full sync actually runs every fourth poll.
- Reconcile message revisions and soft deletions, and remove expired messages that leave the latest 50-message window.
- Avoid rebuilding unchanged message rows during reconciliation, preserving screen-reader stability and the reader's approximate scroll position.
- Add UI contract coverage for revision reconciliation and scroll anchoring; bump the workspace JavaScript cache version.

### Workspace interface

- Add an English application shell with an accessible sign-in form and authenticated workspace navigation.
- Serve the product shell at `/` and `/login` through the PHP front controller.
- Add responsive sidebar, mobile navigation, connection state, notifications indicator, and light/dark/system theme preference.
- Connect overview metrics and recent activity to the dashboard, team, task, and notification APIs.
- Add team listing, team creation, team project views, and one-time invitation creation for authorized team owners/managers.
- Add project directory, project details, task/member/chat tabs, project-member assignment and moderation controls.
- Add task creation, status changes, search/filter controls, and conversion of local date input to UTC API values.
- Add transactional task editing and reassignment with approved member-level assignee validation.
- Add persistent task comments with creator/assignee notifications, a 15-minute edit window, and soft-delete markers.
- Add a bounded 50-item checklist with completion toggles and creator/task-creator deletion permissions.
- Add a task details dialog that connects editable task metadata, checklist items, and threaded discussion while keeping viewers read-only.
- Add project chat send/edit/delete/report controls with eight-second incremental polling and periodic bounded-window reconciliation for existing-message edits/deletions.
- Add user-scoped notification read actions and safe, text-only toast feedback.
- Add permission-gated moderation queues with captured conversation context and server-authorized report review actions.
- Add a same-origin Content Security Policy to HTML responses and keep product CSS/JavaScript in external versioned files.
- Add workspace UI contract tests and CI syntax checks for the new controller.

### Design system

- Add an extended pattern layer for the supplied base-style vocabulary without replacing the token/base/layout/component/utility architecture.
- Complete reusable upload zones, range controls, switch states, avatar presence markers, removable chips, pagination, breadcrumbs, accordions, tooltips, keyboard hints, workflow steps, quote/code blocks, and footer layouts.
- Add responsive breakpoints plus reduced-motion and forced-colors fallbacks for the extended patterns.
- Expand the English showcase with practical, keyboard-operable examples and no inline styles.
- Refresh shared stylesheet cache keys and add automated assertions for extended component coverage.

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
