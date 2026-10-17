# Initial JSON API

All API responses are JSON. Responses are not a stable public contract yet; this is an early development baseline.

## Session and authentication

- `GET /api/auth/csrf` — starts the session and returns `{"csrf_token":"..."}`. Keep the session cookie and token.
- `POST /api/auth/login` — body: `{"email":"...","password":"..."}`. Send the CSRF token using `X-Csrf-Token` or `csrf_token` in the JSON body.
- `GET /api/auth/me` — returns the authenticated user and assigned system roles; otherwise `401`.
- `POST /api/auth/logout` — requires a valid CSRF token.

After a successful login, fetch a new CSRF token because authentication rotates the token. Database-backed endpoints require the configured database and all migrations to have been applied.

## Teams

- `GET /api/teams` — lists teams where the current user has an active membership.
- `POST /api/teams` — creates a team and makes the authenticated user its active owner. Body: `{"name":"Team name"}`. Requires CSRF protection.
- `GET /api/teams/{teamId}` — returns a team only to an active team member.
- `GET /api/teams/{teamId}/invitations` — lists pending invitation metadata for an active team owner/manager. Tokens are not returned by this listing.
- `POST /api/teams/{teamId}/invitations` — creates a seven-day invitation for an email. Body: `{"email":"person@example.com"}`. Requires CSRF protection and owner/manager access. The raw random token is returned once; only its SHA-256 hash is stored. The baseline does not send email, so the inviter must deliver the token through a trusted channel.
- `DELETE /api/teams/{teamId}/invitations/{invitationId}` — revokes a pending invitation; requires CSRF protection and owner/manager access.
- `POST /api/team-invitations/accept` — authenticated user accepts an invitation with body `{"token":"..."}` and CSRF protection. The signed-in email must match. The invitation expires after seven days and cannot be reused.

Invitation creation reserves a team member slot. Active memberships plus unexpired pending invitations cannot exceed `member_limit`. Invitations can grant only the `member` role.

## Projects

- `GET /api/teams/{teamId}/projects` — lists active projects with an approved project membership and active team membership for the current user.
- `POST /api/teams/{teamId}/projects` — creates a project for an active team owner/manager. Body: `{"name":"Project name","description":"Optional description"}`. Requires CSRF protection. The creator receives approved `member` access. Active project quota is enforced transactionally; quota exhaustion returns `409`.
- `GET /api/projects/{projectId}` — returns project details only when the user's project membership is approved and team membership is active. Viewers have read-only access.
- `GET /api/projects/{projectId}/members` — lists membership details for active team owners/managers only.
- `POST /api/projects/{projectId}/members` — assigns an active team member to a project with body `{"user_id":123,"access_level":"viewer"}` or `member`. Assignment is pending by default and requires CSRF protection.
- `POST /api/projects/{projectId}/members/{userId}/approve` — approves a pending project membership. Requires CSRF protection and active team owner/manager permissions.
- `POST /api/projects/{projectId}/members/{userId}/revoke` — revokes pending or approved access. The project creator cannot be revoked through this endpoint. Requires CSRF protection.

Pending and revoked project memberships cannot access project routes. Only an approved `member` can edit project content in future endpoints; a `viewer` is read-only.

## Common errors

- `401` — no active authenticated session.
- `404` — resource not found or membership/management access denied.
- `419` — missing, invalid, or expired CSRF token.
- `422` — invalid request body or invitation token.
- `409` — quota reached or a conflicting membership/invitation exists.
- `429` — login attempt limit reached.

## Current limits

Public registration, email verification, password recovery, invitation email delivery, team member management UI, team editing/deletion, project editing/deletion, tasks, chat, Git integration, and admin UI are not implemented yet. Future routes must repeat authorization checks in their data-access path rather than trusting a prior UI check alone.
