# Initial JSON API

All API responses are JSON. Responses are not a stable public contract yet; this is an early development baseline.

## Session and authentication

- `GET /api/auth/csrf` — starts the session and returns `{"csrf_token":"..."}`. Keep the session cookie and token.
- `POST /api/auth/login` — body: `{"email":"...","password":"..."}`. Send the CSRF token using `X-Csrf-Token` or `csrf_token` in the JSON body.
- `GET /api/auth/me` — returns the authenticated user and assigned system roles; otherwise `401`.
- `POST /api/auth/logout` — requires a valid CSRF token.

After a successful login, fetch a new CSRF token because authentication rotates the token. Database-backed endpoints require the configured database and all migrations to have been applied.

## Dashboard and notifications

- `GET /api/dashboard` — returns the authenticated user's active teams, approved projects, tasks due today, overdue tasks, recent notifications, and summary counts. Task queries include only projects where the user has approved membership and active team membership. Done tasks are excluded from due/overdue lists.
- `GET /api/notifications?limit=20` — returns recent notifications and unread count for the signed-in user. `limit` is clamped to 1–50.
- `POST /api/notifications/{notificationId}/read` — marks a notification as read; requires CSRF protection. A user can only mark their own notification as read.

Notifications are currently generated for team invitation acceptance, project access pending/approval/revocation, task assignment, and task completion. Notification writes occur in the same database transaction as the corresponding workflow. The API does not currently push updates over WebSockets; clients can request a lightweight refresh when needed.

## Teams and invitations

- `GET /api/teams` — lists teams where the current user has an active membership.
- `POST /api/teams` — creates a team and makes the authenticated user its active owner. Body: `{"name":"Team name"}`. Requires CSRF protection.
- `GET /api/teams/{teamId}` — returns a team only to an active team member.
- `GET /api/teams/{teamId}/invitations` — lists pending invitation metadata for an active team owner/manager. Tokens are not returned by this listing.
- `POST /api/teams/{teamId}/invitations` — creates a seven-day invitation for an email. Body: `{"email":"person@example.com"}`. Requires CSRF protection and owner/manager access. The raw random token is returned once; only its SHA-256 hash is stored. The baseline does not send email, so the inviter must deliver the token through a trusted channel.
- `DELETE /api/teams/{teamId}/invitations/{invitationId}` — revokes a pending invitation; requires CSRF protection and owner/manager access.
- `POST /api/team-invitations/accept` — authenticated user accepts an invitation with body `{"token":"..."}` and CSRF protection. The signed-in email must match. The invitation expires after seven days and cannot be reused.

Invitation creation reserves a team member slot. Active memberships plus unexpired pending invitations cannot exceed `member_limit`. Invitations can grant only the `member` role.

## Projects and members

- `GET /api/teams/{teamId}/projects` — lists active projects with approved project membership and active team membership for the current user.
- `POST /api/teams/{teamId}/projects` — creates a project for an active team owner/manager. Body: `{"name":"Project name","description":"Optional description"}`. Requires CSRF protection. The creator receives approved `member` access. Active project quota is enforced transactionally; quota exhaustion returns `409`.
- `GET /api/projects/{projectId}` — returns project details only when the user's project membership is approved and team membership is active. Viewers have read-only access.
- `GET /api/projects/{projectId}/members` — lists membership details for active team owners/managers only.
- `POST /api/projects/{projectId}/members` — assigns an active team member with body `{"user_id":123,"access_level":"viewer"}` or `member`. Assignment is pending by default and requires CSRF protection.
- `POST /api/projects/{projectId}/members/{userId}/approve` — approves a pending project membership. Requires CSRF protection and active team owner/manager permissions.
- `POST /api/projects/{projectId}/members/{userId}/revoke` — revokes pending or approved access. The project creator cannot be revoked through this endpoint. Requires CSRF protection.

Pending and revoked project memberships cannot access project routes. Only approved `member` access can create or update tasks; a `viewer` is read-only.

## Tasks

- `GET /api/projects/{projectId}/tasks` — lists tasks for any user with approved project access, including viewers.
- `POST /api/projects/{projectId}/tasks` — creates a task; requires approved `member` access and CSRF protection. Body: `{"title":"Review pull request","description":"Optional","priority":"normal","assigned_to":123,"due_at":"2026-11-01 12:00:00"}`. `description`, `assigned_to`, and `due_at` may be null/omitted. Priorities: `low`, `normal`, `high`, `urgent`. Due dates use UTC `YYYY-MM-DD HH:MM:SS`. An assignee must have approved `member` access to the same project.
- `POST /api/projects/{projectId}/tasks/{taskId}/status` — changes task status; requires approved `member` access and CSRF protection. Body: `{"status":"in_progress"}`. Statuses: `todo`, `in_progress`, `blocked`, `done`. The completion timestamp is set when status becomes `done` and cleared when it leaves `done`.

## Common errors

- `401` — no active authenticated session.
- `404` — resource not found or membership/management access denied.
- `419` — missing, invalid, or expired CSRF token.
- `422` — invalid request body or invitation token.
- `409` — quota reached or a conflicting membership/invitation exists.
- `429` — login attempt limit reached.

## Current limits

Public registration, email verification, password recovery, invitation email delivery, team editing/deletion, project editing/deletion, task editing/assignment after creation, comments, subtasks, chat, Git integration, and admin UI are not implemented yet. Future routes must repeat authorization checks in their data-access path rather than trusting a prior UI check alone.
