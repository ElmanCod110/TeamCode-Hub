# Initial JSON API

All API responses are JSON. Responses are not a stable public contract yet; the API is an early development baseline.

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

## Projects

- `GET /api/teams/{teamId}/projects` — lists active projects with an approved project membership and active team membership for the current user.
- `POST /api/teams/{teamId}/projects` — creates a project for an active team owner/manager. Body: `{"name":"Project name","description":"Optional description"}`. Requires CSRF protection. The creator receives an approved `member` membership. The active project quota is enforced transactionally; quota exhaustion returns `409`.
- `GET /api/projects/{projectId}` — returns project details only when the user's project membership is approved and team membership is active. Viewer memberships permit reading but not editing.

## Common errors

- `401` — no active authenticated session.
- `404` — resource not found or membership/management access denied. The API intentionally avoids revealing inaccessible resource existence.
- `419` — missing, invalid, or expired CSRF token.
- `422` — invalid request body.
- `409` — active project quota reached.
- `429` — login attempt limit reached.

## Current limits

Registration, invitations, project membership approval workflows, team-member quota enforcement, project editing/deletion, tasks, chat, Git integration, and admin UI are not implemented yet. The service classes are not a substitute for authorization checks on every future route.
