# Database and Migrations

## Supported baseline

The primary database is MySQL/MariaDB through PDO. SQLite is not assumed to be supported unless it is explicitly added and tested as a separate target.

## Connection policy

- Read credentials from environment variables or a private configuration file outside the web root.
- Do not commit real credentials.
- Enable PDO exception mode and disable emulated prepares.
- Use utf8mb4 and explicit transaction boundaries.
- Use parameterized statements for values; dynamic identifiers require a strict allowlist.
- `DatabaseConfig` validates the host, port, database identifier, and username before constructing a DSN. Passwords are not embedded in the DSN.

## Migration policy

Migrations use filenames of the form `YYYYMMDDHHMMSS_description.php` and return a `MigrationInterface` object. The runner sorts versions, records successful migrations in `schema_migrations`, and acquires a MySQL advisory lock to avoid concurrent runs.

Run migrations explicitly from a trusted CLI shell with `php bin/migrate.php`. The web request path does not run migrations. Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` first. The database account needs permission to create/alter the application schema and the migration registry during deployment; runtime permissions should be reduced where practical after setup.

MySQL DDL can implicitly commit. If a migration is interrupted part-way through, inspect the database before retrying. Keep migrations focused and design them for safe recovery. Back up before destructive schema changes and document rollback limits.

The identity migration creates `users`, `teams`, `team_memberships`, `projects`, and `project_memberships`. Additional migrations create system roles, authentication rate-limit buckets, hashed team invitations, tasks, notifications, chat reports, GitHub activity cache, task collaboration records, administrator account-status audit events, project GitHub repository cache, and `account_action_tokens`. Registration creates pending users; email verification stores only SHA-256 token hashes and activates an account only when a valid, unused, unexpired token is consumed. Membership and project access remain application-enforced and must be checked on every protected request.
