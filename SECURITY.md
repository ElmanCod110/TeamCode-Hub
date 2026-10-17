# Security Policy

## Project status

TeamCode Hub is under active foundation development and is not currently represented as production-ready. This policy describes intended handling and design commitments; it does not claim that a staffed incident-response program is operating.

## Reporting

Please do not publish exploit details, credentials, personal information, or sensitive logs in public issues. Use GitHub's private vulnerability reporting feature if it is enabled for this repository. Otherwise, open a non-sensitive issue asking for a private reporting channel before sharing technical details.

## Security design commitments

- Deny by default and enforce authorization server-side on every protected route, API, file, and resource.
- Use secure session settings, CSRF protection for state-changing browser requests, output encoding, parameterized SQL, and appropriate rate limits.
- Trust forwarded client-IP headers only when the immediate proxy is explicitly configured as trusted.
- Keep secrets and private runtime files outside the public document root wherever hosting permits.
- Record security-relevant events with restricted access and a defined retention policy.
- Treat reports as allegations requiring review, not automatic proof of misconduct.
- Do not claim cryptographic guarantees or security percentages without a defensible threat model and validation evidence.
