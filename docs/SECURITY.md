# Security Baseline

This document is a design checklist, not a certification or a claim that all controls are already implemented.

## Request and identity

- Validate request method, content type, size, and input shape.
- Configure sessions with strict mode, cookie-only IDs, HttpOnly, Secure under HTTPS, and an appropriate SameSite policy.
- Regenerate session identifiers after authentication and privilege changes.
- Apply CSRF defenses to browser-based state-changing requests.
- Use password hashing APIs and generic authentication failure responses.

## Authorization

- Default to deny.
- Check permissions on every protected route and resource access, including AJAX endpoints, downloads, chat history, and file operations.
- Model system, team, and project scope separately.
- Do not trust client-supplied role, owner, team, project, or approval fields.
- Prevent a reviewer from deciding a report about themselves; escalate conflicts to a higher authorized reviewer.

## Data and output

- Use PDO prepared statements for SQL values.
- Encode output for its HTML/attribute/JavaScript/URL context.
- Validate upload type, size, names, storage location, and download authorization.
- Never treat a report as conclusive proof of abuse.

## Network and logs

- Trust `Forwarded` or `X-Forwarded-For` only from explicitly trusted proxies.
- Limit access and retention for IP/security logs; IP addresses alone do not prove an attack.
- Never log passwords, session tokens, secret keys, or full sensitive request bodies.

## Database migrations

- Use versioned, ordered migrations with a lock and a migration registry.
- Never execute arbitrary migrations merely because a request encountered a SQL error.
- Back up before destructive schema changes and document rollback limits.

## Operational checks

Before production, validate HTTPS, secret placement, directory access restrictions, session settings, error display off, error logging on, database least privilege, backup/restore, and rate limits. Security controls require tests and review; no numerical security percentage is promised.
