# Workspace UI Integration

This document maps the English browser application to the existing server APIs. The interface is framework-free and designed for PHP shared hosting.

## Entry points and assets

- `/` — authenticated workspace shell; shows sign-in when there is no active session.
- `/login` — the same progressive-enhancement shell, initially displaying sign-in when unauthenticated.
- `/verify-email` — the workspace shell used to confirm a one-time token supplied in the URL fragment.
- `/design-system.html` — component reference page.
- `public/app.html` — semantic workspace shell.
- `public/assets/css/app.css` and its token/base/layout/component/pattern/utility layers — shared design-system styles.
- `public/assets/css/workspace.css` — product-only styles layered on the core design system.
- `public/assets/js/workspace.js` — same-origin API client, navigation, rendering, forms, and chat polling.
- `public/assets/js/ui.js` — shared design-system interactions.

Project repository links accept only public HTTPS GitHub URLs. The server shares one daily cached snapshot per repository and caps refresh attempts to 20 distinct repositories per UTC day.

The HTML has no inline style or script blocks. The front controller returns HTML with a restrictive same-origin Content Security Policy. CSS and JavaScript are external, versioned files; update their query-string version when releasing a changed asset set.

## Connected views

| View | Data source | Available interactions |
| --- | --- | --- |
| Overview | `GET /api/dashboard`, `GET /api/teams`, `GET /api/notifications`, project task endpoints | Review team/project counts, due-today and overdue tasks, recent notifications; navigate to records |
| Teams | `GET /api/teams`, `POST /api/teams`, team project/invitation endpoints | Create teams, rename a team as owner, view a team's projects, archive and restore projects as a team manager, email/revoke pending invitations, accept a token from a matching account |
| Projects | Dashboard projection and `GET /api/projects/{projectId}` | Search projects, edit name/description as an authorized team manager, archive a project without deleting its history, and open a project workspace |
| Project overview | Project detail and task endpoints | View task metrics and navigate to task/member/chat/repository panels |
| Tasks | Task list/create/edit/status, assignee search, and task detail/comment/checklist endpoints | Search/filter, find approved member-level assignees by name/email/ID, edit/reassign, view task details, discuss work, and manage a bounded checklist; viewer access remains read-only |
| Members | Project member listing/candidate search/assignment/approval/revocation endpoints | Search active team members by name, email, or exact user ID; request member/viewer access; approve pending access; change approved access; and revoke access when permitted |
| Project chat | `GET|POST /api/projects/{projectId}/chat` and message edit/delete/report endpoints | Send text, edit/delete own messages, report another member's message |
| Project repository | `GET|POST /api/projects/{projectId}/github` | View cached public repository metadata and recent commits; team owners/managers can link or unlink a public GitHub URL |
| Notifications | `GET /api/notifications`, `POST /api/notifications/{id}/read` | Receive background updates to recent notifications/unread counts while visible; mark individual or visible notifications as read |
| Account preferences | `POST /api/auth/profile`, `POST /api/auth/password` | Update the signed-in display name or change the password after verifying the current credential; email and system roles remain read-only |
| Moderation | `GET /api/admin/reports`, `GET /api/teams/{teamId}/reports`, `POST /api/reports/{reportId}/review` | Review scoped report queues, inspect preserved message context, mark reports valid/rejected, or resolve valid cases |
| User directory | `GET /api/admin/users?q=&status=all&page=1`, `POST /api/admin/users/{userId}/status`, `GET /api/admin/users/{userId}/audit` | Search/filter/page through 50 records at a time, inspect sign-in activity, disable/reactivate eligible accounts with a required reason, and review recent status-change history |
| GitHub activity | `GET /api/github/activity` | View up to 30 cached public events; the service makes at most one refresh attempt per configured username per UTC day and preserves the last good snapshot on failure |
| Preferences | Browser storage and system color preference | Choose light, dark, or system theme; sign out |

## Authentication and request safety

1. The controller requests `GET /api/auth/me` to discover the current session.
2. When unauthenticated, it obtains a CSRF token from `GET /api/auth/csrf` and displays the sign-in form.
3. Login sends `X-Csrf-Token` and same-origin cookies to `POST /api/auth/login`.
4. The server rotates the CSRF token after authentication; the browser fetches the new token before making subsequent mutations.
5. Every state-changing request uses same-origin credentials and the current CSRF token. The server remains authoritative for authentication, team membership, project membership, and access level.
6. API responses are rendered through DOM construction and `textContent`. User-provided message bodies, names, descriptions, and notifications are not treated as HTML.

