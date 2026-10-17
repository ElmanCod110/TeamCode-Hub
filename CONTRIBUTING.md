# Contributing

## Workflow

- Keep changes focused, reviewable, and testable. Work is continuous; the project does not use rigid phase gates.
- Use branches such as `feat/module-registry`, `fix/authorization-boundary`, or `docs/routing`.
- Prefer Conventional Commit messages, for example `feat(core): add module registry contracts`.
- Add or update tests for behavior changes, especially authorization and input-handling boundaries.
- Document deployment assumptions, migration behavior, and backward-incompatible changes.
- Never commit credentials, session secrets, production configuration, private data, logs, uploads, or backups.

## Quality expectations

- Validate PHP syntax and run the automated checks before requesting review.
- Authorize every protected action on the server. A hidden UI element or an obscure URL is not access control.
- Treat all request data as untrusted; use parameterized SQL and context-appropriate output encoding.
- Do not describe planned functionality as implemented.
- Keep CSS and JavaScript in separate assets; PHP templates should primarily render HTML.
- Keep the baseline compatible with ordinary PHP shared hosting unless a new dependency is explicitly justified.

## Pull requests

Include the purpose, behavior changes, validation performed, security implications, deployment notes, and known limitations. Avoid claiming tests passed unless they were actually run.
