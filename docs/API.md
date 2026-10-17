# Initial JSON API

All API responses are JSON. Responses are not a stable public contract yet; this is an early development baseline.

## Session and authentication

- `GET /api/auth/csrf` — starts the session and returns `{"csrf_token":"..."}`. Keep the session cookie and token.
- `POST /api/auth/login` — body: `{"email":"...","password":"..."}`. Send the CSRF token using `X-Csrf-Token` or `csrf_token` in the JSON body.
- `GET /api/auth/me` — returns the authenticated user and assigned system roles; otherwise `401`.
- `POST /api/auth/logout` — requires a valid CSRF token.

After a successful login, fetch a new CSRF token because authentication rotates the token. Database-backed endpoints require the configured database and all migrations to have been applied.

## GitHub public activity

- `GET /api/github/activity` — authenticated read-only endpoint returning the configured public GitHub user's latest cached events, profile URL, cache timestamp, stale flag, and refresh status.
- Set `GITHUB_USERNAME` in the hosting environment to change the account. The default is `ElmanCod110`.
- The service requests only `/users/{username}/events/public?per_page=30`. It does not request private events, repository contents, or authenticated account data, and it stores no GitHub token.
- The singleton MariaDB cache atomically claims one outbound refresh attempt per configured username per UTC day. Repeated dashboard visits read the cached snapshot and do not make another outbound request that day.
- On network, HTTP, or payload failure, the service preserves the last successful snapshot and marks it stale. A failed attempt is not retried repeatedly on the same UTC day.
- Apply the `github_activity_cache` migration using `php bin/migrate.php` before calling this endpoint. The frontend displays cached activity on the overview and a dedicated GitHub activity page.

## Dashboard and notifications

- `GET /api/dashboard` — returns active teams, approved projects, tasks due today, overdue tasks, recent notifications, and summary counts for the current user.
- `GET /api/notifications?limit=20` — returns recent notifications and unread count for the signed-in user. `limit` is clamped to 1–50.
- `POST /api/notifications/{notificationId}/read` — marks a notification as read; requires CSRF protection and only affects the signed-in user's notification.

Notifications are generated for team invitation acceptance, project access changes, task assignment/completion, and chat report receipt/escalation. Notification writes are transactional with the corresponding workflow. There is no WebSocket/push delivery in this baseline.

## Teams and invitations

- `GET /api/teams` — lists teams where the current user has an active membership.
- `POST /api/teams` — creates a team and makes the authenticated user its active owner. Body: `{"name":"Team name"}`. Requires CSRF protection.
- `POST /api/teams/{teamId}/edit` — renames a team using body `{"name":"New team name"}`. Requires CSRF protection and active owner role; managers and members cannot rename a team. The existing slug and invitation/project relationships remain unchanged.
- `GET /api/teams/{teamId}/archived-projects` — lists up to 100 archived project records for active team owners/managers so they can restore projects. It does not expose archived projects to ordinary team members.
- `GET /api/teams/{teamId}` — returns a team only to an active team member.
- `GET /api/teams/{teamId}/invitations` — lists pending invitation metadata for an active team owner/manager. Tokens are not returned by this listing.
- `POST /api/teams/{teamId}/invitations` — creates a seven-day invitation for an email. Body: `{"email":"person@example.com"}`. Requires CSRF protection and owner/manager access. The raw random token is returned once; only its SHA-256 hash is stored. The baseline does not send email, so the inviter must deliver the token through a trusted channel.
- `DELETE /api/teams/{teamId}/invitations/{invitationId}` — revokes a pending invitation; requires CSRF protection and owner/manager access.
- `POST /api/team-invitations/accept` — authenticated user accepts an invitation with body `{"token":"..."}` and CSRF protection. The signed-in email must match. The invitation expires after seven days and cannot be reused.

Invitation creation reserves a team member slot. Active memberships plus unexpired pending invitations cannot exceed `member_limit`. Invitations can grant only the `member` role.

