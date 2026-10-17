# Security Policy

## Status
The application is not yet implemented. This policy describes how to handle security reports during development; it is not a claim that a production security program is operational.

## Reporting
Please do not publish exploit details or sensitive data in public issues. Contact the repository owner privately through a GitHub-supported private reporting channel if enabled; otherwise request a private contact route before sharing details.

## Security design commitments
- Deny by default and enforce authorization server-side on every protected route, API, file, and resource.
- Use secure session settings, CSRF protection for state-changing browser requests, output encoding, parameterized SQL, and rate limits where appropriate.
- Trust forwarded client IP headers only when the immediate proxy is explicitly trusted.
- Keep secrets and private runtime files out of the public document root.
- Record security-relevant events with restricted access and a defined retention policy.
- Treat reports as allegations requiring review, not automatic proof of misconduct.
