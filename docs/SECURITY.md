# Security Baseline

This document distinguishes implemented primitives from controls still required before production.

## Implemented primitives

- Session startup enables strict mode, cookie-only session IDs, HttpOnly cookies, SameSite=Lax, and Secure cookies when direct HTTPS is detected or `APP_HTTPS=true` is configured.
- Session IDs are rotated after successful authentication; logout clears session data and expires the session cookie. Password changes and password resets increment a database-backed session version, invalidating older authenticated sessions on their next request.
- Public registration creates pending accounts only. Email verification uses 256-bit random bearer tokens stored as SHA-256 hashes, a 24-hour expiry, single-use consumption, a resend cooldown, and purpose-specific IP/email rate limits. Verification links keep tokens in the URL fragment and require an explicit confirmation POST.
- State-changing authentication, workspace, invitation, task, chat, report, and notification routes require session-bound CSRF tokens.
- Authentication accepts only active accounts and uses generic failure responses. Login attempts are throttled with database-backed per-IP and per-email-hash buckets.
- Forwarded client IP data is ignored unless `REMOTE_ADDR` matches an exact IP in `TRUSTED_PROXIES`.
- Team/project/task/chat access is checked server-side; project access requires approved membership plus active team membership. Viewers can read but cannot mutate tasks or send/edit/delete chat messages.
- Team renaming is owner-only and is rechecked inside the team repository transaction. Project archive/restore is limited to active team owners/managers, runs transactionally, and preserves project records rather than deleting them; restore rechecks the active project quota. Project metadata updates require an active team owner/manager role plus approved project membership, with both checks repeated inside the project repository transaction. Project access-level changes are restricted to team owners/managers, require approved memberships, notify the affected member, and prevent demoting the project creator to viewer.
- Project assignments start as `pending`; active team owners/managers approve them.
- Normal chat messages expire after 24 hours. A reported message is held with a null expiry while any related report is pending/valid. CLI cleanup deletes only rows whose expiry has passed.
- Chat report creation stores a context snapshot of the reported message and adjacent messages. Only one report per reporter per reported person per UTC day is accepted.
- A report against a team manager or a case without an eligible manager is routed to site admins. After two distinct reporters have valid reports against a person within 24 hours, pending/valid reports in that window escalate to site admins.
- The reporter and reported person cannot review that case. Report status is a review workflow, not proof of wrongdoing or an automatic sanction.
- Notification reads are scoped to the signed-in user; notification events are inserted within the related database transaction.
- Team invitations use random tokens stored only as hashes and reserve member quota. Project quota checks run transactionally.
- Initial site administrator creation is CLI-only.

## Required controls before production

- Add operational monitoring and alerting for chat posting, report submission, project membership changes, account recovery, and other sensitive actions; account recovery already has purpose-specific IP/email and token-attempt rate limits.
- Public registration, email verification, password recovery, and team invitation email delivery require correctly configured HTTPS URLs and mail delivery before production use.
- Define and document final retention durations for report snapshots, notifications, and audit data; schedule the chat cleanup CLI job.
- Review abuse handling and notification access for reported users, reviewers, and site administrators.
- Ensure every future file, Git, and admin route checks authorization at the data-access boundary.
- Keep real secrets outside the web root and never log passwords, session tokens, raw invitation tokens, or report context.
- Test migrations and SQL behavior against supported MySQL/MariaDB versions and the production HTTPS/shared-hosting topology.

## Data and migrations

Use PDO prepared statements for values and context-appropriate output encoding. Validate upload type, size, names, storage location, and download authorization before adding upload features. Versioned migrations are run explicitly through a trusted CLI command; MySQL DDL may partially apply if interrupted, so inspect state before retrying.

Security controls require tests and review. No numerical security percentage is promised.