The client is not an authorization boundary. Hiding a button does not replace server-side permission checks. Site-admin account status changes require a reason and are recorded transactionally; self-disable and reactivation of unverified accounts are blocked. A request can fail legitimately when a user has viewer access, pending membership, revoked access, or no team-management role.

## Notification synchronization

- The workspace polls `GET /api/notifications?limit=20` every 30 seconds while the app is open and the tab is visible.
- Returning to a visible tab triggers an immediate refresh. Polling stops when the session is hidden by the authentication screen.
- The API's unread count is authoritative, so the navigation badge can represent unread items beyond the most recent 20 shown in the inbox.
- If the notification page is open, it is re-rendered only when the visible notification snapshot changes. Poll failures do not interrupt the rest of the workspace.

## Chat behavior and limits

- The first request returns the latest bounded window of at most 50 visible messages.
- The controller fetches messages newer than the last message ID every eight seconds while the chat tab is visible.
- Every fourth background poll refreshes the bounded latest window. This reconciles edits and deletions to existing messages, which are not represented by the ID-only incremental cursor.
- Reconciliation updates only changed message rows, removes expired/out-of-window rows, and preserves the reader’s approximate scroll position when they are reading older messages.
- Polling pauses while the browser tab is hidden and stops when leaving the chat view.
- Sending, editing, deleting, and reporting use the existing CSRF-protected endpoints. The browser does not use WebSockets or download the entire message history.
- Normal messages expire after 24 hours. Reported messages can be retained while their moderation cases remain unresolved.

The UI currently uses a bounded refresh for reconciliation; it is not a durable real-time event stream. If a chat view is left open for an extended period, the server's ID cursor still bounds each incremental response to 50 messages.

## Password recovery

- The sign-in screen links to a password reset request form. Requests always show the same success response for known and unknown addresses.
- Reset links are delivered only when `APP_URL` is a valid HTTPS origin and `MAIL_FROM_ADDRESS` is configured. Tokens are placed in the URL fragment, hashed in MariaDB, expire after 30 minutes, and can be used only once.
- The reset form validates the new password and confirmation before submission. The browser removes the token from history before sending it and keeps it only in memory for transient retries.
- Successful reset increments the account's `session_version`, consumes outstanding reset tokens, and invalidates older authenticated sessions when they next access the server. The user must sign in again.

## Public registration and email verification

- Registration creates a pending account; it does not grant team/project access or bypass existing membership rules.
- The browser displays a confirmation step before submitting the token, so link scanners that only fetch URLs do not consume it.
- Configure `APP_URL` with the canonical HTTPS origin and `MAIL_FROM_ADDRESS` with a valid sender. The host must support PHP `mail()` delivery; see the operations guide.
- Responses are generic for known and unknown email addresses. Pending accounts can request a resend, subject to rate limits and a 60-second cooldown.

## Operational limitations

- Public registration, email verification, and password recovery are connected. New accounts stay pending until the recipient verifies the email address.
- Invitation creation sends a seven-day one-time link when HTTPS email delivery is configured. Successful delivery does not expose the raw token to the inviter; if delivery fails, the UI displays a one-time fallback token for sharing through a trusted channel.
- Invitation links keep their token in the URL fragment and remove it from browser history. The recipient must sign in or create and verify an account with the invited email address before explicitly accepting the invitation.
- Team invitation UI is available from a team detail view to users whose current team role is owner or manager. The server independently verifies the role.
- Member assignment searches only active members of the selected project’s team. It does not provide global account discovery or invite an email address that does not already belong to the team.
- Permanent team/project deletion, Git provider integration, system-role editing, richer site-administration pages, and browser-level visual/accessibility regression tests remain planned. Project archiving is reversible; restoration checks the active project quota and preserves historical records. Team renaming is owner-only; project metadata edits require an active team owner/manager and approved project membership.
- Theme preference is stored locally in the current browser; it does not change account-wide settings or access permissions.

## Verification

CI runs:

```sh
php tests/workspace-ui.php
node --check public/assets/js/workspace.js
```

These checks validate the static asset contract and JavaScript syntax. They do not replace manual browser testing across supported devices, keyboard-only interaction, screen-reader review, or visual regression tests. The design-system showcase includes extended input, avatar, chip, accordion, workflow-step, pagination, and content patterns.
