# Database and Migrations

## Supported baseline

The intended primary database is MySQL/MariaDB through PDO. SQLite is not assumed to be supported unless it is explicitly added and tested as a separate target.

## Connection policy

- Read credentials from environment variables or a private configuration file outside the web root.
- Do not commit real credentials.
- Enable PDO exception mode and disable emulated prepares where supported.
- Use utf8mb4 and explicit transaction boundaries.
- Use parameterized statements for values; dynamic identifiers require a strict allowlist.

## Migration policy

Migrations must be versioned, ordered, recorded in a migration registry/table, and protected against concurrent execution. A migration runner should be invoked through an explicit deployment/maintenance operation, not triggered by arbitrary SQL errors during normal web requests.

For destructive changes, document backup requirements and whether a safe rollback exists. A schema change is not complete until its migration and relevant tests are included.