## Projects and members

- `GET /api/teams/{teamId}/projects` — lists active projects with approved project membership and active team membership for the current user.
- `POST /api/teams/{teamId}/projects` — creates a project for an active team owner/manager. Body: `{"name":"Project name","description":"Optional description"}`. Requires CSRF protection. The creator receives approved `member` access. Active project quota is enforced transactionally; quota exhaustion returns `409`.
- `POST /api/projects/{projectId}/edit` — updates project name and description using body `{"name":"New project name","description":"Updated description"}`. Requires CSRF protection, active team owner/manager role, and approved project membership. Empty descriptions are stored as `NULL`; project slug, memberships, tasks, and chat history are preserved.
- `POST /api/projects/{projectId}/archive` — archives an active project for an active team owner/manager. Requires CSRF protection. This is reversible; memberships, tasks, comments, and chat history are preserved, but the project is removed from active access/list queries.
- `POST /api/projects/{projectId}/restore` — restores an archived project for an active team owner/manager. Requires CSRF protection and available active project quota; returns `409` if restoring would exceed the team's active project limit.
- `GET /api/projects/{projectId}` — returns project details only when the user's project membership is approved and team membership is active. Viewers have read-only access.
- `GET /api/projects/{projectId}/members` — lists membership details for active team owners/managers only.
- `GET /api/projects/{projectId}/members/candidates?q=<term>` — searches up to 12 active users who belong to the project's team and do not already have approved project access. Search matches display name/email or an exact user ID. A pending membership is returned with its status so managers can update the pending access level. Requires active team owner/manager access; the query must contain 1–80 valid UTF-8 bytes.
- `POST /api/projects/{projectId}/members` — assigns an active team member with body `{"user_id":123,"access_level":"viewer"}` or `member`. Assignment is pending by default and requires CSRF protection.
- `POST /api/projects/{projectId}/members/{userId}/approve` — approves a pending project membership. Requires CSRF protection and active team owner/manager permissions.
- `POST /api/projects/{projectId}/members/{userId}/revoke` — revokes pending or approved access. The project creator cannot be revoked through this endpoint. Requires CSRF protection.
- `POST /api/projects/{projectId}/members/{userId}/access` — changes an approved member between `viewer` and `member` using body `{"access_level":"viewer"}` or `member`. Requires CSRF protection and active team owner/manager role. The project creator cannot be demoted to viewer. The affected member is notified when their level changes; repeating the current level is a no-op.

Pending and revoked project memberships cannot access project routes. Only approved `member` access can mutate tasks or send chat messages; a `viewer` is read-only.

## Tasks

- `GET /api/projects/{projectId}/tasks` — lists tasks for any user with approved project access, including viewers.
- `POST /api/projects/{projectId}/tasks` — creates a task; requires approved `member` access and CSRF protection. Body: `{"title":"Review pull request","description":"Optional","priority":"normal","assigned_to":123,"due_at":"2026-11-01 12:00:00"}`. Priorities: `low`, `normal`, `high`, `urgent`. Due dates use UTC `YYYY-MM-DD HH:MM:SS`. An assignee must have approved `member` access to the same project.
- `POST /api/projects/{projectId}/tasks/{taskId}/status` — changes task status; requires approved `member` access and CSRF protection. Body: `{"status":"in_progress"}`. Statuses: `todo`, `in_progress`, `blocked`, `done`.

## Task details, comments, and checklists

