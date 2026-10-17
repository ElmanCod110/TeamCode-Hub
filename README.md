# TeamCode Hub

**TeamCode Hub** is a modular, security-focused collaboration platform for software teams, designed to run on conventional PHP hosting with MySQL/MariaDB.

> **Project status:** foundation work is in progress. This repository is being bootstrapped; the current code must not yet be considered production-ready.

## Principles

- Secure-by-default request handling and server-side authorization.
- A modular monolith with explicit engine contracts and lazy module registration.
- PHP, HTML templates, CSS, and JavaScript separated by responsibility.
- MySQL/MariaDB support for shared hosting; no required long-running worker.
- Pretty routes from the beginning.
- Small, reviewable changes, automated checks, and clear documentation.
- No claim of process isolation between PHP modules running in the same application process.

## Requirements

- PHP 8.2 or newer (the exact supported range will be validated as implementation develops).
- PDO and the PDO MySQL driver for database-backed deployments.
- MySQL 8+ or a compatible MariaDB release.
- Apache with rewrite support, or a compatible web-server configuration.

## Current implementation

The first foundation change will establish application bootstrapping, request/response and routing contracts, module registration, security primitives, environment configuration, and development checks. Features will be added incrementally and documented as they become real.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), and [docs/SECURITY.md](docs/SECURITY.md).

## Security

Please do not disclose vulnerabilities in public issues. Follow [SECURITY.md](SECURITY.md).

## License

No license has been selected yet. Until one is added, no additional license permissions should be assumed.
