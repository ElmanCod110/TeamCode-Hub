# Contributing

## Workflow
1. Keep changes focused and reviewable; no rigid phase gates.
2. Use branches such as `feat/module-registry`, `fix/authorization-boundary`, or `docs/routing`.
3. Use Conventional Commit-style messages, for example `feat(core): define module contracts`.
4. Add or update tests for behavior changes and security boundaries.
5. Document deployment assumptions and backward-incompatible changes.
6. Never commit credentials, session secrets, private data, logs, uploads, or production configuration.

## Quality expectations
- Validate PHP syntax and run automated tests before requesting review.
- Treat every input as untrusted and authorize every protected action on the server.
- Do not describe a planned feature as implemented.
- Keep PHP templates free of inline CSS/JavaScript except minimal, safely encoded bootstrap data when unavoidable.

## Hosting target
The initial deployment target is PHP with MySQL/MariaDB on shared hosting. New dependencies must be justified against this constraint.