- `POST /api/projects/{projectId}/tasks/{taskId}/edit` — updates title, description, priority, assignee, and UTC due date. Requires CSRF protection and approved member-level access. Reassignment is restricted to active approved member-level project users.
- `GET /api/projects/{projectId}/tasks/{taskId}/details` — returns the task, up to 100 comments, and up to 50 checklist items for approved project members/viewers.
- `POST /api/projects/{projectId}/tasks/{taskId}/comments` — body: `{"body":"Implementation note"}`. Requires approved member-level access and CSRF protection; body is limited to 4,000 bytes. A new comment notifies the task creator and current assignee, excluding the author.
- `POST /api/projects/{projectId}/tasks/{taskId}/comments/{commentId}/edit` — body: `{"body":"Updated note"}`. Only the comment author may edit within 15 minutes.
- `POST /api/projects/{projectId}/tasks/{taskId}/comments/{commentId}/delete` — soft-deletes the author's comment and preserves a visible deletion marker in the thread.
- `POST /api/projects/{projectId}/tasks/{taskId}/subtasks` — body: `{"title":"Add regression coverage"}`. Creates a checklist item; at most 50 items per task.
- `POST /api/projects/{projectId}/tasks/{taskId}/subtasks/{subtaskId}/toggle` — toggles completion for a checklist item; requires approved member-level access.
- `POST /api/projects/{projectId}/tasks/{taskId}/subtasks/{subtaskId}/delete` — deletion is limited to the checklist item's creator or the task creator.

Viewers can read task details, comments, and checklist items but cannot edit tasks, post comments, or mutate checklist state. These permissions are checked in the repository layer, not just in the browser.

## Project chat

- `GET /api/projects/{projectId}/chat` — returns at most 50 visible messages. The first request returns the latest 50 in chronological order. Pass `?after_id=<last_message_id>` to retrieve newer messages incrementally; there are no page-number controls.
- `POST /api/projects/{projectId}/chat` — sends a text message with body `{"body":"Hello team"}`. Requires approved `member` access and CSRF protection; message body is limited to 2000 bytes.
- `POST /api/projects/{projectId}/chat/{messageId}/edit` — edits your own recent message (15-minute edit window). Messages with unresolved reports cannot be edited.
- `POST /api/projects/{projectId}/chat/{messageId}/delete` — soft-deletes your own message by replacing its visible body with a deletion marker. A report's saved context is retained.
- `POST /api/projects/{projectId}/chat/{messageId}/report` — reports another user's message with body `{"reason":"Brief explanation"}`. Reason is limited to 500 bytes. The report captures the message plus adjacent context and holds the message from expiry until all related cases are resolved/rejected.

Normal messages expire after 24 hours. Run `php bin/cleanup-chat.php` from a trusted CLI cron job at least daily to physically remove expired rows. Messages under unresolved reports have `expires_at = NULL` and are not removed by cleanup. After all related reports are resolved/rejected, the 24-hour retention countdown resumes.

## Report review

- `GET /api/teams/{teamId}/reports` — pending/valid reports routed to an active team owner/manager.
- `GET /api/admin/reports` — reports routed to a site administrator.
- `POST /api/reports/{reportId}/review` — body `{"action":"valid"}`, `{"action":"rejected"}`, or `{"action":"resolved"}`. Requires CSRF protection and the report's current review scope.

Only one report per reporter per reported person per UTC day is accepted. A valid report initially stays with the team manager. When at least two distinct reporters have valid reports against the same person within 24 hours, pending/valid reports in that window escalate to site administrators. Reports about a team manager, or cases without an eligible manager, go directly to site administrators. A reporter or reported person cannot review that case. A report is an allegation, not proof of wrongdoing.

## Common errors

- `401` — no active authenticated session.
- `403` — site administrator permission required or a reviewer has a conflict of interest.
- `404` — resource not found or membership/management access denied.
- `409` — quota reached, duplicate report, or conflicting state.
- `419` — missing, invalid, or expired CSRF token.
- `422` — invalid request body or report token.
- `429` — login attempt limit reached.

## Current limits

Public registration, email verification, password recovery, automatic invitation email delivery, permanent team/project deletion, Git provider synchronization, richer administrative pages, and browser-level visual/accessibility tests are not implemented yet. Project chat uses bounded HTTP polling rather than WebSockets or push delivery. Future routes must repeat authorization checks in their data-access path rather than trusting a prior UI check alone.
